<?php

namespace App\Services;

use App\Models\RobitaDevice;
use App\Models\RobitaEvent;
use App\Models\RobitaCredential;
use App\Models\ReservationRoom;
use App\Models\KeyCard;
use App\Support\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Local Robita guest-journey workflow and normalized event intake.
 *
 * There is intentionally no outbound Robita cloud client here: no vendor API
 * specification or hardware acceptance has been supplied. Device, lock-state
 * and credential actions are local simulations; the inbound webhook accepts
 * only the documented normalized event schema. A vendor adapter must be added
 * and accepted against real hardware before these records can represent locks.
 */
class RobitaService
{
    public const DEFAULTS = [
        'robita_webhook_token' => '',
        'robita_default_model' => 'Robita S1',
        'robita_battery_alert' => '20',
        'robita_auto_issue' => '1',
        'robita_auto_revoke' => '1',
    ];

    public static function ensureDefaults(): void
    {
        foreach (self::DEFAULTS as $k => $v) {
            \App\Models\Setting::firstOrCreate(['key' => $k], ['value' => $v]);
        }
        if (! setting('robita_webhook_token')) {
            \App\Models\Setting::updateOrCreate(['key' => 'robita_webhook_token'], ['value' => \Illuminate\Support\Str::random(40)]);
        }
        \App\Models\Setting::$version++;
    }

    public static function cfg(): array
    {
        $s = [];
        foreach (self::DEFAULTS as $k => $v) {
            $s[$k] = (string) setting($k, $v);
        }
        $s['robita_battery_alert'] = max(0, min(100, (int) $s['robita_battery_alert']));
        $s['robita_auto_issue'] = $s['robita_auto_issue'] === '1' ? '1' : '0';
        $s['robita_auto_revoke'] = $s['robita_auto_revoke'] === '1' ? '1' : '0';
        $s['demo_mode'] = true;
        $s['legacy_cloud_configured'] = trim((string) setting('robita_cloud_url', '')) !== '';

        return $s;
    }

    public static function saveSettings(array $d): void
    {
        if (trim((string) ($d['robita_cloud_url'] ?? '')) !== '' || trim((string) ($d['robita_access_token'] ?? '')) !== '') {
            throw new RuntimeException('Vendor cloud commands are disabled until Robita protocol documentation and hardware acceptance are available. No request was sent.');
        }

        $values = [
            'robita_default_model' => mb_substr(trim((string) ($d['robita_default_model'] ?? 'Robita S1')) ?: 'Robita S1', 0, 40),
            'robita_battery_alert' => (string) max(0, min(100, (int) ($d['robita_battery_alert'] ?? 20))),
            'robita_auto_issue' => ! empty($d['robita_auto_issue']) ? '1' : '0',
            'robita_auto_revoke' => ! empty($d['robita_auto_revoke']) ? '1' : '0',
        ];
        foreach ($values as $k => $v) {
            \App\Models\Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        \App\Models\Setting::$version++;
        ActivityLogger::log('robita', 'settings', 'Robita local workflow settings updated');
    }

    public static function clearLegacyCloudConfig(): void
    {
        if (! Tenant::check()) {
            throw new RuntimeException('An active hotel context is required.');
        }
        \App\Models\Setting::whereIn('key', ['robita_cloud_url', 'robita_access_token'])->get()->each->delete();
        \App\Models\Setting::$version++;
        ActivityLogger::log('robita', 'legacy_config_removed', 'Ignored legacy cloud URL and token removed; no vendor request was sent');
    }

    public static function regenerateTokens(): array
    {
        $webhookToken = \Illuminate\Support\Str::random(40);
        \App\Models\Setting::updateOrCreate(['key' => 'robita_webhook_token'], ['value' => $webhookToken]);
        \App\Models\Setting::$version++;
        ActivityLogger::log('robita', 'token', 'Robita normalized-event webhook token regenerated');

        return ['webhook_token' => $webhookToken];
    }

    /** Register a local simulated lock record. No vendor device is contacted. */
    public static function registerDevice(array $data): RobitaDevice
    {
        if (! Tenant::check()) {
            throw new RuntimeException('An active hotel context is required to register a demo record.');
        }
        $deviceType = (string) ($data['device_type'] ?? 'door_lock');
        if (! in_array($deviceType, RobitaDevice::DEVICE_TYPES, true)) {
            throw new RuntimeException('Unsupported demo device type.');
        }
        $roomId = $data['room_id'] ?? null;
        if ($roomId !== null && ! \App\Models\Room::where('hotel_id', Tenant::id())->whereKey($roomId)->exists()) {
            throw new RuntimeException('Room mapping must belong to the active hotel.');
        }
        $serial = self::normalizeSerial((string) ($data['serial'] ?? ''));
        if ($serial === '') {
            throw new RuntimeException('A device serial is required.');
        }
        if (RobitaDevice::withoutGlobalScope('hotel')->where('serial', $serial)->exists()) {
            throw new RuntimeException('A demo lock record with that normalized serial already exists.');
        }
        $device = new RobitaDevice;
        $device->fill([
            'hotel_id' => Tenant::id(),
            'device_id' => 'DEMO-'.substr(hash('sha256', (string) Tenant::id().':'.$serial), 0, 40),
            'serial' => $serial,
            'name' => mb_substr($data['name'] ?? 'Unnamed device', 0, 80),
            'model' => mb_substr(trim((string) ($data['model'] ?? '')) ?: self::cfg()['robita_default_model'], 0, 40),
            'room_id' => $roomId,
            'floor' => $data['floor'] ?? null,
            'location' => $data['location'] ?? null,
            'device_type' => $deviceType,
            'status' => 'demo',
            'lock_state' => 'locked',
            'notes' => $data['notes'] ?? null,
        ]);
        $device->hotel_id = Tenant::id();
        try {
            $device->save();
        } catch (QueryException $e) {
            throw new RuntimeException('A demo lock record with that serial already exists.', 0, $e);
        }
        ActivityLogger::log('robita', 'register_demo', "Demo lock record {$device->name} ({$device->serial}) created; no hardware was contacted", $device);

        return $device;
    }

    /** Normalize serials from PMS records and the documented bridge payload. */
    public static function normalizeSerial(string $serial): string
    {
        return mb_substr(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($serial))), 0, 64);
    }

    /** Store a local simulated card credential for a checked-in room. */
    public static function issueCredential(RobitaDevice $device, ReservationRoom $stay, ?KeyCard $card = null, array $areas = []): RobitaCredential
    {
        // $areas comes from the key-card interface; this module stores identity only.
        $hotelId = $stay->hotel_id ?? Tenant::id();
        if (! Tenant::check()
            || (int) $hotelId !== (int) Tenant::id()
            || (int) $device->hotel_id !== (int) $hotelId
            || ($card && (int) $card->hotel_id !== (int) $hotelId)) {
            throw new RuntimeException('The device, stay, and card must belong to the same hotel.');
        }
        if ($device->status === 'decommissioned') {
            throw new RuntimeException('Cannot create a credential for a decommissioned demo record.');
        }
        if ($stay->status !== 'checked_in') {
            throw new RuntimeException('A local lock credential can only be created for a checked-in stay.');
        }
        if ($device->device_type === 'door_lock' && (int) $device->room_id !== (int) $stay->room_id) {
            throw new RuntimeException('The room door record must be mapped to this stay’s room.');
        }
        $card ??= KeyCard::where('reservation_room_id', $stay->id)->where('status', 'active')->latest('id')->first();
        if (! $card || (int) $card->reservation_room_id !== (int) $stay->id || $card->status !== 'active') {
            throw new RuntimeException('No active key card for this stay. Encode a card first.');
        }
        if ($card->room_id && (int) $card->room_id !== (int) $stay->room_id) {
            throw new RuntimeException('The active key card is not mapped to this stay’s room.');
        }
        $credential = DB::transaction(function () use ($device, $stay, $card, $hotelId) {
            $lockedDevice = RobitaDevice::whereKey($device->id)->lockForUpdate()->firstOrFail();
            if ((int) $lockedDevice->hotel_id !== (int) $hotelId || $lockedDevice->status === 'decommissioned'
                || ($lockedDevice->device_type === 'door_lock' && (int) $lockedDevice->room_id !== (int) $stay->room_id)) {
                throw new RuntimeException('The mapped demo device changed or is no longer available.');
            }
            $validUntil = KeyCardService::validUntil($stay->fresh());
            $existing = RobitaCredential::where('robita_device_id', $lockedDevice->id)
                ->where('reservation_room_id', $stay->id)
                ->where('key_card_id', $card->id)
                ->where('status', 'active')->lockForUpdate()->latest('id')->first();
            if ($existing) {
                $existing->forceFill(['valid_until' => $validUntil])->save();
                self::recordDemoSync($existing);
                $lockedDevice->update(['last_event_at' => now()]);

                return $existing;
            }

            $newCredential = new RobitaCredential;
            $newCredential->fill([
                'robita_device_id' => $lockedDevice->id,
                'credential_type' => 'card',
                'credential_id' => $card->serial,
                'credential_name' => $card->holder,
                'reservation_room_id' => $stay->id,
                'key_card_id' => $card->id,
                'card_serial' => $card->serial,
                'valid_from' => now(),
                'valid_until' => $validUntil,
                'status' => 'active',
            ]);
            $newCredential->hotel_id = $hotelId;
            $newCredential->save();
            self::recordDemoSync($newCredential);
            $lockedDevice->update(['last_event_at' => now()]);

            return $newCredential;
        });
        ActivityLogger::log('robita', 'credential_demo', "Local demo credential record for card {$card->card_no} maintained on {$device->name}; not sent to hardware", $device);

        return $credential;
    }

    /**
     * Cancel a credential (lost card, check-out, room move, expiry).
     * $status lets an expiry be recorded as `expired` rather than `cancelled`.
     */
    public static function cancelCredential(RobitaCredential $credential, ?string $reason = null, string $status = 'cancelled'): void
    {
        if (Tenant::check() && (int) $credential->hotel_id !== (int) Tenant::id()) {
            throw new RuntimeException('Credential does not belong to the active hotel.');
        }
        $status = in_array($status, RobitaCredential::STATUSES, true) ? $status : 'cancelled';
        $credential->forceFill(['status' => $status])->save();
        self::recordDemoSync($credential);

        $device = $credential->device;
        if ($device) {
            $device->update(['last_event_at' => now()]);
        }
        $why = $reason ? " ({$reason})" : '';
        ActivityLogger::log('robita', 'credential_demo_cancel', "Local credential {$credential->credential_id} marked {$status} on {$device?->name}; no hardware command sent{$why}", $device);
    }

    /** Record local demo completion without claiming hardware synchronization. */
    private static function recordDemoSync(RobitaCredential $credential): bool
    {
        $legacyUnverified = in_array($credential->sync_state, ['unverified', 'pending', 'failed'], true);
        $credential->forceFill([
            'sync_state' => $legacyUnverified ? 'unverified' : 'simulated',
            'synced_at' => null,
            'last_error' => null,
        ])->save();

        return true;
    }

    // ───────────────────── v7.57 guest journey (check-in → check-out) ─────────────────────
    //
    // These hooks maintain local simulated credential records throughout a stay.
    // They do not send credentials to a physical lock; a verified vendor adapter
    // remains a separate, blocked acceptance item.

    /** The local demo door record mapped to the stay's room, if present. */
    public static function deviceForStay(ReservationRoom $stay): ?RobitaDevice
    {
        if (! $stay->room_id) {
            return null;
        }

        return RobitaDevice::where('hotel_id', $stay->hotel_id ?? Tenant::id())
            ->where('room_id', $stay->room_id)
            ->where('device_type', 'door_lock')
            ->where('status', '!=', 'decommissioned')
            ->orderBy('id')
            ->first();
    }

    /** Every still-active credential issued for a stay. */
    public static function activeForStay(ReservationRoom $stay)
    {
        return RobitaCredential::where('reservation_room_id', $stay->id)->where('status', 'active');
    }

    /**
     * Check-in: maintain one local credential record for each active card of the stay.
     * Re-runs are idempotent; stale cards and records for the previous room mapping
     * are cancelled. This does not transmit credentials or open a physical door.
     *
     * @return RobitaCredential|null the record for the newest active card
     */
    public static function onCheckIn(ReservationRoom $stay): ?RobitaCredential
    {
        if (self::cfg()['robita_auto_issue'] !== '1') {
            return null;
        }
        $device = self::deviceForStay($stay);
        if (! $device) {
            return null; // no mapped room-door demo record
        }
        $cards = KeyCard::where('reservation_room_id', $stay->id)->where('status', 'active')->orderBy('id')->get();
        if ($cards->isEmpty()) {
            return null; // cards are issued by the key-card interface; nothing to encode here
        }

        $active = self::activeForStay($stay)->get();
        foreach ($active as $credential) {
            $cardStillValid = $cards->contains(fn ($c) => (int) $c->id === (int) $credential->key_card_id);
            if (! $cardStillValid || (int) $credential->robita_device_id !== (int) $device->id) {
                self::cancelCredential($credential, $cardStillValid ? 'room changed' : 'card no longer active');
            }
        }

        $newest = null;
        foreach ($cards as $card) {
            $existing = self::activeForStay($stay)->where('robita_device_id', $device->id)
                ->where('key_card_id', $card->id)->latest('id')->first();
            $newest = $existing ?: self::issueCredential($device, $stay, $card);
        }

        return $newest;
    }

    /** Check-out: cancel the stay's local simulated credentials. */
    public static function onCheckOut(ReservationRoom $stay): int
    {
        if (self::cfg()['robita_auto_revoke'] !== '1') {
            return 0;
        }

        return self::cancelForStay($stay, 'checked out');
    }

    /** Cancel every active credential of a stay (check-out, no-show, cancellation). */
    public static function cancelForStay(ReservationRoom $stay, string $reason = 'stay ended'): int
    {
        $n = 0;
        foreach (self::activeForStay($stay)->get() as $credential) {
            self::cancelCredential($credential, $reason);
            $n++;
        }

        return $n;
    }

    /**
     * Room move: cancel the old room's local record and create one for the new mapping.
     */
    public static function onRoomMove(ReservationRoom $stay, ?int $fromRoomId): int
    {
        if (self::cfg()['robita_auto_revoke'] !== '1') {
            return 0;
        }
        $device = self::deviceForStay($stay);
        $n = 0;
        foreach (self::activeForStay($stay)->get() as $credential) {
            if (! $device || (int) $credential->robita_device_id !== (int) $device->id) {
                self::cancelCredential($credential, 'room move');
                $n++;
            }
        }
        // the new room's local device mapping needs its own credential record
        if (self::cfg()['robita_auto_issue'] === '1') {
            try {
                self::onCheckIn($stay->fresh());
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $n;
    }

    /**
     * Extension: update the local expiry record to match the revised departure.
     */
    public static function onExtend(ReservationRoom $stay): int
    {
        if (self::cfg()['robita_auto_issue'] !== '1') {
            return 0;
        }
        $until = KeyCardService::validUntil($stay->fresh());
        $n = 0;
        foreach (self::activeForStay($stay)->get() as $credential) {
            if ($credential->valid_until && $credential->valid_until->equalTo($until)) {
                continue;
            }
            $credential->forceFill(['valid_until' => $until])->save();
            self::recordDemoSync($credential); // local update only; no vendor post
            $n++;
        }

        return $n;
    }

    /**
     * Sweep: local credentials past their validity are expired — run from
     * `pms:interfaces`, alongside key-card expiry.
     */
    public static function expireDue(): int
    {
        $n = 0;
        foreach (RobitaCredential::where('status', 'active')->whereNotNull('valid_until')
            ->where('valid_until', '<', now())->orderBy('id')->limit(500)->get() as $credential) {
            self::cancelCredential($credential, 'validity ended', 'expired');
            $n++;
        }

        return $n;
    }

    /** No automatic retries are sent: no vendor command protocol is installed. */
    public static function retryPending(int $limit = 20): int
    {
        return 0;
    }

    /** Simulate a lock/unlock action locally; this never actuates hardware. */
    public static function setLockState(RobitaDevice $device, string $state): RobitaDevice
    {
        if ($device->status === 'decommissioned') {
            throw new RuntimeException('This lock record is decommissioned.');
        }
        if (! in_array($state, ['locked', 'unlocked'], true)) {
            throw new RuntimeException('Choose locked or unlocked.');
        }

        self::receiveEvent($device, [
            'type' => $state === 'locked' ? 'lock' : 'unlock',
            'method' => 'remote',
            'user_name' => 'PMS demo simulation',
            'lock_state' => $state,
            'source' => 'demo',
        ]);
        ActivityLogger::log('robita', 'lock_simulation', "Demo lock state changed to {$state}; no hardware was contacted", $device);

        return $device->fresh();
    }

    /** Ingest an allow-listed, normalized event from a future bridge or local demo action. */
    public static function receiveEvent(RobitaDevice $device, array $data): RobitaEvent
    {
        if (! Tenant::check() || (int) $device->hotel_id !== (int) Tenant::id()) {
            throw new RuntimeException('Device does not belong to the active hotel.');
        }
        if ($device->status === 'decommissioned') {
            throw new RuntimeException('Cannot record events against a decommissioned demo record.');
        }
        $eventType = (string) ($data['type'] ?? '');
        if (! array_key_exists($eventType, RobitaEvent::TYPES)) {
            throw new RuntimeException('Unsupported normalized lock event type.');
        }
        $method = $data['method'] ?? null;
        if ($method !== null && ! in_array($method, RobitaEvent::METHODS, true)) {
            throw new RuntimeException('Unsupported lock event method.');
        }
        $battery = isset($data['battery_pct']) ? filter_var($data['battery_pct'], FILTER_VALIDATE_INT) : null;
        if (isset($data['battery_pct']) && ($battery === false || $battery < 0 || $battery > 100)) {
            throw new RuntimeException('Battery percentage must be an integer from 0 to 100.');
        }
        $signal = $data['signal'] ?? null;
        if ($signal !== null && ! in_array($signal, RobitaDevice::SIGNALS, true)) {
            throw new RuntimeException('Unsupported lock signal value.');
        }
        $lockState = $data['lock_state'] ?? null;
        if ($lockState !== null && ! in_array($lockState, RobitaDevice::LOCK_STATES, true)) {
            throw new RuntimeException('Unsupported lock state value.');
        }
        $source = ($data['source'] ?? 'webhook') === 'demo' ? 'demo' : 'webhook';
        $sourceEventId = trim((string) ($data['event_id'] ?? ''));
        if ($source === 'webhook' && $sourceEventId === '') {
            throw new RuntimeException('A normalized webhook event_id is required.');
        }
        if (($source === 'webhook' && ! preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/D', $sourceEventId))
            || ($sourceEventId !== '' && ! preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/D', $sourceEventId))) {
            throw new RuntimeException('Normalized event_id must be 1–128 safe identifier characters.');
        }
        if ($sourceEventId !== '') {
            $existing = RobitaEvent::where('robita_device_id', $device->id)->where('source_event_id', $sourceEventId)->first();
            if ($existing) {
                return $existing;
            }
        }
        try {
            $eventAt = isset($data['event_at']) ? Carbon::parse($data['event_at']) : now();
        } catch (\Throwable $e) {
            throw new RuntimeException('Invalid normalized event timestamp.', 0, $e);
        }
        $serial = self::normalizeSerial((string) ($data['card_serial'] ?? ''));
        $safePayload = array_intersect_key($data, array_flip([
            'type', 'method', 'event_at', 'event_id', 'battery_pct', 'signal', 'lock_state', 'alarm_type', 'source',
        ]));
        $room = $device->room;
        $stay = $room ? ReservationRoom::where('room_id', $room->id)->where('status', 'checked_in')->latest('id')->first() : null;
        $card = ($serial !== '' && $stay)
            ? KeyCard::where('serial', $serial)->where('reservation_room_id', $stay->id)->where('status', 'active')->latest('id')->first()
            : null;
        if (! $card && $stay) {
            $card = KeyCard::where('reservation_room_id', $stay->id)->where('status', 'active')->latest('id')->first();
        }

        $event = new RobitaEvent;
        $event->fill([
            'hotel_id' => $device->hotel_id,
            'robita_device_id' => $device->id,
            'room_id' => $room?->id,
            'reservation_room_id' => $stay?->id,
            'key_card_id' => $card?->id,
            'event_type' => $eventType,
            'source_event_id' => $sourceEventId !== '' ? $sourceEventId : null,
            'method' => $method,
            'user_name' => mb_substr(trim((string) ($data['user_name'] ?? $card?->holder ?? '')), 0, 80) ?: null,
            'user_id_ext' => mb_substr(trim((string) ($data['user_id'] ?? '')), 0, 64) ?: null,
            'card_serial' => $serial !== '' ? $serial : $card?->serial,
            'card_no' => $card?->card_no,
            'payload' => $safePayload,
            'is_alarm' => in_array($eventType, ['alarm', 'low_battery', 'tamper', 'forced_entry', 'door_ajar'], true),
            'alarm_type' => mb_substr(trim((string) ($data['alarm_type'] ?? '')), 0, 40) ?: null,
            'event_at' => $eventAt,
            'received_at' => now(),
            'source' => $source,
        ]);
        $event->hotel_id = $device->hotel_id;

        try {
            $deviceId = (int) $device->id;
            $event = DB::transaction(function () use ($battery, $deviceId, $event, $eventType, $lockState, $signal, $sourceEventId) {
                $lockedDevice = RobitaDevice::whereKey($deviceId)->lockForUpdate()->firstOrFail();
                if ($sourceEventId !== '') {
                    $existing = RobitaEvent::where('robita_device_id', $deviceId)->where('source_event_id', $sourceEventId)->first();
                    if ($existing) {
                        return $existing;
                    }
                }
                if ($battery !== null && $battery !== false) {
                    $lockedDevice->battery_pct = $battery;
                }
                if ($signal !== null) {
                    $lockedDevice->signal = $signal;
                }
                if ($lockState !== null) {
                    $lockedDevice->lock_state = $lockState;
                }
                $lockedDevice->last_seen_at = now();
                $lockedDevice->last_event_at = now();
                $lockedDevice->event_count = (int) $lockedDevice->event_count + 1;
                if (in_array($eventType, ['unlock', 'remote_unlock'], true)) {
                    $lockedDevice->unlock_count = (int) $lockedDevice->unlock_count + 1;
                }
                $event->save();
                $lockedDevice->save();

                return $event;
            });
        } catch (QueryException $e) {
            if ($sourceEventId !== '') {
                $duplicate = RobitaEvent::where('robita_device_id', $device->id)->where('source_event_id', $sourceEventId)->first();
                if ($duplicate) {
                    return $duplicate;
                }
            }
            throw $e;
        }
        if (! $event->wasRecentlyCreated) {
            return $event;
        }

        if ($event->isAlarm()) {
            try {
                $roomNo = $device->room?->room_no ?? '—';
                $alarm = $event->alarm_type ?? $event->event_type;
                LogBookService::system(
                    "Lock alert: {$event->event_type} on {$device->name}",
                    "Room {$roomNo} – {$alarm}. Check the device.",
                    'security',
                    'urgent',
                    ['department' => 'security'],
                    true
                );
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $event;
    }

    /** Hardware polling is deliberately disabled until a verified vendor adapter exists. */
    public static function pollDevices(): int
    {
        return 0;
    }

    public static function counts(): array
    {
        return [
            'robita_devices_online' => 0,
            'robita_devices_offline' => 0,
            'robita_devices_demo' => RobitaDevice::where('hotel_id', Tenant::id())->where('status', 'demo')->count(),
            'robita_devices_locked' => RobitaDevice::where('hotel_id', Tenant::id())->where('lock_state', 'locked')->where('status', '!=', 'decommissioned')->count(),
            'robita_devices_total' => RobitaDevice::where('hotel_id', Tenant::id())->where('status', '!=', 'decommissioned')->count(),
            'robita_credentials_active' => RobitaCredential::where('hotel_id', Tenant::id())->where('status', 'active')->count(),
            'robita_events_unlock' => RobitaEvent::where('hotel_id', Tenant::id())->whereIn('event_type', ['unlock', 'remote_unlock'])->whereDate('event_at', bdate()->toDateString())->count(),
            'robita_events_alarm' => RobitaEvent::where('hotel_id', Tenant::id())->where('is_alarm', true)->whereDate('received_at', bdate()->toDateString())->count(),
            'robita_low_battery' => RobitaDevice::where('hotel_id', Tenant::id())->where('battery_pct', '<', (int) self::cfg()['robita_battery_alert'])->whereNotNull('battery_pct')->where('status', '!=', 'decommissioned')->count(),
            'robita_credentials_pending' => RobitaCredential::where('hotel_id', Tenant::id())->whereIn('sync_state', ['pending', 'failed'])->count(),
        ];
    }
}
