@extends('layouts.app')
@section('title', 'Club Billing & Renewals')
@section('crumbs') <a href="{{ route('club.index') }}">Health Club</a> / Billing &amp; renewals @endsection
@section('actions')
@if(can_do('club.members'))<form method="post" action="{{ route('club.billing.run') }}" data-confirm="Raise renewal invoices for every auto-renewing membership ending within {{ $renewDays }} days?">@csrf<button class="btn btn-primary" id="runRenewals"><x-icon name="refresh"/> Run renewals ({{ $due->count() }})</button></form>@endif
@if(can_do('reports.view'))<a class="btn" href="{{ route('reports.show', ['club_revenue']) }}"><x-icon name="chart"/> Revenue report</a>@endif
@endsection
@section('content')
<form method="get" class="filters"><div class="field"><label for="f_from">From</label><input type="date" name="from" id="f_from" value="{{ $from }}"></div><div class="field"><label for="f_to">To</label><input type="date" name="to" id="f_to" value="{{ $to }}"></div><div class="field" style="align-self:flex-end"><button class="btn">Show</button></div></form>
<div class="stats" id="billStats">
  <x-stat label="Charged (incl. tax)" :value="money($charges->whereNull('cancelled_at')->sum('total'))" icon="receipt" :sub="$charges->whereNull('cancelled_at')->count().' charges & credit notes'"/>
  <x-stat label="Received (net of refunds)" :value="money($receipts->whereNull('voided_at')->sum(fn ($p) => $p->signed()))" icon="money" tone="green" :sub="$receipts->whereNull('voided_at')->count().' receipts'"/>
  <x-stat label="Membership revenue earned" :value="money($recognised)" icon="trend" tone="purple" :sub="'recognised day by day · '.money($unearned).' still in advance at '.dmy($to)"/>
  <x-stat :label="$receivable < 0 ? 'Members receivable (net credit)' : 'Members receivable'" :value="money($receivable)" icon="wallet" :tone="$arrears->count() ? 'red' : 'blue'" :sub="$arrears->count().' in arrears · '.money($arrears->sum('amount')).' overdue'"/>
</div>

<div class="tabs" data-tabs>
  <button class="active" data-tab="renewals">Renewals due ({{ $due->count() }})</button>
  <button data-tab="arrears">Arrears ({{ $arrears->count() }})</button>
  <button data-tab="ending">Ending / lapsed ({{ $ending->count() + $lapsed->count() }})</button>
  <button data-tab="journal">Charges &amp; receipts</button>
</div>

<div class="tab-pane active" id="renewals"><div class="card flush"><div class="card-body table-wrap"><table class="table" id="dueTable">
  <thead><tr><th>Membership</th><th>Plan</th><th>Term ends</th><th class="num">Renewal (incl. tax)</th><th></th></tr></thead>
  <tbody>@forelse($due as $m)
    <tr data-membership="{{ $m->membership_no }}"><td><a class="bold" href="{{ route('club.members.show', $m) }}">{{ $m->membership_no }}</a> {{ $m->holder_name }}<div class="tiny muted">{{ $m->company?->name }}</div></td><td class="small">{{ $m->plan->name }}</td><td class="small">{{ dmy($m->end_date) }}</td>
      <td class="num">{{ money(\App\Services\ClubBillingService::tax((float) $m->plan->price)['total'], false) }}</td>
      <td class="num">@if(can_do('club.members'))<form method="post" action="{{ route('club.members.renew', $m) }}">@csrf<button class="btn btn-sm">Renew now</button></form>@endif</td></tr>
  @empty <tr><td colspan="5"><x-empty text="No auto-renewals due in the next {{ $renewDays }} days." icon="refresh"/></td></tr> @endforelse</tbody></table></div></div>
  <p class="tiny muted">The night audit raises these invoices automatically {{ $renewDays }} days before the term ends; the button does the same now. The new period follows on from the current end date.</p></div>

<div class="tab-pane" id="arrears"><div class="card flush"><div class="card-body table-wrap"><table class="table" id="arrearsTable">
  <thead><tr><th>Membership</th><th>Plan</th><th>Phone</th><th>Oldest due</th><th class="num">Days</th><th class="num">Overdue</th><th class="num">Balance</th></tr></thead>
  <tbody>@forelse($arrears as $a)@php $m = $a['membership']; @endphp
    <tr><td><a class="bold" href="{{ route('club.members.show', $m) }}">{{ $m->membership_no }}</a> {{ $m->holder_name }}</td><td class="small">{{ $m->plan->name }}</td><td class="small">{{ $m->phone }}</td><td class="small">{{ dmy($a['since']) }}</td>
      <td class="num">{{ (int) $a['since']->diffInDays(bdate()) }}</td><td class="num bold text-red">{{ money($a['amount'], false) }}</td><td class="num">{{ money($a['balance'], false) }}</td></tr>
  @empty <tr><td colspan="7"><x-empty text="Nobody is in arrears." icon="check"/></td></tr> @endforelse</tbody></table></div></div>
  <p class="tiny muted">A charge more than {{ \App\Services\ClubService::cfg('club_grace_days') }} days past its due date blocks entry at the desk until it is paid (a manager can override).</p></div>

<div class="tab-pane" id="ending"><div class="g-2 grid" style="gap:1rem">
  <div class="card flush"><div class="card-head"><h3>Ending in 30 days – not renewing</h3></div><div class="card-body table-wrap"><table class="table">
    <tbody>@forelse($ending as $m)<tr><td><a class="bold" href="{{ route('club.members.show', $m) }}">{{ $m->membership_no }}</a> {{ $m->holder_name }}<div class="tiny muted">{{ $m->plan->name }} · {{ $m->phone }}</div></td><td class="small">{{ dmy($m->end_date) }}</td><td>{!! $m->cancel_mode ? '<span class="badge badge-slate">notice given</span>' : '<span class="badge badge-amber">auto-renew off</span>' !!}</td></tr>
    @empty <tr><td><x-empty text="None." icon="check"/></td></tr> @endforelse</tbody></table></div></div>
  <div class="card flush"><div class="card-head"><h3>Lapsed in the last 60 days – win back</h3></div><div class="card-body table-wrap"><table class="table">
    <tbody>@forelse($lapsed as $m)<tr><td><a class="bold" href="{{ route('club.members.show', $m) }}">{{ $m->membership_no }}</a> {{ $m->holder_name }}<div class="tiny muted">{{ $m->plan->name }} · {{ $m->phone }}</div></td><td class="small">expired {{ dmy($m->end_date) }}</td></tr>
    @empty <tr><td><x-empty text="None." icon="check"/></td></tr> @endforelse</tbody></table></div></div>
</div></div>

<div class="tab-pane" id="journal"><div class="g-2 grid" style="gap:1rem">
  <div class="card flush"><div class="card-head"><h3>Charges</h3></div><div class="card-body table-wrap"><table class="table table-compact">
    <tbody>@forelse($charges as $c)<tr class="{{ $c->cancelled_at ? 'muted' : '' }}"><td class="small">{{ dmy($c->date, 'd/m') }}</td><td><a href="{{ route('club.members.show', $c->club_membership_id) }}">{{ $c->charge_no }}</a><div class="tiny muted">{{ \App\Models\ClubCharge::TYPES[$c->type] }} · {{ $c->membership->holder_name }}</div></td><td class="num">{{ $c->cancelled_at ? 'cancelled' : money($c->total, false) }}</td></tr>
    @empty <tr><td><x-empty text="No charges in this period." icon="receipt"/></td></tr> @endforelse</tbody></table></div></div>
  <div class="card flush"><div class="card-head"><h3>Receipts</h3></div><div class="card-body table-wrap"><table class="table table-compact">
    <tbody>@forelse($receipts as $p)<tr class="{{ $p->voided_at ? 'muted' : '' }}"><td class="small">{{ dmy($p->date, 'd/m') }}</td><td><a href="{{ route('club.members.show', $p->club_membership_id) }}">{{ $p->receipt_no }}</a><div class="tiny muted">{{ $p->paymentMode?->name }} · {{ $p->membership->holder_name }}</div></td><td class="num">{{ $p->voided_at ? 'void' : ($p->kind === 'refund' ? '−' : '').money($p->amount, false) }}</td></tr>
    @empty <tr><td><x-empty text="No receipts in this period." icon="money"/></td></tr> @endforelse</tbody></table></div></div>
</div></div>
@endsection
