<?php

namespace App\Services;

use App\Models\Guest;
use App\Models\Reservation;
use App\Models\RoomBlock;
use App\Models\RoomType;
use App\Support\Numbering;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Group room blocks (allotments). A block holds N rooms per type for its dates; the hold is removed
 * from sellable inventory everywhere (reservation form, booking engine, OTA iCal feed). Rooming-list
 * pickups turn held rooms into named reservations. At the cut-off date the night audit releases
 * whatever is still unpicked back to general inventory.
 */
class BlockService
{
    /** @param array<int,int> $lines room_type_id => rooms */
    public static function assertFits(array $lines, string $from, string $to, ?RoomBlock $block = null): void
    {
        $days = max(1, Carbon::parse($from)->diffInDays(Carbon::parse($to)));
        $m = AvailabilityService::matrix($from, $days, $block?->id)['matrix'];
        $picked = $block ? AvailabilityService::pickedStays([$block->id]) : collect();
        foreach ($lines as $typeId => $rooms) {
            foreach ($m[$typeId] ?? [] as $date => $free) {
                $d = Carbon::parse($date);
                $alreadyPicked = $picked->where('room_type_id', $typeId)->filter(fn ($s) => $s->arrival_date->lte($d) && $s->departure_date->gt($d))->count();
                if ($rooms - $alreadyPicked > $free) {
                    $name = RoomType::find($typeId)?->name;
                    throw ValidationException::withMessages(['lines' => "Only {$free} {$name} room(s) can be held on ".$d->format('d M').' (requested '.($rooms - $alreadyPicked).' more). Overbooking is not allowed.']);
                }
            }
        }
    }

    /** Pickup grid: [type_id][Y-m-d] => ['held' => block rooms, 'picked' => n]. */
    public static function grid(RoomBlock $block): array
    {
        $picked = AvailabilityService::pickedStays([$block->id]);
        $grid = [];
        foreach ($block->lines as $l) {
            for ($d = $block->arrival_date->copy(); $d->lt($block->departure_date); $d->addDay()) {
                $grid[$l->room_type_id][$d->toDateString()] = ['held' => $l->rooms,
                    'picked' => $picked->where('room_type_id', $l->room_type_id)->filter(fn ($s) => $s->arrival_date->lte($d) && $s->departure_date->gt($d))->count()];
            }
        }

        return $grid;
    }

    public static function pickupPct(RoomBlock $block): float
    {
        $g = self::grid($block);
        $held = collect($g)->flatten(1)->sum('held');

        return $held ? round(collect($g)->flatten(1)->sum(fn ($c) => min($c['picked'], $c['held'])) * 100 / $held, 1) : 0;
    }

    /** Same refusals as pickup(). Writes nothing and creates no guest. */
    public static function quotePickup(RoomBlock $block, array $names, int $roomTypeId, string $from, string $to): string
    {
        $names = array_values(array_filter(array_map('trim', $names)));
        if ($names === []) {
            throw new RuntimeException('Enter at least one guest name.');
        }
        if (! $block->isHolding()) {
            throw new RuntimeException('This block is '.$block->status.' – rooms are no longer held. Book normal reservations instead.');
        }
        if ($from < $block->arrival_date->toDateString() || $to > $block->departure_date->toDateString() || $to <= $from) {
            throw new RuntimeException('Pickup dates must fall within the block dates.');
        }
        $block->loadMissing('lines.roomType');
        if (! $block->lines->contains('room_type_id', $roomTypeId)) {
            throw new RuntimeException('That room type is not part of this block.');
        }
        $type = RoomType::findOrFail($roomTypeId);
        $free = AvailabilityService::typeAvailable($type->id, $from, $to, $block->id);
        if ($free < count($names)) {
            throw new RuntimeException('Only '.$free.' '.$type->name.' room(s) are left on this block for those dates. Nothing has been reserved.');
        }
        $rate = $block->rate !== null ? (float) $block->rate : (float) RateService::quote($type, $from, $to)['average'];
        $nights = max(1, Carbon::parse($from)->diffInDays(Carbon::parse($to)));
        $tax = TaxService::roomTax($rate);
        $each = round($nights * ($rate + $tax['amount']), 2);

        return count($names).' '.$type->name.' pickup(s) from '.$block->block_no.' at '.money($rate).' a night. Each stay would expect '.money($each).' room and tax. '.$free.' room(s) are free on the block before this pickup. Nothing is saved until you confirm.';
    }

    /**
     * Create one reservation per rooming-list name. All-or-nothing.
     * @return Reservation[]
     */
    public static function pickup(RoomBlock $block, array $names, int $roomTypeId, int $adults, string $from, string $to): array
    {
        if (! $block->isHolding()) {
            throw new RuntimeException('This block is '.$block->status.' – rooms are no longer held. Book normal reservations instead.');
        }
        if ($from < $block->arrival_date->toDateString() || $to > $block->departure_date->toDateString() || $to <= $from) {
            throw new RuntimeException('Pickup dates must fall within the block dates.');
        }
        if (! $block->lines->contains('room_type_id', $roomTypeId)) {
            throw new RuntimeException('That room type is not part of this block.');
        }
        $type = RoomType::findOrFail($roomTypeId);

        return DB::transaction(function () use ($block, $names, $type, $adults, $from, $to) {
            RoomBlock::whereKey($block->id)->lockForUpdate()->first();
            $rate = $block->rate !== null ? (float) $block->rate : RateService::quote($type, $from, $to)['average'];
            $made = [];
            foreach ($names as $i => $name) {
                if (AvailabilityService::typeAvailable($type->id, $from, $to, $block->id) < 1) {
                    throw new RuntimeException("No {$type->name} room left for {$name} (".($i).' picked before it). Nothing was saved – increase the block or pick up fewer names.');
                }
                $parts = preg_split('/\s+/', trim($name), 2);
                $guest = Guest::create(['first_name' => mb_substr($parts[0], 0, 80), 'last_name' => isset($parts[1]) ? mb_substr($parts[1], 0, 80) : null, 'company_id' => $block->company_id]);
                $res = Reservation::create([
                    'reservation_no' => Numbering::next('reservation'), 'guest_id' => $guest->id, 'company_id' => $block->company_id,
                    'business_source_id' => $block->business_source_id, 'booking_type' => 'group', 'group_name' => $block->name,
                    'arrival_date' => $from, 'departure_date' => $to, 'adults' => $adults, 'status' => 'confirmed',
                    'billing_instruction' => $block->billing_instruction, 'room_block_id' => $block->id, 'created_by' => auth()->id(),
                ]);
                $res->rooms()->create(['room_type_id' => $type->id, 'guest_id' => $guest->id, 'arrival_date' => $from, 'departure_date' => $to, 'rate' => $rate, 'adults' => $adults, 'status' => 'reserved']);
                $made[] = $res;
            }
            ActivityLogger::log('blocks', 'pickup', 'Picked up '.count($made)." {$type->name} room(s) from {$block->block_no}", $block);

            return $made;
        });
    }

    public static function release(RoomBlock $block, string $why): void
    {
        if (! $block->isHolding()) {
            return;
        }
        $block->forceFill(['status' => 'released', 'released_at' => now()])->save();
        ActivityLogger::log('blocks', 'release', "Released unpicked rooms of {$block->block_no} ({$why})", $block);
    }

    /** Night audit: release blocks whose cut-off has been reached. Returns count. */
    public static function releaseDue(Carbon $date): int
    {
        $due = RoomBlock::whereIn('status', ['tentative', 'definite'])->where('cutoff_date', '<=', $date->toDateString())->get();
        $due->each(fn ($b) => self::release($b, 'cut-off '.$b->cutoff_date->format('d M')));

        return $due->count();
    }
}
