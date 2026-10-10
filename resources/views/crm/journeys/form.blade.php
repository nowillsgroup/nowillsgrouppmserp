@extends('layouts.app')
@section('title', $journey->exists ? 'Edit Automation' : 'New Automation')
@section('crumbs') <a href="{{ route('crm.index') }}">Marketing</a> / <a href="{{ route('crm.journeys.index') }}">Automations</a> / {{ $journey->exists ? $journey->name : 'New' }} @endsection
@section('content')
<form method="post" action="{{ $journey->exists ? route('crm.journeys.update', $journey) : route('crm.journeys.store') }}" id="journeyForm">@csrf @if($journey->exists) @method('PUT') @endif
<div class="grid g-2-1">
  <div>
    <div class="card"><div class="card-head"><h3>Trigger</h3>@if($journey->exists){!! status_badge($journey->is_active ? 'active' : 'inactive') !!}@endif</div><div class="card-body form-grid">
      <x-field name="name" label="Name" :value="$journey->name" required placeholder="e.g. Birthday greeting with 20% off" class="span-all"/>
      <x-field name="trigger" label="When" type="select" :options="collect(\App\Models\MarketingJourney::TRIGGERS)->map(fn ($t) => $t[0])" :value="$journey->trigger" :placeholder="false" required/>
      <x-field name="offset_days" label="Days" type="number" min="0" max="730" :value="$journey->offset_days" required :hint="\App\Models\MarketingJourney::TRIGGERS[$journey->trigger][1] ?? ''"/>
      <x-field name="segment_id" label="Only for guests in segment" type="select" :options="$segments" :value="$journey->segment_id" placeholder="— Everyone who qualifies —" class="span-all"/>
    </div></div>
    @include('crm._composer', ['model' => $journey])
  </div>
  <div class="card sticky-side"><div class="card-head"><h3><x-icon name="eye"/> Preview</h3><span class="tiny muted">{{ $sample ? 'as '.$sample->first_name : '' }}</span></div><div class="card-body">
    <div class="bold mb-1" id="pvSubject"></div>
    <div id="pvBody" class="small" style="border:1px solid var(--c-border);border-radius:10px;padding:.8rem;background:#fff;min-height:120px"></div>
    <div id="pvMeta" class="tiny muted mt-1"></div>
  </div></div>
</div>
<div class="flex" style="justify-content:flex-end;gap:.5rem">
  <a class="btn btn-ghost" href="{{ $journey->exists ? route('crm.journeys.show', $journey) : route('crm.journeys.index') }}">Cancel</a>
  <button class="btn btn-primary" id="saveJourney"><x-icon name="check"/> Save automation</button>
</div>
</form>
<script>
(function () {
  const T = @json(collect(\App\Models\MarketingJourney::TRIGGERS)->map(fn ($t) => ['hint' => $t[1], 'def' => $t[2]])), sel = document.getElementById('f_trigger'), off = document.getElementById('f_offset_days');
  sel.addEventListener('change', () => { off.value = T[sel.value].def; const h = off.closest('.field').querySelector('.hint'); if (h) h.textContent = T[sel.value].hint; });
})();
</script>
@endsection
