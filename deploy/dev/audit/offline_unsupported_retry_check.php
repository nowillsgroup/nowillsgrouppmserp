<?php

// A lost response for an unsupported outbox operation must replay its original
// rejection instead of hitting the unique index and being misreported retryable.
// TINKER='require "/app/deploy/dev/audit/offline_unsupported_retry_check.php";' deploy/dev/bin/php -f deploy/dev/tinker.php

use App\Models\Hotel;
use App\Models\User;
use App\Services\OfflineSyncService;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;

Tenant::set(Hotel::find(1));
$user = User::where('hotel_id', 1)->where('id', 1)->firstOrFail();
$operation = [
    'operation_id' => 'audit.offline.unsupported.'.bin2hex(random_bytes(5)),
    'operation' => 'unsupported.test',
    'payload' => [],
];

DB::beginTransaction();
try {
    $first = OfflineSyncService::push([$operation], $user, 'audit-offline')['results'][0];
    $retry = OfflineSyncService::push([$operation], $user, 'audit-offline')['results'][0];
    $passed = ($first['status'] ?? null) === 'rejected'
        && ($retry['status'] ?? null) === 'rejected'
        && ($first['error'] ?? null) === ($retry['error'] ?? null);
    echo ($passed ? 'PASS ' : 'FAIL ').'unsupported operation retry returns the original idempotent rejection'.PHP_EOL;
    if (! $passed) {
        throw new RuntimeException('Unsupported operation idempotency check failed.');
    }
} finally {
    DB::rollBack();
}
