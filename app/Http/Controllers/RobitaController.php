<?php

namespace App\Http\Controllers;

use App\Models\RobitaDevice;
use App\Models\RobitaEvent;
use App\Models\RobitaCredential;
use App\Models\ReservationRoom;
use App\Models\KeyCard;
use App\Services\RobitaService;
use App\Services\InterfaceService;
use App\Support\Tenant;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * v7.55 – Robita smart door lock management: device registry, door control,
 * credential issuance, event log, and cloud settings.
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

        return redirect()->route('robita.devices')->with('success', "Robita device {$device->name} ({$device->serial}) registered.");
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
        RobitaService::setLockState($device, $newState);

        return back()->with('success', "Door {$device->name} set to {$newState}.");
    }

    public function forceLock(RobitaDevice $device)
    {
        RobitaService::setLockState($device, 'locked');

        return back()->with('success', "Door {$device->name} force-locked.");
    }

    public function forceUnlock(RobitaDevice $device)
    {
        RobitaService::setLockState($device, 'unlocked');

        return back()->with('success', "Door {$device->name} force-unlocked.");
    }

    public function toggleStatus(RobitaDevice $device)
    {
        $newStatus = $device->status === 'decommissioned' ? 'online' : 'decommissioned';
        $device->update(['status' => $newStatus]);

        return back()->with('success', "Device {$device->name} set to {$newStatus}.");
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
            return back()->with('error', 'Only checked-in guests with a room can receive a door credential.');
        }

        $c = RobitaService::cfg();
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

        return back()->with('success', "Credential issued: {$credential->credential_name} – valid until {$credential->valid_until->format('d M H:i')}.");
    }

    public function cancelCredential(RobitaCredential $credential)
    {
        RobitaService::cancelCredential($credential);

        return back()->with('success', 'Credential cancelled.');
    }

    public function settings(Request $request)
    {
        RobitaService::ensureDefaults();

        if ($request->isMethod('post')) {
            $data = $request->validate([
                'robita_cloud_url' => 'nullable|string|max:190',
                'robita_access_token' => 'nullable|string|max:128',
                'robita_auto_register' => 'nullable|boolean',
                'robita_poll_minutes' => 'nullable|integer|min:0|max:60',
                'robita_webhook_token' => 'nullable|string|max:128',
                'robita_default_model' => 'nullable|string|max:40',
                'robita_battery_alert' => 'nullable|integer|min:0|max:100',
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

    public function regenerateTokens()
    {
        $tokens = RobitaService::regenerateTokens();

        return back()->with('success', 'Access token and webhook token regenerated. Update the Robita cloud configuration.');
    }

    public function poll()
    {
        $n = RobitaService::pollDevices();

        return back()->with('success', "{$n} device(s) polled for latest status.");
    }

    /** Webhook: Robita cloud pushes events to this endpoint. */
    public function webhook(Request $request, string $token)
    {
        $row = \App\Models\Setting::withoutGlobalScope('hotel')->where('key', 'robita_webhook_token')->where('value', $token)->first();
        if (! $row || ! hash_equals((string) $row->value, $token)) {
            return response()->json(['ok' => false, 'error' => 'Unknown token'], 401);
        }

        Tenant::set(\App\Models\Hotel::find($row->hotel_id));

        $payload = $request->json()->all();
        $deviceSerial = $payload['serial'] ?? $payload['device_serial'] ?? null;
        if (! $deviceSerial) {
            return response()->json(['ok' => false, 'error' => 'Missing device serial'], 422);
        }

        $device = RobitaDevice::where('serial', strtoupper($deviceSerial))->first();
        if (! $device) {
            return response()->json(['ok' => false, 'error' => 'Unknown device'], 404);
        }

        RobitaService::receiveEvent($device, $payload);

        return response()->json(['ok' => true]);
    }
}
