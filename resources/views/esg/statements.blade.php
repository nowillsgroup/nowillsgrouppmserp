@extends('layouts.app')
@section('title', 'Carbon Statements')
@section('crumbs') Sustainability &amp; ESG / Carbon statements @endsection
@php $f = fn ($v, $d = 2) => $v === null ? '—' : number_format($v, $d); @endphp
@section('content')
<div class="card"><div class="card-head"><h3>Footprint factors</h3><span class="tiny muted">HCMI, {{ \Carbon\Carbon::parse($b['from'])->format('d M Y') }} – {{ \Carbon\Carbon::parse($b['to'])->format('d M Y') }}</span></div><div class="card-body">
  <div class="esg-hcmi">
    <div><div class="l">Per occupied room-night</div><div class="v">{{ $f($b['per_room']) }}</div><div class="u">kg CO₂e · {{ $f($b['hwmi_room'], 0) }} L water</div></div>
    <div><div class="l">Meeting space per m² per hour</div><div class="v">{{ $f($b['per_m2h'], 4) }}</div><div class="u">kg CO₂e · {{ $f($b['hwmi_m2h']) }} L water</div></div>
  </div>
  <p class="small muted" style="margin:.6rem 0 0">Corporate clients and event planners increasingly ask for the footprint of their stay or meeting. These statements use the hotel&rsquo;s own HCMI footprint for the latest 12 months of metered data (or since the first meter reading, if that is shorter).</p>
</div></div>
@if($err)<div class="alert alert-error"><x-icon name="alert"/><div>{{ $err }}</div></div>@endif
<div class="g-3">
  <div class="card"><div class="card-head"><h3>Booking</h3></div><div class="card-body"><form method="get" id="esgStBooking"><input type="hidden" name="type" value="booking">
    <div class="field"><label>Reservation</label><select name="id" required>@foreach($bookings as $r)<option value="{{ $r->id }}" @selected(request('type') === 'booking' && request('id') == $r->id)>{{ $r->reservation_no }} · {{ $r->company?->name ?? $r->guest?->full_name }} · {{ $r->arrival_date->format('d M') }}</option>@endforeach</select></div>
    <button class="btn btn-sm mt-1">Calculate</button></form></div></div>
  <div class="card"><div class="card-head"><h3>Event</h3></div><div class="card-body"><form method="get" id="esgStEvent"><input type="hidden" name="type" value="event">
    <div class="field"><label>Banquet / conference</label><select name="id" required>@foreach($events as $e)<option value="{{ $e->id }}" @selected(request('type') === 'event' && request('id') == $e->id)>{{ $e->event_no }} · {{ \Illuminate\Support\Str::limit($e->title, 30) }} · {{ $e->event_date->format('d M') }}</option>@endforeach</select></div>
    <button class="btn btn-sm mt-1">Calculate</button></form></div></div>
  <div class="card"><div class="card-head"><h3>Corporate account</h3></div><div class="card-body"><form method="get" id="esgStCompany"><input type="hidden" name="type" value="company">
    <div class="field"><label>Company</label><select name="id" required>@foreach($companies as $c)<option value="{{ $c->id }}" @selected(request('type') === 'company' && request('id') == $c->id)>{{ $c->name }}</option>@endforeach</select></div>
    <div class="field"><label>Year</label><input type="number" name="year" min="2000" max="2100" value="{{ request('year', bdate()->year) }}"></div>
    <button class="btn btn-sm mt-1">Calculate</button></form></div></div>
</div>
@if($st)
<div class="card" id="esgStatement"><div class="card-head"><h3>{{ $st['title'] }}</h3><a class="btn btn-sm btn-primary" href="{{ route('esg.statement.print', request()->only(['type', 'id', 'year'])) }}" id="esgStPrint"><x-icon name="print" size="sm"/> Print statement</a></div>
  <div class="card-body">
    <div class="esg-hcmi mb-1"><div><div class="l">Carbon footprint</div><div class="v">{{ number_format($st['kg'], 1) }} kg</div><div class="u">CO₂e</div></div><div><div class="l">Water</div><div class="v">{{ number_format($st['litres'] / 1000, 2) }} m³</div><div class="u">{{ number_format($st['litres']) }} litres</div></div>
      <div><div class="l">Room-nights</div><div class="v">{{ $st['nights'] }}</div><div class="u">{{ $st['party'] }}</div></div><div><div class="l">Meeting space</div><div class="v">{{ number_format($st['m2h']) }}</div><div class="u">m²·h</div></div></div>
    <table class="table table-compact"><thead><tr><th>Item</th><th class="num">Quantity</th><th class="num">kg CO₂e</th><th class="num">Litres</th></tr></thead><tbody>
      @forelse($st['lines'] as $l)<tr><td class="small">{{ $l['label'] }}</td><td class="num small">{{ number_format($l['qty'], $l['kind'] === 'room' ? 0 : 1) }} {{ $l['unit'] }}</td><td class="num">{{ number_format($l['kg'], 1) }}</td><td class="num small">{{ number_format($l['litres']) }}</td></tr>
      @empty<tr><td colspan="4" class="muted">No room-nights or meeting-space use found.</td></tr>@endforelse
    </tbody></table>
  </div></div>
@endif
@endsection
