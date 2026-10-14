<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\RosterShift;
use App\Models\RosterWeek;
use App\Models\ShiftSwap;
use App\Models\ShiftTemplate;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v4.9 Weekly duty rosters per department: shift templates, a week grid with approved leave shown,
 * working-time checks (rest between shifts, consecutive days, weekly hours), coverage against the
 * minimum staffing per shift, copy-forward, publishing to staff, and colleague-to-colleague swaps
 * approved by a manager.
 */
class RosterService
{
    public static function weekStart(Carbon|string|null $d = null): Carbon
    {
        return Carbon::parse($d ?? bdate())->startOfWeek(Carbon::MONDAY)->startOfDay();
    }

    /** Act 651 s. 41: at least twelve consecutive hours of rest between two working days. */
    public static function minRest(): float
    {
        return (float) setting('roster_min_rest_hours', '12');
    }

    /** Act 651 s. 42: at least forty-eight consecutive hours of rest in every seven days. */
    public static function weekRest(): float
    {
        return (float) setting('roster_week_rest_hours', '48');
    }

    public static function maxDays(): int
    {
        return (int) setting('roster_max_days', '6');
    }

    /** Act 651 s. 33: eight hours a day and a forty-hour normal week – anything above it is overtime (s. 35). */
    public static function weekHours(): float
    {
        return (float) setting('roster_week_hours', '40');
    }

    /** Active employees of a department who are employed at some point in the week. */
    public static function employees(?int $dept, Carbon $week): Collection
    {
        $end = $week->copy()->addDays(6)->toDateString();

        return Employee::with('department')->where('is_active', true)
            ->when($dept, fn ($q) => $q->where('department_id', $dept))
            ->where(fn ($q) => $q->whereNull('joining_date')->orWhereDate('joining_date', '<=', $end))
            ->where(fn ($q) => $q->whereNull('leaving_date')->orWhereDate('leaving_date', '>=', $week->toDateString()))
            ->orderBy('name')->get();
    }

    /** [employee_id][Y-m-d] => approved LeaveRequest covering the day. */
    public static function leaveMap(Collection $employeeIds, string $from, string $to): array
    {
        $map = [];
        foreach (LeaveRequest::with('leaveType')->where('status', 'approved')->whereIn('employee_id', $employeeIds)
            ->whereDate('date_from', '<=', $to)->whereDate('date_to', '>=', $from)->get() as $l) {
            for ($d = Carbon::parse(max($from, $l->date_from->toDateString())); $d->toDateString() <= min($to, $l->date_to->toDateString()); $d->addDay()) {
                $map[$l->employee_id][$d->toDateString()] = $l;
            }
        }

        return $map;
    }

    public static function week(int $dept, Carbon $week): ?RosterWeek
    {
        return RosterWeek::where('department_id', $dept)->whereDate('week_start', $week->toDateString())->first();
    }

    /** Everything the week grid needs. */
    public static function grid(int $dept, Carbon $week): array
    {
        $days = collect(range(0, 6))->map(fn ($i) => $week->copy()->addDays($i));
        $emps = self::employees($dept, $week);
        $from = $week->toDateString();
        $to = $week->copy()->addDays(6)->toDateString();
        // a week of history before the grid feeds the rest / consecutive-day checks
        $all = RosterShift::with('template')->whereIn('employee_id', $emps->pluck('id'))
            ->whereDate('date', '>=', $week->copy()->subDays(7)->toDateString())->whereDate('date', '<=', $to)->orderBy('date')->get()->groupBy('employee_id');
        $shifts = [];
        foreach ($all as $eid => $rows) {
            foreach ($rows as $r) {
                if ($r->date->toDateString() >= $from) {
                    $shifts[$eid][$r->date->toDateString()] = $r;
                }
            }
        }
        $templates = self::templates($dept);
        $coverage = [];
        foreach ($shifts as $eid => $byDay) {
            foreach ($byDay as $d => $s) {
                if ($s->kind === 'shift' && $s->shift_template_id) {
                    $coverage[$d][$s->shift_template_id] = ($coverage[$d][$s->shift_template_id] ?? 0) + 1;
                }
            }
        }
        $warnings = [];
        $hours = [];
        foreach ($emps as $e) {
            $rows = $all[$e->id] ?? collect();
            $hours[$e->id] = round($rows->filter(fn ($r) => $r->date->toDateString() >= $from)->sum(fn ($r) => $r->paidMinutes()) / 60, 2);
            $warnings[$e->id] = self::checks($rows, $week);
            // v5.0 food handlers need a valid medical certificate on every rostered day
            $fs = FoodSafetyService::rosterWarning($e, $rows->filter(fn ($r) => $r->isShift() && $r->date->toDateString() >= $from)->map(fn ($r) => $r->date->toDateString())->values());
            if ($fs) {
                $warnings[$e->id][] = $fs;
            }
        }
        $short = [];
        foreach ($days as $d) {
            foreach ($templates->where('min_staff', '>', 0) as $t) {
                $n = $coverage[$d->toDateString()][$t->id] ?? 0;
                if ($n < $t->min_staff) {
                    $short[$d->toDateString()][] = "{$t->code} {$n}/{$t->min_staff}";
                }
            }
        }

        return ['days' => $days, 'employees' => $emps, 'shifts' => $shifts, 'leave' => self::leaveMap($emps->pluck('id'), $from, $to),
            'week' => self::week($dept, $week), 'templates' => $templates, 'coverage' => $coverage, 'short' => $short,
            'warnings' => $warnings, 'hours' => $hours];
    }

    public static function templates(?int $dept = null): Collection
    {
        return ShiftTemplate::where('is_active', true)->when($dept, fn ($q) => $q->where(fn ($w) => $w->whereNull('department_id')->orWhere('department_id', $dept)))
            ->orderBy('start_time')->orderBy('code')->get();
    }

    /**
     * Working-time warnings for one employee's shifts (sorted by date, may include the previous week).
     * Only shifts inside the grid week raise a warning.
     */
    public static function checks(Collection $rows, Carbon $week): array
    {
        $from = $week->toDateString();
        $to = $week->copy()->addDays(6)->toDateString();
        $out = [];
        $shifts = $rows->filter(fn ($r) => $r->isShift())->sortBy(fn ($r) => $r->startsAt())->values();
        for ($i = 1; $i < $shifts->count(); $i++) {
            $prev = $shifts[$i - 1];
            $cur = $shifts[$i];
            if ($cur->date->toDateString() < $from) {
                continue;
            }
            $rest = $prev->endsAt()->diffInMinutes($cur->startsAt(), false) / 60;
            if ($rest < self::minRest()) {
                $out[] = dmy($cur->date).': only '.rtrim(rtrim(number_format(max(0, $rest), 1), '0'), '.').' h rest after the previous shift (minimum '.rtrim(rtrim(number_format(self::minRest(), 1), '0'), '.').' h)';
            }
        }
        // consecutive working days (a day off or no roster breaks the run)
        $worked = $shifts->map(fn ($r) => $r->date->toDateString())->unique()->flip();
        $run = 0;
        $flagged = false;
        for ($d = $week->copy()->subDays(7); $d->toDateString() <= $to; $d->addDay()) {
            $run = isset($worked[$d->toDateString()]) ? $run + 1 : 0;
            if ($run > self::maxDays() && $d->toDateString() >= $from && ! $flagged) {
                $out[] = dmy($d).': '.$run.' days in a row without a day off (maximum '.self::maxDays().')';
                $flagged = true;
            }
        }
        $mins = $shifts->filter(fn ($r) => $r->date->toDateString() >= $from)->sum(fn ($r) => $r->paidMinutes());
        if ($mins / 60 > self::weekHours() + 0.001) {
            $out[] = rtrim(rtrim(number_format($mins / 60, 2), '0'), '.').' h planned this week – above the '.rtrim(rtrim(number_format(self::weekHours(), 1), '0'), '.').' h normal week (overtime)';
        }
        // s. 42: the longest unbroken rest inside the week must reach forty-eight hours
        if (self::weekRest() > 0 && $shifts->isNotEmpty()) {
            $open = $week->copy()->startOfDay();
            $close = $open->copy()->addDays(7);
            $best = 0.0;
            $prevEnd = $open;
            foreach ($shifts as $sh) {
                if ($sh->startsAt()->lt($open)) {
                    $prevEnd = $prevEnd->max($sh->endsAt());

                    continue;
                }
                if ($sh->startsAt()->gte($close)) {
                    break;
                }
                $best = max($best, $prevEnd->diffInMinutes($sh->startsAt(), false) / 60);
                $prevEnd = $prevEnd->max($sh->endsAt());
            }
            $best = max($best, $prevEnd->diffInMinutes($close, false) / 60);
            if ($best + 0.001 < self::weekRest()) {
                $out[] = 'The longest unbroken rest this week is '.rtrim(rtrim(number_format(max(0, $best), 1), '0'), '.').' h – s. 42 of the Labour Act wants '.rtrim(rtrim(number_format(self::weekRest(), 1), '0'), '.').' consecutive hours';
            }
        }

        return $out;
    }

    /**
     * Save a week grid. $cells[employee_id][Y-m-d] = template id | 'OFF' | '' (clear).
     * Days before the business date, approved leave days and employees outside the department are skipped.
     *
     * @return array{saved:int, skipped:int}
     */
    public static function saveWeek(int $dept, Carbon $week, array $cells, ?User $u = null): array
    {
        $emps = self::employees($dept, $week)->keyBy('id');
        $from = $week->toDateString();
        $to = $week->copy()->addDays(6)->toDateString();
        $today = bdate()->toDateString();
        $leave = self::leaveMap($emps->keys(), $from, $to);
        $templates = self::templates($dept)->keyBy('id');
        $saved = $skipped = 0;
        DB::transaction(function () use ($cells, $emps, $from, $to, $today, $leave, $templates, $u, $dept, $week, &$saved, &$skipped) {
            foreach ($cells as $eid => $days) {
                if (! isset($emps[(int) $eid]) || ! is_array($days)) {
                    $skipped += is_array($days) ? count($days) : 1;

                    continue;
                }
                foreach ($days as $date => $val) {
                    $val = trim((string) $val);
                    $existing = RosterShift::where('employee_id', $eid)->whereDate('date', $date)->first();
                    $same = $existing ? ($existing->kind === 'off' ? 'OFF' : (string) $existing->shift_template_id) : '';
                    if ($val === $same) {
                        continue;
                    }
                    $leaveDay = $leave[$eid][$date] ?? null;
                    if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date) || $date < $from || $date > $to || $date < $today
                        || ($leaveDay && ! $leaveDay->half_day && $val !== '' && $val !== 'OFF')) {
                        $skipped++;

                        continue;
                    }
                    if ($val === '') {
                        $existing?->delete();
                    } elseif ($val === 'OFF') {
                        date_upsert(RosterShift::class, ['employee_id' => $eid, 'date' => $date], ['kind' => 'off', 'shift_template_id' => null, 'start_time' => null, 'end_time' => null, 'break_minutes' => 0, 'created_by' => $u?->id]);
                    } elseif ($t = $templates[(int) $val] ?? null) {
                        date_upsert(RosterShift::class, ['employee_id' => $eid, 'date' => $date], ['kind' => 'shift', 'shift_template_id' => $t->id, 'start_time' => $t->start_time,
                            'end_time' => $t->end_time, 'break_minutes' => $t->break_minutes, 'created_by' => $u?->id]);
                    } else {
                        $skipped++;

                        continue;
                    }
                    $saved++;
                }
            }
            if ($saved && ($w = self::week($dept, $week)) && $w->published_at) {
                $w->forceFill(['changed_at' => now()])->save();
            }
        });
        if ($saved) {
            ActivityLogger::log('roster', 'save', "Roster {$emps->first()?->department?->name} week of ".dmy($week).": {$saved} change(s)", null);
        }

        return ['saved' => $saved, 'skipped' => $skipped];
    }

    /** Copy last (or any) week's pattern into empty, open days of the target week. */
    public static function copyWeek(int $dept, Carbon $source, Carbon $target, ?User $u = null): int
    {
        if ($source->equalTo($target)) {
            throw new RuntimeException('Choose a different week to copy from.');
        }
        $emps = self::employees($dept, $target)->pluck('id');
        $cells = [];
        foreach (RosterShift::whereIn('employee_id', $emps)->whereDate('date', '>=', $source->toDateString())->whereDate('date', '<=', $source->copy()->addDays(6)->toDateString())->get() as $s) {
            $d = $target->copy()->addDays($source->diffInDays($s->date))->toDateString();
            if (! RosterShift::where('employee_id', $s->employee_id)->whereDate('date', $d)->exists()) {
                $cells[$s->employee_id][$d] = $s->kind === 'off' ? 'OFF' : (string) $s->shift_template_id;
            }
        }
        if (! $cells) {
            throw new RuntimeException('Nothing to copy – the source week is empty or every day is already rostered.');
        }

        return self::saveWeek($dept, $target, $cells, $u)['saved'];
    }

    public static function publish(int $dept, Carbon $week, User $u): RosterWeek
    {
        $emps = self::employees($dept, $week)->pluck('id');
        if (! RosterShift::whereIn('employee_id', $emps)->whereDate('date', '>=', $week->toDateString())->whereDate('date', '<=', $week->copy()->addDays(6)->toDateString())->exists()) {
            throw new RuntimeException('The week is empty – roster some shifts before publishing.');
        }
        $w = RosterWeek::firstOrNew(['department_id' => $dept, 'week_start' => $week->toDateString()]);
        $w->forceFill(['published_at' => now(), 'published_by' => $u->id, 'changed_at' => null])->save();
        ActivityLogger::log('roster', 'publish', 'Published the '.($w->department?->name ?? 'department').' roster for the week of '.dmy($week), $w);

        return $w;
    }

    /** Is the week containing this shift published for the employee's department? */
    public static function isPublished(Employee $e, Carbon|string $date): bool
    {
        return RosterWeek::where('department_id', $e->department_id)->whereDate('week_start', self::weekStart($date)->toDateString())->whereNotNull('published_at')->exists();
    }

    public static function shiftFor(Employee $e, Carbon|string $date): ?RosterShift
    {
        return RosterShift::with('template')->where('employee_id', $e->id)->whereDate('date', Carbon::parse($date)->toDateString())->first();
    }

    /** An employee's published roster between two dates. */
    public static function myShifts(Employee $e, string $from, string $to): Collection
    {
        $weeks = RosterWeek::where('department_id', $e->department_id)->whereNotNull('published_at')
            ->whereDate('week_start', '>=', self::weekStart($from)->toDateString())->whereDate('week_start', '<=', $to)->pluck('week_start')->map(fn ($d) => $d->toDateString())->all();

        return RosterShift::with('template')->where('employee_id', $e->id)->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->orderBy('date')->get()
            ->filter(fn ($s) => in_array(self::weekStart($s->date)->toDateString(), $weeks, true))->values();
    }

    // ───────────────────────── swaps ─────────────────────────

    public static function requestSwap(Employee $req, RosterShift $mine, Employee $colleague, ?string $reason = null): ShiftSwap
    {
        if ((int) $mine->employee_id !== (int) $req->id || ! $mine->isShift()) {
            throw new RuntimeException('Pick one of your own shifts.');
        }
        if ($mine->startsAt()->lte(now()->addHours((int) setting('roster_swap_notice_hours', '12')))) {
            throw new RuntimeException('Too late to swap this shift – ask your manager directly.');
        }
        if (! self::isPublished($req, $mine->date)) {
            throw new RuntimeException('That week has not been published yet.');
        }
        if ((int) $colleague->id === (int) $req->id || ! $colleague->is_active || (int) $colleague->department_id !== (int) $req->department_id) {
            throw new RuntimeException('Swap with an active colleague from your own department.');
        }
        if (isset(self::leaveMap(collect([$colleague->id]), $mine->date->toDateString(), $mine->date->toDateString())[$colleague->id])) {
            throw new RuntimeException("{$colleague->name} is on leave that day.");
        }
        if (ShiftSwap::where('roster_shift_id', $mine->id)->whereIn('status', ShiftSwap::OPEN)->exists()) {
            throw new RuntimeException('There is already an open swap request for this shift.');
        }
        $theirs = RosterShift::where('employee_id', $colleague->id)->whereDate('date', $mine->date->toDateString())->first();
        if ($theirs && $theirs->isShift() && (string) $theirs->start_time === (string) $mine->start_time && (string) $theirs->end_time === (string) $mine->end_time) {
            throw new RuntimeException("{$colleague->name} already works the same shift that day.");
        }
        $swap = ShiftSwap::create(['requester_id' => $req->id, 'roster_shift_id' => $mine->id, 'colleague_id' => $colleague->id,
            'colleague_shift_id' => $theirs?->isShift() ? $theirs->id : null, 'reason' => $reason, 'status' => 'pending_peer']);
        ActivityLogger::log('roster', 'swap', "{$req->name} asked {$colleague->name} to ".($swap->colleague_shift_id ? 'swap' : 'take').' the '.dmy($mine->date).' '.$mine->label().' shift', $swap);

        return $swap;
    }

    public static function peerRespond(ShiftSwap $swap, Employee $colleague, bool $accept): ShiftSwap
    {
        if ((int) $swap->colleague_id !== (int) $colleague->id || $swap->status !== 'pending_peer') {
            throw new RuntimeException('This request is not waiting for you.');
        }
        $swap->update(['status' => $accept ? 'pending_manager' : 'declined', 'peer_at' => now()]);

        return $swap;
    }

    public static function cancelSwap(ShiftSwap $swap, Employee $req): ShiftSwap
    {
        if ((int) $swap->requester_id !== (int) $req->id || ! in_array($swap->status, ShiftSwap::OPEN, true)) {
            throw new RuntimeException('Only your own open requests can be withdrawn.');
        }
        $swap->update(['status' => 'cancelled']);

        return $swap;
    }

    /** Manager decision; approval exchanges the shifts (or hands the shift over) on the roster. */
    public static function decide(ShiftSwap $swap, bool $approve, ?string $note, User $u): ShiftSwap
    {
        if ($swap->status !== 'pending_manager') {
            throw new RuntimeException('Only requests the colleague has accepted can be decided.');
        }
        if (! $approve && trim((string) $note) === '') {
            throw new RuntimeException('Give a reason for refusing the swap.');
        }
        $selfEmp = Employee::where('user_id', $u->id)->value('id');
        if ($selfEmp && in_array((int) $selfEmp, [(int) $swap->requester_id, (int) $swap->colleague_id], true)) {
            throw new RuntimeException('You cannot decide a swap you are part of.');
        }
        DB::transaction(function () use ($swap, $approve, $note, $u) {
            if ($approve) {
                $mine = RosterShift::lockForUpdate()->find($swap->roster_shift_id);
                if (! $mine || ! $mine->isShift() || (int) $mine->employee_id !== (int) $swap->requester_id || $mine->startsAt()->isPast()) {
                    throw new RuntimeException('The shift has changed or already started – the swap can no longer be applied.');
                }
                $fields = ['kind', 'shift_template_id', 'start_time', 'end_time', 'break_minutes'];
                $theirs = RosterShift::lockForUpdate()->where('employee_id', $swap->colleague_id)->whereDate('date', $mine->date->toDateString())->first();
                if ($swap->colleague_shift_id && (! $theirs || (int) $theirs->id !== (int) $swap->colleague_shift_id || ! $theirs->isShift())) {
                    throw new RuntimeException('The colleague\'s shift has changed – ask them to request again.');
                }
                $a = $mine->only($fields);
                if ($theirs && $theirs->isShift()) {
                    $b = $theirs->only($fields);
                    $mine->update($b + ['notes' => 'Swapped with '.$swap->colleague?->name]);
                    $theirs->update($a + ['notes' => 'Swapped with '.$swap->requester?->name]);
                } else {
                    $mine->update(['kind' => 'off', 'shift_template_id' => null, 'start_time' => null, 'end_time' => null, 'break_minutes' => 0, 'notes' => 'Handed to '.$swap->colleague?->name]);
                    date_upsert(RosterShift::class, ['employee_id' => $swap->colleague_id, 'date' => $mine->date->toDateString()], $a + ['notes' => 'Covering for '.$swap->requester?->name, 'created_by' => $u->id]);
                }
            }
            $swap->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $u->id, 'decided_at' => now(), 'decision_note' => $note]);
        });
        ActivityLogger::log('roster', $approve ? 'swap_ok' : 'swap_no', ($approve ? 'Approved' : 'Refused')." shift swap {$swap->requester?->name} ↔ {$swap->colleague?->name}", $swap);

        return $swap;
    }

    public static function pendingSwaps(): int
    {
        return ShiftSwap::where('status', 'pending_manager')->count();
    }
}
