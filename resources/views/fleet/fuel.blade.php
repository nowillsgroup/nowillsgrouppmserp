@extends('layouts.app')
@section('title', 'Fuel & Efficiency')
@section('crumbs') <a href="{{ route('tr.index') }}">Transport</a> / <a href="{{ route('tr.fleet.index') }}">Fleet</a> / Fuel @endsection
@section('actions')
  @if(can_do('transport.track'))<a class="btn" href="{{ route('tr.fleet.tracking') }}"><x-icon name="wifi"/> Live tracking</a>@endif
  <a class="btn" href="{{ route('tr.fleet.maintenance') }}"><x-icon name="tool"/> Maintenance</a>
  <a class="btn" href="{{ route('tr.fleet.export.fuel', array_filter(['from' => $from->toDateString(), 'to' => $to->toDateString(), 'vehicle' => request('vehicle')])) }}"><x-icon name="download"/> Export CSV</a>
  <a class="btn btn-ghost" href="{{ route('tr.fleet.cost-statement') }}"><x-icon name="receipt"/> Monthly statement</a>
@endsection
@section('content')
<form class="filters" method="get" action="{{ route('tr.fleet.fuel') }}">
  <label class="sr-only" for="fuelFrom">From</label><input type="date" id="fuelFrom" name="from" value="{{ $from->toDateString() }}" max="{{ now()->toDateString() }}">
  <label class="sr-only" for="fuelTo">To</label><input type="date" id="fuelTo" name="to" value="{{ $to->toDateString() }}" max="{{ now()->toDateString() }}">
  <label class="sr-only" for="fuelVehicle">Vehicle</label>
  <select name="vehicle" id="fuelVehicle">
    <option value="">All vehicles</option>
    @foreach($vehicles as $v)<option value="{{ $v->id }}" @selected(request('vehicle') == $v->id)>{{ $v->label() }}</option>@endforeach
  </select>
  <button class="btn btn-sm">Apply</button>
  <a class="btn btn-sm btn-ghost" href="{{ route('tr.fleet.fuel') }}">Reset</a>
</form>

<div class="stats">
  <x-stat label="Fuel spend" :value="money($spend)" icon="wallet" tone="amber" :sub="$from->format('d M').' – '.$to->format('d M Y')"/>
  <x-stat label="Litres filled" :value="rtrim(rtrim(number_format($litres, 2), '0'), '.')" icon="car" tone="blue" :sub="$rows->count().' fill(s) shown'"/>
  <x-stat label="Distance (GPS)" :value="number_format((int) $km).' km'" icon="chart" tone="purple" :sub="'Tracked trail in the period'"/>
  <x-stat label="Average" :value="$avgKmL !== null ? number_format($avgKmL, 2).' km/l' : '—'" icon="trend" tone="green" :sub="$avgCostKm !== null ? money($avgCostKm).' fuel per km' : 'No distance recorded yet'"/>
</div>

@if($checks->isNotEmpty())
<div class="card" id="fuelChecks">
  <div class="card-head"><h3>Fills to check</h3><span class="small muted">{{ $checks->count() }} in this period</span></div>
  <div class="card-body">
    @foreach($checks as $c)
      <div class="small" style="padding:.4rem 0;border-bottom:1px solid var(--c-border)">
        <a href="{{ route('tr.fleet.vehicle', $c['vehicle']) }}"><b>{{ $c['vehicle']->code }}</b></a>
        · {{ $c['expense']->date->format('d M') }}
        · {{ number_format((float) $c['expense']->litres, 2) }} L · {{ money($c['expense']->amount) }}
        @if($c['expense']->odometer)<span class="tiny muted">· {{ number_format($c['expense']->odometer) }} km</span>@endif
        <div class="tiny muted">{{ $c['reason'] }}</div>
      </div>
    @endforeach
    <p class="tiny muted mt-1">A fill is listed when consumption falls below 60% of the vehicle's own median, the odometer does not advance, the fill has no odometer, or the odometer runs ahead of / behind the GPS trail by more than 25% (GPS rules need at least 20 km of trail). Ask for the pump receipt before treating any of these as a loss.</p>
  </div>
</div>
@endif

<div class="grid g-2">
  <div class="card" id="fuelPrice">
    <div class="card-head"><h3>Fuel price trend</h3><span class="small muted">Last 6 months · price per litre</span></div>
    <div class="card-body">
      @if($procurement['total_litres'] <= 0)
        <p class="small muted m-0">No fuel fill in the last 6 months, so there is no price trend to draw.</p>
      @else
        @php
          $priced = collect($procurement['months'])->filter(fn ($m) => $m['price'] !== null);
          $maxP = (float) $priced->max('price');
          $base = (float) $priced->min('price') * 0.92;
          $span = max(0.01, $maxP - $base);
        @endphp
        <div class="flex gap-1" style="align-items:flex-end;height:120px" role="img"
          aria-label="Average fuel price per litre for the last {{ count($procurement['months']) }} months, cheapest {{ number_format((float) $priced->min('price'), 2) }} and dearest {{ number_format($maxP, 2) }}">
          @foreach($procurement['months'] as $m)
            <div style="flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;height:100%;min-width:0">
              <span class="tiny muted" style="font-size:.65rem">{{ $m['price'] !== null ? number_format($m['price'], 2) : '' }}</span>
              <div style="width:100%;max-width:44px;height:{{ $m['price'] !== null ? max(3, (int) round(($m['price'] - $base) / $span * 78)) : 0 }}px;background:#f59e0b;border-radius:4px 4px 0 0"></div>
              <span class="tiny muted">{{ $m['label'] }}</span>
            </div>
          @endforeach
        </div>
        <p class="tiny muted mt-1">{{ number_format($procurement['total_litres'], 2) }} litres · {{ money($procurement['total_spend']) }} over the period. Price per litre is spend ÷ litres for each month's fills; a month with no fill shows no bar rather than a guess.</p>
      @endif
    </div>
  </div>

  <div class="card" id="fuelVendors">
    <div class="card-head"><h3>Where the fuel came from</h3><span class="small muted">Cheapest first · last 6 months</span></div>
    <div class="card-body">
      @if($procurement['vendors']->isEmpty())
        <p class="small muted m-0">Fills without a recorded station cannot be compared. Add the station when recording a fill.</p>
      @else
        <div class="table-wrap">
          <table class="table">
            <caption class="sr-only">Fuel stations with litres, spend and average price per litre</caption>
            <thead><tr><th scope="col">Station</th><th scope="col" class="num">Fills</th><th scope="col" class="num">Litres</th><th scope="col" class="num">Spend</th><th scope="col" class="num">Per litre</th></tr></thead>
            <tbody>
            @foreach($procurement['vendors'] as $v)
              <tr>
                <td class="small"><b>{{ $v['vendor'] }}</b></td>
                <td class="num">{{ $v['fills'] }}</td>
                <td class="num">{{ number_format($v['litres'], 2) }}</td>
                <td class="num">{{ money($v['spend']) }}</td>
                <td class="num">{{ $v['price'] !== null ? number_format($v['price'], 2) : '—' }}</td>
              </tr>
            @endforeach
            </tbody>
          </table>
        </div>
        <p class="tiny muted mt-1">The cheapest litre is not always the cheapest fill: weigh credit terms and the detour to get there. Stations are free text, so near-duplicate names stay separate until they are cleaned up on the fill form.</p>
      @endif
    </div>
  </div>
</div>

<div class="card" id="costPerKmLeague">
  <div class="card-head"><h3>What a kilometre costs</h3>
    <span class="small muted">Dearest first · {{ $from->format('d M') }} – {{ $to->format('d M Y') }}</span></div>
  <div class="card-body">
    @if($league['rows']->isEmpty())
      <p class="small muted m-0">No vehicle has both a running cost and a GPS trail in this period, so there is nothing to compare.
        Cost per km needs a recorded expense <em>and</em> tracked distance — the figure is never estimated.</p>
    @else
      @if($league['median'] !== null)
        <p class="small m-0" style="margin-bottom:.4rem">Fleet median <b>{{ money($league['median']) }}</b> per km@if($league['flagged'] > 0) · {{ $league['flagged'] }} car(s) more than 25% above it@endif</p>
      @endif
      @foreach($league['rows'] as $r)
        <div class="small" style="padding:.4rem 0;border-bottom:1px solid var(--c-border)">
          <div class="flex justify-between">
            <span><a href="{{ route('tr.fleet.vehicle', $r['vehicle']) }}"><b>{{ $r['vehicle']->code }}</b></a>
              <span class="tiny muted">{{ number_format((int) $r['km']) }} km · {{ money($r['total']) }}</span></span>
            <b>{{ money($r['cost_per_km']) }}</b>
          </div>
          @if($r['flagged'])
            <span class="tiny" style="color:#b45309">▲ more than 25% above the fleet median — check tyres, load, or the fuel log</span>
          @elseif($r['vs'] !== null)
            <div class="tiny muted">{{ $r['vs'] > 0 ? '+' : '' }}{{ number_format($r['vs'], 1) }} % against the fleet median</div>
          @endif
        </div>
      @endforeach
      <p class="tiny muted mt-1">Cost per km is each car's recorded fuel, service, repair and other spend divided by its own GPS trail.
        A car under 50 km is listed but never flagged — a short hop makes the ratio meaningless. Ranking adds no extra distance scan:
        these are the same figures in the vehicle table below.</p>
    @endif
  </div>
</div>

<div class="card" id="costHistory">
  <div class="card-head"><h3>Running cost by month</h3>
    <span class="small muted">Last 12 months · fuel, service and everything else</span></div>
  <div class="card-body">
    @php $spent = collect($history['months'])->filter(fn ($m) => $m['total'] > 0); @endphp
    @if($spent->isEmpty())
      <p class="small muted m-0">No running cost has been posted in the last 12 months, so there is no trend to draw.</p>
    @else
      @php $peak = max(1, (float) $spent->max('total')); @endphp
      <div class="flex gap-1" style="align-items:flex-end;height:130px" role="img"
        aria-label="Total fleet running cost per month for the last 12 months; the highest month is {{ $history['peak']['label'] }} at {{ money($history['peak']['total']) }}">
        @foreach($history['months'] as $m)
          <div style="flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;height:100%;min-width:0"
            title="{{ $m['label'] }}: {{ money($m['total']) }} · fuel {{ money($m['fuel']) }} · service {{ money($m['service']) }}{{ $m['other'] > 0 ? ' · other '.money($m['other']) : '' }}">
            <span class="tiny muted" style="font-size:.62rem">{{ $m['total'] > 0 ? number_format($m['total'] / 1000, 1) : '' }}</span>
            <div style="width:100%;max-width:44px;height:{{ $m['total'] > 0 ? max(3, (int) round($m['total'] / $peak * 84)) : 0 }}px;display:flex;flex-direction:column-reverse;border-radius:4px 4px 0 0;overflow:hidden">
              @if($m['total'] > 0)
                <span style="display:block;height:{{ max(1, (int) round($m['fuel'] / max(0.01, $m['total']) * 100)) }}%;background:#f59e0b"></span>
                <span style="display:block;height:{{ max(0, (int) round($m['service'] / max(0.01, $m['total']) * 100)) }}%;background:#3b82f6"></span>
                <span style="display:block;height:{{ max(0, (int) round($m['other'] / max(0.01, $m['total']) * 100)) }}%;background:#94a3b8"></span>
              @endif
            </div>
            <span class="tiny muted">{{ $m['label'] }}</span>
          </div>
        @endforeach
      </div>
      <p class="tiny muted mt-1">
        Bars are total spend in thousands; the shadings are fuel (amber), service and repair (blue) and tolls / parking / other (grey).
        Twelve months total <b>{{ money($history['total']) }}</b>@if($history['peak']), heaviest in <b>{{ $history['peak']['label'] }}</b> at {{ money($history['peak']['total']) }}@endif.
        A month with nothing posted shows no bar rather than a guess, and the percentage on a month is only drawn against a previous month that actually had spend.
      </p>
    @endif
  </div>
</div>

<div class="card" id="idleTime">
  <div class="card-head"><h3>Standing time</h3>
    <span class="small muted">Last 7 days · fixes with the ignition on and the wheels still</span></div>
  <div class="card-body">
    @if($idle['minutes'] === 0)
      <p class="small muted m-0">No vehicle reported the ignition on while standing still in the last 7 days. Idle time can only be counted when the tracker sends the ignition field.</p>
    @else
      <p class="small m-0">
        <b>{{ intdiv($idle['minutes'], 60) }} h {{ $idle['minutes'] % 60 }} min</b> of standing time across the fleet,
        worth about <b>{{ number_format($idle['litres'], 1) }} litres</b> at the {{ number_format($idle['rate'], 1) }} l/h idle rate in Fleet settings.
      </p>
      <div class="table-wrap mt-1">
        <table class="table" id="idleTable">
          <caption class="sr-only">Idle time per vehicle, with the fuel it is estimated to have burnt</caption>
          <thead><tr><th scope="col">Vehicle</th><th scope="col" class="num">Standing time</th><th scope="col" class="num">≈ litres</th></tr></thead>
          <tbody>
          @foreach($idle['rows'] as $row)
            <tr @class(['row-alert' => $loop->first && $idle['rows']->count() > 1])>
              <td><b>{{ $row['vehicle']->code }}</b><div class="tiny muted">{{ $row['vehicle']->name }}</div></td>
              <td class="num">{{ intdiv($row['minutes'], 60) }} h {{ $row['minutes'] % 60 }} min</td>
              <td class="num">{{ number_format($row['litres'], 1) }}</td>
            </tr>
          @endforeach
          </tbody>
        </table>
      </div>
    @endif
    <p class="tiny muted mt-1">
      Two consecutive fixes at most {{ $idle['gap'] }} minutes apart, both reporting the ignition on, with the later one below
      {{ number_format(\App\Services\FleetService::cfg()['speed_moving_kph'], 0) }} km/h, count as standing time for that interval.
      A car parked overnight with the ignition off contributes nothing, and a gap longer than {{ $idle['gap'] }} minutes is skipped rather than
      credited to idling. The litre figure is an assumption priced from Fleet settings — the minutes and the rankings are measured.
    </p>
  </div>
</div>

<div class="card flush" id="efficiencyLeague">
  <div class="card-head"><h3>Efficiency per vehicle</h3>
    <span class="small muted">Distance from the GPS trail; fuel from running expenses · last 90 days</span></div>
  <div class="card-body table-wrap">
    @php $eff = $efficiency['rows']->keyBy(fn ($r) => $r['vehicle']->id); @endphp
    @if($efficiency['median'] === null)
      <p class="small muted" style="padding:.6rem .9rem 0">No vehicle has both a fuel fill and recorded GPS distance in the last 90 days, so there is no fleet median to compare against yet.</p>
    @else
      <p class="small" style="padding:.6rem .9rem 0">
        Fleet median <b>{{ number_format($efficiency['median'], 2) }} km/l</b>.
        @if($eff->where('flagged', true)->count())
          <b>{{ $eff->where('flagged', true)->count() }}</b> vehicle(s) run more than 25% below it:
          {{ $eff->where('flagged', true)->map(fn ($r) => $r['vehicle']->code)->implode(', ') }}.
        @else
          No vehicle runs more than 25% below the median.
        @endif
      </p>
    @endif
    <table class="table" id="fuelEfficiency">
      <caption class="sr-only">Fuel consumption and cost per kilometre for each vehicle in the selected period, with a comparison against the fleet median</caption>
      <thead><tr><th scope="col">Vehicle</th><th scope="col" class="num">Fills</th><th scope="col" class="num">Litres</th><th scope="col" class="num">Fuel cost</th><th scope="col" class="num">Service &amp; repair</th><th scope="col" class="num">km</th><th scope="col" class="num">km / litre</th><th scope="col" class="num">vs median</th><th scope="col" class="num">Cost / km</th></tr></thead>
      <tbody>
      @forelse($vehicles as $v)
        @php $c = $stats[$v->id]; $league = $eff->get($v->id); @endphp
        @continue($c['fills'] === 0 && $c['km'] == 0 && $c['service'] == 0)
        <tr @class(['muted-row' => ! $v->is_active, 'row-alert' => $league && $league['flagged']])>
          <td><b>{{ $v->code }}</b>
            @if($league && $league['flagged'])<span class="badge badge-amber">Below median</span>@endif
            @if($league && $league['km'] > 0 && $league['km'] < 50)<span class="badge badge-slate" title="Too little distance for a reliable ratio">short run</span>@endif
            <div class="tiny muted">{{ $v->name }} · {{ $v->reg_no }} · {{ \App\Models\Vehicle::FUELS[$v->fuel] ?? $v->fuel }}</div></td>
          <td class="num">{{ $c['fills'] }}</td>
          <td class="num">{{ $c['litres'] > 0 ? number_format($c['litres'], 2) : '—' }}</td>
          <td class="num">{{ money($c['fuel']) }}</td>
          <td class="num">{{ money($c['service']) }}</td>
          <td class="num">{{ number_format((int) $c['km']) }}</td>
          <td class="num">{{ $c['km_per_litre'] !== null ? number_format($c['km_per_litre'], 2) : '—' }}</td>
          <td class="num">{{ $league && $league['vs'] !== null ? ($league['vs'] > 0 ? '+' : '').number_format($league['vs'], 1).'%' : '—' }}</td>
          <td class="num">{{ $c['cost_per_km'] !== null ? money($c['cost_per_km']) : '—' }}</td>
        </tr>
      @empty
        <tr><td colspan="9"><x-empty text="No fuel or running cost was recorded for this period." icon="wallet"/></td></tr>
      @endforelse
      </tbody>
    </table>
    <p class="tiny muted" style="padding:.5rem .9rem .6rem">
      “vs median” compares each vehicle with the fleet's own median km/l, never an outside benchmark — a hill route or a heavy van can be normal here.
      A car is only flagged when it is more than 25% below the median <b>and</b> has covered at least 50 km, so a short week in traffic is not labelled wasteful.
      Cars with neither fuel nor distance are left out; their spend still shows on the vehicle page.
    </p>
  </div>
</div>

<div class="grid g-2-1">
  <div class="card flush"><div class="card-head"><h3>Fuel fills</h3><span class="small muted">Latest 300 in the period</span></div>
    <div class="card-body table-wrap">
      <table class="table" id="fuelFills">
        <caption class="sr-only">Individual fuel fills with litres, amount, odometer, supplier and driver</caption>
        <thead><tr><th scope="col">Date</th><th scope="col">Vehicle</th><th scope="col" class="num">Litres</th><th scope="col" class="num">Amount</th><th scope="col" class="num">Odometer</th><th scope="col">Supplier</th><th scope="col">Driver</th><th scope="col"><span class="sr-only">Correct or delete</span></th></tr></thead>
        <tbody>
        @forelse($rows as $e)
          <tr>
            <td class="nowrap">{{ $e->date->format('d M') }}</td>
            <td><b>{{ $e->vehicle?->code }}</b></td>
            <td class="num">{{ number_format((float) $e->litres, 2) }}</td>
            <td class="num">{{ money($e->amount) }}</td>
            <td class="num">{{ $e->odometer ? number_format($e->odometer) : '—' }}</td>
            <td class="small">{{ $e->vendor ?: '—' }}@if($e->notes)<div class="tiny muted">{{ $e->notes }}</div>@endif</td>
            <td class="small">{{ $e->driver?->name ?? '—' }}</td>
            <td class="small">
              <details class="fuel-fix">
                <summary class="tiny">Correct</summary>
                <form method="post" action="{{ route('tr.fleet.fuel.update', $e) }}" class="form-grid" style="margin-top:.4rem;min-width:250px">
                  @csrf @method('PUT')
                  <label class="tiny" for="fixDate{{ $e->id }}">Date</label>
                  <input type="date" id="fixDate{{ $e->id }}" name="date" value="{{ $e->date->toDateString() }}" max="{{ now()->toDateString() }}" required>
                  <label class="tiny" for="fixLitres{{ $e->id }}">Litres</label>
                  <input type="number" step="0.01" min="0.01" id="fixLitres{{ $e->id }}" name="litres" value="{{ number_format((float) $e->litres, 2, '.', '') }}" required>
                  <label class="tiny" for="fixAmount{{ $e->id }}">Amount</label>
                  <input type="number" step="0.01" min="0.01" id="fixAmount{{ $e->id }}" name="amount" value="{{ number_format((float) $e->amount, 2, '.', '') }}" required>
                  <label class="tiny" for="fixOdo{{ $e->id }}">Odometer</label>
                  <input type="number" min="0" id="fixOdo{{ $e->id }}" name="odometer" value="{{ $e->odometer }}">
                  <label class="tiny" for="fixVendor{{ $e->id }}">Station</label>
                  <input type="text" maxlength="80" id="fixVendor{{ $e->id }}" name="vendor" value="{{ $e->vendor }}">
                  <label class="tiny" for="fixReason{{ $e->id }}">Why (logged)</label>
                  <input type="text" maxlength="120" minlength="4" id="fixReason{{ $e->id }}" name="reason" placeholder="Pump receipt said 45 L" required>
                  <button class="btn btn-sm btn-primary">Save correction</button>
                </form>
                <form method="post" action="{{ route('tr.fleet.fuel.destroy', $e) }}" onsubmit="return confirm('Delete this fill? It is removed from every cost figure; the odometer reading stays.')" style="margin-top:.4rem">
                  @csrf
                  <label class="tiny" for="delReason{{ $e->id }}">Delete this fill — why (logged)</label>
                  <input type="text" maxlength="120" minlength="4" id="delReason{{ $e->id }}" name="reason" required placeholder="Duplicate entry">
                  <button class="btn btn-sm btn-ghost text-red"><x-icon name="trash" size="sm"/> Delete</button>
                </form>
                <p class="tiny muted" style="margin:.35rem 0 0">Only fuel, toll and parking costs are corrected here. Service and repair money is corrected through its work order.</p>
              </details>
            </td>
          </tr>
        @empty
          <tr><td colspan="8"><x-empty text="No fuel fills in this period." icon="wallet"/></td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </div>

  <div class="card"><div class="card-head"><h3>Record a fill</h3></div><div class="card-body">
    <form method="post" action="{{ route('tr.fleet.fuel.store') }}" class="form-grid" id="fuelForm">@csrf
      <x-field name="vehicle_id" label="Vehicle" type="select" :options="$vehicles->mapWithKeys(fn ($v) => [$v->id => $v->label()])" required class="span-all"/>
      <x-field name="date" label="Date" type="date" :value="now()->toDateString()" required class="span-all"/>
      <x-field name="litres" label="Litres / kWh" type="number" step="0.01" required/>
      <x-field name="amount" label="Amount paid" type="number" step="0.01" required/>
      <x-field name="odometer" label="Odometer (km)" type="number"/>
      <x-field name="vendor" label="Station / pump"/>
      <x-field name="notes" label="Notes" class="span-all"/>
      <div class="span-all"><button class="btn btn-primary btn-block" id="saveFuelBtn"><x-icon name="plus"/> Save fill</button></div>
    </form>
    <p class="tiny muted mt-1">Fills feed the running-cost report and the efficiency figures above. Add the supplier bill through Accounts Payable as usual.</p>
  </div></div>
</div>
@endsection
