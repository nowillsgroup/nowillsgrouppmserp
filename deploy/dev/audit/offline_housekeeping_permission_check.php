<?php

// The shared /offline/sync route also admits requests.manage logins. They must
// not gain housekeeping write permission through that broader route gate.
// TINKER='require "/app/deploy/dev/audit/offline_housekeeping_permission_check.php";' deploy/dev/bin/php -f deploy/dev/tinker.php

use App\Models\Hotel;
use App\Models\OfflineSyncOperation;
use App\Models\Room;
use App\Models\User;
use App\Services\OfflineSyncService;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;

Tenant::set(Hotel::find(1));
$user = User::where('hotel_id', 1)->where('id', 4)->firstOrFail();
if (! $user->hasPermission('requests.manage') || $user->hasPermission('housekeeping.manage') || $user->hasPermission('housekeeping.attendant')) {
    throw new RuntimeException('Demo user 4 is no longer a requests-only login for this permission check.');
}
$room = Room::where('hotel_id', 1)->where('is_active', true)->firstOrFail();
$original = ['hk_status' => $room->hk_status, 'notes' => $room->notes];
$target = $room->hk_status === 'clean' ? 'dirty' : 'clean';
$operationId = 'audit.offline.permission.'.bin2hex(random_bytes(5));

DB::beginTransaction();
try {
    $response = OfflineSyncService::push([[
        'operation_id' => $operationId,
        'operation' => 'housekeeping.room_status',
        'base_updated_at' => $room->updated_at->toIso8601String(),
        'payload' => ['room_id' => $room->id, 'hk_status' => $target],
    ]], $user, 'audit-permission');
    $fresh = $room->fresh();
    $result = $response['results'][0] ?? [];
    $passed = ($result['status'] ?? null) === 'rejected'
        && str_contains((string) ($result['error'] ?? ''), 'cannot sync housekeeping')
        && $fresh->hk_status === $original['hk_status']
        && $fresh->notes === $original['notes'];
    echo ($passed ? 'PASS ' : 'FAIL ').'requests.manage alone cannot sync housekeeping room-status writes'.PHP_EOL;
    if (! $passed) {
        throw new RuntimeException('Offline housekeeping permission check failed.');
    }
} finally {
    DB::rollBack();
}
