@extends('layouts.app')
@section('title', 'Green Initiatives')
@section('crumbs') Sustainability &amp; ESG / Initiatives @endsection
@php $canRec = \App\Services\EsgService::canRecord(auth()->user()); $I = \App\Models\EsgInitiative::class; @endphp
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'esg_initiatives']) }}"><x-icon name="download"/> Initiative register</a>
  @if($canRec)<button class="btn btn-primary" data-modal="esgInitModal" id="esgInitNew"><x-icon name="plus"/> Propose initiative</button>@endif
@endsection
@section('content')
<div class="stats">
  <x-stat label="Approved capital" :value="money($tot['capex'])" icon="money" tone="purple" sub="approved, running and completed"/>
  <x-stat label="Savings per year" :value="money($tot['ghs'])" icon="trend" tone="green" :sub="money($tot['done_ghs']).' already delivered'"/>
  <x-stat label="Carbon saved per year" :value="number_format($tot['co2'] / 1000, 1).' t'" icon="tree" tone="teal" :sub="number_format($tot['done_co2'] / 1000, 1).' t delivered · '.number_format($tot['kwh']).' kWh · '.number_format($tot['m3']).' m³'"/>
</div>
<div class="card"><div class="card-body"><form method="get" class="filters" style="align-items:flex-end">
  <div class="field"><label>Status</label><select name="status"><option value="">All</option>@foreach($I::STATUSES as $k => [$l])<option value="{{ $k }}" @selected(request('status') === $k)>{{ $l }}</option>@endforeach</select></div>
  <div class="field"><label>Category</label><select name="category"><option value="">All</option>@foreach($I::CATEGORIES as $k => $l)<option value="{{ $k }}" @selected(request('category') === $k)>{{ $l }}</option>@endforeach</select></div>
  <button class="btn btn-sm">Filter</button></form></div></div>
<div class="card flush"><div class="card-body table-wrap"><table class="table table-compact" id="esgInitTable"><thead><tr><th>Ref</th><th>Initiative</th><th>Owner</th><th class="num">Capex</th><th class="num">Saving / yr</th><th class="num">Payback</th><th class="num">CO₂e / yr</th><th>Due</th><th>Status</th></tr></thead><tbody>
  @forelse($list as $i)<tr class="{{ $i->isLate() ? 'row-red' : ($i->status === 'idea' ? 'row-amber' : '') }}"><td class="nowrap"><a href="{{ route('esg.initiative', $i) }}"><b>{{ $i->number }}</b></a></td>
    <td class="small">{{ $i->title }}<div class="tiny muted">{{ $I::CATEGORIES[$i->category] }} · raised by {{ $i->raiser?->name ?? '—' }}</div></td><td class="small">{{ $i->owner?->name ?? '—' }}</td>
    <td class="num small">{{ money($i->capex) }}</td><td class="num small">{{ money($i->saving_ghs) }}</td><td class="num small">{{ $i->payback() !== null ? $i->payback().' yrs' : '—' }}</td>
    <td class="num small">{{ number_format($i->saving_co2_kg / 1000, 2) }} t</td><td class="small nowrap">{{ $i->completed_on ? 'done '.$i->completed_on->format('d M Y') : ($i->due_on?->format('d M Y') ?? '—') }}</td>
    <td><span class="badge badge-{{ $i->statusTone() }}">{{ $i->statusLabel() }}</span></td></tr>
  @empty<tr><td colspan="9" class="muted">No initiatives.</td></tr>@endforelse
</tbody></table></div></div>
@if($canRec)
<div class="modal" id="esgInitModal"><div class="modal-box lg"><form method="post" action="{{ route('esg.initiatives.store') }}" data-reset>@csrf
  <div class="modal-head"><h3>Propose a green initiative</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body">@include('esg._initiative_fields', ['x' => null])</div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Propose</button></div></form></div></div>
@endif
@endsection
