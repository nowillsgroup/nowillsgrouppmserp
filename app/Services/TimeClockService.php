<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\RosterShift;
use App\Models\TimePunch;
use App\Models\User;
use App\Support\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * v4.9 Time clock: staff clock in / out from self-service (optionally only from the hotel network) or a shared
 * kiosk with employee code + PIN. Punches are matched to the roster (late / early / unrostered), managers correct
 * them with a reason, and approving a day writes the attendance that payroll already uses (present, half day,
 * absent for no-shows, week off; in / out times drive overtime).
 */
class TimeClockService
{
    public static function grace(): int
    {
        return (int) setting('timeclock_grace_min', '5');
    }

    public static function earlyWindow(): int
    {
        return (int) setting('timeclock_early_min', '120');
    }

    /** Self-service clocking allowed from this address? Empty list = anywhere. */
    public static function ipAllowed(?string $ip): bool
    {
        $list = array_values(array_filter(array_map('trim', explode(',', (string) setting('timeclock_self_ips', '')))));

        return ! $list || ($ip && IpUtils::checkIp($ip, $list));
    }

    public static function openPunch(Employee $e): ?TimePunch
    {
        return TimePunch::where('employee_id', $e->id)->where('status', 'open')->latest('clock_in')->first();
    }

    /** The shift being worked at a moment: today's, or last night's overnight shift that has not ended yet. */
    public static function shiftAt(Employee $e, Carbon $at): ?RosterShift
    {
        $y = RosterShift::where('employee_id', $e->id)->where('date', $at->copy()->subDay()->toDateString())->first();
        if ($y && $y->isShift() && $at->lt($y->endsAt())) {
            return $y;
        }
        $t = RosterShift::where('employee_id', $e->id)->where('date', $at->toDateString())->first();

        return $t && $t->isShift() ? $t : null;
    }

    protected static function onLeave(Employee $e, string $date): bool
    {
        $l = RosterService::leaveMap(collect([$e->id]), $date, $date)[$e->id][$date] ?? null;

        return $l && ! $l->half_day;
    }

    public static function clockIn(Employee $e, string $source, ?string $ip = null, ?Carbon $at = null): TimePunch
    {
        $at ??= now();
        if (! $e->is_active) {
            throw new RuntimeException('This employee record is inactive.');
        }
        if ($o = self::openPunch($e)) {
            throw new RuntimeException("{$e->name} is already clocked in since ".$o->clock_in->format('D H:i').'.');
        }
        if (self::onLeave($e, $at->toDateString())) {
            throw new RuntimeException("{$e->name} is on approved leave today.");
        }
        $shift = self::shiftAt($e, $at);
        if ($shift && $at->lt($shift->startsAt()->copy()->subMinutes(self::earlyWindow()))) {
            throw new RuntimeException('Your shift starts at '.$shift->startsAt()->format('H:i').' – clock in from '.$shift->startsAt()->copy()->subMinutes(self::earlyWindow())->format('H:i').'.');
        }
        if (! $shift && setting('timeclock_unrostered', '1') !== '1') {
            throw new RuntimeException("{$e->name} is not rostered now – ask a manager to record the hours.");
        }
        if ($shift && TimePunch::where('roster_shift_id', $shift->id)->where('status', '!=', 'open')->where('clock_out', '>', $at)->exists()) {
            throw new RuntimeException('This time overlaps an earlier punch.');
        }
        $first = ! $shift || ! TimePunch::where('roster_shift_id', $shift->id)->exists();
        $late = 0;
        if ($shift && $first && $at->gt($shift->startsAt()->copy()->addMinutes(self::grace()))) {
            $late = (int) $shift->startsAt()->diffInMinutes($at);
        }
        $p = TimePunch::create(['employee_id' => $e->id, 'work_date' => ($shift?->date ?? $at)->toDateString(), 'roster_shift_id' => $shift?->id,
            'clock_in' => $at, 'in_source' => $source, 'in_ip' => $ip, 'late_minutes' => min($late, 65535), 'status' => 'open']);
        ActivityLogger::log('timeclock', 'in', "{$e->name} clocked in ({$source})".($late ? " – {$late} min late" : '').(! $shift ? ' – not rostered' : ''), $p);

        return $p;
    }

    public static function clockOut(Employee $e, string $source, ?string $ip = null, ?Carbon $at = null): TimePunch
    {
        $at ??= now();
        $p = self::openPunch($e);
        if (! $p) {
            throw new RuntimeException("{$e->name} is not clocked in.");
        }
        if ($at->lte($p->clock_in)) {
            throw new RuntimeException('Clock-out must be after the clock-in time.');
        }
        $p->fill(['clock_out' => $at, 'out_source' => $source, 'out_ip' => $ip, 'status' => 'closed']);
        self::measure($p);
        $p->save();
        ActivityLogger::log('timeclock', 'out', "{$e->name} clocked out ({$source}) – ".number_format($p->hours(), 2).' h'.($p->early_minutes ? ", left {$p->early_minutes} min early" : ''), $p);

        return $p;
    }

    /** @return array{0:string,1:TimePunch} ['in'|'out', punch] */
    public static function toggle(Employee $e, string $source, ?string $ip = null, ?Carbon $at = null): array
    {
        return self::openPunch($e) ? ['out', self::clockOut($e, $source, $ip, $at)] : ['in', self::clockIn($e, $source, $ip, $at)];
    }

    /**
     * Worked minutes (early arrival before the rostered start is not paid; the rostered break – or the default
     * break for unrostered stints over 6 h – is deducted) and early-leave minutes.
     */
    public static function measure(TimePunch $p): void
    {
        if (! $p->clock_out) {
            $p->worked_minutes = null;

            return;
        }
        $shift = $p->roster_shift_id ? RosterShift::find($p->roster_shift_id) : null;
        $in = $p->clock_in->copy();
        if ($shift?->isShift() && $in->lt($shift->startsAt())) {
            $in = $shift->startsAt();
        }
        $mins = max(0, (int) $in->diffInMinutes($p->clock_out, false));
        $break = $shift?->isShift() ? (int) $shift->break_minutes : ($mins > 360 ? (int) setting('timeclock_break_min', '60') : 0);
        $p->worked_minutes = min(65535, max(0, $mins - ($mins > $break * 2 ? $break : 0)));
        $early = 0;
        if ($shift?->isShift() && $p->clock_out->lt($shift->endsAt()->copy()->subMinutes(self::grace()))) {
            $early = (int) $p->clock_out->diffInMinutes($shift->endsAt());
        }
        $p->early_minutes = min(65535, $early);
    }

    // ───────────────────────── kiosk & PIN ─────────────────────────

    /** Resolve an employee from code + PIN; 5 wrong PINs lock that employee out of the kiosk for 15 minutes. */
    public static function kioskEmployee(string $code, string $pin): Employee
    {
        $e = Employee::where('emp_code', strtoupper(trim($code)))->where('is_active', true)->first();
        $key = 'kiosk:'.Tenant::id().':'.($e?->id ?? 'x'.strtoupper(trim($code)));
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw new RuntimeException('Too many wrong PINs – try again in '.max(1, (int) ceil(RateLimiter::availableIn($key) / 60)).' min or ask a manager.');
        }
        if (! $e || ! $e->clock_pin || ! Hash::check($pin, $e->clock_pin)) {
            RateLimiter::hit($key, 900);

            throw new RuntimeException('Employee code or PIN not recognised.');
        }
        RateLimiter::clear($key);

        return $e;
    }

    public static function setPin(Employee $e, string $pin): void
    {
        if (! preg_match('/^\d{4,6}$/', $pin)) {
            throw new RuntimeException('The PIN must be 4 to 6 digits.');
        }
        $digits = str_split($pin);
        $asc = implode('', range(0, 9)).'0123';
        if (count(array_unique($digits)) === 1 || str_contains($asc, $pin) || str_contains(strrev($asc), $pin)) {
            throw new RuntimeException('Choose a less obvious PIN (no repeated or sequential digits).');
        }
        $e->forceFill(['clock_pin' => Hash::make($pin)])->save();
        ActivityLogger::log('timeclock', 'pin', "Time-clock PIN set for {$e->name}", $e);
    }

    // ───────────────────────── manager corrections ─────────────────────────

    /** Add or correct a punch. Times are 'Y-m-d H:i'. A correction of an approved punch needs the day re-approved. */
    public static function managerSave(Employee $e, string $in, ?string $out, string $reason, User $u, ?TimePunch $p = null): TimePunch
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw new RuntimeException('Give a reason for the correction (at least 5 characters).');
        }
        $cin = Carbon::parse($in);
        $cout = $out ? Carbon::parse($out) : null;
        if ($cin->gt(now()) || ($cout && $cout->gt(now()))) {
            throw new RuntimeException('Times cannot be in the future.');
        }
        if ($cout && ($cout->lte($cin) || $cin->diffInMinutes($cout) > 16 * 60)) {
            throw new RuntimeException('Clock-out must be after clock-in and within 16 hours.');
        }
        if (! $cout && self::openPunch($e) && (! $p || self::openPunch($e)->id !== $p->id)) {
            throw new RuntimeException("{$e->name} already has an open punch.");
        }
        $shift = self::shiftAt($e, $cin->copy()->addMinutes(self::earlyWindow())) ?? self::shiftAt($e, $cin);
        $workDate = ($shift?->date ?? $cin)->toDateString();
        PayrollService::assertOpen($workDate);
        $overlap = TimePunch::where('employee_id', $e->id)->when($p, fn ($q) => $q->whereKeyNot($p->id))
            ->where('clock_in', '<', $cout ?? $cin->copy()->addMinute())->where(fn ($q) => $q->whereNull('clock_out')->orWhere('clock_out', '>', $cin))->exists();
        if ($overlap) {
            throw new RuntimeException('These times overlap another punch of '.$e->name.'.');
        }
        $p ??= new TimePunch(['employee_id' => $e->id, 'in_source' => 'manager']);
        $late = 0;
        if ($shift && $cin->gt($shift->startsAt()->copy()->addMinutes(self::grace()))) {
            $late = (int) $shift->startsAt()->diffInMinutes($cin);
        }
        $p->fill(['work_date' => $workDate, 'roster_shift_id' => $shift?->id, 'clock_in' => $cin, 'clock_out' => $cout, 'late_minutes' => min($late, 65535),
            'out_source' => $cout ? ($p->out_source && $p->clock_out?->equalTo($cout) ? $p->out_source : 'manager') : null,
            'status' => $cout ? 'closed' : 'open', 'edited_by' => $u->id, 'edit_reason' => trim($reason), 'approved_by' => null, 'approved_at' => null]);
        self::measure($p);
        $p->save();
        ActivityLogger::log('timeclock', 'edit', "Time punch for {$e->name} on ".dmy($workDate).' set to '.$cin->format('H:i').'–'.($cout?->format('H:i') ?? 'open')." ({$reason})", $p);

        return $p;
    }

    public static function deletePunch(TimePunch $p, string $reason, User $u): void
    {
        if ($p->status === 'approved') {
            throw new RuntimeException('Approved punches cannot be deleted – correct the times instead.');
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw new RuntimeException('Give a reason for deleting the punch.');
        }
        ActivityLogger::log('timeclock', 'delete', "Deleted time punch of {$p->employee?->name} on ".dmy($p->work_date).' '.$p->clock_in->format('H:i')." ({$reason})", $p);
        $p->delete();
    }

    // ───────────────────────── day board & approval ─────────────────────────

    /**
     * One row per employee who was rostered, punched or on leave that day.
     * status: leave | off | upcoming | on_duty | done | no_show | missing_out | unrostered
     */
    public static function board(string $date, ?int $dept = null): Collection
    {
        $emps = RosterService::employees($dept, Carbon::parse($date))->keyBy('id');
        $shifts = RosterShift::with('template')->whereIn('employee_id', $emps->keys())->where('date', $date)->get()->keyBy('employee_id');
        $punches = TimePunch::whereIn('employee_id', $emps->keys())->where('work_date', $date)->orderBy('clock_in')->get()->groupBy('employee_id');
        $leave = RosterService::leaveMap($emps->keys(), $date, $date);
        $now = now();
        $rows = collect();
        foreach ($emps as $e) {
            $s = $shifts[$e->id] ?? null;
            $ps = $punches[$e->id] ?? collect();
            $l = $leave[$e->id][$date] ?? null;
            if (! $s && $ps->isEmpty() && ! $l) {
                continue;
            }
            $open = $ps->firstWhere('status', 'open');
            $flags = [];
            if ($l && ! $l->half_day && $ps->isEmpty()) {
                $status = 'leave';
            } elseif ($open) {
                $stale = $open->clock_in->lt($now->copy()->subHours(16)) || ($s?->isShift() && $now->gt($s->endsAt()->copy()->addHours(4)));
                $status = $stale ? 'missing_out' : 'on_duty';
            } elseif ($ps->isNotEmpty()) {
                $status = $s?->isShift() ? 'done' : 'unrostered';
            } elseif ($s?->isShift()) {
                $status = $now->gt($s->startsAt()->copy()->addMinutes(max(30, self::grace()))) ? 'no_show' : 'upcoming';
            } else {
                $status = 'off';
            }
            $late = (int) $ps->sum('late_minutes');
            $early = (int) $ps->sum('early_minutes');
            if ($late) {
                $flags[] = "late {$late} min";
            }
            if ($early) {
                $flags[] = "left {$early} min early";
            }
            if ($ps->whereNotNull('edited_by')->isNotEmpty()) {
                $flags[] = 'corrected';
            }
            if ($ps->isNotEmpty() && ! $s?->isShift()) {
                $flags[] = 'not rostered';
            }
            $rows->push(['e' => $e, 'shift' => $s, 'punches' => $ps, 'leave' => $l, 'status' => $status, 'late' => $late, 'early' => $early,
                'worked' => round($ps->sum('worked_minutes') / 60, 2), 'planned' => round(($s?->paidMinutes() ?? 0) / 60, 2), 'flags' => $flags,
                'approved' => $ps->isNotEmpty() && $ps->every(fn ($p) => $p->status === 'approved')]);
        }

        return $rows->sortBy(fn ($r) => [$r['shift']?->start_time ?? '99', $r['e']->name])->values();
    }

    /**
     * Approve a day: writes attendance for rostered / punched employees (leave-owned days untouched) and marks the
     * punches approved. Employees with an open punch are skipped until it is closed.
     *
     * @return array{approved:int, blocked:array<string>}
     */
    public static function approveDay(string $date, ?int $dept, User $u): array
    {
        if ($date > now()->toDateString()) {
            throw new RuntimeException('Days in the future cannot be approved.');
        }
        PayrollService::assertOpen($date);
        $done = 0;
        $blocked = [];
        $half = max(1.0, (float) setting('payroll_shift_hours', '9')) / 2;
        DB::transaction(function () use ($date, $dept, $u, $half, &$done, &$blocked) {
            foreach (self::board($date, $dept) as $r) {
                $e = $r['e'];
                if ($r['status'] === 'leave') {
                    continue;
                }
                if (in_array($r['status'], ['on_duty', 'missing_out'], true)) {
                    $blocked[] = $e->name;

                    continue;
                }
                if ($r['status'] === 'upcoming' || ($r['status'] === 'no_show' && $date === now()->toDateString())) {
                    continue; // today's shift may still happen
                }
                $att = Attendance::firstOrNew(['employee_id' => $e->id, 'date' => $date]);
                if ($att->exists && $att->leave_request_id) {
                    // a half-day leave keeps its status; only the clock times are recorded
                    if ($att->status === 'H' && $r['punches']->isNotEmpty()) {
                        $att->update(['in_time' => self::effIn($r['punches']->first())->format('H:i'), 'out_time' => $r['punches']->max('clock_out')?->format('H:i')]);
                    }
                } elseif ($r['punches']->isNotEmpty()) {
                    $planned = $r['planned'] ?: (float) setting('payroll_shift_hours', '9') - 1;
                    $att->fill(['status' => $r['worked'] + 0.001 < min($half, $planned / 2) ? 'H' : 'P', 'in_time' => self::effIn($r['punches']->first())->format('H:i'),
                        'out_time' => $r['punches']->max('clock_out')->format('H:i'), 'remarks' => 'Time clock'.($r['flags'] ? ' – '.implode(', ', $r['flags']) : '')])->save();
                } elseif ($r['status'] === 'no_show') {
                    $att->fill(['status' => 'A', 'in_time' => null, 'out_time' => null, 'remarks' => 'Time clock – rostered '.$r['shift']->label().', no clock-in'])->save();
                } elseif ($r['status'] === 'off') {
                    $att->fill(['status' => 'WO', 'in_time' => null, 'out_time' => null, 'remarks' => 'Rostered day off'])->save();
                } else {
                    continue;
                }
                TimePunch::whereIn('id', $r['punches']->pluck('id'))->update(['status' => 'approved', 'approved_by' => $u->id, 'approved_at' => now()]);
                $done++;
            }
        });
        ActivityLogger::log('timeclock', 'approve', 'Approved time & attendance for '.dmy($date).($dept ? ' (department #'.$dept.')' : '').": {$done} employee(s)".($blocked ? ', waiting for clock-out: '.implode(', ', $blocked) : ''), null);

        return ['approved' => $done, 'blocked' => $blocked];
    }

    /** Paid start of a punch: the rostered start when the employee came in early. */
    public static function effIn(TimePunch $p): Carbon
    {
        $s = $p->roster_shift_id ? RosterShift::find($p->roster_shift_id) : null;

        return $s?->isShift() && $p->clock_in->lt($s->startsAt()) ? $s->startsAt() : $p->clock_in;
    }

    /** Nav / dashboard: forgotten clock-outs and closed punches from past days still waiting for approval. */
    public static function attentionCount(): int
    {
        return TimePunch::where('status', 'open')->where('clock_in', '<', now()->subHours(16))->count()
            + TimePunch::where('status', 'closed')->where('work_date', '<', now()->toDateString())->distinct('employee_id')->count('employee_id');
    }

    /** Today at a glance for the dashboard. */
    public static function today(): array
    {
        $rows = self::board(now()->toDateString());

        return ['on_duty' => $rows->where('status', 'on_duty')->count(), 'late' => $rows->where('late', '>', 0)->count(),
            'no_show' => $rows->where('status', 'no_show')->count(), 'upcoming' => $rows->where('status', 'upcoming')->count()];
    }
}
