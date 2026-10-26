<?php

/**
 * v7.57 – Robita guest-journey check (run through `deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/robita_journey_check.php';"`).
 * Everything runs inside a transaction and is rolled back, so the demo data is untouched.
 */
use App\Models\Hotel;
use App\Models\KeyCard;
use App\Models\ReservationRoom;
use App\Models\RobitaCredential;
use App\Models\RobitaDevice;
use App\Models\Setting;
use App\Support\Tenant;
use App\Services\KeyCardService;
use App\Services\RobitaService;

$pass = 0;
$fail = 0;
$check = function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail) {
    $ok ? $pass++ : $fail++;
    echo ($ok ? 'PASS ' : 'FAIL ').$label.($detail !== '' ? "  [{$detail}]" : '').PHP_EOL;
};

Tenant::set(Hotel::orderBy('id')->first());
RobitaService::ensureDefaults();
Setting::updateOrCreate(['key' => 'robita_auto_issue'], ['value' => '1']);
Setting::updateOrCreate(['key' => 'robita_auto_revoke'], ['value' => '1']);

DB::beginTransaction();

$stay = ReservationRoom::where('status', 'checked_in')->whereNotNull('room_id')->orderBy('id')->first();
$check('demo data has a checked-in stay', (bool) $stay);
if (! $stay) {
    DB::rollBack();
    echo "RESULT {$pass} passed, {$fail} failed\n";
    return;
}
$device = RobitaService::registerDevice([
    'room_id' => $stay->room_id, 'serial' => 'JRNY001',
    'name' => 'Journey test lock', 'device_type' => 'door_lock',
]);
$card = KeyCard::create([
    'hotel_id' => Tenant::id(), 'card_no' => 'JRNY-C1', 'serial' => 'JRNY-SERIAL-1', 'type' => 'guest',
    'reservation_room_id' => $stay->id, 'room_id' => $stay->room_id, 'holder' => 'Journey Guest',
    'valid_from' => now(), 'valid_until' => now()->addDay(), 'status' => 'active',
]);

// 1. check-in provisions the lock
$cred = RobitaService::onCheckIn($stay->fresh());
$check('check-in issues a credential', $cred && $cred->status === 'active', $cred ? 'id '.$cred->id : 'none');
$check('credential is bound to the device + card', $cred && (int) $cred->robita_device_id === (int) $device->id && (int) $cred->key_card_id === (int) $card->id);
$check('credential is marked synced (demo mode)', $cred && $cred->sync_state === 'ok');
$expected = KeyCardService::validUntil($stay->fresh());
$check('credential expiry matches the key card', $cred && $cred->valid_until->equalTo($expected), $cred?->valid_until?->toDateTimeString());

// 2. one credential per active card (a stay may have several), and a re-run changes nothing
$cards = KeyCard::where('reservation_room_id', $stay->id)->where('status', 'active')->count();
$first = RobitaService::activeForStay($stay)->count();
$check('one credential per active card', $first === $cards, "cards {$cards}, credentials {$first}");
RobitaService::onCheckIn($stay->fresh());
$check('re-running check-in does not duplicate', RobitaService::activeForStay($stay)->count() === $first, 'active '.RobitaService::activeForStay($stay)->count());

// 2b. the key-card interface and the journey hook must not double up
Setting::updateOrCreate(['key' => 'lock_enabled'], ['value' => '1']);
Setting::updateOrCreate(['key' => 'lock_auto_issue'], ['value' => '1']);
KeyCardService::issue($stay->fresh(), 1, 'new'); // 'new' supersedes the old cards; pushToRobita adds a credential
$cardsNow = KeyCard::where('reservation_room_id', $stay->id)->where('status', 'active')->count();
$credsNow = RobitaService::activeForStay($stay)->count();
$check('a superseded card leaves a stale credential until the hook runs', $credsNow > $cardsNow, "cards {$cardsNow}, credentials {$credsNow}");
RobitaService::onCheckIn($stay->fresh());
$afterHook = RobitaService::activeForStay($stay)->count();
$check('the hook reconciles to one credential per live card', $afterHook === $cardsNow, "cards {$cardsNow}, credentials {$afterHook}");
Setting::updateOrCreate(['key' => 'lock_auto_issue'], ['value' => '0']);

// 3. extension reaches the lock
$stay->update(['departure_date' => $stay->departure_date->copy()->addDays(2)]);
$stay->reservation->update(['departure_date' => $stay->reservation->rooms()->max('departure_date')]);
$moved = RobitaService::onExtend($stay->fresh());
$fresh = RobitaService::activeForStay($stay)->orderByDesc('id')->first(); // the card push replaced the first one
$check('extension updates the credential expiry', $moved === 1 && $fresh->valid_until->greaterThan($expected), $fresh->valid_until->toDateTimeString());

// 4. room move: old door forgets, new door learns
$to = \App\Models\Room::where('hotel_id', Tenant::id())->where('id', '!=', $stay->room_id)->where('is_active', true)->orderBy('id')->first();
if ($to) {
    $device2 = RobitaService::registerDevice([
        'room_id' => $to->id, 'serial' => 'JRNY002',
        'name' => 'Journey test lock 2', 'device_type' => 'door_lock',
    ]);
    $fromRoom = $stay->room_id;
    $stay->update(['room_id' => $to->id]);
    $cancelled = RobitaService::onRoomMove($stay->fresh(), $fromRoom);
    $check('room move cancels the old room credential', $cancelled === 1);
    $onNew = RobitaCredential::where('reservation_room_id', $stay->id)->where('status', 'active')->where('robita_device_id', $device2->id)->exists();
    $check('room move provisions the new room', $onNew);
    $onOld = RobitaCredential::where('reservation_room_id', $stay->id)->where('status', 'active')->where('robita_device_id', $device->id)->exists();
    $check('nothing stays active on the old door', ! $onOld);
}

// 5. check-out revokes instead of trusting the expiry date
$revoked = RobitaService::onCheckOut($stay->fresh());
$left = RobitaCredential::where('reservation_room_id', $stay->id)->where('status', 'active')->count();
$check('check-out revokes every credential', $revoked >= 1 && $left === 0, "revoked {$revoked}, still active {$left}");

// 6. expiry sweep only touches credentials past their validity
$past = RobitaCredential::create([
    'hotel_id' => Tenant::id(), 'robita_device_id' => $device->id, 'credential_type' => 'card',
    'credential_id' => 'JRNY-PAST', 'credential_name' => 'Expired test', 'valid_from' => now()->subDays(3),
    'valid_until' => now()->subDay(), 'status' => 'active',
]);
$n = RobitaService::expireDue();
$check('expiry sweep expires the stale credential', $n >= 1 && $past->fresh()->status === 'expired', "swept {$n}");

// 7. a failed cloud push is visible and retryable, not silently "active"
$failed = RobitaCredential::create([
    'hotel_id' => Tenant::id(), 'robita_device_id' => $device->id, 'credential_type' => 'card',
    'credential_id' => 'JRNY-FAIL', 'credential_name' => 'Unsynced test', 'valid_from' => now(),
    'valid_until' => now()->addDay(), 'status' => 'active', 'sync_state' => 'pending', 'last_error' => 'timeout',
]);
$check('pending push is flagged for the UI', $failed->fresh()->needsSync());
$check('retry is a no-op in demo mode', RobitaService::retryPending() === 0);

DB::rollBack();
echo "RESULT {$pass} passed, {$fail} failed\n";
