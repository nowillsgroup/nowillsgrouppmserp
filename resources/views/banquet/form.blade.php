@extends('layouts.app')
@section('title', $event->exists ? 'Edit '.$event->event_no : 'New Banquet Enquiry')
@section('crumbs') <a href="{{ route('banquet.index') }}">Banquet</a> / {{ $event->exists ? $event->event_no : 'New enquiry' }} @endsection
@section('content')
@if(! $event->exists)
<nav class="bq-form-steps" aria-label="Enquiry form sections">
  <a href="#bqEventDetails"><span class="bq-form-step-number">01</span><span><b>Event &amp; client</b><small>Capture the brief</small></span></a>
  <a href="#bqFunctionPlan"><span class="bq-form-step-number">02</span><span><b>Function plan</b><small>Choose venue and services</small></span></a>
  <a href="#bqEstimate"><span class="bq-form-step-number">03</span><span><b>Estimate &amp; save</b><small>Review before saving</small></span></a>
</nav>
@endif
<div class="bq-form-note" role="note"><x-icon name="book"/><p>@if($event->exists)<b>Editing {{ $event->event_no }}.</b> Update the client brief and function plan here; use the event actions to manage holds, confirmation and billing.@else<b>Start with the client brief, then add one or more functions.</b> An enquiry is non-binding. Choose a tentative hold only when you need to reserve the venue until an option date.@endif</p></div>
<form method="post" action="{{ $event->exists ? route('banquet.update', $event) : route('banquet.store') }}" id="eventForm">@csrf @if($event->exists) @method('PUT') @endif
<div class="grid g-2-1 bq-form-layout">
  <div class="bq-form-main">
    <section class="card bq-intake-card" id="bqEventDetails" aria-labelledby="bqEventDetailsTitle">
      <div class="card-head bq-intake-head"><div><span class="bq-section-kicker">STEP 01 · EVENT &amp; CLIENT</span><h2 id="bqEventDetailsTitle">Enquiry details</h2><p>The event name and a reliable client contact are the essentials.</p></div>@if($event->exists){!! $event->statusBadge() !!}@endif</div>
      <div class="card-body bq-intake-body">
        <section class="bq-form-section" aria-labelledby="bqOverviewTitle">
          <div class="bq-form-section-head"><span class="bq-form-section-number">A</span><div><h3 id="bqOverviewTitle">Event overview</h3><p>Name the occasion and choose its category.</p></div></div>
          <div class="form-grid">
            <x-field name="title" label="Event title" :value="$event->title" maxlength="150" required placeholder="e.g. Annual leadership forum"/>
            <x-field name="event_type" label="Event type" type="select" :options="\App\Models\BanquetEvent::TYPES" :value="$event->event_type" required :placeholder="false"/>
          </div>
        </section>

        <section class="bq-form-section" aria-labelledby="bqContactTitle">
          <div class="bq-form-section-head"><span class="bq-form-section-number">B</span><div><h3 id="bqContactTitle">Client contact</h3><p>Who should the sales team follow up with?</p></div></div>
          <div class="form-grid">
            <x-field name="contact_name" label="Contact person" :value="$event->contact_name" maxlength="120" autocomplete="name" required placeholder="Full name"/>
            <x-field name="contact_phone" label="Phone" type="tel" :value="$event->contact_phone" maxlength="30" autocomplete="tel" placeholder="Best contact number"/>
            <x-field name="contact_email" label="E-mail" type="email" :value="$event->contact_email" maxlength="150" autocomplete="email" placeholder="name@company.com"/>
            <x-field name="company_id" label="Company / bill-to" type="select" :options="$companies" :value="$event->company_id" placeholder="Private client" hint="Optional. Choose the organisation to bill if known."/>
          </div>
        </section>

        <section class="bq-form-section" aria-labelledby="bqPlanningTitle">
          <div class="bq-form-section-head"><span class="bq-form-section-number">C</span><div><h3 id="bqPlanningTitle">Sales &amp; planning</h3><p>Keep ownership and accommodation connected to this enquiry.</p></div></div>
          <div class="form-grid">
            <x-field name="source" label="Enquiry source" type="select" :options="\App\Models\BanquetEvent::SOURCES" :value="$event->source" placeholder="Choose a source"/>
            <x-field name="sales_user_id" label="Sales owner" type="select" :options="$salesUsers" :value="$event->sales_user_id" placeholder="Unassigned" hint="The person responsible for follow-up."/>
            <x-field name="room_block_id" label="Linked room block" type="select" :options="$blocks" :value="$event->room_block_id" placeholder="No accommodation linked" hint="Optional group stay / room block."/>
          </div>
        </section>

        <section class="bq-form-section" aria-labelledby="bqBookingTitle">
          <div class="bq-form-section-head"><span class="bq-form-section-number">D</span><div><h3 id="bqBookingTitle">Booking &amp; deposit plan</h3><p>Record the proposed deposit. No payment is taken on this form.</p></div></div>
          <div class="form-grid">
            @unless($event->exists)
            <div class="field bq-status-field"><span class="lbl" id="startStatusLabel">Create this as</span><div class="seg bq-status-options" id="statusSeg" role="radiogroup" aria-labelledby="startStatusLabel" aria-describedby="statusHint">
              <label class="check bq-status-choice"><input type="radio" name="status" value="enquiry" @checked(old('status', 'enquiry') === 'enquiry')><span class="bq-choice-mark"></span><span class="bq-choice-copy"><b>Enquiry</b><small>Non-binding · no venue hold</small></span></label>
              <label class="check bq-status-choice"><input type="radio" name="status" value="tentative" @checked(old('status') === 'tentative')><span class="bq-choice-mark"></span><span class="bq-choice-copy"><b>Tentative hold</b><small>Temporarily reserves the venue</small></span></label>
            </div><span class="hint" id="statusHint">Choose tentative only when the client needs an option on the venue.</span></div>
            <x-field name="option_date" label="Option expires on" type="date" :value="old('option_date')" :min="bdate()->toDateString()" hint="Required for a tentative hold. The venue is released after this date."/>
            @endunless
            <x-field name="deposit_due" label="Deposit requested" type="number" step="0.01" min="0" max="99999999" :value="$event->deposit_due ?: null" hint="Planned amount only. Leave 0 for approved credit clients."/>
            <x-field name="deposit_due_date" label="Deposit due date" type="date" :value="$event->deposit_due_date?->toDateString()"/>
          </div>
        </section>

        <section class="bq-form-section bq-brief-section" aria-labelledby="bqBriefTitle">
          <div class="bq-form-section-head"><span class="bq-form-section-number">E</span><div><h3 id="bqBriefTitle">The event brief</h3><p>Capture what matters to the client and the operations team.</p></div></div>
          <div class="form-grid">
            <x-field name="requirements" label="Client requirements" type="textarea" :value="$event->requirements" rows="3" maxlength="3000" placeholder="Dietary needs, preferred schedule, AV, accessibility…" hint="Shared with teams on the Banquet Event Order (BEO)."/>
            <x-field name="internal_notes" label="Internal notes" type="textarea" :value="$event->internal_notes" rows="3" maxlength="3000" placeholder="Follow-up details for the sales team…" hint="Internal only — not printed on the BEO."/>
          </div>
        </section>
      </div>
    </section>

    <section class="bq-function-area" id="bqFunctionPlan" aria-labelledby="bqFunctionsTitle">
      <div class="bq-functions-heading"><div><span class="bq-section-kicker">STEP 02 · FUNCTION PLAN</span><h2 id="bqFunctionsTitle">Plan the functions</h2><p>Add each venue session, guest count, menu and service extras.</p></div><div class="flex items-center gap-1"><a class="btn btn-sm" href="{{ route('banquet.availability', $event->event_date ? ['date' => $event->event_date->toDateString()] : []) }}" id="findVenue"><x-icon name="search"/> Find a free venue</a><span class="badge badge-slate" id="fnCount" aria-live="polite">1 function</span></div></div>
      <div id="fnList" class="bq-function-list" role="list" aria-label="Event functions"></div>
      <button type="button" class="btn bq-add-function" id="addFn" aria-controls="fnList"><x-icon name="plus"/> Add another function</button>
    </section>
  </div>

  <aside class="bq-form-aside" aria-label="Estimate and save">
    <div class="card sticky-side bq-estimate" id="bqEstimate" aria-labelledby="bqEstimateTitle">
      <div class="card-head"><div><span class="bq-section-kicker">STEP 03 · REVIEW</span><h3 id="bqEstimateTitle">Live estimate</h3><span class="tiny muted">Property currency · planning only</span></div><span class="badge badge-slate">{{ $event->exists ? ucfirst($event->status) : 'Draft' }}</span></div>
      <div class="card-body"><ul class="bq-list" id="estList" aria-live="polite" aria-label="Current event estimate"></ul>
        <p class="bq-estimate-note">F&amp;B uses the guarantee, or expected guests until final numbers are given. Venue rent uses the half-day rate up to {{ rtrim(rtrim(number_format($halfDayHours, 1), '0'), '.') }} hours, then the full-day rate. Final charges are calculated in the billing workflow.</p>
      </div>
    </div>
    <div class="card bq-save-card">
      <div class="card-body"><span class="bq-section-kicker">READY WHEN YOU ARE</span><h3>{{ $event->exists ? 'Save your updates' : 'Save this enquiry' }}</h3><p>Review the client details and function plan before saving. You can update the proposal later.</p>
        <div class="bq-form-actions"><a class="btn" href="{{ $event->exists ? route('banquet.show', $event) : route('banquet.index') }}">Cancel</a><button class="btn btn-primary" id="saveEvent"><x-icon name="check"/> {{ $event->exists ? 'Save changes' : 'Save enquiry' }}</button></div>
      </div>
    </div>
  </aside>
</div>
</form>

<template id="fnTpl"><div class="card fn-card">
  <div class="card-head"><h3 class="fn-title">Function</h3><div class="flex gap-1 items-center"><span class="tiny fn-lock text-amber" hidden><x-icon name="lock"/> guarantee final</span><button type="button" class="btn btn-ghost btn-sm fn-del"><x-icon name="trash"/> Remove</button></div></div>
  <div class="card-body form-grid fn-grid">
    <input type="hidden" data-f="id">
    <div class="field"><label>Name</label><input class="input" data-f="name" maxlength="120" placeholder="Reception, Day 1 plenary"></div>
    <div class="field"><label>Venue <span class="req">*</span></label><select class="input" data-f="banquet_hall_id" required><option value="">— Venue —</option>@foreach($halls as $h)<option value="{{ $h->id }}">{{ $h->name }}</option>@endforeach</select></div>
    <div class="field"><label>Date <span class="req">*</span></label><input type="date" class="input" data-f="date" required></div>
    <div class="field"><label>From – to <span class="req">*</span></label><div class="flex gap-1"><input type="time" class="input" data-f="start_time" required><input type="time" class="input" data-f="end_time" required></div><span class="hint fn-avail" aria-live="polite"></span></div>
    <div class="field"><label>Set-up</label><select class="input" data-f="setup_style">@foreach(\App\Models\BanquetFunction::SETUPS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
    <div class="field"><label>Expected guests <span class="req">*</span></label><input type="number" min="1" max="10000" class="input" data-f="expected_pax" required><span class="hint fn-cap"></span></div>
    <div class="field"><label>Guaranteed guests</label><input type="number" min="1" max="10000" class="input" data-f="guaranteed_pax" placeholder="Final numbers"></div>
    <div class="field"><label>Menu package</label><select class="input" data-f="banquet_package_id"><option value="">— No F&amp;B / custom —</option>@foreach($packages->groupBy('type') as $t => $grp)<optgroup label="{{ ucfirst($t) }}">@foreach($grp as $p)<option value="{{ $p->id }}">{{ $p->name }} ({{ money($p->price_per_pax, false) }})</option>@endforeach</optgroup>@endforeach</select></div>
    <div class="field"><label>Rate per guest</label><input type="number" min="0" max="999999" step="0.01" class="input" data-f="rate_per_pax"></div>
    <div class="field"><label>Drinks package</label><select class="input" data-f="banquet_bar_package_id"><option value="">— No drinks package —</option>@foreach($packages->groupBy('type') as $t => $grp)<optgroup label="{{ ucfirst($t) }}">@foreach($grp as $p)<option value="{{ $p->id }}">{{ $p->name }} ({{ money($p->price_per_pax, false) }})</option>@endforeach</optgroup>@endforeach</select><span class="hint fn-bar"></span></div>
    <div class="field"><label>Drinks per guest</label><input type="number" min="0" max="999999" step="0.01" class="input" data-f="bar_rate_per_pax"><span class="hint">Charged per paying guest, beside the food.</span></div>
    <div class="field"><label>Venue rent</label><input type="number" min="0" max="99999999" step="0.01" class="input" data-f="hall_rate"><span class="hint fn-rent"></span></div>
    <div class="field span-2"><label>Kitchen / set-up notes (BEO)</label><textarea class="input" rows="2" data-f="notes" maxlength="3000"></textarea></div>
  </div>
  <div class="card-body bq-function-diet" style="padding-top:0">
    <div class="flex items-center gap-1"><span class="lbl-small">Dietary &amp; allergies</span><span class="grow"></span><span class="fn-diet-count tiny muted" aria-live="polite">None recorded</span></div>
    <table class="table fn-diets"><caption class="sr-only">Dietary needs for this function</caption><thead><tr><th scope="col" style="min-width:170px">Diet</th><th scope="col" class="num">Covers</th><th scope="col">Dish served</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead><tbody></tbody></table>
    <div class="flex items-center gap-1 mt-1"><button type="button" class="btn btn-sm fn-adddiet"><x-icon name="plus"/> Add dietary need</button><span class="tiny muted">Counts the kitchen cooks to a different plate for — the menu sheet prints them.</span></div>
  </div>
  <div class="card-body bq-function-extras" style="padding-top:0"><div class="bq-fn-items-scroll"><table class="table fn-items"><caption class="sr-only">Optional service and equipment extras for this function</caption><thead><tr><th scope="col" style="min-width:180px">Service / equipment</th><th scope="col">Description</th><th scope="col" class="num">Qty</th><th scope="col" class="num">Price</th><th scope="col" class="num">Tax %</th><th scope="col" class="num">Amount</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead><tbody></tbody></table></div>
    <div class="flex items-center gap-1 mt-1 bq-extra-actions"><button type="button" class="btn btn-sm fn-additem"><x-icon name="plus"/> Add service</button><span class="fn-items-count tiny muted" aria-live="polite">No extras yet</span><span class="grow"></span><span class="small">Function total <b class="fn-total">0.00</b> <span class="tiny muted">+ tax</span></span></div>
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
<template id="dietTpl"><tr class="fn-diet">
  <td><select class="input input-sm" data-d="diet"><option value="">— Diet —</option>@foreach(\App\Models\BanquetFunction::DIETS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></td>
  <td class="num"><input type="number" min="0" max="10000" step="1" class="input input-sm" style="width:70px" data-d="covers"></td>
  <td><input class="input input-sm" maxlength="120" data-d="dish" placeholder="Gluten-free plate"></td>
  <td><button type="button" class="btn btn-ghost btn-icon dt-del" title="Remove dietary need" aria-label="Remove dietary need"><x-icon name="x"/></button></td>
</tr></template>
<script>
(function () {
  const HALLS = @json($hallData), PKGS = @json($pkgData), SVCS = @json($svcData), HALF = {{ $halfDayHours }}, HALLTAX = {{ $hallTax }};
  const BUSY = @json($busy), TURN = {{ $turnaround }};
  const INIT = @json(array_values(old('functions', $functions)));
  const list = document.getElementById('fnList'), fnTpl = document.getElementById('fnTpl').innerHTML, itTpl = document.getElementById('itemTpl').innerHTML, dietTpl = document.getElementById('dietTpl').innerHTML;
  const fmt = v => Number(v).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  const num = el => parseFloat(el.value) || 0;
  const MAX_FUNCTIONS = 30, MAX_ITEMS = 40, MAX_DIETS = 12;
  let nextCardSequence = 0;
  const functionLabels = {name: 'Function name', banquet_hall_id: 'Venue', date: 'Function date', start_time: 'Start time', end_time: 'End time', setup_style: 'Set-up style', expected_pax: 'Expected guests', guaranteed_pax: 'Guaranteed guests', banquet_package_id: 'Menu package', rate_per_pax: 'Rate per guest', banquet_bar_package_id: 'Drinks package', bar_rate_per_pax: 'Drinks per guest', hall_rate: 'Venue rent', notes: 'Kitchen and set-up notes'};
  const itemLabels = {banquet_service_id: 'Service or equipment', description: 'Description', qty: 'Quantity', unit_price: 'Unit price', tax_rate: 'Tax rate'};
  const dietLabels = {diet: 'Diet', covers: 'Covers', dish: 'Dish served'};
  function names(card) {
    const i = [...list.children].indexOf(card);
    card.querySelectorAll('[data-f]').forEach(el => {
      const key = el.dataset.f;
      el.name = `functions[${i}][${key}]`;
      if (key === 'id') return;
      el.id = `bq-function-${i + 1}-${key}`;
      const label = el.closest('.field')?.querySelector('label');
      if (label && el.closest('.field').querySelector('[data-f]') === el) label.htmlFor = el.id;
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
    card.querySelectorAll('.fn-diet').forEach((row, j) => {
      row.querySelectorAll('[data-d]').forEach(el => {
        const key = el.dataset.d;
        el.name = `functions[${i}][dietary][${j}][${key}]`;
        el.id = `bq-function-${i + 1}-diet-${j + 1}-${key}`;
        el.setAttribute('aria-label', `${dietLabels[key] || key}, function ${i + 1}, dietary need ${j + 1}`);
      });
      row.querySelector('.dt-del')?.setAttribute('aria-label', `Remove dietary need ${j + 1} from function ${i + 1}`);
    });
    card.querySelector('.fn-title').textContent = card.querySelector('[data-f=name]').value || 'Function ' + (i + 1);
    card.querySelector('.fn-del')?.setAttribute('aria-label', `Remove function ${i + 1}`);
    card.querySelector('.fn-additem')?.setAttribute('aria-label', `Add service or equipment to function ${i + 1}`);
    card.querySelector('.fn-adddiet')?.setAttribute('aria-label', `Add a dietary need to function ${i + 1}`);
  }
  function renumber() {
    [...list.children].forEach(card => { names(card); card.setAttribute('role', 'listitem'); });
    const count = document.getElementById('fnCount');
    if (count) count.textContent = `${list.children.length} ${list.children.length === 1 ? 'function' : 'functions'}`;
    list.querySelectorAll('.fn-del').forEach(button => { button.hidden = list.children.length < 2; });
    const addFunction = document.getElementById('addFn');
    if (addFunction) {
      addFunction.disabled = list.children.length >= MAX_FUNCTIONS;
      addFunction.title = addFunction.disabled ? `A maximum of ${MAX_FUNCTIONS} functions can be added to one event.` : '';
    }
  }
  /** What a card reads by: the day it happens, then the clock. A card with no date or clock yet sorts last. */
  const clockKey = card => (card.querySelector('[data-f=date]').value || '9999-12-31') + ' '
    + (card.querySelector('[data-f=start_time]').value || '99:99');
  /**
   * The function plan reads down the clock (v10.35): the cards are ordered by day, then start time, then the order they
   * were keyed in — so an evening gala entered first does not sit above the morning ceremony, and the numbering follows
   * the day the kitchen and the floor work to. The cards are moved, not rebuilt, so nothing typed is lost and the focus
   * stays on the field the desk was in.
   */
  function sortFunctions() {
    const cards = [...list.children];
    const order = cards.map(card => ({ card, key: clockKey(card), id: card.querySelector('[data-f=id]').value }))
      .sort((a, b) => {
        if (a.key !== b.key) return a.key < b.key ? -1 : 1;
        // Existing records use their database id, as on the printed sheets; new cards keep their creation order.
        if (a.id && b.id && a.id !== b.id) return Number(a.id) - Number(b.id);
        if (Boolean(a.id) !== Boolean(b.id)) return a.id ? -1 : 1;
        return Number(a.card.dataset.sequence) - Number(b.card.dataset.sequence);
      });
    if (order.every((row, i) => row.card === cards[i])) return;
    const active = document.activeElement;
    const restoreFocus = active && list.contains(active) && active !== document.body;
    order.forEach(row => list.appendChild(row.card));
    renumber(); calc();
    if (restoreFocus) active.focus({preventScroll: true});
  }
  function syncItemLimit(card) {
    const rows = card.querySelectorAll('.fn-item').length;
    const button = card.querySelector('.fn-additem');
    const count = card.querySelector('.fn-items-count');
    if (button) {
      button.disabled = rows >= MAX_ITEMS;
      button.title = button.disabled ? `A maximum of ${MAX_ITEMS} extras can be added to one function.` : '';
    }
    if (count) count.textContent = rows === 0 ? 'No extras yet' : `${rows} / ${MAX_ITEMS} extras`;
  }
  const mins = t => { const [h, m] = String(t || '').split(':').map(Number); return (h * 60 + m) || 0; };
  /** The hold this function would collide with, allowing the turn-around either side. */
  function clash(card) {
    const hall = card.querySelector('[data-f=banquet_hall_id]').value;
    const date = card.querySelector('[data-f=date]').value;
    const start = card.querySelector('[data-f=start_time]').value, end = card.querySelector('[data-f=end_time]').value;
    if (!hall || !date || !start || !end) return null;
    return BUSY.find(b => String(b.hall) === String(hall) && b.date === date
      && mins(b.start) - TURN < mins(end) && mins(b.end) + TURN > mins(start)) || null;
  }
  function hours(card) {
    const s = card.querySelector('[data-f=start_time]').value, e = card.querySelector('[data-f=end_time]').value;
    if (!s || !e) return 0; const [a, b] = s.split(':').map(Number), [c, d] = e.split(':').map(Number); return Math.max(0, (c * 60 + d - a * 60 - b) / 60);
  }
  function addItem(card, it) {
    const tbody = card.querySelector('.fn-items tbody');
    if (!tbody || tbody.children.length >= MAX_ITEMS) { syncItemLimit(card); return null; }
    const t = document.createElement('tbody'); t.innerHTML = itTpl; const row = t.firstElementChild; tbody.appendChild(row);
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
      row.remove(); names(card); syncItemLimit(card); calc(); returnFocus?.focus();
    });
    row.querySelectorAll('input').forEach(el => el.addEventListener('input', calc));
    names(card); syncItemLimit(card); return row;
  }
  function syncDietLimit(card) {
    const rows = card.querySelectorAll('.fn-diet').length;
    const button = card.querySelector('.fn-adddiet');
    const count = card.querySelector('.fn-diet-count');
    if (button) {
      button.disabled = rows >= MAX_DIETS;
      button.title = button.disabled ? `A maximum of ${MAX_DIETS} dietary needs can be added to one function.` : '';
    }
    if (count) {
      const covers = [...card.querySelectorAll('.fn-diet [data-d=covers]')].reduce((sum, el) => sum + (parseFloat(el.value) || 0), 0);
      count.textContent = rows === 0 ? 'None recorded' : `${rows} ${rows === 1 ? 'need' : 'needs'} · ${covers} ${covers === 1 ? 'cover' : 'covers'}`;
      count.classList.toggle('text-red', covers > Math.max(num(card.querySelector('[data-f=expected_pax]')), num(card.querySelector('[data-f=guaranteed_pax]'))));
    }
  }
  function addDiet(card, d) {
    const tbody = card.querySelector('.fn-diets tbody');
    if (!tbody || tbody.children.length >= MAX_DIETS) { syncDietLimit(card); return null; }
    const t = document.createElement('tbody'); t.innerHTML = dietTpl; const row = t.firstElementChild; tbody.appendChild(row);
    if (d) { for (const k of Object.keys(dietLabels)) { const el = row.querySelector(`[data-d=${k}]`); if (el && d[k] !== null && d[k] !== undefined) el.value = d[k]; } }
    row.querySelector('.dt-del').addEventListener('click', () => {
      const returnFocus = row.nextElementSibling?.querySelector('[data-d=diet]')
        || row.previousElementSibling?.querySelector('[data-d=diet]')
        || card.querySelector('.fn-adddiet');
      row.remove(); names(card); syncDietLimit(card); calc(); returnFocus?.focus();
    });
    row.querySelectorAll('input').forEach(el => el.addEventListener('input', () => { syncDietLimit(card); calc(); }));
    row.querySelector('select')?.addEventListener('change', () => { syncDietLimit(card); calc(); });
    names(card); syncDietLimit(card); return row;
  }
  function addFn(f) {
    if (list.children.length >= MAX_FUNCTIONS) return null;
    const t = document.createElement('div'); t.innerHTML = fnTpl; const card = t.firstElementChild; list.appendChild(card);
    const isNew = !f;
    card.dataset.sequence = nextCardSequence++;
    f = f || {};
    if (isNew && list.children.length > 1) { const prev = list.children[list.children.length - 2]; f = {banquet_hall_id: prev.querySelector('[data-f=banquet_hall_id]').value, date: prev.querySelector('[data-f=date]').value, start_time: '09:00', end_time: '17:00', setup_style: 'classroom'}; }
    for (const k of ['id', ...Object.keys(functionLabels)]) { const el = card.querySelector(`[data-f=${k}]`); if (el && f[k] !== null && f[k] !== undefined) el.value = f[k]; }
    if (f.locked) { card.querySelector('.fn-lock').hidden = false; card.querySelector('[data-f=guaranteed_pax]').min = f.guaranteed_pax; }
    (Array.isArray(f.items) ? f.items : []).forEach(it => addItem(card, it));
    (Array.isArray(f.dietary) ? f.dietary : []).forEach(row => addDiet(card, row));
    card.querySelector('.fn-adddiet').addEventListener('click', () => addDiet(card, null)?.querySelector('select')?.focus());
    card.querySelector('.fn-additem').addEventListener('click', () => addItem(card, null)?.querySelector('select').focus());
    card.querySelector('.fn-del').addEventListener('click', () => {
      const returnFocus = card.nextElementSibling?.querySelector('[data-f=name]')
        || card.previousElementSibling?.querySelector('[data-f=name]');
      card.remove(); renumber(); calc();
      (returnFocus || document.getElementById('addFn')).focus();
    });
    card.querySelector('[data-f=banquet_package_id]').addEventListener('change', e => { const p = PKGS[e.target.value]; card.querySelector('[data-f=rate_per_pax]').value = p ? p.p : ''; calc(); });
    card.querySelector('[data-f=banquet_bar_package_id]').addEventListener('change', e => { const p = PKGS[e.target.value]; card.querySelector('[data-f=bar_rate_per_pax]').value = p ? p.p : ''; calc(); });
    card.querySelectorAll('[data-f]').forEach(el => el.addEventListener(el.tagName === 'SELECT' ? 'change' : 'input', () => { names(card); calc(); }));
    // a committed date or start time is the moment the card knows where it belongs in the day
    card.querySelectorAll('[data-f=date], [data-f=start_time]').forEach(el => el.addEventListener('change', sortFunctions));
    renumber(); calc(); sortFunctions(); return card;
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
      const held = clash(card);
      const availHint = card.querySelector('.fn-avail');
      if (availHint) {
        const hasSlot = hallSelect.value && card.querySelector('[data-f=date]').value
          && card.querySelector('[data-f=start_time]').value && card.querySelector('[data-f=end_time]').value;
        if (held) {
          availHint.textContent = `${held.event} holds this venue ${held.start}–${held.end} (${held.status}) — allow ${TURN} minutes to turn the room round.`;
          availHint.classList.remove('text-green');
          availHint.classList.toggle('text-red', held.status === 'definite' || held.status === 'completed');
          availHint.classList.toggle('text-amber', !(held.status === 'definite' || held.status === 'completed'));
        } else if (hasSlot) {
          availHint.textContent = 'Free for this slot.';
          availHint.classList.add('text-green');
          availHint.classList.remove('text-red', 'text-amber');
        } else {
          availHint.textContent = '';
          availHint.classList.remove('text-red', 'text-amber', 'text-green');
        }
      }
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
      const barId = card.querySelector('[data-f=banquet_bar_package_id]').value;
      const barPkg = PKGS[barId];
      const barRateEl = card.querySelector('[data-f=bar_rate_per_pax]');
      barRateEl.placeholder = barPkg ? fmt(barPkg.p) : '0.00';
      const barRate = barRateEl.value !== '' ? num(barRateEl) : (barPkg ? barPkg.p : 0);
      card.querySelector('.fn-bar').textContent = barPkg
        ? (guestsForPackage < barPkg.min ? `${barPkg.n ?? 'Drinks'} needs ${barPkg.min} guests` : (barPkg.n ?? 'Drinks package'))
        : '';
      card.querySelector('.fn-bar').classList.toggle('text-amber', !!barPkg && guestsForPackage < barPkg.min);
      const barNet = barRate * billable;
      const rent = rentEl.value !== '' ? num(rentEl) : defaultRent;
      const foodNet = rate * billable;
      let extrasNet = 0, extrasTax = 0;
      card.querySelectorAll('.fn-item').forEach(row => {
        const amount = num(row.querySelector('[data-i=qty]')) * num(row.querySelector('[data-i=unit_price]'));
        row.querySelector('.it-amt').textContent = amount ? fmt(amount) : '—';
        extrasNet += amount;
        extrasTax += amount * num(row.querySelector('[data-i=tax_rate]')) / 100;
      });
      const functionNet = foodNet + barNet + rent + extrasNet;
      const functionTax = foodNet * (pkg ? pkg.t : 18) / 100 + barNet * (barPkg ? barPkg.t : 18) / 100 + rent * HALLTAX / 100 + extrasTax;
      net += functionNet;
      tax += functionTax;
      card.querySelector('.fn-total').textContent = fmt(functionNet);
      estimates.push({
        name: card.querySelector('[data-f=name]').value || card.querySelector('.fn-title').textContent,
        detail: `${billable || 0} guests${rate ? ` · ${fmt(rate)} food` : ''}${barRate ? ` · ${fmt(barRate)} drinks` : ''}`,
        amount: functionNet,
      });
    });

    const estimateList = document.getElementById('estList');
    estimateList.replaceChildren(...estimates.map(item => estimateLine(item.name, item.detail, item.amount)));
    estimateList.append(estimateLine('Net', '', net), estimateLine('Tax estimate', '', tax));
    estimateList.append(estimateLine('Estimated total', 'Not a bill or payment request', net + tax, true));
  }
  document.getElementById('addFn').addEventListener('click', () => addFn(null)?.querySelector('[data-f=name]').focus());
  (INIT.length ? INIT : [{}]).forEach(addFn);
  const opt = document.getElementById('f_option_date');
  if (opt) {
    const optionField = opt.closest('.field');
    const syncOptionDate = () => {
      const tentative = document.querySelector('input[name=status]:checked')?.value === 'tentative';
      optionField.hidden = !tentative;
      opt.disabled = !tentative;
      opt.required = tentative;
    };
    document.querySelectorAll('input[name=status]').forEach(r => r.addEventListener('change', syncOptionDate));
    syncOptionDate();
  }
})();
</script>
@endsection
