@extends('layouts.app')
@section('title', $rule->exists ? 'Edit Pricing Rule' : 'New Pricing Rule')
@section('crumbs') <a href="{{ route('revenue.index') }}">Revenue</a> / <a href="{{ route('revenue.rules') }}">Pricing Rules</a> / {{ $rule->exists ? $rule->name : 'New' }} @endsection
@section('actions')
<a class="btn btn-icon rvrf-action" href="{{ route('revenue.rules') }}" aria-label="Back to Pricing Rules" title="Pricing Rules"><x-icon name="chev" class="rvrf-chevron-left"/></a>
<a class="btn btn-icon rvrf-action" href="{{ route('revenue.pricing') }}" aria-label="Open Revenue Pricing Grid" title="Pricing Grid"><x-icon name="chart"/></a>
@endsection
@push('head')
<style>
.rvrf-page{max-width:1180px;margin:0 auto;min-width:0}.rvrf-action{min-width:36px;min-height:36px;justify-content:center}.rvrf-chevron-left{transform:rotate(180deg)}.rvrf-hero{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:1.1rem 1.3rem;margin-bottom:1rem;border-radius:16px;background:linear-gradient(118deg,#103a23,#176239 68%,#247849);color:#f5fff7}.rvrf-hero-copy{min-width:0}.rvrf-kicker{display:flex;align-items:center;gap:.4rem;color:#c7efd0;font-size:.66rem;font-weight:800;letter-spacing:.12em;text-transform:uppercase}.rvrf-kicker svg{width:15px;height:15px}.rvrf-hero p{margin:.3rem 0 0;color:#e0f2e5;font-size:.85rem}.rvrf-status{padding:.35rem .6rem;border:1px solid #ffffff35;border-radius:999px;background:#ffffff15;font-size:.68rem;font-weight:800;white-space:nowrap}.rvrf-grid{display:grid;grid-template-columns:minmax(0,1.65fr) minmax(260px,.85fr);gap:1rem;align-items:start}.rvrf-main{display:grid;gap:1rem;min-width:0}.rvrf-card{min-width:0;overflow:hidden}.rvrf-card-head h3{margin:0}.rvrf-card-head p{margin:.2rem 0 0}.rvrf-card .card-body{min-width:0}.rvrf-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.9rem 1rem}.rvrf-span-all{grid-column:1/-1}.rvrf-field-label{display:block;margin-bottom:.35rem;font-size:.78rem;font-weight:700}.rvrf-field-label .req{color:var(--c-red)}.rvrf-field-row{display:grid;grid-template-columns:minmax(0,1fr) auto minmax(0,1fr);align-items:center;gap:.45rem}.rvrf-field-row input{min-width:0}.rvrf-select{width:100%;min-height:38px;padding:.4rem .55rem;border:1px solid var(--c-border);border-radius:var(--radius-sm);background:var(--c-surface);color:var(--c-text);font:inherit}.rvrf-adjust-row{display:grid;grid-template-columns:minmax(140px,.9fr) minmax(100px,1fr);gap:.55rem;align-items:start}.rvrf-adjust-row input{width:100%}.rvrf-hint{display:block;margin-top:.28rem;color:var(--c-text-2);font-size:.7rem}.rvrf-error{display:block;margin-top:.2rem;color:var(--c-red);font-size:.72rem}.rvrf-days{display:grid;gap:.5rem;margin:0;padding:0;border:0}.rvrf-days legend{margin-bottom:.25rem;font-size:.78rem;font-weight:700}.rvrf-day-tools{display:flex;gap:.35rem;flex-wrap:wrap}.rvrf-day-list{display:flex;gap:.35rem;flex-wrap:wrap}.rvrf-day-list .check{min-width:57px;justify-content:center;padding:.38rem .5rem;border:1px solid var(--c-border);border-radius:8px;background:var(--c-surface)}.rvrf-day-list .check:has(input:checked){border-color:#5aa870;background:#edf8ef}.rvrf-active-toggle{display:flex;align-items:center;gap:.5rem;margin:0;padding:.7rem .8rem;border:1px solid var(--c-border);border-radius:10px;background:var(--c-bg)}.rvrf-active-toggle input{width:16px;height:16px}.rvrf-active-toggle strong{font-size:.8rem}.rvrf-active-toggle small{display:block;color:var(--c-text-2);font-size:.68rem}.rvrf-side-card{position:sticky;top:1rem}.rvrf-example{padding:.7rem .8rem;border:1px solid var(--c-border);border-radius:10px;background:var(--c-bg);font-size:.78rem}.rvrf-example+.rvrf-example{margin-top:.55rem}.rvrf-example strong{display:block;margin-bottom:.13rem}.rvrf-example p{margin:0;color:var(--c-text-2)}.rvrf-note{margin-top:.75rem;padding:.7rem .8rem;border-left:3px solid #4b9a61;border-radius:0 9px 9px 0;background:#eef8f0;color:#245b34;font-size:.74rem;line-height:1.45}.rvrf-note svg{width:15px;height:15px;vertical-align:middle;margin-right:.2rem}.rvrf-foot{position:sticky;bottom:.6rem;z-index:6;display:flex;align-items:center;justify-content:flex-end;gap:.55rem;margin-top:1rem;padding:.65rem .8rem;border:1px solid var(--c-border);border-radius:13px;background:var(--c-surface);box-shadow:0 9px 28px rgba(15,23,42,.12)}.rvrf-foot .btn{min-height:40px}.rvrf-foot .rvrf-foot-hint{margin-right:auto;color:var(--c-text-2);font-size:.7rem}.rvrf-error-summary{margin:0 0 .8rem;padding:.7rem .85rem;border:1px solid #f3b4b4;border-radius:10px;background:#fff1f1;color:#8e2424;font-size:.78rem}.rvrf-sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
body.dark .rvrf-hero{background:linear-gradient(118deg,#0d2718,#143d25 68%,#1d5433)}body.dark .rvrf-day-list .check:has(input:checked){background:#183225;border-color:#45915b}body.dark .rvrf-note{background:#173324;color:#c3ebcc;border-color:#45915b}body.dark .rvrf-error-summary{background:#3a2224;border-color:#714246;color:#ffc3c3}
@media(max-width:900px){.rvrf-grid{grid-template-columns:1fr}.rvrf-side-card{position:static}.rvrf-hero{align-items:flex-start;flex-direction:column}}
@media(max-width:580px){.rvrf-form-grid{grid-template-columns:1fr}.rvrf-span-all{grid-column:auto}.rvrf-adjust-row{grid-template-columns:1fr}.rvrf-foot{bottom:.35rem;flex-wrap:wrap}.rvrf-foot .rvrf-foot-hint{flex-basis:100%;margin-right:0}.rvrf-foot .btn{flex:1}.rvrf-day-list .check{min-width:calc(25% - .3rem)}}
@media(prefers-reduced-motion:reduce){.rvrf-hero,.rvrf-foot{scroll-behavior:auto;transition:none}}
</style>
@endpush
@section('content')
@php $isEdit = $rule->exists; @endphp
<div class="rvrf-page">
  <section class="rvrf-hero" aria-label="Pricing rule form overview"><div class="rvrf-hero-copy"><span class="rvrf-kicker"><x-icon name="trend"/> Revenue management · yield rules</span><p>{{ $isEdit ? 'Update the match conditions, priority, or rate adjustment for this rule.' : 'Build a rule that adjusts the nightly rate only when its conditions match.' }}</p></div><span class="rvrf-status">{{ $isEdit ? 'Editing rule' : 'New rule' }}</span></section>

  <form method="post" action="{{ $isEdit ? route('revenue.rules.update', $rule) : route('revenue.rules.store') }}" class="rvrf-form">@csrf @if($isEdit) @method('PUT') @endif
    @if($errors->any())<div class="rvrf-error-summary" role="alert"><strong>Check the highlighted fields.</strong> Correct the rule details below and save again.</div>@endif
    <div class="rvrf-grid">
      <div class="rvrf-main">
        <section class="card rvrf-card" aria-labelledby="rvrfSetupTitle"><div class="card-head rvrf-card-head"><div><h3 id="rvrfSetupTitle">Rule setup</h3><p class="tiny muted">Name this rule and choose which room types it can affect.</p></div></div><div class="card-body rvrf-form-grid">
          <x-field name="name" label="Rule name" required :value="$rule->name" class="rvrf-span-all" placeholder="e.g. High demand +15%"/>
          <x-field name="room_type_id" label="Room type" type="select" :value="$rule->room_type_id" :options="$types->pluck('name', 'id')" placeholder="All room types" hint="Leave as all room types to apply this rule across the property."/>
          <x-field name="priority" label="Priority" type="number" min="1" max="999" required :value="$rule->priority" hint="Lower numbers apply first; matching adjustments stack in order."/>
          <div class="rvrf-span-all"><label class="rvrf-active-toggle"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $rule->is_active))><span><strong>Rule is active</strong><small>Paused rules stay saved but are ignored when recommendations are calculated.</small></span></label>@error('is_active')<span class="rvrf-error">{{ $message }}</span>@enderror</div>
        </div></section>

        <section class="card rvrf-card" aria-labelledby="rvrfConditionsTitle"><div class="card-head rvrf-card-head"><div><h3 id="rvrfConditionsTitle">When should the rule match?</h3><p class="tiny muted">Every condition you set must match the room type and stay date.</p></div></div><div class="card-body rvrf-form-grid">
          <x-field name="basis" label="Measure occupancy on" type="select" required :value="$rule->basis" :options="['hotel' => 'Whole hotel', 'type' => 'This room type']" :placeholder="false" hint="Choose which occupancy percentage the rule checks."/>
          <fieldset class="rvrf-days"><legend>Occupancy range (%)</legend><div class="rvrf-field-row"><label class="rvrf-sr-only" for="rvrfOccMin">Minimum occupancy</label><input id="rvrfOccMin" type="number" name="occ_min" min="0" max="100" value="{{ old('occ_min', $rule->occ_min) }}" placeholder="0" aria-label="Minimum occupancy percent"><span>to</span><label class="rvrf-sr-only" for="rvrfOccMax">Maximum occupancy</label><input id="rvrfOccMax" type="number" name="occ_max" min="0" max="100" value="{{ old('occ_max', $rule->occ_max) }}" placeholder="100" aria-label="Maximum occupancy percent"></div>@error('occ_min')<span class="rvrf-error">{{ $message }}</span>@enderror @error('occ_max')<span class="rvrf-error">{{ $message }}</span>@enderror<span class="rvrf-hint">Leave both blank for any occupancy.</span></fieldset>
          <fieldset class="rvrf-days"><legend>Days before arrival</legend><div class="rvrf-field-row"><label class="rvrf-sr-only" for="rvrfLeadMin">Minimum booking lead time</label><input id="rvrfLeadMin" type="number" name="lead_min" min="0" max="730" value="{{ old('lead_min', $rule->lead_min) }}" placeholder="Any" aria-label="Minimum days before arrival"><span>to</span><label class="rvrf-sr-only" for="rvrfLeadMax">Maximum booking lead time</label><input id="rvrfLeadMax" type="number" name="lead_max" min="0" max="730" value="{{ old('lead_max', $rule->lead_max) }}" placeholder="Any" aria-label="Maximum days before arrival"></div>@error('lead_min')<span class="rvrf-error">{{ $message }}</span>@enderror @error('lead_max')<span class="rvrf-error">{{ $message }}</span>@enderror<span class="rvrf-hint">0 = same-day arrival · maximum 730 days.</span></fieldset>
          <x-field name="date_from" label="Valid from (stay date)" type="date" :value="$rule->date_from?->toDateString()" hint="Optional start date, inclusive."/>
          <x-field name="date_to" label="Valid to (stay date)" type="date" :value="$rule->date_to?->toDateString()" hint="Optional end date, inclusive."/>
          <fieldset class="rvrf-days rvrf-span-all"><legend>Nights of the week</legend>
            @php $selectedDays = old('days', $isEdit ? $rule->dayList() : []); $selectedDays = array_map('intval', (array) $selectedDays); @endphp
            <div class="rvrf-day-tools"><button type="button" class="btn btn-sm btn-ghost" id="rvrfAllDays">Select every day</button><button type="button" class="btn btn-sm btn-ghost" id="rvrfClearDays">Clear day filter</button></div>
            <div class="rvrf-day-list">@foreach(\App\Models\YieldRule::DAYS as $n => $d)<label class="check"><input type="checkbox" name="days[]" value="{{ $n }}" class="rvrf-day-check" @checked(in_array($n, $selectedDays, true))> {{ $d }}</label>@endforeach</div>
            <span class="rvrf-hint">No days selected means every night. Selected days use ISO weekdays, Monday through Sunday.</span>@error('days')<span class="rvrf-error">{{ $message }}</span>@enderror @error('days.*')<span class="rvrf-error">{{ $message }}</span>@enderror
          </fieldset>
        </div></section>

        <section class="card rvrf-card" aria-labelledby="rvrfAdjustmentTitle"><div class="card-head rvrf-card-head"><div><h3 id="rvrfAdjustmentTitle">Rate adjustment</h3><p class="tiny muted">The adjustment is applied after earlier matching rules.</p></div></div><div class="card-body rvrf-form-grid">
          <div class="field rvrf-span-all"><label class="rvrf-field-label" for="rvrfAdjustType">Adjustment type <span class="req">*</span></label><div class="rvrf-adjust-row"><select id="rvrfAdjustType" class="rvrf-select @error('adjust_type') is-invalid @enderror" name="adjust_type" required aria-describedby="rvrfAdjustHint"><option value="percent" @selected(old('adjust_type', $rule->adjust_type) === 'percent')>Percent of current rate (%)</option><option value="amount" @selected(old('adjust_type', $rule->adjust_type) === 'amount')>Fixed currency amount</option></select><input type="number" step="0.01" name="adjust_value" value="{{ old('adjust_value', $rule->adjust_value !== null ? (float) $rule->adjust_value : '') }}" required placeholder="e.g. 15 or -10" class="@error('adjust_value') is-invalid @enderror" aria-label="Rate adjustment value" aria-describedby="rvrfAdjustHint"></div><span class="rvrf-hint" id="rvrfAdjustHint">Positive raises the current price; negative lowers it. Percent changes are limited to −90% through +300%, fixed amounts to ±9,999,999.</span>@error('adjust_type')<span class="rvrf-error">{{ $message }}</span>@enderror @error('adjust_value')<span class="rvrf-error">{{ $message }}</span>@enderror</div>
          <x-field name="min_stay" label="Minimum stay while active (nights)" type="number" min="2" max="14" :value="$rule->min_stay" hint="Optional restriction; leave blank for no minimum-stay change."/>
          <div class="rvrf-span-all rvrf-note"><x-icon name="trend"/> Matching rules stack in priority order. The final recommendation is rounded to your configured increment and kept within the room type’s floor and ceiling. Fixed-price dates are not adjusted.</div>
        </div></section>
      </div>

      <aside class="card rvrf-side-card" aria-labelledby="rvrfExamplesTitle"><div class="card-head"><h3 id="rvrfExamplesTitle">Rule examples</h3></div><div class="card-body">
        <div class="rvrf-example"><strong>High demand</strong><p>Hotel occupancy 80–100% → +20%</p></div>
        <div class="rvrf-example"><strong>Last-minute low demand</strong><p>Room-type occupancy 0–30%, lead 0–7 days → −10%</p></div>
        <div class="rvrf-example"><strong>Weekend premium</strong><p>Friday and Saturday → +8%</p></div>
        <div class="rvrf-example"><strong>Early booking</strong><p>Lead time 45 days or more → −5%</p></div>
        <div class="rvrf-example"><strong>Event dates</strong><p>Set a stay-date range, use a fixed amount, and optionally require a two-night minimum.</p></div>
        <p class="small muted mb-0 mt-1">Use more than one condition to narrow when a rule applies. If all conditions are blank, the rule matches every future night for its selected room type.</p>
      </div></aside>
    </div>
    <div class="rvrf-foot"><span class="rvrf-foot-hint">{{ $isEdit ? 'Review the matching conditions before saving.' : 'You can pause or edit this rule later.' }}</span><a class="btn" href="{{ route('revenue.rules') }}">Cancel</a><button type="submit" class="btn btn-primary"><x-icon name="check" size="sm"/> {{ $isEdit ? 'Save rule' : 'Add pricing rule' }}</button></div>
  </form>
</div>
@endsection
@push('scripts')
<script>
(() => {
  // the box refuses what the controller refuses: -90..300 for a percent, ±9,999,999 for an amount
  const type = document.getElementById('rvrfAdjustType');
  const value = document.querySelector('[name=adjust_value]');
  const clampToType = () => {
    if (!type || !value) return;
    const pct = type.value !== 'amount';
    value.min = pct ? '-90' : '-9999999';
    value.max = pct ? '300' : '9999999';
    value.step = '0.01';
  };
  type?.addEventListener('change', clampToType);
  clampToType();
  const days = [...document.querySelectorAll('.rvrf-day-check')];
  document.getElementById('rvrfAllDays')?.addEventListener('click', () => days.forEach(day => { day.checked = true; }));
  document.getElementById('rvrfClearDays')?.addEventListener('click', () => days.forEach(day => { day.checked = false; }));
})();
</script>
@endpush
