<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use App\Models\FleetGeofence;
use App\Models\FleetGeofenceEvent;
use App\Models\Vehicle;
use App\Models\VehicleExpense;
use App\Models\VehicleIncident;
use App\Models\VehicleInspection;
use App\Models\VehicleMaintenancePlan;
use App\Models\VehicleMaintenanceRecord;
use App\Models\VehiclePosition;
use App\Models\VehicleTracker;
use App\Services\ActivityLogger;
use App\Services\FleetService as FS;
use App\Services\TransportService as TS;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use RuntimeException;

/** Fleet management: GPS tracking, running costs, fuel efficiency and workshop planning. */
class FleetController extends Controller
{
    /** Fleet overview: utilisation, running cost, fuel efficiency and workshop status per vehicle. */
    public function index(Request $request)
    {
        $month = Carbon::parse(($request->input('month') ?: now()->format('Y-m')).'-01')->startOfMonth();
        $vehicles = Vehicle::with('asset')->orderByDesc('is_active')->orderBy('code')->get();

        $cost = [];
        $maintenance = [];
        foreach ($vehicles as $v) {
            $cost[$v->id] = FS::runningCost($v, $month->copy(), $month->copy()->endOfMonth());
            $maintenance[$v->id] = FS::maintenanceState($v);
        }

        $trackers = FS::liveTrackers()->keyBy(fn ($row) => $row['vehicle']?->id);

        return view('fleet.index', [
            'vehicles' => $vehicles, 'cost' => $cost, 'maintenance' => $maintenance, 'trackers' => $trackers,
            'month' => $month, 'alerts' => FS::alerts(),
            'readiness' => FS::readiness(),
            'fences' => FS::geofenceBoard(false), 'fenceEvents' => FS::geofenceAlerts(1, 40)->take(6),
            'speeds' => FS::speedBreaches(now()->subDays(7)->startOfDay(), now()),
            'offTrip' => FS::offTripMovement(), 'utilisation' => FS::utilisation(30), 'scorecard' => FS::driverScorecard(30),
            'forecast' => FS::serviceForecast(60), 'papers' => FS::paperWatch(), 'tripCheck' => FS::tripVerification(30, 40),
            'totals' => [
                'litres' => round(array_sum(array_column($cost, 'litres')), 2),
                'fuel' => round(array_sum(array_column($cost, 'fuel')), 2),
                'service' => round(array_sum(array_column($cost, 'service')), 2),
                'other' => round(array_sum(array_column($cost, 'other')), 2),
                'km' => round(array_sum(array_column($cost, 'km')), 1),
            ],
        ]);
    }

    /** One vehicle: profile, GPS history, workshop record and running cost. */
    public function vehicle(Vehicle $vehicle)
    {
        $month = now()->startOfMonth();

        return view('fleet.vehicle', [
            'v' => $vehicle->load('asset'),
            'cost' => FS::runningCost($vehicle, $month->copy(), now()),
            'state' => FS::maintenanceState($vehicle),
            'trackers' => $vehicle->trackers()->orderByDesc('is_active')->get(),
            'positions' => $vehicle->positions()->orderByDesc('recorded_at')->orderByDesc('id')->limit(40)->get(),
            'plans' => $vehicle->maintenancePlans()->orderByRaw('due_date IS NULL')->orderBy('due_date')->get(),
            'records' => $vehicle->maintenanceRecords()->with('plan')->orderByDesc('id')->limit(20)->get(),
            'expenses' => $vehicle->expenses()->with('driver')->orderByDesc('date')->orderByDesc('id')->limit(20)->get(),
            'trips' => $vehicle->trips()->with('driver')->orderByDesc('scheduled_at')->limit(10)->get(),
            'month' => $month,
            'trackedKm' => FS::gpsKm($vehicle->id, $month->copy(), now()),
            'speedLimit' => FS::cfg()['speed_limit_kph'],
            'speedFixes' => $vehicle->positions()->whereNotNull('speed_kph')->where('speed_kph', '>', FS::cfg()['speed_limit_kph'])
                ->where('recorded_at', '>=', now()->subDays(30))->orderByDesc('speed_kph')->orderByDesc('recorded_at')->limit(10)->get(),
            'tripCheck' => FS::tripVerification(30, 10, $vehicle->id),
            'inspections' => $vehicle->inspections()->orderByDesc('inspected_at')->orderByDesc('id')->limit(5)->get(),
            'incidents' => VehicleIncident::where('vehicle_id', $vehicle->id)
                ->orderByDesc('occurred_at')->orderByDesc('id')->limit(10)->get(),
            'inspectionDays' => (int) FS::cfg()['inspection_days'],
            'crossings' => FleetGeofenceEvent::with('geofence')->where('vehicle_id', $vehicle->id)
                ->orderByDesc('occurred_at')->orderByDesc('id')->limit(15)->get(),
        ]);
    }

    /** Live GPS tracking board: devices, last fix, state and trail. */
    public function tracking()
    {
        return view('fleet.tracking', [
            'rows' => FS::liveTrackers(),
            'vehicles' => Vehicle::where('is_active', true)->orderBy('code')->get(),
            'fences' => FleetGeofence::orderByDesc('is_active')->orderByRaw("CASE kind WHEN 'yard' THEN 0 WHEN 'zone' THEN 1 ELSE 2 END")->orderBy('name')->get(),
            'fenceBoard' => FS::geofenceBoard(),
            'events' => FS::geofenceAlerts(3, 60)->take(12),
            'cfg' => FS::cfg(),
            'issuedToken' => session('fleet_token'),
            'issuedFor' => session('fleet_token_tracker'),
        ]);
    }

    /** JSON feed polled by the live map (same permission as the board). */
    public function feed(): JsonResponse
    {
        $cfg = FS::cfg();
        $rows = FS::liveTrackers()->map(function (array $row) use ($cfg) {
            $p = $row['position'];

            return [
                'vehicle' => $row['vehicle'] ? ['id' => $row['vehicle']->id, 'code' => $row['vehicle']->code, 'name' => $row['vehicle']->name,
                    'reg_no' => $row['vehicle']->reg_no, 'type' => $row['vehicle']->type, 'status' => $row['vehicle']->status] : null,
                'tracker' => ['id' => $row['tracker']->id, 'provider' => $row['tracker']->provider, 'device_uid' => $row['tracker']->device_uid,
                    'active' => (bool) $row['tracker']->is_active],
                'state' => $row['state'],
                'minutes_since_fix' => $row['age'] === null ? null : (int) floor($row['age'] / 60),
                'position' => $p ? [
                    'lat' => (float) $p->latitude, 'lng' => (float) $p->longitude, 'speed' => $p->speed_kph === null ? null : (float) $p->speed_kph,
                    'heading' => $p->heading === null ? null : (float) $p->heading, 'ignition' => $p->ignition,
                    'odometer' => $p->odometer_km, 'recorded_at' => $p->recorded_at->toIso8601String(),
                ] : null,
                'trail' => FS::trail($row['tracker'], 30),
                'today_km' => $row['vehicle'] ? FS::gpsKm($row['vehicle']->id, now()->startOfDay(), now()) : 0,
            ];
        })->values();

        $fences = FleetGeofence::where('is_active', true)->orderBy('name')->get(['id', 'name', 'kind', 'latitude', 'longitude', 'radius_m'])
            ->map(fn (FleetGeofence $f) => [
                'id' => $f->id, 'name' => $f->name, 'kind' => $f->kind,
                'lat' => (float) $f->latitude, 'lng' => (float) $f->longitude, 'radius_m' => (int) $f->radius_m,
            ])->values();

        return response()->json(['ok' => true, 'generated_at' => now()->toIso8601String(), 'stale_minutes' => $cfg['gps_stale_minutes'],
            'vehicles' => $rows, 'fences' => $fences])
            ->header('Cache-Control', 'no-store, private');
    }

    // ───────────────────────── trackers ─────────────────────────

    public function trackerStore(Request $request)
    {
        $d = $request->validate([
            'vehicle_id' => 'required|integer|owned:vehicles',
            'provider' => ['required', Rule::in(array_keys(VehicleTracker::PROVIDERS))],
            'device_uid' => 'required|string|max:120',
            'name' => 'nullable|string|max:100',
            'stale_minutes' => 'nullable|integer|min:2|max:1440',
            'replace' => 'nullable|boolean',
        ]);
        $vehicle = Vehicle::findOrFail($d['vehicle_id']);
        if (! $vehicle->is_active) {
            return back()->withInput()->with('error', "{$vehicle->code} is retired, so a tracker cannot be paired to it.");
        }
        $uid = trim($d['device_uid']);
        if (VehicleTracker::where('provider', $d['provider'])->where('device_uid', $uid)->exists()) {
            return back()->withInput()->with('error', 'That device ID is already registered for this property.');
        }

        // One device per car: two active units on the same vehicle would ingest two trails into one
        // distance figure. Replacing is explicit, and the old device stops reporting immediately.
        $existing = VehicleTracker::where('vehicle_id', $vehicle->id)->where('is_active', true)->get();
        if ($existing->isNotEmpty() && ! $request->boolean('replace')) {
            return back()->withInput()->with('error',
                "{$vehicle->code} already has an active device ({$existing->pluck('device_uid')->implode(', ')}). "
                .'Tick “replace the paired device” if this unit is a swap, or disable the old one first.');
        }
        foreach ($existing as $old) {
            $old->forceFill(['is_active' => false])->save();
            ActivityLogger::log('transport', 'tracker_replace',
                "GPS device {$old->device_uid} disabled: replaced on {$vehicle->code} by {$uid}", $old);
        }

        $token = VehicleTracker::issueToken();
        $tracker = VehicleTracker::create([
            'vehicle_id' => $vehicle->id, 'provider' => $d['provider'], 'device_uid' => mb_substr($uid, 0, 120),
            'name' => ($n = trim((string) ($d['name'] ?? ''))) !== '' ? mb_substr($n, 0, 100) : null,
            'stale_minutes' => $d['stale_minutes'] ?? null,
            'token_hash' => VehicleTracker::hashToken($token), 'is_active' => true,
        ]);
        ActivityLogger::log('transport', 'tracker_add', "GPS device {$tracker->device_uid} paired to {$vehicle->code}", $tracker);

        return back()->with('success', "GPS device paired to {$vehicle->code}. Copy the ingest token now – it is shown only once.")
            ->with('fleet_token', $token)->with('fleet_token_tracker', $tracker->id);
    }

    public function trackerRotate(VehicleTracker $tracker)
    {
        $token = VehicleTracker::issueToken();
        $tracker->forceFill(['token_hash' => VehicleTracker::hashToken($token)])->save();
        ActivityLogger::log('transport', 'tracker_token', "Ingest token rotated for GPS device {$tracker->device_uid}", $tracker);

        return back()->with('success', 'New ingest token generated – update the device configuration.')
            ->with('fleet_token', $token)->with('fleet_token_tracker', $tracker->id);
    }

    /** Per-device signal window: a battery unit that wakes hourly is not "offline" after 20 minutes. */
    public function trackerWindow(Request $request, VehicleTracker $tracker)
    {
        $d = $request->validate(['stale_minutes' => 'nullable|integer|min:2|max:1440']);
        $tracker->forceFill(['stale_minutes' => $d['stale_minutes'] ?? null])->save();
        ActivityLogger::log('transport', 'tracker_window', $tracker->stale_minutes
            ? "Signal window for {$tracker->device_uid} set to {$tracker->stale_minutes} minutes"
            : "Signal window for {$tracker->device_uid} reset to the property default", $tracker);

        return back()->with('success', $tracker->stale_minutes
            ? "This device is now treated as offline after {$tracker->stale_minutes} minutes without a fix."
            : 'This device now follows the property signal window.');
    }

    public function trackerToggle(VehicleTracker $tracker)
    {
        $tracker->forceFill(['is_active' => ! $tracker->is_active])->save();
        ActivityLogger::log('transport', 'tracker_toggle', "GPS device {$tracker->device_uid} ".($tracker->is_active ? 'enabled' : 'disabled'), $tracker);

        return back()->with('success', 'GPS device '.($tracker->is_active ? 'enabled – it may report positions again.' : 'disabled – reported positions are refused.'));
    }

    // ───────────────────────── geofences ─────────────────────────

    public function geofenceStore(Request $request)
    {
        $d = $request->validate([
            'name' => 'required|string|max:80',
            'kind' => ['required', Rule::in(array_keys(FleetGeofence::KINDS))],
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'radius_m' => 'required|integer|min:25|max:50000',
            'alert_on' => ['required', Rule::in(array_keys(FleetGeofence::ALERTS))],
            'notes' => 'nullable|string|max:255',
        ]);
        if (FleetGeofence::where('name', trim($d['name']))->exists()) {
            return back()->withInput()->with('error', 'A geofence with that name already exists.');
        }
        $fence = FleetGeofence::create([
            'name' => mb_substr(trim($d['name']), 0, 80), 'kind' => $d['kind'],
            'latitude' => round((float) $d['latitude'], 7), 'longitude' => round((float) $d['longitude'], 7),
            'radius_m' => (int) $d['radius_m'], 'alert_on' => $d['alert_on'],
            'notes' => ($n = trim((string) ($d['notes'] ?? ''))) !== '' ? mb_substr($n, 0, 255) : null,
            'is_active' => true, 'created_by' => auth()->id(),
        ]);
        ActivityLogger::log('transport', 'geofence', "Geofence “{$fence->name}” added ({$fence->radius_m} m)", $fence);

        return back()->with('success', "Geofence “{$fence->name}” is active – crossings will be recorded from the next position.");
    }

    public function geofenceToggle(FleetGeofence $geofence)
    {
        $geofence->forceFill(['is_active' => ! $geofence->is_active])->save();
        ActivityLogger::log('transport', 'geofence_toggle', "Geofence “{$geofence->name}” ".($geofence->is_active ? 'enabled' : 'paused'), $geofence);

        return back()->with('success', $geofence->is_active ? 'Geofence enabled.' : 'Geofence paused – no crossings are recorded while it is off.');
    }

    public function geofenceDelete(FleetGeofence $geofence)
    {
        $name = $geofence->name;
        $geofence->delete();
        ActivityLogger::log('transport', 'geofence_delete', "Geofence “{$name}” deleted", null);

        return back()->with('success', "Geofence “{$name}” deleted with its crossing history.");
    }

    public function geofenceAck(FleetGeofenceEvent $geofenceEvent)
    {
        if (! $geofenceEvent->acknowledged_at) {
            $geofenceEvent->forceFill(['acknowledged_at' => now(), 'acknowledged_by' => auth()->id()])->save();
        }

        return back()->with('success', 'Crossing acknowledged.');
    }

    // ───────────────────────── maintenance ─────────────────────────

    public function maintenance(Request $request)
    {
        $status = array_key_exists((string) $request->query('status'), VehicleMaintenanceRecord::STATUSES) ? $request->query('status') : '';
        $records = VehicleMaintenanceRecord::with('vehicle', 'plan')
            ->when($request->query('vehicle'), fn ($q, $v) => $q->where('vehicle_id', $v))
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->when($status === '', fn ($q) => $q->whereIn('status', ['scheduled', 'in_progress']))
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")
            ->orderBy('due_date')->orderByDesc('id')->paginate(40)->withQueryString();

        $vehicles = Vehicle::orderBy('code')->get();
        $plans = VehicleMaintenancePlan::with('vehicle')->where('is_active', true)
            ->orderByRaw('due_date IS NULL')->orderBy('due_date')->get();

        return view('fleet.maintenance', [
            'records' => $records, 'vehicles' => $vehicles, 'plans' => $plans, 'status' => $status,
            'states' => $vehicles->mapWithKeys(fn ($v) => [$v->id => FS::maintenanceState($v)]),
            'stats' => FS::workshopStats(90),
            'openJobs' => VehicleMaintenanceRecord::with('vehicle')->whereIn('status', ['scheduled', 'in_progress'])
                ->orderBy('due_date')->orderBy('id')->limit(8)->get(),
            'inspectionDue' => FS::inspectionDue((int) FS::cfg()['inspection_days']),
            'inspections' => $this->inspectionLog($request),
            'inspectionResult' => $this->inspectionFilter($request),
            'incidents' => FS::incidents(100),
        ]);
    }

    public function planStore(Request $request)
    {
        $d = $request->validate([
            'vehicle_id' => 'required|integer|owned:vehicles',
            'name' => 'required|string|max:120',
            'category' => ['required', Rule::in(array_keys(VehicleMaintenancePlan::CATEGORIES))],
            'interval_days' => 'nullable|integer|min:1|max:3650',
            'interval_km' => 'nullable|integer|min:1|max:1000000',
            'last_completed_date' => 'nullable|date|before_or_equal:today',
            'last_completed_odometer' => 'nullable|integer|min:0|max:9999999',
            'notes' => 'nullable|string|max:500',
        ]);
        try {
            $plan = FS::storePlan(Vehicle::findOrFail($d['vehicle_id']), $d);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', "Service plan “{$plan->name}” saved for {$plan->vehicle->code}.");
    }

    public function planDelete(VehicleMaintenancePlan $plan)
    {
        $code = $plan->vehicle?->code;
        $plan->forceFill(['is_active' => false])->save();
        ActivityLogger::log('transport', 'plan_retire', "Service plan “{$plan->name}” retired for {$code}", $plan);

        return back()->with('success', 'Service plan retired – its history is kept.');
    }

    public function recordStore(Request $request)
    {
        $d = $request->validate([
            'vehicle_id' => 'required|integer|owned:vehicles',
            'plan_id' => 'nullable|integer|owned:vehicle_maintenance_plans',
            'category' => ['required', Rule::in(array_keys(VehicleMaintenanceRecord::CATEGORIES))],
            'priority' => ['required', Rule::in(array_keys(VehicleMaintenanceRecord::PRIORITIES))],
            'title' => 'required|string|max:140',
            'description' => 'nullable|string|max:2000',
            'due_date' => 'nullable|date|before_or_equal:'.now()->addYear()->toDateString(),
            'due_odometer' => 'nullable|integer|min:0|max:9999999',
            'vendor' => 'nullable|string|max:120',
            'estimated_cost' => 'nullable|numeric|min:0|max:9999999',
            'block_vehicle' => 'nullable|boolean',
        ]);
        $record = FS::storeRecord(Vehicle::findOrFail($d['vehicle_id']), $d);

        return back()->with('success', "Work order #{$record->id} logged for {$record->vehicle->code}.");
    }

    public function recordStart(VehicleMaintenanceRecord $record)
    {
        try {
            FS::startRecord($record);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$record->vehicle->code} moved into the workshop.");
    }

    public function recordComplete(Request $request, VehicleMaintenanceRecord $record)
    {
        $d = $request->validate([
            'odometer' => 'nullable|integer|min:0|max:9999999',
            'actual_cost' => 'nullable|numeric|min:0|max:9999999',
            'vendor' => 'nullable|string|max:120',
            'invoice_ref' => 'nullable|string|max:80',
            'notes' => 'nullable|string|max:500',
            'log_expense' => 'nullable|boolean',
        ]);
        try {
            FS::completeRecord($record, $d);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Work order completed for {$record->vehicle->code}".(! empty($d['log_expense']) && (float) ($d['actual_cost'] ?? 0) > 0 ? ' – cost added to running expenses.' : '.'));
    }

    public function recordCancel(Request $request, VehicleMaintenanceRecord $record)
    {
        $d = $request->validate(['reason' => 'required|string|max:190']);
        try {
            FS::cancelRecord($record, $d['reason']);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Work order cancelled.');
    }

    // ───────────────────────── fuel ─────────────────────────

    public function fuel(Request $request)
    {
        $from = Carbon::parse(($request->input('from') ?: now()->startOfMonth()->toDateString()))->startOfDay();
        $to = Carbon::parse(($request->input('to') ?: now()->toDateString()))->startOfDay();
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) > 366) {
            $from = $to->copy()->subDays(366);
        }

        $vehicles = Vehicle::orderBy('code')->get();
        $only = $request->integer('vehicle') ?: null;
        $rows = VehicleExpense::with('vehicle', 'driver')
            ->where('kind', 'fuel')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->when($only, fn ($q, $v) => $q->where('vehicle_id', $v))
            ->orderByDesc('date')->orderByDesc('id')->limit(300)->get();

        // A single-vehicle filter scopes the distance and averages too, so the headline figures always describe the rows above them.
        $stats = $vehicles->when($only, fn ($c) => $c->where('id', $only))
            ->mapWithKeys(fn ($v) => [$v->id => FS::runningCost($v, $from->copy(), $to->copy())]);
        $spend = $rows->sum(fn ($e) => (float) $e->amount);
        $litres = $rows->sum(fn ($e) => (float) $e->litres);
        $km = $stats->sum('km');

        return view('fleet.fuel', [
            'rows' => $rows, 'vehicles' => $vehicles, 'stats' => $stats, 'from' => $from, 'to' => $to,
            'spend' => $spend, 'litres' => $litres, 'km' => $km,
            'avgKmL' => $litres > 0 ? round($km / $litres, 2) : null,
            'avgCostKm' => $km > 0 ? round($spend / $km, 2) : null,
            'checks' => FS::fuelAnomalies($from->copy(), $to->copy())->when($only, fn ($c) => $c->where('vehicle.id', $only))->values(),
            'procurement' => FS::fuelProcurement(6),
            'efficiency' => FS::efficiencyBoard(90),
            'idle' => FS::idleReport(7),
            'history' => FS::costHistory(12),
            // Ranked from the same running-cost rows the screen already computed — no second distance scan.
            'league' => FS::costLeague($stats),
        ]);
    }

    public function fuelStore(Request $request)
    {
        $d = $request->validate([
            'vehicle_id' => 'required|integer|owned:vehicles',
            'date' => 'required|date|before_or_equal:today',
            'litres' => 'required|numeric|min:0.01|max:2000',
            'amount' => 'required|numeric|min:0.01|max:9999999',
            'odometer' => 'nullable|integer|min:0|max:9999999',
            'vendor' => 'nullable|string|max:80',
            'notes' => 'nullable|string|max:255',
        ]);
        $expense = \App\Models\VehicleExpense::create($d + ['kind' => 'fuel', 'created_by' => auth()->id()]);
        if ($expense->odometer && $expense->odometer > (int) $expense->vehicle->odometer) {
            $expense->vehicle->forceFill(['odometer' => $expense->odometer])->save();
        }
        ActivityLogger::log('transport', 'fuel', 'Fuel '.$d['litres'].' L · '.money($d['amount'])." for {$expense->vehicle->code}", $expense);

        return back()->with('success', 'Fuel fill recorded.');
    }

    /**
     * Correct a mistyped fill. Money recorded against a work order is not editable here — it is
     * corrected by the job that raised it, so the workshop's figure and the expense never disagree.
     */
    public function fuelUpdate(Request $request, VehicleExpense $expense)
    {
        $this->assertCorrectable($expense);
        $d = $request->validate([
            'date' => 'required|date|before_or_equal:today',
            'litres' => 'required|numeric|min:0.01|max:2000',
            'amount' => 'required|numeric|min:0.01|max:9999999',
            'odometer' => 'nullable|integer|min:0|max:9999999',
            'vendor' => 'nullable|string|max:80',
            'notes' => 'nullable|string|max:255',
            'reason' => 'required|string|min:4|max:120',
        ]);

        $was = sprintf('%s L · %s · %s', number_format((float) $expense->litres, 2), money($expense->amount), $expense->date->toDateString());
        $expense->fill(collect($d)->except('reason')->all())->save();
        if ($expense->odometer && $expense->odometer > (int) $expense->vehicle?->odometer) {
            $expense->vehicle->forceFill(['odometer' => $expense->odometer])->save();
        }
        ActivityLogger::log('transport', 'fuel', sprintf('Corrected fuel fill for %s: was %s, now %s L · %s — %s',
            $expense->vehicle?->code, $was, number_format((float) $expense->litres, 2), money($expense->amount), $d['reason']), $expense);

        return back()->with('success', 'Fuel fill corrected. The change is in the activity log with your reason.');
    }

    /**
     * Delete a fill entered in error. The vehicle's odometer is deliberately **not** rolled back:
     * that reading may already have been seen by a later fill or a service plan.
     */
    public function fuelDestroy(Request $request, VehicleExpense $expense)
    {
        $this->assertCorrectable($expense);
        $d = $request->validate(['reason' => 'required|string|min:4|max:120']);
        $line = sprintf('%s · %s L · %s', $expense->date->toDateString(), number_format((float) $expense->litres, 2), money($expense->amount));
        $vehicle = $expense->vehicle;
        $expense->delete();
        ActivityLogger::log('transport', 'fuel', sprintf('Deleted fuel fill %s: %s — %s', $line, $vehicle?->code, $d['reason']), $vehicle);

        return back()->with('success', 'Fill deleted. The odometer reading it carried was left where it is.');
    }

    /** Fuel, tolls and parking are corrected here; service and repair money belongs to a work order. */
    private function assertCorrectable(VehicleExpense $expense): void
    {
        abort_if($expense->maintenance_record_id !== null, 403,
            'This cost is attached to a work order. Correct it through the job so the workshop figure and the expense stay together.');
        abort_unless(in_array($expense->kind, ['fuel', 'toll', 'parking', 'other'], true), 403,
            'Service and repair costs are corrected through the work order that raised them.');
    }

    /** Inspection record as a spreadsheet: what was checked, what failed and what it raised. */
    public function exportInspections(Request $request)
    {
        // A hand-edited URL must not 500 the download: fall back to the default window instead.
        try {
            $from = Carbon::parse((string) ($request->query('from') ?: now()->subDays(90)->toDateString()))->startOfDay();
            $to = Carbon::parse((string) ($request->query('to') ?: now()->toDateString()))->endOfDay();
        } catch (\Throwable) {
            $from = now()->subDays(90)->startOfDay();
            $to = now()->endOfDay();
        }
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        $result = array_key_exists((string) $request->query('result'), VehicleInspection::RESULTS) ? (string) $request->query('result') : '';

        $rows = VehicleInspection::with('vehicle', 'workOrder', 'creator')
            ->whereBetween('inspected_at', [$from, $to])
            ->when($result !== '', fn ($q) => $q->where('result', $result))

            ->orderByDesc('inspected_at')->orderByDesc('id')->limit(20000)->lazy(500);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            $this->csvRow($out, ['Inspected at', 'Vehicle', 'Reg no', 'Inspector', 'Odometer', 'Result',
                'Checks confirmed', 'Checks not confirmed', 'Defects', 'Work order', 'Work order status', 'Logged by']);
            foreach ($rows as $i) {
                $this->csvRow($out, [
                    $i->inspected_at?->toDateTimeString(), $i->vehicle?->code ?? '', $i->vehicle?->reg_no ?? '',
                    $i->inspector, $i->odometer, VehicleInspection::RESULTS[$i->result] ?? $i->result,
                    count($i->passedChecks()), implode('; ', $i->missingChecks()), $i->defects,
                    $i->work_order_id ? 'WO-'.str_pad((string) $i->work_order_id, 5, '0', STR_PAD_LEFT) : '',
                    $i->workOrder ? (VehicleMaintenanceRecord::STATUSES[$i->workOrder->status] ?? $i->workOrder->status) : '',
                    $i->creator?->name,
                ]);
            }
            fclose($out);
        }, 'fleet-inspections-'.$from->format('Ymd').'-'.$to->format('Ymd').'.csv', ['Content-Type' => 'text/csv']);
    }

    /** The inspection log: filterable and paged, because the CSV was the only way to see last month. */
    protected function inspectionLog(Request $request)
    {
        $result = $this->inspectionFilter($request);

        return VehicleInspection::with('vehicle', 'workOrder')
            ->when($request->integer('inspection_vehicle'), fn ($q, $v) => $q->where('vehicle_id', $v))
            ->when($result !== '', fn ($q) => $q->where('result', $result))
            ->orderByDesc('inspected_at')->orderByDesc('id')
            ->paginate(20, ['*'], 'inspection_page')->withQueryString();
    }

    protected function inspectionFilter(Request $request): string
    {
        $result = (string) $request->query('inspection_result', '');

        return array_key_exists($result, VehicleInspection::RESULTS) ? $result : '';
    }

    /** Printable job card for the garage: what is being done, on which car, and what has been posted. */
    /** Log something that happened to a car on the road. It stays open until somebody closes it. */
    public function incidentStore(Request $request)
    {
        $d = $request->validate([
            'vehicle_id' => 'required|integer|owned:vehicles',
            'occurred_at' => 'required|date|before_or_equal:now',
            'kind' => ['required', Rule::in(array_keys(VehicleIncident::KINDS))],
            'severity' => ['required', Rule::in(array_keys(VehicleIncident::SEVERITIES))],
            'location' => 'nullable|string|max:150',
            'description' => 'required|string|min:10|max:1000',
            'third_party' => 'nullable|string|max:150',
            'police_ref' => 'nullable|string|max:60',
            'estimated_cost' => 'nullable|numeric|min:0|max:99999999',
        ]);
        $incident = VehicleIncident::create($d + ['status' => 'open', 'created_by' => auth()->id()]);
        ActivityLogger::log('transport', 'fleet', sprintf('%s reported for %s (%s): %s', VehicleIncident::KINDS[$d['kind']],
            $incident->vehicle?->code, VehicleIncident::SEVERITIES[$d['severity']], mb_substr($d['description'], 0, 120)), $incident);

        return back()->with('success', 'Incident logged. It stays open until someone closes it with a note.');
    }

    /** Move an incident along — investigate, close, or reopen it. A status change always carries a note. */
    public function incidentStatus(Request $request, VehicleIncident $vehicleIncident)
    {
        $d = $request->validate([
            'status' => ['required', Rule::in(array_keys(VehicleIncident::STATUSES))],
            'note' => 'required|string|min:4|max:255',
        ]);
        $was = $vehicleIncident->status;
        $vehicleIncident->forceFill([
            'status' => $d['status'],
            'closure_note' => $d['note'],
            'closed_at' => $d['status'] === 'closed' ? now() : null,
        ])->save();
        ActivityLogger::log('transport', 'fleet', sprintf('Incident on %s: %s → %s — %s', $vehicleIncident->vehicle?->code,
            VehicleIncident::STATUSES[$was] ?? $was, VehicleIncident::STATUSES[$d['status']], $d['note']), $vehicleIncident);

        return back()->with('success', 'Incident marked '.mb_strtolower(VehicleIncident::STATUSES[$d['status']]).'.');
    }

    public function costStatement(Request $request)
    {
        $month = self::statementMonth((string) $request->query('month', ''));
        $statement = FS::costStatement($month);
        $next = $month->copy()->addMonthNoOverflow();

        return view('fleet.cost-statement', [
            'month' => $month, 'statement' => $statement, 'hotel' => Tenant::hotel(),
            'prev' => $month->copy()->subMonthNoOverflow(),
            'next' => $next->lte(now()->startOfMonth()) ? $next : null,
        ]);
    }

    /**
     * A statement month is a calendar month. A hand-typed `?month=` cannot pull a future month out of the
     * air, and cannot silently reach back a decade either — both fall back to something sane instead.
     */
    private static function statementMonth(string $value): Carbon
    {
        $month = $value !== '' && Carbon::hasFormat($value, 'Y-m')
            ? Carbon::createFromFormat('!Y-m', $value)->startOfMonth()
            : now()->startOfMonth();
        if ($month->gt(now()->startOfMonth())) {
            $month = now()->startOfMonth();
        }
        $floor = now()->copy()->subYears(5)->startOfMonth();

        return $month->lt($floor) ? $floor : $month;
    }

    /** The statement as a spreadsheet, so accounts can total it their own way. Same month rules and permission. */
    public function exportStatement(Request $request)
    {
        $month = self::statementMonth((string) $request->query('month', ''));
        $statement = FS::costStatement($month);
        $totals = $statement['totals'];

        return response()->streamDownload(function () use ($statement, $totals) {
            $out = fopen('php://output', 'w');
            $this->csvRow($out, ['Vehicle', 'Reg no', 'Make & model', 'Fuel', 'Service & repair', 'Other', 'Total',
                'Litres', 'Fills', 'Distance km', 'Cost per km']);
            foreach ($statement['lines'] as $l) {
                $this->csvRow($out, [$l['vehicle']->code, $l['vehicle']->reg_no,
                    trim(($l['vehicle']->make ?? '').' '.($l['vehicle']->model ?? '')), $l['fuel'], $l['service'],
                    $l['other'], $l['total'], $l['litres'], $l['fills'],
                    // Blank, not zero, when the car sent no fixes — the same rule the printed statement follows.
                    $l['km'] ?? '', $l['cost_per_km'] ?? '']);
            }
            $this->csvRow($out, ['TOTAL', count($statement['lines']).' vehicle(s)', '', $totals['fuel'], $totals['service'],
                $totals['other'], $totals['total'], $totals['litres'], $totals['fills'], $totals['km'], $totals['cost_per_km'] ?? '']);
            fclose($out);
        }, 'fleet-statement-'.$month->format('Y-m').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function workOrderPrint(VehicleMaintenanceRecord $record)
    {
        $record->load(['vehicle', 'plan']);
        $inspections = VehicleInspection::where('work_order_id', $record->id)
            ->orderBy('inspected_at')->orderBy('id')->get();
        $expenses = VehicleExpense::where('maintenance_record_id', $record->id)
            ->orderBy('date')->orderBy('id')->get();

        return view('fleet.work-order', [
            'record' => $record, 'hotel' => Tenant::hotel(), 'inspections' => $inspections, 'expenses' => $expenses,
        ]);
    }

    /** Pre-trip inspection: the checks confirmed, the result, and an optional work order for a defect. */
    public function inspectionStore(Request $request)
    {
        $d = $request->validate([
            'vehicle_id' => 'required|integer|owned:vehicles',
            'inspected_at' => 'nullable|date|before_or_equal:now',
            'odometer' => 'nullable|integer|min:0|max:9999999',
            'inspector' => 'nullable|string|max:100',
            'checks' => 'nullable|array',
            'checks.*' => ['string', Rule::in(array_keys(VehicleInspection::CHECKS))],
            'result' => ['required', Rule::in(array_keys(VehicleInspection::RESULTS))],
            'defects' => 'nullable|string|max:1000',
            'raise_work_order' => 'nullable|boolean',
        ]);
        $vehicle = Vehicle::findOrFail($d['vehicle_id']);
        $inspection = FS::recordInspection($vehicle, $d);
        ActivityLogger::log('transport', 'inspection',
            "Pre-trip inspection {$vehicle->code}: ".VehicleInspection::RESULTS[$inspection->result]
            .' ('.count($inspection->passedChecks()).'/'.count(VehicleInspection::CHECKS).' checks confirmed)', $inspection);

        $message = "Inspection recorded for {$vehicle->code}.";
        if ($inspection->isUnsafe()) {
            $message .= ' The vehicle is marked as in the workshop and is no longer offered on the dispatch board.';
        }
        if ($inspection->work_order_id) {
            $message .= " Work order #{$inspection->work_order_id} opened for the defect.";
        }

        return back()->with('success', $message);
    }

    // ───────────────────────── exports ─────────────────────────

    /** Neutralise spreadsheet formula injection before a value reaches fputcsv. */
    protected function csvSafe($v): string
    {
        $v = (string) $v;

        return preg_match('/^[=+\-@\t\r]/', $v) && ! is_numeric($v) ? "'".$v : $v;
    }

    protected function csvRow($out, array $row): void
    {
        fputcsv($out, array_map(fn ($v) => $this->csvSafe($v), $row), ',', '"', '');
    }

    /** Fleet status and running cost for one month — the overview table, download-ready. */
    public function exportVehicles(Request $request)
    {
        $month = Carbon::parse(($request->input('month') ?: now()->format('Y-m')).'-01')->startOfMonth();
        $vehicles = Vehicle::with('asset')->orderBy('code')->get();

        return response()->streamDownload(function () use ($vehicles, $month) {
            $out = fopen('php://output', 'w');
            $trackers = FS::liveTrackers()->keyBy(fn ($row) => $row['vehicle']?->id);
            $this->csvRow($out, ['Code', 'Name', 'Reg no', 'Type', 'Active', 'GPS state', 'Last fix', 'km (month)', 'Fuel',
                'Litres', 'km per litre', 'Cost per km', 'Workshop', 'Next service', 'Papers expired']);

            foreach ($vehicles as $v) {
                $cost = FS::runningCost($v, $month->copy(), $month->copy()->endOfMonth());
                $state = $trackers->get($v->id);
                $maint = FS::maintenanceState($v);
                $next = $maint['overdue']->first() ?? $maint['soon']->first();
                // $state is null for vehicles without a tracker — do not read $state['state'] unguarded (streamed 500)
                $labels = $state ? (FS::STATES[$state['state']] ?? null) : null;

                $this->csvRow($out, [$v->code, $v->name, $v->reg_no, $v->type, $v->is_active ? 'Yes' : 'No',
                    $state ? ($labels[0] ?? $state['state']) : 'No device',
                    $state && $state['position'] ? $state['position']->recorded_at->toDateTimeString() : '',
                    $cost['km'], $cost['fuel'], $cost['litres'], $cost['km_per_litre'], $cost['cost_per_km'],
                    $maint['overdue']->count() ? 'Service overdue' : ($maint['open']->count() ? $maint['open']->count().' job(s) open' : 'Available'),
                    $next?->name ?? '', count($v->expiredPapers())]);
            }
            fclose($out);
        }, 'fleet-status-'.$month->format('Y-m').'.csv', ['Content-Type' => 'text/csv']);
    }

    /** GPS trail for the last N days, streamed so a busy fleet never buffers in memory. */
    public function exportGps(Request $request)
    {
        $days = max(1, min(31, (int) ($request->integer('days') ?: 7)));
        $from = now()->subDays($days);
        $rows = VehiclePosition::with('vehicle')->where('recorded_at', '>=', $from)
            ->orderBy('recorded_at')->orderBy('id')->limit(100000)->lazy(1000);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            $this->csvRow($out, ['Vehicle', 'Reg no', 'Recorded', 'Latitude', 'Longitude', 'Speed (km/h)', 'Heading', 'Ignition', 'Odometer', 'Map']);
            foreach ($rows as $p) {
                $this->csvRow($out, [$p->vehicle?->code ?? '', $p->vehicle?->reg_no ?? '', $p->recorded_at->toDateTimeString(),
                    $p->latitude, $p->longitude, $p->speed_kph, $p->heading, $p->ignition === null ? '' : ($p->ignition ? 'on' : 'off'),
                    $p->odometer_km, 'https://www.google.com/maps?q='.$p->latitude.','.$p->longitude]);
            }
            fclose($out);
        }, 'fleet-gps-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv']);
    }

    /** Fuel fills for the selected period and vehicle — the same rows the fuel screen shows. */
    public function exportFuel(Request $request)
    {
        $from = Carbon::parse(($request->input('from') ?: now()->startOfMonth()->toDateString()))->startOfDay();
        $to = Carbon::parse(($request->input('to') ?: now()->toDateString()))->startOfDay();
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        $only = $request->integer('vehicle') ?: null;
        $rows = VehicleExpense::with('vehicle', 'driver')->where('kind', 'fuel')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->when($only, fn ($q, $v) => $q->where('vehicle_id', $v))
            ->orderBy('date')->orderBy('id')->limit(20000)->lazy(500);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            $this->csvRow($out, ['Date', 'Vehicle', 'Reg no', 'Litres', 'Amount', 'Price per litre', 'Odometer', 'Supplier', 'Driver', 'Notes']);
            foreach ($rows as $e) {
                $litres = (float) $e->litres;
                $this->csvRow($out, [$e->date->toDateString(), $e->vehicle?->code ?? '', $e->vehicle?->reg_no ?? '', $litres,
                    (float) $e->amount, $litres > 0 ? round((float) $e->amount / $litres, 2) : '', $e->odometer,
                    $e->vendor, $e->driver?->name, $e->notes]);
            }
            fclose($out);
        }, 'fleet-fuel-'.$from->format('Ymd').'-'.$to->format('Ymd').'.csv', ['Content-Type' => 'text/csv']);
    }

    /** Work orders for the accountant: open by default, or any status. */
    public function exportMaintenance(Request $request)
    {
        $status = array_key_exists((string) $request->query('status'), VehicleMaintenanceRecord::STATUSES) ? (string) $request->query('status') : '';
        $rows = VehicleMaintenanceRecord::with('vehicle', 'plan')
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->when($status === '', fn ($q) => $q->whereIn('status', ['scheduled', 'in_progress']))
            ->orderByDesc('id')->limit(20000)->lazy(500);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            $this->csvRow($out, ['Vehicle', 'Reg no', 'Job', 'Category', 'Priority', 'Status', 'Due date', 'Due odometer',
                'Started', 'Completed', 'Odometer', 'Supplier', 'Estimate', 'Actual', 'Invoice ref', 'Notes']);
            foreach ($rows as $r) {
                $this->csvRow($out, [$r->vehicle?->code ?? '', $r->vehicle?->reg_no ?? '', $r->title,
                    VehicleMaintenanceRecord::CATEGORIES[$r->category] ?? $r->category,
                    VehicleMaintenanceRecord::PRIORITIES[$r->priority] ?? $r->priority,
                    VehicleMaintenanceRecord::STATUSES[$r->status] ?? $r->status,
                    $r->due_date?->toDateString(), $r->due_odometer, $r->started_at?->toDateTimeString(),
                    $r->completed_at?->toDateTimeString(), $r->odometer, $r->vendor,
                    (float) $r->estimated_cost, (float) $r->actual_cost, $r->invoice_ref, $r->notes]);
            }
            fclose($out);
        }, 'fleet-work-orders-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv']);
    }

    // ───────────────────────── settings ─────────────────────────

    public function settings()
    {
        FS::ensureDefaults();

        return view('fleet.settings', ['c' => FS::cfg(), 'transport' => TS::cfg()]);
    }

    public function saveSettings(Request $request)
    {
        $d = $request->validate([
            'gps_stale_minutes' => 'required|integer|min:2|max:1440',
            'gps_retention_days' => 'required|integer|min:7|max:3650',
            'speed_moving_kph' => 'required|integer|min:1|max:200',
            'speed_limit_kph' => 'required|integer|min:20|max:200',
            'maintenance_lead_days' => 'required|integer|min:1|max:180',
            'maintenance_lead_km' => 'required|integer|min:10|max:20000',
            'inspection_days' => 'required|integer|min:1|max:90',
            'idle_lph' => 'required|numeric|min:0|max:20',
        ]);
        FS::saveSettings($d);

        return back()->with('success', 'Fleet settings saved.');
    }
}
