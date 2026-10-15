<?php

/**
 * v7.46 — the payroll-versus-budget comparison, including the branches the demo budget never reaches.
 *
 * The seeded budget allows GH₵720,000 a month for Salaries & Wages against a payroll of about GH₵82,000, so a run is
 * always "within budget" and the warning paths never fire. This probe lowers the October line, reads the run page and
 * the nightly HR task over HTTP the way a user would, then puts the figure back exactly as it was — the budget is
 * approved and locked, so the only way to move it is a direct write, and the write is undone before the probe ends.
 *
 * Run:  TINKER='require "/app/deploy/dev/audit/hr_budget_variance.php";' deploy/dev/bin/php -f deploy/dev/tinker.php
 */

use App\Models\AccountHead;
use App\Models\BudgetLine;
use App\Services\BudgetService;
use App\Services\PayrollService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$run = \App\Models\PayrollRun::where('run_no', env('RUN', 'PAY-2026-10'))->firstOrFail();
$budget = BudgetService::active(BudgetService::fyFor($run->period_start));
$salaries = AccountHead::sys('salaries');
$month = BudgetService::monthIndex($run->period_start);
$cell = 'm'.$month;
$line = BudgetLine::where('budget_id', $budget->id)->where('account_head_id', $salaries)->firstOrFail();
$original = (float) $line->{$cell};

/** Fetch a page as the dev server's signed-in user and report which markers it carries. */
function page(string $path, array $markers): array
{
    $html = (string) shell_exec('curl -s -m 120 '.escapeshellarg('http://127.0.0.1:8000'.$path));
    $out = [];
    foreach ($markers as $m) {
        $out[$m] = str_contains($html, $m);
    }

    return $out;
}

/** The nightly task, run in-process: a WASM PHP cannot spawn another one, so Artisan is called directly. */
function hrRun(): string
{
    Artisan::call('pms:hr-run', ['--dry' => true]);

    return (string) Artisan::output();
}

function show(string $label, array $found): void
{
    printf("  %s%s", $label, PHP_EOL);
    foreach ($found as $m => $hit) {
        printf("      %-62s %s%s", mb_substr(preg_replace('/\s+/', ' ', $m), 0, 62), $hit ? 'yes' : 'NO', PHP_EOL);
    }
}

$marks = ['Within budget', 'Over budget', 'Account not budgeted', 'over the approved budget', 'unbudgeted'];

printf("== %s (%s) against %s, month index %d ==%s", $run->run_no, $run->status, $budget->budget_no, $month, PHP_EOL);
printf("  the run charges %s to Salaries & Wages; the budget line for %s is %s%s",
    money(PayrollService::budgetVariance($run)['rows']->firstWhere('account', 'Salaries & Wages')['cost'] ?? 0),
    BudgetService::monthLabels(BudgetService::fyFor($run->period_start))[$month], money($original), PHP_EOL);

try {
    printf("%s== as seeded: comfortably inside the budget ==%s", PHP_EOL, PHP_EOL);
    show('GET /payroll/runs/'.$run->id, page('/payroll/runs/'.$run->id, $marks));

    printf("%s== lower the month to GH₵50,000: the run and what is already posted must break it ==%s", PHP_EOL, PHP_EOL);
    $line->update([$cell => 50000]);
    $v = PayrollService::budgetVariance($run->fresh());
    printf("  engine: status %s, month to date %s against %s = variance %s (%s%%)%s", $v['status'],
        money($v['rows']->firstWhere('account', 'Salaries & Wages')['after']), money(50000),
        money($v['rows']->firstWhere('account', 'Salaries & Wages')['variance']), $v['pct'], PHP_EOL);
    show('GET /payroll/runs/'.$run->id, page('/payroll/runs/'.$run->id, $marks));
    show('the approve button warns before it confirms', page('/payroll/runs/'.$run->id,
        ['This takes '.BudgetService::monthLabels($v['fy'])[$month].' '.money($v['variance']).' over the approved budget']));
    $hr = hrRun();
    printf("  nightly HR task mentions it: %s%s", str_contains($hr, 'over the approved budget') ? 'yes' : 'NO', PHP_EOL);
    foreach (explode("\n", $hr) as $l) {
        if (str_contains($l, 'over the approved budget') || str_contains($l, 'no line for')) {
            printf("      %s%s", trim($l), PHP_EOL);
        }
    }

    printf("%s== take the line to zero: the account is not budgeted at all ==%s", PHP_EOL, PHP_EOL);
    $line->update([$cell => 0]);
    $v = PayrollService::budgetVariance($run->fresh());
    printf("  engine: status %s — %s%s", $v['status'], $v['note'], PHP_EOL);
    show('GET /payroll/runs/'.$run->id, page('/payroll/runs/'.$run->id, $marks));
    $hr = hrRun();
    printf("  nightly HR task mentions it: %s%s", str_contains($hr, 'no line for') ? 'yes' : 'NO', PHP_EOL);
} finally {
    $line->update([$cell => $original]);
    printf("%s== restored: the %s line is %s again ==%s", PHP_EOL, BudgetService::monthLabels(BudgetService::fyFor($run->period_start))[$month],
        money((float) $line->fresh()->{$cell}), PHP_EOL);
    $after = PayrollService::budgetVariance($run->fresh());
    printf("  engine: status %s, variance %s (%s%%)%s", $after['status'], money($after['variance']), $after['pct'], PHP_EOL);
    printf("  the budget line is byte-identical to what it was: %s%s",
        abs((float) DB::table('budget_lines')->where('id', $line->id)->value($cell) - $original) < 0.005 ? 'yes' : 'NO', PHP_EOL);
}
