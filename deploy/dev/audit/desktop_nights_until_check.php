<?php

// Verify desk.nights honors its declared payload.until field.
// deploy/dev/bin/php -f deploy/dev/tinker.php with TINKER='require "/app/deploy/dev/audit/desktop_nights_until_check.php";'

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
    throw new RuntimeException('No checked-in demo stay is available for the offline nights check.');
}
$operationId = 'audit.desktop.nights.'.bin2hex(random_bytes(6));
$until = $stay->departure_date->toDateString();
$occurredAt = $stay->arrival_date->toDateString().'T12:00:00Z';

DB::beginTransaction();
try {
    $response = DesktopSyncService::sync([[
        'operation_id' => $operationId,
        'operation' => 'desk.nights',
        'occurred_at' => $occurredAt,
        'payload' => ['stay_id' => $stay->id, 'until' => $until],
    ]], $user, 'audit-desktop');
    $receipt = OfflineSyncOperation::where('hotel_id', 1)->where('operation_id', $operationId)->first();
    $passed = ($response['results'][0]['status'] ?? null) === 'applied'
        && ($receipt?->result['posted_until'] ?? null) === $until;
    echo ($passed ? 'PASS ' : 'FAIL ').'offline desk.nights honors payload.until ('.($receipt?->result['posted_until'] ?? 'no result').')'.PHP_EOL;
    if (! $passed) {
        throw new RuntimeException('Offline desk.nights until check failed.');
    }
} finally {
    DB::rollBack();
    Auth::logout();
}
