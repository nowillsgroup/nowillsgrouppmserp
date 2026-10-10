@extends('layouts.app')
@section('title', $contract->exists ? 'Edit contract '.$contract->code : 'New rate contract')
@section('crumbs') <a href="{{ route('contracts.index') }}">Rate Contracts</a> / {{ $contract->exists ? $contract->code : 'New' }} @endsection
@section('content')
@php
  $rates = old('rates', $contract->exists ? $contract->rates->map(fn ($r) => ['room_type_id' => $r->room_type_id, 'season_name' => $r->season_name, 'season_from' => $r->season_from?->toDateString(), 'season_to' => $r->season_to?->toDateString(), 'rate' => $r->rate, 'double_rate' => $r->double_rate, 'extra_adult_rate' => $r->extra_adult_rate])->all() : []);
  $blackouts = old('blackouts', $contract->exists ? $contract->blackouts->map(fn ($b) => ['date_from' => $b->date_from->toDateString(), 'date_to' => $b->date_to->toDateString(), 'reason' => $b->reason])->all() : []);
  $allots = old('allotments', $contract->exists ? $contract->allotments->map(fn ($a) => ['room_type_id' => $a->room_type_id, 'date_from' => $a->date_from->toDateString(), 'date_to' => $a->date_to->toDateString(), 'rooms' => $a->rooms, 'release_days' => $a->release_days])->all() : []);
  $typeOpts = fn ($sel) => '<option value="">Room type…</option>'.$types->map(fn ($t) => '<option value="'.$t->id.'" data-public="'.$t->base_rate.'" '.((string) $sel === (string) $t->id ? 'selected' : '').'>'.e($t->name).'</option>')->implode('');
@endphp
@if($contract->exists && $contract->status === 'suspended')<div class="alert alert-warn"><x-icon name="alert"/><div>This contract is <b>suspended</b>. After saving your changes it must be re-activated by an approver.</div></div>@endif
<form method="post" action="{{ $contract->exists ? route('contracts.update', $contract) : route('contracts.store') }}" id="contractForm">@csrf @if($contract->exists) @method('PUT') @endif
<div class="grid g-2-1">
  <div>
    <div class="card"><div class="card-head"><h3>Agreement</h3></div><div class="card-body form-grid">
      <x-field name="company_id" label="Company / agent" type="select" :options="$companies" :value="old('company_id', $contract->company_id)" required class="span-2"/>
      <x-field name="code" label="Contract code" :value="old('code', $contract->code)" placeholder="e.g. INFY-2027" required style="text-transform:uppercase"/>
      <x-field name="name" label="Title" :value="old('name', $contract->name)" placeholder="e.g. Infosys corporate rates FY 2026-27" required class="span-2"/>
      <x-field name="valid_from" label="Valid from" type="date" :value="old('valid_from', $contract->valid_from?->toDateString())" required/>
      <x-field name="valid_to" label="Valid to" type="date" :value="old('valid_to', $contract->valid_to?->toDateString())" required/>
      <x-field name="meal_plan" label="Meal plan" type="select" :options="\App\Models\RateContract::MEAL_PLANS" :value="old('meal_plan', $contract->meal_plan)" required/>
      <x-field name="inclusions" label="Inclusions" :value="old('inclusions', $contract->inclusions)" placeholder="e.g. Wi-Fi, airport pick-up, laundry 2 pcs/day" class="span-all"/>
    </div></div>

    <div class="card"><div class="card-head"><h3>Pricing</h3></div><div class="card-body">
      <div class="flex gap-2 wrap mb-1">
        <label class="check"><input type="radio" name="pricing" value="net" @checked(old('pricing', $contract->pricing) === 'net')> <b>Net rates</b> – fixed price per room type (seasons optional)</label>
        <label class="check"><input type="radio" name="pricing" value="discount" @checked(old('pricing', $contract->pricing) === 'discount')> <b>% off public rate</b> – follows the daily BAR / seasons</label>
      </div>
      <div class="form-grid"><x-field name="discount_pct" label="Discount % (net contracts: applies to room types without a net line)" type="number" step="0.01" min="0" max="90" :value="old('discount_pct', $contract->discount_pct ?: '')" class="span-2"/></div>
      <div id="rateBox" class="mt-1">
        <table class="table" id="rateTable"><thead><tr><th>Room type</th><th>Season (optional)</th><th>From</th><th>To</th><th class="num">Single</th><th class="num">Double</th><th class="num">Extra adult</th><th></th></tr></thead><tbody>
        @foreach($rates as $i => $r)
          <tr><td><select name="rates[{{ $i }}][room_type_id]" data-k="room_type_id">{!! $typeOpts($r['room_type_id'] ?? '') !!}</select><div class="tiny muted pub"></div></td>
            <td><input name="rates[{{ $i }}][season_name]" value="{{ $r['season_name'] ?? '' }}" placeholder="All period" style="width:110px"></td>
            <td><input type="date" name="rates[{{ $i }}][season_from]" value="{{ $r['season_from'] ?? '' }}"></td><td><input type="date" name="rates[{{ $i }}][season_to]" value="{{ $r['season_to'] ?? '' }}"></td>
            <td><input type="number" step="0.01" name="rates[{{ $i }}][rate]" value="{{ $r['rate'] ?? '' }}" style="width:95px" class="num"></td>
            <td><input type="number" step="0.01" name="rates[{{ $i }}][double_rate]" value="{{ $r['double_rate'] ?? '' }}" style="width:95px" class="num"></td>
            <td><input type="number" step="0.01" name="rates[{{ $i }}][extra_adult_rate]" value="{{ $r['extra_adult_rate'] ?? '' }}" style="width:85px" class="num"></td>
            <td><button type="button" class="btn btn-ghost btn-icon" data-del><x-icon name="trash"/></button></td></tr>
        @endforeach
        </tbody></table>
        <button type="button" class="btn btn-sm" data-add="rate" id="addRate"><x-icon name="plus"/> Rate line</button>
        <span class="tiny muted">A season line beats the all-period line on the nights it covers. Double applies from 2 adults.</span>
      </div>
    </div></div>

    <div class="card"><div class="card-head"><h3>Allotments</h3><span class="tiny muted">Rooms held for this account – unsold rooms return to general sale N days before the night</span></div><div class="card-body">
      <table class="table" id="allotTable"><thead><tr><th>Room type</th><th>From</th><th>To</th><th class="num">Rooms / night</th><th class="num">Release (days before)</th><th></th></tr></thead><tbody>
      @foreach($allots as $i => $a)
        <tr><td><select name="allotments[{{ $i }}][room_type_id]">{!! $typeOpts($a['room_type_id'] ?? '') !!}</select></td>
          <td><input type="date" name="allotments[{{ $i }}][date_from]" value="{{ $a['date_from'] ?? '' }}"></td><td><input type="date" name="allotments[{{ $i }}][date_to]" value="{{ $a['date_to'] ?? '' }}"></td>
          <td><input type="number" min="1" name="allotments[{{ $i }}][rooms]" value="{{ $a['rooms'] ?? '' }}" style="width:80px" class="num"></td>
          <td><input type="number" min="0" name="allotments[{{ $i }}][release_days]" value="{{ $a['release_days'] ?? 7 }}" style="width:80px" class="num"></td>
          <td><button type="button" class="btn btn-ghost btn-icon" data-del><x-icon name="trash"/></button></td></tr>
      @endforeach
      </tbody></table>
      <button type="button" class="btn btn-sm" data-add="allot" id="addAllot"><x-icon name="plus"/> Allotment</button>
    </div></div>

    <div class="card"><div class="card-head"><h3>Blackout dates</h3><span class="tiny muted">Contract prices do not apply – the public rate is charged</span></div><div class="card-body">
      <table class="table" id="blackTable"><thead><tr><th>From</th><th>To</th><th>Reason</th><th></th></tr></thead><tbody>
      @foreach($blackouts as $i => $b)
        <tr><td><input type="date" name="blackouts[{{ $i }}][date_from]" value="{{ $b['date_from'] ?? '' }}"></td><td><input type="date" name="blackouts[{{ $i }}][date_to]" value="{{ $b['date_to'] ?? '' }}"></td>
          <td><input name="blackouts[{{ $i }}][reason]" value="{{ $b['reason'] ?? '' }}" placeholder="e.g. Christmas / New Year"></td>
          <td><button type="button" class="btn btn-ghost btn-icon" data-del><x-icon name="trash"/></button></td></tr>
      @endforeach
      </tbody></table>
      <button type="button" class="btn btn-sm" data-add="black" id="addBlack"><x-icon name="plus"/> Blackout</button>
    </div></div>
  </div>

  <div>
    <div class="card"><div class="card-head"><h3>Terms</h3></div><div class="card-body">
      <label class="check mb-1"><input type="hidden" name="lra" value="0"><input type="checkbox" name="lra" value="1" @checked(old('lra', $contract->lra))> <b>Last-room availability</b><br><span class="tiny muted">Contract rate available whenever a room is free – ignores stop-sell and minimum stay</span></label>
      <x-field name="cancel_days" label="Free cancellation until (days before arrival)" type="number" min="0" max="60" :value="old('cancel_days', $contract->cancel_days)" required/><div class="mt-1"></div>
      <x-field name="commission_pct" label="Commission % (agents; blank = company default)" type="number" step="0.01" min="0" max="50" :value="old('commission_pct', $contract->commission_pct)"/><div class="mt-1"></div>
      <x-field name="room_night_target" label="Room-night target (for the period)" type="number" min="0" :value="old('room_night_target', $contract->room_night_target)"/><div class="mt-1"></div>
      <x-field name="access_code" label="Booking-engine corporate code" :value="old('access_code', $contract->access_code)" placeholder="e.g. INFY26" hint="Guests enter this on the website to get the contract rate" style="text-transform:uppercase"/><div class="mt-1"></div>
      <x-field name="notes" label="Internal notes" type="textarea" rows="4" :value="old('notes', $contract->notes)"/>
    </div>
    <div class="card-foot"><button class="btn btn-primary btn-block" id="saveContract"><x-icon name="check"/> {{ $contract->exists ? 'Save changes' : 'Save draft' }}</button></div></div>
    <div class="card"><div class="card-body small muted">New and amended contracts are saved as <b>drafts</b>. An approver (Front Office Manager / Administrator) other than the person who drafted it activates it; only then do its rates, allotments and access code take effect.</div></div>
  </div>
</div>
</form>
<template id="tpl-rate"><tr><td><select name="rates[#][room_type_id]" data-k="room_type_id">{!! $typeOpts('') !!}</select><div class="tiny muted pub"></div></td><td><input name="rates[#][season_name]" placeholder="All period" style="width:110px"></td><td><input type="date" name="rates[#][season_from]"></td><td><input type="date" name="rates[#][season_to]"></td><td><input type="number" step="0.01" name="rates[#][rate]" style="width:95px" class="num"></td><td><input type="number" step="0.01" name="rates[#][double_rate]" style="width:95px" class="num"></td><td><input type="number" step="0.01" name="rates[#][extra_adult_rate]" style="width:85px" class="num"></td><td><button type="button" class="btn btn-ghost btn-icon" data-del><x-icon name="trash"/></button></td></tr></template>
<template id="tpl-allot"><tr><td><select name="allotments[#][room_type_id]">{!! $typeOpts('') !!}</select></td><td><input type="date" name="allotments[#][date_from]"></td><td><input type="date" name="allotments[#][date_to]"></td><td><input type="number" min="1" name="allotments[#][rooms]" style="width:80px" class="num"></td><td><input type="number" min="0" name="allotments[#][release_days]" value="7" style="width:80px" class="num"></td><td><button type="button" class="btn btn-ghost btn-icon" data-del><x-icon name="trash"/></button></td></tr></template>
<template id="tpl-black"><tr><td><input type="date" name="blackouts[#][date_from]"></td><td><input type="date" name="blackouts[#][date_to]"></td><td><input name="blackouts[#][reason]" placeholder="e.g. Christmas / New Year"></td><td><button type="button" class="btn btn-ghost btn-icon" data-del><x-icon name="trash"/></button></td></tr></template>
@endsection
@push('scripts')<script>
document.addEventListener('DOMContentLoaded', () => {
  const tables = {rate: 'rateTable', allot: 'allotTable', black: 'blackTable'};
  let seq = 1000;
  const pub = tr => { const s = tr.querySelector('[data-k=room_type_id]'), p = tr.querySelector('.pub'); if (s && p) p.textContent = s.selectedOptions[0]?.dataset.public ? 'Public ' + Number(s.selectedOptions[0].dataset.public).toLocaleString('en-US') : ''; };
  document.querySelectorAll('[data-add]').forEach(b => b.addEventListener('click', () => {
    const k = b.dataset.add, html = document.getElementById('tpl-' + k).innerHTML.replaceAll('[#]', '[' + (seq++) + ']');
    const tb = document.querySelector('#' + tables[k] + ' tbody'); tb.insertAdjacentHTML('beforeend', html); pub(tb.lastElementChild);
    if (k === 'allot' || k === 'rate') { const vf = document.getElementById('f_valid_from').value, vt = document.getElementById('f_valid_to').value, r = tb.lastElementChild;
      if (k === 'allot') { r.querySelector('[name$="[date_from]"]').value = vf; r.querySelector('[name$="[date_to]"]').value = vt; } }
  }));
  document.getElementById('contractForm').addEventListener('click', e => { const d = e.target.closest('[data-del]'); if (d) d.closest('tr').remove(); });
  document.getElementById('contractForm').addEventListener('change', e => { if (e.target.matches('[data-k=room_type_id]')) pub(e.target.closest('tr')); });
  document.querySelectorAll('#rateTable tbody tr').forEach(pub);
  const sync = () => { const net = document.querySelector('[name=pricing]:checked')?.value !== 'discount';
    document.getElementById('rateBox').style.display = net ? '' : 'none';
    if (net && !document.querySelector('#rateTable tbody tr')) document.getElementById('addRate').click(); };
  document.querySelectorAll('[name=pricing]').forEach(r => r.addEventListener('change', sync)); sync();
});
</script>@endpush
