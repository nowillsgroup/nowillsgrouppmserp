@extends('layouts.app')
@section('title', $event->exists ? 'Edit '.$event->event_no : 'New Banquet Enquiry')
@section('crumbs') <a href="{{ route('banquet.index') }}">Banquet</a> / {{ $event->exists ? $event->event_no : 'New' }} @endsection
@section('content')
<form method="post" action="{{ $event->exists ? route('banquet.update', $event) : route('banquet.store') }}" id="eventForm">@csrf @if($event->exists) @method('PUT') @endif
<div class="grid g-2-1">
<div>
  <div class="card"><div class="card-head"><h3>Client &amp; event</h3>@if($event->exists){!! $event->statusBadge() !!}@endif</div>
    <div class="card-body form-grid">
      <x-field name="title" label="Event title" :value="$event->title" maxlength="150" required placeholder="e.g. Mensah–Owusu wedding reception"/>
      <x-field name="event_type" label="Event type" type="select" :options="\App\Models\BanquetEvent::TYPES" :value="$event->event_type" required :placeholder="false"/>
      <x-field name="contact_name" label="Contact person" :value="$event->contact_name" maxlength="120" autocomplete="name" required/>
      <x-field name="contact_phone" label="Phone" type="tel" :value="$event->contact_phone" maxlength="30" autocomplete="tel"/>
      <x-field name="contact_email" label="E-mail" type="email" :value="$event->contact_email" maxlength="150" autocomplete="email"/>
      <x-field name="company_id" label="Company (bill to)" type="select" :options="$companies" :value="$event->company_id" placeholder="— Private client —"/>
      <x-field name="source" label="Source" type="select" :options="\App\Models\BanquetEvent::SOURCES" :value="$event->source" placeholder="—"/>
      <x-field name="sales_user_id" label="Sales person" type="select" :options="$salesUsers" :value="$event->sales_user_id" placeholder="—"/>
      <x-field name="room_block_id" label="Linked room block" type="select" :options="$blocks" :value="$event->room_block_id" placeholder="— No accommodation —"/>
      <x-field name="deposit_due" label="Deposit required" type="number" step="0.01" min="0" max="99999999" :value="$event->deposit_due ?: null" hint="Needed before the booking can go definite. Leave 0 for credit clients."/>
      <x-field name="deposit_due_date" label="Deposit due by" type="date" :value="$event->deposit_due_date?->toDateString()"/>
      @unless($event->exists)
      <div class="field"><span class="lbl" id="startStatusLabel">Start as</span><div class="seg" id="statusSeg" role="radiogroup" aria-labelledby="startStatusLabel" aria-describedby="statusHint">
        <label class="check"><input type="radio" name="status" value="enquiry" @checked(old('status', 'enquiry') === 'enquiry')> Enquiry</label>
        <label class="check"><input type="radio" name="status" value="tentative" @checked(old('status') === 'tentative')> Tentative hold</label>
      </div><span class="hint" id="statusHint">Tentative holds reserve the venue until the option date; enquiries do not.</span></div>
      <x-field name="option_date" label="Option date (hold until)" type="date" :value="old('option_date')" :min="bdate()->toDateString()" hint="Tentative only – the venue is released if not confirmed by then."/>
      @endunless
    </div>
    <div class="card-body form-grid" style="padding-top:0">
      <x-field name="requirements" label="Client requirements (shown on the BEO)" type="textarea" :value="$event->requirements" rows="2" maxlength="3000"/>
      <x-field name="internal_notes" label="Internal notes (not printed)" type="textarea" :value="$event->internal_notes" rows="2" maxlength="3000"/>
    </div>
  </div>

  <div class="bq-functions-heading"><div><h2>Event functions</h2><p>Set the venue, schedule, guest count and services for each part of the event.</p></div><span class="badge badge-slate" id="fnCount" aria-live="polite">1 function</span></div>
  <div id="fnList" class="bq-function-list" role="list" aria-label="Event functions"></div>
  <button type="button" class="btn" id="addFn" aria-controls="fnList"><x-icon name="plus"/> Add another function</button>
</div>
<div>
  <div class="card sticky-side"><div class="card-head"><div><h3>Live estimate</h3><span class="tiny muted">Property currency · not a bill</span></div><span class="badge badge-slate">Draft</span></div>
    <div class="card-body"><ul class="bq-list" id="estList" aria-live="polite" aria-label="Current event estimate"></ul>
      <p class="tiny muted m-0">F&amp;B uses the guarantee (or expected count until final numbers are given), or actual attendance if higher. Venue rent uses the half-day rate up to {{ rtrim(rtrim(number_format($halfDayHours, 1), '0'), '.') }} h, then the full-day rate. Final charges are calculated in the Banquet billing workflow.</p>
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
    <div class="field"><label>Name</label><input class="input" data-f="name" maxlength="120" placeholder="Reception, Day 1 plenary"></div>
    <div class="field"><label>Venue <span class="req">*</span></label><select class="input" data-f="banquet_hall_id" required><option value="">— Venue —</option>@foreach($halls as $h)<option value="{{ $h->id }}">{{ $h->name }}</option>@endforeach</select></div>
    <div class="field"><label>Date <span class="req">*</span></label><input type="date" class="input" data-f="date" required></div>
    <div class="field"><label>From – to <span class="req">*</span></label><div class="flex gap-1"><input type="time" class="input" data-f="start_time" required><input type="time" class="input" data-f="end_time" required></div></div>
    <div class="field"><label>Set-up</label><select class="input" data-f="setup_style">@foreach(\App\Models\BanquetFunction::SETUPS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
    <div class="field"><label>Expected guests <span class="req">*</span></label><input type="number" min="1" max="10000" class="input" data-f="expected_pax" required><span class="hint fn-cap"></span></div>
    <div class="field"><label>Guaranteed guests</label><input type="number" min="1" max="10000" class="input" data-f="guaranteed_pax" placeholder="Final numbers"></div>
    <div class="field"><label>Menu package</label><select class="input" data-f="banquet_package_id"><option value="">— No F&amp;B / custom —</option>@foreach($packages->groupBy('type') as $t => $grp)<optgroup label="{{ ucfirst($t) }}">@foreach($grp as $p)<option value="{{ $p->id }}">{{ $p->name }} ({{ money($p->price_per_pax, false) }})</option>@endforeach</optgroup>@endforeach</select></div>
    <div class="field"><label>Rate per guest</label><input type="number" min="0" max="999999" step="0.01" class="input" data-f="rate_per_pax"></div>
    <div class="field"><label>Venue rent</label><input type="number" min="0" max="99999999" step="0.01" class="input" data-f="hall_rate"><span class="hint fn-rent"></span></div>
    <div class="field span-2"><label>Kitchen / set-up notes (BEO)</label><textarea class="input" rows="2" data-f="notes" maxlength="3000"></textarea></div>
  </div>
  <div class="card-body" style="padding-top:0"><table class="table fn-items"><caption class="sr-only">Optional service and equipment extras for this function</caption><thead><tr><th scope="col" style="min-width:180px">Service / equipment</th><th scope="col">Description</th><th scope="col" class="num">Qty</th><th scope="col" class="num">Price</th><th scope="col" class="num">Tax %</th><th scope="col" class="num">Amount</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead><tbody></tbody></table>
    <div class="flex items-center gap-1 mt-1"><button type="button" class="btn btn-sm fn-additem"><x-icon name="plus"/> Add service</button><span class="grow"></span><span class="small">Function total <b class="fn-total">0.00</b> <span class="tiny muted">+ tax</span></span></div>
  </div>
</div></template>
<template id="itemTpl"><tr class="fn-item">
  <td><select class="input input-sm" data-i="banquet_service_id"><option value="">— Custom —</option>@foreach($services->groupBy('category') as $c => $grp)<optgroup label="{{ config('pms.banquet_service_categories.'.$c, ucfirst($c)) }}">@foreach($grp as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</optgroup>@endforeach</select></td>
  <td><input class="input input-sm" data-i="description" maxlength="150"></td>
  <td class="num"><input type="number" min="0" max="100000" step="0.01" class="input input-sm" style="width:70px" data-i="qty" value="1"></td>
  <td class="num"><input type="number" min="0" max="99999999" step="0.01" class="input input-sm" style="width:100px" data-i="unit_price"></td>
  <td class="num"><input type="number" min="0" max="50" step="0.01" class="input input-sm" style="width:64px" data-i="tax_rate"></td>
  <td class="num it-amt"></td>
  <td><button type="button" class="btn btn-ghost btn-icon it-del" title="Remove service line" aria-label="Remove service line"><x-icon name="x"/></button></td>
</tr></template>
<script>
(function () {
  const HALLS = @json($hallData), PKGS = @json($pkgData), SVCS = @json($svcData), HALF = {{ $halfDayHours }}, HALLTAX = {{ $hallTax }};
  const INIT = @json(array_values(old('functions', $functions)));
  const list = document.getElementById('fnList'), fnTpl = document.getElementById('fnTpl').innerHTML, itTpl = document.getElementById('itemTpl').innerHTML;
  const fmt = v => Number(v).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  const num = el => parseFloat(el.value) || 0;
  const functionLabels = {name: 'Function name', banquet_hall_id: 'Venue', date: 'Function date', start_time: 'Start time', end_time: 'End time', setup_style: 'Set-up style', expected_pax: 'Expected guests', guaranteed_pax: 'Guaranteed guests', banquet_package_id: 'Menu package', rate_per_pax: 'Rate per guest', hall_rate: 'Venue rent', notes: 'Kitchen and set-up notes'};
  const itemLabels = {banquet_service_id: 'Service or equipment', description: 'Description', qty: 'Quantity', unit_price: 'Unit price', tax_rate: 'Tax rate'};
  function names(card) {
    const i = [...list.children].indexOf(card);
    card.querySelectorAll('[data-f]').forEach(el => {
      const key = el.dataset.f;
      el.name = `functions[${i}][${key}]`;
      if (key === 'id') return;
      el.id = `bq-function-${i + 1}-${key}`;
      const label = el.closest('.field')?.querySelector('label');
      if (label && !label.htmlFor) label.htmlFor = el.id;
      el.setAttribute('aria-label', `${functionLabels[key] || key}, function ${i + 1}`);
    });
    card.querySelectorAll('.fn-item').forEach((row, j) => {
      row.querySelectorAll('[data-i]').forEach(el => {
        const key = el.dataset.i;
        el.name = `functions[${i}][items][${j}][${key}]`;
        el.id = `bq-function-${i + 1}-item-${j + 1}-${key}`;
        el.setAttribute('aria-label', `${itemLabels[key] || key}, function ${i + 1}, extra ${j + 1}`);
      });
      row.querySelector('.it-del')?.setAttribute('aria-label', `Remove extra ${j + 1} from function ${i + 1}`);
    });
    card.querySelector('.fn-title').textContent = card.querySelector('[data-f=name]').value || 'Function ' + (i + 1);
    card.querySelector('.fn-del')?.setAttribute('aria-label', `Remove function ${i + 1}`);
    card.querySelector('.fn-additem')?.setAttribute('aria-label', `Add service or equipment to function ${i + 1}`);
  }
  function renumber() {
    [...list.children].forEach(card => { names(card); card.setAttribute('role', 'listitem'); });
    const count = document.getElementById('fnCount');
    if (count) count.textContent = `${list.children.length} ${list.children.length === 1 ? 'function' : 'functions'}`;
    list.querySelectorAll('.fn-del').forEach(button => { button.hidden = list.children.length < 2; });
  }
  function hours(card) {
    const s = card.querySelector('[data-f=start_time]').value, e = card.querySelector('[data-f=end_time]').value;
    if (!s || !e) return 0; const [a, b] = s.split(':').map(Number), [c, d] = e.split(':').map(Number); return Math.max(0, (c * 60 + d - a * 60 - b) / 60);
  }
  function addItem(card, it) {
    const t = document.createElement('tbody'); t.innerHTML = itTpl; const row = t.firstElementChild; card.querySelector('.fn-items tbody').appendChild(row);
    if (it) { for (const k of Object.keys(itemLabels)) { const el = row.querySelector(`[data-i=${k}]`); if (el && it[k] !== null && it[k] !== undefined) el.value = it[k]; } }
    row.querySelector('[data-i=banquet_service_id]').addEventListener('change', e => {
      const s = SVCS[e.target.value]; if (!s) return;
      row.querySelector('[data-i=description]').value = s.n; row.querySelector('[data-i=unit_price]').value = s.p; row.querySelector('[data-i=tax_rate]').value = s.t;
      if (s.u === 'pax') row.querySelector('[data-i=qty]').value = Math.max(num(card.querySelector('[data-f=guaranteed_pax]')), num(card.querySelector('[data-f=expected_pax]'))) || 1;
      calc();
    });
    row.querySelector('.it-del').addEventListener('click', () => {
      const returnFocus = row.nextElementSibling?.querySelector('[data-i=banquet_service_id]')
        || row.previousElementSibling?.querySelector('[data-i=banquet_service_id]')
        || card.querySelector('.fn-additem');
      row.remove(); names(card); calc(); returnFocus?.focus();
    });
    row.querySelectorAll('input').forEach(el => el.addEventListener('input', calc));
    names(card); return row;
  }
  function addFn(f) {
    const t = document.createElement('div'); t.innerHTML = fnTpl; const card = t.firstElementChild; list.appendChild(card);
    f = f || {};
    if (!f.date && list.children.length > 1) { const prev = list.children[list.children.length - 2]; f = {banquet_hall_id: prev.querySelector('[data-f=banquet_hall_id]').value, date: prev.querySelector('[data-f=date]').value, start_time: '09:00', end_time: '17:00', setup_style: 'classroom'}; }
    for (const k of ['id', ...Object.keys(functionLabels)]) { const el = card.querySelector(`[data-f=${k}]`); if (el && f[k] !== null && f[k] !== undefined) el.value = f[k]; }
    if (f.locked) { card.querySelector('.fn-lock').hidden = false; card.querySelector('[data-f=guaranteed_pax]').min = f.guaranteed_pax; }
    (Array.isArray(f.items) ? f.items : []).forEach(it => addItem(card, it));
    card.querySelector('.fn-additem').addEventListener('click', () => addItem(card, null).querySelector('select').focus());
    card.querySelector('.fn-del').addEventListener('click', () => {
      const returnFocus = card.nextElementSibling?.querySelector('[data-f=name]')
        || card.previousElementSibling?.querySelector('[data-f=name]');
      card.remove(); renumber(); calc();
      (returnFocus || document.getElementById('addFn')).focus();
    });
    card.querySelector('[data-f=banquet_package_id]').addEventListener('change', e => { const p = PKGS[e.target.value]; card.querySelector('[data-f=rate_per_pax]').value = p ? p.p : ''; calc(); });
    card.querySelectorAll('[data-f]').forEach(el => el.addEventListener(el.tagName === 'SELECT' ? 'change' : 'input', () => { names(card); calc(); }));
    renumber(); calc(); return card;
  }
  function estimateLine(label, detail, amount, bold = false) {
    const li = document.createElement('li');
    const copy = document.createElement('span');
    copy.append(document.createTextNode(label));
    if (detail) {
      const sub = document.createElement('div');
      sub.className = 'tiny muted';
      sub.textContent = detail;
      copy.appendChild(sub);
    }
    const value = document.createElement('b');
    value.textContent = fmt(amount);
    if (bold) li.className = 'bold';
    li.append(copy, value);
    return li;
  }
  function calc() {
    let net = 0, tax = 0;
    const estimates = [];
    [...list.children].forEach(card => {
      const hallSelect = card.querySelector('[data-f=banquet_hall_id]');
      const hall = HALLS[hallSelect.value], style = card.querySelector('[data-f=setup_style]').value;
      const expectedEl = card.querySelector('[data-f=expected_pax]');
      const guaranteedEl = card.querySelector('[data-f=guaranteed_pax]');
      const expected = num(expectedEl), guaranteedValue = guaranteedEl.value;
      const guaranteed = guaranteedValue === '' ? null : parseFloat(guaranteedValue);
      const billable = guaranteed ?? expected;
      const capacity = hall ? (hall.caps[style] || hall.cap) : 0;
      const capacityWarning = hall && Math.max(expected, billable) > capacity;
      const capHint = card.querySelector('.fn-cap');
      capHint.textContent = hall ? `${capacity} seats in this set-up` : 'Select a venue to check capacity';
      capHint.classList.toggle('text-red', !!capacityWarning);
      const hoursBooked = hours(card);
      const defaultRent = hall ? (hoursBooked > HALF && hall.full > 0 ? hall.full : hall.half) : 0;
      const rentEl = card.querySelector('[data-f=hall_rate]');
      rentEl.placeholder = hall ? fmt(defaultRent) : '';
      card.querySelector('.fn-rent').textContent = hall ? `${hoursBooked > HALF ? 'Full-day' : 'Half-day'} rate${hoursBooked ? ` · ${hoursBooked} hours` : ''}` : '';

      const packageId = card.querySelector('[data-f=banquet_package_id]').value;
      const pkg = PKGS[packageId];
      const rateEl = card.querySelector('[data-f=rate_per_pax]');
      rateEl.placeholder = pkg ? fmt(pkg.p) : '0.00';
      const guestsForPackage = Math.max(expected, billable);
      const belowPackageMinimum = !!pkg && guestsForPackage < pkg.min;
      capHint.classList.toggle('text-amber', belowPackageMinimum && !capacityWarning);
      if (belowPackageMinimum) capHint.textContent += ` · Package minimum ${pkg.min} guests`;
      const rate = rateEl.value !== '' ? num(rateEl) : (pkg ? pkg.p : 0);
      const rent = rentEl.value !== '' ? num(rentEl) : defaultRent;
      const foodNet = rate * billable;
      let extrasNet = 0, extrasTax = 0;
      card.querySelectorAll('.fn-item').forEach(row => {
        const amount = num(row.querySelector('[data-i=qty]')) * num(row.querySelector('[data-i=unit_price]'));
        row.querySelector('.it-amt').textContent = amount ? fmt(amount) : '—';
        extrasNet += amount;
        extrasTax += amount * num(row.querySelector('[data-i=tax_rate]')) / 100;
      });
      const functionNet = foodNet + rent + extrasNet;
      const functionTax = foodNet * (pkg ? pkg.t : 18) / 100 + rent * HALLTAX / 100 + extrasTax;
      net += functionNet;
      tax += functionTax;
      card.querySelector('.fn-total').textContent = fmt(functionNet);
      estimates.push({
        name: card.querySelector('[data-f=name]').value || card.querySelector('.fn-title').textContent,
        detail: `${billable || 0} guests${rate ? ` · ${fmt(rate)} per guest` : ''}`,
        amount: functionNet,
      });
    });

    const estimateList = document.getElementById('estList');
    estimateList.replaceChildren(...estimates.map(item => estimateLine(item.name, item.detail, item.amount)));
    estimateList.append(estimateLine('Net', '', net), estimateLine('Tax estimate', '', tax));
    estimateList.append(estimateLine('Estimated total', 'Not a bill or payment request', net + tax, true));
  }
  document.getElementById('addFn').addEventListener('click', () => addFn(null).querySelector('[data-f=name]').focus());
  (INIT.length ? INIT : [{}]).forEach(addFn);
  const opt = document.getElementById('f_option_date');
  if (opt) {
    const optionField = opt.closest('.field');
    const syncOptionDate = () => {
      const tentative = document.querySelector('input[name=status]:checked')?.value === 'tentative';
      optionField.hidden = !tentative;
      opt.disabled = !tentative;
    };
    document.querySelectorAll('input[name=status]').forEach(r => r.addEventListener('change', syncOptionDate));
    syncOptionDate();
  }
})();
</script>
@endsection
