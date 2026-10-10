@extends('layouts.app')
@section('title', 'Vendor Settlements')
@section('crumbs') Concierge / Settlements @endsection
@php $settle = can_do('concierge.settle'); @endphp
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'concierge_vendors']) }}"><x-icon name="download"/> Vendor report</a>
@endsection
@section('content')
@php $oweAll = $open->sum('payable'); $dueAll = $open->sum('receivable'); @endphp
<div class="stats" id="ctlStats">
  <x-stat label="Owed to operators" :value="money($oweAll)" icon="wallet" tone="purple" :sub="'finished room-bill bookings · ledger 2170 at last audit '.money($control['payable'])"/>
  <x-stat label="Commission due from operators" :value="money($dueAll)" icon="money" tone="green" :sub="'guest paid the operator · ledger 1140 at last audit '.money($control['receivable'])"/>
  <x-stat label="Net" :value="money(abs($oweAll - $dueAll))" icon="swap" tone="blue" :sub="$oweAll >= $dueAll ? 'hotel pays operators' : 'operators pay the hotel'"/>
</div>

<div class="card flush mt-1" id="balancesCard"><div class="card-head"><h3><x-icon name="users"/> Unsettled by operator</h3>
  <form method="get" class="flex gap-1" style="align-items:center"><label for="upTo" class="small">Activities up to</label><input type="date" name="up_to" id="upTo" value="{{ $upTo }}" onchange="this.form.submit()"></form></div>
<div class="card-body table-wrap"><table class="table table-compact" id="balancesTable">
  <thead><tr><th>Operator</th><th class="num">Bookings</th><th>Oldest</th><th class="num">Hotel owes</th><th class="num">Operator owes</th><th class="num">Net</th><th>Settle</th></tr></thead>
  <tbody>@forelse($balances as $r) @php $v = $r['vendor']; @endphp
    <tr data-vendor="{{ $v->id }}"><td><b>{{ $v->name }}</b><div class="tiny muted">{{ $v->payout_details }}</div></td><td class="num">{{ $r['bookings'] }}</td><td class="small">{{ \Carbon\Carbon::parse($r['oldest'])->format('d M') }}</td>
      <td class="num">{{ money($r['payable'], false) }}</td><td class="num">{{ money($r['receivable'], false) }}</td>
      <td class="num bold">{{ money(abs($r['net']), false) }}<div class="tiny muted">{{ $r['net'] > 0 ? 'pay' : ($r['net'] < 0 ? 'receive' : 'contra') }}</div></td>
      <td>@if($settle)<form method="post" action="{{ route('concierge.settle') }}" class="flex gap-1 wrap" style="align-items:center" data-confirm="Post a settlement with {{ $v->name }} for {{ money(abs($r['net'])) }}?">@csrf
        <input type="hidden" name="activity_vendor_id" value="{{ $v->id }}"><input type="hidden" name="up_to" value="{{ $upTo }}">
        <input type="date" name="date" value="{{ bdate()->toDateString() }}" required aria-label="Payment date" style="width:9.5rem">
        @if(abs($r['net']) >= 0.005)<select name="treasury_account_id" required aria-label="Bank / MoMo / cash account">@foreach($accounts as $acc)<option value="{{ $acc->id }}">{{ $acc->name }}</option>@endforeach</select>@else<input type="hidden" name="treasury_account_id" value="">@endif
        <input name="reference" maxlength="80" placeholder="Ref / MoMo txn" style="width:8rem" aria-label="Reference">
        <button class="btn btn-sm btn-primary">{{ $r['net'] > 0 ? 'Pay' : ($r['net'] < 0 ? 'Receive' : 'Contra') }}</button></form>@endif</td></tr>
  @empty<tr><td colspan="7" class="empty">Nothing to settle – every finished booking up to this date is settled.</td></tr>@endforelse</tbody>
</table></div></div>

<div class="card flush mt-1" id="historyCard"><div class="card-head"><h3><x-icon name="receipt"/> Settlements</h3></div>
<div class="card-body table-wrap"><table class="table table-compact" id="settlementsTable">
  <thead><tr><th>No.</th><th>Date</th><th>Operator</th><th>Up to</th><th class="num">Bookings</th><th class="num">Net</th><th>Account</th><th>Status</th></tr></thead>
  <tbody>@forelse($settlements as $s)
    <tr data-settlement="{{ $s->number }}" class="{{ $s->status === 'void' ? 'muted' : '' }}"><td><a href="{{ route('concierge.settlement', $s) }}"><b>{{ $s->number }}</b></a></td><td class="small">{{ $s->date->format('d M Y') }}</td><td class="small">{{ $s->vendor?->name }}</td>
      <td class="small">{{ $s->up_to->format('d M') }}</td><td class="num">{{ $s->bookings }}</td><td class="num">{{ money(abs((float) $s->net), false) }} <span class="tiny muted">{{ (float) $s->net > 0 ? 'paid' : ((float) $s->net < 0 ? 'received' : '') }}</span></td>
      <td class="small">{{ $s->account?->name ?? '—' }}{{ $s->reference ? ' · '.$s->reference : '' }}</td><td><span class="badge badge-{{ $s->status === 'void' ? 'red' : 'green' }}">{{ ucfirst($s->status) }}</span></td></tr>
  @empty<tr><td colspan="8" class="empty">No settlements yet.</td></tr>@endforelse</tbody>
</table></div></div>
@endsection
