<?php

namespace App\Services;

use App\Models\RobitaDevice;
use App\Models\RobitaEvent;
use App\Models\RobitaCredential;
use App\Models\ReservationRoom;
use App\Models\KeyCard;
use App\Support\Tenant;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * v7.55 – Robita smart door lock driver.
 *
 * Robita locks are cloud-connected smart locks. Each device has a cloud device ID and
 * communicates through a REST API. The PMS acts as the cloud controller:
 *
 *   • Device registration – add a Robita device, map it to a room or common area
 *   • Credential management – issue/cancel card, password, or fingerprint credentials
 *   • Remote lock/unlock – control door state from the PMS
 *   • Event receiving – webhook pushes access events (unlock, alarm, battery, etc.)
 *   • Health monitoring – battery level, signal strength, online/offline status
 *
 * The API base URL, access token, and per-device API keys are configurable in Settings.
 * When `robita_cloud_url` is empty, the demo driver acknowledges commands locally.
 */
class RobitaService
{
    public const DEFAULTS = [
        'robita_cloud_url' => '',                    // e.g. https://api.robita.example.com
        'robita_access_token' => '',                 // cloud access token (generated)
        'robita_auto_register' => '0',               // auto-register new devices on credential issue
        'robita_poll_minutes' => '5',                // event polling interval (0 = webhook only)
        'robita_webhook_token' => '',                // webhook push token (generated)
        'robita_default_model' => 'Robita S1',
        'robita_battery_alert' => '20',              // battery % below which an alert fires
        'robita_auto_issue' => '1',                  // v7.57: issue the door credential automatically at check-in
        'robita_auto_revoke' => '1',                 // v7.57: cancel it at check-out / room move / expiry
    ];

    public static function ensureDefaults(): void
    {
        foreach (self::DEFAULTS as $k => $v) {
            \App\Models\Setting::firstOrCreate(['key' => $k], ['value' => $v]);
        }
        if (! setting('robita_access_token')) {
            \App\Models\Setting::updateOrCreate(['key' => 'robita_access_token'], ['value' => \Illuminate\Support\Str::random(40)]);
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
        $s['robita_poll_minutes'] = max(0, (int) $s['robita_poll_minutes']);
        $s['robita_battery_alert'] = max(0, min(100, (int) $s['robita_battery_alert']));
        $s['robita_auto_register'] = (int) $s['robita_auto_register'] > 0 ? '1' : '0';
        $s['robita_auto_issue'] = $s['robita_auto_issue'] === '1' ? '1' : '0';
        $s['robita_auto_revoke'] = $s['robita_auto_revoke'] === '1' ? '1' : '0';

        return $s;
    }

    public static function saveSettings(array $d): void
    {
        $values = [
            'robita_cloud_url' => rtrim(mb_substr(trim((string) ($d['robita_cloud_url'] ?? '')), 0, 190), '/'),
            'robita_access_token' => mb_substr(trim((string) ($d['robita_access_token'] ?? '')), 0, 128),
            'robita_auto_register' => ! empty($d['robita_auto_register']) ? '1' : '0',
            'robita_poll_minutes' => (string) max(0, min(60, (int) ($d['robita_poll_minutes'] ?? 5))),
            'robita_webhook_token' => mb_substr(trim((string) ($d['robita_webhook_token'] ?? '')), 0, 128),
            'robita_default_model' => mb_substr(trim((string) ($d['robita_default_model'] ?? 'Robita S1')), 0, 40),
            'robita_battery_alert' => (string) max(0, min(100, (int) ($d['robita_battery_alert'] ?? 20))),
            'robita_auto_issue' => ! empty($d['robita_auto_issue']) ? '1' : '0',
            'robita_auto_revoke' => ! empty($d['robita_auto_revoke']) ? '1' : '0',
        ];
        foreach ($values as $k => $v) {
            \App\Models\Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        \App\Models\Setting::$version++;
        ActivityLogger::log('robita', 'settings', 'Robita lock settings updated');
    }

    public static function regenerateTokens(): array
    {
        $accessToken = \Illuminate\Support\Str::random(40);
        $webhookToken = \Illuminate\Support\Str::random(40);
        \App\Models\Setting::updateOrCreate(['key' => 'robita_access_token'], ['value' => $accessToken]);
        \App\Models\Setting::updateOrCreate(['key' => 'robita_webhook_token'], ['value' => $webhookToken]);
        \App\Models\Setting::$version++;
        ActivityLogger::log('robita', 'token', 'Robita access and webhook tokens regenerated');

        return ['access_token' => $accessToken, 'webhook_token' => $webhookToken];
    }

    /**
     * Register a new Robita device. If the cloud is configured, the device is
     * provisioned on the Robita cloud; otherwise it's registered locally only.
     */
    public static function registerDevice(array $data): RobitaDevice
    {
        $c = self::cfg();
        $device = new RobitaDevice;
        $device->fill([
            'hotel_id' => Tenant::id(),
            'device_id' => $data['device_id'] ?? '',
            'serial' => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $data['serial'] ?? '')),
            'name' => mb_substr($data['name'] ?? 'Unnamed device', 0, 80),
            'model' => $data['model'] ?? $c['robita_default_model'],
            'room_id' => $data['room_id'] ?? null,
            'floor' => $data['floor'] ?? null,
            'location' => $data['location'] ?? null,
            'device_type' => $data['device_type'] ?? 'door_lock',
            'status' => 'provisioning',
            'lock_state' => 'unknown',
            'notes' => $data['notes'] ?? null,
        ]);

        if ($c['robita_cloud_url'] !== '') {
            try {
                $resp = Http::withHeaders([
                    'Authorization' => 'Bearer '.$c['robita_access_token'],
                    'Content-Type' => 'application/json',
                ])->post($c['robita_cloud_url'].'/v1/devices', [
                    'serial' => $device->serial,
                    'name' => $device->name,
                    'model' => $device->model,
                    'location' => $device->location,
                    'floor' => $device->floor,
                ])->timeout(10);

                if ($resp->ok()) {
                    $device->device_id = $resp->json('device_id', $resp->json('id', ''));
                    $device->status = 'online';
                    $device->api_key = $resp->json('api_key', $resp->json('access_token', ''));
                    $device->firmware = $resp->json('firmware', null);
                } else {
                    $device->status = 'online';
                    $device->device_id = $device->serial;
                }
            } catch (\Throwable $e) {
                report($e);
                $device->status = 'online';
                $device->device_id = $device->serial;
            }
        } else {
            // demo mode: device is online, no cloud
            $device->status = 'online';
            $device->device_id = $device->serial;
            $device->lock_state = 'locked';
            $device->battery_pct = 100;
            $device->signal = 'excellent';
            $device->last_seen_at = now();
        }

        $device->save();
        ActivityLogger::log('robita', 'register', "Robita device {$device->name} ({$device->serial}) registered", $device);

        return $device;
    }

    /** Issue a card credential to a Robita device for a stay. */
    public static function issueCredential(RobitaDevice $device, ReservationRoom $stay, ?KeyCard $card = null, array $areas = []): RobitaCredential
    {
        // $areas comes from the key-card interface; the access areas themselves
        // are encoded on the card, so the credential only needs its identity.
        $card ??= KeyCard::where('reservation_room_id', $stay->id)->where('status', 'active')->latest('id')->first();
        if (! $card) {
            throw new RuntimeException('No active key card for this stay. Encode a card first.');
        }
        $validUntil = KeyCardService::validUntil($stay);

        $credential = new RobitaCredential;
        $credential->fill([
            'hotel_id' => Tenant::id(),
            'robita_device_id' => $device->id,
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

        $credential->save();
        self::syncToCloud($credential);
        $device->update(['last_event_at' => now()]);
        ActivityLogger::log('robita', 'credential_issue', "Card {$card->card_no} issued to {$device->name} for room {$stay->room?->room_no}", $device);

        return $credential;
    }

    /**
     * Cancel a credential (lost card, check-out, room move, expiry).
     * $status lets an expiry be recorded as `expired` rather than `cancelled`.
     */
    public static function cancelCredential(RobitaCredential $credential, ?string $reason = null, string $status = 'cancelled'): void
    {
        $status = in_array($status, RobitaCredential::STATUSES, true) ? $status : 'cancelled';
        $credential->forceFill(['status' => $status])->save();
        self::syncToCloud($credential);

        $device = $credential->device;
        if ($device) {
            $device->update(['last_event_at' => now()]);
        }
        $why = $reason ? " ({$reason})" : '';
        ActivityLogger::log('robita', 'credential_cancel', "Credential {$credential->credential_id} {$status} on {$device?->name}{$why}", $device);
    }

    /**
     * Push one credential to the Robita cloud and record the outcome.
     *
     * Demo mode (no cloud URL) counts as synced — there is nothing to call.
     * A failure is not fatal: the row is left `pending` so retryPending() can
     * try again, and the UI shows it instead of pretending the door has it.
     */
    private static function syncToCloud(RobitaCredential $credential): bool
    {
        $c = self::cfg();
        $attempts = (int) $credential->sync_attempts + 1;

        if ($c['robita_cloud_url'] === '') {
            $credential->forceFill(['sync_state' => 'ok', 'synced_at' => now(), 'last_error' => null, 'sync_attempts' => $attempts])->save();

            return true;
        }

        $device = $credential->device;
        if (! $device) {
            $credential->forceFill(['sync_state' => 'failed', 'sync_attempts' => $attempts, 'last_error' => 'No device for this credential'])->save();

            return false;
        }

        $url = $c['robita_cloud_url'].'/v1/devices/'.$device->device_id.'/credentials';
        $headers = ['Authorization' => 'Bearer '.$c['robita_access_token'], 'Content-Type' => 'application/json'];

        try {
            if ($credential->status === 'active') {
                // POST is an upsert: the same credential_id re-posted with a new
                // valid_until is how an extension reaches the lock.
                $resp = Http::withHeaders($headers)->post($url, [
                    'type' => $credential->credential_type,
                    'credential_id' => $credential->credential_id,
                    'name' => $credential->credential_name,
                    'valid_from' => $credential->valid_from?->toDateTimeString(),
                    'valid_until' => $credential->valid_until?->toDateTimeString(),
                ])->timeout(10);
            } else {
                $resp = Http::withHeaders($headers)->delete($url.'/'.$credential->credential_id)->timeout(10);
            }

            // 404/410 on a delete means the cloud already forgot it — that is a success for us.
            if (! $resp->ok() && ! in_array($resp->status(), [404, 410], true)) {
                throw new RuntimeException('Robita cloud returned HTTP '.$resp->status());
            }
            if ($credential->status === 'active' && ($id = $resp->json('credential_id'))) {
                $credential->credential_id = $id;
            }
            $credential->forceFill(['sync_state' => 'ok', 'synced_at' => now(), 'last_error' => null, 'sync_attempts' => $attempts])->save();

            return true;
        } catch (\Throwable $e) {
            report($e);
            $credential->forceFill([
                'sync_state' => 'pending',
                'sync_attempts' => $attempts,
                'last_error' => mb_substr($e->getMessage(), 0, 190),
            ])->save();

            return false;
        }
    }

    // ───────────────────── v7.57 guest journey (check-in → check-out) ─────────────────────
    //
    // The key-card interface already pushes a credential when it encodes a card,
    // but nothing ever took it away again: a check-out expired the card record
    // while the door kept accepting the credential until its validity ran out,
    // and an extension never reached the lock at all. These hooks close that
    // loop, and they run even when no card is involved (PIN / mobile credentials
    // created elsewhere) because they work off the stay, not the card.

    /** The Robita device mapped to the stay's room, if the room has a lock. */
    public static function deviceForStay(ReservationRoom $stay): ?RobitaDevice
    {
        if (! $stay->room_id) {
            return null;
        }

        return RobitaDevice::where('hotel_id', $stay->hotel_id ?? Tenant::id())
            ->where('room_id', $stay->room_id)
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
     * Check-in: make sure the room's lock holds a credential for every active
     * card of the stay — a booking may have several (family, duplicate), and
     * each one opens the same door. Idempotent: a re-run (kiosk, re-check-in,
     * the key-card interface having just pushed its own) issues nothing twice,
     * and credentials for cards that are no longer active, or that sit on
     * another room's device, are cancelled.
     *
     * @return RobitaCredential|null the credential for the newest active card
     */
    public static function onCheckIn(ReservationRoom $stay): ?RobitaCredential
    {
        if (self::cfg()['robita_auto_issue'] !== '1') {
            return null;
        }
        $device = self::deviceForStay($stay);
        if (! $device) {
            return null; // the room has no lock (yet) — nothing to provision
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

    /** Check-out: kill the stay's credentials on the locks. */
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
     * Room move: the old door must forget the guest before the new one learns
     * about them. Returns the number of credentials cancelled on the old room.
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
        // the new room's lock needs its own credential, even when the key-card
        // interface is off and nothing was encoded on the move
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
     * Extension: the credential has to outlive the new departure, otherwise the
     * guest is shut out at the old check-out time.
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
            self::syncToCloud($credential); // re-post with the new expiry
            $n++;
        }

        return $n;
    }

    /**
     * Sweep: credentials past their validity are expired (and removed from the
     * lock) — run from `pms:interfaces`, which already expires key cards.
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

    /**
     * Retry credentials the cloud never acknowledged (max 5 attempts each).
     * Called from the poll route and the interface sweep so a cloud outage
     * heals itself instead of leaving a guest locked out.
     */
    public static function retryPending(int $limit = 20): int
    {
        if (self::cfg()['robita_cloud_url'] === '') {
            return 0;
        }
        $n = 0;
        foreach (RobitaCredential::whereIn('sync_state', ['pending', 'failed'])
            ->where('sync_attempts', '<', 5)->orderBy('id')->limit($limit)->get() as $credential) {
            self::syncToCloud($credential);
            $n++;
        }

        return $n;
    }

    /** Remote lock/unlock a door from the PMS. */
    public static function setLockState(RobitaDevice $device, string $state): RobitaDevice
    {
        $c = self::cfg();
        $device->lock_state = in_array($state, RobitaDevice::LOCK_STATES, true) ? $state : 'unknown';

        if ($c['robita_cloud_url'] !== '') {
            try {
                Http::withHeaders([
                    'Authorization' => 'Bearer '.$c['robita_access_token'],
                    'Content-Type' => 'application/json',
                ])->post($c['robita_cloud_url'].'/v1/devices/'.$device->device_id.'/lock', [
                    'action' => $state,
                ])->timeout(10);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $device->update(['last_event_at' => now()]);
        ActivityLogger::log('robita', 'lock_control', "Door {$device->name} set to {$state}", $device);

        return $device;
    }

    /** Receive an event from a Robita device (webhook or poll). */
    public static function receiveEvent(RobitaDevice $device, array $data): RobitaEvent
    {
        $eventType = $data['type'] ?? 'unlock';
        $room = $device->room;
        $stay = $room ? ReservationRoom::with('reservation.guest')->where('room_id', $room->id)->where('status', 'checked_in')->latest('id')->first() : null;
        $card = null;
        if ($data['card_serial'] ?? null) {
            $card = KeyCard::where('serial', strtoupper($data['card_serial']))->latest('id')->first();
        }
        if (! $card && $stay) {
            $card = KeyCard::where('reservation_room_id', $stay->id)->where('status', 'active')->latest('id')->first();
        }

        $event = new RobitaEvent;
        $event->fill([
            'hotel_id' => Tenant::id(),
            'robita_device_id' => $device->id,
            'room_id' => $room?->id,
            'reservation_room_id' => $stay?->id,
            'key_card_id' => $card?->id,
            'event_type' => $eventType,
            'method' => $data['method'] ?? null,
            'user_name' => $data['user_name'] ?? ($card?->holder ?? null),
            'user_id_ext' => $data['user_id'] ?? null,
            'card_serial' => $card?->serial,
            'card_no' => $card?->card_no,
            'payload' => $data,
            'is_alarm' => in_array($eventType, ['alarm', 'low_battery', 'tamper', 'forced_entry', 'door_ajar'], true),
            'alarm_type' => $data['alarm_type'] ?? null,
            'event_at' => isset($data['event_at']) ? \Illuminate\Support\Carbon::parse($data['event_at']) : now(),
            'received_at' => now(),
            'source' => $data['source'] ?? 'webhook',
        ]);

        // Update device health from event
        if (isset($data['battery_pct'])) {
            $device->battery_pct = (int) $data['battery_pct'];
        }
        if (isset($data['signal'])) {
            $device->signal = $data['signal'];
        }
        if (isset($data['lock_state'])) {
            $device->lock_state = $data['lock_state'];
        }
        $device->last_seen_at = now();
        $device->last_event_at = now();
        $device->event_count = ($device->event_count ?? 0) + 1;
        if ($eventType === 'unlock' || $eventType === 'remote_unlock') {
            $device->unlock_count = ($device->unlock_count ?? 0) + 1;
        }
        $device->save();
        $event->save();

        // Log alarms to the shift log book
        if ($event->isAlarm()) {
            try {
                // Precomputed: `?->` and `??` are not allowed inside a "..." interpolation.
                $roomNo = $device->room?->room_no ?? '—';
                $alarm = $event->alarm_type ?? $event->event_type;

                LogBookService::system(
                    "Robita lock alert: {$event->event_type} on {$device->name}",
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

    /** Poll devices for latest status and events. */
    public static function pollDevices(): int
    {
        $c = self::cfg();
        if ($c['robita_cloud_url'] === '') {
            return 0;
        }

        self::retryPending();
        $count = 0;
        foreach (RobitaDevice::where('hotel_id', Tenant::id())->where('status', '!=', 'decommissioned')->get() as $device) {
            try {
                $resp = Http::withHeaders([
                    'Authorization' => 'Bearer '.$c['robita_access_token'],
                ])->get($c['robita_cloud_url'].'/v1/devices/'.$device->device_id, [], ['timeout' => 10])->timeout(10);

                if ($resp->ok()) {
                    $d = $resp->json();
                    $device->status = $d['status'] ?? 'online';
                    $device->lock_state = $d['lock_state'] ?? $device->lock_state;
                    $device->battery_pct = isset($d['battery_pct']) ? (int) $d['battery_pct'] : null;
                    $device->signal = $d['signal'] ?? null;
                    $device->firmware = $d['firmware'] ?? $device->firmware;
                    $device->last_seen_at = now();
                    $device->save();
                    $count++;
                } else {
                    $device->update(['status' => 'offline']);
                }
            } catch (\Throwable $e) {
                report($e);
                $device->update(['status' => 'offline']);
            }
        }

        return $count;
    }

    public static function counts(): array
    {
        return [
            'robita_devices_online' => RobitaDevice::where('hotel_id', Tenant::id())->where('status', 'online')->count(),
            'robita_devices_offline' => RobitaDevice::where('hotel_id', Tenant::id())->where('status', 'offline')->count(),
            'robita_devices_total' => RobitaDevice::where('hotel_id', Tenant::id())->where('status', '!=', 'decommissioned')->count(),
            'robita_credentials_active' => RobitaCredential::where('hotel_id', Tenant::id())->where('status', 'active')->count(),
            'robita_events_unlock' => RobitaEvent::where('hotel_id', Tenant::id())->where('event_type', 'unlock')->whereDate('event_at', bdate()->toDateString())->count(),
            'robita_events_alarm' => RobitaEvent::where('hotel_id', Tenant::id())->where('is_alarm', true)->whereDate('received_at', bdate()->toDateString())->count(),
            'robita_low_battery' => RobitaDevice::where('hotel_id', Tenant::id())->where('battery_pct', '<', (int) self::cfg()['robita_battery_alert'])->where('status', '!=', 'decommissioned')->count(),
            'robita_credentials_pending' => RobitaCredential::where('hotel_id', Tenant::id())->whereIn('sync_state', ['pending', 'failed'])->count(),
        ];
    }
}
