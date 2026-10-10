@extends('layouts.app')
@section('title', $segment->exists ? 'Edit Segment' : 'New Segment')
@section('crumbs') <a href="{{ route('crm.index') }}">Marketing</a> / <a href="{{ route('crm.segments.index') }}">Segments</a> / {{ $segment->exists ? $segment->name : 'New' }} @endsection
@section('content')
@php
  $fields = collect(\App\Services\CrmService::FIELDS)->map(fn ($f) => ['label' => $f[0], 'type' => $f[1]]);
  $rules = old('rules', $segment->rules ?: []);
@endphp
<form method="post" action="{{ $segment->exists ? route('crm.segments.update', $segment) : route('crm.segments.store') }}" id="segForm">@csrf @if($segment->exists) @method('PUT') @endif
<div class="grid g-2-1">
  <div>
    <div class="card"><div class="card-head"><h3>Segment</h3></div><div class="card-body form-grid">
      <x-field name="name" label="Name" :value="$segment->name" required placeholder="e.g. Lapsed high-value guests"/>
      <x-field name="description" label="Description" :value="$segment->description" placeholder="What is this audience for?"/>
      <label class="check span-all"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $segment->is_active))> Active (available for campaigns &amp; automations)</label>
    </div></div>
    <div class="card"><div class="card-head"><h3>Rules</h3>
      <div class="seg"><label class="check"><input type="radio" name="match" value="all" @checked(old('match', $segment->match) !== 'any')> Match <b>all</b></label><label class="check"><input type="radio" name="match" value="any" @checked(old('match', $segment->match) === 'any')> Match <b>any</b></label></div>
    </div><div class="card-body">
      <div id="ruleRows"></div>
      <button type="button" class="btn btn-sm" id="addRule"><x-icon name="plus"/> Add rule</button>
      <p class="tiny muted mb-0">No rules = every guest. Text values are case-insensitive; "is one of" takes a comma list (e.g. Ghana, Nigeria). Tags are lower-case words set on the guest profile.</p>
    </div></div>
  </div>
  <div class="card sticky-side"><div class="card-head"><h3><x-icon name="eye"/> Live preview</h3></div><div class="card-body">
    <div style="font-size:2rem;font-weight:800" id="pvCount">…</div><div class="small muted">matching guests</div>
    <p class="small" id="pvDescribe"></p>
    <ul class="bq-list" id="pvReach"></ul>
    <div class="form-section">Top guests by revenue</div>
    <ul class="bq-list" id="pvSample"></ul>
  </div></div>
</div>
<div class="flex" style="justify-content:flex-end;gap:.5rem">
  @if($segment->exists && can_do('crm.manage'))<button type="submit" form="segDelete" class="btn btn-danger" style="margin-right:auto" onclick="return confirm('Delete this segment? If campaigns used it, it is deactivated instead.')"><x-icon name="trash"/> Delete</button>@endif
  <a class="btn btn-ghost" href="{{ $segment->exists ? route('crm.segments.show', $segment) : route('crm.segments.index') }}">Cancel</a>
  <button class="btn btn-primary" id="saveSegment"><x-icon name="check"/> Save segment</button>
</div>
</form>
@if($segment->exists)<form method="post" action="{{ route('crm.segments.destroy', $segment) }}" id="segDelete">@csrf @method('DELETE')</form>@endif
<template id="ruleTpl"><div class="flex rule-row" style="gap:.5rem;margin-bottom:.5rem;align-items:center">
  <select class="input r-field" style="flex:1.3">@foreach($fields as $k => $f)<option value="{{ $k }}">{{ $f['label'] }}</option>@endforeach</select>
  <select class="input r-op" style="flex:1"></select>
  <input class="input r-value" style="flex:1.2" placeholder="value">
  <button type="button" class="btn btn-ghost btn-icon r-del" title="Remove"><x-icon name="x"/></button>
</div></template>
<script>
(function () {
  const FIELDS = @json($fields), OPS = @json(\App\Services\CrmService::OPS), MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  const rows = document.getElementById('ruleRows'), tpl = document.getElementById('ruleTpl'), form = document.getElementById('segForm');
  function reindex() {
    rows.querySelectorAll('.rule-row').forEach((r, i) => {
      r.querySelector('.r-field').name = `rules[${i}][field]`; r.querySelector('.r-op').name = `rules[${i}][op]`;
      const v = r.querySelector('.r-value'); if (v) v.name = `rules[${i}][value]`;
    });
  }
  function setOps(row, op, value) {
    const type = FIELDS[row.querySelector('.r-field').value].type, sel = row.querySelector('.r-op');
    sel.innerHTML = Object.entries(OPS[type]).map(([k, l]) => `<option value="${k}">${l}</option>`).join('');
    if (op && OPS[type][op]) sel.value = op;
    let v = row.querySelector('.r-value');
    const cur = value ?? (v ? v.value : '');
    const needsValue = !(type === 'bool' || (type === 'month' && sel.value !== 'eq'));
    let nv;
    if (type === 'month') { nv = document.createElement('select'); nv.innerHTML = MONTHS.map((m, i) => `<option value="${i + 1}">${m}</option>`).join(''); if (cur) nv.value = cur; }
    else { nv = document.createElement('input'); nv.placeholder = type === 'number' ? 'number' : (type === 'tag' ? 'tag, e.g. vip' : 'value'); nv.value = (type === 'number' && isNaN(parseFloat(cur))) ? '' : cur; if (type === 'number') nv.type = 'number'; }
    nv.className = 'input r-value'; nv.style.flex = '1.2'; nv.style.visibility = needsValue ? 'visible' : 'hidden';
    v.replaceWith(nv); reindex();
  }
  function add(rule) {
    const row = tpl.content.firstElementChild.cloneNode(true);
    rows.appendChild(row);
    if (rule && FIELDS[rule.field]) row.querySelector('.r-field').value = rule.field;
    setOps(row, rule?.op, rule?.value ?? '');
    row.querySelector('.r-field').addEventListener('change', () => { setOps(row, null, ''); preview(); });
    row.querySelector('.r-op').addEventListener('change', () => { setOps(row, row.querySelector('.r-op').value, null); preview(); });
    row.querySelector('.r-del').addEventListener('click', () => { row.remove(); reindex(); preview(); });
    reindex();
  }
  (@json(array_values($rules))).forEach(add);
  document.getElementById('addRule').addEventListener('click', () => { add({field: 'stays_count', op: 'gte', value: ''}); });
  let t;
  function preview() {
    clearTimeout(t);
    t = setTimeout(() => {
      const fd = new FormData(form); fd.delete('_method');
      fetch(@json(route('crm.segments.preview')), {method: 'POST', body: fd, headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}})
        .then(r => r.json()).then(d => {
          document.getElementById('pvCount').textContent = d.count;
          document.getElementById('pvDescribe').textContent = d.describe;
          document.getElementById('pvReach').innerHTML = Object.entries(d.reach).map(([k, n]) => `<li><span>${{email: 'E-mail opted in', sms: 'SMS opted in', whatsapp: 'WhatsApp opted in'}[k]}</span><b>${n}</b></li>`).join('');
          const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));
          document.getElementById('pvSample').innerHTML = d.sample.length ? d.sample.map(g => `<li><span><a href="${g.url}" target="_blank">${esc(g.name)}</a> <span class="tiny muted">${g.stays} stay(s)${g.country ? ' · ' + esc(g.country) : ''}</span></span><b>${esc(g.revenue)}</b></li>`).join('') : '<li class="muted">Nobody matches.</li>';
        }).catch(() => {});
    }, 250);
  }
  form.addEventListener('input', preview); form.addEventListener('change', preview); preview();
})();
</script>
@endsection
