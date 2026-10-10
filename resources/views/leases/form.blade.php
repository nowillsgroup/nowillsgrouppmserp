@extends('layouts.app')
@section('title', $lease ? 'Edit '.$lease->lease_no : 'New lease')
@section('crumbs') <a href="{{ route('leases.index') }}">Apartments &amp; Leases</a> / {{ $lease ? $lease->lease_no : 'New lease' }} @endsection
@section('content')
@php $v = fn ($k, $d = null) => old($k, $lease?->{$k} ?? $d); @endphp
<form method="post" action="{{ $lease ? route('leases.update', $lease) : route('leases.store') }}" id="leaseForm">@csrf @if($lease) @method('PUT') @endif
<div class="fs-split">
<div>
  <div class="card"><div class="card-head"><h3><x-icon name="building"/> Unit &amp; term</h3></div><div class="card-body"><div class="form-grid">
    <div class="field span-2"><label for="l_unit">Unit <span class="req">*</span></label><select name="lease_unit_id" id="l_unit" required @disabled($lease)>
      <option value="">— choose —</option>@foreach($units as $u)<option value="{{ $u->id }}" data-rent="{{ $u->list_rent }}" data-sc="{{ $u->service_charge }}" data-class="{{ $u->tax_class }}" @selected((int) $v('lease_unit_id', $unitId) === $u->id)>{{ $u->code }} – {{ $u->name }} ({{ \App\Models\LeaseUnit::STATUSES[$u->status] }}{{ $u->room_id ? ', hotel room' : '' }})</option>@endforeach</select>
      @if($lease)<input type="hidden" name="lease_unit_id" value="{{ $lease->lease_unit_id }}">@endif</div>
    <div class="field"><label for="l_class">Tax class</label><select name="tax_class" id="l_class">@foreach(\App\Models\Lease::TAX_CLASSES as $k => $t)<option value="{{ $k }}" @selected($v('tax_class') === $k)>{{ $t }}</option>@endforeach</select></div>
    <div class="field"><label for="l_start">Start date <span class="req">*</span></label><input type="date" name="start_date" id="l_start" value="{{ old('start_date', $lease?->start_date?->toDateString() ?? bdate()->toDateString()) }}" required></div>
    <div class="field"><label for="l_term">Term (months) <span class="req">*</span></label><input type="number" name="term_months" id="l_term" min="1" max="60" value="{{ $v('term_months', 12) }}" required></div>
    <div class="field"><label for="l_cycle">Billing cycle</label><select name="cycle_months" id="l_cycle">@foreach(\App\Models\Lease::CYCLES as $k => $t)<option value="{{ $k }}" @selected((int) $v('cycle_months', 1) === $k)>{{ $t }}</option>@endforeach</select>
      <span class="hint" id="capHint"></span></div>
    <div class="field"><label for="l_rent">Rent / month (GH₵, excl. tax) <span class="req">*</span></label><input type="number" step="0.01" min="1" name="rent" id="l_rent" value="{{ $v('rent') }}" required></div>
    <div class="field"><label for="l_sc">Service charge / month</label><input type="number" step="0.01" min="0" name="service_charge" id="l_sc" value="{{ $v('service_charge', 0) }}"></div>
    <div class="field"><label for="l_dep">Security deposit (GH₵)</label><input type="number" step="0.01" min="0" name="deposit_amount" id="l_dep" value="{{ $v('deposit_amount') }}" placeholder="{{ $cfg['lease_deposit_months'] }} months' rent"></div>
    <div class="field"><label for="l_notice">Notice period (days)</label><input type="number" name="notice_days" id="l_notice" min="0" max="365" value="{{ $v('notice_days', $cfg['lease_notice_days']) }}"></div>
    <div class="field"><label for="l_esc">Rent review (% increase)</label><input type="number" step="0.01" min="0" max="100" name="escalation_pct" id="l_esc" value="{{ $v('escalation_pct', 0) }}"></div>
    <div class="field"><label for="l_escm">Review every (months)</label><input type="number" name="escalation_months" id="l_escm" min="1" max="60" value="{{ $v('escalation_months', 12) }}"></div>
    <label class="check span-2"><input type="checkbox" name="auto_renew" value="1" @checked($v('auto_renew'))> <span>Renew automatically 30 days before the end (same rent, up to 12 months)</span></label>
  </div>
  <div class="small mt-1" id="calcBox"></div></div></div>

  <div class="card mt-1"><div class="card-head"><h3><x-icon name="thermo"/> Utilities</h3></div><div class="card-body"><div class="form-grid">
    <div class="field"><label>Electricity</label><select name="elec_mode">@foreach(\App\Models\Lease::UTILITY_MODES as $k => $t)<option value="{{ $k }}" @selected($v('elec_mode', 'included') === $k)>{{ $t }}</option>@endforeach</select></div>
    <div class="field"><label>Electricity tariff (GH₵/kWh)</label><input type="number" step="0.0001" min="0" name="elec_tariff" value="{{ $v('elec_tariff') }}" placeholder="{{ $cfg['lease_elec_tariff'] }} (default)"></div>
    <div class="field"><label>Water</label><select name="water_mode">@foreach(\App\Models\Lease::UTILITY_MODES as $k => $t)<option value="{{ $k }}" @selected($v('water_mode', 'included') === $k)>{{ $t }}</option>@endforeach</select></div>
    <div class="field"><label>Water tariff (GH₵/m³)</label><input type="number" step="0.0001" min="0" name="water_tariff" value="{{ $v('water_tariff') }}" placeholder="{{ $cfg['lease_water_tariff'] }} (default)"></div>
    <div class="field span-2"><label>Included services</label><input name="inclusions" value="{{ $v('inclusions') }}" maxlength="255" placeholder="Weekly housekeeping, Wi-Fi, 1 parking bay, gym & pool"></div>
  </div></div></div>
</div>
<div>
  <div class="card"><div class="card-head"><h3><x-icon name="user"/> Tenant</h3></div><div class="card-body"><div class="form-grid">
    <div class="field span-2"><label for="l_name">Tenant / occupant name <span class="req">*</span></label><input name="tenant_name" id="l_name" value="{{ $v('tenant_name') }}" required maxlength="120"></div>
    <div class="field span-2"><label for="l_co">Paying company (corporate lease)</label><select name="company_id" id="l_co"><option value="">— individual —</option>@foreach($companies as $c)<option value="{{ $c->id }}" @selected((int) $v('company_id') === $c->id)>{{ $c->name }}</option>@endforeach</select></div>
    <div class="field"><label>Phone</label><input name="phone" value="{{ $v('phone') }}" maxlength="30"></div>
    <div class="field"><label>Email</label><input type="email" name="email" value="{{ $v('email') }}" maxlength="120"></div>
    <div class="field"><label>ID type</label><select name="id_type"><option value="">—</option>@foreach(['Ghana Card', 'Passport', 'Driver licence', 'Voter ID', 'Company registration'] as $t)<option @selected($v('id_type') === $t)>{{ $t }}</option>@endforeach</select></div>
    <div class="field"><label>ID number</label><input name="id_no" value="{{ $v('id_no') }}" maxlength="40" placeholder="GHA-123456789-0"></div>
    <div class="field span-2"><label>Other occupants</label><input name="occupants" value="{{ $v('occupants') }}" maxlength="255" placeholder="Spouse and 2 children"></div>
    <div class="field"><label for="l_wht">WHT deducted by tenant (%)</label><input type="number" step="0.01" min="0" max="20" name="wht_pct" id="l_wht" value="{{ $v('wht_pct') }}" placeholder="{{ $cfg['lease_wht_rate'] }}% if a company pays"></div>
    <div class="field span-2"><label>Notes</label><textarea name="notes" rows="3">{{ $v('notes') }}</textarea></div>
  </div></div></div>
  <div class="flex gap-1 mt-1"><a class="btn" href="{{ $lease ? route('leases.show', $lease) : route('leases.index') }}">Cancel</a><button class="btn btn-primary" id="leaseSave">{{ $lease ? 'Save terms' : 'Create draft lease' }}</button></div>
</div>
</div>
</form>
@endsection
@push('scripts')
<script>
(() => {
  const $ = id => document.getElementById(id);
  const cap = {{ (int) $cfg['lease_enforce_rent_cap'] }};
  const rates = {serviced: 21, residential: 0, commercial: 20};
  const unit = $('l_unit');
  unit.addEventListener('change', () => {
    const o = unit.selectedOptions[0]; if (!o || !o.value) return;
    if (!$('l_rent').value) $('l_rent').value = o.dataset.rent;
    $('l_sc').value = o.dataset.sc; $('l_class').value = o.dataset.class; calc();
  });
  const calc = () => {
    const term = +$('l_term').value || 0, cyc = +$('l_cycle').value, rent = +$('l_rent').value || 0, sc = +$('l_sc').value || 0, cls = $('l_class').value;
    const max = term > 6 ? 6 : 1;
    $('capHint').textContent = cls === 'residential' && cap ? (cyc > max ? '⚠ Rent Act s.25(5): at most ' + max + ' month(s) in advance' : 'Rent Act cap: ' + max + ' month(s) ahead') : '';
    const net = (rent + sc) * cyc, tax = Math.round(net * rates[cls]) / 100;
    const f = n => n.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    $('calcBox').innerHTML = 'Each invoice: <b>GH₵ ' + f(net) + '</b> + tax ' + f(tax) + ' (' + rates[cls] + '%) = <b>GH₵ ' + f(net + tax) + '</b>' + (term ? ' · ' + Math.ceil(term / cyc) + ' invoice(s) over the term' : '');
  };
  ['l_term', 'l_cycle', 'l_rent', 'l_sc', 'l_class'].forEach(id => ['input', 'change'].forEach(ev => $(id).addEventListener(ev, calc)));
  calc();
})();
</script>
@endpush
