@extends('public.layout')
@section('title', 'Online check-in')
@section('content')
@php $g = $r->guest; @endphp
<div class="card mb-2"><div class="card-body flex justify-between items-center wrap gap-2">
  <div><div class="small muted">Online check-in</div><h2 style="margin:.1rem 0">Reservation {{ $r->reservation_no }}</h2>
    <div class="text-2">{{ $r->arrival_date->format('D d M Y') }} → {{ $r->departure_date->format('D d M Y') }} · {{ $r->nights }} night(s) ·
      {{ $r->rooms->count() }} × {{ $r->rooms->pluck('roomType.name')->unique()->join(', ') }}</div></div>
  @if($r->prechecked_in_at)<span class="badge badge-green" style="font-size:.9rem;padding:.4rem .8rem"><x-icon name="check" size="sm"/> Pre-checked-in {{ $r->prechecked_in_at->format('d M H:i') }}</span>@endif
</div></div>

@if($closed)
  <div class="card"><div class="card-body center" style="padding:2.5rem"><h3>{{ $closed }}</h3><p class="muted">Questions? Call us on {{ $hotel->phone }}.</p></div></div>
@else
<form method="post" action="{{ route('checkin.submit', $token) }}" enctype="multipart/form-data" id="pciForm">@csrf
<div class="grid-2" style="display:grid;grid-template-columns:1.3fr 1fr;gap:1rem">
  <div class="card"><div class="card-head"><h3><x-icon name="user"/> Your details</h3></div><div class="card-body">
    <div class="form-grid">
      <x-field name="first_name" label="First name" :value="old('first_name', $g->first_name)" required/>
      <x-field name="last_name" label="Last name" :value="old('last_name', $g->last_name)"/>
      <x-field name="phone" label="Mobile" :value="old('phone', $g->phone)" required/>
      <x-field name="email" label="E-mail" type="email" :value="old('email', $g->email)" required/>
      <x-field name="nationality" label="Nationality" :value="old('nationality', $g->nationality)"/>
      <x-field name="dob" label="Date of birth" type="date" :value="old('dob', $g->dob?->format('Y-m-d'))"/>
      <x-field name="address" label="Address" :value="old('address', $g->address)" class="span-2"/>
      <x-field name="city" label="City" :value="old('city', $g->city)"/>
      <x-field name="country" label="Country" :value="old('country', $g->country)"/>
      <x-field name="id_type" label="ID type" type="select" :options="config('pms.id_types')" :value="old('id_type', $g->id_type ?: 'passport')" required/>
      <x-field name="id_number" label="ID number" :value="old('id_number', $g->id_number)" required/>
      <x-field name="passport_no" label="Passport no. (foreign nationals)" :value="old('passport_no', $g->passport_no)"/>
      <x-field name="visa_expiry" label="Visa expiry" type="date" :value="old('visa_expiry', $g->visa_expiry?->format('Y-m-d'))"/>
    </div>
  </div></div>
  <div>
    <div class="card mb-2"><div class="card-head"><h3><x-icon name="clock"/> Arrival</h3></div><div class="card-body">
      <div class="form-grid" style="grid-template-columns:1fr">
        <x-field name="eta" label="Expected arrival time" type="time" :value="old('eta', $r->eta ? substr($r->eta, 0, 5) : '')" hint="Standard check-in is from {{ substr($hotel->checkin_time, 0, 5) }}"/>
        <x-field name="special_request" label="Requests (early check-in, airport pick-up, cot…)" type="textarea" :value="old('special_request')"/>
      </div>
    </div></div>
    <div class="card"><div class="card-head"><h3><x-icon name="id"/> Photo ID</h3></div><div class="card-body">
      <div class="field"><label>ID – front @unless($r->prechecked_in_at)<span class="req">*</span>@endunless</label><input type="file" name="id_front" accept="image/*,application/pdf" {{ $r->prechecked_in_at ? '' : 'required' }}></div>
      <div class="field"><label>ID – back (optional)</label><input type="file" name="id_back" accept="image/*,application/pdf"></div>
      <p class="tiny muted">JPG, PNG, WEBP or PDF, 5 MB max. Stored securely and used only for statutory guest registration.</p>
    </div></div>
  </div>
</div>
@if(\App\Services\FormCService::enabled())
@php $pf = $r->formc_prefill ?? []; $pv = fn ($k) => old('formc.'.$k, $pf[$k] ?? null); @endphp
<div class="card mt-2" id="formcCard" @if(\App\Services\FormCService::isForeignNationality(old('nationality', $g->nationality)) !== true) hidden @endif><div class="card-head"><h3><x-icon name="globe"/> Foreign nationals – Form C</h3></div><div class="card-body">
  <p class="small text-2">Indian law requires hotels to report every foreign guest to the immigration authorities (FRRO) within 24 hours of arrival. Filling this in now makes check-in faster.</p>
  <div class="form-grid">
    <x-field name="formc[passport_place]" label="Passport – place of issue" :value="$pv('passport_place')"/>
    <x-field name="formc[passport_issued_on]" label="Passport – date of issue" type="date" :value="$pv('passport_issued_on')"/>
    <x-field name="formc[passport_expires_on]" label="Passport – valid until" type="date" :value="$pv('passport_expires_on')"/>
    <x-field name="formc[visa_type]" label="Visa type" type="select" :options="\App\Models\FormCRecord::VISA_TYPES" :value="$pv('visa_type')"/>
    <x-field name="formc[visa_no]" label="Visa / OCI number" :value="$pv('visa_no')"/>
    <x-field name="formc[visa_place]" label="Visa – place of issue" :value="$pv('visa_place')"/>
    <x-field name="formc[visa_issued_on]" label="Visa – date of issue" type="date" :value="$pv('visa_issued_on')"/>
    <x-field name="formc[arrived_india_on]" label="Date of arrival in India" type="date" :value="$pv('arrived_india_on')"/>
    <x-field name="formc[port_of_arrival]" label="Port of arrival in India" :value="$pv('port_of_arrival')" placeholder="e.g. Bengaluru airport"/>
    <x-field name="formc[arrived_from]" label="Coming to us from (city, country)" :value="$pv('arrived_from')"/>
    <x-field name="formc[next_destination]" label="Next destination after the hotel" :value="$pv('next_destination')"/>
    <x-field name="formc[purpose]" label="Purpose of visit" type="select" :options="\App\Models\FormCRecord::PURPOSES" :value="$pv('purpose')"/>
    <div class="field"><label>Visa scan</label><input type="file" name="visa_scan" accept="image/*,application/pdf"></div>
  </div>
  <p class="tiny muted">Choose "Passport" as the ID type above and upload the passport photo page as the ID front.</p>
</div></div>
<script>
(() => {
  const n = document.getElementById('f_nationality'), card = document.getElementById('formcCard');
  const home = ['india', 'indian', 'in', 'ind', 'bharat', 'republic of india'];
  const upd = () => { const v = (n.value || '').trim().toLowerCase(); card.hidden = !v || home.includes(v); };
  n && card && n.addEventListener('input', upd);
})();
</script>
@endif
<div class="card mt-2"><div class="card-head"><h3><x-icon name="edit"/> Registration card signature</h3></div><div class="card-body">
  <p class="small text-2">{{ $terms }} Check-out time is {{ substr($hotel->checkout_time, 0, 5) }}.</p>
  <canvas id="sigPad" width="900" height="260" style="width:100%;max-width:720px;height:180px;border:2px dashed var(--c-border-strong);border-radius:var(--radius);background:#fff;touch-action:none;cursor:crosshair;display:block"></canvas>
  <input type="hidden" name="signature" id="sigData">
  <div class="flex gap-1 items-center mt-1 wrap"><button type="button" class="btn btn-sm" id="sigClear">Clear signature</button>
    <label class="check"><input type="checkbox" name="accept" value="1" required> I confirm these details are correct and accept the terms.</label></div>
  <div class="mt-2"><button class="btn btn-primary btn-lg" id="pciSubmit" disabled><x-icon name="check"/> {{ $r->prechecked_in_at ? 'Update my check-in' : 'Complete online check-in' }}</button> <span class="small muted" id="sigHint">Please sign above to continue.</span></div>
</div></div>
</form>
<script>
(() => {
  const c = document.getElementById('sigPad'), ctx = c.getContext('2d'), btn = document.getElementById('pciSubmit');
  let drawing = false, strokes = 0, last = null;
  ctx.lineWidth = 3.2; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#0f172a';
  const pos = e => { const r = c.getBoundingClientRect(); return [(e.clientX - r.left) * c.width / r.width, (e.clientY - r.top) * c.height / r.height]; };
  c.addEventListener('pointerdown', e => { drawing = true; last = pos(e); c.setPointerCapture(e.pointerId); });
  c.addEventListener('pointermove', e => { if (!drawing) return; const p = pos(e); ctx.beginPath(); ctx.moveTo(...last); ctx.lineTo(...p); ctx.stroke(); last = p; if (++strokes > 8) { btn.disabled = false; document.getElementById('sigHint').textContent = ''; } });
  ['pointerup', 'pointercancel', 'pointerleave'].forEach(ev => c.addEventListener(ev, () => drawing = false));
  document.getElementById('sigClear').onclick = () => { ctx.clearRect(0, 0, c.width, c.height); strokes = 0; btn.disabled = true; };
  document.getElementById('pciForm').addEventListener('submit', () => { document.getElementById('sigData').value = c.toDataURL('image/png'); btn.disabled = true; });
})();
</script>
<style>@media (max-width:760px){.grid-2{grid-template-columns:1fr!important}}</style>
@endif
@endsection
