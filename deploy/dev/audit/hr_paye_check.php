<?php

/**
 * HR & Workforce audit — Ghana PAYE evidence.
 *
 * Replays every payslip of a payroll run and shows the tax the system computed
 * beside the tax the bands **in force for that month** require – GRA's schedule
 * from 1 Jan 2024 up to August 2026, and Act 1178 from 1 September 2026. A month
 * must be taxed on the schedule that was in force in it, so a zero delta on every
 * line of every run is what "correct" looks like, including the historical ones.
 *
 * Personal reliefs on the employee record are taken off chargeable income here too.
 *
 *   TINKER='require "/app/deploy/dev/audit/hr_paye_check.php";' deploy/dev/bin/php -f deploy/dev/tinker.php
 */

use App\Models\Employee;
use App\Models\PayComponent;
use App\Models\PayrollRun;
use App\Models\Payslip;

$taxFor = function (array $bands) {
    return function (float $inc) use ($bands) {
    $t = 0.0;
    $lo = 0.0;
    foreach ($bands as [$u, $r]) {
        $top = $u === null ? $inc : min($inc, (float) $u);
        if ($top > $lo) {
            $t += ($top - $lo) * $r / 100;
        }
        if ($u === null || $inc <= (float) $u) {
            break;
        }
        $lo = (float) $u;
    }

    return round($t, 2);
    };
};

foreach (PayrollRun::orderBy('period_start')->get() as $run) {
    $sched = \App\Services\PayrollService::scheduleFor($run->period_start);
    $tax = $taxFor($sched['bands']);
    $tot = $totNew = $otTotal = 0.0;
    $n = 0;
    echo "\n── {$run->run_no} · {$run->period_start->format('F Y')} · {$run->status} ──\n";
    $slips = Payslip::with('employee', 'lines')->where('payroll_run_id', $run->id)->get()->sortBy(fn ($s) => $s->employee?->name);
    foreach ($slips as $s) {
        $basic = round((float) $s->lines->where('code', 'BASIC')->sum('amount'), 2);
        $ot = round((float) $s->lines->where('code', 'OT')->sum('amount'), 2);
        $taxable = round((float) $s->lines->where('kind', 'earning')->where('taxable', true)->sum('amount'), 2);
        $relief = round((float) $s->lines->where('code', 'SSNIT')->sum('amount'), 2)
            + \App\Services\PayrollService::personalRelief($s->employee);
        $chg = round($taxable - $relief, 2);
        $old = round((float) $s->lines->where('code', 'PAYE')->sum('amount'), 2);
        $nw = $tax($chg);
        $tot += $old;
        $totNew += $nw;
        $otTotal += $ot;
        $n++;
        printf("%-24s %-8s basic=%9s ot=%7s chargeable=%9s PAYE(now)=%9s PAYE(%s)=%9s delta=%8s %s\n",
            $s->employee?->name, $s->employee?->emp_code, $basic, $ot, $chg, $old, substr($sched['name'], 0, 9), $nw,
            round($nw - $old, 2), $s->employee?->employment_type);
    }
    printf("   TOTALS  slips=%d  PAYE as computed=%s  PAYE under %s=%s  delta=%s  overtime paid=%s%s\n",
        $n, round($tot, 2), $sched['name'], round($totNew, 2), round($totNew - $tot, 2), round($otTotal, 2),
        abs(round($totNew - $tot, 2)) < 0.005 ? '  ✓ matches the bands in force' : '  ✗ DOES NOT MATCH');
}

echo "\nemployment types: ".Employee::selectRaw('employment_type, count(*) c')->groupBy('employment_type')->pluck('c', 'employment_type')->toJson()."\n";
echo 'pay components: '.PayComponent::orderBy('kind')->orderBy('sort_order')->get()
    ->map(fn ($c) => $c->code.'['.$c->kind.'/'.$c->calc.'='.$c->value.($c->taxable ? '/taxable' : '/tax-free').($c->is_active ? '' : '/off').']')->implode(' ')."\n";
echo 'payroll settings: '.json_encode([
    'payroll_shift_hours' => setting('payroll_shift_hours', '9'),
    'payroll_ot_multiplier' => setting('payroll_ot_multiplier', '1.5'),
    'payroll_holiday_multiplier' => setting('payroll_holiday_multiplier', '2'),
    'payroll_ot_junior_cap' => setting('payroll_ot_junior_cap', '18000'),
    'payroll_ot_junior_ratio' => setting('payroll_ot_junior_ratio', '0.5'),
    'payroll_ot_junior_rate' => setting('payroll_ot_junior_rate', '5'),
    'payroll_ot_junior_excess_rate' => setting('payroll_ot_junior_excess_rate', '10'),
    'payroll_payday' => setting('payroll_payday', '28'),
    'payroll_notify' => setting('payroll_notify', '0'),
    'leave_count_all_days' => setting('leave_count_all_days', '0'),
    'hr_holiday_notify_days' => setting('hr_holiday_notify_days', '7'),
    'payroll_min_wage_daily' => setting('payroll_min_wage_daily', '21.77'),
    'payroll_min_wage_days' => setting('payroll_min_wage_days', '27'),
    'payroll_paye_bands' => setting('payroll_paye_bands', ''),
    'stat_insurable_cap' => setting('stat_insurable_cap', '69000'),
])."\n";
echo 'bands in code: '.json_encode(App\Services\PayrollService::PAYE_BANDS)."\n";
