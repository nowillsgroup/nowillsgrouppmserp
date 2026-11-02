@php
  $dashGo = fn (array $extra = []) => route('reservations.index', array_merge(request()->only('date'), $extra));
  $pax = (int) $inhouse->sum(fn ($s) => (int) $s->adults + (int) $s->children);
  $vipIn = $inhouse->filter(fn ($s) => $s->reservation?->guest?->is_vip)->count();
  $dueIn = $arrivals->where('status', 'reserved');
  $readyCount = $dueIn->filter(fn ($s) => str_starts_with(\App\Services\StayService::checkInReady($s), 'ID and phone'))->count();
  $maxRev = max(1.0, ...array_column($pipeline, 'revenue'));
  $maxFlow = max(1, ...array_map(fn ($p) => max($p['arrivals'], $p['departures']), $pipeline));
  $weekEnd = $date->copy()->addDays(6);
  $forecastEnd = $date->copy()->addDays(13);
  $thirtyDayEnd = $date->copy()->addDays(29);
  $groupWindowEnd = $date->copy()->addDays($days - 1);
  $arrivalsByEta = $arrivals->sortBy(fn ($s) => $s->reservation->eta ? substr((string) $s->reservation->eta, 0, 5) : '99:99');
  $photoByRoom = [];
  foreach ($rooms as $r) {
      $photoByRoom[$r->id] = \App\Services\ReservationDeskService::photoPath($r);
  }
  $deskPriorities = [
    [
      'label' => 'Arrivals due', 'value' => $arrivalSummary['due_in'], 'icon' => 'calendar',
      'detail' => $arrivalSummary['due_in'] ? $readyCount.' of '.$arrivalSummary['due_in'].' have guest ID, phone and room on file' : 'No check-ins pending for this date',
      'tone' => ! $arrivalSummary['due_in'] ? 'is-clear' : ($readyCount === $arrivalSummary['due_in'] ? 'is-ready' : 'is-attention'),
      'href' => $dashGo(['view' => 'arrivals', 'due' => 'in', 'focus' => null, 'page' => null]),
    ],
    [
      'label' => $date->isSameDay(bdate()) ? 'Departures to review' : 'Departures scheduled', 'value' => $departureSummary['total'], 'icon' => 'clock',
      'detail' => $departureSummary['still_in_house'].' still in-house · '.$departureSummary['not_yet_arrived'].' not yet arrived',
      'tone' => $departureSummary['still_in_house'] ? 'is-attention' : 'is-clear',
      'href' => $dashGo(['view' => 'departures', 'focus' => null, 'page' => null]),
    ],
    [
      'label' => 'Rooms to assign', 'value' => $arrivalSummary['unassigned'], 'icon' => 'key',
      'detail' => $arrivalSummary['unassigned'] ? 'Arriving guests need a room assignment' : 'Every arrival has a room assigned',
      'tone' => $arrivalSummary['unassigned'] ? 'is-attention' : 'is-clear',
      'href' => $dashGo(['view' => 'arrivals', 'focus' => 'unassigned', 'due' => null, 'page' => null]),
    ],
    [
      'label' => 'Guest details to complete', 'value' => $arrivalSummary['guest_details'], 'icon' => 'id',
      'detail' => $arrivalSummary['guest_details'] ? 'Arrival records need ID or phone details' : 'Arrival records have the required guest details',
      'tone' => $arrivalSummary['guest_details'] ? 'is-attention' : 'is-clear',
      'href' => $dashGo(['view' => 'arrivals', 'focus' => 'guest_details', 'due' => null, 'page' => null]),
    ],
  ];
  if ($date->isSameDay(bdate())) {
      $deskPriorities[] = [
        'label' => 'Past ETA', 'value' => $arrivalSummary['late_eta'], 'icon' => 'alert',
        'detail' => $arrivalSummary['late_eta'] ? 'Expected arrival time passed; confirm guest status' : 'No arrivals have passed their ETA',
        'tone' => $arrivalSummary['late_eta'] ? 'is-urgent' : 'is-clear',
        'href' => $dashGo(['view' => 'arrivals', 'focus' => 'late_eta', 'due' => null, 'page' => null]),
      ];
  }
@endphp
<div class="desk-dashboard">
<section class="desk-priority-board" aria-labelledby="deskPriorityTitle">
  <div class="desk-priority-heading"><div><span class="desk-section-eyebrow">FRONT OFFICE FOLLOW-UP</span><h3 id="deskPriorityTitle">{{ $date->isSameDay(bdate()) ? 'Desk priorities' : 'Selected-date priorities' }}</h3></div><span class="desk-priority-date">{{ $date->format('D, d M Y') }}</span></div>
  <div class="desk-priority-grid">
    @foreach($deskPriorities as $priority)
      <a class="desk-priority-card {{ $priority['tone'] }}" href="{{ $priority['href'] }}" aria-label="{{ $priority['label'] }}: {{ number_format($priority['value']) }}. {{ $priority['detail'] }}. Open queue.">
        <span class="desk-priority-icon"><x-icon :name="$priority['icon']"/></span>
        <span class="desk-priority-copy"><span class="desk-priority-label">{{ $priority['label'] }}</span><strong>{{ number_format($priority['value']) }}</strong><small>{{ $priority['detail'] }}</small></span>
        <span class="desk-priority-open">Open queue <x-icon name="chev" size="sm"/></span>
      </a>
    @endforeach
  </div>
</section>
<div class="card desk-dashboard-room-panel">
  <div class="card-head"><div><span class="desk-section-eyebrow">LIVE ROOM STATUS</span><h3>Room snapshot</h3></div><span class="small muted">{{ count($rooms) }} active rooms</span></div>
  <div class="card-body" style="padding-top:.4rem">
    <div class="room-strip-legend" role="group" aria-label="Room status key"><span><i class="room-status-dot vacant"></i>Vacant</span><span><i class="room-status-dot occupied"></i>Occupied</span><span><i class="room-status-dot out"></i>Out of order / service</span><span>Housekeeping status on each room</span></div>
    <div class="room-strip" role="list" aria-label="Active rooms, live room status">
      @forelse($rooms as $room)
        <a class="room-thumb {{ $room->status }}" role="listitem" href="{{ route('reservations.room', $room) }}" aria-label="Room {{ $room->room_no }}, {{ ucwords(str_replace('_', ' ', $room->status)) }}, housekeeping {{ ucwords(str_replace('_', ' ', $room->hk_status)) }}" title="{{ $room->room_no }} · {{ ucwords(str_replace('_', ' ', $room->status)) }} · {{ ucwords(str_replace('_', ' ', $room->hk_status)) }}">
          @if($photoByRoom[$room->id] ?? null)<img src="{{ asset($photoByRoom[$room->id]) }}" alt="" loading="lazy">@else<span class="type-code" title="No photo on file">{{ $room->roomType->code }}</span>@endif
          <span class="room-status-label">{{ ucwords(str_replace('_', ' ', $room->status)) }}</span>
          <span class="rt-no">{{ $room->room_no }} <small>{{ ucwords(str_replace('_', ' ', $room->hk_status)) }}</small></span>
        </a>
      @empty
        <p class="desk-room-empty">No active rooms to show for this desk.</p>
      @endforelse
    </div>
  </div>
</div>
<div class="card">
  <div class="card-head"><h3>Trends — {{ $date->format('d M Y') }} to {{ $forecastEnd->format('d M Y') }}</h3><span class="small muted">14-day window · room revenue before tax</span></div>
  <div class="card-body" style="padding-top:.6rem">
    <div class="grid g-2">
      <div>
        <div class="tiny muted" style="margin-bottom:.3rem">Expected occupancy</div>
        <div class="bar-chart" role="group" aria-label="Expected occupancy over 14 days from the selected desk date">
          @foreach($pipeline as $p)
            <div class="col"><div class="b" role="img" aria-label="{{ $p['date']->format('d M') }}: expected occupancy {{ $p['occ'] === null ? 'unavailable; no sellable rooms' : $p['occ'].' percent' }}" title="{{ $p['date']->format('d M') }} · {{ $p['occ'] === null ? '—' : $p['occ'].'%' }}" style="height: {{ $p['occ'] === null ? 2 : max(2, min(100, $p['occ'])) }}%" data-v="{{ $p['date']->format('d M') }} · {{ $p['occ'] === null ? '—' : $p['occ'].'%' }}"></div><small>{{ $p['date']->format('d/m') }}</small></div>
          @endforeach
        </div>
      </div>
      <div>
        <div class="tiny muted" style="margin-bottom:.3rem">Expected room revenue (pre-tax)</div>
        <div class="bar-chart" role="group" aria-label="Expected room revenue before tax over 14 days from the selected desk date">
          @foreach($pipeline as $p)
            <div class="col"><div class="b" role="img" aria-label="{{ $p['date']->format('d M') }}: expected revenue {{ money($p['revenue']) }} before tax" title="{{ $p['date']->format('d M') }} · {{ money($p['revenue']) }}" style="height: {{ max(2, $p['revenue'] / $maxRev * 100) }}%" data-v="{{ $p['date']->format('d M') }} · {{ money($p['revenue']) }}"></div><small>{{ $p['date']->format('d/m') }}</small></div>
          @endforeach
        </div>
      </div>
    </div>
    <div class="desk-flow-chart">
      <div class="desk-flow-chart-heading"><span class="tiny muted">Arrivals vs departures — {{ $date->format('d M Y') }} to {{ $weekEnd->format('d M Y') }}</span><span class="desk-chart-legend" role="group" aria-label="Chart legend"><span><i class="arrivals"></i>Arrivals</span><span><i class="departures"></i>Departures</span></span></div>
      <div class="bar-chart" role="group" aria-label="Daily arrivals and departures from {{ $date->format('d M Y') }} through {{ $weekEnd->format('d M Y') }}">
        @foreach(array_slice($pipeline, 0, 7) as $p)
          <div class="col"><div class="desk-flow-bars">
            <div class="b arrivals" role="img" aria-label="{{ $p['date']->format('d M') }}: {{ $p['arrivals'] }} arrivals" title="{{ $p['date']->format('d M') }} · Arrivals {{ $p['arrivals'] }}" style="height: {{ max(2, $p['arrivals'] / $maxFlow * 100) }}%" data-v="{{ $p['date']->format('d M') }} · Arrivals {{ $p['arrivals'] }}"></div>
            <div class="b departures" role="img" aria-label="{{ $p['date']->format('d M') }}: {{ $p['departures'] }} departures" title="{{ $p['date']->format('d M') }} · Departures {{ $p['departures'] }}" style="height: {{ max(2, $p['departures'] / $maxFlow * 100) }}%" data-v="{{ $p['date']->format('d M') }} · Departures {{ $p['departures'] }}"></div>
          </div><small>{{ $p['date']->format('d/m') }}</small></div>
        @endforeach
      </div>
    </div>
  </div>
</div>
<div class="grid g-2">
  <div class="card">
    <div class="card-head"><h3>On the books</h3><span class="small muted">Room revenue before tax</span></div>
    <div class="card-body" style="padding-top:.3rem">
      <ul class="list">
        <li><div><span class="muted small">Desk date</span><div class="tiny muted">{{ $date->format('d M Y') }}</div></div><b>{{ money($pipeline[0]['revenue']) }}</b></li>
        <li><div><span class="muted small">14-day window</span><div class="tiny muted">{{ $date->format('d M Y') }} to {{ $forecastEnd->format('d M Y') }}</div></div><b>{{ money($onTheBooks['days14']) }}</b></li>
        <li><div><span class="muted small">30-day window</span><div class="tiny muted">{{ $date->format('d M Y') }} to {{ $thirtyDayEnd->format('d M Y') }}</div></div><b>{{ money($onTheBooks['days30']) }}</b></li>
      </ul>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h3>{{ $date->isSameDay(bdate()) ? 'Past ETA — not checked in' : 'ETA watch' }}</h3><span class="small muted">{{ $lateArrivals->count() }} alerts · {{ $date->isSameDay(bdate()) ? 'No-show risk' : 'Live date only' }}</span><a class="small" href="{{ $dashGo(['view' => 'arrivals']) }}">All arrivals</a></div>
    <div class="card-body" style="padding-top:.3rem">
      <ul class="list">
        @forelse($lateArrivals->take(6) as $s)
          <li><div><b>{{ $s->reservation->guest->full_name }}</b>@if($s->reservation->guest->is_vip) <span class="badge badge-purple">VIP</span>@endif
            <div class="tiny muted">{{ $s->reservation->reservation_no }} · ETA {{ substr((string) $s->reservation->eta, 0, 5) }} · {{ $s->room->room_no ?? 'Unassigned' }}</div></div>
            <div class="flex gap-1"><a class="btn btn-sm" href="{{ route('reservations.stay', $s) }}">Open</a>
            @if($s->room_id && can_do('frontdesk.checkin'))<a class="btn btn-sm btn-success" href="{{ route('frontdesk.checkin', $s) }}">Check in</a>@endif</div></li>
        @empty
          <li class="muted">{{ $date->isSameDay(bdate()) ? 'No arrivals past their ETA.' : 'Past-ETA alerts are only evaluated for the current business date.' }}</li>
        @endforelse
        @if($lateArrivals->count() > 6)<li class="muted small">+ {{ $lateArrivals->count() - 6 }} more alerts in the full arrivals list</li>@endif
      </ul>
    </div>
  </div>
</div>
<div class="grid g-2">
  <div class="card">
    <div class="card-head"><h3>{{ $date->copy()->startOfDay()->lt(bdate()->copy()->startOfDay()) ? 'Arrivals' : 'Expected arrivals' }} — {{ $date->format('d M Y') }}</h3><a class="small" href="{{ $dashGo(['view' => 'arrivals']) }}">Full list</a></div>
    @if($dueIn->isNotEmpty())
    <div class="card-body" style="padding-bottom:0"><span class="badge {{ $readyCount === $dueIn->count() ? 'badge-green' : 'badge-amber' }}">{{ $readyCount }} of {{ $dueIn->count() }} ready to check in</span><span class="tiny muted"> · ID, phone and room on file</span></div>
    @endif
    <div class="card-body" style="padding-top:.3rem">
      <ul class="list">
        @forelse($arrivalsByEta->take(8) as $s)
          @php $eta = $s->reservation->eta ? substr((string) $s->reservation->eta, 0, 5) : '—'; @endphp
          <li><div><b>{{ $s->reservation->guest->full_name }}</b>@if($s->reservation->guest->is_vip) <span class="badge badge-purple">VIP</span>@endif
            <div class="tiny muted">{{ $s->reservation->reservation_no }} · @if($s->room_id && ($photoByRoom[$s->room_id] ?? null))<img class="rt-mini" src="{{ asset($photoByRoom[$s->room_id]) }}" alt="">@endif {{ $s->room->room_no ?? 'Unassigned' }} · ETA {{ $eta }} · {{ $s->status === 'checked_in' ? 'Checked in' : ($s->status === 'checked_out' ? 'Checked out' : \App\Services\StayService::checkInReady($s)) }}</div></div>
            <div class="flex gap-1"><a class="btn btn-sm" href="{{ route('reservations.stay', $s) }}">Open</a>
            @if($s->status === 'reserved' && $s->room_id && can_do('frontdesk.checkin'))<a class="btn btn-sm btn-success" href="{{ route('frontdesk.checkin', $s) }}">Check in</a>@endif</div></li>
        @empty
          <li class="muted">No arrivals on file for this date.</li>
        @endforelse
        @if($arrivalsByEta->count() > 8)<li class="muted small">+ {{ $arrivalsByEta->count() - 8 }} more arrivals in the full arrivals list</li>@endif
      </ul>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h3>{{ $historicalDeskDate ? 'Departures' : 'Due departures' }} — {{ $date->format('d M Y') }}</h3><span class="small muted">{{ $departures->count() }} stays</span><a class="small" href="{{ $dashGo(['view' => 'departures']) }}">Full list</a></div>
    <div class="card-body" style="padding-top:.3rem">
      <ul class="list">
        @forelse($departures->take(8) as $s)
          @php
            $due = $s->status === 'checked_in' ? \App\Services\StayService::quoteCheckoutFigures($s)['due'] : null;
            $holds = $s->status === 'checked_in' ? \App\Services\StayService::checkoutHolds($s) : null;
            // Canonical count from checkoutHolds(): POS orders and a pending Form C
            // block a checkout too, and this badge used to miss both.
            $blocked = $holds && $holds['blocking'] > 0;
          @endphp
          <li><div><b>{{ $s->reservation->guest->full_name }}</b>@if($s->status === 'checked_in' && $blocked) <span class="badge badge-red">Blocked</span>@endif
            <div class="tiny muted">@if($s->room_id && ($photoByRoom[$s->room_id] ?? null))<img class="rt-mini" src="{{ asset($photoByRoom[$s->room_id]) }}" alt="">@endif {{ $s->room->room_no ?? '—' }} · @if($s->status === 'reserved')Not checked in. @elseif($s->status === 'checked_out')Checked out. @elseif($due === null)No folio. @else To settle {{ money($due) }} · not posted. @endif</div></div>
            @if($s->status === 'checked_in' && can_do('frontdesk.checkout'))<a class="btn btn-sm btn-warning" href="{{ route('frontdesk.checkout', $s) }}">Check out</a>@endif</li>
        @empty
          <li class="muted">No departures on file for this date.</li>
        @endforelse
        @if($departures->count() > 8)<li class="muted small">+ {{ $departures->count() - 8 }} more departures in the full list</li>@endif
      </ul>
    </div>
  </div>
</div>
<div class="grid g-2">
  <div class="card">
    <div class="card-head"><h3>Current in-house</h3><a class="small" href="{{ $dashGo(['view' => 'inhouse']) }}">Full list</a></div>
    <div class="card-body" style="padding-top:.3rem">
      <ul class="list">
        <li><span class="muted small">Guests in house</span><b>{{ $pax }}</b></li>
        <li><span class="muted small">Rooms occupied</span><b>{{ $inhouse->whereNotNull('room_id')->count() }}</b></li>
        <li><span class="muted small">VIP in house</span><b>{{ $vipIn }}</b></li>
      </ul>
      @if($inhouse->isNotEmpty())
      <ul class="list" style="margin-top:.4rem">
        @foreach($inhouse->take(6) as $s)
          <li><div><b>{{ $s->reservation->guest->full_name }}</b><div class="tiny muted">{{ $s->room->room_no ?? 'Unassigned' }} · dep {{ $s->departure_date->format('d M') }}@if($s->departure_date->toDateString() === $date->toDateString()) · due out on {{ $date->format('d M') }}. @endif</div></div></li>
        @endforeach
        @if($inhouse->count() > 6)<li class="muted small">+ {{ $inhouse->count() - 6 }} more in the in-house list</li>@endif
      </ul>
      @endif
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h3>Unassigned</h3><a class="small" href="{{ $dashGo(['view' => 'unassigned']) }}">Full list</a></div>
    <div class="card-body" style="padding-top:.3rem">
      <ul class="list">
        @forelse($unassigned->take(8) as $s)
          <li><div><b>{{ $s->reservation->guest->full_name }}</b><div class="tiny muted">{{ $s->reservation->reservation_no }} · {{ $s->roomType->name }} · arrives {{ $s->arrival_date->format('d M Y') }}</div></div>
            <a class="btn btn-sm" href="{{ route('reservations.stay', $s) }}">Assign</a></li>
        @empty
          <li class="muted">Every reserved stay has a room.</li>
        @endforelse
        @if($unassigned->count() > 8)<li class="muted small">+ {{ $unassigned->count() - 8 }} more stays in the full unassigned list</li>@endif
      </ul>
    </div>
  </div>
</div>
<div class="grid g-2">
  <div class="card">
    <div class="card-head"><h3>Walk-ins — {{ $date->format('d M Y') }}</h3><span class="small muted">{{ $kpis['walkins'] }} reservations on file</span></div>
    <div class="card-body" style="padding-top:.3rem">
      <ul class="list">
        @forelse($walkInArrivals as $s)
          <li><div><b>{{ $s->reservation->guest->full_name }}</b>
            <div class="tiny muted">{{ $s->reservation->reservation_no }} · {{ $s->room->room_no ?? 'Unassigned' }} · {{ \App\Services\StayService::checkInReady($s) }}</div></div>
            @if($s->status === 'reserved' && $s->room_id && can_do('frontdesk.checkin'))<a class="btn btn-sm btn-success" href="{{ route('frontdesk.checkin', $s) }}">Check in</a>@endif</li>
        @empty
          <li class="muted">No walk-ins on file for this date.</li>
        @endforelse
      </ul>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h3>Comps &amp; house use — {{ $date->format('d M Y') }}</h3><span class="small muted">Covering the desk date</span></div>
    <div class="card-body" style="padding-top:.3rem">
      <ul class="list">
        @forelse($compStays as $s)
          <li><div><b>{{ $s->reservation->guest->full_name }}</b>
            <div class="tiny muted">{{ $s->room->room_no ?? 'Unassigned' }} · {{ \App\Services\ReservationDeskService::stayLabel($s, $date) }}</div></div>
            <a class="btn btn-sm" href="{{ route('reservations.stay', $s) }}">Open</a></li>
        @empty
          <li class="muted">No complimentary or house-use stays for this date.</li>
        @endforelse
      </ul>
    </div>
  </div>
</div>
<div class="grid g-2">
  <div class="card">
    <div class="card-head"><h3>{{ $date->format('d M Y') }} to {{ $weekEnd->format('d M Y') }}</h3><span class="small muted">7-day window · room revenue before tax</span></div>
  <div class="card-body table-wrap">
    <table class="table">
      <caption class="sr-only">Arrivals, departures, expected rooms and pre-tax room revenue from {{ $date->format('d M Y') }} through {{ $weekEnd->format('d M Y') }}</caption>
      <thead><tr><th scope="col">Day</th><th scope="col">Arrivals</th><th scope="col">Departures</th><th scope="col">Rooms expected</th><th scope="col">Room revenue (pre-tax)</th></tr></thead>
      <tbody>
        @foreach(array_slice($pipeline, 0, 7) as $p)
          <tr>
            <td class="nowrap">@if($p['date']->isSameDay($date)){{ $date->isSameDay(bdate()) ? 'Today' : 'Desk date' }} · {{ $p['date']->format('d M') }}@else{{ $p['date']->format('l, d M') }}@endif</td>
            <td>{{ $p['arrivals'] }}</td>
            <td>{{ $p['departures'] }}</td>
            <td>{{ $p['rooms'] }}</td>
            <td>{{ money($p['revenue']) }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
  </div>
  <div class="card">
    <div class="card-head"><h3>Booking sources — 14-day window</h3><span class="small muted">{{ $date->format('d M Y') }} to {{ $forecastEnd->format('d M Y') }}</span></div>
    <div class="card-body table-wrap">
      <table class="table">
        <caption class="sr-only">Booking sources, room counts and expected pre-tax revenue from {{ $date->format('d M Y') }} through {{ $forecastEnd->format('d M Y') }}</caption>
        <thead><tr><th scope="col">Source</th><th scope="col">Rooms</th><th scope="col">Expected revenue (pre-tax)</th></tr></thead>
        <tbody>
          @forelse($sourceMix as $row)
            <tr><td>{{ $row['source'] }}</td><td>{{ $row['rooms'] }}</td><td>{{ money($row['revenue']) }}</td></tr>
          @empty
            <tr><td colspan="3">No bookings on file in this 14-day window.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>
</div>
<div class="grid g-2">
  <div class="card">
    <div class="card-head"><h3>VIP arrivals — {{ $date->format('d M Y') }} to {{ $weekEnd->format('d M Y') }}</h3></div>
    <div class="card-body" style="padding-top:.3rem">
      <ul class="list">
        @forelse($vipArrivals as $s)
          <li><div><b>{{ $s->reservation->guest->full_name }}</b> <span class="badge badge-purple">VIP</span>
            <div class="tiny muted">{{ $s->reservation->reservation_no }} · {{ $s->arrival_date->format('d M Y') }} · {{ $s->room->room_no ?? 'Unassigned' }}@if($s->reservation->eta) · ETA {{ substr((string) $s->reservation->eta, 0, 5) }} @endif</div></div>
            <a class="btn btn-sm" href="{{ route('reservations.stay', $s) }}">Open</a></li>
        @empty
          <li class="muted">No VIP arrivals in this 7-day window.</li>
        @endforelse
      </ul>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h3>More desk activity</h3></div>
    <div class="card-body flex gap-1 wrap" style="align-items:center">
    <a class="btn btn-sm" href="{{ $dashGo(['tab' => 'vip', 'view' => 'timeline']) }}">VIP {{ $kpis['vip'] }}</a>
    <a class="btn btn-sm" href="{{ $dashGo(['view' => 'groups']) }}">Groups {{ $kpis['groups'] }}</a>
    <a class="btn btn-sm" href="{{ $dashGo(['view' => 'waitlist']) }}">Waitlist {{ $kpis['waitlist'] }}</a>
    <a class="btn btn-sm" href="{{ $dashGo(['view' => 'noshow', 'date' => $date->toDateString(), 'days' => 1]) }}">No-shows {{ $kpis['noshow'] }}</a>
    <a class="btn btn-sm" href="{{ $dashGo(['view' => 'cancelled', 'date' => $date->toDateString(), 'days' => 1]) }}">Cancelled {{ $kpis['cancelled'] }}</a>
  </div>
  @if($groups->isNotEmpty())
  <div class="card-body" style="padding-top:0">
    <div class="tiny muted" style="margin-bottom:.2rem">Group bookings · {{ $groups->count() }} · {{ $date->format('d M Y') }} to {{ $groupWindowEnd->format('d M Y') }}</div>
    <ul class="list">
      @foreach($groups->take(4) as $g)
        <li><div><b>{{ $g->group_name ?: 'Group' }}</b><div class="tiny muted">{{ $g->reservation_no }} · {{ $g->rooms->count() }} rooms · arrives {{ $g->arrival_date->format('d M Y') }}</div></div>
          <a class="btn btn-sm" href="{{ $dashGo(['view' => 'groups']) }}">Open</a></li>
      @endforeach
      @if($groups->count() > 4)<li class="muted small">+ {{ $groups->count() - 4 }} more groups in this window</li>@endif
    </ul>
  </div>
  @endif
  <div class="card-body" style="padding-top:.4rem">
    @php $waitingList = $waitlist->where('status', 'waiting'); @endphp
    <div class="tiny muted" style="margin-bottom:.2rem">Waitlist — waiting</div>
    <ul class="list">
      @forelse($waitingList->take(4) as $w)
        <li><div><b>{{ $w->contact_name }}</b>
          <div class="tiny muted">{{ $w->roomType?->name ?? 'No type' }} · P{{ $w->priority }} · {{ $w->arrival_date->format('d M') }}–{{ $w->departure_date->format('d M') }} · {{ (int) $w->adults + (int) $w->children }} pax</div></div></li>
      @empty
        <li class="muted">Waitlist is clear.</li>
      @endforelse
      @if($waitingList->count() > 4)<li class="muted small">+ {{ $waitingList->count() - 4 }} more waiting · <a href="{{ $dashGo(['view' => 'waitlist']) }}">full waitlist</a></li>@endif
    </ul>
  </div>
  </div>
</div>
<div class="grid g-2">
  <div class="card">
    <div class="card-head"><h3>Group arrivals — {{ $date->format('d M Y') }} to {{ $weekEnd->format('d M Y') }}</h3></div>
    <div class="card-body" style="padding-top:.3rem">
      <ul class="list">
        @forelse($groupArrivals as $g)
          <li><div><b>{{ $g->group_name ?: 'Group' }}</b> <span class="badge {{ $g->status === 'confirmed' ? 'badge-green' : 'badge-amber' }}">{{ $g->status }}</span>
            <div class="tiny muted">{{ $g->reservation_no }} · {{ $g->rooms->count() }} rooms · arrives {{ $g->arrival_date->format('d M Y') }}</div></div>
            <a class="btn btn-sm" href="{{ route('reservations.show', $g) }}">Open</a></li>
        @empty
          <li class="muted">No group arrivals in this 7-day window.</li>
        @endforelse
      </ul>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h3>No-shows — {{ $date->format('d M Y') }}</h3><span class="small muted">Marked on the desk</span></div>
    <div class="card-body" style="padding-top:.3rem">
      <ul class="list">
        @forelse($noShows as $g)
          @php $roomNos = $g->rooms->map(fn ($s) => $s->room?->room_no)->filter()->implode(', '); @endphp
          <li><div><b>{{ $g->guest->full_name }}</b>
            <div class="tiny muted">{{ $g->reservation_no }} · {{ $g->rooms->count() }} room(s){{ $roomNos ? ' · '.$roomNos : '' }}</div></div>
            <a class="btn btn-sm" href="{{ route('reservations.show', $g) }}">Open</a></li>
        @empty
          <li class="muted">No no-shows marked for this date.</li>
        @endforelse
      </ul>
    </div>
  </div>
</div>
<div class="card">
  <div class="card-head"><h3>Room types — 14-day window</h3><span class="small muted">{{ $date->format('d M Y') }} to {{ $forecastEnd->format('d M Y') }} · revenue before tax</span></div>
  <div class="card-body table-wrap">
    <table class="table">
      <caption class="sr-only">Room counts and expected pre-tax room revenue by room type from {{ $date->format('d M Y') }} through {{ $forecastEnd->format('d M Y') }}</caption>
      <thead><tr><th scope="col">Room type</th><th scope="col">Rooms on desk date</th><th scope="col">Room stays in window</th><th scope="col">Expected revenue (pre-tax)</th></tr></thead>
      <tbody>
        @forelse($roomTypePipeline as $row)
          <tr><td>{{ $row['type'] }}</td><td>{{ $row['desk_date'] }}</td><td>{{ $row['bookings'] }}</td><td>{{ money($row['revenue']) }}</td></tr>
        @empty
          <tr><td colspan="4">No bookings on file in this 14-day window.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>
</div>
