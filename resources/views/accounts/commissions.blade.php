@extends('layouts.app')
@section('title', 'Agent Commissions')
@section('crumbs') Accounts / Agent Commissions @endsection
@section('content')
@php $companyId = request()->integer('company_id'); $payable = $companyId && $status === 'due' && can_do('accounts.manage') && $rows->count(); @endphp
<div class="stats">
  <x-stat label="Commission due" :value="money($summary->sum('amount'))" icon="wallet" tone="amber"/>
  <x-stat label="Agents with dues" :value="$summary->count()" icon="building" tone="blue"/>
  <x-stat label="Stays pending" :value="$summary->sum('stays')" icon="bed" tone="slate"/>
</div>
@if($summary->isNotEmpty())
<div class="card flush"><div class="card-head"><h3>Due by agent</h3><span class="small muted">Accrued automatically at check-out on the room revenue charged × the agent's commission %</span></div><div class="card-body table-wrap"><table class="table">
  <thead><tr><th>Agent / company</th><th class="num">Commission %</th><th class="num">Stays</th><th class="num">Room revenue</th><th class="num">Due</th><th></th></tr></thead><tbody>
  @foreach($summary as $s)<tr><td class="bold">{{ $s['company']->name }}</td><td class="num">{{ (float) $s['company']->commission_pct }}%</td><td class="num">{{ $s['stays'] }}</td><td class="num">{{ money($s['revenue'], false) }}</td><td class="num bold">{{ money($s['amount'], false) }}</td>
    <td class="right"><a class="btn btn-sm btn-primary" href="{{ route('accounts.commissions', ['company_id' => $s['company']->id, 'status' => 'due']) }}">Settle</a></td></tr>@endforeach
  </tbody></table></div></div>
@endif

<form method="get" class="filters card"><div class="card-body flex gap-1 wrap items-end">
  <x-field name="company_id" label="Agent" type="select" :options="$companies" :value="$companyId ?: ''" placeholder="All agents"/>
  <x-field name="status" label="Status" type="select" :options="['due' => 'Due', 'paid' => 'Paid', 'void' => 'Void', 'all' => 'All']" :value="$status"/>
  <button class="btn">Filter</button>
</div></form>

<form method="post" action="{{ route('accounts.commissions.pay') }}" id="payForm" @if($payable) data-confirm="Create a payment voucher for the selected commissions?" @endif>@csrf
<input type="hidden" name="company_id" value="{{ $companyId }}">
<div class="card flush"><div class="card-body table-wrap"><table class="table">
<thead><tr>@if($payable)<th style="width:28px"><input type="checkbox" checked onclick="document.querySelectorAll('.cm-chk').forEach(c => { c.checked = this.checked; }); cmTotal()"></th>@endif<th>Check-out</th><th>Reservation</th><th>Guest / room</th><th>Agent</th><th class="num">Nights</th><th class="num">Room revenue</th><th class="num">%</th><th class="num">Commission</th><th>Status</th><th></th></tr></thead><tbody>
@forelse($rows as $c)<tr>
  @if($payable)<td><input type="checkbox" class="cm-chk" name="ids[]" value="{{ $c->id }}" data-amt="{{ $c->amount }}" checked onchange="cmTotal()"></td>@endif
  <td>{{ $c->business_date->format('d M Y') }}</td><td><a href="{{ route('reservations.show', $c->reservation_id) }}">{{ $c->reservation->reservation_no }}</a></td>
  <td>{{ $c->reservation->guest?->full_name }} <span class="tiny muted">{{ $c->stay?->room ? 'Rm '.$c->stay->room->room_no : '' }}</span></td><td>{{ $c->company->name }}</td>
  <td class="num">{{ $c->nights }}</td><td class="num">{{ money($c->room_revenue, false) }}</td><td class="num">{{ (float) $c->pct }}</td><td class="num bold">{{ money($c->amount, false) }}</td>
  <td>{!! status_badge($c->status) !!} @if($c->voucher)<div class="tiny muted">{{ $c->voucher->voucher_no }} · {{ $c->paid_at?->format('d M') }}</div>@endif</td>
  <td class="right">@if($c->status === 'due' && can_do('accounts.manage'))<button type="button" class="btn btn-sm btn-ghost" data-modal="voidCm" data-action="{{ route('accounts.commissions.void', $c) }}" title="Void"><x-icon name="x" size="sm"/></button>@endif</td>
</tr>@empty<tr><td colspan="11"><x-empty text="No commissions for this filter." icon="wallet"/></td></tr>@endforelse
</tbody></table></div></div>
@if($payable)
<div class="card"><div class="card-body flex gap-1 wrap items-end">
  <div><div class="small muted">Selected</div><b style="font-size:1.3rem" id="cmTotal">{{ money($rows->sum('amount')) }}</b></div>
  <x-field name="payment_mode_id" label="Paid by" type="select" :options="$paymentModes" required/>
  <x-field name="date" label="Date" type="date" :value="bdate()->toDateString()" required/>
  <x-field name="reference" label="Reference / cheque no."/>
  <button class="btn btn-primary"><x-icon name="money"/> Pay selected</button>
</div></div>
@elseif($status === 'due' && $rows->count() && can_do('accounts.manage'))
<p class="small muted">To record a payment, choose an agent above or click <b>Settle</b>.</p>
@endif
</form>
{{ $rows->links() }}

<div class="modal" id="voidCm"><div class="modal-box"><form method="post">@csrf
  <div class="modal-head"><h3>Void commission</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="reason" label="Reason" required/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger">Void</button></div>
</form></div></div>
@endsection
@push('scripts')
<script>
function cmTotal() { let t = 0; document.querySelectorAll('.cm-chk:checked').forEach(c => t += +c.dataset.amt); const el = document.getElementById('cmTotal'); if (el) el.textContent = @json(hotel()->currency_symbol ?? '') + t.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}); }
</script>
@endpush
