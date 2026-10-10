@extends('layouts.app')
@section('title', 'Rate Contracts')
@section('crumbs') Guests & CRM / Rate Contracts @endsection
@section('actions')
@if(can_do('reports.view'))<a class="btn" href="{{ route('reports.show', 'contract_production') }}"><x-icon name="chart"/> Production report</a>@endif
@if(can_do('contracts.manage'))<a class="btn btn-primary" href="{{ route('contracts.create') }}" id="newContract"><x-icon name="plus"/> New contract</a>@endif
@endsection
@section('content')
<div class="stats req5">
  <x-stat label="Live contracts" :value="$stats['live']" icon="book" tone="blue" sub="in force today"/>
  <x-stat label="Contract room nights" :value="number_format($stats['nights'])" icon="moon" tone="purple" sub="booked on live contracts"/>
  <x-stat label="Contract revenue" :value="money($stats['revenue'])" icon="money" tone="green" sub="room revenue, before tax"/>
  <x-stat label="Rooms on allotment" :value="$stats['allotted']" icon="bed" tone="slate" sub="tonight, before pick-up"/>
  <x-stat label="Needs attention" :value="$alerts['expiring'] + $alerts['drafts']" icon="alert" :tone="($alerts['expiring'] + $alerts['drafts']) ? 'amber' : 'slate'" :sub="$alerts['expiring'].' expiring · '.$alerts['drafts'].' awaiting approval'" href="?tab=draft"/>
</div>
<div class="tabs" id="contractTabs">@foreach(\App\Http\Controllers\ContractController::TABS as $k => $v)<a href="?tab={{ $k }}" class="{{ $tab === $k ? 'active' : '' }}">{{ $v }} <span class="muted">{{ $counts[$k] }}</span></a>@endforeach</div>
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="contractTable">
<thead><tr><th>Contract</th><th>Company</th><th>Validity</th><th>Pricing</th><th>Allotment</th><th>Production vs target</th><th class="num">Revenue</th><th>Status</th></tr></thead><tbody>
@forelse($contracts as $c) @php $p = $c->prod; $st = $c->state(); $days = $c->daysToExpiry(); @endphp
<tr>
  <td><a class="bold" href="{{ route('contracts.show', $c) }}">{{ $c->code }}</a><div class="tiny muted">{{ $c->name }}</div></td>
  <td><a href="{{ route('credit.show', $c->company) }}" class="small">{{ $c->company->name }}</a><div class="tiny muted">{{ ucwords(str_replace('_', ' ', $c->company->type)) }}</div></td>
  <td class="small nowrap">{{ $c->valid_from->format('d M Y') }} – {{ $c->valid_to->format('d M Y') }}
    @if($st === 'active' && $days <= (int) setting('contract_expiry_alert_days', '60'))<div><span class="badge badge-amber">ends in {{ $days }} day(s)</span></div>@endif</td>
  <td class="small">{{ $c->basisLabel() }}<div class="tiny muted">{{ $c->meal_plan }}{{ $c->lra ? ' · LRA' : '' }}{{ $c->access_code ? ' · code '.$c->access_code : '' }}</div></td>
  <td class="small">@if($c->allotments->isNotEmpty()){{ $c->allotments->sum('rooms') }} room(s) · {{ $c->allotments->count() }} line(s)@else<span class="muted">—</span>@endif</td>
  <td style="min-width:170px">@if($p['target'])<div class="flex between tiny"><span>{{ number_format($p['nights']) }} / {{ number_format($p['target']) }} RN</span><b>{{ $p['target_pct'] }}%</b></div>
      <div class="progress" title="Time elapsed {{ $p['pace_pct'] }}%"><span style="width:{{ min(100, $p['target_pct']) }}%;{{ $p['target_pct'] < $p['pace_pct'] * .8 ? 'background:#f59e0b' : '' }}"></span></div>
      <div class="tiny muted">{{ $p['pace_pct'] }}% of the period elapsed</div>
    @else<span class="small">{{ number_format($p['nights']) }} RN</span> <span class="tiny muted">no target</span>@endif</td>
  <td class="num">{{ money($p['revenue']) }}<div class="tiny muted">ADR {{ money($p['adr']) }}</div></td>
  <td>{!! status_badge($st) !!}</td>
</tr>
@empty
<tr><td colspan="8"><x-empty text="No contracts in this view." icon="book"/></td></tr>
@endforelse
</tbody></table></div></div>
@endsection
