<?php

namespace App\Http\Controllers;

use App\Models\BusinessSource;
use App\Models\Company;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\PaymentMode;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\ActivityLogger;
use App\Services\AvailabilityService;
use App\Services\ReservationDeskService;
use App\Services\ReservationAmendmentService;
use App\Services\CancellationService;
use App\Services\FolioService;
use App\Services\ShiftService;
use App\Support\Numbering;
use App\Models\RateContract;
use App\Services\ContractService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReservationController extends Controller
{
    public function index(Request $request)
    {
        if (! $request->query() && ($saved = ReservationDeskService::defaultQuery())) {
            parse_str($saved, $params);

            return redirect()->route('reservations.index', $params);
        }

        return view('reservations.index', ReservationDeskService::page($request));
    }

    protected function formData(): array
    {
        return [
            'roomTypes' => RoomType::where('is_active', true)->orderBy('base_rate')->get(),
            'ratePlans' => RatePlan::where('is_active', true)->get(),
            'sources' => BusinessSource::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'companies' => Company::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'paymentModes' => PaymentMode::where('is_active', true)->where('type', '!=', 'city_ledger')->pluck('name', 'id'),
        ];
    }

    public function create(Request $request)
    {
        return view('reservations.form', $this->formData() + [
            'reservation' => new Reservation([
                'arrival_date' => $request->input('arrival', bdate()->toDateString()),
                'departure_date' => $request->input('departure', bdate()->addDay()->toDateString()),
                'adults' => 1, 'status' => 'confirmed', 'booking_type' => 'individual',
            ]),
            'guest' => $request->guest_id ? Guest::find($request->guest_id) : new Guest,
            'lines' => [['room_type_id' => $request->room_type_id, 'room_id' => $request->room_id, 'rate_plan_id' => null, 'rate' => null, 'adults' => 2, 'children' => 0]],
        ]);
    }

    protected function rules(bool $update = false): array
    {
        return [
            'guest_id' => 'nullable|integer',
            'guest.title' => 'nullable|string|max:10',
            'guest.first_name' => 'required|string|max:80',
            'guest.last_name' => 'nullable|string|max:80',
            'guest.phone' => 'required|string|max:30',
            'guest.email' => 'nullable|email|max:150',
            'guest.nationality' => 'nullable|string|max:60',
            'guest.city' => 'nullable|string|max:80',
            'guest.id_type' => 'nullable|string|max:30',
            'guest.id_number' => 'nullable|string|max:60',
            'arrival_date' => ['required', 'date', 'after_or_equal:'.bdate()->toDateString()],
            'departure_date' => 'required|date|after:arrival_date',
            'eta' => 'nullable|date_format:H:i',
            'business_source_id' => 'nullable|integer|owned:business_sources',
            'company_id' => 'nullable|integer|owned:companies',
            'booking_type' => 'required|in:individual,group',
            'group_name' => 'nullable|required_if:booking_type,group|string|max:150',
            'status' => 'required|in:confirmed,tentative',
            'ota_reference' => 'nullable|string|max:60',
            'purpose' => 'nullable|string|max:60',
            'billing_instruction' => 'required|in:guest,company',
            'discount_pct' => 'nullable|numeric|min:0|max:100',
            'special_request' => 'nullable|string|max:2000',
            'change_reason' => 'nullable|string|max:255',
            'rooms' => 'required|array|min:1|max:50',
            'rooms.*.room_type_id' => 'required|integer|owned:room_types',
            'rooms.*.rate_plan_id' => 'nullable|integer|owned:rate_plans',
            'rooms.*.room_id' => 'nullable|integer|owned:rooms',
            'rooms.*.rate' => 'required|numeric|min:0|max:9999999',
            'rooms.*.adults' => 'required|integer|min:1|max:10',
            'rooms.*.children' => 'nullable|integer|min:0|max:10',
            'advance' => 'nullable|numeric|min:0',
            'advance_mode' => 'nullable|integer|owned:payment_modes',
            'advance_ref' => 'nullable|string|max:80',
            'cheque_bank_name' => 'nullable|string|max:100', 'cheque_number' => 'nullable|string|max:20', 'cheque_bank_branch' => 'nullable|string|max:100',
        ];
    }

    protected function upsertGuest(array $g, ?int $guestId, ?int $companyId): Guest
    {
        $guest = $guestId ? Guest::findOrFail($guestId) : null;
        if ($guest?->isPrivacyLocked()) {
            $guest = null; // erased / restricted profiles are never reused – a returning guest gets a fresh profile
        }
        if (! $guest && ! empty($g['phone'])) {
            $guest = Guest::whereNull('privacy_status')->where('phone', $g['phone'])->where('first_name', $g['first_name'])->first();
        }
        $guest ??= new Guest;
        $guest->fill(array_filter($g, fn ($v) => $v !== null && $v !== ''));
        if ($companyId && ! $guest->company_id) {
            $guest->company_id = $companyId;
        }
        $guest->save();
        if ($guest->is_blacklisted) {
            throw ValidationException::withMessages(['guest.first_name' => 'This guest is blacklisted. Reservation blocked.']);
        }

        return $guest;
    }

    /**
     * Lock the physical inventory rows before checking availability. Every
     * reservation created through this controller takes the same locks, which
     * keeps two concurrent desk requests from both selling the last room.
     */
    protected function lockInventory(array $lines, ?int $reservationId = null): void
    {
        $typeIds = collect($lines)->pluck('room_type_id')->filter()->map(fn ($id) => (int) $id);
        $roomIds = collect($lines)->pluck('room_id')->filter()->map(fn ($id) => (int) $id);
        if ($reservationId) {
            $typeIds = $typeIds->merge(ReservationRoom::where('reservation_id', $reservationId)->whereIn('status', ['reserved', 'checked_in'])->pluck('room_type_id'));
        }
        if ($roomIds->isNotEmpty()) {
            $typeIds = $typeIds->merge(Room::whereIn('id', $roomIds)->pluck('room_type_id'));
        }
        $typeIds = $typeIds->unique()->values();
        AvailabilityService::lockRoomInventory($typeIds);
    }

    protected function checkInventory(array $lines, string $from, string $to, ?int $ignoreReservation = null, ?int $contractId = null): void
    {
        $need = collect($lines)->groupBy('room_type_id')->map->count();
        foreach ($need as $typeId => $count) {
            $type = RoomType::findOrFail((int) $typeId);
            $quote = $contractId
                ? ContractService::quote(RateContract::with('rates', 'blackouts')->findOrFail($contractId), $type, $from, $to, 1)
                : \App\Services\RateService::quote($type, $from, $to);
            if ($quote['stop_sell']) {
                throw ValidationException::withMessages(['rooms' => "{$type->name} is closed for sale on the selected dates."]);
            }
            if (! $quote['min_stay_ok']) {
                throw ValidationException::withMessages(['rooms' => "A minimum stay of {$quote['min_stay']} night(s) applies to {$type->name}."]);
            }
            $avail = AvailabilityService::typeAvailable((int) $typeId, $from, $to, null, $contractId); // v2.7: own allotment is sellable
            if ($ignoreReservation) {
                $avail += ReservationRoom::where('reservation_id', $ignoreReservation)->where('room_type_id', $typeId)->where('status', 'reserved')->count();
            }
            if ($count > $avail) {
                $name = RoomType::find($typeId)?->name ?? 'room type';
                throw ValidationException::withMessages(['rooms' => "Only {$avail} {$name} room(s) available for the selected dates (requested {$count}). Overbooking is not allowed."]);
            }
        }
        $ids = collect($lines)->pluck('room_id')->filter()->map(fn ($id) => (int) $id);
        if ($ids->count() !== $ids->unique()->count()) {
            throw ValidationException::withMessages(['rooms' => 'The same room is assigned twice.']);
        }
        foreach (collect($lines)->filter(fn ($line) => ! empty($line['room_id'])) as $line) {
            $room = Room::whereKey((int) $line['room_id'])->first();
            if (! $room || (int) $room->room_type_id !== (int) $line['room_type_id']) {
                throw ValidationException::withMessages(['rooms' => 'A selected room does not belong to its selected room type. Refresh availability and choose again.']);
            }
        }
        foreach ($ids as $rid) {
            $ignoreStay = $ignoreReservation
                ? ReservationRoom::where('reservation_id', $ignoreReservation)->where('room_id', $rid)->whereIn('status', ['reserved', 'checked_in'])->value('id')
                : null;
            if (! AvailabilityService::roomAvailableForReservation($rid, $from, $to, $ignoreStay ? (int) $ignoreStay : null)) {
                $roomNo = Room::whereKey($rid)->value('room_no') ?? $rid;
                throw ValidationException::withMessages(['rooms' => "Room {$roomNo} is no longer available for the selected dates. Choose another room or leave it on auto-assign."]);
            }
        }
    }

    protected function lineExtra(array $line, ?RateContract $contract = null): float
    {
        $type = RoomType::find($line['room_type_id']);
        $plan = ! empty($line['rate_plan_id']) ? RatePlan::find($line['rate_plan_id']) : null;
        $extraAdults = max(0, (int) $line['adults'] - (int) ($type->base_occupancy ?? 2));
        $contractExtra = ! $plan && $contract ? $contract->rates->where('room_type_id', $type->id)->whereNotNull('extra_adult_rate')->first()?->extra_adult_rate : null;

        return $extraAdults * (float) ($plan->extra_adult_rate ?? $contractExtra ?? $type->extra_adult_rate);
    }

    /**
     * v2.7: the contract that applies (resolved on the server from company + arrival) and the bill-to-company guards:
     * a company not approved for direct billing is refused; a credit hold needs credit.override.
     */
    protected function contractAndCredit(array $data): ?RateContract
    {
        $company = ! empty($data['company_id']) ? \App\Models\Company::find($data['company_id']) : null;
        if ($company && ($data['billing_instruction'] ?? 'guest') === 'company') {
            if (! $company->direct_billing) {
                throw ValidationException::withMessages(['billing_instruction' => "{$company->name} is not approved for direct billing – choose Bill to Guest."]);
            }
            if ($company->onHold() && ! can_do('credit.override')) {
                throw ValidationException::withMessages(['billing_instruction' => "{$company->name} is on credit hold".($company->credit_hold_reason ? " ({$company->credit_hold_reason})" : '').' – choose Bill to Guest or ask Accounts to release it.']);
            }
        }

        return ContractService::forCompany($company?->id, $data['arrival_date'])?->load('rates');
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());
        if (($data['advance'] ?? 0) > 0 && empty($data['advance_mode'])) {
            throw ValidationException::withMessages(['advance_mode' => 'Select a payment mode for the advance.']);
        }
        $contract = $this->contractAndCredit($data);

        $reservation = DB::transaction(function () use ($data, $contract) {
            $this->lockInventory($data['rooms']);
            $this->checkInventory($data['rooms'], $data['arrival_date'], $data['departure_date'], null, $contract?->id);
            $guest = $this->upsertGuest($data['guest'], $data['guest_id'] ?? null, $data['company_id'] ?? null);
            $res = Reservation::create([
                'reservation_no' => Numbering::next('reservation'),
                'guest_id' => $guest->id,
                'adults' => collect($data['rooms'])->sum('adults'),
                'children' => collect($data['rooms'])->sum('children'),
                'created_by' => auth()->id(), 'rate_contract_id' => $contract?->id,
            ] + collect($data)->only(['company_id', 'business_source_id', 'booking_type', 'group_name', 'arrival_date', 'departure_date', 'eta', 'status', 'ota_reference', 'purpose', 'billing_instruction', 'discount_pct', 'special_request'])->all());
            foreach ($data['rooms'] as $line) {
                $res->rooms()->create([
                    'room_type_id' => $line['room_type_id'], 'room_id' => $line['room_id'] ?? null, 'rate_plan_id' => $line['rate_plan_id'] ?? null,
                    'guest_id' => $guest->id, 'arrival_date' => $data['arrival_date'], 'departure_date' => $data['departure_date'],
                    'rate' => $line['rate'], 'adults' => $line['adults'], 'children' => $line['children'] ?? 0,
                    'extra_charge' => $this->lineExtra($line, $contract), 'status' => 'reserved',
                ]);
            }
            if (! empty($data['advance']) && $data['advance'] > 0) {
                $folio = FolioService::openStandalone(['reservation_id' => $res->id, 'guest_id' => $guest->id, 'type' => 'guest']);
                FolioService::payment($folio, (float) $data['advance'], (int) $data['advance_mode'], $data['advance_ref'] ?? null, 'advance', 'payment', [
                    'bank_name' => $data['cheque_bank_name'] ?? null, 'number' => $data['cheque_number'] ?? null, 'bank_branch' => $data['cheque_bank_branch'] ?? null,
                ]);
            }
            $createdState = ReservationAmendmentService::snapshot($res);
            ReservationAmendmentService::record($res, 'created', null, $createdState, null, 'front_desk');
            ActivityLogger::log('reservations', 'create', "Created reservation {$res->reservation_no} for {$guest->full_name}", $res);

            return $res;
        });

        // Notifications are post-commit side effects; a provider outage must
        // never turn a successfully saved reservation into a 500 response.
        try {
            \App\Services\MailService::autoConfirm($reservation);
            \App\Services\MessageService::autoConfirm($reservation);
        } catch (\Throwable $e) {
            report($e);
        }

        return redirect()->route('reservations.show', $reservation)->with('success', "Reservation {$reservation->reservation_no} created.");
    }

    public function show(Reservation $reservation)
    {
        $reservation->load(['guest.company', 'company', 'source', 'rooms.room', 'rooms.roomType', 'rooms.ratePlan', 'rooms.folio', 'folios.transactions.paymentMode', 'creator', 'channel']);
        $advances = $reservation->folios->flatMap->transactions->where('is_void', false)->whereIn('type', ['payment']);
        $pending = $reservation->rooms->where('status', 'reserved');
        $noShowPending = $pending->filter(fn ($stay) => $stay->arrival_date->lte(bdate()))->values();

        return view('reservations.show', [
            'onlinePayments' => \App\Models\OnlinePayment::where('reservation_id', $reservation->id)->latest('id')->get(),
            'trips' => \App\Models\TransportTrip::with('vehicle', 'driver')->where('reservation_id', $reservation->id)->orderBy('scheduled_at')->get(),
            'formCs' => \App\Models\FormCRecord::with('stay.room')->where('reservation_id', $reservation->id)->orderBy('id')->get(), // v3.4
            'deskMessages' => \App\Models\GuestMessage::whereIn('reservation_room_id', $reservation->rooms->pluck('id'))->latest('id')->limit(10)->get(), // v3.5
            'deskWakeups' => \App\Models\WakeupCall::whereIn('reservation_room_id', $reservation->rooms->pluck('id'))->where(fn ($q) => $q->where('status', 'scheduled')->orWhere('call_at', '>=', now()->subDays(2)))->orderBy('call_at')->limit(10)->get(),
            'deskTraces' => \App\Services\TraceService::forReservation($reservation),
            'foreignGuest' => \App\Services\FormCService::isForeign($reservation->guest),
            'r' => $reservation,
            'cancelQuote' => CancellationService::quote($reservation, $pending, 'cancel'),
            'stayQuotes' => $pending->mapWithKeys(fn ($s) => [$s->id => CancellationService::quote($reservation, collect([$s]), 'cancel')['amount']]),
            'noshowQuote' => CancellationService::quote($reservation, $noShowPending, 'noshow'),
            'commissions' => $reservation->commissions()->with('company')->get(),
            'advances' => $advances,
            'amendments' => $reservation->amendments()->with('user')->get(),
            'paymentModes' => PaymentMode::where('is_active', true)->where('type', '!=', 'city_ledger')->pluck('name', 'id'),
            'freeRooms' => $reservation->rooms->where('status', 'reserved')->mapWithKeys(fn ($s) => [$s->id => AvailabilityService::reservationRooms($s->arrival_date, $s->departure_date, null, $s->id)]),
        ]);
    }

    public function edit(Reservation $reservation)
    {
        abort_unless(in_array($reservation->status, ['confirmed', 'tentative']), 403, 'Only confirmed/tentative reservations can be edited.');
        abort_if($reservation->rooms()->where('status', '!=', 'reserved')->where('status', '!=', 'cancelled')->exists(), 403, 'Some rooms are already checked in. Use Front Desk → Amend stay.');
        $reservation->load('rooms', 'guest');

        return view('reservations.form', $this->formData() + [
            'reservation' => $reservation,
            'guest' => $reservation->guest,
            'lines' => $reservation->rooms->where('status', 'reserved')->map(fn ($s) => $s->only(['room_type_id', 'room_id', 'rate_plan_id', 'rate', 'adults', 'children']))->values()->all(),
        ]);
    }

    public function update(Request $request, Reservation $reservation)
    {
        abort_unless(in_array($reservation->status, ['confirmed', 'tentative']), 403);
        $data = $request->validate($this->rules(true));
        $contract = $this->contractAndCredit($data);

        DB::transaction(function () use ($data, $reservation, $contract) {
            $reservation = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            // The edit form's status check happened before the transaction;
            // repeat it after the reservation lock so a concurrent check-in or
            // cancellation cannot be overwritten by a stale form submission.
            abort_unless(in_array($reservation->status, ['confirmed', 'tentative']), 403);
            abort_if($reservation->rooms()->where('status', '!=', 'reserved')->where('status', '!=', 'cancelled')->exists(), 403, 'Some rooms are already checked in. Use Front Desk → Amend stay.');
            $before = ReservationAmendmentService::snapshot($reservation);
            $this->lockInventory($data['rooms'], $reservation->id);
            $this->checkInventory($data['rooms'], $data['arrival_date'], $data['departure_date'], $reservation->id, $contract?->id);
            $guest = $this->upsertGuest($data['guest'], $reservation->guest_id, $data['company_id'] ?? null);
            $reservation->update([
                'adults' => collect($data['rooms'])->sum('adults'), 'children' => collect($data['rooms'])->sum('children'), 'rate_contract_id' => $contract?->id,
            ] + collect($data)->only(['company_id', 'business_source_id', 'booking_type', 'group_name', 'arrival_date', 'departure_date', 'eta', 'status', 'ota_reference', 'purpose', 'billing_instruction', 'discount_pct', 'special_request'])->all());
            $reservation->rooms()->where('status', 'reserved')->delete();
            foreach ($data['rooms'] as $line) {
                $reservation->rooms()->create([
                    'room_type_id' => $line['room_type_id'], 'room_id' => $line['room_id'] ?? null, 'rate_plan_id' => $line['rate_plan_id'] ?? null,
                    'guest_id' => $guest->id, 'arrival_date' => $data['arrival_date'], 'departure_date' => $data['departure_date'],
                    'rate' => $line['rate'], 'adults' => $line['adults'], 'children' => $line['children'] ?? 0,
                    'extra_charge' => $this->lineExtra($line, $contract), 'status' => 'reserved',
                ]);
            }
            $after = ReservationAmendmentService::snapshot($reservation);
            ReservationAmendmentService::record($reservation, 'amended', $before, $after, $data['change_reason'] ?? null, 'front_desk');
            ActivityLogger::log('reservations', 'update', "Updated reservation {$reservation->reservation_no}", $reservation, [
                'change_count' => count(ReservationAmendmentService::changes($before, $after)),
            ]);
        });

        return redirect()->route('reservations.show', $reservation)->with('success', 'Reservation updated.');
    }

    public function cancel(Request $request, Reservation $reservation)
    {
        $data = $request->validate(['reason' => 'required|string|max:255', 'stay_id' => 'nullable|integer',
            'waive_fee' => 'nullable|boolean', 'waive_reason' => 'nullable|required_if:waive_fee,1|string|max:190']);
        $before = ReservationAmendmentService::snapshot($reservation);
        $q = $reservation->rooms()->where('status', 'reserved');
        if (! empty($data['stay_id'])) {
            $q->where('id', $data['stay_id']);
        }
        $stays = $q->get();
        abort_if($stays->isEmpty(), 422, 'Nothing to cancel.');
        try {
            [, $fee, , $channel] = CancellationService::cancel($reservation, $stays, $data['reason'], $request->boolean('waive_fee'), $data['waive_reason'] ?? null);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        $after = ReservationAmendmentService::snapshot($reservation);
        ReservationAmendmentService::record($reservation, 'cancelled', $before, $after, $data['reason'], 'front_desk');

        try {
            \App\Services\MailService::autoConfirm($reservation->refresh());
        } catch (\Throwable $e) {
            report($e);
        }

        $atChannel = $channel === null ? '' : ($channel['sent']
            ? ' The cancellation was sent to the channel.'.($channel['message'] !== '' ? ' '.$channel['message'] : '')
            : ' The channel could not be reached – cancel it there by hand (flagged on the reservation).');

        return back()->with('success', 'Reservation cancelled.'.($fee > 0 ? ' Cancellation fee '.money($fee).' posted to the advance folio.' : ' Refund any advance from the folio if applicable.').$atChannel);
    }

    public function noShow(Request $request, Reservation $reservation)
    {
        $data = $request->validate(['waive_fee' => 'nullable|boolean', 'waive_reason' => 'nullable|required_if:waive_fee,1|string|max:190']);
        $quote = [];
        DB::transaction(function () use ($reservation, $request, $data, &$quote) {
            $locked = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            $before = ReservationAmendmentService::snapshot($locked);
            $businessDate = bdate();
            // A multi-room booking may have staggered arrivals. Never mark a
            // future room no-show just because another room in the same booking
            // arrived. Lock the selected lines so two agents cannot post the
            // same no-show fee concurrently.
            $stays = $locked->rooms()->where('status', 'reserved')->whereDate('arrival_date', '<=', $businessDate)->lockForUpdate()->get();
            if ($stays->isEmpty()) {
                throw new \Symfony\Component\HttpKernel\Exception\HttpException(422, 'No pending rooms due for no-show today.');
            }
            $quote = CancellationService::quote($locked, $stays, 'noshow');
            $ids = $stays->pluck('id');
            ReservationRoom::whereIn('id', $ids)->update(['status' => 'no_show']);
            \App\Services\UpsellService::closeForStays($ids, 'No-show');   // v4.1
            $locked->syncStatus();
            ActivityLogger::log('reservations', 'no_show', "Marked {$locked->reservation_no} as no-show", $locked);
            if ($quote['amount'] > 0) {
                $request->boolean('waive_fee')
                    ? ActivityLogger::log('reservations', 'cancel_fee_waived', 'Waived no-show fee '.money($quote['amount']).": {$data['waive_reason']}", $locked)
                    : CancellationService::post($locked, $quote['amount'], 'No-show fee');
            }
            $after = ReservationAmendmentService::snapshot($locked);
            ReservationAmendmentService::record($locked, 'no_show', $before, $after, $data['waive_reason'] ?? null, 'front_desk');
        });

        return back()->with('success', 'Marked as no-show.'.($quote['amount'] > 0 && ! $request->boolean('waive_fee') ? ' No-show fee '.money($quote['amount']).' posted.' : ''));
    }

    public function confirm(Reservation $reservation)
    {
        DB::transaction(function () use ($reservation) {
            $reservation = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            abort_unless($reservation->status === 'tentative', 422);
            abort_unless($reservation->rooms()->where('status', 'reserved')->exists(), 422, 'This reservation has no pending rooms to confirm.');
            $before = ReservationAmendmentService::snapshot($reservation);
            $reservation->update(['status' => 'confirmed']);
            $after = ReservationAmendmentService::snapshot($reservation);
            ReservationAmendmentService::record($reservation, 'confirmed', $before, $after, null, 'front_desk');
            ActivityLogger::log('reservations', 'confirm', "Confirmed {$reservation->reservation_no}", $reservation);
        });

        return back()->with('success', 'Reservation confirmed.');
    }

    public function previewAdvance(Request $request, Reservation $reservation)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:1', 'payment_mode_id' => 'required|integer|owned:payment_modes',
            'reference' => 'nullable|string|max:80', 'momo_network' => 'nullable|string|max:20', 'momo_phone' => 'nullable|string|max:30',
        ]);
        $reference = $data['reference'] ?? null;
        if ($request->filled('momo_network') || $request->filled('momo_phone')) {
            $reference = mb_substr(trim(implode(' · ', array_filter([$data['momo_network'] ?? null, $data['momo_phone'] ?? null, $reference]))), 0, 80);
        }
        try {
            ShiftService::assertOpen();
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage().' Nothing was posted.';

            return $request->expectsJson()
                ? response()->json(['ok' => false, 'message' => $msg], 422)
                : back()->with('error', $msg);
        }
        $mode = PaymentMode::find($data['payment_mode_id']);
        $open = $reservation->rooms()->where('status', 'checked_in')->whereHas('folio', fn ($q) => $q->where('status', 'open'))->exists()
            || Folio::where('reservation_id', $reservation->id)->where('status', 'open')->exists();
        $msg = 'An advance of '.money($data['amount']).' by '.($mode->name ?? 'the selected mode')
            .($reference ? ' · '.$reference : '')
            .' would be recorded for '.$reservation->reservation_no.'. '
            .($open ? 'It would post on the open folio.' : 'No folio is open, so confirm opens one through the existing advance action.')
            .' Nothing is saved until you confirm.';
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => $msg]);
        }

        return back()->with('success', $msg);
    }

    public function advance(Request $request, Reservation $reservation)
    {
        $data = $request->validate(['amount' => 'required|numeric|min:1', 'payment_mode_id' => 'required|integer|owned:payment_modes', 'reference' => 'nullable|string|max:80', 'momo_network' => 'nullable|string|max:20', 'momo_phone' => 'nullable|string|max:30', 'cheque_bank_name' => 'nullable|string|max:100', 'cheque_number' => 'nullable|string|max:20', 'cheque_bank_branch' => 'nullable|string|max:100']);
        if ($request->filled('momo_network') || $request->filled('momo_phone')) {
            $data['reference'] = mb_substr(trim(implode(' · ', array_filter([$data['momo_network'] ?? null, $data['momo_phone'] ?? null, $data['reference'] ?? null]))), 0, 80);
        }
        DB::transaction(function () use ($reservation, $data) {
            // Serialize folio selection/creation with other advance payments for
            // this booking, so concurrent desk clicks cannot create two advance
            // folios or post against a stale closed one.
            $locked = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            $stay = $locked->rooms()->where('status', 'checked_in')->lockForUpdate()->first();
            $folio = $stay?->folio ?? Folio::where('reservation_id', $locked->id)->whereNull('reservation_room_id')->where('status', 'open')->lockForUpdate()->first()
                ?? FolioService::openStandalone(['reservation_id' => $locked->id, 'guest_id' => $locked->guest_id, 'type' => 'guest']);
            FolioService::payment($folio, (float) $data['amount'], (int) $data['payment_mode_id'], $data['reference'] ?? null, 'advance', 'payment', [
                'bank_name' => $data['cheque_bank_name'] ?? null, 'number' => $data['cheque_number'] ?? null, 'bank_branch' => $data['cheque_bank_branch'] ?? null,
            ]);
            ActivityLogger::log('folio', 'advance', 'Advance '.money($data['amount'])." received for {$locked->reservation_no}", $locked);
        });

        return back()->with('success', 'Advance payment recorded.');
    }

    public function assignRooms(Request $request, Reservation $reservation)
    {
        $data = $request->validate(['assign' => 'required|array', 'assign.*' => 'nullable|integer|owned:rooms']);
        $roomIds = collect($data['assign'])->filter()->map(fn ($id) => (int) $id);
        if ($roomIds->count() !== $roomIds->unique()->count()) {
            return back()->with('error', 'The same room cannot be assigned to two stays in this reservation.');
        }

        try {
            DB::transaction(function () use ($data, $reservation, $roomIds) {
                $reservation = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
                $before = ReservationAmendmentService::snapshot($reservation);
                foreach (array_keys($data['assign']) as $stayId) {
                    $reservation->rooms()->whereKey($stayId)->where('status', 'reserved')->lockForUpdate()->first();
                }
                if ($roomIds->isNotEmpty()) {
                    Room::whereIn('id', $roomIds)->lockForUpdate()->get();
                }
                foreach ($data['assign'] as $stayId => $roomId) {
                    $stay = $reservation->rooms()->whereKey($stayId)->where('status', 'reserved')->lockForUpdate()->first();
                    if (! $stay) {
                        continue;
                    }
                    if (! $roomId) {
                        $stay->update(['room_id' => null]);
                        continue;
                    }
                    $roomId = (int) $roomId;
                    if (! AvailabilityService::roomAvailableForReservation($roomId, $stay->arrival_date, $stay->departure_date, $stay->id)) {
                        $roomNo = Room::whereKey($roomId)->value('room_no') ?? $roomId;
                        throw new \RuntimeException("Room {$roomNo} is no longer available for this stay (it may be occupied, blocked, leased, or out of order). Choose another room.");
                    }
                    $room = Room::findOrFail($roomId);
                    $stay->update(['room_id' => $room->id, 'room_type_id' => $room->room_type_id]);
                }
                $after = ReservationAmendmentService::snapshot($reservation);
                ReservationAmendmentService::record($reservation, 'assigned', $before, $after, null, 'front_desk');
            });
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Room assignment updated.');
    }

    public function print(Reservation $reservation)
    {
        $reservation->load('guest', 'rooms.roomType', 'rooms.room', 'rooms.ratePlan', 'source', 'company', 'folios.transactions');

        return view('reservations.print', ['r' => $reservation]);
    }

    public function availability(Request $request)
    {
        $data = $request->validate([
            'from' => 'nullable|date',
            'days' => 'nullable|integer|min:1|max:31',
        ]);
        $from = $data['from'] ?? bdate()->toDateString();

        return view('reservations.availability', AvailabilityService::matrix($from, (int) ($data['days'] ?? 14)));
    }

    // ---------- JSON helpers for forms ----------
    public function apiRates(Request $request)
    {
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after:from', 'company_id' => 'nullable|integer|owned:companies']);
        $type = RoomType::findOrFail($request->integer('room_type_id'));
        $from = $request->input('from', bdate()->toDateString());
        $to = $request->input('to', Carbon::parse($from)->addDay()->toDateString());
        $q = \App\Services\RateService::quote($type, $from, $to);
        // v2.7: a company with an active contract gets its negotiated price (net grid / % off; blackout nights at the public rate)
        $contract = ContractService::forCompany($request->integer('company_id') ?: null, $from);
        $cq = $contract ? ContractService::quote($contract, $type, $from, $to, max(1, $request->integer('adults') ?: 1)) : null;
        if ($cq) {
            return response()->json([
                'base_rate' => (float) $type->base_rate, 'rate' => $cq['average'], 'total' => $cq['total'], 'nights' => $cq['nights'],
                'min_stay' => $cq['min_stay'], 'stop_sell' => $cq['stop_sell'], 'seasons' => [], 'extra_adult' => $cq['extra_adult_rate'] ?? (float) $type->extra_adult_rate,
                'max_adults' => $type->max_adults, 'plans' => RatePlan::where('room_type_id', $type->id)->where('is_active', true)->get(['id', 'name', 'meal_plan', 'rate']),
                'contract' => ['id' => $contract->id, 'code' => $contract->code, 'name' => $contract->name, 'basis' => $contract->basisLabel(), 'meal_plan' => $contract->meal_plan,
                    'public_rate' => round($cq['public_total'] / max(1, $cq['count']), 2), 'contract_nights' => $cq['contract_nights'], 'nights' => $cq['count'],
                    'lra' => $contract->lra, 'why' => array_values(array_unique(array_filter(array_column($cq['nights'], 'why'))))],
            ]);
        }

        return response()->json([
            'base_rate' => (float) $type->base_rate, 'rate' => $q['average'], 'total' => $q['total'], 'nights' => $q['nights'],
            'min_stay' => $q['min_stay'], 'stop_sell' => $q['stop_sell'],
            'seasons' => array_values(array_unique(array_filter(array_column($q['nights'], 'season')))), 'extra_adult' => (float) $type->extra_adult_rate, 'max_adults' => $type->max_adults,
            'plans' => RatePlan::where('room_type_id', $type->id)->where('is_active', true)->get(['id', 'name', 'meal_plan', 'rate']),
        ]);
    }

    public function apiFreeRooms(Request $request)
    {
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after:from', 'room_type_id' => 'nullable|integer', 'company_id' => 'nullable|integer|owned:companies']);
        $from = $request->input('from', bdate()->toDateString());
        $to = $request->input('to', Carbon::parse($from)->addDay()->toDateString());
        $typeId = $request->integer('room_type_id') ?: null;
        if ($typeId) {
            $type = RoomType::findOrFail($typeId);
            $contract = ContractService::forCompany($request->integer('company_id') ?: null, $from);
            $quote = $contract
                ? ContractService::quote($contract, $type, $from, $to, max(1, $request->integer('adults') ?: 1))
                : \App\Services\RateService::quote($type, $from, $to);
            if ($quote['stop_sell'] || ! $quote['min_stay_ok']) {
                return response()->json([]);
            }
        }

        return response()->json(AvailabilityService::reservationRooms($from, $to, $typeId)
            ->map(fn ($r) => ['id' => $r->id, 'room_no' => $r->room_no, 'status' => $r->status, 'hk' => $r->hk_status, 'type' => $r->roomType->name])->values());
    }

    public function apiGuests(Request $request)
    {
        $data = $request->validate(['q' => 'nullable|string|max:100']);

        return response()->json(Guest::whereNull('privacy_status')->where('is_blacklisted', false)->search($data['q'] ?? null)->take(10)->get()
            ->map(fn ($g) => $g->only(['id', 'title', 'first_name', 'last_name', 'phone', 'email', 'nationality', 'city', 'id_type', 'id_number', 'company_id', 'is_vip', 'is_blacklisted'])));
    }
}
