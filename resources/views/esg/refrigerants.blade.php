@extends('layouts.app')
@section('title', 'Refrigerants (F-gas)')
@section('crumbs') Sustainability &amp; ESG / Refrigerants @endsection
@php $canRec = \App\Services\EsgService::canRecord(auth()->user()); $R = \App\Models\EsgRefrigerantLog::class; @endphp
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'esg_refrigerants', 'from' => $from, 'to' => $to]) }}"><x-icon name="download"/> F-gas report</a>
  @if($canRec)<button class="btn btn-primary" data-modal="esgRefModal" id="esgRefNew"><x-icon name="plus"/> Record gas added / recovered</button>@endif
@endsection
@section('content')
<div class="stats">
  <x-stat label="Refrigerant emissions" :value="number_format($list->sum(fn ($r) => $r->co2e()) / 1000, 2).' t'" icon="thermo" tone="amber" :sub="'CO₂e · '.number_format($list->sum(fn ($r) => $r->emittedKg()), 2).' kg gas lost'"/>
  <x-stat label="Gas recovered" :value="number_format($list->sum('kg_recovered'), 2).' kg'" icon="refresh" tone="green" sub="kept out of the atmosphere"/>
  <x-stat label="Systems above {{ $cfg['leak_alert_pct'] }}% / yr" :value="collect($leaks)->where('flag', true)->count()" icon="alert" :tone="collect($leaks)->where('flag', true)->count() ? 'red' : 'green'" sub="annual leak rate = gas replaced ÷ system charge"/>
</div>
<div class="card flush"><div class="card-head"><h3>Leak rate by system – last 12 months</h3></div><div class="card-body table-wrap"><table class="table table-compact" id="esgLeaks"><thead><tr><th>System</th><th>Gas</th><th class="num">GWP</th><th class="num">Charge kg</th><th class="num">Replaced kg</th><th class="num">Leak rate</th><th class="num">kg CO₂e</th><th>Last</th></tr></thead><tbody>
  @forelse($leaks as $l)<tr class="{{ $l['flag'] ? 'row-red' : '' }}"><td class="small"><b>{{ $l['equipment'] }}</b></td><td class="small">{{ $l['gas'] }}</td><td class="num small">{{ number_format($R::gwp($l['gas'])) }}</td><td class="num small">{{ $l['charge'] ?? '—' }}</td>
    <td class="num">{{ $l['added'] }}</td><td class="num">@if($l['rate'] !== null)<span class="badge badge-{{ $l['flag'] ? 'red' : 'green' }}">{{ $l['rate'] }}%</span>@else<span class="tiny muted">charge unknown</span>@endif</td><td class="num">{{ number_format($l['co2']) }}</td><td class="small nowrap">{{ $l['last']->format('d M Y') }}</td></tr>
  @empty<tr><td colspan="8" class="muted">No refrigerant work logged in the last 12 months.</td></tr>@endforelse
</tbody></table></div></div>
<div class="card"><div class="card-body">@include('esg._range')</div></div>
<div class="card flush"><div class="card-head"><h3>F-gas log</h3></div><div class="card-body table-wrap"><table class="table table-compact" id="esgRefList"><thead><tr><th>Ref</th><th>Date</th><th>System</th><th>Work</th><th class="num">Added</th><th class="num">Recovered</th><th class="num">kg CO₂e</th><th>Technician</th></tr></thead><tbody>
  @forelse($list as $r)<tr><td class="nowrap"><b>{{ $r->number }}</b></td><td class="small nowrap">{{ $r->date->format('d M Y') }}</td><td class="small">{{ $r->equipment }}<div class="tiny muted">{{ $r->gas }}{{ $r->asset ? ' · '.$r->asset->asset_no : '' }}{{ $r->ticket ? ' · '.$r->ticket->ticket_no : '' }}</div></td>
    <td class="small">{{ $R::REASONS[$r->reason] ?? $r->reason }}</td><td class="num">{{ (float) $r->kg_added ? number_format($r->kg_added, 2) : '' }}</td><td class="num">{{ (float) $r->kg_recovered ? number_format($r->kg_recovered, 2) : '' }}</td>
    <td class="num">{{ number_format($r->co2e()) }}</td><td class="small">{{ $r->technician ?? '—' }}@if($r->certificate_no)<div class="tiny muted">cert {{ $r->certificate_no }}</div>@endif</td></tr>
  @empty<tr><td colspan="8" class="muted">Nothing logged in the period.</td></tr>@endforelse
</tbody></table></div></div>
@if($canRec)
<div class="modal" id="esgRefModal"><div class="modal-box lg"><form method="post" action="{{ route('esg.refrigerants.store') }}" data-reset>@csrf
  <div class="modal-head"><h3>Refrigerant added / recovered</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field"><label>Date</label><input type="date" name="date" value="{{ bdate()->toDateString() }}" max="{{ bdate()->toDateString() }}" required></div>
    <div class="field"><label>System / equipment</label><input name="equipment" maxlength="120" list="esgSystems" required placeholder="e.g. Chiller 1, Cold room, Split AC 204"></div>
    <div class="field"><label>Refrigerant</label><select name="gas" required>@foreach($R::GASES as $k => [$l, $g])<option value="{{ $k }}">{{ $l }} – GWP {{ number_format($g) }}</option>@endforeach</select></div>
    <div class="field"><label>Work done</label><select name="reason" required>@foreach($R::REASONS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label>Gas added (kg)</label><input type="number" name="kg_added" step="0.001" min="0"></div>
    <div class="field"><label>Gas recovered (kg)</label><input type="number" name="kg_recovered" step="0.001" min="0"></div>
    <div class="field"><label>System full charge (kg)</label><input type="number" name="system_charge_kg" step="0.01" min="0" placeholder="from the nameplate"></div>
    <div class="field"><label>Asset</label><select name="asset_id"><option value="">—</option>@foreach($assets as $a)<option value="{{ $a->id }}">{{ $a->asset_no }} {{ $a->name }}</option>@endforeach</select></div>
    <div class="field"><label>Technician / company</label><input name="technician" maxlength="120"></div>
    <div class="field"><label>Technician certificate no.</label><input name="certificate_no" maxlength="60"></div>
    <div class="field"><label>Maintenance ticket</label><select name="maintenance_request_id"><option value="">—</option>@foreach($tickets as $t)<option value="{{ $t->id }}">{{ $t->ticket_no }} {{ \Illuminate\Support\Str::limit($t->title, 40) }}</option>@endforeach</select></div>
    <div class="field"><label>Notes</label><input name="notes" maxlength="255"></div>
  </div><p class="tiny muted">Gas put into a new system (initial charge) is not an emission. Anything topped up replaces gas that leaked; a service loses what is put back beyond what was recovered; a scrapped system loses whatever of its charge was not recovered. Each counts at its GWP. The leak rate uses operating losses only.</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save</button></div>
</form></div></div>
<datalist id="esgSystems">@foreach(collect($leaks)->pluck('equipment')->merge($list->pluck('equipment'))->unique() as $e)<option value="{{ $e }}">@endforeach</datalist>
@endif
@endsection
