@extends('layouts.print')
@section('title', $c->charge_no)
@section('doctitle', $c->type === 'credit' ? 'CREDIT NOTE' : ($l->tax_class === 'residential' ? 'RENT INVOICE' : 'TAX INVOICE'))
@section('docmeta')<div class="small">No. <b id="invNo">{{ $group->pluck('charge_no')->implode(' / ') }}</b> · {{ dmy($c->date) }}<br>Lease {{ $l->lease_no }} · {{ $l->unit->code }}@if($c->type !== 'credit')<br>Due {{ dmy($c->due_date) }}@endif @if($c->cancelled_at)<br><b style="color:#b91c1c">CANCELLED</b>@endif</div>@endsection
@section('content')
@php $td = 'padding:7px 8px;border-bottom:1px solid #e2e8f0'; $sign = $c->type === 'credit' ? -1 : 1; $total = (float) $group->sum('total'); @endphp
<div style="margin:1rem 0"><div class="small muted">Bill to</div><b>{{ $l->company?->name ?? $l->tenant_name }}</b>@if($l->company)<br>Occupant: {{ $l->tenant_name }}@endif<br>{{ $l->unit->code }} – {{ $l->unit->name }}<br>{{ $l->phone }} {{ $l->email }}</div>
<table style="width:100%;border-collapse:collapse;font-size:14px" id="invTable">
  <thead><tr style="background:#f1f5f9;text-align:left"><th style="{{ $td }}">Description</th><th style="{{ $td }};text-align:right">Amount (GH₵)</th></tr></thead>
  <tbody>@foreach($group as $g)<tr><td style="{{ $td }}">{{ $g->description }}@if($g->period_start && $g->type !== 'rent' && $g->type !== 'service_charge')<div class="small muted">Period {{ dmy($g->period_start) }} – {{ dmy($g->period_end) }}</div>@endif @if($g->creditOf)<div class="small muted">Against invoice {{ $g->creditOf->charge_no }} of {{ dmy($g->creditOf->date) }}</div>@endif</td><td style="{{ $td }};text-align:right">{{ money($sign * (float) $g->amount, false) }}</td></tr>@endforeach
    @foreach($split as $code => $amt)@if(abs($amt) > 0.004)<tr><td style="{{ $td }};text-align:right" class="small">{{ $labels[$code] ?? $code }}</td><td style="{{ $td }};text-align:right">{{ money($sign * $amt, false) }}</td></tr>@endif @endforeach
  </tbody>
  <tfoot><tr><th style="{{ $td }};text-align:right">Total</th><th style="{{ $td }};text-align:right;font-size:16px">{{ money($sign * $total) }}</th></tr>
  @if($wht > 0 && $c->type !== 'credit')<tr><td style="{{ $td }};text-align:right" class="small">Less withholding tax {{ rtrim(rtrim(number_format($l->wht_pct, 2), '0'), '.') }}% on rent (remit to GRA and send us the WHT certificate)</td><td style="{{ $td }};text-align:right">({{ money($wht, false) }})</td></tr>
  <tr><th style="{{ $td }};text-align:right">Net payable</th><th style="{{ $td }};text-align:right">{{ money($total - $wht) }}</th></tr>@endif</tfoot>
</table>
<p class="small" style="margin-top:.8rem"><i>Ghana cedis {{ lcfirst(amount_words(abs($total))) }}</i></p>
@if($c->type !== 'credit')<p class="small">Settled: {{ money($group->sum('paid')) }} · Outstanding: <b>{{ money($group->sum(fn ($g) => $g->outstanding())) }}</b></p>@endif
<p class="tiny muted" style="margin-top:1.5rem">@if($l->tax_class === 'residential')Residential letting – an exempt supply; no VAT, NHIL or GETFund is charged.@elseif($l->tax_class === 'commercial')VAT 15%, NHIL 2.5% and GETFund 2.5% (VAT Act 2025, Act 1151) are included as shown.@else VAT 15%, NHIL 2.5% and GETFund 2.5% (VAT Act 2025, Act 1151) and the 1% Tourism Levy (Act 817) are included as shown.@endif Quote {{ $l->lease_no }} with payment.</p>
@endsection
