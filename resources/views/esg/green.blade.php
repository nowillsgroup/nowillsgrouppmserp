@extends('layouts.app')
@section('title', 'Green Stay')
@section('crumbs') Sustainability &amp; ESG / Green stay @endsection
@php $canG = \App\Services\EsgService::canGreen(auth()->user()); @endphp
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'esg_green', 'from' => $from, 'to' => $to]) }}"><x-icon name="download"/> Green-stay report</a>
@endsection
@section('content')
@if(! $cfg['green_enabled'])<div class="alert alert-warn"><x-icon name="alert"/><div>The green-stay choice is switched off in <a href="{{ route('esg.settings') }}">ESG settings</a>.</div></div>@endif
<div class="stats" id="esgGreenStats">
  <x-stat label="Rooms on green stay" :value="$s['active']" icon="bed" tone="teal" :sub="'stay-over clean skipped, full service every '.$cfg['green_every'].' nights'"/>
  <x-stat label="Green nights" :value="$s['nights']" icon="moon" tone="green" :sub="($s['uptake'] !== null ? $s['uptake'].'% of occupied room-nights' : '').' · '.$s['guests'].' stays'"/>
  <x-stat label="Loyalty points awarded" :value="number_format($s['points'])" icon="star" tone="purple" :sub="\App\Services\LoyaltyService::enabled() ? $cfg['green_points'].' per night' : 'loyalty programme is off'"/>
  <x-stat label="Saved (estimate)" :value="number_format($s['co2'], 1).' kg CO₂e'" icon="tree" tone="green" :sub="number_format($s['kwh'], 0).' kWh · '.number_format($s['litres'] / 1000, 1).' m³ water'"/>
</div>
<div class="g-2">
  <div class="card flush"><div class="card-head"><h3>On green stay</h3></div><div class="card-body table-wrap"><table class="table table-compact" id="esgGreenActive"><thead><tr><th>Room</th><th>Guest</th><th>From</th><th>Departs</th><th>Today</th><th></th></tr></thead><tbody>
    @forelse($active as $g)@php $st = $g->stay; @endphp<tr><td><b>{{ $st?->room?->room_no }}</b></td><td class="small">{{ ($st?->guest ?? $st?->reservation?->guest)?->full_name }}<div class="tiny muted">{{ $g->source === 'portal' ? 'chosen on the guest portal' : 'front desk' }}</div></td>
      <td class="small nowrap">{{ $g->from_date->format('d M') }}</td><td class="small nowrap">{{ $st?->departure_date?->format('d M') }}</td>
      <td>@if($g->from_date->toDateString() > bdate()->toDateString())<span class="badge badge-slate">starts tomorrow</span>@elseif($skipToday[$g->id] ?? false)<span class="badge badge-green">no service</span>@else<span class="badge badge-blue">full service</span>@endif</td>
      <td>@if($canG)<form method="post" action="{{ route('esg.green.out', $g) }}">@csrf<button class="btn btn-sm">End</button></form>@endif</td></tr>
    @empty<tr><td colspan="6" class="muted">No rooms on the green stay.</td></tr>@endforelse
  </tbody></table></div></div>
  <div class="card flush"><div class="card-head"><h3>In-house – offer the green stay</h3></div><div class="card-body table-wrap"><table class="table table-compact" id="esgGreenOffer"><thead><tr><th>Room</th><th>Guest</th><th>Departs</th><th></th></tr></thead><tbody>
    @forelse($inhouse as $st)<tr><td><b>{{ $st->room?->room_no }}</b></td><td class="small">{{ ($st->guest ?? $st->reservation?->guest)?->full_name }}</td><td class="small nowrap">{{ $st->departure_date->format('d M') }}</td>
      <td>@if($canG && $cfg['green_enabled'])<form method="post" action="{{ route('esg.green.in') }}">@csrf<input type="hidden" name="reservation_room_id" value="{{ $st->id }}"><button class="btn btn-sm btn-primary">Enrol</button></form>@endif</td></tr>
    @empty<tr><td colspan="4" class="muted">No other in-house stays with nights left to skip.</td></tr>@endforelse
  </tbody></table></div></div>
</div>
<div class="card"><div class="card-body">@include('esg._range')</div></div>
<div class="card flush"><div class="card-head"><h3>Green nights</h3><span class="tiny muted">logged by the night audit when the room was not serviced</span></div><div class="card-body table-wrap"><table class="table table-compact" id="esgGreenNights"><thead><tr><th>Date</th><th>Room</th><th>Guest</th><th class="num">Points</th><th class="num">kWh</th><th class="num">Litres</th><th class="num">kg CO₂e</th></tr></thead><tbody>
  @forelse($nights as $n)<tr><td class="small nowrap">{{ $n->business_date->format('d M Y') }}</td><td>{{ $n->room?->room_no }}</td><td class="small">{{ $n->guest?->full_name }}</td><td class="num">{{ $n->points ?: '' }}</td><td class="num small">{{ $n->kwh }}</td><td class="num small">{{ number_format($n->litres, 0) }}</td><td class="num small">{{ $n->co2_kg }}</td></tr>
  @empty<tr><td colspan="7" class="muted">No green nights in the period.</td></tr>@endforelse
</tbody></table></div></div>
@endsection
