<?php

// Simulate an applied-receipt failure after posting a desktop charge. The
// charge must roll back with its receipt so a retry cannot double-charge.
// TINKER='require "/app/deploy/dev/audit/desktop_financial_atomicity_check.php";' deploy/dev/bin/php -f deploy/dev/tinker.php

use App\Models\FolioTransaction;
use App\Models\Hotel;
use App\Models\OfflineSyncOperation;
use App\Models\ReservationRoom;
use App\Models\User;
use App\Services\DesktopSyncService;
use App\Support\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

if (DB::connection()->getDriverName() !== 'sqlite') {
    echo "SKIP receipt-failure injection requires the local SQLite audit database.".PHP_EOL;
    return;
}

Tenant::set(Hotel::find(1));
$user = User::where('hotel_id', 1)->where('id', 1)->firstOrFail();
Auth::login($user);
$stay = ReservationRoom::where('hotel_id', 1)->where('status', 'checked_in')->firstOrFail();
$operationId = 'audit.desktop.atomic.'.bin2hex(random_bytes(5));
$trigger = 'audit_fail_desktop_receipt';

DB::unprepared("CREATE TRIGGER {$trigger} BEFORE INSERT ON offline_sync_operations WHEN NEW.operation_id = '{$operationId}' AND NEW.status = 'applied' BEGIN SELECT RAISE(ABORT, 'simulated receipt failure'); END");
DB::beginTransaction();
try {
    try {
        $response = DesktopSyncService::sync([[
            'operation_id' => $operationId,
            'operation' => 'desk.charge',
            'occurred_at' => now()->toIso8601String(),
            'payload' => ['stay_id' => $stay->id, 'category' => 'extras', 'description' => 'Audit only', 'amount' => 1, 'tax' => false],
        ]], $user, 'audit-desktop');
    } catch (Throwable $e) {
        $response = ['results' => [['status' => 'exception', 'error' => $e->getMessage()]]];
    }
    $charges = FolioTransaction::where('source_type', 'offline')->where('source_id', $operationId)->count();
    $receipt = OfflineSyncOperation::where('hotel_id', 1)->where('operation_id', $operationId)->first();
    $passed = $charges === 0 && $receipt?->status === 'rejected';
    echo ($passed ? 'PASS ' : 'FAIL ')."desktop charge rolls back when its applied receipt cannot be stored (charge count {$charges}, receipt ".($receipt?->status ?? 'none').")".PHP_EOL;
    if (! $passed) {
        throw new RuntimeException('Desktop financial atomicity check failed.');
    }
} finally {
    DB::rollBack();
    DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
    Auth::logout();
}
