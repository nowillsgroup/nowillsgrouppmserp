@extends('layouts.app')
@section('title', $rfq->exists ? 'Edit '.$rfq->rfq_no : 'New Request for Quotation')
@section('crumbs') <a href="{{ route('rfq.index') }}">RFQs</a> / {{ $rfq->exists ? $rfq->rfq_no : 'New' }} @endsection
@section('content')
<form method="post" action="{{ $rfq->exists ? route('rfq.update', $rfq) : route('rfq.store') }}" id="rfqForm">@csrf
<div class="card"><div class="card-head"><h3>Request</h3></div><div class="card-body form-grid">
  <x-field name="title" label="Title" :value="old('title', $rfq->title)" required placeholder="e.g. Monthly dry stores – October" class="span-2"/>
  <x-field name="store_id" label="Deliver to store" type="select" :options="$stores" :value="old('store_id', $rfq->store_id)" required/>
  <x-field name="respond_by" label="Quotes due by" type="date" :value="old('respond_by', $rfq->respond_by?->toDateString())" required/>
  <x-field name="needed_by" label="Delivery needed by" type="date" :value="old('needed_by', $rfq->needed_by?->toDateString())"/>
  <x-field name="terms" label="Terms for suppliers" type="textarea" :value="old('terms', $rfq->terms ?? 'Prices in GH₵, delivered to the hotel. Quote VAT / NHIL / GETFund as the tax %. Payment 30 days after invoice. Goods failing inspection are returned.')" class="span-all"/>
  <x-field name="notes" label="Internal notes" :value="old('notes', $rfq->notes)" class="span-all"/>
</div></div>
<div class="card"><div class="card-head"><h3>Items</h3></div><div class="card-body">
<table class="table" id="rfqLines"><thead><tr><th>Item</th><th style="width:130px">Quantity</th><th>Specification / brand / pack</th><th></th></tr></thead><tbody></tbody></table>
<button type="button" class="btn btn-sm btn-soft mt-1" onclick="addLine()"><x-icon name="plus" size="sm"/> Add item</button>
</div></div>
<div class="card"><div class="card-head"><h3>Invite suppliers</h3><span class="small muted">Each gets a private link to quote online; suppliers without e-mail get a printed RFQ</span></div><div class="card-body grid g-3" id="rfqSuppliers">
  @foreach($suppliers as $s)<label class="check"><input type="checkbox" name="supplier_ids[]" value="{{ $s->id }}" @checked(in_array($s->id, old('supplier_ids', $invited)))> <span>{{ $s->name }}<span class="tiny muted"> · {{ $s->email ?: 'no e-mail' }}</span></span></label>@endforeach
</div></div>
<div class="card-foot flex gap-1"><button class="btn btn-primary" id="rfqSave">Save draft</button>@unless($rfq->exists)<button class="btn btn-success" name="send_now" value="1" id="rfqSaveSend"><x-icon name="mail"/> Save &amp; send to suppliers</button>@endunless</div>
</form>
<template id="rfqRow"><tr><td><select class="input input-sm" data-k="item_id" required><option value="">Select</option>@foreach($items as $i)<option value="{{ $i->id }}">{{ $i->name }} ({{ $i->unit }})</option>@endforeach</select></td>
<td><input class="input input-sm" type="number" step="0.01" min="0.01" data-k="qty" required></td><td><input class="input input-sm" data-k="specification" maxlength="255"></td>
<td><button type="button" class="btn btn-sm btn-ghost" onclick="this.closest('tr').remove();ri()"><x-icon name="trash" size="sm"/></button></td></tr></template>
@endsection
@push('scripts')<script>
const tb = document.querySelector('#rfqLines tbody');
function ri(){ [...tb.rows].forEach((r,i)=>r.querySelectorAll('[data-k]').forEach(e=>e.name=`lines[${i}][${e.dataset.k}]`)); }
function addLine(v){ const r=document.getElementById('rfqRow').content.firstElementChild.cloneNode(true); tb.appendChild(r); if(v){ r.querySelector('[data-k=item_id]').value=v.item_id; r.querySelector('[data-k=qty]').value=v.qty; r.querySelector('[data-k=specification]').value=v.specification||''; } ri(); }
const init = @json(old('lines', $lines)); (init.length ? init : [null]).forEach(addLine);
</script>@endpush
