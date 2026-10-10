@extends('layouts.print')
@section('title', $rfq->rfq_no)
@section('doctitle', 'REQUEST FOR QUOTATION')
@section('docmeta')<div class="small">No. <b>{{ $rfq->rfq_no }}</b> · {{ $rfq->rfq_date->format('d M Y') }}<br>Quote by <b>{{ $rfq->respond_by->format('d M Y') }}</b>@if($rfq->needed_by)<br>Delivery by {{ $rfq->needed_by->format('d M Y') }}@endif</div>@endsection
@section('content')
@php $td = 'padding:6px 8px;border:1px solid #cbd5e1'; @endphp
<p style="margin-top:1rem"><b>To:</b> {{ $invite?->supplier->name ?? '______________________________' }}@if($invite?->supplier->address)<br>{{ $invite->supplier->address }}@endif</p>
<p><b>Subject:</b> {{ $rfq->title }} – deliver to {{ $rfq->store->name }}</p>
<p class="small">Please quote your best prices for the items below. Prices in Ghana cedis (GH₵) excluding tax; state VAT / NHIL / GETFund separately.@if($invite) You may also quote online: <span class="mono">{{ $invite->link() }}</span>@endif</p>
<table style="width:100%;border-collapse:collapse;font-size:13px" id="rfqPrintLines">
  <tr style="background:#f1f5f9"><th style="{{ $td }}">#</th><th style="{{ $td }};text-align:left">Item / specification</th><th style="{{ $td }}">Qty</th><th style="{{ $td }}">Unit</th><th style="{{ $td }};width:16%">Unit price</th><th style="{{ $td }};width:9%">Tax %</th><th style="{{ $td }};width:16%">Amount</th></tr>
  @foreach($rfq->lines as $l)<tr><td style="{{ $td }};text-align:center">{{ $loop->iteration }}</td><td style="{{ $td }}">{{ $l->item->name }}@if($l->specification)<br><span class="small">{{ $l->specification }}</span>@endif</td><td style="{{ $td }};text-align:right">{{ (float) $l->qty }}</td><td style="{{ $td }}">{{ $l->item->unit }}</td><td style="{{ $td }}"></td><td style="{{ $td }}"></td><td style="{{ $td }}"></td></tr>@endforeach
  <tr><td colspan="6" style="{{ $td }};text-align:right"><b>Total</b></td><td style="{{ $td }}"></td></tr>
</table>
<table style="width:100%;margin-top:1rem;font-size:13px"><tr><td>Validity of prices: ____________</td><td>Delivery lead time: ______ days</td><td>Payment terms: ______ days</td></tr></table>
@if($rfq->terms)<p class="small" style="white-space:pre-line;margin-top:1rem"><b>Terms:</b> {{ $rfq->terms }}</p>@endif
<table style="width:100%;margin-top:3rem;font-size:13px;text-align:center"><tr>
  <td style="width:50%"><div style="border-top:1px solid #0f172a;margin:0 2rem;padding-top:4px">Issued by<br><b>{{ $rfq->creator?->name }}</b></div></td>
  <td style="width:50%"><div style="border-top:1px solid #0f172a;margin:0 2rem;padding-top:4px">Supplier's signature, stamp &amp; date</div></td>
</tr></table>
@endsection
