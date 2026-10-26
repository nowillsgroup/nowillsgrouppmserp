<?php

// Verify offline folio entries use their local operation business date and that
// the scoped override is restored after replay.
// TINKER='require "/app/deploy/dev/audit/desktop_occurred_at_check.php";' deploy/dev/bin/php -f deploy/dev/tinker.php

use App\Models\FolioTransaction;
use App\Models\Hotel;
use App\Models\OfflineSyncOperation;
use App\Models\ReservationRoom;
use App\Models\User;
use App\Services\DesktopSyncService;
use App\Support\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Tenant::set(Hotel::find(1));
$user = User::where('hotel_id', 1)->firstOrFail();
Auth::login($user);
$stay = ReservationRoom::where('hotel_id', 1)->where('status', 'checked_in')->first();
if (! $stay) {
    throw new RuntimeException('No checked-in demo stay is available for the offline occurred_at check.');
}
$currentDate = bdate()->toDateString();
$occurredAt = bdate()->subDay()->setTime(16, 30);
$expectedDate = $occurredAt->toDateString();
$operationId = 'audit.desktop.occurred.'.bin2hex(random_bytes(5));

DB::beginTransaction();
try {
    $response = DesktopSyncService::sync([[
        'operation_id' => $operationId,
        'operation' => 'desk.charge',
        'occurred_at' => $occurredAt->toIso8601String(),
        'payload' => ['stay_id' => $stay->id, 'category' => 'extras', 'description' => 'Audit only', 'amount' => 1, 'tax' => false],
    ]], $user, 'audit-desktop');
    $receipt = OfflineSyncOperation::where('hotel_id', 1)->where('operation_id', $operationId)->first();
    $transaction = $receipt?->result['transaction_id'] ? FolioTransaction::find($receipt->result['transaction_id']) : null;
    $actualDate = $transaction?->business_date?->toDateString();
    $restoredDate = bdate()->toDateString();
    $passed = ($response['results'][0]['status'] ?? null) === 'applied'
        && $actualDate === $expectedDate
        && $restoredDate === $currentDate;
    echo ($passed ? 'PASS ' : 'FAIL ')."offline charge uses occurred_at business date ({$actualDate}; expected {$expectedDate})".PHP_EOL;
    echo ($restoredDate === $currentDate ? 'PASS ' : 'FAIL ')."normal PMS business date is restored after replay ({$restoredDate})".PHP_EOL;
    $tooOldId = 'audit.desktop.too-old.'.bin2hex(random_bytes(4));
    $tooOld = DesktopSyncService::sync([[
        'operation_id' => $tooOldId,
        'operation' => 'desk.charge',
        'occurred_at' => now()->subDays(32)->toIso8601String(),
        'payload' => ['stay_id' => $stay->id, 'category' => 'extras', 'description' => 'Audit only', 'amount' => 1, 'tax' => false],
    ]], $user, 'audit-desktop');
    $bounded = ($tooOld['results'][0]['status'] ?? null) === 'rejected'
        && str_contains((string) ($tooOld['results'][0]['error'] ?? ''), '31 days');
    echo ($bounded ? 'PASS ' : 'FAIL ').'offline occurred_at outside the 31-day cache window is rejected'.PHP_EOL;
    if (! $passed || ! $bounded) {
        throw new RuntimeException('Offline occurred_at business-date check failed.');
    }
} finally {
    DB::rollBack();
    Auth::logout();
}
