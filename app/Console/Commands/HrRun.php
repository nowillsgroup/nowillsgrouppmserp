<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Hotel;
use App\Models\LeaveRequest;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\ShiftSwap;
use App\Services\ActivityLogger;
use App\Services\HolidayService;
use App\Services\PayrollService;
use App\Services\RosterService;
use App\Services\StatutoryService;
use App\Services\TimeClockService;
use App\Support\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * v7.43 – the HR & workforce nightly task.
 *
 * Ghana's Labour Act 2003 (Act 651) and the pension / tax rules are calendar-driven – public
 * holidays, the 12 h daily and 48 h weekly rest periods, notice of leave, and the 14th / 15th
 * remittance deadlines – so the module needs something that reads the calendar every day instead
 * of only when a user happens to open a page.
 *
 * Writes: today's public-holiday attendance rows, and – with --draft – a draft payroll run.
 * Everything else is only reported: approving leave, approving a swap, approving or paying a run
 * and remitting SSNIT / Tier 2 / PAYE all need a named human being on the record.
 */
class HrRun extends Command
{
    protected $signature = 'pms:hr-run {--hotel= : Hotel code (default: all hotels)}
                            {--month= : Payroll month YYYY-MM (default: the current month)}
                            {--draft : prepare a draft payroll run for the month when none exists}
                            {--dry : report only – write nothing}';

    protected $description = 'HR & workforce: public-holiday attendance, leave and swap SLAs, roster rest-period checks, payroll chasing and SSNIT / Tier 2 / PAYE deadlines (Ghana)';

    public function handle(): int
    {
        $month = (string) ($this->option('month') ?: bdate()->format('Y-m'));
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $this->error("{$month} is not a payroll month – use YYYY-MM.");

            return self::FAILURE;
        }
        $dry = (bool) $this->option('dry');
        $today = bdate();
        $prev = Tenant::hotel();

        foreach (Hotel::where('is_active', true)->when($this->option('hotel'), fn ($q, $c) => $q->where('code', $c))->get() as $hotel) {
            Tenant::set($hotel);
            $lines = array_merge(
                $this->holidays($today, $dry),
                $this->leave($today),
                $this->roster($today),
                $this->clock(),
                $this->payroll($month, $dry, $today),
                $this->statutory(),
            );
            foreach ($lines as $line) {
                $this->line("[{$hotel->code}] {$line}");
            }
            if (! $lines) {
                $this->line("[{$hotel->code}] HR clear: no public holiday, nothing past its SLA, payroll {$month} on track.");
            }
        }
        Tenant::set($prev);

        return self::SUCCESS;
    }

    /** ss. 72, 77: everyone is paid for a public holiday – mark the register so payroll can see it. */
    private function holidays(Carbon $today, bool $dry): array
    {
        $out = [];
        $on = HolidayService::on($today);
        if ($on && $on['holiday']) {
            $marked = 0;
            $kept = 0;
            foreach (Employee::where('is_active', true)->orderBy('id')->get() as $e) {
                $row = Attendance::where('employee_id', $e->id)->whereDate('date', $today->toDateString())->first();
                if ($row?->leave_request_id) {
                    $kept++; // s. 22: approved leave and a public holiday do not cost each other

                    continue;
                }
                if ($row && $row->status === 'PH') {
                    continue;
                }
                $marked++;
                if ($dry) {
                    continue;
                }
                if ($row) {
                    $row->update(['status' => 'PH', 'remarks' => $on['name'].' · marked by the HR task']);
                } else {
                    Attendance::create(['employee_id' => $e->id, 'date' => $today->toDateString(), 'status' => 'PH', 'remarks' => $on['name'].' · marked by the HR task']);
                }
            }
            if ($marked && ! $dry) {
                ActivityLogger::log('hr', 'holiday', "Marked {$on['name']} (".dmy($today).") as a public holiday for {$marked} employee(s)");
            }
            $out[] = "Public holiday today: {$on['name']} – {$marked} attendance row(s) ".($dry ? 'would be marked PH' : 'marked PH')
                .($kept ? ", {$kept} kept on approved leave" : '')
                .'. Everyone is paid for it; hours clocked on it are paid at '.HolidayService::cfg('payroll_holiday_multiplier').'× (Act 651 ss. 72, 77).';
        }
        foreach (HolidayService::upcoming((int) HolidayService::cfg('hr_holiday_notify_days')) as $h) {
            if ($h['date']->toDateString() === $today->toDateString()) {
                continue;
            }
            $out[] = "Public holiday in {$h['days']} day(s): {$h['name']} (".dmy($h['date']).', a '.$h['date']->format('l').') – roster and pay for it now.';
        }

        return $out;
    }

    /** s. 20 annual leave, s. 27 notice of the leave start, and the manager's decision SLA. */
    private function leave(Carbon $today): array
    {
        $out = [];
        $sla = max(1, (int) setting('hr_leave_sla_hours', '24'));
        $late = LeaveRequest::with('employee', 'leaveType')->where('status', 'pending')
            ->where('created_at', '<', now()->subHours($sla))->orderBy('created_at')->get();
        foreach ($late as $r) {
            $out[] = 'Leave waiting '.(int) $r->created_at->diffInHours(now(), false)." h (SLA {$sla} h): "
                .($r->employee?->name ?? '?').' – '.($r->leaveType?->name ?? 'leave').' '.dmy($r->date_from).'–'.dmy($r->date_to)
                .($r->half_day ? ' (half day)' : '').' – decide it under HR › Leave.';
        }
        $starting = LeaveRequest::with('employee')->where('status', 'approved')
            ->whereDate('date_from', 'between', [$today->toDateString(), $today->copy()->addDays(7)->toDateString()])->orderBy('date_from')->get();
        if ($starting->count()) {
            $out[] = $starting->count().' approved leave period(s) start in the next 7 days: '
                .$starting->take(4)->map(fn ($r) => ($r->employee?->name ?? '?').' from '.dmy($r->date_from))->join('; ')
                .($starting->count() > 4 ? '; …' : '').' – roster around them.';
        }
        $onLeave = LeaveRequest::with('employee')->where('status', 'approved')
            ->whereDate('date_from', '<=', $today->toDateString())->whereDate('date_to', '>=', $today->toDateString())->count();
        if ($onLeave) {
            $out[] = "{$onLeave} employee(s) on approved leave today.";
        }

        return $out;
    }

    /** ss. 33, 41, 42: the 40 h week, the 12 h daily rest, the 48 h weekly rest, plus swaps on a manager. */
    private function roster(Carbon $today): array
    {
        $out = [];
        $week = RosterService::weekStart($today);
        $depts = Employee::where('is_active', true)->whereNotNull('department_id')->pluck('department_id')->unique()->values();
        foreach ([0, 1] as $i) {
            $w = $week->copy()->addWeeks($i);
            $label = $i === 0 ? 'this week' : 'next week';
            $unpublished = [];
            foreach ($depts as $dept) {
                $name = Department::find($dept)?->name ?? "Dept {$dept}";
                $rw = RosterService::week((int) $dept, $w);
                if (! $rw || $rw->status !== 'published') {
                    // only next week is chased: this week is already under way and may be ad-hoc
                    if ($i === 1) {
                        $unpublished[] = $name;
                    }

                    continue;
                }
                foreach (RosterService::grid((int) $dept, $w)['warnings'] as $eid => $msgs) {
                    foreach ($msgs as $m) {
                        $out[] = $name.' '.($i === 0 ? '' : "{$label} ").trim(Employee::find($eid)?->name ?? '').': '.$m;
                    }
                }
            }
            if ($unpublished) {
                $out[] = 'No published roster for '.$label.' ('.dmy($w).'–'.dmy($w->copy()->addDays(6)).') in '
                    .implode(', ', $unpublished).' – publish it so the workers know their shifts in advance.';
            }
        }
        foreach (ShiftSwap::with('requester', 'colleague', 'shift')->where('status', 'pending_manager')->orderBy('created_at')->get() as $s) {
            $out[] = 'Shift swap waiting for a manager: '.($s->requester?->name ?? '?').' ⇄ '.($s->colleague?->name ?? '?')
                .' for '.dmy($s->shift?->date ?? $today).' – decide it under HR & Payroll › Duty Roster.';
        }
        if ($peers = ShiftSwap::where('status', 'pending_peer')->count()) {
            $out[] = "{$peers} swap request(s) still waiting for the colleague to answer.";
        }

        return $out;
    }

    private function clock(): array
    {
        $n = TimeClockService::attentionCount();

        return $n ? ["{$n} time-clock item(s) need attention: missing clock-outs or past days still to approve (HR › Time Clock)."] : [];
    }

    /** The payroll month: draft / approved / paid, the minimum-wage floor and the payday. */
    private function payroll(string $month, bool $dry, Carbon $today): array
    {
        $out = [];
        $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay();
        $run = PayrollRun::whereDate('period_start', $start->toDateString())->first();
        $sched = PayrollService::scheduleFor($start);

        if (! $run) {
            if ($this->option('draft') && ! $dry) {
                try {
                    $run = PayrollService::createRun($start->copy(), 'Prepared by the nightly HR task');
                    PayrollService::generate($run, false);
                    $out[] = "Draft payroll {$run->run_no} prepared for {$start->format('F Y')}: {$run->employees} payslip(s), gross ".money($run->gross)
                        .', net '.money($run->net).". PAYE bands: {$sched['name']}. Someone other than the preparer must approve it.";
                } catch (\Throwable $e) {
                    $out[] = "Could not prepare the {$start->format('F Y')} payroll: {$e->getMessage()}";
                }
            } else {
                $out[] = "No payroll run for {$start->format('F Y')} yet".($dry ? '.' : ' – open Payroll › New run, or pass --draft.');
            }

            return $out;
        }

        $below = StatutoryService::belowMinimumWage()->whereIn('id', Payslip::where('payroll_run_id', $run->id)->pluck('employee_id')->all());
        if ($below->isNotEmpty()) {
            $out[] = "{$run->run_no}: {$below->count()} payslip(s) below the national minimum wage of ".money(StatutoryService::minWageMonthly())
                .' a month ('.$below->pluck('name')->take(3)->join(', ').($below->count() > 3 ? ', …' : '').') – raise the basic before approving.';
        }
        $due = $start->copy()->day(min(28, max(1, (int) setting('payroll_payday', '28'))));
        if ($run->status === 'draft') {
            $out[] = "{$run->run_no} ({$start->format('F Y')}) is still a draft: gross ".money($run->gross).', net '.money($run->net)
                .". Payday is the {$due->format('jS')} – approve it in time, and re-check the PAYE bands ({$sched['name']}) if the run is old.";
        } elseif ($run->status === 'approved') {
            $out[] = $today->gt($due)
                ? "{$run->run_no} is approved but not paid and payday ({$due->format('j M')}) has passed – mark it paid once the bank file is through."
                : "{$run->run_no} is approved and due for payment on {$due->format('j M')}.";
        }

        return $out;
    }

    /** SSNIT and Tier 2 by the 14th, PAYE by the 15th of the following month. */
    private function statutory(): array
    {
        $out = [];
        foreach (StatutoryService::alerts() as $a) {
            $out[] = ($a['overdue'] ? 'OVERDUE' : 'Due').' '.strtoupper($a['kind'])." for {$a['period']}: ".money($a['total']).' by '.dmy($a['due'])
                .' – file and pay it under Payroll › Statutory (SSNIT charges 3% a month compounded, GRA 10% plus interest).';
        }

        return $out;
    }
}
