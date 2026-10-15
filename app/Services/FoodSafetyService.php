<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\FsCertificate;
use App\Models\FsChecklist;
use App\Models\FsChecklistRun;
use App\Models\FsEquipment;
use App\Models\FsLabel;
use App\Models\FsTempLog;
use App\Models\MenuItem;
use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v5.0 HACCP records: critical limits, temperature logs with corrective actions and manager review, cooling in two
 * stages, checklists with sign-off, food-handler certificates and prep labels. Limits follow the Codex General
 * Principles of Food Hygiene / Ghana FDA guidance: chilled ≤ 5 °C, frozen ≤ −18 °C, hot holding ≥ 63 °C,
 * cooking & reheating core ≥ 75 °C, cooling ≤ 21 °C within 2 h and ≤ 5 °C within 6 h.
 */
class FoodSafetyService
{
    public const SETTINGS = [
        'fs_fridge_max' => ['Chilled storage – maximum °C', 5], 'fs_freezer_max' => ['Frozen storage – maximum °C', -18],
        'fs_hot_hold_min' => ['Hot holding – minimum °C', 63], 'fs_cook_min' => ['Cooking – core minimum °C', 75],
        'fs_reheat_min' => ['Reheating – core minimum °C', 75], 'fs_recv_chilled_max' => ['Receiving chilled – maximum °C', 5],
        'fs_recv_frozen_max' => ['Receiving frozen – maximum °C', -15], 'fs_cool_2h_max' => ['Cooling – maximum °C after 2 h', 21],
        'fs_cool_6h_max' => ['Cooling – maximum °C after 6 h', 5],
    ];

    public const SHELF_HOURS = ['chilled' => 72, 'frozen' => 2160, 'ambient' => 24, 'hot' => 4];

    protected static array $memo = [];

    public static function num(string $k): float
    {
        return (float) setting($k, (string) self::SETTINGS[$k][1]);
    }

    public static function certWarnDays(): int
    {
        return max(0, (int) setting('fs_cert_warn_days', '30'));
    }

    /** Departments whose staff handle food (need a valid medical certificate). Default Kitchen + F&B Service. */
    public static function handlerDepartments(): array
    {
        $raw = setting('fs_handler_depts', '');
        if ($raw === '' || $raw === null) {
            return \App\Models\Department::whereIn('name', ['Kitchen', 'F&B Service'])->pluck('id')->map(fn ($i) => (int) $i)->all();
        }

        return array_values(array_filter(array_map('intval', explode(',', (string) $raw))));
    }

    public static function saveSettings(array $d): void
    {
        foreach (self::SETTINGS as $k => $_) {
            if (array_key_exists($k, $d)) {
                Setting::updateOrCreate(['key' => $k], ['value' => (string) round((float) $d[$k], 1)]);
            }
        }
        foreach (['fs_cert_warn_days', 'fs_cert_roster'] as $k) {
            if (array_key_exists($k, $d)) {
                Setting::updateOrCreate(['key' => $k], ['value' => (string) (int) $d[$k]]);
            }
        }
        if (array_key_exists('fs_handler_depts', $d)) {
            Setting::updateOrCreate(['key' => 'fs_handler_depts'], ['value' => implode(',', array_map('intval', (array) $d['fs_handler_depts']))]);
        }
        Setting::$version++;
        ActivityLogger::log('foodsafety', 'settings', 'Food safety critical limits / settings updated');
    }

    /** Standard HACCP checklist templates for a new / upgraded property (only when it has none). */
    public const DEFAULT_CHECKLISTS = [
        ['Kitchen opening checks', 'daily', 'Main kitchen', '10:00', ['Hand-wash basins stocked with soap, paper towels and hot water', 'Staff fit for work – no illness reported, uniforms clean, jewellery removed',
            'Fridge & freezer temperatures recorded', 'Sanitiser made up at the correct dilution', 'Colour-coded boards and knives in place (raw / cooked separate)',
            'Deliveries checked and put away within 15 minutes', 'Food in storage covered, labelled and within use-by', 'No sign of pests (droppings, damage, insects)']],
        ['Kitchen closing & cleaning', 'daily', 'Main kitchen', '23:30', ['Hot food cooled or discarded – nothing left out at room temperature', 'Leftovers labelled with date and use-by',
            'Work surfaces and equipment cleaned and sanitised', 'Floors swept and mopped, drains cleared', 'Extraction hood filters degreased', 'Bins emptied, lined and lids closed',
            'Fridge and freezer doors closed and sealing']],
        ['Bar opening checks', 'daily', 'Skyline Bar', '16:00', ['Bar fridge temperature recorded', 'Ice machine clean, scoop stored outside the ice', 'Glass washer at the correct temperature, glasses clean',
            'Garnishes fresh and covered', 'Hand-wash basin stocked']],
        ['Weekly deep clean', 'weekly', 'Main kitchen', null, ['Walk-in chiller shelves and floor cleaned', 'Ovens and grills degreased', 'Dry store shelving cleaned, stock rotated (first in, first out)',
            'Behind and under equipment cleaned', 'Cold-room door seals checked', 'Temperature probes calibrated (ice water 0 °C / boiling water 100 °C)']],
        ['Monthly pest control inspection', 'monthly', 'All F&B areas', null, ['Bait stations and fly killers checked and logged by the contractor', 'No gaps around pipes, doors or windows',
            'Waste area clean, bins closed', 'Fly screens intact', 'Contractor report filed']],
    ];

    public static function ensureDefaults(): int
    {
        if (FsChecklist::exists()) {
            return 0;
        }
        foreach (self::DEFAULT_CHECKLISTS as [$name, $freq, $area, $due, $items]) {
            FsChecklist::create(['name' => $name, 'frequency' => $freq, 'area' => $area, 'due_time' => $due, 'items' => $items, 'is_active' => true]);
        }

        return count(self::DEFAULT_CHECKLISTS);
    }

    // ------------------------------------------------------------------ limits

    /** Default [min, max] for a type of equipment. */
    public static function typeLimits(string $type): array
    {
        return match ($type) {
            'freezer' => [null, self::num('fs_freezer_max')],
            'hot_hold' => [self::num('fs_hot_hold_min'), null],
            default => [null, self::num('fs_fridge_max')], // fridge, display, blast chiller holding
        };
    }

    /** [min, max] in force for a reading. */
    public static function limits(string $kind, ?FsEquipment $eq = null, ?string $category = null, int $stage = 1): array
    {
        return match ($kind) {
            'equipment' => [$eq?->min_c !== null ? (float) $eq->min_c : null, $eq?->max_c !== null ? (float) $eq->max_c : null],
            'receiving' => match ($category) {
                'frozen' => [null, self::num('fs_recv_frozen_max')],
                'hot' => [self::num('fs_hot_hold_min'), null],
                default => [null, self::num('fs_recv_chilled_max')],
            },
            'cooking' => [self::num('fs_cook_min'), null],
            'reheating' => [self::num('fs_reheat_min'), null],
            'cooling' => [null, self::num($stage === 1 ? 'fs_cool_2h_max' : 'fs_cool_6h_max')],
            default => [null, null],
        };
    }

    public static function limitText($min, $max): string
    {
        $f = fn ($v) => rtrim(rtrim(number_format((float) $v, 1), '0'), '.').' °C';
        if ($min !== null && $max !== null) {
            return $f($min).' – '.$f($max);
        }

        return $min !== null ? '≥ '.$f($min) : ($max !== null ? '≤ '.$f($max) : 'no limit');
    }

    public static function inRange(float $v, $min, $max): bool
    {
        return ($min === null || $v >= (float) $min - 0.001) && ($max === null || $v <= (float) $max + 0.001);
    }

    // ------------------------------------------------------------------ equipment & readings

    public static function saveEquipment(array $d, ?FsEquipment $eq = null): FsEquipment
    {
        [$dmin, $dmax] = self::typeLimits($d['type']);
        $new = ! $eq;
        $eq ??= new FsEquipment;
        $eq->fill(['name' => $d['name'], 'type' => $d['type'], 'location' => $d['location'] ?? null, 'outlet_id' => $d['outlet_id'] ?? null,
            'min_c' => ($d['min_c'] ?? '') !== '' && $d['min_c'] !== null ? $d['min_c'] : ($new ? $dmin : null),
            'max_c' => ($d['max_c'] ?? '') !== '' && $d['max_c'] !== null ? $d['max_c'] : ($new ? $dmax : null),
            'checks_per_day' => (int) ($d['checks_per_day'] ?? 2), 'is_active' => (bool) ($d['is_active'] ?? true)]);
        if ($eq->min_c !== null && $eq->max_c !== null && (float) $eq->min_c > (float) $eq->max_c) {
            throw new RuntimeException('The minimum temperature cannot be above the maximum.');
        }
        if ($eq->min_c === null && $eq->max_c === null) {
            throw new RuntimeException('Set at least one critical limit (minimum or maximum °C).');
        }
        $eq->save();
        ActivityLogger::log('foodsafety', $new ? 'equipment' : 'equipment_edit', ($new ? 'Added' : 'Updated')." equipment {$eq->name} ({$eq->limitText()})", $eq);

        return $eq;
    }

    /**
     * Record a reading. Out-of-range readings need the corrective action taken (discard, move stock, call engineer …).
     *
     * @param  array{kind:string, fs_equipment_id?:int, item?:string, category?:string, supplier_id?:int, reading_c:float, corrective_action?:string, recorded_at?:string}  $d
     */
    public static function record(array $d, User $u): FsTempLog
    {
        $kind = $d['kind'];
        if (! isset(FsTempLog::KINDS[$kind])) {
            throw new RuntimeException('Unknown reading type.');
        }
        $eq = null;
        if ($kind === 'equipment') {
            $eq = FsEquipment::where('is_active', true)->find($d['fs_equipment_id'] ?? 0);
            if (! $eq) {
                throw new RuntimeException('Choose the fridge / freezer / hot-holding unit you checked.');
            }
        } elseif (trim((string) ($d['item'] ?? '')) === '') {
            throw new RuntimeException($kind === 'receiving' ? 'Enter the delivery / product checked.' : 'Enter the food item probed.');
        }
        $at = ! empty($d['recorded_at']) ? Carbon::parse($d['recorded_at']) : now();
        if ($at->gt(now()->addMinutes(5))) {
            throw new RuntimeException('A reading cannot be recorded in the future.');
        }
        if ($at->lt(now()->subDays(3)->startOfDay())) {
            throw new RuntimeException('Readings older than 3 days cannot be entered – HACCP records are made at the time of the check.');
        }
        $v = round((float) $d['reading_c'], 1);
        if ($v < -60 || $v > 150) {
            throw new RuntimeException('That temperature is not plausible – check the probe.');
        }
        $category = $kind === 'receiving' ? ($d['category'] ?? 'chilled') : null;
        [$min, $max] = self::limits($kind, $eq, $category);
        $ok = self::inRange($v, $min, $max);
        $action = trim((string) ($d['corrective_action'] ?? ''));
        if (! $ok && $action === '') {
            throw new RuntimeException(rtrim(rtrim(number_format($v, 1), '0'), '.').' °C is outside the critical limit ('.self::limitText($min, $max).'). Record the corrective action taken.');
        }
        $log = FsTempLog::create(['kind' => $kind, 'fs_equipment_id' => $eq?->id, 'item' => $eq ? null : mb_substr(trim($d['item']), 0, 120), 'category' => $category,
            'supplier_id' => $kind === 'receiving' ? ($d['supplier_id'] ?? null) : null, 'reading_c' => $v, 'min_c' => $min, 'max_c' => $max,
            'in_range' => $ok, 'corrective_action' => $action !== '' ? mb_substr($action, 0, 255) : null, 'recorded_at' => $at, 'user_id' => $u->id]);
        self::$memo = [];
        if (! $ok) {
            ActivityLogger::log('foodsafety', 'breach', 'Out-of-range '.strtolower(FsTempLog::KINDS[$kind]).": {$log->subject()} {$v} °C (".self::limitText($min, $max).") – {$action}", $log);
        }

        return $log;
    }

    /** Second (6-hour) cooling reading. */
    public static function completeCooling(FsTempLog $log, float $reading, ?string $action, User $u): FsTempLog
    {
        if ($log->kind !== 'cooling' || $log->reading2_c !== null) {
            throw new RuntimeException('This cooling record is already complete.');
        }
        if (! $log->in_range) {
            throw new RuntimeException('The 2-hour reading failed – the food should have been discarded or reheated; the record is closed.');
        }
        $v = round($reading, 1);
        [, $max] = self::limits('cooling', null, null, 2);
        $ok = self::inRange($v, null, $max);
        $action = trim((string) $action);
        if (! $ok && $action === '') {
            throw new RuntimeException(rtrim(rtrim(number_format($v, 1), '0'), '.').' °C is above '.self::limitText(null, $max).' at 6 hours. Record the corrective action (usually: discard).');
        }
        $log->update(['reading2_c' => $v, 'reading2_at' => now(), 'max_c' => $max, 'in_range' => $ok,
            'corrective_action' => $ok ? $log->corrective_action : mb_substr($action, 0, 255)]);
        self::$memo = [];
        if (! $ok) {
            ActivityLogger::log('foodsafety', 'breach', "Cooling failed at 6 h: {$log->item} {$v} °C – {$action}", $log);
        }

        return $log;
    }

    /** Cooling records whose 6-hour reading is due (more than 6 h since the food came off the heat ≈ 4 h after the 2-hour reading). */
    public static function coolingOverdue(): Collection
    {
        return FsTempLog::where('kind', 'cooling')->whereNull('reading2_c')->where('in_range', true)->where('recorded_at', '<', now()->subHours(4))->get();
    }

    /** A manager reviews an out-of-range reading and its corrective action. Someone other than the person who recorded it. */
    public static function review(FsTempLog $log, ?string $note, User $u): FsTempLog
    {
        if ($log->in_range) {
            throw new RuntimeException('Only out-of-range readings need a review.');
        }
        if ($log->reviewed_at) {
            throw new RuntimeException('Already reviewed by '.$log->reviewer?->name.'.');
        }
        if ($log->user_id === $u->id) {
            throw new RuntimeException('A different person must review your own out-of-range reading.');
        }
        $log->update(['reviewed_at' => now(), 'reviewed_by' => $u->id, 'review_note' => $note ? mb_substr($note, 0, 255) : null]);
        self::$memo = [];
        ActivityLogger::log('foodsafety', 'review', "Reviewed out-of-range reading {$log->subject()} {$log->reading_c} °C", $log);

        return $log;
    }

    /** Today's board: every active unit with readings so far, what is due and the last value. */
    public static function board(?Carbon $day = null): Collection
    {
        $day ??= now();
        $logs = FsTempLog::where('kind', 'equipment')->whereBetween('recorded_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])->orderBy('recorded_at')->get()->groupBy('fs_equipment_id');
        $last = FsTempLog::where('kind', 'equipment')->whereIn('id', FsTempLog::where('kind', 'equipment')->selectRaw('max(id)')->groupBy('fs_equipment_id'))->get()->keyBy('fs_equipment_id');

        return FsEquipment::with('outlet')->where('is_active', true)->orderBy('type')->orderBy('name')->get()->map(function ($eq) use ($logs, $last) {
            $today = $logs[$eq->id] ?? collect();
            $eq->today = $today;
            $eq->done = $today->count();
            $eq->last = $last[$eq->id] ?? null;
            $eq->breach = $today->contains(fn ($l) => ! $l->in_range);
            $eq->state = $eq->breach ? 'breach' : ($eq->done >= $eq->checks_per_day ? 'done' : 'due');

            return $eq;
        });
    }

    /**
     * Checks completed / expected over whole past days, readings in range, checklists done.
     *
     * @return array{expected:int, done:int, missed:int, checks_pct:?float, readings:int, in_range_pct:?float, lists_due:int, lists_done:int, score:?float}
     */
    public static function compliance(int $days = 7): array
    {
        $key = \App\Support\Tenant::id().'c'.$days.'-'.now()->toDateString();
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }
        $to = now()->subDay()->endOfDay();
        $from = now()->subDays($days)->startOfDay();
        $counts = FsTempLog::where('kind', 'equipment')->whereBetween('recorded_at', [$from, $to])
            ->selectRaw('fs_equipment_id, date(recorded_at) d, count(*) n')->groupBy('fs_equipment_id', DB::raw('date(recorded_at)'))->get()
            ->mapWithKeys(fn ($r) => [$r->fs_equipment_id.'|'.Carbon::parse($r->d)->toDateString() => (int) $r->n]);
        $expected = $done = 0;
        foreach (FsEquipment::where('is_active', true)->get() as $eq) {
            for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                if ($eq->created_at && $eq->created_at->toDateString() > $d->toDateString()) {
                    continue;
                }
                $expected += $eq->checks_per_day;
                $done += min($eq->checks_per_day, $counts[$eq->id.'|'.$d->toDateString()] ?? 0);
            }
        }
        $readings = FsTempLog::whereBetween('recorded_at', [$from, $to])->count();
        $good = FsTempLog::whereBetween('recorded_at', [$from, $to])->where('in_range', true)->count();
        [$due, $listDone] = self::checklistTally($from, $to);
        $total = $expected + $due;
        $res = ['expected' => $expected, 'done' => $done, 'missed' => $expected - $done, 'checks_pct' => $expected ? round($done / $expected * 100, 1) : null,
            'readings' => $readings, 'in_range_pct' => $readings ? round($good / $readings * 100, 1) : null, 'lists_due' => $due, 'lists_done' => $listDone,
            'score' => $total ? round(($done + $listDone) / $total * 100, 1) : null];

        return self::$memo[$key] = $res;
    }

    // ------------------------------------------------------------------ checklists

    public static function periodFor(FsChecklist $c, Carbon|string|null $d = null): Carbon
    {
        $d = $d ? Carbon::parse($d)->startOfDay() : now()->startOfDay();

        return match ($c->frequency) {
            'weekly' => $d->copy()->startOfWeek(Carbon::MONDAY),
            'monthly' => $d->copy()->startOfMonth(),
            default => $d,
        };
    }

    public static function periodLabel(FsChecklist $c, Carbon $p): string
    {
        return match ($c->frequency) {
            'weekly' => 'week of '.$p->format('d M'),
            'monthly' => $p->format('M Y'),
            default => $p->format('D d M'),
        };
    }

    /** Checklist periods that ended inside [from, to] and how many were completed. */
    protected static function checklistTally(Carbon $from, Carbon $to): array
    {
        $due = $done = 0;
        $runs = FsChecklistRun::whereBetween('period', [$from->copy()->subMonth()->toDateString(), $to->toDateString()])->get()
            ->mapWithKeys(fn ($r) => [$r->fs_checklist_id.'|'.$r->period->toDateString() => true]);
        foreach (FsChecklist::where('is_active', true)->get() as $c) {
            for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                $p = self::periodFor($c, $d);
                $end = match ($c->frequency) { 'weekly' => $p->copy()->addDays(6), 'monthly' => $p->copy()->endOfMonth()->startOfDay(), default => $p };
                if ($end->toDateString() !== $d->toDateString() || ($c->created_at && $c->created_at->toDateString() > $p->toDateString())) {
                    continue; // count each period once, on its last day
                }
                $due++;
                $done += isset($runs[$c->id.'|'.$p->toDateString()]) ? 1 : 0;
            }
        }

        return [$due, $done];
    }

    /** Current-period status of each active checklist. */
    public static function checklistStatus(): Collection
    {
        $lists = FsChecklist::where('is_active', true)->orderByRaw("field(frequency,'daily','weekly','monthly')")->orderBy('due_time')->orderBy('name')->get();
        $runs = FsChecklistRun::with('completer', 'signer')->whereIn('fs_checklist_id', $lists->pluck('id'))->whereDate('period', '>=', now()->startOfMonth()->subDays(7)->toDateString())->get()
            ->keyBy(fn ($r) => $r->fs_checklist_id.'|'.$r->period->toDateString());

        return $lists->map(function ($c) use ($runs) {
            $p = self::periodFor($c);
            $c->period = $p;
            $c->run = $runs[$c->id.'|'.$p->toDateString()] ?? null;
            $late = $c->frequency === 'daily' && $c->due_time && now()->format('H:i:s') > $c->due_time;
            $c->state = $c->run ? ($c->run->failed ? ($c->run->signed_at ? 'signed' : 'failed') : ($c->run->signed_at ? 'signed' : 'done')) : ($late ? 'overdue' : 'due');

            return $c;
        });
    }

    public static function saveChecklist(array $d, ?FsChecklist $c = null): FsChecklist
    {
        $items = collect(preg_split('/\r\n|\r|\n/', (string) $d['items']))->map(fn ($l) => trim($l))->filter()->values();
        if ($items->isEmpty() || $items->count() > 40) {
            throw new RuntimeException('A checklist needs 1–40 items, one per line.');
        }
        $new = ! $c;
        $c ??= new FsChecklist;
        $c->fill(['name' => $d['name'], 'frequency' => $d['frequency'], 'area' => $d['area'] ?? null, 'items' => $items->map(fn ($l) => mb_substr($l, 0, 160))->all(),
            'due_time' => ($d['due_time'] ?? null) ?: null, 'is_active' => (bool) ($d['is_active'] ?? true)]);
        $c->save();
        ActivityLogger::log('foodsafety', 'checklist', ($new ? 'Created' : 'Updated')." checklist {$c->name} ({$items->count()} items)", $c);

        return $c;
    }

    /**
     * Complete the current period of a checklist. Every item must be answered; failed items need a note.
     *
     * @param  array<int, array{ok?:string|bool, note?:string}>  $answers
     */
    public static function complete(FsChecklist $c, array $answers, ?string $notes, User $u): FsChecklistRun
    {
        if (! $c->is_active) {
            throw new RuntimeException('This checklist is no longer in use.');
        }
        $p = self::periodFor($c);
        $out = [];
        $failed = 0;
        foreach ($c->items as $i => $text) {
            $a = $answers[$i] ?? null;
            if (! $a || ! isset($a['ok']) || $a['ok'] === '') {
                throw new RuntimeException('Answer every item – "'.$text.'" is not ticked.');
            }
            $ok = in_array($a['ok'], [true, 1, '1', 'yes'], true);
            $note = trim((string) ($a['note'] ?? ''));
            if (! $ok && $note === '') {
                throw new RuntimeException('Say what was wrong and what was done for "'.$text.'".');
            }
            $failed += $ok ? 0 : 1;
            $out[] = ['item' => $text, 'ok' => $ok, 'note' => $note !== '' ? mb_substr($note, 0, 200) : null];
        }
        $run = DB::transaction(function () use ($c, $p, $out, $failed, $notes, $u) {
            if (FsChecklistRun::where('fs_checklist_id', $c->id)->whereDate('period', $p->toDateString())->lockForUpdate()->exists()) {
                throw new RuntimeException("{$c->name} is already completed for ".self::periodLabel($c, $p).'.');
            }

            return FsChecklistRun::create(['fs_checklist_id' => $c->id, 'period' => $p->toDateString(), 'answers' => $out, 'failed' => $failed,
                'notes' => $notes ? mb_substr($notes, 0, 500) : null, 'completed_by' => $u->id, 'completed_at' => now()]);
        });
        self::$memo = [];
        ActivityLogger::log('foodsafety', 'checklist_done', "{$c->name} completed for ".self::periodLabel($c, $p).($failed ? " – {$failed} item(s) failed" : ''), $run);

        return $run;
    }

    public static function signOff(FsChecklistRun $run, ?string $note, User $u): FsChecklistRun
    {
        if ($run->signed_at) {
            throw new RuntimeException('Already signed off by '.$run->signer?->name.'.');
        }
        if ($run->completed_by === $u->id) {
            throw new RuntimeException('A manager other than the person who completed the checklist signs it off.');
        }
        if ($run->failed && trim((string) $note) === '') {
            throw new RuntimeException('Failed items: add a sign-off note confirming the follow-up.');
        }
        $run->update(['signed_by' => $u->id, 'signed_at' => now(), 'sign_note' => $note ? mb_substr($note, 0, 255) : null]);
        self::$memo = [];
        ActivityLogger::log('foodsafety', 'checklist_sign', 'Signed off '.$run->checklist->name.' ('.self::periodLabel($run->checklist, $run->period).')', $run);

        return $run;
    }

    // ------------------------------------------------------------------ food handler certificates

    public static function handlers(): Collection
    {
        return Employee::with('department')->where('is_active', true)->whereIn('department_id', self::handlerDepartments())->orderBy('name')->get();
    }

    /** status for one certificate (or null): valid | expiring | expired | missing */
    public static function certState(?FsCertificate $c, ?Carbon $on = null): string
    {
        $on ??= now()->startOfDay();
        if (! $c) {
            return 'missing';
        }
        if ($c->expires_on->lt($on)) {
            return 'expired';
        }

        return $c->expires_on->lte($on->copy()->addDays(self::certWarnDays())) ? 'expiring' : 'valid';
    }

    /** employee id => ['medical' => cert|null, 'hygiene' => cert|null] (latest expiry of each type). */
    public static function certMap(Collection $employeeIds): array
    {
        $out = [];
        foreach (FsCertificate::whereIn('employee_id', $employeeIds)->orderBy('expires_on')->get() as $c) {
            $out[$c->employee_id][$c->type] = $c; // ordered by expiry → last one wins
        }

        return $out;
    }

    public static function saveCertificate(array $d, User $u): FsCertificate
    {
        $emp = Employee::findOrFail($d['employee_id']);
        $issued = Carbon::parse($d['issued_on']);
        $exp = Carbon::parse($d['expires_on']);
        if ($exp->lte($issued)) {
            throw new RuntimeException('The expiry date must be after the issue date.');
        }
        if ($issued->gt(now()->endOfDay())) {
            throw new RuntimeException('The issue date cannot be in the future.');
        }
        $c = FsCertificate::create(['employee_id' => $emp->id, 'type' => $d['type'], 'cert_no' => $d['cert_no'] ?? null, 'issuer' => $d['issuer'] ?? null,
            'issued_on' => $issued->toDateString(), 'expires_on' => $exp->toDateString(), 'notes' => $d['notes'] ?? null, 'user_id' => $u->id]);
        self::$memo = [];
        ActivityLogger::log('foodsafety', 'certificate', 'Recorded '.strtolower(explode(' (', FsCertificate::TYPES[$c->type])[0])." certificate for {$emp->name} valid to ".dmy($exp), $c);

        return $c;
    }

    /** Roster warning text when a food handler is rostered without a valid medical certificate. */
    public static function rosterWarning(Employee $e, Collection $shiftDates): ?string
    {
        if (setting('fs_cert_roster', '1') !== '1' || ! in_array((int) $e->department_id, self::handlerDepartments(), true) || $shiftDates->isEmpty()) {
            return null;
        }
        $cert = FsCertificate::where('employee_id', $e->id)->where('type', 'medical')->orderByDesc('expires_on')->first();
        $bad = $shiftDates->filter(fn ($d) => ! $cert || $cert->expires_on->lt(Carbon::parse($d)))->values();
        if ($bad->isEmpty()) {
            return null;
        }

        return $cert ? 'Food handler medical certificate expired '.dmy($cert->expires_on).' – renew before handling food ('.$bad->count().' shift(s) this week)'
            : 'No food handler medical certificate on file – required before handling food';
    }

    /** Handlers whose medical certificate is missing / expired / expiring. */
    public static function certProblems(): array
    {
        $h = self::handlers();
        $map = self::certMap($h->pluck('id'));
        $out = ['expired' => 0, 'missing' => 0, 'expiring' => 0];
        foreach ($h as $e) {
            $s = self::certState($map[$e->id]['medical'] ?? null);
            if (isset($out[$s])) {
                $out[$s]++;
            }
        }

        return $out + ['handlers' => $h->count()];
    }

    // ------------------------------------------------------------------ prep labels

    public static function shelfHours(string $storage): int
    {
        return (int) setting('fs_shelf_'.$storage, (string) (self::SHELF_HOURS[$storage] ?? 24));
    }

    public static function createLabels(array $d, User $u): FsLabel
    {
        $menu = ! empty($d['menu_item_id']) ? MenuItem::find($d['menu_item_id']) : null;
        $item = trim((string) ($d['item'] ?? '')) ?: $menu?->name;
        if (! $item) {
            throw new RuntimeException('Enter what is being labelled, or pick a menu item.');
        }
        $storage = $d['storage'];
        $prep = ! empty($d['prepared_at']) ? Carbon::parse($d['prepared_at']) : now();
        if ($prep->gt(now()->addMinutes(5))) {
            throw new RuntimeException('The preparation time cannot be in the future.');
        }
        $hours = (int) (($d['shelf_hours'] ?? '') !== '' ? $d['shelf_hours'] : self::shelfHours($storage));
        if ($hours < 1 || $hours > 4320) {
            throw new RuntimeException('Shelf life must be between 1 hour and 180 days.');
        }
        $allergens = AllergenService::clean(array_merge((array) ($d['allergens'] ?? []), $menu ? AllergenService::effective($menu) : []));
        $l = FsLabel::create(['item' => mb_substr($item, 0, 80), 'menu_item_id' => $menu?->id, 'storage' => $storage, 'prepared_at' => $prep,
            'use_by' => $prep->copy()->addHours($hours), 'qty' => max(1, min(48, (int) ($d['qty'] ?? 1))), 'allergens' => $allergens ?: null,
            'batch' => $d['batch'] ?? null, 'user_id' => $u->id]);
        ActivityLogger::log('foodsafety', 'labels', "Printed {$l->qty} prep label(s) for {$l->item}, use by ".$l->use_by->format('d/m H:i'), $l);

        return $l;
    }

    // ------------------------------------------------------------------ attention

    /** Items that need someone: unreviewed breaches, overdue cooling, failed checklists not signed off, overdue daily checklists. */
    public static function attention(): array
    {
        $ak = \App\Support\Tenant::id().'att';
        if (isset(self::$memo[$ak])) {
            return self::$memo[$ak];
        }
        $breaches = FsTempLog::where('in_range', false)->whereNull('reviewed_at')->count();
        $cooling = self::coolingOverdue()->count();
        $failed = FsChecklistRun::where('failed', '>', 0)->whereNull('signed_at')->count();
        $overdue = self::checklistStatus()->where('state', 'overdue')->count();

        return self::$memo[$ak] = ['breaches' => $breaches, 'cooling' => $cooling, 'failed' => $failed, 'overdue' => $overdue, 'total' => $breaches + $cooling + $failed + $overdue];
    }

    public static function attentionCount(): int
    {
        return self::attention()['total'];
    }

    public static function flush(): void
    {
        self::$memo = [];
    }
}
