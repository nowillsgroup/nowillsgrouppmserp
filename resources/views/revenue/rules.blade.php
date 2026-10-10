@extends('layouts.app')
@section('title', 'Pricing Rules')
@section('crumbs') <a href="{{ route('revenue.index') }}">Revenue</a> / Pricing Rules @endsection
@section('actions')
@if(can_do('revenue.manage'))<a class="btn btn-primary" href="{{ route('revenue.rules.create') }}"><x-icon name="plus"/> New pricing rule</a>@endif
@endsection
@section('content')
@php $canManage = can_do('revenue.manage'); @endphp
<div class="grid g-2">
<div class="card"><div class="card-head"><h3>Pricing mode</h3><span class="rm-mode {{ $mode }}">{{ \App\Services\RevenueService::MODES[$mode] }}</span></div><div class="card-body">
<form method="post" action="{{ route('revenue.settings') }}">@csrf
  <div class="rm-steps mb-2">
  @foreach(['off' => 'Forecasts and recommendations only. Nothing changes your rates.', 'recommend' => 'You review the pricing grid and publish the rates you accept as day rates.', 'auto' => 'Every future night is priced live from the rules as bookings come in – booking engine, channels and front desk.'] as $k => $txt)
    <label style="cursor:pointer"><input type="radio" name="rm_mode" value="{{ $k }}" @checked($mode === $k) @disabled(! $canManage)> <b style="display:inline">{{ \App\Services\RevenueService::MODES[$k] }}</b><div class="small muted mt-1">{{ $txt }}</div></label>
  @endforeach
  </div>
  <div class="form-grid">
    <div class="field"><label>Round rates to the nearest</label><input type="number" name="rm_round_to" min="0" max="10000" step="1" value="{{ old('rm_round_to', $roundTo) }}" @disabled(! $canManage)><span class="hint">0 = no rounding</span></div>
    <div class="field"><label>Forecast horizon (days)</label><input type="number" name="rm_horizon_days" min="14" max="365" value="{{ old('rm_horizon_days', $horizon) }}" @disabled(! $canManage)><span class="hint">Nights tracked by the daily snapshot</span></div>
  </div>
  @if($canManage)<button class="btn btn-primary mt-2">Save mode</button>@endif
</form>
</div></div>

<div class="card"><div class="card-head"><h3>Rate floors &amp; ceilings</h3><span class="muted small">Recommendations never go outside these</span></div><div class="card-body">
<form method="post" action="{{ route('revenue.limits') }}">@csrf
<table class="table table-compact"><thead><tr><th>Room type</th><th class="num">Base</th><th class="num">Weekend</th><th class="num">Floor (min)</th><th class="num">Ceiling (max)</th></tr></thead><tbody>
@foreach($types as $t)
<tr><td class="bold">{{ $t->name }}</td><td class="num">{{ money($t->base_rate) }}</td><td class="num">{{ $t->weekend_rate ? money($t->weekend_rate) : '—' }}</td>
<td class="num"><input type="number" step="1" min="0" name="min_rate[{{ $t->id }}]" value="{{ old('min_rate.'.$t->id, $t->min_rate !== null ? round($t->min_rate) : '') }}" style="max-width:110px;margin-left:auto" @disabled(! $canManage)></td>
<td class="num"><input type="number" step="1" min="0" name="max_rate[{{ $t->id }}]" value="{{ old('max_rate.'.$t->id, $t->max_rate !== null ? round($t->max_rate) : '') }}" style="max-width:110px;margin-left:auto" @disabled(! $canManage)>
@error('max_rate.'.$t->id)<div class="err">{{ $message }}</div>@enderror</td></tr>
@endforeach
</tbody></table>
@if($canManage)<button class="btn btn-primary mt-1">Save floors &amp; ceilings</button>@endif
</form>
</div></div>
</div>

<div class="card flush mt-2"><div class="card-head"><h3>Pricing rules</h3><span class="muted small">Matching rules apply one after another, lowest priority number first</span></div>
<div class="card-body table-wrap"><table class="table">
<thead><tr><th class="center">Priority</th><th>Rule</th><th>Room type</th><th>When</th><th class="num">Adjustment</th><th class="center">Min stay</th><th class="center">Status</th><th></th></tr></thead>
<tbody>
@forelse($rules as $r)
<tr class="{{ $r->is_active ? '' : 'rule-off' }}"><td class="center">{{ $r->priority }}</td><td class="bold">{{ $r->name }}</td><td>{{ $r->roomType?->name ?? 'All room types' }}</td>
<td class="small">{{ $r->conditionLabel() }}</td>
<td class="num bold {{ (float) $r->adjust_value >= 0 ? 'text-amber' : 'text-green' }}">{{ $r->adjustmentLabel() }}</td>
<td class="center">{{ $r->min_stay ?: '—' }}</td>
<td class="center">{!! $r->is_active ? '<span class="badge badge-green">Active</span>' : '<span class="badge badge-slate">Paused</span>' !!}</td>
<td class="right nowrap">@if($canManage)
  <form method="post" action="{{ route('revenue.rules.toggle', $r) }}" class="inline">@csrf<button class="btn btn-sm btn-ghost">{{ $r->is_active ? 'Pause' : 'Enable' }}</button></form>
  <a class="btn btn-sm btn-ghost" href="{{ route('revenue.rules.edit', $r) }}" title="Edit"><x-icon name="edit" size="sm"/></a>
  <form method="post" action="{{ route('revenue.rules.destroy', $r) }}" class="inline" onsubmit="return confirm('Delete this pricing rule?')">@csrf @method('DELETE')<button class="btn btn-sm btn-ghost" title="Delete"><x-icon name="trash" size="sm"/></button></form>
@endif</td></tr>
@empty
<tr><td colspan="8"><x-empty title="No pricing rules" text="Add rules such as “+15% when the hotel is over 80% full” or “−10% for last-minute low demand”."/></td></tr>
@endforelse
</tbody></table></div></div>
@endsection
