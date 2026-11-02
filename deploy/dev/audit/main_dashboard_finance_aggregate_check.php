<?php

// Compare the dashboard's grouped finance totals with its former row-by-row
// calculations over every business date in the dev ledger.
// Run with: deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/main_dashboard_finance_aggregate_check.php';"

use App\Models\FolioTransaction;
use App\Models\PosOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

$dates = FolioTransaction::query()->whereNotNull('business_date')->distinct()->pluck('business_date')
    ->merge(PosOrder::query()->whereNotNull('business_date')->distinct()->pluck('business_date'))
    ->map(fn ($date) => Carbon::parse($date)->toDateString())->unique()->sort()->values();

foreach ($dates as $date) {
    $transactions = FolioTransaction::whereDate('business_date', $date)->where('is_void', false)->get();
    $posOrders = PosOrder::whereDate('business_date', $date)->where('status', 'settled')->where('settlement', 'payment')->get();

    $expected = [
        'roomRevenue' => $transactions->where('type', 'charge')->whereIn('category', ['room', 'extra_bed'])->sum('amount'),
        'otherRevenue' => $transactions->where('type', 'charge')->whereNotIn('category', ['room', 'extra_bed'])->sum('amount')
            + $posOrders->sum(fn ($order) => $order->total - $order->tax),
        'collections' => $transactions->where('type', 'payment')->where('category', '!=', 'city_ledger')->sum('amount')
            + $posOrders->sum('total'),
    ];

    $groupedTransactions = FolioTransaction::whereDate('business_date', $date)->where('is_void', false)
        ->select('type', 'category', DB::raw('SUM(amount) as amount_sum'))
        ->groupBy('type', 'category')->get();
    $posTotals = PosOrder::whereDate('business_date', $date)->where('status', 'settled')->where('settlement', 'payment')
        ->selectRaw('COALESCE(SUM(total - tax), 0) as net_total, COALESCE(SUM(total), 0) as gross_total')->first();

    $actual = [
        'roomRevenue' => $groupedTransactions->where('type', 'charge')->whereIn('category', ['room', 'extra_bed'])->sum('amount_sum'),
        'otherRevenue' => $groupedTransactions->where('type', 'charge')->whereNotIn('category', ['room', 'extra_bed'])->sum('amount_sum')
            + (float) ($posTotals->net_total ?? 0),
        'collections' => $groupedTransactions->where('type', 'payment')->where('category', '!=', 'city_ledger')->sum('amount_sum')
            + (float) ($posTotals->gross_total ?? 0),
    ];

    foreach ($expected as $metric => $amount) {
        if (abs((float) $amount - (float) $actual[$metric]) >= 0.005) {
            throw new RuntimeException("{$date} {$metric} changed: row sum {$amount}, SQL aggregate {$actual[$metric]}");
        }
    }
    echo "PASS {$date}: room revenue, other revenue and collections match".PHP_EOL;
}

echo 'Compared '.count($dates).' distinct business dates.'.PHP_EOL;
