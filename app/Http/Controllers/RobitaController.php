<?php

namespace App\Http\Controllers;

use App\Models\RobitaDevice;
use App\Models\RobitaEvent;
use App\Models\RobitaCredential;
use App\Models\ReservationRoom;
use App\Models\KeyCard;
use App\Models\Hotel;
use App\Services\RobitaService;
use App\Services\InterfaceService;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * Local Robita demo management: device records, guest-journey credentials,
 * normalized-event intake, and explicit hardware-integration limits.
 */
class RobitaController extends Controller
{
    public function index(Request $request)
    {
        RobitaService::ensureDefaults();

        $devices = RobitaDevice::with('room', 'credentials')
            ->where('hotel_id', Tenant::id())
            ->where('status', '!=', 'decommissioned')
            ->orderBy('device_type')
            ->orderBy('name')
            ->get();

        return view('robita.index', [
            'devices' => $devices,
            'counts' => RobitaService::counts(),
            'cfg' => RobitaService::cfg(),
            'lastEvents' => RobitaEvent::with('device', 'room', 'card')
                ->where('hotel_id', Tenant::id())
                ->latest('event_at')
                ->limit(15)
                ->get(),
        ]);
    }

    public function devices(Request $request)
    {
        RobitaService::ensureDefaults();

        $devices = RobitaDevice::with('room', 'credentials')
            ->where('hotel_id', Tenant::id())
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('type'), fn ($q, $t) => $q->where('device_type', $t))
            ->orderBy('device_type')
            ->orderBy('name')
            ->get();

        return view('robita.devices', [
            'devices' => $devices,
            'cfg' => RobitaService::cfg(),
            'counts' => RobitaService::counts(),
        ]);
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'serial' => 'required|string|max:64',
            'name' => 'required|string|max:80',
            'model' => 'nullable|string|max:40',
            'room_id' => 'nullable|integer|owned:rooms',
            'floor' => 'nullable|string|max:40',
            'location' => 'nullable|string|max:80',
            'device_type' => 'required|in:'.implode(',', RobitaDevice::DEVICE_TYPES),
            'notes' => 'nullable|string|max:200',
        ]);

        try {
            $device = RobitaService::registerDevice($data);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('robita.devices')->with('success', "Demo lock record {$device->name} ({$device->serial}) added. No hardware was contacted.");
    }

    public function update(Request $request, RobitaDevice $device)
    {
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'room_id' => 'nullable|integer|owned:rooms',
            'floor' => 'nullable|string|max:40',
            'location' => 'nullable|string|max:80',
            'device_type' => 'required|in:'.implode(',', RobitaDevice::DEVICE_TYPES),
            'notes' => 'nullable|string|max:200',
        ]);

        $device->update($data);

        return back()->with('success', "Robita device {$device->name} updated.");
    }

    public function toggleLock(RobitaDevice $device)
    {
        $newState = $device->isLocked() ? 'unlocked' : 'locked';
        try {
            RobitaService::setLockState($device, $newState);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Demo state for {$device->name} set to {$newState}. No hardware was actuated.");
    }

    public function forceLock(RobitaDevice $device)
    {
        try {
            RobitaService::setLockState($device, 'locked');
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Demo state for {$device->name} set to locked. No hardware was actuated.");
    }

    public function forceUnlock(RobitaDevice $device)
    {
        try {
            RobitaService::setLockState($device, 'unlocked');
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Demo state for {$device->name} set to unlocked. No hardware was actuated.");
    }

    public function toggleStatus(RobitaDevice $device)
    {
        $newStatus = $device->status === 'decommissioned' ? 'demo' : 'decommissioned';
        DB::transaction(function () use ($device, $newStatus): void {
            $device->update(['status' => $newStatus]);
            if ($newStatus === 'decommissioned') {
                foreach ($device->activeCredentials()->get() as $credential) {
                    RobitaService::cancelCredential($credential, 'demo record decommissioned');
                }
            }
        });

        return back()->with('success', $newStatus === 'demo'
            ? "{$device->name} is active as a local demo record; no hardware is connected."
            : "{$device->name} demo record decommissioned and local credentials cancelled.");
    }

    public function events(Request $request)
    {
        $events = RobitaEvent::with('device', 'room', 'card', 'stay.guest')
            ->where('hotel_id', Tenant::id())
            ->when($request->query('type'), fn ($q, $t) => $q->where('event_type', $t))
            ->when($request->query('device_id'), fn ($q, $d) => $q->where('robita_device_id', $d))
            ->when($request->query('alarm'), function ($q) use ($request) {
                if ($request->query('alarm') === '1') {
                    $q->where('is_alarm', true);
                } elseif ($request->query('alarm') === '0') {
                    $q->where('is_alarm', false);
                }
            })
            ->when($request->query('q'), fn ($q, $t) => $q->where(function ($w) use ($t) {
                $w->where('user_name', 'like', "%{$t}%")
                    ->orWhere('card_serial', 'like', "%{$t}%")
                    ->orWhere('card_no', 'like', "%{$t}%")
                    ->orWhereHas('room', fn ($r) => $r->where('room_no', 'like', "%{$t}%"));
            }))
            ->orderByDesc('event_at')
            ->paginate(50)
            ->withQueryString();

        return view('robita.events', [
            'events' => $events,
            'counts' => RobitaService::counts(),
        ]);
    }

    public function issueCredential(Request $request, RobitaDevice $device)
    {
        $data = $request->validate([
            'stay_id' => 'required|integer|owned:reservation_rooms',
            'areas' => 'nullable|array',
            'areas.*' => 'string|max:20',
        ]);

        $stay = ReservationRoom::findOrFail($data['stay_id']);
        if ($stay->status !== 'checked_in' || ! $stay->room_id) {
            return back()->with('error', 'Only checked-in guests with a room can have a local demo credential record.');
        }

        $icfg = InterfaceService::cfg();
        $areas = array_values(array_intersect($data['areas'] ?? $icfg['default_areas'] ?? [], $icfg['areas'] ?? []));

        try {
            $card = KeyCard::where('reservation_room_id', $stay->id)->where('status', 'active')->latest('id')->first();
            if (! $card) {
                throw new RuntimeException('No active key card for this stay. Encode a card first in Key Cards.');
            }
            $credential = RobitaService::issueCredential($device, $stay, $card, $areas);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', "Demo credential record created for {$credential->credential_name} – local expiry {$credential->valid_until->format('d M H:i')}. Not sent to a lock.");
    }

    public function cancelCredential(RobitaCredential $credential)
    {
        RobitaService::cancelCredential($credential);

        return back()->with('success', 'Local credential record cancelled. No hardware command was sent.');
    }

    public function settings(Request $request)
    {
        RobitaService::ensureDefaults();

        if ($request->isMethod('post')) {
            if ($request->filled('robita_cloud_url') || $request->filled('robita_access_token')) {
                return back()->withInput()->with('error', 'Vendor cloud commands are disabled pending protocol documentation and hardware acceptance. No request was sent.');
            }
            $data = $request->validate([
                'robita_default_model' => 'nullable|string|max:40',
                'robita_battery_alert' => 'nullable|integer|min:0|max:100',
                'robita_auto_issue' => 'nullable|boolean',
                'robita_auto_revoke' => 'nullable|boolean',
            ]);
            try {
                RobitaService::saveSettings($data);
            } catch (RuntimeException $e) {
                return back()->withInput()->with('error', $e->getMessage());
            }

            return back()->with('success', 'Robita lock settings saved.');
        }

        return view('robita.settings', [
            'cfg' => RobitaService::cfg(),
        ]);
    }

    public function forgetLegacyCloudConfig()
    {
        try {
            RobitaService::clearLegacyCloudConfig();
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Ignored legacy Robita URL and access token removed. No vendor request was sent.');
    }

    public function regenerateTokens()
    {
        RobitaService::regenerateTokens();

        return back()->with('success', 'Normalized-event webhook token regenerated. Any previous webhook URL is now invalid.');
    }

    public function poll()
    {
        RobitaService::pollDevices();

        return back()->with('warning', 'Hardware polling is disabled in demo mode. No vendor request was sent.');
    }

    /** Receive only the canonical normalized-event schema from a trusted local bridge. */
    public function webhook(Request $request)
    {
        $token = (string) $request->bearerToken();
        if (! preg_match('/^[A-Za-z0-9]{40}$/D', $token)) {
            return response()->json(['ok' => false, 'error' => 'Unauthorized'], 401);
        }
        $row = \App\Models\Setting::withoutGlobalScope('hotel')
            ->where('key', 'robita_webhook_token')
            ->get()
            ->first(fn ($setting) => hash_equals((string) $setting->value, $token));
        if (! $row) {
            return response()->json(['ok' => false, 'error' => 'Unauthorized'], 401);
        }
        $hotel = Hotel::find($row->hotel_id);
        if (! $hotel || ! $hotel->is_active) {
            return response()->json(['ok' => false, 'error' => 'Unknown tenant'], 404);
        }
        Tenant::set($hotel);
        // v10.5 the lock intake belongs to API & Integrations; a tenant without the module does not
        // get event rows written for it just because a lock still holds its key.
        if (! \App\Services\LicenceService::allowsModule('API & Integrations', $hotel)) {
            return response()->json(['ok' => false, 'error' => 'Module not licensed'], 403);
        }

        if (strlen((string) $request->getContent()) > 8192) {
            return response()->json(['ok' => false, 'error' => 'Payload too large'], 413);
        }
        $payload = $request->json()->all();
        $validator = Validator::make($payload, [
            'serial' => 'nullable|string|max:64|required_without:device_serial',
            'device_serial' => 'nullable|string|max:64|required_without:serial',
            'event_id' => ['required', 'string', 'max:128', 'regex:/\A[A-Za-z0-9_.:-]+\z/'],
            'type' => 'required|string|in:'.implode(',', array_keys(RobitaEvent::TYPES)),
            'method' => 'nullable|string|in:'.implode(',', RobitaEvent::METHODS),
            'event_at' => 'nullable|date',
            'card_serial' => 'nullable|string|max:64',
            'user_name' => 'nullable|string|max:80',
            'user_id' => 'nullable|string|max:64',
            'battery_pct' => 'nullable|integer|min:0|max:100',
            'signal' => 'nullable|string|in:'.implode(',', RobitaDevice::SIGNALS),
            'lock_state' => 'nullable|string|in:'.implode(',', RobitaDevice::LOCK_STATES),
            'alarm_type' => 'nullable|string|max:40',
        ]);
        if ($validator->fails()) {
            return response()->json(['ok' => false, 'error' => 'Invalid normalized event', 'fields' => $validator->errors()], 422);
        }
        $data = $validator->validated();
        if (isset($data['serial'], $data['device_serial'])
            && RobitaService::normalizeSerial((string) $data['serial']) !== RobitaService::normalizeSerial((string) $data['device_serial'])) {
            return response()->json(['ok' => false, 'error' => 'Conflicting device serial fields'], 422);
        }
        $serial = RobitaService::normalizeSerial((string) ($data['serial'] ?? $data['device_serial'] ?? ''));
        if ($serial === '') {
            return response()->json(['ok' => false, 'error' => 'Invalid device serial'], 422);
        }
        $device = RobitaDevice::where('serial', $serial)->first();
        if (! $device || (int) $device->hotel_id !== (int) $hotel->id) {
            return response()->json(['ok' => false, 'error' => 'Unknown device'], 404);
        }
        unset($data['serial'], $data['device_serial']);
        $data['source'] = 'webhook';

        try {
            $event = RobitaService::receiveEvent($device, $data);
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'error' => 'Event could not be recorded'], 500);
        }

        return response()->json(['ok' => true, 'duplicate' => ! $event->wasRecentlyCreated]);
    }

}
