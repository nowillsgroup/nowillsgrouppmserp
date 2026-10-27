<?php

// Verify the server enforces the configured TV volume cap (not only the remote UI).
// deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/iptv_volume_limit_check.php';"

use App\Models\Hotel;
use App\Models\Setting;
use App\Models\TvDevice;
use App\Services\IptvService;
use App\Support\Tenant;

Tenant::set(Hotel::first());
$device = TvDevice::where('status', 'active')->first();
if (! $device) {
    throw new RuntimeException('No active demo TV is available for the volume-limit check.');
}

DB::beginTransaction();
try {
    Setting::updateOrCreate(['key' => 'tv_volume_max'], ['value' => '60']);
    IptvService::flush();
    $acceptedAtCap = IptvService::command($device, 'volume', ['level' => 60], null);
    $rejected = false;
    try {
        IptvService::command($device, 'volume', ['level' => 61], null);
    } catch (RuntimeException $e) {
        $rejected = str_contains($e->getMessage(), '10 to 60');
    }
    echo (($acceptedAtCap->payload['level'] ?? null) === 60 ? 'PASS ' : 'FAIL ').'configured volume maximum is accepted'.PHP_EOL;
    echo ($rejected ? 'PASS ' : 'FAIL ').'commands above the configured volume maximum are rejected server-side'.PHP_EOL;
    if (($acceptedAtCap->payload['level'] ?? null) !== 60 || ! $rejected) {
        throw new RuntimeException('IPTV volume cap check failed.');
    }
} finally {
    IptvService::flush();
    DB::rollBack();
}
