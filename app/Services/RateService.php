<?php

namespace App\Services;

use App\Models\RateOverride;
use App\Models\RoomType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Dynamic room pricing.
 *
 * Nightly rate for a room type on a date =
 *   the most recently created override covering that date that defines a price for it
 *     (weekend_rate on Fri/Sat nights if set, else rate), otherwise the room type's base rate.
 * Restrictions combine across all covering overrides: any stop-sell closes the date online,
 * the highest minimum stay applies.
 *
 * v1.9 revenue management: overrides published by revenue management (source = rm) are ignored for the *base* view
 * and in auto-pilot mode, where RevenueService adjusts the resolved rate live instead. Overrides marked fixed
 * (contract / event prices) are never adjusted.
 */
class RateService
{
    public const WEEKEND = [Carbon::FRIDAY, Carbon::SATURDAY];

    /** Overrides touching [from, to) for the given types, newest first. */
    protected static function overrides(array $typeIds, Carbon $from, Carbon $to, bool $withRm = true): Collection
    {
        return RateOverride::whereIn('room_type_id', $typeIds)->when(! $withRm, fn ($q) => $q->where('source', '!=', 'rm'))
            ->where('date_from', '<', $to->toDateString())->where('date_to', '>=', $from->toDateString())
            ->orderByDesc('id')->get();
    }

    /**
     * Resolve one night: newest override that defines a price for that night wins the price;
     * restrictions combine across every override covering the date (any stop-sell closes, highest min-stay applies).
     */
    protected static function resolve(RoomType $type, Collection $ovr, Carbon $d): array
    {
        $covering = $ovr->filter(fn ($o) => $o->room_type_id === $type->id && $o->date_from->lte($d) && $o->date_to->gte($d));
        $weekend = in_array($d->dayOfWeek, self::WEEKEND, true);
        $rate = null;
        $season = null;
        $source = null;
        foreach ($covering as $o) {
            $p = ($weekend && $o->weekend_rate !== null) ? $o->weekend_rate : $o->rate;
            if ($p !== null) {
                $rate = (float) $p;
                $season = $o->name;
                $source = $o->source ?? 'manual';
                break;
            }
        }

        return [
            'rate' => $rate ?? (float) $type->base_rate,
            'season' => $season ?? $covering->first()?->name,
            'stop' => $covering->contains('stop_sell', true),
            'min_stay' => (int) max(1, $covering->max('min_stay') ?? 1),
            'override' => $covering->isNotEmpty(),
            'weekend' => $weekend,
            'fixed' => $covering->contains('is_fixed', true),
            'source' => $source,
        ];
    }

    /**
     * Quote a stay. Returns nights[], total, average (rounded), min_stay, stop_sell (any night closed).
     */
    public static function quote(RoomType $type, $from, $to): array
    {
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->startOfDay();
        if ($to->lte($from)) {
            $to = $from->copy()->addDay();
        }
        $auto = RevenueService::mode() === 'auto';
        $ovr = self::overrides([$type->id], $from, $to, ! $auto);
        $resolved = [];
        for ($d = $from->copy(); $d->lt($to); $d->addDay()) {
            $resolved[$d->toDateString()] = self::resolve($type, $ovr, $d);
        }
        if ($auto) {
            $resolved = RevenueService::applyLive($type, $resolved);
        }
        $nights = [];
        $minStay = 1;
        $stop = false;
        foreach ($resolved as $date => $r) {
            $nights[] = ['date' => $date, 'rate' => $r['rate'], 'season' => $r['season']];
            $minStay = max($minStay, $r['min_stay']);
            $stop = $stop || $r['stop'];
        }
        $total = array_sum(array_column($nights, 'rate'));

        return ['nights' => $nights, 'count' => count($nights), 'total' => round($total, 2), 'average' => round($total / max(1, count($nights)), 2),
            'min_stay' => $minStay, 'stop_sell' => $stop, 'min_stay_ok' => count($nights) >= $minStay];
    }

    /**
     * Rate calendar grid: [typeId][Y-m-d] => ['rate', 'season', 'stop', 'min_stay', 'weekend', 'fixed', 'source', 'dynamic'?].
     * $base = the rate before revenue management (manual & seasonal overrides only, no live adjustment).
     */
    public static function calendar(Collection $types, Carbon $from, int $days, bool $base = false): array
    {
        $to = $from->copy()->addDays($days);
        $auto = ! $base && RevenueService::mode() === 'auto';
        $ovr = self::overrides($types->pluck('id')->all(), $from, $to, ! $base && ! $auto);
        $grid = [];
        foreach ($types as $type) {
            for ($d = $from->copy(); $d->lt($to); $d->addDay()) {
                $grid[$type->id][$d->toDateString()] = self::resolve($type, $ovr, $d);
            }
        }
        if ($auto) {
            $occ = RevenueService::occupancy($from, $days);
            $rules = RevenueService::rules();
            foreach ($types as $type) {
                $grid[$type->id] = RevenueService::applyLive($type, $grid[$type->id], $occ, $rules);
            }
        }

        return $grid;
    }
}
