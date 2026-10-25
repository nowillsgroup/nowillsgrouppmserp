@extends('layouts.app')
@section('title', 'New Purchase Order')
@section('crumbs') <a href="{{ route('purchases.index') }}">Purchase Orders</a> / New @endsection
@section('content')
<form method="post" action="{{ route('purchases.store') }}" class="card" id="poForm">@csrf
<div class="card-body form-grid">
  <x-field name="supplier_id" label="Supplier" type="select" :options="$suppliers" required/><x-field name="store_id" label="Deliver to store" type="select" :options="$stores" required/>
  <x-field name="po_date" label="PO date" type="date" :value="old('po_date', bdate()->toDateString())" required/><x-field name="expected_date" label="Expected delivery" type="date"/>
  <div class="field"><label for="poCurrency">Supplier currency</label>
    <select id="poCurrency" name="currency" data-home="{{ $home }}" data-rate-url="{{ $fxRateUrl }}">
      <option value="{{ $home }}" @selected(old('currency', $home) === $home)>{{ $home }} · property currency</option>
      @if($fxEnabled)@foreach($currencies as $currency)@if(strtoupper($currency->code) !== $home)
        <option value="{{ $currency->code }}" @selected(old('currency', $home) === $currency->code)>{{ $currency->label() }}</option>
      @endif @endforeach @endif
    </select>
    <div class="tiny muted">@if($fxEnabled)Foreign PO rates are entered in the supplier's currency and converted at the PO-date Forex Desk board for approval values.@else Foreign-currency PO conversion is switched off under Foreign Guests › Settings › Forex desk.@endif</div>
  </div>
  <x-field name="notes" label="Notes / terms" class="span-all"/>
  <div id="poFxInfo" class="notice notice-info span-all" style="display:none;margin:0"></div>
</div>
<div class="card-body" style="border-top:1px solid var(--c-border)">
<table class="table" id="poLines"><thead><tr><th>Item</th><th style="width:120px">Qty</th><th style="width:150px">Unit rate · <span id="poCurrencyLabel">{{ $home }}</span></th><th style="width:100px">Tax %</th><th class="num" style="width:150px">Amount · <span id="poCurrencyLabel2">{{ $home }}</span></th><th></th></tr></thead><tbody></tbody>
<tfoot><tr><td colspan="4" class="right">Total (incl. tax) · <span id="poCurrencyLabel3">{{ $home }}</span></td><td class="num" id="poTotal">0.00</td><td></td></tr><tr id="poHomeTotalRow" style="display:none"><td colspan="4" class="right muted">Approval value in {{ $home }} at the displayed PO-date board</td><td class="num muted" id="poHomeTotal">0.00</td><td></td></tr></tfoot></table>
<button type="button" class="btn btn-sm btn-soft mt-1" id="addPoRow"><x-icon name="plus" size="sm"/> Add item</button>
<p class="tiny muted mt-1" id="poApprovalHint">Rates default from the supplier's price list (else the last cost). Approval: up to {{ money($limits[0]) }} store · up to {{ money($limits[1]) }} purchasing manager · above that senior management. Lines above the agreed rate need the purchasing manager. For a foreign PO, approval thresholds use the converted property-currency amount.</p>
</div>
<div class="card-foot"><button class="btn btn-primary" id="savePo">Save Purchase Order</button></div>
</form>
<template id="poRow"><tr><td><select class="input input-sm" data-k="item_id" required><option value="">Select</option>@foreach($items as $i)<option value="{{ $i->id }}" data-cost="{{ $i->last_cost }}" data-tax="{{ $i->tax_rate }}">{{ $i->name }} ({{ $i->unit }})</option>@endforeach</select></td>
<td><input class="input input-sm" type="number" step="0.01" min="0.01" data-k="qty" required></td><td><input class="input input-sm" type="number" step="0.0001" min="0" data-k="rate" required></td><td><input class="input input-sm" type="number" step="0.01" min="0" data-k="tax_rate" value="0"></td><td class="num"><span data-line-total>0.00</span><div class="tiny" data-line-home></div><div class="tiny" data-list></div></td>
<td><button type="button" class="btn btn-sm btn-ghost" data-remove-po-row><x-icon name="trash" size="sm"/></button></td></tr></template>
@endsection
@push('scripts')<script>
(() => {
  const form = document.getElementById('poForm'), tb = document.querySelector('#poLines tbody');
  const currency = document.getElementById('poCurrency'), poDate = document.querySelector('[name="po_date"]');
  const fxInfo = document.getElementById('poFxInfo'), save = document.getElementById('savePo');
  const home = currency.dataset.home, prices = @json($prices), tolerance = {{ (float) setting('po_price_tolerance_pct', '2') }};
  let quote = null, requestNo = 0;
  const round = n => Math.round((Number(n) + Number.EPSILON) * 100) / 100;
  const foreign = () => currency.value !== home;
  const lineRate = row => parseFloat(row.querySelector('[data-k="rate"]').value) || 0;
  function reindex() { [...tb.rows].forEach((row, index) => row.querySelectorAll('[data-k]').forEach(el => el.name = `lines[${index}][${el.dataset.k}]`)); }
  function listRate(row) { return (prices[document.querySelector('[name="supplier_id"]').value] || {})[row.querySelector('[data-k="item_id"]').value]; }
  function priceForCurrency(row) {
    const input = row.querySelector('[data-k="rate"]'), homeRate = parseFloat(input.dataset.homeRate) || 0;
    if (!input.dataset.auto) return;
    if (foreign()) input.value = quote && quote.rate > 0 && homeRate > 0 ? (homeRate / quote.rate).toFixed(4) : '';
    else input.value = homeRate > 0 ? homeRate.toFixed(2) : '';
  }
  function flag(row) {
    const agreed = listRate(row), rate = lineRate(row), el = row.querySelector('[data-list]');
    if (agreed === undefined || !rate || (foreign() && !quote)) { el.textContent = ''; return; }
    const localRate = foreign() ? rate * quote.rate : rate;
    const above = localRate > (parseFloat(agreed) * (1 + tolerance / 100) + 0.004);
    el.textContent = above ? `above agreed ${home} ${Number(agreed).toFixed(2)}` : `agreed ${home} ${Number(agreed).toFixed(2)}`;
    el.className = 'tiny ' + (above ? 'text-red' : 'muted');
  }
  function total() {
    let foreignTotal = 0, homeTotal = 0, hasLine = false;
    [...tb.rows].forEach(row => {
      const qty = parseFloat(row.querySelector('[data-k="qty"]').value) || 0;
      const unit = lineRate(row), taxRate = parseFloat(row.querySelector('[data-k="tax_rate"]').value) || 0;
      const foreignSub = round(qty * unit), foreignTax = round(foreignSub * taxRate / 100);
      const localUnit = foreign() && quote ? round(unit * quote.rate) : unit;
      const localSub = round(qty * localUnit), localTax = round(localSub * taxRate / 100);
      foreignTotal += foreignSub + foreignTax; homeTotal += localSub + localTax;
      hasLine = hasLine || (qty > 0 && unit > 0 && !!row.querySelector('[data-k="item_id"]').value);
      row.querySelector('[data-line-total]').textContent = (foreign() ? foreignSub + foreignTax : localSub + localTax).toFixed(2);
      row.querySelector('[data-line-home]').textContent = foreign() && quote ? `${home} ${ (localSub + localTax).toFixed(2) }` : '';
      flag(row);
    });
    document.getElementById('poTotal').textContent = (foreign() ? foreignTotal : homeTotal).toFixed(2);
    document.getElementById('poCurrencyLabel').textContent = currency.value;
    document.getElementById('poCurrencyLabel2').textContent = currency.value;
    document.getElementById('poCurrencyLabel3').textContent = currency.value;
    const localRow = document.getElementById('poHomeTotalRow');
    if (foreign() && quote) { localRow.style.display = ''; document.getElementById('poHomeTotal').textContent = `${home} ${homeTotal.toFixed(2)}`; }
    else localRow.style.display = 'none';
    save.disabled = !hasLine || (foreign() && !quote);
  }
  function fill(row) {
    const select = row.querySelector('[data-k="item_id"]'), input = row.querySelector('[data-k="rate"]');
    const agreed = listRate(row), base = agreed !== undefined ? parseFloat(agreed) : (parseFloat(select.selectedOptions[0]?.dataset.cost) || 0);
    input.dataset.homeRate = base > 0 ? base : '';
    input.dataset.auto = '1';
    row.querySelector('[data-k="tax_rate"]').value = select.selectedOptions[0]?.dataset.tax || 0;
    priceForCurrency(row); flag(row); total();
  }
  function addRow() {
    const row = document.getElementById('poRow').content.firstElementChild.cloneNode(true);
    tb.appendChild(row);
    const select = row.querySelector('[data-k="item_id"]'), rate = row.querySelector('[data-k="rate"]');
    select.addEventListener('change', () => fill(row));
    row.querySelectorAll('input').forEach(input => input.addEventListener('input', () => {
      if (input === rate) input.dataset.auto = '0';
      flag(row); total();
    }));
    row.querySelector('[data-remove-po-row]').addEventListener('click', () => { row.remove(); reindex(); total(); });
    reindex();
  }
  async function loadQuote() {
    const current = ++requestNo;
    if (!foreign()) {
      quote = {rate: 1, basis: 'home', date: poDate.value, stale: false};
      fxInfo.style.display = 'none'; total(); return;
    }
    quote = null; fxInfo.style.display = ''; fxInfo.className = 'notice notice-info span-all';
    fxInfo.textContent = `Loading ${currency.value} Forex Desk rate for ${poDate.value}…`;
    [...tb.rows].forEach(row => priceForCurrency(row)); total();
    const params = new URLSearchParams({currency: currency.value, date: poDate.value});
    try {
      const response = await fetch(currency.dataset.rateUrl + '?' + params.toString(), {headers: {Accept: 'application/json'}, credentials: 'same-origin'});
      const data = await response.json();
      if (current !== requestNo) return;
      if (!response.ok) throw new Error(data.message || 'No Forex Desk rate is available.');
      quote = data;
      fxInfo.className = 'notice notice-info span-all';
      fxInfo.textContent = `1 ${currency.value} = ${Number(data.rate).toFixed(4)} ${home} · ${data.basis} board of ${data.date}${data.stale ? ' (carried forward within the configured window)' : ''}. PO approval value and stock price comparison use the displayed board.`;
      [...tb.rows].forEach(row => priceForCurrency(row)); total();
    } catch (error) {
      if (current !== requestNo) return;
      quote = null; fxInfo.className = 'alert alert-warn span-all'; fxInfo.textContent = error.message;
      [...tb.rows].forEach(row => priceForCurrency(row)); total();
    }
  }
  document.getElementById('addPoRow').addEventListener('click', addRow);
  currency.addEventListener('change', loadQuote); poDate.addEventListener('change', loadQuote);
  document.querySelector('[name="supplier_id"]').addEventListener('change', () => [...tb.rows].forEach(row => { if (row.querySelector('[data-k="item_id"]').value) fill(row); }));
  addRow(); loadQuote();
})();
</script>@endpush
