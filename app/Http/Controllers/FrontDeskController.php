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
use App\Models\RoomOutage;
use App\Models\RoomType;
use App\Services\ActivityLogger;
use App\Services\AvailabilityService;
use App\Services\CityLedgerService;
use App\Services\FolioService;
use App\Services\LeaseService;
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
        $filters = $request->validate([
            'type' => ['nullable', 'integer', 'min:1'],
            'room_status' => ['nullable', 'in:vacant,occupied,reserved,due_out,out_of_order,out_of_service'],
            'hk_status' => ['nullable', 'in:clean,dirty,inspected,touch_up'],
            // Accept the original combined filter URL while presenting distinct,
            // clearly labeled room-state and housekeeping controls in the view.
            'status' => ['nullable', 'in:vacant,occupied,reserved,due_out,out_of_order,out_of_service,clean,dirty,inspected,touch_up'],
        ]);
        $today = bdate();
        $todayString = $today->toDateString();
        $canViewFolio = can_do('folio.view');
        $canViewHousekeeping = can_do('housekeeping.view') || can_do('housekeeping.manage') || can_do('housekeeping.attendant');
        $roomStatus = $filters['room_status'] ?? null;
        $housekeepingStatus = $filters['hk_status'] ?? null;
        $legacyStatus = $filters['status'] ?? null;
        if (! $roomStatus && in_array($legacyStatus, ['vacant', 'occupied', 'reserved', 'due_out', 'out_of_order', 'out_of_service'], true)) {
            $roomStatus = $legacyStatus;
        } elseif (! $housekeepingStatus && in_array($legacyStatus, ['clean', 'dirty', 'inspected', 'touch_up'], true)) {
            $housekeepingStatus = $legacyStatus;
        }
        if (! $canViewHousekeeping) {
            $housekeepingStatus = null;
        }

        $relations = ['roomType', 'floor', 'currentStay.reservation.guest'];
        if ($canViewFolio) {
            $relations[] = 'currentStay.folio';
        }
        $allRooms = Room::with($relations)->where('is_active', true)
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('room_type_id', $type))
            ->orderBy('room_no')->get();
        $arrivingToday = ReservationRoom::with('reservation.guest')->where('status', 'reserved')
            ->whereDate('arrival_date', $todayString)->whereNotNull('room_id')->get()->keyBy('room_id');

        foreach ($allRooms as $room) {
            $room->view_status = $room->status;
            if ($room->status === 'occupied' && $room->currentStay && $room->currentStay->departure_date->lte($today)) {
                $room->view_status = 'due_out';
            } elseif ($room->status === 'vacant' && isset($arrivingToday[$room->id])) {
                $room->view_status = 'reserved';
                $room->arrival = $arrivingToday[$room->id];
            }
        }

        $counts = $allRooms->countBy('view_status');
        $hk = $canViewHousekeeping ? $allRooms->countBy('hk_status') : collect();
        $rooms = $allRooms
            ->when($roomStatus, fn ($collection) => $collection->filter(fn ($room) => $room->view_status === $roomStatus))
            ->when($housekeepingStatus, fn ($collection) => $collection->filter(fn ($room) => $room->hk_status === $housekeepingStatus));

        return view('frontdesk.rooms', [
            'floors' => $rooms->groupBy(fn ($room) => $room->floor?->name ?? 'Unassigned'),
            'counts' => $counts,
            'hk' => $hk,
            'types' => RoomType::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'totalRooms' => $allRooms->count(),
            'visibleRooms' => $rooms->count(),
            'roomStatusFilter' => $roomStatus,
            'housekeepingStatusFilter' => $housekeepingStatus,
            'canViewHousekeeping' => $canViewHousekeeping,
            'canViewFolio' => $canViewFolio,
            'today' => $today,
        ]);
    }

    public function tapeChart(Request $request)
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'days' => ['nullable', 'integer', 'between:7,31'],
        ]);
        $from = Carbon::parse($filters['from'] ?? bdate()->copy()->subDays(2)->toDateString());
        $days = (int) ($filters['days'] ?? 14);
        $to = $from->copy()->addDays($days);
        $today = bdate();
        $canDrag = can_do('frontdesk.roommove') || can_do('reservations.edit');
        $canMoveInHouse = can_do('frontdesk.roommove');
        $canViewReservation = can_do('reservations.view');
        $canViewHousekeeping = can_do('housekeeping.view') || can_do('housekeeping.manage') || can_do('housekeeping.attendant');
        $rooms = Room::with('roomType')->where('is_active', true)->orderBy('room_type_id')->orderBy('room_no')->get();
        $stays = ReservationRoom::with(['reservation.guest', 'roomType'])
            ->whereIn('status', ['reserved', 'checked_in', 'checked_out'])
            ->whereDate('arrival_date', '<', $to->toDateString())
            ->whereDate('departure_date', '>', $from->toDateString())
            ->orderBy('arrival_date')->orderBy('room_id')->orderBy('id')->get();
        $unassigned = $stays->whereNull('room_id')->where('status', 'reserved')->sortBy('arrival_date')->values();

        // One line under the day columns: the rooms sold on each night and what that is
        // as a share of the rooms that can be sold. A room that is out of order is not
        // for sale, so it must not sit in the denominator — counting it would understate
        // occupancy on every night of the window.
        $sellable = $rooms->filter(fn ($room) => ! in_array($room->status, ['out_of_order', 'out_of_service'], true))->count();

        // Rooms taken off the market for a stretch of nights — a maintenance block, a
        // leased apartment — cannot be sold on those nights, so they leave the
        // denominator there instead of silently inflating it. The chart draws them too.
        $offMarket = $rooms->filter(fn ($room) => in_array($room->status, ['out_of_order', 'out_of_service'], true))
            ->pluck('id')->flip()->map(fn () => true)->all();
        $outages = RoomOutage::covering($from, $to)->whereIn('room_id', $rooms->pluck('id'))->with('room:id,room_no')->get();
        $leases = LeaseService::roomBlocks($from, $to);
        $blocksByRoom = [];
        $blockedNights = [];
        $blockNotes = [];
        foreach ($outages as $outage) {
            if (isset($offMarket[$outage->room_id])) {
                continue; // already out of the denominator every night
            }
            $blockReason = RoomOutage::REASONS[$outage->reason] ?? $outage->reason;
            $blocksByRoom[$outage->room_id][] = [
                'from' => $outage->starts_on,
                'to' => $outage->ends_on,
                'title' => 'Room '.($outage->room?->room_no ?? $outage->room_id).' blocked '.
                    $outage->starts_on->format('d M').' – '.$outage->ends_on->format('d M').
                    ' ('.$blockReason.')',
                'short' => $blockReason,
            ];
            $blockNotes[] = 'room '.($outage->room?->room_no ?? $outage->room_id).' is blocked '.
                $outage->starts_on->format('j M').' – '.$outage->ends_on->format('j M').' ('.mb_strtolower($blockReason).')';
        }
        foreach ($leases as $lease) {
            if (isset($offMarket[$lease['room_id']])) {
                continue;
            }
            $blocksByRoom[$lease['room_id']][] = [
                'from' => Carbon::parse($lease['from']),
                'to' => Carbon::parse($lease['to']),
                'title' => 'Room '.($rooms->firstWhere('id', $lease['room_id'])?->room_no ?? $lease['room_id']).
                    ' let as an apartment '.$lease['from'].' – '.$lease['to'],
                'short' => 'Leased',
            ];
            $blockNotes[] = 'room '.($rooms->firstWhere('id', $lease['room_id'])?->room_no ?? $lease['room_id']).
                ' is let as an apartment from '.Carbon::parse($lease['from'])->format('j M').' to '.
                Carbon::parse($lease['to'])->format('j M');
        }
        foreach ($blocksByRoom as $roomId => $roomBlocks) {
            for ($i = 0; $i < $days; $i++) {
                $night = $from->copy()->addDays($i)->toDateString();
                foreach ($roomBlocks as $block) {
                    if ($block['from']->toDateString() <= $night && $block['to']->toDateString() > $night) {
                        // one flat key per room-night: the view only ever asks "is this cell off the market?"
                        $blockedNights[$roomId.'|'.$night] = true;
                    }
                }
            }
        }

        $dailyTotals = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $from->copy()->addDays($i);
            $night = $day->toDateString();
            $sold = $stays->filter(fn ($stay) => $stay->room_id
                && in_array($stay->status, ['reserved', 'checked_in'], true)
                // a tentative hold is not a sold room; it stays visible on the chart
                && $stay->reservation?->status !== 'tentative'
                && $stay->arrival_date->lte($day)
                && $stay->departure_date->gt($day))->count();
            $blocked = count(array_filter(array_keys($blockedNights), fn ($key) => str_ends_with($key, '|'.$night)));
            $sellableTonight = max(0, $sellable - $blocked);
            $dailyTotals[] = [
                'day' => $night,
                'label' => $day->format('l, j F Y'),
                'short' => $day->format('j F'),
                'occupied' => $sold,
                'sellable' => $sellableTonight,
                'blocked' => $blocked,
                'pct' => $sellableTonight > 0 ? (int) round($sold / $sellableTonight * 100) : 0,
            ];
        }

        // The window in one line: what the whole period sold, how full it ran and which
        // night filled most. Average occupancy is room-nights over sellable room-nights,
        // not the mean of the nightly percentages — those differ once a night is lost.
        $roomNights = array_sum(array_column($dailyTotals, 'occupied'));
        $sellableNights = array_sum(array_column($dailyTotals, 'sellable'));
        $peak = $dailyTotals === [] ? null : collect($dailyTotals)->sortByDesc('occupied')->first();
        $excludedRooms = $rooms->filter(fn ($room) => in_array($room->status, ['out_of_order', 'out_of_service'], true))
            ->map(fn ($room) => [
                'room_no' => $room->room_no,
                'reason' => $room->status === 'out_of_order' ? 'out of order' : 'out of service',
            ])->values()->all();
        $totalsSummary = [
            'roomNights' => $roomNights,
            'sellableNights' => $sellableNights,
            'sellable' => $sellable,
            'active' => $rooms->count(),
            'excluded' => $excludedRooms,
            'blockedNights' => array_sum(array_column($dailyTotals, 'blocked')),
            'blockNotes' => $blockNotes,
            'avgPct' => $sellableNights > 0 ? (int) round($roomNights / $sellableNights * 100) : 0,
            'peak' => $peak,
        ];

        return view('frontdesk.tapechart', [
            'from' => $from,
            'to' => $to,
            'days' => $days,
            'today' => $today,
            'rooms' => $rooms->groupBy(fn ($room) => $room->roomType?->name ?? 'Unassigned room type'),
            'byRoom' => $stays->whereNotNull('room_id')->groupBy('room_id'),
            'unassigned' => $unassigned,
            'dailyTotals' => $dailyTotals,
            'totalsSummary' => $totalsSummary,
            'blocksByRoom' => $blocksByRoom,
            'blockedNights' => $blockedNights,
            'hasDayUse' => $stays->contains(fn ($stay) => $stay->is_day_use),
            'canDrag' => $canDrag,
            'canMoveInHouse' => $canMoveInHouse,
            'canViewReservation' => $canViewReservation,
            'canViewHousekeeping' => $canViewHousekeeping,
            'summary' => [
                'rooms' => $rooms->count(),
                'stays' => $stays->count(),
                'arrivals' => $stays->filter(fn ($stay) => $stay->status === 'reserved'
                    && $stay->arrival_date->gte($from) && $stay->arrival_date->lt($to))->count(),
                'inHouse' => $stays->where('status', 'checked_in')->count(),
                'departures' => $stays->filter(fn ($stay) => $stay->status === 'checked_in'
                    && $stay->departure_date->gte($from) && $stay->departure_date->lt($to))->count(),
                'unassigned' => $unassigned->count(),
            ],
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

    public function arrivals(Request $request)
    {
        $filters = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'overdue' => ['nullable', 'boolean'],
        ]);
        $date = Carbon::parse($filters['date'] ?? bdate()->toDateString());
        $dateString = $date->toDateString();
        $includeOverdue = $request->boolean('overdue');

        $stays = ReservationRoom::with([
            'reservation.guest', 'reservation.company', 'reservation.businessSource', 'room', 'roomType',
        ])
            ->where('status', 'reserved')
            ->whereDate('arrival_date', '<=', $dateString)
            ->whereDate('arrival_date', '>=', $date->copy()->subDays($includeOverdue ? 30 : 0)->toDateString())
            ->orderBy('arrival_date')
            ->orderBy('room_id')
            ->orderBy('id')
            ->get();

        return view('frontdesk.list', [
            'stays' => $stays,
            'mode' => 'arrivals',
            'title' => 'Arrivals',
            'arrivalDate' => $date,
            'includeOverdue' => $includeOverdue,
            'arrivalSummary' => [
                'pending' => $stays->count(),
                'guests' => $stays->sum(fn ($stay) => (int) $stay->adults + (int) $stay->children),
                'prechecked' => $stays->filter(fn ($stay) => (bool) $stay->reservation?->prechecked_in_at)->count(),
                'unassigned' => $stays->whereNull('room_id')->count(),
            ],
        ]);
    }

    public function inHouse()
    {
        $canViewFolio = can_do('folio.view');
        $relations = ['reservation.guest', 'reservation.company', 'reservation.businessSource', 'room', 'roomType'];
        if ($canViewFolio) {
            $relations['folio'] = fn ($relation) => $relation->withListBalance();
        }

        $stays = ReservationRoom::with($relations)
            ->where('status', 'checked_in')
            ->orderBy('room_id')
            ->orderBy('departure_date')
            ->orderBy('id')
            ->get();
        $today = bdate();

        return view('frontdesk.list', [
            'stays' => $stays,
            'mode' => 'inhouse',
            'title' => 'In-House Guests',
            'inHouseDate' => $today,
            'canViewFolio' => $canViewFolio,
            'inHouseSummary' => [
                'stays' => $stays->count(),
                'guests' => $stays->sum(fn ($stay) => (int) $stay->adults + (int) $stay->children),
                'dueToday' => $stays->filter(fn ($stay) => $stay->departure_date->isSameDay($today))->count(),
                'pastDue' => $stays->filter(fn ($stay) => $stay->departure_date->lt($today))->count(),
            ],
        ]);
    }

    public function departures(Request $request)
    {
        $filters = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = Carbon::parse($filters['date'] ?? bdate()->toDateString());
        $canViewFolio = can_do('folio.view');
        $relations = ['reservation.guest', 'reservation.company', 'reservation.businessSource', 'room', 'roomType'];
        if ($canViewFolio) {
            $relations['folio'] = fn ($relation) => $relation->withListBalance();
        }

        $stays = ReservationRoom::with($relations)
            ->where('status', 'checked_in')
            ->whereDate('departure_date', '<=', $date->toDateString())
            ->orderBy('departure_date')
            ->orderBy('room_id')
            ->orderBy('id')
            ->get();

        return view('frontdesk.list', [
            'stays' => $stays,
            'mode' => 'departures',
            'title' => 'Departures',
            'departureDate' => $date,
            'canViewFolio' => $canViewFolio,
            'departureSummary' => [
                'due' => $stays->count(),
                'guests' => $stays->sum(fn ($stay) => (int) $stay->adults + (int) $stay->children),
                'overdue' => $stays->filter(fn ($stay) => $stay->departure_date->lt($date))->count(),
                'balance' => $canViewFolio ? round($stays->sum(fn ($stay) => max(0, (float) ($stay->folio?->listing_balance ?? 0))), 2) : null,
            ],
        ]);
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
        $activeCompanies = Company::where('is_active', true)->orderBy('name')->get(['id', 'name', 'direct_billing']);
        $companyBilling = $activeCompanies->mapWithKeys(fn (Company $company) => [$company->id => (bool) $company->direct_billing]);

        return view('frontdesk.walkin', [
            'guest' => new Guest,
            'fullGuest' => true,
            'roomTypes' => RoomType::where('is_active', true)->get(),
            'ratePlans' => RatePlan::where('is_active', true)->get(),
            'rooms' => AvailabilityService::freeRooms(bdate(), bdate()->addDay())->where('status', 'vacant'),
            'sources' => BusinessSource::where('is_active', true)->pluck('name', 'id'),
            'companies' => $activeCompanies->pluck('name', 'id'),
            'companyBilling' => $companyBilling,
            'paymentModes' => PaymentMode::where('is_active', true)->where('type', '!=', 'city_ledger')->pluck('name', 'id'),
            'walkInSource' => BusinessSource::where('type', 'walk_in')->value('id'),
            'preRoom' => $request->integer('room_id'),
        ]);
    }

    public function storeWalkInCompany(Request $request): \Illuminate\Http\JsonResponse
    {
        abort_unless(can_do('frontdesk.checkin') && can_do('credit.manage'), 403);

        $company = CityLedgerService::createFromDesk(
            $request->validate(CityLedgerService::rules()),
            'Walk-in check-in'
        );

        return response()->json(['company' => CityLedgerService::payload($company)], 201);
    }

    public function storeWalkIn(Request $request)
    {
        $data = $request->validate([
            'guest.title' => 'nullable|string|max:30', 'guest.custom_title' => 'required_if:guest.title,__other__|nullable|string|max:30|not_in:__other__',
            'guest.first_name' => 'required|string|max:80', 'guest.last_name' => 'nullable|string|max:80',
            'guest.phone' => 'required|string|max:30', 'guest.email' => 'nullable|email', 'guest.nationality' => 'nullable|string|max:60',
            'guest.city' => 'nullable|string|max:80', 'guest.id_type' => 'required|string|max:30', 'guest.id_number' => 'required|string|max:60',
            'guest.gender' => 'nullable|string|max:10', 'guest.dob' => 'nullable|date', 'guest.address' => 'nullable|string|max:255',
            'guest.passport_no' => 'nullable|string|max:40', 'guest.visa_expiry' => 'nullable|date', 'guest_id' => 'nullable|integer',
            'room_id' => 'required|integer|owned:rooms',
            'stay_type' => 'required|in:overnight,day_use',
            'nights' => 'required_if:stay_type,overnight|nullable|integer|min:1|max:90',
            'day_use_hours' => 'required_if:stay_type,day_use|nullable|integer|min:1|max:24',
            'day_use_until' => 'nullable|date_format:H:i',
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
        if (($data['guest']['title'] ?? null) === '__other__') {
            $data['guest']['title'] = trim((string) $data['guest']['custom_title']);
        }
        unset($data['guest']['custom_title']);
        if (! empty($data['guest_id']) && ! can_do('reservations.view')) {
            throw ValidationException::withMessages(['guest_id' => 'Existing guest profile lookup requires reservation-view access.']);
        }
        $advanceLines = $this->advancePaymentLines($data);
        $room = Room::findOrFail($data['room_id']);
        // Day use: the guest takes the room for a few hours today and leaves it free
        // for the night, so arrival and departure are the same business date.
        $dayUse = ($data['stay_type'] ?? 'overnight') === 'day_use';
        if ($dayUse && empty($data['day_use_until'])) {
            // The desk names a number of hours; the record keeps the hour the room runs
            // until, which is what the rosters, the tape chart and the folio read.
            $data['day_use_until'] = now()->addHours((int) ($data['day_use_hours'] ?? 4))->format('H:i');
        }
        // A day use is sold by the hour, exactly as the booking desk sells it: the desk
        // names the hours and the rate per hour, and the room is charged for the hours
        // bought — once, with no night in the sum.
        $hours = $dayUse ? (int) ($data['day_use_hours'] ?? 0) : 0;
        $hourly = $dayUse ? (float) $data['rate'] : 0.0;
        $dayCharge = $dayUse ? round($hours * $hourly, 2) : (float) $data['rate'];
        $arr = bdate();
        $dep = $dayUse ? $arr->copy() : bdate()->addDays((int) $data['nights']);
        // A day use still has to leave the room sellable tonight — it may be let again.
        $checkedThrough = $dayUse ? $arr->copy()->addDay() : $dep;
        if ($room->status !== 'vacant'
            || AvailabilityService::blockingStays($arr, $checkedThrough)->where('room_id', $room->id)->exists()
            || ! AvailabilityService::roomAvailableForReservation($room->id, $arr, $checkedThrough)) {
            throw ValidationException::withMessages(['room_id' => $dayUse
                ? "Room {$room->room_no} cannot be used for today's day use: it is not vacant, is blocked, or is needed tonight."
                : "Room {$room->room_no} is not available for {$data['nights']} night(s)."]);
        }

        $stay = DB::transaction(function () use ($data, $room, $arr, $dep, $advanceLines, $dayUse, $hours, $hourly, $dayCharge) {
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
                'arrival_date' => $arr, 'departure_date' => $dep, 'rate' => $dayCharge, 'adults' => $data['adults'],
                'children' => $data['children'] ?? 0, 'extra_charge' => $extra, 'status' => 'reserved',
                'is_day_use' => $dayUse, 'day_use_until' => $dayUse ? $data['day_use_until'] : null,
                'day_use_hours' => $dayUse ? $hours : null, 'day_use_rate' => $dayUse ? $hourly : null,
            ]);
            $stay = StayService::checkIn($stay, $room->id);
            foreach ($advanceLines as $line) {
                FolioService::payment($stay->folio, (float) $line['amount'], (int) $line['payment_mode_id'], $line['reference'] ?? null, 'advance', 'payment', [
                    'bank_name' => $line['cheque_bank_name'] ?? null, 'number' => $line['cheque_number'] ?? null, 'bank_branch' => $line['cheque_bank_branch'] ?? null,
                ]);
            }

            return $stay;
        });

        $message = $dayUse
            ? 'Day-use checked in to room '.$room->room_no." for {$hours} hour(s) at ".money($hourly)." an hour (".money($dayCharge)."), until {$data['day_use_until']}."
            : "Walk-in checked in to room {$room->room_no}.";

        return $this->checkInSuccessRedirect($stay->reservation_id, $message);
    }

    /** Send check-in staff to a page their role can actually open after success. */
    protected function checkInSuccessRedirect(int $reservationId, string $message)
    {
        if (can_do('reservations.view')) {
            return redirect()->route('reservations.show', $reservationId)->with('success', $message);
        }
        if (can_do('frontdesk.view')) {
            return redirect()->route('frontdesk.inhouse')->with('success', $message);
        }

        return redirect()->to(\App\Http\Controllers\Auth\LoginController::home(auth()->user()))->with('success', $message);
    }

    // ---------------- Check-in ----------------
    public function checkInForm(ReservationRoom $stay)
    {
        abort_unless($stay->status === 'reserved', 404);
        $stay->load('reservation.guest', 'roomType', 'room', 'ratePlan');
        $free = AvailabilityService::freeRooms(bdate(), $stay->departure_date->max(bdate()->addDay()), null, $stay->id)->where('status', 'vacant');

        // v4.1: only read or present desk offers to staff authorized to sell them.
        $canSellUpsell = can_do('upsell.sell');
        $upOffers = $canSellUpsell ? \App\Services\UpsellService::offersFor($stay, 'desk') : collect();
        $upOrders = $canSellUpsell
            ? \App\Models\UpsellOrder::with('offer')->where('reservation_room_id', $stay->id)->orderByDesc('id')->get()
            : collect();
        if ($upOffers->isNotEmpty()) {
            \App\Services\UpsellService::present($stay->reservation, 'desk', $upOffers->map(fn ($x) => $x['offer']->id));
        }

        return view('frontdesk.checkin', [
            'upOffers' => $upOffers, 'upOrders' => $upOrders,
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
            'guest.title' => 'nullable|string|max:30', 'guest.first_name' => 'required|string|max:80', 'guest.last_name' => 'nullable|string|max:80',
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

        return $this->checkInSuccessRedirect($stay->reservation_id, 'Guest checked in successfully.');
    }

    // ---------------- Check-out ----------------
    public function checkOutForm(ReservationRoom $stay)
    {
        abort_unless($stay->status === 'checked_in', 404);
        $stay->load('reservation.guest', 'reservation.company', 'room', 'roomType');
        $folio = FolioService::openForStay($stay);
        $stay->setRelation('folio', $folio);
        $canViewFolio = can_do('folio.view');
        $checkoutFigures = StayService::quoteCheckoutFigures($stay);
        $checkoutHolds = StayService::checkoutHolds($stay);

        return view('frontdesk.checkout', [
            'stay' => $stay, 'folio' => $folio, 'totals' => $folio->totals(),
            'checkoutFigures' => $checkoutFigures,
            'checkoutHolds' => $checkoutHolds,
            'checkoutQuote' => StayService::quoteCheckout($stay, $checkoutFigures, $checkoutHolds),
            'checkoutDue' => $checkoutFigures['due'],
            'canViewFolio' => $canViewFolio,
            'transactions' => $canViewFolio ? $folio->transactions()->with('paymentMode')->orderBy('id')->get() : collect(),
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

    /** Validate a checkout tender under the checkout transaction, for payments and refunds alike. */
    protected function checkoutDirectPaymentMode(int $modeId, string $field): PaymentMode
    {
        // Keep the mode stable until settlement commits if an administrator
        // deactivates or changes it at the same time.
        $mode = PaymentMode::whereKey($modeId)->lockForUpdate()->first();
        if (! $mode || ! $mode->is_active || $mode->type === 'city_ledger') {
            throw ValidationException::withMessages([$field => 'Choose an active direct payment mode.']);
        }

        return $mode;
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
        // Multiple modes are locked in ID order even when the tender rows are
        // entered in the opposite order, reducing split-settlement deadlocks.
        $modeIds = [];
        foreach ($allocations as $i => $line) {
            $modeIds[$i] = (int) $line['payment_mode_id'];
        }
        asort($modeIds, SORT_NUMERIC);
        $lockedModes = [];
        foreach ($modeIds as $i => $modeId) {
            $mode = $this->checkoutDirectPaymentMode($modeId, "payments.{$i}.payment_mode_id");
            $lockedModes[$i] = $mode;
            if ($mode->type === 'gift_voucher') {
                $voucherCount++;
            }
        }
        if ($voucherCount && count($allocations) !== 1) {
            throw ValidationException::withMessages(['payments' => 'A gift voucher must be used as a single tender.']);
        }
        foreach ($allocations as $i => $line) {
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
            ], $lockedModes[$i]);
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
            // Check before any folio lock: laundry delivery locks its order before
            // the folio, so the reverse order here could deadlock.
            StayService::assertLaundryClear($stay);
            StayService::postPendingNights($stay, bdate());
            $folio = FolioService::lockOpen($stay->fresh()->folio);
            // A locking read is current even if this outer transaction has an
            // older repeatable-read snapshot from the stay checks above.
            $bal = $folio->totals(FolioService::lockedTransactions($folio))['balance'];
            $splitPayments = array_values($data['payments'] ?? []);
            if ($bal > 0) {
                if ($data['settle'] === 'payment') {
                    if ($splitPayments) {
                        $this->postCheckoutPaymentAllocations($folio, $splitPayments, round($bal, 2), 'payment');
                    } else {
                        if (empty($data['payment_mode_id'])) {
                            throw ValidationException::withMessages(['payment_mode_id' => 'Select payment mode.']);
                        }
                        $mode = $this->checkoutDirectPaymentMode((int) $data['payment_mode_id'], 'payment_mode_id');
                        $settlementAmount = $this->checkoutSettlementAmount($bal, $data['amount'] ?? null);
                        $reference = trim((string) ($data['reference'] ?? ''));
                        if (filled($data['momo_network'] ?? null) || filled($data['momo_phone'] ?? null)) {
                            $reference = mb_substr(trim(implode(' · ', array_filter([
                                $data['momo_network'] ?? null, $data['momo_phone'] ?? null, $reference,
                            ]))), 0, 80);
                        }
                        FolioService::payment($folio, $settlementAmount, (int) $data['payment_mode_id'], $reference ?: null, 'settlement', 'payment', [
                            'bank_name' => $data['cheque_bank_name'] ?? null, 'number' => $data['cheque_number'] ?? null, 'bank_branch' => $data['cheque_bank_branch'] ?? null,
                        ], $mode);
                    }
                } elseif ($data['settle'] === 'company') {
                    $cid = $data['company_id'] ?? $stay->reservation->company_id;
                    if (! $cid) {
                        throw ValidationException::withMessages(['company_id' => 'Select a company for city-ledger transfer.']);
                    }
                    FolioService::transferToCityLedger($folio, (int) $cid, $data['override_reason'] ?? null);
                }
            } elseif ($bal < 0) {
                if ($splitPayments && $data['settle'] === 'payment') {
                    $this->postCheckoutPaymentAllocations($folio, $splitPayments, round(abs($bal), 2), 'refund');
                } elseif (! empty($data['payment_mode_id'])) {
                    $mode = $this->checkoutDirectPaymentMode((int) $data['payment_mode_id'], 'payment_mode_id');
                    $refundAmount = $this->checkoutSettlementAmount(abs($bal), $data['amount'] ?? null);
                    $refundReference = trim((string) ($data['reference'] ?? '')) ?: 'Refund at check-out';
                    if (filled($data['momo_network'] ?? null) || filled($data['momo_phone'] ?? null)) {
                        $refundReference = mb_substr(trim(implode(' · ', array_filter([
                            $refundReference, $data['momo_network'] ?? null, $data['momo_phone'] ?? null,
                        ]))), 0, 80);
                    }
                    FolioService::payment($folio, $refundAmount, (int) $data['payment_mode_id'], $refundReference, 'settlement', 'refund', [
                        'bank_name' => $data['cheque_bank_name'] ?? null, 'number' => $data['cheque_number'] ?? null, 'bank_branch' => $data['cheque_bank_branch'] ?? null,
                    ], $mode);
                }
            }
            StayService::checkOut($stay->fresh(), false);
        });

        return $this->checkOutSuccessRedirect($stay->fresh(), 'Checked out successfully.');
    }

    /** Route successful check-outs to a screen the staff member is authorized to open. */
    protected function checkOutSuccessRedirect(ReservationRoom $stay, string $message)
    {
        if (can_do('folio.view')) {
            return redirect()->route('folios.invoice', $stay->folio)->with('success', 'Checked out. Invoice generated.');
        }
        if (can_do('reservations.view')) {
            return redirect()->route('reservations.show', $stay->reservation_id)->with('success', $message);
        }
        if (can_do('frontdesk.view')) {
            return redirect()->route('frontdesk.departures')->with('success', $message);
        }

        return redirect()->to(\App\Http\Controllers\Auth\LoginController::home(auth()->user()))->with('success', $message);
    }

    /** Post eligible outlet bills and transport trips; laundry is resolved in its own workflow. */
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
        $businessDate = bdate();
        $moveUntil = $stay->departure_date->gt($businessDate) ? $stay->departure_date : $businessDate->copy()->addDay();
        $rooms = AvailabilityService::freeRooms($businessDate, $moveUntil, null, $stay->id)
            ->where('status', 'vacant')->values();

        return view('frontdesk.move', [
            'stay' => $stay,
            'rooms' => $rooms,
            'businessDate' => $businessDate,
            'moveUntil' => $moveUntil,
            'stayNights' => max(1, (int) $stay->arrival_date->diffInDays($stay->departure_date)),
            'canViewReservation' => can_do('reservations.view'),
        ]);
    }

    public function move(Request $request, ReservationRoom $stay)
    {
        $data = $request->validate(['to_room_id' => 'required|integer|owned:rooms', 'new_rate' => 'nullable|numeric|min:0', 'reason' => 'required|string|max:255']);
        abort_unless($stay->status === 'checked_in', 422);
        try {
            StayService::moveRoom($stay, (int) $data['to_room_id'], isset($data['new_rate']) ? (float) $data['new_rate'] : null, $data['reason']);
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return $this->roomMoveSuccessRedirect($stay->fresh(), 'Room moved successfully.');
    }

    /** Redirect room-move staff to a destination their role can open. */
    protected function roomMoveSuccessRedirect(ReservationRoom $stay, string $message)
    {
        if (can_do('reservations.view')) {
            return redirect()->route('reservations.show', $stay->reservation_id)->with('success', $message);
        }
        if (can_do('frontdesk.view')) {
            return redirect()->route('frontdesk.inhouse')->with('success', $message);
        }

        return redirect()->to(\App\Http\Controllers\Auth\LoginController::home(auth()->user()))->with('success', $message);
    }

    public function amend(Request $request, ReservationRoom $stay)
    {
        $data = $request->validate(['departure_date' => 'required|date|after:'.bdate()->toDateString(), 'rate' => 'nullable|numeric|min:0']);
        abort_unless($stay->status === 'checked_in', 422);

        $newDeparture = Carbon::parse($data['departure_date'])->toDateString();
        $oldDeparture = $stay->departure_date->toDateString();
        $oldRate = (float) $stay->rate;
        $newRate = array_key_exists('rate', $data) && $data['rate'] !== null ? (float) $data['rate'] : $oldRate;
        $dateChanged = $newDeparture !== $oldDeparture;
        $rateChanged = abs($newRate - $oldRate) > 0.005;
        $quote = null;

        if ($dateChanged) {
            try {
                $quote = StayService::changeDates($stay, $stay->arrival_date, $newDeparture);
            } catch (\RuntimeException $e) {
                return back()->withInput()->with('error', $e->getMessage());
            }
        }
        if ($rateChanged) {
            $stay->fresh()->update(['rate' => $newRate]);
            ActivityLogger::log('frontdesk', 'amend_rate', "Amended nightly rate for {$stay->reservation->reservation_no}: ".money($oldRate).' → '.money($newRate), $stay->fresh());
        }
        if (! $dateChanged && ! $rateChanged) {
            return back()->with('warning', 'No stay changes were submitted.');
        }

        $message = 'Stay amended.';
        if ($quote && ! $rateChanged) {
            $sign = $quote['delta_nights'] > 0 ? '+' : '';
            $message .= ' Expected room-charge change: '.$sign.$quote['delta_nights'].' night(s), '.money($quote['delta_room']).' plus tax '.money($quote['delta_tax']).'. Night audit posts the room charges; no folio line was posted here.';
        } elseif ($rateChanged) {
            $message .= ' The nightly rate is now '.money($newRate).'; night audit applies room charges, and this action did not post a folio line.';
        }

        return back()->with('success', $message);
    }

    public function grc(ReservationRoom $stay)
    {
        $stay->load('reservation.guest.documents', 'reservation.company', 'reservation.source', 'room', 'roomType', 'ratePlan');

        // The signed card is a private file: read it here, as a data URI, so the print
        // view never touches the storage disk and the signature cannot be hot-linked.
        $signature = null;
        if ($stay->grc_signature_path && \Illuminate\Support\Facades\Storage::disk('local')->exists($stay->grc_signature_path)) {
            $signature = 'data:image/png;base64,'.base64_encode(\Illuminate\Support\Facades\Storage::disk('local')->get($stay->grc_signature_path));
        }

        // The card lists documents by their human labels, so the view never reaches into
        // the model's type map.
        $documentTypes = $stay->reservation->guest->documents
            ->map(fn ($document) => \App\Models\GuestDocument::TYPES[$document->type] ?? $document->type)
            ->unique()->values()->all();

        return view('frontdesk.grc', [
            'stay' => $stay, 'g' => $stay->reservation->guest,
            'signature' => $signature, 'documentTypes' => $documentTypes,
        ]);
    }
}
