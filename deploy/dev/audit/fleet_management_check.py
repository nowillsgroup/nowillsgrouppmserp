#!/usr/bin/env python3
"""Static regression checks for the fleet management module (GPS, maintenance, fuel)."""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[3]


def read(path: str) -> str:
    return (ROOT / path).read_text()


MIGRATION = read("database/migrations/2026_11_04_000100_create_fleet_management_tables.php")
TRACKER_MODEL = read("app/Models/VehicleTracker.php")
POSITION_MODEL = read("app/Models/VehiclePosition.php")
RECORD_MODEL = read("app/Models/VehicleMaintenanceRecord.php")
PLAN_MODEL = read("app/Models/VehicleMaintenancePlan.php")
SERVICE = read("app/Services/FleetService.php")
CONTROLLER = read("app/Http/Controllers/FleetController.php")
CONTROLLER_DASH = read("app/Http/Controllers/DashboardController.php")
INGEST = read("app/Http/Controllers/GpsIngestController.php")
ROUTES = read("routes/web.php")
CONSOLE = read("routes/console.php")
CONFIG = read("config/pms.php")
PROVIDER = read("app/Providers/AppServiceProvider.php")
PROVISIONER = read("app/Services/HotelProvisioner.php")
DASHBOARD = read("resources/views/dashboard.blade.php")
# v10.x the report library was split out of ReportController into per-module trait files under
# app/Http/Controllers/Reports/; the fleet report pins follow the code and read both surfaces
REPORTS = read("app/Http/Controllers/ReportController.php") + "\n" + "\n".join(
    read(str(path)) for path in sorted((ROOT / "app/Http/Controllers/Reports").glob("*.php")))
MODULES_DOC = read("docs/MODULES.md")
TRANSPORT_FLEET = read("resources/views/transport/fleet.blade.php")
VIEWS = {
    name: read(f"resources/views/fleet/{name}.blade.php")
    for name in ("index", "tracking", "maintenance", "fuel", "settings", "vehicle")
}
GEOFENCE_MIGRATION = read("database/migrations/2026_11_04_000200_create_fleet_geofences.php")
WINDOW_MIGRATION = read("database/migrations/2026_11_04_000300_add_signal_window_to_vehicle_trackers.php")
WORK_ORDER_VIEW = read("resources/views/fleet/work-order.blade.php")
DOC_STYLE = read("resources/views/fleet/_docstyle.blade.php")
INSPECTION_MIGRATION = read("database/migrations/2026_11_04_000400_create_vehicle_inspections.php")
INSPECTION_MODEL = read("app/Models/VehicleInspection.php")
GEOFENCE_MODEL = read("app/Models/FleetGeofence.php")
GEOFENCE_STATE = read("app/Models/FleetGeofenceState.php")
GEOFENCE_EVENT = read("app/Models/FleetGeofenceEvent.php")
PREVIEW = read("deploy/dev/previews/fleet-tracking.html")
RUNBOOK = read("docs/FLEET.md")
PREVIEW_MAINT = read("deploy/dev/previews/fleet-maintenance.html")
PREVIEW_FUEL = read("deploy/dev/previews/fleet-fuel.html")
PREVIEW_STATEMENT = read("deploy/dev/previews/fleet-cost-statement.html")
INCIDENT_MIGRATION = read("database/migrations/2026_11_04_000600_create_vehicle_incidents.php")
INCIDENT_MODEL = read("app/Models/VehicleIncident.php")
STATEMENT_VIEW = read("resources/views/fleet/cost-statement.blade.php")
PREVIEW_SERVER = read("deploy/dev/management-dashboard-preview-server.mjs")
LIVE_JS = read("public/js/fleet-live.js")
FLEET_CSS = read("public/css/fleet.css")


class FleetManagementTest(unittest.TestCase):
    def test_schema_is_tenant_owned_and_idempotent(self):
        for table in ("vehicle_trackers", "vehicle_positions", "vehicle_maintenance_plans", "vehicle_maintenance_records"):
            with self.subTest(table=table):
                self.assertIn(f"if (! Schema::hasTable('{table}'))", MIGRATION)
                self.assertIn(f"Schema::create('{table}'", MIGRATION)
                self.assertIn(f"dropIfExists('{table}')", MIGRATION)
        self.assertIn("$table->foreignId('hotel_id')->constrained()->cascadeOnDelete();", MIGRATION)
        self.assertIn("$table->unique(['hotel_id', 'provider', 'device_uid']);", MIGRATION)
        self.assertIn("$table->unique(['vehicle_tracker_id', 'event_id']);", MIGRATION)
        self.assertIn("Schema::hasColumn('vehicle_expenses', 'maintenance_record_id')", MIGRATION)
        self.assertIn("dropConstrainedForeignId('maintenance_record_id')", MIGRATION)

    def test_models_stay_hotel_scoped(self):
        for name, body in (("VehicleTracker", TRACKER_MODEL), ("VehiclePosition", POSITION_MODEL),
                           ("VehicleMaintenancePlan", PLAN_MODEL), ("VehicleMaintenanceRecord", RECORD_MODEL)):
            with self.subTest(model=name):
                self.assertIn("use BelongsToHotel;", body)
                self.assertIn("protected $guarded = ['id', 'hotel_id'", body)

    def test_device_tokens_are_hashed_and_compared_in_constant_time(self):
        self.assertIn("protected $guarded = ['id', 'hotel_id', 'token_hash'];", TRACKER_MODEL)
        self.assertIn("hash('sha256', $token)", TRACKER_MODEL)
        self.assertIn("bin2hex(random_bytes(24))", TRACKER_MODEL)
        self.assertIn("hash_equals((string) $this->token_hash, self::hashToken($token))", TRACKER_MODEL)
        self.assertIn("'token_hash' => VehicleTracker::hashToken($token)", CONTROLLER)
        self.assertIn("$tracker->forceFill(['token_hash' => VehicleTracker::hashToken($token)])", CONTROLLER)
        # the plaintext token is only ever flashed to the session, never persisted or logged
        self.assertIn("->with('fleet_token', $token)", CONTROLLER)
        self.assertNotIn("'token' =>", CONTROLLER)
        self.assertIn("Ingest token rotated for GPS device {$tracker->device_uid}", CONTROLLER)

    def test_ingest_endpoint_is_stateless_scoped_and_throttled(self):
        self.assertIn("Route::post('/api/fleet/{token}', [Controllers\\GpsIngestController::class, 'push'])", ROUTES)
        self.assertIn("where(['token' => '[A-Za-z0-9]{48}'])", ROUTES)
        self.assertIn("middleware('throttle:fleet-gps')", ROUTES)
        for skipped in ("StartSession::class", "PreventRequestForgery::class", "EncryptCookies::class"):
            with self.subTest(skipped=skipped):
                self.assertIn(skipped, ROUTES)
        self.assertIn("RateLimiter::for('fleet-gps'", PROVIDER)
        self.assertIn("::perMinute(240)->by('fleet-gps:'.$r->route('token'))", PROVIDER)

        # the token alone chooses the property; nothing is trusted from the request body
        self.assertIn("VehicleTracker::withoutGlobalScope('hotel')->where('token_hash', VehicleTracker::hashToken($token))->first()", INGEST)
        self.assertIn("Tenant::set($hotel);", INGEST)
        self.assertIn("if (! $tracker->is_active) {", INGEST)
        self.assertIn("if (! $hotel || ! $hotel->is_active) {", INGEST)
        self.assertIn("hash_equals((string) $tracker->device_uid, $sentUid)", INGEST)

    def test_positions_are_validated_deduplicated_and_bounded(self):
        self.assertIn("if (count($rows) > 500) {", SERVICE)
        self.assertIn("abs((float) $lat) > 90 || abs((float) $lng) > 180", SERVICE)
        self.assertIn("if ($fix['recorded_at']->isAfter(now()->addMinutes(5))) {", SERVICE)
        self.assertIn("if ($recorded->isBefore(now()->subDays(7))) {", SERVICE)
        self.assertIn("where('event_id', $fix['event_id'])->exists()", SERVICE)
        self.assertIn("'received_at' => now()", SERVICE)
        self.assertIn("$out = ['stored' => 0, 'duplicates' => 0, 'rejected' => 0, 'errors' => []];", SERVICE)
        self.assertIn("max(0, min(400, round((float) $speed, 2)))", SERVICE)

    def test_distance_is_measured_from_the_gps_trail_with_jitter_filtering(self):
        self.assertIn("public static function metres(float $lat1, float $lng1, float $lat2, float $lng2): float", SERVICE)
        self.assertIn("$r = 6371000;", SERVICE)
        self.assertIn("if ($gap <= 600 && $d >= 25 && $d <= 5000) {", SERVICE)
        self.assertIn("'km_per_litre' => $litres > 0 ? round($km / $litres, 2) : null,", SERVICE)
        self.assertIn("'cost_per_km' => $km > 0 ? round(($fuelCost + $serviceCost + $otherCost) / $km, 2) : null,", SERVICE)

    def test_maintenance_completion_rolls_the_plan_forward(self):
        self.assertIn("'last_completed_date' => now()->toDateString(),", SERVICE)
        self.assertIn("'due_date' => $plan->interval_days ? now()->startOfDay()->addDays($plan->interval_days)->toDateString() : null,", SERVICE)
        self.assertIn("'due_odometer' => $plan->interval_km ? $odo + $plan->interval_km : null,", SERVICE)
        self.assertIn("if ($cost > 0 && ! empty($d['log_expense'])) {", SERVICE)
        self.assertIn("'maintenance_record_id' => $record->id,", SERVICE)
        self.assertIn("if ($vehicle->status === 'maintenance' && ! VehicleMaintenanceRecord::where('vehicle_id', $vehicle->id)", SERVICE)
        self.assertIn("$vehicle->forceFill(['status' => 'available'])->save();", SERVICE)
        # a vehicle taken off the road is the same status the dispatch board already excludes
        self.assertIn("'status', 'available'", read("app/Services/TransportService.php"))
        self.assertIn("$vehicle->forceFill(['status' => 'maintenance'", SERVICE)

    def test_permissions_are_declared_and_granted(self):
        self.assertIn("'transport.track' => 'Fleet GPS tracking", CONFIG)
        self.assertIn("'transport.fleet' => 'Fleet management", CONFIG)
        self.assertIn("'transport.track', 'transport.fleet'", PROVISIONER)
        for route in ("'tracking'", "'feed'", "'maintenance'", "'fuel'", "'settings'"):
            with self.subTest(route=route):
                self.assertIn("Route::middleware('perm:transport.fleet')", read("routes/web.php"))
                self.assertIn(route, read("routes/web.php"))
        self.assertIn("Route::middleware('perm:transport.track')->group(function () {", ROUTES)
        self.assertIn("Route::get('/', 'index')->name('index');", ROUTES)
        self.assertIn("['label' => 'Live Vehicle Tracking', 'route' => 'tr.fleet.tracking', 'perm' => 'transport.track']", CONFIG)
        self.assertIn("['label' => 'Maintenance & Repairs', 'route' => 'tr.fleet.maintenance', 'perm' => 'transport.fleet']", CONFIG)
        self.assertIn("['label' => 'Fuel & Efficiency', 'route' => 'tr.fleet.fuel', 'perm' => 'transport.fleet']", CONFIG)

    def test_screens_are_permission_aware_and_accessible(self):
        self.assertIn("can_do('transport.track')", VIEWS["index"])
        self.assertIn("can_do('transport.fleet')", VIEWS["index"])
        for name in ("index", "maintenance", "fuel"):
            with self.subTest(view=name):
                self.assertIn("<caption class=\"sr-only\">", VIEWS[name])
                self.assertIn('scope="col"', VIEWS[name])
        self.assertIn('id="fleetMapStatus" aria-live="polite"', VIEWS["tracking"])
        self.assertIn('role="img" aria-labelledby="fleetMapTitle fleetMapStatus"', VIEWS["tracking"])
        self.assertIn("data-feed=\"{{ route('tr.fleet.feed') }}\"", VIEWS["tracking"])
        self.assertIn('aria-controls="complete-{{ $r->id }}"', VIEWS["maintenance"])
        self.assertIn("aria-expanded", VIEWS["maintenance"])
        self.assertIn("aria-label=\"Work order status\"", VIEWS["maintenance"])
        self.assertIn('<label class="sr-only" for="fuelFrom">From</label>', VIEWS["fuel"])

    def test_live_map_stays_self_contained_and_resilient(self):
        self.assertIn("fetch(feed, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })", LIVE_JS)
        self.assertIn("window.setInterval(load, 20000)", LIVE_JS)
        self.assertIn("document.addEventListener('visibilitychange'", LIVE_JS)
        self.assertIn("Live positions could not be refreshed – retrying shortly.", LIVE_JS)
        for view, body in VIEWS.items():
            with self.subTest(view=view):
                lowered = body.lower()
                for external in ("cdn.", "unpkg", "jsdelivr", "googletagmanager", "leaflet"):
                    self.assertNotIn(external, lowered)
                self.assertNotIn("<script src=\"http", lowered)
        self.assertIn("no third-party tiles are loaded", VIEWS["tracking"])
        self.assertIn("@media (prefers-reduced-motion: reduce)", FLEET_CSS)
        self.assertIn(".fleet-map .marker.offline circle { fill: #dc2626; }", FLEET_CSS)

    def test_dashboard_and_transport_pages_link_to_the_module(self):
        self.assertIn("\\App\\Services\\FleetService::alerts()", DASHBOARD)
        self.assertIn("id=\"dashFleetSignal\"", DASHBOARD)
        self.assertIn("id=\"dashFleetWorkshop\"", DASHBOARD)
        self.assertTrue("(can_do('transport.track') || can_do('transport.fleet')) && " in DASHBOARD, "fleet alerts are not permission gated")
        self.assertTrue("Vehicle::exists())" in DASHBOARD, "fleet alerts do not check for a fleet")
        self.assertIn("route('tr.fleet.tracking')", TRANSPORT_FLEET)
        self.assertIn("route('tr.fleet.index')", TRANSPORT_FLEET)

    def test_history_pruning_is_scheduled_not_automatic(self):
        self.assertIn("public static function prune(): int", SERVICE)
        self.assertIn("$pruned = VehiclePosition::where('recorded_at', '<', now()->subDays($days))->delete();", SERVICE)
        self.assertIn("FleetGeofenceEvent::where('occurred_at', '<', now()->subDays(max(30, $days * 2)))->delete();", SERVICE)
        self.assertIn("Schedule::command('pms:fleet-run')", CONSOLE)
        self.assertIn("class FleetRun extends Command", read("app/Console/Commands/FleetRun.php"))
        self.assertIn("'pms:fleet-run\n        {--hotel= : Hotel code (default: all hotels)}", read("app/Console/Commands/FleetRun.php"))

    def test_vehicle_history_page_is_routed_and_gated(self):
        self.assertIn("Route::get('/vehicles/{vehicle}', 'vehicle')->whereNumber('vehicle')->name('vehicle');", ROUTES)
        self.assertIn("public function vehicle(Vehicle $vehicle)", CONTROLLER)
        for fragment in (
            "'cost' => FS::runningCost($vehicle, $month->copy(), now()),",
            "'trackedKm' => FS::gpsKm($vehicle->id, $month->copy(), now()),",
            "->limit(40)->get()",
            "maintenanceRecords()->with('plan')",
            "expenses()->with('driver')",
            "trips()->with('driver')",
        ):
            with self.subTest(fragment=fragment):
                self.assertIn(fragment, CONTROLLER)
        self.assertIn("route('tr.fleet.vehicle', $v)", VIEWS["index"])
        for caption in ('vehicleWorkOrders', 'vehicleExpenses', 'vehiclePositions', 'vehicleTrips', 'vehicleSpeedBreaches', 'vehicleCrossings'):
            with self.subTest(table=caption):
                self.assertIn(f'id="{caption}"', VIEWS["vehicle"])
        self.assertEqual(VIEWS["vehicle"].count('<caption class="sr-only">'), 6)

    def test_fuel_figures_follow_the_vehicle_filter(self):
        self.assertIn("$only = $request->integer('vehicle') ?: null;", CONTROLLER)
        self.assertIn("->when($only, fn ($q, $v) => $q->where('vehicle_id', $v))", CONTROLLER)
        self.assertIn("$vehicles->when($only, fn ($c) => $c->where('id', $only))", CONTROLLER)

    def test_dispatch_board_links_to_live_tracking(self):
        self.assertIn("route('tr.fleet.tracking')", read("resources/views/transport/index.blade.php"))
        self.assertIn("can_do('transport.track')", read("resources/views/transport/index.blade.php"))

    def test_fleet_reports_are_permission_scoped(self):
        self.assertIn("'fleet_maintenance' => ['Transport & Parking', 'Fleet Maintenance & Service Compliance'", REPORTS)
        self.assertIn("'fleet_gps' => ['Transport & Parking', 'Fleet GPS Activity'", REPORTS)
        self.assertTrue("'fleet_maintenance'" in REPORTS and "'transport.fleet']" in REPORTS, "maintenance report is not gated on transport.fleet")
        self.assertTrue("'fleet_gps'" in REPORTS and "'transport.track']" in REPORTS, "GPS report is not gated on transport.track")
        self.assertIn("protected function r_fleet_maintenance($from, $to): array", REPORTS)
        self.assertIn("protected function r_fleet_gps($from, $to): array", REPORTS)
        self.assertIn("FleetService::gpsKm($v->id, $f, $t)", REPORTS)
        self.assertIn("route('reports.show', 'fleet_maintenance')", VIEWS["index"])
        self.assertIn("'overlimit' => 'Over limit'", REPORTS)
        self.assertIn("SUM(CASE WHEN speed_kph > {$limit} THEN 1 ELSE 0 END) overlimit", REPORTS)
        self.assertIn("'Fixes over '.$limit.' km/h'", REPORTS)
        self.assertIn("- **Transport** — 14 pages", MODULES_DOC)
        self.assertIn("Live Vehicle Tracking · Maintenance & Repairs · Fuel & Efficiency", MODULES_DOC)

    def test_alert_counts_are_aggregated_for_the_dashboard(self):
        block = SERVICE[SERVICE.index('public static function alerts(): array'):SERVICE.index('public static function prune(): int')]
        self.assertIn("Vehicle::where('is_active', true)->count()", block)
        self.assertIn("join('vehicles', 'vehicles.id', '=', 'vehicle_maintenance_plans.vehicle_id')", block)
        self.assertIn("whereIn('status', ['scheduled', 'in_progress'])->count()", block)
        self.assertIn("$t->last_position_at->lt(now()->subMinutes($t->signalWindow($c['gps_stale_minutes'])))", block)
        self.assertNotIn('maintenanceState(', block, 'the dashboard alert strip must not walk the fleet row by row')

    def test_geofence_schema_is_tenant_owned_and_idempotent(self):
        for table in ("fleet_geofences", "fleet_geofence_states", "fleet_geofence_events"):
            with self.subTest(table=table):
                self.assertIn(f"if (! Schema::hasTable('{table}'))", GEOFENCE_MIGRATION)
                self.assertIn(f"Schema::create('{table}'", GEOFENCE_MIGRATION)
                self.assertIn(f"dropIfExists('{table}')", GEOFENCE_MIGRATION)
        self.assertIn("$table->foreignId('geofence_id')->constrained('fleet_geofences')->cascadeOnDelete();", GEOFENCE_MIGRATION)
        self.assertIn("$table->foreignId('position_id')->nullable()->constrained('vehicle_positions')->nullOnDelete();", GEOFENCE_MIGRATION)
        self.assertIn("$table->unique(['geofence_id', 'vehicle_id']);", GEOFENCE_MIGRATION)
        for model in (GEOFENCE_MODEL, GEOFENCE_STATE, GEOFENCE_EVENT):
            with self.subTest(model=model.split("\n")[2]):
                self.assertIn("use BelongsToHotel;", model)
                self.assertIn("protected $guarded = ['id', 'hotel_id'];", model)

    def test_geofence_crossings_are_evaluated_once_per_report(self):
        block = SERVICE[SERVICE.index("public static function evaluateGeofences"):SERVICE.index("public static function geofenceBoard")]
        self.assertIn("$distance <= (int) $fence->radius_m", block)
        self.assertIn("firstOrCreate(", block)
        self.assertIn("'type' => $inside ? 'enter' : 'exit'", block)
        self.assertIn("'position_id' => $position->id", block)
        self.assertIn("if ((bool) $state->inside === $inside)", block, "an unchanged state must not write a duplicate crossing")
        ingest = SERVICE[SERVICE.index("public static function ingest"):SERVICE.index("public static function liveTrackers")]
        self.assertIn("self::evaluateGeofences($newestPosition)", ingest)
        self.assertEqual(ingest.count("evaluateGeofences("), 1, "only the newest fix of a batch may be evaluated")

    def test_geofence_actions_are_permission_gated_and_audited(self):
        for route in ("geofences.store", "geofences.toggle", "geofences.delete", "geofences.ack"):
            with self.subTest(route=route):
                self.assertIn(f"->name('{route}');", ROUTES)
        for action in ("geofenceStore", "geofenceToggle", "geofenceDelete", "geofenceAck"):
            with self.subTest(action=action):
                self.assertIn(f"public function {action}(", CONTROLLER)
        self.assertIn("'name' => 'required|string|max:80'", CONTROLLER)
        self.assertIn("'radius_m' => 'required|integer|min:25|max:50000'", CONTROLLER)
        self.assertIn("Rule::in(array_keys(FleetGeofence::KINDS))", CONTROLLER)
        self.assertIn("ActivityLogger::log('transport', 'geofence'", CONTROLLER)
        self.assertIn("ActivityLogger::log('transport', 'geofence_delete'", CONTROLLER)

    def test_tracking_board_renders_fences_and_crossings(self):
        self.assertIn("'fences' => FleetGeofence::orderByDesc('is_active')", CONTROLLER)
        self.assertIn("'fenceBoard' => FS::geofenceBoard()", CONTROLLER)
        self.assertIn("'events' => FS::geofenceAlerts(3, 60)->take(12)", CONTROLLER)
        self.assertIn("orderByRaw(\"CASE kind WHEN 'yard' THEN 0 WHEN 'zone' THEN 1 ELSE 2 END\")", CONTROLLER)
        self.assertNotIn("orderByNullsFirst", CONTROLLER, "orderByNullsFirst is not available on every supported Laravel version")
        for marker in ('id="geofences"', 'id="addGeofence"', "tr.fleet.geofences.store", "tr.fleet.geofences.ack"):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEWS["tracking"])
        self.assertIn("'fences' => FS::geofenceBoard(false)", CONTROLLER)
        self.assertIn("$fenceEvents as $e", VIEWS["index"])
        self.assertIn("$alerts['geofence_today']", VIEWS["index"])
        self.assertIn("$flA['geofence_today']", DASHBOARD)
        alerts = SERVICE[SERVICE.index("public static function geofenceAlerts"):SERVICE.index("protected static function alertsOn")]
        self.assertIn("->filter(fn (FleetGeofenceEvent $e) => self::alertsOn($e->geofence?->alert_on, $e->type))", alerts)
        self.assertIn("return in_array($alertOn, ['enter', 'exit'], true) && $alertOn === $type;", SERVICE)

    def test_geofence_map_layer_reads_the_feed(self):
        self.assertIn("'fences' => $fences", CONTROLLER)
        self.assertIn("FleetGeofence::where('is_active', true)->orderBy('name')->get(['id', 'name', 'kind', 'latitude', 'longitude', 'radius_m'])", CONTROLLER)
        self.assertIn("var fences = data.fences || [];", LIVE_JS)
        self.assertIn("f.lat + (f.radius_m / 111320)", LIVE_JS)
        self.assertIn("class=\"fence ' + esc(f.kind)", LIVE_JS)
        self.assertIn(".fleet-map .fence.yard circle", FLEET_CSS)
        self.assertIn(".fleet-map .fence.restricted circle", FLEET_CSS)

    def test_speeding_and_off_trip_are_measured_from_fixes(self):
        self.assertIn("'transport_speed_limit_kph' => '80'", SERVICE)
        self.assertIn("'speed_limit_kph' => 'required|integer|min:20|max:200'", CONTROLLER)
        breaches = SERVICE[SERVICE.index("public static function speedBreaches"):SERVICE.index("public static function offTripMovement")]
        self.assertIn("->where('speed_kph', '>', $limit)", breaches)
        self.assertIn("->groupBy('vehicle_id')->get()->keyBy('vehicle_id')", breaches, 'speeding must be aggregated in SQL, not row by row')
        self.assertIn("Vehicle::whereIn('id', $rows->keys()->all())", breaches)
        self.assertNotIn('->get()->filter', breaches)
        off_trip = SERVICE[SERVICE.index("public static function offTripMovement"):SERVICE.index("public static function runningCost")]
        self.assertIn("->where('status', '!=', 'cancelled')", off_trip)
        self.assertIn("->reject(fn (Vehicle $v) => $trips->has($v->id))", off_trip)
        self.assertIn("if ($km < 1.0)", off_trip, 'a parked car must not be reported as an untracked trip')
        self.assertIn("$out['speed_today'] = VehiclePosition::where('recorded_at', '>=', now()->startOfDay())", SERVICE)
        self.assertIn("$out['off_trip_today'] = $movedToday->diff($onTripToday)->count();", SERVICE)

    def test_behaviour_is_shown_on_the_overview_and_alerted_on_the_dashboard(self):
        for marker in ('id="driverBehaviour"', 'id="offTrip"', '$speeds as $row', '$offTrip as $m', "$alerts['speed_today']", "$alerts['off_trip_today']"):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEWS["index"])
        self.assertIn("'speeds' => FS::speedBreaches(now()->subDays(7)->startOfDay(), now())", CONTROLLER)
        self.assertIn("'offTrip' => FS::offTripMovement()", CONTROLLER)
        for marker in ('id="dashFleetOffTrip"', 'id="dashFleetSpeed"'):
            with self.subTest(marker=marker):
                self.assertIn(marker, DASHBOARD)
        self.assertIn('name="speed_limit_kph"', VIEWS["settings"])

    def test_vehicle_page_shows_behaviour_and_crossings(self):
        self.assertIn("'speedLimit' => FS::cfg()['speed_limit_kph']", CONTROLLER)
        self.assertIn("'speedFixes' => $vehicle->positions()->whereNotNull('speed_kph')", CONTROLLER)
        self.assertIn("'crossings' => FleetGeofenceEvent::with('geofence')->where('vehicle_id', $vehicle->id)", CONTROLLER)
        for marker in ('id="vehicleSpeedBreaches"', 'id="vehicleCrossings"', ':text="\'No GPS fix above \''):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEWS["vehicle"])

    def test_fuel_fills_are_checked_against_their_own_baseline(self):
        self.assertIn("public static function fuelAnomalies(", SERVICE)
        block = SERVICE[SERVICE.index("public static function fuelAnomalies"):SERVICE.index("public static function utilisation")]
        self.assertIn("$median = self::median($ratios);", block, 'the baseline must be the vehicle\'s own consumption, not a fleet average')
        self.assertIn("$kmpl < $median * 0.6", block)
        self.assertIn("Odometer did not advance since the previous fill.", block)
        self.assertIn("No odometer on this fill, so consumption cannot be checked.", block)
        self.assertIn("$odoDelta > $gps * 1.25 + 20", block)
        self.assertIn("$odoDelta * 1.5 + 50 < $gps", block)
        self.assertIn("if ($inWindow && $withGps && $odoDelta !== null && $odoDelta > 0)", block, 'GPS rules must be opt-out for the dashboard')
        self.assertIn("protected static function median(array $values): ?float", SERVICE)
        self.assertIn("$out['fuel_checks'] = self::fuelAnomalies(now()->subDays(30), now()->endOfDay(), false)->count();", SERVICE)
        self.assertIn("'checks' => FS::fuelAnomalies($from->copy(), $to->copy())", CONTROLLER)
        self.assertIn("id=\"fuelChecks\"", VIEWS["fuel"])
        self.assertIn("$checks as $c", VIEWS["fuel"])
        self.assertIn('id="dashFleetFuel"', DASHBOARD)
        self.assertIn("$alerts['fuel_checks']", VIEWS["index"])

    def test_utilisation_and_cost_split_are_measured_not_guessed(self):
        block = SERVICE[SERVICE.index("public static function utilisation"):SERVICE.index("protected static function median")]
        self.assertIn("COUNT(DISTINCT DATE(recorded_at)) days", block)
        self.assertIn("SUM(CASE WHEN speed_kph >= ? THEN 1 ELSE 0 END) moving", block)
        self.assertIn("->groupBy('vehicle_id')->get()->keyBy('vehicle_id')", block)
        self.assertIn("'share' => $fixes ? (int) round((int) $r->moving / $fixes * 100) : 0", block)
        self.assertIn("'utilisation' => FS::utilisation(30)", CONTROLLER)
        for marker in ('id="utilisation"', 'id="costSplit"', "$utilisation as $u", "role=\"img\"", 'aria-labelledby="costSplitTitle"'):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEWS["index"])
        self.assertIn("$splitTotal = array_sum($split);", VIEWS["index"])
        self.assertIn("stroke-dasharray=", VIEWS["index"], 'the cost split must be drawn from the month totals, not a stored image')

    def test_driver_scorecard_attributes_speeding_honestly(self):
        block = SERVICE[SERVICE.index("public static function driverScorecard"):SERVICE.index("protected static function median")]
        self.assertIn("$start = $t->started_at ?? $t->busy_from;", block)
        self.assertIn("$end = $t->completed_at ?? $t->busy_to", block, 'attribution must use the recorded trip window, not the plan')
        self.assertIn("$p->recorded_at->between($w['from'], $w['to'])", block)
        self.assertIn("$unattributed++;", block, 'a fix outside every trip must not be blamed on a driver')
        self.assertIn("->filter(fn ($r) => $r['driver'] !== null)", block)
        self.assertIn("'unattributed' => $unattributed", block)
        self.assertIn("'scorecard' => FS::driverScorecard(30)", CONTROLLER)
        self.assertIn('id="driverScorecard"', VIEWS["index"])
        self.assertIn("$scorecard['rows'] as $r", VIEWS["index"])
        self.assertIn("no trip window", VIEWS["index"], 'the page must say which fixes could not be attributed')
        self.assertIn("Late starts", VIEWS["index"])

    def test_service_forecast_projects_kilometres_from_the_own_trail(self):
        self.assertIn("public static function serviceForecast(int $days = 60): Collection", SERVICE)
        block = SERVICE[SERVICE.index("public static function serviceForecast"):SERVICE.index("public static function paperWatch")]
        self.assertIn("round(self::gpsKm($v->id, now()->subDays(30), now()) / 30, 1)", block, 'the projection must use this vehicle\'s own GPS distance')
        self.assertIn("$kmLeft = $plan->due_odometer !== null ? (int) $plan->due_odometer - (int) $v->odometer : null;", block)
        self.assertIn("$daysLeft = $plan->due_date ? (int) now()->startOfDay()->diffInDays", block)
        self.assertIn("ceil($kmLeft / $perDay[$v->id])", block)
        self.assertIn("'urgent' => ($daysLeft !== null && $daysLeft <= 0) || ($kmLeft !== null && $kmLeft <= 0)", block)
        self.assertIn("->where(fn ($q) => $q->whereNotNull('due_date')->orWhereNotNull('due_odometer'))", block, 'a plan without a due date or odometer cannot be forecast')
        self.assertIn("'forecast' => FS::serviceForecast(60)", CONTROLLER)
        for marker in ('id="serviceForecast"', '$forecast as $f', 'no GPS distance in the last 30 days', "route('tr.fleet.vehicle', $f['vehicle'])"):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEWS["index"])

    def test_papers_are_watched_with_the_transport_window(self):
        self.assertIn("public static function paperWatch(): array", SERVICE)
        block = SERVICE[SERVICE.index("public static function paperWatch"):SERVICE.index("public static function prun")]
        self.assertIn("TransportService::cfg()['paper_days']", block, 'the fleet must use the transport desk\'s warning window')
        self.assertIn("$v->expiredPapers()", block)
        self.assertIn("$v->expiringPapers($days)", block)
        self.assertIn("$out['papers_expired'] = $papers['expired']->count();", SERVICE)
        self.assertIn("$out['papers_soon'] = $papers['soon']->count();", SERVICE)
        self.assertIn("'papers' => FS::paperWatch()", CONTROLLER)
        for marker in ('id="papers"', "$papers['expired'] as $row", "$papers['soon'] as $row", 'Fleet &amp; Papers'):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEWS["index"])
        self.assertIn("expired papers", VIEWS["index"])

    def test_exports_are_permission_gated_streamed_and_injection_safe(self):
        for route, action in (('vehicles', 'exportVehicles'), ('gps', 'exportGps'), ('fuel', 'exportFuel'),
                              ('work-orders', 'exportMaintenance'), ('inspections', 'exportInspections')):
            name = f"Route::get('/export/{route}', '{action}')->name('export.{route.replace('-', '_')}');"
            with self.subTest(route=route):
                self.assertIn(name, ROUTES)
                self.assertIn(f'public function {action}(Request $request)', CONTROLLER)
        self.assertLess(ROUTES.index("perm:transport.view"), ROUTES.index("name('export.vehicles')"))
        self.assertGreater(ROUTES.index("name('export.fuel')"), ROUTES.index("perm:transport.fleet"))
        self.assertGreater(ROUTES.index("name('export.gps')"), ROUTES.index("perm:transport.track"))
        self.assertIn("csvSafe", CONTROLLER)
        self.assertIn("array_map(fn ($v) => $this->csvSafe($v), $row)", CONTROLLER)
        self.assertEqual(CONTROLLER.count('response()->streamDownload('), 6, 'every export must stream instead of buffering the file')
        self.assertIn("->limit(100000)->lazy(1000)", CONTROLLER, 'the GPS trail must be streamed in chunks and capped')
        self.assertIn("->limit(20000)->lazy(500)", CONTROLLER)
        self.assertIn("'month' => $month->format('Y-m')", VIEWS["index"])
        self.assertIn("tr.fleet.export.fuel", VIEWS["fuel"])
        self.assertIn("tr.fleet.export.gps", VIEWS["tracking"])
        self.assertIn("tr.fleet.export.work_orders", VIEWS["maintenance"])

    def test_inspection_export_keeps_the_record_honest(self):
        self.assertIn("Route::get('/export/inspections', 'exportInspections')->name('export.inspections');", ROUTES)
        self.assertGreater(ROUTES.index("name('export.inspections')"), ROUTES.index("perm:transport.fleet"))
        block = CONTROLLER[CONTROLLER.index("public function exportInspections"):CONTROLLER.index("public function workOrderPrint")]
        self.assertIn("count($i->passedChecks()), implode('; ', $i->missingChecks())", block, 'an empty tick list must be visible, not implied')
        self.assertIn("VehicleInspection::RESULTS[$i->result] ?? $i->result", block)
        self.assertIn("array_key_exists((string) $request->query('result'), VehicleInspection::RESULTS)", block)
        self.assertIn("[$from, $to] = [$to, $from];", block, 'a reversed range must still download')
        self.assertIn("} catch (\\Throwable) {", block, 'a hand-edited date must not 500 the download')
        self.assertIn("->limit(20000)->lazy(500)", block)
        self.assertIn("'fleet-inspections-'.$from->format('Ymd').'-'.$to->format('Ymd').'.csv'", block)
        self.assertIn("'Checks not confirmed'", block)
        self.assertIn("tr.fleet.export.inspections", VIEWS["maintenance"])

    def test_each_device_can_carry_its_own_signal_window(self):
        self.assertIn("Schema::hasColumn('vehicle_trackers', 'stale_minutes')", WINDOW_MIGRATION, 'the column add must be idempotent')
        self.assertIn("$table->unsignedSmallInteger('stale_minutes')->nullable()", WINDOW_MIGRATION)
        self.assertIn("$table->dropColumn('stale_minutes');", WINDOW_MIGRATION)
        self.assertIn("'stale_minutes' => 'integer'", TRACKER_MODEL)
        self.assertIn("public function signalWindow(int $default): int", TRACKER_MODEL)
        self.assertIn("return $this->stale_minutes ? max(2, (int) $this->stale_minutes) : max(2, $default);", TRACKER_MODEL)
        self.assertIn("if ($age > $tracker->signalWindow(self::cfg()['gps_stale_minutes']) * 60) {", SERVICE)
        self.assertIn("$t->last_position_at->lt(now()->subMinutes($t->signalWindow($c['gps_stale_minutes'])))", SERVICE)
        self.assertIn("Route::post('/trackers/{tracker}/window', 'trackerWindow')->name('trackers.window');", ROUTES)
        self.assertIn("public function trackerWindow(Request $request, VehicleTracker $tracker)", CONTROLLER)
        self.assertIn("'stale_minutes' => 'nullable|integer|min:2|max:1440'", CONTROLLER)
        self.assertIn("tr.fleet.trackers.window", VIEWS["tracking"])
        self.assertIn("offline after", VIEWS["tracking"], 'the board must say which window applies to each device')

    def test_restricted_area_entries_are_counted_separately(self):
        start = SERVICE.index("$out['restricted_today']")
        block = SERVICE[start:start + 320]
        self.assertIn("FleetGeofenceEvent::where('type', 'enter')", block)
        self.assertIn("->whereHas('geofence', fn ($q) => $q->where('kind', 'restricted'))", block)
        self.assertIn("->whereHas('geofence', fn ($q) => $q->where('alert_on', '!=', 'none'))", block, 'a fence set to record silently must not raise a security alert')
        self.assertIn("'restricted_today' => 0", SERVICE)
        self.assertIn("$alerts['restricted_today']", VIEWS["index"])
        self.assertIn('id="dashFleetRestricted"', DASHBOARD)

    def test_workshop_throughput_and_downtime_come_from_closed_jobs(self):
        self.assertIn("public static function workshopStats(int $days = 90): array", SERVICE)
        block = SERVICE[SERVICE.index("public static function workshopStats"):SERVICE.index("public static function fuelProcurement")]
        self.assertIn("->where('status', 'completed')", block)
        self.assertIn("where('completed_at', '>=', $from)", block)
        self.assertIn("if ($r->started_at && $r->completed_at && $r->completed_at->greaterThan($r->started_at))", block, 'downtime needs both timestamps')
        self.assertIn("(int) $r->started_at->diffInDays($r->completed_at)", block)
        self.assertIn("'off_road_days' => $offRoad,", block)
        self.assertIn("'stats' => FS::workshopStats(90)", CONTROLLER)
        self.assertIn("'openJobs' => VehicleMaintenanceRecord::with('vehicle')->whereIn('status', ['scheduled', 'in_progress'])", CONTROLLER)
        for marker in ('id="workshopStats"', 'id="openJobsAgeing"', '$openJobs as $job', 'Days off road', 'a floor, not an estimate'):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEWS["maintenance"])
        self.assertNotIn("VehicleMaintenanceRecord::with('vehicle')->whereIn('status'", VIEWS["maintenance"], 'views must not query the database')

    def test_fuel_price_trend_needs_a_fill_per_month(self):
        self.assertIn("public static function fuelProcurement(int $months = 6): array", SERVICE)
        start = SERVICE.index("public static function fuelProcurement")
        nxt = SERVICE.find("public static function", start + 10)
        block = SERVICE[start:nxt if nxt != -1 else len(SERVICE)]
        self.assertIn("$buckets[$m->format('Y-m')] = ['label' => $m->format('M'), 'litres' => 0.0, 'spend' => 0.0, 'price' => null];", block)
        self.assertIn("$buckets[$key]['price'] = $b['litres'] > 0 ? round($b['spend'] / $b['litres'], 2) : null;", block, 'a month without a fill must not show a price')
        self.assertIn("->groupBy(fn ($r) => mb_substr(trim((string) $r->vendor), 0, 60))", block)
        self.assertIn("'procurement' => FS::fuelProcurement(6)", CONTROLLER)
        for marker in ('id="fuelPrice"', 'id="fuelVendors"', 'role="img"', 'a month with no fill shows no bar', 'Cheapest first'):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEWS["fuel"])

    def test_fleet_previews_carry_no_preview_furniture(self):
        # The previews exist to show the screens as they look in the application. The
        # "UI-only preview … no operational records" banners, tooltips and title suffixes
        # were removed at the user's request and must not creep back in.
        pages = {
            'overview': PREVIEW, 'maintenance': PREVIEW_MAINT, 'fuel': PREVIEW_FUEL,
            'tracking': read('deploy/dev/previews/fleet-tracking.html'),
            'vehicle': read('deploy/dev/previews/fleet-vehicle.html'),
            'work-order': read('deploy/dev/previews/fleet-work-order.html'),
            'cost-statement': PREVIEW_STATEMENT,
        }
        for name, body in pages.items():
            for marker in ('UI-only preview', 'UI preview', 'Preview only', 'illustrative',
                           'Illustrative', 'not live property data', 'No records are created',
                           'no operational records', 'pv-banner'):
                with self.subTest(page=name, marker=marker):
                    self.assertNotIn(marker, body)
            with self.subTest(page=name, provenance=True):
                self.assertIn('<!-- ', body, 'the provenance note belongs in a comment, not on the screen')

    def test_workshop_and_fuel_previews_are_static_copies(self):
        for name, body, markers in (
            ('maintenance', PREVIEW_MAINT, ('id="workshopStats"', 'id="openJobsAgeing"', 'Days off road')),
            ('fuel', PREVIEW_FUEL, ('id="fuelChecks"', 'id="fuelPrice"', 'id="fuelVendors"')),
        ):
            for marker in markers:
                with self.subTest(page=name, marker=marker):
                    self.assertIn(marker, body)
            for form in re.findall(r'<form[^>]*>', body):
                self.assertIn('action="#"', form, f'{name} preview must not post to a live route')
        previews = read("deploy/dev/previews/index.html")
        for page in ('fleet-overview', 'fleet-tracking', 'fleet-maintenance', 'fleet-fuel'):
            with self.subTest(page=page):
                self.assertIn(f'href="/__preview/{page}"', previews)
        server = read("deploy/dev/management-dashboard-preview-server.mjs")
        for page in ('fleet-overview', 'fleet-tracking', 'fleet-maintenance', 'fleet-fuel'):
            with self.subTest(route=page):
                self.assertIn(f"/__preview/{page}'", server)

    def test_trips_are_checked_against_the_gps_trail(self):
        self.assertIn("public static function tripVerification(int $days = 30, int $limit = 20, ?int $vehicleId = null): array", SERVICE)
        block = SERVICE[SERVICE.index("public static function tripVerification"):SERVICE.index("public static function workshopStats")]
        self.assertIn("where('status', 'completed')", block, 'only completed trips carry a billed distance')
        self.assertIn("$billed = $t->km();", block)
        self.assertIn("if (! $t->vehicle_id || ! $start || ! $end || ! $end->greaterThan($start) || $billed === null) {", block)
        self.assertIn("$unchecked++;", block, 'a trip that cannot be checked must not count as an agreement')
        self.assertIn("abs($diff) > max(3, $gps * 0.25)", block)
        self.assertIn("'unchecked' => $unchecked", block)
        self.assertIn("'tripCheck' => FS::tripVerification(30, 40)", CONTROLLER)
        self.assertIn("'tripCheck' => FS::tripVerification(30, 10, $vehicle->id)", CONTROLLER)
        for marker in ('id="tripCheck"', 'id="tripDistanceCheck"', '$tripCheck[\'rows\'] as $r', 'could not be checked'):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEWS["index"])
        self.assertIn("GPS km", VIEWS["vehicle"])
        self.assertIn("$tripGps = collect($tripCheck['rows'])->keyBy(fn ($r) => $r['trip']->id);", VIEWS["vehicle"])

    def test_inspections_are_recorded_and_can_raise_a_work_order(self):
        for table in ('vehicle_inspections',):
            self.assertIn(f"if (Schema::hasTable('{table}'))", INSPECTION_MIGRATION)
            self.assertIn(f"Schema::create('{table}'", INSPECTION_MIGRATION)
            self.assertIn(f"dropIfExists('{table}')", INSPECTION_MIGRATION)
        self.assertIn("$table->foreignId('hotel_id')->constrained()->cascadeOnDelete();", INSPECTION_MIGRATION)
        self.assertIn("$table->foreignId('work_order_id')->nullable()->constrained('vehicle_maintenance_records')->nullOnDelete();", INSPECTION_MIGRATION)
        self.assertIn("$table->json('checks')->nullable();", INSPECTION_MIGRATION)
        self.assertIn("use BelongsToHotel;", INSPECTION_MODEL)
        self.assertIn("protected $guarded = ['id', 'hotel_id'];", INSPECTION_MODEL)
        self.assertIn("public const CHECKS = [", INSPECTION_MODEL)
        self.assertIn("public const RESULTS = ['pass' => 'Passed', 'minor' => 'Passed with a minor defect', 'major' => 'Unsafe – do not dispatch'];", INSPECTION_MODEL)
        self.assertIn("public function passedChecks(): array", INSPECTION_MODEL)
        self.assertIn("public function missingChecks(): array", INSPECTION_MODEL)

        block = SERVICE[SERVICE.index("public static function recordInspection"):SERVICE.index("public static function tripVerification")]
        self.assertIn("$checks = array_values(array_intersect(array_keys(VehicleInspection::CHECKS), (array) ($d['checks'] ?? [])));", block)
        self.assertIn("if ($inspection->isUnsafe()) {", block)
        self.assertIn("$vehicle->forceFill(['status' => 'maintenance'])->save();", block, 'an unsafe car must leave the dispatch board')
        self.assertIn("if (! empty($d['raise_work_order']) && $inspection->result !== 'pass') {", block)
        self.assertIn("$inspection->forceFill(['work_order_id' => $record->id])->save();", block)
        self.assertIn("Route::post('/inspections', 'inspectionStore')->name('inspections.store');", ROUTES)
        self.assertIn("public function inspectionStore(Request $request)", CONTROLLER)
        self.assertIn("'inspected_at' => 'nullable|date|before_or_equal:now'", CONTROLLER, 'an inspection cannot be dated in the future')
        self.assertIn("'checks.*' => ['string', Rule::in(array_keys(VehicleInspection::CHECKS))]", CONTROLLER)

    def test_inspection_due_list_treats_a_blank_history_as_overdue(self):
        block = SERVICE[SERVICE.index("public static function inspectionDue"):SERVICE.index("public static function recordInspection")]
        self.assertIn("'due' => $age === null || $age >= $days", block)
        self.assertIn("->sortByDesc(fn ($r) => $r['days'] === null ? 100000 : $r['days'])", block, 'a never-inspected car must sort first')
        self.assertIn("'transport_inspection_days' => '7'", SERVICE)
        self.assertIn("$out['inspection_due'] = self::inspectionDue((int) self::cfg()['inspection_days'])->where('due', true)->count();", SERVICE)
        self.assertIn("'inspection_days' => 'required|integer|min:1|max:90'", CONTROLLER)
        self.assertIn('name="inspection_days"', VIEWS["settings"])
        self.assertIn("$alerts['inspection_due']", VIEWS["index"])
        self.assertIn('id="inspection"', VIEWS["vehicle"])
        self.assertIn("tr.fleet.inspections.store", VIEWS["vehicle"])
        self.assertIn("name=\"checks[]\"", VIEWS["vehicle"])
        self.assertIn('id="inspections"', VIEWS["maintenance"])
        self.assertIn("$inspectionDue->where('due', true)", VIEWS["maintenance"])
        self.assertIn("public function inspections()", read("app/Models/Vehicle.php"))

    def test_nightly_command_reports_and_never_repairs(self):
        run = read("app/Console/Commands/FleetRun.php")
        self.assertIn("{--dry-run : Report what would be pruned without deleting anything}", run)
        self.assertIn("$dry = (bool) $this->option('dry-run');", run)
        self.assertIn("$pruned = $dry ? 0 : FleetService::prune();", run, 'a dry run must not delete history')
        self.assertIn("use App\\Services\\ActivityLogger;", run)
        for key in ('inspections due', 'fuel fills to check', 'papers expired', 'restricted-area entries today', 'open work orders'):
            with self.subTest(key=key):
                self.assertIn(key, run)
        self.assertIn("'inspections due' => $a['inspection_due'],", run)
        self.assertIn("if ($todo) {", run, 'a quiet night writes no digest line')
        self.assertIn("'Nightly fleet digest: '", run)
        self.assertIn("'nothing needs attention'", run)
        # The command only reports: nothing here writes a work order or moves a vehicle.
        for forbidden in ('storeRecord(', 'takeOffRoad(', '->save();', '->update('):
            with self.subTest(forbidden=forbidden):
                self.assertNotIn(forbidden, run)

    def test_dashboard_counts_vehicles_due_an_inspection(self):
        self.assertIn('id="dashFleetInspection"', DASHBOARD)
        self.assertIn("$flA['inspection_due'] && can_do('transport.fleet')", DASHBOARD)
        self.assertIn("route('tr.fleet.maintenance')", DASHBOARD)
        self.assertEqual(DASHBOARD.count('id="dashFleetInspection"'), 1, 'one row, not one per hotel')
        self.assertIn("$flA['restricted_today']", DASHBOARD, 'the rest of the fleet strip still renders')

    def test_idle_time_only_counts_an_engine_that_is_reported_on(self):
        self.assertIn("public static function idleReport(int $days = 7, int $minMinutes = 5): array", SERVICE)
        block = SERVICE[SERVICE.index("public static function idleReport"):SERVICE.index("public static function efficiencyBoard")]
        self.assertIn("$ignitionKnown = $prev->ignition !== null && $f->ignition !== null;", block, 'a tracker that omits the ignition field cannot prove idling')
        self.assertIn("(bool) $prev->ignition && (bool) $f->ignition", block)
        self.assertIn("&& (float) $f->speed_kph < $still", block)
        self.assertIn("if ($ignitionKnown && $mins >= 1 && $mins <= $gap", block)
        self.assertIn("'transport_idle_lph' => '0.9'", SERVICE)
        self.assertIn("$rate = max(0, (float) ($cfg['idle_lph'] ?? 0.9));", block)
        self.assertIn("'litres' => round($idle / 60 * $rate, 2)", block)
        self.assertIn("->sortByDesc('minutes')->values();", block, 'the worst standing vehicle leads')
        self.assertIn("'idle' => FS::idleReport(7)", CONTROLLER)
        self.assertIn("'idle_lph' => 'required|numeric|min:0|max:20'", CONTROLLER)
        self.assertIn('name="idle_lph"', VIEWS["settings"])
        for marker in ('id="idleTime"', 'id="idleTable"', 'standing time', 'is an assumption priced from Fleet settings',
                       'contributes nothing', 'skipped rather than'):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEWS["fuel"])
        self.assertIn('id="idleTime"', PREVIEW_FUEL)
        self.assertIn('id="idleTable"', PREVIEW_FUEL)

    def test_efficiency_league_compares_each_car_with_the_fleet_median(self):
        self.assertIn("public static function efficiencyBoard(int $days = 90): array", SERVICE)
        block = SERVICE[SERVICE.index("public static function efficiencyBoard"):SERVICE.index("public static function inspectionDue")]
        self.assertIn("$cost = self::runningCost($v, $from->copy(), $to->copy());", block, 'the league must reuse the vehicle-page arithmetic')
        self.assertIn("->filter(fn ($r) => $r['litres'] > 0 && $r['km'] > 0);", block, 'no ratio without both fill and distance')
        self.assertIn("$median = self::median($rows->pluck('km_per_litre')->filter()->values()->all());", block)
        self.assertIn("$r['vs'] = $median ? round(($r['km_per_litre'] - $median) / $median * 100, 1) : null;", block)
        self.assertIn("$r['flagged'] = $median && $r['km'] >= 50 && $r['km_per_litre'] < $median * 0.75;", block)
        self.assertIn("->sortBy('km_per_litre')->values();", block, 'worst efficiency first')
        self.assertIn("'efficiency' => FS::efficiencyBoard(90)", CONTROLLER)
        for marker in ('id="efficiencyLeague"', 'id="fuelEfficiency"', 'vs median', 'Fleet median', '$efficiency[\'median\']', 'short run', 'never an outside benchmark'):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEWS["fuel"])
        self.assertIn("$eff->where('flagged', true)->map(fn ($r) => $r['vehicle']->code)->implode(', ')", VIEWS["fuel"])
        self.assertIn('id="efficiencyLeague"', PREVIEW_FUEL)

    def test_work_order_job_card_prints_only_posted_money(self):
        self.assertIn("public function workOrderPrint(VehicleMaintenanceRecord $record)", CONTROLLER)
        block = CONTROLLER[CONTROLLER.index("public function workOrderPrint"):CONTROLLER.index("public function inspectionStore")]
        self.assertIn("VehicleInspection::where('work_order_id', $record->id)", block)
        self.assertIn("$expenses = VehicleExpense::where('maintenance_record_id', $record->id)", block)
        self.assertIn("'hotel' => Tenant::hotel()", block, 'the document is printed on the property letterhead')
        self.assertIn("Route::get('/work-order/{record}/print', 'workOrderPrint')->whereNumber('record')->name('work-order.print');", ROUTES)
        # The card is a standalone document, not a page inside the app shell.
        self.assertIn("<!DOCTYPE html>", WORK_ORDER_VIEW)
        self.assertIn("@include('fleet._docstyle')", WORK_ORDER_VIEW)
        self.assertIn('onclick="window.print()"', WORK_ORDER_VIEW)
        for marker in ('VEHICLE WORK ORDER', 'WO-{{ str_pad((string) $record->id, 5, \'0\', STR_PAD_LEFT) }}',
                       'Work requested', 'Checks not confirmed:', 'Costs posted against this job',
                       'Before the vehicle goes back on the road', 'Transport manager'):
            with self.subTest(marker=marker):
                self.assertIn(marker, WORK_ORDER_VIEW)
        self.assertIn("{{ $record->actual_cost !== null ? money($record->actual_cost) : '—' }}", WORK_ORDER_VIEW)
        self.assertIn("the amounts above are what has actually been posted, not what was quoted", WORK_ORDER_VIEW.replace("\n  ", " "))
        self.assertNotIn('$record->estimated_cost, false', WORK_ORDER_VIEW)
        for css in ('@page{size:A4 portrait', '@media print', 'break-inside:avoid-page', '.document-actions'):
            with self.subTest(css=css):
                self.assertIn(css, DOC_STYLE)
        self.assertIn("route('tr.fleet.work-order.print', $r)", VIEWS["maintenance"])
        self.assertIn("route('tr.fleet.work-order.print', $job)", VIEWS["maintenance"])
        card = read("deploy/dev/previews/fleet-work-order.html")
        for marker in ('VEHICLE WORK ORDER', 'Defects reported at inspection', 'Total posted', 'noprint'):
            with self.subTest(marker=marker):
                self.assertIn(marker, card)
        self.assertIn("/__preview/fleet-work-order", read("deploy/dev/previews/index.html"))
        self.assertIn("fleet-work-order", read("deploy/dev/management-dashboard-preview-server.mjs"))
        vehicle = read("deploy/dev/previews/fleet-vehicle.html")
        for marker in ('id="inspection"', 'Pre-trip inspection', 'GPS km', 'Speed breaches', 'Fence crossings',
                       'Running expenses', 'GPS trail', 'jitter-filtered distance'):
            with self.subTest(marker=marker):
                self.assertIn(marker, vehicle)
        self.assertIn("/__preview/fleet-vehicle", read("deploy/dev/previews/index.html"))
        self.assertIn("fleet-vehicle", read("deploy/dev/management-dashboard-preview-server.mjs"))

    def test_runbook_documents_the_contract_it_ships(self):
        # The operator guide must describe the code, not a memory of it.
        self.assertIn("POST https://your-pms-host/api/fleet/<48-character-token>", RUNBOOK)
        self.assertIn("/health", RUNBOOK)
        for field in ('lat', 'lng', 'speed', 'heading', 'ignition', 'odometer', 'recorded_at', 'event_id'):
            with self.subTest(field=field):
                self.assertIn(f'| `{field}` |', RUNBOOK, 'every accepted field is listed with its aliases')
                self.assertIn(f"'{field}'", read("app/Http/Controllers/GpsIngestController.php"))
        for alias in ('latitude', 'lon', 'bearing', 'course', 'acc', 'timestamp', 'speed_kph', 'odometer_km'):
            with self.subTest(alias=alias):
                self.assertIn(alias, RUNBOOK)
                self.assertIn(alias, SERVICE)
        self.assertIn("At most 500 fixes per request", RUNBOOK.replace('**', ''))
        self.assertIn("count($rows) > 500", SERVICE)
        self.assertIn("older than 7 days", RUNBOOK)
        self.assertIn("now()->subDays(7)", SERVICE)
        self.assertIn("more than 5 minutes in the future", RUNBOOK)
        self.assertIn("now()->addMinutes(5)", SERVICE)
        for code in ('`401`', '`403`', '`422`', '`429`'):
            with self.subTest(code=code):
                self.assertIn(code, RUNBOOK)
        provider = read("app/Providers/AppServiceProvider.php")
        self.assertIn("RateLimiter::for('fleet-gps'", provider)
        self.assertIn("Limit::perMinute(240)->by('fleet-gps:'", provider)
        self.assertIn("Limit::perMinute(3000)->by('fleet-gps-ip:'", provider)
        self.assertIn("3,000/minute", RUNBOOK)
        self.assertIn("240 requests/minute", RUNBOOK)
        for migration in ('2026_11_04_000100_create_fleet_management_tables', '2026_11_04_000200_create_fleet_geofences',
                          '2026_11_04_000300_add_signal_window_to_vehicle_trackers', '2026_11_04_000400_create_vehicle_inspections'):
            with self.subTest(migration=migration):
                self.assertIn(migration, RUNBOOK)
                self.assertTrue((ROOT / 'database/migrations' / f'{migration}.php').exists())
        self.assertIn("**03:50**", RUNBOOK)
        self.assertIn("dailyAt('03:50')", read("routes/console.php"))
        self.assertIn("php artisan pms:upgrade", RUNBOOK)
        self.assertIn("'pms:upgrade'", read("app/Console/Commands/PmsUpgrade.php"))
        # The guide must not promise a number of checks the audit does not run.
        expected = sum(1 for line in read("deploy/dev/audit/fleet_management_check.py").splitlines()
                       if line.startswith("    def test_"))
        self.assertIn(f"{expected} static checks", RUNBOOK)
        self.assertIn("docs/FLEET.md", read("README.md"))

    def test_one_device_per_vehicle_or_an_explicit_replacement(self):
        block = CONTROLLER[CONTROLLER.index("public function trackerStore"):CONTROLLER.index("public function trackerRotate")]
        self.assertIn("if (! $vehicle->is_active) {", block, 'a retired car must not gain a device')
        self.assertIn("$existing = VehicleTracker::where('vehicle_id', $vehicle->id)->where('is_active', true)->get();", block)
        self.assertIn("if ($existing->isNotEmpty() && ! $request->boolean('replace')) {", block)
        self.assertIn("'replace' => 'nullable|boolean',", block)
        self.assertIn("$old->forceFill(['is_active' => false])->save();", block, 'a swap must disable the old unit')
        self.assertIn("'tracker_replace'", block)
        self.assertIn('name="replace"', VIEWS["tracking"])
        self.assertIn('Replace the device already paired to this vehicle', read("deploy/dev/previews/fleet-tracking.html"))
        self.assertIn("One device per car", RUNBOOK)

    def test_every_fleet_record_is_tenant_scoped(self):
        for model in ('VehicleTracker', 'VehiclePosition', 'VehicleMaintenancePlan', 'VehicleMaintenanceRecord',
                      'FleetGeofence', 'FleetGeofenceState', 'FleetGeofenceEvent', 'VehicleInspection'):
            with self.subTest(model=model):
                self.assertIn("use BelongsToHotel;", read(f"app/Models/{model}.php"))
                self.assertIn("protected $guarded = ['id', 'hotel_id'", read(f"app/Models/{model}.php"),
                              'hotel_id must never be mass-assignable')
        # The fleet controller must never step outside its own tenant scope.
        self.assertNotIn('withoutGlobalScope', CONTROLLER)
        self.assertNotIn('Tenant::set', CONTROLLER)
        # Route-model binding is what applies the global scope to an {id} in the URL.
        for signature in ('public function trackerToggle(VehicleTracker $tracker)',
                          'public function trackerRotate(VehicleTracker $tracker)',
                          'public function trackerWindow(Request $request, VehicleTracker $tracker)',
                          'public function planDelete(VehicleMaintenancePlan $plan)',
                          'public function recordStart(VehicleMaintenanceRecord $record)',
                          'public function geofenceToggle(FleetGeofence $geofence)',
                          'public function geofenceDelete(FleetGeofence $geofence)',
                          'public function geofenceAck(FleetGeofenceEvent $geofenceEvent)'):
            with self.subTest(signature=signature):
                self.assertIn(signature, CONTROLLER)
        # Ingest is the one place that resolves a tenant from the token — and it only does that read outside the scope.
        ingest = read("app/Http/Controllers/GpsIngestController.php")
        self.assertEqual(ingest.count("withoutGlobalScope('hotel')"), 2, 'one token lookup per endpoint, nothing else')
        self.assertIn("Tenant::set($hotel);", ingest)
        self.assertIn("if (! $hotel || ! $hotel->is_active) {", ingest)
        # Cross-tenant ids in a form body are rejected by the owned: rule, not by luck.
        self.assertIn("'vehicle_id' => 'required|integer|owned:vehicles',", CONTROLLER)
        self.assertIn("'checks.*' => ['string', Rule::in(array_keys(VehicleInspection::CHECKS))]", CONTROLLER)

    def test_a_fresh_install_is_told_what_to_do_first(self):
        self.assertIn("public static function readiness(): array", SERVICE)
        block = SERVICE[SERVICE.index("public static function readiness"):SERVICE.index("public static function idleReport")]
        self.assertIn("$fixes = $trackers > 0 ? VehiclePosition::count() : 0;", block, 'do not count a table nobody writes to yet')
        for key in ('vehicles', 'trackers', 'fixes', 'plans', 'inspections'):
            with self.subTest(key=key):
                self.assertIn(f"'key' => '{key}'", block)
        self.assertIn("'complete' => $done === count($steps)", block)
        self.assertIn("'readiness' => FS::readiness(),", CONTROLLER)
        # The card is evidence, not decoration: it vanishes once everything is done.
        self.assertIn("@if(! $readiness['complete'])", VIEWS["index"])
        self.assertIn('id="fleetReadiness"', VIEWS["index"])
        self.assertIn("$readiness['done'] }} of {{ $readiness['total']", VIEWS["index"])
        self.assertIn("Nothing here is seeded", VIEWS["index"])
        self.assertIn("docs/FLEET.md", VIEWS["index"])
        # A board with no devices still explains itself.
        self.assertIn('id="noDevices"', VIEWS["tracking"])
        self.assertIn("it names the device and its last fix", VIEWS["tracking"])
        self.assertIn("docs/FLEET.md", VIEWS["tracking"])
        self.assertIn('id="fleetReadiness"', read("deploy/dev/previews/fleet-overview.html"))

    def test_dashboard_shows_fleet_spend_from_two_queries(self):
        self.assertIn("public static function costSummary(?Carbon $month = null): array", SERVICE)
        block = SERVICE[SERVICE.index("public static function costSummary"):SERVICE.index("public static function readiness")]
        self.assertIn("'delta' => $prevTotal > 0 ? round(($total - $prevTotal) / $prevTotal * 100, 1) : null,", block)
        self.assertIn("->selectRaw('vehicle_id, SUM(amount) spend')->groupBy('vehicle_id')", block)
        self.assertIn("->orderByDesc('spend')->limit(1)->first();", block)
        self.assertIn("subMonthNoOverflow()", block, 'a month-end date must not overflow into the next month')
        self.assertNotIn('runningCost(', block, 'the dashboard must not run per-vehicle arithmetic')
        self.assertIn("'fleetCost' => can_do('transport.fleet') ? FleetService::costSummary() : null,", CONTROLLER_DASH)
        self.assertIn('id="dashFleetCost"', DASHBOARD)
        self.assertIn("can_do('transport.fleet') && $fleetCost", DASHBOARD)
        self.assertIn("vehicle expenses", DASHBOARD, 'the card says where the money comes from')
        self.assertIn("route('tr.fleet.vehicle', $fleetCost['top']['vehicle'])", DASHBOARD)

    def test_fleet_screens_are_labelled_for_assistive_tech(self):
        import re
        for name, view in VIEWS.items():
            for table in re.findall(r'<table\b[\s\S]*?</table>', view):
                with self.subTest(view=name):
                    self.assertIn('<caption', table, 'every fleet table needs a caption')
            for match in re.finditer(r'<(a|button)\b([^>]*)>([\s\S]*?)</\1>', view):
                tag, attrs, body = match.group(1), match.group(2), match.group(3)
                text = re.sub(r'<[^>]+>', '', body).strip()
                if text:
                    continue
                with self.subTest(view=name, control=tag):
                    self.assertTrue(any(k in attrs for k in ('aria-label', 'title=')) or 'sr-only' in body,
                                    'an icon-only control must carry an accessible name')
        self.assertIn('aria-live="polite"', VIEWS["tracking"])
        self.assertIn('role="img"', VIEWS["tracking"], 'the map is a picture, so it needs a text description')
        self.assertIn('id="fleetMapTitle"', VIEWS["tracking"])

    def test_inspection_log_is_filterable_and_paged_on_screen(self):
        self.assertIn("protected function inspectionLog(Request $request)", CONTROLLER)
        self.assertIn("protected function inspectionFilter(Request $request): string", CONTROLLER)
        block = CONTROLLER[CONTROLLER.index("protected function inspectionLog"):CONTROLLER.index("public function workOrderPrint")]
        self.assertIn("->when($request->integer('inspection_vehicle'), fn ($q, $v) => $q->where('vehicle_id', $v))", block)
        self.assertIn("->when($result !== '', fn ($q) => $q->where('result', $result))", block)
        self.assertIn("->paginate(20, ['*'], 'inspection_page')->withQueryString();", block)
        self.assertIn("array_key_exists($result, VehicleInspection::RESULTS) ? $result : ''", block, 'an unknown result filter falls back to all')
        self.assertIn("'inspections' => $this->inspectionLog($request),", CONTROLLER)
        for marker in ('id="inspectionLog"', 'id="inspectionLogTable"', 'name="inspection_result"', 'name="inspection_vehicle"',
                       'Inspection log with result, checked items and any work order raised', '$inspections->links()',
                       'No inspection matches this filter', 's own page'):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEWS["maintenance"])
        self.assertEqual(VIEWS["maintenance"].count('id="inspectionLog"'), 1, 'one element owns the anchor')
        self.assertIn("tr.fleet.export.inspections", VIEWS["maintenance"])
        # The CSV keeps the same filter the screen is showing.
        self.assertIn("array_filter(['result' => $inspectionResult])", VIEWS["maintenance"])
        self.assertIn('id="inspectionLogTable"', read("deploy/dev/previews/fleet-maintenance.html"))
        self.assertIn('id="inspectionLog"', read("deploy/dev/previews/fleet-maintenance.html"))

    def test_the_ingest_lookup_is_indexed_and_idempotent(self):
        migration = read("database/migrations/2026_11_04_000500_add_fleet_lookup_indexes.php")
        self.assertIn("$table->unique('token_hash', 'vehicle_trackers_token_hash_unique');", migration,
                      'the token is looked up on every push, so it must be indexed')
        self.assertIn("$table->index(['hotel_id', 'recorded_at'], 'vehicle_positions_hotel_id_recorded_at_index');", migration)
        self.assertIn("protected function hasIndex(string $table, string $index): bool", migration)
        self.assertIn("collect(Schema::getIndexes($table))->contains(fn ($i) => ($i['name'] ?? null) === $index);", migration)
        self.assertEqual(migration.count("$this->hasIndex("), 3, 'up() checks both, down() checks before dropping')
        self.assertIn("Schema::hasTable('vehicle_trackers')", migration)
        self.assertIn("$t->dropIndex($index)", migration)
        self.assertIn("} catch (\\Throwable) {", migration, 'an engine that cannot list indexes must not break migrate')
        # The lookup really is unscoped, which is exactly why the index is needed.
        self.assertIn("VehicleTracker::withoutGlobalScope('hotel')->where('token_hash'", read("app/Http/Controllers/GpsIngestController.php"))
        self.assertIn("2026_11_04_000500_add_fleet_lookup_indexes", RUNBOOK)
        self.assertIn("unique `vehicle_trackers.token_hash`", RUNBOOK)

    def test_monthly_statement_accounts_for_distance_before_dividing_by_it(self):
        self.assertIn("public static function costStatement(Carbon $month): array", SERVICE)
        block = SERVICE[SERVICE.index("public static function costStatement"):SERVICE.index("public static function costSummary")]
        self.assertIn("$grouped = $expenses->groupBy('vehicle_id');", block, 'one expenses query, not one per vehicle')
        self.assertIn("if ($rows === null || $rows->isEmpty()) {", block, 'a quiet car is left off, not printed as zeroes')
        self.assertIn("$tracked = VehiclePosition::where('vehicle_id', $v->id)->whereBetween('recorded_at', [$from, $to])->exists();", block)
        self.assertIn("$km = $tracked ? self::gpsKm($v->id, $from->copy(), $to->copy()) : null;", block,
                      'no trail must stay null, not collapse to zero km')
        self.assertIn("'cost_per_km' => $km !== null && $km > 0 ? round($total / $km, 2) : null]", block)
        self.assertIn("'tracked' => count($withKm),", block, 'fleet cost/km counts only the measurable cars')
        self.assertIn("'cost_per_km' => $km > 0 ? round($total / $km, 2) : null,", block)

        self.assertIn("Route::get('/cost-statement', 'costStatement')->name('cost-statement');", ROUTES)
        fleet_block = ROUTES[ROUTES.index("perm:transport.fleet"):ROUTES.index("Route::get('/cost-statement'")]
        self.assertIn("perm:transport.fleet", fleet_block, 'the statement sits behind the same permission as the costs it prints')
        self.assertIn("public function costStatement(Request $request)", CONTROLLER)
        self.assertIn("$month = self::statementMonth((string) $request->query('month', ''));", CONTROLLER)
        self.assertIn("private static function statementMonth(string $value): Carbon", CONTROLLER)
        guard = CONTROLLER[CONTROLLER.index("private static function statementMonth"):]
        self.assertIn("if ($month->gt(now()->startOfMonth())) {", guard, 'a hand-typed month cannot reach into the future')
        self.assertIn("$month->lt($floor) ? $floor : $month", guard, 'nor back a decade')
        self.assertIn("'next' => $next->lte(now()->startOfMonth()) ? $next : null,", CONTROLLER)

        for marker in ('FLEET RUNNING-COST STATEMENT', 'How to read this statement', 'No trail',
                       'Received by', "route('tr.fleet.cost-statement'", 'window.print()'):
            with self.subTest(marker=marker):
                self.assertIn(marker, STATEMENT_VIEW)
        self.assertIn("route('tr.fleet.cost-statement')", VIEWS["fuel"], 'the fuel screen links to the statement')
        for marker in ('FLEET RUNNING-COST STATEMENT', 'No trail', 'Received by'):
            with self.subTest(preview=marker):
                self.assertIn(marker, PREVIEW_STATEMENT)
        self.assertNotIn('<form method="post"', PREVIEW_STATEMENT)
        self.assertIn("/fleet-cost-statement", PREVIEW_SERVER)
        self.assertIn("/__preview/fleet-cost-statement", PREVIEW_SERVER)
        self.assertIn("fleet-cost-statement", read("deploy/dev/previews/index.html"))
        self.assertIn("fleet-cost-statement.html", PREVIEW_FUEL)

        # The spreadsheet twin of the statement must keep the same blanks and carry a totals row.
        self.assertIn("public function exportStatement(Request $request)", CONTROLLER)
        self.assertIn("Route::get('/cost-statement/export', 'exportStatement')->name('cost-statement.export');", ROUTES)
        exporter = CONTROLLER[CONTROLLER.index("public function exportStatement"):CONTROLLER.index("public function workOrderPrint")]
        self.assertIn("$month = self::statementMonth((string) $request->query('month', ''));", exporter, 'same month rules as the screen')
        self.assertIn("$l['km'] ?? '', $l['cost_per_km'] ?? ''", exporter, 'no trail exports blank, not zero')
        self.assertIn("response()->streamDownload", exporter)
        self.assertIn("'fleet-statement-'.$month->format('Y-m').'.csv'", exporter)
        self.assertIn("$this->csvRow($out, ['TOTAL',", exporter)
        self.assertIn("route('tr.fleet.cost-statement.export'", STATEMENT_VIEW)
        self.assertIn("Download CSV", PREVIEW_STATEMENT)

    def test_incidents_are_logged_without_turning_an_estimate_into_money(self):
        self.assertIn("if (Schema::hasTable('vehicle_incidents')) {\n            return;\n        }", INCIDENT_MIGRATION,
                      'the migration must be re-runnable, like the four before it')
        self.assertIn("dropIfExists('vehicle_incidents')", INCIDENT_MIGRATION)
        self.assertIn("$table->foreignId('hotel_id')->constrained()->cascadeOnDelete();", INCIDENT_MIGRATION)
        self.assertIn("$table->decimal('estimated_cost', 12, 2)->nullable();", INCIDENT_MIGRATION,
                      'an estimate is optional, never a default of zero')
        self.assertIn("$table->index(['hotel_id', 'occurred_at']);", INCIDENT_MIGRATION)
        self.assertIn("$table->index(['hotel_id', 'status']);", INCIDENT_MIGRATION)

        self.assertIn("use BelongsToHotel;", INCIDENT_MODEL, 'incidents are tenant-owned')
        self.assertIn("protected $guarded = ['id', 'hotel_id'];", INCIDENT_MODEL)
        for const in ("public const KINDS", "public const SEVERITIES", "public const STATUSES", "public const TONES"):
            with self.subTest(const=const):
                self.assertIn(const, INCIDENT_MODEL)
        self.assertIn("return in_array($this->status, ['open', 'investigating'], true);", INCIDENT_MODEL)

        self.assertIn("public static function incidents(int $limit = 100): array", SERVICE)
        block = SERVICE[SERVICE.index("public static function incidents("):SERVICE.index("public static function inspectionDue")]
        self.assertIn("->orderByRaw(\"CASE status WHEN 'open' THEN 0 WHEN 'investigating' THEN 1 ELSE 2 END\")", block,
                      'live incidents come first, closed ones do not bury them')
        self.assertIn("'serious' => $live->whereIn('severity', ['major', 'critical'])->count(),", block)
        self.assertIn("'estimate' => round((float) $live->sum(", block, 'only live incidents carry an open estimate')

        # The dashboard alert is one aggregate count, not a walk of the incident log.
        alerts = SERVICE[SERVICE.index('public static function alerts(): array'):SERVICE.index('public static function prune(): int')]
        self.assertIn("'incidents_open' => 0,", alerts)
        self.assertIn("$out['incidents_open'] = VehicleIncident::whereIn('status', ['open', 'investigating'])->count();", alerts)
        self.assertIn('id="dashFleetIncidents"', DASHBOARD)

        self.assertIn("Route::post('/incidents', 'incidentStore')->name('incidents.store');", ROUTES)
        self.assertIn("Route::post('/incidents/{vehicleIncident}/status', 'incidentStatus')->whereNumber('vehicleIncident')->name('incidents.status');", ROUTES)
        self.assertIn("'incidents' => FS::incidents(100),", CONTROLLER)
        self.assertIn("public function incidentStore(Request $request)", CONTROLLER)
        self.assertIn("public function incidentStatus(Request $request, VehicleIncident $vehicleIncident)", CONTROLLER)
        store = CONTROLLER[CONTROLLER.index("public function incidentStore"):CONTROLLER.index("public function incidentStatus")]
        self.assertIn("'occurred_at' => 'required|date|before_or_equal:now',", store, 'an incident cannot be dated in the future')
        self.assertIn("'description' => 'required|string|min:10|max:1000',", store, 'a one-word incident is not evidence')
        self.assertIn("['status' => 'open', 'created_by' => auth()->id()]", store)
        self.assertIn("ActivityLogger::log('transport', 'fleet',", store)
        status = CONTROLLER[CONTROLLER.index("public function incidentStatus"):CONTROLLER.index("public function incidentStatus") + 1500]
        self.assertIn("'note' => 'required|string|min:4|max:255',", status, 'a status change without a note is not auditable')
        self.assertIn("'closed_at' => $d['status'] === 'closed' ? now() : null,", status, 'reopening clears the closed stamp')
        self.assertIn("ActivityLogger::log('transport', 'fleet',", status)

        for marker in ('id="incidentLog"', 'id="incidentTable"', 'id="incidentForm"', 'Report an incident',
                       'it is <b>not</b> money posted', 'raises nothing automatically'):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEWS["maintenance"])
        self.assertIn('id="vehicleIncidents"', VIEWS["vehicle"], 'the car history page shows its own incidents')
        self.assertIn("'incidents' => VehicleIncident::where('vehicle_id', $vehicle->id)", CONTROLLER)
        for marker in ('id="incidentLog"', 'id="incidentTable"', 'Report an incident', 'it is <b>not</b> money posted'):
            with self.subTest(preview=marker):
                self.assertIn(marker, PREVIEW_MAINT)
        for form in re.findall(r'<form[^>]*>', PREVIEW_MAINT):
            self.assertIn('action="#"', form, 'the maintenance preview must not post to a live route')

    def test_a_mistyped_fill_can_be_corrected_but_not_silently(self):
        self.assertIn("public function fuelUpdate(Request $request, VehicleExpense $expense)", CONTROLLER)
        self.assertIn("public function fuelDestroy(Request $request, VehicleExpense $expense)", CONTROLLER)
        self.assertIn("Route::put('/fuel/{expense}', 'fuelUpdate')->whereNumber('expense')->name('fuel.update');", ROUTES)
        self.assertIn("Route::post('/fuel/{expense}/delete', 'fuelDestroy')->whereNumber('expense')->name('fuel.destroy');", ROUTES)

        # Money attached to a job is corrected by the job, not behind its back.
        guard = CONTROLLER[CONTROLLER.index("private function assertCorrectable"):CONTROLLER.index("private function assertCorrectable") + 800]
        self.assertIn("abort_if($expense->maintenance_record_id !== null, 403,", guard)
        self.assertIn("in_array($expense->kind, ['fuel', 'toll', 'parking', 'other'], true)", guard)

        for method, reason in (("public function fuelUpdate", "'reason' => 'required|string|min:4|max:120',"),
                               ("public function fuelDestroy", "$request->validate(['reason' => 'required|string|min:4|max:120']);")):
            block = CONTROLLER[CONTROLLER.index(method):CONTROLLER.index(method) + 1600]
            with self.subTest(method=method):
                self.assertIn("$this->assertCorrectable($expense);", block)
                self.assertIn(reason, block, 'a correction without a stated reason is not auditable')
                self.assertIn("ActivityLogger::log('transport', 'fuel',", block)
        destroy = CONTROLLER[CONTROLLER.index("public function fuelDestroy"):CONTROLLER.index("public function fuelDestroy") + 1600]
        self.assertNotIn("forceFill(['odometer'", destroy, 'deleting a fill must not rewind the odometer')

        # Forms need onsubmit, not data-confirm: the shared handler only listens for links.
        self.assertIn("onsubmit=\"return confirm('Delete this fill?", VIEWS["fuel"])
        self.assertNotIn('data-confirm', VIEWS["fuel"], 'data-confirm on a form is dead code')
        self.assertNotIn('data-confirm', VIEWS["tracking"], 'data-confirm on a form is dead code')
        self.assertIn("route('tr.fleet.fuel.update', $e)", VIEWS["fuel"])
        self.assertIn("route('tr.fleet.fuel.destroy', $e)", VIEWS["fuel"])
        self.assertIn('<details class="fuel-fix">', VIEWS["fuel"])
        self.assertIn('Only fuel, toll and parking costs are corrected here', VIEWS["fuel"])

        fills = PREVIEW_FUEL[PREVIEW_FUEL.index("Individual fuel fills"):]
        self.assertIn("<summary class=\"tiny\">Correct</summary>", fills, 'the preview shows the correction affordance')
        self.assertNotIn('data-confirm', fills)

    def test_cost_per_kilometre_league_reuses_distance_it_already_has(self):
        self.assertIn("public static function costLeague(Collection $stats): array", SERVICE)
        block = SERVICE[SERVICE.index("public static function costLeague"):SERVICE.index("public static function efficiencyBoard")]
        self.assertIn("$vehicles = Vehicle::whereIn('id', $stats->keys()->all())->get()->keyBy('id');", block,
                      'one query for the vehicles, not one lookup per row')
        self.assertNotIn('Vehicle::find(', block, 'a find inside the map would be a query per vehicle')
        self.assertIn("$median = self::median($rows->pluck('cost_per_km')->filter()->values()->all());", block)
        self.assertIn("$r['flagged'] = (bool) ($median && $r['km'] >= 50 && $r['cost_per_km'] > $median * 1.25);", block,
                      'flag only past a quarter above the median, and only with enough distance to mean something')
        self.assertIn("->filter(fn ($r) => $r['vehicle'] !== null && $r['total'] > 0 && $r['km'] > 0)", block,
                      'no cost or no trail means no entry, not a zero')
        self.assertIn("'league' => FS::costLeague($stats),", CONTROLLER)
        self.assertIn("'stats' => $stats,", CONTROLLER)
        for marker in ('id="costPerKmLeague"', 'Fleet median', 'more than 25% above the fleet median',
                       'a short hop makes the ratio meaningless', 'no extra distance scan'):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEWS["fuel"])
        self.assertIn('id="costPerKmLeague"', PREVIEW_FUEL)

    def test_the_dashboard_preview_mirrors_the_live_fleet_spend_card(self):
        dashboard_preview = read("deploy/dev/previews/management-dashboard.html")
        self.assertIn('id="dashFleetCost"', dashboard_preview)
        self.assertIn('Fleet running cost · Nov', dashboard_preview)   # the fleetCost month in dashboard-sample.mjs
        for marker in ('GH₵ 18,640.00', 'GH₵ 7,420.00', 'GH₵ 1,260.00', 'GH₵ 27,320.00',
                       'Most expensive:', 'GT-4821-24', 'GH₵ 8,240.00',
                       '18 fill(s) · 1,240.5 litres posted this month from vehicle expenses.'):
            with self.subTest(marker=marker):
                self.assertIn(marker, dashboard_preview)
        self.assertIn('10 business areas', dashboard_preview, 'the card count has to match the areas in dashboard-sample.mjs')
        self.assertEqual(10, dashboard_preview.count('<article class="dash-management-card'), 'ten cards, or the count above is a lie')
        self.assertNotIn('dash-management-list', dashboard_preview, 'no invented classes the stylesheet does not define')

    def test_twelve_month_cost_trend_refuses_to_invent_a_month(self):
        self.assertIn("public static function costHistory(int $months = 12): array", SERVICE)
        block = SERVICE[SERVICE.index("public static function costHistory"):SERVICE.index("public static function costSummary")]
        self.assertIn("$key = $r->date->format('Y-m');", block, 'month keys are bucketed in PHP, not by a database date function')
        self.assertIn("'prev' => $previous !== null && $previous > 0 ? round(($total - $previous) / $previous * 100, 1) : null]", block,
                      'no percentage against a month that had no spend')
        self.assertIn("$kind = in_array($r->kind, ['fuel', 'service', 'repair', 'toll', 'parking', 'other'], true) ? $r->kind : 'other';", block)
        self.assertIn("$bucket = in_array($kind, ['service', 'repair'], true) ? 'service' : ($kind === 'fuel' ? 'fuel' : 'other');", block)
        self.assertIn("'history' => FS::costHistory(12),", CONTROLLER)
        for marker in ('id="costHistory"', 'Running cost by month', 'role="img"', 'heaviest in', 'shows no bar rather than a guess'):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEWS["fuel"])
        self.assertIn('id="costHistory"', PREVIEW_FUEL)
        self.assertIn("max(1, (float) $spent->max('total'))", VIEWS["fuel"], 'the bar scale needs a non-zero denominator')

    def test_component_attributes_never_interpolate_blade_syntax(self):
        """<x-… text="{{ $x }}"> is rendered literally by Laravel – use :text="'…'.$x" instead."""
        for name, body in VIEWS.items():
            for match in re.finditer(r"<x-[a-z-]+[^>]*?\{\{", body):
                self.fail(f"fleet/{name}.blade.php interpolates Blade inside a component attribute: {match.group(0)[:80]}")
        for match in re.finditer(r"<x-[a-z-]+[^>]*?\{\{", DASHBOARD):
            self.fail(f"dashboard.blade.php interpolates Blade inside a component attribute: {match.group(0)[:80]}")

    def test_geofence_preview_is_a_static_copy(self):
        for marker in ('id="geofences"', 'id="addGeofence"', 'class="fence yard"', 'class="fence restricted"'):
            with self.subTest(marker=marker):
                self.assertIn(marker, PREVIEW)
        self.assertNotIn("<form method=\"post\"", PREVIEW, "previews must not post to a live route")

    def test_module_creates_no_sample_operational_records(self):
        seeder = read("database/seeders/DatabaseSeeder.php")
        for phrase in ("VehiclePosition::create", "VehicleTracker::create", "VehicleMaintenanceRecord::create", "VehicleMaintenancePlan::create",
                       "FleetGeofence::create", "FleetGeofenceEvent::create", "FleetGeofenceState::create"):
            with self.subTest(phrase=phrase):
                self.assertNotIn(phrase, seeder)
        self.assertNotIn("->seed(", INGEST)
        self.assertIn("No GPS position yet. Pair a tracker below", VIEWS["tracking"])


if __name__ == "__main__":
    unittest.main(verbosity=2)
