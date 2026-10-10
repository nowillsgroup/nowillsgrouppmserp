@extends('layouts.app')
@section('title', $c->name)
@section('crumbs') <a href="{{ route('sales.accounts') }}">Sales / Accounts</a> / {{ $c->name }} @endsection
@php
  $C = \App\Models\Company::class; $mayEdit = $canEdit; $seller = can_do('sales.edit') || can_do('sales.manage');
  $open = $opps->filter->isOpen(); $maxM = max(1, collect($monthly)->max(fn ($m) => $m['revenue'] + $m['otb_revenue']));
  $chg = fn ($a, $b) => $b > 0 ? round(($a - $b) * 100 / $b) : null;
@endphp
@section('actions')
  @if($seller)<button class="btn" data-modal="actModal" data-title="Plan an activity with {{ $c->name }}" id="planActBtn"><x-icon name="calendar"/> Plan activity</button>
  <button class="btn btn-primary" data-modal="oppModalNew" id="newOppBtn"><x-icon name="plus"/> New opportunity</button>@endif
  @if($mayEdit)<button class="btn" data-modal="accountModal" id="editAccountBtn"><x-icon name="edit"/> Edit</button>@endif
@endsection
@section('content')
<div class="flex gap-1 wrap mb-1" id="accBadges"><span class="badge badge-{{ $C::TIERS[$c->tier][1] ?? 'slate' }}">{{ $c->tierLabel() }}</span><span class="badge badge-{{ $c->salesStatusColor() }}" data-status="{{ $c->sales_status }}">{{ $c->salesStatusLabel() }}</span><span class="badge badge-slate">{{ $c->typeLabel() }}</span>@if($c->industry)<span class="badge badge-slate">{{ $C::INDUSTRIES[$c->industry] }}</span>@endif @if(! $c->direct_billing)<span class="badge badge-amber">No direct billing</span>@endif @if($c->onHold())<span class="badge badge-red">Credit hold</span>@endif</div>
<div class="stats" id="accStats">
  <x-stat label="Room nights {{ bdate()->year }} YTD" :value="number_format($ytd['nights'])" icon="bed" tone="blue" :sub="'LY '.number_format($ytd['ly_nights']).($chg($ytd['nights'], $ytd['ly_nights']) !== null ? ' · '.sprintf('%+d', $chg($ytd['nights'], $ytd['ly_nights'])).'%' : '')"/>
  <x-stat label="Room revenue YTD" :value="money_short($ytd['revenue'])" icon="money" tone="green" :sub="'LY '.money($ytd['ly_revenue'])"/>
  <x-stat label="Open pipeline" :value="money_short($open->sum(fn ($o) => $o->total()))" icon="trend" tone="purple" :sub="$open->count().' open · weighted '.money($open->sum(fn ($o) => $o->weighted()))"/>
  <x-stat label="Credit exposure" :value="money_short($credit['exposure'] ?? 0)" icon="wallet" :tone="($credit['over_limit'] ?? false) ? 'red' : 'slate'" :sub="($credit['limit'] ?? 0) > 0 ? 'limit '.money($credit['limit']).' · overdue '.money($credit['overdue']) : 'no credit limit'"/>
</div>

<div class="g-2-1 mt-1">
<div>
<div class="card flush" id="prodCard"><div class="card-head"><h3><x-icon name="chart"/> Monthly production</h3><span class="tiny muted">room revenue · striped = on the books</span></div>
<div class="card-body pad"><div class="sa-chart">@foreach($monthly as $m)
  <div class="sa-col" data-month="{{ $m['month'] }}" title="{{ $m['label'] }}: {{ $m['nights'] }} RN, {{ money($m['revenue']) }}{{ $m['otb_nights'] ? ' + '.$m['otb_nights'].' RN on the books' : '' }}">
    <div class="sa-col-bars"><span class="sa-otb" style="height:{{ round($m['otb_revenue'] * 100 / $maxM) }}%"></span><span class="sa-act-bar" style="height:{{ round($m['revenue'] * 100 / $maxM) }}%"></span></div>
    <div class="tiny muted">{{ $m['label'] }}</div><div class="tiny">{{ $m['nights'] + $m['otb_nights'] ?: '' }}</div></div>
@endforeach</div></div></div>

<div class="card flush mt-1" id="oppsCard"><div class="card-head"><h3><x-icon name="trend"/> Opportunities</h3></div>
<div class="card-body table-wrap"><table class="table table-compact" id="accOppsTable">
  <thead><tr><th>Opportunity</th><th>Stage</th><th>Owner</th><th>Close</th><th class="num">RN</th><th class="num">Value</th></tr></thead>
  <tbody>@forelse($opps as $o)
    <tr data-opp="{{ $o->number }}"><td><a href="{{ route('sales.opportunity', $o) }}"><b>{{ $o->number }}</b> {{ $o->name }}</a><div class="tiny muted">{{ $o->typeLabel() }}</div></td><td><span class="badge badge-{{ $o->stageColor() }}">{{ $o->stageLabel() }}</span></td>
      <td class="small">{{ $o->owner?->name }}</td><td class="small">{{ ($o->closed_on ?? $o->expected_close)->format('d M Y') }}</td><td class="num">{{ $o->room_nights ?: '·' }}</td><td class="num">{{ money($o->total()) }}</td></tr>
  @empty<tr><td colspan="6" class="empty">No opportunities yet.</td></tr>@endforelse</tbody>
</table></div></div>

<div class="card flush mt-1" id="actsCard"><div class="card-head"><h3><x-icon name="calendar"/> Activity</h3><a class="btn btn-sm btn-ghost" href="{{ route('sales.activities', ['company' => $c->id, 'status' => 'all']) }}">All →</a></div>
<div class="card-body">@include('sales._activity_rows', ['acts' => $acts, 'showAccount' => false])</div></div>
</div>

<div>
<div class="card" id="profileCard"><div class="card-head"><h3><x-icon name="building"/> Profile</h3></div><div class="card-body">
  <dl class="kv"><dt>Account manager</dt><dd><b>{{ $c->accountManager?->name ?? 'Unassigned' }}</b></dd><dt>Contact</dt><dd>{{ $c->contact_person ?? '—' }}</dd><dt>Phone</dt><dd>{{ $c->phone ?? '—' }}</dd><dt>E-mail</dt><dd>{{ $c->email ?? '—' }}</dd>
    <dt>Address</dt><dd>{{ trim(($c->address ?? '').' '.($c->city ?? '')) ?: '—' }}</dd>@if($c->website)<dt>Website</dt><dd>{{ $c->website }}</dd>@endif<dt>Potential</dt><dd>{{ $c->potential_nights ? number_format($c->potential_nights).' RN / year' : '—' }}</dd>
    <dt>Discount / commission</dt><dd>{{ (float) $c->discount_pct }}% / {{ (float) $c->commission_pct }}%</dd><dt>Credit</dt><dd>{{ $c->direct_billing ? 'Direct billing · '.((float) $c->credit_limit ? money($c->credit_limit) : 'no limit') : 'Pay own account' }}@if(can_do('credit.view')) · <a href="{{ route('credit.show', $c) }}">credit control</a>@endif</dd></dl>
  @if($c->sales_notes)<p class="small mt-1 sa-notes">{{ $c->sales_notes }}</p>@endif
</div></div>

<div class="card flush mt-1" id="contactsCard"><div class="card-head"><h3><x-icon name="users"/> Contacts</h3>@if($mayEdit)<button class="btn btn-sm" data-modal="contactModalNew" id="addContactBtn"><x-icon name="plus"/> Add</button>@endif</div>
<div class="card-body">@forelse($c->contacts->sortByDesc('is_primary') as $x)
  <div class="between-row {{ $x->is_active ? '' : 'muted' }}" data-contact="{{ $x->id }}"><div><b>{{ $x->name }}</b>@if($x->is_primary) <span class="badge badge-blue">primary</span>@endif @if($x->decision_maker) <span class="badge badge-purple">decides</span>@endif @unless($x->is_active) <span class="badge badge-slate">inactive</span>@endunless
    <div class="tiny muted">{{ $x->title }}{{ $x->phone ? ' · '.$x->phone : '' }}{{ $x->email ? ' · '.$x->email : '' }}{{ $x->birthday ? ' · 🎂 '.$x->birthday->format('d M') : '' }}</div></div>
    @if($mayEdit)<div class="flex gap-05"><button class="btn btn-sm btn-ghost btn-icon" data-modal="contactModal{{ $x->id }}" title="Edit"><x-icon name="edit"/></button>
      <form method="post" action="{{ route('sales.contact.delete', $x) }}" data-confirm="Remove {{ $x->name }}?">@csrf<button class="btn btn-sm btn-ghost btn-icon" title="Remove"><x-icon name="trash"/></button></form></div>@endif</div>
@empty<div class="muted small">No contacts yet.</div>@endforelse</div></div>

<div class="card flush mt-1" id="contractsCard"><div class="card-head"><h3><x-icon name="receipt"/> Rate contracts</h3></div>
<div class="card-body">@forelse($contracts as $k)
  <div class="between-row"><div>@if(can_do('contracts.view'))<a href="{{ route('contracts.show', $k) }}"><b>{{ $k->code }}</b></a>@else<b>{{ $k->code }}</b>@endif<div class="tiny muted">{{ $k->valid_from->format('d M Y') }} – {{ $k->valid_to->format('d M Y') }}</div></div><span class="badge badge-{{ $k->status === 'active' ? 'green' : ($k->status === 'draft' ? 'amber' : 'slate') }}">{{ ucfirst($k->status) }}</span></div>
@empty<div class="muted small">No contracts.</div>@endforelse</div></div>
</div>
</div>
@if($mayEdit)@include('sales._account_modal', ['c' => $c])@include('sales._contact_modal', ['x' => null])@foreach($c->contacts as $x)@include('sales._contact_modal', ['x' => $x])@endforeach @endif
@if($seller)@include('sales._opp_modal', ['o' => null, 'preset' => $c->id])
@include('sales._activity_modals', ['contacts' => $c->contacts->where('is_active', true), 'openOpps' => $open, 'presetCompany' => $c->id, 'cfg' => \App\Services\SalesService::cfg()])@endif
@endsection
