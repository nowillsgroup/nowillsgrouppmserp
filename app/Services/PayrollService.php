<?php

namespace App\Services;

use App\Models\AccountHead;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\PayComponent;
use App\Models\PaymentMode;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PayslipLine;
use App\Models\StaffAdvance;
use App\Support\Numbering;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Monthly payroll.
 *
 *  • Attendance → paid days: P and WO are paid, A is loss of pay (LOP), L is paid unless the approved leave type is unpaid,
 *    H is half present (the other half is paid leave when covered by an approved half-day leave, otherwise LOP).
 *    Unmarked days count as present by default (setting payroll_unmarked). Days before joining / after leaving are not employed.
 *  • Overtime = hours beyond the shift (payroll_shift_hours) on present days, paid at basic ÷ days ÷ shift × payroll_ot_multiplier.
 *  • Earnings: BASIC (pro-rata), components (fixed / % of basic), OT, manual lines. Deductions & employer contributions:
 *    fixed, % of basic (with a base cap), % of gross (while gross ≤ ceiling), slab on gross, progressive income tax, and
 *    salary-advance instalments.
 *  • Ghana (v4.3): SSNIT 5.5% employee / 8% employer (Tier 1) + 5% employer (Tier 2) on basic up to the insurable cap;
 *    PAYE on GRA monthly bands over chargeable income = taxable earnings − reliefs (SSNIT employee contribution).
 *  • Draft → approved (accrued in the ledger at the period end) → paid (salaries payable cleared by payment mode).
 *    Attendance and leave inside an approved period are locked until the run is reopened.
 */
class PayrollService
{
    /** SSNIT maximum insurable earnings per month (GH¢ 69,000 from 1 Jan 2026 – SSNIT / NPRA notice of 14 Jan 2026). */
    public const SSNIT_CAP = 69000;

    /**
     * GRA PAYE – resident individuals, monthly bands from 1 Jan 2026 (cumulative upper limits in GH¢, rate %):
     * first 490 @ 0 · next 110 @ 5 · next 130 @ 10 · next 3,166.67 @ 17.5 · next 16,000 @ 25 · next 30,520 @ 30 · above 50,416.67 @ 35.
     */
    public const PAYE_BANDS = [[490, 0], [600, 5], [730, 10], [3896.67, 17.5], [19896.67, 25], [50416.67, 30], [null, 35]];

    // ───────────────────────── defaults ─────────────────────────

    /** Default pay structure & leave types for a property (idempotent; existing codes are never touched). */
    public static function ensureDefaults(): void
    {
        ChartOfAccounts::ensureLight();
        $sys = fn ($k) => AccountHead::sys($k);
        $defs = [
            ['BASIC', 'Basic salary', 'earning', 'system', 0, ['sort_order' => 0, 'expense_head_id' => $sys('salaries')]],
            ['HOUSING', 'Housing allowance', 'earning', 'percent_basic', 15, ['sort_order' => 10]],
            ['TRANSPORT', 'Transport allowance', 'earning', 'fixed', 300, ['sort_order' => 20]],
            ['OT', 'Overtime', 'earning', 'system', 0, ['sort_order' => 90, 'expense_head_id' => $sys('salaries')]],
            // Ghana (National Pensions Act 2008, Act 766): 18.5% of basic up to the maximum insurable earnings –
            // Tier 1 (SSNIT) 13.5% = employee 5.5% + employer 8%; Tier 2 (occupational trustee) 5% employer.
            ['SSNIT', 'SSNIT (employee 5.5%)', 'deduction', 'percent_basic', 5.5, ['sort_order' => 10, 'cap_base' => self::SSNIT_CAP, 'tax_relief' => true, 'liability_head_id' => $sys('pf_payable')]],
            ['PAYE', 'PAYE income tax', 'deduction', 'tax', 0, ['sort_order' => 40, 'liability_head_id' => $sys('paye_payable')]],
            ['ADV', 'Salary advance recovery', 'deduction', 'system', 0, ['sort_order' => 90, 'liability_head_id' => $sys('staff_advances')]],
            ['SSNIT_ER', 'SSNIT Tier 1 (employer 8%)', 'employer', 'percent_basic', 8, ['sort_order' => 10, 'cap_base' => self::SSNIT_CAP, 'expense_head_id' => $sys('employer_contrib'), 'liability_head_id' => $sys('pf_payable')]],
            ['TIER2_ER', 'Tier 2 pension (employer 5%)', 'employer', 'percent_basic', 5, ['sort_order' => 20, 'cap_base' => self::SSNIT_CAP, 'expense_head_id' => $sys('employer_contrib'), 'liability_head_id' => $sys('esi_payable')]],
        ];
        foreach ($defs as [$code, $name, $kind, $calc, $value, $x]) {
            if (! PayComponent::where('code', $code)->exists()) {
                PayComponent::create(['code' => $code, 'name' => $name, 'kind' => $kind, 'calc' => $calc, 'value' => $value, 'is_default' => true, 'taxable' => true] + $x);
            }
        }
        if (! \App\Models\LeaveType::exists()) {
            foreach ([['CL', 'Casual leave', 12, true, 0], ['SL', 'Sick leave', 12, true, 0], ['EL', 'Earned / annual leave', 18, true, 30], ['LWP', 'Leave without pay', 0, false, 0]] as [$c, $n, $d, $paid, $cf]) {
                \App\Models\LeaveType::create(['code' => $c, 'name' => $n, 'days_per_year' => $d, 'is_paid' => $paid, 'carry_forward_max' => $cf]);
            }
        }
    }

    // ───────────────────────── attendance & tax ─────────────────────────

    public static function attendanceSummary(Employee $e, Carbon $start, Carbon $end): array
    {
        $from = $start->copy()->startOfDay();
        $to = $end->copy()->startOfDay();
        if ($e->joining_date && $e->joining_date->gt($from)) {
            $from = $e->joining_date->copy()->startOfDay();
        }
        if ($e->leaving_date && $e->leaving_date->lt($to)) {
            $to = $e->leaving_date->copy()->startOfDay();
        }
        $out = ['employed' => 0.0, 'present' => 0.0, 'week_off' => 0.0, 'paid_leave' => 0.0, 'lop' => 0.0, 'ot_hours' => 0.0, 'paid' => 0.0];
        if ($from->gt($to)) {
            return $out;
        }
        $att = Attendance::with('leaveRequest.leaveType')->where('employee_id', $e->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])->get()->keyBy(fn ($a) => $a->date->toDateString());
        $unmarkedPresent = setting('payroll_unmarked', 'present') !== 'absent';
        $shift = max(1.0, (float) setting('payroll_shift_hours', 9));
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $out['employed']++;
            $a = $att[$d->toDateString()] ?? null;
            $status = $a?->status ?? ($unmarkedPresent ? 'P' : 'A');
            $leavePaid = $a?->leave_request_id ? (bool) ($a->leaveRequest?->leaveType?->is_paid ?? true) : true; // a manually marked L is paid
            switch ($status) {
                case 'P': $out['present']++;
                    break;
                case 'WO': $out['week_off']++;
                    break;
                case 'L': $leavePaid ? $out['paid_leave']++ : $out['lop']++;
                    break;
                case 'H':
                    $out['present'] += .5;
                    $a?->leave_request_id && $leavePaid ? $out['paid_leave'] += .5 : $out['lop'] += .5;
                    break;
                default: $out['lop']++; // A
            }
            if ($a && $a->in_time && $a->out_time && in_array($status, ['P', 'H'], true)) {
                $in = Carbon::parse($a->in_time);
                $outT = Carbon::parse($a->out_time);
                if ($outT->lte($in)) {
                    $outT->addDay(); // night shift
                }
                $extra = $in->diffInMinutes($outT) / 60 - ($status === 'H' ? $shift / 2 : $shift);
                if ($extra >= .5) {
                    $out['ot_hours'] += floor($extra * 4) / 4; // quarter hours
                }
            }
        }
        $out['paid'] = $out['employed'] - $out['lop'];

        return $out;
    }

    /** Monthly PAYE bands (setting payroll_paye_bands overrides the statutory table). */
    public static function taxSlabs(): array
    {
        $slabs = json_decode((string) setting('payroll_paye_bands', ''), true);

        return is_array($slabs) && $slabs ? $slabs : self::PAYE_BANDS;
    }

    /**
     * GRA PAYE on one month's chargeable income (taxable pay less SSNIT 5.5% and other approved reliefs):
     * graduated monthly bands, no standard deduction / rebate / cess. Rounded to the pesewa.
     */
    public static function monthlyPaye(float $chargeable): float
    {
        $income = max(0, $chargeable);
        $tax = 0.0;
        $lower = 0.0;
        foreach (self::taxSlabs() as [$upto, $rate]) {
            $top = $upto === null ? $income : min($income, (float) $upto);
            if ($top > $lower) {
                $tax += ($top - $lower) * (float) $rate / 100;
            }
            if ($upto === null || $income <= (float) $upto) {
                break;
            }
            $lower = (float) $upto;
        }

        return round($tax, 2);
    }

    /** Annual PAYE for an annual chargeable income (12 × the monthly bands) – used by projections and the annual return. */
    public static function annualTax(float $annualChargeable): float
    {
        return round(self::monthlyPaye($annualChargeable / 12) * 12, 2);
    }

    // ───────────────────────── payslip computation ─────────────────────────

    /** Components that apply to the employee, with the effective value. [[PayComponent, value], …] */
    public static function componentsFor(Employee $e): Collection
    {
        $assigned = $e->payComponents()->get()->keyBy('pay_component_id');

        return PayComponent::where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()
            ->filter(function ($c) use ($assigned) {
                $a = $assigned[$c->id] ?? null;

                return ! $c->isSystem() && ! ($a?->is_excluded) && ($c->is_default || $a);
            })
            ->map(fn ($c) => [$c, ($assigned[$c->id] ?? null)?->value !== null ? (float) $assigned[$c->id]->value : (float) $c->value])->values();
    }

    /**
     * Compute one payslip. $manual = the slip's manual lines (kept on recalculation).
     * Returns ['stats' => attendance summary, 'lines' => [line arrays], 'gross', 'deductions', 'employer', 'net'].
     */
    public static function compute(Employee $e, PayrollRun $run, Collection $manual): array
    {
        $dim = $run->period_start->daysInMonth;
        $s = self::attendanceSummary($e, $run->period_start, $run->period_end);
        $factor = $dim ? $s['paid'] / $dim : 0;
        $basicFull = (float) $e->salary;
        $sys = PayComponent::whereIn('code', PayComponent::SYSTEM)->get()->keyBy('code');
        $salaries = AccountHead::sys('salaries');
        $lines = [];
        $line = fn (string $code, string $name, string $kind, float $amount, array $x = []) => $x + [
            'code' => $code, 'name' => $name, 'kind' => $kind, 'amount' => round($amount, 2), 'taxable' => true, 'is_manual' => false,
            'pay_component_id' => null, 'staff_advance_id' => null, 'expense_head_id' => null, 'liability_head_id' => null, 'sort_order' => 100,
        ];

        // earnings
        $basic = round($basicFull * $factor, 2);
        $b = $sys['BASIC'] ?? null;
        $lines[] = $line('BASIC', $b?->name ?? 'Basic salary', 'earning', $basic, ['pay_component_id' => $b?->id, 'expense_head_id' => $b?->expense_head_id ?? $salaries, 'sort_order' => 0]);
        $comps = self::componentsFor($e);
        foreach ($comps as [$c, $v]) {
            if ($c->kind !== 'earning') {
                continue;
            }
            $amt = match ($c->calc) {
                'fixed' => $v * ($c->prorate ? $factor : 1),
                'percent_basic' => min($basic, $c->cap_base !== null ? (float) $c->cap_base : INF) * $v / 100,
                default => 0,
            };
            if ($amt > 0.004) {
                $lines[] = $line($c->code, $c->name, 'earning', $amt, ['pay_component_id' => $c->id, 'taxable' => $c->taxable, 'expense_head_id' => $c->expense_head_id ?? $salaries, 'sort_order' => $c->sort_order]);
            }
        }
        if ($e->ot_eligible && $s['ot_hours'] > 0 && $basicFull > 0) {
            $rate = $basicFull / $dim / max(1, (float) setting('payroll_shift_hours', 9)) * (float) setting('payroll_ot_multiplier', 1.5);
            $o = $sys['OT'] ?? null;
            $lines[] = $line('OT', ($o?->name ?? 'Overtime').' ('.rtrim(rtrim(number_format($s['ot_hours'], 2), '0'), '.').' h)', 'earning', $s['ot_hours'] * $rate,
                ['pay_component_id' => $o?->id, 'expense_head_id' => $o?->expense_head_id ?? $salaries, 'sort_order' => 90]);
        }
        foreach ($manual->where('kind', 'earning') as $m) {
            $lines[] = self::manualArray($m);
        }
        $gross = round(array_sum(array_map(fn ($l) => $l['amount'], array_filter($lines, fn ($l) => $l['kind'] === 'earning'))), 2);
        $taxable = array_sum(array_map(fn ($l) => $l['amount'], array_filter($lines, fn ($l) => $l['kind'] === 'earning' && $l['taxable'])));

        // deductions & employer contributions (sorted: SSNIT before PAYE, so the relief is known when PAYE is computed)
        $relief = 0.0;
        $comps = $comps->sortBy(fn ($cv) => [$cv[0]->calc === 'tax' ? 1 : 0, $cv[0]->sort_order, $cv[0]->id])->values();
        foreach ($comps as [$c, $v]) {
            if ($c->kind === 'earning') {
                continue;
            }
            $amt = match ($c->calc) {
                'fixed' => $v * ($c->prorate ? $factor : 1),
                'percent_basic' => min($basic, $c->cap_base !== null ? (float) $c->cap_base * $factor : INF) * $v / 100,
                'percent_gross' => ($c->gross_ceiling !== null && $gross > (float) $c->gross_ceiling) ? 0 : $gross * $v / 100,
                'slab' => self::slabAmount($c->slabs ?? [], $gross),
                'tax' => self::monthlyPaye($taxable - $relief),
                default => 0,
            };
            if ($c->calc === 'percent_gross' && $amt > 0) {
                $amt = ceil($amt); // statutory contributions are rounded up to the next unit
            }
            if ($amt > 0.004 && $gross > 0) {
                if ($c->kind === 'deduction' && $c->tax_relief) {
                    $relief += round($amt, 2); // SSNIT employee contribution is deductible before PAYE
                }
                $lines[] = $line($c->code, $c->name, $c->kind, $amt, ['pay_component_id' => $c->id, 'sort_order' => 200 + $c->sort_order,
                    'expense_head_id' => $c->kind === 'employer' ? ($c->expense_head_id ?? AccountHead::sys('employer_contrib')) : null,
                    'liability_head_id' => $c->liability_head_id ?? AccountHead::sys('suspense')]);
            }
        }
        foreach ($manual->where('kind', 'deduction') as $m) {
            $lines[] = self::manualArray($m);
        }
        // salary advances: one instalment per open advance, never more than the net pay left
        $room = $gross - array_sum(array_map(fn ($l) => $l['amount'], array_filter($lines, fn ($l) => $l['kind'] === 'deduction')));
        $a = $sys['ADV'] ?? null;
        foreach (StaffAdvance::where('employee_id', $e->id)->whereNull('cancelled_at')->where('date', '<=', $run->period_end->toDateString())->orderBy('date')->get() as $adv) {
            $amt = min((float) $adv->installment, $adv->outstanding($run->id), max(0, $room));
            if ($amt > 0.004) {
                $lines[] = $line('ADV', ($a?->name ?? 'Salary advance recovery').' '.$adv->advance_no, 'deduction', $amt,
                    ['pay_component_id' => $a?->id, 'staff_advance_id' => $adv->id, 'liability_head_id' => AccountHead::sys('staff_advances'), 'sort_order' => 290]);
                $room -= $amt;
            }
        }
        $ded = round(array_sum(array_map(fn ($l) => $l['amount'], array_filter($lines, fn ($l) => $l['kind'] === 'deduction'))), 2);
        $emp = round(array_sum(array_map(fn ($l) => $l['amount'], array_filter($lines, fn ($l) => $l['kind'] === 'employer'))), 2);

        return ['stats' => $s, 'days' => $dim, 'basic' => $basicFull, 'lines' => $lines, 'gross' => $gross, 'deductions' => $ded, 'employer' => $emp, 'net' => round($gross - $ded, 2)];
    }

    public static function slabAmount(array $slabs, float $gross): float
    {
        foreach ($slabs as [$upto, $amount]) {
            if ($upto === null || $upto === '' || $gross <= (float) $upto) {
                return (float) $amount;
            }
        }

        return 0.0;
    }

    protected static function manualArray(PayslipLine $m): array
    {
        return $m->only(['code', 'name', 'kind', 'taxable', 'pay_component_id', 'staff_advance_id', 'expense_head_id', 'liability_head_id', 'sort_order'])
            + ['amount' => (float) $m->amount, 'is_manual' => true, 'id' => $m->id];
    }

    // ───────────────────────── runs ─────────────────────────

    public static function eligible(PayrollRun $run): Collection
    {
        return Employee::where('salary', '>', 0)
            ->where(fn ($q) => $q->whereNull('joining_date')->whereDate('joining_date', '<=', $run->period_end->toDateString()))
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('is_active', true)->whereNull('leaving_date'))->whereDate('leaving_date', '>=', $run->period_start->toDateString()))
            ->orderBy('emp_code')->get();
    }

    public static function createRun(Carbon $month, ?string $notes = null): PayrollRun
    {
        $start = $month->copy()->startOfMonth();
        if (PayrollRun::where('period_start', $start->toDateString())->exists()) {
            throw new RuntimeException('A payroll run for '.$start->format('F Y').' already exists.');
        }
        if ($start->gt(bdate()->copy()->startOfMonth())) {
            throw new RuntimeException('Payroll cannot be run for a future month.');
        }
        $run = PayrollRun::create(['run_no' => 'PAY-'.$start->format('Y-m'), 'period_start' => $start, 'period_end' => $start->copy()->endOfMonth()->startOfDay(), 'status' => 'draft', 'notes' => $notes]);
        self::generate($run);
        ActivityLogger::log('payroll', 'create', "Payroll run {$run->run_no} generated for {$run->employees} employee(s)", $run);

        return $run;
    }

    /** (Re)calculate every payslip of a draft run; manual lines are kept. */
    public static function generate(PayrollRun $run, bool $stamp = true): PayrollRun
    {
        if ($run->isLocked()) {
            throw new RuntimeException('Only a draft run can be recalculated – reopen it first.');
        }
        DB::transaction(function () use ($run, $stamp) {
            $emps = self::eligible($run)->keyBy('id');
            Payslip::where('payroll_run_id', $run->id)->whereNotIn('employee_id', $emps->keys())->get()->each->delete();
            foreach ($emps as $e) {
                $slip = Payslip::firstOrNew(['payroll_run_id' => $run->id, 'employee_id' => $e->id]);
                self::fill($slip, $e, $run);
            }
            self::totals($run);
            $stamp && $run->update(['generated_by' => auth()->id(), 'generated_at' => now()]);
        });

        return $run->fresh();
    }

    public static function recalcSlip(Payslip $slip): Payslip
    {
        $run = $slip->run;
        if ($run->isLocked()) {
            throw new RuntimeException('The run is '.$run->status.' – reopen it to change payslips.');
        }
        DB::transaction(function () use ($slip, $run) {
            self::fill($slip, $slip->employee, $run);
            self::totals($run);
        });

        return $slip->fresh('lines');
    }

    protected static function fill(Payslip $slip, Employee $e, PayrollRun $run): void
    {
        $manual = $slip->exists ? $slip->lines()->where('is_manual', true)->get() : collect();
        $r = self::compute($e, $run, $manual);
        $s = $r['stats'];
        $slip->fill([
            'days_in_period' => $r['days'], 'employed_days' => $s['employed'], 'present_days' => $s['present'] + $s['week_off'], 'paid_leave_days' => $s['paid_leave'],
            'lop_days' => $s['lop'], 'paid_days' => $s['paid'], 'ot_hours' => $s['ot_hours'], 'basic' => $r['basic'], 'gross' => $r['gross'],
            'deductions' => $r['deductions'], 'net' => $r['net'], 'employer_cost' => $r['employer'],
            'payment_mode_id' => $e->payment_mode_id ?: self::defaultModeId(), 'bank_account' => $e->bank_account,
        ])->save();
        $slip->lines()->where('is_manual', false)->delete();
        foreach ($r['lines'] as $l) {
            if (empty($l['is_manual'])) {
                unset($l['id']);
                $slip->lines()->create($l);
            }
        }
    }

    protected static function totals(PayrollRun $run): void
    {
        $t = Payslip::where('payroll_run_id', $run->id)->selectRaw('COUNT(*) n, SUM(gross) g, SUM(deductions) d, SUM(net) nt, SUM(employer_cost) e')->first();
        $run->update(['employees' => (int) $t->n, 'gross' => $t->g ?? 0, 'deductions' => $t->d ?? 0, 'net' => $t->nt ?? 0, 'employer_cost' => $t->e ?? 0]);
    }

    public static function defaultModeId(): ?int
    {
        return (int) setting('payroll_default_mode', 0) ?: PaymentMode::where('type', 'bank')->value('id') ?: PaymentMode::where('type', 'cash')->value('id');
    }

    public static function addAdjustment(Payslip $slip, array $d): PayslipLine
    {
        if ($slip->run->isLocked()) {
            throw new RuntimeException('The run is '.$slip->run->status.' – reopen it to change payslips.');
        }
        $line = $slip->lines()->create([
            'code' => $d['kind'] === 'earning' ? 'ADJ+' : 'ADJ-', 'name' => $d['name'], 'kind' => $d['kind'], 'amount' => round((float) $d['amount'], 2),
            'taxable' => $d['kind'] === 'earning' && ! empty($d['taxable']), 'is_manual' => true, 'sort_order' => $d['kind'] === 'earning' ? 95 : 295,
            'expense_head_id' => $d['kind'] === 'earning' ? ($d['account_head_id'] ?? null ?: AccountHead::sys('salaries')) : null,
            'liability_head_id' => $d['kind'] === 'deduction' ? ($d['account_head_id'] ?? null ?: AccountHead::sys('salaries')) : null, // e.g. a fine/recovery reduces salary cost
        ]);
        self::recalcSlip($slip->fresh());
        ActivityLogger::log('payroll', 'adjust', "Payslip adjustment '{$line->name}' ".money($line->amount)." for {$slip->employee->name} ({$slip->run->run_no})", $slip);

        return $line;
    }

    public static function removeAdjustment(PayslipLine $line): void
    {
        $slip = $line->payslip;
        if (! $line->is_manual || $slip->run->isLocked()) {
            throw new RuntimeException('Only manual lines of a draft run can be removed.');
        }
        if ($line->code === 'SVC') { // v4.9
            throw new RuntimeException('This is a service charge share – cancel the service charge distribution instead.');
        }
        $line->delete();
        self::recalcSlip($slip->fresh());
    }

    public static function approve(PayrollRun $run): PayrollRun
    {
        if ($run->status !== 'draft') {
            throw new RuntimeException('Only a draft run can be approved.');
        }
        if (setting('payroll_segregation', '1') === '1' && $run->generated_by && (int) $run->generated_by === (int) auth()->id()) {
            throw new RuntimeException('Segregation of duties: the run must be approved by someone other than the person who generated it.');
        }
        self::generate($run, false); // latest attendance, leave and advances (the preparer stays on record)
        $run->refresh();
        if (! $run->employees) {
            throw new RuntimeException('The run has no payslips.');
        }
        if ($neg = Payslip::with('employee')->where('payroll_run_id', $run->id)->where('net', '<', 0)->first()) {
            throw new RuntimeException("Net pay for {$neg->employee->name} is negative – reduce the deductions first.");
        }
        $run->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
        self::postNow('payroll', $run, $run->period_end);
        ActivityLogger::log('payroll', 'approve', "Approved payroll {$run->run_no}: net ".money($run->net), $run);

        return $run;
    }

    public static function reopen(PayrollRun $run, string $reason): PayrollRun
    {
        if ($run->status !== 'approved') {
            throw new RuntimeException($run->status === 'paid' ? 'A paid run cannot be reopened.' : 'The run is already a draft.');
        }
        StatutoryService::assertRunReopenable($run); // v4.5
        $run->update(['status' => 'draft', 'approved_by' => null, 'approved_at' => null]);
        self::postNow('payroll', $run, $run->period_end); // reverses the accrual when that day is already closed
        ActivityLogger::log('payroll', 'reopen', "Reopened payroll {$run->run_no}: {$reason}", $run);

        return $run;
    }

    public static function pay(PayrollRun $run, string $date): PayrollRun
    {
        if ($run->status !== 'approved') {
            throw new RuntimeException('Only an approved run can be paid.');
        }
        if ($date < $run->period_end->toDateString() || $date > bdate()->toDateString()) {
            throw new RuntimeException('The payment date must be between the period end ('.dmy($run->period_end).') and today.');
        }
        $run->update(['status' => 'paid', 'paid_date' => $date, 'paid_by' => auth()->id()]);
        self::postNow('payroll_pay', $run, Carbon::parse($date));
        ActivityLogger::log('payroll', 'pay', "Paid payroll {$run->run_no}: ".money($run->net)." on {$date}", $run);

        return $run;
    }

    public static function deleteRun(PayrollRun $run): void
    {
        if ($run->isLocked()) {
            throw new RuntimeException('Only a draft run can be deleted.');
        }
        ActivityLogger::log('payroll', 'delete', "Deleted draft payroll {$run->run_no}", $run);
        $run->delete();
    }

    /** The approved / paid run covering a date (attendance and leave are locked there). */
    public static function lockedRunFor(string $date): ?PayrollRun
    {
        return PayrollRun::whereIn('status', ['approved', 'paid'])->where('period_start', '<=', $date)->where('period_end', '>=', $date)->first();
    }

    public static function assertOpen(string $from, ?string $to = null): void
    {
        $to ??= $from;
        $run = PayrollRun::whereIn('status', ['approved', 'paid'])->where('period_start', '<=', $to)->where('period_end', '>=', $from)->first();
        if ($run) {
            throw new RuntimeException("Payroll for {$run->label()} is {$run->status} – attendance and leave in that month are locked".($run->status === 'approved' ? ' (reopen the run to change them).' : '.'));
        }
    }

    // ───────────────────────── advances ─────────────────────────

    public static function giveAdvance(array $d): StaffAdvance
    {
        $adv = StaffAdvance::create($d + ['advance_no' => Numbering::next('staff_advance'), 'created_by' => auth()->id()]);
        self::postNow('advance', $adv, $adv->date);
        ActivityLogger::log('payroll', 'advance', "Salary advance {$adv->advance_no} ".money($adv->amount)." to {$adv->employee->name}", $adv);

        return $adv;
    }

    public static function cancelAdvance(StaffAdvance $adv): void
    {
        if ($adv->cancelled_at) {
            throw new RuntimeException('Already cancelled.');
        }
        if (PayslipLine::where('staff_advance_id', $adv->id)->exists()) {
            throw new RuntimeException('Instalments are already on a payslip – the advance cannot be cancelled.');
        }
        $adv->update(['cancelled_at' => now()]);
        self::postNow('advance', $adv, $adv->date);
        ActivityLogger::log('payroll', 'advance_cancel', "Cancelled salary advance {$adv->advance_no}", $adv);
    }

    // ───────────────────────── ledger ─────────────────────────

    /** Post / reverse straight away when the document date is in a closed day; otherwise the night-audit sync picks it up. */
    public static function postNow(string $type, Model $doc, Carbon $date): void
    {
        $cutoff = setting('gl_cutoff');
        if (! $cutoff || $date->toDateString() > $cutoff) {
            return;
        }
        try {
            ChartOfAccounts::ensureLight();
            LedgerService::reconcile($type, (string) $doc->id, LedgerService::build($type, (string) $doc->id), $cutoff);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Sub-ledger balances for the GL control reconciliation. */
    public static function payableBalance(string $asOf): float
    {
        $accrued = PayrollRun::whereIn('status', ['approved', 'paid'])->where('period_end', '<=', $asOf)->sum('net');
        $paid = PayrollRun::where('status', 'paid')->where('paid_date', '<=', $asOf)->sum('net');

        return round((float) $accrued - (float) $paid, 2);
    }

    public static function advancesBalance(string $asOf): float
    {
        $given = StaffAdvance::whereNull('cancelled_at')->where('date', '<=', $asOf)->sum('amount');
        $recovered = PayslipLine::whereNotNull('staff_advance_id')
            ->whereHas('payslip.run', fn ($q) => $q->whereIn('status', ['approved', 'paid'])->where('period_end', '<=', $asOf))->sum('amount');

        return round((float) $given - (float) $recovered, 2);
    }
}
