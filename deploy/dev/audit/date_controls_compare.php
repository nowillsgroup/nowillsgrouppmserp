<?php
/**
 * Control-account + trial-balance reconciliation at a POSTED cut date.
 * Run on the patched tree and on a stashed (pre-fix) tree to get a true before/after.
 *   TINKER='require "/app/deploy/dev/audit/date_controls_compare.php";' deploy/dev/bin/php -f deploy/dev/tinker.php
 */
use App\Services\GlReportService;
use App\Services\LedgerService;

$asOf = getenv('ASOF') ?: '2026-09-30';
printf("CUT %s%s", $asOf, PHP_EOL);
$t = LedgerService::totals(null, $asOf);
printf("trial balance to %s: accounts %d   total debit %s   total credit %s%s", $asOf, $t->count(),
    number_format((float) $t->sum('dr'), 2), number_format((float) $t->sum('cr'), 2), PHP_EOL);
$bad = 0;
foreach (LedgerService::controls($asOf) as $c) {
    $diff = round((float) $c['sub'] - (float) $c['gl'], 2);
    $flag = abs($diff) < 0.01 ? 'ok  ' : ($c['strict'] ? 'FAIL' : 'warn');
    if ($flag === 'FAIL') {
        $bad++;
    }
    printf("  [%s] %-58s sub %15s   gl %15s   diff %12s%s", $flag, $c['label'], number_format((float) $c['sub'], 2),
        number_format((float) $c['gl'], 2), number_format($diff, 2), PHP_EOL);
}
printf("STRICT controls out of balance: %d%s", $bad, PHP_EOL);
