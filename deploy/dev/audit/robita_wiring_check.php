<?php

/**
 * v7.57 – proves the Robita hooks fire from the *real* stay operations
 * (StayService::changeDates / moveRoom / checkOut), not just from the service
 * methods in isolation. Runs in a transaction and rolls back.
 */
use App\Models\Hotel;
use App\Models\KeyCard;
use App\Models\ReservationRoom;
use App\Models\RobitaCredential;
use App\Models\Setting;
use App\Support\Tenant;
use App\Services\InterfaceService;
use App\Services\RobitaService;
use App\Services\StayService;

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
// desk-encoded cards: nothing is issued automatically, which is the case the fix targets
Setting::updateOrCreate(['key' => 'lock_enabled'], ['value' => '1']);
Setting::updateOrCreate(['key' => 'lock_auto_issue'], ['value' => '0']);
echo 'lock cfg: enabled='.InterfaceService::cfg()['lock_enabled'].' auto_issue='.InterfaceService::cfg()['lock_auto_issue'].PHP_EOL;

DB::beginTransaction();

$stay = ReservationRoom::where('status', 'checked_in')->whereNotNull('room_id')->orderBy('id')->first();
$check('checked-in stay available', (bool) $stay);
if (! $stay) {
    DB::rollBack();
    echo "RESULT {$pass} passed, {$fail} failed\n";
    return;
}

$device = RobitaService::registerDevice(['room_id' => $stay->room_id, 'serial' => 'WIRE001', 'name' => 'Wiring lock']);
$card = KeyCard::create([
    'hotel_id' => Tenant::id(), 'card_no' => 'WIRE-C1', 'serial' => 'WIRE-SERIAL-1', 'type' => 'guest',
    'reservation_room_id' => $stay->id, 'room_id' => $stay->room_id, 'holder' => 'Wiring Guest',
    'valid_from' => now(), 'valid_until' => now()->addDay(), 'status' => 'active',
]);
$cred = RobitaService::onCheckIn($stay->fresh());
$check('baseline credential exists', (bool) $cred);
if (! $cred) {
    DB::rollBack();
    echo "RESULT {$pass} passed, {$fail} failed\n";
    return;
}

// 1. extending through the real amend path moves the door credential
$before = $cred->fresh()->valid_until->copy();
try {
    StayService::changeDates($stay->fresh(), $stay->arrival_date->toDateString(), $stay->departure_date->copy()->addDays(3)->toDateString());
    $after = $cred->fresh()->valid_until;
    $check('StayService::changeDates moves the credential expiry', $after->greaterThan($before), $before->toDateTimeString().' → '.$after->toDateTimeString());
} catch (\Throwable $e) {
    $check('StayService::changeDates moves the credential expiry', false, $e->getMessage());
}

// 2. room move through the real path cancels the old door and provisions the new one
$to = \App\Models\Room::where('hotel_id', Tenant::id())->where('id', '!=', $stay->room_id)->where('is_active', true)
    ->where('status', 'vacant')->orderBy('id')->first();
if ($to) {
    $device2 = RobitaService::registerDevice(['room_id' => $to->id, 'serial' => 'WIRE002', 'name' => 'Wiring lock 2']);
    try {
        StayService::moveRoom($stay->fresh(), $to->id, null, 'wiring check');
        $oldActive = RobitaCredential::where('reservation_room_id', $stay->id)->where('status', 'active')->where('robita_device_id', $device->id)->exists();
        $newActive = RobitaCredential::where('reservation_room_id', $stay->id)->where('status', 'active')->where('robita_device_id', $device2->id)->exists();
        $check('StayService::moveRoom cancels the old door', ! $oldActive);
        $check('StayService::moveRoom provisions the new door', $newActive);
    } catch (\Throwable $e) {
        $check('StayService::moveRoom cancels the old door', false, $e->getMessage());
        $check('StayService::moveRoom provisions the new door', false);
    }
}

// 3. checking out through the real path revokes the credential (the security fix)
try {
    StayService::postPendingNights($stay->fresh(), bdate());
    $folio = $stay->fresh()->folio()->first();
    if ($folio && abs($folio->balance()) > 0.009) {
        \App\Services\FolioService::payment($folio, $folio->balance(), \App\Models\PaymentMode::orderBy('id')->first()->id, 'wiring check settlement');
    }
    StayService::checkOut($stay->fresh(), true);
    $active = RobitaCredential::where('reservation_room_id', $stay->id)->where('status', 'active')->count();
    $check('StayService::checkOut revokes the door credential', $active === 0, "still active {$active}");
} catch (\Throwable $e) {
    $check('StayService::checkOut revokes the door credential', false, $e->getMessage());
}

// 4. the switches actually switch it off
Setting::updateOrCreate(['key' => 'robita_auto_revoke'], ['value' => '0']);
Setting::updateOrCreate(['key' => 'robita_auto_issue'], ['value' => '0']);
$stay2 = ReservationRoom::where('status', 'checked_in')->whereNotNull('room_id')->orderBy('id', 'desc')->first();
if ($stay2 && $stay2->id !== $stay->id) {
    $d3 = RobitaService::registerDevice(['room_id' => $stay2->room_id, 'serial' => 'WIRE003', 'name' => 'Wiring lock 3']);
    $c3 = KeyCard::create([
        'hotel_id' => Tenant::id(), 'card_no' => 'WIRE-C3', 'serial' => 'WIRE-SERIAL-3', 'type' => 'guest',
        'reservation_room_id' => $stay2->id, 'room_id' => $stay2->room_id, 'holder' => 'Wiring Guest 2',
        'valid_from' => now(), 'valid_until' => now()->addDay(), 'status' => 'active',
    ]);
    $check('auto-issue off → nothing is provisioned', RobitaService::onCheckIn($stay2->fresh()) === null);
    $manual = RobitaService::issueCredential($d3, $stay2->fresh(), $c3);
    $check('a manual credential still works', (bool) $manual);
    $check('auto-revoke off → check-out leaves it alone', RobitaService::onCheckOut($stay2->fresh()) === 0 && $manual->fresh()->status === 'active');
}

DB::rollBack();
echo "RESULT {$pass} passed, {$fail} failed\n";
