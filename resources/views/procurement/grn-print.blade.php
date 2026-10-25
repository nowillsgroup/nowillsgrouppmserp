@extends('layouts.print')
@section('title', $grn->grn_no)
@section('doctitle', 'GOODS RECEIVED NOTE')
@section('docmeta')<div class="small">No. <b id="grnNo">{{ $grn->grn_no }}</b> · {{ $grn->date->format('d M Y') }}<br>PO {{ $grn->order->po_no }} · {{ $grn->store->name }}@if($grn->delivery_note)<br>Delivery note {{ $grn->delivery_note }}@endif @if($grn->invoice_no)<br>Supplier invoice {{ $grn->invoice_no }}@endif</div>@endsection
@section('content')
@php $td = 'padding:6px 8px;border:1px solid #cbd5e1'; $home = strtoupper(trim((string) (hotel()?->currency ?: 'GHS'))); @endphp
<p style="margin-top:1rem"><b>Supplier:</b> {{ $grn->supplier->name }}@if($grn->supplier->tax_number) · TIN {{ $grn->supplier->tax_number }}@endif @if($grn->vehicle_no) · Vehicle {{ $grn->vehicle_no }}@endif</p>
@if($grn->isForeign())<p class="small"><b>Foreign-currency receipt:</b> {{ $grn->fxLabel() }} · valued for stock and GRN accrual at 1 {{ $grn->currency }} = {{ number_format((float) $grn->fx_rate, 4) }} {{ $home }} using the {{ $grn->fx_basis }} Forex Desk board of {{ $grn->fx_date?->format('d M Y') }}@if($grn->fx_date?->toDateString() !== $grn->date->toDateString()) <i>(carried forward to the receipt date)</i>@endif.</p>@endif
<table style="width:100%;border-collapse:collapse;font-size:13px">
  <tr style="background:#f1f5f9"><th style="{{ $td }};text-align:left">Item</th><th style="{{ $td }}">Delivered</th><th style="{{ $td }}">Rejected</th><th style="{{ $td }}">Accepted</th><th style="{{ $td }}">Rate · {{ $grn->isForeign() ? $grn->currency : $home }}</th><th style="{{ $td }}">Tax %</th><th style="{{ $td }}">Amount · {{ $grn->isForeign() ? $grn->currency : $home }}</th></tr>
  @foreach($grn->lines as $l)<tr><td style="{{ $td }}">{{ $l->item->name }}@if($l->reject_reason)<br><span class="small" style="color:#b91c1c">Rejected: {{ $l->reject_reason }}</span>@endif</td>
    <td style="{{ $td }};text-align:right">{{ (float) $l->qty_delivered }} {{ $l->item->unit }}</td><td style="{{ $td }};text-align:right">{{ (float) $l->qty_rejected ?: '—' }}</td><td style="{{ $td }};text-align:right"><b>{{ (float) $l->qty_accepted }}</b></td>
    <td style="{{ $td }};text-align:right">{{ $grn->isForeign() ? number_format((float) $l->fx_unit_rate, 4) : number_format((float) $l->rate, 2) }}@if($grn->isForeign())<br><span class="small">{{ number_format((float) $l->rate, 2) }} {{ $home }}</span>@endif</td><td style="{{ $td }};text-align:right">{{ (float) $l->tax_rate }}</td><td style="{{ $td }};text-align:right">{{ $grn->isForeign() ? number_format((float) $l->fx_amount, 2) : number_format((float) $l->amount, 2) }}@if($grn->isForeign())<br><span class="small">{{ number_format((float) $l->amount, 2) }} {{ $home }}</span>@endif</td></tr>@endforeach
  <tr><td colspan="6" style="{{ $td }};text-align:right">Subtotal · {{ $grn->isForeign() ? $grn->currency : $home }}</td><td style="{{ $td }};text-align:right">{{ number_format($grn->isForeign() ? (float) $grn->fx_subtotal : (float) $grn->subtotal, 2) }}@if($grn->isForeign())<br><span class="small">{{ number_format($grn->subtotal, 2) }} {{ $home }}</span>@endif</td></tr>
  <tr><td colspan="6" style="{{ $td }};text-align:right">Tax · {{ $grn->isForeign() ? $grn->currency : $home }}</td><td style="{{ $td }};text-align:right">{{ number_format($grn->isForeign() ? (float) $grn->fx_tax : (float) $grn->tax, 2) }}@if($grn->isForeign())<br><span class="small">{{ number_format($grn->tax, 2) }} {{ $home }}</span>@endif</td></tr>
  <tr><td colspan="6" style="{{ $td }};text-align:right"><b>Accepted value · {{ $grn->isForeign() ? $grn->currency : $home }}</b></td><td style="{{ $td }};text-align:right"><b>{{ $grn->isForeign() ? number_format((float) $grn->fx_total, 2) : money($grn->total) }}</b>@if($grn->isForeign())<br><span class="small"><b>{{ money($grn->total) }}</b></span>@endif</td></tr>
  @if((float) $grn->rejected_value)<tr><td colspan="6" style="{{ $td }};text-align:right;color:#b91c1c">Rejected &amp; returned (before tax) · {{ $grn->isForeign() ? $grn->currency : $home }}</td><td style="{{ $td }};text-align:right;color:#b91c1c">{{ number_format($grn->isForeign() ? (float) $grn->fx_rejected_value : (float) $grn->rejected_value, 2) }}@if($grn->isForeign())<br><span class="small">{{ number_format($grn->rejected_value, 2) }} {{ $home }}</span>@endif</td></tr>@endif
</table>
@if($grn->notes)<p class="small">{{ $grn->notes }}</p>@endif
<table style="width:100%;margin-top:3rem;font-size:13px;text-align:center"><tr>
  <td style="width:33%"><div style="border-top:1px solid #0f172a;margin:0 1rem;padding-top:4px">Received &amp; inspected by<br><b>{{ $grn->receiver?->name }}</b></div></td>
  <td style="width:33%"><div style="border-top:1px solid #0f172a;margin:0 1rem;padding-top:4px">Supplier's driver / representative</div></td>
  <td style="width:33%"><div style="border-top:1px solid #0f172a;margin:0 1rem;padding-top:4px">Checked (Cost controller)</div></td>
</tr></table>
@endsection
