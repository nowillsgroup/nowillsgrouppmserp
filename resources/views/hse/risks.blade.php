@extends('layouts.app')
@section('title', 'Risk Assessments')
@section('crumbs') Health &amp; Safety / Risk assessments @endsection
@php $canMg = \App\Services\HseService::canManage(auth()->user()); @endphp
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'hse_risks']) }}"><x-icon name="download"/> Risk register</a>
  @if($canMg)<button class="btn btn-primary" data-modal="hseRiskModal" id="hseRiskNew"><x-icon name="plus"/> New assessment</button>@endif
@endsection
@section('content')
<div class="g-2-1">
  <div>
    <div class="card"><div class="card-body"><form method="get" class="flex gap-1 wrap" style="align-items:center">
      <select name="area" aria-label="Area"><option value="">All areas</option>@foreach($areas as $a)<option value="{{ $a }}" @selected(request('area') === $a)>{{ $a }}</option>@endforeach</select>
      <select name="band" aria-label="Residual"><option value="">Any residual risk</option>@foreach(['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'] as $k => $l)<option value="{{ $k }}" @selected(request('band') === $k)>{{ $l }}</option>@endforeach</select>
      <select name="status" aria-label="Status"><option value="">Live</option><option value="pending" @selected(request('status') === 'pending')>Awaiting acceptance</option><option value="archived" @selected(request('status') === 'archived')>Archived</option></select>
      <button class="btn btn-sm">Filter</button>
    </form></div></div>
    <div class="card flush"><div class="card-body table-wrap"><table class="table table-compact" id="hseRisksTable"><thead><tr><th>Ref</th><th>Activity</th><th>Area</th><th class="center">Risk</th><th class="center">Residual</th><th>Owner</th><th>Review</th><th>Status</th></tr></thead><tbody>
      @forelse($risks as $x)@php [, $il, $it] = \App\Models\HseRisk::band($x->score()); [, $rl, $rt] = \App\Models\HseRisk::band($x->residual()); $late = $x->status !== 'archived' && $x->review_on->toDateString() < bdate()->toDateString(); @endphp
      <tr class="{{ $late ? 'row-red' : ($x->status === 'pending' ? 'row-amber' : '') }}"><td><a href="{{ route('hse.risk', $x) }}"><b>{{ $x->number }}</b></a></td><td class="small">{{ $x->title }}</td><td class="small">{{ $x->area }}</td>
        <td class="center"><span class="badge badge-{{ $it }}" title="{{ $il }}">{{ $x->score() }}</span></td><td class="center"><span class="badge badge-{{ $rt }}" title="{{ $rl }}">{{ $x->residual() }}</span></td>
        <td class="small">{{ $x->owner?->name ?? '—' }}</td><td class="small nowrap">{{ $x->review_on->format('d M Y') }}</td>
        <td class="small">{{ ['active' => 'Live', 'pending' => 'Awaiting acceptance', 'archived' => 'Archived'][$x->status] }}{{ $late ? ' · review overdue' : '' }}</td></tr>
      @empty<tr><td colspan="8" class="muted">No assessments.</td></tr>@endforelse
    </tbody></table></div></div>
  </div>
  <div><div class="card"><div class="card-head"><h3>Residual risk map</h3></div><div class="card-body">@include('hse._heat', ['heat' => $heat])</div></div></div>
</div>
<datalist id="hseAreas">@foreach($areas as $a)<option value="{{ $a }}">@endforeach</datalist>
@if($canMg)
<div class="modal" id="hseRiskModal"><div class="modal-box lg"><form method="post" action="{{ route('hse.risks.store') }}" data-reset>@csrf
  <div class="modal-head"><h3>New risk assessment</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body">@include('hse._risk_fields', ['x' => null])</div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save assessment</button></div>
</form></div></div>
@endif
@endsection
