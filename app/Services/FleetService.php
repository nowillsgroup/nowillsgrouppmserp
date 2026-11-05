<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\FleetGeofence;
use App\Models\FleetGeofenceEvent;
use App\Models\FleetGeofenceState;
use App\Models\Vehicle;
use App\Models\VehicleExpense;
use App\Models\VehicleIncident;
use App\Models\VehicleInspection;
use App\Models\VehicleMaintenancePlan;
use App\Models\VehicleMaintenanceRecord;
use App\Models\TransportTrip;
use App\Models\VehiclePosition;
use App\Models\VehicleTracker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Provider-neutral fleet management: GPS fix ingestion, live vehicle state, running-cost
 * analytics, and preventive-maintenance planning / work-order completion.
 */
class FleetService
{
    public const DEFAULTS = [
        'transport_gps_stale_minutes' => '20',        // a device silent for longer is shown offline
        'transport_gps_retention_days' => '180',      // prune fixes older than this
        'transport_speed_moving_kph' => '5',          // above this the vehicle is moving
        'transport_speed_limit_kph' => '80',          // above this the driver is speeding
        'transport_idle_lph' => '0.9',                // litres per hour while standing still, engine on
        'transport_maintenance_lead_days' => '14',    // "due soon" window for planned service
        'transport_maintenance_lead_km' => '500',     // "due soon" odometer window
        'transport_inspection_days' => '7',           // a car should be inspected this often
    ];

    // ───────────────────────── settings ─────────────────────────

    public static function ensureDefaults(): void
    {
        foreach (self::DEFAULTS as $k => $v) {
            \App\Models\Setting::firstOrCreate(['key' => $k], ['value' => $v]);
        }
    }

    public static function cfg(): array
    {
        $s = [];
        foreach (self::DEFAULTS as $k => $v) {
            $s[substr($k, 10)] = (string) setting($k, $v);
        }
        foreach (['gps_stale_minutes', 'gps_retention_days', 'speed_moving_kph', 'speed_limit_kph', 'maintenance_lead_days', 'maintenance_lead_km', 'inspection_days'] as $k) {
            $s[$k] = max(1, (int) $s[$k]);
        }

        return $s;
    }

    public static function saveSettings(array $d): void
    {
        foreach (self::DEFAULTS as $k => $v) {
            $short = substr($k, 10);
            if (array_key_exists($short, $d)) {
                \App\Models\Setting::updateOrCreate(['key' => $k], ['value' => (int) $d[$short]]);
            }
        }
        \App\Models\Setting::$version++;
        ActivityLogger::log('transport', 'fleet_settings', 'Fleet tracking & maintenance settings updated');
    }

    // ───────────────────────── GPS ingestion ─────────────────────────

    /**
     * Store a batch of GPS fixes for one tracker. Each row accepts lat|latitude, lng|lon|longitude, speed|speed_kph,
     * heading|bearing|course, ignition|acc, odometer|odometer_km, recorded_at|timestamp|time, event_id|id.
     *
     * @return array{stored:int,duplicates:int,rejected:int,errors:array<int,string>}
     */
    public static function ingest(VehicleTracker $tracker, array $rows): array
    {
        $rows = array_values(array_filter($rows, 'is_array'));
        if (count($rows) > 500) {
            throw new RuntimeException('Send at most 500 positions per request.');
        }

        $out = ['stored' => 0, 'duplicates' => 0, 'rejected' => 0, 'errors' => []];
        $newest = null;
        $newestPosition = null;
        $odometer = null;

        foreach ($rows as $i => $r) {
            $fix = self::normalizeFix($r, $i);
            if (isset($fix['error'])) {
                $out['rejected']++;
                if (count($out['errors']) < 5) {
                    $out['errors'][] = $fix['error'];
                }

                continue;
            }
            if ($fix['recorded_at']->isAfter(now()->addMinutes(5))) {
                $out['rejected']++;
                if (count($out['errors']) < 5) {
                    $out['errors'][] = 'Position '.($i + 1).': the device clock is in the future.';
                }

                continue;
            }
            if ($fix['event_id'] && VehiclePosition::where('vehicle_tracker_id', $tracker->id)->where('event_id', $fix['event_id'])->exists()) {
                $out['duplicates']++;

                continue;
            }

            $position = VehiclePosition::create($fix + ['vehicle_tracker_id' => $tracker->id, 'vehicle_id' => $tracker->vehicle_id, 'received_at' => now()]);
            $out['stored']++;
            if (! $newest || $fix['recorded_at']->greaterThan($newest)) {
                $newest = $fix['recorded_at'];
                $newestPosition = $position;
            }
            if ($fix['odometer_km'] !== null) {
                $odometer = max((int) ($odometer ?? 0), (int) $fix['odometer_km']);
            }
        }

        $latest = $newest ?: now();
        $tracker->forceFill([
            'last_seen_at' => now(),
            'last_position_at' => $tracker->last_position_at && $tracker->last_position_at->greaterThan($latest) ? $tracker->last_position_at : $latest,
        ])->save();

        if ($odometer && ($v = $tracker->vehicle) && $odometer > (int) $v->odometer) {
            $v->forceFill(['odometer' => $odometer])->save();
        }

        if ($newestPosition) {
            self::evaluateGeofences($newestPosition);
        }

        return $out;
    }

    /** @return array<string,mixed> normalized fix or ['error' => '…'] */
    protected static function normalizeFix(array $r, int $i): array
    {
        $lat = $r['lat'] ?? $r['latitude'] ?? null;
        $lng = $r['lng'] ?? $r['lon'] ?? $r['longitude'] ?? null;
        if (! is_numeric($lat) || ! is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
            return ['error' => 'Position '.($i + 1).': latitude / longitude missing or out of range.'];
        }

        $at = $r['recorded_at'] ?? $r['timestamp'] ?? $r['time'] ?? null;
        try {
            $recorded = is_numeric($at) ? Carbon::createFromTimestamp((int) $at) : Carbon::parse((string) $at);
        } catch (\Throwable) {
            return ['error' => 'Position '.($i + 1).': the recorded time is not a date.'];
        }
        if (! $at) {
            $recorded = now();
        }
        if ($recorded->isBefore(now()->subDays(7))) {
            return ['error' => 'Position '.($i + 1).': older than 7 days, ignored.'];
        }

        $speed = $r['speed'] ?? $r['speed_kph'] ?? null;
        $heading = $r['heading'] ?? $r['bearing'] ?? $r['course'] ?? null;
        $ignition = $r['ignition'] ?? $r['acc'] ?? null;
        $odo = $r['odometer'] ?? $r['odometer_km'] ?? null;
        $event = $r['event_id'] ?? $r['id'] ?? null;

        return [
            'latitude' => round((float) $lat, 7),
            'longitude' => round((float) $lng, 7),
            'speed_kph' => is_numeric($speed) ? max(0, min(400, round((float) $speed, 2))) : null,
            'heading' => is_numeric($heading) ? fmod(max(0, (float) $heading), 360) : null,
            'ignition' => $ignition === null ? null : filter_var($ignition, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            'odometer_km' => is_numeric($odo) ? max(0, (int) $odo) : null,
            'event_id' => $event === null || $event === '' ? null : mb_substr((string) $event, 0, 120),
            'recorded_at' => $recorded,
        ];
    }

    /** Seconds since the last fix, or null when the device never reported. */
    public static function secondsSinceFix(VehicleTracker $tracker): ?int
    {
        return $tracker->last_position_at ? (int) $tracker->last_position_at->diffInSeconds(now()) : null;
    }

    public static function trackerState(VehicleTracker $tracker, ?VehiclePosition $position = null): string
    {
        if (! $tracker->is_active) {
            return 'disabled';
        }
        $age = self::secondsSinceFix($tracker);
        if ($age === null) {
            return 'never';
        }
        if ($age > $tracker->signalWindow(self::cfg()['gps_stale_minutes']) * 60) {
            return 'offline';
        }
        $moving = $position && $position->speed_kph !== null && (float) $position->speed_kph > self::cfg()['speed_moving_kph'];

        return $moving ? 'moving' : 'parked';
    }

    public const STATES = [
        'moving' => ['Moving', 'green'], 'parked' => ['Parked', 'blue'], 'offline' => ['Offline', 'red'],
        'never' => ['No signal yet', 'slate'], 'disabled' => ['Disabled', 'slate'],
    ];

    /** Live rows for the tracking board: one per tracker, vehicle and latest fix included. */
    public static function liveTrackers(): \Illuminate\Support\Collection
    {
        return VehicleTracker::with('vehicle')->orderBy('vehicle_id')->orderBy('id')->get()->map(function (VehicleTracker $t) {
            $position = $t->positions()->orderByDesc('recorded_at')->orderByDesc('id')->first();
            $t->setRelation('latest', $position);

            return [
                'tracker' => $t,
                'vehicle' => $t->vehicle,
                'position' => $position,
                'state' => self::trackerState($t, $position),
                'age' => self::secondsSinceFix($t),
            ];
        });
    }

    /** Trail of the last N fixes for the live map. */
    public static function trail(VehicleTracker $tracker, int $limit = 30): array
    {
        return $tracker->positions()->orderByDesc('recorded_at')->orderByDesc('id')->limit(max(2, min(200, $limit)))->get()
            ->reverse()->map(fn (VehiclePosition $p) => [(float) $p->latitude, (float) $p->longitude])->values()->all();
    }

    // ───────────────────────── geofences ─────────────────────────

    /**
     * Compare the newest fix against every active geofence and record the crossings. Only the latest fix of a
     * batch is evaluated, so a burst of catch-up fixes cannot replay days of entries and exits.
     */
    public static function evaluateGeofences(VehiclePosition $position): int
    {
        if (! $position->vehicle_id) {
            return 0;
        }
        $recorded = 0;
        foreach (FleetGeofence::where('is_active', true)->get() as $fence) {
            $distance = self::metres((float) $fence->latitude, (float) $fence->longitude, (float) $position->latitude, (float) $position->longitude);
            $inside = $distance <= (int) $fence->radius_m;
            $state = FleetGeofenceState::firstOrCreate(
                ['geofence_id' => $fence->id, 'vehicle_id' => $position->vehicle_id],
                ['inside' => $inside, 'changed_at' => now()]
            );
            if ((bool) $state->inside === $inside) {
                continue;
            }
            $state->forceFill(['inside' => $inside, 'changed_at' => $position->recorded_at])->save();
            FleetGeofenceEvent::create([
                'geofence_id' => $fence->id, 'vehicle_id' => $position->vehicle_id, 'position_id' => $position->id,
                'type' => $inside ? 'enter' : 'exit', 'occurred_at' => $position->recorded_at,
            ]);
            $recorded++;
        }

        return $recorded;
    }

    /** Where each vehicle stands right now relative to the yard and the other zones. */
    public static function geofenceBoard(bool $activeOnly = true): array
    {
        $fences = FleetGeofence::when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderByRaw("CASE kind WHEN 'yard' THEN 0 WHEN 'zone' THEN 1 ELSE 2 END")->orderBy('name')->get();
        $states = FleetGeofenceState::all()->keyBy(fn ($s) => $s->geofence_id.':'.$s->vehicle_id);

        return $fences->map(fn (FleetGeofence $f) => [
            'fence' => $f,
            'inside' => $states->filter(fn ($s) => $s->geofence_id === $f->id && $s->inside)->count(),
            'vehicles' => $states->filter(fn ($s) => $s->geofence_id === $f->id && $s->inside)->pluck('vehicle_id')->all(),
        ])->all();
    }

    /** Crossings that should be looked at: today's events on fences set to alert, newest first. */
    /**
     * Crossings a fence is configured to alert on: 'both', only the direction chosen,
     * or nothing for a fence set to record silently.
     */
    public static function geofenceAlerts(int $days = 1, int $limit = 200): Collection
    {
        return FleetGeofenceEvent::with('geofence', 'vehicle')
            ->where('occurred_at', '>=', now()->subDays(max(1, $days))->startOfDay())
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit($limit)->get()
            ->filter(fn (FleetGeofenceEvent $e) => self::alertsOn($e->geofence?->alert_on, $e->type))
            ->values();
    }

    protected static function alertsOn(?string $alertOn, string $type): bool
    {
        if ($alertOn === 'both') {
            return true;
        }

        return in_array($alertOn, ['enter', 'exit'], true) && $alertOn === $type;
    }

    // ───────────────────────── distance & consumption ─────────────────────────

    /** Great-circle metres between two points. */
    public static function metres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }

    /**
     * Distance actually driven per the GPS trail: consecutive fixes no more than 10 minutes apart, ignoring
     * jitter under 25 m and single hops above 5 km (usually a lost-signal jump, not a straight-line drive).
     */
    public static function gpsKm(int $vehicleId, ?Carbon $from = null, ?Carbon $to = null): float
    {
        $from ??= now()->subDay();
        $to ??= now();
        $rows = VehiclePosition::where('vehicle_id', $vehicleId)->whereBetween('recorded_at', [$from, $to])
            ->orderBy('recorded_at')->orderBy('id')->get(['latitude', 'longitude', 'recorded_at']);

        $metres = 0.0;
        $prev = null;
        foreach ($rows as $p) {
            if ($prev) {
                $gap = $prev->recorded_at->diffInSeconds($p->recorded_at);
                $d = self::metres((float) $prev->latitude, (float) $prev->longitude, (float) $p->latitude, (float) $p->longitude);
                if ($gap <= 600 && $d >= 25 && $d <= 5000) {
                    $metres += $d;
                }
            }
            $prev = $p;
        }

        return round($metres / 1000, 1);
    }

    /** Fuel, other running cost and consumption for one vehicle over a period. */
    /**
     * Fills worth checking: consumption far below the vehicle's own median, an odometer that
     * runs ahead of the GPS trail, or a fill with no odometer at all so nothing can be checked.
     * The odometer rules need no GPS queries, so the dashboard can reuse this cheaply.
     *
     * @return Collection<int,array{expense:VehicleExpense,vehicle:Vehicle,reason:string,kmpl:?float,odo_delta:?int,gps_km:?float}>
     */
    public static function fuelAnomalies(?Carbon $from = null, ?Carbon $to = null, bool $withGps = true): Collection
    {
        $from ??= now()->subDays(30)->startOfDay();
        $to ??= now()->endOfDay();
        $out = collect();

        $vehicles = Vehicle::orderBy('code')->get();
        $fills = VehicleExpense::where('kind', 'fuel')
            ->whereBetween('date', [$from->copy()->subDays(120)->toDateString(), $to->toDateString()])
            ->whereIn('vehicle_id', $vehicles->pluck('id'))
            ->orderBy('date')->orderBy('id')->get()->groupBy('vehicle_id');

        foreach ($vehicles as $v) {
            $series = ($fills[$v->id] ?? collect())->filter(fn ($e) => (float) $e->litres > 0)->values();
            if ($series->isEmpty()) {
                continue;
            }

            // The vehicle's own baseline: median km per litre between consecutive odometer readings.
            $ratios = [];
            $prev = null;
            foreach ($series as $fill) {
                if ($prev && $fill->odometer && $prev->odometer && $fill->odometer > $prev->odometer) {
                    $ratios[] = ($fill->odometer - $prev->odometer) / (float) $fill->litres;
                }
                if ($fill->odometer) {
                    $prev = $fill;
                }
            }
            $median = self::median($ratios);

            $prev = null;
            foreach ($series as $fill) {
                $odoDelta = $prev && $fill->odometer && $prev->odometer ? (int) $fill->odometer - (int) $prev->odometer : null;
                $kmpl = $odoDelta && $odoDelta > 0 ? round($odoDelta / (float) $fill->litres, 2) : null;
                $inWindow = $fill->date->between($from->copy()->startOfDay(), $to->copy()->endOfDay());
                $reason = null;

                if ($inWindow && ! $fill->odometer) {
                    $reason = 'No odometer on this fill, so consumption cannot be checked.';
                } elseif ($inWindow && $odoDelta !== null && $odoDelta <= 0) {
                    $reason = 'Odometer did not advance since the previous fill.';
                } elseif ($inWindow && $median && $kmpl !== null && $kmpl < $median * 0.6) {
                    $reason = 'Burns '.number_format($kmpl, 2).' km/l against its usual '.number_format($median, 2).' km/l.';
                }

                if ($inWindow && $withGps && $odoDelta !== null && $odoDelta > 0) {
                    $gps = self::gpsKm($v->id, ($prev?->date ?? $fill->date)->copy()->startOfDay(), $fill->date->copy()->endOfDay());
                    if ($gps >= 20 && $odoDelta > $gps * 1.25 + 20) {
                        $reason = 'Odometer advanced '.number_format($odoDelta).' km but the GPS trail shows '.number_format($gps).' km.';
                    } elseif ($gps >= 20 && $odoDelta * 1.5 + 50 < $gps) {
                        $reason = 'GPS trail shows '.number_format($gps).' km but the odometer moved only '.number_format($odoDelta).' km.';
                    }
                }

                if ($reason) {
                    $out->push(['expense' => $fill, 'vehicle' => $v, 'reason' => $reason, 'kmpl' => $kmpl,
                        'odo_delta' => $odoDelta, 'gps_km' => null]);
                }

                if ($fill->odometer) {
                    $prev = $fill;
                }
            }
        }

        return $out->take(200)->values();
    }

    /**
     * How hard each vehicle is working: one grouped query over the fixes in the window.
     *
     * @return Collection<int,array{vehicle:Vehicle,fixes:int,moving:int,days:int,share:int,last_at:?Carbon}>
     */
    public static function utilisation(int $days = 30): Collection
    {
        $days = max(1, min(365, $days));
        $moving = self::cfg()['speed_moving_kph'];

        $rows = VehiclePosition::where('recorded_at', '>=', now()->subDays($days)->startOfDay())
            ->selectRaw('vehicle_id, COUNT(*) fixes, SUM(CASE WHEN speed_kph >= ? THEN 1 ELSE 0 END) moving,
                COUNT(DISTINCT DATE(recorded_at)) days, MAX(recorded_at) last_at', [$moving])
            ->groupBy('vehicle_id')->get()->keyBy('vehicle_id');

        return Vehicle::whereIn('id', $rows->keys()->all())->orderBy('code')->get()->map(function (Vehicle $v) use ($rows) {
            $r = $rows[$v->id];
            $fixes = (int) $r->fixes;

            return ['vehicle' => $v, 'fixes' => $fixes, 'moving' => (int) $r->moving, 'days' => (int) $r->days,
                'share' => $fixes ? (int) round((int) $r->moving / $fixes * 100) : 0, 'last_at' => $r->last_at ? Carbon::parse($r->last_at) : null];
        })->sortByDesc('share')->values();
    }

    /**
     * One calendar month of running cost, vehicle by vehicle, for the printable statement accounts ask
     * for at month end. Vehicles with nothing recorded in the month are left off rather than printed as
     * a row of zeroes; distance is the same GPS trail used everywhere else and is null (blank) when a car
     * reported no fixes at all, because "0 km" and "we have no trail" are different answers.
     *
     * @return array{lines:array<int,array>,totals:array,from:Carbon,to:Carbon}
     */
    public static function costStatement(Carbon $month): array
    {
        $from = $month->copy()->startOfMonth()->startOfDay();
        $to = $month->copy()->endOfMonth()->endOfDay();
        $expenses = VehicleExpense::whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get(['vehicle_id', 'kind', 'amount', 'litres']);
        $grouped = $expenses->groupBy('vehicle_id');

        $lines = [];
        foreach (Vehicle::orderBy('code')->get() as $v) {
            $rows = $grouped->get($v->id);
            if ($rows === null || $rows->isEmpty()) {
                continue;
            }
            $fuel = (float) $rows->where('kind', 'fuel')->sum(fn ($e) => (float) $e->amount);
            $service = (float) $rows->whereIn('kind', ['service', 'repair'])->sum(fn ($e) => (float) $e->amount);
            $other = (float) $rows->whereIn('kind', ['toll', 'parking', 'other'])->sum(fn ($e) => (float) $e->amount);
            $litres = (float) $rows->where('kind', 'fuel')->sum(fn ($e) => (float) $e->litres);
            $total = round($fuel + $service + $other, 2);
            $tracked = VehiclePosition::where('vehicle_id', $v->id)->whereBetween('recorded_at', [$from, $to])->exists();
            $km = $tracked ? self::gpsKm($v->id, $from->copy(), $to->copy()) : null;

            $lines[] = ['vehicle' => $v, 'fuel' => round($fuel, 2), 'service' => round($service, 2),
                'other' => round($other, 2), 'total' => $total, 'litres' => round($litres, 2),
                'fills' => $rows->where('kind', 'fuel')->count(), 'km' => $km,
                'cost_per_km' => $km !== null && $km > 0 ? round($total / $km, 2) : null];
        }

        $withKm = array_filter($lines, fn ($l) => $l['km'] !== null);
        $total = round(array_sum(array_column($lines, 'total')), 2);
        $km = round((float) array_sum(array_column($withKm, 'km')), 1);

        return [
            'lines' => $lines, 'from' => $from, 'to' => $to,
            'totals' => [
                'fuel' => round(array_sum(array_column($lines, 'fuel')), 2),
                'service' => round(array_sum(array_column($lines, 'service')), 2),
                'other' => round(array_sum(array_column($lines, 'other')), 2),
                'total' => $total,
                'litres' => round(array_sum(array_column($lines, 'litres')), 2),
                'fills' => (int) array_sum(array_column($lines, 'fills')),
                'km' => $km,
                'tracked' => count($withKm),
                // Fleet cost/km only over the cars we could actually measure, never by dividing by all of them.
                'cost_per_km' => $km > 0 ? round($total / $km, 2) : null,
            ],
        ];
    }

    /**
     * Twelve months of running cost, so the fuel screen can answer "is fleet spend rising?" next to
     * "is fuel getting dearer?". One query, bucketed in PHP, because the month key must not depend on
     * the database's date functions (MySQL and SQLite disagree).
     *
     * @return array{months:array<int,array>,total:float,peak:?array,from:Carbon}
     */
    public static function costHistory(int $months = 12): array
    {
        $months = max(3, min(24, $months));
        $from = now()->startOfMonth()->subMonths($months - 1);
        $rows = VehicleExpense::where('date', '>=', $from->toDateString())
            ->orderBy('date')->limit(20000)->get(['date', 'kind', 'amount']);

        $buckets = [];
        for ($i = 0; $i < $months; $i++) {
            $m = $from->copy()->addMonths($i);
            $buckets[$m->format('Y-m')] = ['label' => $m->format('M'), 'fuel' => 0.0, 'service' => 0.0, 'other' => 0.0, 'total' => 0.0, 'prev' => null];
        }
        foreach ($rows as $r) {
            $key = $r->date->format('Y-m');
            if (! isset($buckets[$key])) {
                continue;
            }
            $kind = in_array($r->kind, ['fuel', 'service', 'repair', 'toll', 'parking', 'other'], true) ? $r->kind : 'other';
            $bucket = in_array($kind, ['service', 'repair'], true) ? 'service' : ($kind === 'fuel' ? 'fuel' : 'other');
            $buckets[$key][$bucket] += (float) $r->amount;
        }

        $previous = null;
        foreach ($buckets as $key => $b) {
            $total = round($b['fuel'] + $b['service'] + $b['other'], 2);
            $buckets[$key] = ['label' => $b['label'], 'fuel' => round($b['fuel'], 2), 'service' => round($b['service'], 2),
                'other' => round($b['other'], 2), 'total' => $total,
                // The change is against the previous month, and only once that month actually had spend:
                // "up 400%" out of a quiet month is noise, not a trend.
                'prev' => $previous !== null && $previous > 0 ? round(($total - $previous) / $previous * 100, 1) : null];
            $previous = $total;
        }

        $list = array_values($buckets);
        $withSpend = array_values(array_filter($list, fn ($m) => $m['total'] > 0));

        return ['months' => $list, 'total' => round(array_sum(array_column($list, 'total')), 2),
            'peak' => $withSpend ? collect($withSpend)->sortByDesc('total')->first() : null, 'from' => $from];
    }

    /**
     * Running cost for one calendar month, in two aggregate queries, for the management dashboard.
     * Not the per-vehicle arithmetic: that belongs on the fleet screens. This is the money only.
     *
     * @return array{fuel:float,service:float,other:float,total:float,litres:float,fills:int,prev_total:float,delta:?float,top:?array}
     */
    public static function costSummary(?Carbon $month = null): array
    {
        $month = ($month ?? now())->copy()->startOfMonth();
        $span = [$month->toDateString(), $month->copy()->endOfMonth()->toDateString()];

        $sum = function (array $span) {
            $r = VehicleExpense::whereBetween('date', $span)->selectRaw(
                "COALESCE(SUM(CASE WHEN kind = 'fuel' THEN amount ELSE 0 END), 0) fuel,"
                ." COALESCE(SUM(CASE WHEN kind IN ('service','repair') THEN amount ELSE 0 END), 0) service,"
                ." COALESCE(SUM(CASE WHEN kind IN ('toll','parking','other') THEN amount ELSE 0 END), 0) other,"
                ." COALESCE(SUM(CASE WHEN kind = 'fuel' THEN litres ELSE 0 END), 0) litres,"
                ." COALESCE(SUM(CASE WHEN kind = 'fuel' THEN 1 ELSE 0 END), 0) fills"
            )->first();

            return ['fuel' => round((float) $r->fuel, 2), 'service' => round((float) $r->service, 2),
                'other' => round((float) $r->other, 2), 'litres' => round((float) $r->litres, 2), 'fills' => (int) $r->fills];
        };

        $now = $sum($span);
        $prev = $sum([$month->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(),
            $month->copy()->subMonthNoOverflow()->endOfMonth()->toDateString()]);

        $total = round($now['fuel'] + $now['service'] + $now['other'], 2);
        $prevTotal = round($prev['fuel'] + $prev['service'] + $prev['other'], 2);

        // Two queries, not one per vehicle: the dashboard must stay cheap.
        $topRow = VehicleExpense::whereBetween('date', $span)
            ->selectRaw('vehicle_id, SUM(amount) spend')->groupBy('vehicle_id')
            ->orderByDesc('spend')->limit(1)->first();
        $top = null;
        if ($topRow && ($v = Vehicle::find($topRow->vehicle_id))) {
            $top = ['vehicle' => $v, 'spend' => round((float) $topRow->spend, 2)];
        }

        return $now + [
            'total' => $total, 'prev_total' => $prevTotal,
            'delta' => $prevTotal > 0 ? round(($total - $prevTotal) / $prevTotal * 100, 1) : null,
            'top' => $top, 'month' => $month,
        ];
    }

    /**
     * How far a property has got with the module, as the steps a real deployment walks through.
     * Shown only while something is still missing, so it disappears instead of becoming furniture.
     *
     * @return array{steps:array<int,array<string,mixed>>,done:int,total:int,complete:bool}
     */
    public static function readiness(): array
    {
        $vehicles = Vehicle::where('is_active', true)->count();
        $trackers = VehicleTracker::where('is_active', true)->count();
        $fixes = $trackers > 0 ? VehiclePosition::count() : 0;   // do not count a table nobody writes to yet
        $plans = VehicleMaintenancePlan::where('is_active', true)->count();
        $inspections = VehicleInspection::count();
        $firstVehicle = $vehicles > 0 ? Vehicle::where('is_active', true)->orderBy('code')->first() : null;

        $steps = [
            ['key' => 'vehicles', 'label' => 'Register the vehicles', 'done' => $vehicles > 0,
                'hint' => $vehicles > 0 ? $vehicles.' active vehicle(s) on the register' : 'No active vehicle yet — the fleet register is where they start',
                'href' => route('tr.fleet')],
            ['key' => 'trackers', 'label' => 'Pair a tracking device', 'done' => $trackers > 0,
                'hint' => $trackers > 0 ? $trackers.' active device(s) paired' : 'Pairing issues the ingest token the unit needs',
                'href' => route('tr.fleet.tracking')],
            ['key' => 'fixes', 'label' => 'Receive a first position', 'done' => $fixes > 0,
                'hint' => $fixes > 0 ? number_format($fixes).' position(s) received' : 'Send one fix with the token to prove the wiring before mounting the unit',
                'href' => route('tr.fleet.tracking')],
            ['key' => 'plans', 'label' => 'Set a service plan', 'done' => $plans > 0,
                'hint' => $plans > 0 ? $plans.' active plan(s)' : 'Plans drive due dates, the workshop queue and the service forecast',
                'href' => route('tr.fleet.maintenance')],
            ['key' => 'inspections', 'label' => 'Record a pre-trip inspection', 'done' => $inspections > 0,
                'hint' => $inspections > 0 ? $inspections.' inspection(s) recorded' : 'An inspection gives a defect somewhere to go',
                'href' => $firstVehicle ? route('tr.fleet.vehicle', $firstVehicle) : route('tr.fleet')],
        ];

        $done = count(array_filter($steps, fn ($s) => $s['done']));

        return ['steps' => $steps, 'done' => $done, 'total' => count($steps), 'complete' => $done === count($steps)];
    }

    /**
     * Standing still with the engine running burns fuel and covers no ground. Two consecutive fixes
     * that both report the ignition on, at most 10 minutes apart, with the later one stationary,
     * count as idle time for that interval — a car parked overnight with the ignition off does not.
     *
     * @return array{rows:Collection<int,array>,minutes:int,litres:float,rate:float,from:Carbon,to:Carbon,gap:int}
     */
    public static function idleReport(int $days = 7, int $minMinutes = 5): array
    {
        $days = max(1, min(31, $days));
        $cfg = self::cfg();
        $from = now()->subDays($days)->startOfDay();
        $to = now();
        $still = max(1, (int) $cfg['speed_moving_kph']);
        $rate = max(0, (float) ($cfg['idle_lph'] ?? 0.9));
        $gap = 10;   // the same interval the distance filter trusts

        $rows = Vehicle::where('is_active', true)->orderBy('code')->get()->map(function (Vehicle $v) use ($from, $to, $still, $gap, $rate) {
            $fixes = VehiclePosition::where('vehicle_id', $v->id)
                ->whereBetween('recorded_at', [$from, $to])
                ->whereNotNull('speed_kph')
                ->orderBy('recorded_at')->orderBy('id')
                ->limit(20000)
                ->get(['recorded_at', 'speed_kph', 'ignition']);

            $idle = 0;
            $prev = null;
            foreach ($fixes as $f) {
                if ($prev) {
                    $mins = $prev->recorded_at->diffInMinutes($f->recorded_at);
                    $ignitionKnown = $prev->ignition !== null && $f->ignition !== null;
                    if ($ignitionKnown && $mins >= 1 && $mins <= $gap
                        && (bool) $prev->ignition && (bool) $f->ignition && (float) $f->speed_kph < $still) {
                        $idle += (int) $mins;
                    }
                }
                $prev = $f;
            }

            return ['vehicle' => $v, 'minutes' => $idle, 'litres' => round($idle / 60 * $rate, 2)];
        })->sortByDesc('minutes')->values();

        $total = (int) $rows->sum('minutes');

        return [
            'rows' => $rows->filter(fn ($r) => $r['minutes'] >= $minMinutes)->values(),
            'minutes' => $total, 'litres' => round($total / 60 * $rate, 2),
            'rate' => $rate, 'from' => $from, 'to' => $to, 'gap' => $gap,
        ];
    }

    /**
     * Fuel-efficiency league over the window, per active vehicle, using the same running-cost
     * arithmetic as the vehicle page so the two screens can never disagree. A vehicle is only
     * ranked when it has both fills and enough GPS distance to make a ratio meaningful.
     *
     * @return array{rows:Collection<int,array>,median:?float,from:Carbon,to:Carbon,threshold:float}
     */
    /**
     * Cost per kilometre, dearest first, judged against the fleet's own median. It takes the running-cost
     * rows the fuel screen has already computed from vehicle expenses and the GPS trail, so ranking them
     * costs no further queries — the expensive part of this screen stays done once.
     *
     * @param  \Illuminate\Support\Collection  $stats  vehicle id => runningCost() array
     * @return array{rows:Collection,median:?float,threshold:float,flagged:int}
     */
    public static function costLeague(Collection $stats): array
    {
        // One query for the vehicles on the board, not one per row: the caller already paid for the costs.
        $vehicles = Vehicle::whereIn('id', $stats->keys()->all())->get()->keyBy('id');
        $rows = $stats->map(fn (array $cost, $id) => ['vehicle' => $vehicles->get($id)] + $cost)
            ->filter(fn ($r) => $r['vehicle'] !== null && $r['total'] > 0 && $r['km'] > 0)
            ->values();

        $median = self::median($rows->pluck('cost_per_km')->filter()->values()->all());
        $rows = $rows->map(function ($r) use ($median) {
            $r['vs'] = $median ? round(($r['cost_per_km'] - $median) / $median * 100, 1) : null;
            // Dearer than the fleet's own median by more than a quarter, and only once the car has
            // covered enough ground for the ratio to mean anything.
            $r['flagged'] = (bool) ($median && $r['km'] >= 50 && $r['cost_per_km'] > $median * 1.25);

            return $r;
        })->sortByDesc('cost_per_km')->values();

        return ['rows' => $rows, 'median' => $median, 'threshold' => 1.25, 'flagged' => $rows->where('flagged', true)->count()];
    }

    public static function efficiencyBoard(int $days = 90): array
    {
        $days = max(7, min(365, $days));
        $from = now()->subDays($days)->startOfDay();
        $to = now()->endOfDay();

        $rows = Vehicle::where('is_active', true)->orderBy('code')->limit(60)->get()->map(function (Vehicle $v) use ($from, $to) {
            $cost = self::runningCost($v, $from->copy(), $to->copy());

            return ['vehicle' => $v] + $cost;
        })->filter(fn ($r) => $r['litres'] > 0 && $r['km'] > 0);

        $median = self::median($rows->pluck('km_per_litre')->filter()->values()->all());
        $rows = $rows->map(function ($r) use ($median) {
            $r['vs'] = $median ? round(($r['km_per_litre'] - $median) / $median * 100, 1) : null;
            // A car more than a quarter below the fleet's own median is worth a look, but only
            // once it has covered enough ground for the ratio to mean something.
            $r['flagged'] = $median && $r['km'] >= 50 && $r['km_per_litre'] < $median * 0.75;

            return $r;
        })->sortBy('km_per_litre')->values();

        return ['rows' => $rows, 'median' => $median, 'from' => $from, 'to' => $to, 'threshold' => 0.75];
    }

    /**
     * Which cars have not been inspected recently. A vehicle with no inspection at all is the
     * most overdue, so it sorts first — a blank history is a gap, not a clean record.
     *
     * @return Collection<int,array{vehicle:Vehicle,last:?VehicleInspection,days:?int,due:bool}>
     */
    /**
     * The incident log: live incidents first, newest first, with the three numbers the screen and the
     * dashboard need. Nothing here is money that was spent — `estimated_cost` is what the property
     * expects a claim to come to, and the screen labels it that way.
     *
     * @return array{rows:Collection,open:int,serious:int,estimate:float}
     */
    public static function incidents(int $limit = 100): array
    {
        $rows = VehicleIncident::with(['vehicle', 'driver'])
            ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'investigating' THEN 1 ELSE 2 END")
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->limit(max(20, min(300, $limit)))->get();

        $live = $rows->filter(fn (VehicleIncident $i) => $i->isOpen());

        return ['rows' => $rows, 'open' => $live->count(),
            'serious' => $live->whereIn('severity', ['major', 'critical'])->count(),
            'estimate' => round((float) $live->sum(fn (VehicleIncident $i) => (float) ($i->estimated_cost ?? 0)), 2)];
    }

    public static function inspectionDue(int $days = 7): Collection
    {
        $days = max(1, min(365, $days));
        $vehicles = Vehicle::where('is_active', true)->orderBy('code')->get();
        $last = VehicleInspection::orderByDesc('inspected_at')->orderByDesc('id')->get()
            ->groupBy('vehicle_id')->map->first();

        return $vehicles->map(function (Vehicle $v) use ($last, $days) {
            $row = $last->get($v->id);
            $age = $row ? (int) $row->inspected_at->startOfDay()->diffInDays(now()->startOfDay()) : null;

            return ['vehicle' => $v, 'last' => $row, 'days' => $age, 'due' => $age === null || $age >= $days];
        })->sortByDesc(fn ($r) => $r['days'] === null ? 100000 : $r['days'])->values();
    }

    public static function recordInspection(Vehicle $vehicle, array $d): VehicleInspection
    {
        $checks = array_values(array_intersect(array_keys(VehicleInspection::CHECKS), (array) ($d['checks'] ?? [])));
        $inspection = VehicleInspection::create([
            'vehicle_id' => $vehicle->id,
            'inspected_at' => Carbon::parse($d['inspected_at'] ?? now()),
            'odometer' => $d['odometer'] ?? null,
            'inspector' => ($i = trim((string) ($d['inspector'] ?? ''))) !== '' ? mb_substr($i, 0, 100) : auth()->user()?->name,
            'checks' => $checks,
            'result' => $d['result'] ?? 'pass',
            'defects' => ($n = trim((string) ($d['defects'] ?? ''))) !== '' ? mb_substr($n, 0, 1000) : null,
            'created_by' => auth()->id(),
        ]);

        if ($inspection->odometer && $inspection->odometer > (int) $vehicle->odometer) {
            $vehicle->forceFill(['odometer' => $inspection->odometer])->save();
        }

        // An unsafe car is taken off the dispatch board immediately, and a defect can open a job.
        if ($inspection->isUnsafe()) {
            $vehicle->forceFill(['status' => 'maintenance'])->save();
        }
        if (! empty($d['raise_work_order']) && $inspection->result !== 'pass') {
            $record = self::storeRecord($vehicle, [
                'category' => 'inspection',
                'priority' => $inspection->isUnsafe() ? 'urgent' : 'normal',
                'title' => 'Defect from pre-trip inspection',
                'description' => $inspection->defects ?: 'Reported during the '.$inspection->inspected_at->format('d M Y H:i').' inspection.',
                'due_odometer' => $inspection->odometer,
                'block_vehicle' => $inspection->isUnsafe(),
            ]);
            $inspection->forceFill(['work_order_id' => $record->id])->save();
        }

        return $inspection;
    }

    /**
     * Trips checked against the GPS trail: what the desk billed from the odometer versus what the
     * vehicle actually covered while the trip was running. Only completed trips with both an
     * odometer reading and a recorded window can be checked; a trip the desk never started, or one
     * without odometer readings, is counted separately instead of being flagged.
     *
     * @return array{rows:Collection,checked:int,flagged:int,unchecked:int,from:Carbon,to:Carbon}
     */
    public static function tripVerification(int $days = 30, int $limit = 20, ?int $vehicleId = null): array
    {
        $days = max(1, min(365, $days));
        $from = now()->subDays($days)->startOfDay();
        $to = now();

        $trips = TransportTrip::with('vehicle', 'driver')
            ->whereBetween('scheduled_at', [$from, $to])
            ->where('status', 'completed')
            ->when($vehicleId, fn ($q, $id) => $q->where('vehicle_id', $id))
            ->orderByDesc('scheduled_at')->orderByDesc('id')->limit(max(1, min(200, $limit)))->get();

        $rows = collect();
        $unchecked = 0;
        foreach ($trips as $t) {
            $start = $t->started_at ?? $t->busy_from;
            $end = $t->completed_at ?? $t->busy_to;
            $billed = $t->km();
            if (! $t->vehicle_id || ! $start || ! $end || ! $end->greaterThan($start) || $billed === null) {
                $unchecked++;

                continue;
            }
            $gps = self::gpsKm((int) $t->vehicle_id, $start->copy(), $end->copy());
            $diff = round($gps - $billed, 1);
            $flagged = $gps >= 2 && abs($diff) > max(3, $gps * 0.25);
            $reason = null;
            if ($flagged) {
                $reason = $diff > 0
                    ? 'The trail shows '.number_format($gps, 1).' km against '.number_format($billed).' km billed – a detour, or a trip that ended early on paper.'
                    : 'Billed '.number_format($billed).' km but the trail shows only '.number_format($gps, 1).' km – check the odometer readings.';
            }

            $rows->push(['trip' => $t, 'billed' => (int) $billed, 'gps' => $gps, 'diff' => $diff, 'flagged' => $flagged, 'reason' => $reason]);
        }

        $checked = $rows->count();

        return ['rows' => $rows->sortByDesc(fn ($r) => $r['flagged'])->values(), 'checked' => $checked,
            'flagged' => $rows->where('flagged', true)->count(), 'unchecked' => $unchecked, 'from' => $from, 'to' => $to];
    }

    /**
     * Workshop throughput and downtime from closed work orders, plus the ageing of the open ones.
     * Days off road are counted from started_at to completed_at only when both exist — a job the
     * desk never started has no downtime to report.
     *
     * @return array{closed:int,open:int,off_road_days:int,avg_days:?float,cost:float,avg_cost:?float,
     *     categories:array<string,int>,vendors:Collection,oldest:?VehicleMaintenanceRecord,avg_open_days:?float}
     */
    public static function workshopStats(int $days = 90): array
    {
        $days = max(1, min(730, $days));
        $from = now()->subDays($days)->startOfDay();

        $closed = VehicleMaintenanceRecord::with('vehicle')->where('status', 'completed')
            ->where('completed_at', '>=', $from)->get();
        $open = VehicleMaintenanceRecord::with('vehicle')->whereIn('status', ['scheduled', 'in_progress'])
            ->orderBy('due_date')->orderBy('id')->get();

        $offRoad = 0;
        $timed = 0;
        foreach ($closed as $r) {
            if ($r->started_at && $r->completed_at && $r->completed_at->greaterThan($r->started_at)) {
                $offRoad += (int) $r->started_at->diffInDays($r->completed_at);
                $timed++;
            }
        }
        $cost = (float) $closed->sum(fn ($r) => (float) $r->actual_cost);

        return [
            'closed' => $closed->count(),
            'open' => $open->count(),
            'off_road_days' => $offRoad,
            'avg_days' => $timed ? round($offRoad / $timed, 1) : null,
            'cost' => round($cost, 2),
            'avg_cost' => $closed->count() ? round($cost / $closed->count(), 2) : null,
            'categories' => $closed->groupBy('category')->map->count()->sortDesc()->all(),
            'vendors' => $closed->filter(fn ($r) => (string) $r->vendor !== '')
                ->groupBy(fn ($r) => mb_substr(trim((string) $r->vendor), 0, 60))
                ->map(fn ($rows, $vendor) => ['vendor' => $vendor, 'jobs' => $rows->count(), 'cost' => round((float) $rows->sum(fn ($r) => (float) $r->actual_cost), 2)])
                ->sortByDesc('cost')->take(5)->values(),
            'oldest' => $open->first(),
            'avg_open_days' => $open->count() ? round($open->avg(fn ($r) => $r->due_date ? $r->due_date->diffInDays(now()->startOfDay(), false) : 0), 1) : null,
        ];
    }

    /**
     * What fuel actually cost over time and per supplier, from the fills the desk recorded.
     *
     * @return array{months:array<int,array{label:string,litres:float,spend:float,price:?float}>,
     *     vendors:Collection,total_litres:float,total_spend:float}
     */
    public static function fuelProcurement(int $months = 6): array
    {
        $months = max(1, min(24, $months));
        $from = now()->startOfMonth()->subMonths($months - 1);
        $rows = VehicleExpense::where('kind', 'fuel')->where('date', '>=', $from->toDateString())
            ->orderBy('date')->limit(5000)->get(['date', 'litres', 'amount', 'vendor']);

        $buckets = [];
        for ($i = 0; $i < $months; $i++) {
            $m = $from->copy()->addMonths($i);
            $buckets[$m->format('Y-m')] = ['label' => $m->format('M'), 'litres' => 0.0, 'spend' => 0.0, 'price' => null];
        }
        foreach ($rows as $r) {
            $key = $r->date->format('Y-m');
            if (! isset($buckets[$key])) {
                continue;
            }
            $buckets[$key]['litres'] += (float) $r->litres;
            $buckets[$key]['spend'] += (float) $r->amount;
        }
        foreach ($buckets as $key => $b) {
            $buckets[$key]['litres'] = round($b['litres'], 2);
            $buckets[$key]['spend'] = round($b['spend'], 2);
            $buckets[$key]['price'] = $b['litres'] > 0 ? round($b['spend'] / $b['litres'], 2) : null;
        }

        $vendors = $rows->filter(fn ($r) => (string) $r->vendor !== '')
            ->groupBy(fn ($r) => mb_substr(trim((string) $r->vendor), 0, 60))
            ->map(fn ($list, $vendor) => [
                'vendor' => $vendor,
                'fills' => $list->count(),
                'litres' => round((float) $list->sum(fn ($r) => (float) $r->litres), 2),
                'spend' => round((float) $list->sum(fn ($r) => (float) $r->amount), 2),
                'price' => (float) $list->sum(fn ($r) => (float) $r->litres) > 0
                    ? round((float) $list->sum(fn ($r) => (float) $r->amount) / (float) $list->sum(fn ($r) => (float) $r->litres), 2) : null,
            ])->sortBy('price')->values()->take(8);

        return ['months' => array_values($buckets), 'vendors' => $vendors,
            'total_litres' => round((float) $rows->sum(fn ($r) => (float) $r->litres), 2),
            'total_spend' => round((float) $rows->sum(fn ($r) => (float) $r->amount), 2)];
    }

    /**
     * What the workshop will need in the next N days, whether the plan is driven by time or by
     * kilometres. The kilometre side is projected from the vehicle's own GPS distance over the
     * last 30 days, so a car that sits still is not forecast to need a service next week.
     *
     * @return Collection<int,array{plan:VehicleMaintenancePlan,vehicle:Vehicle,due_date:?Carbon,due_odometer:?int,
     *     km_left:?int,days_left:?int,per_day:float,projected:?Carbon,urgent:bool}>
     */
    public static function serviceForecast(int $days = 60): Collection
    {
        $days = max(1, min(365, $days));
        $plans = VehicleMaintenancePlan::with('vehicle')->where('is_active', true)
            ->where(fn ($q) => $q->whereNotNull('due_date')->orWhereNotNull('due_odometer'))->get();

        $perDay = [];
        $rows = $plans->map(function (VehicleMaintenancePlan $plan) use (&$perDay, $days) {
            $v = $plan->vehicle;
            if (! $v) {
                return null;
            }
            if (! array_key_exists($v->id, $perDay)) {
                $perDay[$v->id] = round(self::gpsKm($v->id, now()->subDays(30), now()) / 30, 1);
            }
            $kmLeft = $plan->due_odometer !== null ? (int) $plan->due_odometer - (int) $v->odometer : null;
            $daysLeft = $plan->due_date ? (int) now()->startOfDay()->diffInDays($plan->due_date->copy()->startOfDay(), false) : null;
            $byKm = $kmLeft !== null && $perDay[$v->id] > 0 ? now()->copy()->addDays((int) ceil($kmLeft / $perDay[$v->id])) : null;

            $projected = null;
            foreach ([$plan->due_date?->copy(), $byKm] as $candidate) {
                if ($candidate && (! $projected || $candidate->lt($projected))) {
                    $projected = $candidate;
                }
            }

            $within = ($plan->due_date && $daysLeft !== null && $daysLeft <= $days)
                || ($kmLeft !== null && $kmLeft <= self::cfg()['maintenance_lead_km'])
                || ($projected && $projected->lte(now()->addDays($days)));
            if (! $within) {
                return null;
            }

            return ['plan' => $plan, 'vehicle' => $v, 'due_date' => $plan->due_date, 'due_odometer' => $plan->due_odometer,
                'km_left' => $kmLeft, 'days_left' => $daysLeft, 'per_day' => (float) $perDay[$v->id], 'projected' => $projected,
                'urgent' => ($daysLeft !== null && $daysLeft <= 0) || ($kmLeft !== null && $kmLeft <= 0)];
        })->filter()->values();

        return $rows->sortBy(fn ($r) => [$r['urgent'] ? 0 : 1, $r['days_left'] ?? 9999, $r['km_left'] ?? 999999])->values();
    }

    /**
     * Expired and soon-to-expire papers across the active fleet, using the same window as the
     * transport desk so the two screens never disagree.
     *
     * @return array{expired:Collection,soon:Collection,days:int}
     */
    public static function paperWatch(): array
    {
        $days = (int) (\App\Services\TransportService::cfg()['paper_days'] ?? 30);
        $vehicles = Vehicle::where('is_active', true)->orderBy('code')->get();

        $expired = $vehicles->map(fn (Vehicle $v) => ['vehicle' => $v, 'papers' => $v->expiredPapers()])
            ->filter(fn ($r) => $r['papers'] !== [])->values();
        $soon = $vehicles->map(fn (Vehicle $v) => ['vehicle' => $v, 'papers' => $v->expiringPapers($days)])
            ->filter(fn ($r) => $r['papers'] !== [])->values();

        return ['expired' => $expired, 'soon' => $soon, 'days' => $days];
    }

    /**
     * Driver scorecard: what each chauffeur actually drove, whether trips started on time, and
     * the speeding fixes that arrived while one of their trips was running. A fix is only
     * attributed when a trip for that vehicle has a recorded window around it – everything else
     * is reported as vehicle-only rather than pinned on a person.
     *
     * @return array{rows:Collection,unattributed:int,limit:int,from:Carbon,to:Carbon}
     */
    public static function driverScorecard(int $days = 30, int $graceMinutes = 15): array
    {
        $from = now()->subDays(max(1, min(365, $days)))->startOfDay();
        $to = now();
        $limit = self::cfg()['speed_limit_kph'];

        $trips = TransportTrip::with('driver', 'vehicle')
            ->whereBetween('scheduled_at', [$from, $to])
            ->whereNotNull('driver_id')->where('status', '!=', 'cancelled')->orderBy('scheduled_at')->get();

        $speeds = VehiclePosition::whereBetween('recorded_at', [$from, $to])
            ->whereNotNull('speed_kph')->where('speed_kph', '>', $limit)
            ->orderBy('recorded_at')->get(['vehicle_id', 'speed_kph', 'recorded_at']);

        // Windows are taken from what the desk recorded, not from the plan.
        $windows = [];
        foreach ($trips as $t) {
            $start = $t->started_at ?? $t->busy_from;
            $end = $t->completed_at ?? $t->busy_to ?? ($start ? $start->copy()->addHours(4) : null);
            if ($start && $end && $end->greaterThan($start) && $t->vehicle_id) {
                $windows[] = ['trip' => $t, 'vehicle_id' => (int) $t->vehicle_id, 'from' => $start, 'to' => $end];
            }
        }

        $byDriver = [];
        $unattributed = 0;
        foreach ($speeds as $p) {
            $hit = null;
            foreach ($windows as $w) {
                if ($w['vehicle_id'] === (int) $p->vehicle_id && $p->recorded_at->between($w['from'], $w['to'])) {
                    $hit = $w['trip'];
                    break;
                }
            }
            if (! $hit) {
                $unattributed++;

                continue;
            }
            $id = (int) $hit->driver_id;
            $byDriver[$id] = $byDriver[$id] ?? ['fixes' => 0, 'fastest' => 0.0];
            $byDriver[$id]['fixes']++;
            $byDriver[$id]['fastest'] = max($byDriver[$id]['fastest'], (float) $p->speed_kph);
        }

        $rows = $trips->groupBy('driver_id')->map(function ($list, $id) use ($byDriver, $graceMinutes) {
            $driver = $list->first()->driver;
            $completed = $list->whereIn('status', ['completed', 'onboard', 'en_route']);
            $late = $list->filter(fn ($t) => $t->started_at && $t->scheduled_at
                && $t->started_at->greaterThan($t->scheduled_at->copy()->addMinutes($graceMinutes)))->count();

            return ['driver' => $driver, 'trips' => $list->count(), 'completed' => $completed->count(),
                'km' => (int) $list->sum(fn ($t) => (int) ($t->km() ?? 0)), 'late' => $late,
                'speeding' => $byDriver[$id]['fixes'] ?? 0, 'fastest' => round($byDriver[$id]['fastest'] ?? 0, 0),
                'last' => $list->sortByDesc('scheduled_at')->first()->scheduled_at];
        })->filter(fn ($r) => $r['driver'] !== null)
            ->sortBy([['speeding', 'desc'], ['trips', 'desc']])->values();

        return ['rows' => $rows->take(12), 'unattributed' => $unattributed, 'limit' => $limit, 'from' => $from, 'to' => $to];
    }

    /** @param array<int,float|int> $values */
    protected static function median(array $values): ?float
    {
        $n = count($values);
        if ($n === 0) {
            return null;
        }
        sort($values);
        $mid = intdiv($n, 2);

        return $n % 2 ? (float) $values[$mid] : round(((float) $values[$mid - 1] + (float) $values[$mid]) / 2, 4);
    }

    /**
     * Fixes above the configured speed limit, aggregated per vehicle so a busy month
     * never loads every position row into PHP.
     *
     * @return array<int,array{vehicle:Vehicle,fixes:int,max:float,over:float}>
     */
    public static function speedBreaches(?Carbon $from = null, ?Carbon $to = null): Collection
    {
        $from ??= now()->subDays(7)->startOfDay();
        $to ??= now();
        $limit = self::cfg()['speed_limit_kph'];

        $rows = VehiclePosition::whereBetween('recorded_at', [$from, $to])
            ->whereNotNull('speed_kph')->where('speed_kph', '>', $limit)
            ->selectRaw('vehicle_id, COUNT(*) n, MAX(speed_kph) top, AVG(speed_kph) avg, MAX(recorded_at) last_at')
            ->groupBy('vehicle_id')->get()->keyBy('vehicle_id');

        return Vehicle::whereIn('id', $rows->keys()->all())->orderBy('code')->get()->map(fn (Vehicle $v) => [
            'vehicle' => $v, 'fixes' => (int) $rows[$v->id]->n, 'max' => round((float) $rows[$v->id]->top, 0),
            'over' => round((float) $rows[$v->id]->avg - $limit, 1), 'last_at' => Carbon::parse($rows[$v->id]->last_at),
        ]);
    }

    /**
     * Vehicles that moved today without a trip on the board – private use, an unrecorded
     * transfer, or a guard moving a car. Uses the same GPS distance as the cost report.
     *
     * @return Collection<int,array{vehicle:Vehicle,km:float,last_at:?Carbon,trip_count:int}>
     */
    public static function offTripMovement(?Carbon $day = null): Collection
    {
        $from = ($day ?? now())->copy()->startOfDay();
        $to = $from->copy()->endOfDay();

        $trips = TransportTrip::whereBetween('scheduled_at', [$from, $to])
            ->where('status', '!=', 'cancelled')
            ->selectRaw('vehicle_id, COUNT(*) n')->whereNotNull('vehicle_id')->groupBy('vehicle_id')->pluck('n', 'vehicle_id');

        return Vehicle::where('is_active', true)
            ->orderBy('code')->get()
            ->reject(fn (Vehicle $v) => $trips->has($v->id))
            ->map(function (Vehicle $v) use ($from, $to) {
                $km = self::gpsKm($v->id, $from, $to);
                if ($km < 1.0) {
                    return null;
                }
                $last = VehiclePosition::where('vehicle_id', $v->id)->whereBetween('recorded_at', [$from, $to])->max('recorded_at');

                return ['vehicle' => $v, 'km' => $km, 'last_at' => $last ? Carbon::parse($last) : null, 'trip_count' => 0];
            })->filter()->values();
    }

    public static function runningCost(Vehicle $vehicle, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $from ??= now()->startOfMonth();
        $to ??= now();
        $rows = VehicleExpense::where('vehicle_id', $vehicle->id)->whereBetween('date', [$from->toDateString(), $to->toDateString()])->get();
        $litres = (float) $rows->where('kind', 'fuel')->sum(fn ($e) => (float) $e->litres);
        $fuelCost = (float) $rows->where('kind', 'fuel')->sum(fn ($e) => (float) $e->amount);
        $serviceCost = (float) $rows->whereIn('kind', ['service', 'repair'])->sum(fn ($e) => (float) $e->amount);
        $otherCost = (float) $rows->whereIn('kind', ['toll', 'parking', 'other'])->sum(fn ($e) => (float) $e->amount);
        $km = self::gpsKm($vehicle->id, $from->copy()->startOfDay(), $to->copy()->endOfDay());

        return [
            'litres' => round($litres, 2), 'fuel' => round($fuelCost, 2), 'service' => round($serviceCost, 2), 'other' => round($otherCost, 2),
            'total' => round($fuelCost + $serviceCost + $otherCost, 2), 'km' => $km,
            'km_per_litre' => $litres > 0 ? round($km / $litres, 2) : null,
            'cost_per_km' => $km > 0 ? round(($fuelCost + $serviceCost + $otherCost) / $km, 2) : null,
            'fills' => $rows->where('kind', 'fuel')->count(),
        ];
    }

    // ───────────────────────── maintenance ─────────────────────────

    /** Plans and work orders that need attention for one vehicle. */
    public static function maintenanceState(Vehicle $vehicle): array
    {
        $c = self::cfg();
        $plans = VehicleMaintenancePlan::where('vehicle_id', $vehicle->id)->where('is_active', true)->get();
        $overdue = collect();
        $soon = collect();
        foreach ($plans as $p) {
            if ($p->isDue($vehicle->odometer)) {
                $overdue->push($p);
            } elseif ($p->due_date && $p->due_date->lte(now()->addDays($c['maintenance_lead_days']))
                || ($p->due_odometer && $vehicle->odometer && $p->due_odometer - $vehicle->odometer <= $c['maintenance_lead_km'] && $p->due_odometer > $vehicle->odometer)) {
                $soon->push($p);
            }
        }
        $open = VehicleMaintenanceRecord::where('vehicle_id', $vehicle->id)->whereIn('status', ['scheduled', 'in_progress'])->orderBy('due_date')->get();

        return ['plans' => $plans, 'overdue' => $overdue, 'soon' => $soon, 'open' => $open,
            'blocking' => $open->whereIn('priority', ['high', 'urgent'])->count()];
    }

    public static function storePlan(Vehicle $vehicle, array $d): VehicleMaintenancePlan
    {
        $intervalDays = isset($d['interval_days']) && $d['interval_days'] !== '' ? max(1, (int) $d['interval_days']) : null;
        $intervalKm = isset($d['interval_km']) && $d['interval_km'] !== '' ? max(1, (int) $d['interval_km']) : null;
        if (! $intervalDays && ! $intervalKm) {
            throw new RuntimeException('Give the service an interval in days, in km, or both.');
        }
        $lastDate = ! empty($d['last_completed_date']) ? Carbon::parse($d['last_completed_date'])->startOfDay() : null;
        $lastOdo = isset($d['last_completed_odometer']) && $d['last_completed_odometer'] !== '' ? (int) $d['last_completed_odometer'] : null;

        return VehicleMaintenancePlan::create([
            'vehicle_id' => $vehicle->id,
            'name' => mb_substr(trim((string) $d['name']), 0, 120),
            'category' => array_key_exists($d['category'] ?? '', VehicleMaintenancePlan::CATEGORIES) ? $d['category'] : 'preventive',
            'interval_days' => $intervalDays,
            'interval_km' => $intervalKm,
            'last_completed_date' => $lastDate,
            'last_completed_odometer' => $lastOdo,
            'due_date' => $intervalDays ? ($lastDate ?? now()->startOfDay())->copy()->addDays($intervalDays) : null,
            'due_odometer' => $intervalKm ? ($lastOdo ?? (int) $vehicle->odometer) + $intervalKm : null,
            'notes' => ($n = trim((string) ($d['notes'] ?? ''))) !== '' ? mb_substr($n, 0, 500) : null,
            'created_by' => auth()->id(),
        ]);
    }

    public static function storeRecord(Vehicle $vehicle, array $d): VehicleMaintenanceRecord
    {
        $record = VehicleMaintenanceRecord::create([
            'vehicle_id' => $vehicle->id,
            'plan_id' => ! empty($d['plan_id']) ? VehicleMaintenancePlan::where('vehicle_id', $vehicle->id)->find((int) $d['plan_id'])?->id : null,
            'category' => array_key_exists($d['category'] ?? '', VehicleMaintenanceRecord::CATEGORIES) ? $d['category'] : 'repair',
            'status' => 'scheduled',
            'priority' => array_key_exists($d['priority'] ?? '', VehicleMaintenanceRecord::PRIORITIES) ? $d['priority'] : 'normal',
            'title' => mb_substr(trim((string) $d['title']), 0, 140),
            'description' => ($x = trim((string) ($d['description'] ?? ''))) !== '' ? mb_substr($x, 0, 2000) : null,
            'due_date' => ! empty($d['due_date']) ? Carbon::parse($d['due_date'])->toDateString() : null,
            'due_odometer' => isset($d['due_odometer']) && $d['due_odometer'] !== '' ? (int) $d['due_odometer'] : null,
            'vendor' => ($v = trim((string) ($d['vendor'] ?? ''))) !== '' ? mb_substr($v, 0, 120) : null,
            'estimated_cost' => round(max(0, (float) ($d['estimated_cost'] ?? 0)), 2),
            'created_by' => auth()->id(),
        ]);
        if (! empty($d['block_vehicle'])) {
            self::takeOffRoad($vehicle, 'Workshop: '.$record->title);
        }
        ActivityLogger::log('transport', 'maintenance', "Work order for {$vehicle->code}: {$record->title}", $record);

        return $record;
    }

    public static function startRecord(VehicleMaintenanceRecord $record): VehicleMaintenanceRecord
    {
        if ($record->status !== 'scheduled') {
            throw new RuntimeException('Only a scheduled job can be started.');
        }
        $record->forceFill(['status' => 'in_progress', 'started_at' => now()])->save();
        self::takeOffRoad($record->vehicle, 'Workshop: '.$record->title);
        ActivityLogger::log('transport', 'maintenance_start', "{$record->vehicle->code} in the workshop: {$record->title}", $record);

        return $record;
    }

    /**
     * Complete a job, roll the plan forward, and (optionally) log the supplier invoice as a vehicle running cost.
     */
    public static function completeRecord(VehicleMaintenanceRecord $record, array $d): VehicleMaintenanceRecord
    {
        if (! in_array($record->status, ['scheduled', 'in_progress'], true)) {
            throw new RuntimeException('This job is already '.VehicleMaintenanceRecord::STATUSES[$record->status].'.');
        }
        $vehicle = $record->vehicle;
        $odo = isset($d['odometer']) && $d['odometer'] !== '' ? (int) $d['odometer'] : (int) $vehicle->odometer;
        $cost = round(max(0, (float) ($d['actual_cost'] ?? 0)), 2);

        return DB::transaction(function () use ($record, $d, $vehicle, $odo, $cost) {
            $record->forceFill([
                'status' => 'completed', 'completed_at' => now(), 'completed_by' => auth()->id(),
                'odometer' => $odo, 'actual_cost' => $cost,
                'vendor' => ($v = trim((string) ($d['vendor'] ?? $record->vendor ?? ''))) !== '' ? mb_substr($v, 0, 120) : null,
                'invoice_ref' => ($i = trim((string) ($d['invoice_ref'] ?? ''))) !== '' ? mb_substr($i, 0, 80) : null,
                'notes' => ($n = trim((string) ($d['notes'] ?? $record->notes ?? ''))) !== '' ? mb_substr($n, 0, 500) : null,
            ])->save();

            if ($record->plan_id && ($plan = VehicleMaintenancePlan::find($record->plan_id))) {
                $plan->forceFill([
                    'last_completed_date' => now()->toDateString(),
                    'last_completed_odometer' => $odo,
                    'due_date' => $plan->interval_days ? now()->startOfDay()->addDays($plan->interval_days)->toDateString() : null,
                    'due_odometer' => $plan->interval_km ? $odo + $plan->interval_km : null,
                ])->save();
            }

            if ($cost > 0 && ! empty($d['log_expense'])) {
                VehicleExpense::create([
                    'vehicle_id' => $vehicle->id, 'maintenance_record_id' => $record->id,
                    'date' => now()->toDateString(), 'kind' => $record->category === 'repair' ? 'repair' : 'service',
                    'amount' => $cost, 'odometer' => $odo, 'vendor' => $record->vendor,
                    'notes' => mb_substr('Work order: '.$record->title, 0, 255), 'created_by' => auth()->id(),
                ]);
            }

            if ($odo > (int) $vehicle->odometer) {
                $vehicle->forceFill(['odometer' => $odo])->save();
            }
            if ($vehicle->status === 'maintenance' && ! VehicleMaintenanceRecord::where('vehicle_id', $vehicle->id)
                ->whereIn('status', ['scheduled', 'in_progress'])->where('priority', '!=', 'low')->exists()) {
                $vehicle->forceFill(['status' => 'available'])->save();
            }
            ActivityLogger::log('transport', 'maintenance_done', "{$vehicle->code} completed: {$record->title}".($cost > 0 ? ' · '.money($cost) : ''), $record);

            return $record->fresh();
        });
    }

    public static function cancelRecord(VehicleMaintenanceRecord $record, string $reason): VehicleMaintenanceRecord
    {
        if (! in_array($record->status, ['scheduled', 'in_progress'], true)) {
            throw new RuntimeException('This job is already '.VehicleMaintenanceRecord::STATUSES[$record->status].'.');
        }
        $record->forceFill(['status' => 'cancelled', 'notes' => mb_substr(trim($record->notes.' Cancelled: '.$reason), 0, 500)])->save();
        $vehicle = $record->vehicle;
        if ($vehicle->status === 'maintenance' && ! VehicleMaintenanceRecord::where('vehicle_id', $vehicle->id)->whereIn('status', ['scheduled', 'in_progress'])->exists()) {
            $vehicle->forceFill(['status' => 'available'])->save();
        }
        ActivityLogger::log('transport', 'maintenance_cancel', "{$vehicle->code} work order cancelled: {$record->title}", $record);

        return $record;
    }

    protected static function takeOffRoad(Vehicle $vehicle, string $reason): void
    {
        if ($vehicle->status !== 'available') {
            return;
        }
        $vehicle->forceFill(['status' => 'maintenance', 'notes' => mb_substr(trim(($vehicle->notes ? $vehicle->notes.' · ' : '').$reason), 0, 500)])->save();
    }

    // ───────────────────────── alerts ─────────────────────────

    public static function alerts(): array
    {
        $c = self::cfg();
        $today = now()->toDateString();
        $soonDate = now()->addDays($c['maintenance_lead_days'])->toDateString();
        $out = ['gps_offline' => 0, 'no_signal' => 0, 'maintenance_overdue' => 0, 'maintenance_soon' => 0, 'open_repairs' => 0,
            'geofence_today' => 0, 'speed_today' => 0, 'off_trip_today' => 0, 'fuel_checks' => 0,
            'papers_expired' => 0, 'papers_soon' => 0, 'restricted_today' => 0, 'inspection_due' => 0,
            'incidents_open' => 0, 'vehicles' => 0];

        // Aggregated so the dashboard never walks the fleet row by row.
        $out['vehicles'] = Vehicle::where('is_active', true)->count();

        $plans = VehicleMaintenancePlan::query()
            ->join('vehicles', 'vehicles.id', '=', 'vehicle_maintenance_plans.vehicle_id')
            ->where('vehicle_maintenance_plans.is_active', true)
            ->get(['vehicle_maintenance_plans.due_date', 'vehicle_maintenance_plans.due_odometer', 'vehicles.odometer']);

        foreach ($plans as $plan) {
            $dueByDate = $plan->due_date && $plan->due_date->toDateString() <= $today;
            $dueByKm = $plan->due_odometer !== null && (int) $plan->odometer !== null && $plan->due_odometer <= (int) $plan->odometer;
            if ($dueByDate || $dueByKm) {
                $out['maintenance_overdue']++;

                continue;
            }
            $soonByDate = $plan->due_date && $plan->due_date->toDateString() <= $soonDate;
            $soonByKm = $plan->due_odometer !== null && (int) $plan->odometer !== null
                && $plan->due_odometer > (int) $plan->odometer && $plan->due_odometer - (int) $plan->odometer <= $c['maintenance_lead_km'];
            if ($soonByDate || $soonByKm) {
                $out['maintenance_soon']++;
            }
        }

        $out['open_repairs'] = VehicleMaintenanceRecord::whereIn('status', ['scheduled', 'in_progress'])->count();

        $out['geofence_today'] = self::geofenceAlerts(1)->count();
        $out['fuel_checks'] = self::fuelAnomalies(now()->subDays(30), now()->endOfDay(), false)->count();

        $out['inspection_due'] = self::inspectionDue((int) self::cfg()['inspection_days'])->where('due', true)->count();
        // Live incidents, counted in one query: the dashboard must not walk the incident log.
        $out['incidents_open'] = VehicleIncident::whereIn('status', ['open', 'investigating'])->count();

        $papers = self::paperWatch();
        $out['papers_expired'] = $papers['expired']->count();
        $out['papers_soon'] = $papers['soon']->count();

        // Behaviour counts stay aggregated: one grouped query each, no per-vehicle loop.
        $out['speed_today'] = VehiclePosition::where('recorded_at', '>=', now()->startOfDay())
            ->whereNotNull('speed_kph')->where('speed_kph', '>', $c['speed_limit_kph'])
            ->distinct()->count('vehicle_id');

        $movedToday = VehiclePosition::where('recorded_at', '>=', now()->startOfDay())
            ->whereNotNull('speed_kph')->where('speed_kph', '>', $c['speed_moving_kph'])
            ->distinct()->pluck('vehicle_id');
        $onTripToday = TransportTrip::whereBetween('scheduled_at', [now()->startOfDay(), now()->endOfDay()])
            ->where('status', '!=', 'cancelled')->whereNotNull('vehicle_id')->distinct()->pluck('vehicle_id');
        $out['off_trip_today'] = $movedToday->diff($onTripToday)->count();

        // Each device is judged by its own window: a battery unit that wakes hourly is not offline.
        $trackers = VehicleTracker::where('is_active', true)->get(['id', 'last_position_at', 'stale_minutes']);
        foreach ($trackers as $t) {
            if (! $t->last_position_at) {
                $out['no_signal']++;
            } elseif ($t->last_position_at->lt(now()->subMinutes($t->signalWindow($c['gps_stale_minutes'])))) {
                $out['gps_offline']++;
            }
        }

        // A restricted-area entry is a security event, not just another crossing.
        $out['restricted_today'] = FleetGeofenceEvent::where('type', 'enter')
            ->where('occurred_at', '>=', now()->startOfDay())
            ->whereHas('geofence', fn ($q) => $q->where('kind', 'restricted'))
            ->whereHas('geofence', fn ($q) => $q->where('alert_on', '!=', 'none'))
            ->count();

        return $out;
    }

    /** Prune GPS history beyond the retention window (called by the nightly scheduler). */
    public static function prune(): int
    {
        $days = (int) self::cfg()['gps_retention_days'];
        $pruned = VehiclePosition::where('recorded_at', '<', now()->subDays($days))->delete();

        // Crossings outlive the raw fixes they came from, so a yard report still reads after a prune.
        FleetGeofenceEvent::where('occurred_at', '<', now()->subDays(max(30, $days * 2)))->delete();

        return $pruned;
    }
}
