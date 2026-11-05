<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use App\Models\VehicleTracker;
use App\Services\ActivityLogger;
use App\Services\FleetService;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * Stateless GPS ingest: a tracker pushes its fixes to /api/fleet/{token}. The 48-character
 * token identifies one paired device; it is stored only as a SHA-256 hash.
 */
class GpsIngestController extends Controller
{
    public function push(Request $request, string $token): JsonResponse
    {
        $tracker = VehicleTracker::withoutGlobalScope('hotel')->where('token_hash', VehicleTracker::hashToken($token))->first();
        if (! $tracker) {
            return response()->json(['ok' => false, 'error' => 'Unknown device token'], 401);
        }
        if (! $tracker->is_active) {
            return response()->json(['ok' => false, 'error' => 'This device is disabled'], 403);
        }
        $hotel = Hotel::find($tracker->hotel_id);
        if (! $hotel || ! $hotel->is_active) {
            return response()->json(['ok' => false, 'error' => 'Property is not active'], 403);
        }
        Tenant::set($hotel);

        $rows = $this->rows($request);
        if ($rows === []) {
            return response()->json(['ok' => false, 'error' => 'Send at least one position'], 422);
        }

        $sentUid = trim((string) ($request->input('device_uid') ?? $request->input('device_id') ?? ''));
        if ($sentUid !== '' && ! hash_equals((string) $tracker->device_uid, $sentUid)) {
            return response()->json(['ok' => false, 'error' => 'Device ID does not match this token'], 403);
        }

        $hadSignal = $tracker->last_position_at !== null;
        try {
            $result = FleetService::ingest($tracker, $rows);
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'error' => 'Could not store the position'], 500);
        }

        if ($result['stored'] > 0 && ! $hadSignal) {
            ActivityLogger::log('transport', 'gps_first_fix', "First GPS fix from {$tracker->device_uid}", $tracker);
        }

        return response()->json(['ok' => true] + $result);
    }

    /** Devices (and installers) can check that the token works before wiring the config. */
    public function health(Request $request, string $token): JsonResponse
    {
        $tracker = VehicleTracker::withoutGlobalScope('hotel')->with('vehicle')->where('token_hash', VehicleTracker::hashToken($token))->first();
        if (! $tracker) {
            return response()->json(['ok' => false, 'error' => 'Unknown device token'], 401);
        }
        if (! $tracker->is_active) {
            return response()->json(['ok' => false, 'error' => 'This device is disabled'], 403);
        }
        $hotel = Hotel::find($tracker->hotel_id);
        if (! $hotel || ! $hotel->is_active) {
            return response()->json(['ok' => false, 'error' => 'Property is not active'], 403);
        }
        Tenant::set($hotel);

        return response()->json([
            'ok' => true,
            'provider' => $tracker->provider,
            'device_uid' => $tracker->device_uid,
            'vehicle' => $tracker->vehicle?->code,
            'last_position_at' => $tracker->last_position_at?->toIso8601String(),
            'server_time' => now()->toIso8601String(),
            'accepted_fields' => ['lat', 'lng', 'speed', 'heading', 'ignition', 'odometer', 'recorded_at', 'event_id'],
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    protected function rows(Request $request): array
    {
        if ($request->isJson()) {
            $payload = $request->json()->all();
            $batch = $payload['positions'] ?? $payload['records'] ?? $payload['data'] ?? null;
            if (is_array($batch)) {
                return array_values(array_filter($batch, 'is_array'));
            }
            if (array_is_list($payload)) {
                return array_values(array_filter($payload, 'is_array'));
            }

            return $payload ? [$payload] : [];
        }

        if ($request->filled('positions') && is_array($request->input('positions'))) {
            return array_values(array_filter($request->input('positions'), 'is_array'));
        }

        return $request->all() ? [$request->all()] : [];
    }
}
