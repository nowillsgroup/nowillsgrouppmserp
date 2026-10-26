<?php

// Full KeyCardService -> InterfaceService -> TCP encoder integration check.
// Start node deploy/dev/lock-sim.mjs --port=4457, then run:
// deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/lock_tcp_keycard_check.php';"

use App\Models\Hotel;
use App\Models\ReservationRoom;
use App\Models\Setting;
use App\Services\InterfaceService;
use App\Services\KeyCardService;
use App\Support\Tenant;

Tenant::set(Hotel::first());
$stay = ReservationRoom::where('status', 'checked_in')->whereNotNull('room_id')
    ->whereDate('departure_date', '>=', bdate()->toDateString())->first();
if (! $stay) {
    throw new RuntimeException('Need one checked-in stay departing today or later to run the TCP key-card smoke check.');
}

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
    $cards = KeyCardService::issue($stay, 1, 'new');
    $card = $cards->first();
    $expected = $card?->getKey() ? $card->serial : null;
    $cmd = $card ? App\Models\InterfaceCommand::where('key_card_id', $card->id)->latest('id')->first() : null;
    $passed = $card && preg_match('/^SIM[A-F0-9]{8}$/', (string) $expected) === 1
        && $cmd?->status === 'done' && ($cmd->payload['serial'] ?? null) === $expected;

    echo ($passed ? 'PASS ' : 'FAIL ').'KeyCardService records the UID actually returned by the TCP encoder'.PHP_EOL;
    echo 'stay='.$stay->id.' card='.($card?->card_no ?? '(none)').' serial='.($expected ?? '(none)').' command='.($cmd?->status ?? '(none)').PHP_EOL;
    if (! $passed) {
        throw new RuntimeException('TCP key-card integration check failed.');
    }
} finally {
    DB::rollBack();
}
