@extends('layouts.app')
@section('title', 'Sales Accounts')
@section('crumbs') Sales / Accounts @endsection
@php $C = \App\Models\Company::class; @endphp
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'account_production']) }}"><x-icon name="download"/> Production report</a>
  @if(can_do('sales.edit') || can_do('sales.manage'))<button class="btn btn-primary" data-modal="accountModalNew" id="newAccountBtn"><x-icon name="plus"/> New account</button>@endif
@endsection
@section('content')
<div class="card"><div class="card-body"><form method="get" class="flex gap-1 wrap" style="align-items:center" id="accFilter">
  <input name="q" value="{{ request('q') }}" placeholder="Name, contact or city" aria-label="Search">
  <select name="type" aria-label="Type"><option value="">Corporate &amp; agents</option>@foreach(['corporate' => 'Corporate', 'travel_agent' => 'Travel agents', 'ota' => 'OTAs'] as $k => $l)<option value="{{ $k }}" @selected(request('type') === $k)>{{ $l }}</option>@endforeach</select>
  <select name="tier" aria-label="Tier"><option value="">Any tier</option>@foreach($C::TIERS as $k => [$l])<option value="{{ $k }}" @selected(request('tier') === $k)>{{ $l }}</option>@endforeach</select>
  <select name="status" aria-label="Status"><option value="">Any status</option>@foreach($C::SALES_STATUSES as $k => [$l])<option value="{{ $k }}" @selected(request('status') === $k)>{{ $l }}</option>@endforeach</select>
  <select name="industry" aria-label="Industry"><option value="">Any industry</option>@foreach($C::INDUSTRIES as $k => $l)<option value="{{ $k }}" @selected(request('industry') === $k)>{{ $l }}</option>@endforeach</select>
  <select name="manager" aria-label="Account manager"><option value="">Any manager</option><option value="me" @selected(request('manager') === 'me')>My accounts</option><option value="none" @selected(request('manager') === 'none')>Unassigned</option>@foreach($sellers as $u)<option value="{{ $u->id }}" @selected(request('manager') == $u->id)>{{ $u->name }}</option>@endforeach</select>
  <button class="btn">Filter</button>@if(request()->query())<a class="btn btn-ghost" href="{{ route('sales.accounts') }}">Clear</a>@endif
</form></div></div>
<div class="card flush mt-1"><div class="card-body table-wrap"><table class="table table-compact" id="accountsTable">
  <thead><tr><th>Account</th><th>Tier</th><th>Status</th><th>Manager</th><th class="num">RN YTD</th><th class="num">Revenue YTD</th><th class="num">On the books</th><th class="num">Weighted pipeline</th><th>Last contact</th><th>Next step</th></tr></thead>
  <tbody>@forelse($list as $c)@php $p = $prod[$c->id] ?? null; $last = $touch[$c->id] ?? null; $nx = $next[$c->id] ?? null; @endphp
    <tr data-account="{{ $c->id }}"><td><a href="{{ route('sales.account', $c) }}"><b>{{ $c->name }}</b></a><div class="tiny muted">{{ $c->typeLabel() }}{{ $c->industry ? ' · '.$C::INDUSTRIES[$c->industry] : '' }}{{ $c->city ? ' · '.$c->city : '' }}</div></td>
      <td><span class="badge badge-{{ $C::TIERS[$c->tier][1] ?? 'slate' }}">{{ $c->tierLabel() }}</span></td><td><span class="badge badge-{{ $c->salesStatusColor() }}">{{ $c->salesStatusLabel() }}</span></td>
      <td class="small">{{ $c->accountManager?->name ?? '—' }}</td><td class="num">{{ $p ? number_format($p['nights']) : '·' }}</td><td class="num">{{ $p && $p['revenue'] ? money($p['revenue']) : '·' }}</td>
      <td class="num small">{{ $p && $p['otb_nights'] ? number_format($p['otb_nights']).' RN' : '·' }}</td><td class="num">{{ isset($pipe[$c->id]) ? money($pipe[$c->id]) : '·' }}@if($c->open_count)<div class="tiny muted">{{ $c->open_count }} open</div>@endif</td>
      <td class="small {{ in_array($c->tier, ['key', 'major']) && $c->sales_status === 'active' && (! $last || $last->lt($dormantCut)) ? 'text-red bold' : '' }}">{{ $last ? $last->format('d M Y') : 'never' }}</td>
      <td class="small">@if($nx)<span class="{{ $nx->isOverdue() ? 'text-red bold' : '' }}">{{ $nx->due_at->format('d M') }}</span> · {{ \Illuminate\Support\Str::limit($nx->subject, 28) }}@else<span class="muted">—</span>@endif</td></tr>
  @empty<tr><td colspan="10" class="empty">No accounts match.</td></tr>@endforelse</tbody>
</table></div></div>
@if(can_do('sales.edit') || can_do('sales.manage'))@include('sales._account_modal', ['c' => null])@endif
@endsection
