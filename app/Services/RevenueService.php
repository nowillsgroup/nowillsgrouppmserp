<?php

namespace App\Services;

use App\Models\RateOverride;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\RmSnapshot;
use App\Models\RoomType;
use App\Models\YieldRule;
use App\Support\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Revenue management.
 *
 * Pricing: yield rules (occupancy band, lead time, weekday, season) adjust the best available rate (BAR) resolved by
 * RateService, cumulatively in priority order, then the room type's floor/ceiling and rounding apply.
 * Modes: off · recommend (suggestions are reviewed and published as single-day "rm" rate overrides) · auto (applied
 * live to every quote – booking engine, reservations, channel export).
 *
 * Demand: on-the-books (OTB) rooms and revenue per stay date, nightly OTB snapshots (pickup & pace) and a forecast
 * = OTB + the hotel's own historical net pickup for the remaining lead time, by weekday.
 */
class RevenueService
{
    public const MODES = ['off' => 'Off', 'recommend' => 'Recommend – I publish', 'auto' => 'Auto-pilot – live pricing'];

    public static function mode(): string
    {
        $m = (string) setting('rm_mode', 'off');

        return array_key_exists($m, self::MODES) ? $m : 'off';
    }

    public static function horizon(): int
    {
        return max(14, min(365, (int) setting('rm_horizon_days', 90)));
    }

    public static function rules(): Collection
    {
        return YieldRule::where('is_active', true)->orderBy('priority')->orderBy('id')->get();
    }

    // ───────────────────────── occupancy & pricing ─────────────────────────

    /**
     * Occupancy on the books for [from, from+days): [Y-m-d => ['hotel' => %, 'types' => [id => %], 'sold', 'cap', 'left' => [id => n]]].
     * Sold includes rooms held by group blocks; capacity excludes out-of-order rooms.
     */
    public static function occupancy(Carbon $from, int $days): array
    {
        $m = AvailabilityService::matrix($from->copy()->startOfDay(), max(1, $days));
        $out = [];
        foreach ($m['types'] as $type) {
            foreach ($m['matrix'][$type->id] ?? [] as $date => $sellable) {
                $cap = (int) $type->rooms_count;
                $sold = max(0, $cap - $sellable);
                $out[$date]['types'][$type->id] = $cap ? (int) min(100, round($sold * 100 / $cap)) : 100;
                $out[$date]['left'][$type->id] = $sellable;
                $out[$date]['sold'] = ($out[$date]['sold'] ?? 0) + $sold;
                $out[$date]['cap'] = ($out[$date]['cap'] ?? 0) + $cap;
            }
        }
        foreach ($out as $date => $o) {
            $out[$date]['hotel'] = $o['cap'] ? (int) min(100, round($o['sold'] * 100 / $o['cap'])) : 100;
        }

        return $out;
    }

    /** Recommended rate for one type/night from its base (BAR) rate. */
    public static function evaluate(RoomType $type, Carbon $date, float $base, array $occ, Collection $rules): array
    {
        $lead = (int) bdate()->copy()->startOfDay()->diffInDays($date->copy()->startOfDay(), false);
        $occH = (int) ($occ['hotel'] ?? 0);
        $occT = (int) ($occ['types'][$type->id] ?? 0);
        $rate = $base;
        $applied = [];
        $minStay = null;
        if ($lead >= 0) {
            foreach ($rules as $r) {
                if ($r->matches($type->id, $date, $lead, $occH, $occT)) {
                    $new = $r->apply($rate);
                    $applied[] = ['name' => $r->name, 'adj' => $r->adjustmentLabel(), 'delta' => round($new - $rate, 2)];
                    $rate = $new;
                    $minStay = $r->min_stay ? max($minStay ?? 1, (int) $r->min_stay) : $minStay;
                }
            }
        }
        $limit = null;
        if ($applied) {
            $step = (float) setting('rm_round_to', 50);
            if ($step > 0) {
                $rate = round($rate / $step) * $step;
            }
            [$rate, $limit] = self::clamp($type, $rate);
        }

        return ['base' => round($base, 2), 'rate' => round(max(0, $rate), 2), 'rules' => $applied, 'min_stay' => $minStay,
            'occ_hotel' => $occH, 'occ_type' => $occT, 'lead' => $lead, 'limit' => $limit];
    }

    protected static function clamp(RoomType $type, float $rate): array
    {
        if ($type->min_rate !== null && $rate < (float) $type->min_rate) {
            return [(float) $type->min_rate, 'floor'];
        }
        if ($type->max_rate !== null && $rate > (float) $type->max_rate) {
            return [(float) $type->max_rate, 'ceiling'];
        }

        return [$rate, null];
    }

    /** Auto-pilot: adjust RateService-resolved nights in place. $nights = [Y-m-d => resolved array]. */
    public static function applyLive(RoomType $type, array $nights, ?array $occ = null, ?Collection $rules = null): array
    {
        $today = bdate()->toDateString();
        $future = array_filter(array_keys($nights), fn ($d) => $d >= $today);
        if (! $future) {
            return $nights;
        }
        $rules ??= self::rules();
        if ($rules->isEmpty()) {
            return $nights;
        }
        $occ ??= self::occupancy(Carbon::parse(min($future)), Carbon::parse(min($future))->diffInDays(Carbon::parse(max($future))) + 1);
        foreach ($future as $d) {
            if (! empty($nights[$d]['fixed'])) {
                continue;
            }
            $e = self::evaluate($type, Carbon::parse($d), (float) $nights[$d]['rate'], $occ[$d] ?? [], $rules);
            if ($e['rules']) {
                $nights[$d]['rate'] = $e['rate'];
                $nights[$d]['dynamic'] = true;
                $nights[$d]['season'] = $nights[$d]['season'] ?? 'Dynamic';
                $nights[$d]['min_stay'] = max((int) ($nights[$d]['min_stay'] ?? 1), (int) ($e['min_stay'] ?? 1));
            }
        }

        return $nights;
    }

    /**
     * Pricing grid: [typeId][Y-m-d] => current (live) rate, base rate, recommendation, occupancy, rooms left,
     * fixed (a contract price – untouched) and published (an rm override is live).
     */
    public static function recommendations(Collection $types, Carbon $from, int $days): array
    {
        $live = RateService::calendar($types, $from, $days);
        $base = RateService::calendar($types, $from, $days, true);
        $occ = self::occupancy($from, $days);
        $rules = self::rules();
        $grid = [];
        foreach ($types as $type) {
            foreach ($base[$type->id] ?? [] as $date => $b) {
                $rec = self::evaluate($type, Carbon::parse($date), (float) $b['rate'], $occ[$date] ?? [], $rules);
                $cur = $live[$type->id][$date];
                $grid[$type->id][$date] = [
                    'current' => (float) $cur['rate'], 'base' => (float) $b['rate'], 'rec' => $rec, 'fixed' => ! empty($b['fixed']),
                    'published' => ($cur['source'] ?? null) === 'rm', 'stop' => $cur['stop'], 'left' => $occ[$date]['left'][$type->id] ?? null,
                    'occ_type' => $occ[$date]['types'][$type->id] ?? 0, 'occ_hotel' => $occ[$date]['hotel'] ?? 0,
                    'change' => ! empty($b['fixed']) ? false : abs($rec['rate'] - (float) $cur['rate']) >= 0.01,
                ];
            }
        }

        return ['grid' => $grid, 'occ' => $occ];
    }

    /**
     * Publish rates as single-day revenue-managed overrides. $cells = [typeId => [Y-m-d => rate]].
     * A rate equal to the base rate simply removes the rm override. Fixed (contract) dates and past dates are skipped.
     */
    public static function publish(array $cells): array
    {
        if (self::mode() === 'auto') {
            throw new RuntimeException('Auto-pilot is on – rates are already applied live. Switch to Recommend to publish manually.');
        }
        $stats = ['published' => 0, 'reverted' => 0, 'skipped' => 0];
        $today = bdate()->toDateString();
        $types = RoomType::whereIn('id', array_keys($cells))->get()->keyBy('id');
        DB::transaction(function () use ($cells, $today, $types, &$stats) {
            foreach ($cells as $typeId => $dates) {
                $type = $types[$typeId] ?? null;
                if (! $type) {
                    throw new RuntimeException('Unknown room type.');
                }
                $dates = array_filter($dates, fn ($v) => $v !== null && $v !== '');
                if (! $dates) {
                    continue;
                }
                $from = Carbon::parse(min(array_keys($dates)));
                $span = $from->diffInDays(Carbon::parse(max(array_keys($dates)))) + 1;
                $base = RateService::calendar(collect([$type]), $from, $span, true)[$type->id];
                $occ = self::occupancy($from, $span);
                $rules = self::rules();
                foreach ($dates as $date => $rate) {
                    $rate = round((float) $rate, 2);
                    if ($date < $today || ! isset($base[$date]) || ! empty($base[$date]['fixed'])) {
                        $stats['skipped']++;

                        continue;
                    }
                    if ($rate <= 0 || $rate > 99999999) {
                        throw new RuntimeException("Invalid rate for {$type->name} on {$date}.");
                    }
                    RateOverride::where('source', 'rm')->where('room_type_id', $type->id)->where('date_from', $date)->where('date_to', $date)->delete();
                    $minStay = self::evaluate($type, Carbon::parse($date), (float) $base[$date]['rate'], $occ[$date] ?? [], $rules)['min_stay'];
                    if (abs($rate - (float) $base[$date]['rate']) < 0.01 && ! $minStay) {
                        $stats['reverted']++;

                        continue;
                    }
                    RateOverride::create(['room_type_id' => $type->id, 'name' => 'Revenue management', 'date_from' => $date, 'date_to' => $date,
                        'rate' => $rate, 'min_stay' => $minStay ?: 1, 'stop_sell' => false, 'source' => 'rm', 'created_by' => auth()->id()]);
                    $stats['published']++;
                }
            }
        });
        ActivityLogger::log('revenue', 'publish', "Published {$stats['published']} day rate(s), reverted {$stats['reverted']}, skipped {$stats['skipped']}");

        return $stats;
    }

    /** Remove revenue-managed overrides in a range (back to the base / seasonal rates). */
    public static function reset(Carbon $from, Carbon $to, ?array $typeIds = null): int
    {
        $n = RateOverride::where('source', 'rm')->whereBetween('date_from', [$from->toDateString(), $to->toDateString()])
            ->when($typeIds, fn ($q) => $q->whereIn('room_type_id', $typeIds))->delete();
        ActivityLogger::log('revenue', 'reset', "Removed {$n} revenue-managed day rate(s) {$from->format('d M')} – {$to->format('d M Y')}");

        return $n;
    }

    // ───────────────────────── demand: OTB, snapshots, pickup, forecast ─────────────────────────

    /** Sellable capacity per type (active rooms not out of order). */
    public static function capacity(): Collection
    {
        return RoomType::where('is_active', true)->withCount(['rooms' => fn ($q) => $q->where('is_active', true)->whereNotIn('status', ['out_of_order', 'out_of_service'])])
            ->orderBy('base_rate')->get();
    }

    /**
     * On the books per stay date: [Y-m-d => ['rooms', 'revenue', 'held', 'types' => [id => ['rooms', 'revenue']]]].
     * Past dates count what actually stayed (checked in / out); future dates count confirmed stays.
     */
    public static function otb(Carbon $from, int $days): array
    {
        $to = $from->copy()->addDays($days);
        $stays = ReservationRoom::whereIn('status', ['reserved', 'checked_in', 'checked_out'])
            ->where('arrival_date', '<', $to->toDateString())->where('departure_date', '>', $from->toDateString())
            ->get(['id', 'room_type_id', 'arrival_date', 'departure_date', 'rate', 'extra_charge', 'status']);
        $held = AvailabilityService::heldRooms($from, $to);
        $out = [];
        for ($d = $from->copy(); $d->lt($to); $d->addDay()) {
            $key = $d->toDateString();
            $row = ['rooms' => 0, 'revenue' => 0.0, 'held' => 0, 'types' => []];
            foreach ($stays as $s) {
                if ($s->arrival_date->lte($d) && $s->departure_date->gt($d)) {
                    $row['rooms']++;
                    $row['revenue'] += $s->nightlyTotal();
                    $row['types'][$s->room_type_id]['rooms'] = ($row['types'][$s->room_type_id]['rooms'] ?? 0) + 1;
                    $row['types'][$s->room_type_id]['revenue'] = ($row['types'][$s->room_type_id]['revenue'] ?? 0) + $s->nightlyTotal();
                }
            }
            foreach ($held as $typeId => $byDate) {
                $row['held'] += (int) ($byDate[$key] ?? 0);
            }
            $row['revenue'] = round($row['revenue'], 2);
            $out[$key] = $row;
        }

        return $out;
    }

    /** Record tonight's on-the-books position for the next horizon days (idempotent per snapshot date). */
    public static function snapshot(?Carbon $asOf = null): int
    {
        $asOf ??= bdate()->copy();
        $from = $asOf->copy()->addDay()->startOfDay();

        return self::storeSnapshot($asOf, self::otb($from, self::horizon()));
    }

    /**
     * Rebuild the on-the-books position as it stood at the end of a past day from booking and cancellation times
     * (used to backfill history when revenue management is switched on).
     */
    public static function reconstruct(Carbon $day): int
    {
        $end = $day->copy()->endOfDay();
        $from = $day->copy()->addDay()->startOfDay();
        $to = $from->copy()->addDays(self::horizon());
        $stays = ReservationRoom::with('reservation:id,created_at,cancelled_at,status')
            ->whereIn('status', ['reserved', 'checked_in', 'checked_out', 'cancelled', 'no_show'])
            ->where('arrival_date', '<', $to->toDateString())->where('departure_date', '>', $from->toDateString())
            ->get()->filter(function ($s) use ($end) {
                $r = $s->reservation;
                if (! $r || $r->created_at->gt($end)) {
                    return false;
                }
                if (in_array($s->status, ['cancelled', 'no_show'])) {
                    return $r->cancelled_at && $r->cancelled_at->gt($end); // still on the books that night
                }

                return true;
            });
        $otb = [];
        for ($d = $from->copy(); $d->lt($to); $d->addDay()) {
            $key = $d->toDateString();
            foreach ($stays as $s) {
                if ($s->arrival_date->lte($d) && $s->departure_date->gt($d)) {
                    $otb[$key]['types'][$s->room_type_id]['rooms'] = ($otb[$key]['types'][$s->room_type_id]['rooms'] ?? 0) + 1;
                    $otb[$key]['types'][$s->room_type_id]['revenue'] = ($otb[$key]['types'][$s->room_type_id]['revenue'] ?? 0) + $s->nightlyTotal();
                }
            }
        }

        return self::storeSnapshot($day, $otb);
    }

    protected static function storeSnapshot(Carbon $asOf, array $otb): int
    {
        $rows = [];
        foreach ($otb as $date => $o) {
            foreach ($o['types'] ?? [] as $typeId => $t) {
                $rows[] = ['hotel_id' => Tenant::id(), 'snapshot_date' => $asOf->toDateString(), 'stay_date' => $date, 'room_type_id' => $typeId,
                    'rooms' => $t['rooms'], 'revenue' => round($t['revenue'], 2)];
            }
        }
        DB::transaction(function () use ($asOf, $rows) {
            RmSnapshot::where('snapshot_date', $asOf->toDateString())->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                RmSnapshot::insert($chunk);
            }
        });

        return count($rows);
    }

    /** Rooms on the books per stay date according to the snapshot taken at the end of $snapDate (null = no snapshot). */
    public static function snapshotRooms(string $snapDate): ?array
    {
        if (! RmSnapshot::where('snapshot_date', $snapDate)->exists() && ! self::snapshotTaken($snapDate)) {
            return null;
        }

        return RmSnapshot::where('snapshot_date', $snapDate)->groupBy('stay_date')->selectRaw('stay_date, SUM(rooms) r')->pluck('r', 'stay_date')
            ->mapWithKeys(fn ($r, $d) => [Carbon::parse($d)->toDateString() => (int) $r])->all();
    }

    /** An empty snapshot (nothing on the books) still counts as taken if the night audit ran that day. */
    protected static function snapshotTaken(string $date): bool
    {
        return \App\Models\NightAudit::where('business_date', $date)->exists() && RmSnapshot::where('snapshot_date', '>', $date)->exists();
    }

    /**
     * Net pickup curve from the hotel's own history: for past stay dates, how many of the rooms that finally stayed
     * were booked within L days of arrival. Returns [isoWeekday => [L => avg rooms]] (index 0 = all days).
     */
    public static function pickupCurve(int $lookback = 42): array
    {
        $today = bdate()->copy()->startOfDay();
        $start = $today->copy()->subDays($lookback);
        $stays = ReservationRoom::with('reservation:id,created_at')->whereIn('status', ['checked_in', 'checked_out'])
            ->where('arrival_date', '<', $today->toDateString())->where('departure_date', '>', $start->toDateString())->get();
        $first = $stays->min('arrival_date'); // learn only from the period that has history (new installs)
        if ($first && $first->gt($start)) {
            $start = $first->copy()->startOfDay();
        }
        $H = self::horizon();
        $sum = [];
        $cnt = [];
        for ($s = $start->copy(); $s->lt($today); $s->addDay()) {
            $leads = [];
            foreach ($stays as $st) {
                if ($st->arrival_date->lte($s) && $st->departure_date->gt($s) && $st->reservation) {
                    $leads[] = max(0, (int) $st->reservation->created_at->copy()->startOfDay()->diffInDays($s, false));
                }
            }
            sort($leads);
            foreach ([0, $s->dayOfWeekIso] as $bucket) {
                $cnt[$bucket] = ($cnt[$bucket] ?? 0) + 1;
                $i = 0;
                for ($L = 1; $L <= $H + 1; $L++) {
                    while ($i < count($leads) && $leads[$i] < $L) {
                        $i++;
                    }
                    $sum[$bucket][$L] = ($sum[$bucket][$L] ?? 0) + $i;
                }
            }
        }
        $curve = [];
        foreach ($sum as $bucket => $byL) {
            foreach ($byL as $L => $v) {
                $curve[$bucket][$L] = $v / max(1, $cnt[$bucket]);
            }
        }
        $curve['samples'] = $cnt;

        return $curve;
    }

    /** Forecast rows for [from, from+days). */
    public static function forecast(Carbon $from, int $days): array
    {
        $types = self::capacity();
        $cap = (int) $types->sum('rooms_count');
        $avgRate = $cap ? $types->sum(fn ($t) => (float) $t->base_rate * $t->rooms_count) / $cap : 0;
        $otb = self::otb($from, $days);
        $curve = self::pickupCurve();
        $today = bdate()->toDateString();
        $snap1 = self::snapshotRooms(bdate()->copy()->subDay()->toDateString());
        $snap7 = self::snapshotRooms(bdate()->copy()->subDays(7)->toDateString());
        $rows = [];
        foreach ($otb as $date => $o) {
            $d = Carbon::parse($date);
            $lead = (int) bdate()->copy()->startOfDay()->diffInDays($d, false);
            $sold = $o['rooms'] + $o['held'];
            $adr = $o['rooms'] ? $o['revenue'] / $o['rooms'] : 0.0;
            $dowSamples = $curve['samples'][$d->dayOfWeekIso] ?? 0;
            $bucket = $dowSamples >= 3 ? $d->dayOfWeekIso : 0;
            $pick = $date >= $today ? (float) ($curve[$bucket][$lead + 1] ?? 0) : 0.0;
            $fc = (int) min($cap, round($sold + $pick));
            $rows[$date] = [
                'date' => $date, 'dow' => $d->format('D'), 'weekend' => in_array($d->dayOfWeek, RateService::WEEKEND, true), 'lead' => $lead,
                'cap' => $cap, 'rooms' => $o['rooms'], 'held' => $o['held'], 'occ' => $cap ? round($sold * 100 / $cap, 1) : 0,
                'revenue' => $o['revenue'], 'adr' => round($adr, 2), 'revpar' => $cap ? round($o['revenue'] / $cap, 2) : 0,
                'pickup1' => $snap1 !== null && $date >= $today ? $o['rooms'] - ($snap1[$date] ?? 0) : null,
                'pickup7' => $snap7 !== null && $date >= $today ? $o['rooms'] - ($snap7[$date] ?? 0) : null,
                'fc_rooms' => $fc, 'fc_occ' => $cap ? round($fc * 100 / $cap, 1) : 0,
                'fc_revenue' => round($o['revenue'] + max(0, $fc - $sold) * ($adr ?: $avgRate) + $o['held'] * ($adr ?: $avgRate), 2),
            ];
        }

        return $rows;
    }

    /** Headline KPIs for the dashboard. */
    public static function kpis(array $rows): array
    {
        $agg = function (array $slice) {
            $cap = array_sum(array_column($slice, 'cap'));
            $rooms = array_sum(array_column($slice, 'rooms'));
            $held = array_sum(array_column($slice, 'held'));
            $rev = array_sum(array_column($slice, 'revenue'));

            return ['occ' => $cap ? round(($rooms + $held) * 100 / $cap, 1) : 0, 'adr' => $rooms ? round($rev / $rooms, 2) : 0,
                'revpar' => $cap ? round($rev / $cap, 2) : 0, 'revenue' => round($rev, 2), 'rooms' => $rooms,
                'fc_occ' => $cap ? round(array_sum(array_column($slice, 'fc_rooms')) * 100 / $cap, 1) : 0,
                'fc_revenue' => round(array_sum(array_column($slice, 'fc_revenue')), 2)];
        };
        $rows = array_values($rows);
        $since = bdate()->copy()->subDays(7)->startOfDay();
        $new = Reservation::with('rooms')->where('created_at', '>=', $since)->whereNotIn('status', ['cancelled'])->get();
        $cx = Reservation::with('rooms')->where('cancelled_at', '>=', $since)->get();
        $nights = fn ($res) => $res->sum(fn ($r) => $r->rooms->sum(fn ($s) => $s->nights));
        $value = fn ($res) => $res->sum(fn ($r) => $r->rooms->sum(fn ($s) => $s->nights * $s->nightlyTotal()));

        return [
            'd7' => $agg(array_slice($rows, 0, 7)), 'd30' => $agg(array_slice($rows, 0, 30)),
            'pickup' => ['bookings' => $new->count(), 'nights' => $nights($new), 'revenue' => round($value($new), 2),
                'cancellations' => $cx->count(), 'cx_nights' => $nights($cx), 'cx_revenue' => round($value($cx), 2)],
        ];
    }
}
