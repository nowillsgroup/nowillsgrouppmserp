<?php

// Keep invalid status errors distinct from the valid pairing guard.
// deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/iptv_status_validation_check.php';"

use App\Models\Hotel;
use App\Models\TvDevice;
use App\Models\User;
use App\Services\IptvService;
use App\Support\Tenant;
use Illuminate\Support\Str;

Tenant::set(Hotel::firstOrFail());
$user = User::all()->first(fn (User $candidate) => $candidate->hasPermission('iptv.manage'));
if (! $user) {
    throw new RuntimeException('No IPTV manager is available for the status-validation check.');
}

DB::beginTransaction();
try {
    $active = TvDevice::create(['name' => 'Audit active TV', 'token' => Str::random(40), 'status' => 'active']);
    $pending = TvDevice::create(['name' => 'Audit pending TV', 'token' => Str::random(40), 'status' => 'pending']);

    $invalidMessage = '';
    try {
        IptvService::setStatus($active, 'maintenance', $user);
    } catch (RuntimeException $e) {
        $invalidMessage = $e->getMessage();
    }

    $pendingMessage = '';
    try {
        IptvService::setStatus($pending, 'active', $user);
    } catch (RuntimeException $e) {
        $pendingMessage = $e->getMessage();
    }

    $invalidOk = $invalidMessage === 'TV status must be active or disabled.';
    $pendingOk = $pendingMessage === 'A TV awaiting pairing must be paired first.';
    $unchanged = $active->fresh()->status === 'active' && $pending->fresh()->status === 'pending';
    echo ($invalidOk ? 'PASS ' : 'FAIL ').'unsupported status gets a status-validation message'.PHP_EOL;
    echo ($pendingOk ? 'PASS ' : 'FAIL ').'pending TV gets the pairing-required message'.PHP_EOL;
    echo ($unchanged ? 'PASS ' : 'FAIL ').'rejected status attempts leave device state unchanged'.PHP_EOL;

    if (! $invalidOk || ! $pendingOk || ! $unchanged) {
        throw new RuntimeException('IPTV status-validation check failed.');
    }
} finally {
    DB::rollBack();
}
