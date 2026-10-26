<?php

// Driver-level PABX TCP certification checks (no sockets or DB writes):
// deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/pbx_driver_check.php';"

use App\Models\InterfaceCommand;
use App\Services\Interfaces\TcpPbxDriver;

$checks = 0;
$failures = [];
$check = static function (string $name, bool $ok) use (&$checks, &$failures): void {
    $checks++;
    echo ($ok ? 'PASS ' : 'FAIL ').$name.PHP_EOL;
    if (! $ok) {
        $failures[] = $name;
    }
};
$cfg = [
    'pbx_cmd_checkin' => 'CMD CHECKIN {extension} {name} {cos}',
    'pbx_tcp_ok' => '\b(OK|ACK|SUCCESS)\b',
];
$command = static function (string $name, array $payload = []): InterfaceCommand {
    $cmd = new InterfaceCommand;
    $cmd->system = 'pbx';
    $cmd->command = $name;
    $cmd->payload = $payload;
    return $cmd;
};
[$line, $error] = TcpPbxDriver::buildLine($cfg, $command('checkin', ['extension' => '101', 'name' => 'Ada Guest', 'cos' => 'national']));
$check('check-in template substitutes payload fields', $error === null && $line === 'CMD CHECKIN 101 Ada Guest national');
$bad = $cfg;
$bad['pbx_cmd_checkin'] = 'CMD {unknown}';
[, $error] = TcpPbxDriver::buildLine($bad, $command('checkin'));
$check('unknown placeholders fail before a socket opens', str_contains((string) $error, '{unknown}'));
[$line, $error] = TcpPbxDriver::buildLine($cfg, $command('checkin', ['extension' => '101', 'name' => "Ada\r\nCOS 101 full", 'cos' => 'national']));
$check('CR/LF payload cannot inject a second PABX command', $line === null && str_contains((string) $error, 'control characters'));
[$ok] = TcpPbxDriver::acknowledged($cfg, 'OK accepted', false);
$check('configured success expression accepts OK', $ok);
[$ok] = TcpPbxDriver::acknowledged($cfg, 'ERROR rejected', false);
$check('configured success expression rejects ERROR', ! $ok);
[$ok, $message] = TcpPbxDriver::acknowledged($cfg, '', true);
$check('timeout without acknowledgement fails when a pattern is configured', ! $ok && str_contains($message, 'No reply'));
$blank = $cfg;
$blank['pbx_tcp_ok'] = '';
[$ok] = TcpPbxDriver::acknowledged($blank, '', true);
$check('silent PABX can accept a completed write when regex is blank', $ok);

printf("RESULT %d passed, %d failed\n", $checks - count($failures), count($failures));
if ($failures) {
    throw new RuntimeException('PABX driver checks failed: '.implode('; ', $failures));
}
