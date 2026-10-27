<?php

// Offline check-out must never accept the client-controlled force flag that
// bypasses unsettled POS, transport, activity or compliance holds.
// TINKER='require "/app/deploy/dev/audit/desktop_force_checkout_check.php";' deploy/dev/bin/php -f deploy/dev/tinker.php

use App\Models\Hotel;
use App\Models\ReservationRoom;
use App\Models\User;
use App\Services\DesktopSyncService;
use App\Support\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Tenant::set(Hotel::find(1));
$user = User::where('hotel_id', 1)->where('id', 1)->firstOrFail();
Auth::login($user);
$stay = ReservationRoom::where('hotel_id', 1)->where('status', 'checked_in')->firstOrFail();
$operationId = 'audit.desktop.force-checkout.'.bin2hex(random_bytes(5));

DB::beginTransaction();
try {
    $response = DesktopSyncService::sync([[
        'operation_id' => $operationId,
        'operation' => 'desk.check_out',
        'payload' => ['stay_id' => $stay->id, 'force' => true],
    ]], $user, 'audit-desktop');
    $fresh = $stay->fresh();
    $result = $response['results'][0] ?? [];
    $passed = ($result['status'] ?? null) === 'rejected'
        && str_contains((string) ($result['error'] ?? ''), 'Forced checkout is not available offline')
        && $fresh->status === 'checked_in';
    echo ($passed ? 'PASS ' : 'FAIL ').'offline sync rejects forced checkout without changing the stay'.PHP_EOL;
    if (! $passed) {
        throw new RuntimeException('Forced offline checkout check failed.');
    }
} finally {
    DB::rollBack();
    Auth::logout();
}
