<?php

// Optional TCP smoke check; run node deploy/dev/pbx-sim.mjs --cmd-port=4455 first.
// deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/pbx_tcp_smoke.php';"
use App\Models\InterfaceCommand;
use App\Services\Interfaces\TcpPbxDriver;

$cfg = [
    'pbx_cmd_checkin' => 'CMD CHECKIN {extension} {name} {cos}',
    'pbx_tcp_host' => '127.0.0.1',
    'pbx_tcp_port' => '4455',
    'pbx_tcp_timeout' => '2',
    'pbx_tcp_ok' => '\b(OK|ACK|SUCCESS)\b',
];
$cmd = new InterfaceCommand;
$cmd->system = 'pbx';
$cmd->command = 'checkin';
$cmd->payload = ['extension' => '101', 'name' => 'Ada Guest', 'cos' => 'national'];
[$ok, $response] = TcpPbxDriver::send($cfg, $cmd);
echo ($ok ? 'PASS ' : 'FAIL ').'PABX TCP command receives an acknowledgement: '.$response.PHP_EOL;
if (! $ok) {
    throw new RuntimeException('PABX TCP smoke check failed: '.$response);
}
