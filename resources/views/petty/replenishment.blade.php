@extends('layouts.print')
@section('title', $r->repl_no)
@section('doctitle', 'PETTY CASH TOP-UP')
@section('docmeta')<div class="small">No. <b>{{ $r->repl_no }}</b> · requested {{ dmy($r->date) }}<br>{{ $r->float->name }} ({{ $r->float->account?->label }})<br>Status: <b>{{ strtoupper($r->status) }}</b></div>@endsection
@section('content')
@php $td = 'padding:5px 6px;border-bottom:1px solid #e2e8f0'; $num = $td.';text-align:right;font-variant-numeric:tabular-nums'; $heads = $r->txns->where('type', '!=', 'topup'); @endphp
<table style="width:100%;border-collapse:collapse;font-size:13px;margin-top:1rem" id="replItems">
  <tr style="background:#f1f5f9"><th style="{{ $td }};text-align:left">No.</th><th style="{{ $td }};text-align:left">Date</th><th style="{{ $td }};text-align:left">Paid to</th><th style="{{ $td }};text-align:left">Account</th><th style="{{ $num }}">VAT</th><th style="{{ $num }}">Amount</th></tr>
  @foreach($heads as $t)
  <tr><td style="{{ $td }}">{{ $t->txn_no }}</td><td style="{{ $td }}">{{ dmy($t->date) }}</td><td style="{{ $td }}">{{ $t->party() }} <span style="color:#64748b">{{ \Illuminate\Support\Str::limit($t->description, 50) }}</span></td>
    <td style="{{ $td }}">{{ $t->head?->name ?? $t->typeLabel() }}</td><td style="{{ $num }}">{{ (float) $t->vat_amount ? money($t->vat_amount, false) : '' }}</td><td style="{{ $num }}">{{ money(-(float) $t->effect, false) }}</td></tr>
  @endforeach
  <tr><td colspan="5" style="{{ $td }}"><b>Receipts &amp; differences since the last top-up</b></td><td style="{{ $num }}"><b>{{ money(-(float) $heads->sum('effect'), false) }}</b></td></tr>
</table>
<h4 style="margin-top:1.2rem">By account</h4>
<table style="width:60%;border-collapse:collapse;font-size:13px">
  @foreach($heads->groupBy(fn ($t) => $t->head?->name ?? $t->typeLabel()) as $name => $g)<tr><td style="{{ $td }}">{{ $name }}</td><td style="{{ $num }}">{{ money(-(float) $g->sum('effect'), false) }}</td></tr>@endforeach
</table>
<p style="margin-top:1rem;font-size:14px">Top-up requested: <b id="replAmount">{{ money($r->amount) }}</b> – restores the float to its imprest of {{ money($r->float->imprest_amount) }}.
@if($r->status === 'paid')<br>Paid {{ dmy($r->paid_on) }} from {{ $r->paymentMode?->name }}{{ $r->reference ? ' · ref '.$r->reference : '' }}.@endif
@if($r->status === 'rejected')<br>Rejected: {{ $r->reject_reason }}@endif</p>
<table style="width:100%;margin-top:3rem;font-size:13px;text-align:center"><tr>
  <td><div style="border-top:1px solid #0f172a;margin:0 1rem;padding-top:4px">Custodian<br><b>{{ $r->requester?->name }}</b></div></td>
  <td><div style="border-top:1px solid #0f172a;margin:0 1rem;padding-top:4px">Approved by<br><b>{{ $r->approver?->name }}</b></div></td>
  <td><div style="border-top:1px solid #0f172a;margin:0 1rem;padding-top:4px">Cash received by<br><b>{{ $r->float->custodian?->name }}</b></div></td>
</tr></table>
@endsection
