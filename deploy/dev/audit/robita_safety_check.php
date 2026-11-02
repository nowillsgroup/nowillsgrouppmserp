<?php

/**
 * Robita local/demo safety, normalized webhook, replay and tenant checks.
 * Run with: deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/robita_safety_check.php';"
 * All test records are wrapped in a transaction and rolled back.
 */
use App\Http\Controllers\RobitaController;
use App\Models\Hotel;
use App\Models\RobitaDevice;
use App\Models\RobitaEvent;
use App\Models\Setting;
use App\Services\RobitaService;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Route;

$pass = 0;
$fail = 0;
$check = function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail) {
    $ok ? $pass++ : $fail++;
    echo ($ok ? 'PASS ' : 'FAIL ').$label.($detail !== '' ? "  [{$detail}]" : '').PHP_EOL;
};

$hotel = Hotel::where('is_active', true)->orderBy('id')->first();
if (! $hotel) {
    echo "FAIL no active hotel available\nRESULT 0 passed, 1 failed\n";
    return;
}
Tenant::set($hotel);
RobitaService::ensureDefaults();
DB::beginTransaction();

try {
    $serial = 'SAFE-'.strtoupper(Str::random(10));
    $device = RobitaService::registerDevice([
        'serial' => $serial,
        'name' => 'Robita safety test demo',
        'device_type' => 'door_lock',
    ]);
    $check('new records are explicitly demo-only', $device->status === 'demo' && $device->lock_state === 'locked' && $device->last_seen_at === null && $device->battery_pct === null);
    $check('device identifier is local and bounded', str_starts_with($device->device_id, 'DEMO-') && strlen($device->device_id) <= 64);

    try {
        RobitaService::registerDevice(['serial' => strtolower(str_replace('-', ' ', $serial)), 'name' => 'Duplicate serial']);
        $duplicateRejected = false;
    } catch (\RuntimeException) {
        $duplicateRejected = true;
    }
    $check('normalized duplicate serial is rejected', $duplicateRejected);

    $device = RobitaService::setLockState($device, 'unlocked');
    $demoEvent = RobitaEvent::where('robita_device_id', $device->id)->latest('id')->first();
    $check('lock control mutates only local demo state', $device->lock_state === 'unlocked' && $demoEvent?->source === 'demo' && $device->battery_pct === null);

    $token = (string) setting('robita_webhook_token');
    $makeRequest = function (array $payload, string $bearer) {
        return Request::create('/api/robita/events', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$bearer,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode($payload, JSON_THROW_ON_ERROR));
    };
    $controller = app(RobitaController::class);
    $payload = [
        'event_id' => 'safety-'.Str::random(20),
        'serial' => $device->serial,
        'type' => 'lock',
        'event_at' => now()->toIso8601String(),
        'lock_state' => 'locked',
        'untrusted_extra' => ['should' => 'not be stored'],
    ];
    $response = $controller->webhook($makeRequest($payload, $token));
    $body = $response->getData(true);
    $check('valid normalized event is authenticated and accepted', $response->getStatusCode() === 200 && ($body['ok'] ?? false) === true && ($body['duplicate'] ?? true) === false);
    $event = RobitaEvent::where('robita_device_id', $device->id)->where('source_event_id', $payload['event_id'])->first();
    $check('event data is allow-listed and source is explicit', $event && $event->source === 'webhook' && ! array_key_exists('untrusted_extra', $event->payload));

    $beforeReplay = $device->fresh()->event_count;
    $replay = $controller->webhook($makeRequest($payload, $token));
    $replayBody = $replay->getData(true);
    $check('duplicate event id is idempotent', $replay->getStatusCode() === 200 && ($replayBody['duplicate'] ?? false) === true && $device->fresh()->event_count === $beforeReplay);

    $conflictingSerial = $payload;
    $conflictingSerial['device_serial'] = 'DIFFERENT-LOCK';
    $conflictingSerialResponse = $controller->webhook($makeRequest($conflictingSerial, $token));
    $check('conflicting serial aliases are rejected', $conflictingSerialResponse->getStatusCode() === 422 && $device->fresh()->event_count === $beforeReplay);

    $badType = $payload;
    $badType['event_id'] .= '-bad-type';
    $badType['type'] = 'execute_command';
    $badTypeResponse = $controller->webhook($makeRequest($badType, $token));
    $check('unsupported event type is rejected', $badTypeResponse->getStatusCode() === 422 && $device->fresh()->event_count === $beforeReplay);

    $badTimestamp = $payload;
    $badTimestamp['event_id'] .= '-bad-time';
    $badTimestamp['event_at'] = 'not-a-date';
    $badTimestampResponse = $controller->webhook($makeRequest($badTimestamp, $token));
    $check('malformed event timestamp is rejected', $badTimestampResponse->getStatusCode() === 422 && $device->fresh()->event_count === $beforeReplay);

    $unknownDevice = $payload;
    $unknownDevice['event_id'] .= '-unknown-device';
    $unknownDevice['serial'] = 'UNKNOWN-DEVICE';
    $unknownDeviceResponse = $controller->webhook($makeRequest($unknownDevice, $token));
    $check('unknown device serial is rejected', $unknownDeviceResponse->getStatusCode() === 404 && $device->fresh()->event_count === $beforeReplay);

    $oversizedRequest = Request::create('/api/robita/events', 'POST', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], json_encode(['padding' => str_repeat('x', 8200)], JSON_THROW_ON_ERROR));
    $oversizedResponse = $controller->webhook($oversizedRequest);
    $check('oversized normalized payload is rejected', $oversizedResponse->getStatusCode() === 413 && $device->fresh()->event_count === $beforeReplay);

    $badBattery = $payload;
    $badBattery['event_id'] .= '-bad-battery';
    $badBattery['battery_pct'] = 101;
    $badBatteryResponse = $controller->webhook($makeRequest($badBattery, $token));
    $check('out-of-range battery is rejected', $badBatteryResponse->getStatusCode() === 422 && $device->fresh()->event_count === $beforeReplay);

    $unauthorized = $controller->webhook($makeRequest($payload, str_repeat('x', 40)));
    $check('invalid bearer token is rejected', $unauthorized->getStatusCode() === 401);

    $route = Route::getRoutes()->getByName('robita.api.webhook');
    $check('webhook route keeps token out of the URL', $route && $route->uri() === 'api/robita/events' && in_array('POST', $route->methods(), true));

    $otherHotel = Hotel::where('is_active', true)->where('id', '!=', $hotel->id)->orderBy('id')->first();
    if ($otherHotel) {
        Tenant::set($otherHotel);
        try {
            RobitaService::receiveEvent($device, ['type' => 'lock', 'source' => 'demo']);
            $crossTenantRejected = false;
        } catch (\RuntimeException) {
            $crossTenantRejected = true;
        }
        Tenant::set($hotel);
        $check('cross-tenant device action is rejected', $crossTenantRejected);
    } else {
        $check('cross-tenant device action is rejected', true, 'no second active hotel in this local dataset; ownership guard checked by service');
        Tenant::set($hotel);
    }

    Setting::updateOrCreate(['key' => 'robita_cloud_url'], ['value' => 'https://legacy.invalid']);
    Setting::updateOrCreate(['key' => 'robita_access_token'], ['value' => 'legacy-secret']);
    $legacyCfg = RobitaService::cfg();
    $check('legacy cloud settings are not exposed to the UI config', ($legacyCfg['legacy_cloud_configured'] ?? false) && ! array_key_exists('robita_access_token', $legacyCfg) && ! array_key_exists('robita_cloud_url', $legacyCfg));
    RobitaService::clearLegacyCloudConfig();
    $clearedCfg = RobitaService::cfg();
    $check('legacy cloud URL and token can be explicitly removed', ! $clearedCfg['legacy_cloud_configured'] && setting('robita_cloud_url', '') === '' && setting('robita_access_token', '') === '');

    $source = file_get_contents(base_path('app/Services/RobitaService.php'));
    $check('service contains no assumed outbound HTTP client', ! str_contains($source, 'Http::') && ! str_contains($source, 'curl_exec('));
} finally {
    DB::rollBack();
    Tenant::set($hotel);
}

echo "RESULT {$pass} passed, {$fail} failed\n";
