@extends('layouts.print')
@section('title', $p->receipt_no)
@section('doctitle', $p->kind === 'refund' ? 'REFUND VOUCHER' : 'OFFICIAL RECEIPT')
@section('docmeta')<div class="small">No. <b id="rcNo">{{ $p->receipt_no }}</b> · {{ dmy($p->date) }}<br>Membership {{ $m->membership_no }}@if($p->voided_at)<br><b style="color:#b91c1c">VOID – {{ $p->void_reason }}</b>@endif</div>@endsection
@section('content')
@php $td = 'padding:7px 8px;border-bottom:1px solid #e2e8f0;vertical-align:top'; @endphp
<table style="width:100%;border-collapse:collapse;font-size:14px;margin-top:1rem">
  <tr><td style="{{ $td }};width:32%;color:#64748b">{{ $p->kind === 'refund' ? 'Paid to' : 'Received from' }}</td><td style="{{ $td }}"><b>{{ $m->company?->name ?? $m->holder_name }}</b></td></tr>
  <tr><td style="{{ $td }};color:#64748b">Amount</td><td style="{{ $td }};font-size:18px"><b>{{ money($p->amount) }}</b></td></tr>
  <tr><td style="{{ $td }};color:#64748b">In words</td><td style="{{ $td }}"><i>Ghana cedis {{ lcfirst(amount_words((float) $p->amount)) }}</i></td></tr>
  <tr><td style="{{ $td }};color:#64748b">Mode</td><td style="{{ $td }}">{{ $p->paymentMode?->name }}{{ $p->reference ? ' · '.$p->reference : '' }}</td></tr>
  <tr><td style="{{ $td }};color:#64748b">For</td><td style="{{ $td }}">{{ $m->plan->name }} membership{{ $p->allocations ? ' – '.collect($p->allocations)->pluck('charge_no')->implode(', ') : '' }}</td></tr>
  <tr><td style="{{ $td }};color:#64748b">Account balance now</td><td style="{{ $td }}">{{ $balance >= 0 ? money($balance).' due' : money(-$balance).' in credit' }}</td></tr>
</table>
<table style="width:100%;margin-top:3rem;font-size:13px;text-align:center"><tr>
  <td style="width:50%"><div style="border-top:1px solid #0f172a;margin:0 2rem;padding-top:4px">{{ $p->kind === 'refund' ? 'Paid' : 'Received' }} by<br><b>{{ $p->user?->name }}</b></div></td>
  <td style="width:50%"><div style="border-top:1px solid #0f172a;margin:0 2rem;padding-top:4px">{{ $p->kind === 'refund' ? 'Received by' : 'Member' }}<br><b>{{ $m->holder_name }}</b></div></td>
</tr></table>
@endsection
