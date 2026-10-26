<?php

// Driver-level certification checks (no hardware or database writes):
// deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/lock_interface_check.php';"

use App\Models\InterfaceCommand;
use App\Services\Interfaces\TcpLockDriver;

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
    'lock_cmd_issue' => 'ENCODE {room} {until} {areas}',
    'lock_cmd_duplicate' => 'DUPLICATE {room} {serial}',
    'lock_cmd_cancel' => 'CANCEL {serial}',
    'lock_cmd_checkout' => 'CHECKOUT {room}',
    'lock_cmd_extend' => 'EXTEND {room} {until}',
    'lock_cmd_read' => 'READ {serial}',
    'lock_tcp_ok' => '\b(OK|ACK|SUCCESS)\b',
    'lock_reply_serial' => '(?:UID|SERIAL|CARD)[^A-Za-z0-9]*([A-Za-z0-9][A-Za-z0-9:-]{3,31})',
];
$command = static function (string $name, array $payload = []): InterfaceCommand {
    $cmd = new InterfaceCommand;
    $cmd->system = 'lock';
    $cmd->command = $name;
    $cmd->payload = $payload;

    return $cmd;
};

[$line, $error] = TcpLockDriver::buildLine($cfg, $command('issue', ['room' => '101', 'until' => '2026-10-29 12:00', 'areas' => 'Lift,Gym']));
$check('issue template substitutes payload values', $error === null && $line === 'ENCODE 101 2026-10-29 12:00 Lift,Gym');
[$line, $error] = TcpLockDriver::buildLine($cfg, $command('cancel', ['serial' => 'A1B2C3D4']));
$check('cancel template substitutes the card serial', $error === null && $line === 'CANCEL A1B2C3D4');
$bad = $cfg;
$bad['lock_cmd_read'] = 'READ {missing}';
[, $error] = TcpLockDriver::buildLine($bad, $command('read'));
$check('unknown placeholders are rejected before connecting', str_contains((string) $error, '{missing}'));
$check('encoder reply UID is parsed and normalized', TcpLockDriver::parseSerial($cfg, 'OK UID=a1-b2-c3-d4') === 'A1B2C3D4');
$check('encode reply without UID is not accepted as a recorded card', TcpLockDriver::parseSerial($cfg, 'OK ENCODED') === null);
[$ok] = TcpLockDriver::acknowledged($cfg, 'OK card written', false);
$check('success regex accepts OK', $ok);
[$ok] = TcpLockDriver::acknowledged($cfg, 'ERROR card rejected', false);
$check('success regex rejects an encoder error', ! $ok);
[$ok, $message] = TcpLockDriver::acknowledged($cfg, '', true);
$check('configured success regex treats a timed-out empty reply as failure', ! $ok && str_contains($message, 'No reply'));
$blank = $cfg;
$blank['lock_tcp_ok'] = '';
[$ok] = TcpLockDriver::acknowledged($blank, '', true);
$check('blank success regex supports silent encoders', $ok);

printf("RESULT %d passed, %d failed\n", $checks - count($failures), count($failures));
if ($failures) {
    throw new RuntimeException('Lock interface checks failed: '.implode('; ', $failures));
}
