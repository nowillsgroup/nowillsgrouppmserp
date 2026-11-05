@extends('layouts.app')
@section('title', 'Live Vehicle Tracking')
@section('crumbs') <a href="{{ route('tr.index') }}">Transport</a> / <a href="{{ route('tr.fleet.index') }}">Fleet</a> / Live tracking @endsection
@section('actions')
  @if(can_do('transport.fleet'))<a class="btn" href="{{ route('tr.fleet.maintenance') }}"><x-icon name="tool"/> Maintenance</a>
  <a class="btn btn-primary" href="{{ route('tr.fleet.settings') }}"><x-icon name="grid"/> Device settings</a>@endif
@endsection
@push('head')<link rel="stylesheet" href="{{ asset('css/fleet.css') }}?v={{ config('pms.version') }}">@endpush
@section('content')
<div class="grid g-2-1">
  <div class="card">
    <div class="card-head"><h3>Live map</h3><span class="small muted">Refreshes every 20 seconds</span></div>
    <div class="card-body">
      <div class="fleet-map-wrap">
        <div class="fleet-map" id="fleetMap" data-feed="{{ route('tr.fleet.feed') }}">
          <svg viewBox="0 0 1000 625" preserveAspectRatio="xMidYMid meet" role="img" aria-labelledby="fleetMapTitle fleetMapStatus">
            <title id="fleetMapTitle">Last known position of each hotel vehicle</title>
            <g id="fleetMarkers"></g>
          </svg>
          <div class="fleet-map-empty" id="fleetMapEmpty" @if($rows->contains(fn ($r) => $r['position'])) hidden @endif>
            <div>
              <x-icon name="wifi" size="sm"/>
              <p class="small muted mb-0">No GPS position yet. Pair a tracker below, then point the device at the ingest URL.</p>
            </div>
          </div>
        </div>
        <div class="fleet-map-meta">
          <p class="small muted m-0" id="fleetMapStatus" aria-live="polite">Loading vehicle positions…</p>
          <span class="small muted" id="fleetMapUpdated"></span>
        </div>
        <div class="fleet-legend" aria-hidden="true">
          <span><i class="fleet-dot moving"></i>Moving</span>
          <span><i class="fleet-dot parked"></i>Parked</span>
          <span><i class="fleet-dot offline"></i>Signal lost</span>
          <span><i class="fleet-dot never"></i>No signal yet</span>
        </div>
      </div>
      <p class="tiny muted mt-1">The map is drawn from the reported coordinates inside this page – no third-party tiles are loaded, so it works on a hotel network without internet. Open a vehicle row to jump to its coordinates in your maps app.</p>
    </div>
  </div>

  <div class="card" id="deviceFleet">
    <div class="card-head"><h3>Devices</h3><span class="small muted">{{ $rows->count() }} paired</span></div>
    <div class="card-body">
      @if($rows->isEmpty())
        <div id="noDevices">
          <x-empty text="No GPS device has been paired to a vehicle yet." icon="wifi"/>
          <ol class="small" style="margin:.6rem 0 0;padding-left:1.1rem;display:grid;gap:.25rem">
            <li>Pair the unit below and copy the token — it is shown only once.</li>
            <li>Point the device at <code>{{ url('/api/fleet') }}/&lt;token&gt;</code> and send one test fix.</li>
            <li>Check <code>{{ url('/api/fleet') }}/&lt;token&gt;/health</code> if nothing arrives: it names the device and its last fix.</li>
          </ol>
          <p class="tiny muted mt-1">The full contract — accepted bodies, fields, limits and fault codes — is in <code>docs/FLEET.md</code>.</p>
        </div>
      @else
        <ul class="list-plain" style="list-style:none;padding:0;margin:0;display:grid;gap:.6rem">
          @foreach($rows as $r)
            @php $t = $r['tracker']; $lbl = \App\Services\FleetService::STATES[$r['state']] ?? [$r['state'], 'slate']; @endphp
            <li class="flex items-center justify-between gap-1 wrap">
              <span>
                <b>{{ $r['vehicle']?->code ?? 'Unassigned' }}</b>
                <span class="fleet-state"><span class="fleet-dot {{ $r['state'] }}"></span>{{ $lbl[0] }}</span>
                <div class="tiny muted">{{ \App\Models\VehicleTracker::PROVIDERS[$t->provider] ?? $t->provider }} · {{ $t->device_uid }}
                  @if($r['age'] === null) · never reported @else · {{ $t->last_position_at->diffForHumans() }} @endif
                  · offline after {{ $t->signalWindow(\App\Services\FleetService::cfg()['gps_stale_minutes']) }} min{{ $t->stale_minutes ? ' (device)' : '' }}
                </div>
              </span>
              @if(can_do('transport.fleet'))
                <span class="flex gap-1 items-center wrap">
                  <form method="post" action="{{ route('tr.fleet.trackers.window', $t) }}" class="inline flex gap-1 items-center">
                    @csrf
                    <label class="sr-only" for="win{{ $t->id }}">Signal window for {{ $t->device_uid }} in minutes</label>
                    <input id="win{{ $t->id }}" type="number" name="stale_minutes" min="2" max="1440" inputmode="numeric"
                      value="{{ $t->stale_minutes }}" placeholder="{{ \App\Services\FleetService::cfg()['gps_stale_minutes'] }}" style="width:5.5rem" title="Minutes without a fix before this device is shown offline (blank = property default)">
                    <button class="btn btn-sm btn-ghost">Save window</button>
                  </form>
                  <form method="post" action="{{ route('tr.fleet.trackers.toggle', $t) }}" class="inline">
                    @csrf<button class="btn btn-sm {{ $t->is_active ? 'btn-ghost' : '' }}" title="{{ $t->is_active ? 'Disable this device' : 'Enable this device' }}">{{ $t->is_active ? 'Disable' : 'Enable' }}</button>
                  </form>
                </span>
              @endif
            </li>
          @endforeach
        </ul>
      @endif
    </div>
  </div>
</div>

<div class="grid g-2-1">
  <div class="card" id="geofences">
    <div class="card-head"><h3>Yard &amp; zone fences</h3><span class="small muted">Crossings are recorded from incoming positions</span></div>
    <div class="card-body">
      @forelse($fences as $f)
        @php $inside = $fenceBoard ? collect($fenceBoard)->firstWhere('fence.id', $f->id) : null; @endphp
        <div class="small" style="padding:.4rem 0;border-bottom:1px solid var(--c-border)">
          <b>{{ $f->name }}</b>
          <span class="badge badge-{{ $f->kind === 'restricted' ? 'red' : ($f->kind === 'yard' ? 'green' : 'blue') }}">{{ \App\Models\FleetGeofence::KINDS[$f->kind] ?? $f->kind }}</span>
          @if(! $f->is_active)<span class="badge badge-slate">paused</span>@endif
          <div class="tiny muted">{{ $f->radius_m }} m radius · {{ \App\Models\FleetGeofence::ALERTS[$f->alert_on] ?? $f->alert_on }}
            · {{ (int) ($inside['inside'] ?? 0) }} vehicle(s) inside now</div>
          @if(can_do('transport.fleet'))
            <div class="fleet-actions mt-1">
              <form method="post" action="{{ route('tr.fleet.geofences.toggle', $f) }}">@csrf<button class="btn btn-sm btn-ghost">{{ $f->is_active ? 'Pause' : 'Enable' }}</button></form>
              <form method="post" action="{{ route('tr.fleet.geofences.delete', $f) }}" onsubmit="return confirm('Delete this geofence and its crossing history?')">@csrf<button class="btn btn-sm btn-ghost" title="Delete geofence"><x-icon name="trash"/></button></form>
            </div>
          @endif
        </div>
      @empty
        <p class="small muted m-0">No geofence yet. Add the hotel yard so you can see which cars are out, and any restricted area drivers must stay out of.</p>
      @endforelse
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3>Recent crossings</h3><span class="small muted">Last 3 days</span></div>
    <div class="card-body">
      @forelse($events as $e)
        <div class="small" style="padding:.35rem 0;border-bottom:1px solid var(--c-border)">
          <b>{{ $e->vehicle?->code }}</b> {{ mb_strtolower(\App\Models\FleetGeofenceEvent::TYPES[$e->type] ?? $e->type) }}
          <b>{{ $e->geofence?->name }}</b>
          @if($e->acknowledged_at)<span class="badge badge-slate">acknowledged</span>@endif
          <div class="tiny muted">{{ $e->occurred_at->format('d M H:i') }}
            @if(can_do('transport.fleet') && ! $e->acknowledged_at)
              <form method="post" action="{{ route('tr.fleet.geofences.ack', $e) }}" class="inline">@csrf<button class="btn btn-sm btn-ghost">Acknowledge</button></form>
            @endif
          </div>
        </div>
      @empty
        <p class="small muted m-0">No crossings recorded yet.</p>
      @endforelse
    </div>
  </div>
</div>

@if(can_do('transport.fleet'))
<div class="card" id="addGeofence">
  <div class="card-head"><h3>Add a geofence</h3><span class="small muted">Circle around a point</span></div>
  <div class="card-body">
    <form method="post" action="{{ route('tr.fleet.geofences.store') }}" class="form-grid" id="geofenceForm">@csrf
      <x-field name="name" label="Name" required hint="e.g. Hotel yard, Airport drop-off, KIA restricted"/>
      <x-field name="kind" label="Type" type="select" :options="\App\Models\FleetGeofence::KINDS" :placeholder="false"/>
      <x-field name="radius_m" label="Radius (metres)" type="number" :value="300" required/>
      <x-field name="alert_on" label="Record alerts" type="select" :options="\App\Models\FleetGeofence::ALERTS" :placeholder="false"/>
      <x-field name="latitude" label="Centre latitude" type="number" step="0.0000001" required placeholder="5.6037170"/>
      <x-field name="longitude" label="Centre longitude" type="number" step="0.0000001" required placeholder="-0.1870050"/>
      <x-field name="notes" label="Notes" class="span-all"/>
      <div class="span-all"><button class="btn btn-primary" id="saveGeofenceBtn"><x-icon name="plus"/> Add geofence</button>
        <span class="tiny muted">Tip: open the yard in your maps app, copy the coordinates from the vehicle’s last position, or use the position list on the vehicle page.</span></div>
    </form>
  </div>
</div>
@endif

@if(can_do('transport.fleet'))
@php $issuedTracker = $issuedFor ? \App\Models\VehicleTracker::with('vehicle')->find($issuedFor) : null; @endphp
@if($issuedToken && $issuedTracker)
<div class="alert alert-warn" id="issuedToken">
  <x-icon name="key"/>
  <div>
    <b>Ingest token for {{ $issuedTracker->device_uid }}{{ $issuedTracker->vehicle ? ' · '.$issuedTracker->vehicle->code : '' }} – shown only once.</b>
    <p class="tiny m-0 mt-1">Copy it into the tracker (or your installer's portal) together with the URL below. If it is lost, generate a new token – the old one stops working immediately.</p>
    <code class="fleet-token mt-1">{{ $issuedToken }}</code>
    <p class="tiny muted m-0 mt-1">POST to <span class="fleet-endpoint">{{ url('/api/fleet') }}/{{ $issuedToken }}</span></p>
  </div>
</div>
@endif

<div class="card" id="exportTrail">
  <div class="card-head"><h3>Download the GPS trail</h3><span class="small muted">For an auditor, a dispute or a maintenance review</span></div>
  <div class="card-body small">
    <p class="m-0">The trail is streamed as CSV from the same permission-gated data this board reads. Vehicles, timestamps, coordinates, speed, heading, ignition, odometer and a maps link per fix.</p>
    <div class="flex gap-1 wrap mt-1">
      @foreach([1, 7, 31] as $d)
        <a class="btn btn-sm" href="{{ route('tr.fleet.export.gps', ['days' => $d]) }}"><x-icon name="download"/> Last {{ $d }} day{{ $d === 1 ? '' : 's' }}</a>
      @endforeach
    </div>
    <p class="tiny muted mt-1">Rows are capped at 100,000 per download and the history window follows Fleet settings ({{ \App\Services\FleetService::cfg()['gps_retention_days'] }} days now).</p>
  </div>
</div>

<div class="card" id="pairDevice">
  <div class="card-head"><h3>Pair a tracking device</h3><span class="small muted">Works with any unit that can POST a position</span></div>
  <div class="card-body">
    <form method="post" action="{{ route('tr.fleet.trackers.store') }}" class="form-grid" id="trackerForm">@csrf
      <x-field name="vehicle_id" label="Vehicle" type="select" :options="$vehicles->mapWithKeys(fn ($v) => [$v->id => $v->label()])" required/>
      <x-field name="provider" label="Device family" type="select" :options="\App\Models\VehicleTracker::PROVIDERS" :placeholder="false"/>
      <x-field name="device_uid" label="Device ID / IMEI" required hint="as printed on the unit or shown in the vendor portal"/>
      <x-field name="name" label="Label (optional)" hint="e.g. dashboard unit, spare tracker"/>
      <x-field name="stale_minutes" label="Signal window (minutes)" type="number" placeholder="property default"
        hint="Blank follows Fleet settings; set 120+ for a battery unit that only wakes on movement"/>
      <label class="small span-all" style="display:flex;gap:.4rem;align-items:center">
        <input type="checkbox" name="replace" value="1">
        Replace the device already paired to this vehicle (the old unit stops reporting at once)
      </label>
      <div class="span-all"><button class="btn btn-primary" id="pairTrackerBtn"><x-icon name="plus"/> Pair device &amp; create token</button></div>
    </form>
    <details class="mt-1">
      <summary class="small">What the device must send</summary>
      <p class="small muted mt-1">A JSON body (single position or <code>{"positions":[…]}</code>, up to 500 per call), a form post, or query parameters. Fields: <code>lat</code>, <code>lng</code>, <code>speed</code>, <code>heading</code>, <code>ignition</code>, <code>odometer</code>, <code>recorded_at</code> (ISO time or unix), <code>event_id</code> (deduplicates retries).</p>
      <pre class="fleet-token">curl -X POST {{ url('/api/fleet') }}/&lt;token&gt; \
  -H 'Content-Type: application/json' \
  -d '{"lat":5.6037,"lng":-0.1870,"speed":42,"heading":180,"ignition":true,"odometer":48210,"recorded_at":"{{ now()->toIso8601String() }}","event_id":"abc-1"}'</pre>
      <p class="small muted mt-1">API gateways (Traccar, Wialon, Samsara, vendor clouds) can forward normalised positions to the same URL – point their HTTP forwarder at it and map their field names to the ones above.</p>
    </details>
  </div>
</div>
@endif
@endsection
@push('scripts')<script src="{{ asset('js/fleet-live.js') }}?v={{ config('pms.version') }}" defer></script>@endpush
