@extends('layouts.app')
@section('title', 'Fleet Settings')
@section('crumbs') <a href="{{ route('tr.index') }}">Transport</a> / <a href="{{ route('tr.fleet.index') }}">Fleet</a> / Settings @endsection
@section('content')
<div class="grid g-2">
  <div class="card"><div class="card-head"><h3>Tracking &amp; maintenance thresholds</h3></div><div class="card-body">
    <form method="post" action="{{ route('tr.fleet.settings.save') }}" class="form-grid" id="fleetSettingsForm">@csrf
      <x-field name="gps_stale_minutes" label="Treat a device as offline after (min)" type="number" :value="$c['gps_stale_minutes']" required hint="typical units report every 30–120 seconds"/>
      <x-field name="speed_moving_kph" label="Moving above (km/h)" type="number" :value="$c['speed_moving_kph']" required hint="below this the vehicle is shown as parked"/>
      <x-field name="speed_limit_kph" label="Speed limit (km/h)" type="number" :value="$c['speed_limit_kph']" required
        hint="fixes above this count as speeding on the overview and the vehicle page"/>
      <x-field name="idle_lph" label="Fuel burnt while idling (litres / hour)" type="number" step="0.1" :value="$c['idle_lph']" required
        hint="used only to price standing time on the fuel screen; it is an assumption, not a measurement"/>
      <x-field name="gps_retention_days" label="Keep GPS history for (days)" type="number" :value="$c['gps_retention_days']" required
        hint="Yard and zone crossings are kept twice as long, so a fence report still reads after old fixes are pruned."/>
      <x-field name="maintenance_lead_days" label="Flag service due within (days)" type="number" :value="$c['maintenance_lead_days']" required/>
      <x-field name="maintenance_lead_km" label="Flag service due within (km)" type="number" :value="$c['maintenance_lead_km']" required/>
      <x-field name="inspection_days" label="Inspect each vehicle every (days)" type="number" :value="$c['inspection_days']" required
        hint="pre-trip inspections; a car never inspected counts as overdue"/>
      <div class="span-all"><button class="btn btn-primary" id="saveFleetSettings"><x-icon name="check"/> Save fleet settings</button></div>
    </form>
  </div></div>

  <div class="card"><div class="card-head"><h3>How tracking connects</h3></div><div class="card-body">
    <dl class="fleet-kv">
      <dt>Data path</dt><dd>Tracker → <span class="fleet-endpoint">POST /api/fleet/&lt;token&gt;</span></dd>
      <dt>Authentication</dt><dd>48-character per-device token, stored as a SHA-256 hash</dd>
      <dt>Accepted</dt><dd>JSON, form post or query string; up to 500 fixes per call</dd>
      <dt>De-duplication</dt><dd>Optional <code>event_id</code> per fix; retries are ignored</dd>
      <dt>Rate limit</dt><dd>240 calls / minute per device, 3,000 per IP</dd>
      <dt>Sharing an IP</dt><dd>Fine – the token identifies the vehicle, not the address</dd>
    </dl>
    <p class="small muted mt-1">Any unit that can post an HTTP request works directly. Vendor clouds (Traccar, Wialon, Samsara, Teltonika, Concox and similar) can forward normalised positions to the same URL; map their field names to <code>lat</code>, <code>lng</code>, <code>speed</code>, <code>heading</code>, <code>ignition</code>, <code>odometer</code>, <code>recorded_at</code> and <code>event_id</code>. Pair devices under <a href="{{ route('tr.fleet.tracking') }}">Live tracking</a>.</p>
    <p class="tiny muted">The map in this module is drawn locally from the reported coordinates. Showing street imagery instead would mean adding a tile provider to the Content-Security-Policy – a deliberate decision, not a default, so guest and staff pages stay free of third-party requests.</p>
  </div></div>
</div>

<div class="card">
  <div class="card-head"><h3>Transport desk reference</h3></div>
  <div class="card-body small muted">
    Trip timing and billing rules stay in <a href="{{ route('tr.settings') }}">Transport settings</a>: pick-up lead time, turnaround buffer, driver reminders, paper-expiry warnings and the default billing mode for guest transfers.
    Service plans and work orders belong to <a href="{{ route('tr.fleet.maintenance') }}">Maintenance &amp; repairs</a>; fills and consumption to <a href="{{ route('tr.fleet.fuel') }}">Fuel &amp; efficiency</a>.
  </div>
</div>
@endsection
