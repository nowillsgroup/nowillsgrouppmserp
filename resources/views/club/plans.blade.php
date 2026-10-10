@extends('layouts.app')
@section('title', 'Club Plans & Settings')
@section('crumbs') <a href="{{ route('club.index') }}">Health Club</a> / Plans &amp; settings @endsection
@section('actions')
@if(can_do('club.manage'))<button class="btn btn-primary" data-modal="planModal" data-title="New plan" data-action="{{ route('club.plans.store') }}" data-f-is_active="1" data-f-freeze_days_max="30" data-f-max_persons="1" data-f-term_months="1" id="newPlan"><x-icon name="plus"/> New plan</button>
<form method="post" action="{{ route('club.nightly') }}" data-confirm="Run the club housekeeping now (lapse expired terms, start/end freezes, close open visits, raise renewals)?">@csrf<button class="btn" id="nightlyBtn"><x-icon name="refresh"/> Run housekeeping</button></form>@endif
@endsection
@section('content')
<div class="card flush"><div class="card-head"><h3>Membership plans</h3><span class="small muted">prices exclude {{ rtrim(rtrim(number_format($rate, 2), '0'), '.') }}% VAT, NHIL, GETFund &amp; Tourism Levy</span></div><div class="card-body table-wrap"><table class="table" id="plansTable">
  <thead><tr><th>Code</th><th>Plan</th><th>Term</th><th class="num">Price</th><th class="num">Joining</th><th>Facilities</th><th>Benefits</th><th class="num">Members</th><th></th></tr></thead>
  <tbody>@foreach($plans as $p)
    <tr data-plan="{{ $p->code }}" class="{{ $p->is_active ? '' : 'muted' }}"><td class="bold">{{ $p->code }}</td><td>{{ $p->name }}<div class="tiny muted">{{ \App\Models\ClubPlan::CATEGORIES[$p->category] }} · up to {{ $p->max_persons }} person(s){{ $p->is_active ? '' : ' · withdrawn' }}</div></td>
      <td class="small">{{ $p->termLabel() }}</td><td class="num">{{ money($p->price, false) }}</td><td class="num">{{ money($p->joining_fee, false) }}</td>
      <td class="small">{{ $p->facilityNames() }}{{ $p->hoursLabel() ? ' · '.$p->hoursLabel() : '' }}</td>
      <td class="small">{{ $p->guest_passes }} guest passes · {{ (float) $p->fnb_discount_pct }}% F&amp;B · {{ (float) $p->spa_discount_pct }}% spa · {{ $p->freeze_days_max }} freeze days</td>
      <td class="num">{{ $p->live }}</td>
      <td class="num">@if(can_do('club.manage'))<button class="btn btn-sm" data-modal="planModal" data-title="Edit {{ $p->name }}" data-action="{{ route('club.plans.update', $p) }}" data-plan-json="{{ json_encode(['code' => $p->code, 'name' => $p->name, 'category' => $p->category, 'term_months' => $p->term_months, 'price' => (float) $p->price, 'joining_fee' => (float) $p->joining_fee, 'max_persons' => $p->max_persons, 'facilities' => $p->facilities, 'access_from' => $p->access_from ? substr($p->access_from, 0, 5) : '', 'access_to' => $p->access_to ? substr($p->access_to, 0, 5) : '', 'guest_passes' => $p->guest_passes, 'fnb_discount_pct' => (float) $p->fnb_discount_pct, 'spa_discount_pct' => (float) $p->spa_discount_pct, 'freeze_days_max' => $p->freeze_days_max, 'description' => $p->description, 'is_active' => $p->is_active]) }}">Edit</button>@endif</td></tr>
  @endforeach</tbody></table></div></div>

<div class="card mt-1" id="clubSettings"><div class="card-head"><h3>Club settings</h3></div><div class="card-body">
  <form method="post" action="{{ route('club.settings') }}">@csrf<div class="grid g-3" style="gap:.2rem 1rem">
    @foreach($settings as $k => $s)<div class="field"><label for="s_{{ $k }}">{{ $s['label'] }}</label><input type="number" step="any" min="0" name="{{ $k }}" id="s_{{ $k }}" value="{{ $s['value'] }}" @disabled(! can_do('club.manage'))></div>@endforeach
  </div>@if(can_do('club.manage'))<button class="btn btn-primary mt-1" id="settingsSave">Save settings</button>@endif</form>
  <p class="tiny muted">GL: subscriptions Dr 1120 Club Members Receivable / Cr 2130 Membership Fees Received in Advance + output tax; released monthly to 4042 Health Club Membership Revenue. Joining fees, guest passes, day passes → 4043. Room-charged day passes post through the guest folio (category "club").</p>
</div></div>

@if(can_do('club.manage'))
<div class="modal" id="planModal"><div class="modal-box" style="max-width:680px"><form method="post" action="" id="planForm">@csrf
  <div class="modal-head"><h3 data-title>Plan</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="code" label="Code" required placeholder="FULL-M"/><x-field name="name" label="Name" required/>
    <x-field name="category" label="Category" type="select" :options="\App\Models\ClubPlan::CATEGORIES" :placeholder="false"/>
    <x-field name="term_months" label="Term" type="select" :options="\App\Models\ClubPlan::TERMS" :placeholder="false"/>
    <x-field name="price" label="Price per term (excl. tax)" type="number" step="0.01" min="1" required/>
    <x-field name="joining_fee" label="Joining fee (excl. tax)" type="number" step="0.01" min="0"/>
    <x-field name="max_persons" label="Card holders" type="number" min="1" max="8" required/>
    <x-field name="freeze_days_max" label="Freeze days per term" type="number" min="0" max="180"/>
    <div class="field span-2"><label>Facilities</label><div class="flex gap-2" style="flex-wrap:wrap">@foreach(\App\Models\ClubPlan::FACILITIES as $k => $l)<label class="check"><input type="checkbox" name="facilities[]" value="{{ $k }}"> <span>{{ $l }}</span></label>@endforeach</div></div>
    <x-field name="access_from" label="Off-peak from" type="time"/><x-field name="access_to" label="Off-peak to" type="time"/>
    <x-field name="guest_passes" label="Free guest passes / month" type="number" min="0" max="20"/>
    <x-field name="fnb_discount_pct" label="F&B discount %" type="number" step="0.5" min="0" max="50"/>
    <x-field name="spa_discount_pct" label="Spa discount %" type="number" step="0.5" min="0" max="50"/>
    <label class="check" style="align-self:flex-end"><input type="checkbox" name="is_active" value="1"> <span>On sale</span></label>
    <x-field name="description" label="Description" class="span-2"/>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="planSave">Save plan</button></div>
</form></div></div>
@endif
@endsection
@push('scripts')
<script>
document.addEventListener('click', e => { const b = e.target.closest('[data-modal=planModal]'); if (!b) return; setTimeout(() => { const f = document.getElementById('planForm');
  const d = b.dataset.planJson ? JSON.parse(b.dataset.planJson) : {term_months: 1, max_persons: 1, freeze_days_max: 30, is_active: true, facilities: ['gym']};
  if (!b.dataset.planJson) f.reset();
  Object.entries(d).forEach(([k, v]) => { if (k === 'facilities') f.querySelectorAll('[name="facilities[]"]').forEach(c => c.checked = (v || []).includes(c.value)); else if (k === 'is_active') f.is_active.checked = !!v; else if (f[k]) f[k].value = v ?? ''; }); }, 0); });
</script>
@endpush
