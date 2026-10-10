@extends('public.layout')
@section('title', 'Quotation '.$rfq->rfq_no)
@section('content')
@if(session('error'))<div class="alert alert-error"><x-icon name="alert"/><div>{{ session('error') }}</div></div>@endif
<div class="card mb-2"><div class="card-body flex justify-between items-center wrap gap-2">
  <div><div class="small muted">Request for quotation · {{ $inv->supplier->name }}</div><h2 style="margin:.1rem 0" id="rfqTitle">{{ $rfq->rfq_no }} – {{ $rfq->title }}</h2>
    <div class="text-2">Quote by <b>{{ $rfq->respond_by->format('D d M Y') }}</b>@if($rfq->needed_by) · delivery needed by {{ $rfq->needed_by->format('d M Y') }}@endif</div></div>
  <div>@if($quote)<span class="badge badge-green" id="quoteReceived">Quotation received{{ $quote->revision > 1 ? ' · revision '.$quote->revision : '' }} · GH₵ {{ number_format($quote->total, 2) }}</span>@elseif($inv->declined_at)<span class="badge badge-slate">Declined</span>@endif</div>
</div></div>
@if(! $open)
  <div class="card"><div class="card-body center" style="padding:2.2rem"><h3 id="rfqClosedMsg">{{ $rfq->status === 'awarded' ? 'This request has been awarded. Thank you for taking part.' : ($rfq->status === 'cancelled' ? 'This request for quotation has been withdrawn.' : 'Quotations for this request are closed.') }}</h3>
  <p class="muted">Questions? Call {{ $hotel->phone }} or e-mail {{ $hotel->email }}.</p></div></div>
  @if($quote)<div class="card"><div class="card-head"><h3>Your quotation</h3></div><div class="card-body">@include('procurement._quote-fields', ['editable' => false])</div></div>@endif
@else
<form method="post" action="{{ route('rfq.public.submit', $inv->token) }}" class="card" id="supplierQuote">@csrf
<div class="card-head"><h3>{{ $quote ? 'Revise your quotation' : 'Your quotation' }}</h3><span class="small muted">Prices in Ghana cedis, excluding tax – put VAT, NHIL &amp; GETFund in the tax % (20% if you are VAT-registered)</span></div>
<div class="card-body">@include('procurement._quote-fields', ['editable' => true])</div>
<div class="card-foot flex justify-between wrap gap-1"><button class="btn btn-primary" id="submitQuote">{{ $quote ? 'Submit revised quotation' : 'Submit quotation' }}</button>
  @unless($quote)<button type="button" class="btn btn-ghost" onclick="document.getElementById('declineBox').style.display='block'">We cannot quote this time</button>@endunless</div>
</form>
@unless($quote)
<form method="post" action="{{ route('rfq.public.decline', $inv->token) }}" class="card" id="declineBox" style="display:none">@csrf<div class="card-body flex gap-1 wrap items-end">
  <div class="field" style="flex:1"><label>Reason (optional)</label><input name="reason" maxlength="255" placeholder="e.g. items out of stock"></div><button class="btn btn-danger">Decline to quote</button></div></form>
@endunless
@if($rfq->terms)<div class="card"><div class="card-head"><h3>Terms</h3></div><div class="card-body small" style="white-space:pre-line">{{ $rfq->terms }}</div></div>@endif
@endif
@endsection
