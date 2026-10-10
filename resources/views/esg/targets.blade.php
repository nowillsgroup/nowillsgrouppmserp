@extends('layouts.app')
@section('title', 'ESG Targets')
@section('crumbs') Sustainability &amp; ESG / Targets @endsection
@php $canMg = \App\Services\EsgService::canManage(auth()->user()); $M = \App\Services\EsgService::METRICS; @endphp
@section('actions')
  @if($canMg)<button class="btn btn-primary" data-modal="esgTargetModal" id="esgTargetNew"><x-icon name="plus"/> New target</button>@endif
@endsection
@section('content')
<div class="g-2-1">
  <div class="card flush"><div class="card-head"><h3>Active targets</h3><span class="tiny muted">progress = share of the baseline → target gap closed; on track = at or ahead of a straight line from the baseline year</span></div>
    <div class="card-body table-wrap"><table class="table table-compact" id="esgTargetTable"><thead><tr><th style="min-width:210px">Metric</th><th class="num">Baseline</th><th class="num">Expected now</th><th class="num">Current</th><th class="num">Target</th><th>Progress</th><th></th></tr></thead><tbody>
    @forelse($rows as $t)@php $x = $t['target']; @endphp<tr class="{{ $t['state'] === 'behind' ? 'row-amber' : '' }}"><td class="small"><b>{{ $t['label'] }}</b><div class="tiny muted">{{ $t['unit'] }}{{ $x->notes ? ' · '.$x->notes : '' }}</div></td>
      <td class="num small">{{ number_format($x->baseline_value, $t['dp']) }}<div class="tiny muted">{{ $x->baseline_year }}</div></td><td class="num small">{{ number_format($t['expected'], $t['dp']) }}</td>
      <td class="num"><b>{{ $t['current'] !== null ? number_format($t['current'], $t['dp']) : '—' }}</b></td><td class="num small">{{ number_format($x->target_value, $t['dp']) }}<div class="tiny muted">{{ $x->target_year }}</div></td>
      <td>@include('esg._target_state', ['t' => $t])</td><td>@if($canMg)<button class="btn btn-sm" data-modal="esgT{{ $x->id }}">Edit</button>@endif</td></tr>
    @empty<tr><td colspan="7" class="muted">No targets yet.</td></tr>@endforelse
    </tbody></table></div></div>
  <div class="card"><div class="card-head"><h3>Current values</h3><span class="tiny muted">{{ \Carbon\Carbon::parse($window[0])->format('d M Y') }} – {{ \Carbon\Carbon::parse($window[1])->format('d M Y') }}</span></div><div class="card-body"><dl class="kv small" id="esgCurrent">
    @foreach($M as $k => [$l, $u, $b, $dp])<dt>{{ $l }}</dt><dd>{{ $current[$k] !== null ? number_format($current[$k], $dp).' '.$u : '—' }}</dd>@endforeach
  </dl><p class="tiny muted m-0">Environmental metrics use the last 12 months of metered data; waste, sourcing and people metrics the last 12 months.</p></div></div>
</div>
@if($retired->count())<div class="card"><div class="card-head"><h3>Retired targets</h3></div><div class="card-body"><ul class="plain small">@foreach($retired as $x)<li>{{ $M[$x->metric][0] ?? $x->metric }}: {{ $x->baseline_value }} ({{ $x->baseline_year }}) → {{ $x->target_value }} ({{ $x->target_year }})@if($canMg) <button class="btn btn-sm" data-modal="esgT{{ $x->id }}">Edit</button>@endif</li>@endforeach</ul></div></div>@endif
@if($canMg)
<div class="modal" id="esgTargetModal"><div class="modal-box"><form method="post" action="{{ route('esg.targets.store') }}" data-reset>@csrf
  <div class="modal-head"><h3>New target</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body">@include('esg._target_fields', ['x' => null])</div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save target</button></div></form></div></div>
@foreach(collect($rows)->pluck('target')->merge($retired) as $x)
<div class="modal" id="esgT{{ $x->id }}"><div class="modal-box"><form method="post" action="{{ route('esg.targets.update', $x) }}">@csrf
  <div class="modal-head"><h3>Edit target</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body">@include('esg._target_fields', ['x' => $x])</div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save</button></div></form></div></div>
@endforeach
@endif
@endsection
