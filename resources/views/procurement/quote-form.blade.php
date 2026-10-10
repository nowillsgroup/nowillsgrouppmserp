@extends('layouts.app')
@section('title', 'Quotation – '.$invite->supplier->name)
@section('crumbs') <a href="{{ route('rfq.index') }}">RFQs</a> / <a href="{{ route('rfq.show', $rfq) }}">{{ $rfq->rfq_no }}</a> / Quote @endsection
@section('content')
<div class="alert alert-info"><x-icon name="edit"/><div>Key in the quotation <b>{{ $invite->supplier->name }}</b> sent on paper, by e-mail or by phone. Leave the price blank for items they did not quote.@if($quote) This replaces revision {{ $quote->revision }} ({{ $quote->source === 'portal' ? 'submitted online' : 'keyed in' }}).@endif</div></div>
<form method="post" action="{{ route('rfq.quote.save', [$rfq, $invite]) }}" class="card" id="quoteForm">@csrf
<div class="card-head"><h3>{{ $rfq->rfq_no }} · {{ $rfq->title }}</h3><span class="small muted">quotes due {{ $rfq->respond_by->format('d M Y') }}</span></div>
<div class="card-body">@include('procurement._quote-fields', ['editable' => true])</div>
<div class="card-foot"><button class="btn btn-primary" id="quoteSave">Save quotation</button></div>
</form>
@endsection
