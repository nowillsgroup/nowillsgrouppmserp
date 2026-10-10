@extends('layouts.print')
@section('title', $p->receipt_no)
@section('doctitle', in_array($p->kind, ['refund', 'deposit_refund']) ? 'REFUND VOUCHER' : ($p->kind === 'deposit_applied' ? 'DEPOSIT APPLICATION' : ($p->kind === 'deposit' ? 'DEPOSIT RECEIPT' : 'OFFICIAL RECEIPT')))
@section('docmeta')<div class="small">No. <b id="rcNo">{{ $p->receipt_no }}</b> · {{ dmy($p->date) }}<br>Lease {{ $l->lease_no }} · {{ $l->unit->code }}@if($p->voided_at)<br><b style="color:#b91c1c">VOID – {{ $p->void_reason }}</b>@endif</div>@endsection
@section('content')
@php $td = 'padding:7px 8px;border-bottom:1px solid #e2e8f0;vertical-align:top'; $out = in_array($p->kind, ['refund', 'deposit_refund']); @endphp
<table style="width:100%;border-collapse:collapse;font-size:14px;margin-top:1rem">
  <tr><td style="{{ $td }};width:32%;color:#64748b">{{ $out ? 'Paid to' : 'Received from' }}</td><td style="{{ $td }}"><b>{{ $l->company?->name ?? $l->tenant_name }}</b>{{ $l->company ? ' – '.$l->tenant_name : '' }}</td></tr>
  <tr><td style="{{ $td }};color:#64748b">Amount</td><td style="{{ $td }};font-size:18px"><b>{{ money($p->amount) }}</b></td></tr>
  <tr><td style="{{ $td }};color:#64748b">In words</td><td style="{{ $td }}"><i>Ghana cedis {{ lcfirst(amount_words((float) $p->amount)) }}</i></td></tr>
  @if((float) $p->wht_amount > 0)<tr><td style="{{ $td }};color:#64748b">Tax withheld</td><td style="{{ $td }}">{{ money($p->wht_amount) }}{{ $p->wht_cert_no ? ' – certificate '.$p->wht_cert_no : ' – certificate to follow' }} · total settled {{ money((float) $p->amount + (float) $p->wht_amount) }}</td></tr>@endif
  @if($p->kind !== 'deposit_applied')<tr><td style="{{ $td }};color:#64748b">Mode</td><td style="{{ $td }}">{{ $p->paymentMode?->name }}{{ $p->reference ? ' · '.$p->reference : '' }}</td></tr>@endif
  <tr><td style="{{ $td }};color:#64748b">For</td><td style="{{ $td }}">{{ \App\Models\LeasePayment::KINDS[$p->kind] }} – {{ $l->unit->code }} {{ $l->unit->name }}{{ $p->allocations ? ' – '.collect($p->allocations)->pluck('charge_no')->implode(', ') : '' }}{{ $p->note ? ' – '.$p->note : '' }}</td></tr>
  <tr><td style="{{ $td }};color:#64748b">Account balance now</td><td style="{{ $td }}">{{ $balance >= 0 ? money($balance).' due' : money(-$balance).' in credit' }} · deposit held {{ money($deposit) }}</td></tr>
</table>
<table style="width:100%;margin-top:3rem;font-size:13px;text-align:center"><tr>
  <td style="width:50%"><div style="border-top:1px solid #0f172a;margin:0 2rem;padding-top:4px">{{ $out ? 'Paid' : 'Received' }} by<br><b>{{ $p->user?->name }}</b></div></td>
  <td style="width:50%"><div style="border-top:1px solid #0f172a;margin:0 2rem;padding-top:4px">{{ $out ? 'Received by' : 'Tenant' }}<br><b>{{ $l->tenant_name }}</b></div></td>
</tr></table>
@endsection
