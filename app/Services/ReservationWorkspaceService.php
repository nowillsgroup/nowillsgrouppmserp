<?php

namespace App\Services;

use App\Models\ReservationRoom;
use App\Models\ReservationWaitlist;
use App\Models\Room;
use App\Models\RoomAmenity;
use App\Models\RoomOutage;
use Illuminate\Support\Carbon;

/**
 * v7.41 Reservation Workspace — the room-board snapshot behind the workspace:
 * KPIs, room cards (photo block, stay panel, balances), and the right rail
 * (calendar events, status summary, arrivals, departures, VIPs, waitlist,
 * housekeeping).
 */
class ReservationWorkspaceService
{
    /** KPI values for one date, interpreted against the hotel's business date. */
    protected static function dayStats(Carbon $date, $rooms, Carbon $businessDate, array $blockedStatusByRoom, ?int $hotelId = null): array
    {
        $d = $date->toDateString();
        $past = $date->lt($businessDate);
        $future = $date->gt($businessDate);
        $ids = $rooms->pluck('id');
        $statuses = $past ? ['reserved', 'checked_in', 'checked_out'] : ['reserved', 'checked_in'];
        $coveringQuery = ReservationRoom::with(['reservation:id,guest_id,adults,children', 'reservation.guest:id,is_vip', 'guest:id,is_vip'])
            ->whereIn('status', $statuses)
            ->whereDate('arrival_date', '<=', $d)
            ->whereDate('departure_date', '>', $d);
        $covering = ($hotelId ? $coveringQuery->where('hotel_id', $hotelId) : $coveringQuery->whereIn('room_id', $ids))->get();
        $byRoom = $covering->groupBy('room_id');
        $blockedSet = array_fill_keys(array_keys($blockedStatusByRoom), true);
        $occupiedIds = [];
        $reservedIds = [];

        foreach ($rooms as $room) {
            if (isset($blockedSet[$room->id])) {
                continue;
            }
            $stays = $byRoom->get($room->id, collect());
            $occupiedStay = $stays->first(fn ($stay) => in_array($stay->status, $past ? ['checked_in', 'checked_out'] : ['checked_in'], true));
            if ($occupiedStay || (! $past && $room->status === 'occupied')) {
                $occupiedIds[] = $room->id;
            } elseif ($stays->contains(fn ($stay) => $stay->status === 'reserved')) {
                $reservedIds[] = $room->id;
            }
        }

        $occupied = count($occupiedIds);
        $reserved = count($reservedIds);
        $blocked = count($blockedStatusByRoom);
        $total = $rooms->count();
        $guestStays = $covering->filter(function ($stay) use ($past, $future) {
            if ($past) {
                return in_array($stay->status, ['checked_in', 'checked_out'], true);
            }
            if ($future) {
                return in_array($stay->status, ['reserved', 'checked_in'], true);
            }
            return $stay->status === 'checked_in';
        });
        $revenueStays = $covering->filter(function ($stay) use ($past, $future) {
            if ($past) {
                return in_array($stay->status, ['checked_in', 'checked_out'], true);
            }
            return $future ? in_array($stay->status, ['reserved', 'checked_in'], true) : $stay->status === 'checked_in';
        });

        // Hotel-scoped so unassigned arrivals and departures remain visible.
        $deskScope = fn ($q) => $hotelId ? $q->where('hotel_id', $hotelId) : $q->whereIn('room_id', $ids);
        $arrQ = $deskScope(ReservationRoom::query())->whereDate('arrival_date', $d);
        $depQ = $deskScope(ReservationRoom::query())->whereDate('departure_date', $d);
        $deskStatuses = $past ? ['checked_in', 'checked_out'] : ['reserved', 'checked_in'];
        $departureStatuses = $past ? ['checked_in', 'checked_out'] : ($future ? ['reserved', 'checked_in'] : ['checked_in', 'checked_out']);
        $arrivals = (clone $arrQ)->whereIn('status', $deskStatuses)->count();
        $departures = (clone $depQ)->whereIn('status', $departureStatuses)->count();
        $occupancyRooms = $future ? $occupied + $reserved : $occupied;
        $sellableRooms = max(0, $total - $blocked);

        return [
            'occupancy' => $sellableRooms ? min(100, round(100 * $occupancyRooms / $sellableRooms)) : 0,
            'revenue' => (float) $revenueStays->sum('rate'),
            'available' => max(0, $total - $occupied - $reserved - $blocked),
            'occupied' => $occupied,
            'reserved' => $reserved,
            'arrivals' => $arrivals,
            'departures' => $departures,
            'inhouse' => (int) $guestStays->sum(fn ($stay) => (int) ($stay->reservation->adults ?? 1) + (int) ($stay->reservation->children ?? 0)),
            'vips' => (int) $guestStays->filter(fn ($stay) => $stay->guest?->is_vip ?? $stay->reservation?->guest?->is_vip ?? false)
                ->unique(fn ($stay) => $stay->guest_id ?? $stay->reservation?->guest_id ?? 'stay-'.$stay->id)->count(),
        ];
    }

    /** Current room flags plus date-bounded outage and lease restrictions for a short date range. */
    protected static function roomBlockStatusesForDates(array $dates, $rooms): array
    {
        if ($dates === []) {
            return [];
        }

        $roomIds = $rooms->pluck('id');
        $base = [];
        foreach ($rooms as $room) {
            if (in_array($room->status, ['out_of_order', 'out_of_service', 'maintenance', 'house_use'], true)) {
                $base[$room->id] = $room->status;
            }
        }
        $businessDate = bdate()->copy()->startOfDay();
        $maps = [];
        foreach ($dates as $date) {
            // Room.status is only the current snapshot; do not project it backward
            // onto historical dates. Dated outages and leases below remain accurate.
            $maps[$date->toDateString()] = $date->lt($businessDate) ? [] : $base;
        }

        $from = $dates[0]->toDateString();
        $to = end($dates)->copy()->addDay()->toDateString();
        $outages = RoomOutage::covering($from, $to)->whereIn('room_id', $roomIds)->get(['room_id', 'reason', 'starts_on', 'ends_on']);
        foreach ($outages as $outage) {
            $status = match ($outage->reason) {
                'maintenance', 'renovation' => 'maintenance',
                'vip', 'management', 'house', 'owner' => 'house_use',
                'group' => 'group_hold',
                'other' => 'other_block',
                default => 'out_of_order',
            };
            foreach ($dates as $date) {
                $key = $date->toDateString();
                if ($outage->starts_on->toDateString() <= $key && $outage->ends_on->toDateString() > $key) {
                    $maps[$key][$outage->room_id] ??= $status;
                }
            }
        }

        $leases = LeaseService::roomBlocks($from, $to);
        foreach ($leases as $lease) {
            foreach ($dates as $date) {
                $key = $date->toDateString();
                if ($lease['from'] <= $key && $lease['to'] > $key && $roomIds->contains($lease['room_id'])) {
                    $maps[$key][$lease['room_id']] ??= 'leased';
                }
            }
        }

        return $maps;
    }

    /** amenity name (lowercased substring) => icon sprite name */
    public const AMENITY_ICONS = [
        'air condition' => 'thermo', 'a/c' => 'thermo', 'ac' => 'thermo', 'wi-fi' => 'wifi', 'wifi' => 'wifi',
        'smart tv' => 'grid', 'tv' => 'grid', 'shower' => 'spa', 'bathtub' => 'spa', 'jacuzzi' => 'spa',
        'safe' => 'lock', 'coffee' => 'coffee', 'tea' => 'coffee', 'usb' => 'link', 'telephone' => 'phone',
        'phone' => 'phone', 'iron' => 'shirt', 'non-smoking' => 'shield', 'parking' => 'car', 'city view' => 'eye',
        'view' => 'eye', 'minibar' => 'cup', 'dining' => 'cup', 'lounge' => 'home', 'living' => 'home',
        'butler' => 'user', 'desk' => 'edit', 'work' => 'edit', 'bed' => 'bed', 'pool' => 'spa',
    ];

    public static function amenityIcon(string $name): string
    {
        $n = mb_strtolower($name);
        foreach (self::AMENITY_ICONS as $needle => $icon) {
            if (str_contains($n, $needle)) {
                return $icon;
            }
        }

        return 'star';
    }

    /** Tiny SVG sparkline for the KPI strip. */
    public static function spark(array $values, string $tone = 'green'): string
    {
        $vals = array_values($values);
        if (count($vals) < 2) {
            $vals = [0, 1];
        }
        $min = min($vals);
        $max = max($vals);
        $span = max(1e-9, $max - $min);
        $pts = [];
        foreach ($vals as $i => $v) {
            $x = round($i * 78 / (count($vals) - 1) + 1, 1);
            $y = round(22 - (($v - $min) / $span) * 18, 1);
            $pts[] = $x.','.$y;
        }
        $color = $tone === 'red' ? '#ef4444' : '#10b981';

        return '<svg viewBox="0 0 80 24" class="ws-spark" aria-hidden="true"><polyline fill="none" stroke="'.$color.'" stroke-width="1.6" stroke-linejoin="round" points="'.implode(' ', $pts).'"/></svg>';
    }

    /** The full board for one date. */
    public static function snapshot(?Carbon $date = null): array
    {
        $businessDate = bdate()->copy()->startOfDay();
        $date = ($date ?? $businessDate->copy())->startOfDay();
        $selectedDay = $date->toDateString();
        $isHistory = $date->lt($businessDate);
        $isFuture = $date->gt($businessDate);
        $hotelId = \App\Support\Tenant::id();

        $rooms = Room::with(['roomType', 'floor'])
            ->where('hotel_id', $hotelId)->where('is_active', true)
            ->orderBy('room_no')->get();
        $trendDates = [];
        for ($i = 6; $i >= 0; $i--) {
            $trendDates[] = $date->copy()->subDays($i);
        }
        $roomBlockMaps = self::roomBlockStatusesForDates($trendDates, $rooms);
        $blockedStatusByRoom = $roomBlockMaps[$selectedDay] ?? [];

        $activeRoomIds = $rooms->pluck('id');
        $typeAmenities = [];
        foreach ($rooms->pluck('roomType')->filter()->unique('id') as $rt) {
            $typeAmenities[$rt->id] = array_values(array_filter(array_map('trim', explode(',', (string) ($rt->amenities ?? '')))));
        }

        // Only stays actually covering the selected night belong on its room board.
        // Past boards include completed stays so that a checked-out guest remains visible.
        $boardStatuses = $isHistory ? ['reserved', 'checked_in', 'checked_out'] : ['reserved', 'checked_in'];
        $stays = ReservationRoom::with(['reservation:id,guest_id,business_source_id,company_id,reservation_no,adults,children', 'reservation.guest:id,first_name,last_name,is_vip,nationality,phone,email', 'reservation.businessSource:id,name', 'guest:id,first_name,last_name,is_vip,nationality', 'folio'])
            ->where('hotel_id', $hotelId)
            ->whereIn('status', $boardStatuses)
            ->whereDate('arrival_date', '<=', $selectedDay)
            ->whereDate('departure_date', '>', $selectedDay)
            ->whereIn('room_id', $activeRoomIds)
            ->orderByRaw("CASE WHEN status = 'checked_in' THEN 0 WHEN status = 'checked_out' THEN 1 ELSE 2 END")
            ->orderBy('arrival_date')->get()
            ->groupBy('room_id');

        $cards = [];

        foreach ($rooms as $room) {
            $stay = ($stays->get($room->id) ?? collect())->first();

            $status = 'available';
            if (isset($blockedStatusByRoom[$room->id])) {
                $status = $blockedStatusByRoom[$room->id]; // dated outages and leases also remove future inventory from sale
            } elseif ($stay && in_array($stay->status, $isHistory ? ['checked_in', 'checked_out'] : ['checked_in'], true)) {
                $status = 'occupied';
            } elseif (! $isHistory && $room->status === 'occupied') {
                // Keep a physically occupied room out of sale even if its stay record is missing.
                // The current room flag cannot describe a historical room-board date.
                $status = 'occupied';
            } elseif ($stay && $stay->status === 'reserved') {
                $status = 'reserved';
            }

            // Two different numbers, and the labels matter:
            //   balance — what the folio carries right now
            //   due     — what checkout would collect, i.e. balance plus the
            //             nights and fixed charges still to post
            // The room card says "due", so it must be fed `due`; the modal says
            // "Balance", so it keeps `balance`. quoteCheckoutFigures() only
            // works on a checked-in stay, hence the fallback.
            $balance = null;
            $due = null;
            $bestRate = null;
            if ($stay) {
                if ($stay->status === 'checked_in') {
                    try {
                        $fig = StayService::quoteCheckoutFigures($stay);
                        $balance = (float) ($fig['balance'] ?? 0);
                        $due = (float) ($fig['due'] ?? $balance);
                    } catch (\Throwable) {
                        $balance = null;
                        $due = null;
                    }
                } elseif ($stay->status === 'checked_out') {
                    $balance = $stay->folio ? round((float) $stay->folio->balance(), 2) : null;
                    $due = $balance;
                }
                $bestRate = (float) $stay->rate;
            }

            $cards[] = [
                'room' => $room,
                'status' => $status,
                'stay' => $stay,
                'balance' => $balance,
                'due' => $due,
                'rate' => $bestRate ?? (float) ($room->roomType->base_rate ?? 0),
                'amenities' => $typeAmenities[$room->room_type_id] ?? [],
                'arriving_on_date' => $stay && $stay->status === 'reserved' && $stay->arrival_date?->toDateString() === $selectedDay,
            ];

        }

        // arrivals & departures for the date, regardless of room assignment —
        // an arrival with no room yet is still desk work (room_id is null until
        // it is assigned, so room-scoped queries would silently drop it)
        $deskStatuses = $isHistory ? ['checked_in', 'checked_out'] : ['reserved', 'checked_in'];
        $departureStatuses = $isHistory ? ['checked_in', 'checked_out'] : ($isFuture ? ['reserved', 'checked_in'] : ['checked_in', 'checked_out']);
        $arrivals = ReservationRoom::with(['reservation:id,guest_id,business_source_id,adults,children', 'reservation.guest:id,first_name,last_name,is_vip,nationality', 'reservation.businessSource:id,name', 'room:id,room_no,status'])
            ->where('hotel_id', $hotelId)
            ->whereDate('arrival_date', $selectedDay)
            ->whereIn('status', $deskStatuses)
            ->orderBy('room_id')->get();

        // Selected-date departures include reserved future stays and completed departures.
        $departures = ReservationRoom::with(['reservation:id,guest_id,business_source_id,adults,children', 'reservation.guest:id,first_name,last_name,is_vip,nationality', 'reservation.businessSource:id,name', 'room:id,room_no', 'folio'])
            ->where('hotel_id', $hotelId)
            ->whereDate('departure_date', $selectedDay)
            ->whereIn('status', $departureStatuses)
            ->orderBy('room_id')->get();

        $vipStatuses = $isHistory ? ['checked_in', 'checked_out'] : ($isFuture ? ['reserved', 'checked_in'] : ['checked_in']);
        $vip = ReservationRoom::with(['guest:id,first_name,last_name,is_vip,nationality', 'reservation:id,guest_id,adults,children', 'reservation.guest:id,first_name,last_name,is_vip', 'room:id,room_no'])
            ->where('hotel_id', $hotelId)
            ->whereIn('status', $vipStatuses)
            ->whereDate('arrival_date', '<=', $selectedDay)
            ->whereDate('departure_date', '>', $selectedDay)
            ->get()
            ->filter(fn ($stay) => $stay->guest?->is_vip || $stay->reservation?->guest?->is_vip)
            ->unique(fn ($stay) => $stay->guest_id ?? $stay->reservation?->guest_id ?? 'stay-'.$stay->id)
            ->values();

        // What each departure will actually settle. A checked-in stay still has
        // nights and fixed charges to post, so its figure is `due`; a stay that
        // has already checked out has a final folio balance and nothing more to
        // post. Reporting the raw balance here understated departures — one demo
        // guest showed GH₵ 774.40 when checkout would collect GH₵ 2,589.40.
        $departureFigures = [];
        foreach ($departures as $s) {
            if ($s->status === 'reserved') {
                $departureFigures[$s->id] = ['amount' => null, 'pending' => 0, 'unposted' => 0, 'blocked' => []];
                continue;
            }
            // A departure the desk cannot actually check out yet is worse than an
            // understated figure: it sends a guest to the cashier who will be turned
            // away. checkoutHolds() is the same read-only picture the checkout guard
            // is built from, so the board and the guard cannot disagree.
            $blocked = [];
            if ($s->status === 'checked_in') {
                $holds = StayService::checkoutHolds($s);
                $pl = fn (int $n, string $w) => $n.' '.$w.($n > 1 ? 's' : '');
                // The held money is worth showing: once the desk clears the hold and
                // the charge lands on the folio, the settlement figure rises by exactly
                // this much, so the number can be anticipated rather than discovered.
                $amt = fn (float $v) => $v > 0 ? ' ('.money($v).')' : '';
                if ($holds['pos']['count']) {
                    $blocked[] = $pl($holds['pos']['count'], 'unsettled POS order').$amt((float) $holds['pos']['total']);
                }
                if ($holds['trips']['count']) {
                    $blocked[] = $pl($holds['trips']['count'], 'transport trip').' not on the bill'.$amt((float) $holds['trips']['total']);
                }
                if ($holds['activities']['count']) {
                    $blocked[] = $pl($holds['activities']['count'], 'activity').' not confirmed'.$amt((float) $holds['activities']['total']);
                }
                if ($holds['formc']['count']) {
                    $blocked[] = 'Form C '.($holds['formc']['form_no'] ?: 'pending').' not filed';
                }
            }
            try {
                $fig = StayService::quoteCheckoutFigures($s);
                // 'unposted' is what checkout will still add on top of the folio as it
                // stands. It is a better asterisk signal than 'pending' (missing nights
                // only), which misses extra/early/late and unposted fixed charges.
                $departureFigures[$s->id] = [
                    'amount' => (float) ($fig['due'] ?? 0),
                    'pending' => (int) ($fig['missing'] ?? 0),
                    'unposted' => max(0, round((float) ($fig['due'] ?? 0) - (float) ($fig['balance'] ?? 0), 2)),
                    'blocked' => $blocked,
                ];
            } catch (\Throwable) {
                $bal = $s->folio ? round((float) $s->folio->balance(), 2) : null;
                $departureFigures[$s->id] = ['amount' => $bal, 'pending' => 0, 'unposted' => 0, 'blocked' => $blocked];
            }
        }

        // Month markers must describe the whole month, not only records loaded for the selected day.
        $monthStart = $date->copy()->startOfMonth();
        $monthEnd = $monthStart->copy()->addMonth();
        $monthArrivals = ReservationRoom::where('hotel_id', $hotelId)
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->whereDate('arrival_date', '>=', $monthStart->toDateString())
            ->whereDate('arrival_date', '<', $monthEnd->toDateString())
            ->pluck('arrival_date')->mapWithKeys(fn ($d) => [Carbon::parse($d)->toDateString() => true])->all();
        $monthDepartures = ReservationRoom::where('hotel_id', $hotelId)
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->whereDate('departure_date', '>=', $monthStart->toDateString())
            ->whereDate('departure_date', '<', $monthEnd->toDateString())
            ->pluck('departure_date')->mapWithKeys(fn ($d) => [Carbon::parse($d)->toDateString() => true])->all();

        // Keep each unassigned arrival's picker aligned to its complete stay range.
        $assignableByStay = [];
        foreach ($arrivals->whereNull('room_id')->where('status', 'reserved') as $arrival) {
            $assignableByStay[$arrival->id] = AvailabilityService::reservationRooms(
                $arrival->arrival_date->toDateString(),
                $arrival->departure_date->toDateString()
            );
        }

        $history = [];
        foreach ($trendDates as $trendDate) {
            $history[] = self::dayStats(
                $trendDate,
                $rooms,
                $businessDate,
                $roomBlockMaps[$trendDate->toDateString()] ?? [],
                $hotelId
            );
        }
        $selectedStats = $history[count($history) - 1];
        $keys = array_keys($selectedStats);
        $trend = [];
        $deltas = [];
        foreach ($keys as $k) {
            $trend[$k] = array_map(fn ($d) => $d[$k], $history);
            $prev = (float) $history[count($history) - 2][$k];
            $current = (float) $selectedStats[$k];
            $deltas[$k] = $prev <= 0 ? null : round(100 * ($current - $prev) / $prev, 1);
        }

        $blockedCounts = array_count_values(array_values($blockedStatusByRoom));
        $statusSummary = [
            'Available' => [$selectedStats['available'], '#10b981'],
            'Occupied' => [$selectedStats['occupied'], '#3b82f6'],
            'Reserved' => [$selectedStats['reserved'], '#f59e0b'],
            'House Use' => [$blockedCounts['house_use'] ?? 0, '#a855f7'],
            'Out of Order' => [$blockedCounts['out_of_order'] ?? 0, '#ef4444'],
            'Out of Service' => [$blockedCounts['out_of_service'] ?? 0, '#b91c1c'],
            'Leased' => [$blockedCounts['leased'] ?? 0, '#64748b'],
            'Maintenance' => [$blockedCounts['maintenance'] ?? 0, '#f97316'],
            'Group Hold' => [$blockedCounts['group_hold'] ?? 0, '#0ea5e9'],
            'Other Block' => [$blockedCounts['other_block'] ?? 0, '#64748b'],
        ];

        return [
            'date' => $date,
            'businessDate' => $businessDate,
            'dateMin' => $businessDate->copy()->subDays(62),
            'dateMax' => $businessDate->copy()->addDays(62),
            'isHistory' => $isHistory,
            'isFuture' => $isFuture,
            'inventoryBlocks' => $blockedStatusByRoom,
            'trend' => $trend,
            'deltas' => $deltas,
            'kpis' => $selectedStats,
            'cards' => collect($cards),
            'arrivals' => collect($arrivals),
            'departures' => $departures,
            'departureFigures' => $departureFigures,
            'vip' => collect($vip),
            'monthEvents' => ['arrivals' => $monthArrivals, 'departures' => $monthDepartures],
            'statusSummary' => $statusSummary,
            'hk' => [
                'Dirty' => $rooms->where('hk_status', 'dirty')->count(),
                'Clean' => $rooms->whereIn('hk_status', ['clean', 'inspected'])->count(),
                'Touch-up' => $rooms->where('hk_status', 'touch_up')->count(),
            ],
            'waitlist' => ReservationWaitlist::where('hotel_id', $hotelId)
                ->where('status', 'waiting')
                ->with(['guest:id,first_name,last_name', 'roomType:id,name'])
                ->orderByDesc('priority')->orderBy('arrival_date')->orderByDesc('created_at')->limit(5)->get(),
            // rooms the desk may assign for this night — the same source the
            // reservations screen and the assign endpoint validate against, so
            // the inline picker can never offer a room that would be rejected
            'assignableByStay' => $assignableByStay,
            'roomTypes' => $rooms->pluck('roomType')->filter()->unique('id')->values(),
            'floors' => $rooms->pluck('floor')->filter()->unique('id')->values(),
        ];
    }
}
