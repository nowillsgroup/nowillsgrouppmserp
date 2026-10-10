@extends('layouts.app')
@section('title', 'Stock Issue / Transfer / Adjustment')
@section('crumbs') <a href="{{ route('inventory.index') }}">Inventory</a> / Movement @endsection
@section('content')
<form method="post" action="{{ route('inventory.movement.store') }}" class="card">@csrf
<div class="card-body form-grid">
  <x-field name="type" label="Transaction" type="select" :options="['issue'=>'Issue to department','transfer'=>'Store transfer','adjustment'=>'Stock adjustment','wastage'=>'Wastage / breakage','opening'=>'Opening stock']" :placeholder="false"/>
  <x-field name="store_id" label="From store" type="select" :options="$stores" required/>
  <x-field name="to_store_id" label="To store (transfer)" type="select" :options="$stores"/>
  <x-field name="adjust_sign" label="Adjustment direction" type="select" :options="['minus'=>'Decrease (−)','plus'=>'Increase (+)']" :placeholder="false"/>
  <x-field name="date" label="Date" type="date" :value="bdate()->toDateString()" required/>
  <x-field name="department" label="Department" type="select" :options="$departments" hint="Issues are expensed to the department's cost account. For planned needs use a requisition."/>
  <x-field name="remarks" label="Remarks" class="span-2"/>
</div>
<div class="card-body" style="border-top:1px solid var(--c-border)">
  <table class="table" id="mvLines"><thead><tr><th>Item</th><th style="width:160px">Qty</th><th></th></tr></thead><tbody></tbody></table>
  <button type="button" class="btn btn-sm btn-soft mt-1" onclick="addRow()"><x-icon name="plus" size="sm"/> Add item</button>
</div>
<div class="card-foot"><button class="btn btn-primary">Post Transaction</button></div>
</form>
<template id="mvRow"><tr><td><select class="input input-sm" data-k="item_id" required><option value="">Select item</option>@foreach($items as $i)<option value="{{ $i->id }}">{{ $i->name }} ({{ $i->unit }})</option>@endforeach</select></td><td><input class="input input-sm" type="number" step="0.01" min="0.01" data-k="qty" required></td><td><button type="button" class="btn btn-sm btn-ghost" onclick="this.closest('tr').remove();ri()"><x-icon name="trash" size="sm"/></button></td></tr></template>
@endsection
@push('scripts')<script>
const tb = document.querySelector('#mvLines tbody'); function ri(){ [...tb.rows].forEach((r,i)=>r.querySelectorAll('[data-k]').forEach(e=>e.name=`lines[${i}][${e.dataset.k}]`)); }
function addRow(){ tb.appendChild(document.getElementById('mvRow').content.firstElementChild.cloneNode(true)); ri(); } addRow();
</script>@endpush
