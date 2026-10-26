<?php

// Verify offline pack/delta watermarks precede each read by one second so
// second-precision concurrent updates are covered by a subsequent delta.
// TINKER='require "/app/deploy/dev/audit/desktop_cursor_check.php";' deploy/dev/bin/php -f deploy/dev/tinker.php

use App\Models\Hotel;
use App\Models\User;
use App\Services\DesktopSyncService;
use App\Support\Tenant;
use Illuminate\Support\Carbon;

Tenant::set(Hotel::find(1));
$user = User::where('hotel_id', 1)->firstOrFail();
$pack = DesktopSyncService::pack($user, 2, 'audit-cursor');
$packGenerated = Carbon::parse($pack['meta']['generated_at']);
$packCursor = Carbon::parse($pack['meta']['cursor']);
$delta = DesktopSyncService::delta($user, $pack['meta']['cursor']);
$deltaGenerated = Carbon::parse($delta['meta']['generated_at']);
$deltaCursor = Carbon::parse($delta['meta']['cursor']);
$packOk = $packCursor->lt($packGenerated) && $packCursor->diffInSeconds($packGenerated) <= 1.1;
$deltaOk = $deltaCursor->lt($deltaGenerated) && $deltaCursor->diffInSeconds($deltaGenerated) <= 1.1;

echo ($packOk ? 'PASS ' : 'FAIL ').'pack cursor overlaps its initial snapshot by one second'.PHP_EOL;
echo ($deltaOk ? 'PASS ' : 'FAIL ').'delta cursor overlaps its initial snapshot by one second'.PHP_EOL;
if (! $packOk || ! $deltaOk) {
    throw new RuntimeException('Desktop cursor overlap check failed.');
}
