@extends('layouts.app')
@section('title', 'Valet Board')
@section('crumbs') Parking &amp; Valet / Valet board @endsection
@php $canOp = \App\Services\ParkingService::canOperate(auth()->user()); $sla = $cfg['sla_minutes']; @endphp
@section('actions')
  <a class="btn" href="{{ route('parking.valet') }}"><x-icon name="refresh"/> Refresh</a>
  @if($canOp)<button class="btn btn-primary" data-modal="pkCheckinModal" data-fkind="guest" data-fis_valet="1"><x-icon name="plus"/> Take a car</button>@endif
@endsection
@section('content')
<div class="stats">
  <x-stat label="Requested" :value="$requested->count()" icon="bell" :tone="$requested->count() ? 'amber' : 'slate'" :sub="'target '.$sla.' min to the door'"/>
  <x-stat label="At the door" :value="$ready->count()" icon="car" tone="purple"/>
  <x-stat label="Keys on the board" :value="$parked->count()" icon="key" tone="blue"/>
  <x-stat label="Average retrieval" :value="$k['avg_wait'] !== null ? $k['avg_wait'].' min' : '—'" icon="clock" :tone="$k['within_sla'] !== null && $k['within_sla'] < 80 ? 'amber' : 'green'" :sub="$k['retrievals'].' retrievals · 30 days'"/>
</div>
<div class="pk-board">
  <div class="card flush"><div class="card-head"><h3>Requested</h3><span class="tiny muted">oldest first</span></div><div class="card-body" id="pkRequested">
    @forelse($requested as $s)@php $w = $s->waitMinutes(); @endphp
      <div class="pk-car {{ $w > $sla ? 'late' : '' }}" data-ticket="{{ $s->number }}">
        <div class="flex" style="justify-content:space-between"><b class="mono">{{ $s->plate }}</b><span class="badge badge-{{ $w > $sla ? 'red' : 'amber' }}">{{ $w }} min</span></div>
        <div class="small">Key <b>{{ $s->key_tag }}</b> · {{ $s->zone->code }}{{ $s->bay ? ' '.$s->bay : '' }} · {{ $s->vehicle?->description() }}</div>
        <div class="tiny muted">{{ $s->stay?->room ? 'Rm '.$s->stay->room->room_no.' · ' : '' }}{{ $s->guest_name }} · via {{ $s->requested_via === 'qr' ? 'QR' : $s->requested_via }} at {{ $s->requested_at->format('H:i') }}</div>
        @if($canOp)<div class="flex gap-1 mt-1"><form method="post" action="{{ route('parking.ready', $s) }}" class="flex gap-1">@csrf
          <select name="runner_id" aria-label="Runner" class="input-sm">@foreach($runners as $u)<option value="{{ $u->id }}" @selected($u->id === auth()->id())>{{ $u->name }}</option>@endforeach</select><button class="btn btn-sm btn-primary">At the door</button></form>
          <form method="post" action="{{ route('parking.repark', $s) }}">@csrf<button class="btn btn-sm">Cancel request</button></form></div>@endif
      </div>
    @empty<p class="muted small">No cars requested.</p>@endforelse
  </div></div>
  <div class="card flush"><div class="card-head"><h3>At the door</h3></div><div class="card-body" id="pkReady">
    @forelse($ready as $s)
      <div class="pk-car ready" data-ticket="{{ $s->number }}">
        <div class="flex" style="justify-content:space-between"><b class="mono">{{ $s->plate }}</b><span class="tiny muted">ready {{ $s->ready_at->format('H:i') }}</span></div>
        <div class="small">Key <b>{{ $s->key_tag }}</b> · {{ $s->guest_name }} · brought by {{ $s->runner?->name ?? '—' }} in {{ $s->waitMinutes() }} min</div>
        @if($canOp)<div class="flex gap-1 mt-1"><a class="btn btn-sm btn-primary" href="{{ route('parking.session', $s) }}#exit">Hand over</a>
          <form method="post" action="{{ route('parking.repark', $s) }}">@csrf<button class="btn btn-sm">Re-park</button></form></div>@endif
      </div>
    @empty<p class="muted small">Nothing waiting at the door.</p>@endforelse
  </div></div>
  <div class="card flush"><div class="card-head"><h3>Key board</h3><span class="tiny muted">{{ $parked->count() }} cars</span></div><div class="card-body table-wrap"><table class="table table-compact" id="pkKeyBoard"><thead><tr><th>Key</th><th>Plate</th><th>Where</th><th>Guest</th><th></th></tr></thead><tbody>
    @forelse($parked as $s)<tr><td><b>{{ $s->key_tag }}</b></td><td class="mono"><a href="{{ route('parking.session', $s) }}">{{ $s->plate }}</a>@if($s->damage)<span class="badge badge-amber" title="Damage noted at intake">{{ count($s->damage) }}</span>@endif</td>
      <td class="small">{{ $s->zone->code }}{{ $s->bay ? ' '.$s->bay : '' }}</td><td class="small">{{ $s->stay?->room ? 'Rm '.$s->stay->room->room_no : $s->kindLabel() }}</td>
      <td>@if($canOp)<form method="post" action="{{ route('parking.request', $s) }}">@csrf<input type="hidden" name="via" value="phone"><button class="btn btn-sm" title="The guest phoned for the car">Call up</button></form>@endif</td></tr>
    @empty<tr><td colspan="5" class="muted">No valet cars parked.</td></tr>@endforelse
  </tbody></table></div></div>
</div>
<div class="card flush mt-1"><div class="card-head"><h3>Recent handovers</h3></div><div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>Ticket</th><th>Plate</th><th>Requested</th><th>Via</th><th class="num">Minutes to door</th><th>Runner</th><th>Out</th></tr></thead><tbody>
  @forelse($recent as $s)<tr class="{{ ($s->waitMinutes() ?? 0) > $sla ? 'row-amber' : '' }}"><td><a href="{{ route('parking.session', $s) }}">{{ $s->number }}</a></td><td class="mono">{{ $s->plate }}</td><td class="small">{{ $s->requested_at->format('d M H:i') }}</td><td class="small">{{ $s->requested_via === 'qr' ? 'QR' : $s->requested_via }}</td><td class="num">{{ $s->waitMinutes() }}</td><td class="small">{{ $s->runner?->name ?? '—' }}</td><td class="small">{{ $s->exited_at?->format('H:i') }}</td></tr>
  @empty<tr><td colspan="7" class="muted">No valet handovers yet.</td></tr>@endforelse
</tbody></table></div></div>
@if($canOp)@include('parking._checkin_modal')@endif
@endsection
