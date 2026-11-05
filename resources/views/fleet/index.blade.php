@extends('layouts.app')
@section('title', 'Fleet Management')
@section('crumbs') <a href="{{ route('tr.index') }}">Transport</a> / Fleet management @endsection
@section('actions')
  @if(can_do('transport.track'))<a class="btn" href="{{ route('tr.fleet.tracking') }}"><x-icon name="wifi"/> Live tracking</a>@endif
  <a class="btn" href="{{ route('tr.fleet.export.vehicles', ['month' => $month->format('Y-m')]) }}"><x-icon name="download"/> Export CSV</a>
  @if(can_do('transport.fleet'))<a class="btn" href="{{ route('tr.fleet.maintenance') }}"><x-icon name="tool"/> Maintenance</a>
  <a class="btn" href="{{ route('tr.fleet.fuel') }}"><x-icon name="wallet"/> Fuel</a>
  <a class="btn" href="{{ route('reports.show', 'fleet_maintenance') }}"><x-icon name="chart"/> Reports</a>
  <a class="btn btn-primary" href="{{ route('tr.fleet.settings') }}"><x-icon name="grid"/> Fleet settings</a>@endif
@endsection
@section('content')
@php $cur = $currentHotel?->currency ?: 'GHS'; @endphp

@if(! $readiness['complete'])
<div class="card" id="fleetReadiness">
  <div class="card-head"><h3>Getting the fleet live</h3>
    <span class="small muted">{{ $readiness['done'] }} of {{ $readiness['total'] }} steps done</span></div>
  <div class="card-body">
    <ol class="small" style="margin:0;padding-left:1.1rem;display:grid;gap:.35rem">
      @foreach($readiness['steps'] as $step)
        <li>
          @if($step['done'])
            <span class="badge badge-green">Done</span> <s class="muted">{{ $step['label'] }}</s>
          @else
            <b>{{ $step['label'] }}</b> — <a href="{{ $step['href'] }}">open</a>
          @endif
          <div class="tiny muted">{{ $step['hint'] }}</div>
        </li>
      @endforeach
    </ol>
    <p class="tiny muted mt-1">
      Nothing here is seeded: each step is counted from this property's own records, and the list disappears once all
      {{ $readiness['total'] }} are done. Device pairing, the position contract and the fault codes are in
      <code>docs/FLEET.md</code>.
    </p>
  </div>
</div>
@endif

@if(($alerts['gps_offline'] + $alerts['no_signal'] + $alerts['maintenance_overdue'] + $alerts['open_repairs'] + $alerts['geofence_today'] + $alerts['restricted_today'] + $alerts['speed_today'] + $alerts['off_trip_today'] + $alerts['fuel_checks'] + $alerts['papers_expired'] + $alerts['papers_soon'] + $alerts['inspection_due']) > 0)
<div class="alert alert-warn" id="fleetAttention">
  <x-icon name="alert"/>
  <div>
    @if($alerts['maintenance_overdue'])<b>{{ $alerts['maintenance_overdue'] }}</b> scheduled service{{ $alerts['maintenance_overdue'] === 1 ? '' : 's' }} past due. @endif
    @if($alerts['open_repairs'])<b>{{ $alerts['open_repairs'] }}</b> open work order{{ $alerts['open_repairs'] === 1 ? '' : 's' }}. @endif
    @if($alerts['gps_offline'])<b>{{ $alerts['gps_offline'] }}</b> tracker{{ $alerts['gps_offline'] === 1 ? '' : 's' }} lost signal. @endif
    @if($alerts['no_signal'])<b>{{ $alerts['no_signal'] }}</b> paired device{{ $alerts['no_signal'] === 1 ? ' has' : 's have' }} never reported. @endif
    @if($alerts['geofence_today'])<b>{{ $alerts['geofence_today'] }}</b> yard / zone crossing{{ $alerts['geofence_today'] === 1 ? '' : 's' }} today. @endif
    @if($alerts['restricted_today'])<span class="text-red"><b>{{ $alerts['restricted_today'] }}</b> entry into a restricted area today.</span> @endif
    @if($alerts['speed_today'])<b>{{ $alerts['speed_today'] }}</b> vehicle{{ $alerts['speed_today'] === 1 ? '' : 's' }} over {{ \App\Services\FleetService::cfg()['speed_limit_kph'] }} km/h today. @endif
    @if($alerts['off_trip_today'])<b>{{ $alerts['off_trip_today'] }}</b> vehicle{{ $alerts['off_trip_today'] === 1 ? ' moved' : 's moved' }} with no trip on the board. @endif
    @if($alerts['fuel_checks'])<b>{{ $alerts['fuel_checks'] }}</b> fuel fill{{ $alerts['fuel_checks'] === 1 ? '' : 's' }} to check in the last 30 days. @endif
    @if($alerts['inspection_due'])<b>{{ $alerts['inspection_due'] }}</b> vehicle{{ $alerts['inspection_due'] === 1 ? ' is' : 's are' }} due a pre-trip inspection. @endif
    @if($alerts['papers_expired'])<b>{{ $alerts['papers_expired'] }}</b> vehicle{{ $alerts['papers_expired'] === 1 ? ' has' : 's have' }} expired papers. @endif
    @if($alerts['papers_soon'])<b>{{ $alerts['papers_soon'] }}</b> vehicle{{ $alerts['papers_soon'] === 1 ? ' has' : 's have' }} papers expiring within {{ $papers['days'] }} days. @endif
    <a href="{{ can_do('transport.track') ? route('tr.fleet.tracking') : route('tr.fleet.maintenance') }}">Review the fleet</a>.
  </div>
</div>
@endif

<div class="stats">
  <x-stat label="Vehicles in service" :value="$vehicles->where('is_active', true)->count()" icon="car" tone="blue" :sub="$vehicles->count().' on the register'"/>
  <x-stat label="GPS reporting" :value="collect($trackers)->filter(fn ($t) => in_array($t['state'], ['moving', 'parked'], true))->count().' / '.collect($trackers)->count()" icon="wifi" tone="green" :sub="'Signal window '.\App\Services\FleetService::cfg()['gps_stale_minutes'].' min'"/>
  <x-stat label="Distance this month" :value="number_format((int) $totals['km']).' km'" icon="chart" tone="purple" :sub="$month->format('M Y').' from GPS trail'"/>
  <x-stat label="Fuel this month" :value="money($totals['fuel'])" icon="wallet" tone="amber" :sub="rtrim(rtrim(number_format($totals['litres'], 2), '0'), '.').' litres'"/>
  <x-stat label="Service & repair" :value="money($totals['service'])" icon="tool" tone="slate" :sub="'Month to date'"/>
  <x-stat label="Open work orders" :value="$alerts['open_repairs']" icon="receipt" tone="red" :sub="$alerts['maintenance_overdue'].' service(s) overdue'"/>
</div>

<div class="grid g-2">
  <div class="card" id="costSplit">
    <div class="card-head"><h3>Where the money went</h3><span class="small muted">{{ $month->format('M Y') }}</span></div>
    <div class="card-body">
      @php
        $split = ['fuel' => (float) $totals['fuel'], 'service' => (float) $totals['service'], 'other' => (float) $totals['other']];
        $splitTotal = array_sum($split);
        $palette = ['fuel' => ['#f59e0b', 'Fuel'], 'service' => ['#6366f1', 'Service & repair'], 'other' => ['#64748b', 'Tolls, parking & other']];
        $circ = 263.9;
        $offset = 0.0;
      @endphp
      @if($splitTotal <= 0)
        <p class="small muted m-0">No running cost recorded for {{ $month->format('M Y') }} yet. Fills and work orders appear here as soon as they are posted.</p>
      @else
        <div class="flex gap-2 items-center wrap">
          <svg viewBox="0 0 120 120" width="132" height="132" role="img" aria-labelledby="costSplitTitle" style="flex:none">
            <title id="costSplitTitle">Running cost split for {{ $month->format('M Y') }}: fuel {{ money($split['fuel']) }}, service and repair {{ money($split['service']) }}, other {{ money($split['other']) }}</title>
            <circle cx="60" cy="60" r="42" fill="none" stroke="var(--c-border)" stroke-width="16"/>
            @foreach($split as $kind => $amount)
              @continue($amount <= 0)
              @php $len = $amount / $splitTotal * $circ; @endphp
              <circle cx="60" cy="60" r="42" fill="none" stroke="{{ $palette[$kind][0] }}" stroke-width="16"
                stroke-dasharray="{{ sprintf('%.2f %.2f', $len, $circ - $len) }}" stroke-dashoffset="{{ sprintf('%.2f', -$offset) }}" transform="rotate(-90 60 60)"/>
              @php $offset += $len; @endphp
            @endforeach
            <text x="60" y="58" text-anchor="middle" font-size="11" font-weight="700" fill="currentColor">{{ money($splitTotal) }}</text>
            <text x="60" y="72" text-anchor="middle" font-size="8" fill="currentColor" opacity=".65">total</text>
          </svg>
          <ul style="list-style:none;padding:0;margin:0;display:grid;gap:.35rem;min-width:190px">
            @foreach($split as $kind => $amount)
              <li class="small">
                <span style="display:inline-block;width:.6rem;height:.6rem;border-radius:2px;background:{{ $palette[$kind][0] }};margin-right:.35rem"></span>
                {{ $palette[$kind][1] }}
                <b>{{ money($amount) }}</b>
                <span class="tiny muted">· {{ $splitTotal > 0 ? round($amount / $splitTotal * 100) : 0 }}%</span>
              </li>
            @endforeach
          </ul>
        </div>
        <p class="tiny muted mt-1">Derived from running expenses and the GPS trail for {{ $month->format('F Y') }}; service and repair includes work orders posted from Maintenance.</p>
      @endif
    </div>
  </div>

  <div class="card" id="utilisation">
    <div class="card-head"><h3>Utilisation</h3><span class="small muted">Share of fixes moving · last 30 days</span></div>
    <div class="card-body">
      @forelse($utilisation as $u)
        <div class="small" style="padding:.35rem 0;border-bottom:1px solid var(--c-border)">
          <div class="flex items-center justify-between gap-1">
            <a href="{{ route('tr.fleet.vehicle', $u['vehicle']) }}"><b>{{ $u['vehicle']->code }}</b></a>
            <span class="tiny muted">{{ $u['share'] }}% moving</span>
          </div>
          <div style="height:.35rem;background:var(--c-slate-50,#f1f5f9);border-radius:999px;overflow:hidden;margin:.25rem 0" role="img"
            aria-label="{{ $u['vehicle']->code }} was moving in {{ $u['share'] }}% of its reported fixes">
            <div style="height:100%;width:{{ $u['share'] }}%;background:{{ $u['share'] >= 50 ? '#16a34a' : ($u['share'] >= 20 ? '#f59e0b' : '#94a3b8') }}"></div>
          </div>
          <div class="tiny muted">{{ $u['days'] }} day(s) with fixes · {{ number_format($u['fixes']) }} fixes · last {{ $u['last_at']?->diffForHumans() ?? 'never' }}</div>
        </div>
      @empty
        <p class="small muted m-0">No GPS fixes in the last 30 days, so utilisation cannot be measured yet.</p>
      @endforelse
      <p class="tiny muted mt-1">High numbers mean the car earns its keep; a low share on a busy car usually means long waits between jobs, or a device that reports while parked.</p>
    </div>
  </div>
</div>

<div class="grid g-2">
  <div class="card" id="driverBehaviour">
    <div class="card-head"><h3>Driver behaviour</h3><span class="small muted">Fixes above {{ \App\Services\FleetService::cfg()['speed_limit_kph'] }} km/h · last 7 days</span></div>
    <div class="card-body">
      @if($speeds->isEmpty())
        <p class="small muted m-0">No GPS fix above the speed limit in the last 7 days.</p>
      @else
        <div class="table-wrap">
          <table class="table">
            <caption class="sr-only">Vehicles with GPS fixes above the speed limit in the last seven days</caption>
            <thead><tr><th scope="col">Vehicle</th><th scope="col" class="num">Fixes</th><th scope="col" class="num">Fastest</th><th scope="col" class="num">Avg over limit</th><th scope="col">Last seen</th></tr></thead>
            <tbody>
            @foreach($speeds as $row)
              <tr>
                <td><a href="{{ route('tr.fleet.vehicle', $row['vehicle']) }}"><b>{{ $row['vehicle']->code }}</b></a>
                  <div class="tiny muted">{{ $row['vehicle']->reg_no }}</div></td>
                <td class="num">{{ $row['fixes'] }}</td>
                <td class="num text-red">{{ (int) $row['max'] }} km/h</td>
                <td class="num">+{{ $row['over'] }}</td>
                <td class="small">{{ $row['last_at']->format('d M H:i') }}</td>
              </tr>
            @endforeach
            </tbody>
          </table>
        </div>
        <p class="tiny muted mt-1">Speed comes from the fix itself, so a unit that reports only on ignition or every few minutes will show fewer rows than a live-tracking unit. Raise the limit in <a href="{{ can_do('transport.fleet') ? route('tr.fleet.settings') : '#' }}">Fleet settings</a> if local roads justify it.</p>
      @endif
    </div>
  </div>

  <div class="card" id="offTrip">
    <div class="card-head"><h3>Movement with no trip</h3><span class="small muted">Today · at least 1 km driven</span></div>
    <div class="card-body">
      @forelse($offTrip as $m)
        <div class="small" style="padding:.4rem 0;border-bottom:1px solid var(--c-border)">
          <a href="{{ route('tr.fleet.vehicle', $m['vehicle']) }}"><b>{{ $m['vehicle']->code }}</b></a>
          <span class="badge badge-amber">{{ $m['km'] }} km</span>
          <div class="tiny muted">No trip is scheduled for this vehicle today.
            @if($m['last_at']) Last fix {{ $m['last_at']->format('H:i') }}.@endif
            Check the log book, or record the trip so the distance is billed.</div>
        </div>
      @empty
        <p class="small muted m-0">Every vehicle that moved today has a trip on the board.</p>
      @endforelse
      @if($offTrip->isNotEmpty())
        <p class="tiny muted mt-1">Distance is measured from the GPS trail, so a car moved with the ignition on for a short hop may not reach 1 km.</p>
      @endif
    </div>
  </div>
</div>

@if($fences)
<div class="grid g-2-1">
  <div class="card">
    <div class="card-head"><h3>Yard &amp; zone fences</h3>
      @if(can_do('transport.track'))<a class="small" href="{{ route('tr.fleet.tracking') }}#geofences">Manage</a>@endif</div>
    <div class="card-body">
      <ul class="list" style="margin:0;padding-left:1rem">
        @foreach($fences as $f)
          <li class="small"><b>{{ $f['fence']->name }}</b>
            <span class="muted">· {{ \App\Models\FleetGeofence::KINDS[$f['fence']->kind] ?? $f['fence']->kind }} · {{ (int) $f['inside'] }} inside</span>
            @if(! $f['fence']->is_active)<span class="badge badge-slate">paused</span>@endif</li>
        @endforeach
      </ul>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h3>Crossings today</h3></div>
    <div class="card-body">
      @forelse($fenceEvents as $e)
        <div class="small" style="padding:.3rem 0;border-bottom:1px solid var(--c-border)">
          <b>{{ $e->vehicle?->code }}</b> {{ mb_strtolower(\App\Models\FleetGeofenceEvent::TYPES[$e->type] ?? $e->type) }} <b>{{ $e->geofence?->name }}</b>
          <div class="tiny muted">{{ $e->occurred_at->format('H:i') }}{{ $e->acknowledged_at ? ' · acknowledged' : '' }}</div>
        </div>
      @empty
        <p class="small muted m-0">No yard or zone crossings today.</p>
      @endforelse
    </div>
  </div>
</div>
@endif

<div class="grid g-2">
  <div class="card" id="serviceForecast">
    <div class="card-head"><h3>Service forecast</h3><span class="small muted">Next 60 days · time and kilometres</span></div>
    <div class="card-body">
      @forelse($forecast as $f)
        <div class="small" style="padding:.4rem 0;border-bottom:1px solid var(--c-border)">
          <div class="flex items-center justify-between gap-1">
            <span><a href="{{ route('tr.fleet.vehicle', $f['vehicle']) }}"><b>{{ $f['vehicle']->code }}</b></a> · {{ $f['plan']->name }}</span>
            @if($f['urgent'])<span class="badge badge-red">Overdue</span>
            @elseif($f['projected'] && $f['projected']->lte(now()->addDays(14)))<span class="badge badge-amber">Due soon</span>
            @else<span class="badge badge-slate">Planned</span>@endif
          </div>
          <div class="tiny muted">
            @if($f['due_date'])By date {{ $f['due_date']->format('d M') }}
              ({{ $f['days_left'] === 0 ? 'today' : ($f['days_left'] > 0 ? $f['days_left'].' day(s)' : abs($f['days_left']).' day(s) ago') }})@endif
            @if($f['due_date'] && $f['due_odometer']) · @endif
            @if($f['due_odometer'])at {{ number_format($f['due_odometer']) }} km
              ({{ $f['km_left'] === 0 ? 'now' : ($f['km_left'] > 0 ? number_format($f['km_left']).' km to go' : number_format(abs($f['km_left'])).' km past') }})@endif
            @if($f['per_day'] > 0 && $f['projected'])
              · about {{ $f['per_day'] }} km/day on the GPS trail, so {{ $f['projected']->format('d M') }}
            @elseif($f['per_day'] <= 0)
              · no GPS distance in the last 30 days
            @endif
          </div>
        </div>
      @empty
        <p class="small muted m-0">No active service plan falls due in the next 60 days.</p>
      @endforelse
      <p class="tiny muted mt-1">Kilometre projections use each vehicle's own GPS distance over the last 30 days. A plan with a day interval and a kilometre interval shows whichever comes first.</p>
    </div>
  </div>

  <div class="card" id="papers">
    <div class="card-head"><h3>Papers &amp; compliance</h3><span class="small muted">Expiring within {{ $papers['days'] }} days</span></div>
    <div class="card-body">
      @if($papers['expired']->isEmpty() && $papers['soon']->isEmpty())
        <p class="small muted m-0">Every active vehicle's insurance, permit, fitness and PUC papers are valid beyond the warning window.</p>
      @else
        @foreach($papers['expired'] as $row)
          <div class="small" style="padding:.35rem 0;border-bottom:1px solid var(--c-border)">
            <a href="{{ route('tr.fleet.vehicle', $row['vehicle']) }}"><b>{{ $row['vehicle']->code }}</b></a>
            <span class="badge badge-red">Expired</span>
            <div class="tiny muted">
              @foreach($row['papers'] as $label => $date){{ $label }} {{ $date->format('d M Y') }}{{ $loop->last ? '' : ' · ' }}@endforeach
            </div>
          </div>
        @endforeach
        @foreach($papers['soon'] as $row)
          <div class="small" style="padding:.35rem 0;border-bottom:1px solid var(--c-border)">
            <a href="{{ route('tr.fleet.vehicle', $row['vehicle']) }}"><b>{{ $row['vehicle']->code }}</b></a>
            <span class="badge badge-amber">Renew soon</span>
            <div class="tiny muted">
              @foreach($row['papers'] as $label => $date){{ $label }} {{ $date->format('d M Y') }}{{ $loop->last ? '' : ' · ' }}@endforeach
            </div>
          </div>
        @endforeach
      @endif
      <p class="tiny muted mt-1">A vehicle with expired papers should be taken off the dispatch board; the transport desk shows the same window in <b>Fleet &amp; Papers</b>.</p>
    </div>
  </div>
</div>

<div class="card flush" id="scorecard">
  <div class="card-head"><h3>Driver scorecard</h3><span class="small muted">{{ $scorecard['from']->format('d M') }} – {{ $scorecard['to']->format('d M') }} · trips with a driver</span></div>
  <div class="card-body table-wrap">
    <table class="table" id="driverScorecard">
      <caption class="sr-only">Trips, distance, late starts and speeding fixes per driver for the last 30 days</caption>
      <thead><tr><th scope="col">Driver</th><th scope="col" class="num">Trips</th><th scope="col" class="num">Completed</th><th scope="col" class="num">km</th>
        <th scope="col" class="num">Late starts</th><th scope="col" class="num">Speeding</th><th scope="col" class="num">Fastest</th><th scope="col">Last trip</th></tr></thead>
      <tbody>
      @forelse($scorecard['rows'] as $r)
        <tr>
          <td><b>{{ $r['driver']->name }}</b><div class="tiny muted">{{ $r['driver']->phone ?? '—' }}</div></td>
          <td class="num">{{ $r['trips'] }}</td>
          <td class="num">{{ $r['completed'] }}</td>
          <td class="num">{{ number_format($r['km']) }}</td>
          <td class="num">{{ $r['late'] ?: '—' }}</td>
          <td class="num">@if($r['speeding'])<span class="badge badge-amber">{{ $r['speeding'] }}</span>@else<span class="muted">—</span>@endif</td>
          <td class="num">{{ $r['fastest'] > 0 ? (int) $r['fastest'].' km/h' : '—' }}</td>
          <td class="small nowrap">{{ $r['last']->format('d M H:i') }}</td>
        </tr>
      @empty
        <tr><td colspan="8"><x-empty text="No trip with a driver in the last 30 days, so there is nothing to score yet." icon="users"/></td></tr>
      @endforelse
      </tbody>
    </table>
    <p class="tiny muted" style="padding:.6rem .9rem .2rem">
      Speeding is attributed to the driver whose trip was running when the fix arrived, using the window the desk recorded (start / busy times). A fix outside every recorded trip is not pinned on anyone:
      @if($scorecard['unattributed'])<b>{{ $scorecard['unattributed'] }}</b> fix(es) above {{ $scorecard['limit'] }} km/h had no trip window.@else no fix above {{ $scorecard['limit'] }} km/h lacked a trip window.@endif
      A late start means the trip started more than 15 minutes after it was scheduled; a trip the desk never started is not counted.
    </p>
  </div>
</div>

<div class="card flush" id="tripCheck">
  <div class="card-head"><h3>Trips vs GPS</h3>
    <span class="small muted">{{ $tripCheck['checked'] }} completed trip(s) checked · {{ $tripCheck['flagged'] }} to look at</span></div>
  <div class="card-body table-wrap">
    @if($tripCheck['rows']->isEmpty())
      <p class="small muted m-0" style="padding:.9rem">No completed trip in the last 30 days has both an odometer reading and a recorded start / finish, so there is nothing to compare yet.</p>
    @else
      <table class="table" id="tripDistanceCheck">
        <caption class="sr-only">Completed trips comparing the billed odometer distance with the GPS trail</caption>
        <thead><tr><th scope="col">Trip</th><th scope="col">Vehicle</th><th scope="col">Driver</th><th scope="col">When</th>
          <th scope="col" class="num">Billed km</th><th scope="col" class="num">GPS km</th><th scope="col" class="num">Difference</th><th scope="col">Check</th></tr></thead>
        <tbody>
        @foreach($tripCheck['rows'] as $r)
          <tr @class(['row-alert' => $r['flagged']])>
            <td><a href="{{ route('tr.show', $r['trip']) }}">{{ $r['trip']->trip_no }}</a></td>
            <td class="small"><b>{{ $r['trip']->vehicle?->code ?? '—' }}</b></td>
            <td class="small">{{ $r['trip']->driver?->name ?? '—' }}</td>
            <td class="small nowrap">{{ $r['trip']->scheduled_at->format('d M H:i') }}</td>
            <td class="num">{{ number_format($r['billed']) }}</td>
            <td class="num">{{ number_format($r['gps'], 1) }}</td>
            <td class="num">{{ $r['diff'] > 0 ? '+' : '' }}{{ number_format($r['diff'], 1) }}</td>
            <td class="small">
              @if($r['flagged'])<span class="badge badge-amber">Check</span><div class="tiny muted">{{ $r['reason'] }}</div>
              @else<span class="badge badge-green">Agrees</span>@endif
            </td>
          </tr>
        @endforeach
        </tbody>
      </table>
      <p class="tiny muted" style="padding:.6rem .9rem .2rem">
        A trip is flagged when the trail and the odometer disagree by more than 3 km and more than 25% of the GPS distance.
        {{ $tripCheck['unchecked'] }} completed trip(s) could not be checked because the odometer, the start time or the finish time was not recorded – these are not counted as agreements.
        GPS distance uses the same jitter filter as the cost report, so a very short transfer may fall below it.
      </p>
    @endif
  </div>
</div>

<div class="card flush">
  <div class="card-head"><h3>Fleet status &amp; running cost</h3>
    <form class="filters" method="get" action="{{ route('tr.fleet.index') }}">
      <label class="sr-only" for="fleetMonth">Month</label>
      <input type="month" id="fleetMonth" name="month" value="{{ $month->format('Y-m') }}" data-autosubmit>
    </form>
  </div>
  <div class="card-body table-wrap">
    <table class="table" id="fleetStatusTable">
      <caption class="sr-only">Each vehicle with its GPS state, month-to-date distance, fuel use, cost per kilometre and workshop status</caption>
      <thead><tr>
        <th scope="col">Vehicle</th><th scope="col">GPS</th><th scope="col">Workshop</th>
        <th scope="col" class="num">km (month)</th><th scope="col" class="num">Fuel</th><th scope="col" class="num">Litres</th>
        <th scope="col" class="num">km / litre</th><th scope="col" class="num">Cost / km</th><th scope="col">Next service</th><th scope="col">Papers</th>
      </tr></thead>
      <tbody>
      @forelse($vehicles as $v)
        @php $c = $cost[$v->id]; $m = $maintenance[$v->id]; $t = $trackers->get($v->id); @endphp
        <tr @class(['muted-row' => ! $v->is_active]) data-vehicle="{{ $v->code }}">
          <td><a href="{{ route('tr.fleet.vehicle', $v) }}"><b>{{ $v->code }}</b></a><div class="tiny muted">{{ $v->name }} · {{ $v->reg_no }}</div></td>
          <td>
            @if(! $t)<span class="fleet-state muted">No device</span>
            @else
              @php $lbl = \App\Services\FleetService::STATES[$t['state']] ?? [$t['state'], 'slate']; @endphp
              @if(can_do('transport.track') && $t['position'])
                <a class="fleet-state" href="https://www.google.com/maps?q={{ $t['position']->latitude }},{{ $t['position']->longitude }}" target="_blank" rel="noopener noreferrer">
                  <span class="fleet-dot {{ $t['state'] }}"></span>{{ $lbl[0] }}<span class="sr-only"> – open the last position in maps</span>
                </a>
                <div class="tiny muted">{{ $t['position']->recorded_at->diffForHumans() }}</div>
              @else
                <span class="fleet-state"><span class="fleet-dot {{ $t['state'] }}"></span>{{ $lbl[0] }}</span>
              @endif
            @endif
          </td>
          <td>
            @if(! $v->is_active)<span class="badge badge-slate">Retired</span>
            @elseif($v->status === 'maintenance')<span class="badge badge-amber">In the workshop</span>
            @elseif($m['overdue']->count())<span class="badge badge-red">Service overdue</span>
            @elseif($m['open']->count())<span class="badge badge-blue">{{ $m['open']->count() }} job(s) open</span>
            @else<span class="badge badge-green">Available</span>@endif
          </td>
          <td class="num">{{ number_format((int) $c['km']) }}</td>
          <td class="num">{{ money($c['fuel']) }}</td>
          <td class="num">{{ $c['litres'] > 0 ? rtrim(rtrim(number_format($c['litres'], 2), '0'), '.') : '—' }}</td>
          <td class="num">{{ $c['km_per_litre'] !== null ? number_format($c['km_per_litre'], 2) : '—' }}</td>
          <td class="num">{{ $c['cost_per_km'] !== null ? money($c['cost_per_km']) : '—' }}</td>
          <td class="small">
            @if($m['overdue']->isNotEmpty())
              <span class="text-red">{{ $m['overdue']->first()->name }}
                @if($m['overdue']->first()->due_date) – due {{ $m['overdue']->first()->due_date->format('d M') }}@endif
                @if($m['overdue']->first()->due_odometer) – at {{ number_format($m['overdue']->first()->due_odometer) }} km @endif
              </span>
            @elseif($m['soon']->isNotEmpty())
              <span class="text-amber">{{ $m['soon']->first()->name }}
                @if($m['soon']->first()->due_date) – {{ $m['soon']->first()->due_date->format('d M') }}@endif
              </span>
            @elseif($m['plans']->isNotEmpty())<span class="muted">{{ $m['plans']->first()->due_date?->format('d M') ?? 'By odometer' }}</span>
            @else<span class="muted">No plan</span>@endif
          </td>
          <td class="small">@if(count($v->expiredPapers()))<span class="badge badge-red">{{ count($v->expiredPapers()) }} expired</span>
            @elseif(count($v->expiringPapers(\App\Services\TransportService::cfg()['paper_days'])))<span class="badge badge-amber">Renew soon</span>
            @else<span class="muted">Valid</span>@endif</td>
        </tr>
      @empty
        <tr><td colspan="10"><x-empty text="No vehicles on the fleet register yet." icon="car"/></td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  <div class="card-body small muted">
    Distance is measured from the GPS trail, so it works even when the odometer is not captured. Fuel and cost figures come from vehicle running expenses.
    @if(can_do('transport.manage')) <a href="{{ route('tr.fleet.fuel') }}">Record a fill</a> · <a href="{{ route('tr.expenses') }}">All running expenses</a>. @endif
  </div>
</div>
@endsection
