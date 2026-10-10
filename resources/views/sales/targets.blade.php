@extends('layouts.app')
@section('title', 'Sales Targets')
@section('crumbs') Sales / Targets @endsection
@php $bar = fn ($p) => $p === null ? '' : '<div class="cp-bar"><span style="width:'.min(100, $p).'%;background:'.($p >= 100 ? '#16a34a' : ($p >= 75 ? '#f59e0b' : '#dc2626')).'"></span></div>'; @endphp
@section('actions')
  <a class="btn" href="?period={{ $prev }}" id="prevMonth">← {{ \Carbon\Carbon::parse($prev.'-01')->format('M Y') }}</a>
  <a class="btn" href="?period={{ $next }}" id="nextMonth">{{ \Carbon\Carbon::parse($next.'-01')->format('M Y') }} →</a>
  <a class="btn" href="{{ route('reports.show', ['report' => 'sales_targets']) }}"><x-icon name="download"/> Report</a>
@endsection
@section('content')
<h2 class="mb-1" id="periodLabel">{{ $label }}</h2>
@if($current || $future)<div class="alert alert-info"><x-icon name="bell"/> Room nights and revenue include business on the books (reserved) from today on.</div>@endif
<div class="card flush"><div class="card-body table-wrap"><table class="table table-compact" id="targetsTable">
  <thead><tr><th>Salesperson</th><th class="num">Accounts</th><th class="num">RN target</th><th class="num">RN actual + OTB</th><th style="width:12%"></th><th class="num">Revenue target</th><th class="num">Revenue actual + OTB</th><th style="width:12%"></th><th class="num">Activities</th><th class="num">Won</th></tr></thead>
  <tbody>@forelse($rows as $r)@php $t = $r['target']; @endphp
    <tr data-user="{{ $r['user']->id }}"><td><b>{{ $r['user']->name }}</b></td><td class="num">{{ $r['accounts'] }}</td>
      <td class="num">{{ $t ? number_format($t->room_nights) : '—' }}</td><td class="num">{{ number_format($r['nights']) }}{!! $r['otb_nights'] ? ' <span class="tiny muted">+'.number_format($r['otb_nights']).'</span>' : '' !!}</td><td>{!! $bar($r['pct_nights']) !!}<span class="tiny">{{ $r['pct_nights'] !== null ? $r['pct_nights'].'%' : '' }}</span></td>
      <td class="num">{{ $t ? money($t->revenue) : '—' }}</td><td class="num">{{ money($r['revenue']) }}{!! $r['otb_revenue'] ? ' <span class="tiny muted">+'.e(money($r['otb_revenue'])).'</span>' : '' !!}</td><td>{!! $bar($r['pct_revenue']) !!}<span class="tiny" data-pct-revenue>{{ $r['pct_revenue'] !== null ? $r['pct_revenue'].'%' : '' }}</span></td>
      <td class="num">{{ $r['activities'] }}{{ $t && $t->activities ? ' / '.$t->activities : '' }}</td><td class="num">{{ $r['won'] ? money($r['won']) : '·' }}</td></tr>
  @empty<tr><td colspan="10" class="empty">No targets or sales activity this month.</td></tr>@endforelse</tbody>
</table></div></div>
@if(can_do('sales.manage'))
<div class="card mt-1" style="max-width:52rem" id="setTargets"><div class="card-head"><h3><x-icon name="edit"/> Set targets for {{ $label }}</h3></div><div class="card-body">
<form method="post" action="{{ route('sales.targets.save') }}">@csrf<input type="hidden" name="period" value="{{ $period }}">
  <table class="table table-compact"><thead><tr><th>Salesperson</th><th>Room nights</th><th>Room revenue (GH₵)</th><th>Activities</th></tr></thead><tbody>
  @foreach($sellers as $u)@php $t = $rows->firstWhere('user.id', $u->id)['target'] ?? null; @endphp
    <tr><td>{{ $u->name }}</td><td><input type="number" name="t[{{ $u->id }}][room_nights]" min="0" value="{{ $t?->room_nights }}" style="width:8rem"></td><td><input type="number" name="t[{{ $u->id }}][revenue]" min="0" step="0.01" value="{{ $t?->revenue }}" style="width:10rem"></td><td><input type="number" name="t[{{ $u->id }}][activities]" min="0" value="{{ $t?->activities }}" style="width:6rem"></td></tr>
  @endforeach</tbody></table>
  <p class="tiny muted">Leave a row empty to remove that salesperson's target.</p><button class="btn btn-primary" id="saveTargets">Save targets</button>
</form></div></div>
@endif
@endsection
