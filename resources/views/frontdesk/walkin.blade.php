@extends('layouts.app')
@section('title', 'Walk-in Check-in')
@section('crumbs') Front Office / Walk-in @endsection
@section('content')
@php
  $ratePlanData = $ratePlans->groupBy('room_type_id')->map(fn ($group) => $group->map(fn ($plan) => [
      'id' => $plan->id, 'name' => $plan->name, 'rate' => $plan->rate, 'room_type_id' => $plan->room_type_id,
  ])->values());
  $oldRatePlan = $ratePlans->firstWhere('id', (int) old('rate_plan_id'));
  $restoreManualRate = session()->hasOldInput('rate')
      && (! $oldRatePlan || abs((float) old('rate') - (float) $oldRatePlan->rate) > 0.005);
  $canQuoteRates = can_do('reservations.view');
@endphp
@push('head')
<style>
  .walkin-page { --walkin-green:#17623b; --walkin-soft:#eff7f0; }
  .walkin-hero { display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; margin-bottom:1rem; padding:1.1rem 1.25rem; border:1px solid var(--c-border); border-radius:var(--radius); background:linear-gradient(115deg,var(--walkin-soft),var(--c-surface) 72%); }
  .walkin-eyebrow { margin:0 0 .2rem; color:var(--walkin-green); font-size:.68rem; font-weight:800; letter-spacing:.09em; text-transform:uppercase; }
  .walkin-toggle { display:inline-flex; gap:.25rem; padding:.2rem; border:1px solid var(--c-border-strong); border-radius:999px; background:var(--c-surface); }
  .walkin-toggle-opt { position:relative; cursor:pointer; }
  .walkin-toggle-opt input { position:absolute; inset:0; width:100%; height:100%; margin:0; opacity:0; cursor:pointer; }
  .walkin-toggle-opt span { display:block; padding:.32rem 1.15rem; border-radius:999px; font-size:.83rem; font-weight:600; color:var(--c-text-2); }
  .walkin-toggle-opt:has(input:checked) span { background:var(--walkin-green); color:#fff; }
  .walkin-toggle-opt:has(input:focus-visible) span { outline:2px solid var(--walkin-green); outline-offset:2px; }
  body.dark .walkin-toggle { border-color:#2f4538; background:#16211b; }
  body.dark .walkin-toggle-opt span { color:#c7d6cc; }
  body.dark .walkin-toggle-opt:has(input:checked) span { background:#2f7d52; color:#fff; }
  .walkin-hero h1 { margin:0; color:var(--c-text); font-size:1.35rem; line-height:1.25; }
  .walkin-hero p { margin:.3rem 0 0; color:var(--c-text-2); font-size:.83rem; }
  .walkin-hero-meta { display:flex; flex-wrap:wrap; gap:.45rem; justify-content:flex-end; }
  .walkin-chip { display:inline-flex; align-items:center; gap:.35rem; padding:.34rem .6rem; border:1px solid var(--c-border); border-radius:999px; background:var(--c-surface); color:var(--c-text-2); font-size:.71rem; font-weight:700; white-space:nowrap; }
  .walkin-layout { display:grid; grid-template-columns:minmax(0,1fr) minmax(300px,350px); gap:1rem; align-items:start; }
  .walkin-main { min-width:0; }
  .walkin-card { overflow:hidden; margin-bottom:1rem; background:var(--c-surface); border:1px solid var(--c-border); border-radius:var(--radius); box-shadow:var(--shadow-sm); }
  .walkin-card-head { display:flex; justify-content:space-between; align-items:center; gap:.75rem; padding:.8rem 1rem; border-bottom:1px solid var(--c-border); }
  .walkin-card-head h2 { display:flex; align-items:center; gap:.45rem; margin:0; color:var(--c-text); font-size:.92rem; }
  .walkin-card-head p { margin:.18rem 0 0; color:var(--c-text-2); font-size:.73rem; }
  .walkin-card-body { padding:1rem; }
  .walkin-card-body>.form-grid { margin:0; }
  .walkin-card-body>.form-grid>.field { min-width:0; }
  .walkin-section-label { margin:1rem 0 .55rem; padding-bottom:.38rem; border-bottom:1px dashed var(--c-border); color:var(--c-text-2); font-size:.7rem; font-weight:800; letter-spacing:.065em; text-transform:uppercase; }
  .walkin-profile-note { display:flex; justify-content:space-between; align-items:center; gap:.7rem; margin-top:.8rem; padding:.6rem .75rem; border:1px solid var(--c-border); border-radius:var(--radius-sm); background:var(--c-bg); color:var(--c-text-2); font-size:.73rem; }
  .walkin-profile-note[data-state="selected"] { border-color:#c9dfce; background:var(--walkin-soft); color:#214b2e; }
  .walkin-room-note { margin:.45rem 0 0; color:var(--c-text-2); font-size:.72rem; }
  .walkin-room-preview { margin-top:.65rem; padding:.55rem .7rem; border:1px solid var(--c-border); border-radius:var(--radius-sm); background:var(--c-bg); color:var(--c-text-2); font-size:.76rem; }
  .walkin-room-preview b { color:var(--c-text); }
  .walkin-room-preview[data-state="ready"] { border-color:#c9dfce; background:var(--walkin-soft); color:#214b2e; }
  .walkin-room-preview[data-state="attention"] { border-color:#f1d8a6; background:#fff8e8; color:#76510a; }
  body.dark .walkin-room-preview[data-state="ready"] { border-color:#31583d; background:#193425; color:#c1e6c9; }
  body.dark .walkin-room-preview[data-state="attention"] { border-color:#665126; background:#332a18; color:#f4cf75; }
  .walkin-rate-guidance { display:flex; grid-column:1/-1; align-items:center; gap:.6rem; }
  .walkin-rate-note { flex:1; margin:0; padding:.55rem .7rem; border:1px solid var(--c-border); border-radius:var(--radius-sm); background:var(--c-bg); color:var(--c-text-2); font-size:.72rem; }
  .walkin-rate-note[data-state="contract"] { border-color:#c9dfce; background:var(--walkin-soft); color:#214b2e; }
  .walkin-rate-note[data-state="manual"] { border-color:#f1d8a6; background:#fff8e8; color:#76510a; }
  .walkin-billing-note { grid-column:1/-1; margin:-.55rem 0 0; padding:.55rem .7rem; border:1px solid var(--c-border); border-radius:var(--radius-sm); background:var(--c-bg); color:var(--c-text-2); font-size:.72rem; }
  .walkin-company-picker { min-width:0; }
  .walkin-company-picker .field { margin:0; }
  .walkin-company-create { margin-top:.4rem; }
  .walkin-company-status { grid-column:1/-1; margin:-.55rem 0 0; color:var(--walkin-green); font-size:.72rem; }
  .walkin-billing-note[data-state="company"] { border-color:#c9dfce; background:var(--walkin-soft); color:#214b2e; }
  body.dark .walkin-billing-note[data-state="company"] { border-color:#31583d; background:#193425; color:#c1e6c9; }
  body.dark .walkin-rate-note[data-state="contract"] { border-color:#31583d; background:#193425; color:#c1e6c9; }
  body.dark .walkin-rate-note[data-state="manual"] { border-color:#665126; background:#332a18; color:#f4cf75; }
  .walkin-side { position:sticky; top:calc(var(--top-h,64px) + 1rem); }
  .walkin-summary { padding:1rem; }
  .walkin-summary-title { margin:0 0 .1rem; color:var(--c-text); font-size:.88rem; font-weight:750; }
  .walkin-summary-note { margin:0 0 .65rem; color:var(--c-muted); font-size:.7rem; }
  .walkin-summary-list { display:grid; gap:.25rem; margin:0; }
  .walkin-summary-row { display:flex; justify-content:space-between; gap:.75rem; padding:.5rem 0; border-bottom:1px solid var(--c-border); font-size:.77rem; }
  .walkin-summary-row span { color:var(--c-text-2); }
  .walkin-summary-row b { color:var(--c-text); text-align:right; }
  .walkin-total { display:flex; justify-content:space-between; align-items:baseline; gap:.75rem; margin-top:.75rem; padding:.8rem; border:1px solid #c9dfce; border-radius:var(--radius-sm); background:var(--walkin-soft); color:#214b2e; }
  .walkin-total span { font-size:.76rem; font-weight:700; }
  .walkin-total b { font-size:1.05rem; }
  .walkin-deposit { margin-top:.7rem; padding:.7rem; border:1px dashed var(--c-border-strong); border-radius:var(--radius-sm); }
  .walkin-deposit h3 { margin:0 0 .15rem; color:var(--c-text); font-size:.78rem; }
  .walkin-deposit p { margin:0; color:var(--c-muted); font-size:.7rem; }
  .walkin-submit { width:100%; margin-top:.8rem; }
  .walkin-errors { margin-bottom:1rem; }
  body.dark .walkin-hero { --walkin-soft:#193425; }
  body.dark .walkin-profile-note[data-state="selected"] { border-color:#31583d; background:#193425; color:#c1e6c9; }
  body.dark .walkin-total { border-color:#31583d; background:#193425; color:#c1e6c9; }
  @media(max-width:980px) { .walkin-layout{grid-template-columns:minmax(0,1fr) 300px} }
  @media(max-width:780px) { .walkin-layout{grid-template-columns:1fr}.walkin-side{position:static}.walkin-hero{display:block;padding:.9rem}.walkin-hero-meta{justify-content:flex-start;margin-top:.7rem} }
  @media(max-width:560px) { .walkin-card-body{padding:.8rem}.walkin-card-head{align-items:flex-start;flex-direction:column}.walkin-rate-guidance,.walkin-profile-note{align-items:stretch;flex-direction:column}.walkin-submit{position:sticky;bottom:.5rem;z-index:4;box-shadow:0 5px 18px #0002} }
</style>
@endpush

<div class="walkin-page">
  <header class="walkin-hero">
    <div>
      <p class="walkin-eyebrow">Front Office · Guest arrival</p>
      <h1>Walk-in check-in</h1>
      <p>Create a guest profile, assign a vacant room, and check in without a prior reservation.</p>
    </div>
    <div class="walkin-hero-meta" aria-label="Walk-in context">
      <span class="walkin-chip"><x-icon name="calendar" size="sm"/> Business date · {{ bdate()->format('d M Y') }}</span>
      <span class="walkin-chip"><x-icon name="bed" size="sm"/> {{ $rooms->count() }} vacant room(s)</span>
    </div>
  </header>

  @if($errors->any())
    <div class="alert alert-warn walkin-errors" role="alert" aria-labelledby="walkinErrorTitle">
      <x-icon name="alert"/>
      <div><b id="walkinErrorTitle">Please review the walk-in details.</b><ul class="list" style="margin:.3rem 0 0;padding-left:1.1rem">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    </div>
  @endif

  <form method="post" action="{{ route('frontdesk.walkin.store') }}" id="walkinForm">
    @csrf
    <div class="walkin-layout">
      <div class="walkin-main">
        <section class="walkin-card" aria-labelledby="walkinRoomTitle">
          <div class="walkin-card-head">
            <div><h2 id="walkinRoomTitle"><x-icon name="bed"/> Room &amp; stay</h2><p>Room availability is checked again when the stay is saved.</p>@if($canQuoteRates)<p>Contract pricing refreshes for the selected company and stay dates.</p>@else<p>Enter the agreed rate; contract quote refresh requires reservation-view access.</p>@endif</div>
            <span class="tiny muted">Arrival: {{ bdate()->format('d M Y') }}</span>
          </div>
          <div class="walkin-card-body">
            <div class="form-grid">
              <div class="field span-2">
                <label for="room_id">Vacant room <span class="req">*</span></label>
                <select name="room_id" id="room_id" required @class(['is-invalid' => $errors->has('room_id')])>
                  <option value="">Select a vacant room</option>
                  @foreach($rooms->groupBy(fn ($room) => $room->roomType?->name ?? 'Other room type') as $typeName => $roomList)
                    <optgroup label="{{ $typeName }}">
                      @foreach($roomList as $room)
                        <option value="{{ $room->id }}" data-room="{{ $room->room_no }}" data-type="{{ $room->room_type_id }}" data-rate="{{ $room->roomType?->base_rate ?? 0 }}" data-hk="{{ $room->hk_status }}" @selected((string) old('room_id', $preRoom) === (string) $room->id)>
                          {{ $room->room_no }} · {{ ucfirst(str_replace('_', ' ', $room->hk_status)) }}
                        </option>
                      @endforeach
                    </optgroup>
                  @endforeach
                </select>
                @error('room_id')<span class="err">{{ $message }}</span>@enderror
                @if($rooms->isEmpty())<span class="err">No vacant rooms are available for the current business-date arrival. Check room readiness and inventory before starting a walk-in.</span>@else<span class="walkin-room-note">Clean and inspected rooms are marked ready. Other housekeeping states require confirmation with Housekeeping before arrival.</span>@endif
                <div class="walkin-room-preview" id="walkinRoomPreview" aria-live="polite">Select a vacant room to see its room and housekeeping details.</div>
              </div>
              <div class="field span-2">
                <label id="stayTypeLabel">Stay <span class="req">*</span></label>
                <div class="walkin-toggle" role="radiogroup" aria-labelledby="stayTypeLabel">
                  <label class="walkin-toggle-opt">
                    <input type="radio" name="stay_type" value="overnight" id="stayTypeOvernight" @checked(old('stay_type', 'day_use') === 'overnight')>
                    <span>Night</span>
                  </label>
                  <label class="walkin-toggle-opt">
                    <input type="radio" name="stay_type" value="day_use" id="stayTypeDayUse" @checked(old('stay_type', 'day_use') === 'day_use')>
                    <span>Day</span>
                  </label>
                </div>
                @error('stay_type')<span class="err">{{ $message }}</span>@enderror
              </div>
              {{-- The night count stays on the form whichever stay type is chosen; a day use
                   only explains that today's room is charged once, not per night. --}}
              <div class="field" id="walkinNightsField">
                <label for="f_nights">Nights <span class="req">*</span></label>
                <input type="number" name="nights" id="f_nights" min="1" max="90" value="{{ old('nights', 1) }}">
                @error('nights')<span class="err">{{ $message }}</span>@enderror
              </div>
              <div class="field"><label for="depDate">Scheduled departure</label><input id="depDate" readonly aria-live="polite"></div>
              {{-- Day: how many hours the room is needed for. The record keeps the hour it runs
                   until, which is what the rosters and the folio read. --}}
              <div class="field" id="walkinDayUseField" @if(old('stay_type', 'day_use') === 'overnight') hidden @endif>
                <label for="f_day_use_hours">Hours <span class="req">*</span></label>
                <select name="day_use_hours" id="f_day_use_hours" class="@error('day_use_hours') is-invalid @enderror">
                  @foreach([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12] as $hours)
                    <option value="{{ $hours }}" @selected((int) old('day_use_hours', 4) === $hours)>{{ $hours }} {{ $hours === 1 ? 'hour' : 'hours' }}</option>
                  @endforeach
                </select>
                @error('day_use_hours')<span class="err">{{ $message }}</span>@enderror
              </div>
              <x-field name="rate_plan_id" label="Rate plan" type="select" :options="[]" placeholder="Rack rate"/>
              <x-field name="rate" label="Rate / night" type="number" step="0.01" min="0" required/>
              <x-field name="adults" label="Adults" type="number" min="1" max="10" value="1" required/>
              <x-field name="children" label="Children" type="number" min="0" max="10" value="0"/>
              <x-field name="business_source_id" label="Booking source" type="select" :options="$sources" :value="$walkInSource"/>
              <div class="walkin-company-picker">
                <x-field name="company_id" label="Company / account" type="select" :options="$companies"/>
                @if(can_do('credit.manage'))<button type="button" class="btn btn-sm btn-ghost walkin-company-create" id="walkinCreateCityLedger" data-modal="walkinCompanyCreateModal"><x-icon name="plus" size="sm"/> Create City Ledger / Company</button>@endif
              </div>
              <p class="walkin-billing-note" id="walkinBillingNote" aria-live="polite">No company account selected; guest billing is the default.</p>
              <p class="walkin-company-status" id="walkinCompanyStatus" aria-live="polite" hidden></p>
              <x-field name="purpose" label="Purpose of visit" type="select" :options="['Leisure'=>'Leisure','Business'=>'Business','Medical'=>'Medical','Other'=>'Other']"/>
              <div class="walkin-rate-guidance span-all">
                <p class="walkin-rate-note" id="walkinRateNote" aria-live="polite">Choose a vacant room to see the applicable rate guidance.</p>
                @if($canQuoteRates)<button class="btn btn-sm btn-ghost" id="walkinRateRefresh" type="button" disabled><x-icon name="refresh" size="sm"/> Refresh suggested rate</button>@endif
              </div>
            </div>
          </div>
        </section>

        <section class="walkin-card" aria-labelledby="walkinGuestTitle">
          <div class="walkin-card-head"><div><h2 id="walkinGuestTitle"><x-icon name="user"/> Guest registration</h2><p>Enter the guest’s details or look up an existing profile if your role permits.</p></div></div>
          <div class="walkin-card-body">
            @include('reservations._guest', ['allowCustomGuestTitle' => true])
            <div class="walkin-profile-note" id="walkinGuestLink" aria-live="polite">
              <span id="walkinGuestLinkText">No existing profile is selected. A profile with the same first name and mobile may be reused on save.</span>
              <button type="button" class="btn btn-sm btn-ghost" id="walkinGuestUnlink" hidden>Clear selected-profile link</button>
            </div>
          </div>
        </section>

        <section class="walkin-card" aria-labelledby="walkinRemarksTitle">
          <div class="walkin-card-head"><div><h2 id="walkinRemarksTitle"><x-icon name="message"/> Stay notes</h2><p>Keep operational remarks concise and relevant to this stay.</p></div></div>
          <div class="walkin-card-body"><x-field name="special_request" label="Remarks / special request" type="textarea" placeholder="Optional requests or arrival notes…" maxlength="1000"/></div>
        </section>
      </div>

      <aside class="walkin-side" aria-label="Walk-in charges and deposit">
        <section class="walkin-card" aria-labelledby="walkinSummaryTitle">
          <div class="walkin-card-head"><h2 id="walkinSummaryTitle"><x-icon name="receipt"/> Charges &amp; deposit</h2></div>
          <div class="walkin-summary">
            <p class="walkin-summary-title">Stay estimate</p>
            <p class="walkin-summary-note">Room charges only · before applicable taxes and extras</p>
            <dl class="walkin-summary-list">
              <div class="walkin-summary-row"><span>Room</span><b id="walkinSummaryRoom">Not selected</b></div>
              <div class="walkin-summary-row"><span>Rate / night</span><b id="walkinSummaryRate">—</b></div>
              <div class="walkin-summary-row"><span>Duration</span><b id="walkinSummaryNights">1 night</b></div>
            </dl>
            <div class="walkin-total"><span>Estimated room charges</span><b id="est">—</b></div>
            <div class="walkin-deposit" aria-live="polite"><h3>Advance / deposit</h3><p>Recorded against the guest folio at check-in.</p><div class="walkin-summary-row" style="border:0;padding-bottom:0"><span>Payment received</span><b id="walkinDepositSummary">—</b></div></div>
          </div>
          <div class="walkin-card-body" style="border-top:1px solid var(--c-border)">
            <div class="walkin-section-label" style="margin-top:0">Single deposit</div>
            <div id="walkinAdvanceSingle">
              <x-field name="advance" label="Amount" type="number" step="0.01" min="0" value="0"/><div class="mt-1"></div>
              <x-field name="advance_mode" label="Payment method" type="select" :options="$paymentModes" data-payment-mode/><div class="mt-1"></div>
              <x-payment-momo-fields mode-name="advance_mode"/>
              <div class="mt-1"></div><x-field name="advance_ref" label="Reference / transaction ID" maxlength="80"/>
              <x-cheque-fields/>
            </div>
            <x-payment-split-tender widget-id="walkinAdvanceSplit" single-id="walkinAdvanceSingle" :modes="$paymentModes" label="deposit"/>
            @error('payments')<span class="err">{{ $message }}</span>@enderror
          </div>
          <div class="walkin-card-body" style="padding-top:0">
            <button class="btn btn-success btn-lg btn-block walkin-submit" type="submit" @disabled($rooms->isEmpty())><x-icon name="login"/> Check in walk-in</button>
            @if($rooms->isEmpty())<p class="tiny muted" style="margin:.5rem 0 0">This action is unavailable until a vacant room can be selected.</p>@endif
          </div>
        </section>
      </aside>
    </div>
  </form>

  @include('reservations._city-ledger-create', [
    'prefix' => 'walkinCompanyCreate',
    'action' => route('frontdesk.walkin.company.store'),
  ])
</div>
@endsection
@push('scripts')
<script>
(() => {
  const plans = @json($ratePlanData);
  const businessDate = '{{ bdate()->toDateString() }}';
  const selectedPlanOnLoad = @json((string) old('rate_plan_id', ''));
  const restoreManualRate = @json($restoreManualRate);
  const canQuoteRates = @json($canQuoteRates);
  const currencySymbol = @json($currentHotel->currency_symbol ?? $currentHotel->currency ?? 'GHS');
  const companyBilling = @json($companyBilling);
  const room = document.getElementById('room_id');
  const plan = document.getElementById('f_rate_plan_id');
  const rate = document.getElementById('f_rate');
  const nights = document.getElementById('f_nights');
  const stayTypeInputs = [...document.querySelectorAll('input[name="stay_type"]')];
  const dayUseField = document.getElementById('walkinDayUseField');
  const dayUseHours = document.getElementById('f_day_use_hours');
  const rateFieldLabel = rate?.closest('.field')?.querySelector('label') ?? null;
  const summaryRateLabel = document.getElementById('walkinSummaryRate')?.previousElementSibling ?? null;
  const isDayUse = () => stayTypeInputs.some(input => input.checked && input.value === 'day_use');
  const dayUseHoursCount = () => {
    const hours = Number.parseInt(dayUseHours?.value ?? '', 10);

    return Number.isFinite(hours) && hours > 0 ? hours : 4;
  };
  const dayUseHoursText = () => `${dayUseHoursCount()} ${dayUseHoursCount() === 1 ? 'hour' : 'hours'}`;
  // A day use is sold by the hour: the rate on the form is what an hour costs and the
  // charge is the hours bought times that rate — one charge, no night in the sum.
  const dayHourlyRate = () => Math.max(0, Number.parseFloat(rate?.value) || 0);
  const dayUseCharge = () => dayHourlyRate() * dayUseHoursCount();
  const adults = document.getElementById('f_adults');
  const company = document.getElementById('f_company_id');
  const billingNote = document.getElementById('walkinBillingNote');
  const roomPreview = document.getElementById('walkinRoomPreview');
  const rateNote = document.getElementById('walkinRateNote');
  const rateRefresh = document.getElementById('walkinRateRefresh');
  const requestUrl = @json(route('reservations.api.rates'));
  document.addEventListener('city-ledger-created', event => {
    const account = event.detail;
    if (!company || !account?.id) return;
    companyBilling[String(account.id)] = Boolean(account.direct_billing);
    let option = [...company.options].find(item => item.value === String(account.id));
    if (!option) {
      option = document.createElement('option');
      option.value = String(account.id);
      option.textContent = account.name;
      company.append(option);
    }
    company.value = String(account.id);
    company.dispatchEvent(new Event('change', {bubbles:true}));
    const status = document.getElementById('walkinCompanyStatus');
    if (status) {
      status.textContent = `${account.name} was created and selected. The direct-billing account is available in Finance → City Ledger.`;
      status.hidden = false;
    }
  });
  if (!room || !plan || !rate || !nights) return;

  const dateFromBusinessDay = (value) => {
    const [year, month, day] = value.split('-').map(Number);
    return new Date(year, month - 1, day);
  };
  const dateValue = date => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
  const moneyText = amount => `${currencySymbol} ${Number(amount || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
  const chosenRoom = () => room.selectedOptions[0];
  const syncRateRefresh = () => {
    if (rateRefresh) rateRefresh.disabled = !chosenRoom()?.value || !!plan.value;
  };
  const setRateNote = (message, state = '') => {
    if (!rateNote) return;
    rateNote.textContent = message;
    rateNote.dataset.state = state;
  };
  const updateBillingNote = () => {
    if (!billingNote || !company?.value) {
      if (billingNote) {
        billingNote.textContent = 'No company account selected; guest billing is the default.';
        billingNote.dataset.state = '';
      }
      return;
    }
    if (Boolean(companyBilling[String(company.value)])) {
      billingNote.textContent = 'Direct billing is enabled for this company. The walk-in will use the company billing instruction; this does not post any charge now.';
      billingNote.dataset.state = 'company';
    } else {
      billingNote.textContent = 'Direct billing is off for this company. The guest remains responsible for charges.';
      billingNote.dataset.state = '';
    }
  };
  const guestId = document.getElementById('guest_id');
  const guestLink = document.getElementById('walkinGuestLink');
  const guestLinkText = document.getElementById('walkinGuestLinkText');
  const guestUnlink = document.getElementById('walkinGuestUnlink');
  const guestName = () => [
    document.querySelector('[name="guest[first_name]"]')?.value,
    document.querySelector('[name="guest[last_name]"]')?.value,
  ].filter(Boolean).join(' ') || 'Guest';
  const showLinkedGuest = () => {
    if (!guestId?.value || !guestLink || !guestLinkText) return;
    guestLink.dataset.state = 'selected';
    guestLinkText.textContent = `Existing profile selected: ${guestName()}. Editing name, mobile, email or ID details clears this profile link.`;
    if (guestUnlink) guestUnlink.hidden = false;
  };
  const clearLinkedGuest = () => {
    if (!guestId?.value) return;
    guestId.value = '';
    guestLink?.removeAttribute('data-state');
    if (guestUnlink) guestUnlink.hidden = true;
    if (guestLinkText) guestLinkText.textContent = 'The profile link was cleared. On save, an exact first-name and mobile match may still be reused; otherwise a new profile is created.';
  };
  const guestLookupResults = document.getElementById('guestResults');
  guestLookupResults?.addEventListener('click', event => {
    if (event.target.closest('[role="option"]') && guestId?.value) showLinkedGuest();
  });
  ['title','first_name','last_name','phone','email','nationality','id_type','id_number'].forEach(key => {
    document.querySelector(`[name="guest[${key}]"]`)?.addEventListener('input', clearLinkedGuest);
  });
  document.querySelector('[name="guest[title]"]')?.addEventListener('change', clearLinkedGuest);
  document.getElementById('guestCustomTitle')?.addEventListener('input', clearLinkedGuest);
  guestUnlink?.addEventListener('click', clearLinkedGuest);
  if (guestId?.value) showLinkedGuest();
  let planToRestore = selectedPlanOnLoad;
  let priceRequest = 0;
  let rateQuoteTotal = null;
  let manualRate = restoreManualRate;
  let roomInitialized = false;

  const updateSummary = () => {
    const roomOption = chosenRoom();
    const dayUse = isDayUse();
    const count = Math.max(1, Number.parseInt(nights.value, 10) || 1);
    const nightly = Math.max(0, Number.parseFloat(rate.value) || 0);
    const roomName = roomOption?.value ? roomOption.textContent.trim() : 'Not selected';
    const departure = dateFromBusinessDay(businessDate);
    departure.setDate(departure.getDate() + count);
    // A day use is over today: there is no night to count and one charge to make.
    document.getElementById('depDate').value = dayUse
      ? `Same day · ${dayUseHoursText()}`
      : departure.toLocaleDateString(undefined, {day:'2-digit', month:'short', year:'numeric'});
    document.getElementById('walkinSummaryRoom').textContent = roomName;
    document.getElementById('walkinSummaryRate').textContent = roomOption?.value ? moneyText(dayUse ? dayHourlyRate() : nightly) : '—';
    document.getElementById('walkinSummaryNights').textContent = dayUse
      ? `Day use · ${dayUseHoursText()}`
      : `${count} night${count === 1 ? '' : 's'}`;
    const estimate = dayUse ? dayUseCharge() : (rateQuoteTotal !== null && !manualRate ? rateQuoteTotal : nightly * count);
    document.getElementById('est').textContent = roomOption?.value ? moneyText(estimate) : '—';
  };

  // Day use: no nights to enter, a time the room is needed until, and one charge
  // at the agreed day-use rate — never a silent multiple of the nightly rate.
  const applyStayType = (options = {}) => {
    const dayUse = isDayUse();
    // The night count is never taken off the form: the desk keeps it in front of them
    // whichever stay type is chosen, so a booking line can always record its nights.
    if (nights && !Number.parseInt(nights.value, 10)) nights.value = '1';
    if (dayUseField) dayUseField.hidden = !dayUse;
    // Folded away is not the same as switched off: a hidden select still posts and can
    // still be tabbed into, so a night stay sends no hours at all.
    if (dayUseHours) dayUseHours.disabled = !dayUse;
    if (rateFieldLabel) rateFieldLabel.firstChild.textContent = dayUse ? 'Rate / hour ' : 'Rate / night ';
    if (summaryRateLabel) summaryRateLabel.textContent = dayUse ? 'Rate / hour' : 'Rate / night';
    if (dayUse && !options.initial && !manualRate) {
      // The nightly quote is not the day-use price: clear it so the desk names one.
      rate.value = '';
      rate.title = '';
      rate.style.borderColor = '';
      rateQuoteTotal = null;
      setRateNote('Day use selected. No night is sold — enter the rate per hour; the guest is charged for the hours bought.', 'manual');
    }
    if (!dayUse && !options.initial) refreshRateForContext('stay type');
    updateSummary();
  };
  stayTypeInputs.forEach(input => input.addEventListener('change', () => applyStayType()));
  // The hours are what the guest is charged for, so changing them re-prices the
  // estimate; the departure line follows the same count.
  dayUseHours?.addEventListener('change', updateSummary);

  const updateRoomPreview = () => {
    const option = chosenRoom();
    if (!option?.value) {
      roomPreview.removeAttribute('data-state');
      roomPreview.textContent = 'Select a vacant room to see its room and housekeeping details.';
      return;
    }
    const typeName = room.selectedOptions[0].parentElement?.label || 'Room';
    const status = (option.dataset.hk || 'unknown').replaceAll('_', ' ');
    const isReady = ['clean', 'inspected'].includes(option.dataset.hk);
    roomPreview.dataset.state = isReady ? 'ready' : 'attention';
    roomPreview.replaceChildren();
    const label = document.createElement('b');
    label.textContent = `${option.dataset.room || room.selectedOptions[0].textContent.trim()} · ${typeName}`;
    const details = document.createElement('span');
    details.textContent = isReady
      ? ` · Housekeeping: ${status} · Ready for arrival.`
      : ` · Housekeeping: ${status} · Confirm readiness with Housekeeping before arrival.`;
    roomPreview.append(label, details);
  };

  const populatePlans = () => {
    const option = chosenRoom();
    const restore = plan.value || planToRestore;
    plan.replaceChildren(new Option('Rack rate', ''));
    const available = option?.dataset.type ? (plans[String(option.dataset.type)] || []) : [];
    available.forEach(item => {
      const planOption = document.createElement('option');
      planOption.value = String(item.id);
      planOption.dataset.rate = String(item.rate ?? '');
      planOption.textContent = `${item.name} · ${moneyText(item.rate)} per night`;
      plan.appendChild(planOption);
    });
    if (restore && available.some(item => String(item.id) === String(restore))) plan.value = String(restore);
    planToRestore = '';
  };

  const fetchSeasonRate = () => {
    const option = chosenRoom();
    if (!option?.dataset.type || plan.value || manualRate) return;
    if (isDayUse()) {
      setRateNote(`Day use is charged once. The room's nightly rate is ${moneyText(option.dataset.rate)} — enter the agreed day-use charge.`, 'manual');
      return;
    }
    if (!canQuoteRates) {
      setRateNote('Contract rate refresh is not available to this role. Confirm or enter the agreed nightly rate.', 'manual');
      return;
    }
    setRateNote('Checking the current public or company-contract rate for these dates…');
    const departure = dateFromBusinessDay(businessDate);
    departure.setDate(departure.getDate() + Math.max(1, Number.parseInt(nights.value, 10) || 1));
    const params = new URLSearchParams({
      room_type_id: option.dataset.type,
      from: businessDate,
      to: dateValue(departure),
      company_id: company?.value || '',
      adults: String(Math.max(1, Number.parseInt(adults?.value, 10) || 1)),
    });
    const currentRequest = ++priceRequest;
    getJSON(`${requestUrl}?${params.toString()}`).then(result => {
      if (currentRequest !== priceRequest || !chosenRoom()?.value || plan.value || manualRate) return;
      if (!result || result.rate === null || result.rate === '' || !Number.isFinite(Number(result.rate))) {
        setRateNote('The rate service returned an incomplete quote. Review or enter the nightly rate before check-in.', 'manual');
        return;
      }
      rate.value = Number(result.rate).toFixed(2);
      const quotedTotal = Number(result.total);
      rateQuoteTotal = result.total !== null && result.total !== '' && Number.isFinite(quotedTotal)
        ? quotedTotal : Number(result.rate) * Math.max(1, Number.parseInt(nights.value, 10) || 1);
      rate.title = result.contract ? `Contract ${result.contract.code} (public rate ${result.contract.public_rate})` : '';
      rate.style.borderColor = result.contract ? 'var(--c-green-500, #16a34a)' : '';
      updateSummary();
      const quoteTotalText = moneyText(rateQuoteTotal);
      const quoteNights = Math.max(1, Number.parseInt(nights.value, 10) || 1);
      setRateNote(result.contract
        ? `Company contract ${result.contract.code} applied · exact ${quoteNights}-night room quote ${quoteTotalText}.`
        : `Current public rate applied · exact ${quoteNights}-night room quote ${quoteTotalText}.`, result.contract ? 'contract' : '');
    }).catch(() => {
      if (currentRequest === priceRequest) {
        rate.title = 'Could not refresh the current rate; the displayed amount remains available for review.';
        setRateNote('Rate refresh failed. Review the displayed nightly rate and adjust it if needed.', 'manual');
      }
    });
  };

  rateRefresh?.addEventListener('click', () => {
    if (!chosenRoom()?.value || plan.value) return;
    manualRate = false;
    rateQuoteTotal = null;
    rate.value = chosenRoom()?.dataset.rate || '';
    rate.title = '';
    rate.style.borderColor = '';
    updateSummary();
    fetchSeasonRate();
  });

  room.addEventListener('change', () => {
    const restoreManualRate = !roomInitialized && manualRate;
    populatePlans();
    syncRateRefresh();
    updateRoomPreview();
    const selectedPlan = plan.selectedOptions[0];
    manualRate = restoreManualRate;
    rateQuoteTotal = null;
    if (!manualRate) rate.value = selectedPlan?.dataset.rate || chosenRoom()?.dataset.rate || '';
    rate.title = '';
    rate.style.borderColor = '';
    if (!chosenRoom()?.value) {
      setRateNote('Choose a vacant room to see the applicable rate guidance.');
    } else if (manualRate) {
      setRateNote(`The entered nightly rate ${moneyText(rate.value)} was kept after validation. Review it before checking in.`, 'manual');
    } else if (plan.value) {
      setRateNote(`Rate plan “${selectedPlan.textContent}” selected at ${moneyText(rate.value)} per night.`);
    } else if (!canQuoteRates) {
      setRateNote('Contract rate refresh is not available to this role. Confirm or enter the agreed nightly rate.', 'manual');
    }
    roomInitialized = true;
    updateSummary();
    fetchSeasonRate();
  });
  plan.addEventListener('change', () => {
    syncRateRefresh();
    manualRate = false;
    rateQuoteTotal = null;
    rate.value = plan.selectedOptions[0]?.dataset.rate || chosenRoom()?.dataset.rate || '';
    rate.title = '';
    rate.style.borderColor = '';
    updateSummary();
    if (plan.value) {
      setRateNote(`Rate plan “${plan.selectedOptions[0].textContent}” selected at ${moneyText(rate.value)} per night.`);
    } else if (!canQuoteRates) {
      setRateNote('Rack rate selected. Contract rate refresh is not available to this role; confirm the agreed amount.', 'manual');
    }
    fetchSeasonRate();
  });
  const refreshRateForContext = context => {
    rateQuoteTotal = null;
    if (isDayUse()) {
      // The nightly quote has nothing to say about an hourly rate.
      if (rateRefresh) rateRefresh.disabled = true;
      updateSummary();
      setRateNote('A day use is priced by the hour — enter the rate per hour. There is no nightly rate to refresh.', 'manual');
      return;
    }
    updateSummary();
    if (manualRate) {
      setRateNote(`The manually entered nightly rate was kept after the ${context} change. Review the amount before check-in.`, 'manual');
      return;
    }
    if (plan.value) {
      setRateNote(`The selected rate plan remains active after the ${context} change; the stay total has been recalculated.`);
      return;
    }
    if (!canQuoteRates) {
      setRateNote(`Rate quote refresh is not available to this role after the ${context} change. Confirm the agreed nightly rate.`, 'manual');
      return;
    }
    fetchSeasonRate();
  };
  nights.addEventListener('input', () => { priceRequest++; rateQuoteTotal = null; updateSummary(); });
  nights.addEventListener('change', () => refreshRateForContext('stay length'));
  company?.addEventListener('change', () => {
    updateBillingNote();
    refreshRateForContext('company');
  });
  adults?.addEventListener('change', () => refreshRateForContext('guest-count'));
  rate.addEventListener('input', () => {
    manualRate = true;
    rateQuoteTotal = null;
    updateSummary();
    setRateNote(`Manual nightly rate: ${moneyText(rate.value)}. The estimate recalculates from this amount.`, 'manual');
  });

  const updateDepositSummary = () => {
    const single = document.getElementById('walkinAdvanceSingle');
    const split = document.querySelector('#walkinAdvanceSplit [data-split-panel]');
    let total = 0;
    if (split && !split.hidden) {
      total = [...document.querySelectorAll('#walkinAdvanceSplit input[name$="[amount]"]:not(:disabled)')]
        .reduce((sum, input) => sum + (Number(input.value) || 0), 0);
    } else {
      const input = single?.querySelector('[name="advance"]');
      total = input && !input.disabled ? Number(input.value) || 0 : 0;
    }
    document.getElementById('walkinDepositSummary').textContent = moneyText(total);
  };
  const walkinForm = document.getElementById('walkinForm');
  walkinForm.addEventListener('input', event => {
    if (event.target.matches('[name="advance"],input[name$="[amount]"]')) updateDepositSummary();
  });
  walkinForm.addEventListener('change', updateDepositSummary);
  walkinForm.addEventListener('submit', event => {
    if (event.defaultPrevented) return;
    const guestName = [
      walkinForm.querySelector('[name="guest[first_name]"]')?.value,
      walkinForm.querySelector('[name="guest[last_name]"]')?.value,
    ].filter(Boolean).join(' ') || 'this guest';
    const roomName = chosenRoom()?.textContent.trim() || 'the selected room';
    const stayLength = Math.max(1, Number.parseInt(nights.value, 10) || 1);
    const stayWords = isDayUse() ? `a day use of ${dayUseHoursText()}` : `${stayLength} night(s)`;
    const estimated = document.getElementById('est').textContent;
    const deposit = document.getElementById('walkinDepositSummary').textContent;
    const housekeeping = chosenRoom()?.dataset.hk || 'unknown';
    const readinessWarning = ['clean', 'inspected'].includes(housekeeping) ? ''
      : ` WARNING: housekeeping is ${housekeeping.replaceAll('_', ' ')}. Confirm room readiness with Housekeeping before arrival.`;
    const message = `Check in ${guestName} to ${roomName} for ${stayWords}? Estimated room charges: ${estimated}. Deposit: ${deposit}.${readinessWarning} This creates or reuses the guest profile, checks the guest in and records any deposit.`;
    if (!window.confirm(message)) event.preventDefault();
  });
  document.getElementById('walkinAdvanceSplit')?.addEventListener('click', event => {
    if (event.target.closest('[data-split-toggle],[data-split-add],[data-split-remove]')) setTimeout(updateDepositSummary, 0);
  });

  updateBillingNote();
  applyStayType({ initial: true });
  if (room.value) room.dispatchEvent(new Event('change'));
  else { roomInitialized = true; updateRoomPreview(); updateSummary(); }
  updateDepositSummary();
})();
</script>
@endpush
