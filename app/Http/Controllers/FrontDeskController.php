<?php

namespace App\Http\Controllers;

use App\Models\BusinessSource;
use App\Models\Company;
use App\Models\Floor;
use App\Models\Guest;
use App\Models\PaymentMode;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\ActivityLogger;
use App\Services\AvailabilityService;
use App\Services\FolioService;
use App\Services\StayService;
use App\Support\Numbering;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FrontDeskController extends Controller
{
    public function rooms(Request $request)
    {
        $d = bdate()->toDateString();
        $rooms = Room::with(['roomType', 'floor', 'currentStay.reservation.guest'])->where('is_active', true)
            ->when($request->type, fn ($q, $t) => $q->where('room_type_id', $t))
            ->orderBy('room_no')->get();
        $arrivingToday = ReservationRoom::with('reservation.guest')->where('status', 'reserved')->whereDate('arrival_date', $d)->whereNotNull('room_id')->get()->keyBy('room_id');

        foreach ($rooms as $room) {
            $room->view_status = $room->status;
            if ($room->status === 'occupied' && $room->currentStay && $room->currentStay->departure_date->lte(bdate())) {
                $room->view_status = 'due_out';
            } elseif ($room->status === 'vacant' && isset($arrivingToday[$room->id])) {
                $room->view_status = 'reserved';
                $room->arrival = $arrivingToday[$room->id];
            }
        }
        if ($request->status) {
            $rooms = $rooms->filter(fn ($r) => $r->view_status === $request->status || $r->hk_status === $request->status);
        }

        return view('frontdesk.rooms', [
            'floors' => $rooms->groupBy(fn ($r) => $r->floor?->name ?? 'Unassigned'),
            'counts' => $rooms->countBy('view_status'),
            'hk' => $rooms->countBy('hk_status'),
            'types' => RoomType::pluck('name', 'id'),
            'paymentModes' => PaymentMode::where('is_active', true)->pluck('name', 'id'),
        ]);
    }

    public function tapeChart(Request $request)
    {
        $from = Carbon::parse($request->input('from', bdate()->copy()->subDays(2)->toDateString()));
        $days = min(31, max(7, (int) $request->input('days', 14)));
        $to = $from->copy()->addDays($days);
        $rooms = Room::with('roomType')->where('is_active', true)->orderBy('room_type_id')->orderBy('room_no')->get();
        $stays = ReservationRoom::with('reservation.guest')->whereIn('status', ['reserved', 'checked_in', 'checked_out'])
            ->whereDate('arrival_date', '<', $to->toDateString())->whereDate('departure_date', '>', $from->toDateString())->get();

        return view('frontdesk.tapechart', [
            'from' => $from, 'days' => $days, 'rooms' => $rooms->groupBy(fn ($r) => $r->roomType->name),
            'byRoom' => $stays->whereNotNull('room_id')->groupBy('room_id'),
            'unassigned' => $stays->whereNull('room_id')->where('status', 'reserved'),
        ]);
    }

    /**
     * Tape chart drag & drop: drop a stay (or an unassigned reservation) onto a room row.
     * Dates never change – only the room. In-house guests go through the audited room-move
     * flow (old room becomes vacant/dirty); future stays are simply (re)assigned.
     */
    public function tapeDrop(Request $request)
    {
        $data = $request->validate(['stay_id' => 'required|integer|owned:reservation_rooms', 'room_id' => 'required|integer|owned:rooms']);
        $stay = ReservationRoom::with('reservation', 'room', 'roomType')->findOrFail($data['stay_id']);
        $room = Room::with('roomType')->findOrFail($data['room_id']);
        $fail = fn (string $msg) => $request->expectsJson() ? response()->json(['ok' => false, 'message' => $msg], 422) : back()->with('error', $msg);

        if (! in_array($stay->status, ['reserved', 'checked_in'], true)) {
            return $fail('Only reserved or in-house stays can be moved.');
        }
        if ($stay->room_id === $room->id) {
            return $fail('The stay is already in room '.$room->room_no.'.');
        }

        try {
            if ($stay->status === 'checked_in') {
                $oldRoom = $stay->room?->room_no ?? 'unassigned';
                $typeNote = $stay->room_type_id !== $room->room_type_id ? " (room type changed to {$room->roomType->name} – rate kept at ".money($stay->rate).')' : '';
                StayService::moveRoom($stay, $room->id, null, 'Tape chart drag & drop');
                $msg = "Guest moved from room {$oldRoom} to {$room->room_no}{$typeNote}.";
            } else {
                // The service serializes the reservation, stay and destination
                // room, then rechecks clashes/outages before assigning.
                $msg = StayService::placeStay($stay, $room->id, null, 'Tape chart drag & drop');
            }
        } catch (\RuntimeException $e) {
            return $fail($e->getMessage());
        }

        if ($request->expectsJson()) {
            session()->flash('success', $msg);

            return response()->json(['ok' => true, 'message' => $msg]);
        }

        return back()->with('success', $msg);
    }

    protected function stayList(string $view, $query, string $title)
    {
        return view('frontdesk.list', ['stays' => $query->with(['reservation.guest', 'reservation.company', 'room', 'roomType', 'folio'])->get(), 'mode' => $view, 'title' => $title]);
    }

    public function arrivals(Request $request)
    {
        $d = $request->input('date', bdate()->toDateString());

        return $this->stayList('arrivals', ReservationRoom::where('status', 'reserved')->whereDate('arrival_date', '<=', $d)->whereDate('arrival_date', '>=', Carbon::parse($d)->subDays($request->boolean('overdue') ? 30 : 0))->orderBy('arrival_date'), 'Arrivals');
    }

    public function inHouse()
    {
        return $this->stayList('inhouse', ReservationRoom::where('status', 'checked_in')->orderBy('room_id'), 'In-House Guests');
    }

    public function departures(Request $request)
    {
        $d = $request->input('date', bdate()->toDateString());

        return $this->stayList('departures', ReservationRoom::where('status', 'checked_in')->whereDate('departure_date', '<=', $d)->orderBy('departure_date'), 'Departures');
    }

    /** Normalize the initial deposit in either legacy single-tender form or split rows. */
    protected function advancePaymentLines(array $data): array
    {
        $usingSplit = ! empty($data['payments']);
        if ($usingSplit && ($data['advance'] ?? 0) > 0) {
            throw ValidationException::withMessages(['payments' => 'Use either the single deposit fields or split tender lines, not both.']);
        }
        if (! $usingSplit && ($data['advance'] ?? 0) <= 0) {
            return [];
        }
        $lines = $usingSplit ? array_values($data['payments']) : [[
            'amount' => $data['advance'], 'payment_mode_id' => $data['advance_mode'] ?? null,
            'reference' => $data['advance_ref'] ?? null, 'cheque_bank_name' => $data['cheque_bank_name'] ?? null,
            'cheque_number' => $data['cheque_number'] ?? null, 'cheque_bank_branch' => $data['cheque_bank_branch'] ?? null,
        ]];
        $voucherCount = 0;
        foreach ($lines as $i => &$line) {
            $mode = PaymentMode::find((int) ($line['payment_mode_id'] ?? 0));
            if (! $mode || ! $mode->is_active || $mode->type === 'city_ledger') {
                throw ValidationException::withMessages([$usingSplit ? "payments.{$i}.payment_mode_id" : 'advance_mode' => 'Choose an active direct payment mode for the deposit.']);
            }
            if (filled($line['momo_network'] ?? null) || filled($line['momo_phone'] ?? null)) {
                $line['reference'] = mb_substr(trim(implode(' · ', array_filter([
                    $line['momo_network'] ?? null, $line['momo_phone'] ?? null, $line['reference'] ?? null,
                ]))), 0, 80);
            }
            $line['amount'] = round((float) $line['amount'], 2);
            $line['payment_mode_id'] = (int) $mode->id;
            if (\App\Services\GiftVoucherService::isVoucherMode((int) $mode->id)) {
                $voucherCount++;
            }
        }
        unset($line);
        if ($voucherCount && count($lines) !== 1) {
            throw ValidationException::withMessages(['payments' => 'A gift voucher must be used as a single tender.']);
        }

        return $lines;
    }

    // ---------------- Walk-in ----------------
    public function walkIn(Request $request)
    {
        return view('frontdesk.walkin', [
            'guest' => new Guest,
            'fullGuest' => true,
            'roomTypes' => RoomType::where('is_active', true)->get(),
            'ratePlans' => RatePlan::where('is_active', true)->get(),
            'rooms' => AvailabilityService::freeRooms(bdate(), bdate()->addDay())->where('status', 'vacant'),
            'sources' => BusinessSource::where('is_active', true)->pluck('name', 'id'),
            'companies' => Company::where('is_active', true)->pluck('name', 'id'),
            'paymentModes' => PaymentMode::where('is_active', true)->where('type', '!=', 'city_ledger')->pluck('name', 'id'),
            'walkInSource' => BusinessSource::where('type', 'walk_in')->value('id'),
            'preRoom' => $request->integer('room_id'),
        ]);
    }

    public function storeWalkIn(Request $request)
    {
        $data = $request->validate([
            'guest.title' => 'nullable|string|max:10', 'guest.first_name' => 'required|string|max:80', 'guest.last_name' => 'nullable|string|max:80',
            'guest.phone' => 'required|string|max:30', 'guest.email' => 'nullable|email', 'guest.nationality' => 'nullable|string|max:60',
            'guest.city' => 'nullable|string|max:80', 'guest.id_type' => 'required|string|max:30', 'guest.id_number' => 'required|string|max:60',
            'guest.gender' => 'nullable|string|max:10', 'guest.dob' => 'nullable|date', 'guest.address' => 'nullable|string|max:255',
            'guest.passport_no' => 'nullable|string|max:40', 'guest.visa_expiry' => 'nullable|date', 'guest_id' => 'nullable|integer',
            'room_id' => 'required|integer|owned:rooms', 'nights' => 'required|integer|min:1|max:90',
            'rate_plan_id' => 'nullable|integer|owned:rate_plans', 'rate' => 'required|numeric|min:0',
            'adults' => 'required|integer|min:1|max:10', 'children' => 'nullable|integer|min:0|max:10',
            'business_source_id' => 'nullable|integer|owned:business_sources', 'company_id' => 'nullable|integer|owned:companies',
            'purpose' => 'nullable|string|max:60', 'special_request' => 'nullable|string|max:1000',
            'advance' => 'nullable|numeric|min:0', 'advance_mode' => 'nullable|integer|owned:payment_modes', 'advance_ref' => 'nullable|string|max:80',
            'cheque_bank_name' => 'nullable|string|max:100', 'cheque_number' => 'nullable|string|max:20', 'cheque_bank_branch' => 'nullable|string|max:100',
            'payments' => 'nullable|array|min:2|max:10',
            'payments.*.amount' => 'required|numeric|min:0.01', 'payments.*.payment_mode_id' => 'required|integer|owned:payment_modes',
            'payments.*.reference' => 'nullable|string|max:80', 'payments.*.momo_network' => 'nullable|string|max:20',
            'payments.*.momo_phone' => 'nullable|string|max:30', 'payments.*.cheque_bank_name' => 'nullable|string|max:100',
            'payments.*.cheque_number' => 'nullable|string|max:20', 'payments.*.cheque_bank_branch' => 'nullable|string|max:100',
        ]);
        $advanceLines = $this->advancePaymentLines($data);
        $room = Room::findOrFail($data['room_id']);
        $arr = bdate();
        $dep = bdate()->addDays((int) $data['nights']);
        if ($room->status !== 'vacant' || AvailabilityService::blockingStays($arr, $dep)->where('room_id', $room->id)->exists()) {
            throw ValidationException::withMessages(['room_id' => "Room {$room->room_no} is not available for {$data['nights']} night(s)."]);
        }

        $stay = DB::transaction(function () use ($data, $room, $arr, $dep, $advanceLines) {
            $guest = ! empty($data['guest_id']) ? Guest::findOrFail($data['guest_id']) : (Guest::whereNull('privacy_status')->where('phone', $data['guest']['phone'])->where('first_name', $data['guest']['first_name'])->first() ?? new Guest);
            if ($guest->isPrivacyLocked()) {
                $guest = new Guest; // erased / restricted profiles are never reused – a returning guest gets a fresh profile
            }
            $guest->fill(array_filter($data['guest'], fn ($v) => $v !== null && $v !== ''))->save();
            if ($guest->is_blacklisted) {
                throw ValidationException::withMessages(['guest.first_name' => 'Guest is blacklisted.']);
            }
            $res = Reservation::create([
                'reservation_no' => Numbering::next('reservation'), 'guest_id' => $guest->id, 'company_id' => $data['company_id'] ?? null,
                'business_source_id' => $data['business_source_id'] ?? BusinessSource::where('type', 'walk_in')->value('id'),
                'arrival_date' => $arr, 'departure_date' => $dep, 'adults' => $data['adults'], 'children' => $data['children'] ?? 0,
                'status' => 'confirmed', 'purpose' => $data['purpose'] ?? null, 'special_request' => $data['special_request'] ?? null,
                'billing_instruction' => ! empty($data['company_id']) && \App\Models\Company::find($data['company_id'])?->direct_billing ? 'company' : 'guest',
                'rate_contract_id' => \App\Services\ContractService::forCompany($data['company_id'] ?? null, $arr)?->id, 'created_by' => auth()->id(),
            ]);
            $type = $room->roomType;
            $plan = ! empty($data['rate_plan_id']) ? RatePlan::find($data['rate_plan_id']) : null;
            $extra = max(0, $data['adults'] - $type->base_occupancy) * (float) ($plan->extra_adult_rate ?? $type->extra_adult_rate);
            $stay = $res->rooms()->create([
                'room_type_id' => $room->room_type_id, 'room_id' => $room->id, 'rate_plan_id' => $plan?->id, 'guest_id' => $guest->id,
                'arrival_date' => $arr, 'departure_date' => $dep, 'rate' => $data['rate'], 'adults' => $data['adults'],
                'children' => $data['children'] ?? 0, 'extra_charge' => $extra, 'status' => 'reserved',
            ]);
            $stay = StayService::checkIn($stay, $room->id);
            foreach ($advanceLines as $line) {
                FolioService::payment($stay->folio, (float) $line['amount'], (int) $line['payment_mode_id'], $line['reference'] ?? null, 'advance', 'payment', [
                    'bank_name' => $line['cheque_bank_name'] ?? null, 'number' => $line['cheque_number'] ?? null, 'bank_branch' => $line['cheque_bank_branch'] ?? null,
                ]);
            }

            return $stay;
        });

        return redirect()->route('reservations.show', $stay->reservation_id)->with('success', "Walk-in checked in to room {$room->room_no}.");
    }

    // ---------------- Check-in ----------------
    public function checkInForm(ReservationRoom $stay)
    {
        abort_unless($stay->status === 'reserved', 404);
        $stay->load('reservation.guest', 'roomType', 'room', 'ratePlan');
        $free = AvailabilityService::freeRooms(bdate(), $stay->departure_date->max(bdate()->addDay()), null, $stay->id)->where('status', 'vacant');

        // v4.1: offers the desk can suggest while the guest registers (the presentation counts towards conversion)
        $upOffers = can_do('upsell.sell') ? \App\Services\UpsellService::offersFor($stay, 'desk') : collect();
        if ($upOffers->isNotEmpty()) {
            \App\Services\UpsellService::present($stay->reservation, 'desk', $upOffers->map(fn ($x) => $x['offer']->id));
        }

        return view('frontdesk.checkin', [
            'upOffers' => $upOffers, 'upOrders' => \App\Models\UpsellOrder::with('offer')->where('reservation_room_id', $stay->id)->orderByDesc('id')->get(),
            'stay' => $stay, 'guest' => $stay->reservation->guest, 'fullGuest' => true,
            'rooms' => $free->sort(function ($a, $b) use ($stay) {
                $aType = $a->room_type_id === $stay->room_type_id ? 0 : 1;
                $bType = $b->room_type_id === $stay->room_type_id ? 0 : 1;
                $aReadiness = match ($a->hk_status) { 'inspected' => 0, 'clean' => 1, default => 2 };
                $bReadiness = match ($b->hk_status) { 'inspected' => 0, 'clean' => 1, default => 2 };
                return ($aType <=> $bType) ?: ($aReadiness <=> $bReadiness) ?: strnatcasecmp((string) $a->room_no, (string) $b->room_no);
            })->values(),
            'paymentModes' => PaymentMode::where('is_active', true)->where('type', '!=', 'city_ledger')->pluck('name', 'id'),
        ]);
    }

    public function checkIn(Request $request, ReservationRoom $stay)
    {
        $data = $request->validate([
            'room_id' => 'required|integer|owned:rooms',
            'guest.title' => 'nullable|string|max:10', 'guest.first_name' => 'required|string|max:80', 'guest.last_name' => 'nullable|string|max:80',
            'guest.phone' => 'required|string|max:30', 'guest.email' => 'nullable|email', 'guest.nationality' => 'nullable|string|max:60',
            'guest.city' => 'nullable|string|max:80', 'guest.id_type' => 'required|string|max:30', 'guest.id_number' => 'required|string|max:60',
            'guest.gender' => 'nullable|string|max:10', 'guest.dob' => 'nullable|date', 'guest.address' => 'nullable|string|max:255',
            'guest.passport_no' => 'nullable|string|max:40', 'guest.visa_expiry' => 'nullable|date',
            'rate' => 'required|numeric|min:0', 'advance' => 'nullable|numeric|min:0', 'advance_mode' => 'nullable|integer|owned:payment_modes',
            'advance_ref' => 'nullable|string|max:80',
            'cheque_bank_name' => 'nullable|string|max:100', 'cheque_number' => 'nullable|string|max:20', 'cheque_bank_branch' => 'nullable|string|max:100',
            'payments' => 'nullable|array|min:2|max:10',
            'payments.*.amount' => 'required|numeric|min:0.01', 'payments.*.payment_mode_id' => 'required|integer|owned:payment_modes',
            'payments.*.reference' => 'nullable|string|max:80', 'payments.*.momo_network' => 'nullable|string|max:20',
            'payments.*.momo_phone' => 'nullable|string|max:30', 'payments.*.cheque_bank_name' => 'nullable|string|max:100',
            'payments.*.cheque_number' => 'nullable|string|max:20', 'payments.*.cheque_bank_branch' => 'nullable|string|max:100',
        ]);
        $advanceLines = $this->advancePaymentLines($data);
        DB::transaction(function () use ($data, $stay, $advanceLines) {
            // Match StayService's reservation → stay lock order before writing
            // registration data or the rate. Updating the stay first here used
            // to invert that order against cancellation and checkout.
            $reservation = Reservation::whereKey($stay->reservation_id)->lockForUpdate()->firstOrFail();
            $lockedStay = ReservationRoom::whereKey($stay->id)->lockForUpdate()->firstOrFail();
            $lockedStay->setRelation('reservation', $reservation);
            $reservation->guest->fill(array_filter($data['guest'], fn ($v) => $v !== null && $v !== ''))->save();
            $lockedStay->update(['rate' => $data['rate']]);
            $stay = StayService::checkIn($lockedStay, (int) $data['room_id']);
            foreach ($advanceLines as $line) {
                FolioService::payment($stay->folio, (float) $line['amount'], (int) $line['payment_mode_id'], $line['reference'] ?? null, 'advance', 'payment', [
                    'bank_name' => $line['cheque_bank_name'] ?? null, 'number' => $line['cheque_number'] ?? null, 'bank_branch' => $line['cheque_bank_branch'] ?? null,
                ]);
            }
        });

        return redirect()->route('reservations.show', $stay->reservation_id)->with('success', 'Guest checked in successfully.');
    }

    // ---------------- Check-out ----------------
    public function checkOutForm(ReservationRoom $stay)
    {
        abort_unless($stay->status === 'checked_in', 404);
        $stay->load('reservation.guest', 'reservation.company', 'room', 'roomType');
        $folio = FolioService::openForStay($stay);
        $stay->setRelation('folio', $folio);
        $checkoutFigures = StayService::quoteCheckoutFigures($stay);

        return view('frontdesk.checkout', [
            'stay' => $stay, 'folio' => $folio, 'totals' => $folio->totals(),
            'checkoutQuote' => StayService::quoteCheckout($stay),
            'checkoutDue' => $checkoutFigures['due'],
            'transactions' => $folio->transactions()->with('paymentMode')->orderBy('id')->get(),
            'paymentModes' => PaymentMode::where('is_active', true)->where('type', '!=', 'city_ledger')->pluck('name', 'id'),
            'companies' => Company::where('is_active', true)->pluck('name', 'id'),
            'openPos' => \App\Models\PosOrder::with('outlet')->where('reservation_room_id', $stay->id)->whereIn('status', ['open', 'billed'])->get(),
            'openLaundry' => \App\Services\LaundryService::pendingForStay($stay->id),
            'openTrips' => \App\Services\TransportService::pendingForStay($stay->id), // v3.3
            'openParking' => \App\Services\ParkingService::activeForStay($stay->id), // v6.6: car still in the car park / with the valet
            'deskOpen' => \App\Services\GuestMessageService::openFor($stay), // v3.5: messages / parcels still at the desk
            'deskWakeups' => \App\Models\WakeupCall::where('reservation_room_id', $stay->id)->where('status', 'scheduled')->orderBy('call_at')->get(),
            'deskItems' => \App\Models\LostFoundItem::where('reservation_room_id', $stay->id)->where('status', 'stored')->get(),
            'formcOpen' => \App\Services\FormCService::enabled() ? \App\Models\FormCRecord::where('reservation_room_id', $stay->id)->where('status', 'pending')->get() : collect(), // v3.4
            'forexOk' => \App\Services\ForexService::enabled() && can_do('forex.exchange') && \App\Services\FormCService::isForeign(\App\Services\FormCService::stayGuest($stay)),
        ]);
    }

    /** Validate and post split checkout tenders atomically on the caller's checkout transaction. */
    protected function postCheckoutPaymentAllocations(\App\Models\Folio $folio, array $allocations, float $expected, string $type): void
    {
        if (count($allocations) < 2 || count($allocations) > 10) {
            throw ValidationException::withMessages(['payments' => 'Use between two and ten payment tenders.']);
        }
        if (! in_array($type, ['payment', 'refund'], true)) {
            throw ValidationException::withMessages(['payments' => 'Choose a valid checkout settlement type.']);
        }
        $allocated = round(array_sum(array_map(fn ($line) => round((float) $line['amount'], 2), $allocations)), 2);
        if (abs($allocated - $expected) > 0.005) {
            throw ValidationException::withMessages(['payments' => 'Payment allocations must total '.money($expected).' to complete check-out.']);
        }
        $voucherCount = 0;
        foreach ($allocations as $i => $line) {
            $mode = PaymentMode::find((int) $line['payment_mode_id']);
            if (! $mode || ! $mode->is_active || $mode->type === 'city_ledger') {
                throw ValidationException::withMessages(["payments.{$i}.payment_mode_id" => 'Choose an active direct payment mode.']);
            }
            if (\App\Services\GiftVoucherService::isVoucherMode((int) $mode->id)) {
                $voucherCount++;
            }
        }
        if ($voucherCount && count($allocations) !== 1) {
            throw ValidationException::withMessages(['payments' => 'A gift voucher must be used as a single tender.']);
        }
        foreach ($allocations as $line) {
            $reference = trim((string) ($line['reference'] ?? ''));
            if (filled($line['momo_network'] ?? null) || filled($line['momo_phone'] ?? null)) {
                $reference = mb_substr(trim(implode(' · ', array_filter([
                    $line['momo_network'] ?? null, $line['momo_phone'] ?? null, $reference,
                ]))), 0, 80);
            }
            FolioService::payment($folio, (float) $line['amount'], (int) $line['payment_mode_id'], $reference ?: null, 'settlement', $type, [
                'bank_name' => $line['cheque_bank_name'] ?? null,
                'number' => $line['cheque_number'] ?? null,
                'bank_branch' => $line['cheque_bank_branch'] ?? null,
            ]);
        }
    }

    /** Checkout by direct payment must clear the whole folio balance, single or split tender. */
    protected function checkoutSettlementAmount(float $balance, $submitted): float
    {
        $expected = round($balance, 2);
        $amount = $submitted === null || $submitted === '' ? $expected : round((float) $submitted, 2);
        if (abs($amount - $expected) > 0.005) {
            throw ValidationException::withMessages(['amount' => 'The checkout payment must equal the full outstanding balance of '.money($expected).'.']);
        }

        return $expected;
    }

    public function checkOut(Request $request, ReservationRoom $stay)
    {
        $data = $request->validate([
            'settle' => 'required|in:payment,company,none',
            'amount' => 'nullable|numeric|min:0', 'payment_mode_id' => 'nullable|integer|owned:payment_modes',
            'reference' => 'nullable|string|max:80', 'momo_network' => 'nullable|string|max:20', 'momo_phone' => 'nullable|string|max:30',
            'company_id' => 'nullable|integer|owned:companies',
            'cheque_bank_name' => 'nullable|string|max:100', 'cheque_number' => 'nullable|string|max:20', 'cheque_bank_branch' => 'nullable|string|max:100',
            'payments' => 'nullable|array|min:2|max:10',
            'payments.*.amount' => 'required|numeric|min:0.01',
            'payments.*.payment_mode_id' => 'required|integer|owned:payment_modes',
            'payments.*.reference' => 'nullable|string|max:80',
            'payments.*.momo_network' => 'nullable|string|max:20', 'payments.*.momo_phone' => 'nullable|string|max:30',
            'payments.*.cheque_bank_name' => 'nullable|string|max:100',
            'payments.*.cheque_number' => 'nullable|string|max:20',
            'payments.*.cheque_bank_branch' => 'nullable|string|max:100',
            'override_reason' => 'nullable|string|max:200',
        ]);
        DB::transaction(function () use ($data, $stay) {
            // Reserve the stay and room before posting nights or settlement
            // entries. This keeps checkout in the same reservation → stay →
            // room → folio order as the lifecycle service and prevents two
            // desk requests from settling a stale balance concurrently.
            $reservation = Reservation::whereKey($stay->reservation_id)->lockForUpdate()->firstOrFail();
            $lockedStay = ReservationRoom::whereKey($stay->id)->lockForUpdate()->firstOrFail();
            $room = $lockedStay->room_id ? Room::whereKey($lockedStay->room_id)->lockForUpdate()->first() : null;
            if ($lockedStay->status !== 'checked_in') {
                throw ValidationException::withMessages(['settle' => 'This stay is no longer checked in.']);
            }
            $lockedStay->setRelation('reservation', $reservation);
            $lockedStay->setRelation('room', $room);
            $stay = $lockedStay;
            StayService::postPendingNights($stay, bdate());
            $folio = $stay->fresh()->folio;
            $bal = $folio->balance();
            $splitPayments = array_values($data['payments'] ?? []);
            if ($bal > 0) {
                if ($data['settle'] === 'payment') {
                    if ($splitPayments) {
                        $this->postCheckoutPaymentAllocations($folio, $splitPayments, round($bal, 2), 'payment');
                    } else {
                        if (empty($data['payment_mode_id'])) {
                            throw ValidationException::withMessages(['payment_mode_id' => 'Select payment mode.']);
                        }
                        $settlementAmount = $this->checkoutSettlementAmount($bal, $data['amount'] ?? null);
                        $reference = trim((string) ($data['reference'] ?? ''));
                        if (filled($data['momo_network'] ?? null) || filled($data['momo_phone'] ?? null)) {
                            $reference = mb_substr(trim(implode(' · ', array_filter([
                                $data['momo_network'] ?? null, $data['momo_phone'] ?? null, $reference,
                            ]))), 0, 80);
                        }
                        FolioService::payment($folio, $settlementAmount, (int) $data['payment_mode_id'], $reference ?: null, 'settlement', 'payment', [
                            'bank_name' => $data['cheque_bank_name'] ?? null, 'number' => $data['cheque_number'] ?? null, 'bank_branch' => $data['cheque_bank_branch'] ?? null,
                        ]);
                    }
                } elseif ($data['settle'] === 'company') {
                    $cid = $data['company_id'] ?? $stay->reservation->company_id;
                    if (! $cid) {
                        throw ValidationException::withMessages(['company_id' => 'Select a company for city-ledger transfer.']);
                    }
                    FolioService::transferToCityLedger($folio, (int) $cid, $data['override_reason'] ?? null);
                    $folio->update(['company_id' => $cid]);
                }
            } elseif ($bal < 0) {
                if ($splitPayments && $data['settle'] === 'payment') {
                    $this->postCheckoutPaymentAllocations($folio, $splitPayments, round(abs($bal), 2), 'refund');
                } elseif (! empty($data['payment_mode_id'])) {
                    $refundAmount = $this->checkoutSettlementAmount(abs($bal), $data['amount'] ?? null);
                    $refundReference = trim((string) ($data['reference'] ?? '')) ?: 'Refund at check-out';
                    if (filled($data['momo_network'] ?? null) || filled($data['momo_phone'] ?? null)) {
                        $refundReference = mb_substr(trim(implode(' · ', array_filter([
                            $refundReference, $data['momo_network'] ?? null, $data['momo_phone'] ?? null,
                        ]))), 0, 80);
                    }
                    FolioService::payment($folio, $refundAmount, (int) $data['payment_mode_id'], $refundReference, 'settlement', 'refund', [
                        'bank_name' => $data['cheque_bank_name'] ?? null, 'number' => $data['cheque_number'] ?? null, 'bank_branch' => $data['cheque_bank_branch'] ?? null,
                    ]);
                }
            }
            StayService::checkOut($stay->fresh(), false);
        });

        return redirect()->route('folios.invoice', $stay->fresh()->folio)->with('success', 'Checked out. Invoice generated.');
    }

    /** Post every outstanding outlet bill, laundry order and transport trip onto the folio. */
    public function prepareBill(ReservationRoom $stay)
    {
        abort_unless($stay->status === 'checked_in', 404);
        $done = StayService::assembleBill($stay);
        $parts = [];
        if ($done['pos']) {
            $parts[] = $done['pos'].' outlet bill(s) '.money($done['pos_total']);
        }
        if ($done['laundry']) {
            $parts[] = $done['laundry'].' laundry order(s) '.money($done['laundry_total']);
        }
        if ($done['trips']) {
            $parts[] = $done['trips'].' transport trip(s)';
        }
        $msg = $parts ? 'Posted to the bill: '.implode(', ', $parts).'.' : 'Nothing outstanding could be posted.';
        foreach ($done['skipped'] as $skip) {
            $msg .= ' Left alone: '.$skip.'.';
        }

        return back()->with('success', $msg);
    }

    // ---------------- Room move / amend ----------------
    public function moveForm(ReservationRoom $stay)
    {
        abort_unless($stay->status === 'checked_in', 404);
        $stay->load('room.roomType', 'reservation.guest', 'moves.fromRoom', 'moves.toRoom', 'moves.user');

        return view('frontdesk.move', [
            'stay' => $stay,
            'rooms' => AvailabilityService::freeRooms(bdate(), $stay->departure_date, null, $stay->id)->where('status', 'vacant'),
        ]);
    }

    public function move(Request $request, ReservationRoom $stay)
    {
        $data = $request->validate(['to_room_id' => 'required|integer|owned:rooms', 'new_rate' => 'nullable|numeric|min:0', 'reason' => 'required|string|max:255']);
        abort_unless($stay->status === 'checked_in', 422);
        StayService::moveRoom($stay, (int) $data['to_room_id'], isset($data['new_rate']) ? (float) $data['new_rate'] : null, $data['reason']);

        return redirect()->route('reservations.show', $stay->reservation_id)->with('success', 'Room moved successfully.');
    }

    public function amend(Request $request, ReservationRoom $stay)
    {
        $data = $request->validate(['departure_date' => 'required|date|after:'.bdate()->toDateString(), 'rate' => 'nullable|numeric|min:0']);
        abort_unless($stay->status === 'checked_in', 422);
        $conflict = AvailabilityService::blockingStays($stay->departure_date->min(Carbon::parse($data['departure_date'])), Carbon::parse($data['departure_date']))
            ->where('room_id', $stay->room_id)->where('id', '!=', $stay->id)->exists();
        if ($conflict) {
            return back()->with('error', 'Room is booked by another reservation in the extended period. Move the guest or the other booking first.');
        }
        $old = $stay->departure_date->format('d M');
        $stay->update(['departure_date' => $data['departure_date'], 'rate' => $data['rate'] ?? $stay->rate]);
        $res = $stay->reservation;
        $res->update(['departure_date' => $res->rooms()->max('departure_date')]);
        ActivityLogger::log('frontdesk', 'amend', "Amended stay {$res->reservation_no}: departure {$old} → ".Carbon::parse($data['departure_date'])->format('d M'), $stay);
        try {
            \App\Services\KeyCardService::onExtend($stay->fresh()); // v3.7: key cards re-validated to the new departure
            \App\Services\RobitaService::onExtend($stay->fresh());  // v7.57: so is the door credential
        } catch (\Throwable $e) {
            report($e);
        }

        return back()->with('success', 'Stay amended.');
    }

    public function grc(ReservationRoom $stay)
    {
        $stay->load('reservation.guest', 'reservation.company', 'reservation.source', 'room', 'roomType', 'ratePlan');

        return view('frontdesk.grc', ['stay' => $stay, 'g' => $stay->reservation->guest]);
    }
}
