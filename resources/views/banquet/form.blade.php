@extends('layouts.app')
@section('title', $event->exists ? 'Edit '.$event->event_no : 'New Banquet Enquiry')
@section('crumbs') <a href="{{ route('banquet.index') }}">Banquet</a> / {{ $event->exists ? $event->event_no : 'New' }} @endsection
@section('content')
<form method="post" action="{{ $event->exists ? route('banquet.update', $event) : route('banquet.store') }}" id="eventForm">@csrf @if($event->exists) @method('PUT') @endif
<div class="grid g-2-1">
<div>
  <div class="card"><div class="card-head"><h3>Client &amp; event</h3>@if($event->exists){!! $event->statusBadge() !!}@endif</div>
    <div class="card-body form-grid">
      <x-field name="title" label="Event title" :value="$event->title" required placeholder="e.g. Mensah–Owusu wedding reception"/>
      <x-field name="event_type" label="Event type" type="select" :options="\App\Models\BanquetEvent::TYPES" :value="$event->event_type" required :placeholder="false"/>
      <x-field name="contact_name" label="Contact person" :value="$event->contact_name" required/>
      <x-field name="contact_phone" label="Phone" :value="$event->contact_phone"/>
      <x-field name="contact_email" label="E-mail" type="email" :value="$event->contact_email"/>
      <x-field name="company_id" label="Company (bill to)" type="select" :options="$companies" :value="$event->company_id" placeholder="— Private client —"/>
      <x-field name="source" label="Source" type="select" :options="\App\Models\BanquetEvent::SOURCES" :value="$event->source" placeholder="—"/>
      <x-field name="sales_user_id" label="Sales person" type="select" :options="$salesUsers" :value="$event->sales_user_id" placeholder="—"/>
      <x-field name="room_block_id" label="Linked room block" type="select" :options="$blocks" :value="$event->room_block_id" placeholder="— No accommodation —"/>
      <x-field name="deposit_due" label="Deposit required" type="number" step="0.01" min="0" :value="$event->deposit_due ?: null" hint="Needed before the booking can go definite. Leave 0 for credit clients."/>
      <x-field name="deposit_due_date" label="Deposit due by" type="date" :value="$event->deposit_due_date?->toDateString()"/>
      @unless($event->exists)
      <div class="field"><label>Start as</label><div class="seg" id="statusSeg">
        <label class="check"><input type="radio" name="status" value="enquiry" @checked(old('status', 'enquiry') === 'enquiry')> Enquiry</label>
        <label class="check"><input type="radio" name="status" value="tentative" @checked(old('status') === 'tentative')> Tentative hold</label>
      </div></div>
      <x-field name="option_date" label="Option date (hold until)" type="date" :value="old('option_date')" :min="bdate()->toDateString()" hint="Tentative only – the venue is released if not confirmed by then."/>
      @endunless
    </div>
    <div class="card-body form-grid" style="padding-top:0">
      <x-field name="requirements" label="Client requirements (shown on the BEO)" type="textarea" :value="$event->requirements" rows="2"/>
      <x-field name="internal_notes" label="Internal notes (not printed)" type="textarea" :value="$event->internal_notes" rows="2"/>
    </div>
  </div>

  <div id="fnList"></div>
  <button type="button" class="btn" id="addFn"><x-icon name="plus"/> Add function</button>
</div>
<div>
  <div class="card sticky-side"><div class="card-head"><h3>Estimate</h3><span class="tiny muted">before billing</span></div>
    <div class="card-body"><ul class="bq-list" id="estList"></ul>
      <p class="tiny muted m-0">F&amp;B is charged on the guarantee (or the expected count until one is given), or on the actual attendance if higher. Venue rent: half-day rate up to {{ rtrim(rtrim(number_format($halfDayHours, 1), '0'), '.') }} h, full-day beyond.</p>
    </div>
  </div>
  <div class="flex gap-1" style="justify-content:flex-end">
    <a class="btn" href="{{ $event->exists ? route('banquet.show', $event) : route('banquet.index') }}">Cancel</a>
    <button class="btn btn-primary" id="saveEvent"><x-icon name="check"/> {{ $event->exists ? 'Save changes' : 'Save' }}</button>
  </div>
</div>
</div>
</form>

<template id="fnTpl"><div class="card fn-card">
  <div class="card-head"><h3 class="fn-title">Function</h3><div class="flex gap-1 items-center"><span class="tiny fn-lock text-amber" hidden><x-icon name="lock"/> guarantee final</span><button type="button" class="btn btn-ghost btn-sm fn-del"><x-icon name="trash"/> Remove</button></div></div>
  <div class="card-body form-grid fn-grid">
    <input type="hidden" data-f="id">
    <div class="field"><label>Name</label><input class="input" data-f="name" maxlength="120" placeholder="e.g. Reception, Day 1 plenary"></div>
    <div class="field"><label>Venue *</label><select class="input" data-f="banquet_hall_id"><option value="">— Venue —</option>@foreach($halls as $h)<option value="{{ $h->id }}">{{ $h->name }}</option>@endforeach</select></div>
    <div class="field"><label>Date *</label><input type="date" class="input" data-f="date"></div>
    <div class="field"><label>From – to *</label><div class="flex gap-1"><input type="time" class="input" data-f="start_time"><input type="time" class="input" data-f="end_time"></div></div>
    <div class="field"><label>Set-up</label><select class="input" data-f="setup_style">@foreach(\App\Models\BanquetFunction::SETUPS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
    <div class="field"><label>Expected pax *</label><input type="number" min="1" class="input" data-f="expected_pax"><span class="hint fn-cap"></span></div>
    <div class="field"><label>Guaranteed pax</label><input type="number" min="1" class="input" data-f="guaranteed_pax" placeholder="final numbers"></div>
    <div class="field"><label>Menu package</label><select class="input" data-f="banquet_package_id"><option value="">— No F&amp;B / custom —</option>@foreach($packages->groupBy('type') as $t => $grp)<optgroup label="{{ ucfirst($t) }}">@foreach($grp as $p)<option value="{{ $p->id }}">{{ $p->name }} ({{ money($p->price_per_pax, false) }})</option>@endforeach</optgroup>@endforeach</select></div>
    <div class="field"><label>Rate per pax</label><input type="number" min="0" step="0.01" class="input" data-f="rate_per_pax"></div>
    <div class="field"><label>Venue rent</label><input type="number" min="0" step="0.01" class="input" data-f="hall_rate"><span class="hint fn-rent"></span></div>
    <div class="field span-2"><label>Kitchen / set-up notes (BEO)</label><textarea class="input" rows="2" data-f="notes" maxlength="3000"></textarea></div>
  </div>
  <div class="card-body" style="padding-top:0"><table class="table fn-items"><thead><tr><th style="min-width:180px">Service / equipment</th><th>Description</th><th class="num">Qty</th><th class="num">Price</th><th class="num">Tax %</th><th class="num">Amount</th><th></th></tr></thead><tbody></tbody></table>
    <div class="flex items-center gap-1 mt-1"><button type="button" class="btn btn-sm fn-additem"><x-icon name="plus"/> Add service</button><span class="grow"></span><span class="small">Function total <b class="fn-total">0.00</b> <span class="tiny muted">+ tax</span></span></div>
  </div>
</div></template>
<template id="itemTpl"><tr class="fn-item">
  <td><select class="input input-sm" data-i="banquet_service_id"><option value="">— Custom —</option>@foreach($services->groupBy('category') as $c => $grp)<optgroup label="{{ config('pms.banquet_service_categories.'.$c, ucfirst($c)) }}">@foreach($grp as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</optgroup>@endforeach</select></td>
  <td><input class="input input-sm" data-i="description" maxlength="150"></td>
  <td class="num"><input type="number" min="0" step="0.01" class="input input-sm" style="width:70px" data-i="qty" value="1"></td>
  <td class="num"><input type="number" min="0" step="0.01" class="input input-sm" style="width:100px" data-i="unit_price"></td>
  <td class="num"><input type="number" min="0" step="0.01" class="input input-sm" style="width:64px" data-i="tax_rate"></td>
  <td class="num it-amt"></td>
  <td><button type="button" class="btn btn-ghost btn-icon it-del" title="Remove"><x-icon name="x"/></button></td>
</tr></template>
<script>
(function () {
  const HALLS = @json($hallData), PKGS = @json($pkgData), SVCS = @json($svcData), HALF = {{ $halfDayHours }}, HALLTAX = {{ $hallTax }};
  const INIT = @json(array_values(old('functions', $functions)));
  const list = document.getElementById('fnList'), fnTpl = document.getElementById('fnTpl').innerHTML, itTpl = document.getElementById('itemTpl').innerHTML;
  const fmt = v => Number(v).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  const num = el => parseFloat(el.value) || 0;
  let seq = 0;
  function names(card) {
    const i = [...list.children].indexOf(card);
    card.querySelectorAll('[data-f]').forEach(el => el.name = `functions[${i}][${el.dataset.f}]`);
    card.querySelectorAll('.fn-item').forEach((row, j) => row.querySelectorAll('[data-i]').forEach(el => el.name = `functions[${i}][items][${j}][${el.dataset.i}]`));
    card.querySelector('.fn-title').textContent = (card.querySelector('[data-f=name]').value || 'Function ' + (i + 1));
  }
  function renumber() { [...list.children].forEach(names); list.querySelectorAll('.fn-del').forEach(b => b.hidden = list.children.length < 2); }
  function hours(card) {
    const s = card.querySelector('[data-f=start_time]').value, e = card.querySelector('[data-f=end_time]').value;
    if (!s || !e) return 0; const [a, b] = s.split(':').map(Number), [c, d] = e.split(':').map(Number); return Math.max(0, (c * 60 + d - a * 60 - b) / 60);
  }
  function addItem(card, it) {
    const t = document.createElement('tbody'); t.innerHTML = itTpl; const row = t.firstElementChild; card.querySelector('.fn-items tbody').appendChild(row);
    if (it) { for (const k in it) { const el = row.querySelector(`[data-i=${k}]`); if (el && it[k] !== null) el.value = it[k]; } }
    row.querySelector('[data-i=banquet_service_id]').addEventListener('change', e => {
      const s = SVCS[e.target.value]; if (!s) return;
      row.querySelector('[data-i=description]').value = s.n; row.querySelector('[data-i=unit_price]').value = s.p; row.querySelector('[data-i=tax_rate]').value = s.t;
      if (s.u === 'pax') row.querySelector('[data-i=qty]').value = Math.max(num(card.querySelector('[data-f=guaranteed_pax]')), num(card.querySelector('[data-f=expected_pax]'))) || 1;
      calc();
    });
    row.querySelector('.it-del').addEventListener('click', () => { row.remove(); names(card); calc(); });
    row.querySelectorAll('input').forEach(el => el.addEventListener('input', calc));
    names(card); return row;
  }
  function addFn(f) {
    const t = document.createElement('div'); t.innerHTML = fnTpl; const card = t.firstElementChild; list.appendChild(card); seq++;
    f = f || {};
    if (!f.date && list.children.length > 1) { const prev = list.children[list.children.length - 2]; f = {banquet_hall_id: prev.querySelector('[data-f=banquet_hall_id]').value, date: prev.querySelector('[data-f=date]').value, start_time: '09:00', end_time: '17:00', setup_style: 'classroom'}; }
    for (const k in f) { const el = card.querySelector(`[data-f=${k}]`); if (el && f[k] !== null && f[k] !== undefined) el.value = f[k]; }
    if (f.locked) { card.querySelector('.fn-lock').hidden = false; card.querySelector('[data-f=guaranteed_pax]').min = f.guaranteed_pax; }
    (f.items || []).forEach(it => addItem(card, it));
    card.querySelector('.fn-additem').addEventListener('click', () => addItem(card, null).querySelector('select').focus());
    card.querySelector('.fn-del').addEventListener('click', () => { card.remove(); renumber(); calc(); });
    card.querySelector('[data-f=banquet_package_id]').addEventListener('change', e => { const p = PKGS[e.target.value]; card.querySelector('[data-f=rate_per_pax]').value = p ? p.p : ''; calc(); });
    card.querySelectorAll('[data-f]').forEach(el => el.addEventListener(el.tagName === 'SELECT' ? 'change' : 'input', () => { names(card); calc(); }));
    renumber(); calc(); return card;
  }
  function calc() {
    let net = 0, tax = 0; const est = [];
    [...list.children].forEach((card, i) => {
      const h = HALLS[card.querySelector('[data-f=banquet_hall_id]').value], style = card.querySelector('[data-f=setup_style]').value;
      const exp = num(card.querySelector('[data-f=expected_pax]')), gua = card.querySelector('[data-f=guaranteed_pax]').value, pax = gua !== '' ? parseFloat(gua) : exp;
      const cap = h ? (h.caps[style] || h.cap) : 0, capEl = card.querySelector('.fn-cap');
      capEl.textContent = h ? `seats ${cap} this way` : ''; capEl.classList.toggle('text-red', !!h && Math.max(exp, pax) > cap);
      const hrs = hours(card), auto = h ? (hrs > HALF && h.full > 0 ? h.full : h.half) : 0, rentEl = card.querySelector('[data-f=hall_rate]');
      rentEl.placeholder = h ? fmt(auto) : ''; card.querySelector('.fn-rent').textContent = h ? (hrs > HALF ? 'full-day rate' : 'half-day rate') + (hrs ? ` · ${hrs} h` : '') : '';
      const pk = PKGS[card.querySelector('[data-f=banquet_package_id]').value], rateEl = card.querySelector('[data-f=rate_per_pax]');
      rateEl.placeholder = pk ? fmt(pk.p) : '0.00';
      const rate = rateEl.value !== '' ? num(rateEl) : (pk ? pk.p : 0), rent = rentEl.value !== '' ? num(rentEl) : auto;
      const fnb = rate * pax; let ex = 0, exTax = 0;
      card.querySelectorAll('.fn-item').forEach(row => { const a = num(row.querySelector('[data-i=qty]')) * num(row.querySelector('[data-i=unit_price]')); row.querySelector('.it-amt').textContent = a ? fmt(a) : ''; ex += a; exTax += a * num(row.querySelector('[data-i=tax_rate]')) / 100; });
      const fnet = fnb + rent + ex; net += fnet; tax += fnb * (pk ? pk.t : 18) / 100 + rent * HALLTAX / 100 + exTax;
      card.querySelector('.fn-total').textContent = fmt(fnet);
      est.push(`<li><span>${card.querySelector('.fn-title').textContent}<div class="tiny muted">${pax || 0} pax${rate ? ' × ' + fmt(rate) : ''}</div></span><b>${fmt(fnet)}</b></li>`);
    });
    document.getElementById('estList').innerHTML = est.join('') + `<li><span>Net</span><b>${fmt(net)}</b></li><li><span>Tax</span><b>${fmt(tax)}</b></li><li class="bold"><span>Total</span><b id="estTotal">${fmt(net + tax)}</b></li>`;
  }
  document.getElementById('addFn').addEventListener('click', () => addFn(null).querySelector('[data-f=name]').focus());
  (INIT.length ? INIT : [{}]).forEach(addFn);
  const opt = document.getElementById('f_option_date');
  if (opt) { const sync = () => { const t = document.querySelector('input[name=status]:checked'); opt.closest('.field').style.opacity = t && t.value === 'tentative' ? 1 : .45; }; document.querySelectorAll('input[name=status]').forEach(r => r.addEventListener('change', sync)); sync(); }
})();
</script>
@endsection
