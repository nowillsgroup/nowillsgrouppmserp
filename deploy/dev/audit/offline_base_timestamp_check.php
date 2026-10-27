<?php

// Conflict protection requires a real client snapshot timestamp; relative
// strings and future timestamps must not bypass stale-write detection.
// TINKER='require "/app/deploy/dev/audit/offline_base_timestamp_check.php";' deploy/dev/bin/php -f deploy/dev/tinker.php

use App\Models\Hotel;
use App\Models\Room;
use App\Models\User;
use App\Services\OfflineSyncService;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;

Tenant::set(Hotel::find(1));
$user = User::where('hotel_id', 1)->where('id', 1)->firstOrFail();
$room = Room::where('hotel_id', 1)->where('is_active', true)->firstOrFail();
$originalStatus = $room->hk_status;
$targetStatus = $originalStatus === 'clean' ? 'dirty' : 'clean';

DB::beginTransaction();
try {
    $relative = OfflineSyncService::push([[
        'operation_id' => 'audit.offline.base.relative.'.bin2hex(random_bytes(4)),
        'operation' => 'housekeeping.room_status',
        'base_updated_at' => 'now',
        'payload' => ['room_id' => $room->id, 'hk_status' => $targetStatus],
    ]], $user, 'audit-offline')['results'][0];
    $future = OfflineSyncService::push([[
        'operation_id' => 'audit.offline.base.future.'.bin2hex(random_bytes(4)),
        'operation' => 'housekeeping.room_status',
        'base_updated_at' => now()->addDay()->toIso8601String(),
        'payload' => ['room_id' => $room->id, 'hk_status' => $targetStatus],
    ]], $user, 'audit-offline')['results'][0];
    $unchanged = $room->fresh()->hk_status === $originalStatus;
    $passed = ($relative['status'] ?? null) === 'rejected'
        && str_contains((string) ($relative['error'] ?? ''), 'ISO 8601')
        && ($future['status'] ?? null) === 'rejected'
        && str_contains((string) ($future['error'] ?? ''), '5 minutes ahead')
        && $unchanged;
    echo ($passed ? 'PASS ' : 'FAIL ').'offline conflict protection rejects relative and future snapshot timestamps without changing the room'.PHP_EOL;
    if (! $passed) {
        throw new RuntimeException('Offline base timestamp validation check failed.');
    }
} finally {
    DB::rollBack();
}
