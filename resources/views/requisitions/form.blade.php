@extends('layouts.app')
@section('title', $r->exists ? 'Edit '.$r->req_no : 'New Requisition')
@section('crumbs') <a href="{{ route('req.index') }}">Requisitions</a> / {{ $r->exists ? $r->req_no : 'New' }} @endsection
@section('content')
<form method="post" action="{{ $r->exists ? route('req.update', $r) : route('req.store') }}" id="reqForm">@csrf @if($r->exists) @method('PUT') @endif
<div class="grid g-2-1">
  <div>
    <div class="card"><div class="card-head"><h3>Request</h3>@if($r->exists)<span class="tiny muted">{{ $r->req_no }} · draft</span>@endif</div>
      <div class="card-body form-grid">
        <x-field name="department_id" label="Department" type="select" :options="$departments->pluck('name', 'id')" :value="$r->department_id" required/>
        <div class="field"><label>Priority</label><div class="seg" id="prioSeg">
          <label class="check"><input type="radio" name="priority" value="normal" @checked(old('priority', $r->priority ?? 'normal') !== 'urgent')> Normal</label>
          <label class="check"><input type="radio" name="priority" value="urgent" @checked(old('priority', $r->priority) === 'urgent')> Urgent</label>
        </div></div>
        <x-field name="from_store_id" label="Issue from" type="select" :options="$stores->pluck('name', 'id')" :value="$r->from_store_id" required :placeholder="false"/>
        <x-field name="to_store_id" label="Deliver to" type="select" :options="$stores->where('type', '!=', 'main')->pluck('name', 'id')" :value="$r->to_store_id" placeholder="— Used by the department (expensed) —" hint="Pick the department's own store (kitchen, bar…) to transfer the stock instead of expensing it."/>
        <x-field name="required_by" label="Needed by" type="date" :value="$r->required_by?->toDateString()" :min="bdate()->toDateString()"/>
        <x-field name="purpose" label="Purpose / notes" :value="$r->purpose" placeholder="e.g. weekly guest-amenity top-up" maxlength="255"/>
      </div>
    </div>
    <div class="card flush"><div class="card-head"><h3>Items</h3><div class="flex gap-1">
      @unless($r->exists)<a id="parFill" class="btn btn-sm" href="#" hidden><x-icon name="refresh"/> Top up to par</a>@endunless
      <button type="button" class="btn btn-sm" id="addLine"><x-icon name="plus"/> Add item</button></div></div>
      @if($filled)<div class="card-body small" style="border-bottom:1px solid var(--c-border);padding:10px 16px">Filled from the store's par levels: par − stock in the store − quantities already on open requisitions. Adjust as needed.</div>@endif
      <div class="card-body table-wrap"><table class="table" id="lineTable">
        <thead><tr><th style="min-width:180px">Item</th><th class="num">Qty</th><th>Unit</th><th class="num">In issuing store</th><th class="num col-to">In dept store</th><th class="num">Value</th><th>Remarks</th><th></th></tr></thead>
        <tbody></tbody>
        <tfoot><tr><td colspan="5" class="num bold">Estimated value <span class="tiny muted">(at last cost)</span></td><td class="num bold" id="reqTotal">0.00</td><td colspan="2"></td></tr></tfoot>
      </table></div>
      @error('lines')<div class="card-body"><span class="err">{{ $message }}</span></div>@enderror
    </div>
  </div>
  <div>
    <div class="card"><div class="card-head"><h3>Department budget</h3><span class="tiny muted">{{ bdate()->format('F Y') }}</span></div><div class="card-body" id="budgetBody"></div></div>
    <div class="card"><div class="card-body small muted">
      <b>What happens next:</b> once submitted, the head of the department approves it (they can cut quantities). The store then issues what it has – a red stock figure means the store can't cover it yet – and you acknowledge receipt on the requisition.
    </div></div>
    <div class="flex gap-1" style="justify-content:flex-end">
      <a class="btn" href="{{ $r->exists ? route('req.show', $r) : route('req.index') }}">Cancel</a>
      <button class="btn" name="action" value="draft" id="saveDraft"><x-icon name="edit"/> Save draft</button>
      <button class="btn btn-primary" name="action" value="submit" id="submitReq"><x-icon name="check"/> Submit for approval</button>
    </div>
  </div>
</div>
</form>

<template id="lineTpl"><tr class="req-line">
  <td><select name="lines[__i__][item_id]" class="input line-item"><option value="">— Item —</option>@foreach($items->groupBy(fn ($i) => $i->category?->name ?? 'Other') as $cat => $grp)<optgroup label="{{ $cat }}">@foreach($grp as $it)<option value="{{ $it->id }}">{{ $it->name }}</option>@endforeach</optgroup>@endforeach</select></td>
  <td class="num"><input type="number" name="lines[__i__][qty]" step="0.01" min="0" class="input input-sm line-qty" style="width:72px"></td>
  <td class="small muted line-unit"></td>
  <td class="num small line-stock"></td>
  <td class="num small muted line-to col-to"></td>
  <td class="num line-value"></td>
  <td><input name="lines[__i__][remarks]" maxlength="190" class="input input-sm line-rem" placeholder="optional"></td>
  <td><button type="button" class="btn btn-ghost btn-icon line-del" title="Remove"><x-icon name="x"/></button></td>
</tr></template>
<script>
(function () {
  const ITEMS = @json($itemData), DEPTS = @json($deptData), PARS = @json($parStores), INIT = @json(array_values(old('lines', $lines)));
  const tb = document.querySelector('#lineTable tbody'), tpl = document.getElementById('lineTpl').innerHTML;
  const deptSel = document.getElementById('f_department_id'), fromSel = document.getElementById('f_from_store_id'), toSel = document.getElementById('f_to_store_id');
  let n = 0, toTouched = {{ $r->exists || request()->has('to_store') ? 'true' : 'false' }};
  const fmt = v => Number(v).toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  const q = v => String(Math.round(v * 100) / 100);
  function addRow(l) {
    const t = document.createElement('tbody'); t.innerHTML = tpl.replace(/__i__/g, n++); const row = t.firstElementChild; tb.appendChild(row);
    if (l) { row.querySelector('.line-item').value = l.item_id || ''; row.querySelector('.line-qty').value = l.qty || ''; row.querySelector('.line-rem').value = l.remarks || ''; }
    refresh(row); return row;
  }
  function refresh(row) {
    const it = ITEMS[row.querySelector('.line-item').value], qty = parseFloat(row.querySelector('.line-qty').value) || 0;
    row.querySelector('.line-unit').textContent = it ? it.u : '';
    const s = it ? (it.s[fromSel.value] || 0) : 0, cell = row.querySelector('.line-stock');
    cell.textContent = it ? q(s) : ''; cell.classList.toggle('text-red', !!it && qty > s); cell.classList.toggle('bold', !!it && qty > s);
    row.querySelector('.line-to').textContent = it && toSel.value ? q(it.s[toSel.value] || 0) : '';
    row.querySelector('.line-value').textContent = it && qty ? fmt(qty * it.c) : '';
  }
  function total() {
    let t = 0; tb.querySelectorAll('tr').forEach(r => { const it = ITEMS[r.querySelector('.line-item').value]; if (it) t += (parseFloat(r.querySelector('.line-qty').value) || 0) * it.c; });
    document.getElementById('reqTotal').textContent = fmt(t); budget(t);
  }
  function budget(t) {
    const el = document.getElementById('budgetBody'), d = DEPTS[deptSel.value];
    if (!d) { el.innerHTML = '<p class="muted small m-0">Choose a department.</p>'; return; }
    const b = d.b;
    if (b.budget === null) { el.innerHTML = '<p class="small m-0">No monthly stores budget set for this department.</p><p class="tiny muted m-0 mt-1">Issued this month ' + fmt(b.issued) + ' · committed ' + fmt(b.committed) + '</p>'; return; }
    const after = b.used + t, over = after > b.budget + 0.005, usedPct = Math.min(100, b.used / b.budget * 100), thisPct = Math.max(0, Math.min(100, after / b.budget * 100) - usedPct);
    el.innerHTML = '<div class="budget-bar"><span class="bb-used" style="width:' + usedPct + '%"></span><span class="bb-this' + (over ? ' over' : '') + '" style="width:' + thisPct + '%"></span></div>'
      + '<ul class="list"><li><span>Monthly budget</span><b>' + fmt(b.budget) + '</b></li><li><span>Issued this month</span><b>' + fmt(b.issued) + '</b></li><li><span>Committed (approved, not issued)</span><b>' + fmt(b.committed) + '</b></li>'
      + '<li><span>This requisition</span><b>' + fmt(t) + '</b></li><li><span>' + (over ? 'Over budget by' : 'Left after this') + '</span><b class="' + (over ? 'text-red' : 'text-green') + '">' + fmt(Math.abs(b.budget - after)) + '</b></li></ul>'
      + (over ? '<p class="tiny text-red m-0">The approver will have to approve it over budget, with a reason.</p>' : '');
  }
  function all() { tb.querySelectorAll('tr').forEach(refresh); total(); document.getElementById('lineTable').classList.toggle('no-to', !toSel.value); par(); }
  function par() {
    const a = document.getElementById('parFill'); if (!a) return;
    const ok = toSel.value && PARS.includes(parseInt(toSel.value, 10)); a.hidden = !ok;
    a.href = '?department=' + deptSel.value + '&to_store=' + toSel.value + '&from_store=' + fromSel.value + '&fill=1';
  }
  deptSel.addEventListener('change', () => { const d = DEPTS[deptSel.value]; if (d && !toTouched) toSel.value = d.store || ''; all(); });
  toSel.addEventListener('change', () => { toTouched = true; all(); });
  fromSel.addEventListener('change', all);
  tb.addEventListener('input', e => { const row = e.target.closest('tr'); if (row) { refresh(row); total(); } });
  tb.addEventListener('change', e => { const row = e.target.closest('tr'); if (row) { refresh(row); total(); } });
  tb.addEventListener('click', e => { const b = e.target.closest('.line-del'); if (!b) return; if (tb.children.length > 1) b.closest('tr').remove(); else { const r = b.closest('tr'); r.querySelector('.line-item').value = ''; r.querySelector('.line-qty').value = ''; refresh(r); } total(); });
  document.getElementById('addLine').addEventListener('click', () => addRow(null).querySelector('.line-item').focus());
  (INIT.length ? INIT : [null]).forEach(addRow);
  all();
})();
</script>
@endsection
