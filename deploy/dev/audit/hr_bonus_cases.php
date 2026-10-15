<?php

/**
 * v7.45 — GRA taxation of bonuses / ex-gratia payments: the tax engine, case by case.
 *
 * Every expectation below is hand-computed from the rule GRA publishes (Act 896 s. 114 and the practice note on
 * ex-gratia payments), so the probe is a check of the code against the law rather than against itself:
 *
 *   resident      a final 5% on the part of the bonus up to 15% of the year's basic salary; the excess over that cap is
 *                 added to the month's chargeable income and taxed on the bands.
 *   non-resident  a flat 20% on the whole bonus (the rest of a non-resident's employment income stays at 25%).
 *   casual        the bonus is part of the gross pay that carries the final 5% — no separate bonus tax.
 *   junior OT     GRA tests the concession on the year's qualifying employment income, the bonus included.
 *
 * Run:  TINKER='require "/app/deploy/dev/audit/hr_bonus_cases.php";' deploy/dev/bin/php -f deploy/dev/tinker.php
 */

use App\Models\Employee;
use App\Models\Setting;
use App\Services\PayrollService;
use Illuminate\Support\Carbon;

$period = Carbon::parse('2026-09-01');       // Act 1178 bands, in force 1 Sep 2026
$pass = 0;
$fail = 0;

/** An unsaved employee is enough: incomeTax() only reads residence, employment type and personal reliefs. */
$who = fn (array $x = []) => new Employee(array_merge(['tax_resident' => true, 'employment_type' => 'permanent', 'tax_relief' => 0], $x));

function check(string $label, array $got, array $want): void
{
    global $pass, $fail;
    $bad = [];
    foreach ($want as $k => $v) {
        $g = is_string($v) ? (string) ($got[$k] ?? '') : round((float) ($got[$k] ?? NAN), 2);
        if (is_string($v) ? ! str_contains($g, $v) : abs($g - $v) > 0.005) {
            $bad[] = "$k: got ".(is_string($v) ? "'$g'" : number_format($g, 2))." want ".(is_string($v) ? "'…$v…'" : number_format($v, 2));
        }
    }
    if ($bad) {
        $fail++;
        echo "  FAIL  $label\n        ".implode("\n        ", $bad)."\n";
    } else {
        $pass++;
        echo "  ok    $label\n";
    }
}

echo "== A resident: basic 1,000 a month ⇒ annual basic 12,000 ⇒ the 15% cap is 1,800 ==\n";
$r = PayrollService::incomeTax($who(), $period, 1000.0, 55.0, 0.0, 1000.0, 0.0);
check('no bonus at all (the pre-v7.45 answer must not move: 945 on the bands = 44.98)', $r, ['paye' => 44.98, 'ot_tax' => 0.0, 'chargeable' => 945.0, 'bonus_tax' => 0.0, 'bonus_final' => 0.0, 'bonus_cap' => 1800.0]);

$r = PayrollService::incomeTax($who(), $period, 1000.0, 55.0, 0.0, 1000.0, 1500.0);
check('bonus 1,500 – inside the cap: a final 5% and the bands untouched', $r,
    ['bonus_final' => 1500.0, 'bonus_excess' => 0.0, 'bonus_tax' => 75.0, 'chargeable' => 945.0, 'basis' => 'final 5%']);

$r = PayrollService::incomeTax($who(), $period, 1000.0, 55.0, 0.0, 1000.0, 3000.0);
check('bonus 3,000 – 1,800 at 5% and the 1,200 over the cap on the bands', $r,
    ['bonus_final' => 1800.0, 'bonus_excess' => 1200.0, 'bonus_tax' => 90.0, 'chargeable' => 2145.0, 'basis' => 'over the 15%']);

$r0 = PayrollService::incomeTax($who(), $period, 2145.0 + 55.0, 55.0, 0.0, 1000.0, 0.0);
check('… and that chargeable income really is taxed on the bands (same PAYE as an equal salary)', $r, ['paye' => $r0['paye']]);

echo "\n== A big bonus: basic 12,000 a month ⇒ annual 144,000 ⇒ cap 21,600 ==\n";
$r = PayrollService::incomeTax($who(), $period, 15000.0, 660.0, 0.0, 12000.0, 25000.0);
check('bonus 25,000 – 21,600 at 5% = 1,080 and 3,400 into the bands', $r,
    ['bonus_cap' => 21600.0, 'bonus_final' => 21600.0, 'bonus_excess' => 3400.0, 'bonus_tax' => 1080.0, 'chargeable' => 17740.0]);

echo "\n== A non-resident: the whole bonus at a flat 20%, the rest of the pay at 25% ==\n";
$r = PayrollService::incomeTax($who(['tax_resident' => false]), $period, 5000.0, 0.0, 0.0, 5000.0, 5000.0);
check('bonus 5,000 – 1,000 withheld, chargeable income untouched', $r,
    ['paye' => 1250.0, 'bonus_tax' => 1000.0, 'bonus_final' => 5000.0, 'bonus_excess' => 0.0, 'chargeable' => 5000.0, 'basis' => '20% final (non-resident)']);
$r = PayrollService::incomeTax($who(['tax_resident' => false]), $period, 3748.31, 0.0, 0.0, 3000.0, 0.0);
check('no bonus – still the v7.43 figure for EMP004 (25% of 3,748.31 = 937.08)', $r, ['paye' => 937.08, 'bonus_tax' => 0.0]);

echo "\n== A casual worker (Act 651 s. 78): the bonus is inside the 5% final tax on gross ==\n";
$r = PayrollService::incomeTax($who(['employment_type' => 'casual']), $period, 4000.0, 0.0, 0.0, 4000.0, 1000.0);
check('gross 4,000 + bonus 1,000 ⇒ 5% of 5,000 = 250, no separate bonus line', $r,
    ['paye' => 250.0, 'bonus_tax' => 0.0, 'chargeable' => 5000.0, 'basis' => 'inside the 5% final']);
$r = PayrollService::incomeTax($who(['employment_type' => 'casual']), $period, 4432.0, 0.0, 0.0, 4432.0, 0.0);
check('no bonus – still the v7.43 figure for EMP012 (5% of 4,432 = 221.60)', $r, ['paye' => 221.60, 'bonus_tax' => 0.0]);

echo "\n== A junior employee: the bonus counts towards the qualifying-income test, and is taxed apart ==\n";
$r = PayrollService::incomeTax($who(), $period, 1200.0, 0.0, 200.0, 1000.0, 500.0);
check('qualifying 12,700 ≤ 18,000 ⇒ OT at 5% = 10.00, bonus 500 at 5% = 25.00', $r,
    ['junior' => true, 'ot_tax' => 10.0, 'bonus_tax' => 25.0, 'chargeable' => 1000.0]);
$r = PayrollService::incomeTax($who(), $period, 1200.0, 0.0, 200.0, 1000.0, 8000.0);
check('a bonus of 8,000 pushes qualifying income to 20,200 ⇒ the concession is lost, bands on everything', $r,
    ['junior' => false, 'ot_tax' => 0.0, 'bonus_final' => 1800.0, 'bonus_excess' => 6200.0, 'bonus_tax' => 90.0, 'chargeable' => 7400.0]);

echo "\n== Personal reliefs still come off before the bands, bonus excess included ==\n";
$r = PayrollService::incomeTax($who(['tax_relief' => 200.0]), $period, 1000.0, 55.0, 0.0, 1000.0, 3000.0);
check('chargeable = 1,000 + 1,200 excess − 55 SSNIT − 200 relief = 1,945', $r, ['chargeable' => 1945.0, 'bonus_tax' => 90.0]);

echo "\n== The GRA figures are settings, not hard-coded: move them and the engine follows ==\n";
$before = ['payroll_bonus_cap_pct' => Setting::where('key', 'payroll_bonus_cap_pct')->value('value'),
    'payroll_bonus_rate' => Setting::where('key', 'payroll_bonus_rate')->value('value'),
    'payroll_bonus_nonresident_rate' => Setting::where('key', 'payroll_bonus_nonresident_rate')->value('value')];
Setting::updateOrCreate(['key' => 'payroll_bonus_cap_pct'], ['value' => '10']);
Setting::updateOrCreate(['key' => 'payroll_bonus_rate'], ['value' => '7.5']);
Setting::updateOrCreate(['key' => 'payroll_bonus_nonresident_rate'], ['value' => '15']);
Setting::$version++;
$r = PayrollService::incomeTax($who(), $period, 1000.0, 55.0, 0.0, 1000.0, 3000.0);
check('cap 10% of 12,000 = 1,200 at 7.5% = 90.00, excess 1,800 on the bands', $r,
    ['bonus_cap' => 1200.0, 'bonus_final' => 1200.0, 'bonus_excess' => 1800.0, 'bonus_tax' => 90.0, 'chargeable' => 2745.0]);
$r = PayrollService::incomeTax($who(['tax_resident' => false]), $period, 5000.0, 0.0, 0.0, 5000.0, 5000.0);
check('a non-resident follows the overridden 15%', $r, ['bonus_tax' => 750.0]);
foreach ($before as $k => $v) {
    $v === null ? Setting::where('key', $k)->delete() : Setting::where('key', $k)->update(['value' => $v]);
}
Setting::$version++;
$r = PayrollService::incomeTax($who(), $period, 1000.0, 55.0, 0.0, 1000.0, 3000.0);
check('back to the statutory figures', $r, ['bonus_cap' => 1800.0, 'bonus_tax' => 90.0]);

echo "\n  ".($fail === 0 ? "ALL $pass CASES PASS" : "$pass passed, $fail FAILED")."\n";
