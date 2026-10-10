@extends('layouts.app')
@section('title', 'Carbon & HCMI')
@section('crumbs') Sustainability &amp; ESG / Carbon footprint @endsection
@php $canMg = \App\Services\EsgService::canManage(auth()->user()); $f = fn ($v, $d = 1) => $v === null ? '—' : number_format($v, $d); $al = $h['alloc']; @endphp
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'esg_carbon', 'from' => $from, 'to' => $to]) }}"><x-icon name="download"/> Carbon inventory report</a>
  <a class="btn" href="{{ route('esg.statements') }}"><x-icon name="receipt"/> Carbon statements</a>
@endsection
@section('content')
<div class="card"><div class="card-body">@include('esg._range')</div></div>
<div class="card"><div class="card-head"><h3>Hotel Carbon &amp; Water Measurement (HCMI / HWMI)</h3><span class="tiny muted">{{ \Carbon\Carbon::parse($from)->format('d M Y') }} – {{ \Carbon\Carbon::parse($to)->format('d M Y') }} · {{ $h['days'] }} days</span></div><div class="card-body">
  <div class="esg-hcmi" id="esgHcmi">
    <div><div class="l">Footprint per occupied room</div><div class="v">{{ $f($h['per_room'], 2) }}</div><div class="u">kg CO₂e per room-night · {{ number_format($h['occupied']) }} rooms occupied</div></div>
    <div><div class="l">Meeting space per m² per hour</div><div class="v">{{ $f($h['per_m2h'], 4) }}</div><div class="u">kg CO₂e · {{ number_format($h['m2h']) }} m²·h used</div></div>
    <div><div class="l">Per m² floor area per year</div><div class="v">{{ $f($h['per_m2_year'], 2) }}</div><div class="u">kg CO₂e / m² / yr · {{ number_format($al['area']['total']) }} m²</div></div>
    <div><div class="l">Water per occupied room (HWMI)</div><div class="v">{{ $f($h['hwmi_room'], 0) }}</div><div class="u">litres · meeting {{ $f($h['hwmi_m2h'], 2) }} L / m²·h</div></div>
    <div><div class="l">Energy per occupied room</div><div class="v">{{ $f($h['kwh_room'], 1) }}</div><div class="u">kWh (grid + generator + solar)</div></div>
  </div>
  <p class="small muted" style="margin:.8rem 0 0">HCMI boundary: Scope 1 and 2 plus outsourced laundry = <b>{{ number_format($h['boundary_kg'], 0) }} kg</b>@if($al['private_share'] > 0), less {{ round($al['private_share'] * 100, 1) }}% private / tenant area = {{ number_format($h['hotel_kg'], 0) }} kg @endif, split by floor area
    {{ round($al['rooms_share'] * 100, 1) }}% guest rooms &amp; corridors ({{ number_format($h['rooms_kg'], 0) }} kg) and {{ round($al['meeting_share'] * 100, 1) }}% meeting space ({{ number_format($h['meeting_kg'], 0) }} kg).
    @if($h['laundry']['estimated']) Laundry for months without supplier tonnage is estimated at {{ rtrim(rtrim(number_format($cfg['laundry_kg_room'], 4), '0'), '.') }} kg per occupied room.@endif @if($canMg)<a href="{{ route('esg.settings') }}">Areas &amp; factors</a>@endif</p>
</div></div>
<div class="g-2-1">
  <div>
    <div class="card flush"><div class="card-head"><h3>Carbon inventory by scope</h3><span class="tiny muted">{{ number_format($cb['total_kg'] / 1000, 2) }} t CO₂e · {{ $f($cb['per_room'], 2) }} kg per occupied room (all scopes)</span></div>
      <div class="card-body table-wrap"><table class="table table-compact" id="esgInventory"><thead><tr><th>Source</th><th class="center">Scope</th><th class="num">Activity</th><th class="num">Factor</th><th class="num">kg CO₂e</th><th class="num">Share</th></tr></thead><tbody>
      @foreach([1 => 'Scope 1 – direct (fuels burnt on site, refrigerant leaks)', 2 => 'Scope 2 – purchased electricity', 3 => 'Scope 3 – water, waste, outsourced laundry'] as $sc => $lbl)
        <tr class="row-sub"><td colspan="4"><b>{{ $lbl }}</b></td><td class="num"><b>{{ number_format($cb['scopes'][$sc], 0) }}</b></td><td class="num">{{ $cb['total_kg'] > 0 ? round($cb['scopes'][$sc] / $cb['total_kg'] * 100, 1).'%' : '' }}</td></tr>
        @forelse(collect($cb['rows'])->where('scope', $sc) as $k => $r)<tr><td class="small">{{ $r['label'] }}@if($r['note'])<div class="tiny muted">{{ $r['note'] }}</div>@endif</td><td class="center small">{{ $sc }}</td>
          <td class="num small">{{ number_format($r['qty'], 1) }} {{ $r['unit'] }}</td><td class="num small">{{ $r['factor'] ? rtrim(rtrim(number_format($r['factor'], 4), '0'), '.') : '—' }}</td><td class="num">{{ number_format($r['kg'], 0) }}</td>
          <td class="num small">{{ $cb['total_kg'] > 0 ? round($r['kg'] / $cb['total_kg'] * 100, 1).'%' : '' }}</td></tr>
        @empty<tr><td colspan="6" class="muted small">No activity recorded.</td></tr>@endforelse
      @endforeach
      </tbody></table></div></div>
    <div class="card flush"><div class="card-head"><h3>Meeting-space use</h3><span class="tiny muted">indoor functions of definite / completed events · hall area × hours</span></div>
      <div class="card-body table-wrap"><table class="table table-compact" id="esgMeetingUse"><thead><tr><th>Date</th><th>Function</th><th>Hall</th><th class="num">m²</th><th class="num">Hours</th><th class="num">m²·h</th><th class="num">kg CO₂e</th></tr></thead><tbody>
      @forelse($use['rows'] as $x)<tr><td class="small nowrap">{{ $x['function']->date->format('d M Y') }}</td><td class="small">{{ $x['function']->name }}<div class="tiny muted">{{ $x['function']->event?->event_no }}</div></td><td class="small">{{ $x['function']->hall?->name }}</td>
        <td class="num small">{{ number_format($x['m2'], 0) }}</td><td class="num small">{{ $x['hours'] }}</td><td class="num small">{{ number_format($x['m2h'], 0) }}</td><td class="num">{{ $h['per_m2h'] !== null ? number_format($x['m2h'] * $h['per_m2h'], 1) : '—' }}</td></tr>
      @empty<tr><td colspan="7" class="muted small">No indoor functions in the period – the meeting-space share of the footprint cannot be expressed per m²·h.</td></tr>@endforelse
      </tbody></table></div></div>
  </div>
  <div>
    <div class="card"><div class="card-head"><h3>Factors in use</h3></div><div class="card-body"><dl class="kv small">
      <dt>Grid electricity</dt><dd>{{ $ef['electricity'] }} kg/kWh</dd><dt>LPG</dt><dd>{{ $ef['lpg'] }} kg/kg</dd><dt>Diesel</dt><dd>{{ $ef['diesel'] }} kg/L</dd>
      <dt>Water + wastewater</dt><dd>{{ $cfg['ef_water'] }} + {{ $cfg['ef_wastewater'] }} kg/m³</dd><dt>Landfill</dt><dd>{{ $cfg['ef_waste']['landfill'] }} kg/kg (food {{ $cfg['ef_landfill_food'] }})</dd>
      <dt>Recycling</dt><dd>{{ $cfg['ef_waste']['recycling'] }} kg/kg</dd><dt>Laundry</dt><dd>{{ $cfg['ef_laundry'] }} kg per kg linen</dd>
    </dl><p class="tiny muted m-0">Grid: Ghana ≈ 314 g/kWh (Ember, 2023 generation mix). Waste and water: DEFRA / DESNZ conversion factors. Refrigerants: IPCC AR5 GWP-100. Laundry factor is an editable default – replace it with your laundry's figure. Fuel factors are set in Utilities.</p></div></div>
    <div class="card"><div class="card-head"><h3>Outsourced laundry</h3>@if(! $cfg['laundry_outsourced'])<span class="badge badge-slate">in-house</span>@endif</div><div class="card-body">
      <table class="table table-compact" id="esgLaundry"><thead><tr><th>Month</th><th class="num">kg</th><th>Laundry</th></tr></thead><tbody>
      @forelse($laundry as $l)<tr><td class="small">{{ $l->month->format('M Y') }}</td><td class="num">{{ number_format($l->kg, 0) }}</td><td class="small">{{ $l->supplier?->name ?? '—' }}</td></tr>
      @empty<tr><td colspan="3" class="muted small">No tonnage recorded – the HCMI estimate is used.</td></tr>@endforelse
      </tbody></table>
      @if($canMg)<form method="post" action="{{ route('esg.laundry.store') }}" class="form-grid mt-1" id="esgLaundryForm">@csrf
        <div class="field"><label>Month</label><input type="month" name="month" value="{{ bdate()->copy()->subMonthNoOverflow()->format('Y-m') }}" required></div>
        <div class="field"><label>Linen processed (kg)</label><input type="number" name="kg" step="0.1" min="0" required></div>
        <div class="field span-all"><label>Laundry</label><select name="supplier_id" class="input"><option value="">—</option>@foreach($suppliers as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select></div>
        <div class="span-all"><button class="btn btn-sm btn-primary">Save tonnage</button></div>
      </form>@endif
    </div></div>
  </div>
</div>
@endsection
