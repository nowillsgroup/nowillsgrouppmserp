@extends('layouts.app')
@section('title', 'Sales Dashboard')
@section('crumbs') Sales / Dashboard @endsection
@php $O = \App\Models\SalesOpportunity::class; $p = $pipe['total']; $max = max(1, collect($O::OPEN)->max(fn ($s) => $pipe[$s]['value'])); @endphp
@section('actions')
  <a class="btn" href="{{ route('sales.activities', ['owner' => 'me']) }}"><x-icon name="calendar"/> My diary</a>
  @if(can_do('sales.edit') || can_do('sales.manage'))<button class="btn btn-primary" data-modal="oppModalNew" id="newOppBtn"><x-icon name="plus"/> New opportunity</button>@endif
@endsection
@section('content')
@include('sales._owner_toggle')
@if($alerts['overdue'])<div class="alert alert-warn" id="overdueAlert"><x-icon name="alert"/> {{ $alerts['overdue'] }} sales activit{{ $alerts['overdue'] === 1 ? 'y is' : 'ies are' }} overdue – <a href="{{ route('sales.activities', ['status' => 'overdue'] + ($owner ? ['owner' => request('owner')] : [])) }}">open the diary</a>.</div>@endif

<div class="stats" id="salesStats">
  <x-stat label="Open pipeline" :value="money_short($p['value'])" icon="trend" tone="blue" :sub="$p['count'].' deals · '.number_format($p['nights']).' room nights'" :href="route('sales.opportunities')"/>
  <x-stat label="Weighted forecast" :value="money_short($p['weighted'])" icon="chart" tone="purple" sub="value × win probability"/>
  <x-stat label="Won this month" :value="money_short($wonMonth->sum(fn ($o) => $o->total()))" icon="star" tone="green" :sub="$wonMonth->count().' deals · '.money($wonYear->sum(fn ($o) => $o->total())).' this year'"/>
  <x-stat label="Win rate (90 days)" :value="$winRate === null ? '—' : $winRate.'%'" icon="check" :tone="($winRate ?? 0) >= 40 ? 'green' : 'amber'" :sub="$decided.' deals decided'"/>
  <x-stat label="Needs attention" :value="$alerts['overdue'] + $alerts['closing_overdue'] + $alerts['stale']" icon="alert" :tone="$alerts['overdue'] + $alerts['closing_overdue'] + $alerts['stale'] ? 'red' : 'green'" :sub="$alerts['overdue'].' overdue activities · '.$alerts['closing_overdue'].' past close date · '.$alerts['stale'].' stale deals'"/>
</div>

<div class="g-2-1 mt-1">
<div>
<div class="card flush" id="funnelCard"><div class="card-head"><h3><x-icon name="layers"/> Pipeline by stage</h3><a class="btn btn-sm btn-ghost" href="{{ route('sales.opportunities', $owner ? ['owner' => request('owner')] : []) }}">Board →</a></div>
<div class="card-body pad">@foreach($O::OPEN as $s)@php $x = $pipe[$s]; @endphp
  <div class="sa-funnel" data-stage="{{ $s }}"><div class="sa-funnel-label"><span class="badge badge-{{ $O::STAGES[$s][1] }}">{{ $O::STAGES[$s][0] }}</span> <span class="tiny muted">{{ $cfg['prob_'.$s] }}%</span></div>
    <div class="sa-funnel-bar"><span class="sa-bar-{{ $O::STAGES[$s][1] }}" style="width:{{ max(2, round($x['value'] * 100 / $max)) }}%"></span></div>
    <div class="sa-funnel-num"><b>{{ money($x['value']) }}</b><div class="tiny muted">{{ $x['count'] }} deal{{ $x['count'] === 1 ? '' : 's' }} · weighted {{ money($x['weighted']) }}</div></div></div>
@endforeach</div></div>

<div class="card flush mt-1" id="agendaCard"><div class="card-head"><h3><x-icon name="calendar"/> Agenda – overdue and next 7 days</h3><a class="btn btn-sm btn-ghost" href="{{ route('sales.activities', $owner ? ['owner' => request('owner')] : []) }}">Diary →</a></div>
<div class="card-body table-wrap"><table class="table table-compact" id="agendaTable">
  <thead><tr><th>When</th><th>Activity</th><th>Account</th><th>Owner</th></tr></thead>
  <tbody>@forelse($agenda as $a)
    <tr data-activity="{{ $a->id }}" class="{{ $a->isOverdue() ? 'row-alert' : '' }}"><td class="small nowrap">{{ $a->due_at->format('D d M H:i') }}@if($a->isOverdue())<div class="tiny text-red bold">overdue</div>@endif</td>
      <td><x-icon :name="$a->typeIcon()"/> <b>{{ $a->subject }}</b><div class="tiny muted">{{ $a->typeLabel() }}{{ $a->opportunity ? ' · '.$a->opportunity->number : '' }}</div></td>
      <td class="small">@if($a->company)<a href="{{ route('sales.account', $a->company) }}">{{ $a->company->name }}</a>@else — @endif</td><td class="small">{{ $a->owner?->name }}</td></tr>
  @empty<tr><td colspan="4" class="empty">Nothing planned for the next week – time to book some sales calls.</td></tr>@endforelse</tbody>
</table></div></div>

<div class="card flush mt-1" id="closingCard"><div class="card-head"><h3><x-icon name="clock"/> Closing in the next 30 days</h3></div>
<div class="card-body table-wrap"><table class="table table-compact" id="closingTable">
  <thead><tr><th>Opportunity</th><th>Stage</th><th>Close</th><th class="num">Value</th><th class="num">Weighted</th></tr></thead>
  <tbody>@forelse($closing as $o)
    <tr data-opp="{{ $o->number }}" class="{{ $o->expected_close->lt(bdate()->copy()->startOfDay()) ? 'row-warn' : '' }}"><td><a href="{{ route('sales.opportunity', $o) }}"><b>{{ $o->number }}</b> {{ $o->name }}</a><div class="tiny muted">{{ $o->company->name }} · {{ $o->owner?->name }}</div></td>
      <td><span class="badge badge-{{ $o->stageColor() }}">{{ $o->stageLabel() }}</span></td><td class="small nowrap {{ $o->expected_close->lt(bdate()->copy()->startOfDay()) ? 'text-red bold' : '' }}">{{ $o->expected_close->format('d M') }}</td>
      <td class="num">{{ money($o->total()) }}</td><td class="num">{{ money($o->weighted()) }}</td></tr>
  @empty<tr><td colspan="5" class="empty">No deals due to close in the next 30 days.</td></tr>@endforelse</tbody>
</table></div></div>
</div>

<div>
<div class="card flush" id="topCard"><div class="card-head"><h3><x-icon name="building"/> Top accounts – {{ bdate()->year }} to date</h3><a class="btn btn-sm btn-ghost" href="{{ route('reports.show', ['report' => 'account_production']) }}">Report →</a></div>
<div class="card-body">@forelse($top as $t)
  <div class="between-row" data-top="{{ $t['company']->id }}"><div><a href="{{ route('sales.account', $t['company']) }}"><b>{{ $t['company']->name }}</b></a><div class="tiny muted">{{ number_format($t['nights']) }} room nights{{ $t['otb_nights'] ? ' · '.number_format($t['otb_nights']).' on the books' : '' }}</div></div><b class="small">{{ money($t['revenue']) }}</b></div>
@empty<div class="muted small">No account production yet this year.</div>@endforelse</div></div>

<div class="card flush mt-1" id="staleCard"><div class="card-head"><h3><x-icon name="alert"/> Stale deals</h3><span class="tiny muted">no contact in {{ $cfg['stale_days'] }} days</span></div>
<div class="card-body">@forelse($stale as $o)
  <div class="between-row" data-stale="{{ $o->number }}"><div><a href="{{ route('sales.opportunity', $o) }}"><b>{{ $o->number }}</b> {{ \Illuminate\Support\Str::limit($o->name, 34) }}</a><div class="tiny muted">{{ $o->company->name }} · {{ $o->owner?->name }}</div></div><span class="badge badge-{{ $o->stageColor() }}">{{ $o->stageLabel() }}</span></div>
@empty<div class="muted small">Every open deal has been worked recently.</div>@endforelse</div></div>

<div class="card flush mt-1" id="dormantCard"><div class="card-head"><h3><x-icon name="moon"/> Key accounts going quiet</h3><span class="tiny muted">no contact in {{ $cfg['dormant_days'] }} days</span></div>
<div class="card-body">@forelse($dormant as $c)
  <div class="between-row" data-dormant="{{ $c->id }}"><div><a href="{{ route('sales.account', $c) }}"><b>{{ $c->name }}</b></a><div class="tiny muted">{{ $c->tierLabel() }} · {{ $c->accountManager?->name ?? 'unassigned' }}</div></div>
    <span class="tiny muted">{{ isset(\App\Services\SalesService::lastTouch()[$c->id]) ? 'last '.\App\Services\SalesService::lastTouch()[$c->id]->format('d M Y') : 'never visited' }}</span></div>
@empty<div class="muted small">All key and major accounts have been contacted recently.</div>@endforelse</div></div>

<div class="card flush mt-1" id="birthdayCard"><div class="card-head"><h3><x-icon name="star"/> Contact birthdays</h3><span class="tiny muted">next {{ $cfg['birthday_days'] }} days</span></div>
<div class="card-body">@forelse($birthdays as $b)
  <div class="between-row" data-birthday="{{ $b['contact']->id }}"><div><b>{{ $b['contact']->name }}</b><div class="tiny muted">{{ $b['contact']->title }} · <a href="{{ route('sales.account', $b['contact']->company) }}">{{ $b['contact']->company->name }}</a></div></div><span class="badge badge-{{ $b['on']->isSameDay(bdate()) ? 'green' : 'blue' }}">{{ $b['on']->isSameDay(bdate()) ? 'today' : $b['on']->format('D d M') }}</span></div>
@empty<div class="muted small">No birthdays coming up.</div>@endforelse</div></div>
</div>
</div>
@if(can_do('sales.edit') || can_do('sales.manage'))@include('sales._opp_modal', ['o' => null])@endif
@endsection
