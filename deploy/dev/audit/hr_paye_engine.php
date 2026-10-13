<?php

/**
 * HR & Workforce audit — the Ghana PAYE engine after the Act 1178 fix.
 *
 *   TINKER='require "/app/deploy/dev/audit/hr_paye_engine.php";' deploy/dev/bin/php -f deploy/dev/tinker.php
 */

use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\PayComponent;
use App\Models\PayrollRun;
use App\Services\HolidayService;
use App\Services\PayrollService as PS;
use App\Support\Tenant;
use Illuminate\Support\Carbon;

echo "== effective-dated band schedules ==\n";
foreach (['2024-06-01', '2026-08-31', '2026-09-01', '2026-10-13'] as $d) {
    $s = PS::scheduleFor($d);
    echo str_pad($d, 12).' '.$s['from'].'  '.$s['name'].'  '.json_encode($s['bands'])."\n";
}
echo 'PAYE_BANDS (in force today): '.json_encode(PS::PAYE_BANDS)."\n";

echo "\n== the same chargeable income in August and in October 2026 ==\n";
foreach ([588, 700, 1000, 2928, 4501.86, 7417.5, 20000, 60000] as $c) {
    printf("chargeable %10s  Aug bands %10s  Oct bands %10s  delta %8s\n", $c,
        PS::monthlyPaye((float) $c, '2026-08-01'), PS::monthlyPaye((float) $c, '2026-10-01'),
        round(PS::monthlyPaye((float) $c, '2026-10-01') - PS::monthlyPaye((float) $c, '2026-08-01'), 2));
}

echo "\n== the four GRA treatments ==\n";
$e = Employee::where('employment_type', 'permanent')->orderBy('id')->first();
$period = Carbon::parse('2026-10-01');
$show = function (string $label, Employee $emp, float $taxable, float $ssnit, float $ot, float $basic) use ($period) {
    $t = PS::incomeTax($emp, $period, $taxable, $ssnit, $ot, $basic);
    printf("%-34s taxable=%9s ssnit=%7s ot=%8s basic=%9s → PAYE=%9s OTtax=%7s chargeable=%9s  %s\n",
        $label, $taxable, $ssnit, $ot, $basic, $t['paye'], $t['ot_tax'], $t['chargeable'], $t['basis']);
};
$show('resident, no overtime', $e, 4500, 247.5, 0, 3800);
$show('resident, overtime, above cap', $e, 4786.02, 247.5, 286.02, 3800);
$junior = (clone $e)->forceFill(['salary' => 1200, 'employment_type' => 'permanent']);
$show('junior (1,200 basic) OT 300', $junior, 1500, 66, 300, 1200);
$show('junior (1,200 basic) OT 900', $junior, 2100, 66, 900, 1200);
$casual = (clone $e)->forceFill(['employment_type' => 'casual']);
$show('casual worker, gross 5,200', $casual, 5200, 0, 0, 5200);
$nonres = (clone $e)->forceFill(['tax_resident' => false]);
$show('non-resident, 12,000', $nonres, 12000, 660, 0, 10000);
$relieved = (clone $e)->forceFill(['tax_relief' => 208.33]);
$show('resident + personal reliefs', $relieved, 4500, 247.5, 0, 3800);

echo "\n== statutory components & leave types (after ensureDefaults) ==\n";
foreach ([1, 2] as $hid) {
    Tenant::set(App\Models\Hotel::find($hid));
    PS::ensureDefaults();
    echo 'hotel '.$hid.': '.PayComponent::whereIn('code', ['OTH', 'OT_TAX'])->pluck('code')->implode(', ')." \n";
    echo '   leave types: '.LeaveType::orderBy('id')->get()->map(fn ($t) => $t->code.'='.$t->days_per_year.($t->is_paid ? 'p' : 'u'))->implode(' ')."\n";
}
Tenant::set(App\Models\Hotel::find(1));

echo "\n== public holidays ==\n";
foreach ([2026, 2027] as $y) {
    $d = HolidayService::dates($y);
    echo $y.': '.count($d)." paid public holidays – ".implode(', ', array_map(fn ($n, $k) => substr($k, 5).' '.$n, $d, array_keys($d)))."\n";
    echo '   commemorative (working days): '.HolidayService::list($y)->where('commemorative', true)->map(fn ($r) => $r['date']->format('d M').' '.$r['name'])->implode(', ')."\n";
}
echo 'today ('.bdate()->toDateString().') is a holiday: '.(HolidayService::isHoliday(bdate()) ? 'yes' : 'no')."\n";
echo 'upcoming 60 days: '.HolidayService::upcoming(60)->map(fn ($h) => $h['date']->format('d M').' '.$h['name'].' (in '.$h['days'].' d)')->implode('; ')."\n";
$run = PayrollRun::where('run_no', 'PAY-2026-10')->first();
echo 'October payroll note: '.HolidayService::payrollNote($run->period_start, $run->period_end)['text']."\n";
