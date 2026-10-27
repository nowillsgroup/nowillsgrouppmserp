<?php

// A DATE column may contain a timestamp-shaped value in SQLite (legacy/raw SQL or imports).
// Ensure an as-of liability includes transactions on the requested end date.
// deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/gift_voucher_liability_date_check.php';"

use App\Models\GiftVoucher;
use App\Models\Hotel;
use App\Services\GiftVoucherService;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;

if (DB::getDriverName() !== 'sqlite') {
    echo "SKIP SQLite DATE-shape regression (driver: ".DB::getDriverName().")\n";

    return;
}

Tenant::set(Hotel::firstOrFail());
$voucher = GiftVoucher::query()->firstOrFail();
$date = bdate()->toDateString();
$amount = 1.23;

DB::beginTransaction();
try {
    $before = GiftVoucherService::liabilityAt($date);
    DB::table('gift_voucher_transactions')->insert([
        'hotel_id' => Tenant::id(),
        'gift_voucher_id' => $voucher->id,
        'type' => 'issue',
        'amount' => $amount,
        'balance_after' => $amount,
        'business_date' => $date.' 00:00:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $after = GiftVoucherService::liabilityAt($date);
    $delta = round($after - $before, 2);
    $passed = $delta === $amount;
    echo ($passed ? 'PASS ' : 'FAIL ')."same-day timestamp-shaped voucher movement is included (delta {$delta})".PHP_EOL;
    if (! $passed) {
        throw new RuntimeException('Gift voucher liability cutoff omitted a movement on the as-of date.');
    }
} finally {
    DB::rollBack();
}
