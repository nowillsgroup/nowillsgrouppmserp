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
     * Rooms that the reservations desk may assign for a future stay.
     * Dirty rooms remain selectable (housekeeping will prepare them), but an
     * occupied room must never be assigned just because it has no reservation
     * line attached to it.
     */
    public static function reservationRooms($from, $to, ?int $roomTypeId = null, ?int $ignoreStayId = null): Collection
    {
        return self::freeRooms($from, $to, $roomTypeId, $ignoreStayId)
            ->reject(fn (Room $room) => $room->status === 'occupied')
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
        foreach ($types as $type) {
            foreach (CarbonPeriod::create($from, $to->copy()->subDay()) as $d) {
                $key = $d->toDateString();
                $used = $stays->where('room_type_id', $type->id)->filter(fn ($s) => $s->arrival_date->lte($d) && $s->departure_date->gt($d))->count();
                $let = $leased->where('room_type_id', $type->id)->filter(fn ($b) => $b['from'] <= $key && $b['to'] > $key)->count();
                $blocked = $outages->filter(fn ($o) => $o->room->room_type_id === $type->id && $o->starts_on->lte($d) && $o->ends_on->gt($d))->count();
                $out[$type->id][$key] = $type->rooms_count - $used - $let - $blocked - ($held[$type->id][$key] ?? 0);
            }
        }

        return ['types' => $types, 'matrix' => $out, 'held' => $held, 'from' => $from, 'days' => $days];
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
