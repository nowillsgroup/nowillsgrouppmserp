@extends('layouts.app')
@section('title', 'New Purchase Order')
@section('crumbs') <a href="{{ route('purchases.index') }}">Purchase Orders</a> / New @endsection
@section('content')
<form method="post" action="{{ route('purchases.store') }}" class="card">@csrf
<div class="card-body form-grid">
  <x-field name="supplier_id" label="Supplier" type="select" :options="$suppliers" required/><x-field name="store_id" label="Deliver to store" type="select" :options="$stores" required/>
  <x-field name="po_date" label="PO date" type="date" :value="bdate()->toDateString()" required/><x-field name="expected_date" label="Expected delivery" type="date"/>
  <x-field name="notes" label="Notes / terms" class="span-all"/>
</div>
<div class="card-body" style="border-top:1px solid var(--c-border)">
<table class="table" id="poLines"><thead><tr><th>Item</th><th style="width:120px">Qty</th><th style="width:130px">Rate</th><th style="width:100px">Tax %</th><th class="num" style="width:130px">Amount</th><th></th></tr></thead><tbody></tbody>
<tfoot><tr><td colspan="4" class="right">Total (incl. tax)</td><td class="num" id="poTotal">0.00</td><td></td></tr></tfoot></table>
<button type="button" class="btn btn-sm btn-soft mt-1" onclick="addRow()"><x-icon name="plus" size="sm"/> Add item</button>
<p class="tiny muted mt-1" id="poApprovalHint">Rates default from the supplier's price list (else the last cost). Approval: up to {{ money($limits[0]) }} store · up to {{ money($limits[1]) }} purchasing manager · above that senior management. Lines above the agreed rate need the purchasing manager.</p>
</div>
<div class="card-foot"><button class="btn btn-primary">Save Purchase Order</button></div>
</form>
<template id="poRow"><tr><td><select class="input input-sm" data-k="item_id" required><option value="">Select</option>@foreach($items as $i)<option value="{{ $i->id }}" data-cost="{{ $i->last_cost }}" data-tax="{{ $i->tax_rate }}">{{ $i->name }} ({{ $i->unit }})</option>@endforeach</select></td>
<td><input class="input input-sm" type="number" step="0.01" min="0.01" data-k="qty" required></td><td><input class="input input-sm" type="number" step="0.01" min="0" data-k="rate" required></td><td><input class="input input-sm" type="number" step="0.01" min="0" data-k="tax_rate" value="0"></td><td class="num" data-amt>0.00<div class="tiny" data-list></div></td>
<td><button type="button" class="btn btn-sm btn-ghost" onclick="this.closest('tr').remove();ri();tot()"><x-icon name="trash" size="sm"/></button></td></tr></template>
@endsection
@push('scripts')<script>
const tb = document.querySelector('#poLines tbody'); function ri(){ [...tb.rows].forEach((r,i)=>r.querySelectorAll('[data-k]').forEach(e=>e.name=`lines[${i}][${e.dataset.k}]`)); }
function tot(){ let g=0; [...tb.rows].forEach(r=>{ const q=+r.querySelector('[data-k=qty]').value||0, rt=+r.querySelector('[data-k=rate]').value||0, tx=+r.querySelector('[data-k=tax_rate]').value||0; const a=q*rt*(1+tx/100); g+=a; r.querySelector('[data-amt]').textContent=a.toFixed(2); }); document.getElementById('poTotal').textContent=g.toFixed(2); }
const TOL = {{ (float) setting('po_price_tolerance_pct', '2') }}, PRICES = @json($prices), supSel = document.querySelector('[name=supplier_id]');
function listRate(r){ const it=r.querySelector('select').value, sp=supSel.value; return (PRICES[sp]||{})[it]; }
function flag(r){ const lr=listRate(r), rate=+r.querySelector('[data-k=rate]').value||0, el=r.querySelector('[data-list]'); if(lr===undefined){ el.textContent=''; return; } el.textContent = rate > (lr*(1+TOL/100)+0.004) ? 'above agreed '+lr.toFixed(2) : 'agreed '+lr.toFixed(2); el.className='tiny '+(rate > (lr*(1+TOL/100)+0.004) ? 'text-red' : 'muted'); }
function fill(r){ const s=r.querySelector('select'), lr=listRate(r); r.querySelector('[data-k=rate]').value = lr!==undefined ? lr : (s.selectedOptions[0].dataset.cost||''); r.querySelector('[data-k=tax_rate]').value=s.selectedOptions[0].dataset.tax||0; flag(r); tot(); }
function addRow(){ const r=document.getElementById('poRow').content.firstElementChild.cloneNode(true); tb.appendChild(r); const s=r.querySelector('select'); s.addEventListener('change',()=>fill(r)); r.querySelectorAll('input').forEach(i=>i.addEventListener('input',()=>{ flag(r); tot(); })); ri(); }
supSel.addEventListener('change',()=>[...tb.rows].forEach(r=>{ if(r.querySelector('select').value) fill(r); }));
addRow();
</script>@endpush
