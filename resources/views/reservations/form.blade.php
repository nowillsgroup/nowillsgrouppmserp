@extends('layouts.app')
@php $editing = $reservation->exists; @endphp
@section('title', $editing ? 'Edit Reservation '.$reservation->reservation_no : 'New Reservation')
@section('crumbs') <a href="{{ route('reservations.index') }}">Reservations</a> / {{ $editing ? 'Edit' : 'New' }} @endsection
@section('content')
<form method="post" action="{{ $editing ? route('reservations.update', $reservation) : route('reservations.store') }}" id="resForm">
  @csrf @if($editing) @method('PUT') @endif
  <div class="grid g-3-1">
    <div>
      <div class="card">
        <div class="card-head"><h3>Stay Details</h3></div>
        <div class="card-body form-grid">
          @if($editing)
            {{-- The stay type is chosen when the booking is taken; an amendment keeps it. --}}
            <div class="field span-2" id="stayTypeField">
              <label>Stay</label>
              <div class="hint" id="stayTypeFixed">@if($reservation->isDayUse())Day use · {{ $reservation->rooms->first()?->dayUseHoursLabel() ?? 'hours' }}@else Night @endif</div>
            </div>
          @else
            <div class="field span-2" id="stayTypeField">
              <label id="stayTypeLabel">Stay <span class="req">*</span></label>
              <div class="seg" role="radiogroup" aria-labelledby="stayTypeLabel">
                <label class="check"><input type="radio" name="stay_type" value="overnight" id="stayTypeNight" @checked(old('stay_type', 'overnight') === 'overnight')> Night</label>
                <label class="check"><input type="radio" name="stay_type" value="day_use" id="stayTypeDay" @checked(old('stay_type') === 'day_use')> Day use</label>
              </div>
              @error('stay_type')<span class="err">{{ $message }}</span>@enderror
            </div>
            {{-- A day use is sold by the hour: the hours bought and what an hour costs.
                 The night count does not price a day use. --}}
            <div class="span-all" id="dayUsePanel" hidden>
              <div class="form-grid">
                <div class="field">
                  <label for="f_day_use_hours">Hours <span class="req">*</span></label>
                  <select name="day_use_hours" id="f_day_use_hours" class="@error('day_use_hours') is-invalid @enderror">
                    @foreach([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12] as $hours)
                      <option value="{{ $hours }}" @selected((int) old('day_use_hours', 4) === $hours)>{{ $hours }} {{ $hours === 1 ? 'hour' : 'hours' }}</option>
                    @endforeach
                  </select>
                  @error('day_use_hours')<span class="err">{{ $message }}</span>@enderror
                </div>
                <div class="field">
                  <label for="f_day_use_rate">Rate / hour <span class="req">*</span></label>
                  <input type="number" step="0.01" min="0" id="f_day_use_rate" name="day_use_rate" value="{{ old('day_use_rate') }}" class="@error('day_use_rate') is-invalid @enderror" aria-describedby="dayUseTotal">
                  @error('day_use_rate')<span class="err">{{ $message }}</span>@enderror
                </div>
                <p class="hint" id="dayUseTotal" aria-live="polite">Charged once for the hours bought.</p>
              </div>
            </div>
          @endif
          <x-field name="arrival_date" label="Arrival" type="date" :value="$reservation->arrival_date?->toDateString()" required/>
          <x-field name="departure_date" label="Departure" type="date" :value="$reservation->departure_date?->toDateString()" required/>
          <div class="field"><label>Nights</label><input id="nights" readonly></div>
          <x-field name="eta" label="ETA" type="time" :value="$reservation->eta ? substr($reservation->eta,0,5) : ''"/>
          <x-field name="booking_type" label="Booking type" type="select" :options="['individual'=>'Individual','group'=>'Group']" :value="$reservation->booking_type" :placeholder="false"/>
          <x-field name="group_name" label="Group name" :value="$reservation->group_name"/>
          <x-field name="status" label="Status" type="select" :options="['confirmed'=>'Confirmed','tentative'=>'Tentative']" :value="$reservation->status" :placeholder="false"/>
          <x-field name="business_source_id" label="Business source" type="select" :options="$sources" :value="$reservation->business_source_id"/>
          <div class="span-all" style="display:grid;grid-template-columns:minmax(0,1fr) auto;gap:.5rem;align-items:end">
            <x-field name="company_id" label="Company / Travel agent" type="select" :options="$companies" :value="$reservation->company_id"/>
            {{-- The booking desk can open the account it needs, exactly as the walk-in desk does. --}}
            @if(can_do('credit.manage'))<button type="button" class="btn btn-sm btn-ghost" id="resCompanyCreate" data-modal="resCompanyCreateModal" style="margin-bottom:.35rem"><x-icon name="plus" size="sm"/> Create City Ledger / Company</button>@endif
          </div>
          <p class="hint span-all" id="resCompanyStatus" aria-live="polite" hidden></p>
          <x-field name="billing_instruction" label="Bill to" type="select" :options="['guest'=>'Guest','company'=>'Company (City Ledger)']" :value="$reservation->billing_instruction ?? 'guest'" :placeholder="false"/>
          <x-field name="ota_reference" label="OTA / Voucher ref." :value="$reservation->ota_reference"/>
          <x-field name="purpose" label="Purpose of visit" type="select" :options="['Leisure'=>'Leisure','Business'=>'Business','Conference'=>'Conference','Wedding'=>'Wedding','Medical'=>'Medical','Other'=>'Other']" :value="$reservation->purpose"/>
        </div>
      </div>

      <div class="card">
        @php $rc = $editing ? $reservation->rateContract : null; @endphp
        <div id="contractInfo" class="alert alert-info {{ $rc ? '' : 'hidden' }}" style="margin:0;border-radius:0">@if($rc)<b>Contract {{ $rc->code }}</b> – {{ $rc->name }} · {{ $rc->basisLabel() }} · {{ $rc->meal_plan }}. Rates saved on this booking are kept; change the room type or dates to re-price.@endif</div>
        <div class="card-head"><h3>Rooms</h3><button type="button" class="btn btn-sm btn-soft" onclick="addLine()"><x-icon name="plus" size="sm"/> Add room</button></div>
        @if($editing)<div class="alert alert-info" style="margin:0;border-radius:0">To remove a booked room, <a href="{{ route('reservations.show', $reservation) }}">open the reservation</a> and use <b>Cancel room</b>. This applies the cancellation policy and updates the booking channel.</div>@endif
        <div class="card-body table-wrap" style="padding:0">
          <table class="table" id="linesTable">
            <thead><tr><th style="min-width:170px">Room type</th><th style="min-width:170px">Rate plan</th><th style="min-width:120px">Room no.</th><th>Adults</th><th>Child</th><th style="min-width:110px" id="rateColHead">Rate / night</th><th class="num">Total</th><th></th></tr></thead>
            <tbody></tbody>
            <tfoot><tr><td colspan="6" class="right">Estimated room total (excl. tax)</td><td class="num" id="grandTotal">0.00</td><td></td></tr></tfoot>
          </table>
        </div>
      </div>

      <div class="card">
        <div class="card-head"><h3>Guest</h3></div>
        {{-- Same title picker as the walk-in desk: a booking can need a title the list does not carry. --}}
        <div class="card-body">@include('reservations._guest', ['allowCustomGuestTitle' => true])</div>
      </div>
    </div>

    <div>
      <div class="card">
        <div class="card-head"><h3>Billing</h3></div>
        <div class="card-body">
          <x-field name="discount_pct" label="Discount %" type="number" step="0.01" min="0" max="100" :value="$reservation->discount_pct ?? 0"/>
          @unless($editing)
          <div class="form-section">Advance payment</div>
          <div id="reservationAdvanceSingle">
            <x-field name="advance" label="Amount" type="number" step="0.01" min="0" value="0"/>
            <div class="mt-1"></div>
            <x-field name="advance_mode" label="Mode" type="select" :options="$paymentModes" data-payment-mode/>
            <div class="mt-1"></div>
            <x-payment-momo-fields mode-name="advance_mode"/>
            <div class="mt-1"></div>
            <x-field name="advance_ref" label="Reference / Txn ID"/>
            <x-cheque-fields/>
          </div>
          <x-payment-split-tender widget-id="reservationAdvanceSplit" single-id="reservationAdvanceSingle" :modes="$paymentModes" label="deposit"/>
          @endunless
        </div>
      </div>
      <div class="card">
        <div class="card-head"><h3>Special Requests</h3></div>
        <div class="card-body">
          <x-field name="special_request" type="textarea" :value="$reservation->special_request" placeholder="Early check-in, airport pickup, extra bed, dietary needs…"/>
          @if($editing)<div class="mt-1"></div><x-field name="change_reason" label="Amendment reason" placeholder="Optional: guest request, rate correction, date change…"/>@endif
        </div>
      </div>
      <button class="btn btn-primary btn-lg btn-block" type="submit"><x-icon name="check"/> {{ $editing ? 'Update Reservation' : 'Save Reservation' }}</button>
      <a href="{{ url()->previous() }}" class="btn btn-ghost btn-block mt-1">Cancel</a>
    </div>
  </div>
</form>

@include('reservations._city-ledger-create', [
  'prefix' => 'resCompanyCreate',
  'action' => route('reservations.company.store'),
])

<template id="lineTpl">
  <tr>
    <td><input type="hidden" data-k="stay_id" value=""><select class="input input-sm" data-k="room_type_id" required><option value="">Select</option>@foreach($roomTypes as $t)<option value="{{ $t->id }}" data-rate="{{ $t->base_rate }}">{{ $t->name }}</option>@endforeach</select></td>
    <td><select class="input input-sm" data-k="rate_plan_id"><option value="">Rack rate</option></select></td>
    <td><select class="input input-sm" data-k="room_id"><option value="">Auto-assign</option></select></td>
    <td><input class="input input-sm" type="number" min="1" max="10" data-k="adults" value="2" style="width:64px" required></td>
    <td><input class="input input-sm" type="number" min="0" max="10" data-k="children" value="0" style="width:64px"></td>
    <td><input class="input input-sm" type="number" step="0.01" min="0" data-k="rate" required></td>
    <td class="num" data-total>0.00</td>
    <td><button type="button" class="btn btn-sm btn-ghost" data-remove-line onclick="this.closest('tr').remove();reindex();total()" title="Remove"><x-icon name="trash" size="sm"/></button></td>
  </tr>
</template>
@endsection
@push('head')
<style>
  #stayTypeField .seg .check { display:inline-flex; align-items:center; gap:.35rem; }
  #dayUsePanel { margin:0; }
  #dayUsePanel[hidden] { display:none; }
  #dayUseTotal b { color:var(--c-text); }
</style>
@endpush
@push('scripts')
<script>
const plans = @json($ratePlans->groupBy('room_type_id'));
const initial = @json(old('rooms', $lines));
const tbody = document.querySelector('#linesTable tbody');
const A = document.querySelector('[name=arrival_date]'), D = document.querySelector('[name=departure_date]');
function nights(){
  if (!A.value || !D.value) return 0;
  const arrival = Date.parse(`${A.value}T00:00:00Z`), departure = Date.parse(`${D.value}T00:00:00Z`);
  return Number.isFinite(arrival) && Number.isFinite(departure) ? Math.max(0, Math.round((departure - arrival) / 86400000)) : 0;
}
function nextDate(value){
  if (!value) return '';
  const [year, month, day] = value.split('-').map(Number);
  const next = new Date(Date.UTC(year, month - 1, day + 1));
  return `${next.getUTCFullYear()}-${String(next.getUTCMonth() + 1).padStart(2, '0')}-${String(next.getUTCDate()).padStart(2, '0')}`;
}
function reindex(){ [...tbody.rows].forEach((tr,i) => tr.querySelectorAll('[data-k]').forEach(el => el.name = `rooms[${i}][${el.dataset.k}]`)); }
// A day use is sold by the hour: the hours bought are what the room is charged on,
// and the night count is not part of the money.
const stayInputs = [...document.querySelectorAll('input[name="stay_type"]')];
const dayUsePanel = document.getElementById('dayUsePanel');
const dayHoursField = document.getElementById('f_day_use_hours');
const dayRateField = document.getElementById('f_day_use_rate');
const rateColHead = document.getElementById('rateColHead');
const dayUseTotal = document.getElementById('dayUseTotal');
const isDayUse = () => stayInputs.some(input => input.checked && input.value === 'day_use');
const dayHours = () => { const hours = Number.parseInt(dayHoursField?.value ?? '', 10); return Number.isFinite(hours) && hours > 0 ? hours : 4; };
const dayHourlyRate = () => Number.parseFloat(dayRateField?.value ?? '') || 0;
const stayUnits = () => (isDayUse() ? dayHours() : nights());
const occupiedTo = () => (isDayUse() ? nextDate(A.value) : D.value);
const hourText = () => `${dayHours()} ${dayHours() === 1 ? 'hour' : 'hours'}`;
function total(){ const units = stayUnits(); document.getElementById('nights').value = isDayUse() ? 0 : nights(); let g = 0;
  [...tbody.rows].forEach(tr => { const v = (parseFloat(tr.querySelector('[data-k=rate]').value)||0) * units; g += v; tr.querySelector('[data-total]').textContent = v.toFixed(2); });
  document.getElementById('grandTotal').textContent = g.toFixed(2); }
function syncDayUse(){
  const day = isDayUse();
  if (dayUsePanel) dayUsePanel.hidden = !day;
  if (rateColHead) rateColHead.textContent = day ? 'Rate / hour' : 'Rate / night';
  if (D) {
    if (day) {
      // Keep the departure the desk had chosen, so going back to a night restores it.
      if (!D.readOnly && D.value && D.value > A.value) D.dataset.nightDeparture = D.value;
      D.value = A.value;
      D.readOnly = true;
    } else {
      D.readOnly = false;
      const restore = D.dataset.nightDeparture;
      if (restore && restore > A.value) D.value = restore;
      else if (A.value && (!D.value || D.value <= A.value)) D.value = nextDate(A.value);
      delete D.dataset.nightDeparture;
    }
  }
  [...tbody.rows].forEach(tr => {
    const rate = tr.querySelector('[data-k=rate]');
    if (!rate) return;
    rate.readOnly = day;
    if (day && dayRateField && dayRateField.value !== '') rate.value = dayRateField.value;
  });
  if (dayUseTotal) {
    dayUseTotal.replaceChildren();
    dayUseTotal.append(day
      ? `${hourText()} × ${dayHourlyRate().toFixed(2)} = ${(dayHours() * dayHourlyRate()).toFixed(2)} a room, charged once.`
      : 'Charged once for the hours bought.');
  }
  total();
}
function option(label, value = ''){ const el = document.createElement('option'); el.value = value; el.textContent = label; return el; }
function loadRooms(tr, keep){
  const type = tr.querySelector('[data-k=room_type_id]').value, sel = tr.querySelector('[data-k=room_id]');
  const cur = keep ?? tr.dataset.selectedRoom ?? sel.value;
  const request = (Number(tr.dataset.roomsRequest) || 0) + 1;
  tr.dataset.roomsRequest = request;
  tr.dataset.selectedRoom = cur;
  const to = occupiedTo();
  if (!type || !A.value || !to || to <= A.value) { sel.replaceChildren(option('Choose room type and dates')); return; }
  const params = new URLSearchParams({room_type_id:type, from:A.value, to, company_id:CO?.value || ''});
  sel.disabled = true;
  sel.replaceChildren(option('Checking room availability…'));
  getJSON(`{{ route('reservations.api.rooms') }}?${params.toString()}`).then(list => {
    if (request !== Number(tr.dataset.roomsRequest)) return;
    if (!Array.isArray(list)) throw new Error('Invalid room availability response');
    const options = list.length ? [option('Auto-assign')] : [option('No rooms available for these dates')];
    list.forEach(room => options.push(option(`${room.room_no}${room.hk === 'dirty' ? ' (dirty)' : ''}`, String(room.id))));
    if (cur && !list.some(room => String(room.id) === String(cur))) options.push(option(`#${cur} (current booking)`, String(cur)));
    sel.replaceChildren(...options);
    if (cur) sel.value = String(cur);
    tr.dataset.selectedRoom = sel.value;
  }).catch(() => {
    if (request !== Number(tr.dataset.roomsRequest)) return;
    sel.replaceChildren(option('Could not load rooms — change dates to retry'));
    sel.title = 'The live room list could not be loaded. The reservation will still be checked before saving.';
  }).finally(() => {
    if (request === Number(tr.dataset.roomsRequest)) sel.disabled = false;
  });
}
function fillPlans(tr, keep){
  const type = tr.querySelector('[data-k=room_type_id]').value, sel = tr.querySelector('[data-k=rate_plan_id]');
  const options = [option('Rack rate')];
  (plans[type] || []).forEach(plan => {
    const el = option(`${plan.name} – ${plan.rate}`, String(plan.id));
    el.dataset.rate = plan.rate;
    options.push(el);
  });
  sel.replaceChildren(...options);
  if (keep) sel.value = keep;
}
// seasonal pricing: rack-rate lines get the average nightly rate for the chosen dates
// v2.7: with a company that has an active contract, rack-rate lines get the negotiated price (per adults: single / double)
const CO = document.getElementById('f_company_id'), CI = document.getElementById('contractInfo');
// A company opened from this form is added to the picker and chosen straight away, so
// the contract banner and the rack-rate quote refresh for it at once.
document.addEventListener('city-ledger-created', event => {
  const account = event.detail;
  if (!CO || !account?.id) return;
  let option = [...CO.options].find(item => item.value === String(account.id));
  if (!option) {
    option = document.createElement('option');
    option.value = String(account.id);
    option.textContent = account.name;
    CO.append(option);
  }
  CO.value = String(account.id);
  CO.dispatchEvent(new Event('change', {bubbles:true}));
  const status = document.getElementById('resCompanyStatus');
  if (status) {
    status.textContent = `${account.name} was created and selected. The direct-billing account is available in Finance → City Ledger.`;
    status.hidden = false;
  }
});
const esc = t => String(t ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
function contractBanner(c){ if (!c) { CI.classList.add('hidden'); CI.innerHTML = ''; return; }
  const part = c.contract_nights < c.nights ? ` · <b>${c.nights - c.contract_nights} of ${c.nights} night(s) at the public rate</b> (${esc(c.why.join(', '))})` : '';
  CI.innerHTML = `<b>Contract ${esc(c.code)}</b> – ${esc(c.name)} · ${esc(c.basis)} · ${esc(c.meal_plan)}${c.lra ? ' · last-room availability' : ''}${part}. Rack-rate lines are priced from the contract.`;
  CI.dataset.code = c.code; CI.classList.remove('hidden'); }
function seasonRate(tr){
  const type = tr.querySelector('[data-k=room_type_id]').value, r = tr.querySelector('[data-k=rate]');
  const plan = tr.querySelector('[data-k=rate_plan_id]').value;
  const request = (Number(tr.dataset.rateRequest) || 0) + 1;
  tr.dataset.rateRequest = request;
  const to = occupiedTo();
  if (!type || !A.value || !to || to <= A.value || plan) return;
  const adults = tr.querySelector('[data-k=adults]').value || 1;
  const params = new URLSearchParams({room_type_id:type, from:A.value, to, company_id:CO?.value || '', adults});
  getJSON(`{{ route('reservations.api.rates') }}?${params.toString()}`).then(quote => {
    if (request !== Number(tr.dataset.rateRequest) || type !== tr.querySelector('[data-k=room_type_id]').value
      || A.value !== params.get('from') || D.value !== params.get('to') || plan !== tr.querySelector('[data-k=rate_plan_id]').value) return;
    if (!quote || !Number.isFinite(Number(quote.rate))) throw new Error('Invalid rate quote response');
    r.value = quote.rate;
    if (quote.contract) { r.title = `Contract ${quote.contract.code} (public rate ${quote.contract.public_rate})`; r.style.borderColor = 'var(--c-green-500, #16a34a)'; r.dataset.contract = quote.contract.code; contractBanner(quote.contract); }
    else { delete r.dataset.contract; if (![...tbody.rows].some(x => x.querySelector('[data-k=rate]').dataset.contract)) contractBanner(null);
      r.title = quote.seasons?.length ? 'Season: ' + quote.seasons.join(', ') : 'Rack rate'; r.style.borderColor = quote.seasons?.length ? 'var(--c-amber-500, #f59e0b)' : ''; }
    total();
  }).catch(() => {
    if (request === Number(tr.dataset.rateRequest)) r.title = 'Rate could not be refreshed. Check the amount before saving.';
  });
}
function addLine(d = {}){ const tr = document.getElementById('lineTpl').content.firstElementChild.cloneNode(true); tbody.appendChild(tr);
  tr.querySelector('[data-k=stay_id]').value = d.stay_id ?? '';
  const remove = tr.querySelector('[data-remove-line]');
  if (d.stay_id) { remove.disabled = true; remove.title = 'Use Cancel room on the reservation page to apply the cancellation policy'; remove.setAttribute('aria-label', remove.title); }
  const t = tr.querySelector('[data-k=room_type_id]'), p = tr.querySelector('[data-k=rate_plan_id]'), r = tr.querySelector('[data-k=rate]');
  if (d.room_type_id) { t.value = d.room_type_id; fillPlans(tr, d.rate_plan_id); loadRooms(tr, d.room_id); }
  if (d.adults) tr.querySelector('[data-k=adults]').value = d.adults; if (d.children) tr.querySelector('[data-k=children]').value = d.children;
  r.value = d.rate ?? (isDayUse() && dayRateField?.value !== '' ? dayRateField.value : (t.selectedOptions[0]?.dataset.rate || ''));
  t.addEventListener('change', () => { tr.dataset.selectedRoom = ''; fillPlans(tr); r.value = t.selectedOptions[0]?.dataset.rate || ''; loadRooms(tr, ''); seasonRate(tr); total(); });
  p.addEventListener('change', () => { r.value = p.selectedOptions[0]?.dataset.rate || t.selectedOptions[0]?.dataset.rate || ''; seasonRate(tr); total(); });
  tr.querySelector('[data-k=room_id]').addEventListener('change', (event) => { tr.dataset.selectedRoom = event.target.value; });
  if (d.room_type_id && !d.rate) seasonRate(tr);
  tr.querySelector('[data-k=adults]').addEventListener('change', () => seasonRate(tr));
  r.addEventListener('input', () => { tr.dataset.rateRequest = (Number(tr.dataset.rateRequest) || 0) + 1; total(); });
  reindex(); total(); }
(initial.length ? initial : [{}]).forEach(addLine);
stayInputs.forEach(input => input.addEventListener('change', () => syncDayUse()));
dayHoursField?.addEventListener('change', () => { syncDayUse(); refreshDateQuotes(); });
dayRateField?.addEventListener('input', () => syncDayUse());
syncDayUse();
CO?.addEventListener('change', () => { contractBanner(null); [...tbody.rows].forEach(tr => seasonRate(tr)); });
@if(! $editing && ! old('rooms')) document.addEventListener('DOMContentLoaded', () => { if (CO?.value) [...tbody.rows].forEach(tr => seasonRate(tr)); }); @endif
function refreshDateQuotes(){ [...tbody.rows].forEach(tr => { loadRooms(tr); seasonRate(tr); }); total(); }
if (A.value) D.min = nextDate(A.value);
A.addEventListener('change', () => {
  if (A.value) {
    D.min = nextDate(A.value);
    if (isDayUse()) D.value = A.value;
    else if (!D.value || D.value < D.min) D.value = D.min;
  } else D.removeAttribute('min');
  refreshDateQuotes();
});
D.addEventListener('change', () => {
  if (A.value) {
    D.min = nextDate(A.value);
    if (!D.value || D.value < D.min) D.value = D.min;
  }
  refreshDateQuotes();
});
</script>
@endpush
