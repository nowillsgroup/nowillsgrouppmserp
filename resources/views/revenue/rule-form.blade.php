@extends('layouts.app')
@section('title', $rule->exists ? 'Edit Pricing Rule' : 'New Pricing Rule')
@section('crumbs') <a href="{{ route('revenue.index') }}">Revenue</a> / <a href="{{ route('revenue.rules') }}">Pricing Rules</a> / {{ $rule->exists ? $rule->name : 'New' }} @endsection
@section('content')
<form method="post" action="{{ $rule->exists ? route('revenue.rules.update', $rule) : route('revenue.rules.store') }}">@csrf @if($rule->exists) @method('PUT') @endif
<div class="grid g-2-1">
<div class="card"><div class="card-head"><h3>Rule</h3></div><div class="card-body form-grid">
  <x-field name="name" label="Rule name" required :value="$rule->name" class="span-2" placeholder="e.g. High demand +15%"/>
  <x-field name="room_type_id" label="Room type" type="select" :value="$rule->room_type_id" :options="$types->pluck('name', 'id')" placeholder="All room types"/>
  <x-field name="priority" label="Priority" type="number" min="1" max="999" required :value="$rule->priority" hint="Lower numbers apply first"/>

  <div class="field span-2"><label>Adjustment <span class="req">*</span></label>
    <div class="flex gap-1">
      <select name="adjust_type" style="max-width:190px">@foreach(['percent' => 'Percent of rate (%)', 'amount' => 'Fixed amount'] as $k => $v)<option value="{{ $k }}" @selected(old('adjust_type', $rule->adjust_type) === $k)>{{ $v }}</option>@endforeach</select>
      <input type="number" step="0.01" name="adjust_value" value="{{ old('adjust_value', $rule->adjust_value !== null ? (float) $rule->adjust_value : '') }}" required placeholder="e.g. 15 or -10" style="max-width:160px" class="@error('adjust_value') is-invalid @enderror">
    </div>
    <span class="hint">Positive raises the rate, negative lowers it.</span>@error('adjust_value')<span class="err">{{ $message }}</span>@enderror</div>

  <x-field name="basis" label="Measure occupancy on" type="select" required :value="$rule->basis" :options="['hotel' => 'Whole hotel', 'type' => 'This room type']" :placeholder="false"/>
  <div class="field"><label>Occupancy between (%)</label><div class="flex gap-1 items-center">
    <input type="number" name="occ_min" min="0" max="100" value="{{ old('occ_min', $rule->occ_min) }}" placeholder="0"> – <input type="number" name="occ_max" min="0" max="100" value="{{ old('occ_max', $rule->occ_max) }}" placeholder="100"></div>
    @error('occ_max')<span class="err">{{ $message }}</span>@enderror</div>
  <div class="field"><label>Days before arrival (lead time)</label><div class="flex gap-1 items-center">
    <input type="number" name="lead_min" min="0" max="730" value="{{ old('lead_min', $rule->lead_min) }}" placeholder="any"> – <input type="number" name="lead_max" min="0" max="730" value="{{ old('lead_max', $rule->lead_max) }}" placeholder="any"></div>
    <span class="hint">0 = same day</span>@error('lead_max')<span class="err">{{ $message }}</span>@enderror</div>
  <x-field name="min_stay" label="Minimum stay while active (nights)" type="number" min="2" max="14" :value="$rule->min_stay" hint="Optional restriction"/>

  <div class="field span-2"><label>Nights of the week</label>
    @php $sel = old('days', $rule->exists ? $rule->dayList() : []); @endphp
    <div class="flex wrap gap-1">@foreach(\App\Models\YieldRule::DAYS as $n => $d)<label class="check"><input type="checkbox" name="days[]" value="{{ $n }}" @checked(in_array($n, array_map('intval', (array) $sel), true))> {{ $d }}</label>@endforeach</div>
    <span class="hint">None ticked = every night</span></div>
  <x-field name="date_from" label="Valid from (stay date)" type="date" :value="$rule->date_from?->toDateString()"/>
  <x-field name="date_to" label="Valid to (stay date)" type="date" :value="$rule->date_to?->toDateString()"/>
  <div class="field span-2"><label class="check"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $rule->is_active))> Active</label></div>
</div>
<div class="card-foot flex gap-1"><a class="btn" href="{{ route('revenue.rules') }}">Cancel</a><button class="btn btn-primary">{{ $rule->exists ? 'Save rule' : 'Add rule' }}</button></div>
</div>

<div class="card"><div class="card-head"><h3>Examples</h3></div><div class="card-body small">
  <p><b>Compression:</b> whole hotel 80–100% → <b>+20%</b></p>
  <p><b>Last-minute low demand:</b> room type 0–30%, lead 0–7 → <b>−10%</b></p>
  <p><b>Weekend premium:</b> Fri + Sat → <b>+8%</b></p>
  <p><b>Early bird:</b> lead 45+ → <b>−5%</b></p>
  <p><b>Event:</b> dates 24–26 Dec, +2 500 fixed, min stay 2</p>
  <p class="muted mb-0">Rules stack: a Saturday at 85% gets +20% then +8%. The result is rounded and kept between the room type's floor and ceiling. Dates marked “fixed price” on the Rate Calendar are never changed.</p>
</div></div>
</div>
</form>
@endsection
