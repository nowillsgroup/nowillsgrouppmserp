@extends('layouts.app')
@section('title', $v->code.' · Fleet vehicle')
@section('crumbs') <a href="{{ route('tr.index') }}">Transport</a> / <a href="{{ route('tr.fleet.index') }}">Fleet</a> / {{ $v->code }} @endsection
@section('actions')
  @if(can_do('transport.track'))<a class="btn" href="{{ route('tr.fleet.tracking') }}"><x-icon name="wifi"/> Live tracking</a>@endif
  @if(can_do('transport.fleet'))<a class="btn" href="{{ route('tr.fleet.maintenance') }}"><x-icon name="tool"/> Maintenance</a>
  <a class="btn" href="{{ route('tr.fleet.fuel') }}"><x-icon name="wallet"/> Fuel</a>@endif
@endsection
@push('head')<link rel="stylesheet" href="{{ asset('css/fleet.css') }}?v={{ config('pms.version') }}">@endpush
@section('content')
@php
  $expired = $v->expiredPapers();
  $expiring = $v->expiringPapers(\App\Services\TransportService::cfg()['paper_days']);
  $lastFix = $positions->first();
  $tracker = $trackers->firstWhere('is_active', true) ?? $trackers->first();
  // $state is the controller's maintenanceState() workload array — do not shadow it with the tracker-state string
  $trackerState = $tracker ? \App\Services\FleetService::trackerState($tracker, $lastFix) : 'never';
  $stateLabel = \App\Services\FleetService::STATES[$trackerState] ?? [$trackerState, 'slate'];
@endphp

<div class="stats">
  <x-stat label="Odometer" :value="number_format((int) $v->odometer).' km'" icon="car" tone="blue" :sub="$v->name.' · '.$v->reg_no"/>
  <x-stat label="GPS" :value="$stateLabel[0]" icon="wifi" :tone="$stateLabel[1]" :sub="$lastFix ? $lastFix->recorded_at->diffForHumans() : 'No position received'"/>
  <x-stat label="Distance this month" :value="number_format((int) $trackedKm).' km'" icon="chart" tone="purple" :sub="$month->format('M Y').' from the GPS trail'"/>
  <x-stat label="Fuel this month" :value="money($cost['fuel'])" icon="wallet" tone="amber" :sub="rtrim(rtrim(number_format($cost['litres'], 2), '0'), '.').' litres'"/>
  <x-stat label="Service & repair" :value="money($cost['service'])" icon="tool" tone="slate" :sub="$state['open']->count().' open job(s)'"/>
  <x-stat label="Cost per km" :value="$cost['cost_per_km'] !== null ? money($cost['cost_per_km']) : '—'" icon="trend" tone="green" :sub="$cost['km_per_litre'] !== null ? number_format($cost['km_per_litre'], 2).' km / litre' : 'Not enough data yet'"/>
</div>

<div class="grid g-2">
  <div class="card">
    <div class="card-head"><h3>Vehicle</h3>
      @if(can_do('transport.manage'))<a class="small" href="{{ route('masters.edit', ['vehicles', $v->id]) }}">Edit master</a>@endif</div>
    <div class="card-body">
      <dl class="fleet-kv">
        <dt>Type</dt><dd>{{ \App\Models\Vehicle::TYPES[$v->type] ?? $v->type }} · {{ \App\Models\Vehicle::FUELS[$v->fuel] ?? $v->fuel }}</dd>
        <dt>Seats / bags</dt><dd>{{ $v->seats }} passengers · {{ $v->luggage }} bags</dd>
        <dt>Colour</dt><dd>{{ $v->color ?: '—' }}</dd>
        <dt>Workshop</dt><dd>{{ $v->status === 'maintenance' ? 'In the workshop' : ($v->is_active ? 'Available' : 'Retired') }}</dd>
        <dt>Papers</dt><dd>
          @foreach(\App\Models\Vehicle::PAPERS as $col => $label)
            <div>{{ $label }}: {{ $v->{$col}?->format('d M Y') ?? 'not recorded' }}
              @if(isset($expired[$label]))<span class="badge badge-red">expired</span>
              @elseif(isset($expiring[$label]))<span class="badge badge-amber">renew soon</span>@endif
            </div>
          @endforeach
        </dd>
        <dt>Device</dt><dd>{{ $tracker ? (\App\Models\VehicleTracker::PROVIDERS[$tracker->provider] ?? $tracker->provider).' · '.$tracker->device_uid : 'No tracker paired' }}</dd>
        @if($v->asset)<dt>Asset</dt><dd>{{ $v->asset->asset_no ?? $v->asset->name }}</dd>@endif
      </dl>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3>Service plans</h3></div>
    <div class="card-body">
      @forelse($plans as $p)
        <div class="small" style="padding:.35rem 0;border-bottom:1px solid var(--c-border)">
          <b>{{ $p->name }}</b>
          @if(! $p->is_active)<span class="badge badge-slate">retired</span>@endif
          @if($p->isDue($v->odometer))<span class="badge badge-red">due</span>@endif
          <div class="tiny muted">
            {{ $p->interval_days ? 'every '.$p->interval_days.' days' : '' }}{{ $p->interval_days && $p->interval_km ? ' · ' : '' }}{{ $p->interval_km ? 'every '.number_format($p->interval_km).' km' : '' }}
            · next {{ $p->due_date?->format('d M Y') ?? '—' }}{{ $p->due_odometer ? ' / '.number_format($p->due_odometer).' km' : '' }}
          </div>
        </div>
      @empty
        <p class="small muted m-0">No service plan for this vehicle yet. Add one under Maintenance &amp; repairs so due dates are calculated.</p>
      @endforelse
    </div>
  </div>
</div>

<div class="card flush">
  <div class="card-head"><h3>Workshop history</h3><span class="small muted">Latest 20 work orders</span></div>
  <div class="card-body table-wrap">
    <table class="table" id="vehicleWorkOrders">
      <caption class="sr-only">Work orders for {{ $v->code }} with status, completion date, odometer and cost</caption>
      <thead><tr><th scope="col">Job</th><th scope="col">Status</th><th scope="col">Due</th><th scope="col">Completed</th><th scope="col" class="num">Odometer</th><th scope="col" class="num">Cost</th><th scope="col">Supplier</th></tr></thead>
      <tbody>
      @forelse($records as $r)
        <tr>
          <td>{{ $r->title }}<div class="tiny muted">{{ \App\Models\VehicleMaintenanceRecord::CATEGORIES[$r->category] ?? $r->category }}@if($r->plan) · {{ $r->plan->name }}@endif</div></td>
          <td>{{ status_badge($r->status) }}</td>
          <td class="small">{{ $r->due_date?->format('d M Y') ?? '—' }}</td>
          <td class="small">{{ $r->completed_at?->format('d M Y') ?? '—' }}</td>
          <td class="num">{{ $r->odometer ? number_format($r->odometer) : '—' }}</td>
          <td class="num">{{ (float) $r->actual_cost > 0 ? money($r->actual_cost) : '—' }}</td>
          <td class="small">{{ $r->vendor ?: '—' }}</td>
        </tr>
      @empty
        <tr><td colspan="7"><x-empty text="No work orders recorded for this vehicle." icon="tool"/></td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="grid g-2">
  <div class="card flush">
    <div class="card-head"><h3>Recent running costs</h3></div>
    <div class="card-body table-wrap">
      <table class="table" id="vehicleExpenses">
        <caption class="sr-only">Running costs for {{ $v->code }}</caption>
        <thead><tr><th scope="col">Date</th><th scope="col">Kind</th><th scope="col" class="num">Litres</th><th scope="col" class="num">Amount</th><th scope="col" class="num">Odometer</th></tr></thead>
        <tbody>
        @forelse($expenses as $e)
          <tr><td class="nowrap">{{ $e->date->format('d M') }}</td><td>{{ \App\Models\VehicleExpense::KINDS[$e->kind] ?? $e->kind }}</td>
            <td class="num">{{ $e->litres !== null ? number_format((float) $e->litres, 2) : '—' }}</td>
            <td class="num">{{ money($e->amount) }}</td>
            <td class="num">{{ $e->odometer ? number_format($e->odometer) : '—' }}</td></tr>
        @empty
          <tr><td colspan="5"><x-empty text="No running costs recorded." icon="wallet"/></td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </div>

  <div class="card flush">
    <div class="card-head"><h3>Last known positions</h3><span class="small muted">{{ $positions->count() }} fix(es)</span></div>
    <div class="card-body table-wrap">
      <table class="table" id="vehiclePositions">
        <caption class="sr-only">The most recent GPS fixes for {{ $v->code }}</caption>
        <thead><tr><th scope="col">Recorded</th><th scope="col" class="num">Latitude</th><th scope="col" class="num">Longitude</th><th scope="col" class="num">Speed</th><th scope="col" class="num">Odometer</th><th scope="col"></th></tr></thead>
        <tbody>
        @forelse($positions as $p)
          <tr>
            <td class="nowrap small">{{ $p->recorded_at->format('d M H:i:s') }}</td>
            <td class="num small">{{ number_format((float) $p->latitude, 5) }}</td>
            <td class="num small">{{ number_format((float) $p->longitude, 5) }}</td>
            <td class="num">{{ $p->speed_kph !== null ? rtrim(rtrim(number_format((float) $p->speed_kph, 1), '0'), '.').' km/h' : '—' }}</td>
            <td class="num">{{ $p->odometer_km ? number_format($p->odometer_km) : '—' }}</td>
            <td class="right"><a class="tiny" href="https://www.google.com/maps?q={{ $p->latitude }},{{ $p->longitude }}" target="_blank" rel="noopener noreferrer">Map<span class="sr-only"> position from {{ $p->recorded_at->format('d M H:i') }}</span></a></td>
          </tr>
        @empty
          <tr><td colspan="6"><x-empty text="No GPS fix has been received for this vehicle." icon="wifi"/></td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="grid g-2-1" id="inspection">
  <div class="card">
    <div class="card-head"><h3>Pre-trip inspection</h3><span class="small muted">Expected every {{ $inspectionDays }} day(s)</span></div>
    <div class="card-body">
      <form method="post" action="{{ route('tr.fleet.inspections.store') }}" class="form-grid" id="inspectionForm">@csrf
        <input type="hidden" name="vehicle_id" value="{{ $v->id }}">
        <x-field name="inspected_at" label="Inspected at" type="datetime-local" :value="now()->format('Y-m-d\TH:i')" class="span-all"/>
        <x-field name="odometer" label="Odometer (km)" type="number" :value="$v->odometer"/>
        <x-field name="inspector" label="Inspected by" :value="auth()->user()?->name"/>
        <x-field name="result" label="Result" type="select" :options="\App\Models\VehicleInspection::RESULTS" :placeholder="false" class="span-all"/>
        <div class="span-all">
          <span class="small" style="display:block;margin-bottom:.25rem">Checks confirmed</span>
          <div class="grid g-2" style="gap:.35rem .9rem">
            @foreach(\App\Models\VehicleInspection::CHECKS as $key => $label)
              <label class="small" style="display:flex;gap:.4rem;align-items:center">
                <input type="checkbox" name="checks[]" value="{{ $key }}" checked>
                {{ $label }}
              </label>
            @endforeach
          </div>
        </div>
        <x-field name="defects" label="Defects found" type="textarea" class="span-all" placeholder="Anything the next driver must know"/>
        <label class="small span-all" style="display:flex;gap:.4rem;align-items:center">
          <input type="checkbox" name="raise_work_order" value="1" checked>
          Open a work order for a minor or major defect
        </label>
        <div class="span-all"><button class="btn btn-primary" id="saveInspectionBtn"><x-icon name="check"/> Record inspection</button></div>
      </form>
      <p class="tiny muted mt-1">An <b>Unsafe</b> result takes the car off the dispatch board immediately and can raise an urgent work order. Inspections can never be dated in the future.</p>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3>Recent inspections</h3><span class="small muted">Latest 5</span></div>
    <div class="card-body">
      @forelse($inspections as $i)
        <div class="small" style="padding:.4rem 0;border-bottom:1px solid var(--c-border)">
          <span class="badge badge-{{ \App\Models\VehicleInspection::TONES[$i->result] ?? 'slate' }}">{{ \App\Models\VehicleInspection::RESULTS[$i->result] ?? $i->result }}</span>
          <b>{{ $i->inspected_at->format('d M H:i') }}</b>
          @if($i->odometer)<span class="tiny muted">· {{ number_format($i->odometer) }} km</span>@endif
          <div class="tiny muted">{{ $i->inspector ?: '—' }} · {{ count($i->passedChecks()) }}/{{ count(\App\Models\VehicleInspection::CHECKS) }} checks confirmed
            @if($i->work_order_id) · <span class="text-amber">work order #{{ $i->work_order_id }}</span>@endif</div>
          @if($i->defects)<div class="tiny">{{ $i->defects }}</div>@endif
        </div>
      @empty
        <p class="small muted m-0">This car has never been inspected. Record the first one so a defect has somewhere to go.</p>
      @endforelse
    </div>
  </div>
</div>

<div class="card" id="vehicleIncidents">
  <div class="card-head"><h3>Incidents</h3><span class="small muted">Latest 10 · accidents, damage, breakdowns and theft</span></div>
  <div class="card-body">
    @forelse($incidents as $i)
      <div class="small" style="padding:.4rem 0;border-bottom:1px solid var(--c-border)">
        <span class="badge badge-{{ \App\Models\VehicleIncident::TONES[$i->severity] ?? 'slate' }}">{{ \App\Models\VehicleIncident::SEVERITIES[$i->severity] ?? $i->severity }}</span>
        <b>{{ \App\Models\VehicleIncident::KINDS[$i->kind] ?? $i->kind }}</b>
        <span class="tiny muted">· {{ $i->occurred_at->format('d M Y H:i') }} · {{ \App\Models\VehicleIncident::STATUSES[$i->status] ?? $i->status }}</span>
        <div class="tiny">{{ \Illuminate\Support\Str::limit($i->description, 140) }}</div>
        <div class="tiny muted">
          @if($i->location){{ $i->location }} · @endif
          @if($i->police_ref)Police {{ $i->police_ref }} · @endif
          @if($i->estimated_cost !== null)Estimate {{ money($i->estimated_cost) }} (not posted)@else No estimate @endif
          @if($i->closure_note) · {{ $i->closure_note }}@endif
        </div>
      </div>
    @empty
      <p class="small muted m-0">Nothing has happened to this car that anybody reported. Log one from the maintenance screen if it does.</p>
    @endforelse
  </div>
</div>

<div class="grid g-2">
  <div class="card flush">
    <div class="card-head"><h3>Speed breaches</h3><span class="small muted">Above {{ $speedLimit }} km/h · last 30 days</span></div>
    <div class="card-body table-wrap">
      <table class="table" id="vehicleSpeedBreaches">
        <caption class="sr-only">GPS fixes for {{ $v->code }} above the speed limit in the last 30 days</caption>
        <thead><tr><th scope="col">Recorded</th><th scope="col" class="num">Speed</th><th scope="col"></th></tr></thead>
        <tbody>
        @forelse($speedFixes as $p)
          <tr>
            <td class="small nowrap">{{ $p->recorded_at->format('d M H:i:s') }}</td>
            <td class="num text-red">{{ rtrim(rtrim(number_format((float) $p->speed_kph, 1), '0'), '.') }} km/h</td>
            <td class="right"><a class="tiny" href="https://www.google.com/maps?q={{ $p->latitude }},{{ $p->longitude }}" target="_blank" rel="noopener noreferrer">Map<span class="sr-only"> for the fix at {{ $p->recorded_at->format('d M H:i') }}</span></a></td>
          </tr>
        @empty
          <tr><td colspan="3"><x-empty :text="'No GPS fix above '.$speedLimit.' km/h in the last 30 days.'" icon="check"/></td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </div>

  <div class="card flush">
    <div class="card-head"><h3>Yard &amp; zone crossings</h3><span class="small muted">Latest 15</span></div>
    <div class="card-body table-wrap">
      <table class="table" id="vehicleCrossings">
        <caption class="sr-only">Geofence crossings recorded for {{ $v->code }}</caption>
        <thead><tr><th scope="col">When</th><th scope="col">Fence</th><th scope="col">Direction</th><th scope="col">Acknowledged</th></tr></thead>
        <tbody>
        @forelse($crossings as $c)
          <tr>
            <td class="small nowrap">{{ $c->occurred_at->format('d M H:i') }}</td>
            <td class="small">{{ $c->geofence?->name ?? 'Deleted fence' }}</td>
            <td><span class="badge badge-{{ $c->type === 'enter' ? 'green' : 'slate' }}">{{ \App\Models\FleetGeofenceEvent::TYPES[$c->type] ?? $c->type }}</span></td>
            <td class="small">{{ $c->acknowledged_at ? $c->acknowledged_at->format('d M H:i') : '—' }}</td>
          </tr>
        @empty
          <tr><td colspan="4"><x-empty text="No geofence crossing recorded for this vehicle." icon="globe"/></td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="card flush">
  <div class="card-head"><h3>Recent transfers</h3><span class="small muted">Latest 10 trips</span></div>
  <div class="card-body table-wrap">
    <table class="table" id="vehicleTrips">
      <caption class="sr-only">Recent transfers driven by {{ $v->code }}, with the billed distance and the GPS trail</caption>
      <thead><tr><th scope="col">Trip</th><th scope="col">When</th><th scope="col">Guest</th><th scope="col">Route</th><th scope="col">Driver</th><th scope="col">Status</th><th scope="col" class="num">km</th><th scope="col" class="num">GPS km</th></tr></thead>
      <tbody>
      @php $tripGps = collect($tripCheck['rows'])->keyBy(fn ($r) => $r['trip']->id); @endphp
      @forelse($trips as $t)
        <tr>
          <td><a href="{{ route('tr.show', $t) }}">{{ $t->trip_no }}</a></td>
          <td class="small nowrap">{{ $t->scheduled_at->format('d M H:i') }}</td>
          <td class="small">{{ $t->passenger_name }}</td>
          <td class="small">{{ $t->route() }}</td>
          <td class="small">{{ $t->driver?->name ?? '—' }}</td>
          <td>{{ status_badge($t->status) }}</td>
          <td class="num">{{ $t->km() ?? '—' }}</td>
          <td class="num">
            @if(isset($tripGps[$t->id]))
              {{ number_format($tripGps[$t->id]['gps'], 1) }}
              @if($tripGps[$t->id]['flagged'])<span class="badge badge-amber">Check</span>@endif
            @else
              <span class="muted" title="Only completed trips with an odometer reading and recorded start / finish can be compared">—</span>
            @endif
          </td>
        </tr>
      @empty
        <tr><td colspan="8"><x-empty text="No transfers assigned to this vehicle." icon="car"/></td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</div>
@endsection
