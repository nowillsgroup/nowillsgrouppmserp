@extends('layouts.app')
@section('title', 'Night Audit')
@section('content')
<div class="grid g-2-1">
<div>
  <div class="card"><div class="card-head"><h3>Business date: {{ bdate()->format('l, d M Y') }}</h3></div><div class="card-body">
    <p class="muted">Night audit closes the current business day: marks unarrived reservations as no-show, extends overstaying guests, posts room tariff for all in-house rooms, sets occupied rooms to dirty, records the day's statistics and rolls the business date forward.</p>
    <div class="stats">
      <x-stat label="In-house rooms (tariff to post)" :value="$in_house" icon="bed" tone="blue"/>
      <x-stat label="Pending arrivals (→ no-show)" :value="$pending_arrivals->count()" icon="login" tone="{{ $pending_arrivals->count() ? 'red' : 'green' }}"/>
      <x-stat label="Pending departures (→ extend)" :value="$pending_departures->count()" icon="logout" tone="{{ $pending_departures->count() ? 'red' : 'green' }}"/>
      <x-stat label="Open POS orders" :value="$open_pos->count()" icon="cup" tone="{{ $open_pos->count() ? 'red' : 'green' }}"/>
    </div>
  </div></div>
  @if($pending_arrivals->count())<div class="card flush"><div class="card-head"><h3>Pending arrivals</h3></div><div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>Reservation</th><th>Guest</th><th>Room type</th><th>Arrival</th><th></th></tr></thead><tbody>
  @foreach($pending_arrivals as $s)<tr><td>{{ $s->reservation->reservation_no }}</td><td>{{ $s->reservation->guest->full_name }}</td><td>{{ $s->roomType->name }}</td><td>{{ dmy($s->arrival_date) }}</td><td><a class="btn btn-sm" href="{{ route('reservations.show', $s->reservation) }}">Open</a></td></tr>@endforeach</tbody></table></div></div>@endif
  @if($pending_departures->count())<div class="card flush"><div class="card-head"><h3>Pending departures</h3></div><div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>Room</th><th>Guest</th><th>Departure</th><th></th></tr></thead><tbody>
  @foreach($pending_departures as $s)<tr><td class="bold">{{ $s->room?->room_no }}</td><td>{{ $s->reservation->guest->full_name }}</td><td>{{ dmy($s->departure_date) }}</td><td><a class="btn btn-sm" href="{{ route('frontdesk.checkout', $s) }}">Check-out</a></td></tr>@endforeach</tbody></table></div></div>@endif
  @if(($desk_traces ?? collect())->count() || ($desk_urgent ?? collect())->count())<div class="alert alert-warn" id="naDesk"><x-icon name="bell"/><div>
    @if($desk_traces->count())<b>{{ $desk_traces->count() }} open trace(s)</b> will be carried to the next day @if(can_do('desk.traces')) – <a href="{{ route('desk.traces') }}">review traces</a>@endif. @endif
    @if($desk_urgent->count())<b>{{ $desk_urgent->count() }} urgent log book item(s)</b> still open: {{ $desk_urgent->pluck('title')->take(3)->implode('; ') }}@if(can_do('logbook.view')) – <a href="{{ route('desk.logbook', ['tab' => 'open']) }}">log book</a>@endif.@endif
  </div></div>@endif
  @if(($meters_missing ?? collect())->count() || ($licences_expired ?? collect())->count())<div class="alert alert-warn" id="naEngineering"><x-icon name="tool"/><div>
    @if($meters_missing->count())<b>{{ $meters_missing->count() }} meter(s) not read today</b>: {{ $meters_missing->pluck('code')->implode(', ') }}@if(can_do('utilities.record')) – <a href="{{ route('utilities.index', ['tab' => 'readings']) }}">enter readings</a>@endif. @endif
    @if($licences_expired->count())<b>{{ $licences_expired->count() }} critical licence(s) expired</b>: {{ $licences_expired->pluck('name')->take(3)->implode('; ') }}@if(can_do('compliance.view')) – <a href="{{ route('compliance.index', ['state' => 'expired']) }}">compliance register</a>@endif.@endif
  </div></div>@endif
  @if(($formc_overdue ?? collect())->count())<div class="card flush" id="naFormC"><div class="card-head"><h3><x-icon name="globe"/> Form C overdue (FRRO)</h3><span class="small muted">file these today – the audit is not blocked</span></div><div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>Form</th><th>Guest</th><th>Room</th><th>Deadline</th><th></th></tr></thead><tbody>
  @foreach($formc_overdue as $f)<tr><td>{{ $f->form_no }}</td><td>{{ $f->name() }} <span class="muted small">{{ $f->nationality }}</span></td><td>{{ $f->stay?->room?->room_no }}</td><td class="text-red">{{ $f->deadline_at->format('d M H:i') }}</td><td>@if(can_do('formc.view'))<a class="btn btn-sm" href="{{ route('fg.formc.show', $f) }}">Open</a>@endif</td></tr>@endforeach</tbody></table></div></div>@endif
  @if($open_pos->count())<div class="card flush"><div class="card-head"><h3>Open POS orders</h3></div><div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>Order</th><th>Outlet</th><th>Status</th><th class="num">Total</th><th></th></tr></thead><tbody>
  @foreach($open_pos as $o)<tr><td>{{ $o->order_no }}</td><td>{{ $o->outlet->name }}</td><td>{!! status_badge($o->status) !!}</td><td class="num">{{ money($o->total) }}</td><td><a class="btn btn-sm" href="{{ route('pos.terminal', $o) }}">Open</a></td></tr>@endforeach</tbody></table></div></div>@endif
</div>
<div>
  <form method="post" action="{{ route('nightaudit.run') }}" class="card" data-confirm="Run night audit and close {{ bdate()->format('d M Y') }}? This cannot be undone.">@csrf
    <div class="card-head"><h3>Run Night Audit</h3></div>
    <div class="card-body">
      <label class="check"><input type="checkbox" name="mark_no_shows" value="1" checked> Mark pending arrivals as no-show</label>
      <x-field name="notes" label="Auditor notes" type="textarea" rows="3"/>
      <label class="check"><input type="checkbox" name="confirm" value="1" required> I have verified cash, POS and front-desk balances</label>
      @if($open_pos->count())<div class="alert alert-info mt-1">Open POS orders will carry forward to the next day.</div>@endif
    </div>
    <div class="card-foot"><button class="btn btn-primary btn-block"><x-icon name="moon"/> Close Day &amp; Roll Date</button></div>
  </form>
  <div class="card flush"><div class="card-head"><h3>Audit history</h3></div><div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>Date</th><th class="num">Occ %</th><th class="num">Revenue</th><th></th></tr></thead><tbody>
  @forelse($history as $h)<tr><td>{{ $h->business_date->format('d M Y') }}</td><td class="num">{{ (float)$h->occupancy_pct }}</td><td class="num">{{ money($h->room_revenue + $h->other_revenue, false) }}</td><td><a class="btn btn-sm" href="{{ route('nightaudit.show', $h) }}"><x-icon name="eye" size="sm"/></a></td></tr>
  @empty<tr><td colspan="4" class="muted">No audits yet.</td></tr>@endforelse</tbody></table>{{ $history->links() }}</div></div>
</div>
</div>
@endsection
