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
          <x-field name="arrival_date" label="Arrival" type="date" :value="$reservation->arrival_date?->toDateString()" required/>
          <x-field name="departure_date" label="Departure" type="date" :value="$reservation->departure_date?->toDateString()" required/>
          <div class="field"><label>Nights</label><input id="nights" readonly></div>
          <x-field name="eta" label="ETA" type="time" :value="$reservation->eta ? substr($reservation->eta,0,5) : ''"/>
          <x-field name="booking_type" label="Booking type" type="select" :options="['individual'=>'Individual','group'=>'Group']" :value="$reservation->booking_type" :placeholder="false"/>
          <x-field name="group_name" label="Group name" :value="$reservation->group_name"/>
          <x-field name="status" label="Status" type="select" :options="['confirmed'=>'Confirmed','tentative'=>'Tentative']" :value="$reservation->status" :placeholder="false"/>
          <x-field name="business_source_id" label="Business source" type="select" :options="$sources" :value="$reservation->business_source_id"/>
          <x-field name="company_id" label="Company / Travel agent" type="select" :options="$companies" :value="$reservation->company_id"/>
          <x-field name="billing_instruction" label="Bill to" type="select" :options="['guest'=>'Guest','company'=>'Company (City Ledger)']" :value="$reservation->billing_instruction ?? 'guest'" :placeholder="false"/>
          <x-field name="ota_reference" label="OTA / Voucher ref." :value="$reservation->ota_reference"/>
          <x-field name="purpose" label="Purpose of visit" type="select" :options="['Leisure'=>'Leisure','Business'=>'Business','Conference'=>'Conference','Wedding'=>'Wedding','Medical'=>'Medical','Other'=>'Other']" :value="$reservation->purpose"/>
        </div>
      </div>

      <div class="card">
        @php $rc = $editing ? $reservation->rateContract : null; @endphp
        <div id="contractInfo" class="alert alert-info {{ $rc ? '' : 'hidden' }}" style="margin:0;border-radius:0">@if($rc)<b>Contract {{ $rc->code }}</b> – {{ $rc->name }} · {{ $rc->basisLabel() }} · {{ $rc->meal_plan }}. Rates saved on this booking are kept; change the room type or dates to re-price.@endif</div>
        <div class="card-head"><h3>Rooms</h3><button type="button" class="btn btn-sm btn-soft" onclick="addLine()"><x-icon name="plus" size="sm"/> Add room</button></div>
        <div class="card-body table-wrap" style="padding:0">
          <table class="table" id="linesTable">
            <thead><tr><th style="min-width:170px">Room type</th><th style="min-width:170px">Rate plan</th><th style="min-width:120px">Room no.</th><th>Adults</th><th>Child</th><th style="min-width:110px">Rate / night</th><th class="num">Total</th><th></th></tr></thead>
            <tbody></tbody>
            <tfoot><tr><td colspan="6" class="right">Estimated room total (excl. tax)</td><td class="num" id="grandTotal">0.00</td><td></td></tr></tfoot>
          </table>
        </div>
      </div>

      <div class="card">
        <div class="card-head"><h3>Guest</h3></div>
        <div class="card-body">@include('reservations._guest')</div>
      </div>
    </div>

    <div>
      <div class="card">
        <div class="card-head"><h3>Billing</h3></div>
        <div class="card-body">
          <x-field name="discount_pct" label="Discount %" type="number" step="0.01" min="0" max="100" :value="$reservation->discount_pct ?? 0"/>
          @unless($editing)
          <div class="form-section">Advance payment</div>
          <x-field name="advance" label="Amount" type="number" step="0.01" min="0" value="0"/>
          <div class="mt-1"></div>
          <x-field name="advance_mode" label="Mode" type="select" :options="$paymentModes" data-payment-mode/>
          <div class="mt-1"></div>
          <x-field name="advance_ref" label="Reference / Txn ID"/>
          <x-cheque-fields/>
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

<template id="lineTpl">
  <tr>
    <td><select class="input input-sm" data-k="room_type_id" required><option value="">Select</option>@foreach($roomTypes as $t)<option value="{{ $t->id }}" data-rate="{{ $t->base_rate }}">{{ $t->name }}</option>@endforeach</select></td>
    <td><select class="input input-sm" data-k="rate_plan_id"><option value="">Rack rate</option></select></td>
    <td><select class="input input-sm" data-k="room_id"><option value="">Auto-assign</option></select></td>
    <td><input class="input input-sm" type="number" min="1" max="10" data-k="adults" value="2" style="width:64px" required></td>
    <td><input class="input input-sm" type="number" min="0" max="10" data-k="children" value="0" style="width:64px"></td>
    <td><input class="input input-sm" type="number" step="0.01" min="0" data-k="rate" required></td>
    <td class="num" data-total>0.00</td>
    <td><button type="button" class="btn btn-sm btn-ghost" onclick="this.closest('tr').remove();reindex();total()" title="Remove"><x-icon name="trash" size="sm"/></button></td>
  </tr>
</template>
@endsection
@push('scripts')
<script>
const plans = @json($ratePlans->groupBy('room_type_id'));
const initial = @json(old('rooms', $lines));
const tbody = document.querySelector('#linesTable tbody');
const A = document.querySelector('[name=arrival_date]'), D = document.querySelector('[name=departure_date]');
function nights(){ return Math.max(0, Math.round((new Date(D.value) - new Date(A.value)) / 86400000)); }
function reindex(){ [...tbody.rows].forEach((tr,i) => tr.querySelectorAll('[data-k]').forEach(el => el.name = `rooms[${i}][${el.dataset.k}]`)); }
function total(){ const n = nights(); document.getElementById('nights').value = n; let g = 0;
  [...tbody.rows].forEach(tr => { const v = (parseFloat(tr.querySelector('[data-k=rate]').value)||0) * n; g += v; tr.querySelector('[data-total]').textContent = v.toFixed(2); });
  document.getElementById('grandTotal').textContent = g.toFixed(2); }
function loadRooms(tr, keep){ const type = tr.querySelector('[data-k=room_type_id]').value, sel = tr.querySelector('[data-k=room_id]');
  if (!type || !A.value || !D.value) return;
  getJSON(`{{ route('reservations.api.rooms') }}?room_type_id=${type}&from=${A.value}&to=${D.value}&company_id=${CO?.value || ''}`).then(list => {
    const cur = keep ?? sel.value; sel.innerHTML = '<option value="">Auto-assign</option>' + list.map(r => `<option value="${r.id}">${r.room_no}${r.hk==='dirty'?' (dirty)':''}</option>`).join('');
    if (cur) { if (![...sel.options].some(o => o.value == cur)) sel.insertAdjacentHTML('beforeend', `<option value="${cur}">#${cur} (current)</option>`); sel.value = cur; } }); }
function fillPlans(tr, keep){ const type = tr.querySelector('[data-k=room_type_id]').value, sel = tr.querySelector('[data-k=rate_plan_id]');
  sel.innerHTML = '<option value="">Rack rate</option>' + (plans[type]||[]).map(p => `<option value="${p.id}" data-rate="${p.rate}">${p.name} – ${p.rate}</option>`).join(''); if (keep) sel.value = keep; }
// seasonal pricing: rack-rate lines get the average nightly rate for the chosen dates
// v2.7: with a company that has an active contract, rack-rate lines get the negotiated price (per adults: single / double)
const CO = document.getElementById('f_company_id'), CI = document.getElementById('contractInfo');
const esc = t => String(t ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
function contractBanner(c){ if (!c) { CI.classList.add('hidden'); CI.innerHTML = ''; return; }
  const part = c.contract_nights < c.nights ? ` · <b>${c.nights - c.contract_nights} of ${c.nights} night(s) at the public rate</b> (${esc(c.why.join(', '))})` : '';
  CI.innerHTML = `<b>Contract ${esc(c.code)}</b> – ${esc(c.name)} · ${esc(c.basis)} · ${esc(c.meal_plan)}${c.lra ? ' · last-room availability' : ''}${part}. Rack-rate lines are priced from the contract.`;
  CI.dataset.code = c.code; CI.classList.remove('hidden'); }
function seasonRate(tr){ const type = tr.querySelector('[data-k=room_type_id]').value, r = tr.querySelector('[data-k=rate]');
  if (!type || !A.value || !D.value || tr.querySelector('[data-k=rate_plan_id]').value) return;
  const ad = tr.querySelector('[data-k=adults]').value || 1;
  getJSON(`{{ route('reservations.api.rates') }}?room_type_id=${type}&from=${A.value}&to=${D.value}&company_id=${CO?.value || ''}&adults=${ad}`).then(q => { r.value = q.rate;
    if (q.contract) { r.title = `Contract ${q.contract.code} (public rate ${q.contract.public_rate})`; r.style.borderColor = 'var(--c-green-500, #16a34a)'; r.dataset.contract = q.contract.code; contractBanner(q.contract); }
    else { delete r.dataset.contract; if (![...tbody.rows].some(x => x.querySelector('[data-k=rate]').dataset.contract)) contractBanner(null);
      r.title = q.seasons.length ? 'Season: ' + q.seasons.join(', ') : 'Rack rate'; r.style.borderColor = q.seasons.length ? 'var(--c-amber-500, #f59e0b)' : ''; }
    total(); }); }
function addLine(d = {}){ const tr = document.getElementById('lineTpl').content.firstElementChild.cloneNode(true); tbody.appendChild(tr);
  const t = tr.querySelector('[data-k=room_type_id]'), p = tr.querySelector('[data-k=rate_plan_id]'), r = tr.querySelector('[data-k=rate]');
  if (d.room_type_id) { t.value = d.room_type_id; fillPlans(tr, d.rate_plan_id); loadRooms(tr, d.room_id); }
  if (d.adults) tr.querySelector('[data-k=adults]').value = d.adults; if (d.children) tr.querySelector('[data-k=children]').value = d.children;
  r.value = d.rate ?? (t.selectedOptions[0]?.dataset.rate || '');
  t.addEventListener('change', () => { fillPlans(tr); r.value = t.selectedOptions[0]?.dataset.rate || ''; loadRooms(tr, ''); seasonRate(tr); total(); });
  p.addEventListener('change', () => { r.value = p.selectedOptions[0]?.dataset.rate || t.selectedOptions[0]?.dataset.rate || ''; seasonRate(tr); total(); });
  if (d.room_type_id && !d.rate) seasonRate(tr);
  tr.querySelector('[data-k=adults]').addEventListener('change', () => { if (CO?.value) seasonRate(tr); });
  r.addEventListener('input', total); reindex(); total(); }
(initial.length ? initial : [{}]).forEach(addLine);
CO?.addEventListener('change', () => { contractBanner(null); [...tbody.rows].forEach(tr => seasonRate(tr)); });
@if(! $editing && ! old('rooms')) document.addEventListener('DOMContentLoaded', () => { if (CO?.value) [...tbody.rows].forEach(tr => seasonRate(tr)); }); @endif
[A, D].forEach(el => el.addEventListener('change', () => { if (new Date(D.value) <= new Date(A.value)) { const x = new Date(A.value); x.setDate(x.getDate()+1); D.value = x.toISOString().slice(0,10); } [...tbody.rows].forEach(tr => { loadRooms(tr); seasonRate(tr); }); total(); }));
</script>
@endpush
