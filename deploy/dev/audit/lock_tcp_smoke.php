<?php

// Optional end-to-end adapter check; start the simulator first:
// node deploy/dev/lock-sim.mjs --port=4457
// deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/lock_tcp_smoke.php';"

use App\Models\Hotel;
use App\Models\Setting;
use App\Services\InterfaceService;
use App\Support\Tenant;

Tenant::set(Hotel::first());
DB::beginTransaction();
try {
    foreach ([
        'lock_enabled' => '1',
        'lock_driver' => 'tcp',
        'lock_tcp_host' => '127.0.0.1',
        'lock_tcp_port' => '4457',
        'lock_tcp_timeout' => '2',
        'lock_tcp_ok' => '\b(OK|ACK|SUCCESS)\b',
        'lock_reply_serial' => '(?:UID|SERIAL|CARD)[^A-Za-z0-9]*([A-Za-z0-9][A-Za-z0-9:-]{3,31})',
        'lock_cmd_issue' => 'ENCODE {room} {until} {areas}',
    ] as $key => $value) {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
    }
    Setting::$version++;
    $cmd = InterfaceService::send('lock', 'issue', [
        'room' => '101', 'until' => '2026-10-29 12:00', 'areas' => ['Lift', 'Gym'],
    ]);
    $passed = $cmd->status === 'done' && preg_match('/^SIM[A-F0-9]{8}$/', (string) ($cmd->payload['serial'] ?? '')) === 1;
    echo ($passed ? 'PASS ' : 'FAIL ').'TCP issue command receives and stores simulator UID'.PHP_EOL;
    echo 'status='.$cmd->status.' serial='.($cmd->payload['serial'] ?? '(none)').' response='.$cmd->response.PHP_EOL;
    if (! $passed) {
        throw new RuntimeException('TCP lock simulator smoke test failed.');
    }
} finally {
    DB::rollBack();
}
