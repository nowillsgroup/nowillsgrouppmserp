@extends('layouts.print')
@section('title', 'Settlement '.$s->number)
@section('doctitle', 'OPERATOR SETTLEMENT')
@section('docmeta')<div class="small"><b>{{ $s->number }}</b> · {{ $s->date->format('d M Y') }}</div><div class="small">Activities up to {{ $s->up_to->format('d M Y') }}</div>@if($s->status === 'void')<div class="badge badge-red">VOID</div>@endif @endsection
@section('pageclass', 'landscape')
@section('content')
<div class="flex gap-1 wrap mt-1 small" id="settleHead" style="justify-content:space-between">
  <div><b>{{ $s->vendor->name }}</b><br>{{ collect([$s->vendor->contact_name, $s->vendor->phone, $s->vendor->email])->filter()->implode(' · ') }}<br>{{ $s->vendor->payout_details }}</div>
  <div class="right">{{ (float) $s->net > 0 ? 'Paid to the operator' : ((float) $s->net < 0 ? 'Received from the operator' : 'Contra – nothing to pay') }}: <b id="settleNet">{{ money(abs((float) $s->net)) }}</b>
    <br>{{ $s->account?->name }}{{ $s->reference ? ' · ref '.$s->reference : '' }}<br>Journal {{ $s->journal?->journal_no ?? '—' }} · {{ $s->creator?->name }}</div>
</div>
@if($s->status === 'void')<div class="alert alert-error mt-1">Voided – {{ $s->void_reason }}. The bookings are open for settlement again.</div>@endif
<table class="table table-compact mt-1" id="settleTable">
  <thead><tr><th>Booking</th><th>Date</th><th>Activity</th><th>Guest</th><th>Status</th><th>Paid to</th><th class="num">Guest paid</th><th class="num">Commission</th><th class="num">Hotel owes</th><th class="num">Operator owes</th></tr></thead>
  <tbody>@forelse($rows as $b)
    <tr data-booking="{{ $b->number }}"><td>{{ $b->number }}</td><td class="small">{{ $b->date->format('d M') }}</td><td class="small">{{ $b->activity->name }}</td><td class="small">{{ $b->guest_name }}</td><td class="small">{{ $b->statusLabel() }}</td>
      <td class="small">{{ $b->collection === 'folio' ? 'Hotel' : 'Operator' }}</td><td class="num">{{ money($b->status === 'cancelled' ? $b->cancel_fee : $b->gross, false) }}</td><td class="num">{{ money($b->commission, false) }}</td>
      <td class="num">{{ $b->collection === 'folio' ? money($b->vendor_net, false) : '' }}</td><td class="num">{{ $b->collection === 'vendor' ? money($b->commission, false) : '' }}</td></tr>
  @empty<tr><td colspan="10" class="empty">{{ $s->status === 'void' ? 'Void – no bookings attached.' : 'No bookings.' }}</td></tr>@endforelse</tbody>
  <tfoot><tr class="bold"><td colspan="8">Totals</td><td class="num">{{ money($s->payable, false) }}</td><td class="num">{{ money($s->receivable, false) }}</td></tr></tfoot>
</table>
@if(can_do('concierge.settle') && $s->status === 'posted')
<form method="post" action="{{ route('concierge.settlement.void', $s) }}" class="no-print card mt-1" data-confirm="Void this settlement and reverse its journal?">@csrf<div class="card-body flex gap-1" style="align-items:flex-end">
  <div class="field" style="flex:1"><label>Void reason</label><input name="reason" required maxlength="190" placeholder="Wrong account / amount"></div><button class="btn btn-danger" id="voidSettleBtn">Void settlement</button></div></form>
@endif
@endsection
