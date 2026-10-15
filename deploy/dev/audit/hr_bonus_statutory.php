<?php

/**
 * v7.45 — the GRA return must carry a bonus and its tax.
 *
 * StatutoryService::build() only builds employee rows for a locked (approved/paid) run, and the demo run that carries a
 * bonus is a draft — nobody may approve a run they prepared, so the probe locks it inside a transaction, reads the
 * return, and rolls back. Nothing is left behind: the run is still a draft when this finishes.
 *
 * Run:  TINKER='require "/app/deploy/dev/audit/hr_bonus_statutory.php";' deploy/dev/bin/php -f deploy/dev/tinker.php
 */

use App\Models\PayrollRun;
use App\Services\StatutoryService;
use Illuminate\Support\Facades\DB;

$run = PayrollRun::where('run_no', env('RUN', 'PAY-2026-10'))->firstOrFail();
$period = $run->period_start->format('Y-m');
printf("== %s (%s, %s) — locking it inside a transaction so the return builds rows ==%s", $run->run_no, $period, $run->status, PHP_EOL);

DB::beginTransaction();
try {
    $run->status = 'approved';
    $run->save();

    $r = StatutoryService::build('paye', $period);
    printf("  employees on the return: %d · PAYE %s · overtime tax %s · bonus %s · bonus tax %s · total %s%s",
        $r['employees'], number_format($r['rows']->sum('total'), 2), number_format($r['ot_tax'], 2),
        number_format($r['bonus'], 2), number_format($r['bonus_tax'], 2), number_format($r['total'], 2), PHP_EOL);

    foreach ($r['rows'] as $x) {
        if (($x['bonus'] ?? 0) > 0 || ($x['bonus_tax'] ?? 0) > 0) {
            printf("  %s %s — basic %s allowances %s overtime %s bonus %s | chargeable %s OT tax %s bonus tax %s PAYE %s%s",
                $x['emp_code'], $x['name'], number_format($x['basic'], 2), number_format($x['allowances'], 2),
                number_format($x['overtime'], 2), number_format($x['bonus'], 2), number_format($x['chargeable'], 2),
                number_format($x['ot_tax'], 2), number_format($x['bonus_tax'], 2), number_format($x['total'], 2), PHP_EOL);
            printf("    rule printed on the return: %s%s", $x['basis'], PHP_EOL);
            printf("    gross emolument %s = basic + allowances + overtime + bonus (%s)%s", number_format($x['gross'], 2),
                number_format($x['basic'] + $x['allowances'] + $x['overtime'] + $x['bonus'], 2), PHP_EOL);
        }
    }

    [$name, $head, $rows] = StatutoryService::csv('paye', $period);
    printf("  CSV %s — %d columns%s", $name, count($head), PHP_EOL);
    printf("    %s%s", implode(' | ', $head), PHP_EOL);
    foreach ($rows as $row) {
        if ((float) $row[7] > 0) {
            printf("    %s%s", implode(' | ', array_map(fn ($v) => is_float($v) ? number_format($v, 2) : (string) $v, $row)), PHP_EOL);
        }
    }
    printf("  the liability the return must clear: payroll credited %s to PAYE payable%s",
        $r['gl'] === null ? 'nothing (not posted yet)' : number_format($r['gl'], 2), PHP_EOL);
} finally {
    DB::rollBack();
}

$fresh = $run->fresh();
printf("%s== after the rollback: %s is %s again, payslips %d ==%s", PHP_EOL, $fresh->run_no, $fresh->status, $fresh->payslips()->count(), PHP_EOL);
printf("  payslips.paye_basis column: %s%s", collect(DB::select('SELECT type FROM pragma_table_info("payslips") WHERE name = "paye_basis"'))->first()->type ?? '?', PHP_EOL);
printf("  longest basis stored: %d characters%s", (int) DB::table('payslips')->whereNotNull('paye_basis')->max(DB::raw('LENGTH(paye_basis)')), PHP_EOL);
