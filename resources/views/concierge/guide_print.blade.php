@extends('layouts.print')
@section('title', 'Guest guide')
@section('doctitle', 'EXPLORE '.strtoupper($hotel->city ?: 'THE CITY'))
@section('docmeta')<div class="small">Concierge{{ $cfg['desk_phone'] ? ' · '.$cfg['desk_phone'] : '' }}</div><div class="small">{{ bdate()->format('F Y') }}</div>@endsection
@section('content')
@php $P = \App\Models\ConciergePlace::class; $A = \App\Models\Activity::class; @endphp
<style>.gcols{columns:2;column-gap:1.6rem}.gcols .gsec{break-inside:avoid;margin-bottom:.8rem}.gsec h4{margin:.2rem 0 .3rem;font-size:.9rem;color:#166534;text-transform:uppercase;letter-spacing:.04em}
.gpl{font-size:.8rem;margin-bottom:.35rem;break-inside:avoid}.gpl b{font-size:.84rem}@media(max-width:640px){.gcols{columns:1}}</style>
@if($activities->isNotEmpty())
<h3 class="mt-1 mb-0">Tours &amp; excursions – book at the concierge desk or on your guest portal</h3>
<table class="table table-compact" id="guideActivities">
  <thead><tr><th>Activity</th><th>When</th><th>Duration</th><th class="num">Price</th></tr></thead>
  <tbody>@foreach($activities as $a)<tr><td><b>{{ $a->name }}</b>@if($a->highlights)<div class="tiny muted">{{ $a->highlights }}</div>@endif</td><td class="small">{{ $a->daysLabel() }}{{ $a->isScheduled() ? ' · '.implode(', ', $a->timesList()) : '' }}</td><td class="small">{{ $a->durationLabel() }}</td><td class="num small">{{ $a->priceLabel() }}</td></tr>@endforeach</tbody>
</table>
@endif
<div class="gcols mt-1" id="guidePlaces">
@foreach($P::CATEGORIES as $cat => $label) @continue(! isset($places[$cat]))
  <div class="gsec"><h4>{{ $label }}</h4>
  @foreach($places[$cat] as $p)
    <div class="gpl"><b>{{ $p->name }}</b>{{ $p->area ? ' – '.$p->area : '' }}{{ $p->distance_km !== null ? ' ('.rtrim(rtrim(number_format((float) $p->distance_km, 1), '0'), '.').' km)' : '' }}
      @if($p->description)<br>{{ $p->description }}@endif
      @if($p->hours || $p->phone)<br><span class="muted">{{ collect([$p->hours, $p->phone])->filter()->implode(' · ') }}</span>@endif
      @if($p->tip)<br><i>Tip: {{ $p->tip }}</i>@endif</div>
  @endforeach
  </div>
@endforeach
</div>
<p class="tiny muted">Tours are run by independent licensed operators; {{ $hotel->name }} books them as agent. Opening hours change – please check with the concierge before you go.</p>
@endsection
