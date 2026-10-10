@extends('layouts.print')
@section('title', $c->charge_no)
@section('doctitle', $c->type === 'credit' ? 'CREDIT NOTE' : 'TAX INVOICE')
@section('docmeta')<div class="small">No. <b id="invNo">{{ $c->charge_no }}</b> · {{ dmy($c->date) }}<br>Membership {{ $m->membership_no }}@if($c->type !== 'credit')<br>Due {{ dmy($c->due_date) }}@endif @if($c->cancelled_at)<br><b style="color:#b91c1c">CANCELLED</b>@endif</div>@endsection
@section('content')
@php $td = 'padding:7px 8px;border-bottom:1px solid #e2e8f0'; $sign = $c->type === 'credit' ? -1 : 1; @endphp
<div style="margin:1rem 0"><div class="small muted">Bill to</div><b>{{ $m->company?->name ?? $m->holder_name }}</b>@if($m->company)<br>Member: {{ $m->holder_name }}@endif<br>{{ $m->phone }} {{ $m->email }}</div>
<table style="width:100%;border-collapse:collapse;font-size:14px" id="invTable">
  <thead><tr style="background:#f1f5f9;text-align:left"><th style="{{ $td }}">Description</th><th style="{{ $td }};text-align:right">Amount (GH₵)</th></tr></thead>
  <tbody><tr><td style="{{ $td }}">{{ $c->description }}@if($c->period_start)<div class="small muted">Service period {{ dmy($c->period_start) }} – {{ dmy($c->period_end) }}</div>@endif @if($c->creditOf)<div class="small muted">Against invoice {{ $c->creditOf->charge_no }} of {{ dmy($c->creditOf->date) }}</div>@endif</td><td style="{{ $td }};text-align:right">{{ money($sign * (float) $c->amount, false) }}</td></tr>
    @foreach($split as $code => $amt)@if(abs($amt) > 0.004)<tr><td style="{{ $td }};text-align:right" class="small">{{ $labels[$code] ?? $code }}</td><td style="{{ $td }};text-align:right">{{ money($sign * $amt, false) }}</td></tr>@endif @endforeach
  </tbody>
  <tfoot><tr><th style="{{ $td }};text-align:right">Total</th><th style="{{ $td }};text-align:right;font-size:16px">{{ money($sign * (float) $c->total) }}</th></tr></tfoot>
</table>
<p class="small" style="margin-top:.8rem"><i>Ghana cedis {{ lcfirst(amount_words(abs((float) $c->total))) }}</i></p>
@if($c->type !== 'credit')<p class="small">Settled: {{ money($c->paid) }} · Outstanding: <b>{{ money($c->outstanding()) }}</b></p>@endif
<p class="tiny muted" style="margin-top:1.5rem">VAT 15%, NHIL 2.5% and GETFund 2.5% (VAT Act 2025, Act 1151) and the 1% Tourism Levy (Act 817) are included as shown. Quote {{ $m->membership_no }} with payment.</p>
@endsection
