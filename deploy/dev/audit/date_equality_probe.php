<?php

/**
 * Date-storage probe: what a string comparison misses on a DATE column.
 *
 * SQLite is dynamically typed, so a DATE column holds exactly what the writer handed it. Eloquent
 * writes a `date`-cast attribute as "Y-m-d", but a seeder or a service that assigns a Carbon writes
 * "Y-m-d 00:00:00" — and several tables hold both shapes in the same column. A query that compares
 * with a date string then misses part of the table:
 *
 *   where('business_date', '<=', '2026-10-13')  →  '2026-10-13 00:00:00' is GREATER, so excluded
 *   where('business_date', '2026-10-13')        →  never matches a row stored with a time part
 *
 * Section A measures that per column, in rows and in money. Section B prints the control-account and
 * sub-ledger figures that are built on those comparisons, so the effect of the whereDate() fix can be
 * read off directly (run it before and after).
 *
 *   TINKER='require "/app/deploy/dev/audit/date_equality_probe.php";' deploy/dev/bin/php -f deploy/dev/tinker.php
 */

use App\Models\PettyFloat;
use App\Services\ConciergeService;
use App\Services\GiftVoucherService;
use App\Services\LedgerService;
use App\Services\ManagementReportService;
use App\Services\OnlinePaymentService;
use App\Services\PettyCashService;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$asOf = bdate()->toDateString();
echo "as-of date: {$asOf}\n\n";

/** table => [date column, money column or null] */
$targets = [
    'folio_transactions' => ['business_date', 'amount'],
    'folios' => ['invoice_date', 'grand_total'],
    'vouchers' => ['date', 'amount'],
    'stock_movements' => ['date', null],
    'petty_txns' => ['date', 'amount'],
    'gift_voucher_transactions' => ['business_date', 'amount'],
    'linen_movements' => ['date', 'value'],
    'parking_charges' => ['business_date', 'total'],
    'rfq_quotes' => ['quote_date', 'total'],
    'compliance_events' => ['event_date', null],
    'leases' => ['move_out_on', null],
    'night_audits' => ['business_date', null],
    'gl_lines' => ['date', 'debit'],
    'attendances' => ['date', null],
];

printf("%-30s %-14s %8s %8s %8s %14s %s\n", 'TABLE.COLUMN', 'OP', 'string', 'whereDate', 'MISSED', 'money missed', 'shapes');
echo str_repeat('─', 118)."\n";

foreach ($targets as $table => [$col, $money]) {
    if (! Schema::hasTable($table)) {
        continue;
    }
    $shapes = [];
    foreach (DB::select("select length({$col}) len, count(*) c from {$table} where {$col} is not null group by length({$col})") as $s) {
        $shapes[(int) $s->len] = (int) $s->c;
    }
    if (! $shapes) {
        continue;
    }
    ksort($shapes);
    $shapeDesc = json_encode($shapes);
    foreach ([['<=', 'le'], ['=', 'eq']] as [$op, $tag]) {
        $q = DB::table($table)->where('hotel_id', Tenant::id());
        $nString = (clone $q)->where($col, $op, $asOf)->count();
        $nDate = (clone $q)->whereDate($col, $op, $asOf)->count();
        $missed = $nDate - $nString;
        $sumMissed = '';
        if ($money && $missed) {
            $a = (float) (clone $q)->whereDate($col, $op, $asOf)->sum($money);
            $b = (float) (clone $q)->where($col, $op, $asOf)->sum($money);
            $sumMissed = number_format($a - $b, 2);
        }
        if ($missed || $tag === 'le') {
            printf("%-30s %-14s %8d %8d %8d %14s %s\n", "{$table}.{$col}", $op, $nString, $nDate, $missed, $sumMissed, $shapeDesc);
        }
    }
}

echo "\n── balances built on those comparisons ──\n";
$controls = LedgerService::controls($asOf);
foreach ($controls as $k => $v) {
    if (is_array($v)) {
        foreach ($v as $k2 => $v2) {
            if (is_numeric($v2)) {
                printf("  controls.%-22s %-10s %s\n", $k, $k2, number_format((float) $v2, 2));
            }
        }

        continue;
    }
    if (is_numeric($v)) {
        printf("  controls.%-34s %s\n", $k, number_format((float) $v, 2));
    }
}
printf("  voucher liability as at today       %s\n", number_format(GiftVoucherService::liabilityAt($asOf), 2));
printf("  online clearing balance             %s\n", number_format(OnlinePaymentService::clearingBalance($asOf), 2));
printf("  concierge payable balance           %s\n", number_format(ConciergeService::payableBalance($asOf), 2));
foreach (PettyFloat::orderBy('id')->get() as $f) {
    printf("  petty float %-12s balance      %s\n", $f->code, number_format(PettyCashService::balance($f, $asOf), 2));
}
$ageing = ManagementReportService::arAgeing(bdate());
printf("  AR ageing: %d company/companies, total billed %s\n", $ageing->count(),
    number_format((float) $ageing->sum(fn ($r) => (float) ($r['billed'] ?? $r['total'] ?? 0)), 2));
