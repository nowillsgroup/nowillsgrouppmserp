<?php

namespace App\Services;

use App\Models\ReservationRoom;
use App\Models\ReservationWaitlist;
use App\Models\Room;
use App\Models\RoomAmenity;
use Illuminate\Support\Carbon;

/**
 * v7.41 Reservation Workspace — the room-board snapshot behind the workspace:
 * KPIs, room cards (photo block, stay panel, balances), and the right rail
 * (calendar events, status summary, arrivals, departures, VIPs, waitlist,
 * housekeeping).
 */
class ReservationWorkspaceService
{
    /** KPI values for one date (history: on-the-books; today: live statuses). */
    protected static function dayStats(\Illuminate\Support\Carbon $date, $rooms, bool $history = false, ?int $hotelId = null): array
    {
        $d = $date->toDateString();
        $ids = $rooms->pluck('id');
        $q = ReservationRoom::whereIn('room_id', $ids)
            ->whereDate('arrival_date', '<=', $d)->whereDate('departure_date', '>', $d);
        $q = $history
            ? (clone $q)->whereNotIn('status', ['cancelled', 'no_show'])
            : (clone $q)->whereIn('status', ['reserved', 'checked_in']);
        $covering = $q->get();
        $sold = $covering->where('status', '!=', 'reserved')->count() ?: $covering->count();
        $reserved = $covering->where('status', 'reserved')->count();

        // hotel scope (not room scope) so arrivals still without a room count
        $deskScope = fn ($q) => $hotelId ? $q->where('hotel_id', $hotelId) : $q->whereIn('room_id', $ids);
        $arrQ = $deskScope(ReservationRoom::query())->whereDate('arrival_date', $d);
        $depQ = $deskScope(ReservationRoom::query())->whereDate('departure_date', $d);
        $arrivals = ($history ? (clone $arrQ)->whereNotIn('status', ['cancelled', 'no_show']) : (clone $arrQ)->whereIn('status', ['reserved', 'checked_in']))->count();
        $departures = ($history ? (clone $depQ)->whereNotIn('status', ['cancelled', 'no_show']) : (clone $depQ)->whereIn('status', ['checked_in', 'checked_out']))->count();

        $total = max(1, $rooms->count());
        $blocked = $rooms->whereIn('status', ['out_of_order', 'maintenance', 'house_use'])->count();
        $occupied = $sold;

        return [
            'occupancy' => round(100 * $occupied / $total),
            'revenue' => (float) $covering->sum('rate'),
            'available' => max(0, $total - $occupied - $reserved - $blocked),
            'occupied' => $occupied,
            'reserved' => $reserved,
            'arrivals' => $arrivals,
            'departures' => $departures,
            'inhouse' => (int) $covering->sum(fn ($s) => (int) ($s->reservation->adults ?? 1) + (int) ($s->reservation->children ?? 0)),
            'vips' => (int) $covering->filter(fn ($s) => $s->guest?->is_vip ?? $s->reservation->guest?->is_vip ?? false)->count(),
        ];
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
    public static function snapshot(Carbon $date = null): array
    {
        $date = ($date ?? now())->startOfDay();
        $today = $date->toDateString();
        $hotelId = \App\Support\Tenant::id();

        $rooms = Room::with(['roomType', 'floor'])
            ->where('hotel_id', $hotelId)->where('is_active', true)
            ->orderBy('room_no')->get();

        $activeRoomIds = $rooms->pluck('id');
        $typeAmenities = [];
        foreach ($rooms->pluck('roomType')->filter()->unique('id') as $rt) {
            $typeAmenities[$rt->id] = array_values(array_filter(array_map('trim', explode(',', (string) ($rt->amenities ?? '')))));
        }

        // the stay each room is showing: in-house tonight, or the next arrival
        $stays = ReservationRoom::with(['reservation:id,guest_id,business_source_id,company_id,reservation_no,adults,children', 'reservation.guest:id,first_name,last_name,is_vip,nationality,phone,email', 'reservation.businessSource:id,name', 'guest:id,first_name,last_name,is_vip,nationality'])
            ->where('hotel_id', $hotelId)
            ->whereIn('status', ['reserved', 'checked_in'])
            ->whereDate('departure_date', '>', $today)
            ->whereIn('room_id', $activeRoomIds)
            ->orderBy('arrival_date')->get()
            ->groupBy('room_id');

        $cards = [];
        $arrivals = [];
        $departures = [];
        $vip = [];
        $sold = 0;
        $reservedCount = 0;
        $inHouseGuests = 0;
        $revenue = 0.0;

        foreach ($rooms as $room) {
            $stay = ($stays->get($room->id) ?? collect())
                ->first(fn ($s) => $s->arrival_date?->toDateString() <= $today && $s->departure_date?->toDateString() > $today)
                ?? ($stays->get($room->id) ?? collect())->first();

            $status = 'available';
            if (in_array($room->status, ['out_of_order', 'maintenance', 'house_use'], true)) {
                $status = $room->status; // out_of_order | maintenance | house_use
            } elseif ($stay && $stay->status === 'checked_in') {
                $status = 'occupied';
            } elseif ($stay && $stay->status === 'reserved') {
                $status = 'reserved';
            }

            $balance = null;
            $bestRate = null;
            if ($stay) {
                try {
                    $balance = (float) (StayService::quoteCheckoutFigures($stay)['balance'] ?? 0);
                } catch (\Throwable) {
                    $balance = null;
                }
                $bestRate = (float) $stay->rate;
            }

            $cards[] = [
                'room' => $room,
                'status' => $status,
                'stay' => $stay,
                'balance' => $balance,
                'rate' => $bestRate ?? (float) ($room->roomType->base_rate ?? 0),
                'amenities' => $typeAmenities[$room->room_type_id] ?? [],
                'arriving_today' => $stay && $stay->status === 'reserved' && $stay->arrival_date?->toDateString() === $today,
            ];

            if ($stay && $stay->status === 'checked_in') {
                $sold++;
                $inHouseGuests += (int) ($stay->reservation->adults ?? 1) + (int) ($stay->reservation->children ?? 0);
                $revenue += (float) $stay->rate;
                if ($stay->guest?->is_vip || $stay->reservation->guest?->is_vip) {
                    $vip[] = $stay;
                }
            } elseif ($stay && $stay->status === 'reserved') {
                $reservedCount++;
            }

        }

        // arrivals & departures for the date, regardless of room assignment —
        // an arrival with no room yet is still desk work (room_id is null until
        // it is assigned, so room-scoped queries would silently drop it)
        $arrivals = ReservationRoom::with(['reservation:id,guest_id,business_source_id,adults,children', 'reservation.guest:id,first_name,last_name,is_vip,nationality', 'reservation.businessSource:id,name', 'room:id,room_no'])
            ->where('hotel_id', $hotelId)
            ->whereDate('arrival_date', $today)
            ->whereIn('status', ['reserved', 'checked_in'])
            ->orderBy('room_id')->get();

        // departures today (regardless of room assignment state)
        $departures = ReservationRoom::with(['reservation:id,guest_id,business_source_id,adults,children', 'reservation.guest:id,first_name,last_name,is_vip,nationality', 'reservation.businessSource:id,name', 'room:id,room_no'])
            ->where('hotel_id', $hotelId)
            ->whereDate('departure_date', $today)
            ->whereIn('status', ['checked_in', 'checked_out'])
            ->orderBy('room_id')->get();

        $total = max(1, $rooms->count());
        $houseUse = $rooms->where('status', 'house_use')->count();
        $ooo = $rooms->whereIn('status', ['out_of_order', 'maintenance'])->count();

        // 7-day trend + day-over-day deltas: history (t-6..t-1) computed on
        // the books as of each date (stays not cancelled/no-show), today live.
        $history = [];
        for ($i = 6; $i >= 1; $i--) {
            $history[] = self::dayStats($date->copy()->subDays($i), $rooms, true, $hotelId);
        }
        $todayStats = [
            'occupancy' => round(100 * $sold / $total),
            'revenue' => $revenue,
            'available' => $rooms->count() - $sold - $reservedCount - $houseUse - $ooo,
            'occupied' => $sold,
            'reserved' => $reservedCount,
            'arrivals' => count($arrivals),
            'departures' => $departures->count(),
            'inhouse' => $inHouseGuests,
            'vips' => count($vip),
        ];
        $history[] = $todayStats;
        $keys = array_keys($todayStats);
        $trend = [];
        $deltas = [];
        foreach ($keys as $k) {
            $trend[$k] = array_map(fn ($d) => $d[$k], $history);
            $prev = (float) $history[count($history) - 2][$k];
            $now = (float) $todayStats[$k];
            $deltas[$k] = $prev <= 0 ? null : round(100 * ($now - $prev) / $prev, 1);
        }

        return [
            'date' => $date,
            'trend' => $trend,
            'deltas' => $deltas,
            'kpis' => [
                'occupancy' => round(100 * $sold / $total),
                'revenue' => $revenue,
                'available' => $rooms->count() - $sold - $reservedCount - $houseUse - $ooo,
                'occupied' => $sold,
                'reserved' => $reservedCount,
                'arrivals' => count($arrivals),
                'departures' => $departures->count(),
                'inhouse' => $inHouseGuests,
                'vips' => count($vip),
            ],
            'cards' => collect($cards),
            'arrivals' => collect($arrivals),
            'departures' => $departures,
            'vip' => collect($vip),
            'statusSummary' => [
                'Available' => [$rooms->count() - $sold - $reservedCount - $houseUse - $ooo, '#10b981'],
                'Occupied' => [$sold, '#3b82f6'],
                'Reserved' => [$reservedCount, '#f59e0b'],
                'House Use' => [$houseUse, '#a855f7'],
                'Out of Order' => [$ooo, '#ef4444'],
            ],
            'hk' => [
                'Dirty' => $rooms->where('hk_status', 'dirty')->count(),
                'Clean' => $rooms->whereIn('hk_status', ['clean', 'inspected'])->count(),
                'Touch-up' => $rooms->where('hk_status', 'touch_up')->count(),
            ],
            'waitlist' => ReservationWaitlist::where('hotel_id', $hotelId)
                ->with(['guest:id,first_name,last_name', 'roomType:id,name'])
                ->orderByDesc('created_at')->limit(5)->get(),
            // rooms the desk may assign for this night — the same source the
            // reservations screen and the assign endpoint validate against, so
            // the inline picker can never offer a room that would be rejected
            'assignable' => AvailabilityService::reservationRooms(
                $date->toDateString(),
                $date->copy()->addDay()->toDateString()
            ),
            'roomTypes' => $rooms->pluck('roomType')->filter()->unique('id')->values(),
            'floors' => $rooms->pluck('floor')->filter()->unique('id')->values(),
        ];
    }
}
