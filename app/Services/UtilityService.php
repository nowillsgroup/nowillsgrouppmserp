<?php

namespace App\Services;

use App\Models\AccountHead;
use App\Models\MeterReading;
use App\Models\NightAudit;
use App\Models\ReservationRoom;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\UtilityBill;
use App\Models\UtilityMeter;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v3.9 – utilities & energy management.
 *  • Daily meter readings → consumption (× CT multiplier, register roll-over and meter replacement handled), cost at the
 *    meter tariff, and an anomaly flag when the daily rate deviates from the recent average by more than the threshold.
 *  • Utility bills reconciled with metered units for the billing period and booked as an AP expense bill.
 *  • KPIs per occupied room / guest night, cost as a share of revenue and the carbon footprint (Scope 1 + 2).
 */
class UtilityService
{
    public const DEFAULTS = [
        // kg CO2e per unit. Grid (v7.0 Ghana): Ember / lowcarbonpower.org 2023 generation-mix intensity ≈ 314 g/kWh (≈60 % gas, 38 % hydro).
        'eng_ef_electricity' => '0.314', 'eng_ef_dg' => '0', 'eng_ef_solar' => '0', 'eng_ef_water' => '0',
        'eng_ef_lpg' => '2.98', 'eng_ef_diesel' => '2.68', 'eng_ef_png' => '2.1',
        'eng_alert_pct' => '30', 'eng_require_readings' => '1',
    ];

    /** default expense head (system key) a utility's bill is booked to */
    public const BILL_HEADS = ['electricity' => 'utility_power', 'dg' => 'utility_fuel', 'solar' => 'utility_power', 'water' => 'utility_water',
        'lpg' => 'utility_fuel', 'diesel' => 'utility_fuel', 'png' => 'utility_fuel'];

    public static function cfg(): array
    {
        $g = fn ($k) => (string) setting($k, self::DEFAULTS[$k]);
        $ef = [];
        foreach (array_keys(UtilityMeter::UTILITIES) as $u) {
            $ef[$u] = max(0, (float) $g('eng_ef_'.$u));
        }

        return ['ef' => $ef, 'alert_pct' => max(5, min(500, (int) $g('eng_alert_pct'))), 'require_readings' => $g('eng_require_readings') === '1'];
    }

    public static function saveSettings(array $d): void
    {
        $map = ['eng_alert_pct' => (string) max(5, min(500, (int) ($d['alert_pct'] ?? 30))), 'eng_require_readings' => ! empty($d['require_readings']) ? '1' : '0'];
        foreach (array_keys(UtilityMeter::UTILITIES) as $u) {
            $map['eng_ef_'.$u] = (string) max(0, min(100, round((float) ($d['ef'][$u] ?? self::DEFAULTS['eng_ef_'.$u]), 4)));
        }
        foreach ($map as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        Setting::$version++;
        ActivityLogger::log('utilities', 'settings', 'Utility & energy settings updated');
    }

    // ───────────────────────────── meters ─────────────────────────────

    public static function saveMeter(array $d, ?UtilityMeter $m = null): UtilityMeter
    {
        if (! isset(UtilityMeter::UTILITIES[$d['utility'] ?? ''])) {
            throw new RuntimeException('Choose the utility this meter measures.');
        }
        $code = strtoupper(trim((string) ($d['code'] ?? '')));
        if ($code === '' || UtilityMeter::where('code', $code)->when($m, fn ($q) => $q->where('id', '!=', $m->id))->exists()) {
            throw new RuntimeException("Meter code \"{$code}\" is empty or already used.");
        }
        $parent = ! empty($d['parent_id']) ? UtilityMeter::findOrFail($d['parent_id']) : null;
        if ($parent && ($m && ($parent->id === $m->id || $parent->parent_id === $m->id))) {
            throw new RuntimeException('A meter cannot be a sub-meter of itself.');
        }
        if ($parent && $parent->utility !== $d['utility']) {
            throw new RuntimeException('A sub-meter must measure the same utility as its parent ('.$parent->utilityLabel().').');
        }
        $unit = isset(UtilityMeter::UNITS[$d['unit'] ?? '']) ? $d['unit'] : UtilityMeter::UTILITIES[$d['utility']][1];
        $data = [
            'code' => $code, 'name' => trim($d['name']), 'utility' => $d['utility'], 'unit' => $unit, 'meter_no' => $d['meter_no'] ?? null,
            'area' => $d['area'] ?? null, 'parent_id' => $parent?->id, 'multiplier' => max(0.001, (float) ($d['multiplier'] ?? 1)),
            'digits' => max(3, min(9, (int) ($d['digits'] ?? 7))), 'rate' => max(0, round((float) ($d['rate'] ?? 0), 4)),
            // a sub-meter never counts toward the hotel total – its consumption is already inside the parent
            'is_main' => $parent ? false : ! empty($d['is_main']),
            // 0 = no anomaly detection (standby meters); otherwise 5–500 %
            'alert_pct' => (isset($d['alert_pct']) && $d['alert_pct'] !== '' && (int) $d['alert_pct'] === 0) ? 0 : max(5, min(500, (int) ($d['alert_pct'] ?? self::cfg()['alert_pct']))),
            'is_active' => ! array_key_exists('is_active', $d) || ! empty($d['is_active']), 'notes' => $d['notes'] ?? null,
        ];
        $m ? $m->update($data) : $m = UtilityMeter::create($data);
        ActivityLogger::log('utilities', 'meter', "Meter {$m->code} – {$m->name} saved", $m);

        return $m;
    }

    // ───────────────────────────── readings ─────────────────────────────

    public static function previous(UtilityMeter $m, string $date): ?MeterReading
    {
        return MeterReading::where('utility_meter_id', $m->id)->whereDate('reading_date', '<', $date)->orderByDesc('reading_date')->orderByDesc('id')->first();
    }

    public static function next(UtilityMeter $m, string $date): ?MeterReading
    {
        return MeterReading::where('utility_meter_id', $m->id)->whereDate('reading_date', '>', $date)->orderBy('reading_date')->orderBy('id')->first();
    }

    /** consumption, days, cost and roll-over for a reading against its predecessor (throws on an impossible reading) */
    public static function compute(UtilityMeter $m, MeterReading $r, ?MeterReading $prev): void
    {
        if (! $prev) {                       // opening reading – the baseline, no consumption yet
            $r->forceFill(['consumption' => 0, 'days' => 0, 'cost' => 0, 'rolled_over' => false]);

            return;
        }
        $rolled = false;
        if ($r->meter_reset) {
            $delta = $r->reading;
        } else {
            $delta = round($r->reading - $prev->reading, 3);
            if ($delta < 0) {
                if ($prev->reading >= 0.8 * $m->rolloverAt()) {
                    $delta = round($m->rolloverAt() - $prev->reading + $r->reading, 3);
                    $rolled = true;
                } else {
                    throw new RuntimeException("{$m->code}: reading ".number_format($r->reading, 2).' is lower than the previous reading '.number_format($prev->reading, 2)
                        .' on '.$prev->reading_date->format('d M').". Tick \"meter replaced / reset\" if the meter was changed.");
                }
            }
        }
        $cons = round($delta * $m->multiplier, 3);
        $r->forceFill(['consumption' => $cons, 'days' => max(1, (int) $prev->reading_date->diffInDays($r->reading_date)), 'cost' => round($cons * $m->rate, 2), 'rolled_over' => $rolled]);
    }

    /** flag a day whose consumption rate is far from the average of the previous 14 readings */
    public static function detect(UtilityMeter $m, MeterReading $r): void
    {
        $r->anomaly = $r->anomaly_note = null;
        if ($r->days < 1 || $m->alert_pct <= 0) {          // opening reading, or detection switched off for this meter
            return;
        }
        $hist = MeterReading::where('utility_meter_id', $m->id)->whereDate('reading_date', '<', $r->reading_date->toDateString())->where('days', '>', 0)
            ->orderByDesc('reading_date')->limit(14)->get(['consumption', 'days']);
        if ($hist->count() < 3) {
            return;
        }
        // standby / intermittent meters (DG set, the diesel it burns) are idle most days – a daily average means nothing there
        if ($hist->filter(fn ($h) => $h->consumption > 0)->count() < 0.8 * $hist->count()) {
            return;
        }
        $avg = $hist->sum('consumption') / max(1, $hist->sum('days'));
        if ($avg <= 0) {
            return;
        }
        $rate = $r->consumption / $r->days;
        $pct = ($rate - $avg) / $avg * 100;
        $u = $m->unitShort();
        if ($rate == 0.0) {
            $r->anomaly = 'zero';
        } elseif ($pct > $m->alert_pct) {
            $r->anomaly = 'high';
        } elseif ($pct < -$m->alert_pct) {
            $r->anomaly = 'low';
        }
        if ($r->anomaly) {
            $r->anomaly_note = number_format($rate, 1)." {$u}/day vs ".number_format($avg, 1)." {$u}/day average (".($pct >= 0 ? '+' : '').number_format($pct, 0).'%)';
            $r->anomaly_ack_at = null;
        }
    }

    /** expected register reading on a date from the recent daily average (for the entry grid / estimates) */
    public static function expected(UtilityMeter $m, string $date): ?float
    {
        $prev = self::previous($m, $date);
        if (! $prev) {
            return null;
        }
        $hist = MeterReading::where('utility_meter_id', $m->id)->whereDate('reading_date', '<', $date)->where('days', '>', 0)->orderByDesc('reading_date')->limit(14)->get(['consumption', 'days']);
        $avg = $hist->count() ? $hist->sum('consumption') / max(1, $hist->sum('days')) : 0;
        $days = max(1, (int) $prev->reading_date->diffInDays(Carbon::parse($date)));

        return round($prev->reading + $avg * $days / max(0.001, $m->multiplier), 1);
    }

    /**
     * Record (or correct) the reading of a meter on a business date. The following reading is recomputed so a correction
     * never leaves a wrong consumption behind; an impossible chain rolls the whole change back.
     */
    public static function record(UtilityMeter $m, string $date, float $reading, array $o = []): MeterReading
    {
        if ($date > bdate()->toDateString()) {
            throw new RuntimeException('A reading cannot be dated after the business date ('.bdate()->format('d M Y').').');
        }
        if (! $m->is_active) {
            throw new RuntimeException("Meter {$m->code} is inactive.");
        }
        if ($reading < 0) {
            throw new RuntimeException("{$m->code}: a meter reading cannot be negative.");
        }

        return DB::transaction(function () use ($m, $date, $reading, $o) {
            $r = MeterReading::firstOrNew(['utility_meter_id' => $m->id, 'reading_date' => $date]);
            $corr = $r->exists;
            $r->forceFill(['reading' => round($reading, 3), 'is_estimated' => ! empty($o['estimated']), 'meter_reset' => ! empty($o['reset']),
                'note' => isset($o['note']) && trim((string) $o['note']) !== '' ? mb_substr(trim($o['note']), 0, 190) : ($corr ? $r->note : null), 'user_id' => auth()->id()]);
            $r->reading_date = Carbon::parse($date);
            self::compute($m, $r, self::previous($m, $date));
            self::detect($m, $r);
            $r->save();
            if ($nx = self::next($m, $date)) {
                self::compute($m, $nx, $r);
                self::detect($m, $nx);
                $nx->save();
            }
            if ($corr) {
                ActivityLogger::log('utilities', 'reading_edit', "Reading of {$m->code} for ".Carbon::parse($date)->format('d M Y').' corrected to '.number_format($reading, 2), $r);
            }

            return $r;
        });
    }

    /** the daily entry grid: [meter_id => ['reading'=>, 'estimated'=>, 'reset'=>, 'note'=>]] – blanks are skipped */
    public static function recordBatch(string $date, array $rows): array
    {
        $saved = 0;
        $errors = [];
        $flags = [];
        $meters = UtilityMeter::whereIn('id', array_keys($rows))->get()->keyBy('id');
        $existing = MeterReading::whereIn('utility_meter_id', $meters->keys())->whereDate('reading_date', $date)->get()->keyBy('utility_meter_id');
        foreach ($rows as $id => $row) {
            $val = trim((string) ($row['reading'] ?? ''));
            if ($val === '' || ! ($m = $meters->get((int) $id))) {
                continue;
            }
            if (! is_numeric($val)) {
                $errors[] = "{$m->code}: \"{$val}\" is not a number.";

                continue;
            }
            if (($cur = $existing->get($m->id)) && abs($cur->reading - (float) $val) < 0.0005 && $cur->is_estimated === ! empty($row['estimated'])
                && $cur->meter_reset === ! empty($row['reset']) && (string) $cur->note === trim((string) ($row['note'] ?? ''))) {
                continue;   // unchanged row of the grid – keep it (and any acknowledged flag) as it is
            }
            try {
                $r = self::record($m, $date, (float) $val, $row);
                $saved++;
                if ($r->anomaly) {
                    $flags[] = "{$m->code} ".MeterReading::ANOMALIES[$r->anomaly][0].' – '.$r->anomaly_note;
                }
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }
        if ($saved) {
            ActivityLogger::log('utilities', 'readings', "{$saved} meter reading(s) recorded for ".Carbon::parse($date)->format('d M Y'));
        }

        return ['saved' => $saved, 'errors' => $errors, 'anomalies' => $flags];
    }

    public static function deleteReading(MeterReading $r): void
    {
        $m = $r->meter;
        DB::transaction(function () use ($r, $m) {
            $date = $r->reading_date->toDateString();
            $r->delete();
            if ($nx = self::next($m, $date)) {
                self::compute($m, $nx, self::previous($m, $nx->reading_date->toDateString()));
                self::detect($m, $nx);
                $nx->save();
            }
        });
        ActivityLogger::log('utilities', 'reading_delete', "Reading of {$m->code} for ".$r->reading_date->format('d M Y').' deleted', $m);
    }

    public static function acknowledge(MeterReading $r, ?string $note = null): void
    {
        $r->forceFill(['anomaly_ack_at' => now(), 'note' => $note ? mb_substr(trim($note), 0, 190) : $r->note])->save();
        ActivityLogger::log('utilities', 'anomaly_ack', "Anomaly on {$r->meter->code} ".$r->reading_date->format('d M').' acknowledged'.($note ? ": {$note}" : ''), $r);
    }

    /** active meters with no reading on a date ($established: only meters already read before that date) */
    public static function missing(?string $date = null, bool $established = false): Collection
    {
        $date ??= bdate()->toDateString();

        // a later reading spans the gap (its consumption covers the missed day), so only meters with nothing on or after $date count
        return UtilityMeter::where('is_active', true)->whereDoesntHave('readings', fn ($q) => $q->whereDate('reading_date', '>=', $date))
            ->when($established, fn ($q) => $q->whereHas('readings', fn ($r) => $r->whereDate('reading_date', '<', $date)))->orderBy('code')->get();
    }

    /** nav badge: meters not read yesterday + unacknowledged anomalies of the last week */
    public static function attentionCount(): int
    {
        return self::missing(bdate()->copy()->subDay()->toDateString(), true)->count()
            + MeterReading::whereNotNull('anomaly')->whereNull('anomaly_ack_at')->whereDate('reading_date', '>=', bdate()->copy()->subDays(7)->toDateString())->count();
    }

    public static function openAnomalies(int $days = 7): Collection
    {
        return MeterReading::with('meter')->whereNotNull('anomaly')->whereNull('anomaly_ack_at')
            ->whereDate('reading_date', '>=', bdate()->copy()->subDays($days)->toDateString())->orderByDesc('reading_date')->get();
    }

    // ───────────────────────────── analytics ─────────────────────────────

    /** per meter: consumption, cost, readings, anomalies in a date range */
    public static function consumption(string $from, string $to, bool $mainOnly = false): Collection
    {
        $agg = MeterReading::whereDate('reading_date', '>=', $from)->whereDate('reading_date', '<=', $to)->groupBy('utility_meter_id')
            ->selectRaw('utility_meter_id, SUM(consumption) cons, SUM(cost) cost, SUM(days) days, COUNT(*) n, SUM(anomaly IS NOT NULL) flags, SUM(is_estimated) est')
            ->get()->keyBy('utility_meter_id');

        return UtilityMeter::when($mainOnly, fn ($q) => $q->where('is_main', true))->orderBy('utility')->orderBy('code')->get()
            ->map(function ($m) use ($agg) {
                $a = $agg->get($m->id);

                return ['meter' => $m, 'cons' => round((float) ($a->cons ?? 0), 3), 'cost' => round((float) ($a->cost ?? 0), 2), 'days' => (int) ($a->days ?? 0),
                    'n' => (int) ($a->n ?? 0), 'flags' => (int) ($a->flags ?? 0), 'est' => (int) ($a->est ?? 0)];
            });
    }

    /** main-meter consumption per utility in a range */
    public static function byUtility(string $from, string $to): array
    {
        $rows = MeterReading::join('utility_meters as um', 'um.id', '=', 'meter_readings.utility_meter_id')->where('um.is_main', true)
            ->whereBetween('meter_readings.reading_date', [$from, $to])->groupBy('um.utility')
            ->selectRaw('um.utility, SUM(meter_readings.consumption) cons, SUM(meter_readings.cost) cost')->get()->keyBy('utility');
        $out = [];
        foreach (UtilityMeter::UTILITIES as $u => $def) {
            $out[$u] = ['cons' => round((float) ($rows[$u]->cons ?? 0), 3), 'cost' => round((float) ($rows[$u]->cost ?? 0), 2)];
        }

        return $out;
    }

    /** occupied room-nights, guest-nights and revenue per business date – night audit figures where the day is closed */
    public static function occupancy(string $from, string $to): array
    {
        $audits = NightAudit::whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)->get()->keyBy(fn ($a) => $a->business_date->toDateString());
        $stays = ReservationRoom::whereIn('status', ['checked_in', 'checked_out'])->where('arrival_date', '<=', $to)->where('departure_date', '>', $from)
            ->get(['arrival_date', 'departure_date', 'adults', 'children']);
        $out = [];
        for ($d = Carbon::parse($from); $d->toDateString() <= $to; $d->addDay()) {
            $k = $d->toDateString();
            $in = $stays->filter(fn ($s) => $s->arrival_date->toDateString() <= $k && $s->departure_date->toDateString() > $k);
            $a = $audits->get($k);
            $out[$k] = ['rooms' => $a ? (int) $a->rooms_occupied : $in->count(), 'guests' => (int) $in->sum(fn ($s) => $s->adults + $s->children),
                'revenue' => $a ? round((float) $a->room_revenue + (float) $a->other_revenue, 2) : 0.0];
        }

        return $out;
    }

    /** daily main-meter consumption per utility: [date => [utility => cons]] + cost */
    public static function daily(string $from, string $to): array
    {
        $rows = MeterReading::join('utility_meters as um', 'um.id', '=', 'meter_readings.utility_meter_id')->where('um.is_main', true)
            ->whereBetween('meter_readings.reading_date', [$from, $to])->groupBy('meter_readings.reading_date', 'um.utility')
            ->selectRaw('meter_readings.reading_date d, um.utility, SUM(meter_readings.consumption) cons, SUM(meter_readings.cost) cost')->get();
        $out = [];
        for ($d = Carbon::parse($from); $d->toDateString() <= $to; $d->addDay()) {
            $out[$d->toDateString()] = array_fill_keys(array_keys(UtilityMeter::UTILITIES), 0.0) + ['cost' => 0.0];
        }
        foreach ($rows as $r) {
            $k = Carbon::parse($r->d)->toDateString();
            $out[$k][$r->utility] = round((float) $r->cons, 3);
            $out[$k]['cost'] = round($out[$k]['cost'] + (float) $r->cost, 2);
        }

        return $out;
    }

    /** KPI table per day plus totals: energy per occupied room, water per guest night, cost per room, cost % of revenue, CO2 per room */
    public static function kpis(string $from, string $to): array
    {
        $occ = self::occupancy($from, $to);
        $daily = self::daily($from, $to);
        $ef = self::cfg()['ef'];
        $rows = [];
        $tot = ['rooms' => 0, 'guests' => 0, 'revenue' => 0.0, 'kwh' => 0.0, 'solar' => 0.0, 'water' => 0.0, 'cost' => 0.0, 'co2' => 0.0];
        foreach ($daily as $d => $u) {
            $kwh = $u['electricity'] + $u['dg'] + $u['solar'];
            $co2 = 0.0;
            foreach ($ef as $k => $f) {
                $co2 += $u[$k] * $f;
            }
            $o = $occ[$d];
            $rows[$d] = ['rooms' => $o['rooms'], 'guests' => $o['guests'], 'revenue' => $o['revenue'], 'kwh' => round($kwh, 1), 'solar' => $u['solar'], 'water' => $u['water'],
                'cost' => $u['cost'], 'co2' => round($co2, 1)] + self::ratios($kwh, $u['water'], $u['cost'], $co2, $o['rooms'], $o['guests'], $o['revenue']);
            foreach (['rooms', 'guests', 'revenue', 'solar', 'water', 'cost'] as $k) {
                $tot[$k] += $o[$k] ?? $u[$k];
            }
            $tot['kwh'] += $kwh;
            $tot['co2'] += $co2;
        }
        $tot += self::ratios($tot['kwh'], $tot['water'], $tot['cost'], $tot['co2'], $tot['rooms'], $tot['guests'], $tot['revenue']);
        $tot['solar_share'] = $tot['kwh'] > 0 ? round($tot['solar'] / $tot['kwh'] * 100, 1) : 0.0;

        return ['rows' => $rows, 'total' => $tot];
    }

    protected static function ratios(float $kwh, float $water, float $cost, float $co2, int $rooms, int $guests, float $rev): array
    {
        return [
            'kwh_por' => $rooms ? round($kwh / $rooms, 1) : null,
            'water_lgn' => $guests ? round($water * 1000 / $guests, 0) : null,       // litres per guest night
            'cost_por' => $rooms ? round($cost / $rooms, 2) : null,
            'cost_pct' => $rev > 0 ? round($cost / $rev * 100, 1) : null,
            'co2_por' => $rooms ? round($co2 / $rooms, 1) : null,
        ];
    }

    /** carbon footprint per utility (Scope 1: fuels burnt on site, Scope 2: purchased electricity) + solar avoided */
    public static function carbon(string $from, string $to): array
    {
        $by = self::byUtility($from, $to);
        $ef = self::cfg()['ef'];
        $scope = ['electricity' => 2, 'dg' => 1, 'solar' => 0, 'water' => 3, 'lpg' => 1, 'diesel' => 1, 'png' => 1];
        $rows = [];
        $total = 0.0;
        foreach ($by as $u => $v) {
            if ($v['cons'] <= 0) {
                continue;
            }
            $kg = round($v['cons'] * $ef[$u], 1);
            $total += $kg;
            $rows[$u] = ['label' => UtilityMeter::UTILITIES[$u][0], 'unit' => UtilityMeter::UTILITIES[$u][1], 'cons' => $v['cons'], 'factor' => $ef[$u], 'kg' => $kg, 'scope' => $scope[$u]];
        }
        $rooms = array_sum(array_column(self::occupancy($from, $to), 'rooms'));

        return ['rows' => $rows, 'total_kg' => round($total, 1), 'rooms' => $rooms, 'per_room' => $rooms ? round($total / $rooms, 1) : null,
            'avoided_kg' => round($by['solar']['cons'] * $ef['electricity'], 1)];
    }

    // ───────────────────────────── bills ─────────────────────────────

    /** metered consumption for a billing period: readings dated after the start reading up to and including the end */
    public static function meteredUnits(UtilityMeter $m, string $from, string $to): float
    {
        return round((float) MeterReading::where('utility_meter_id', $m->id)->where('reading_date', '>', $from)->where('reading_date', '<=', $to)->sum('consumption'), 3);
    }

    public static function saveBill(array $d, ?UtilityBill $b = null): UtilityBill
    {
        if ($b?->supplier_invoice_id) {
            throw new RuntimeException('This bill is already booked to accounts payable and cannot be edited.');
        }
        $m = UtilityMeter::findOrFail($d['utility_meter_id']);
        if ($d['period_to'] < $d['period_from']) {
            throw new RuntimeException('The billing period ends before it starts.');
        }
        $no = trim($d['bill_no']);
        if (UtilityBill::where('utility_meter_id', $m->id)->where('bill_no', $no)->when($b, fn ($q) => $q->where('id', '!=', $b->id))->exists()) {
            throw new RuntimeException("Bill {$no} is already recorded for {$m->code}.");
        }
        $e = round((float) ($d['energy_charges'] ?? 0), 2);
        $f = round((float) ($d['fixed_charges'] ?? 0), 2);
        $t = round((float) ($d['taxes'] ?? 0), 2);
        if ($e + $f + $t <= 0) {
            throw new RuntimeException('Enter the bill amounts.');
        }
        $data = ['utility_meter_id' => $m->id, 'bill_no' => $no, 'bill_date' => $d['bill_date'], 'period_from' => $d['period_from'], 'period_to' => $d['period_to'],
            'units_billed' => round((float) ($d['units_billed'] ?? 0), 3), 'energy_charges' => $e, 'fixed_charges' => $f, 'taxes' => $t, 'total' => round($e + $f + $t, 2),
            'metered_units' => self::meteredUnits($m, $d['period_from'], $d['period_to']), 'due_date' => $d['due_date'] ?? null,
            'supplier_id' => $d['supplier_id'] ?? null, 'notes' => $d['notes'] ?? null, 'user_id' => auth()->id()];
        $b ? $b->update($data) : $b = UtilityBill::create($data);
        ActivityLogger::log('utilities', 'bill', "Utility bill {$b->bill_no} for {$m->code}: ".money($b->total).' ('.number_format($b->units_billed, 0)." {$m->unitShort()} billed / ".number_format($b->metered_units, 0).' metered)', $b);

        return $b;
    }

    /** book the bill as a supplier expense bill – GL and budget actuals follow through AP */
    public static function postBill(UtilityBill $b, ?int $supplierId = null, ?int $headId = null, ?string $budgetReason = null): UtilityBill
    {
        if ($b->supplier_invoice_id) {
            throw new RuntimeException('This bill is already booked to accounts payable.');
        }
        $s = Supplier::find($supplierId ?: $b->supplier_id);
        if (! $s) {
            throw new RuntimeException('Choose the supplier (utility company) to book the bill against.');
        }
        $m = $b->meter;
        $head = $headId ?: AccountHead::sys(self::BILL_HEADS[$m->utility] ?? 'utility_power');
        $desc = $m->utilityLabel().' '.$b->period_from->format('d M').' – '.$b->period_to->format('d M Y')." ({$m->code}".($b->units_billed > 0 ? ', '.number_format($b->units_billed, 0)." {$m->unitShort()}" : '').')';
        $inv = ApService::expenseBill($s, ['doc_date' => $b->bill_date->toDateString(), 'supplier_ref' => $b->bill_no, 'due_date' => $b->due_date?->toDateString(),
            'lines' => [['account_head_id' => $head, 'description' => mb_substr($desc, 0, 200), 'amount' => $b->total, 'tax_rate' => 0]],
            'budget_reason' => $budgetReason, 'notes' => 'Utility bill '.$b->bill_no]);
        $b->update(['supplier_id' => $s->id, 'supplier_invoice_id' => $inv->id]);
        ActivityLogger::log('utilities', 'bill_post', "Utility bill {$b->bill_no} booked to AP as {$inv->doc_no}", $b);

        return $b;
    }

    /** adopt the bill's effective energy rate (energy + fixed + taxes per unit) as the meter tariff */
    public static function adoptRate(UtilityBill $b): float
    {
        $rate = $b->effectiveRate();
        if ($rate <= 0) {
            throw new RuntimeException('The bill has no billed units to derive a rate from.');
        }
        $b->meter->update(['rate' => $rate]);
        ActivityLogger::log('utilities', 'rate', "Tariff of {$b->meter->code} set to {$rate} per {$b->meter->unitShort()} from bill {$b->bill_no}", $b->meter);

        return $rate;
    }

    /** dashboard / nav summary */
    public static function snapshot(): array
    {
        $d = bdate()->toDateString();
        $y = bdate()->copy()->subDay()->toDateString();
        $active = UtilityMeter::where('is_active', true)->count();
        $k = $active ? self::kpis($y, $y)['total'] : null;

        return ['meters' => $active, 'read_today' => $active - self::missing($d)->count(), 'missing_yesterday' => $active ? self::missing($y, true)->count() : 0,
            'anomalies' => $active ? self::openAnomalies()->count() : 0, 'yesterday' => $k];
    }
}
