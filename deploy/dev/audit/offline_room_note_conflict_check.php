<?php

// A stale offline room-status sync must not overwrite a newer server note even
// when the housekeeping status itself is already the requested status.
// TINKER='require "/app/deploy/dev/audit/offline_room_note_conflict_check.php";' deploy/dev/bin/php -f deploy/dev/tinker.php

use App\Models\Hotel;
use App\Models\Room;
use App\Models\User;
use App\Services\OfflineSyncService;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;

Tenant::set(Hotel::find(1));
$user = User::where('hotel_id', 1)->firstOrFail();
$room = Room::where('hotel_id', 1)->where('is_active', true)->first();
if (! $room) {
    throw new RuntimeException('No active demo room is available for the offline note conflict check.');
}
$status = in_array($room->hk_status, OfflineSyncService::ROOM_STATUSES, true) ? $room->hk_status : 'clean';
$operationId = 'audit.offline.note.'.bin2hex(random_bytes(5));

DB::beginTransaction();
try {
    $room->forceFill(['hk_status' => $status, 'notes' => 'Newer server note', 'updated_at' => now()])->save();
    $response = OfflineSyncService::push([[
        'operation_id' => $operationId,
        'operation' => 'housekeeping.room_status',
        'base_updated_at' => now()->subMinutes(5)->toIso8601String(),
        'payload' => ['room_id' => $room->id, 'hk_status' => $status, 'notes' => 'Stale offline note'],
    ]], $user, 'audit-offline');
    $fresh = $room->fresh();
    $passed = ($response['results'][0]['status'] ?? null) === 'conflict'
        && $fresh->notes === 'Newer server note';
    echo ($passed ? 'PASS ' : 'FAIL ').'stale offline sync preserves a newer room note when status matches'.PHP_EOL;
    if (! $passed) {
        throw new RuntimeException('Offline room note conflict check failed.');
    }
} finally {
    DB::rollBack();
}
