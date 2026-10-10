@extends('layouts.app')
@section('title', 'Utilities & Energy')
@section('crumbs') Engineering / Utilities & Energy / {{ \App\Http\Controllers\UtilityController::TABS[$tab] }} @endsection
@section('actions')
  @if(can_do('reports.view'))<a class="btn" href="{{ route('reports.show', 'energy_kpis') }}"><x-icon name="chart"/> Energy KPIs</a>@endif
  @if(can_do('utilities.record'))<a class="btn btn-primary" href="{{ route('utilities.index', ['tab' => 'readings']) }}" id="enterReadingsBtn"><x-icon name="edit"/> Enter today's readings</a>@endif
@endsection
@section('content')
@php
  $U = \App\Models\UtilityMeter::UTILITIES;
  $s = $snap;
  $fmt = fn ($n, $d = 0) => $n === null ? '—' : number_format($n, $d);
@endphp
@if($s['meters'] === 0)<div class="alert alert-info"><x-icon name="alert"/><div>No meters yet. @if(can_do('utilities.manage'))<a href="{{ route('utilities.index', ['tab' => 'meters']) }}">Add your main electricity, DG, solar, water and fuel meters</a> to start tracking consumption.@endif</div></div>@endif

<div class="stats">
  <x-stat label="Readings today" :value="$s['read_today'].' / '.$s['meters']" icon="edit" :tone="$s['read_today'] >= $s['meters'] ? 'green' : 'amber'" :href="route('utilities.index', ['tab' => 'readings'])" :sub="bdate()->format('d M Y')"/>
  <x-stat label="Energy yesterday" :value="$s['yesterday'] ? $fmt($s['yesterday']['kwh']).' kWh' : '—'" icon="alert" tone="blue" :sub="$s['yesterday'] && $s['yesterday']['kwh_por'] !== null ? $fmt($s['yesterday']['kwh_por'], 1).' kWh per occupied room' : 'no occupancy'"/>
  <x-stat label="Utility cost yesterday" :value="$s['yesterday'] ? money($s['yesterday']['cost']) : '—'" icon="money" tone="purple" :sub="$s['yesterday'] && $s['yesterday']['cost_por'] !== null ? money($s['yesterday']['cost_por']).' per occupied room' : ''"/>
  <x-stat label="Anomalies to check" :value="$s['anomalies']" icon="alert" :tone="$s['anomalies'] ? 'red' : 'green'" :sub="$s['missing_yesterday'] ? $s['missing_yesterday'].' meter(s) not read yesterday' : 'all meters read yesterday'"/>
</div>

<div class="card flush">
  <div class="card-head"><div class="tabs" style="margin:0;border:0">
    @foreach(\App\Http\Controllers\UtilityController::TABS as $k => $l)
      @if(can_do(\App\Http\Controllers\UtilityController::TAB_PERM[$k]))<a href="{{ route('utilities.index', ['tab' => $k]) }}" class="{{ $tab === $k ? 'active' : '' }}" data-tab="{{ $k }}">{{ $l }}</a>@endif
    @endforeach
  </div></div>

@if($tab === 'overview')
  @php $t = $kpi['total']; $days = $kpi['rows']; @endphp
  <div class="card-body">
    <form method="get" class="flex" style="gap:.5rem;flex-wrap:wrap;align-items:flex-end" id="rangeForm">
      <input type="hidden" name="tab" value="overview">
      <div class="field" style="margin:0"><label>From</label><input type="date" name="from" value="{{ $from }}"></div>
      <div class="field" style="margin:0"><label>To</label><input type="date" name="to" value="{{ $to }}"></div>
      <button class="btn"><x-icon name="refresh"/> Show</button>
      <span class="muted small">{{ \Carbon\Carbon::parse($from)->format('d M') }} – {{ \Carbon\Carbon::parse($to)->format('d M Y') }} · {{ $t['rooms'] }} occupied room-nights · {{ $t['guests'] }} guest-nights</span>
    </form>
    @if($missingY->count())<div class="alert alert-warn" id="missingAlert" style="margin-top:.8rem"><x-icon name="clock"/><div><b>{{ $missingY->count() }}</b> meter(s) not read yesterday: {{ $missingY->pluck('code')->implode(', ') }}@if(can_do('utilities.record')) – <a href="{{ route('utilities.index', ['tab' => 'readings', 'date' => bdate()->copy()->subDay()->toDateString()]) }}">enter them now</a>@endif. The next reading will cover both days.</div></div>@endif

    <div class="grid g-4" style="margin-top:.8rem" id="kpiCards">
      <div class="kpi"><div class="muted small">Energy per occupied room</div><div class="kpi-v" id="kpiKwhPor">{{ $fmt($t['kwh_por'], 1) }} <small>kWh</small></div><div class="tiny muted">{{ $fmt($t['kwh']) }} kWh · {{ $t['solar_share'] }}% solar</div></div>
      <div class="kpi"><div class="muted small">Water per guest night</div><div class="kpi-v" id="kpiWater">{{ $fmt($t['water_lgn']) }} <small>litres</small></div><div class="tiny muted">{{ $fmt($t['water'], 1) }} m³ total</div></div>
      <div class="kpi"><div class="muted small">Utility cost per occupied room</div><div class="kpi-v" id="kpiCostPor">{{ $t['cost_por'] !== null ? money($t['cost_por']) : '—' }}</div><div class="tiny muted">{{ money($t['cost']) }}@if($t['cost_pct'] !== null) · {{ $t['cost_pct'] }}% of revenue @endif</div></div>
      <div class="kpi"><div class="muted small">Carbon footprint</div><div class="kpi-v" id="kpiCo2">{{ $fmt($carbon['total_kg'] / 1000, 2) }} <small>t CO₂e</small></div><div class="tiny muted">{{ $fmt($carbon['per_room'], 1) }} kg per occupied room</div></div>
    </div>
  </div>

  {{-- daily electricity (stacked grid / DG / solar) --}}
  @php
    $maxE = max(1, collect($days)->max('kwh'));
    $n = count($days); $w = 900; $h = 170; $bw = $n ? $w / $n : $w;
    $daily = \App\Services\UtilityService::daily($from, $to);
  @endphp
  <div class="card-body" style="border-top:1px solid var(--c-border)">
    <div class="flex" style="justify-content:space-between;align-items:center"><h3 style="margin:0">Daily electricity (kWh)</h3>
      <div class="small">@foreach(['electricity', 'dg', 'solar'] as $u)<span style="display:inline-block;width:10px;height:10px;background:{{ $U[$u][2] }};border-radius:2px;margin:0 .25rem 0 .7rem"></span>{{ $U[$u][0] }}@endforeach</div></div>
    <svg viewBox="0 0 {{ $w }} {{ $h + 18 }}" width="100%" style="max-height:220px;margin-top:.5rem" id="energyChart" role="img" aria-label="Daily electricity">
      @foreach($daily as $d => $row)
        @php $x = $loop->index * $bw; $y = $h; @endphp
        @foreach(['electricity', 'dg', 'solar'] as $u)
          @php $bh = $row[$u] / $maxE * ($h - 10); $y -= $bh; @endphp
          @if($bh > 0)<rect x="{{ round($x + 1, 1) }}" y="{{ round($y, 1) }}" width="{{ round(max(1, $bw - 2), 1) }}" height="{{ round($bh, 1) }}" fill="{{ $U[$u][2] }}"><title>{{ \Carbon\Carbon::parse($d)->format('d M') }} · {{ $U[$u][0] }} {{ number_format($row[$u]) }} kWh</title></rect>@endif
        @endforeach
        @if($loop->index % max(1, intdiv($n, 10)) === 0)<text x="{{ round($x + $bw / 2, 1) }}" y="{{ $h + 14 }}" font-size="10" text-anchor="middle" fill="#64748b">{{ \Carbon\Carbon::parse($d)->format('d M') }}</text>@endif
      @endforeach
      <line x1="0" y1="{{ $h }}" x2="{{ $w }}" y2="{{ $h }}" stroke="#cbd5e1"/>
    </svg>
  </div>

  <div class="grid g-2" style="gap:0;border-top:1px solid var(--c-border)">
    <div>
      <div class="card-head"><h3><x-icon name="layers"/> By utility (main meters)</h3></div>
      <div class="table-wrap"><table class="table" id="byUtility">
        <thead><tr><th>Utility</th><th class="right">Consumption</th><th class="right">Cost</th><th class="right">Share</th></tr></thead>
        <tbody>
        @php $tc = max(0.01, array_sum(array_column($by, 'cost'))); @endphp
        @foreach($by as $u => $v) @if($v['cons'] > 0 || $v['cost'] > 0)
          <tr><td><span class="badge" style="background:{{ $U[$u][2] }}1a;color:{{ $U[$u][2] }}">{{ $U[$u][0] }}</span></td><td class="right">{{ number_format($v['cons'], 1) }} {{ \App\Models\UtilityMeter::UNITS[$U[$u][1]] }}</td>
            <td class="right">{{ money($v['cost']) }}</td><td class="right">{{ round($v['cost'] / $tc * 100) }}%</td></tr>
        @endif @endforeach
        </tbody>
      </table></div>
    </div>
    <div style="border-left:1px solid var(--c-border)">
      <div class="card-head"><h3><x-icon name="tree"/> Carbon footprint</h3><span class="small muted">kg CO₂e</span></div>
      <div class="table-wrap"><table class="table" id="carbonTable">
        <thead><tr><th>Source</th><th>Scope</th><th class="right">Factor</th><th class="right">kg CO₂e</th></tr></thead>
        <tbody>
        @foreach($carbon['rows'] as $u => $r) @if($r['kg'] > 0)
          <tr><td>{{ $r['label'] }}</td><td>Scope {{ $r['scope'] }}</td><td class="right small">{{ $r['factor'] }} / {{ $r['unit'] }}</td><td class="right">{{ number_format($r['kg']) }}</td></tr>
        @endif @endforeach
        <tr style="font-weight:700"><td colspan="3">Total</td><td class="right">{{ number_format($carbon['total_kg']) }}</td></tr>
        @if($carbon['avoided_kg'] > 0)<tr class="text-green"><td colspan="3">Avoided by solar generation</td><td class="right">−{{ number_format($carbon['avoided_kg']) }}</td></tr>@endif
        </tbody>
      </table></div>
    </div>
  </div>

  @if($anomalies->count())
  <div class="card-head" style="border-top:1px solid var(--c-border)"><h3><x-icon name="alert"/> Usage anomalies to check</h3><span class="small muted">last 14 days, not yet acknowledged</span></div>
  <div class="table-wrap"><table class="table" id="anomalyTable">
    <thead><tr><th>Date</th><th>Meter</th><th>Flag</th><th>Detail</th><th></th></tr></thead>
    <tbody>
    @foreach($anomalies as $a)
      <tr><td class="nowrap">{{ $a->reading_date->format('d M') }}</td><td><a href="{{ route('utilities.meter', $a->meter) }}">{{ $a->meter->code }}</a> <span class="muted small">{{ $a->meter->name }}</span></td>
        <td><span class="badge badge-{{ \App\Models\MeterReading::ANOMALIES[$a->anomaly][1] }}">{{ \App\Models\MeterReading::ANOMALIES[$a->anomaly][0] }}</span></td><td class="small">{{ $a->anomaly_note }}</td>
        <td class="right">@if(can_do('utilities.record'))<form method="post" action="{{ route('utilities.ack', $a) }}" class="flex" style="gap:.3rem;justify-content:flex-end">@csrf<input class="input" name="note" placeholder="Cause (e.g. banquet, leak fixed)" style="max-width:220px"><button class="btn btn-sm">Acknowledge</button></form>@endif</td></tr>
    @endforeach
    </tbody>
  </table></div>
  @endif

  <div class="card-head" style="border-top:1px solid var(--c-border)"><h3><x-icon name="trend"/> Daily KPIs</h3><span class="small muted">occupancy from the night audit; revenue for closed days</span></div>
  <div class="table-wrap"><table class="table table-compact" id="kpiTable">
    <thead><tr><th>Date</th><th class="right">Occ. rooms</th><th class="right">Guests</th><th class="right">kWh</th><th class="right">kWh / room</th><th class="right">Water m³</th><th class="right">L / guest</th><th class="right">Cost</th><th class="right">Cost / room</th><th class="right">% revenue</th><th class="right">kg CO₂ / room</th></tr></thead>
    <tbody>
    @foreach(array_reverse($days, true) as $d => $r)
      <tr><td class="nowrap">{{ \Carbon\Carbon::parse($d)->format('D d M') }}</td><td class="right">{{ $r['rooms'] }}</td><td class="right">{{ $r['guests'] }}</td><td class="right">{{ number_format($r['kwh']) }}</td>
        <td class="right">{{ $fmt($r['kwh_por'], 1) }}</td><td class="right">{{ number_format($r['water'], 1) }}</td><td class="right">{{ $fmt($r['water_lgn']) }}</td><td class="right">{{ money($r['cost']) }}</td>
        <td class="right">{{ $r['cost_por'] !== null ? money($r['cost_por']) : '—' }}</td><td class="right">{{ $r['cost_pct'] !== null ? $r['cost_pct'].'%' : '—' }}</td><td class="right">{{ $fmt($r['co2_por'], 1) }}</td></tr>
    @endforeach
    </tbody>
  </table></div>

@elseif($tab === 'readings')
  @php $prevDay = \Carbon\Carbon::parse($date)->subDay()->toDateString(); $nextDay = \Carbon\Carbon::parse($date)->addDay()->toDateString(); $canRec = can_do('utilities.record'); @endphp
  <div class="card-body flex" style="gap:.6rem;align-items:center;flex-wrap:wrap">
    <a class="btn btn-sm" href="{{ route('utilities.index', ['tab' => 'readings', 'date' => $prevDay]) }}"><span style="display:inline-flex;transform:rotate(180deg)"><x-icon name="chev"/></span> Previous day</a>
    <form method="get" class="flex" style="gap:.4rem"><input type="hidden" name="tab" value="readings"><input type="date" name="date" value="{{ $date }}" max="{{ bdate()->toDateString() }}" onchange="this.form.submit()"></form>
    @if($date < bdate()->toDateString())<a class="btn btn-sm" href="{{ route('utilities.index', ['tab' => 'readings', 'date' => $nextDay]) }}">Next day</a>@endif
    <span class="muted small">Read every meter at the same time each day. Values are the register reading as shown on the meter – the CT multiplier is applied automatically.</span>
  </div>
  <form method="post" action="{{ route('utilities.readings') }}" id="readingsForm">@csrf
    <input type="hidden" name="date" value="{{ $date }}">
    <div class="table-wrap"><table class="table" id="readingsTable">
      <thead><tr><th>Meter</th><th>Previous</th><th class="right">Expected</th><th style="width:150px">Reading {{ \Carbon\Carbon::parse($date)->format('d M') }}</th><th class="right">Consumption</th><th>Estimated</th><th>Meter replaced</th><th>Note</th></tr></thead>
      <tbody>
      @forelse($rows as $row)
        @php $m = $row['meter']; $cur = $row['cur']; $p = $row['prev']; @endphp
        <tr data-meter="{{ $m->code }}">
          <td><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:{{ $m->color() }}"></span> <a href="{{ route('utilities.meter', $m) }}"><b>{{ $m->code }}</b></a> {{ $m->name }}
            <div class="tiny muted">{{ $m->utilityLabel() }} · {{ $m->unitLabel() }}@if($m->multiplier != 1) · ×{{ rtrim(rtrim(number_format($m->multiplier, 3), '0'), '.') }}@endif @if($m->parent) · sub-meter of {{ $m->parent->code }}@endif</div></td>
          <td class="small nowrap">@if($p){{ number_format($p->reading, 2) }}<div class="tiny muted">{{ $p->reading_date->format('d M') }}</div>@else<span class="muted">opening</span>@endif</td>
          <td class="right small muted">{{ $row['expected'] !== null ? number_format($row['expected'], 1) : '—' }}</td>
          <td><input class="input" name="r[{{ $m->id }}][reading]" inputmode="decimal" value="{{ old('r.'.$m->id.'.reading', $cur ? rtrim(rtrim(number_format($cur->reading, 3, '.', ''), '0'), '.') : '') }}" @disabled(! $canRec) placeholder="{{ $row['expected'] !== null ? '≈ '.number_format($row['expected'], 0, '.', '') : '' }}"></td>
          <td class="right nowrap">@if($cur){{ number_format($cur->consumption, 1) }} <span class="tiny muted">{{ $m->unitShort() }}</span>@if($cur->cost > 0)<div class="tiny muted">{{ money($cur->cost) }}</div>@endif
              @if($cur->anomaly)<div><span class="badge badge-{{ \App\Models\MeterReading::ANOMALIES[$cur->anomaly][1] }}" title="{{ $cur->anomaly_note }}">{{ \App\Models\MeterReading::ANOMALIES[$cur->anomaly][0] }}</span></div>@endif @if($cur->rolled_over)<div class="tiny muted">rolled over</div>@endif
            @else<span class="muted">—</span>@endif</td>
          <td class="center"><input type="hidden" name="r[{{ $m->id }}][estimated]" value="0"><input type="checkbox" name="r[{{ $m->id }}][estimated]" value="1" @checked($cur?->is_estimated) @disabled(! $canRec)></td>
          <td class="center"><input type="hidden" name="r[{{ $m->id }}][reset]" value="0"><input type="checkbox" name="r[{{ $m->id }}][reset]" value="1" @checked($cur?->meter_reset) @disabled(! $canRec)></td>
          <td><input class="input" name="r[{{ $m->id }}][note]" value="{{ $cur?->note }}" maxlength="190" @disabled(! $canRec)>@if($cur?->user)<div class="tiny muted">{{ $cur->user->name }} · {{ $cur->updated_at->format('d M H:i') }}</div>@endif</td>
        </tr>
      @empty
        <tr><td colspan="8" class="muted center">No active meters.</td></tr>
      @endforelse
      </tbody>
    </table></div>
    @if($canRec && $rows->count())<div class="card-body right"><button class="btn btn-primary" id="saveReadings"><x-icon name="check"/> Save readings</button></div>@endif
  </form>

@elseif($tab === 'meters')
  <div class="card-body right" style="padding-bottom:0">@if(can_do('utilities.manage'))<button class="btn btn-primary" data-modal="meterModal" data-title="Add meter" data-action="{{ route('utilities.meters.store') }}" id="addMeterBtn"><x-icon name="plus"/> Add meter</button>@endif</div>
  <div class="table-wrap"><table class="table" id="meterTable">
    <thead><tr><th>Code</th><th>Meter</th><th>Utility</th><th>Area</th><th class="right">Multiplier</th><th class="right">Tariff</th><th>Last reading</th><th>Counts in total</th><th>Status</th><th></th></tr></thead>
    <tbody>
    @forelse($meters as $m)
      <tr class="{{ $m->is_active ? '' : 'muted' }}">
        <td><a href="{{ route('utilities.meter', $m) }}"><b>{{ $m->code }}</b></a></td>
        <td>{{ $m->name }}@if($m->parent)<div class="tiny muted">sub-meter of {{ $m->parent->code }}</div>@endif @if($m->meter_no)<div class="tiny muted">No. {{ $m->meter_no }}</div>@endif</td>
        <td><span class="badge" style="background:{{ $m->color() }}1a;color:{{ $m->color() }}">{{ $m->utilityLabel() }}</span></td>
        <td class="small">{{ $m->area }}</td>
        <td class="right">×{{ rtrim(rtrim(number_format($m->multiplier, 3), '0'), '.') }}</td>
        <td class="right nowrap">{{ money($m->rate) }} <span class="tiny muted">/ {{ $m->unitShort() }}</span></td>
        <td class="small nowrap">@if($m->lastReading){{ number_format($m->lastReading->reading, 2) }} <span class="muted">{{ $m->lastReading->reading_date->format('d M') }}</span>@else<span class="muted">none</span>@endif</td>
        <td>{!! $m->is_main ? '<span class="badge badge-green">Main</span>' : '<span class="badge badge-slate">Sub-meter</span>' !!}</td>
        <td>{!! $m->is_active ? '<span class="badge badge-green">Active</span>' : '<span class="badge badge-slate">Inactive</span>' !!}</td>
        <td class="right">@if(can_do('utilities.manage'))<button class="btn btn-sm btn-ghost" data-modal="meterModal" data-title="Edit meter {{ $m->code }}" data-action="{{ route('utilities.meters.update', $m) }}"
          data-f-code="{{ $m->code }}" data-f-name="{{ $m->name }}" data-f-utility="{{ $m->utility }}" data-f-unit="{{ $m->unit }}" data-f-meter_no="{{ $m->meter_no }}" data-f-area="{{ $m->area }}"
          data-f-parent_id="{{ $m->parent_id }}" data-f-multiplier="{{ $m->multiplier }}" data-f-digits="{{ $m->digits }}" data-f-rate="{{ $m->rate }}" data-f-alert_pct="{{ $m->alert_pct }}"
          data-f-is_main="{{ $m->is_main ? 1 : 0 }}" data-f-is_active="{{ $m->is_active ? 1 : 0 }}" data-f-notes="{{ $m->notes }}" data-f-opening=""><x-icon name="edit"/></button>@endif</td>
      </tr>
    @empty
      <tr><td colspan="10" class="muted center">No meters yet.</td></tr>
    @endforelse
    </tbody>
  </table></div>

@elseif($tab === 'bills')
  <div class="card-body flex" style="justify-content:space-between;padding-bottom:0"><span class="muted small">Metered units = consumption of readings after the period start up to the period end. A variance above ±3% usually means a missed reading, a wrong multiplier or a billing error.</span>
    @if(can_do('utilities.manage'))<button class="btn btn-primary" data-modal="billModal" id="addBillBtn"><x-icon name="plus"/> Enter bill</button>@endif</div>
  <div class="table-wrap"><table class="table" id="billTable">
    <thead><tr><th>Bill</th><th>Meter</th><th>Period</th><th class="right">Billed</th><th class="right">Metered</th><th class="right">Variance</th><th class="right">Total</th><th class="right">Rate / unit</th><th>Accounts payable</th><th></th></tr></thead>
    <tbody>
    @forelse($bills as $b)
      @php $vp = $b->variancePct(); @endphp
      <tr>
        <td><b>{{ $b->bill_no }}</b><div class="tiny muted">{{ $b->bill_date->format('d M Y') }}@if($b->due_date) · due {{ $b->due_date->format('d M') }}@endif</div></td>
        <td>{{ $b->meter->code }}<div class="tiny muted">{{ $b->meter->utilityLabel() }}</div></td>
        <td class="small nowrap">{{ $b->period_from->format('d M') }} – {{ $b->period_to->format('d M Y') }}</td>
        <td class="right">{{ number_format($b->units_billed) }}</td><td class="right">{{ number_format($b->metered_units) }}</td>
        <td class="right {{ $vp !== null && abs($vp) > 3 ? 'text-red' : 'text-green' }}">{{ $vp === null ? '—' : ($vp >= 0 ? '+' : '').$vp.'%' }}</td>
        <td class="right">{{ money($b->total) }}<div class="tiny muted">fixed {{ money($b->fixed_charges) }} · tax {{ money($b->taxes) }}</div></td>
        <td class="right">{{ number_format($b->effectiveRate(), 2) }}<div class="tiny muted">tariff {{ number_format($b->meter->rate, 2) }}</div></td>
        <td>@if($b->supplierInvoice)<span class="badge badge-green">{{ $b->supplierInvoice->doc_no }}</span><div class="tiny muted">{{ $b->supplier?->name }}</div>@else<span class="badge badge-amber">Not booked</span>@endif</td>
        <td class="right nowrap">@if(can_do('utilities.manage'))
          @if(! $b->supplier_invoice_id)<button class="btn btn-sm" data-modal="postModal" data-action="{{ route('utilities.bills.post', $b) }}" data-f-supplier_id="{{ $b->supplier_id }}" data-title="Book {{ $b->bill_no }} ({{ money($b->total) }}) to AP">Book to AP</button>@endif
          @if($b->units_billed > 0 && abs($b->effectiveRate() - $b->meter->rate) > 0.005)<form method="post" action="{{ route('utilities.bills.rate', $b) }}" style="display:inline" data-confirm="Set the tariff of {{ $b->meter->code }} to {{ number_format($b->effectiveRate(), 4) }} per {{ $b->meter->unitShort() }}?">@csrf<button class="btn btn-sm btn-ghost" title="Use this bill's effective rate as the meter tariff">Adopt rate</button></form>@endif
        @endif</td>
      </tr>
    @empty
      <tr><td colspan="10" class="muted center">No utility bills entered yet.</td></tr>
    @endforelse
    </tbody>
  </table></div>
  <div class="card-body">{{ $bills->links() }}</div>

@elseif($tab === 'settings')
  <form method="post" action="{{ route('utilities.settings') }}" class="card-body" id="utilSettings">@csrf
    <div class="form-grid">
      <x-field name="alert_pct" label="Default anomaly threshold (% deviation from the recent average)" type="number" min="5" max="500" :value="$cfg['alert_pct']" required/>
      <div class="field"><label>Night audit</label><label class="check"><input type="hidden" name="require_readings" value="0"><input type="checkbox" name="require_readings" value="1" @checked($cfg['require_readings'])> Warn at night audit when today's meter readings are missing</label></div>
    </div>
    <h4 style="margin:1rem 0 .3rem">Emission factors (kg CO₂e per unit)</h4>
    <p class="small muted" style="margin:0 0 .6rem">Grid electricity: Ghana grid ≈ 0.314 kg/kWh (Ember 2023 generation mix, ≈60 % gas / 38 % hydro) – update each year. Diesel 2.68 kg/L, LPG 2.98 kg/kg, PNG ≈ 2.1 kg/SCM (IPCC defaults). DG units are 0 when the diesel they burn is metered separately (avoids double counting); solar is zero-emission.</p>
    <div class="form-grid">
      @foreach($U as $u => $def)<x-field name="ef[{{ $u }}]" :label="$def[0].' – per '.$def[1]" type="number" step="0.0001" min="0" :value="$cfg['ef'][$u]"/>@endforeach
    </div>
    <div class="right"><button class="btn btn-primary">Save settings</button></div>
  </form>
@endif
</div>

@if(can_do('utilities.manage'))
<div class="modal" id="meterModal"><div class="modal-box" style="max-width:720px"><form method="post" action="{{ route('utilities.meters.store') }}" data-reset>@csrf
  <div class="modal-head"><h3 data-title>Add meter</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <x-field name="code" label="Code" required placeholder="EB-MAIN" maxlength="20"/>
    <x-field name="name" label="Name" required placeholder="Main incomer – HT meter"/>
    <x-field name="utility" label="Utility" type="select" :options="collect($U)->map(fn ($d) => $d[0])" required :placeholder="false"/>
    <x-field name="unit" label="Unit" type="select" :options="\App\Models\UtilityMeter::UNITS" placeholder="Default for the utility"/>
    <x-field name="meter_no" label="Meter / consumer no." maxlength="40"/>
    <x-field name="area" label="Area / cost centre" type="select" :options="array_combine(\App\Models\UtilityMeter::AREAS, \App\Models\UtilityMeter::AREAS)"/>
    <x-field name="parent_id" label="Sub-meter of" type="select" :options="$allMeters->pluck('code', 'id')" placeholder="— main meter —"/>
    <x-field name="multiplier" label="Multiplier (CT / PT factor)" type="number" step="0.001" min="0.001" value="1"/>
    <x-field name="digits" label="Register digits (roll-over)" type="number" min="3" max="9" value="7"/>
    <x-field name="rate" label="Tariff per unit" type="number" step="0.0001" min="0" value="0"/>
    <x-field name="alert_pct" label="Anomaly threshold %" type="number" min="0" max="500" :value="$cfg['alert_pct']" hint="0 = off (standby meters such as a DG set)"/>
    <x-field name="opening" label="Opening reading (new meters)" type="number" step="0.001" min="0" hint="Recorded for today's business date"/>
    <div class="field"><label class="check"><input type="hidden" name="is_main" value="0"><input type="checkbox" name="is_main" value="1" checked> Counts toward the hotel total</label>
      <label class="check"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" checked> Active</label></div>
    <x-field name="notes" label="Notes" type="textarea" rows="2" class="span-2" maxlength="500"/>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button type="submit" class="btn btn-primary">Save meter</button></div>
</form></div></div>

@if($tab === 'bills')
<div class="modal" id="billModal"><div class="modal-box" style="max-width:720px"><form method="post" action="{{ route('utilities.bills.store') }}" data-reset>@csrf
  <div class="modal-head"><h3>Enter utility bill</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <x-field name="utility_meter_id" label="Meter billed" type="select" :options="$allMeters->where('is_main', true)->mapWithKeys(fn ($m) => [$m->id => $m->code.' – '.$m->name])" required/>
    <x-field name="bill_no" label="Bill no." required maxlength="40"/>
    <x-field name="bill_date" label="Bill date" type="date" required :value="bdate()->toDateString()"/>
    <x-field name="due_date" label="Due date" type="date"/>
    <x-field name="period_from" label="Period from (start reading date)" type="date" required/>
    <x-field name="period_to" label="Period to (end reading date)" type="date" required/>
    <x-field name="units_billed" label="Units billed" type="number" step="0.001" min="0"/>
    <x-field name="energy_charges" label="Energy / consumption charges" type="number" step="0.01" min="0" required/>
    <x-field name="fixed_charges" label="Fixed / demand charges, meter rent" type="number" step="0.01" min="0"/>
    <x-field name="taxes" label="Electricity duty, cess & taxes" type="number" step="0.01" min="0"/>
    <x-field name="supplier_id" label="Supplier (utility company)" type="select" :options="$suppliers"/>
    <x-field name="notes" label="Notes" maxlength="500"/>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button type="submit" class="btn btn-primary">Save bill</button></div>
</form></div></div>

<div class="modal" id="postModal"><div class="modal-box" style="max-width:520px"><form method="post" action="#">@csrf
  <div class="modal-head"><h3 data-title>Book to accounts payable</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <p class="small muted" style="margin-top:0">Creates a supplier expense bill (open for payment in Accounts Payable), posts it to the general ledger and counts it against the department budget.</p>
    <x-field name="supplier_id" label="Supplier" type="select" :options="$suppliers" required/>
    <x-field name="account_head_id" label="Expense account" type="select" :options="$heads" placeholder="Default for the utility (Electricity / Water / Fuel)"/>
    <x-field name="budget_reason" label="Reason if over budget" maxlength="300"/>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button type="submit" class="btn btn-success">Book bill</button></div>
</form></div></div>
@endif
@endif
@endsection
