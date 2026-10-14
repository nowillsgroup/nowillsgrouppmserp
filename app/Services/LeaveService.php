<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Leave balances & approvals.
 *  balance = entitlement (pro-rata in the joining year, rounded to ½ day) + carried forward (capped) − approved.
 *  available = balance − pending. Paid leave may not exceed the available balance; unpaid leave (LWP) is unlimited
 *  and becomes loss of pay in payroll. Approval writes the attendance register (L, or H for a half day).
 *
 *  Ghana (Act 651): s. 20 measures annual leave in *working* days and s. 22 says a public holiday, certified sickness
 *  or confinement does not reduce the entitlement, so a gazetted public holiday and the worker's own rostered day off
 *  are not counted against the balance and are not overwritten in the register (leave_count_all_days=1 counts every
 *  calendar day instead). s. 27 wants the worker told at least 30 days ahead, and every decision messages the worker.
 */
class LeaveService
{
    public static function entitlement(Employee $e, LeaveType $t, int $year): float
    {
        $days = (float) $t->days_per_year;
        if ($e->joining_date && $e->joining_date->year > $year) {
            return 0.0;
        }
        if ($e->joining_date && $e->joining_date->year === $year) {
            $months = 13 - $e->joining_date->month; // the joining month counts
            $days = round($days * $months / 12 * 2) / 2;
        }

        return $days;
    }

    public static function taken(Employee $e, LeaveType $t, int $year, array $statuses = ['approved'], ?int $except = null): float
    {
        return (float) LeaveRequest::where('employee_id', $e->id)->where('leave_type_id', $t->id)->whereIn('status', $statuses)
            ->whereYear('date_from', $year)->when($except, fn ($q) => $q->where('id', '!=', $except))->sum('days');
    }

    public static function carried(Employee $e, LeaveType $t, int $year): float
    {
        if ((float) $t->carry_forward_max <= 0 || ! $t->is_paid) {
            return 0.0;
        }
        $left = self::entitlement($e, $t, $year - 1) - self::taken($e, $t, $year - 1);

        return max(0.0, min((float) $t->carry_forward_max, $left));
    }

    /** [type, entitled, carried, taken, pending, balance, available] per active leave type. */
    public static function balances(Employee $e, ?int $year = null, ?int $except = null): Collection
    {
        $year ??= bdate()->year;

        return LeaveType::where('is_active', true)->orderBy('id')->get()->map(function ($t) use ($e, $year, $except) {
            $ent = self::entitlement($e, $t, $year);
            $car = self::carried($e, $t, $year);
            $taken = self::taken($e, $t, $year, ['approved'], $except);
            $pending = self::taken($e, $t, $year, ['pending'], $except);
            $bal = $ent + $car - $taken;

            return ['type' => $t, 'entitled' => $ent, 'carried' => $car, 'taken' => $taken, 'pending' => $pending,
                'balance' => $t->is_paid ? $bal : null, 'available' => $t->is_paid ? $bal - $pending : null];
        });
    }

    public static function available(Employee $e, LeaveType $t, int $year, ?int $except = null): float
    {
        return self::entitlement($e, $t, $year) + self::carried($e, $t, $year)
            - self::taken($e, $t, $year, ['approved', 'pending'], $except);
    }

    /** Is this date one of the employee's own rest days (rostered day off, or already marked week off)? */
    public static function isDayOff(Employee $e, Carbon $d): bool
    {
        $date = $d->toDateString();
        $roster = \App\Models\RosterShift::where('employee_id', $e->id)->whereDate('date', $date)->first();
        if ($roster) {
            return $roster->kind === 'off';
        }

        return Attendance::where('employee_id', $e->id)->whereDate('date', $date)->where('status', 'WO')->exists();
    }

    /**
     * The days of a leave span that count against the entitlement: every calendar day except gazetted public holidays
     * (Act 651 s. 22) and the employee's own rest days (s. 20 measures annual leave in working days).
     */
    public static function countDays(Employee $e, Carbon $from, Carbon $to, bool $half = false): float
    {
        if ($half) {
            return 0.5;
        }
        if (setting('leave_count_all_days', '0') === '1') {
            return (float) ($from->diffInDays($to) + 1);
        }
        $holidays = HolidayService::inRange($from, $to);
        $n = 0.0;
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            if (isset($holidays[$d->toDateString()]) || self::isDayOff($e, $d)) {
                continue;
            }
            $n++;
        }

        return $n;
    }

    public static function request(Employee $e, LeaveType $t, string $from, string $to, bool $half, ?string $reason, ?User $by = null): LeaveRequest
    {
        $f = Carbon::parse($from)->startOfDay();
        $tt = Carbon::parse($to)->startOfDay();
        if ($tt->lt($f)) {
            throw new RuntimeException('The end date is before the start date.');
        }
        if ($f->year !== $tt->year) {
            throw new RuntimeException('A leave request cannot span two calendar years – split it at 31 December.');
        }
        if ($half && ! $f->equalTo($tt)) {
            throw new RuntimeException('A half day must be a single date.');
        }
        if (! $t->is_active) {
            throw new RuntimeException("{$t->name} is not available.");
        }
        if ($e->joining_date && $f->lt($e->joining_date)) {
            throw new RuntimeException('The leave starts before the joining date.');
        }
        $days = self::countDays($e, $f, $tt, $half);
        if ($days <= 0) {
            throw new RuntimeException('Those dates fall only on public holidays and rest days – there is no leave to take.');
        }
        self::assertNoOverlap($e, $f, $tt);
        PayrollService::assertOpen($f->toDateString(), $tt->toDateString());
        if ($t->is_paid && $days > self::available($e, $t, $f->year) + 0.001) {
            throw new RuntimeException(sprintf('Insufficient %s balance: %s day(s) available, %s requested.', $t->name, self::fmt(self::available($e, $t, $f->year)), self::fmt($days)));
        }
        $req = LeaveRequest::create(['employee_id' => $e->id, 'leave_type_id' => $t->id, 'date_from' => $f, 'date_to' => $tt, 'half_day' => $half,
            'days' => $days, 'reason' => $reason, 'status' => 'pending', 'requested_by' => $by?->id ?? auth()->id()]);
        ActivityLogger::log('hr', 'leave_request', "Leave request {$t->code} {$req->periodLabel()} ({$days} d) for {$e->name}", $req);

        return $req;
    }

    public static function approve(LeaveRequest $r, ?string $note = null): LeaveRequest
    {
        if ($r->status !== 'pending') {
            throw new RuntimeException('Only a pending request can be approved.');
        }
        self::assertNotSelf($r);
        $e = $r->employee;
        $t = $r->leaveType;
        PayrollService::assertOpen($r->date_from->toDateString(), $r->date_to->toDateString());
        self::assertNoOverlap($e, $r->date_from, $r->date_to, $r->id);
        $days = self::countDays($e, $r->date_from, $r->date_to, (bool) $r->half_day);
        if ($t->is_paid && $days > self::available($e, $t, $r->date_from->year, $r->id) + 0.001) {
            throw new RuntimeException("Insufficient {$t->name} balance to approve.");
        }
        DB::transaction(function () use ($r, $t, $note, $e, $days) {
            $holidays = HolidayService::inRange($r->date_from, $r->date_to);
            for ($d = $r->date_from->copy(); $d->lte($r->date_to); $d->addDay()) {
                // a public holiday stays a paid holiday (ss. 22, 72) and a rest day stays a rest day
                if (! $r->half_day && (isset($holidays[$d->toDateString()]) || self::isDayOff($e, $d))) {
                    continue;
                }
                $att = Attendance::firstOrNew(['employee_id' => $r->employee_id, 'date' => $d->toDateString()]);
                $att->fill(['status' => $r->half_day ? 'H' : 'L', 'leave_request_id' => $r->id, 'remarks' => "Leave {$t->code}"]);
                if (! $r->half_day) {
                    $att->in_time = $att->out_time = null;
                }
                $att->save();
            }
            $r->update(['status' => 'approved', 'days' => $days, 'decided_by' => auth()->id(), 'decided_at' => now(), 'decision_note' => $note]);
        });
        ActivityLogger::log('hr', 'leave_approve', "Approved leave {$t->code} {$r->periodLabel()} for {$r->employee->name}", $r);
        self::notify($r, 'approved', 'Your '.$t->name.' for '.$r->periodLabel().' ('.self::fmt($days).' working day(s)) was approved'.($note ? ' – '.$note : '').'.');

        return $r;
    }

    public static function reject(LeaveRequest $r, string $note): LeaveRequest
    {
        if ($r->status !== 'pending') {
            throw new RuntimeException('Only a pending request can be rejected.');
        }
        self::assertNotSelf($r);
        $r->update(['status' => 'rejected', 'decided_by' => auth()->id(), 'decided_at' => now(), 'decision_note' => $note]);
        ActivityLogger::log('hr', 'leave_reject', "Rejected leave {$r->leaveType->code} {$r->periodLabel()} for {$r->employee->name}: {$note}", $r);
        self::notify($r, 'rejected', 'Your '.$r->leaveType->name.' for '.$r->periodLabel().' was not approved: '.$note);

        return $r;
    }

    /** Pending → withdrawn; approved → attendance rows written by the approval are removed (unless payroll is locked). */
    public static function cancel(LeaveRequest $r, ?string $note = null): LeaveRequest
    {
        if (! in_array($r->status, ['pending', 'approved'], true)) {
            throw new RuntimeException('This request is already '.$r->status.'.');
        }
        if ($r->status === 'approved') {
            PayrollService::assertOpen($r->date_from->toDateString(), $r->date_to->toDateString());
        }
        DB::transaction(function () use ($r, $note) {
            Attendance::where('leave_request_id', $r->id)->delete();
            $r->update(['status' => 'cancelled', 'decision_note' => $note ?? $r->decision_note, 'decided_by' => $r->decided_by ?? auth()->id(), 'decided_at' => $r->decided_at ?? now()]);
        });
        ActivityLogger::log('hr', 'leave_cancel', "Cancelled leave {$r->leaveType->code} {$r->periodLabel()} for {$r->employee->name}", $r);
        self::notify($r, 'cancelled', 'The '.$r->leaveType->name.' for '.$r->periodLabel().' was cancelled'.($note ? ' – '.$note : '').'.');

        return $r;
    }

    protected static function assertNoOverlap(Employee $e, Carbon $f, Carbon $t, ?int $except = null): void
    {
        $clash = LeaveRequest::where('employee_id', $e->id)->whereIn('status', ['pending', 'approved'])
            ->whereDate('date_from', '<=', $t->toDateString())->whereDate('date_to', '>=', $f->toDateString())
            ->when($except, fn ($q) => $q->where('id', '!=', $except))->first();
        if ($clash) {
            throw new RuntimeException("Overlaps an existing {$clash->status} request ({$clash->periodLabel()}).");
        }
    }

    protected static function assertNotSelf(LeaveRequest $r): void
    {
        if (auth()->id() && (int) $r->employee?->user_id === (int) auth()->id()) {
            throw new RuntimeException('You cannot decide on your own leave request.');
        }
    }

    /** Best-effort SMS / WhatsApp to the worker about a decision on their leave. */
    protected static function notify(LeaveRequest $r, string $kind, string $body): void
    {
        try {
            $to = $r->employee?->phone;
            if ($to && class_exists(MessageService::class)) {
                MessageService::send($to, $body.' – '.hotel()->name, MessageService::defaultChannel(), 'leave_'.$kind, $r);
            }
        } catch (\Throwable) {
            // a missing gateway must never block a leave decision
        }
    }

    public static function fmt(float $d): string
    {
        return rtrim(rtrim(number_format($d, 1), '0'), '.');
    }
}
