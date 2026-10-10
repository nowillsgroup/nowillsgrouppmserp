@extends('layouts.print')
@section('title', 'Parking ticket '.$s->number)
@section('doctitle', $s->is_valet ? 'VALET TICKET' : 'PARKING TICKET')
@section('docmeta')<div class="small"><b>{{ $s->number }}</b></div><div class="small">{{ $s->entered_at->format('d M Y H:i') }}</div>@endsection
@section('content')
<style>.pkt{display:grid;grid-template-columns:1fr 170px;gap:1.2rem;align-items:start;margin-top:.8rem}.pkt h1{font-size:2rem;letter-spacing:.08em;margin:0}.pkt .qr svg{width:170px;height:170px}
.pkv{display:grid;grid-template-columns:120px 1fr;gap:.3rem .8rem;font-size:.92rem;margin-top:.7rem}.pkv dt{color:#64748b}.pkv dd{margin:0;font-weight:600}.pkbox{border:1px dashed #94a3b8;border-radius:10px;padding:.6rem .8rem;margin-top:1rem;font-size:.85rem}
.pkstub{border-top:2px dashed #94a3b8;margin-top:1.2rem;padding-top:.8rem;display:flex;justify-content:space-between;font-size:.9rem}</style>
<div class="pkt" id="pkTicket" data-number="{{ $s->number }}">
  <div>
    <h1 class="mono">{{ $s->plate }}</h1>
    <div class="small muted">{{ $s->vehicle?->description() }}</div>
    <dl class="pkv">
      <dt>Parked</dt><dd>{{ $s->zone->name }}{{ $s->bay ? ' · bay '.$s->bay : '' }}</dd>
      <dt>Time in</dt><dd>{{ $s->entered_at->format('D d M Y, H:i') }}</dd>
      <dt>Driver</dt><dd>{{ $s->guest_name ?? '—' }}</dd>
      @if($s->stay?->room)<dt>Room</dt><dd>{{ $s->stay->room->room_no }}</dd>@endif
      @if($s->is_valet)<dt>Key tag</dt><dd>{{ $s->key_tag }}</dd>@endif
    </dl>
  </div>
  <div class="qr center">{!! $qr !!}<div class="tiny muted">{{ $s->is_valet ? 'Scan to call for your car' : $s->number }}</div></div>
</div>
@if($s->is_valet)
<div class="pkbox"><b>Condition at intake:</b> {{ $s->damage ? implode(', ', array_map(fn ($p) => \App\Models\ParkingSession::PANELS[$p] ?? $p, $s->damage)) : 'no damage noted' }}{{ $s->condition_notes ? ' – '.$s->condition_notes : '' }}{{ $s->fuel ? ' · fuel '.$s->fuel : '' }}.
  <br>Valuables: {{ $s->valuables ?: 'none declared' }}. The hotel is not responsible for items left in the vehicle.</div>
@endif
<div class="pkbox">@if($s->kind === 'guest')Parking {{ $s->is_valet ? ($cfg['valet_rate'] > 0 ? money($cfg['valet_rate']).' per night, charged to your room.' : 'complimentary for hotel guests.') : ($cfg['guest_rate'] > 0 ? money($cfg['guest_rate']).' per night, charged to your room.' : 'complimentary for hotel guests.') }}
  @elseif($s->kind === 'visitor')First {{ $cfg['free_minutes'] }} minutes free, then {{ money($cfg['hour_rate']) }} per hour{{ $cfg['daily_max'] > 0 ? ', maximum '.money($cfg['daily_max']).' per day' : '' }}{{ $s->is_valet && $cfg['valet_fee'] > 0 ? ' + valet service '.money($cfg['valet_fee']) : '' }}, plus taxes. Ask the restaurant, spa or banquet cashier to validate your ticket.
  @else Permit holder – no charge. @endif
  Lost tickets: {{ money($cfg['lost_fee']) }} + ID check.</div>
@if($s->is_valet)<div class="pkstub"><span>Key tag <b>{{ $s->key_tag }}</b></span><span class="mono"><b>{{ $s->plate }}</b></span><span>{{ $s->number }} · {{ $s->zone->code }}{{ $s->bay ? ' '.$s->bay : '' }}</span></div>@endif
@endsection
