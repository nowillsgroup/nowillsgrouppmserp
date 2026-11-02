<?php

// Compare the daily transaction-register SQL summary with the rows' displayed amounts.
// Run with: deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/folio_transaction_summary_check.php';"

use App\Models\FolioTransaction;
use App\Models\Hotel;
use App\Support\Tenant;
use Illuminate\Support\Carbon;

Tenant::set(Hotel::findOrFail(1));
$dates = FolioTransaction::query()->where('is_void', false)->whereNotNull('business_date')
    ->select('business_date')->distinct()->orderByDesc('business_date')->limit(3)->pluck('business_date')
    ->map(fn ($date) => Carbon::parse($date)->toDateString())->unique()->values();
$checked = 0;

foreach ($dates as $date) {
    $base = FolioTransaction::query()->whereDate('business_date', $date)->where('is_void', false);
    $types = (clone $base)->distinct()->orderBy('type')->pluck('type')->filter()->values();
    $categories = (clone $base)->distinct()->orderBy('category')->pluck('category')->filter()->values();
    $cases = [['all filters' => []]];
    foreach ($types as $type) {
        $cases[] = ["type={$type}" => ['type' => $type]];
    }
    foreach ($categories as $category) {
        $cases[] = ["category={$category}" => ['category' => $category]];
    }
    foreach ($types->take(3) as $type) {
        foreach ($categories->take(3) as $category) {
            $cases[] = ["type={$type}, category={$category}" => ['type' => $type, 'category' => $category]];
        }
    }

    foreach ($cases as $case) {
        $label = array_key_first($case);
        $filters = $case[$label];
        $query = clone $base;
        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (isset($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        $expectedRows = (clone $query)->get(['type', 'amount', 'tax_amount']);
        $expectedAmount = 0.0;
        $expectedTax = 0.0;
        $expectedTotal = 0.0;
        foreach ($expectedRows as $row) {
            $amount = in_array($row->type, ['discount', 'allowance', 'refund'], true)
                ? -(float) $row->amount
                : (float) $row->amount;
            $tax = $row->type === 'charge' ? (float) $row->tax_amount : 0.0;
            $expectedAmount += $amount;
            $expectedTax += $tax;
            $expectedTotal += $row->type === 'charge' ? (float) $row->amount + $tax : $amount;
        }

        $summary = (clone $query)->selectRaw("count(*) as entries,
            coalesce(sum(case type when 'charge' then amount when 'discount' then -amount when 'allowance' then -amount when 'refund' then -amount else amount end), 0) as amount,
            coalesce(sum(case when type = 'charge' then tax_amount else 0 end), 0) as tax,
            coalesce(sum(case type when 'charge' then amount + tax_amount when 'discount' then -amount when 'allowance' then -amount when 'refund' then -amount else amount end), 0) as total_posted")
            ->first();
        $values = [
            'entries' => [(int) $summary->entries, $expectedRows->count()],
            'amount' => [round((float) $summary->amount, 2), round($expectedAmount, 2)],
            'tax' => [round((float) $summary->tax, 2), round($expectedTax, 2)],
            'total' => [round((float) $summary->total_posted, 2), round($expectedTotal, 2)],
        ];
        foreach ($values as $metric => [$actual, $expected]) {
            if ($actual !== $expected) {
                throw new RuntimeException("{$date} {$label}: {$metric} SQL summary {$actual} differs from displayed rows {$expected}.");
            }
        }
        ++$checked;
    }
}

echo "Matched {$checked} SQL transaction summaries to displayed row values across ".$dates->count()." business dates.".PHP_EOL;
