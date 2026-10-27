<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\RoomBlock;
use App\Models\Room;
use App\Models\RoomOutage;
use App\Models\RoomType;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AvailabilityService
{
    /** Stay lines that block inventory in [from, to). */
    public static function blockingStays($from, $to)
    {
        return ReservationRoom::whereIn('status', ['reserved', 'checked_in'])
            ->whereDate('arrival_date', '<', Carbon::parse($to)->toDateString())
            ->whereDate('departure_date', '>', Carbon::parse($from)->toDateString());
    }

    /** Sellable active-room counts for each night in [from, from + days). */
    public static function sellableRoomCounts(Carbon $from, int $days, ?Collection $roomRows = null): array
    {
        if ($days <= 0) {
            return [];
        }

        $from = $from->copy()->startOfDay();
        $roomRows ??= Room::where('is_active', true)->get(['id', 'status']);
        $to = $from->copy()->addDays($days);
        $roomIds = $roomRows->pluck('id')->map(fn ($id) => (int) $id)->all();
        $activeIds = array_fill_keys($roomIds, true);
        $baseUnavailable = array_fill_keys($roomRows->whereIn('status', ['out_of_order', 'out_of_service'])
            ->pluck('id')->map(fn ($id) => (int) $id)->all(), true);
        $businessDate = bdate()->copy()->startOfDay();
        $leases = LeaseService::roomBlocks($from, $to);
        $outages = RoomOutage::query()->whereIn('status', ['active', 'released'])
            ->whereDate('starts_on', '<', $to->toDateString())->whereDate('ends_on', '>', $from->toDateString())
            ->whereIn('room_id', $roomIds)
            ->get(['room_id', 'starts_on', 'ends_on', 'status', 'released_at']);
        $counts = [];

        for ($i = 0; $i < $days; $i++) {
            $dayDate = $from->copy()->addDays($i);
            $day = $dayDate->toDateString();
            // Current physical room flags cannot be projected onto historical nights.
            $blocked = $dayDate->lt($businessDate) ? [] : $baseUnavailable;
            foreach ($leases as $lease) {
                if ($lease['from'] <= $day && $lease['to'] > $day && isset($activeIds[(int) $lease['room_id']])) {
                    $blocked[(int) $lease['room_id']] = true;
                }
            }
            foreach ($outages as $outage) {
                $historical = $dayDate->lt($businessDate);
                if (! $historical && $outage->status !== 'active') {
                    continue;
                }
                $effectiveEnd = $outage->ends_on->toDateString();
                if ($historical && $outage->status === 'released' && $outage->released_at) {
                    $effectiveEnd = min($effectiveEnd, $outage->released_at->toDateString());
                }
                if ($outage->starts_on->toDateString() <= $day && $effectiveEnd > $day) {
                    $blocked[(int) $outage->room_id] = true;
                }
            }
            $counts[$day] = max(0, count($roomIds) - count(array_intersect_key($blocked, $activeIds)));
        }

        return $counts;
    }

    /** Physical rooms free for the whole range. */
    public static function freeRooms($from, $to, ?int $roomTypeId = null, ?int $ignoreStayId = null): Collection
    {
        $busy = self::blockingStays($from, $to)->whereNotNull('room_id')
            ->when($ignoreStayId, fn ($q) => $q->where('id', '!=', $ignoreStayId))
            ->pluck('room_id')
            ->merge(LeaseService::blockedRoomIds($from, $to)) // v5.2 rooms let as apartments are off nightly sale
            ->merge(RoomOutage::covering($from, $to)->pluck('room_id')); // dated desk blocks

        return Room::with('roomType')->where('is_active', true)
            ->whereNotIn('status', ['out_of_order', 'out_of_service'])
            ->whereNotIn('id', $busy)
            ->when($roomTypeId, fn ($q) => $q->where('room_type_id', $roomTypeId))
            ->orderBy('room_no')->get();
    }

    /**
     * Rooms that the reservations desk may assign for a stay.
     * Dirty rooms remain selectable (housekeeping will prepare them). The
     * physical occupied flag is a current snapshot, so only exclude it when
     * the requested interval includes the hotel's business date; other dates
     * are checked against the dated stay and block records above.
     */
    public static function reservationRooms($from, $to, ?int $roomTypeId = null, ?int $ignoreStayId = null): Collection
    {
        $businessDate = bdate()->copy()->startOfDay();
        $fromDate = Carbon::parse($from)->startOfDay();
        $toDate = Carbon::parse($to)->startOfDay();
        $includesBusinessDate = $fromDate->lte($businessDate) && $toDate->gt($businessDate);

        return self::freeRooms($fromDate, $toDate, $roomTypeId, $ignoreStayId)
            ->reject(fn (Room $room) => $includesBusinessDate && $room->status === 'occupied')
            ->values();
    }

    /** Whether a physical room is safe to assign for the requested dates. */
    public static function roomAvailableForReservation(int $roomId, $from, $to, ?int $ignoreStayId = null): bool
    {
        return self::reservationRooms($from, $to, null, $ignoreStayId)->contains('id', $roomId);
    }

    /**
     * Lock the same physical inventory rows used by every reservation writer.
     * Room-type rows are not inventory: two writers locking only a type row can
     * still both sell the last physical room.
     */
    public static function lockRoomInventory(array|Collection $roomTypeIds): void
    {
        $ids = collect($roomTypeIds)->filter()->map(fn ($id) => (int) $id)->unique()->sort()->values();
        if ($ids->isEmpty()) {
            return;
        }
        Room::whereIn('room_type_id', $ids)->where('is_active', true)->orderBy('id')->lockForUpdate()->get();
    }

    /**
     * Matrix [room_type_id][Y-m-d] => sellable count = active rooms − booked stays − unpicked group-block holds.
     * $exceptBlockId: treat that block's remaining hold as sellable (used when picking up from it).
     * Also returns 'held' [type][date] => rooms still held by blocks.
     */
    public static function matrix($from, int $days = 14, ?int $exceptBlockId = null, ?int $exceptContractId = null): array
    {
        $from = Carbon::parse($from)->startOfDay();
        $to = $from->copy()->addDays($days);
        $types = RoomType::where('is_active', true)->withCount(['rooms' => fn ($q) => $q->where('is_active', true)->whereNotIn('status', ['out_of_order', 'out_of_service'])])->get();
        $stays = self::blockingStays($from, $to)->get(['room_type_id', 'arrival_date', 'departure_date']);
        $held = self::heldRooms($from, $to, $exceptBlockId, $exceptContractId);
        $leased = LeaseService::roomBlocks($from, $to); // v5.2
        $outages = RoomOutage::covering($from, $to)->with('room:id,room_type_id,status')->get()
            ->filter(fn ($o) => $o->room && ! in_array($o->room->status, ['out_of_order', 'out_of_service'], true));
        $out = [];
        $capacity = [];
        foreach ($types as $type) {
            foreach (CarbonPeriod::create($from, $to->copy()->subDay()) as $d) {
                $key = $d->toDateString();
                $used = $stays->where('room_type_id', $type->id)->filter(fn ($s) => $s->arrival_date->lte($d) && $s->departure_date->gt($d))->count();
                $let = $leased->where('room_type_id', $type->id)->filter(fn ($b) => $b['from'] <= $key && $b['to'] > $key)->count();
                $blocked = $outages->filter(fn ($o) => $o->room->room_type_id === $type->id && $o->starts_on->lte($d) && $o->ends_on->gt($d))->count();
                $capacity[$type->id][$key] = max(0, $type->rooms_count - $let - $blocked);
                $out[$type->id][$key] = $capacity[$type->id][$key] - $used - ($held[$type->id][$key] ?? 0);
            }
        }

        return ['types' => $types, 'matrix' => $out, 'capacity' => $capacity, 'held' => $held, 'from' => $from, 'days' => $days];
    }

    /**
     * Remaining (unpicked) rooms held by active group blocks and (v2.7) unreleased contract allotments:
     * [type][Y-m-d] => count. $exceptContractId: that contract's own allotment is sellable to its bookings.
     */
    public static function heldRooms($from, $to, ?int $exceptBlockId = null, ?int $exceptContractId = null): array
    {
        $held = self::blockHeld($from, $to, $exceptBlockId);
        foreach (ContractService::heldRooms($from, $to, $exceptContractId) as $type => $byDate) {
            foreach ($byDate as $d => $n) {
                $held[$type][$d] = ($held[$type][$d] ?? 0) + $n;
            }
        }

        return $held;
    }

    protected static function blockHeld($from, $to, ?int $exceptBlockId = null): array
    {
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->startOfDay();
        $blocks = RoomBlock::with('lines')->whereIn('status', ['tentative', 'definite'])
            ->when($exceptBlockId, fn ($q) => $q->where('id', '!=', $exceptBlockId))
            ->whereDate('arrival_date', '<', $to->toDateString())->whereDate('departure_date', '>', $from->toDateString())->get();
        if ($blocks->isEmpty()) {
            return [];
        }
        $picked = self::pickedStays($blocks->pluck('id')->all());
        $held = [];
        foreach ($blocks as $b) {
            $start = $b->arrival_date->copy()->max($from);
            $end = $b->departure_date->copy()->min($to);
            for ($d = $start->copy(); $d->lt($end); $d->addDay()) {
                $key = $d->toDateString();
                foreach ($b->lines as $l) {
                    $p = $picked->where('block_id', $b->id)->where('room_type_id', $l->room_type_id)
                        ->filter(fn ($s) => $s->arrival_date->lte($d) && $s->departure_date->gt($d))->count();
                    $held[$l->room_type_id][$key] = ($held[$l->room_type_id][$key] ?? 0) + max(0, $l->rooms - $p);
                }
            }
        }

        return $held;
    }

    /** Active stays picked up from the given blocks (each with ->block_id). */
    public static function pickedStays(array $blockIds): Collection
    {
        $resToBlock = Reservation::whereIn('room_block_id', $blockIds)->pluck('room_block_id', 'id');

        return ReservationRoom::whereIn('reservation_id', $resToBlock->keys())->whereIn('status', ['reserved', 'checked_in'])
            ->get(['id', 'reservation_id', 'room_type_id', 'arrival_date', 'departure_date'])
            ->each(fn ($s) => $s->block_id = $resToBlock[$s->reservation_id]);
    }

    public static function typeAvailable(int $roomTypeId, $from, $to, ?int $exceptBlockId = null, ?int $exceptContractId = null, ?int $exceptReservationId = null): int
    {
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->startOfDay();
        $m = self::matrix($from, max(1, $from->diffInDays($to)), $exceptBlockId, $exceptContractId);
        $daily = $m['matrix'][$roomTypeId] ?? [];

        if ($exceptReservationId && $daily) {
            // Restore only this reservation's actual occupied nights. Adding the
            // reservation's total room count to every date can overbook when an
            // edit moves the stay onto different dates.
            $ownStays = ReservationRoom::where('reservation_id', $exceptReservationId)
                ->where('room_type_id', $roomTypeId)
                ->whereIn('status', ['reserved', 'checked_in'])
                ->whereDate('arrival_date', '<', $to->toDateString())
                ->whereDate('departure_date', '>', $from->toDateString())
                ->get(['arrival_date', 'departure_date']);

            foreach ($daily as $date => $count) {
                $night = Carbon::parse($date);
                $daily[$date] = $count + $ownStays->filter(fn ($stay) => $stay->arrival_date->lte($night) && $stay->departure_date->gt($night))->count();
            }
        }

        return $daily ? min($daily) : 0;
    }
}
