@extends('layouts.app')
@section('title', 'Walk-in Check-in')
@section('crumbs') Front Office / Walk-in @endsection
@section('content')
<form method="post" action="{{ route('frontdesk.walkin.store') }}">@csrf
<div class="grid g-3-1">
  <div>
    <div class="card"><div class="card-head"><h3>Room & Tariff</h3><span class="small muted">Arrival: {{ bdate()->format('d M Y') }} (business date)</span></div>
      <div class="card-body form-grid">
        <div class="field"><label>Room <span class="req">*</span></label>
          <select name="room_id" id="room_id" required><option value="">Select vacant room</option>
            @foreach($rooms->groupBy(fn($r) => $r->roomType->name) as $t => $list)<optgroup label="{{ $t }}">@foreach($list as $r)<option value="{{ $r->id }}" data-type="{{ $r->room_type_id }}" data-rate="{{ $r->roomType->base_rate }}" @selected(old('room_id', $preRoom) == $r->id)>{{ $r->room_no }}{{ $r->hk_status !== 'clean' && $r->hk_status !== 'inspected' ? ' ('.$r->hk_status.')' : '' }}</option>@endforeach</optgroup>@endforeach
          </select></div>
        <x-field name="nights" label="Nights" type="number" min="1" max="90" value="1" required/>
        <div class="field"><label>Departure</label><input id="depDate" readonly></div>
        <div class="field"><label>Rate plan</label><select name="rate_plan_id" id="rate_plan_id"><option value="">Rack rate</option></select></div>
        <x-field name="rate" label="Rate / night" type="number" step="0.01" min="0" required/>
        <x-field name="adults" label="Adults" type="number" min="1" max="10" value="1" required/>
        <x-field name="children" label="Children" type="number" min="0" max="10" value="0"/>
        <x-field name="business_source_id" label="Source" type="select" :options="$sources" :value="$walkInSource"/>
        <x-field name="company_id" label="Company" type="select" :options="$companies"/>
        <x-field name="purpose" label="Purpose" type="select" :options="['Leisure'=>'Leisure','Business'=>'Business','Medical'=>'Medical','Other'=>'Other']"/>
      </div></div>
    <div class="card"><div class="card-head"><h3>Guest Registration</h3></div><div class="card-body">@include('reservations._guest')</div></div>
  </div>
  <div>
    <div class="card"><div class="card-head"><h3>Advance / Deposit</h3></div><div class="card-body">
      <x-field name="advance" label="Amount" type="number" step="0.01" min="0" value="0"/><div class="mt-1"></div>
      <x-field name="advance_mode" label="Mode" type="select" :options="$paymentModes" data-payment-mode/><div class="mt-1"></div>
      <x-field name="advance_ref" label="Reference"/>
      <x-cheque-fields/>
    </div></div>
    <div class="card"><div class="card-body"><x-field name="special_request" label="Remarks" type="textarea"/></div></div>
    <div class="card"><div class="card-body"><ul class="list"><li><span>Estimated room charges</span><b id="est">—</b></li></ul></div></div>
    <button class="btn btn-success btn-lg btn-block"><x-icon name="login"/> Check-in Now</button>
  </div>
</div>
</form>
@endsection
@push('scripts')
<script>
const plans = @json($ratePlans->groupBy('room_type_id')); const bd = new Date('{{ bdate()->toDateString() }}');
const room = document.getElementById('room_id'), plan = document.getElementById('rate_plan_id'), rate = document.querySelector('[name=rate]'), nights = document.querySelector('[name=nights]');
function upd(){ const d = new Date(bd); d.setDate(d.getDate() + (+nights.value||1)); document.getElementById('depDate').value = d.toDateString(); document.getElementById('est').textContent = ((+rate.value||0) * (+nights.value||1)).toFixed(2); }
room.addEventListener('change', () => { const o = room.selectedOptions[0]; plan.innerHTML = '<option value="">Rack rate</option>' + (plans[o?.dataset.type]||[]).map(p => `<option value="${p.id}" data-rate="${p.rate}">${p.name} – ${p.rate}</option>`).join(''); rate.value = o?.dataset.rate || ''; upd(); });
plan.addEventListener('change', () => { rate.value = plan.selectedOptions[0]?.dataset.rate || room.selectedOptions[0]?.dataset.rate; seasonRate(); upd(); });
function seasonRate(){ const o = room.selectedOptions[0]; if (!o?.dataset.type || plan.value) return; const d = new Date(bd); d.setDate(d.getDate() + (+nights.value||1));
  getJSON(`{{ route('reservations.api.rates') }}?room_type_id=${o.dataset.type}&from={{ bdate()->toDateString() }}&to=${d.toISOString().slice(0,10)}&company_id=${document.getElementById('f_company_id')?.value || ''}&adults=${document.querySelector('[name=adults]')?.value || 1}`).then(q => { rate.value = q.rate;
    rate.title = q.contract ? `Contract ${q.contract.code} (public rate ${q.contract.public_rate})` : ''; rate.style.borderColor = q.contract ? 'var(--c-green-500, #16a34a)' : ''; upd(); }); }
room.addEventListener('change', seasonRate); nights.addEventListener('change', seasonRate);
document.getElementById('f_company_id')?.addEventListener('change', seasonRate); document.querySelector('[name=adults]')?.addEventListener('change', seasonRate);
[rate, nights].forEach(e => e.addEventListener('input', upd)); if (room.value) room.dispatchEvent(new Event('change')); upd();
</script>
@endpush
