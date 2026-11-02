@extends('layouts.app')
@section('title', match($view ?? null) { 'dashboard' => 'Reservation Desk', 'timeline' => 'Hotel Timeline', 'board' => 'Room Board', 'list' => 'Reservation Register', 'arrivals' => 'Arrivals', 'departures' => 'Departures', 'waitlist' => 'Reservation Waitlist', 'groups' => 'Group Reservations', 'noshow' => 'No-show Register', 'cancelled' => 'Cancelled Register', default => 'Reservations' })
@section('actions')
  @if(($view ?? null) === 'list')<a href="{{ route('reservations.export', request()->query()) }}" class="btn"><x-icon name="download"/> Export list</a>@endif
  <a href="{{ route('reservations.availability') }}" class="btn"><x-icon name="grid"/> Availability</a>
  @if(($view ?? null) === 'dashboard' && can_do('frontdesk.checkin'))<a href="{{ route('frontdesk.walkin') }}" class="btn btn-accent"><x-icon name="plus"/> Walk-in</a>@endif
  @if(can_do('reservations.create'))<a href="{{ route('reservations.create') }}" class="btn btn-primary"><x-icon name="plus"/> New Reservation</a>@endif
@endsection
@section('content')
@php
  $q = request()->except('page');
  $go = function (array $extra = []) use ($q) {
    $query = array_merge($q, $extra);
    if (($query['view'] ?? null) === 'list') {
      unset($query['tab'], $query['vip']);
      if (($query['status'] ?? null) !== 'cancelled') {
        unset($query['cancelled_on']);
      }
    }

    return route('reservations.index', $query);
  };
  $folioMetricHref = function (string $label, array $extra) use ($date, $go) {
    return match (true) {
      $label === 'Outstanding' && can_do('folio.view') => route('folios.index', ['status' => 'open', 'scope' => 'reservation_desk']),
      $label === 'Posted room' && can_do('folio.view') => route('folios.transactions', ['date' => $date->toDateString(), 'type' => 'charge', 'category' => 'room']),
      $label === 'Posted all' && can_do('folio.view') => route('folios.transactions', ['date' => $date->toDateString(), 'type' => 'charge']),
      $label === 'Deposits' && can_do('folio.view') => route('folios.transactions', ['date' => $date->toDateString(), 'type' => 'payment', 'category' => 'advance']),
      default => $go($extra),
    };
  };
  $tabCounts = $tabs;
  $nav = [
    'arrivals' => 'Arrivals', 'duein' => 'Due in', 'inhouse' => 'In-house', 'stayover' => 'Stayover', 'dueout' => 'Due out',
    'departures' => 'Departures', 'unassigned' => 'Unassigned', 'vip' => 'VIP', 'groups' => 'Groups',
    'waitlist' => 'Waitlist', 'noshow' => 'No-show', 'cancelled' => 'Cancelled',
  ];
  $views = ['dashboard' => 'Dashboard', 'timeline' => 'Timeline', 'board' => 'Room board', 'list' => 'List', 'arrivals' => 'Arrivals', 'departures' => 'Departures', 'inhouse' => 'In-house', 'unassigned' => 'Unassigned', 'waitlist' => 'Waitlist', 'groups' => 'Groups', 'noshow' => 'No-show', 'cancelled' => 'Cancelled'];
@endphp
<div id="desk" class="desk" data-view="{{ $view }}">
  @if($view === 'list')
    <header class="reservation-list-hero">
      <div class="reservation-list-hero-copy">
        <span class="reservation-list-eyebrow"><x-icon name="calendar" size="sm"/> FRONT OFFICE <span>/</span> RESERVATION REGISTER</span>
        <h2>Every stay, clearly in view.</h2>
        <p>Find a booking, check the guest journey, and move from search to action without losing context.</p>
      </div>
      <div class="reservation-list-hero-aside">
        <div class="reservation-list-date"><span>DESK DATE</span><strong>{{ $date->format('D, d M Y') }}</strong><small>Business date · {{ bdate()->format('d M Y') }}</small></div>
        <button type="button" class="btn btn-sm" id="print-btn"><x-icon name="print" size="sm"/> Print this page</button>
      </div>
    </header>
  @elseif($view === 'arrivals')
    <header class="arrival-hero">
      <div class="arrival-hero-copy">
        <span class="arrival-eyebrow"><x-icon name="calendar" size="sm"/> FRONT OFFICE <i aria-hidden="true">/</i> ARRIVAL QUEUE</span>
        <h2>{{ $date->isSameDay(bdate()) ? 'Welcome starts here.' : 'Prepare every arrival.' }}</h2>
        <p>See who is due, what needs follow-up, and the guest, room and arrival details your team needs.</p>
        <div class="arrival-date-meta">
          <span><b>Arrival date</b> <time datetime="{{ $date->toDateString() }}">{{ $date->format('l, d F Y') }}</time></span>
          <span><b>Business date</b> <time datetime="{{ bdate()->toDateString() }}">{{ bdate()->format('d M Y') }}</time></span>
        </div>
        <div class="arrival-hero-actions">
          <button type="button" class="btn btn-sm" id="print-btn"><x-icon name="print" size="sm"/> Print arrivals</button>
          @if(can_do('frontdesk.view'))<a class="btn btn-sm" href="{{ route('frontdesk.arrivals', ['date' => $date->toDateString()]) }}">Open Front Desk queue</a>@endif
        </div>
      </div>
      <div class="arrival-summary" aria-label="Arrival summary">
        <div class="arrival-summary-primary"><span>Scheduled stays</span><strong>{{ number_format($arrivalSummary['total']) }}</strong><small>room stays on this date</small></div>
        <div><span>Pending check-in</span><strong>{{ number_format($arrivalSummary['due_in']) }}</strong></div>
        <div><span>Processed</span><strong>{{ number_format($arrivalSummary['processed']) }}</strong><small>checked in or completed</small></div>
        <div><span>Need a room</span><strong>{{ number_format($arrivalSummary['unassigned']) }}</strong></div>
        <div><span>Guest details needed</span><strong>{{ number_format($arrivalSummary['guest_details']) }}</strong><small>ID or phone missing</small></div>
        @if($date->isSameDay(bdate()))<div><span>Past ETA</span><strong>{{ number_format($arrivalSummary['late_eta']) }}</strong><small>still due to check in</small></div>@endif
      </div>
    </header>
  @elseif($view === 'waitlist')
    <header class="waitlist-hero">
      <div class="waitlist-hero-copy">
        <span class="waitlist-eyebrow"><x-icon name="clock" size="sm"/> FRONT OFFICE <i aria-hidden="true">/</i> RESERVATION WAITLIST</span>
        <h2>Be ready when a room opens.</h2>
        <p>Keep guest requests visible, check availability for the requested dates, and create a reservation only when a room type is available. Conversion does not assign a room.</p>
        <div class="waitlist-date-meta">
          <span><b>Arrival window starts</b> <time datetime="{{ $date->toDateString() }}">{{ $date->format('l, d F Y') }}</time></span>
          <span><b>Business date</b> <time datetime="{{ bdate()->toDateString() }}">{{ bdate()->format('d M Y') }}</time></span>
        </div>
        <div class="waitlist-hero-actions">
          <button type="button" class="btn btn-sm" id="print-btn"><x-icon name="print" size="sm"/> Print waitlist</button>
          <a class="btn btn-sm" href="{{ route('reservations.availability', ['from' => $date->toDateString()]) }}">Open availability</a>
          @if(can_do('reservations.create'))<a class="btn btn-sm waitlist-add-shortcut" href="#waitlist-add-form">Add an entry</a>@endif
        </div>
      </div>
      <section class="waitlist-summary" aria-label="Waitlist summary">
        <div class="waitlist-summary-primary"><span>Waitlist entries</span><strong>{{ number_format($waitlistSummary['total']) }}</strong><small>all statuses</small></div>
        <div><span>Waiting</span><strong>{{ number_format($waitlistSummary['waiting']) }}</strong><small>not yet booked</small></div>
        <div><span>Arriving in 7 days</span><strong>{{ number_format($waitlistSummary['next_7_days']) }}</strong><small>waiting entries</small></div>
        <div><span>Room type needed</span><strong>{{ number_format($waitlistSummary['needs_type']) }}</strong><small>cannot check or convert</small></div>
        <div><span>Converted</span><strong>{{ number_format($waitlistSummary['converted']) }}</strong><small>reservation created</small></div>
        <div><span>Cancelled</span><strong>{{ number_format($waitlistSummary['cancelled']) }}</strong><small>closed entries</small></div>
      </section>
    </header>
  @elseif($view === 'groups')
    <header class="groups-hero">
      <div class="groups-hero-copy">
        <span class="groups-eyebrow"><x-icon name="users" size="sm"/> FRONT OFFICE <i aria-hidden="true">/</i> GROUP STAY COORDINATION</span>
        <h2>Bring every group stay together.</h2>
        <p>See group bookings, room stays and block pickup in one place. Rooming-list pickup uses the existing block preview and confirmation flow.</p>
        <div class="groups-date-meta"><span><b>Window starts</b> <time datetime="{{ $date->toDateString() }}">{{ $date->format('l, d F Y') }}</time></span><span><b>Window length</b> {{ $days }} days</span><span><b>Business date</b> {{ bdate()->format('d M Y') }}</span></div>
        <div class="groups-hero-actions"><button type="button" class="btn btn-sm" id="print-btn"><x-icon name="print" size="sm"/> Print group roster</button>@if(can_do('reservations.create'))<a class="btn btn-sm" href="{{ route('blocks.create') }}">Open group engine</a>@endif</div>
      </div>
      <section class="groups-summary" aria-label="Group reservation summary">
        <div class="groups-summary-primary"><span>Group bookings</span><strong>{{ number_format($groupSummary['total']) }}</strong><small>overlapping this window</small></div>
        <div><span>Room stays</span><strong>{{ number_format($groupSummary['room_stays']) }}</strong><small>not cancelled</small></div>
        <div><span>Groups with a block</span><strong>{{ number_format($groupSummary['with_block']) }}</strong><small>block attached</small></div>
        <div><span>Tentative</span><strong>{{ number_format($groupSummary['tentative']) }}</strong><small>awaiting confirmation</small></div>
        <div><span>Confirmed</span><strong>{{ number_format($groupSummary['confirmed']) }}</strong><small>group bookings</small></div>
        <div><span>In-house</span><strong>{{ number_format($groupSummary['in_house']) }}</strong><small>currently checked in</small></div>
      </section>
    </header>
  @elseif($view === 'noshow')
    <header class="noshow-hero">
      <div class="noshow-hero-copy">
        <span class="noshow-eyebrow"><x-icon name="alert" size="sm"/> FRONT OFFICE <i aria-hidden="true">/</i> NO-SHOW REGISTER</span>
        <h2>Keep missed arrivals clear and traceable.</h2>
        <p>Review reservations already marked no-show, their expected stay dates and room stays. This register is read-only; open a booking for its existing history and folio controls.</p>
        <div class="noshow-date-meta"><span><b>Arrival window</b> <time datetime="{{ $date->toDateString() }}">{{ $date->format('l, d F Y') }}</time> · {{ $days }} days</span><span><b>Business date</b> <time datetime="{{ bdate()->toDateString() }}">{{ bdate()->format('d M Y') }}</time></span></div>
        <div class="noshow-hero-actions"><button type="button" class="btn btn-sm" id="print-btn"><x-icon name="print" size="sm"/> Print no-show register</button><a class="btn btn-sm" href="{{ $go(['view' => 'arrivals', 'date' => bdate()->toDateString()]) }}">Open arrivals</a></div>
      </div>
      <section class="noshow-summary" aria-label="No-show register summary">
        <div class="noshow-summary-primary"><span>No-show bookings</span><strong>{{ number_format($noShowSummary['total']) }}</strong><small>arrival dates in this window</small></div>
        <div><span>Room stays</span><strong>{{ number_format($noShowSummary['room_stays']) }}</strong><small>marked no-show</small></div>
        <div><span>Individual bookings</span><strong>{{ number_format($noShowSummary['individuals']) }}</strong><small>not group bookings</small></div>
        <div><span>Group bookings</span><strong>{{ number_format($noShowSummary['groups']) }}</strong><small>group reservations</small></div>
      </section>
    </header>
  @elseif($view === 'cancelled')
    <header class="cancelled-hero">
      <div class="cancelled-hero-copy">
        <span class="cancelled-eyebrow"><x-icon name="calendar" size="sm"/> FRONT OFFICE <i aria-hidden="true">/</i> CANCELLATION REGISTER</span>
        <h2>Keep cancellation history clear and factual.</h2>
        <p>Review reservations with a recorded cancellation timestamp, their original stay dates and linked room stays. This register is read-only; open a booking for its existing history and folio controls.</p>
        <div class="cancelled-date-meta"><span><b>Cancellation window</b> <time datetime="{{ $date->toDateString() }}">{{ $date->format('l, d F Y') }}</time> · {{ $days }} days</span><span><b>Business date</b> <time datetime="{{ bdate()->toDateString() }}">{{ bdate()->format('d M Y') }}</time></span></div>
        <div class="cancelled-hero-actions"><button type="button" class="btn btn-sm" id="print-btn"><x-icon name="print" size="sm"/> Print cancellations</button><a class="btn btn-sm" href="{{ $go(['view' => 'list', 'status' => 'cancelled', 'tab' => null]) }}">Open reservation register</a></div>
      </div>
      <section class="cancelled-summary" aria-label="Cancelled reservation summary">
        <div class="cancelled-summary-primary"><span>Cancelled bookings</span><strong>{{ number_format($cancelledSummary['total']) }}</strong><small>recorded in this window</small></div>
        <div><span>Room stays</span><strong>{{ number_format($cancelledSummary['room_stays']) }}</strong><small>cancelled room stays</small></div>
        <div><span>Individual bookings</span><strong>{{ number_format($cancelledSummary['individuals']) }}</strong><small>not group bookings</small></div>
        <div><span>Group bookings</span><strong>{{ number_format($cancelledSummary['groups']) }}</strong><small>group reservations</small></div>
      </section>
    </header>
  @elseif($view === 'dashboard')
    <header class="desk-head desk-dashboard-hero">
      <div class="desk-dashboard-hero-copy">
        <span class="desk-dashboard-eyebrow"><x-icon name="hotel" size="sm"/> FRONT OFFICE <span aria-hidden="true">/</span> DAILY OPERATIONS</span>
        <h2>{{ $date->isSameDay(bdate()) ? 'Today at a glance' : 'Desk overview' }}</h2>
        <div class="desk-dashboard-date-line">
          <span>{{ $date->format('l, d M Y') }}</span>
          <span>Business date · {{ bdate()->format('d M Y') }}</span>
          <span>{{ number_format($kpis['sellable']) }} rooms on sale</span>
        </div>
      </div>
      <form class="desk-dashboard-search" action="{{ route('reservations.index') }}" method="get" role="search">
        <input type="hidden" name="view" value="list">
        <input type="hidden" name="date" value="{{ $date->toDateString() }}">
        <label for="deskDashboardSearch">Find a reservation</label>
        <div class="desk-dashboard-search-control"><x-icon name="search" size="sm"/><input id="deskDashboardSearch" type="search" name="q" maxlength="160" placeholder="Guest, confirmation, phone or room" autocomplete="off"><button type="submit" class="btn btn-sm"><x-icon name="search" size="sm"/> Search</button></div>
      </form>
      <details class="desk-dashboard-tools">
        <summary><x-icon name="grid" size="sm"/> Desk tools</summary>
        <div class="desk-dashboard-tool-menu">
          <a href="{{ route('reservations.plan', request()->only(['date','days','group'])) }}" target="_blank" rel="noopener">Print plan</a>
          <a href="{{ route('reservations.daysummary', request()->only(['date','days','group'])) }}" target="_blank" rel="noopener">Day summary</a>
          <button type="button" id="print-btn">Print this desk</button>
          <a href="{{ route('reservations.export', request()->query()) }}">Export reservations</a>
          <a href="{{ route('frontdesk.tapechart') }}">Tape chart</a>
          <a href="{{ route('reservations.amenities') }}">Amenities &amp; photos</a>
          @if(can_do('nightaudit.run'))<a href="{{ route('nightaudit.index') }}">Night audit</a>@endif
          @if(can_do('gl.view'))<a href="{{ route('gl.index') }}">Ledger</a>@endif
          @if(can_do('logs.view'))<a href="{{ route('logs.index') }}">Audit log</a>@endif
          @if(can_do('users.manage'))<a href="{{ route('roles.index') }}">Permissions</a>@endif
        </div>
      </details>
    </header>
  @elseif($view === 'timeline')
    <header class="timeline-hero">
      <div class="timeline-hero-copy">
        <span class="timeline-eyebrow"><x-icon name="hotel" size="sm"/> FRONT OFFICE <i aria-hidden="true">/</i> ROOM TIMELINE</span>
        <h2>Every room, every stay</h2>
        <p>See every room stay, arrival and departure across the selected window.</p>
        <div class="timeline-range-line"><strong>{{ $date->format('j M') }} – {{ $date->copy()->addDays($days - 1)->format('j M Y') }}</strong><span>Desk date · {{ $date->format('l, d M Y') }}</span><span>Business date · {{ bdate()->format('d M Y') }}</span></div>
        <div class="timeline-hero-actions">
          <a class="btn btn-sm" href="{{ route('reservations.plan', request()->only(['date','days','group'])) }}" target="_blank" rel="noopener"><x-icon name="print" size="sm"/> Print plan</a>
          <a class="btn btn-sm" href="{{ route('reservations.daysummary', request()->only(['date','days','group'])) }}" target="_blank" rel="noopener">Day summary</a>
          <button type="button" class="btn btn-sm" id="print-btn"><x-icon name="print" size="sm"/> Print timeline</button>
        </div>
      </div>
      <div class="timeline-hero-metrics" aria-label="Hotel snapshot">
        <div><span>Sellable</span><strong>{{ number_format($kpis['sellable']) }}</strong><small>rooms</small></div>
        <div><span>Occupancy</span><strong>{{ $kpis['occupancy'] === null ? '—' : $kpis['occupancy'].'%' }}</strong><small>{{ number_format($kpis['sold']) }} sold</small></div>
        <div><span>Arrivals</span><strong>{{ number_format($kpis['arrivals']) }}</strong><small>this date</small></div>
        <div><span>Departures</span><strong>{{ number_format($kpis['due_out']) }}</strong><small>this date</small></div>
        <div><span>Unassigned</span><strong>{{ number_format($loose->count()) }}</strong><small>in this window</small></div>
      </div>
    </header>
  @elseif($view === 'board')
    @php
      $boardRoomCount = $rooms->count();
      $boardOccupiedCount = $rooms->where('status', 'occupied')->count();
      $boardVacantCount = $rooms->where('status', 'vacant')->count();
      $boardBlockedCount = $rooms->filter(fn ($room) => ($outages[$room->id] ?? collect())->isNotEmpty())->count();
    @endphp
    <header class="room-board-hero">
      <div class="room-board-hero-copy">
        <span class="room-board-eyebrow"><x-icon name="hotel" size="sm"/> FRONT OFFICE <i aria-hidden="true">/</i> ROOM BOARD</span>
        <h2>Every room, at a glance</h2>
        <p>Live room and housekeeping status, with stays and room blocks across the selected window.</p>
        <div class="room-board-date-line"><strong>Desk date · <time datetime="{{ $date->toDateString() }}">{{ $date->format('D, d M Y') }}</time></strong><span>Window · <time datetime="{{ $date->toDateString() }}">{{ $date->format('j M') }}</time> – <time datetime="{{ $date->copy()->addDays($days - 1)->toDateString() }}">{{ $date->copy()->addDays($days - 1)->format('j M Y') }}</time></span><span>Business date · <time datetime="{{ bdate()->toDateString() }}">{{ bdate()->format('d M Y') }}</time></span></div>
        <div class="room-board-hero-actions">
          <a class="btn btn-sm" href="{{ $go(['view' => 'timeline']) }}">Open hotel timeline</a>
          <button type="button" class="btn btn-sm" id="print-btn"><x-icon name="print" size="sm"/> Print room board</button>
        </div>
      </div>
      <div class="room-board-metrics" aria-label="Room board snapshot">
        <div><span>Rooms shown</span><strong>{{ number_format($boardRoomCount) }}</strong><small>after filters</small></div>
        <div><span>Occupied now</span><strong>{{ number_format($boardOccupiedCount) }}</strong><small>live room status</small></div>
        <div><span>Vacant now</span><strong>{{ number_format($boardVacantCount) }}</strong><small>live room status</small></div>
        <div><span>Rooms with blocks</span><strong>{{ number_format($boardBlockedCount) }}</strong><small>in this window</small></div>
      </div>
    </header>
  @elseif($view === 'departures')
    <header class="departure-hero">
      <div class="departure-hero-copy">
        <span class="departure-eyebrow"><x-icon name="calendar" size="sm"/> FRONT OFFICE <i aria-hidden="true">/</i> DEPARTURE QUEUE</span>
        <h2>{{ $date->isSameDay(bdate()) ? 'Make every checkout count.' : 'Prepare every departure.' }}</h2>
        <p>See who is still in-house, what has already been completed, and which stays have not checked in yet.</p>
        <div class="departure-date-meta">
          <span><b>Departure date</b> <time datetime="{{ $date->toDateString() }}">{{ $date->format('l, d F Y') }}</time></span>
          <span><b>Business date</b> <time datetime="{{ bdate()->toDateString() }}">{{ bdate()->format('d M Y') }}</time></span>
        </div>
        <div class="departure-hero-actions">
          <button type="button" class="btn btn-sm" id="print-btn"><x-icon name="print" size="sm"/> Print departures</button>
          <a class="btn btn-sm" href="{{ $go(['view' => 'inhouse', 'tab' => null, 'focus' => null]) }}">Open in-house</a>
        </div>
      </div>
      <section class="departure-summary" aria-label="Departure summary">
        <div class="departure-summary-primary"><span>Scheduled departures</span><strong>{{ number_format($departureSummary['total']) }}</strong><small>room stays on this date</small></div>
        <div><span>Still in-house</span><strong>{{ number_format($departureSummary['still_in_house']) }}</strong><small>checkout not recorded</small></div>
        <div><span>Checked out</span><strong>{{ number_format($departureSummary['checked_out']) }}</strong><small>completed stays</small></div>
        <div><span>Not yet checked in</span><strong>{{ number_format($departureSummary['not_yet_arrived']) }}</strong><small>reserved stays</small></div>
      </section>
    </header>
  @elseif($view === 'inhouse')
    <header class="inhouse-hero">
      <div class="inhouse-hero-copy">
        <span class="inhouse-eyebrow"><x-icon name="hotel" size="sm"/> FRONT OFFICE <i aria-hidden="true">/</i> LIVE IN-HOUSE</span>
        <h2>Every current stay, in view.</h2>
        <p>Keep track of guests on property, spot due-outs and overdue stays, and open the existing folio or checkout workflow when your role allows.</p>
        <div class="inhouse-date-meta">
          <span><b>Live roster</b> Checked-in stays</span>
          <span><b>Business date</b> <time datetime="{{ bdate()->toDateString() }}">{{ bdate()->format('l, d F Y') }}</time></span>
        </div>
        <div class="inhouse-hero-actions">
          <button type="button" class="btn btn-sm" id="print-btn"><x-icon name="print" size="sm"/> Print in-house</button>
          @if(can_do('frontdesk.view'))<a class="btn btn-sm" href="{{ route('frontdesk.inhouse') }}">Open Front Desk roster</a>@endif
        </div>
      </div>
      <section class="inhouse-summary" aria-label="Current in-house summary">
        <div class="inhouse-summary-primary"><span>Stays in-house</span><strong>{{ number_format($inhouseSummary['total']) }}</strong><small>{{ number_format($inhouseSummary['guests']) }} guests on property</small></div>
        <div><span>Due out today</span><strong>{{ number_format($inhouseSummary['due_today']) }}</strong><small>departure on business date</small></div>
        <div><span>Past due</span><strong>{{ number_format($inhouseSummary['overdue']) }}</strong><small>still checked in</small></div>
        <div><span>Stayovers</span><strong>{{ number_format($inhouseSummary['stayovers']) }}</strong><small>departing after today</small></div>
        @if(can_do('folio.view'))<div><span>Balance follow-up</span><strong>{{ number_format($inhouseSummary['balance_review']) }}</strong><small>non-zero folio balance</small></div>@endif
      </section>
    </header>
  @elseif($view === 'unassigned')
    <header class="unassigned-hero">
      <div class="unassigned-hero-copy">
        <span class="unassigned-eyebrow"><x-icon name="calendar" size="sm"/> FRONT OFFICE <i aria-hidden="true">/</i> ROOM ASSIGNMENT QUEUE</span>
        <h2>Every stay deserves a room.</h2>
        <p>Review reserved stays without a room, prioritize guests arriving soon, and assign only through the existing availability-checked stay workflow.</p>
        <div class="unassigned-date-meta">
          <span><b>Desk date</b> <time datetime="{{ $date->toDateString() }}">{{ $date->format('l, d F Y') }}</time></span>
          <span><b>Business date</b> <time datetime="{{ bdate()->toDateString() }}">{{ bdate()->format('d M Y') }}</time></span>
        </div>
        <div class="unassigned-hero-actions">
          <button type="button" class="btn btn-sm" id="print-btn"><x-icon name="print" size="sm"/> Print unassigned stays</button>
          <a class="btn btn-sm" href="{{ $go(['view' => 'board', 'focus' => null, 'tab' => null]) }}">Open room board</a>
        </div>
      </div>
      <section class="unassigned-summary" aria-label="Unassigned stay summary">
        <div class="unassigned-summary-primary"><span>Stays without a room</span><strong>{{ number_format($unassignedSummary['total']) }}</strong><small>reserved room stays</small></div>
        <div><span>Arriving on desk date</span><strong>{{ number_format($unassignedSummary['arriving_today']) }}</strong><small>needs assignment first</small></div>
        <div><span>Past arrival date</span><strong>{{ number_format($unassignedSummary['past_arrival']) }}</strong><small>still reserved</small></div>
        <div><span>Next 7 days</span><strong>{{ number_format($unassignedSummary['next_7_days']) }}</strong><small>upcoming arrivals</small></div>
        <div><span>Group stays</span><strong>{{ number_format($unassignedSummary['groups']) }}</strong><small>room stays without allocation</small></div>
      </section>
    </header>
  @else
    <header class="desk-head">
      <div>
        <div class="tiny" style="letter-spacing:.14em;text-transform:uppercase;opacity:.75">Nowills · Front office</div>
        <h1>Reservation desk</h1>
        <p>{{ $date->format('l, d M Y') }} · business date {{ bdate()->format('d M Y') }} · {{ $kpis['sellable'] }} rooms on sale</p>
      </div>
      <div class="flex gap-1 wrap">
        <a class="btn btn-sm" href="{{ $go(['view' => 'dashboard']) }}">Dashboard</a>
        <a class="btn btn-sm" href="{{ route('reservations.plan', request()->only(['date','days','group'])) }}" target="_blank" rel="noopener">Print plan</a>
        <a class="btn btn-sm" href="{{ route('reservations.daysummary', request()->only(['date','days','group'])) }}" target="_blank" rel="noopener">Day summary</a>
        <button type="button" class="btn btn-sm" id="print-btn">Print view</button>
        <a class="btn btn-sm" href="{{ route('reservations.export', request()->query()) }}">Export</a>
        <a class="btn btn-sm" href="{{ route('reservations.amenities') }}">Amenities &amp; photos</a>
        @if(can_do('nightaudit.run'))<a class="btn btn-sm" href="{{ route('nightaudit.index') }}">Night audit</a>@endif
        @if(can_do('gl.view'))<a class="btn btn-sm" href="{{ route('gl.index') }}">Ledger</a>@endif
        @if(can_do('logs.view'))<a class="btn btn-sm" href="{{ route('logs.index') }}">Audit log</a>@endif
        @if(can_do('users.manage'))<a class="btn btn-sm" href="{{ route('roles.index') }}">Permissions</a>@endif
      </div>
    </header>
  @endif

  @if($view !== 'list' && $view !== 'noshow' && $view !== 'cancelled')
  @php
    $credit = $kpis['outstanding'] < 0 ? money(abs($kpis['outstanding'])).' credit' : money($kpis['outstanding']);
    $historicalDeskDate = $date->copy()->startOfDay()->lt(bdate()->copy()->startOfDay());
    $currentDeskDate = $date->isSameDay(bdate());
    $kpiGroups = [
      'Rooms' => [
        ['Total', $kpis['total'], ['view' => 'board', 'focus' => null, 'tab' => null], 'Active rooms in inventory now'],
        ['Available', $kpis['available'], ['view' => 'board', 'focus' => 'available'], 'Free for this desk date after bookings and blocks'],
        ['Occupied', $kpis['occupied'], ['view' => 'board', 'focus' => 'occupied'], 'Currently marked occupied in room status'],
        ['Reserved', $kpis['reserved'], ['view' => 'timeline', 'focus' => 'reserved'], 'Reserved stays covering this date'],
        ['Vacant clean', $kpis['vacant_clean'], ['view' => 'board', 'focus' => 'vacant_clean'], 'Currently vacant and clean'],
        ['Vacant dirty', $kpis['vacant_dirty'], ['view' => 'board', 'focus' => 'vacant_dirty'], 'Currently vacant and dirty'],
        ['Cleaning', $kpis['cleaning'], ['view' => 'board', 'focus' => 'cleaning'], 'Housekeeping tasks currently in progress for this task date'],
        ['Inspected', $kpis['inspected'], ['view' => 'board', 'focus' => 'inspected'], 'Currently marked inspected by housekeeping'],
        ['Maintenance', $kpis['maintenance'], ['view' => 'board', 'focus' => 'maintenance'], 'Rooms with an open maintenance ticket now'],
        ['OOO', $kpis['ooo'], ['view' => 'board', 'focus' => 'ooo'], 'Currently marked out of order'],
        ['OOS', $kpis['oos'], ['view' => 'board', 'focus' => 'oos'], 'Currently marked out of service'],
        ['Blocked', $kpis['blocked'], ['view' => 'board', 'focus' => 'blocked'], 'Dated blocks covering this desk date'],
      ],
      'Reservations' => [
        ['Arrivals', $kpis['arrivals'], ['view' => 'arrivals', 'focus' => null, 'due' => null], $historicalDeskDate ? 'Room stays that arrived on this date' : 'Room stays scheduled to arrive on this date'],
        ['Due in', $kpis['due_in'], ['view' => 'arrivals', 'due' => 'in'], 'Arriving and not yet checked in'],
        ['Departures', $kpis['due_out'], ['view' => 'departures', 'tab' => $currentDeskDate ? 'dueout' : null], $historicalDeskDate ? 'Room stays with this departure date, including completed or overdue stays' : 'Room stays scheduled to depart on this date'],
        ['In-house', $kpis['inhouse'], ['view' => 'inhouse'], 'Checked in now'],
        ['Stayovers', $kpis['stayover'], ['view' => 'timeline', 'tab' => 'stayover'], 'Currently checked-in stays covering this date and departing after it'],
        ['Walk-ins', $kpis['walkins'], ['view' => 'arrivals', 'focus' => 'walkin'], 'Walk-in reservations arriving on this date'],
        ['VIP', $kpis['vip'], ['view' => 'timeline', 'tab' => 'vip'], 'VIP stays covering this date'],
        ['Groups', $kpis['groups'], ['view' => 'groups'], 'Group reservations covering this date'],
        ['Waitlist', $kpis['waitlist'], ['view' => 'waitlist'], 'Waiting, not yet a reservation'],
        ['No-shows', $kpis['noshow'], ['view' => 'noshow', 'date' => $date->toDateString(), 'days' => 1], 'No-show reservations arriving on this date'],
        ['Cancelled', $kpis['cancelled'], ['view' => 'cancelled', 'date' => $date->toDateString(), 'days' => 1], 'Cancelled on this date'],
        ['Unassigned', $kpis['unassigned'], ['view' => 'unassigned'], 'Reserved stays with no room'],
      ],
      'Revenue' => [
        ['Expected', money($kpis['on_books']), ['view' => 'timeline', 'focus' => null], 'Room rate of stays covering this date, before tax. Click opens the desk timeline'],
        ['Posted room', money($kpis['room_posted']), ['view' => 'inhouse'], can_do('folio.view') ? 'Room charges posted on this date. Click opens those folio entries' : 'Room charges posted on this date. Click opens the in-house guest list'],
        ['Posted all', money($kpis['posted']), ['view' => 'inhouse'], can_do('folio.view') ? 'All folio charges posted on this date. Click opens those folio entries' : 'All folio charges posted on this date. Click opens the in-house guest list'],
        ['Occupancy', $kpis['occupancy'] === null ? '—' : $kpis['occupancy'].'%', ['view' => 'board'], $kpis['sold'].' sold / '.$kpis['sellable'].' sellable'],
        ['ADR', $kpis['adr'] === null ? '—' : money($kpis['adr']), ['view' => 'timeline'], 'Average nightly rate for stays covering this date. Click opens the desk timeline'],
        ['RevPAR', $kpis['revpar'] === null ? '—' : money($kpis['revpar']), ['view' => 'board'], 'Posted room revenue divided by sellable rooms. Click opens the selected-date room board'],
        ['Deposits', money($kpis['deposits']), ['view' => 'timeline', 'focus' => 'deposit'], can_do('folio.view') ? 'Advance payments posted on this desk date. Click opens those payment entries' : 'Advance payments posted on this desk date. Click shows stays with a payment on file'],
        ['Outstanding', $credit, ['view' => 'inhouse', 'focus' => 'balance'], can_do('folio.view') ? 'Open reservation-linked folios. Credits mean payments already exceed charges' : 'In-house stays with non-zero folio balances. Credits mean payments exceed charges'],
      ],
    ];
    $arrivalNote = $historicalDeskDate
      ? 'room stays arrived '.$date->format('d M')
      : 'room stays arriving '.$date->format('d M').' · '.$kpis['due_in'].' still due to check in';
    $departureNote = $historicalDeskDate
      ? 'room stays with departure date '.$date->format('d M')
      : ($currentDeskDate ? 'checked-in room stays due out '.$date->format('d M') : 'booked room stays due out '.$date->format('d M'));
    $dashboardStats = [
      ['Rooms available', $kpis['available'], 'of '.$kpis['sellable'].' sellable rooms', ['view' => 'board', 'focus' => 'available'], 'key', 'green'],
      ['Arrivals', $kpis['arrivals'], $arrivalNote, ['view' => 'arrivals'], 'calendar', 'green'],
      ['Departures', $kpis['due_out'], $departureNote, ['view' => 'departures', 'tab' => $currentDeskDate ? 'dueout' : null], 'door', 'amber'],
      ['In-house rooms', $kpis['inhouse'], 'currently checked in', ['view' => 'inhouse'], 'users', 'blue'],
      ['Occupancy', $kpis['occupancy'] === null ? '—' : $kpis['occupancy'].'%', $kpis['sold'].' sold · '.$kpis['sellable'].' sellable', ['view' => 'board'], 'chart', 'forest'],
      ['Unassigned stays', $kpis['unassigned'], 'reserved stays need a room', ['view' => 'unassigned'], 'alert', $kpis['unassigned'] > 0 ? 'red' : 'green'],
    ];
  @endphp
  @endif
  @if($view !== 'list' && $view !== 'timeline' && $view !== 'board' && $view !== 'arrivals' && $view !== 'departures' && $view !== 'inhouse' && $view !== 'unassigned' && $view !== 'waitlist' && $view !== 'groups' && $view !== 'noshow' && $view !== 'cancelled')
  <section class="kpi-strip {{ $view === 'dashboard' ? 'dashboard-kpi-strip' : '' }}" aria-label="Front desk figures for {{ $date->format('d M Y') }}">
    @if($view === 'dashboard')
      <div class="desk-summary-grid" role="group" aria-label="Front desk snapshot">
        @foreach($dashboardStats as [$label, $value, $note, $extra, $icon, $tone])
          <a class="desk-summary-card tone-{{ $tone }}" href="{{ $go($extra) }}" aria-label="{{ $label }}: {{ $value }}. {{ $note }}">
            <span class="desk-summary-card-top"><span>{{ $label }}</span><x-icon :name="$icon" size="sm"/></span>
            <strong>{{ $value }}</strong>
            <small>{{ $note }}</small>
          </a>
        @endforeach
      </div>
      <details class="desk-metric-details">
        <summary>All desk figures <span>Rooms · reservations · revenue</span></summary>
        <div class="desk-metric-groups">
          @foreach($kpiGroups as $groupName => $items)
            <div class="kpi-group">
              <div class="kpi-label">{{ $groupName }}</div>
              <div class="kpi-row">
                @foreach($items as [$label, $value, $extra, $hint])
                  <a class="kpi {{ request('focus') === ($extra['focus'] ?? null) && request('view') === ($extra['view'] ?? null) ? 'on' : '' }}" href="{{ $folioMetricHref($label, $extra) }}" title="{{ $hint }}"><span>{{ $label }}</span><strong>{{ $value }}</strong></a>
                @endforeach
              </div>
            </div>
          @endforeach
        </div>
      </details>
    @else
      @foreach($kpiGroups as $groupName => $items)
        <div class="kpi-group">
          <div class="kpi-label">{{ $groupName }}</div>
          <div class="kpi-row">
            @foreach($items as [$label, $value, $extra, $hint])
              <a class="kpi {{ request('focus') === ($extra['focus'] ?? null) && request('view') === ($extra['view'] ?? null) ? 'on' : '' }}" href="{{ $folioMetricHref($label, $extra) }}" title="{{ $hint }}"><span>{{ $label }}</span><strong>{{ $value }}</strong></a>
            @endforeach
          </div>
        </div>
      @endforeach
    @endif
  </section>
  @endif

  @if($view === 'arrivals')
    @php
      $arrivalAllActive = !request()->filled('due') && !request()->filled('focus') && request('tab') !== 'duein';
      $arrivalDueActive = request('due') === 'in' || request('tab') === 'duein';
      $arrivalWalkinActive = request('focus') === 'walkin';
      $arrivalUnassignedActive = request('focus') === 'unassigned';
      $arrivalGuestDetailsActive = request('focus') === 'guest_details';
      $arrivalLateEtaActive = request('focus') === 'late_eta';
    @endphp
    <nav class="arrival-segments" aria-label="Filter arrivals">
      <a href="{{ $go(['view' => 'arrivals', 'tab' => null, 'due' => null, 'focus' => null, 'page' => null]) }}" class="{{ $arrivalAllActive ? 'is-active' : '' }}" @if($arrivalAllActive) aria-current="page" @endif>All arrivals <b>{{ number_format($arrivalSummary['total']) }}</b></a>
      <a href="{{ $go(['view' => 'arrivals', 'tab' => null, 'due' => 'in', 'focus' => null, 'page' => null]) }}" class="{{ $arrivalDueActive ? 'is-active' : '' }}" @if($arrivalDueActive) aria-current="page" @endif>Due in <b>{{ number_format($arrivalSummary['due_in']) }}</b></a>
      <a href="{{ $go(['view' => 'arrivals', 'tab' => null, 'due' => null, 'focus' => 'walkin', 'page' => null]) }}" class="{{ $arrivalWalkinActive ? 'is-active' : '' }}" @if($arrivalWalkinActive) aria-current="page" @endif>Walk-ins <b>{{ number_format($arrivalSummary['walkins']) }}</b></a>
      <a href="{{ $go(['view' => 'arrivals', 'tab' => null, 'due' => null, 'focus' => 'unassigned', 'page' => null]) }}" class="{{ $arrivalUnassignedActive ? 'is-active' : '' }}" @if($arrivalUnassignedActive) aria-current="page" @endif>Needs a room <b>{{ number_format($arrivalSummary['unassigned']) }}</b></a>
      <a href="{{ $go(['view' => 'arrivals', 'tab' => null, 'due' => null, 'focus' => 'guest_details', 'page' => null]) }}" class="{{ $arrivalGuestDetailsActive ? 'is-active' : '' }}" @if($arrivalGuestDetailsActive) aria-current="page" @endif>Guest details <b>{{ number_format($arrivalSummary['guest_details']) }}</b></a>
      @if($date->isSameDay(bdate()))<a href="{{ $go(['view' => 'arrivals', 'tab' => null, 'due' => null, 'focus' => 'late_eta', 'page' => null]) }}" class="{{ $arrivalLateEtaActive ? 'is-active' : '' }}" @if($arrivalLateEtaActive) aria-current="page" @endif>Past ETA <b>{{ number_format($arrivalSummary['late_eta']) }}</b></a>@endif
    </nav>
  @elseif($view === 'departures')
    @php
      $departureStillActive = request('focus') === 'still_in_house' || request('tab') === 'dueout';
      $departureCheckedOutActive = request('focus') === 'checked_out';
      $departureNotArrivedActive = request('focus') === 'not_yet_arrived';
      $departureAllActive = ! $departureStillActive && ! $departureCheckedOutActive && ! $departureNotArrivedActive;
    @endphp
    <nav class="departure-segments" aria-label="Filter departures">
      <a href="{{ $go(['view' => 'departures', 'tab' => null, 'focus' => null, 'page' => null]) }}" class="{{ $departureAllActive ? 'is-active' : '' }}" @if($departureAllActive) aria-current="page" @endif>All departures <b>{{ number_format($departureSummary['total']) }}</b></a>
      <a href="{{ $go(['view' => 'departures', 'tab' => null, 'focus' => 'still_in_house', 'page' => null]) }}" class="{{ $departureStillActive ? 'is-active' : '' }}" @if($departureStillActive) aria-current="page" @endif>Still in-house <b>{{ number_format($departureSummary['still_in_house']) }}</b></a>
      <a href="{{ $go(['view' => 'departures', 'tab' => null, 'focus' => 'checked_out', 'page' => null]) }}" class="{{ $departureCheckedOutActive ? 'is-active' : '' }}" @if($departureCheckedOutActive) aria-current="page" @endif>Checked out <b>{{ number_format($departureSummary['checked_out']) }}</b></a>
      <a href="{{ $go(['view' => 'departures', 'tab' => null, 'focus' => 'not_yet_arrived', 'page' => null]) }}" class="{{ $departureNotArrivedActive ? 'is-active' : '' }}" @if($departureNotArrivedActive) aria-current="page" @endif>Not yet checked in <b>{{ number_format($departureSummary['not_yet_arrived']) }}</b></a>
    </nav>
  @elseif($view === 'inhouse')
    @php
      $inhouseFocus = request('focus');
      $inhouseAllActive = !in_array($inhouseFocus, ['due_today', 'overdue', 'stayovers', 'vip', 'unassigned', 'balance'], true);
    @endphp
    <nav class="inhouse-segments" aria-label="Filter in-house stays">
      <a href="{{ $go(['view' => 'inhouse', 'tab' => null, 'focus' => null, 'page' => null]) }}" class="{{ $inhouseAllActive ? 'is-active' : '' }}" @if($inhouseAllActive) aria-current="page" @endif>All in-house <b>{{ number_format($inhouseSummary['total']) }}</b></a>
      <a href="{{ $go(['view' => 'inhouse', 'tab' => null, 'focus' => 'due_today', 'page' => null]) }}" class="{{ $inhouseFocus === 'due_today' ? 'is-active' : '' }}" @if($inhouseFocus === 'due_today') aria-current="page" @endif>Due out today <b>{{ number_format($inhouseSummary['due_today']) }}</b></a>
      <a href="{{ $go(['view' => 'inhouse', 'tab' => null, 'focus' => 'overdue', 'page' => null]) }}" class="{{ $inhouseFocus === 'overdue' ? 'is-active' : '' }}" @if($inhouseFocus === 'overdue') aria-current="page" @endif>Past due <b>{{ number_format($inhouseSummary['overdue']) }}</b></a>
      <a href="{{ $go(['view' => 'inhouse', 'tab' => null, 'focus' => 'stayovers', 'page' => null]) }}" class="{{ $inhouseFocus === 'stayovers' ? 'is-active' : '' }}" @if($inhouseFocus === 'stayovers') aria-current="page" @endif>Stayovers <b>{{ number_format($inhouseSummary['stayovers']) }}</b></a>
      <a href="{{ $go(['view' => 'inhouse', 'tab' => null, 'focus' => 'vip', 'page' => null]) }}" class="{{ $inhouseFocus === 'vip' ? 'is-active' : '' }}" @if($inhouseFocus === 'vip') aria-current="page" @endif>VIP <b>{{ number_format($inhouseSummary['vip']) }}</b></a>
      <a href="{{ $go(['view' => 'inhouse', 'tab' => null, 'focus' => 'unassigned', 'page' => null]) }}" class="{{ $inhouseFocus === 'unassigned' ? 'is-active' : '' }}" @if($inhouseFocus === 'unassigned') aria-current="page" @endif>No room assigned <b>{{ number_format($inhouseSummary['unassigned']) }}</b></a>
      @if(can_do('folio.view'))<a href="{{ $go(['view' => 'inhouse', 'tab' => null, 'focus' => 'balance', 'page' => null]) }}" class="{{ $inhouseFocus === 'balance' ? 'is-active' : '' }}" @if($inhouseFocus === 'balance') aria-current="page" @endif>Balance follow-up <b>{{ number_format($inhouseSummary['balance_review']) }}</b></a>@endif
    </nav>
  @elseif($view === 'unassigned')
    @php
      $unassignedFocus = request('focus');
      $unassignedAllActive = !in_array($unassignedFocus, ['arriving_today', 'past_arrival', 'next_7_days', 'groups'], true);
    @endphp
    <nav class="unassigned-segments" aria-label="Filter unassigned stays">
      <a href="{{ $go(['view' => 'unassigned', 'tab' => null, 'focus' => null, 'page' => null]) }}" class="{{ $unassignedAllActive ? 'is-active' : '' }}" @if($unassignedAllActive) aria-current="page" @endif>All unassigned <b>{{ number_format($unassignedSummary['total']) }}</b></a>
      <a href="{{ $go(['view' => 'unassigned', 'tab' => null, 'focus' => 'arriving_today', 'page' => null]) }}" class="{{ $unassignedFocus === 'arriving_today' ? 'is-active' : '' }}" @if($unassignedFocus === 'arriving_today') aria-current="page" @endif>On desk date <b>{{ number_format($unassignedSummary['arriving_today']) }}</b></a>
      <a href="{{ $go(['view' => 'unassigned', 'tab' => null, 'focus' => 'past_arrival', 'page' => null]) }}" class="{{ $unassignedFocus === 'past_arrival' ? 'is-active' : '' }}" @if($unassignedFocus === 'past_arrival') aria-current="page" @endif>Past arrival <b>{{ number_format($unassignedSummary['past_arrival']) }}</b></a>
      <a href="{{ $go(['view' => 'unassigned', 'tab' => null, 'focus' => 'next_7_days', 'page' => null]) }}" class="{{ $unassignedFocus === 'next_7_days' ? 'is-active' : '' }}" @if($unassignedFocus === 'next_7_days') aria-current="page" @endif>Next 7 days <b>{{ number_format($unassignedSummary['next_7_days']) }}</b></a>
      <a href="{{ $go(['view' => 'unassigned', 'tab' => null, 'focus' => 'groups', 'page' => null]) }}" class="{{ $unassignedFocus === 'groups' ? 'is-active' : '' }}" @if($unassignedFocus === 'groups') aria-current="page" @endif>Group stays <b>{{ number_format($unassignedSummary['groups']) }}</b></a>
    </nav>
  @elseif($view === 'waitlist')
    @php
      $waitlistStatus = request('waitlist_status');
      $waitlistFocus = request('waitlist_focus');
      $waitlistAllActive = !in_array($waitlistStatus, ['waiting', 'converted', 'cancelled'], true) && !in_array($waitlistFocus, ['next_7_days', 'needs_type'], true);
    @endphp
    <nav class="waitlist-segments" aria-label="Filter reservation waitlist">
      <a href="{{ $go(['view' => 'waitlist', 'tab' => null, 'waitlist_status' => null, 'waitlist_focus' => null, 'page' => null]) }}" class="{{ $waitlistAllActive ? 'is-active' : '' }}" @if($waitlistAllActive) aria-current="page" @endif>All entries <b>{{ number_format($waitlistSummary['total']) }}</b></a>
      <a href="{{ $go(['view' => 'waitlist', 'tab' => null, 'waitlist_status' => 'waiting', 'waitlist_focus' => null, 'page' => null]) }}" class="{{ $waitlistStatus === 'waiting' && !$waitlistFocus ? 'is-active' : '' }}" @if($waitlistStatus === 'waiting' && !$waitlistFocus) aria-current="page" @endif>Waiting <b>{{ number_format($waitlistSummary['waiting']) }}</b></a>
      <a href="{{ $go(['view' => 'waitlist', 'tab' => null, 'waitlist_status' => null, 'waitlist_focus' => 'next_7_days', 'page' => null]) }}" class="{{ $waitlistFocus === 'next_7_days' ? 'is-active' : '' }}" @if($waitlistFocus === 'next_7_days') aria-current="page" @endif>Arriving in 7 days <b>{{ number_format($waitlistSummary['next_7_days']) }}</b></a>
      <a href="{{ $go(['view' => 'waitlist', 'tab' => null, 'waitlist_status' => null, 'waitlist_focus' => 'needs_type', 'page' => null]) }}" class="{{ $waitlistFocus === 'needs_type' ? 'is-active' : '' }}" @if($waitlistFocus === 'needs_type') aria-current="page" @endif>Room type needed <b>{{ number_format($waitlistSummary['needs_type']) }}</b></a>
      <a href="{{ $go(['view' => 'waitlist', 'tab' => null, 'waitlist_status' => 'converted', 'waitlist_focus' => null, 'page' => null]) }}" class="{{ $waitlistStatus === 'converted' ? 'is-active' : '' }}" @if($waitlistStatus === 'converted') aria-current="page" @endif>Converted <b>{{ number_format($waitlistSummary['converted']) }}</b></a>
      <a href="{{ $go(['view' => 'waitlist', 'tab' => null, 'waitlist_status' => 'cancelled', 'waitlist_focus' => null, 'page' => null]) }}" class="{{ $waitlistStatus === 'cancelled' ? 'is-active' : '' }}" @if($waitlistStatus === 'cancelled') aria-current="page" @endif>Cancelled <b>{{ number_format($waitlistSummary['cancelled']) }}</b></a>
    </nav>
  @elseif($view === 'groups')
    @php
      $groupStatus = request('group_status');
      $groupFocus = request('group_focus');
      $groupAllActive = !in_array($groupStatus, ['tentative', 'confirmed', 'checked_in', 'checked_out'], true) && !in_array($groupFocus, ['with_block', 'without_block'], true);
    @endphp
    <nav class="groups-segments" aria-label="Filter group reservations">
      <a href="{{ $go(['view' => 'groups', 'tab' => null, 'group_status' => null, 'group_focus' => null, 'page' => null]) }}" class="{{ $groupAllActive ? 'is-active' : '' }}" @if($groupAllActive) aria-current="page" @endif>All groups <b>{{ number_format($groupSummary['total']) }}</b></a>
      <a href="{{ $go(['view' => 'groups', 'tab' => null, 'group_status' => 'tentative', 'group_focus' => null, 'page' => null]) }}" class="{{ $groupStatus === 'tentative' ? 'is-active' : '' }}" @if($groupStatus === 'tentative') aria-current="page" @endif>Tentative <b>{{ number_format($groupSummary['tentative']) }}</b></a>
      <a href="{{ $go(['view' => 'groups', 'tab' => null, 'group_status' => 'confirmed', 'group_focus' => null, 'page' => null]) }}" class="{{ $groupStatus === 'confirmed' ? 'is-active' : '' }}" @if($groupStatus === 'confirmed') aria-current="page" @endif>Confirmed <b>{{ number_format($groupSummary['confirmed']) }}</b></a>
      <a href="{{ $go(['view' => 'groups', 'tab' => null, 'group_status' => 'checked_in', 'group_focus' => null, 'page' => null]) }}" class="{{ $groupStatus === 'checked_in' ? 'is-active' : '' }}" @if($groupStatus === 'checked_in') aria-current="page" @endif>In-house <b>{{ number_format($groupSummary['in_house']) }}</b></a>
      <a href="{{ $go(['view' => 'groups', 'tab' => null, 'group_status' => 'checked_out', 'group_focus' => null, 'page' => null]) }}" class="{{ $groupStatus === 'checked_out' ? 'is-active' : '' }}" @if($groupStatus === 'checked_out') aria-current="page" @endif>Completed <b>{{ number_format($groupSummary['completed']) }}</b></a>
      <a href="{{ $go(['view' => 'groups', 'tab' => null, 'group_status' => null, 'group_focus' => 'with_block', 'page' => null]) }}" class="{{ $groupFocus === 'with_block' ? 'is-active' : '' }}" @if($groupFocus === 'with_block') aria-current="page" @endif>With block <b>{{ number_format($groupSummary['with_block']) }}</b></a>
      <a href="{{ $go(['view' => 'groups', 'tab' => null, 'group_status' => null, 'group_focus' => 'without_block', 'page' => null]) }}" class="{{ $groupFocus === 'without_block' ? 'is-active' : '' }}" @if($groupFocus === 'without_block') aria-current="page" @endif>No block <b>{{ number_format($groupSummary['without_block']) }}</b></a>
    </nav>
  @elseif($view === 'noshow')
    @php $noShowFocus = request('noshow_focus'); $noShowAllActive = !in_array($noShowFocus, ['groups', 'individuals'], true); @endphp
    <nav class="noshow-segments" aria-label="Filter no-show bookings">
      <a href="{{ $go(['view' => 'noshow', 'noshow_focus' => null, 'page' => null]) }}" class="{{ $noShowAllActive ? 'is-active' : '' }}" @if($noShowAllActive) aria-current="page" @endif>All no-shows <b>{{ number_format($noShowSummary['total']) }}</b></a>
      <a href="{{ $go(['view' => 'noshow', 'noshow_focus' => 'individuals', 'page' => null]) }}" class="{{ $noShowFocus === 'individuals' ? 'is-active' : '' }}" @if($noShowFocus === 'individuals') aria-current="page" @endif>Individual <b>{{ number_format($noShowSummary['individuals']) }}</b></a>
      <a href="{{ $go(['view' => 'noshow', 'noshow_focus' => 'groups', 'page' => null]) }}" class="{{ $noShowFocus === 'groups' ? 'is-active' : '' }}" @if($noShowFocus === 'groups') aria-current="page" @endif>Groups <b>{{ number_format($noShowSummary['groups']) }}</b></a>
    </nav>
  @elseif($view === 'cancelled')
    @php $cancelledFocus = request('cancelled_focus'); $cancelledAllActive = !in_array($cancelledFocus, ['groups', 'individuals'], true); @endphp
    <nav class="cancelled-segments" aria-label="Filter cancelled bookings">
      <a href="{{ $go(['view' => 'cancelled', 'cancelled_focus' => null, 'page' => null]) }}" class="{{ $cancelledAllActive ? 'is-active' : '' }}" @if($cancelledAllActive) aria-current="page" @endif>All cancellations <b>{{ number_format($cancelledSummary['total']) }}</b></a>
      <a href="{{ $go(['view' => 'cancelled', 'cancelled_focus' => 'individuals', 'page' => null]) }}" class="{{ $cancelledFocus === 'individuals' ? 'is-active' : '' }}" @if($cancelledFocus === 'individuals') aria-current="page" @endif>Individual <b>{{ number_format($cancelledSummary['individuals']) }}</b></a>
      <a href="{{ $go(['view' => 'cancelled', 'cancelled_focus' => 'groups', 'page' => null]) }}" class="{{ $cancelledFocus === 'groups' ? 'is-active' : '' }}" @if($cancelledFocus === 'groups') aria-current="page" @endif>Groups <b>{{ number_format($cancelledSummary['groups']) }}</b></a>
    </nav>
  @elseif($view !== 'dashboard' && $view !== 'list')
  <nav class="desk-nav" aria-label="Reservation status">
    <a href="{{ $go(['tab' => null, 'view' => 'timeline']) }}" class="{{ !request('tab') && in_array($view, ['timeline', 'board'], true) ? 'on' : '' }}" @if(!request('tab') && in_array($view, ['timeline', 'board'], true)) aria-current="page" @endif>All</a>
    @foreach($nav as $key => $label)
      @php $target = $key === 'dueout' ? 'departures' : (in_array($key, ['waitlist','groups','unassigned','arrivals','departures','inhouse','noshow','cancelled'], true) ? $key : ($view === 'list' ? 'list' : 'timeline')); $navCurrent = request('tab') ? request('tab') === $key : $view === $key; @endphp
      <a href="{{ $go(['tab' => $key, 'view' => $target]) }}" class="{{ $navCurrent ? 'on' : '' }}" @if($navCurrent) aria-current="page" @endif>{{ $label }} <em>{{ $tabCounts[$key] ?? 0 }}</em></a>
    @endforeach
  </nav>
  @endif

  @if($view === 'arrivals')
  <form class="arrival-toolbar" action="{{ route('reservations.index') }}" method="get" role="search">
    <input type="hidden" name="view" value="arrivals">
    @if(request('due'))<input type="hidden" name="due" value="{{ request('due') }}">@endif
    @if(request('focus'))<input type="hidden" name="focus" value="{{ request('focus') }}" @if(request('focus') === 'late_eta') id="arrivalLateEtaFocus" @endif>@endif
    <div class="arrival-date-group">
      <label class="arrival-date-field" for="arrivalDeskDate">Arrival date</label>
      <div class="arrival-date-control">
        <a href="{{ $go(['view' => 'arrivals', 'tab' => null, 'date' => $date->copy()->subDay()->toDateString(), 'focus' => request('focus') === 'late_eta' ? null : request('focus')]) }}" aria-label="Previous arrival date">‹</a>
        <input id="arrivalDeskDate" type="date" name="date" value="{{ $date->toDateString() }}">
        <a href="{{ $go(['view' => 'arrivals', 'tab' => null, 'date' => $date->copy()->addDay()->toDateString(), 'focus' => request('focus') === 'late_eta' ? null : request('focus')]) }}" aria-label="Next arrival date">›</a>
        <a class="arrival-today" href="{{ $go(['view' => 'arrivals', 'tab' => null, 'date' => bdate()->toDateString()]) }}">Today</a>
      </div>
    </div>
    <div class="arrival-search-group">
      <label class="arrival-search-field" for="arrivalSearch">Find a guest or booking</label>
      <div class="arrival-search-control"><x-icon name="search" size="sm"/><input id="arrivalSearch" type="search" name="q" maxlength="160" value="{{ request('q') }}" placeholder="{{ can_do('guests.view') ? 'Name, confirmation, room or phone' : 'Name, confirmation or room' }}"><button class="btn btn-primary btn-sm" type="submit">Search</button></div>
    </div>
    @if(request('q'))<a class="arrival-reset" href="{{ $go(['view' => 'arrivals', 'tab' => null, 'q' => null, 'page' => null]) }}">Clear search</a>@endif
  </form>
  @elseif($view === 'departures')
  <form class="departure-toolbar" action="{{ route('reservations.index') }}" method="get" role="search">
    <input type="hidden" name="view" value="departures">
    @if(request('tab'))<input type="hidden" name="tab" value="{{ request('tab') }}">@endif
    @if(request('focus'))<input type="hidden" name="focus" value="{{ request('focus') }}">@endif
    <div class="departure-date-group">
      <label class="departure-date-field" for="departureDeskDate">Departure date</label>
      <div class="departure-date-control">
        <a href="{{ $go(['view' => 'departures', 'date' => $date->copy()->subDay()->toDateString(), 'page' => null]) }}" aria-label="Previous departure date">‹</a>
        <input id="departureDeskDate" type="date" name="date" value="{{ $date->toDateString() }}">
        <a href="{{ $go(['view' => 'departures', 'date' => $date->copy()->addDay()->toDateString(), 'page' => null]) }}" aria-label="Next departure date">›</a>
        <a class="departure-today" href="{{ $go(['view' => 'departures', 'date' => bdate()->toDateString(), 'page' => null]) }}">Today</a>
      </div>
    </div>
    <div class="departure-search-group">
      <label class="departure-search-field" for="departureSearch">Find a guest or booking</label>
      <div class="departure-search-control"><x-icon name="search" size="sm"/><input id="departureSearch" type="search" name="q" maxlength="160" value="{{ request('q') }}" placeholder="{{ can_do('guests.view') ? 'Name, confirmation, room or phone' : 'Name, confirmation or room' }}"><button class="btn btn-primary btn-sm" type="submit">Search</button></div>
    </div>
    @if(request('q'))<a class="departure-reset" href="{{ $go(['view' => 'departures', 'q' => null, 'page' => null]) }}">Clear search</a>@endif
  </form>
  @elseif($view === 'inhouse')
  <form class="inhouse-toolbar" action="{{ route('reservations.index') }}" method="get" role="search">
    <input type="hidden" name="view" value="inhouse">
    @if(request('focus'))<input type="hidden" name="focus" value="{{ request('focus') }}">@endif
    <div class="inhouse-search-group">
      <label for="inhouseSearch">Find a guest, booking or room</label>
      <div class="inhouse-search-control"><x-icon name="search" size="sm"/><input id="inhouseSearch" type="search" name="q" maxlength="160" value="{{ request('q') }}" placeholder="{{ can_do('guests.view') ? 'Name, confirmation, company, room or phone' : 'Name, confirmation, company or room' }}"><button class="btn btn-primary btn-sm" type="submit">Search</button></div>
    </div>
    <span class="inhouse-live-note"><i aria-hidden="true"></i> Live check-in roster</span>
    @if(request('q'))<a class="inhouse-reset" href="{{ $go(['view' => 'inhouse', 'q' => null, 'page' => null]) }}">Clear search</a>@endif
  </form>
  @elseif($view === 'unassigned')
  <form class="unassigned-toolbar" action="{{ route('reservations.index') }}" method="get" role="search">
    <input type="hidden" name="view" value="unassigned">
    @if(request('focus'))<input type="hidden" name="focus" value="{{ request('focus') }}">@endif
    <div class="unassigned-date-group">
      <label class="unassigned-date-field" for="unassignedDeskDate">Desk date</label>
      <div class="unassigned-date-control">
        <a href="{{ $go(['view' => 'unassigned', 'date' => $date->copy()->subDay()->toDateString(), 'page' => null]) }}" aria-label="Previous unassigned desk date">‹</a>
        <input id="unassignedDeskDate" type="date" name="date" value="{{ $date->toDateString() }}">
        <a href="{{ $go(['view' => 'unassigned', 'date' => $date->copy()->addDay()->toDateString(), 'page' => null]) }}" aria-label="Next unassigned desk date">›</a>
        <a class="unassigned-today" href="{{ $go(['view' => 'unassigned', 'date' => bdate()->toDateString(), 'page' => null]) }}">Today</a>
      </div>
    </div>
    <div class="unassigned-search-group">
      <label class="unassigned-search-field" for="unassignedSearch">Find a guest, booking or room type</label>
      <div class="unassigned-search-control"><x-icon name="search" size="sm"/><input id="unassignedSearch" type="search" name="q" maxlength="160" value="{{ request('q') }}" placeholder="{{ can_do('guests.view') ? 'Name, confirmation, group, type, company or phone' : 'Name, confirmation, group, type or company' }}"><button class="btn btn-primary btn-sm" type="submit">Search</button></div>
    </div>
    @if(request('q'))<a class="unassigned-reset" href="{{ $go(['view' => 'unassigned', 'q' => null, 'page' => null]) }}">Clear search</a>@endif
  </form>
  @elseif($view === 'waitlist')
  <form class="waitlist-toolbar" action="{{ route('reservations.index') }}" method="get" role="search">
    <input type="hidden" name="view" value="waitlist">
    @if(request('waitlist_status'))<input type="hidden" name="waitlist_status" value="{{ request('waitlist_status') }}">@endif
    @if(request('waitlist_focus'))<input type="hidden" name="waitlist_focus" value="{{ request('waitlist_focus') }}">@endif
    <div class="waitlist-date-group">
      <label class="waitlist-date-field" for="waitlistDate">Arrival window starts</label>
      <div class="waitlist-date-control">
        <a href="{{ $go(['view' => 'waitlist', 'date' => $date->copy()->subDay()->toDateString(), 'page' => null]) }}" aria-label="Previous waitlist arrival date">‹</a>
        <input id="waitlistDate" type="date" name="date" value="{{ $date->toDateString() }}">
        <a href="{{ $go(['view' => 'waitlist', 'date' => $date->copy()->addDay()->toDateString(), 'page' => null]) }}" aria-label="Next waitlist arrival date">›</a>
        <a class="waitlist-today" href="{{ $go(['view' => 'waitlist', 'date' => bdate()->toDateString(), 'page' => null]) }}">Today</a>
      </div>
    </div>
    <div class="waitlist-search-group">
      <label class="waitlist-search-field" for="waitlistSearch">Find a guest or room request</label>
      <div class="waitlist-search-control"><x-icon name="search" size="sm"/><input id="waitlistSearch" type="search" name="q" maxlength="160" value="{{ request('q') }}" placeholder="{{ can_do('guests.view') ? 'Name, confirmation, room type, phone or email' : 'Name, confirmation or room type' }}"><button class="btn btn-primary btn-sm" type="submit">Search</button></div>
    </div>
    @if(request('q'))<a class="waitlist-reset" href="{{ $go(['view' => 'waitlist', 'q' => null, 'page' => null]) }}">Clear search</a>@endif
  </form>
  @elseif($view === 'groups')
  <form class="groups-toolbar" action="{{ route('reservations.index') }}" method="get" role="search">
    <input type="hidden" name="view" value="groups">
    @if(request('group_status'))<input type="hidden" name="group_status" value="{{ request('group_status') }}">@endif
    @if(request('group_focus'))<input type="hidden" name="group_focus" value="{{ request('group_focus') }}">@endif
    <div class="groups-date-group">
      <label class="groups-date-field" for="groupsDate">Window start</label>
      <div class="groups-date-control"><a href="{{ $go(['view' => 'groups', 'date' => $date->copy()->subDay()->toDateString(), 'page' => null]) }}" aria-label="Previous group window start">‹</a><input id="groupsDate" type="date" name="date" value="{{ $date->toDateString() }}"><a href="{{ $go(['view' => 'groups', 'date' => $date->copy()->addDay()->toDateString(), 'page' => null]) }}" aria-label="Next group window start">›</a><a class="groups-today" href="{{ $go(['view' => 'groups', 'date' => bdate()->toDateString(), 'page' => null]) }}">Today</a></div>
    </div>
    <label class="groups-days-field" for="groupsDays">Window length<select id="groupsDays" name="days">@foreach([7, 14, 21, 31] as $n)<option value="{{ $n }}" @selected($days === $n)>{{ $n }} days</option>@endforeach</select></label>
    <div class="groups-search-group"><label class="groups-search-field" for="groupsSearch">Find a group, lead guest or room type</label><div class="groups-search-control"><x-icon name="search" size="sm"/><input id="groupsSearch" type="search" name="q" maxlength="160" value="{{ request('q') }}" placeholder="{{ can_do('guests.view') ? 'Group, confirmation, guest, company, type or phone' : 'Group, confirmation, guest, company or type' }}"><button class="btn btn-primary btn-sm" type="submit">Search</button></div></div>
    @if(request('q'))<a class="groups-reset" href="{{ $go(['view' => 'groups', 'q' => null, 'page' => null]) }}">Clear search</a>@endif
  </form>
  @elseif($view === 'noshow')
  <form class="noshow-toolbar" action="{{ route('reservations.index') }}" method="get" role="search">
    <input type="hidden" name="view" value="noshow">
    @if(request('noshow_focus'))<input type="hidden" name="noshow_focus" value="{{ request('noshow_focus') }}">@endif
    <div class="noshow-date-group"><label class="noshow-date-field" for="noshowDate">Arrival window starts</label><div class="noshow-date-control"><a href="{{ $go(['view' => 'noshow', 'date' => $date->copy()->subDay()->toDateString(), 'page' => null]) }}" aria-label="Previous no-show window">‹</a><input id="noshowDate" type="date" name="date" value="{{ $date->toDateString() }}"><a href="{{ $go(['view' => 'noshow', 'date' => $date->copy()->addDay()->toDateString(), 'page' => null]) }}" aria-label="Next no-show window">›</a><a class="noshow-today" href="{{ $go(['view' => 'noshow', 'date' => bdate()->toDateString(), 'page' => null]) }}">Today</a></div></div>
    <label class="noshow-days-field" for="noshowDays">Window length<select id="noshowDays" name="days">@foreach([1, 7, 14, 21, 31] as $n)<option value="{{ $n }}" @selected($days === $n)>{{ $n }} days</option>@endforeach</select></label>
    <div class="noshow-search-group"><label class="noshow-search-field" for="noshowSearch">Find a guest or booking</label><div class="noshow-search-control"><x-icon name="search" size="sm"/><input id="noshowSearch" type="search" name="q" maxlength="160" value="{{ request('q') }}" placeholder="{{ can_do('guests.view') ? 'Guest, confirmation, group, room or contact' : 'Guest, confirmation, group or room' }}"><button class="btn btn-primary btn-sm" type="submit">Search</button></div></div>
    @if(request('q'))<a class="noshow-reset" href="{{ $go(['view' => 'noshow', 'q' => null, 'page' => null]) }}">Clear search</a>@endif
  </form>
  @elseif($view === 'cancelled')
  <form class="cancelled-toolbar" action="{{ route('reservations.index') }}" method="get" role="search">
    <input type="hidden" name="view" value="cancelled">
    @if(request('cancelled_focus'))<input type="hidden" name="cancelled_focus" value="{{ request('cancelled_focus') }}">@endif
    <div class="cancelled-date-group"><label class="cancelled-date-field" for="cancelledDate">Cancellation window starts</label><div class="cancelled-date-control"><a href="{{ $go(['view' => 'cancelled', 'date' => $date->copy()->subDay()->toDateString(), 'page' => null]) }}" aria-label="Previous cancellation window">‹</a><input id="cancelledDate" type="date" name="date" value="{{ $date->toDateString() }}"><a href="{{ $go(['view' => 'cancelled', 'date' => $date->copy()->addDay()->toDateString(), 'page' => null]) }}" aria-label="Next cancellation window">›</a><a class="cancelled-today" href="{{ $go(['view' => 'cancelled', 'date' => bdate()->toDateString(), 'page' => null]) }}">Today</a></div></div>
    <label class="cancelled-days-field" for="cancelledDays">Window length<select id="cancelledDays" name="days">@foreach([1, 7, 14, 21, 31] as $n)<option value="{{ $n }}" @selected($days === $n)>{{ $n }} days</option>@endforeach</select></label>
    <div class="cancelled-search-group"><label class="cancelled-search-field" for="cancelledSearch">Find a guest or booking</label><div class="cancelled-search-control"><x-icon name="search" size="sm"/><input id="cancelledSearch" type="search" name="q" maxlength="160" value="{{ request('q') }}" placeholder="{{ can_do('guests.view') ? 'Guest, confirmation, group, room or contact' : 'Guest, confirmation, group or room' }}"><button class="btn btn-primary btn-sm" type="submit">Search</button></div></div>
    @if(request('q'))<a class="cancelled-reset" href="{{ $go(['view' => 'cancelled', 'q' => null, 'page' => null]) }}">Clear search</a>@endif
  </form>
  @elseif($view !== 'list')
  <form class="desk-tools {{ $view === 'dashboard' ? 'desk-dashboard-date-tools' : '' }}" method="get">
    <input type="hidden" name="view" value="{{ $view }}">
    @if(request('tab'))<input type="hidden" name="tab" value="{{ request('tab') }}">@endif
    <label>{{ $view === 'dashboard' ? 'Desk date' : 'Date' }}
      <span class="date-nav">
        <a href="{{ $go(['date' => $date->copy()->subDays($view === 'timeline' ? $days : 1)->toDateString()]) }}" aria-label="{{ $view === 'timeline' ? 'Previous timeline window' : 'Previous day' }}" title="{{ $view === 'timeline' ? 'Previous '.$days.'-day window' : 'Previous day' }}">‹</a>
        <input type="date" name="date" value="{{ $date->toDateString() }}">
        <a href="{{ $go(['date' => $date->copy()->addDays($view === 'timeline' ? $days : 1)->toDateString()]) }}" aria-label="{{ $view === 'timeline' ? 'Next timeline window' : 'Next day' }}" title="{{ $view === 'timeline' ? 'Next '.$days.'-day window' : 'Next day' }}">›</a>
        <a href="{{ $go(['date' => bdate()->toDateString()]) }}">Today</a>
      </span>
    </label>
    @if($view === 'dashboard')
      <button class="btn btn-primary btn-sm" type="submit">Update desk date</button>
    @else
    <label>Days
      <select name="days">
        @foreach([7, 14, 21, 31] as $n)<option value="{{ $n }}" @selected($days === $n)>{{ $n }}</option>@endforeach
      </select>
    </label>
    <label>Group
      <select name="group"><option value="type" @selected($group==='type')>Room type</option><option value="floor" @selected($group==='floor')>Floor</option><option value="hk" @selected($group==='hk')>Housekeeping</option><option value="status" @selected($group==='status')>Room status</option></select>
    </label>
    <label class="grow">Search <input name="q" value="{{ request('q') }}" placeholder="Guest, reservation, room, phone, group, OTA ref"></label>
    <label>Source
      <select name="source"><option value="">All</option>@foreach($sources as $id => $n)<option value="{{ $id }}" @selected(request('source') == $id)>{{ $n }}</option>@endforeach</select>
    </label>
    <label>Booking
      <select name="type"><option value="">All</option><option value="individual" @selected(request('type')==='individual')>Individual</option><option value="group" @selected(request('type')==='group')>Group</option></select>
    </label>
    <label>Room type
      <select name="room_type"><option value="">All</option>@foreach($roomTypes as $t)<option value="{{ $t->id }}" @selected(request('room_type') == $t->id)>{{ $t->name }}</option>@endforeach</select>
    </label>
    <label>HK
      <select name="hk"><option value="">All</option>@foreach(['clean','dirty','inspected','touch_up'] as $h)<option value="{{ $h }}" @selected(request('hk')===$h)>{{ ucfirst(str_replace('_',' ',$h)) }} ({{ $hkCounts[$h] ?? 0 }})</option>@endforeach</select>
    </label>
    <label>Amenity
      <select name="amenity"><option value="">All</option>@foreach($amenities as $a)<option value="{{ $a->id }}" @selected(request('amenity') == $a->id)>{{ $a->name }}</option>@endforeach</select>
    </label>
    <label>Floor
      <select name="floor"><option value="">All</option>@foreach($floors as $id => $n)<option value="{{ $id }}" @selected(request('floor') == $id)>{{ $n }}</option>@endforeach</select>
    </label>
    <label>Company
      <select name="company"><option value="">All</option>@foreach($companies as $id => $n)<option value="{{ $id }}" @selected(request('company') == $id)>{{ $n }}</option>@endforeach</select>
    </label>
    <button class="btn btn-primary btn-sm">Apply</button>
    <a class="btn btn-sm" href="{{ in_array($view, ['timeline', 'board'], true) ? route('reservations.index', ['view' => $view, 'date' => $date->toDateString(), 'days' => $days, 'group' => $group]) : route('reservations.index') }}" @if($view === 'timeline') aria-label="Clear timeline filters" @elseif($view === 'board') aria-label="Clear room board filters" @endif>{{ in_array($view, ['timeline', 'board'], true) ? 'Clear filters' : 'Reset' }}</a>
    @endif
  </form>
  @endif

  <nav class="desk-views {{ $view === 'list' ? 'reservation-list-views' : '' }} {{ $view === 'arrivals' ? 'arrival-views' : '' }} {{ $view === 'departures' ? 'departure-views' : '' }} {{ $view === 'inhouse' ? 'inhouse-views' : '' }} {{ $view === 'unassigned' ? 'unassigned-views' : '' }} {{ $view === 'waitlist' ? 'waitlist-views' : '' }} {{ $view === 'groups' ? 'groups-views' : '' }} {{ $view === 'noshow' ? 'noshow-views' : '' }} {{ $view === 'cancelled' ? 'cancelled-views' : '' }}" aria-label="Reservation desk views">
    @foreach($views as $key => $label)
      <a href="{{ $go(['view' => $key]) }}" class="desk-view-link {{ $view === $key ? 'on' : '' }}" @if($view === $key) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
    @if($view === 'timeline')<label class="zoom">Column <input id="col-zoom" type="range" min="36" max="92" value="52" aria-label="Timeline column width" aria-valuetext="52 pixels per day"></label>@endif
    @if($saved->isNotEmpty())
      <span class="tiny muted">Saved:</span>
      @foreach($saved as $sv)
        <a class="chip" href="{{ route('reservations.index') }}?{{ $sv->query }}">{{ $sv->name }}</a>
        <form method="post" action="{{ route('reservations.views.destroy', $sv) }}" class="inline">@csrf @method('DELETE')<button class="linkish" title="Remove {{ $sv->name }}">×</button></form>
      @endforeach
    @endif
    <form method="post" action="{{ route('reservations.views.store') }}" class="inline flex gap-1">
      @csrf
      <input type="hidden" name="query" value="{{ request()->getQueryString() }}">
      <input name="name" placeholder="Save this view" aria-label="View name" required maxlength="80" style="width:8rem">
      <label class="tiny"><input type="checkbox" name="is_default" value="1"> Default</label>
      <button class="btn btn-sm">Save</button>
    </form>
  </nav>

  @if($view === 'dashboard')
    @include('reservations.desk-dashboard')
  @elseif($view === 'timeline')
    <section class="timeline-guide" aria-label="Timeline legend and instructions">
      <div class="timeline-guide-copy"><strong>Stay status</strong><span>Each bar opens the stay. @if(can_do('frontdesk.roommove'))Drag a stay bar or unassigned reservation card to a room to request an assignment; the server checks availability before saving.@elseif(can_do('reservations.edit'))Drag reserved stays or unassigned reservation cards to a room to assign; in-house moves require front-desk room-move permission.@elseRoom moves require front-desk room-move permission; use the stay page to review room availability.@endif</span></div>
      <ul class="timeline-legend" aria-label="Stay and room indicators">
        <li><i class="legend-swatch is-reserved" aria-hidden="true"></i>Reserved</li>
        <li><i class="legend-swatch is-tentative" aria-hidden="true"></i>Tentative</li>
        <li><i class="legend-swatch is-inhouse" aria-hidden="true"></i>In-house</li>
        <li><i class="legend-swatch is-due" aria-hidden="true"></i>Due out</li>
        <li><i class="legend-swatch is-checkedout" aria-hidden="true"></i>Checked out</li>
        <li><i class="legend-swatch is-outage" aria-hidden="true"></i>Room block</li>
        <li><i class="legend-swatch is-vip" aria-hidden="true"></i>VIP</li>
                <li><i class="legend-swatch is-group" aria-hidden="true"></i>Group</li>
                <li><i class="timeline-edge-key" aria-hidden="true">IN</i>Arrival</li>
                <li><i class="timeline-edge-key is-out" aria-hidden="true">OUT</i>Departure</li>
                <li><i class="timeline-deposit-key" aria-hidden="true">dep</i>Deposit on file</li>
      </ul>
      <span class="timeline-guide-note">@if(can_do('frontdesk.roommove') || can_do('reservations.edit'))Keyboard assignment: open a reserved stay, choose a room, run <b>Check room</b>, then confirm. In-house room changes require room-move permission.@elseUse each stay bar to open its reservation and review the details permitted by your role.@endif</span>
    </section>
    @if($loose->isNotEmpty())
      <section class="loose timeline-loose-panel" aria-labelledby="timelineUnassignedTitle">
        <div class="timeline-loose-heading"><div><strong id="timelineUnassignedTitle">Unassigned stays</strong><span>{{ $loose->count() }} {{ \Illuminate\Support\Str::plural('stay', $loose->count()) }} need a room in this window</span></div><b class="timeline-loose-count">{{ number_format($loose->count()) }}</b></div>
        <div class="timeline-loose-list">
          @foreach($loose->take(6) as $s)
            <a class="timeline-loose-link" draggable="{{ (can_do('frontdesk.roommove') || can_do('reservations.edit')) ? 'true' : 'false' }}" data-stay="{{ $s->id }}" href="{{ route('reservations.stay', $s) }}" aria-label="Unassigned stay: reservation {{ $s->reservation->reservation_no }}, {{ $s->reservation->guest->full_name ?? 'guest profile unavailable' }}, {{ $s->roomType->name ?? $s->roomType->code ?? 'room type unspecified' }}, arrives {{ $s->arrival_date->format('d M Y') }}, departs {{ $s->departure_date->format('d M Y') }}{{ (can_do('frontdesk.roommove') || can_do('reservations.edit')) ? ', drag to assign a room' : '' }}" title="Reservation {{ $s->reservation->reservation_no }} · {{ $s->arrival_date->format('d M Y') }} to {{ $s->departure_date->format('d M Y') }}{{ (can_do('frontdesk.roommove') || can_do('reservations.edit')) ? ' · Drag to a room to assign' : '' }}">
              <b>{{ $s->reservation->reservation_no }}</b><span>{{ $s->reservation->guest->full_name ?? 'Guest profile unavailable' }}</span>
              <small>{{ $s->roomType->name ?? $s->roomType->code ?? 'Room type unspecified' }} · {{ $s->arrival_date->format('d M') }} → {{ $s->departure_date->format('d M') }}</small>
            </a>
          @endforeach
        </div>
        @if($loose->count() > 6)
          <details class="timeline-loose-more">
            <summary>View {{ number_format($loose->count() - 6) }} more unassigned {{ \Illuminate\Support\Str::plural('stay', $loose->count() - 6) }}</summary>
            <div class="timeline-loose-list">
              @foreach($loose->skip(6) as $s)
                <a class="timeline-loose-link" draggable="{{ (can_do('frontdesk.roommove') || can_do('reservations.edit')) ? 'true' : 'false' }}" data-stay="{{ $s->id }}" href="{{ route('reservations.stay', $s) }}" aria-label="Unassigned stay: reservation {{ $s->reservation->reservation_no }}, {{ $s->reservation->guest->full_name ?? 'guest profile unavailable' }}, {{ $s->roomType->name ?? $s->roomType->code ?? 'room type unspecified' }}, arrives {{ $s->arrival_date->format('d M Y') }}, departs {{ $s->departure_date->format('d M Y') }}{{ (can_do('frontdesk.roommove') || can_do('reservations.edit')) ? ', drag to assign a room' : '' }}" title="Reservation {{ $s->reservation->reservation_no }} · {{ $s->arrival_date->format('d M Y') }} to {{ $s->departure_date->format('d M Y') }}{{ (can_do('frontdesk.roommove') || can_do('reservations.edit')) ? ' · Drag to a room to assign' : '' }}">
                  <b>{{ $s->reservation->reservation_no }}</b><span>{{ $s->reservation->guest->full_name ?? 'Guest profile unavailable' }}</span>
                  <small>{{ $s->roomType->name ?? $s->roomType->code ?? 'Room type unspecified' }} · {{ $s->arrival_date->format('d M') }} → {{ $s->departure_date->format('d M') }}</small>
                </a>
              @endforeach
            </div>
          </details>
        @endif
      </section>
    @endif
    <p id="timelineScrollHint" class="sr-only">The timeline scrolls vertically through rooms and horizontally through dates. Focus the timeline and use the arrow keys, or use a trackpad, to move around it.</p>
    <div class="tape" id="tape" role="region" tabindex="0" aria-describedby="timelineScrollHint" aria-label="Hotel room timeline from {{ $date->format('d M Y') }} for {{ $days }} days">
      <div class="tape-head" style="--days:{{ $days }}">
        <div class="room-label timeline-room-heading"><b>Room</b><small>Live status · HK · type</small></div>
        @foreach($dates as $d)
          <div class="timeline-day {{ $d->isWeekend() ? 'is-weekend' : '' }} {{ $d->isSameDay(bdate()) ? 'is-today' : '' }}" title="{{ $d->format('l, d F Y') }}">
            @if($loop->first || $d->day === 1)<em>{{ $d->format('M') }}</em>@endif
            <time datetime="{{ $d->toDateString() }}">{{ $d->format('d') }}</time><small>{{ $d->format('D') }}</small>
          </div>
        @endforeach
      </div>
      @forelse($grouped as $name => $groupRooms)
        <div class="tape-group">{{ $name }} <span>{{ $groupRooms->count() }}</span></div>
        @foreach($groupRooms as $room)
          @php
            $photo = \App\Services\ReservationDeskService::photoPath($room);
            $names = \App\Services\ReservationDeskService::amenityNames($room);
          @endphp
          <div class="room-row {{ $room->status }} {{ $loop->odd ? 'is-odd' : '' }}" data-room="{{ $room->id }}" style="--days:{{ $days }}">
            <a class="room-label" aria-label="Room {{ $room->room_no }}, {{ $room->roomType->name ?? 'type not set' }}, live room status {{ str_replace('_', ' ', $room->status) }}, housekeeping {{ str_replace('_', ' ', $room->hk_status) }}" title="Room {{ $room->room_no }} · {{ $room->roomType->name ?? 'type not set' }} · Live {{ str_replace('_', ' ', $room->status) }} · HK {{ str_replace('_', ' ', $room->hk_status) }}{{ $names->isNotEmpty() ? ' · Amenities: '.$names->join(', ') : '' }}" href="{{ route('reservations.room', $room) }}">
              @if($photo)<img src="{{ asset($photo) }}" alt="">@else<span class="type-code" title="No photo on file">{{ $room->roomType->code }}</span>@endif
              <span>
                <b>{{ $room->room_no }}</b>
                <small><span class="timeline-room-status status-{{ $room->status }}">{{ ucfirst(str_replace('_', ' ', $room->status)) }}</span> · HK {{ ucfirst(str_replace('_', ' ', $room->hk_status)) }} · {{ $room->roomType->name ?? 'Type not set' }}</small>
              </span>
            </a>
            @foreach($dates as $dayColumn => $dayDate)<span class="timeline-day-cell {{ $dayDate->isWeekend() ? 'is-weekend' : '' }} {{ $dayDate->isSameDay(bdate()) ? 'is-today' : '' }}" style="grid-column:{{ $dayColumn + 2 }}" role="presentation" aria-hidden="true"></span>@endforeach
            @foreach($byRoom[$room->id] ?? [] as $s)
              @php $span = \App\Services\ReservationDeskService::span($date, $to, $s->arrival_date, $s->departure_date); @endphp
              @if($span)
                @php
                  [$offset, $len] = $span;
                  $due = $s->status === 'checked_in' && $s->departure_date->isSameDay($date);
                  $cls = $s->status === 'checked_out' ? 'out' : ($s->status === 'checked_in' ? ($due ? 'due' : 'in') : ($s->reservation->status === 'tentative' ? 'tent' : 'res'));
                  if ($s->reservation->guest->is_vip) $cls .= ' vip';
                  if ($s->reservation->booking_type === 'group') $cls .= ' grp';
                  $barLabel = \App\Services\ReservationDeskService::stayLabel($s, $date);
                  $flag = $flags[$s->id] ?? null;
                  $marks = $barLabel;
                  if ($flag && $flag['deposit'] === 'received') $marks .= ' · deposit';
                  elseif ($flag && $flag['deposit'] === 'partial') $marks .= ' · part deposit';
                  elseif ($s->status !== 'checked_out') $marks .= ' · no deposit';
                  if ($flag && $flag['balance'] > 0.009) $marks .= ' · balance '.money($flag['balance']);
                  if ($s->reservation->special_request) $marks .= ' · request';
                  $arrivalColumn = $s->arrival_date->toDateString() >= $date->toDateString() && $s->arrival_date->toDateString() < $to->toDateString() ? (int) $date->diffInDays($s->arrival_date) + 2 : null;
                  $departureColumn = $s->departure_date->toDateString() >= $date->toDateString() && $s->departure_date->toDateString() < $to->toDateString() ? (int) $date->diffInDays($s->departure_date) + 2 : null;
                @endphp
                <a class="stay-bar {{ $cls }}" aria-label="Room {{ $room->room_no }}, {{ $s->reservation->guest->full_name }}, reservation {{ $s->reservation->reservation_no }}, {{ $barLabel }}, {{ $s->arrival_date->format('d M Y') }} to {{ $s->departure_date->format('d M Y') }}, {{ $marks }}" draggable="{{ in_array($s->status, ['reserved', 'checked_in'], true) && (can_do('frontdesk.roommove') || ($s->status === 'reserved' && can_do('reservations.edit'))) ? 'true' : 'false' }}" data-stay="{{ $s->id }}" href="{{ route('reservations.stay', $s) }}" style="grid-column: {{ $offset + 2 }} / span {{ $len }}" title="{{ $s->reservation->guest->full_name }} · {{ $s->reservation->reservation_no }} · {{ $s->arrival_date->format('d M Y') }} – {{ $s->departure_date->format('d M Y') }} · {{ $s->reservation->source?->name ?? 'Source not recorded' }} · {{ $marks }}">
                  <b>{{ $s->reservation->guest->full_name }}</b>
                  <small>{{ $s->reservation->reservation_no }} · {{ $barLabel }} · {{ money($s->rate) }}{{ $s->reservation->special_request ? ' · request' : '' }}{{ $flag && $flag['deposit'] !== 'none' ? ' · dep' : '' }}</small>
                </a>
                @if($arrivalColumn)<span class="timeline-edge is-in" role="note" aria-label="Arrival: {{ $s->reservation->guest->full_name }}, {{ $s->arrival_date->format('d M Y') }}" style="grid-column:{{ $arrivalColumn }}" title="Arrives {{ $s->arrival_date->format('d M Y') }}">IN</span>@endif
                @if($departureColumn)<span class="timeline-edge is-out" role="note" aria-label="Departure: {{ $s->reservation->guest->full_name }}, {{ $s->departure_date->format('d M Y') }}" style="grid-column:{{ $departureColumn }}" title="Departs {{ $s->departure_date->format('d M Y') }}">OUT</span>@endif
              @endif
            @endforeach
            @foreach($outages[$room->id] ?? [] as $o)
              @php $span = \App\Services\ReservationDeskService::span($date, $to, $o->starts_on, $o->ends_on); @endphp
              @if($span)
                <span class="outage-bar" role="note" aria-label="Room {{ $room->room_no }} blocked: {{ \App\Models\RoomOutage::REASONS[$o->reason] ?? $o->reason }}, {{ $o->starts_on->format('d M Y') }} to {{ $o->ends_on->format('d M Y') }}" style="grid-column: {{ $span[0] + 2 }} / span {{ $span[1] }}" title="Blocked: {{ \App\Models\RoomOutage::REASONS[$o->reason] ?? $o->reason }}">Blocked · {{ \App\Models\RoomOutage::REASONS[$o->reason] ?? $o->reason }}</span>
              @endif
            @endforeach
          </div>
        @endforeach
      @empty
        <div class="timeline-empty-state">
          <span class="timeline-empty-icon"><x-icon name="search"/></span>
          <div><strong>No rooms match this timeline</strong><p>Try another room type, floor or housekeeping filter to bring rooms back into view.</p></div>
          <a class="btn btn-sm" href="{{ route('reservations.index', ['view' => 'timeline', 'date' => $date->toDateString(), 'days' => $days, 'group' => $group]) }}">Clear room filters</a>
        </div>
      @endforelse
    </div>
  @elseif($view === 'board')
    <div class="room-board" role="region" aria-label="Hotel rooms grouped by {{ $group }}">
      @forelse($grouped as $name => $groupRooms)
        <section class="room-board-group" aria-labelledby="roomBoardGroup{{ $loop->index }}">
          <div class="room-board-group-head"><h3 id="roomBoardGroup{{ $loop->index }}">{{ $name }}</h3><span>{{ number_format($groupRooms->count()) }} {{ \Illuminate\Support\Str::plural('room', $groupRooms->count()) }}</span></div>
          <div class="room-board-grid">
            @foreach($groupRooms as $room)
              @php
                $roomStays = $byRoom[$room->id] ?? collect();
                $boardStay = $roomStays->first(fn ($stay) => $stay->arrival_date->toDateString() <= $date->toDateString() && $stay->departure_date->toDateString() > $date->toDateString());
                $dueOutStay = $roomStays->first(fn ($stay) => in_array($stay->status, ['checked_in', 'checked_out'], true) && $stay->departure_date->toDateString() === $date->toDateString());
                $nextStay = $roomStays->where('status', 'reserved')->filter(fn ($stay) => $stay->arrival_date->toDateString() >= $date->toDateString())->sortBy('arrival_date')->first();
                $roomBlocks = ($outages[$room->id] ?? collect())->sortBy('starts_on')->values();
                $photo = \App\Services\ReservationDeskService::photoPath($room);
                $roomTypeName = $room->roomType->name ?? $room->roomType->code ?? 'Type not set';
                $floorName = $room->floor->name ?? 'Floor not set';
                $roomStatusLabel = ucwords(str_replace('_', ' ', $room->status));
                $hkStatusLabel = ucwords(str_replace('_', ' ', $room->hk_status));
                $stayStateLabel = $boardStay
                    ? ($boardStay->status === 'checked_in' ? 'In-house' : ($boardStay->status === 'checked_out' ? 'Completed stay' : ($boardStay->reservation?->status === 'tentative' ? 'Tentative' : ($boardStay->arrival_date->isSameDay($date) ? 'Arriving' : 'Reserved'))))
                    : ($dueOutStay ? ($dueOutStay->status === 'checked_out' ? 'Completed stay' : 'Due out') : null);
                $stayStateClass = $boardStay
                    ? ($boardStay->status === 'checked_in' ? 'in-house' : ($boardStay->status === 'checked_out' ? 'completed' : ($boardStay->reservation?->status === 'tentative' ? 'tentative' : 'reserved')))
                    : ($dueOutStay ? ($dueOutStay->status === 'checked_out' ? 'completed' : 'due-out') : 'reserved');
                $cardStay = $boardStay ?? $dueOutStay ?? $nextStay;
                $nextStayLabel = $nextStay?->reservation?->status === 'tentative' ? 'Tentative arrival' : 'Next arrival';
              @endphp
              <article class="room-board-card status-{{ $room->status }} hk-{{ $room->hk_status }} {{ $roomBlocks->isNotEmpty() ? 'has-block' : '' }}" aria-labelledby="roomBoardRoom{{ $room->id }}">
                <div class="room-board-photo">
                  @if($photo)<img src="{{ asset($photo) }}" alt="">@else<span class="room-board-type-code" aria-hidden="true">{{ $room->roomType->code ?? 'ROOM' }}</span>@endif
                  <span class="room-board-photo-room">Room {{ $room->room_no }}</span>
                  <span class="room-board-status status-{{ $room->status }}">{{ $roomStatusLabel }}</span>
                </div>
                <div class="room-board-card-body">
                  <div class="room-board-title-row">
                    <div><h4 id="roomBoardRoom{{ $room->id }}"><a href="{{ route('reservations.room', $room) }}">Room {{ $room->room_no }}</a></h4><p>{{ $roomTypeName }} · {{ $floorName }}</p></div>
                    <span class="room-board-hk hk-{{ $room->hk_status }}">HK · {{ $hkStatusLabel }}</span>
                  </div>
                  @if($roomBlocks->isNotEmpty())
                    <ul class="room-board-blocks" aria-label="Room blocks for room {{ $room->room_no }}">
                      @foreach($roomBlocks as $roomBlock)
                        @php
                          $roomBlockActive = $roomBlock->starts_on->toDateString() <= $date->toDateString() && $roomBlock->ends_on->toDateString() > $date->toDateString();
                          $blockReason = \App\Models\RoomOutage::REASONS[$roomBlock->reason] ?? str_replace('_', ' ', $roomBlock->reason);
                        @endphp
                        <li class="room-board-block {{ $roomBlockActive ? 'is-active' : 'is-upcoming' }}" aria-label="{{ $roomBlockActive ? 'Active' : 'Upcoming' }} room block: {{ $blockReason }}, starts {{ $roomBlock->starts_on->format('d M Y') }}, room returns {{ $roomBlock->ends_on->format('d M Y') }}">
                          <span class="room-board-block-mark" aria-hidden="true">!</span><span><b>{{ ($roomBlockActive ? 'Active · ' : 'Upcoming · ').$blockReason }}</b><small>Starts <time datetime="{{ $roomBlock->starts_on->toDateString() }}">{{ $roomBlock->starts_on->format('d M') }}</time> · returns <time datetime="{{ $roomBlock->ends_on->toDateString() }}">{{ $roomBlock->ends_on->format('d M Y') }}</time></small></span>
                        </li>
                      @endforeach
                    </ul>
                  @endif
                  @if($cardStay)
                    <div class="room-board-stay">
                      @if($boardStay || $dueOutStay)<span class="room-board-stay-state {{ $stayStateClass }}">{{ $stayStateLabel }}</span>@else<span class="room-board-stay-state {{ $nextStay?->reservation?->status === 'tentative' ? 'tentative' : 'next' }}">{{ $nextStayLabel }}</span>@endif
                      @php $cardGuest = $cardStay->reservation?->guest; @endphp
                      @if($cardGuest && can_do('guests.view'))
                        <a class="room-board-guest" href="{{ route('guests.show', $cardGuest) }}" aria-label="Open guest profile for {{ $cardGuest->full_name }}">{{ $cardGuest->full_name }}</a>
                      @else
                        <a class="room-board-guest" href="{{ route('reservations.stay', $cardStay) }}" aria-label="Open stay for {{ $cardGuest?->full_name ?? 'guest profile unavailable' }}">{{ $cardGuest?->full_name ?? 'Guest profile unavailable' }}</a>
                      @endif
                      <small><a class="room-board-reservation" href="{{ route('reservations.stay', $cardStay) }}" aria-label="Open reservation {{ $cardStay->reservation?->reservation_no ?? 'details' }}">{{ $cardStay->reservation?->reservation_no ?? 'Reservation details' }}</a> · <time datetime="{{ $cardStay->arrival_date->toDateString() }}">{{ $cardStay->arrival_date->format('d M') }}</time> → <time datetime="{{ $cardStay->departure_date->toDateString() }}">{{ $cardStay->departure_date->format('d M Y') }}</time></small>
                      @if($cardStay->reservation?->guest?->is_vip)<span class="room-board-flag vip">VIP guest</span>@endif
                      @if($cardStay->reservation?->booking_type === 'group')<span class="room-board-flag group">Group stay</span>@endif
                      @if($cardStay->reservation?->special_request)<span class="room-board-flag request" title="{{ $cardStay->reservation->special_request }}">Request · {{ \Illuminate\Support\Str::limit($cardStay->reservation->special_request, 72) }}</span>@endif
                    </div>
                    @if($boardStay && $dueOutStay)
                      <div class="room-board-turnover">
                        <span>Due out · <time datetime="{{ $date->toDateString() }}">{{ $date->format('d M') }}</time></span>
                        <a href="{{ route('reservations.stay', $dueOutStay) }}" aria-label="Open departing stay for {{ $dueOutStay->reservation?->guest?->full_name ?? 'guest profile unavailable' }}">{{ $dueOutStay->reservation?->guest?->full_name ?? 'Guest profile unavailable' }}</a>
                        <small>{{ $dueOutStay->reservation?->reservation_no ?? 'Reservation details' }} · Departs <time datetime="{{ $dueOutStay->departure_date->toDateString() }}">{{ $dueOutStay->departure_date->format('d M Y') }}</time></small>
                      </div>
                    @endif
                  @else
                    <div class="room-board-clear"><strong>No matching stay</strong><span>Nothing shown for this date and filters</span></div>
                  @endif
                </div>
              </article>
            @endforeach
          </div>
        </section>
      @empty
        <div class="room-board-empty">
          <span class="room-board-empty-icon"><x-icon name="search"/></span>
          <div><strong>No rooms match these filters</strong><p>Clear the room filters to restore the full room board.</p></div>
          <a class="btn btn-sm" href="{{ route('reservations.index', ['view' => 'board', 'date' => $date->toDateString(), 'days' => $days, 'group' => $group]) }}">Clear room filters</a>
        </div>
      @endforelse
    </div>
  @elseif($view === 'list')
    @php
      $statusOptions = [
        ['all', 'All stays', null, $counts->sum()],
        ['confirmed', 'Confirmed', 'confirmed', $counts['confirmed'] ?? 0],
        ['tentative', 'Tentative', 'tentative', $counts['tentative'] ?? 0],
        ['checked_in', 'In-house', 'checked_in', $counts['checked_in'] ?? 0],
        ['checked_out', 'Checked out', 'checked_out', $counts['checked_out'] ?? 0],
        ['cancelled', 'Cancelled', 'cancelled', $counts['cancelled'] ?? 0],
        ['no_show', 'No-show', 'no_show', $counts['no_show'] ?? 0],
      ];
      $statusValue = request('status');
      $currentStatus = is_string($statusValue) && in_array($statusValue, ['confirmed', 'tentative', 'checked_in', 'checked_out', 'cancelled', 'no_show'], true) ? $statusValue : 'all';
      $searchValue = is_string(request('q')) ? trim(request('q')) : '';
      $arrivalOnValue = is_string(request('arrival_on')) ? request('arrival_on') : '';
      $departureOnValue = is_string(request('departure_on')) ? request('departure_on') : '';
      $cancelledOnValue = is_string(request('cancelled_on')) ? request('cancelled_on') : '';
      $fromValue = is_string(request('from')) ? request('from') : '';
      $toValue = is_string(request('to')) ? request('to') : '';
      $earliestArrivalValue = max($arrivalOnValue, $fromValue);
      $departureMinValue = $earliestArrivalValue !== '' ? \Illuminate\Support\Carbon::parse($earliestArrivalValue)->addDay()->toDateString() : '';
      $dateFilterWarning = null;
      if ($fromValue !== '' && $toValue !== '' && $fromValue > $toValue) {
        $dateFilterWarning = 'Arrival start must be on or before arrival end.';
      } elseif ($arrivalOnValue !== '' && $fromValue !== '' && $arrivalOnValue < $fromValue) {
        $dateFilterWarning = 'Arrival on must be on or after Arrival from.';
      } elseif ($arrivalOnValue !== '' && $toValue !== '' && $arrivalOnValue > $toValue) {
        $dateFilterWarning = 'Arrival on must be on or before Arrival to.';
      } elseif ($departureOnValue !== '' && $earliestArrivalValue !== '' && $departureOnValue <= $earliestArrivalValue) {
        $dateFilterWarning = 'Departure on must be after the earliest selected arrival date.';
      }
      $sourceValue = is_scalar(request('source')) ? (string) request('source') : '';
      $typeValue = is_string(request('type')) ? request('type') : '';
      $channelValue = is_scalar(request('channel')) ? (string) request('channel') : '';
      $companyValue = is_scalar(request('company')) ? (string) request('company') : '';
      $requestedSort = request('sort');
      $sortValue = is_string($requestedSort) && in_array($requestedSort, ['arrival', 'arrival_asc', 'arrival_desc', 'departure', 'departure_desc', 'created'], true) && ($requestedSort !== 'arrival_asc' || $currentStatus === 'checked_out') ? $requestedSort : 'arrival';
      $pageSizeValue = $reservations->perPage();
    @endphp
    <section class="reservation-list-module" aria-label="Reservation register">
      <nav class="reservation-status-tabs" aria-label="Filter reservations by status">
        @foreach($statusOptions as [$key, $label, $statusValue, $count])
          <a href="{{ $go(['view' => 'list', 'status' => $statusValue, 'page' => null]) }}" class="reservation-status-tab {{ $currentStatus === $key ? 'is-active' : '' }}" @if($currentStatus === $key) aria-current="page" @endif>
            <span>{{ $label }}</span><b>{{ number_format($count) }}</b>
          </a>
        @endforeach
      </nav>

      <form class="reservation-list-filter" method="get" id="reservationListFilter">
        <input type="hidden" name="view" value="list">
        <input type="hidden" name="status" value="{{ $currentStatus === 'all' ? '' : $currentStatus }}">
        <label class="reservation-search-field">
          <span class="reservation-filter-label">Search reservations</span>
          <span class="reservation-search-control"><x-icon name="search" size="sm"/><input type="search" name="q" value="{{ $searchValue }}" placeholder="Guest, email, reservation, room, phone, company or OTA reference" autocomplete="off" maxlength="160" enterkeyhint="search" aria-label="Search reservations" aria-describedby="reservationSearchHint"><kbd class="reservation-search-shortcut" data-search-shortcut aria-hidden="true" @if($searchValue !== '') hidden @endif>/</kbd><button type="button" class="reservation-search-clear" data-clear-reservation-search aria-label="Clear search" @if($searchValue === '') hidden @endif>×</button></span>
          <span id="reservationSearchHint" class="sr-only">Press slash to focus search. Press Escape to clear and apply the search.</span>
        </label>
        <label><span class="reservation-filter-label">Arrives on</span><input type="date" name="arrival_on" value="{{ $arrivalOnValue }}" @if($fromValue !== '') min="{{ $fromValue }}" @endif @if($toValue !== '') max="{{ $toValue }}" @endif></label>
        <label><span class="reservation-filter-label">Departs on</span><input type="date" name="departure_on" value="{{ $departureOnValue }}" @if($departureMinValue !== '') min="{{ $departureMinValue }}" @endif></label>
        @if($currentStatus === 'cancelled')<label><span class="reservation-filter-label">Cancelled on</span><input type="date" name="cancelled_on" value="{{ $cancelledOnValue }}"></label>@endif
        <label><span class="reservation-filter-label">Arrival from</span><input type="date" name="from" value="{{ $fromValue }}" @if($toValue !== '') max="{{ $toValue }}" @endif></label>
        <label><span class="reservation-filter-label">Arrival to</span><input type="date" name="to" value="{{ $toValue }}" @if($fromValue !== '') min="{{ $fromValue }}" @endif></label>
        <label><span class="reservation-filter-label">Source</span><select name="source"><option value="">All sources</option>@foreach($sources as $id => $n)<option value="{{ $id }}" @selected($sourceValue == $id)>{{ $n }}</option>@endforeach</select></label>
        <label><span class="reservation-filter-label">Booking type</span><select name="type"><option value="">All types</option><option value="individual" @selected($typeValue === 'individual')>Individual</option><option value="group" @selected($typeValue === 'group')>Group</option></select></label>
        <label><span class="reservation-filter-label">Company</span><select name="company"><option value="">All companies</option>@foreach($companies as $id => $name)<option value="{{ $id }}" @selected($companyValue == $id)>{{ $name }}</option>@endforeach</select></label>
        @if($channels->isNotEmpty())
          <label><span class="reservation-filter-label">Channel</span><select name="channel"><option value="">All channels</option>@foreach($channels as $id => $n)<option value="{{ $id }}" @selected($channelValue == $id)>{{ $n }}</option>@endforeach</select></label>
        @endif
        <label><span class="reservation-filter-label">Sort by</span><select name="sort"><option value="arrival" @selected($sortValue === 'arrival')>Arrival date · {{ $currentStatus === 'checked_out' ? 'latest' : 'earliest' }}</option>@if($currentStatus === 'checked_out')<option value="arrival_asc" @selected($sortValue === 'arrival_asc')>Arrival date · earliest</option>@else<option value="arrival_desc" @selected($sortValue === 'arrival_desc')>Arrival date · latest</option>@endif<option value="departure" @selected($sortValue === 'departure')>Departure date · earliest</option><option value="departure_desc" @selected($sortValue === 'departure_desc')>Departure date · latest</option><option value="created" @selected($sortValue === 'created')>Newest booking</option></select></label>
        <label><span class="reservation-filter-label">Rows</span><select name="per_page"><option value="25" @selected($reservations->perPage() === 25)>25</option><option value="50" @selected($reservations->perPage() === 50)>50</option><option value="100" @selected($reservations->perPage() === 100)>100</option></select></label>
        <div class="reservation-filter-actions"><button class="btn btn-primary"><x-icon name="grid" size="sm"/> Apply filters</button><a class="btn btn-ghost" href="{{ route('reservations.index', ['view' => 'list']) }}">Reset</a></div>
      </form>
      @if($dateFilterWarning)
        <p class="reservation-date-alert" role="alert">{{ $dateFilterWarning }} Adjust the dates or clear one of them.</p>
      @endif

      <div class="reservation-list-results" role="status" aria-live="polite" aria-atomic="true">
        <div><strong>{{ number_format($reservations->firstItem() ?? 0) }}–{{ number_format($reservations->lastItem() ?? 0) }}</strong><span> of </span><strong>{{ number_format($reservations->total()) }}</strong><span> reservations</span></div>
        @if($searchValue !== '')<span class="reservation-query-note"><x-icon name="search" size="sm"/> Matching “{{ $searchValue }}”</span>@endif
      </div>
      @php
        $filterChips = [];
        $statusLabels = ['confirmed' => 'Confirmed', 'tentative' => 'Tentative', 'checked_in' => 'In-house', 'checked_out' => 'Checked out', 'cancelled' => 'Cancelled', 'no_show' => 'No-show'];
        if ($currentStatus !== 'all') $filterChips[] = ['status', 'Status: '.$statusLabels[$currentStatus]];
        if ($searchValue !== '') $filterChips[] = ['q', 'Search: '.$searchValue];
        if ($fromValue !== '') $filterChips[] = ['from', 'Arrival from: '.\Illuminate\Support\Carbon::parse($fromValue)->format('d M Y')];
        if ($toValue !== '') $filterChips[] = ['to', 'Arrival to: '.\Illuminate\Support\Carbon::parse($toValue)->format('d M Y')];
        if ($arrivalOnValue !== '') $filterChips[] = ['arrival_on', 'Arrival on: '.\Illuminate\Support\Carbon::parse($arrivalOnValue)->format('d M Y')];
        if ($departureOnValue !== '') $filterChips[] = ['departure_on', 'Departure on: '.\Illuminate\Support\Carbon::parse($departureOnValue)->format('d M Y')];
        if ($cancelledOnValue !== '') $filterChips[] = ['cancelled_on', 'Cancelled on: '.\Illuminate\Support\Carbon::parse($cancelledOnValue)->format('d M Y')];
        if ($sourceValue !== '') $filterChips[] = ['source', 'Source: '.($sources->get($sourceValue) ?? 'Selected')];
        if (in_array($typeValue, ['individual', 'group'], true)) $filterChips[] = ['type', 'Type: '.ucfirst($typeValue)];
        if ($channelValue !== '') $filterChips[] = ['channel', 'Channel: '.($channels->get($channelValue) ?? 'Selected')];
        if ($companyValue !== '') $filterChips[] = ['company', 'Company: '.($companies->get($companyValue) ?? 'Selected')];
        if ($sortValue !== 'arrival') $filterChips[] = ['sort', 'Sort: '.match($sortValue) { 'arrival_asc' => 'Arrival date · earliest', 'arrival_desc' => 'Arrival date · latest', 'departure' => 'Departure date · earliest', 'departure_desc' => 'Departure date · latest', 'created' => 'Newest booking', default => 'Arrival date · earliest' }];
        if ($pageSizeValue !== 25) $filterChips[] = ['per_page', 'Rows: '.$reservations->perPage()];
        $hasListCriteria = $currentStatus !== 'all' || (bool) array_filter($filterChips, fn ($chip) => ! in_array($chip[0], ['sort', 'per_page'], true));
      @endphp
      @if($filterChips)
        <div class="reservation-active-filters" role="group" aria-label="Active reservation filters">
          <span class="reservation-active-label">Filters</span>
          @foreach($filterChips as [$filterKey, $filterLabel])
            @php $withoutFilter = request()->query(); unset($withoutFilter[$filterKey], $withoutFilter['page']); $withoutFilter['view'] = 'list'; @endphp
            <a href="{{ route('reservations.index', $withoutFilter) }}" aria-label="Remove {{ $filterLabel }} filter">{{ $filterLabel }} <b aria-hidden="true">×</b></a>
          @endforeach
          <a class="reservation-clear-filters" href="{{ route('reservations.index', ['view' => 'list']) }}">Clear all</a>
        </div>
      @endif

      <div class="table-wrap reservation-table-wrap">
        <table class="table reservation-table">
          <caption class="sr-only">Reservation search results</caption>
          <thead><tr><th scope="col">Reservation</th><th scope="col">Guest</th><th scope="col">Stay</th><th scope="col">Rooms</th><th scope="col">Guests</th><th scope="col">Channel / source</th><th scope="col">Status</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
          <tbody>
          @forelse($reservations as $r)
            @php
              $activeRooms = $r->rooms->where('status', '!=', 'cancelled');
              $openStay = $r->rooms->first(fn ($s) => in_array($s->status, ['reserved', 'checked_in'], true));
              $bookedAt = $r->created_at?->format($sortValue === 'created' ? 'd M Y, H:i' : 'd M Y') ?? 'date unavailable';
              $guestPhone = trim((string) ($r->guest?->phone ?? ''));
              $callPhone = preg_match('/^\\+?[0-9][0-9 .()-]{5,}$/', $guestPhone) ? preg_replace('/[^0-9+]/', '', $guestPhone) : null;
            @endphp
            <tr>
              <td data-label="Reservation">
                <div class="reservation-number-stack">
                  <a href="{{ route('reservations.show', $r) }}" class="reservation-number">{{ $r->reservation_no }}</a>
                  <small class="reservation-created-at">Booked {{ $bookedAt }}</small>
                </div>
                @if($r->booking_type === 'group')<div class="reservation-row-tag"><span class="badge badge-purple">Group</span><span>{{ $r->group_name }}</span></div>@endif
              </td>
              <td data-label="Guest">
                <div class="reservation-guest-name">{{ $r->guest?->full_name ?? 'Guest profile unavailable' }}@if($r->guest?->is_vip)<span class="badge badge-amber">VIP</span>@endif</div>
                <div class="reservation-guest-meta">@if($callPhone)<a href="tel:{{ $callPhone }}" aria-label="Call {{ $r->guest?->full_name ?? 'guest' }} at {{ $guestPhone }}" title="Call guest">{{ $guestPhone }}</a>@else{{ $guestPhone ?: 'No phone on file' }}@endif @if($r->company)<span>· {{ $r->company->name }}</span>@endif</div>
              </td>
              <td data-label="Stay">
                <div class="reservation-stay-dates"><span><small><abbr title="Arrival">ARR</abbr></small>{{ $r->arrival_date->format('d M Y') }}</span><span><small><abbr title="Departure">DEP</abbr></small>{{ $r->departure_date->format('d M Y') }}</span></div>
                <span class="reservation-night-count">{{ $r->nights }} {{ \Illuminate\Support\Str::plural('night', $r->nights) }}</span>
              </td>
              <td data-label="Rooms">
                @if($activeRooms->isNotEmpty())
                  <div class="reservation-room-tags">
                    @foreach($activeRooms->take(2) as $stay)
                      <span>{{ $stay->room?->room_no ?? $stay->roomType?->code ?? 'Unassigned' }}</span>
                    @endforeach
                    @if($activeRooms->count() > 2)<span class="reservation-room-more">+{{ $activeRooms->count() - 2 }}</span>@endif
                  </div>
                  <small class="reservation-cell-note">{{ $activeRooms->count() }} {{ \Illuminate\Support\Str::plural('room', $activeRooms->count()) }}</small>
                @else
                  <span class="reservation-cell-note">No active rooms</span>
                @endif
              </td>
              <td data-label="Guests"><span class="reservation-pax"><b>{{ $r->adults }}</b> adults</span><span class="reservation-pax-sub">{{ $r->children }} children</span></td>
              <td data-label="Channel / source"><span class="reservation-source">{{ $r->otaChannel?->name ?? $r->source?->name ?? 'Direct' }}</span>@if($r->otaChannel && $r->source)<span class="reservation-cell-note">Source · {{ $r->source->name }}</span>@endif @if($r->ota_reference)<span class="reservation-cell-note">Ref · {{ $r->ota_reference }}</span>@endif</td>
              <td data-label="Status">{!! status_badge($r->status) !!}</td>
              <td data-label="Actions" class="reservation-row-actions">
                <a class="btn btn-sm" href="{{ route('reservations.show', $r) }}" aria-label="View reservation {{ $r->reservation_no }}" title="View reservation"><x-icon name="eye" size="sm"/></a>
                @if($openStay)<a class="btn btn-sm" href="{{ route('reservations.stay', $openStay) }}" aria-label="Open stay for {{ $r->reservation_no }}" title="Open stay"><x-icon name="bed" size="sm"/></a>@endif
              </td>
            </tr>
          @empty
            <tr class="reservation-empty-row"><td colspan="8"><div class="reservation-empty"><span class="reservation-empty-icon"><x-icon name="calendar"/></span><strong>{{ $hasListCriteria ? 'No reservations match these filters' : 'No reservations yet' }}</strong><span>{{ $hasListCriteria ? 'Try adjusting your search or removing a filter.' : 'Create the first reservation to start building the register.' }}</span>@if($hasListCriteria)<a class="btn btn-sm" href="{{ route('reservations.index', ['view' => 'list']) }}">Clear filters</a>@elseif(can_do('reservations.create'))<a class="btn btn-sm btn-primary" href="{{ route('reservations.create') }}">New reservation</a>@endif</div></td></tr>
          @endforelse
          </tbody>
        </table>
        <div class="reservation-pagination">{{ $reservations->links() }}</div>
      </div>
    </section>
  @elseif($view === 'arrivals')
    @include('reservations.desk-lines', ['rows' => $arrivals, 'mode' => 'arrival'])
  @elseif($view === 'departures')
    @include('reservations.desk-lines', ['rows' => $departures, 'mode' => 'departure'])
  @elseif($view === 'inhouse')
    @include('reservations.desk-lines', ['rows' => $inhouse, 'mode' => 'inhouse'])
  @elseif($view === 'unassigned')
    @include('reservations.desk-lines', ['rows' => $unassigned, 'mode' => 'unassigned'])
  @elseif($view === 'groups')
    <section class="groups-manifest" aria-labelledby="groupsManifestTitle">
      <div class="groups-manifest-head"><div><span class="groups-section-kicker">GROUP STAY REGISTER</span><h3 id="groupsManifestTitle">Group reservation roster</h3><p>Showing groups overlapping {{ $date->format('d M Y') }} through {{ $date->copy()->addDays($days - 1)->format('d M Y') }}. Room-block pickup remains a preview-and-confirm action.</p></div><span class="groups-manifest-count"><strong>{{ number_format($groups->count()) }}</strong> {{ \Illuminate\Support\Str::plural('group', $groups->count()) }}<small>in this view</small></span></div>
      @if($groups->isNotEmpty())
        <div class="groups-table-wrap"><table class="groups-table">
          <caption class="sr-only">Group reservations overlapping the selected {{ $days }}-day window</caption>
          <thead><tr><th scope="col">Group &amp; booking</th><th scope="col">Lead guest</th><th scope="col">Stay window</th><th scope="col">Room stays</th><th scope="col">Status</th><th scope="col">Block &amp; rooming list</th></tr></thead>
          <tbody>
          @foreach($groups as $r)
            @php
              $activeGroupRooms = $r->rooms->whereNotIn('status', ['cancelled', 'no_show'])->values();
              $groupGuestName = $r->guest?->full_name ?? 'Guest profile unavailable';
              $groupPhone = trim((string) ($r->guest?->phone ?? ''));
              $groupCallPhone = preg_match('/^\+?[0-9][0-9 .()-]{5,}$/', $groupPhone) ? preg_replace('/[^0-9+]/', '', $groupPhone) : null;
              $groupNights = max(1, (int) $r->arrival_date->diffInDays($r->departure_date));
              $blockHeld = $r->block?->lines?->sum('rooms') ?? 0;
              $groupPickupPercent = $r->block ? \App\Services\BlockService::pickupPct($r->block) : 0;
            @endphp
            <tr>
              <td data-label="Group &amp; booking"><a class="groups-name" href="{{ route('reservations.show', $r) }}">{{ $r->group_name ?: 'Group reservation' }}</a><a class="groups-reference" href="{{ route('reservations.show', $r) }}">{{ $r->reservation_no }}</a>@if($r->company)<span class="groups-company">{{ $r->company->name }}</span>@endif</td>
              <td data-label="Lead guest"><strong class="groups-guest-name">{{ $groupGuestName }}</strong>@if(can_do('guests.view') && $r->guest?->is_vip)<span class="groups-vip">VIP</span>@endif @if(can_do('guests.view') && $groupPhone)<span class="groups-contact">@if($groupCallPhone)<a href="tel:{{ $groupCallPhone }}" aria-label="Call {{ $groupGuestName }} at {{ $groupPhone }}">{{ $groupPhone }}</a>@else{{ $groupPhone }}@endif</span>@endif</td>
              <td data-label="Stay window"><div class="groups-stay-dates"><time datetime="{{ $r->arrival_date->toDateString() }}">{{ $r->arrival_date->format('d M Y') }}</time><span aria-hidden="true">→</span><time datetime="{{ $r->departure_date->toDateString() }}">{{ $r->departure_date->format('d M Y') }}</time></div><span class="groups-stay-meta">{{ $groupNights }} {{ \Illuminate\Support\Str::plural('night', $groupNights) }} · {{ $r->adults }} {{ \Illuminate\Support\Str::plural('adult', $r->adults) }}@if($r->children) · {{ $r->children }} {{ \Illuminate\Support\Str::plural('child', $r->children) }}@endif</span></td>
              <td data-label="Room stays"><strong class="groups-room-count">{{ $activeGroupRooms->count() }}</strong><span class="groups-room-count-label">{{ \Illuminate\Support\Str::plural('room stay', $activeGroupRooms->count()) }}</span>@if($activeGroupRooms->isNotEmpty())<div class="groups-room-tags">@foreach($activeGroupRooms->take(3) as $stay)<span>{{ $stay->room?->room_no ?? $stay->roomType?->name ?? 'Unassigned' }}</span>@endforeach @if($activeGroupRooms->count() > 3)<span>+{{ $activeGroupRooms->count() - 3 }}</span>@endif</div>@else<span class="groups-no-rooms">No active room stays</span>@endif</td>
              <td data-label="Status"><span class="groups-status">{!! status_badge($r->status) !!}</span>@if($r->block)<span class="groups-block-status">Block · {{ ucfirst($r->block->status) }}</span>@endif</td>
              <td data-label="Block &amp; rooming list" class="groups-allocation-cell">
                @if($r->block)
                  <a class="groups-block-link" href="{{ route('blocks.show', $r->block) }}">{{ $r->block->block_no }} · {{ $r->block->name }}</a>
                  <div class="groups-block-meta">{{ number_format($blockHeld) }} {{ \Illuminate\Support\Str::plural('room', $blockHeld) }} held · {{ number_format($groupPickupPercent, 1) }}% picked up</div>
                  @if($r->block->isHolding() && can_do('reservations.create') && $r->block->lines->isNotEmpty())
                    <details class="groups-pickup-details"><summary>Prepare rooming-list pickup</summary>
                      <form method="post" action="{{ route('blocks.pickup', $r->block) }}" data-preview="{{ route('blocks.pickup.preview', $r->block) }}" class="groups-pickup-form">
                        @csrf
                        <label class="groups-rooming-label">One guest name per line<textarea name="names" required rows="3" placeholder="Guest name 1&#10;Guest name 2" aria-label="Rooming list for {{ $r->block->block_no }}"></textarea></label>
                        <label>Room type<select name="room_type_id" required aria-label="Block room type">@foreach($r->block->lines as $line)<option value="{{ $line->room_type_id }}">{{ $line->roomType->name ?? 'Type' }} · {{ $line->rooms }} held</option>@endforeach</select></label>
                        <label>Adults<input type="number" name="adults" value="1" min="1" max="6" required aria-label="Adults"></label>
                        <label>Arrival<input type="date" name="arrival_date" required value="{{ $r->block->arrival_date->toDateString() }}" aria-label="Pickup arrival"></label>
                        <label>Departure<input type="date" name="departure_date" required value="{{ $r->block->departure_date->toDateString() }}" aria-label="Pickup departure"></label>
                        <div class="groups-pickup-actions"><button class="btn btn-sm" type="button" data-quote>Preview pickup</button><button class="btn btn-sm btn-primary" type="submit">Confirm pickup</button></div>
                        <p class="quote tiny muted" role="status" aria-live="polite" aria-atomic="true">Preview checks block inventory and rates. Nothing is saved until you confirm.</p>
                      </form>
                    </details>
                  @elseif($r->block->isHolding() && $r->block->lines->isEmpty())<span class="groups-allocation-note">No room types are held on this block.</span>
                  @endif
                @else
                  <span class="groups-no-block">No room block linked</span>
                  @if(can_do('reservations.create'))<a class="groups-engine-link" href="{{ route('blocks.create') }}">Open group engine</a>@endif
                @endif
              </td>
            </tr>
          @endforeach
          </tbody>
        </table></div>
      @else
        <div class="groups-empty" role="status"><span class="groups-empty-mark" aria-hidden="true">◎</span><div><strong>{{ request('q') || request('group_status') || request('group_focus') ? 'No group bookings match these filters' : 'No groups in this window' }}</strong><p>{{ request('q') ? 'Try another group name, confirmation, lead guest or room-type search.' : 'Group reservations that overlap this window will appear here with their room stays and block status.' }}</p></div>@if(request('q') || request('group_status') || request('group_focus'))<a class="btn btn-sm" href="{{ route('reservations.index', ['view' => 'groups', 'date' => $date->toDateString(), 'days' => $days]) }}">Clear filters</a>@elseif(can_do('reservations.create'))<a class="btn btn-sm btn-primary" href="{{ route('blocks.create') }}">Open group engine</a>@endif</div>
      @endif
    </section>
  @elseif($view === 'noshow')
    <section class="noshow-manifest" aria-labelledby="noshowManifestTitle">
      <div class="noshow-manifest-head"><div><span class="noshow-section-kicker">ARRIVAL FOLLOW-UP</span><h3 id="noshowManifestTitle">No-show booking register</h3><p>Reservations marked no-show with arrival dates from {{ $date->format('d M Y') }} through {{ $date->copy()->addDays($days - 1)->format('d M Y') }}. No-show timestamps are not inferred from arrival dates.</p></div><span class="noshow-manifest-count"><strong>{{ number_format($noShowReservations->total()) }}</strong> {{ \Illuminate\Support\Str::plural('booking', $noShowReservations->total()) }}<small>in this view</small></span></div>
      @if($noShowReservations->isNotEmpty())
        <div class="noshow-table-wrap"><table class="noshow-table">
          <caption class="sr-only">No-show reservations with arrivals in the selected {{ $days }}-day window</caption>
          <thead><tr><th scope="col">Reservation</th><th scope="col">Guest</th><th scope="col">Expected stay</th><th scope="col">Room stays</th><th scope="col">Booking details</th><th scope="col">Status</th><th scope="col">Action</th></tr></thead>
          <tbody>
          @foreach($noShowReservations as $r)
            @php
              $noShowStays = $r->rooms->where('status', 'no_show')->values();
              $noShowGuestName = $r->guest?->full_name ?? 'Guest profile unavailable';
              $noShowPhone = trim((string) ($r->guest?->phone ?? ''));
              $noShowCallPhone = preg_match('/^\+?[0-9][0-9 .()-]{5,}$/', $noShowPhone) ? preg_replace('/[^0-9+]/', '', $noShowPhone) : null;
            @endphp
            <tr>
              <td data-label="Reservation"><a class="noshow-reference" href="{{ route('reservations.show', $r) }}">{{ $r->reservation_no }}</a><strong class="noshow-group-name">{{ $r->group_name ?: ($r->booking_type === 'group' ? 'Group reservation' : 'Individual booking') }}</strong>@if($r->company)<span class="noshow-company">{{ $r->company->name }}</span>@endif</td>
              <td data-label="Guest"><strong class="noshow-guest-name">{{ $noShowGuestName }}</strong>@if(can_do('guests.view') && $r->guest?->is_vip)<span class="noshow-vip">VIP</span>@endif @if(can_do('guests.view') && $noShowPhone)<span class="noshow-contact">@if($noShowCallPhone)<a href="tel:{{ $noShowCallPhone }}" aria-label="Call {{ $noShowGuestName }} at {{ $noShowPhone }}">{{ $noShowPhone }}</a>@else{{ $noShowPhone }}@endif</span>@endif</td>
              <td data-label="Expected stay"><div class="noshow-stay-dates"><time datetime="{{ $r->arrival_date->toDateString() }}">{{ $r->arrival_date->format('d M Y') }}</time><span aria-hidden="true">→</span><time datetime="{{ $r->departure_date->toDateString() }}">{{ $r->departure_date->format('d M Y') }}</time></div><span class="noshow-stay-meta">{{ max(1, (int) $r->arrival_date->diffInDays($r->departure_date)) }} {{ \Illuminate\Support\Str::plural('night', max(1, (int) $r->arrival_date->diffInDays($r->departure_date))) }}</span></td>
              <td data-label="Room stays"><strong class="noshow-room-count">{{ $noShowStays->count() }}</strong><span class="noshow-room-count-label">{{ \Illuminate\Support\Str::plural('room stay', $noShowStays->count()) }}</span>@if($noShowStays->isNotEmpty())<div class="noshow-room-tags">@foreach($noShowStays->take(3) as $stay)<span>{{ $stay->room?->room_no ?? $stay->roomType?->name ?? 'Unassigned' }}</span>@endforeach @if($noShowStays->count() > 3)<span>+{{ $noShowStays->count() - 3 }}</span>@endif</div>@endif</td>
              <td data-label="Booking details"><span class="noshow-booking-type">{{ ucfirst($r->booking_type ?: 'individual') }}</span><span class="noshow-source">{{ $r->source?->name ?? 'Direct' }}</span>@if($r->otaChannel)<span class="noshow-channel">{{ $r->otaChannel->name }}</span>@endif</td>
              <td data-label="Status"><span class="noshow-status">{!! status_badge($r->status) !!}</span></td>
              <td data-label="Action"><a class="btn btn-sm noshow-open-link" href="{{ route('reservations.show', $r) }}" aria-label="Open reservation {{ $r->reservation_no }}">Review booking</a></td>
            </tr>
          @endforeach
          </tbody>
        </table></div>
        @if($noShowReservations->hasPages())<div class="noshow-pagination">{{ $noShowReservations->links() }}</div>@endif
      @else
        <div class="noshow-empty" role="status"><span class="noshow-empty-mark" aria-hidden="true">!</span><div><strong>{{ request('q') || request('noshow_focus') ? 'No no-show bookings match these filters' : 'No no-show bookings in this window' }}</strong><p>{{ request('q') ? 'Try another reservation number, guest, group or room search.' : 'Reservations marked no-show with arrival dates in this window will appear here.' }}</p></div>@if(request('q') || request('noshow_focus'))<a class="btn btn-sm" href="{{ route('reservations.index', ['view' => 'noshow', 'date' => $date->toDateString(), 'days' => $days]) }}">Clear filters</a>@endif</div>
      @endif
    </section>
  @elseif($view === 'cancelled')
    <section class="cancelled-manifest" aria-labelledby="cancelledManifestTitle">
      <div class="cancelled-manifest-head"><div><span class="cancelled-section-kicker">CANCELLATION FOLLOW-UP</span><h3 id="cancelledManifestTitle">Cancelled booking register</h3><p>Reservations with a recorded cancellation timestamp from {{ $date->format('d M Y') }} through {{ $date->copy()->addDays($days - 1)->format('d M Y') }}. Records without a cancellation timestamp are not assigned an inferred date.</p></div><span class="cancelled-manifest-count"><strong>{{ number_format($cancelledReservations->total()) }}</strong> {{ \Illuminate\Support\Str::plural('booking', $cancelledReservations->total()) }}<small>in this view</small></span></div>
      @if($cancelledReservations->isNotEmpty())
        <div class="cancelled-table-wrap"><table class="cancelled-table">
          <caption class="sr-only">Cancelled reservations with cancellation timestamps in the selected {{ $days }}-day window</caption>
          <thead><tr><th scope="col">Reservation</th><th scope="col">Guest</th><th scope="col">Cancelled at</th><th scope="col">Original stay</th><th scope="col">Room stays</th><th scope="col">Booking details</th><th scope="col">Status</th><th scope="col">Action</th></tr></thead>
          <tbody>
          @foreach($cancelledReservations as $r)
            @php
              $cancelledStays = $r->rooms->where('status', 'cancelled')->values();
              $cancelledGuestName = $r->guest?->full_name ?? 'Guest profile unavailable';
              $cancelledPhone = trim((string) ($r->guest?->phone ?? ''));
              $cancelledCallPhone = preg_match('/^\+?[0-9][0-9 .()-]{5,}$/', $cancelledPhone) ? preg_replace('/[^0-9+]/', '', $cancelledPhone) : null;
            @endphp
            <tr>
              <td data-label="Reservation"><a class="cancelled-reference" href="{{ route('reservations.show', $r) }}">{{ $r->reservation_no }}</a><strong class="cancelled-group-name">{{ $r->group_name ?: ($r->booking_type === 'group' ? 'Group reservation' : 'Individual booking') }}</strong>@if($r->company)<span class="cancelled-company">{{ $r->company->name }}</span>@endif</td>
              <td data-label="Guest"><strong class="cancelled-guest-name">{{ $cancelledGuestName }}</strong>@if(can_do('guests.view') && $r->guest?->is_vip)<span class="cancelled-vip">VIP</span>@endif @if(can_do('guests.view') && $cancelledPhone)<span class="cancelled-contact">@if($cancelledCallPhone)<a href="tel:{{ $cancelledCallPhone }}" aria-label="Call {{ $cancelledGuestName }} at {{ $cancelledPhone }}">{{ $cancelledPhone }}</a>@else{{ $cancelledPhone }}@endif</span>@endif</td>
              <td data-label="Cancelled at"><time class="cancelled-time" datetime="{{ $r->cancelled_at->toIso8601String() }}">{{ $r->cancelled_at->format('d M Y · H:i') }}</time><span class="cancelled-time-meta">Recorded cancellation time</span></td>
              <td data-label="Original stay"><div class="cancelled-stay-dates"><time datetime="{{ $r->arrival_date->toDateString() }}">{{ $r->arrival_date->format('d M Y') }}</time><span aria-hidden="true">→</span><time datetime="{{ $r->departure_date->toDateString() }}">{{ $r->departure_date->format('d M Y') }}</time></div><span class="cancelled-stay-meta">{{ max(1, (int) $r->arrival_date->diffInDays($r->departure_date)) }} {{ \Illuminate\Support\Str::plural('night', max(1, (int) $r->arrival_date->diffInDays($r->departure_date))) }}</span></td>
              <td data-label="Room stays"><strong class="cancelled-room-count">{{ $cancelledStays->count() }}</strong><span class="cancelled-room-count-label">{{ \Illuminate\Support\Str::plural('room stay', $cancelledStays->count()) }}</span>@if($cancelledStays->isNotEmpty())<div class="cancelled-room-tags">@foreach($cancelledStays->take(3) as $stay)<span>{{ $stay->room?->room_no ?? $stay->roomType?->name ?? 'Unassigned' }}</span>@endforeach @if($cancelledStays->count() > 3)<span>+{{ $cancelledStays->count() - 3 }}</span>@endif</div>@endif</td>
              <td data-label="Booking details"><span class="cancelled-booking-type">{{ ucfirst($r->booking_type ?: 'individual') }}</span><span class="cancelled-source">{{ $r->source?->name ?? 'Direct' }}</span>@if($r->otaChannel)<span class="cancelled-channel">{{ $r->otaChannel->name }}</span>@endif</td>
              <td data-label="Status"><span class="cancelled-status">{!! status_badge($r->status) !!}</span></td>
              <td data-label="Action"><a class="btn btn-sm cancelled-open-link" href="{{ route('reservations.show', $r) }}" aria-label="Open reservation {{ $r->reservation_no }}">Review booking</a></td>
            </tr>
          @endforeach
          </tbody>
        </table></div>
        @if($cancelledReservations->hasPages())<div class="cancelled-pagination">{{ $cancelledReservations->links() }}</div>@endif
      @else
        <div class="cancelled-empty" role="status"><span class="cancelled-empty-mark" aria-hidden="true">↶</span><div><strong>{{ request('q') || request('cancelled_focus') ? 'No cancelled bookings match these filters' : 'No cancellations in this window' }}</strong><p>{{ request('q') ? 'Try another reservation number, guest, group or room search.' : 'Reservations with a recorded cancellation timestamp in this window will appear here.' }}</p></div>@if(request('q') || request('cancelled_focus'))<a class="btn btn-sm" href="{{ route('reservations.index', ['view' => 'cancelled', 'date' => $date->toDateString(), 'days' => $days]) }}">Clear filters</a>@endif</div>
      @endif
    </section>
  @elseif($view === 'waitlist')
    @php $waitlistFormArrival = $date->isBefore(bdate()) ? bdate()->copy() : $date->copy(); @endphp
    @if(can_do('reservations.create'))
      <form class="waitlist-add-form" id="waitlist-add-form" method="post" action="{{ route('reservations.waitlist.store') }}" aria-labelledby="waitlistAddTitle">
        @csrf
        <div class="waitlist-add-head"><div><span class="waitlist-section-kicker">NEW REQUEST</span><h3 id="waitlistAddTitle">Add a guest to the waitlist</h3><p>A request only. No reservation or room is created until you deliberately convert it.</p></div><span class="waitlist-add-icon" aria-hidden="true">＋</span></div>
        <div class="waitlist-form-grid">
          <div class="waitlist-field waitlist-field-name"><label for="waitlistContactName">Guest or contact name <span aria-hidden="true">*</span></label><input id="waitlistContactName" name="contact_name" value="{{ old('contact_name') }}" maxlength="120" autocomplete="name" required @error('contact_name') aria-invalid="true" aria-describedby="waitlistNameError" @enderror>@error('contact_name')<small class="waitlist-field-error" id="waitlistNameError">{{ $message }}</small>@enderror</div>
          <div class="waitlist-field"><label for="waitlistPhone">Phone</label><input id="waitlistPhone" type="tel" name="phone" value="{{ old('phone') }}" maxlength="30" autocomplete="tel" placeholder="Optional contact number"></div>
          <div class="waitlist-field"><label for="waitlistEmail">Email</label><input id="waitlistEmail" type="email" name="email" value="{{ old('email') }}" maxlength="120" autocomplete="email" placeholder="Optional email address"></div>
          <div class="waitlist-field"><label for="waitlistArrival">Arrival <span aria-hidden="true">*</span></label><input id="waitlistArrival" type="date" name="arrival_date" value="{{ old('arrival_date', $waitlistFormArrival->toDateString()) }}" min="{{ bdate()->toDateString() }}" required @error('arrival_date') aria-invalid="true" aria-describedby="waitlistArrivalError" @enderror>@error('arrival_date')<small class="waitlist-field-error" id="waitlistArrivalError">{{ $message }}</small>@enderror</div>
          <div class="waitlist-field"><label for="waitlistDeparture">Departure <span aria-hidden="true">*</span></label><input id="waitlistDeparture" type="date" name="departure_date" value="{{ old('departure_date', $waitlistFormArrival->copy()->addDay()->toDateString()) }}" min="{{ $waitlistFormArrival->copy()->addDay()->toDateString() }}" required @error('departure_date') aria-invalid="true" aria-describedby="waitlistDepartureError" @enderror>@error('departure_date')<small class="waitlist-field-error" id="waitlistDepartureError">{{ $message }}</small>@enderror</div>
          <div class="waitlist-field"><label for="waitlistRoomType">Preferred room type</label><select id="waitlistRoomType" name="room_type_id"><option value="">Not chosen yet</option>@foreach($roomTypes as $t)<option value="{{ $t->id }}" @selected(old('room_type_id') == $t->id)>{{ $t->name }}</option>@endforeach</select></div>
          <div class="waitlist-field waitlist-field-short"><label for="waitlistAdults">Adults</label><input id="waitlistAdults" type="number" name="adults" value="{{ old('adults', 1) }}" min="1" max="20" required></div>
          <div class="waitlist-field waitlist-field-short"><label for="waitlistChildren">Children</label><input id="waitlistChildren" type="number" name="children" value="{{ old('children', 0) }}" min="0" max="20"></div>
          <div class="waitlist-field waitlist-field-short"><label for="waitlistPriority">Priority <span class="waitlist-label-hint">0–9</span></label><input id="waitlistPriority" type="number" name="priority" value="{{ old('priority', 0) }}" min="0" max="9"></div>
          <div class="waitlist-field waitlist-field-notes"><label for="waitlistNotes">Request notes</label><input id="waitlistNotes" name="notes" value="{{ old('notes') }}" maxlength="500" placeholder="Optional details for the reservations team"></div>
        </div>
        <div class="waitlist-add-footer"><p><strong>How conversion works:</strong> availability is rechecked at conversion time; creating the reservation does not assign a physical room.</p><button class="btn btn-primary" type="submit"><x-icon name="plus" size="sm"/> Add to waitlist</button></div>
      </form>
    @endif
    <section class="waitlist-manifest" aria-labelledby="waitlistManifestTitle">
      <div class="waitlist-manifest-head"><div><span class="waitlist-section-kicker">GUEST REQUESTS</span><h3 id="waitlistManifestTitle">Waitlist entries</h3><p>Highest priority first, then earliest arrival. Converting creates a reservation only after a fresh availability check.</p></div><span class="waitlist-manifest-count"><strong>{{ number_format($waitlist->count()) }}</strong> {{ \Illuminate\Support\Str::plural('entry', $waitlist->count()) }}<small>in this view</small></span></div>
      @if($waitlist->isNotEmpty())
        <div class="waitlist-table-wrap"><table class="waitlist-table">
          <caption class="sr-only">Reservation waitlist entries sorted by priority and arrival date</caption>
          <thead><tr><th scope="col">Priority</th><th scope="col">Guest &amp; contact</th><th scope="col">Stay request</th><th scope="col">Room preference</th><th scope="col">Status</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
          <tbody>
          @foreach($waitlist as $w)
            @php
              $waitlistGuestName = trim((string) $w->contact_name) ?: 'Guest name unavailable';
              $waitlistNights = max(1, (int) $w->arrival_date->diffInDays($w->departure_date));
              $waitlistPriorityTone = $w->priority >= 7 ? 'high' : ($w->priority >= 4 ? 'raised' : 'standard');
              $waitlistStatusLabel = ucfirst($w->status);
            @endphp
            <tr>
              <td data-label="Priority"><span class="waitlist-priority waitlist-priority-{{ $waitlistPriorityTone }}"><strong>{{ $w->priority }}</strong><span>{{ $w->priority >= 7 ? 'High' : ($w->priority >= 4 ? 'Raised' : 'Standard') }}</span></span></td>
              <td data-label="Guest &amp; contact"><strong class="waitlist-guest-name">{{ $waitlistGuestName }}</strong>@if(can_do('guests.view') && $w->guest?->is_vip)<span class="waitlist-vip">VIP</span>@endif
                @if(can_do('guests.view') && ($w->phone || $w->email))<div class="waitlist-contact">@if($w->phone)<span>{{ $w->phone }}</span>@endif @if($w->email)<span>{{ $w->email }}</span>@endif</div>@endif
                @if(can_do('guests.view') && $w->notes)<div class="waitlist-notes">{{ $w->notes }}</div>@endif
                <span class="waitlist-created">Added {{ $w->created_at?->format('d M Y') ?? 'date unavailable' }}</span>
              </td>
              <td data-label="Stay request"><div class="waitlist-stay-dates"><time datetime="{{ $w->arrival_date->toDateString() }}">{{ $w->arrival_date->format('d M Y') }}</time><span aria-hidden="true">→</span><time datetime="{{ $w->departure_date->toDateString() }}">{{ $w->departure_date->format('d M Y') }}</time></div><span class="waitlist-stay-meta">{{ $waitlistNights }} {{ \Illuminate\Support\Str::plural('night', $waitlistNights) }} · {{ $w->adults }} {{ \Illuminate\Support\Str::plural('adult', $w->adults) }}@if($w->children) · {{ $w->children }} {{ \Illuminate\Support\Str::plural('child', $w->children) }}@endif</span></td>
              <td data-label="Room preference"><strong class="waitlist-room-type">{{ $w->roomType?->name ?? 'Not chosen yet' }}</strong>@if(!$w->room_type_id && $w->status === 'waiting')<span class="waitlist-type-warning">Choose a type before checking or converting.</span>@else<span class="waitlist-room-note">{{ $w->roomType ? 'Requested room type' : 'No type required' }}</span>@endif</td>
              <td data-label="Status"><span class="waitlist-status waitlist-status-{{ $w->status }}">{{ $waitlistStatusLabel }}</span>@if($w->reservation)<span class="waitlist-reservation-ref">Reservation created · {{ $w->reservation->reservation_no }} · Room assignment still needed.</span>@elseif($w->status === 'waiting')<span class="waitlist-reservation-ref">No room has been assigned.</span>@endif</td>
              <td data-label="Actions" class="waitlist-actions">
                @if($w->status === 'waiting' && $w->room_type_id)
                  <form method="post" action="{{ route('reservations.waitlist.check', $w) }}">@csrf<button class="btn btn-sm" type="submit" aria-label="Check availability for {{ $waitlistGuestName }}">Check dates</button></form>
                  @if(can_do('reservations.create'))<form method="post" action="{{ route('reservations.waitlist.convert', $w) }}">@csrf<button class="btn btn-sm btn-primary" type="submit" aria-label="Convert {{ $waitlistGuestName }} to a reservation">Convert</button></form>@endif
                @endif
                @if($w->status === 'waiting' && can_do('reservations.edit'))<form method="post" action="{{ route('reservations.waitlist.cancel', $w) }}">@csrf<button class="btn btn-sm btn-quiet" type="submit" aria-label="Cancel waitlist entry for {{ $waitlistGuestName }}">Cancel</button></form>@endif
              </td>
            </tr>
          @endforeach
          </tbody>
        </table></div>
      @else
        <div class="waitlist-empty" role="status"><span class="waitlist-empty-mark" aria-hidden="true">⌕</span><div><strong>{{ request('q') || request('waitlist_status') || request('waitlist_focus') ? 'No entries match these filters' : 'The waitlist is clear' }}</strong><p>{{ request('q') ? 'Try another name, confirmation or room-type search.' : (request('waitlist_focus') === 'needs_type' ? 'Every waiting entry has a preferred room type.' : (request('waitlist_focus') === 'next_7_days' ? 'There are no waiting requests in this arrival window.' : 'New guest requests will appear here, ordered by priority and arrival.')) }}</p></div>@if(request('q') || request('waitlist_status') || request('waitlist_focus'))<a class="btn btn-sm" href="{{ route('reservations.index', ['view' => 'waitlist', 'date' => $date->toDateString()]) }}">Clear filters</a>@endif</div>
      @endif
    </section>
  @else
    <div class="card"><div class="card-body"><h3>Reservation desk</h3><p>Choose a desk view above.</p></div></div>
  @endif
  <p id="desk-toast" class="desk-toast" role="status" aria-live="polite" aria-atomic="true" hidden></p>
  <nav class="mobile-tabs" aria-label="Desk views">
    @foreach(['timeline' => 'Timeline', 'board' => 'Rooms', 'arrivals' => 'Arrivals', 'departures' => 'Depart', 'inhouse' => 'In house', 'waitlist' => 'Waitlist', 'groups' => 'Groups', 'noshow' => 'No-show', 'cancelled' => 'Cancelled', 'list' => 'List'] as $key => $label)
      <a href="{{ $go(['view' => $key]) }}" class="{{ $view === $key ? 'on' : '' }}">{{ $label }}</a>
    @endforeach
  </nav>
</div>
@endsection
@push('head')
<style>
.desk{--navy:#10271c;--day:52px;background:#fff;border:1px solid var(--c-border);border-radius:16px;overflow:hidden;box-shadow:var(--shadow)}
.desk-head{display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;padding:1.1rem 1.2rem;background:linear-gradient(120deg,#10271c,#173b2a);color:#fff}
.desk-head h1{color:#fff;font-size:1.45rem;margin:.15rem 0}
.desk-head p{margin:0;color:#c9d6ee;font-size:.85rem}
.desk-head .btn{background:#fff;color:var(--navy);border-color:transparent}
.kpi-strip{display:flex;flex-direction:column;gap:.45rem;padding:.8rem 1rem;background:#f7f9fc}
.kpi-group{display:grid;grid-template-columns:5.5rem 1fr;gap:.4rem;align-items:center}
.kpi-label{font-size:.68rem;letter-spacing:.08em;text-transform:uppercase;color:#5b6b86;font-weight:700}
.kpi-row{display:flex;gap:.4rem;overflow:auto;padding-bottom:.15rem}
.kpi{background:#fff;border:1px solid var(--c-border);border-radius:10px;padding:.4rem .55rem;min-width:6.2rem;color:inherit;text-decoration:none}
.kpi.on{border-color:#166534;box-shadow:inset 0 0 0 1px #166534}
.kpi span{display:block;font-size:.66rem;letter-spacing:.04em;text-transform:uppercase;color:var(--c-muted)}
.kpi strong{font-size:.98rem;color:var(--navy)}
.date-nav{display:flex;gap:.2rem;align-items:center}
.date-nav a{padding:.28rem .4rem;border:1px solid var(--c-border);border-radius:8px;background:#fff;color:inherit;text-decoration:none}
.mobile-tabs{display:none}
.desk-nav,.desk-views{display:flex;gap:.35rem;flex-wrap:wrap;align-items:center;padding:.55rem 1rem;border-top:1px solid var(--c-border)}
.desk-nav a,.desk-views a{padding:.28rem .6rem;border-radius:999px;color:var(--c-text-2);background:#f1f5f9;font-size:.82rem}
.desk-nav a.on,.desk-views a.on{background:var(--navy);color:#fff}
.desk-nav em{font-style:normal;opacity:.7}
.desk-tools{display:flex;gap:.45rem;flex-wrap:wrap;align-items:end;padding:.4rem 1rem .8rem}
.desk-tools label{display:flex;flex-direction:column;font-size:.68rem;color:var(--c-muted);gap:.15rem}
.desk-tools input,.desk-tools select{font:inherit;padding:.35rem .45rem;border:1px solid var(--c-border);border-radius:8px;background:#fff;color:inherit}
.desk-tools .grow{flex:1;min-width:180px}
.legend{padding:0 1rem .4rem}
.tape{overflow:auto;border-top:1px solid var(--c-border);max-height:70vh}
.tape-head,.room-row{display:grid;grid-template-columns:190px repeat(var(--days),minmax(var(--day),1fr));min-width:calc(190px + var(--days) * var(--day))}
.tape-head{position:sticky;top:0;background:#fff;z-index:2;font-size:.72rem;text-align:center;border-bottom:1px solid var(--c-border)}
.tape-head .today{background:#e8eefc;color:var(--navy);font-weight:700}
.tape-head small{display:block;color:var(--c-muted);font-weight:500}
.tape-group{padding:.35rem .8rem;background:#eef3fb;color:var(--navy);font-weight:700;font-size:.78rem;position:sticky;left:0}
.room-row{position:relative;min-height:46px;border-bottom:1px solid #eef2f7}
.room-row.drop{outline:2px solid #2563eb}
.room-label{display:flex;gap:.4rem;align-items:center;padding:.3rem .55rem;position:sticky;left:0;background:#fff;z-index:1;border-right:1px solid var(--c-border);color:inherit;text-decoration:none}
.room-label img,.type-code{width:34px;height:34px;border-radius:8px;object-fit:cover;background:#e8eef8;display:grid;place-items:center;font-size:.62rem;font-weight:700;color:#1e3a6b}
.room-label b{display:block;line-height:1.1}
.room-label small{display:block;color:var(--c-muted);font-size:.68rem;max-width:130px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.stay-bar,.outage-bar{margin:6px 2px;border-radius:8px;padding:.15rem .4rem;font-size:.72rem;line-height:1.15;overflow:hidden;z-index:1;text-decoration:none;color:#fff}
.stay-bar b,.stay-bar small{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.stay-bar small{opacity:.85}
.stay-bar.res{background:#1d4ed8}
.stay-bar.in{background:#15803d}
.stay-bar.due{background:#b45309}
.stay-bar.tent{background:#64748b;border:1px dashed #fff}
.stay-bar.out{background:#94a3b8}
.stay-bar.vip{box-shadow:inset 0 0 0 2px #f5c451}
.stay-bar.grp{box-shadow:inset 3px 0 0 #7c3aed}
.outage-bar{background:repeating-linear-gradient(45deg,#7f1d1d,#7f1d1d 6px,#991b1b 6px,#991b1b 12px);color:#fff}
.loose{display:flex;gap:.4rem;flex-wrap:wrap;align-items:center;padding:.4rem 1rem;background:#fff7ed}
.loose a{background:#fff;border:1px solid #fdba74;border-radius:999px;padding:.15rem .55rem;font-size:.78rem}
.board{padding:.8rem 1rem}
.board-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:.5rem;margin-bottom:1rem}
.board-card{display:flex;flex-direction:column;gap:.15rem;border:1px solid var(--c-border);border-radius:12px;padding:.55rem .65rem;color:inherit;text-decoration:none;background:#fff}
.board-card img{width:100%;height:64px;object-fit:cover;border-radius:8px}
.board-card.occupied{border-color:#86efac;background:#f0fdf4}
.board-card.out_of_order,.board-card.out_of_service{background:#fef2f2}
.board-card.dirty{box-shadow:inset 0 -3px 0 #f59e0b}
.board-card em{font-style:normal;font-size:.75rem;color:var(--c-text-2)}
.desk-form{margin:.8rem 0;padding-top:.6rem;border-top:1px solid var(--c-border)}
.desk-form h3{font-size:.9rem;margin-bottom:.35rem}
.desk-toast{position:fixed;bottom:1rem;left:50%;transform:translateX(-50%);background:#10271c;color:#fff;padding:.6rem 1rem;border-radius:10px;z-index:70}
.no-photo{width:96px;height:72px;border-radius:8px;background:#e8eef8;color:#1e3a6b;font-size:.68rem;display:grid;place-items:center;text-align:center;padding:.3rem}
.inline{display:inline}
.linkish{border:0;background:transparent;color:var(--c-muted);cursor:pointer}
.chip{font-size:.75rem}
.zoom{display:flex;align-items:center;gap:.3rem;font-size:.75rem;color:var(--c-muted)}
.desk.dark{background:#0e1a30;color:#e8eef8;border-color:#1e3a66}
.desk.dark .kpi-strip,.desk.dark .tape-head,.desk.dark .room-label,.desk.dark .board-card,.desk.dark .desk-tools input,.desk.dark .desk-tools select{background:#132443;color:#e8eef8;border-color:#1e3a66}
.desk.dark .kpi strong,.desk.dark .tape-group{color:#dbe7ff}
.desk.dark .desk-nav a,.desk.dark .desk-views a{background:#1a3158;color:#dbe7ff}
.desk.dark .desk-nav a.on,.desk.dark .desk-views a.on{background:#3b82f6;color:#fff}
.desk.dark .room-row{border-color:#1e3a66}
.desk.dark .tape-group,.desk.dark .tape-head .today{background:#1a3158;color:#dbe7ff}
.desk.dark .loose{background:#2a2116}
.desk.dark .loose a{background:#132443;color:#e8eef8;border-color:#1e3a66}
.desk.dark .board-card.occupied{background:#132f28;border-color:#166534}
.desk.dark .board-card.out_of_order,.desk.dark .board-card.out_of_service{background:#3a1d1d}
.desk.dark .no-photo{background:#1a3158;color:#dbe7ff}
@media(max-width:1100px){
  .desk-head{padding:.8rem}
  .desk-tools{flex-wrap:nowrap;overflow:auto}
  .desk .btn, .mobile-tabs a, .stay-bar { min-height: 40px; }
  .board-grid{grid-template-columns:repeat(auto-fill,minmax(128px,1fr))}
  .kpi{min-width:5.4rem}
}
@media(max-width:900px){
  .kpi-group{grid-template-columns:1fr}
  .room-label{width:140px}
  .tape-head,.room-row{grid-template-columns:140px repeat(var(--days),minmax(var(--day),1fr))}
  .desk{padding-bottom:3.2rem}
  .mobile-tabs{display:flex}
}
@media print{
  .sidebar,.topbar,.mobile-tabs,.desk-toast,.desk-head .btn,#print-btn{display:none !important}
  .main{margin:0}
  .desk{box-shadow:none;border:0}
}
@media print{
  .desk-head .btn,.desk-tools,.desk-views,.sidebar,.topbar{display:none!important}
  .desk{box-shadow:none;border:0}
}
.room-strip{display:grid;grid-template-columns:repeat(auto-fill,minmax(84px,1fr));gap:8px}
.room-thumb{position:relative;display:block;aspect-ratio:4/3;border-radius:8px;overflow:hidden;border:1px solid var(--c-border);background:#eef3fb;text-decoration:none}
.room-thumb img{width:100%;height:100%;object-fit:cover;display:block}
.room-thumb .type-code{width:100%;height:100%;border-radius:0}
.room-thumb .rt-no{position:absolute;bottom:0;left:0;right:0;background:rgba(15,23,42,.72);color:#fff;font-size:.66rem;padding:1px 5px;text-align:center}
.room-thumb.occupied{border-color:#dc2626}
.room-thumb.out_of_order,.room-thumb.out_of_service{opacity:.55}
.rt-mini{width:26px;height:26px;object-fit:cover;border-radius:5px;border:1px solid var(--c-border);vertical-align:-7px;margin-right:5px}
.desk.dark .room-thumb{background:#132443;border-color:#1e3a66}
.desk.dark .room-thumb .type-code{background:#1a3158;color:#dbe7ff}
.desk.dark .rt-mini{border-color:#1e3a66}

/* Reservation register — quiet, information-dense and quick to scan. */
#desk[data-view="list"]{background:transparent;border:0;box-shadow:none;overflow:visible}
.reservation-list-hero{position:relative;isolation:isolate;display:flex;justify-content:space-between;align-items:center;gap:24px;min-height:164px;margin:0 0 16px;padding:26px 30px;border:1px solid #174d37;border-radius:18px;overflow:hidden;color:#fff;background:linear-gradient(115deg,#123b2b 0%,#176342 58%,#23764e 100%);box-shadow:0 12px 30px rgba(16,58,40,.14)}
.reservation-list-hero:after{content:"";position:absolute;z-index:-1;width:310px;height:310px;right:16%;top:-195px;border:1px solid rgba(255,255,255,.12);border-radius:50%;box-shadow:0 0 0 35px rgba(255,255,255,.035),0 0 0 75px rgba(255,255,255,.025)}
.reservation-list-hero-copy{max-width:680px}
.reservation-list-eyebrow{display:flex;align-items:center;gap:8px;color:#c7ead5;font-size:.66rem;font-weight:800;letter-spacing:.14em}
.reservation-list-eyebrow span{opacity:.55}
.reservation-list-hero h2{margin:10px 0 6px;color:#fff;font-size:clamp(1.35rem,2.4vw,1.9rem);line-height:1.15;letter-spacing:-.035em}
.reservation-list-hero p{max-width:560px;margin:0;color:#d1e6d9;font-size:.9rem;line-height:1.55}
.reservation-list-hero-aside{display:flex;align-items:center;gap:18px;flex:none}
.reservation-list-date{display:flex;flex-direction:column;gap:3px;padding-left:18px;border-left:1px solid rgba(255,255,255,.22)}
.reservation-list-date span{color:#c7ead5;font-size:.62rem;font-weight:800;letter-spacing:.12em}
.reservation-list-date strong{font-size:.95rem;white-space:nowrap}
.reservation-list-date small{color:#d1e6d9;font-size:.72rem;white-space:nowrap}
.reservation-list-hero .btn{background:#fff;color:#14532d;border-color:#fff;min-height:40px}
.reservation-list-views{margin-bottom:14px;padding:6px 8px;border:1px solid var(--c-border);border-radius:12px;background:#fff;box-shadow:0 2px 8px rgba(18,43,31,.04)}
.reservation-list-views .desk-view-link{padding:7px 11px;background:transparent;color:#53665b;font-size:.78rem;font-weight:700;text-decoration:none}
.reservation-list-views .desk-view-link:hover{background:#f0f7f2;color:#14532d}
.reservation-list-views .desk-view-link.on{background:#e6f3e9;color:#14532d;box-shadow:inset 0 0 0 1px #c7e5ce}
.reservation-list-views .inline.flex{margin-left:auto;align-items:center}
.reservation-list-module{display:flex;flex-direction:column;gap:12px}
.reservation-status-tabs{display:flex;gap:4px;align-items:stretch;padding:5px;overflow-x:auto;border:1px solid var(--c-border);border-radius:14px;background:#fff;box-shadow:0 3px 12px rgba(18,43,31,.045);scrollbar-width:thin}
.reservation-status-tab{display:flex;align-items:center;gap:9px;min-height:42px;padding:0 12px;border:1px solid transparent;border-radius:10px;color:#58665f;text-decoration:none;white-space:nowrap;transition:background .15s ease,color .15s ease,border-color .15s ease}
.reservation-status-tab span{font-size:.78rem;font-weight:700}
.reservation-status-tab b{display:inline-grid;min-width:24px;height:22px;place-items:center;padding:0 6px;border-radius:7px;background:#f0f3f1;color:#5f6d64;font-size:.68rem;font-weight:800}
.reservation-status-tab:hover{background:#f5f9f6;color:#14532d}
.reservation-status-tab.is-active{border-color:#c6e1cd;background:#eaf5ed;color:#14532d}
.reservation-status-tab.is-active b{background:#d5ebda;color:#14532d}
.reservation-list-filter{display:grid;grid-template-columns:minmax(240px,1.65fr) repeat(4,minmax(120px,1fr));gap:11px 12px;align-items:end;padding:16px;border:1px solid var(--c-border);border-radius:14px;background:#fff;box-shadow:0 3px 12px rgba(18,43,31,.04)}
.reservation-list-filter>label{display:flex;min-width:0;flex-direction:column;gap:6px}
.reservation-list-filter .reservation-search-field{grid-column:span 2}
.reservation-filter-label{color:#5f6d64;font-size:.63rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase}
.reservation-list-filter input,.reservation-list-filter select{width:100%;min-width:0;height:40px;padding:0 10px;border:1px solid #dce5df;border-radius:9px;background:#fff;color:#1e3026;font:inherit;font-size:.79rem;outline:none;transition:border-color .15s ease,box-shadow .15s ease}
.reservation-list-filter input:focus,.reservation-list-filter select:focus{border-color:#2e8155;box-shadow:0 0 0 3px rgba(46,129,85,.13)}
.reservation-search-control{position:relative;display:flex;align-items:center}
.reservation-search-control>svg{position:absolute;left:11px;color:#738078;pointer-events:none}
.reservation-search-control input{padding-left:36px;padding-right:36px}
.reservation-search-control input::-webkit-search-cancel-button{display:none}
.reservation-search-clear{position:absolute;right:7px;display:grid;width:27px;height:27px;place-items:center;border:0;border-radius:7px;background:#eef3ef;color:#4b5d51;font-size:1.1rem;line-height:1;cursor:pointer}
.reservation-search-clear:hover{background:#dcebe0;color:#14532d}
.reservation-search-clear[hidden]{display:none}
.reservation-search-shortcut{position:absolute;right:10px;display:grid;min-width:21px;height:21px;place-items:center;border:1px solid #dce5df;border-radius:5px;background:#f7faf8;color:#68766e;font:inherit;font-size:.68rem;font-weight:700;line-height:1}
.reservation-search-shortcut[hidden]{display:none}
.reservation-filter-actions{display:flex;align-items:center;gap:6px;min-height:40px}
.reservation-filter-actions .btn{height:40px;white-space:nowrap}
.reservation-date-alert{margin:0;padding:10px 12px;border:1px solid #f1c0b8;border-radius:9px;background:#fff4f2;color:#9a3412;font-size:.78rem;font-weight:650}
.reservation-list-results{display:flex;justify-content:space-between;align-items:center;gap:12px;min-height:30px;padding:0 3px;color:#68766e;font-size:.78rem}
.reservation-list-results strong{color:#24372b;font-variant-numeric:tabular-nums}
.reservation-query-note{display:inline-flex;align-items:center;gap:6px;max-width:50%;overflow:hidden;color:#557262;text-overflow:ellipsis;white-space:nowrap}
.reservation-active-filters{display:flex;align-items:center;flex-wrap:wrap;gap:6px}
.reservation-active-label{margin-right:2px;color:#5f6d64;font-size:.65rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase}
.reservation-active-filters a{display:inline-flex;align-items:center;gap:7px;max-width:100%;padding:5px 8px;border:1px solid #dce8df;border-radius:999px;background:#f6faf7;color:#46604e;font-size:.68rem;text-decoration:none;overflow-wrap:anywhere}
.reservation-active-filters a:hover{border-color:#afd2b8;background:#edf6ef;color:#14532d}
.reservation-active-filters a b{color:#5f6d64;font-size:.9rem}
.reservation-active-filters .reservation-clear-filters{border-color:transparent;background:transparent;color:#25613d;font-weight:750}
.reservation-table-wrap{overflow:auto;border:1px solid var(--c-border);border-radius:14px;background:#fff;box-shadow:0 5px 18px rgba(18,43,31,.055)}
.reservation-table{width:100%;min-width:980px;border-collapse:separate;border-spacing:0}
.reservation-table thead th{padding:12px 13px;border-bottom:1px solid #e6ece8;background:#f7faf8;color:#5f6d64;font-size:.62rem;font-weight:800;letter-spacing:.09em;text-align:left;text-transform:uppercase;white-space:nowrap}
.reservation-table tbody td{padding:13px;border-bottom:1px solid #edf1ee;color:#283a2f;font-size:.79rem;vertical-align:middle}
.reservation-table tbody tr:last-child td{border-bottom:0}
.reservation-table tbody tr:not(.reservation-empty-row):hover td{background:#f8fbf9}
.reservation-number{color:#155e3b;font-size:.82rem;font-weight:800;text-decoration:none;white-space:nowrap}
.reservation-number:hover{text-decoration:underline;text-underline-offset:3px}
.reservation-number-stack{display:flex;min-width:0;flex-direction:column;gap:3px}
.reservation-created-at{color:#5f6d64;font-size:.63rem}
.reservation-row-tag{display:flex;align-items:center;gap:5px;margin-top:5px;color:#5f6d64;font-size:.68rem}
.reservation-row-tag .badge{font-size:.6rem}
.reservation-guest-name{display:flex;align-items:center;gap:6px;color:#20342a;font-weight:750;white-space:nowrap}
.reservation-guest-meta{display:flex;flex-wrap:wrap;gap:4px;margin-top:4px;color:#5f6d64;font-size:.68rem}
.reservation-guest-meta a{color:inherit;text-decoration:underline;text-decoration-color:#a7c3ae;text-underline-offset:2px}
.reservation-guest-meta a:hover{text-decoration-color:currentColor}
.reservation-stay-dates{display:flex;gap:9px;white-space:nowrap}
.reservation-stay-dates>span{display:flex;flex-direction:column;gap:2px;font-weight:700;font-variant-numeric:tabular-nums}
.reservation-stay-dates small{color:#5f6d64;font-size:.55rem;font-weight:800;letter-spacing:.08em}
.reservation-stay-dates abbr{text-decoration:none}
.reservation-night-count,.reservation-cell-note{display:block;margin-top:4px;color:#5f6d64;font-size:.66rem}
.reservation-room-tags{display:flex;flex-wrap:wrap;gap:4px}
.reservation-room-tags span{display:inline-flex;align-items:center;padding:3px 6px;border:1px solid #dce9df;border-radius:6px;background:#f4f9f5;color:#325b40;font-size:.66rem;font-weight:750;white-space:nowrap}
.reservation-room-tags .reservation-room-more{border-color:#e4eae5;background:#f6f7f6;color:#5f6d64}
.reservation-pax{display:block;color:#3c5144;font-size:.72rem;white-space:nowrap}
.reservation-pax b{color:#193b29;font-size:.9rem}
.reservation-pax-sub{display:block;margin-top:3px;color:#5f6d64;font-size:.66rem;white-space:nowrap}
.reservation-source{display:block;color:#42564a;font-weight:650;white-space:nowrap}
.reservation-row-actions{display:flex;justify-content:flex-end;gap:5px;white-space:nowrap}
.reservation-row-actions .btn{width:34px;height:34px;justify-content:center;padding:0;border-color:#dfe8e1;background:#fff;color:#42634e}
.reservation-row-actions .btn:hover{border-color:#b8d6c0;background:#eff7f1;color:#14532d}
.reservation-row-actions svg{width:15px;height:15px}
.reservation-empty-row td{padding:42px 16px!important}
.reservation-empty{display:flex;align-items:center;flex-direction:column;gap:8px;color:#5f6d64;text-align:center}
.reservation-empty-icon{display:grid;width:48px;height:48px;place-items:center;margin-bottom:4px;border:1px solid #dcebe0;border-radius:14px;background:#f2f8f4;color:#49805a}
.reservation-empty-icon svg{width:22px;height:22px}
.reservation-empty strong{color:#304338;font-size:.95rem}
.reservation-empty>span:not(.reservation-empty-icon){font-size:.77rem}
.reservation-empty .btn{margin-top:4px}
.reservation-pagination{padding:12px 14px;border-top:1px solid #edf1ee;background:#fff}
#desk[data-view="list"] .reservation-pagination nav{display:flex;justify-content:center}
.desk.dark[data-view="list"] .reservation-list-views,.desk.dark[data-view="list"] .reservation-status-tabs,.desk.dark[data-view="list"] .reservation-list-filter,.desk.dark[data-view="list"] .reservation-table-wrap,.desk.dark[data-view="list"] .reservation-pagination{border-color:#263b31;background:#13231a}
.desk.dark[data-view="list"] .reservation-list-views .desk-view-link{color:#c1d2c6}
.desk.dark[data-view="list"] .reservation-list-views .desk-view-link.on,.desk.dark[data-view="list"] .reservation-status-tab.is-active{border-color:#36774d;background:#1b3a29;color:#d7f0dd}
.desk.dark[data-view="list"] .reservation-status-tab{color:#bac8be}
.desk.dark[data-view="list"] .reservation-date-alert{border-color:#754238;background:#301e1a;color:#ffcabd}
.desk.dark[data-view="list"] .reservation-status-tab b,.desk.dark[data-view="list"] .reservation-search-clear{background:#20362a;color:#c0d1c4}
.desk.dark[data-view="list"] .reservation-list-filter input,.desk.dark[data-view="list"] .reservation-list-filter select,.desk.dark[data-view="list"] .reservation-row-actions .btn{border-color:#30483a;background:#102018;color:#e1eee4}
.desk.dark[data-view="list"] .reservation-table thead th{border-color:#30483a;background:#172a1f;color:#aebfb2}
.desk.dark[data-view="list"] .reservation-table tbody td{border-color:#263b31;color:#d5e2d8}
.desk.dark[data-view="list"] .reservation-table tbody tr:not(.reservation-empty-row):hover td{background:#192f22}
.desk.dark[data-view="list"] .reservation-number,.desk.dark[data-view="list"] .reservation-guest-name,.desk.dark[data-view="list"] .reservation-list-results strong,.desk.dark[data-view="list"] .reservation-empty strong{color:#d7f0dd}
.desk.dark[data-view="list"] .reservation-list-results,.desk.dark[data-view="list"] .reservation-query-note,.desk.dark[data-view="list"] .reservation-active-label,.desk.dark[data-view="list"] .reservation-active-filters a,.desk.dark[data-view="list"] .reservation-row-tag,.desk.dark[data-view="list"] .reservation-created-at,.desk.dark[data-view="list"] .reservation-guest-meta,.desk.dark[data-view="list"] .reservation-night-count,.desk.dark[data-view="list"] .reservation-cell-note,.desk.dark[data-view="list"] .reservation-pax-sub,.desk.dark[data-view="list"] .reservation-stay-dates small,.desk.dark[data-view="list"] .reservation-empty{color:#a9bdae}
.desk.dark[data-view="list"] .reservation-active-filters a{border-color:#365341;background:#1a3323}
.desk.dark[data-view="list"] .reservation-active-filters .reservation-clear-filters{border-color:transparent;background:transparent;color:#b8e2c4}
.desk.dark[data-view="list"] .reservation-table tbody td:before{color:#a0b3a4}
.desk.dark[data-view="list"] .reservation-empty-icon{border-color:#365341;background:#1a3323;color:#b8e2c4}
.desk.dark[data-view="list"] .reservation-room-tags span{border-color:#365341;background:#1a3323;color:#d7e9db}
.desk.dark[data-view="list"] .reservation-pax,.desk.dark[data-view="list"] .reservation-source{color:#c4d4c8}
.desk.dark[data-view="list"] .reservation-room-tags .reservation-room-more{background:#20362a;color:#c0d1c4}
.reservation-status-tab:focus-visible,.reservation-list-views .desk-view-link:focus-visible,.reservation-active-filters a:focus-visible,.reservation-guest-meta a:focus-visible,.reservation-row-actions .btn:focus-visible,.reservation-search-clear:focus-visible{outline:3px solid #77b989;outline-offset:2px;position:relative;z-index:2}
@media(max-width:1250px){.reservation-list-filter{grid-template-columns:repeat(4,minmax(130px,1fr))}.reservation-list-filter .reservation-search-field{grid-column:span 2}.reservation-filter-actions{grid-column:span 2}}
@media(max-width:850px){
  .reservation-list-hero{align-items:flex-start;flex-direction:column;padding:22px}
  .reservation-list-hero-aside{justify-content:space-between;width:100%}
  .reservation-list-date{padding-left:0;border-left:0}
  .reservation-list-views{flex-wrap:nowrap;overflow-x:auto;scrollbar-width:thin}
  .reservation-list-views .inline.flex{margin-left:0}
  .reservation-list-filter{grid-template-columns:repeat(2,minmax(0,1fr));padding:13px}
  .reservation-list-filter .reservation-search-field,.reservation-filter-actions{grid-column:1/-1}
}
@media(max-width:640px){
  #desk[data-view="list"]{padding-bottom:0}
  .reservation-list-hero{min-height:0;gap:17px;margin-bottom:11px;padding:19px;border-radius:14px}
  .reservation-list-hero h2{font-size:1.4rem}
  .reservation-list-hero p{font-size:.8rem}
  .reservation-list-hero-aside{align-items:center}
  .reservation-list-date strong{font-size:.82rem}
  .reservation-list-date small{font-size:.64rem}
  .reservation-status-tabs{border-radius:11px}
  .reservation-status-tab{min-height:38px;padding:0 9px}
  .reservation-status-tab span{font-size:.7rem}
  .reservation-status-tab b{min-width:21px;height:20px}
  .reservation-list-filter{gap:9px;padding:11px}
  .reservation-list-filter input,.reservation-list-filter select{height:38px;font-size:.75rem}
  .reservation-filter-actions .btn{flex:1;justify-content:center}
  .reservation-list-results{align-items:flex-start;flex-direction:column;gap:4px;padding:0 2px}
  .reservation-query-note{max-width:100%}
  .reservation-table{min-width:0}
  .reservation-table thead{display:none}
  .reservation-table,.reservation-table tbody{display:block;width:100%}
  .reservation-table tbody tr:not(.reservation-empty-row){display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:0 8px;padding:7px 10px;border-bottom:1px solid #e9efeb}
  .reservation-table tbody td{display:block;min-width:0;padding:9px 5px;border:0;font-size:.74rem}
  .reservation-number{white-space:normal;overflow-wrap:anywhere}
  .reservation-guest-meta,.reservation-source,.reservation-cell-note{overflow-wrap:anywhere}
  .reservation-source{white-space:normal}
  .reservation-stay-dates{flex-direction:column;gap:4px;white-space:normal}
  .reservation-room-tags span{max-width:100%;white-space:normal;overflow-wrap:anywhere}
  .reservation-table tbody td:before{display:block;margin-bottom:4px;color:#5f6d64;content:attr(data-label);font-size:.55rem;font-weight:800;letter-spacing:.09em;text-transform:uppercase}
  .reservation-table tbody td[data-label="Reservation"]{grid-column:1/-1;display:flex;align-items:center;justify-content:space-between;gap:8px}
  .reservation-table tbody td[data-label="Reservation"]:before{display:none}
  .reservation-table tbody td[data-label="Guest"]{grid-column:1/-1}
  .reservation-table tbody td[data-label="Actions"]{grid-column:1/-1;display:flex;justify-content:flex-end;padding-top:3px}
  .reservation-table tbody td[data-label="Actions"]:before{display:none}
  .reservation-guest-name{min-width:0;white-space:normal;overflow-wrap:anywhere}
  .reservation-row-tag,.reservation-row-tag>span:last-child{min-width:0;overflow-wrap:anywhere}
  .reservation-stay-dates{gap:7px;font-size:.7rem}
  .reservation-table tbody tr.reservation-empty-row{display:block;padding:0;border:0}
  .reservation-empty-row td{display:block;padding:34px 12px!important}
  .reservation-pagination{padding:10px 7px}
}
@media(max-width:390px){.reservation-list-filter{grid-template-columns:minmax(0,1fr)}.reservation-list-filter .reservation-search-field,.reservation-filter-actions{grid-column:auto}.reservation-list-hero-aside{align-items:flex-start;flex-direction:column}.reservation-list-hero .btn{width:100%;justify-content:center}}
@media(prefers-reduced-motion:reduce){.reservation-status-tab,.reservation-list-filter input,.reservation-list-filter select{transition:none}}
@media print{
  .page-head .actions,.reservation-status-tabs,.reservation-list-filter,.reservation-list-views,.reservation-list-hero .btn,.reservation-pagination,.reservation-row-actions{display:none!important}
  .reservation-list-hero{min-height:0;padding:10px 0;border:0;border-radius:0;background:#fff;color:#17251b;box-shadow:none}
  .reservation-list-hero h2,.reservation-list-date strong{color:#17251b}
  .reservation-list-hero p,.reservation-list-date small,.reservation-list-eyebrow,.reservation-list-date span{color:#526257}
  .reservation-list-hero:after{display:none}
  .reservation-table-wrap{overflow:visible;border:0;box-shadow:none}
  .reservation-table{min-width:0}
  #desk[data-view="list"] .reservation-table-wrap{background:#fff!important;color:#17251b!important}
  #desk[data-view="list"] .reservation-table thead th{border-color:#e6ece8!important;background:#f7faf8!important;color:#5f6d64!important}
  #desk[data-view="list"] .reservation-table tbody td{border-color:#edf1ee!important;background:#fff!important;color:#283a2f!important}
  #desk[data-view="list"] .reservation-number{color:#155e3b!important}
  #desk[data-view="list"] .reservation-guest-name{color:#20342a!important}
  #desk[data-view="list"] .reservation-list-results,#desk[data-view="list"] .reservation-active-label,#desk[data-view="list"] .reservation-created-at,#desk[data-view="list"] .reservation-guest-meta,#desk[data-view="list"] .reservation-row-tag,#desk[data-view="list"] .reservation-stay-dates small,#desk[data-view="list"] .reservation-night-count,#desk[data-view="list"] .reservation-cell-note,#desk[data-view="list"] .reservation-pax-sub,#desk[data-view="list"] .reservation-empty{color:#5f6d64!important}
  #desk[data-view="list"] .reservation-list-results strong{color:#24372b!important}
  #desk[data-view="list"] .reservation-pax,#desk[data-view="list"] .reservation-source{color:#42564a!important}
  #desk[data-view="list"] .reservation-active-filters a{border-color:#dce8df!important;background:#f6faf7!important;color:#46604e!important}
  #desk[data-view="list"] .reservation-room-tags span{border-color:#dce9df!important;background:#f4f9f5!important;color:#325b40!important}
}

/* Reservation desk dashboard: clear hierarchy, operational focus, forest-green brand. */
#desk[data-view="dashboard"]{overflow:visible;border:0;background:transparent;box-shadow:none}
#desk[data-view="dashboard"] .desk-dashboard-hero{position:relative;isolation:isolate;display:grid;grid-template-columns:minmax(220px,1fr) minmax(280px,1.1fr) auto;align-items:center;gap:22px;min-height:166px;margin-bottom:14px;padding:24px 26px;border:1px solid #164d37;border-radius:18px;overflow:visible;background:radial-gradient(circle at 75% 0%,rgba(148,213,168,.19),transparent 34%),linear-gradient(115deg,#102d21 0%,#145437 58%,#1b6842 100%);box-shadow:0 16px 34px rgba(16,58,40,.14)}
.desk-dashboard-hero:before{content:"";position:absolute;z-index:-1;right:22%;top:-125px;width:260px;height:260px;border:1px solid rgba(255,255,255,.11);border-radius:50%;box-shadow:0 0 0 32px rgba(255,255,255,.025),0 0 0 68px rgba(255,255,255,.018);pointer-events:none}
.desk-dashboard-hero-copy{min-width:0}
.desk-dashboard-eyebrow{display:flex;align-items:center;gap:8px;color:#c8ebd4;font-size:.64rem;font-weight:850;letter-spacing:.14em}
.desk-dashboard-eyebrow span{opacity:.6}
.desk-dashboard-hero h2{margin:9px 0 8px;color:#fff;font-size:clamp(1.4rem,2.2vw,1.9rem);line-height:1.1;letter-spacing:-.035em}
.desk-dashboard-date-line{display:flex;flex-wrap:wrap;gap:6px 12px;color:#d2e7d8;font-size:.73rem}
.desk-dashboard-date-line span+span{padding-left:11px;border-left:1px solid rgba(255,255,255,.25)}
.desk-dashboard-search{position:relative;z-index:1;padding:12px;border:1px solid rgba(255,255,255,.21);border-radius:13px;background:rgba(255,255,255,.09);backdrop-filter:blur(7px)}
.desk-dashboard-search label{display:block;margin:0 0 6px;color:#e0f0e5;font-size:.7rem;font-weight:750}
.desk-dashboard-search-control{display:flex;align-items:center;gap:7px;padding:4px 5px 4px 11px;border:1px solid rgba(255,255,255,.32);border-radius:10px;background:#fff;color:#20392c}
.desk-dashboard-search-control>svg{flex:none;color:#66776c}
.desk-dashboard-search-control input{width:100%;min-width:0;height:34px;border:0;outline:0;background:transparent;color:#20392c;font:inherit;font-size:.78rem}
.desk-dashboard-search-control input::placeholder{color:#63736a}
.desk-dashboard-search-control .btn{flex:none;min-height:34px;border-color:#dce9df;background:#edf6ef;color:#14532d}
.desk-dashboard-search-control:focus-within{outline:3px solid rgba(195,235,207,.42)}
.desk-dashboard-tools{position:relative;z-index:4;align-self:center}
.desk-dashboard-tools summary{display:flex;align-items:center;gap:7px;min-height:42px;padding:0 12px;border:1px solid rgba(255,255,255,.3);border-radius:10px;background:rgba(255,255,255,.12);color:#fff;font-size:.76rem;font-weight:750;white-space:nowrap;cursor:pointer;list-style:none}
.desk-dashboard-tools summary::-webkit-details-marker{display:none}
.desk-dashboard-tools summary:hover,.desk-dashboard-tools[open] summary{background:rgba(255,255,255,.2)}
.desk-dashboard-tools summary:focus-visible,.desk-dashboard-tool-menu a:focus-visible,.desk-dashboard-tool-menu button:focus-visible{outline:3px solid #b8e4c6;outline-offset:2px}
.desk-dashboard-tool-menu{position:absolute;top:calc(100% + 8px);right:0;display:grid;min-width:205px;max-height:65vh;overflow:auto;padding:6px;border:1px solid #dbe7de;border-radius:12px;background:#fff;box-shadow:0 14px 34px rgba(12,34,22,.2)}
.desk-dashboard-tool-menu a,.desk-dashboard-tool-menu button{display:block;padding:9px 10px;border:0;border-radius:8px;background:transparent;color:#274334;font:inherit;font-size:.77rem;text-align:left;text-decoration:none;cursor:pointer}
.desk-dashboard-tool-menu a:hover,.desk-dashboard-tool-menu button:hover{background:#edf6ef;color:#14532d}
#desk[data-view="dashboard"] .dashboard-kpi-strip{display:block;padding:0;background:transparent}
.desk-summary-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px}
.desk-summary-card{--stat-accent:#28734c;display:flex;min-width:0;min-height:122px;flex-direction:column;justify-content:space-between;gap:8px;padding:13px 14px;border:1px solid #e0e9e2;border-top:3px solid var(--stat-accent);border-radius:14px;background:#fff;color:#24392c;text-decoration:none;box-shadow:0 4px 12px rgba(18,43,31,.045);transition:transform .15s ease,box-shadow .15s ease,border-color .15s ease}
.desk-summary-card:hover{transform:translateY(-2px);border-color:#c6ddcb;box-shadow:0 10px 20px rgba(18,43,31,.09)}
.desk-summary-card:focus-visible{outline:3px solid #4b9a67;outline-offset:2px}
.desk-summary-card.tone-green{--stat-accent:#258153}.desk-summary-card.tone-amber{--stat-accent:#c17a19}.desk-summary-card.tone-blue{--stat-accent:#3b7183}.desk-summary-card.tone-forest{--stat-accent:#14532d}.desk-summary-card.tone-red{--stat-accent:#bd4c43}
.desk-summary-card-top{display:flex;align-items:center;justify-content:space-between;gap:8px;color:#66766b;font-size:.67rem;font-weight:800;letter-spacing:.055em;text-transform:uppercase}
.desk-summary-card-top svg{width:18px;height:18px;color:var(--stat-accent)}
.desk-summary-card>strong{color:#173d2a;font-size:1.8rem;line-height:1;font-variant-numeric:tabular-nums;letter-spacing:-.04em}
.desk-summary-card>small{color:#627267;font-size:.68rem;line-height:1.35}
.desk-metric-details{margin-top:10px;border:1px solid #e0e9e2;border-radius:13px;background:#fff}
.desk-metric-details summary{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:11px 14px;color:#284636;font-size:.76rem;font-weight:800;cursor:pointer;list-style:none}
.desk-metric-details summary::-webkit-details-marker{display:none}
.desk-metric-details summary:after{content:"+";display:grid;width:22px;height:22px;place-items:center;border-radius:7px;background:#eff6f0;color:#24603d;font-size:1rem}
.desk-metric-details[open] summary:after{content:"−"}
.desk-metric-details summary span{margin-left:auto;color:#65756a;font-size:.67rem;font-weight:550}
.desk-metric-details summary:focus-visible{outline:3px solid #4b9a67;outline-offset:2px}
.desk-metric-groups{display:grid;gap:9px;padding:0 13px 13px}
.desk-metric-groups .kpi-group{grid-template-columns:5.5rem minmax(0,1fr);padding-top:9px;border-top:1px solid #edf1ed}
.desk-metric-groups .kpi-row{scrollbar-width:thin}
#desk[data-view="dashboard"] .desk-dashboard-date-tools{display:flex;align-items:center;gap:12px;margin:2px 0 12px;padding:0 2px}
.desk-dashboard-date-tools label{flex-direction:row;align-items:center;gap:9px;color:#52645a;font-size:.72rem;font-weight:750}
.desk-dashboard-date-tools .date-nav{gap:5px}
.desk-dashboard-date-tools .date-nav a{display:inline-grid;min-width:34px;height:34px;place-items:center;border-color:#e0e9e2;background:#fff;color:#365343;font-weight:700}
.desk-dashboard-date-tools .date-nav a:hover{border-color:#a9ccb2;background:#f2f8f3;color:#14532d}
.desk-dashboard-date-tools input[type="date"]{min-height:34px;border-color:#dce7de;border-radius:9px;background:#fff;color:#274334}
#desk[data-view="dashboard"]>.desk-views{gap:5px;margin:0 0 14px;padding:7px;border:1px solid #e0e9e2;border-radius:13px;background:#fff;box-shadow:0 3px 10px rgba(18,43,31,.035)}
#desk[data-view="dashboard"]>.desk-views .desk-view-link{padding:7px 10px;border-radius:8px;background:transparent;color:#53665b;font-size:.74rem;font-weight:750;text-decoration:none}
#desk[data-view="dashboard"]>.desk-views .desk-view-link:hover{background:#f1f7f2;color:#14532d}
#desk[data-view="dashboard"]>.desk-views .desk-view-link.on{background:#e7f3ea;color:#14532d;box-shadow:inset 0 0 0 1px #c7e5ce}
.desk-dashboard{display:grid;gap:14px}
.desk-priority-board{padding:14px;border:1px solid #dfe9e1;border-radius:15px;background:linear-gradient(115deg,#f8fbf8,#fff 70%);box-shadow:0 5px 16px rgba(18,43,31,.04)}
.desk-priority-heading{display:flex;align-items:center;justify-content:space-between;gap:1rem;margin:0 2px 10px}
.desk-priority-heading h3{margin:0;color:#203b2b;font-size:.94rem;letter-spacing:-.015em}
.desk-priority-date{color:#617268;font-size:.68rem;font-weight:700;white-space:nowrap}
.desk-priority-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(195px,1fr));gap:8px}
.desk-priority-card{--priority:#2a7048;--priority-tint:#eff7f1;display:grid;grid-template-columns:34px minmax(0,1fr);grid-template-rows:auto auto;align-items:center;gap:2px 9px;min-width:0;min-height:94px;padding:10px 11px;border:1px solid #e0e9e2;border-left:3px solid var(--priority);border-radius:11px;background:#fff;color:#24392c;text-decoration:none;transition:transform .15s ease,box-shadow .15s ease,border-color .15s ease}
.desk-priority-card:hover{transform:translateY(-1px);border-color:#c6ddcb;box-shadow:0 7px 15px rgba(18,43,31,.08);text-decoration:none}
.desk-priority-card:focus-visible{outline:3px solid #4b9a67;outline-offset:2px}
.desk-priority-card.is-ready{--priority:#258153;--priority-tint:#edf8f0}.desk-priority-card.is-attention{--priority:#b87917;--priority-tint:#fff7e8}.desk-priority-card.is-urgent{--priority:#b8443b;--priority-tint:#fff0ed}.desk-priority-card.is-clear{--priority:#587565;--priority-tint:#f1f6f2}
.desk-priority-icon{display:grid;grid-row:1/3;place-items:center;width:34px;height:34px;border:1px solid color-mix(in srgb,var(--priority) 22%,white);border-radius:10px;background:var(--priority-tint);color:var(--priority)}
.desk-priority-icon svg{width:17px;height:17px}
.desk-priority-copy{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:1px 6px;min-width:0}
.desk-priority-label{overflow:hidden;color:#405348;font-size:.65rem;font-weight:800;line-height:1.25;text-overflow:ellipsis}
.desk-priority-copy strong{color:var(--priority);font-size:1.25rem;line-height:1;font-variant-numeric:tabular-nums}
.desk-priority-copy small{grid-column:1/-1;display:-webkit-box;overflow:hidden;color:#68786d;font-size:.61rem;line-height:1.35;-webkit-box-orient:vertical;-webkit-line-clamp:2}
.desk-priority-open{grid-column:2;display:flex;align-items:center;justify-self:end;gap:2px;color:var(--priority);font-size:.61rem;font-weight:800}
.desk-priority-open svg{width:13px;height:13px}
.desk-dashboard .card{margin:0;border-color:#e2e9e3;border-radius:15px;box-shadow:0 5px 16px rgba(18,43,31,.045)}
.desk-dashboard .card-head{min-height:58px;padding:12px 16px;border-bottom-color:#edf1ed}
.desk-dashboard .card-head h3{color:#233c2d;font-size:.9rem;letter-spacing:-.01em}
.desk-dashboard .card-body{padding:14px 16px}
.desk-dashboard .grid.g-2{align-items:start;gap:14px}
.desk-section-eyebrow{display:block;margin-bottom:3px;color:#4f7458;font-size:.57rem;font-weight:850;letter-spacing:.13em}
.desk-dashboard-room-panel .card-head{align-items:end}
.room-strip-legend{display:flex;align-items:center;flex-wrap:wrap;gap:8px 14px;margin:0 0 10px;color:#69796e;font-size:.65rem}
.room-strip-legend>span{display:inline-flex;align-items:center;gap:5px}
.room-status-dot{width:7px;height:7px;border-radius:50%;background:#45966a}.room-status-dot.occupied{background:#d0655b}.room-status-dot.out{background:#8b6b9e}
.desk-dashboard .room-strip{display:flex;gap:8px;overflow-x:auto;padding:1px 2px 8px;scrollbar-width:thin;scroll-snap-type:x proximity}
.desk-dashboard .room-thumb{position:relative;flex:0 0 100px;aspect-ratio:1.18;border-color:#dfe8e1;border-radius:11px;background:#f1f6f2;scroll-snap-align:start;transition:transform .15s ease,box-shadow .15s ease}
.desk-dashboard .room-thumb:hover{z-index:1;transform:translateY(-2px);box-shadow:0 8px 16px rgba(18,43,31,.15)}
.desk-dashboard .room-thumb:focus-visible{outline:3px solid #4b9a67;outline-offset:2px}
.desk-dashboard .room-thumb.occupied{border-color:#e6b8b3}
.desk-dashboard .room-thumb.out_of_order,.desk-dashboard .room-thumb.out_of_service{border-color:#c9bdd0}
.desk-room-empty{margin:4px 0;color:#65756a;font-size:.74rem}
.room-status-label{position:absolute;top:5px;left:5px;max-width:calc(100% - 10px);overflow:hidden;padding:3px 5px;border-radius:5px;background:rgba(26,70,45,.9);color:#fff;font-size:.5rem;font-weight:800;text-overflow:ellipsis;text-transform:uppercase;white-space:nowrap}
.room-thumb.occupied .room-status-label{background:rgba(137,48,42,.92)}
.room-thumb.out_of_order .room-status-label,.room-thumb.out_of_service .room-status-label{background:rgba(79,58,91,.92)}
.desk-dashboard .room-thumb .rt-no{display:flex;align-items:center;justify-content:center;gap:4px;padding:4px;background:rgba(19,43,30,.88);font-size:.68rem;font-weight:800}
.desk-dashboard .room-thumb .rt-no small{color:#d8e9dc;font-size:.52rem;font-weight:600}
.desk-dashboard .bar-chart{gap:4px;height:150px;padding:10px 4px 0;border-bottom:1px solid #e9efea}
.desk-dashboard .bar-chart .col{min-width:0}
.desk-dashboard .bar-chart .col small{color:#65756a;font-size:.58rem}
.desk-dashboard .bar-chart .col .b{min-width:4px;border-radius:5px 5px 1px 1px;box-shadow:0 2px 4px rgba(19,71,43,.11)}
.desk-dashboard .list li{padding:.65rem 0;border-bottom-color:#edf1ed;font-size:.79rem}
.desk-dashboard .list li>div{min-width:0}
.desk-dashboard .list li .tiny{line-height:1.45}
.desk-dashboard .list li .btn{flex:none}
.desk-dashboard .table{font-size:.75rem}
.desk-dashboard .table thead th{background:#f6f9f6;color:#53655a;font-size:.62rem;letter-spacing:.07em;text-transform:uppercase}
.desk-dashboard .table tbody tr:hover td{background:#f7faf7}
.desk-dashboard .table-wrap{border-radius:10px}
.desk-dashboard .badge{white-space:nowrap}
.desk-dashboard .card-head>a{color:#267246;font-size:.72rem;font-weight:750;text-decoration:none}
.desk-dashboard .card-head>a:hover{text-decoration:underline;text-underline-offset:3px}
.desk-dashboard .list>li.muted{padding:12px 0;color:#65756a;font-size:.74rem}
.desk-dashboard .desk-chart-legend{display:flex;flex-wrap:wrap;gap:10px;color:#637469;font-size:.66rem}
.desk-dashboard .desk-chart-legend span{display:inline-flex;align-items:center;gap:5px}
.desk-dashboard .desk-chart-legend i{width:8px;height:8px;border-radius:2px;background:#258153}
.desk-dashboard .desk-chart-legend .departures{background:#c17a19}
.desk-flow-chart{margin-top:14px}
.desk-flow-chart-heading{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
.desk-flow-bars{display:flex;align-items:flex-end;gap:3px;width:100%;height:100%}
.desk-flow-bars .b{flex:1;max-width:none}
.desk-flow-bars .b.arrivals{background:linear-gradient(180deg,#49a86f,#227349)}
.desk-flow-bars .b.departures{background:linear-gradient(180deg,#e8b04f,#b77419)}
.desk.dark[data-view="dashboard"]{background:transparent;color:#e2ece4}
.desk.dark[data-view="dashboard"] .desk-priority-board{border-color:#294333;background:linear-gradient(115deg,#14251a,#13231a 70%)}
.desk.dark[data-view="dashboard"] .desk-priority-heading h3{color:#dceade}
.desk.dark[data-view="dashboard"] .desk-priority-date{color:#adbdaf}
.desk.dark[data-view="dashboard"] .desk-priority-card{border-color:#294333;background:#172a1d;color:#dceade}
.desk.dark[data-view="dashboard"] .desk-priority-card:hover{border-color:#416c4c}
.desk.dark[data-view="dashboard"] .desk-priority-label{color:#c6d7c9}
.desk.dark[data-view="dashboard"] .desk-priority-copy small{color:#adbdaf}
.desk.dark[data-view="dashboard"] .desk-priority-card.is-ready{--priority:#7bc592;--priority-tint:#1c3925}
.desk.dark[data-view="dashboard"] .desk-priority-card.is-attention{--priority:#e4b65d;--priority-tint:#3b301d}
.desk.dark[data-view="dashboard"] .desk-priority-card.is-urgent{--priority:#e68b81;--priority-tint:#402523}
.desk.dark[data-view="dashboard"] .desk-priority-card.is-clear{--priority:#a6c5b0;--priority-tint:#1c3023}
.desk.dark[data-view="dashboard"] .desk-summary-card,.desk.dark[data-view="dashboard"] .desk-metric-details,.desk.dark[data-view="dashboard"]>.desk-views{border-color:#294333;background:#14251a}
.desk.dark[data-view="dashboard"] .desk-summary-card-top,.desk.dark[data-view="dashboard"] .desk-summary-card>small,.desk.dark[data-view="dashboard"] .desk-metric-details summary span{color:#adbdaf}
.desk.dark[data-view="dashboard"] .desk-summary-card>strong,.desk.dark[data-view="dashboard"] .desk-metric-details summary{color:#dbecdf}
.desk.dark[data-view="dashboard"] .desk-metric-groups .kpi-group{border-color:#294333}
.desk.dark[data-view="dashboard"]>.desk-views .desk-view-link{color:#bfd0c2}
.desk.dark[data-view="dashboard"]>.desk-views .desk-view-link.on{background:#21432c;color:#d8f0dd}
.desk.dark[data-view="dashboard"] .desk-dashboard-date-tools label{color:#bfd0c2}
.desk.dark[data-view="dashboard"] .desk-dashboard-date-tools .date-nav a,.desk.dark[data-view="dashboard"] .desk-dashboard-date-tools input[type="date"]{border-color:#304b38;background:#14251a;color:#dceade}
.desk.dark[data-view="dashboard"] .desk-dashboard .card{border-color:#294333;background:#13231a}
.desk.dark[data-view="dashboard"] .desk-dashboard .card-head{border-color:#294333}
.desk.dark[data-view="dashboard"] .desk-dashboard .card-head h3{color:#dceade}
.desk.dark[data-view="dashboard"] .desk-dashboard .room-thumb{border-color:#365341;background:#1a3323}
.desk.dark[data-view="dashboard"] .desk-dashboard .table thead th{border-color:#294333;background:#1a2e21;color:#bed0c2}
.desk.dark[data-view="dashboard"] .desk-dashboard .table tbody td{border-color:#294333;color:#d3e1d5}
.desk.dark[data-view="dashboard"] .desk-dashboard .bar-chart{border-color:#294333}
.desk.dark[data-view="dashboard"] .desk-dashboard .bar-chart .col small,.desk.dark[data-view="dashboard"] .desk-chart-legend,.desk.dark[data-view="dashboard"] .room-strip-legend,.desk.dark[data-view="dashboard"] .desk-room-empty{color:#adbdaf}
.desk.dark[data-view="dashboard"] .desk-section-eyebrow{color:#a8cdb2}
@media(max-width:1180px){
  #desk[data-view="dashboard"] .desk-dashboard-hero{grid-template-columns:minmax(220px,1fr) minmax(240px,1fr);}
  .desk-dashboard-tools{grid-column:2;justify-self:end}
  .desk-summary-grid{grid-template-columns:repeat(3,minmax(0,1fr))}
}
@media(max-width:760px){
  #desk[data-view="dashboard"] .desk-dashboard-hero{grid-template-columns:1fr;gap:14px;padding:18px}
  .desk-dashboard-tools{grid-column:auto;justify-self:start}
  .desk-dashboard-tool-menu{right:auto;left:0}
  .desk-dashboard-date-tools{align-items:flex-start!important;flex-direction:column}
  .desk-summary-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
  .desk-summary-card{min-height:108px;padding:11px}
  .desk-summary-card>strong{font-size:1.6rem}
  .desk-metric-groups .kpi-group{grid-template-columns:1fr;gap:5px}
  .desk-dashboard .grid.g-2{grid-template-columns:minmax(0,1fr)}
  .desk-dashboard .card-head{align-items:flex-start}
}
@media(max-width:420px){
  .desk-summary-grid{grid-template-columns:minmax(0,1fr)}
  .desk-summary-card{min-height:96px}
  .desk-dashboard-date-line{gap:4px 8px;font-size:.67rem}
  .desk-dashboard-date-line span+span{padding-left:8px}
  .desk-metric-details summary{flex-wrap:wrap}
  .desk-metric-details summary span{width:100%;margin-left:0}
  .desk-dashboard .room-thumb{flex-basis:84px}
}
@media(prefers-reduced-motion:reduce){.desk-summary-card,.desk-dashboard .room-thumb{transition:none}}
@media print{
  #desk[data-view="dashboard"] .desk-dashboard-hero{min-height:0;padding:10px;border:0;border-radius:0;background:#fff;color:#17251b;box-shadow:none}
  #desk[data-view="dashboard"] .desk-dashboard-eyebrow,#desk[data-view="dashboard"] .desk-dashboard-date-line{color:#526257}
  #desk[data-view="dashboard"] .desk-dashboard-hero h2{color:#17251b}
  #desk[data-view="dashboard"] .desk-dashboard-search,#desk[data-view="dashboard"] .desk-dashboard-tools,.dashboard-kpi-strip .desk-metric-details,#desk[data-view="dashboard"]>.desk-views,#desk[data-view="dashboard"] .desk-dashboard-date-tools{display:none!important}
  #desk[data-view="dashboard"] .desk-summary-card,#desk[data-view="dashboard"] .desk-dashboard .card{break-inside:avoid;box-shadow:none}
}
/* Hotel timeline: a focused tape chart, purpose-built for front-desk scanning. */
#desk[data-view="timeline"]{--room-col:224px;display:flex;flex-direction:column;gap:12px;overflow:visible;border:0;border-radius:0;background:transparent;box-shadow:none}
.timeline-hero{position:relative;isolation:isolate;display:flex;justify-content:space-between;align-items:center;gap:28px;min-height:178px;padding:24px 28px;border:1px solid #174d37;border-radius:20px;overflow:hidden;color:#fff;background:linear-gradient(118deg,#103a29 0%,#15593b 60%,#26764d 100%);box-shadow:0 14px 32px rgba(14,58,39,.15)}
.timeline-hero:after{position:absolute;z-index:-1;top:-220px;right:12%;width:430px;height:430px;border:1px solid rgba(255,255,255,.14);border-radius:50%;box-shadow:0 0 0 45px rgba(255,255,255,.035),0 0 0 95px rgba(255,255,255,.025);content:""}
.timeline-hero-copy{position:relative;z-index:1;min-width:0;max-width:660px}
.timeline-eyebrow{display:flex;align-items:center;gap:8px;color:#c9ead4;font-size:.64rem;font-weight:850;letter-spacing:.14em}
.timeline-eyebrow i{font-style:normal;opacity:.55}
.timeline-hero h2{margin:9px 0 5px;color:#fff;font-size:clamp(1.45rem,2.5vw,2rem);line-height:1.1;letter-spacing:-.035em}
.timeline-hero-copy>p{margin:0;color:#d2e7d8;font-size:.84rem}
.timeline-range-line{display:flex;align-items:center;flex-wrap:wrap;gap:7px 14px;margin-top:13px;color:#d2e7d8;font-size:.7rem}
.timeline-range-line strong{color:#fff;font-size:.8rem}
.timeline-range-line span+span{padding-left:13px;border-left:1px solid rgba(255,255,255,.25)}
.timeline-hero-actions{display:flex;gap:7px;flex-wrap:wrap;margin-top:13px}
.timeline-hero-actions .btn{min-height:36px;border-color:rgba(255,255,255,.28);background:rgba(255,255,255,.1);color:#fff;font-size:.72rem}
.timeline-hero-actions .btn:hover{background:rgba(255,255,255,.2)}
.timeline-hero-metrics{position:relative;z-index:1;display:grid;grid-template-columns:repeat(5,minmax(68px,1fr));min-width:min(500px,46%);border:1px solid rgba(255,255,255,.2);border-radius:15px;background:rgba(255,255,255,.075);backdrop-filter:blur(8px)}
.timeline-hero-metrics>div{display:flex;flex-direction:column;gap:2px;padding:14px 13px}
.timeline-hero-metrics>div+div{border-left:1px solid rgba(255,255,255,.19)}
.timeline-hero-metrics span{color:#c7e3cf;font-size:.58rem;font-weight:800;letter-spacing:.09em;text-transform:uppercase}
.timeline-hero-metrics strong{color:#fff;font-size:1.35rem;font-variant-numeric:tabular-nums;line-height:1.15}
.timeline-hero-metrics small{color:#d1e6d7;font-size:.62rem}
#desk[data-view="timeline"]>.desk-nav{display:flex;gap:5px;flex-wrap:nowrap;overflow-x:auto;padding:7px;border:1px solid #e1e9e3;border-radius:14px;background:#fff;box-shadow:0 3px 12px rgba(18,43,31,.04);scrollbar-width:thin}
#desk[data-view="timeline"]>.desk-nav a{display:inline-flex;align-items:center;gap:6px;min-height:34px;padding:0 10px;border-radius:9px;background:transparent;color:#59685e;font-size:.72rem;font-weight:700;text-decoration:none;white-space:nowrap}
#desk[data-view="timeline"]>.desk-nav a:hover{background:#f0f7f2;color:#14532d}
#desk[data-view="timeline"]>.desk-nav a.on{background:#e8f4eb;color:#14532d;box-shadow:inset 0 0 0 1px #cce3d1}
#desk[data-view="timeline"]>.desk-nav em{display:inline-grid;min-width:20px;height:19px;place-items:center;padding:0 5px;border-radius:6px;background:#f1f4f1;color:#5d6b61;font-size:.62rem;font-variant-numeric:tabular-nums;opacity:1}
#desk[data-view="timeline"]>.desk-views{display:flex;gap:5px;flex-wrap:wrap;align-items:center;padding:7px 9px;border:1px solid #e1e9e3;border-radius:14px;background:#fff}
#desk[data-view="timeline"]>.desk-views .desk-view-link{padding:6px 10px;border-radius:8px;background:transparent;color:#5a6b60;font-size:.73rem;font-weight:750;text-decoration:none}
#desk[data-view="timeline"]>.desk-views .desk-view-link:hover{background:#f2f7f3;color:#14532d}
#desk[data-view="timeline"]>.desk-views .desk-view-link.on{background:#14532d;color:#fff}
#desk[data-view="timeline"]>.desk-views .zoom{margin-left:auto;color:#5b6b60;font-weight:700}
#desk[data-view="timeline"]>.desk-views .zoom input{accent-color:#166534}
#desk[data-view="timeline"]>.desk-views form{align-items:center;margin-left:auto}
#desk[data-view="timeline"]>.desk-views form input[name="name"]{height:30px;border:1px solid #dce5df;border-radius:7px;padding:0 8px}
#desk[data-view="timeline"]>.desk-views form .btn{min-height:30px;padding:0 9px}
#desk[data-view="timeline"] .desk-tools{display:grid;grid-template-columns:repeat(6,minmax(125px,1fr));gap:10px;align-items:end;margin:0;padding:13px;border:1px solid #e1e9e3;border-radius:15px;background:#fff;box-shadow:0 3px 12px rgba(18,43,31,.04)}
#desk[data-view="timeline"] .desk-tools label{min-width:0;gap:5px;color:#52645a;font-size:.62rem;font-weight:800;letter-spacing:.065em;text-transform:uppercase}
#desk[data-view="timeline"] .desk-tools input,#desk[data-view="timeline"] .desk-tools select{width:100%;min-width:0;height:36px;padding:0 8px;border:1px solid #dce5df;border-radius:8px;background:#fff;color:#263a2d;font-size:.74rem;letter-spacing:0}
#desk[data-view="timeline"] .desk-tools input:focus,#desk[data-view="timeline"] .desk-tools select:focus{border-color:#39865a;outline:3px solid rgba(57,134,90,.13)}
#desk[data-view="timeline"] .desk-tools .grow{grid-column:span 2;min-width:0}
#desk[data-view="timeline"] .desk-tools .date-nav{display:grid;grid-template-columns:30px minmax(118px,1fr) 30px auto;gap:4px;align-items:center}
#desk[data-view="timeline"] .desk-tools .date-nav a{display:grid;min-height:34px;place-items:center;padding:0 8px;border-color:#dce5df;border-radius:8px;color:#45614e;font-size:.7rem;font-weight:750;white-space:nowrap}
#desk[data-view="timeline"] .desk-tools .date-nav input{padding:0 5px;font-size:.68rem}
#desk[data-view="timeline"] .desk-tools>.btn{min-height:36px;align-self:end}
.timeline-guide{display:grid;grid-template-columns:minmax(210px,.8fr) minmax(350px,2fr);gap:9px 16px;align-items:center;padding:11px 14px;border:1px solid #e1e9e3;border-radius:14px;background:#fff}
.timeline-guide-copy{display:flex;flex-direction:column;gap:3px;min-width:0}
.timeline-guide-copy strong{color:#274334;font-size:.72rem}
.timeline-guide-copy span,.timeline-guide-note{color:#647269;font-size:.65rem;line-height:1.45}
.timeline-legend{display:flex;align-items:center;justify-content:flex-end;flex-wrap:wrap;gap:7px 13px;margin:0;padding:0;list-style:none}
.timeline-legend li{display:inline-flex;align-items:center;gap:6px;color:#53645a;font-size:.65rem;white-space:nowrap}
.legend-swatch{display:inline-block;width:10px;height:10px;border-radius:3px;background:#166534}
.legend-swatch.is-tentative{border:1px dashed #64748b;background:#94a3b8}
.legend-swatch.is-inhouse{background:#0f766e}
.legend-swatch.is-due{background:#b45309}
.legend-swatch.is-checkedout{background:#7b8790}
.legend-swatch.is-outage{background:repeating-linear-gradient(135deg,#7b2826,#7b2826 3px,#963c36 3px,#963c36 6px)}
.legend-swatch.is-vip{border:2px solid #c08b16;background:#fff7d6}
.legend-swatch.is-group{border-left:3px solid #7c3aed;background:#e7ddff}
.timeline-guide-note{grid-column:1/-1;padding-top:7px;border-top:1px solid #eef2ef}
.timeline-edge-key{display:inline-grid;min-width:23px;height:16px;place-items:center;padding:0 3px;border:1px solid #a8cfb2;border-radius:4px;background:#eaf5ed;color:#235d36;font-size:.47rem;font-style:normal;font-weight:900;letter-spacing:.02em}
.timeline-edge-key.is-out{border-color:#ecc47a;background:#fff4dd;color:#8a5811}
.timeline-deposit-key{display:inline-grid;min-width:23px;height:16px;place-items:center;padding:0 3px;border:1px solid #cad8cd;border-radius:4px;background:#f4f8f5;color:#506357;font-size:.47rem;font-style:normal;font-weight:850}
#desk[data-view="timeline"] .timeline-edge{grid-row:1;z-index:3;display:flex;align-self:start;justify-self:end;align-items:center;margin:3px 3px 0 0;padding:2px 3px;border:1px solid #add0b6;border-radius:4px;background:#eff8f1;color:#245e37;font-size:.46rem;font-weight:900;line-height:1;pointer-events:none}
#desk[data-view="timeline"] .timeline-edge.is-out{border-color:#ebc274;background:#fff4dc;color:#8b5711}
#desk[data-view="timeline"] .loose{display:flex;flex-direction:column;align-items:stretch;gap:9px;margin:0;padding:12px 14px;border:1px solid #f0d5ad;border-radius:14px;background:#fff9ee}
.timeline-loose-heading{display:flex;justify-content:space-between;align-items:center;gap:12px}
.timeline-loose-heading>div{display:flex;flex-direction:column;gap:2px}
.timeline-loose-heading strong{color:#874b13;font-size:.76rem}
.timeline-loose-heading span{color:#80613a;font-size:.64rem}
.timeline-loose-count{display:grid;min-width:29px;height:27px;place-items:center;padding:0 7px;border-radius:8px;background:#f6e5c8;color:#7b4b16;font-size:.72rem;font-variant-numeric:tabular-nums}
.timeline-loose-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(205px,1fr));gap:7px}
#desk[data-view="timeline"] .timeline-loose-link{display:grid;grid-template-columns:auto minmax(0,1fr);gap:2px 8px;align-items:center;min-width:0;min-height:52px;padding:7px 9px;border:1px solid #edd8b5;border-radius:9px;background:#fff;color:#483a27;text-decoration:none}
#desk[data-view="timeline"] .timeline-loose-link:hover{border-color:#d39a4f;background:#fff5e5}
#desk[data-view="timeline"] .timeline-loose-link[draggable="true"]{cursor:grab}
#desk[data-view="timeline"] .timeline-loose-link[draggable="true"]:active{cursor:grabbing}
#desk[data-view="timeline"] .timeline-loose-link.is-dragging{opacity:.5}
.timeline-loose-link b{color:#7a501f;font-size:.61rem;font-variant-numeric:tabular-nums}
.timeline-loose-link span{overflow:hidden;color:#443a2d;font-size:.68rem;font-weight:700;text-overflow:ellipsis;white-space:nowrap}
.timeline-loose-link small{grid-column:1/-1;color:#796c58;font-size:.59rem}
.timeline-loose-more{padding-top:8px;border-top:1px solid #f0dfc4}
.timeline-loose-more summary{color:#7b4b16;font-size:.67rem;font-weight:750;cursor:pointer}
.timeline-loose-more .timeline-loose-list{margin-top:8px}
#desk[data-view="timeline"] .timeline-empty-state{display:flex;align-items:center;gap:13px;margin:12px;padding:20px;border:1px dashed #c9d9cd;border-radius:14px;background:#f8fbf8}
#desk[data-view="timeline"] .timeline-empty-icon{display:grid;flex:none;width:42px;height:42px;place-items:center;border-radius:12px;background:#e9f3eb;color:#24633a}
#desk[data-view="timeline"] .timeline-empty-state strong{color:#274334;font-size:.85rem}
#desk[data-view="timeline"] .timeline-empty-state p{margin:3px 0 0;color:#5f6d64;font-size:.72rem}
#desk[data-view="timeline"] .timeline-empty-state .btn{margin-left:auto;border-color:#d2dfd5;background:#fff;color:#28583a}
#desk[data-view="timeline"] .tape{--room-col:224px;max-height:min(70vh,780px);min-height:380px;overflow:auto;border:1px solid #dce6df;border-radius:16px;background:#fff;box-shadow:0 5px 18px rgba(24,55,37,.055);overscroll-behavior:contain;scrollbar-color:#b6c9bb #f4f7f4;scrollbar-width:thin}
#desk[data-view="timeline"] .tape:focus-visible{outline:3px solid #39865a;outline-offset:3px}
#desk[data-view="timeline"] .tape-head,#desk[data-view="timeline"] .room-row{grid-template-columns:var(--room-col) repeat(var(--days),minmax(var(--day),1fr));min-width:calc(var(--room-col) + var(--days) * var(--day))}
#desk[data-view="timeline"] .tape-head{position:sticky;top:0;z-index:5;min-height:58px;border-bottom:1px solid #dce6df;background:#f6faf7;color:#344d3b;font-size:.7rem}
#desk[data-view="timeline"] .tape-head .room-label{position:sticky;left:0;z-index:7;display:flex;flex-direction:column;justify-content:center;align-items:flex-start;gap:2px;border-right:1px solid #dce6df;background:#f6faf7;text-align:left}
#desk[data-view="timeline"] .timeline-room-heading b{font-size:.72rem}
#desk[data-view="timeline"] .timeline-room-heading small{color:#5f6d64;font-size:.58rem}
#desk[data-view="timeline"] .timeline-day{position:relative;display:flex;flex-direction:column;justify-content:center;align-items:center;gap:1px;padding:10px 2px 6px;border-left:1px solid #e9efea;color:#4b5e51;text-align:center}
#desk[data-view="timeline"] .timeline-day time{font-size:.79rem;font-weight:850;font-variant-numeric:tabular-nums}
#desk[data-view="timeline"] .timeline-day small{color:#5e6c62;font-size:.59rem;font-weight:650}
#desk[data-view="timeline"] .timeline-day em{position:absolute;top:2px;color:#5e6c62;font-size:.5rem;font-style:normal;font-weight:850;letter-spacing:.07em;text-transform:uppercase}
#desk[data-view="timeline"] .timeline-day.is-weekend{background:#f3f6f3}
#desk[data-view="timeline"] .timeline-day.is-today{background:#e5f2e8;color:#14532d;box-shadow:inset 0 -3px 0 #247648}
#desk[data-view="timeline"] .tape-group{position:sticky;left:0;z-index:3;padding:7px 12px;border-top:1px solid #e4ebe5;border-bottom:1px solid #e4ebe5;background:#edf5ef;color:#264d34;font-size:.68rem;font-weight:850;letter-spacing:.04em}
#desk[data-view="timeline"] .tape-group{width:max-content;min-width:100%;box-sizing:border-box}
#desk[data-view="timeline"] .tape-group span{display:inline-grid;min-width:20px;height:19px;place-items:center;margin-left:5px;border-radius:6px;background:#d9e9dd;color:#315b3d;font-size:.6rem;font-variant-numeric:tabular-nums}
#desk[data-view="timeline"] .room-row{position:relative;min-height:62px;border-bottom:1px solid #edf1ed;background:#fff}
#desk[data-view="timeline"] .room-row.is-odd{background:#fbfdfb}
#desk[data-view="timeline"] .room-row.drop{outline:2px solid #2e8155;outline-offset:-2px;background:#f0f8f2}
#desk[data-view="timeline"] .room-label{grid-column:1;grid-row:1;display:flex;width:auto;min-width:0;gap:8px;align-items:center;padding:6px 9px;position:sticky;left:0;z-index:2;border-right:1px solid #e4ebe5;background:#fff;color:inherit;text-decoration:none}
#desk[data-view="timeline"] .room-row.is-odd .room-label{background:#fbfdfb}
#desk[data-view="timeline"] .room-label:focus-visible{z-index:4;outline:3px solid #499565;outline-offset:-3px}
#desk[data-view="timeline"] .room-label img,#desk[data-view="timeline"] .room-label .type-code{flex:none;width:38px;height:38px;border:1px solid #e1e9e2;border-radius:9px;background:#eff5f0;color:#28573a}
#desk[data-view="timeline"] .room-label b{font-size:.76rem;font-variant-numeric:tabular-nums}
#desk[data-view="timeline"] .room-label small{max-width:156px;color:#748078;font-size:.57rem;line-height:1.25}
#desk[data-view="timeline"] .timeline-room-status{display:inline-block;padding:1px 4px;border-radius:4px;background:#eef3ef;color:#526257;font-size:.5rem;font-weight:850;line-height:1.35;text-transform:capitalize}
#desk[data-view="timeline"] .timeline-room-status.status-occupied{background:#e5f1e8;color:#24623b}
#desk[data-view="timeline"] .timeline-room-status.status-out_of_order,#desk[data-view="timeline"] .timeline-room-status.status-out_of_service{background:#fbe9e7;color:#9a342a}
#desk[data-view="timeline"] .timeline-room-status.status-vacant{background:#eff4ef;color:#50665a}
#desk[data-view="timeline"] .timeline-day-cell{grid-row:1;z-index:0;min-width:0;border-left:1px solid #edf1ed;pointer-events:none}
#desk[data-view="timeline"] .timeline-day-cell.is-weekend{background:rgba(243,246,243,.65)}
#desk[data-view="timeline"] .timeline-day-cell.is-today{background:rgba(224,241,228,.8)}
#desk[data-view="timeline"] .stay-bar,#desk[data-view="timeline"] .outage-bar{grid-row:1;align-self:stretch;margin:8px 4px;padding:5px 8px;border-radius:9px;font-size:.68rem;line-height:1.2;box-shadow:0 2px 5px rgba(14,35,22,.13);transition:filter .15s ease,transform .15s ease,box-shadow .15s ease}
#desk[data-view="timeline"] .stay-bar{display:flex;min-width:0;flex-direction:column;justify-content:center;gap:3px;border:1px solid rgba(255,255,255,.2);color:#fff;text-decoration:none}
#desk[data-view="timeline"] .stay-bar b{font-size:.68rem;font-weight:800}
#desk[data-view="timeline"] .stay-bar small{font-size:.57rem;font-weight:600;opacity:.94}
#desk[data-view="timeline"] .stay-bar.res{background:linear-gradient(110deg,#17623d,#24784b)}
#desk[data-view="timeline"] .stay-bar.in{background:linear-gradient(110deg,#0d756b,#0f756b)}
#desk[data-view="timeline"] .stay-bar.due{background:linear-gradient(110deg,#a65a08,#a4560a)}
#desk[data-view="timeline"] .stay-bar.tent{border:1px dashed #64748b;background:#606c78}
#desk[data-view="timeline"] .stay-bar.out{background:#5e6c62}
#desk[data-view="timeline"] .stay-bar.vip{box-shadow:inset 0 0 0 2px #f0bd4d,0 2px 5px rgba(14,35,22,.13)}
#desk[data-view="timeline"] .stay-bar.grp{border-left:4px solid #8e63d9}
#desk[data-view="timeline"] .stay-bar:hover{z-index:2;filter:brightness(1.08);transform:translateY(-1px);box-shadow:0 5px 12px rgba(14,35,22,.2)}
#desk[data-view="timeline"] .stay-bar:focus-visible{z-index:3;outline:3px solid #f1c75b;outline-offset:2px}
#desk[data-view="timeline"] .stay-bar[draggable="true"]{cursor:grab}
#desk[data-view="timeline"] .stay-bar[draggable="true"]:active{cursor:grabbing}
#desk[data-view="timeline"] .outage-bar{background:repeating-linear-gradient(135deg,#7b2826,#7b2826 7px,#963c36 7px,#963c36 14px);color:#fff;text-align:center;font-size:.61rem;font-weight:800}
#desk[data-view="timeline"] .desk-toast{border:1px solid rgba(255,255,255,.2);box-shadow:0 8px 25px rgba(0,0,0,.22)}
.desk.dark[data-view="timeline"]{color:#e2ece4}
.desk.dark[data-view="timeline"]>.desk-nav,.desk.dark[data-view="timeline"]>.desk-views,.desk.dark[data-view="timeline"] .desk-tools,.desk.dark[data-view="timeline"] .timeline-guide{border-color:#294333;background:#14251a}
.desk.dark[data-view="timeline"]>.desk-nav a,.desk.dark[data-view="timeline"]>.desk-views .desk-view-link,.desk.dark[data-view="timeline"] .desk-tools label,.desk.dark[data-view="timeline"] .timeline-legend li,.desk.dark[data-view="timeline"] .timeline-guide-copy span,.desk.dark[data-view="timeline"] .timeline-guide-note{color:#b8c8ba}
.desk.dark[data-view="timeline"]>.desk-nav a.on,.desk.dark[data-view="timeline"]>.desk-views .desk-view-link.on{background:#245632;color:#e0f0e3}
.desk.dark[data-view="timeline"]>.desk-nav em,.desk.dark[data-view="timeline"] .desk-tools input,.desk.dark[data-view="timeline"] .desk-tools select,#desk[data-view="timeline"].dark>.desk-views form input[name="name"]{border-color:#36503d;background:#182c1e;color:#dbe8dc}
.desk.dark[data-view="timeline"] .timeline-guide-copy strong{color:#dceade}
.desk.dark[data-view="timeline"] .timeline-guide-note{border-color:#294333}
.desk.dark[data-view="timeline"] .timeline-edge-key,.desk.dark[data-view="timeline"] .timeline-edge.is-in{border-color:#466c4e;background:#213d27;color:#c9e5ce}
.desk.dark[data-view="timeline"] .timeline-deposit-key{border-color:#465749;background:#1d3022;color:#bdcbbf}
.desk.dark[data-view="timeline"] .timeline-edge-key.is-out,.desk.dark[data-view="timeline"] .timeline-edge.is-out{border-color:#72552b;background:#3b2f1d;color:#f0d29b}
.desk.dark[data-view="timeline"] .tape{border-color:#294333;background:#122117}
.desk.dark[data-view="timeline"] .tape-head,#desk[data-view="timeline"].dark .tape-head .room-label{border-color:#36503d;background:#192e20;color:#e0ece2}
.desk.dark[data-view="timeline"] .timeline-day{border-color:#2d4433;color:#cfdfd1}
.desk.dark[data-view="timeline"] .timeline-day small,.desk.dark[data-view="timeline"] .timeline-day em{color:#a9bbac}
.desk.dark[data-view="timeline"] .timeline-day.is-weekend{background:#17291c}
.desk.dark[data-view="timeline"] .timeline-day.is-today{background:#244630;color:#e1f0e4}
.desk.dark[data-view="timeline"] .tape-group{border-color:#36503d;background:#203c28;color:#d9ebdc}
.desk.dark[data-view="timeline"] .tape-group span{background:#2b5336;color:#d9ebdc}
.desk.dark[data-view="timeline"] .room-row,.desk.dark[data-view="timeline"] .room-row.is-odd{border-color:#263d2c;background:#14251a}
.desk.dark[data-view="timeline"] .room-row.is-odd .room-label,.desk.dark[data-view="timeline"] .room-label{border-color:#36503d;background:#172a1d;color:#e1ece3}
.desk.dark[data-view="timeline"] .room-label small{color:#adbdaf}
.desk.dark[data-view="timeline"] .timeline-room-status{background:#263d2c;color:#cfdfd1}
.desk.dark[data-view="timeline"] .timeline-room-status.status-occupied{background:#23432b;color:#c5e4ca}
.desk.dark[data-view="timeline"] .timeline-room-status.status-out_of_order,.desk.dark[data-view="timeline"] .timeline-room-status.status-out_of_service{background:#4c2925;color:#f1b6ac}
.desk.dark[data-view="timeline"] .timeline-day-cell{border-color:#2b4031}
.desk.dark[data-view="timeline"] .timeline-day-cell.is-weekend{background:rgba(255,255,255,.018)}
.desk.dark[data-view="timeline"] .timeline-day-cell.is-today{background:rgba(45,104,61,.28)}
.desk.dark[data-view="timeline"] .loose{border-color:#5e492b;background:#2b2418}
.desk.dark[data-view="timeline"] .loose strong,.desk.dark[data-view="timeline"] .loose a{color:#f0cf9c}
.desk.dark[data-view="timeline"] .loose a{border-color:#65502e;background:#332a1a}
.desk.dark[data-view="timeline"] .timeline-loose-heading strong,.desk.dark[data-view="timeline"] .timeline-loose-more summary{color:#f0cf9c}
.desk.dark[data-view="timeline"] .timeline-loose-heading span,.desk.dark[data-view="timeline"] .timeline-loose-link small{color:#c5b596}
.desk.dark[data-view="timeline"] .timeline-loose-count{background:#4b3922;color:#f0d49f}
.desk.dark[data-view="timeline"] .timeline-loose-link{border-color:#5e492b;background:#302718;color:#e8dcc5}
.desk.dark[data-view="timeline"] .timeline-loose-link b,.desk.dark[data-view="timeline"] .timeline-loose-link span{color:#ecd8b4}
.desk.dark[data-view="timeline"] .timeline-loose-more{border-color:#5e492b}
.desk.dark[data-view="timeline"] .timeline-empty-state{border-color:#36503d;background:#172a1d}
.desk.dark[data-view="timeline"] .timeline-empty-icon{background:#243e2a;color:#c7e3cd}
.desk.dark[data-view="timeline"] .timeline-empty-state strong{color:#dceade}
.desk.dark[data-view="timeline"] .timeline-empty-state p{color:#aebdaf}
.desk.dark[data-view="timeline"] .timeline-empty-state .btn{border-color:#36503d;background:#203826;color:#d4e7d7}
@media(max-width:1100px){
  .timeline-hero{align-items:flex-start;flex-direction:column;gap:16px;padding:21px}
  .timeline-hero-metrics{width:100%;min-width:0}
  #desk[data-view="timeline"] .desk-tools{grid-template-columns:repeat(4,minmax(125px,1fr));overflow:visible}
  .timeline-guide{grid-template-columns:1fr}
  .timeline-legend{justify-content:flex-start}
}
@media(max-width:900px){
  #desk[data-view="timeline"]{padding-bottom:0}
  #desk[data-view="timeline"] .desk-tools{grid-template-columns:repeat(3,minmax(125px,1fr));flex-wrap:initial;overflow:visible}
  #desk[data-view="timeline"] .tape-head,#desk[data-view="timeline"] .room-row{--room-col:174px}
  #desk[data-view="timeline"] .tape{--room-col:174px;min-height:320px}
  #desk[data-view="timeline"] .room-label img,#desk[data-view="timeline"] .room-label .type-code{width:32px;height:32px}
  #desk[data-view="timeline"] .room-label small{max-width:114px}
}
@media(max-width:620px){
  #desk[data-view="timeline"]{gap:9px}
  .timeline-hero{min-height:0;padding:17px;border-radius:16px}
  .timeline-hero-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}
  .timeline-hero-metrics>div{padding:10px 12px}
  .timeline-hero-metrics>div:nth-child(n+3){border-top:1px solid rgba(255,255,255,.19)}
  .timeline-hero-metrics>div:nth-child(odd){border-left:0}
  .timeline-range-line{gap:5px 10px}
  .timeline-range-line span+span{padding-left:0;border-left:0}
  .timeline-hero-actions .btn{min-height:38px}
  #desk[data-view="timeline"]>.desk-views{gap:3px;padding:6px}
  #desk[data-view="timeline"]>.desk-views .desk-view-link{padding:6px 8px;font-size:.68rem}
  #desk[data-view="timeline"]>.desk-views .zoom{width:100%;margin:4px 2px 0}
  #desk[data-view="timeline"]>.desk-views form{width:100%;margin:2px 0 0}
  #desk[data-view="timeline"] .desk-tools{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;padding:10px}
  #desk[data-view="timeline"] .desk-tools .grow{grid-column:span 2}
  #desk[data-view="timeline"] .desk-tools .date-nav{grid-template-columns:27px minmax(100px,1fr) 27px auto}
  #desk[data-view="timeline"] .desk-tools .date-nav a{padding:0 5px}
  .timeline-guide{gap:8px;padding:10px}
  .timeline-legend{gap:7px 10px}
  .timeline-guide-note{grid-column:auto}
  #desk[data-view="timeline"] .timeline-empty-state{align-items:flex-start;flex-direction:column;margin:8px;padding:14px}
  #desk[data-view="timeline"] .timeline-empty-state .btn{margin-left:0}
  #desk[data-view="timeline"] .tape{min-height:280px;max-height:65vh;border-radius:12px}
  #desk[data-view="timeline"] .tape-head,#desk[data-view="timeline"] .room-row{--room-col:142px}
  #desk[data-view="timeline"] .tape{--room-col:142px}
  #desk[data-view="timeline"] .room-label{gap:5px;padding:5px}
  #desk[data-view="timeline"] .room-label img,#desk[data-view="timeline"] .room-label .type-code{width:28px;height:28px;border-radius:7px}
  #desk[data-view="timeline"] .room-label b{font-size:.68rem}
  #desk[data-view="timeline"] .room-label small{max-width:96px;font-size:.53rem}
  #desk[data-view="timeline"] .stay-bar,#desk[data-view="timeline"] .outage-bar{margin:8px 2px;padding:4px 5px}
  #desk[data-view="timeline"] .stay-bar b{font-size:.61rem}
  #desk[data-view="timeline"] .stay-bar small{font-size:.51rem}
}
@media(prefers-reduced-motion:reduce){#desk[data-view="timeline"] .stay-bar{transition:none}}
@media print{
  #desk[data-view="timeline"]{display:block;padding:0;border:0;background:#fff;color:#111}
  #desk[data-view="timeline"] .timeline-hero{min-height:0;padding:8px 0;border:0;border-radius:0;background:#fff;color:#111;box-shadow:none}
  #desk[data-view="timeline"] .timeline-hero:after,#desk[data-view="timeline"] .timeline-hero-actions,#desk[data-view="timeline"]>.desk-nav,#desk[data-view="timeline"]>.desk-views,#desk[data-view="timeline"] .desk-tools,#desk[data-view="timeline"] .timeline-guide-note{display:none!important}
  #desk[data-view="timeline"] .timeline-hero h2,#desk[data-view="timeline"] .timeline-range-line strong{color:#111}
  #desk[data-view="timeline"] .timeline-eyebrow,#desk[data-view="timeline"] .timeline-hero-copy>p,#desk[data-view="timeline"] .timeline-range-line span{color:#444}
  #desk[data-view="timeline"] .timeline-hero-metrics{border-color:#bbb;background:#fff}
  #desk[data-view="timeline"] .timeline-hero-metrics span,#desk[data-view="timeline"] .timeline-hero-metrics small{color:#444}
  #desk[data-view="timeline"] .timeline-hero-metrics strong{color:#111}
  #desk[data-view="timeline"] .timeline-guide{margin:6px 0;padding:7px;border-color:#bbb;box-shadow:none}
  #desk[data-view="timeline"] .timeline-loose-more summary{display:none}
  #desk[data-view="timeline"] .timeline-loose-more .timeline-loose-list{margin-top:0}
  #desk[data-view="timeline"] .timeline-loose-link{min-height:0;padding:4px 6px}
  #desk[data-view="timeline"] .tape{--room-col:160px;max-height:none;min-height:0;overflow:visible;border:1px solid #aaa;border-radius:0;box-shadow:none}
  #desk[data-view="timeline"] .tape-head,#desk[data-view="timeline"] .room-row{grid-template-columns:var(--room-col) repeat(var(--days),minmax(0,1fr));min-width:0}
  #desk[data-view="timeline"] .tape-head{position:static}
  #desk[data-view="timeline"] .room-row,#desk[data-view="timeline"] .tape-group{break-inside:avoid;page-break-inside:avoid}
  #desk[data-view="timeline"] .room-label{gap:4px;padding:3px}
  #desk[data-view="timeline"] .room-label img,#desk[data-view="timeline"] .room-label .type-code{width:26px;height:26px}
  #desk[data-view="timeline"] .room-label small{font-size:.48rem}
  #desk[data-view="timeline"] .stay-bar,#desk[data-view="timeline"] .outage-bar{margin:4px 1px;padding:3px;font-size:.51rem;box-shadow:none}
  #desk[data-view="timeline"] .stay-bar b{font-size:.52rem}
  #desk[data-view="timeline"] .stay-bar small{font-size:.45rem}
  #desk[data-view="timeline"] .room-label{position:static}
  #desk[data-view="timeline"] .stay-bar,#desk[data-view="timeline"] .outage-bar{print-color-adjust:exact;-webkit-print-color-adjust:exact}
  #desk[data-view="timeline"] .mobile-tabs,.sidebar,.topbar{display:none!important}
  .main{margin:0!important}
}

/* Room board: inventory-first, date-aware room cards for front-office scanning. */
#desk[data-view="board"]{display:flex;flex-direction:column;gap:14px;overflow:visible;border:0;border-radius:0;background:transparent;box-shadow:none}
.room-board-hero{position:relative;isolation:isolate;display:flex;justify-content:space-between;align-items:center;gap:24px;min-height:164px;padding:23px 27px;border:1px solid #174d37;border-radius:19px;overflow:hidden;color:#fff;background:linear-gradient(118deg,#103a29 0%,#15593b 60%,#26764d 100%);box-shadow:0 13px 30px rgba(14,58,39,.14)}
.room-board-hero:after{position:absolute;z-index:-1;top:-190px;right:11%;width:360px;height:360px;border:1px solid rgba(255,255,255,.13);border-radius:50%;box-shadow:0 0 0 38px rgba(255,255,255,.035),0 0 0 80px rgba(255,255,255,.025);content:""}
.room-board-hero-copy{position:relative;z-index:1;min-width:0;max-width:650px}
.room-board-eyebrow{display:flex;align-items:center;gap:8px;color:#e7f2ea;font-size:.64rem;font-weight:850;letter-spacing:.14em}
.room-board-eyebrow i{font-style:normal;opacity:.55}
.room-board-hero h2{margin:9px 0 5px;color:#fff;font-size:clamp(1.4rem,2.4vw,1.9rem);line-height:1.1;letter-spacing:-.035em}
.room-board-hero-copy>p{margin:0;color:#e7f2ea;font-size:.82rem;line-height:1.5}
.room-board-date-line{display:flex;flex-wrap:wrap;gap:6px 13px;margin-top:11px;color:#e7f2ea;font-size:.68rem}
.room-board-date-line strong{color:#fff}
.room-board-date-line span+span{padding-left:12px;border-left:1px solid rgba(255,255,255,.24)}
.room-board-hero-actions{display:flex;gap:7px;flex-wrap:wrap;margin-top:12px}
.room-board-hero-actions .btn{min-height:35px;border-color:rgba(255,255,255,.3);background:rgba(255,255,255,.1);color:#fff;font-size:.7rem}
.room-board-hero-actions .btn:hover{background:rgba(255,255,255,.2)}
.room-board-metrics{position:relative;z-index:1;display:grid;grid-template-columns:repeat(4,minmax(70px,1fr));min-width:min(470px,48%);border:1px solid rgba(255,255,255,.2);border-radius:14px;background:rgba(255,255,255,.075);backdrop-filter:blur(8px)}
.room-board-metrics>div{display:flex;flex-direction:column;gap:3px;padding:12px 11px}
.room-board-metrics>div+div{border-left:1px solid rgba(255,255,255,.18)}
.room-board-metrics span{color:#f7fbf8;font-size:.56rem;font-weight:850;letter-spacing:.07em;text-transform:uppercase}
.room-board-metrics strong{color:#fff;font-size:1.28rem;font-variant-numeric:tabular-nums;line-height:1.1}
.room-board-metrics small{color:#fff;font-size:.59rem}
.room-board{display:flex;flex-direction:column;gap:18px}
.room-board-group{min-width:0}
.room-board-group-head{display:flex;justify-content:space-between;align-items:center;gap:12px;margin:0 2px 9px}
.room-board-group-head h3{margin:0;color:#234533;font-size:.88rem;font-weight:850;letter-spacing:-.01em}
.room-board-group-head span{display:inline-flex;align-items:center;min-height:24px;padding:2px 9px;border:1px solid #dce8df;border-radius:999px;background:#f5faf6;color:#53675a;font-size:.64rem;font-weight:750;font-variant-numeric:tabular-nums}
.room-board-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(244px,1fr));gap:12px}
.room-board-card{position:relative;display:flex;min-width:0;flex-direction:column;overflow:hidden;border:1px solid #dfe8e1;border-radius:15px;background:#fff;box-shadow:0 3px 12px rgba(20,52,35,.045);transition:transform .16s ease,border-color .16s ease,box-shadow .16s ease}
.room-board-card:hover{transform:translateY(-2px);border-color:#b9d4c0;box-shadow:0 9px 20px rgba(20,52,35,.11)}
.room-board-photo{position:relative;display:grid;height:112px;place-items:center;overflow:hidden;background:linear-gradient(135deg,#eaf2ec,#dce9df)}
.room-board-photo:after{position:absolute;inset:42% 0 0;background:linear-gradient(0deg,rgba(10,31,20,.54),transparent);content:""}
.room-board-photo img{width:100%;height:100%;object-fit:cover}
.room-board-type-code{display:grid;width:52px;height:52px;place-items:center;border:1px solid #c3d8c8;border-radius:15px;background:rgba(255,255,255,.76);color:#28583a;font-size:.82rem;font-weight:850;letter-spacing:.05em}
.room-board-photo-room{position:absolute;z-index:1;bottom:10px;left:12px;color:#fff;font-size:.72rem;font-weight:850;text-shadow:0 1px 5px rgba(0,0,0,.35)}
.room-board-status{position:absolute;z-index:2;top:10px;right:10px;display:inline-flex;align-items:center;min-height:24px;padding:3px 8px;border:1px solid rgba(255,255,255,.45);border-radius:999px;background:#e9f5ec;color:#1f6439;font-size:.59rem;font-weight:850;box-shadow:0 2px 8px rgba(10,31,20,.12)}
.room-board-status.status-occupied{background:#e4f1e7;color:#215b37}
.room-board-status.status-out_of_order,.room-board-status.status-out_of_service{background:#fff0ed;color:#9c3328}
.room-board-status.status-reserved{background:#edf2ff;color:#394d7b}
.room-board-card-body{display:flex;min-width:0;flex:1;flex-direction:column;gap:10px;padding:12px 13px 13px}
.room-board-title-row{display:flex;justify-content:space-between;align-items:flex-start;gap:8px}
.room-board-title-row>div{min-width:0}
.room-board-title-row h4{margin:0;font-size:.88rem;line-height:1.2}
.room-board-title-row h4 a{color:#1f4931;text-decoration:none}
.room-board-title-row h4 a:hover{text-decoration:underline;text-underline-offset:3px}
.room-board-title-row p{margin:4px 0 0;color:#5f6d64;font-size:.65rem;line-height:1.35}
.room-board-hk{display:inline-flex;flex:none;align-items:center;min-height:23px;padding:3px 7px;border:1px solid #dce8df;border-radius:7px;background:#f5faf6;color:#52665a;font-size:.57rem;font-weight:800}
.room-board-hk.hk-clean,.room-board-hk.hk-inspected{border-color:#c9e4cf;background:#eef8f0;color:#27623b}
.room-board-hk.hk-dirty,.room-board-hk.hk-touch_up{border-color:#f0d8a6;background:#fff7e6;color:#81520f}
.room-board-blocks{display:flex;flex-direction:column;gap:5px;margin:0;padding:0;list-style:none}
.room-board-block{display:flex;align-items:center;gap:8px;padding:7px 8px;border:1px solid #f0c7bd;border-radius:9px;background:#fff5f2;color:#7c3028}
.room-board-block.is-upcoming{border-color:#ecd9ad;background:#fff8e8;color:#74510e}
.room-board-block-mark{display:grid;flex:none;width:23px;height:23px;place-items:center;border-radius:7px;background:#f9ded7;color:#8b3026;font-size:.8rem;font-weight:900}
.room-board-block.is-upcoming .room-board-block-mark{background:#f3e6c8;color:#76530e}
.room-board-block>span:last-child{display:flex;min-width:0;flex-direction:column;gap:1px}
.room-board-block b{font-size:.65rem}
.room-board-block small{color:#7c3028;font-size:.57rem;font-weight:650}
.room-board-block.is-upcoming small{color:#74510e}
.room-board-stay{display:flex;min-width:0;flex-direction:column;align-items:flex-start;gap:4px;padding:9px 10px;border:1px solid #e4ebe5;border-radius:10px;background:#f8fbf8}
.room-board-stay-state{display:inline-flex;align-items:center;min-height:19px;padding:2px 7px;border-radius:6px;background:#e5f2e8;color:#24623b;font-size:.55rem;font-weight:850;letter-spacing:.04em;text-transform:uppercase}
.room-board-stay-state.in-house{background:#dff1ed;color:#0f6259}
.room-board-stay-state.completed{background:#e9edeb;color:#4f5e55}
.room-board-stay-state.due-out{background:#fff1dc;color:#80500d}
.room-board-stay-state.tentative{background:#e8edf5;color:#41516a}
.room-board-stay-state.next{background:#eaf2ff;color:#365078}
.room-board-guest{max-width:100%;overflow:hidden;color:#243b2d;font-size:.75rem;font-weight:800;text-decoration:none;text-overflow:ellipsis;white-space:nowrap}
.room-board-guest:hover{text-decoration:underline;text-underline-offset:2px}
.room-board-stay small{max-width:100%;overflow-wrap:anywhere;color:#5f6d64;font-size:.59rem;font-variant-numeric:tabular-nums}
.room-board-reservation{color:inherit;font-weight:800;text-decoration:none}
.room-board-reservation:hover{text-decoration:underline;text-underline-offset:2px}
.room-board-flag{display:inline-flex;align-items:center;min-height:19px;padding:2px 6px;border:1px solid #dce8df;border-radius:6px;background:#fff;color:#53675a;font-size:.55rem;font-weight:750}
.room-board-flag.vip{border-color:#e8d49d;background:#fff8e4;color:#705516}
.room-board-flag.group{border-color:#d8cef1;background:#f5f1ff;color:#5d448d}
.room-board-flag.request{max-width:100%;overflow-wrap:anywhere;border-color:#edd9b6;background:#fff8eb;color:#775321}
.room-board-turnover{display:flex;min-width:0;flex-direction:column;gap:3px;padding:7px 9px;border:1px solid #ecd9ad;border-radius:9px;background:#fff8e8}
.room-board-turnover span{color:#74510e;font-size:.55rem;font-weight:850;letter-spacing:.05em;text-transform:uppercase}
.room-board-turnover a{max-width:100%;overflow:hidden;color:#513b16;font-size:.68rem;font-weight:800;text-decoration:none;text-overflow:ellipsis;white-space:nowrap}
.room-board-turnover a:hover{text-decoration:underline;text-underline-offset:2px}
.room-board-turnover small{max-width:100%;overflow-wrap:anywhere;color:#74510e;font-size:.57rem}
.room-board-clear{display:flex;flex-direction:column;gap:3px;padding:10px;border:1px dashed #d2dfd5;border-radius:10px;background:#f8fbf8}
.room-board-clear strong{color:#315b3d;font-size:.71rem}
.room-board-clear span{color:#5f6d64;font-size:.62rem}
.room-board-empty{display:flex;align-items:center;gap:13px;padding:20px;border:1px dashed #c9d9cd;border-radius:14px;background:#f8fbf8}
.room-board-empty-icon{display:grid;flex:none;width:40px;height:40px;place-items:center;border-radius:12px;background:#e9f3eb;color:#24633a}
.room-board-empty strong{color:#274334;font-size:.82rem}
.room-board-empty p{margin:3px 0 0;color:#5f6d64;font-size:.68rem}
.room-board-empty .btn{margin-left:auto}
.desk.dark[data-view="board"]{color:#e2ece4}
.desk.dark[data-view="board"] .room-board-group-head h3{color:#dceade}
.desk.dark[data-view="board"] .room-board-group-head span{border-color:#36503d;background:#192e20;color:#c4d5c7}
.desk.dark[data-view="board"] .room-board-card{border-color:#294333;background:#14251a;box-shadow:0 3px 12px rgba(0,0,0,.18)}
.desk.dark[data-view="board"] .room-board-card:hover{border-color:#4e7658;box-shadow:0 9px 20px rgba(0,0,0,.28)}
.desk.dark[data-view="board"] .room-board-photo{background:linear-gradient(135deg,#203c28,#172b1d)}
.desk.dark[data-view="board"] .room-board-type-code{border-color:#3c6145;background:rgba(20,37,26,.82);color:#d9ebdc}
.desk.dark[data-view="board"] .room-board-title-row h4 a{color:#d9ebdc}
.desk.dark[data-view="board"] .room-board-title-row p{color:#adbdaf}
.desk.dark[data-view="board"] .room-board-hk{border-color:#36503d;background:#192e20;color:#c4d5c7}
.desk.dark[data-view="board"] .room-board-hk.hk-clean,.desk.dark[data-view="board"] .room-board-hk.hk-inspected{border-color:#3a6944;background:#203b27;color:#c9e5ce}
.desk.dark[data-view="board"] .room-board-hk.hk-dirty,.desk.dark[data-view="board"] .room-board-hk.hk-touch_up{border-color:#72552b;background:#3b2f1d;color:#f0d29b}
.desk.dark[data-view="board"] .room-board-stay{border-color:#304838;background:#192e20}
.desk.dark[data-view="board"] .room-board-stay-state.in-house{background:#21433d;color:#bfe4da}
.desk.dark[data-view="board"] .room-board-stay-state.completed{background:#303a33;color:#c4d0c7}
.desk.dark[data-view="board"] .room-board-stay-state.due-out{background:#3c321b;color:#f0d69a}
.desk.dark[data-view="board"] .room-board-stay-state.tentative{background:#303849;color:#cad4e4}
.desk.dark[data-view="board"] .room-board-stay-state.next{background:#273850;color:#c9d9f0}
.desk.dark[data-view="board"] .room-board-guest{color:#e0ece2}
.desk.dark[data-view="board"] .room-board-stay small{color:#b8c8ba}
.desk.dark[data-view="board"] .room-board-turnover{border-color:#725b2b;background:#3c321b}
.desk.dark[data-view="board"] .room-board-turnover span,.desk.dark[data-view="board"] .room-board-turnover small{color:#f0d69a}
.desk.dark[data-view="board"] .room-board-turnover a{color:#f4e7c4}
.desk.dark[data-view="board"] .room-board-block{border-color:#68403a;background:#38211e;color:#f0b9b0}
.desk.dark[data-view="board"] .room-board-block.is-upcoming{border-color:#725b2b;background:#3c321b;color:#f0d69a}
.desk.dark[data-view="board"] .room-board-block-mark{background:#57302b;color:#f4c0b8}
.desk.dark[data-view="board"] .room-board-block.is-upcoming .room-board-block-mark{background:#514321;color:#f0d69a}
.desk.dark[data-view="board"] .room-board-block small{color:#efc0b7}
.desk.dark[data-view="board"] .room-board-block.is-upcoming small{color:#f0d69a}
.desk.dark[data-view="board"] .room-board-flag{border-color:#36503d;background:#1d3022;color:#c4d5c7}
.desk.dark[data-view="board"] .room-board-flag.vip{border-color:#725b2b;background:#3c321b;color:#f0d69a}
.desk.dark[data-view="board"] .room-board-flag.group{border-color:#554778;background:#2b2540;color:#d4c8f0}
.desk.dark[data-view="board"] .room-board-flag.request{border-color:#72552b;background:#3b2f1d;color:#f0d29b}
.desk.dark[data-view="board"] .room-board-clear,.desk.dark[data-view="board"] .room-board-empty{border-color:#36503d;background:#172a1d}
.desk.dark[data-view="board"] .room-board-clear strong,.desk.dark[data-view="board"] .room-board-empty strong{color:#dceade}
.desk.dark[data-view="board"] .room-board-clear span,.desk.dark[data-view="board"] .room-board-empty p{color:#aebdaf}
.desk.dark[data-view="board"] .room-board-empty-icon{background:#243e2a;color:#c7e3cd}
@media(max-width:1280px){
  .room-board-hero{align-items:flex-start;flex-direction:column;gap:16px;padding:21px}
  .room-board-metrics{width:100%;min-width:0}
}
@media(max-width:620px){
  #desk[data-view="board"]{gap:10px}
  .room-board-hero{min-height:0;padding:17px;border-radius:16px}
  .room-board-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}
  .room-board-metrics>div:nth-child(n+3){border-top:1px solid rgba(255,255,255,.18)}
  .room-board-metrics>div:nth-child(odd){border-left:0}
  .room-board-date-line{gap:5px 9px}
  .room-board-date-line span+span{padding-left:0;border-left:0}
  .room-board-hero-actions .btn{min-height:38px}
  .room-board-grid{grid-template-columns:repeat(auto-fill,minmax(min(100%,260px),1fr));gap:9px}
  .room-board-photo{height:104px}
  .room-board-card-body{gap:8px;padding:10px}
  .room-board-empty{align-items:flex-start;flex-direction:column;padding:14px}
  .room-board-empty .btn{margin-left:0}
}
#desk[data-view="board"] .room-board-card a:focus-visible{position:relative;z-index:2;outline:3px solid #39784d;outline-offset:2px;border-radius:3px}
.desk.dark[data-view="board"] .room-board-card a:focus-visible{outline-color:#a6d3ae}
@media(prefers-reduced-motion:reduce){.room-board-card{transition:none}}
@media print{
  #desk[data-view="board"]{display:block;padding:0;border:0;background:#fff;color:#111}
  #desk[data-view="board"] .room-board-hero{min-height:0;margin:0 0 8px;padding:8px 0;border:0;border-radius:0;background:#fff;color:#111;box-shadow:none}
  #desk[data-view="board"] .room-board-hero:after,#desk[data-view="board"] .room-board-hero-actions,#desk[data-view="board"]>.desk-nav,#desk[data-view="board"]>.desk-views,#desk[data-view="board"] .desk-tools{display:none!important}
  #desk[data-view="board"] .room-board-hero h2,#desk[data-view="board"] .room-board-date-line strong{color:#111}
  #desk[data-view="board"] .room-board-hero-copy>p,#desk[data-view="board"] .room-board-date-line span,#desk[data-view="board"] .room-board-eyebrow{color:#444}
  #desk[data-view="board"] .room-board-metrics{border-color:#bbb;background:#fff}
  #desk[data-view="board"] .room-board-metrics span,#desk[data-view="board"] .room-board-metrics small{color:#444}
  #desk[data-view="board"] .room-board-metrics strong{color:#111}
  #desk[data-view="board"] .room-board-grid{grid-template-columns:repeat(3,minmax(0,1fr));gap:7px}
  #desk[data-view="board"] .room-board-card{break-inside:avoid;page-break-inside:avoid;border-color:#aaa;box-shadow:none}
  #desk[data-view="board"] .room-board-card-body{padding:7px}
  #desk[data-view="board"] .room-board-photo{height:72px}
  #desk[data-view="board"] .room-board-title-row h4 a,#desk[data-view="board"] .room-board-title-row p,#desk[data-view="board"] .room-board-guest,#desk[data-view="board"] .room-board-stay small,#desk[data-view="board"] .room-board-reservation,#desk[data-view="board"] .room-board-turnover span,#desk[data-view="board"] .room-board-turnover a,#desk[data-view="board"] .room-board-turnover small{color:#111}
  #desk[data-view="board"] .room-board-status,#desk[data-view="board"] .room-board-hk,#desk[data-view="board"] .room-board-stay-state,#desk[data-view="board"] .room-board-flag,#desk[data-view="board"] .room-board-block,#desk[data-view="board"] .room-board-turnover{print-color-adjust:exact;-webkit-print-color-adjust:exact}
  #desk[data-view="board"] .room-board-group-head{break-after:avoid;page-break-after:avoid}
  #desk[data-view="board"] .mobile-tabs,.sidebar,.topbar{display:none!important}
  .main{margin:0!important}
}
#desk[data-view="arrivals"]{display:flex;flex-direction:column;gap:12px;overflow:visible;border:0;border-radius:0;background:transparent;box-shadow:none}
#desk[data-view="arrivals"]>.arrival-views{display:flex;gap:4px;flex-wrap:wrap;align-items:center;padding:6px;border:1px solid #dfe9e1;border-radius:13px;background:#fff;box-shadow:0 3px 10px rgba(18,43,31,.035)}
#desk[data-view="arrivals"]>.arrival-views .desk-view-link{padding:7px 10px;border-radius:8px;background:transparent;color:#53665b;font-size:.72rem;font-weight:750;text-decoration:none}
#desk[data-view="arrivals"]>.arrival-views .desk-view-link:hover{background:#f1f7f2;color:#14532d}
#desk[data-view="arrivals"]>.arrival-views .desk-view-link.on{background:#e7f3ea;color:#14532d;box-shadow:inset 0 0 0 1px #c7e5ce}
#desk[data-view="departures"]{display:flex;flex-direction:column;gap:12px;overflow:visible;border:0;border-radius:0;background:transparent;box-shadow:none}
#desk[data-view="departures"]>.departure-views{display:flex;gap:4px;flex-wrap:wrap;align-items:center;padding:6px;border:1px solid #dfe9e1;border-radius:13px;background:#fff;box-shadow:0 3px 10px rgba(18,43,31,.035)}
#desk[data-view="departures"]>.departure-views .desk-view-link{padding:7px 10px;border-radius:8px;background:transparent;color:#53665b;font-size:.72rem;font-weight:750;text-decoration:none}
#desk[data-view="departures"]>.departure-views .desk-view-link:hover{background:#f1f7f2;color:#14532d}
#desk[data-view="departures"]>.departure-views .desk-view-link.on{background:#e7f3ea;color:#14532d;box-shadow:inset 0 0 0 1px #c7e5ce}
.departure-hero{position:relative;isolation:isolate;display:grid;grid-template-columns:minmax(0,1.05fr) minmax(360px,.95fr);gap:24px;align-items:stretch;overflow:hidden;padding:23px 25px;border:1px solid #164d37;border-radius:18px;background:radial-gradient(circle at 78% 0%,rgba(239,190,101,.18),transparent 36%),linear-gradient(120deg,#102d21 0%,#145437 58%,#1b6842 100%);box-shadow:0 14px 30px rgba(16,58,40,.13);color:#f3faf4}
.departure-hero-copy{min-width:0;align-self:center}
.departure-eyebrow{display:flex;align-items:center;gap:8px;color:#d4ead5;font-size:.63rem;font-weight:850;letter-spacing:.14em}
.departure-eyebrow i{font-style:normal;opacity:.6}
.departure-hero h2{margin:10px 0 7px;color:#fff;font-size:clamp(1.55rem,2.4vw,2rem);line-height:1.08;letter-spacing:-.04em}
.departure-hero-copy>p{max-width:520px;margin:0;color:#d9eadf;font-size:.82rem;line-height:1.5}
.departure-date-meta{display:flex;flex-wrap:wrap;gap:7px 15px;margin-top:14px;color:#e2efe6;font-size:.72rem}
.departure-date-meta span{display:flex;gap:6px;align-items:center}
.departure-hero-actions{display:flex;flex-wrap:wrap;gap:7px;margin-top:15px}
.departure-hero .btn{min-height:35px;border-color:rgba(255,255,255,.28);background:rgba(255,255,255,.1);color:#fff}
.departure-hero .btn:hover{background:rgba(255,255,255,.2)}
.departure-summary{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;align-content:center}
.departure-summary>div{display:flex;min-height:72px;flex-direction:column;justify-content:center;gap:2px;padding:10px 13px;border:1px solid rgba(255,255,255,.18);border-radius:12px;background:rgba(255,255,255,.085);backdrop-filter:blur(5px)}
.departure-summary>div span{color:#d5e9dc;font-size:.65rem;font-weight:750;letter-spacing:.055em;text-transform:uppercase}
.departure-summary>div strong{color:#fff;font-size:1.35rem;line-height:1.1;font-variant-numeric:tabular-nums}
.departure-summary>div small{color:#c5dfce;font-size:.64rem}
.departure-summary .departure-summary-primary{grid-row:auto;min-height:72px;justify-content:center;padding:10px 13px;background:rgba(255,255,255,.13)}
.departure-summary-primary strong{font-size:2.5rem!important;letter-spacing:-.05em}
.departure-segments{display:flex;gap:5px;overflow-x:auto;padding:6px;border:1px solid #dfe9e1;border-radius:13px;background:#fff;box-shadow:0 3px 10px rgba(18,43,31,.035);scrollbar-width:thin}
.departure-segments a{display:inline-flex;flex:none;align-items:center;gap:9px;min-height:36px;padding:0 11px;border-radius:9px;color:#52665a;font-size:.73rem;font-weight:750;text-decoration:none;white-space:nowrap}
.departure-segments a:hover{background:#f1f7f2;color:#14532d}
.departure-segments a.is-active{background:#e7f3ea;color:#14532d;box-shadow:inset 0 0 0 1px #c7e5ce}
.departure-segments b{display:inline-grid;min-width:22px;height:21px;place-items:center;padding:0 5px;border-radius:999px;background:#edf2ee;color:#52665a;font-size:.65rem;font-variant-numeric:tabular-nums}
.departure-segments a.is-active b{background:#d5ead9;color:#14532d}
.departure-toolbar{display:grid;grid-template-columns:minmax(285px,.9fr) minmax(280px,1.1fr) auto;gap:14px;align-items:end;padding:12px 14px;border:1px solid #dfe8e1;border-radius:13px;background:#fff;box-shadow:0 3px 12px rgba(18,43,31,.035)}
.departure-date-field,.departure-search-field{display:block;margin-bottom:5px;color:#4c6052;font-size:.67rem;font-weight:800;letter-spacing:.04em;text-transform:uppercase}
.departure-date-control{display:flex;align-items:center;gap:6px;min-height:39px}
.departure-date-control>a{display:grid;min-width:31px;height:34px;place-items:center;border:1px solid #dfe8e1;border-radius:8px;color:#28573a;font-size:1.1rem;font-weight:800;text-decoration:none}
.departure-date-control>a:hover{background:#f0f7f2}
.departure-date-control input{min-width:0;min-height:36px;padding:5px 8px;border:1px solid #dfe8e1;border-radius:8px;background:#fff;color:#213c2b;font:inherit;font-size:.76rem}
.departure-date-control .departure-today{min-width:auto;padding:0 10px;font-size:.7rem}
.departure-search-control{display:flex;min-height:39px;align-items:center;gap:7px;padding:3px 4px 3px 10px;border:1px solid #dfe8e1;border-radius:9px;background:#fbfdfb;color:#738177}
.departure-search-control .i{flex:none;width:15px;height:15px}
.departure-search-control input{width:100%;min-width:0;min-height:30px;border:0;outline:0;background:transparent;color:#223d2c;font:inherit;font-size:.77rem}
.departure-search-control .btn{flex:none;min-height:30px}
.departure-reset{align-self:center;color:#477253;font-size:.72rem;font-weight:700;text-decoration:none;white-space:nowrap}
.departure-toolbar a:focus-visible,.departure-toolbar input:focus-visible,.departure-toolbar button:focus-visible,.departure-segments a:focus-visible{outline:3px solid #4b9a67;outline-offset:2px}
.departure-manifest{overflow:hidden;border:1px solid #dfe8e1;border-radius:15px;background:#fff;box-shadow:0 8px 22px rgba(18,43,31,.045)}
.departure-manifest-head{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:16px 18px;border-bottom:1px solid #e5ece6;background:linear-gradient(110deg,#fff,#f8fbf8)}
.departure-manifest-kicker{color:#477253;font-size:.59rem;font-weight:850;letter-spacing:.13em}
.departure-manifest-head h3{margin:4px 0 0;color:#1b3424;font-size:1.05rem;letter-spacing:-.02em}
.departure-manifest-head p{margin:4px 0 0;color:#64756a;font-size:.7rem;line-height:1.45}
.departure-manifest-count{flex:none;color:#64756a;font-size:.68rem;white-space:nowrap}
.departure-manifest-count strong{color:#1d5031;font-size:1.05rem;font-variant-numeric:tabular-nums}
.departure-table-wrap{width:100%;overflow-x:auto;overscroll-behavior-inline:contain}
.departure-table{width:100%;min-width:950px;border-collapse:separate;border-spacing:0;font-size:.73rem}
.departure-table thead th{position:sticky;top:0;padding:10px 12px;background:#f3f7f4;color:#526359;text-align:left;font-size:.61rem;font-weight:850;letter-spacing:.065em;text-transform:uppercase;white-space:nowrap}
.departure-table tbody td{padding:12px;border-top:1px solid #edf1ee;color:#263c2c;vertical-align:top}
.departure-table tbody tr:hover td{background:#fbfdfb}
.departure-guest-cell{display:flex;align-items:center;gap:7px;min-width:145px}
.departure-guest-name{color:#203f2a;font-size:.78rem;font-weight:800;text-decoration:none}
.departure-guest-name:hover,.departure-booking-ref:hover{text-decoration:underline}
.departure-booking-ref{display:inline-block;margin-top:4px;color:#52735b;font-size:.65rem;font-weight:750;text-decoration:none}
.departure-vip{padding:2px 6px;border:1px solid #f0d9a8;border-radius:999px;background:#fff7e6;color:#8b5d13;font-size:.56rem;font-weight:850}
.departure-booking-meta{display:flex;flex-wrap:wrap;gap:3px 8px;margin-top:4px;color:#6a796e;font-size:.62rem;line-height:1.4}
.departure-booking-meta a{color:#2c7045;font-weight:700}
.departure-stay-dates{display:flex;align-items:center;gap:6px;color:#243d2c;font-size:.68rem;font-weight:760;white-space:nowrap}
.departure-stay-dates span{color:#859489}
.departure-stay-meta,.departure-room-type,.departure-hk,.departure-prep-note{display:block;margin-top:5px;color:#6a796e;font-size:.63rem;line-height:1.4}
.departure-room-number{display:block;color:#203f2a;font-size:.79rem}
.departure-hk{width:max-content;max-width:170px;padding:3px 6px;border:1px solid #dfe8e1;border-radius:6px;background:#f6f9f6;color:#52685a;font-size:.57rem;font-weight:700}
.departure-hk-dirty,.departure-hk-touch_up{border-color:#f0dfb4;background:#fff9e7;color:#835b0d}
.departure-hk-unassigned{border-color:#f1d2c8;background:#fff2ee;color:#9c3528}
.departure-prep-status{display:flex;flex-wrap:wrap;align-items:center;gap:4px}
.departure-prep{display:inline-flex;align-items:center;min-height:23px;padding:0 7px;border:1px solid #d5e7d8;border-radius:999px;background:#eff7f0;color:#28613b;font-size:.59rem;font-weight:850;white-space:nowrap}
.departure-prep-attention{border-color:#f1d2c8;background:#fff2ee;color:#9c3528}
.departure-prep-ready{border-color:#d5e7d8;background:#eff7f0;color:#28613b}
.departure-prep-complete{border-color:#dce4df;background:#f2f5f3;color:#54665a}
.departure-prep-pending{border-color:#e4e8ed;background:#f3f5f7;color:#5d6873}
.departure-prep-note{max-width:275px;margin-top:5px}
.departure-estimate-label{display:block;color:#6a796e;font-size:.57rem;font-weight:750;text-transform:uppercase;letter-spacing:.045em}
.departure-balance{display:block;color:#203f2a;font-size:.72rem;font-weight:800;white-space:nowrap}
.departure-estimate{display:block;max-width:190px;margin-top:5px;color:#64756a;font-size:.61rem;line-height:1.4}
.departure-estimate b{color:#48624f;font-weight:750}
.departure-actions{min-width:150px}
.departure-actions>.btn,.departure-post-bills{margin:0 3px 4px 0}
.departure-post-bills{display:inline-block}
.departure-empty{display:flex;align-items:center;gap:13px;padding:28px 22px;color:#526359}
.departure-empty-mark{display:grid;flex:none;width:37px;height:37px;place-items:center;border:1px solid #d9e8dc;border-radius:50%;background:#eff7f0;color:#28613b;font-size:1rem;font-weight:800}
.departure-empty strong{color:#203f2a;font-size:.86rem}
.departure-empty p{margin:3px 0 0;color:#6a796e;font-size:.72rem;line-height:1.45}
.desk.dark[data-view="departures"] .departure-segments,.desk.dark[data-view="departures"] .departure-toolbar,.desk.dark[data-view="departures"] .departure-manifest,.desk.dark[data-view="departures"]>.departure-views{border-color:#294333;background:#13231a;color:#dceade}
.desk.dark[data-view="departures"]>.departure-views .desk-view-link{color:#bfd0c2}
.desk.dark[data-view="departures"]>.departure-views .desk-view-link.on{background:#21432c;color:#d8f0dd;box-shadow:inset 0 0 0 1px #36774d}
.desk.dark[data-view="departures"] .departure-segments a{color:#bfd0c2}
.desk.dark[data-view="departures"] .departure-segments a.is-active{background:#21432c;color:#d8f0dd;box-shadow:inset 0 0 0 1px #36774d}
.desk.dark[data-view="departures"] .departure-segments b{background:#20362a;color:#c0d1c4}
.desk.dark[data-view="departures"] .departure-toolbar input,.desk.dark[data-view="departures"] .departure-search-control{border-color:#304b38;background:#182a20;color:#e1eee4}
.desk.dark[data-view="departures"] .departure-date-field,.desk.dark[data-view="departures"] .departure-search-field{color:#b8c9bc}
.desk.dark[data-view="departures"] .departure-date-control>a{border-color:#365341;color:#b8e2c4}
.desk.dark[data-view="departures"] .departure-manifest-head,.desk.dark[data-view="departures"] .departure-table thead th{border-color:#294333;background:#172a1f;color:#b8c9bc}
.desk.dark[data-view="departures"] .departure-manifest-head h3,.desk.dark[data-view="departures"] .departure-guest-name,.desk.dark[data-view="departures"] .departure-room-number,.desk.dark[data-view="departures"] .departure-balance,.desk.dark[data-view="departures"] .departure-empty strong{color:#dceade}
.desk.dark[data-view="departures"] .departure-manifest-head p,.desk.dark[data-view="departures"] .departure-manifest-count,.desk.dark[data-view="departures"] .departure-booking-meta,.desk.dark[data-view="departures"] .departure-stay-meta,.desk.dark[data-view="departures"] .departure-room-type,.desk.dark[data-view="departures"] .departure-hk,.desk.dark[data-view="departures"] .departure-prep-note,.desk.dark[data-view="departures"] .departure-estimate,.desk.dark[data-view="departures"] .departure-estimate-label,.desk.dark[data-view="departures"] .departure-empty p{color:#a9bdae}
.desk.dark[data-view="departures"] .departure-estimate b{color:#bfd0c2}
.desk.dark[data-view="departures"] .departure-table tbody td{border-color:#263b31;color:#d5e2d8}
.desk.dark[data-view="departures"] .departure-table tbody tr:hover td{background:#192f22}
.desk.dark[data-view="departures"] .departure-booking-ref,.desk.dark[data-view="departures"] .departure-booking-meta a{color:#a4d7b1}
.desk.dark[data-view="departures"] .departure-hk-dirty,.desk.dark[data-view="departures"] .departure-hk-touch_up,.desk.dark[data-view="departures"] .departure-prep-attention{border-color:#725b2b;background:#3c321b;color:#f0d69a}
.desk.dark[data-view="departures"] .departure-prep-complete,.desk.dark[data-view="departures"] .departure-prep-pending{border-color:#365341;background:#1a3323;color:#c8d8cc}
.desk.dark[data-view="departures"] .departure-empty-mark{border-color:#365341;background:#1a3323;color:#b8e2c4}
@media(max-width:1050px){.departure-hero{grid-template-columns:1fr}.departure-summary{grid-template-columns:repeat(4,minmax(0,1fr))}.departure-summary .departure-summary-primary{grid-row:auto;min-height:72px}.departure-summary-primary strong{font-size:1.7rem!important}.departure-toolbar{grid-template-columns:minmax(220px,.9fr) minmax(260px,1.2fr) auto}}
@media(max-width:760px){.departure-toolbar{grid-template-columns:minmax(0,1fr) minmax(0,1.4fr)}.departure-reset{grid-column:1/-1;justify-self:end}.departure-table{min-width:950px}.departure-hero{padding:18px}.departure-summary{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:560px){.departure-hero{gap:15px;padding:16px;border-radius:14px}.departure-summary>div{min-height:65px;padding:8px 10px}.departure-summary-primary strong{font-size:1.5rem!important}.departure-hero h2{font-size:1.5rem}.departure-date-meta{display:grid;gap:5px}.departure-manifest-head{align-items:flex-start;padding:13px}.departure-manifest-head p{max-width:270px;line-height:1.4}.departure-empty{align-items:flex-start;flex-wrap:wrap}.departure-toolbar{grid-template-columns:minmax(0,1fr);gap:5px}.departure-date-field,.departure-search-field{grid-column:auto}.departure-date-control,.departure-search-control{grid-row:auto}.departure-reset{grid-column:auto;justify-self:start;margin:2px 0 5px}}
@media print{#desk[data-view="departures"]{gap:7px}.departure-hero{grid-template-columns:1fr;padding:12px;border:0;background:#fff!important;box-shadow:none;color:#17251b}.departure-hero h2,.departure-summary>div strong{color:#17251b}.departure-eyebrow,.departure-hero-copy>p,.departure-date-meta,.departure-date-meta b,.departure-summary>div span,.departure-summary>div small{color:#42564a}.departure-summary>div{min-height:50px;border-color:#dbe5dd;background:#fff}.departure-summary{grid-template-columns:repeat(4,1fr)}.departure-summary .departure-summary-primary{grid-row:auto;min-height:50px}.departure-summary-primary strong{font-size:1.4rem!important}.departure-hero-actions,.departure-segments,.departure-toolbar{display:none!important}.departure-manifest,.departure-table tbody td{border-color:#dfe7e0;box-shadow:none}.departure-table thead th{background:#f3f7f4!important;color:#48594d!important}.departure-actions{display:none}.departure-table{font-size:8pt}}
.arrival-hero{position:relative;isolation:isolate;display:grid;grid-template-columns:minmax(0,1.05fr) minmax(360px,.95fr);gap:24px;align-items:stretch;overflow:hidden;padding:23px 25px;border:1px solid #164d37;border-radius:18px;background:radial-gradient(circle at 78% 0%,rgba(163,216,176,.2),transparent 36%),linear-gradient(120deg,#102d21 0%,#145437 58%,#1b6842 100%);box-shadow:0 14px 30px rgba(16,58,40,.13);color:#f3faf4}
.arrival-hero:after{content:"";position:absolute;z-index:-1;right:34%;top:-128px;width:245px;height:245px;border:1px solid rgba(255,255,255,.11);border-radius:50%;box-shadow:0 0 0 30px rgba(255,255,255,.025),0 0 0 64px rgba(255,255,255,.018);pointer-events:none}
.arrival-hero-copy{min-width:0;align-self:center}
.arrival-eyebrow{display:flex;align-items:center;gap:8px;color:#c9ead3;font-size:.63rem;font-weight:850;letter-spacing:.14em}
.arrival-eyebrow i{font-style:normal;opacity:.6}
.arrival-hero h2{margin:10px 0 7px;color:#fff;font-size:clamp(1.55rem,2.4vw,2rem);line-height:1.08;letter-spacing:-.04em}
.arrival-hero-copy>p{max-width:520px;margin:0;color:#d9eadf;font-size:.82rem;line-height:1.5}
.arrival-date-meta{display:flex;flex-wrap:wrap;gap:7px 15px;margin-top:14px;color:#e2efe6;font-size:.72rem}
.arrival-date-meta span{display:flex;gap:6px;align-items:center}
.arrival-date-meta b{color:#b8ddc4;font-size:.66rem;font-weight:750;text-transform:uppercase;letter-spacing:.045em}
.arrival-hero-actions{display:flex;flex-wrap:wrap;gap:7px;margin-top:14px}
.arrival-hero-actions .btn{border-color:rgba(255,255,255,.3);background:rgba(255,255,255,.1);color:#fff}
.arrival-hero-actions .btn:hover{background:rgba(255,255,255,.18)}
.arrival-summary{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;align-content:center}
.arrival-summary>div{display:flex;min-height:72px;flex-direction:column;justify-content:center;gap:2px;padding:10px 13px;border:1px solid rgba(255,255,255,.18);border-radius:12px;background:rgba(255,255,255,.085);backdrop-filter:blur(5px)}
.arrival-summary>div span{color:#d5e9dc;font-size:.65rem;font-weight:750;letter-spacing:.055em;text-transform:uppercase}
.arrival-summary>div strong{color:#fff;font-size:1.35rem;line-height:1.1;font-variant-numeric:tabular-nums}
.arrival-summary>div small{color:#c5dfce;font-size:.64rem}
.arrival-summary .arrival-summary-primary{grid-row:span 2;min-height:100%;justify-content:center;padding:15px 17px;background:rgba(255,255,255,.13)}
.arrival-summary-primary strong{font-size:2.5rem!important;letter-spacing:-.05em}
.arrival-segments{display:flex;gap:5px;overflow-x:auto;padding:6px;border:1px solid #dfe9e1;border-radius:13px;background:#fff;box-shadow:0 3px 10px rgba(18,43,31,.035);scrollbar-width:thin}
.arrival-segments a{display:inline-flex;flex:none;align-items:center;gap:9px;min-height:36px;padding:0 11px;border-radius:9px;color:#52665a;font-size:.73rem;font-weight:750;text-decoration:none;white-space:nowrap}
.arrival-segments a:hover{background:#f1f7f2;color:#14532d}
.arrival-segments a.is-active{background:#e7f3ea;color:#14532d;box-shadow:inset 0 0 0 1px #c7e5ce}
.arrival-segments b{display:inline-grid;min-width:22px;height:21px;place-items:center;padding:0 5px;border-radius:999px;background:#edf2ee;color:#52665a;font-size:.65rem;font-variant-numeric:tabular-nums}
.arrival-segments a.is-active b{background:#d5ead9;color:#14532d}
.arrival-segments a:focus-visible,.arrival-toolbar a:focus-visible,.arrival-toolbar input:focus-visible,.arrival-toolbar button:focus-visible{outline:3px solid #4b9a67;outline-offset:2px}
.arrival-toolbar{display:grid;grid-template-columns:minmax(250px,.9fr) minmax(300px,1.3fr) auto;align-items:end;gap:8px 14px;padding:12px 14px;border:1px solid #dfe8e1;border-radius:13px;background:#fff;box-shadow:0 3px 10px rgba(18,43,31,.03)}
.arrival-date-group,.arrival-search-group{display:grid;min-width:0;gap:4px}
.arrival-date-field,.arrival-search-field{display:block;color:#465a4d;font-size:.67rem;font-weight:800;letter-spacing:.035em}
.arrival-date-control{display:flex;align-items:center;gap:4px;min-height:38px;margin-top:4px}
.arrival-date-control>a{display:inline-grid;min-width:34px;height:34px;place-items:center;border:1px solid #dce7de;border-radius:8px;background:#f8fbf8;color:#355b40;text-decoration:none;font-size:.73rem;font-weight:750}
.arrival-date-control>a:hover{background:#eaf4ec}
.arrival-date-control input{min-width:142px;height:34px;padding:0 8px;border:1px solid #dce7de;border-radius:8px;background:#fff;color:#203728;font:inherit;font-size:.73rem}
.arrival-date-control .arrival-today{min-width:48px;padding:0 8px}
.arrival-search-control{display:flex;align-items:center;gap:7px;min-height:38px;margin-top:4px;padding:3px 4px 3px 10px;border:1px solid #dce7de;border-radius:9px;background:#fff;color:#64756a}
.arrival-search-control:focus-within{outline:3px solid rgba(75,154,103,.2);border-color:#6ca87b}
.arrival-search-control>svg{flex:none}
.arrival-search-control input{width:100%;min-width:0;height:30px;border:0;outline:0;background:transparent;color:#203728;font:inherit;font-size:.74rem}
.arrival-search-control input::placeholder{color:#728176}
.arrival-search-control .btn{flex:none;min-height:31px}
.arrival-reset{align-self:center;color:#286243;font-size:.71rem;font-weight:700;text-decoration:none}
.arrival-reset:hover{text-decoration:underline}
.arrival-manifest{overflow:hidden;border:1px solid #dfe8e1;border-radius:15px;background:#fff;box-shadow:0 5px 16px rgba(18,43,31,.045)}
.arrival-manifest-head{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:15px 17px;border-bottom:1px solid #e7eee8;background:linear-gradient(180deg,#fff,#fbfdfb)}
.arrival-manifest-kicker{color:#578064;font-size:.59rem;font-weight:850;letter-spacing:.12em}
.arrival-manifest-head h3{margin:3px 0 2px;color:#203b2b;font-size:.98rem;letter-spacing:-.02em}
.arrival-manifest-head p{margin:0;color:#647469;font-size:.7rem}
.arrival-manifest-count{display:inline-flex;align-items:baseline;gap:6px;flex:none;padding:7px 10px;border:1px solid #dce9de;border-radius:9px;background:#f4f9f5;color:#52675a;font-size:.68rem;font-weight:650}
.arrival-manifest-count strong{color:#1c5a37;font-size:.95rem;font-variant-numeric:tabular-nums}
.arrival-table-wrap{width:100%;overflow-x:auto}
.arrival-table{width:100%;border-collapse:separate;border-spacing:0;color:#273b2e;font-size:.72rem}
.arrival-table thead th{padding:9px 11px;border-bottom:1px solid #e2ebe4;background:#f5f9f6;color:#607066;font-size:.62rem;font-weight:850;letter-spacing:.055em;text-align:left;text-transform:uppercase;white-space:nowrap}
.arrival-table tbody td{padding:12px 11px;border-bottom:1px solid #edf1ed;vertical-align:top}
.arrival-table tbody tr:last-child td{border-bottom:0}
.arrival-table tbody tr:hover td{background:#fbfdfb}
.arrival-guest-cell{display:flex;align-items:center;gap:7px;min-width:165px}
.arrival-guest-name{color:#173d29;font-size:.79rem;font-weight:800;text-decoration:none}
.arrival-guest-name:hover,.arrival-booking-ref:hover{color:#1c7544;text-decoration:underline}
.arrival-vip{padding:2px 6px;border:1px solid #f0d693;border-radius:999px;background:#fff7dc;color:#805a00;font-size:.57rem;font-weight:850;letter-spacing:.04em}
.arrival-booking-ref{display:inline-block;margin-top:3px;color:#2d6843;font-size:.66rem;font-weight:750;text-decoration:none}
.arrival-booking-meta{display:flex;flex-wrap:wrap;gap:3px 8px;margin-top:4px;color:#6a796e;font-size:.63rem}
.arrival-booking-meta span+span:before,.arrival-booking-meta a:before{content:"·";margin-right:7px;color:#99a89c}
.arrival-booking-meta a{color:#416b50;text-decoration:none}
.arrival-stay-dates{display:flex;align-items:center;gap:5px;color:#31483a;font-size:.67rem;font-weight:750;white-space:nowrap}
.arrival-stay-dates span{color:#8da094}
.arrival-stay-meta,.arrival-room-type,.arrival-hk,.arrival-prep-note{display:block;margin-top:5px;color:#6a796e;font-size:.63rem;line-height:1.4}
.arrival-room-number{display:block;color:#243b2d;font-size:.74rem}
.arrival-hk{color:#466950}
.arrival-hk-unassigned{color:#945717}
.arrival-eta{display:inline-flex;min-height:25px;align-items:center;padding:0 7px;border:1px solid #e5ebe6;border-radius:7px;background:#f8faf8;color:#68776c;font-size:.66rem;white-space:nowrap}
.arrival-eta.has-eta{border-color:#d4e7d8;background:#eff7f0;color:#275d39;font-weight:750}
.arrival-eta-alert{display:block;width:max-content;max-width:100%;margin-top:4px;padding:3px 6px;border:1px solid #f1d2c8;border-radius:6px;background:#fff2ee;color:#9c3528;font-size:.57rem;font-weight:800;line-height:1.25}
.arrival-prep-status{display:flex;flex-wrap:wrap;align-items:center;gap:4px}.arrival-prep{display:inline-flex;align-items:center;min-height:23px;padding:0 7px;border:1px solid #d5e7d8;border-radius:999px;background:#eff7f0;color:#28613b;font-size:.59rem;font-weight:850;white-space:nowrap}
.arrival-prep-attention{border-color:#f1d2c8;background:#fff2ee;color:#9c3528}
.arrival-prep-caution{border-color:#f0dfb4;background:#fff9e7;color:#835b0d}
.arrival-prep-complete{border-color:#dce4df;background:#f2f5f3;color:#54665a}
.arrival-prep-note{max-width:220px;margin-top:4px}
.arrival-balance{font-variant-numeric:tabular-nums;font-weight:750;white-space:nowrap}
.arrival-actions{min-width:150px;text-align:right;white-space:nowrap}
.arrival-actions .btn{margin:1px 0 1px 3px}
.arrival-empty{display:flex;align-items:center;gap:13px;padding:23px 18px;color:#596b5f}
.arrival-empty-mark{display:grid;width:38px;height:38px;flex:none;place-items:center;border:1px solid #d5e7d8;border-radius:12px;background:#eff7f0;color:#287044;font-size:1.15rem;font-weight:850}
.arrival-empty strong{display:block;color:#264332;font-size:.82rem}
.arrival-empty p{margin:3px 0 0;color:#6a796e;font-size:.7rem}
.arrival-empty .btn{margin-left:auto;flex:none}
.desk.dark[data-view="arrivals"] .arrival-segments,.desk.dark[data-view="arrivals"] .arrival-toolbar,.desk.dark[data-view="arrivals"] .arrival-manifest,.desk.dark[data-view="arrivals"]>.arrival-views{border-color:#294333;background:#13231a;color:#dceade}
.desk.dark[data-view="arrivals"]>.arrival-views .desk-view-link{color:#bfd0c2}
.desk.dark[data-view="arrivals"]>.arrival-views .desk-view-link.on{background:#21432c;color:#d8f0dd;box-shadow:inset 0 0 0 1px #36774d}
.desk.dark[data-view="arrivals"] .arrival-segments a{color:#bfd0c2}
.desk.dark[data-view="arrivals"] .arrival-segments a.is-active{background:#21432c;color:#d8f0dd;box-shadow:inset 0 0 0 1px #36774d}
.desk.dark[data-view="arrivals"] .arrival-segments b{background:#20362a;color:#c0d1c4}
.desk.dark[data-view="arrivals"] .arrival-date-field,.desk.dark[data-view="arrivals"] .arrival-search-field,.desk.dark[data-view="arrivals"] .arrival-manifest-head h3,.desk.dark[data-view="arrivals"] .arrival-guest-name,.desk.dark[data-view="arrivals"] .arrival-room-number,.desk.dark[data-view="arrivals"] .arrival-empty strong{color:#d7e9db}
.desk.dark[data-view="arrivals"] .arrival-date-control>a,.desk.dark[data-view="arrivals"] .arrival-date-control input,.desk.dark[data-view="arrivals"] .arrival-search-control{border-color:#30483a;background:#102018;color:#e1eee4}
.desk.dark[data-view="arrivals"] .arrival-search-control input{color:#e1eee4}
.desk.dark[data-view="arrivals"] .arrival-manifest-head,.desk.dark[data-view="arrivals"] .arrival-table thead th{border-color:#294333;background:#172a1f;color:#b8c9bc}
.desk.dark[data-view="arrivals"] .arrival-table tbody td{border-color:#263b31;color:#d5e2d8}
.desk.dark[data-view="arrivals"] .arrival-table tbody tr:hover td{background:#192f22}
.desk.dark[data-view="arrivals"] .arrival-booking-ref,.desk.dark[data-view="arrivals"] .arrival-booking-meta a{color:#a4d7b1}
.desk.dark[data-view="arrivals"] .arrival-booking-meta,.desk.dark[data-view="arrivals"] .arrival-stay-meta,.desk.dark[data-view="arrivals"] .arrival-room-type,.desk.dark[data-view="arrivals"] .arrival-hk,.desk.dark[data-view="arrivals"] .arrival-prep-note,.desk.dark[data-view="arrivals"] .arrival-manifest-head p,.desk.dark[data-view="arrivals"] .arrival-empty p{color:#a9bdae}
.desk.dark[data-view="arrivals"] .arrival-eta{border-color:#365341;background:#1a3323;color:#c8d8cc}
.desk.dark[data-view="arrivals"] .arrival-eta-alert{border-color:#74362e;background:#351c1a;color:#ffc4ba}
.desk.dark[data-view="arrivals"] .arrival-empty-mark{border-color:#365341;background:#1a3323;color:#b8e2c4}
@media(max-width:1050px){.arrival-hero{grid-template-columns:1fr}.arrival-summary{grid-template-columns:repeat(4,minmax(0,1fr))}.arrival-summary .arrival-summary-primary{grid-row:auto;min-height:72px}.arrival-summary-primary strong{font-size:1.7rem!important}.arrival-toolbar{grid-template-columns:minmax(220px,.9fr) minmax(260px,1.2fr) auto}}
@media(max-width:760px){.arrival-toolbar{grid-template-columns:minmax(0,1fr) minmax(0,1.4fr)}.arrival-reset{grid-column:1/-1;justify-self:end}.arrival-table{min-width:850px}.arrival-hero{padding:18px}.arrival-summary{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:560px){.arrival-hero{gap:15px;padding:16px;border-radius:14px}.arrival-summary>div{min-height:65px;padding:8px 10px}.arrival-summary-primary strong{font-size:1.5rem!important}.arrival-hero h2{font-size:1.5rem}.arrival-date-meta{display:grid;gap:5px}.arrival-manifest-head{align-items:flex-start;padding:13px}.arrival-manifest-head p{max-width:270px;line-height:1.4}.arrival-empty{align-items:flex-start;flex-wrap:wrap}.arrival-empty .btn{margin:0 0 0 51px}.arrival-toolbar{grid-template-columns:minmax(0,1fr);gap:5px}.arrival-date-field,.arrival-search-field{grid-column:auto}.arrival-date-control,.arrival-search-control{grid-row:auto}.arrival-reset{grid-column:auto;justify-self:start;margin:2px 0 5px}}
@media(prefers-reduced-motion:reduce){.arrival-segments a,.arrival-date-control>a,.arrival-search-control{scroll-behavior:auto;transition:none}}
@media print{#desk[data-view="arrivals"]{gap:7px}.arrival-hero{grid-template-columns:1fr;padding:12px;border:0;background:#fff!important;box-shadow:none;color:#17251b}.arrival-hero h2,.arrival-summary>div strong{color:#17251b}.arrival-eyebrow,.arrival-hero-copy>p,.arrival-date-meta,.arrival-date-meta b,.arrival-summary>div span,.arrival-summary>div small{color:#42564a}.arrival-summary>div{min-height:50px;border-color:#dbe5dd;background:#fff}.arrival-summary{grid-template-columns:repeat(4,1fr)}.arrival-summary .arrival-summary-primary{grid-row:auto;min-height:50px}.arrival-summary-primary strong{font-size:1.4rem!important}.arrival-hero-actions,.arrival-segments,.arrival-toolbar,.desk-views{display:none!important}.arrival-manifest,.arrival-table tbody td{border-color:#dfe7e0;box-shadow:none}.arrival-table thead th{background:#f3f7f4!important;color:#48594d!important}.arrival-actions{display:none}.arrival-table{font-size:8pt}}
.inhouse-hero{position:relative;isolation:isolate;display:grid;grid-template-columns:minmax(0,1fr) minmax(300px,.78fr);gap:22px;align-items:stretch;overflow:hidden;padding:23px 25px;border:1px solid #164d37;border-radius:18px;background:radial-gradient(circle at 75% 0%,rgba(240,195,113,.16),transparent 38%),linear-gradient(120deg,#102d21 0%,#145437 60%,#1c6843 100%);box-shadow:0 14px 30px rgba(16,58,40,.13);color:#f3faf4}
.inhouse-hero-copy{min-width:0;align-self:center}.inhouse-eyebrow,.inhouse-manifest-kicker{display:inline-flex;align-items:center;gap:6px;color:#d7e9dc;font-size:.62rem;font-weight:850;letter-spacing:.11em;text-transform:uppercase}.inhouse-eyebrow i{font-style:normal;opacity:.65}.inhouse-hero h2{margin:10px 0 7px;color:#fff;font-size:clamp(1.55rem,2.4vw,2rem);line-height:1.08;letter-spacing:-.04em}.inhouse-hero-copy>p{max-width:540px;margin:0;color:#d9eadf;font-size:.82rem;line-height:1.55}.inhouse-date-meta{display:flex;flex-wrap:wrap;gap:6px 17px;margin-top:13px;color:#d3e5d8;font-size:.68rem}.inhouse-date-meta b{margin-right:4px;color:#fff}.inhouse-hero-actions{display:flex;flex-wrap:wrap;gap:7px;margin-top:15px}.inhouse-hero .btn{min-height:35px;border-color:rgba(255,255,255,.28);background:rgba(255,255,255,.1);color:#fff}.inhouse-hero .btn:hover{background:rgba(255,255,255,.2)}
.inhouse-summary{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;align-content:center}.inhouse-summary>div{display:flex;min-height:67px;flex-direction:column;justify-content:center;gap:2px;padding:9px 12px;border:1px solid rgba(255,255,255,.18);border-radius:12px;background:rgba(255,255,255,.085);backdrop-filter:blur(5px)}.inhouse-summary>div span{color:#d7e7dc;font-size:.61rem;font-weight:750;letter-spacing:.045em;text-transform:uppercase}.inhouse-summary>div strong{color:#fff;font-size:1.15rem;font-variant-numeric:tabular-nums;line-height:1.1}.inhouse-summary>div small{color:#bfd6c6;font-size:.59rem}.inhouse-summary .inhouse-summary-primary{grid-column:1/-1;min-height:79px;padding:11px 14px;background:rgba(255,255,255,.13)}.inhouse-summary-primary strong{font-size:1.8rem!important}.inhouse-segments{display:flex;gap:7px;overflow-x:auto;padding:9px 13px;border-top:1px solid var(--c-border);border-bottom:1px solid var(--c-border);background:#fff;scrollbar-width:thin}.inhouse-segments a{display:inline-flex;min-height:34px;flex:none;align-items:center;gap:8px;padding:0 11px;border:1px solid #e5ece6;border-radius:999px;background:#f8faf8;color:#4a5e50;font-size:.68rem;font-weight:700;text-decoration:none;white-space:nowrap;transition:background .15s,border-color .15s,color .15s}.inhouse-segments a:hover{border-color:#b8d2bd;background:#eff7f0;color:#1e5e35}.inhouse-segments a.is-active{border-color:#1d6a3b;background:#174d2d;color:#fff;box-shadow:0 2px 7px rgba(20,77,45,.12)}.inhouse-segments b{display:inline-grid;min-width:20px;height:20px;place-items:center;padding:0 5px;border-radius:999px;background:#e8efe9;color:#52675a;font-size:.61rem;font-variant-numeric:tabular-nums}.inhouse-segments a.is-active b{background:rgba(255,255,255,.16);color:#fff}
.inhouse-toolbar{display:flex;align-items:flex-end;gap:14px;padding:12px 14px;border-bottom:1px solid var(--c-border);background:#f8faf8}.inhouse-search-group{flex:1;min-width:220px}.inhouse-search-group>label{display:block;margin-bottom:5px;color:#52675a;font-size:.65rem;font-weight:750}.inhouse-search-control{display:flex;min-height:39px;align-items:center;gap:8px;padding:3px 4px 3px 11px;border:1px solid #dbe5dd;border-radius:10px;background:#fff;color:#829187}.inhouse-search-control input{width:100%;min-width:0;border:0;outline:0;background:transparent;color:#24392b;font:inherit;font-size:.75rem}.inhouse-search-control .btn{flex:none}.inhouse-live-note{display:inline-flex;align-items:center;gap:7px;padding:0 2px 9px;color:#617267;font-size:.67rem;font-weight:700;white-space:nowrap}.inhouse-live-note i{width:8px;height:8px;border-radius:50%;background:#2e9b59;box-shadow:0 0 0 3px #e0f1e4}.inhouse-reset{padding:0 0 9px;color:#316744;font-size:.68rem;font-weight:700;text-decoration:none;white-space:nowrap}.inhouse-reset:hover{text-decoration:underline}
.inhouse-manifest{overflow:hidden;border:1px solid var(--c-border);border-radius:13px;background:#fff}.inhouse-manifest-head{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:14px 16px;border-bottom:1px solid #e5ece6;background:linear-gradient(180deg,#fff,#fbfdfb)}.inhouse-manifest-kicker{color:#357149;font-size:.58rem}.inhouse-manifest-head h3{margin:4px 0 3px;color:#23382a;font-size:.95rem;letter-spacing:-.015em}.inhouse-manifest-head p{margin:0;color:#718076;font-size:.67rem;line-height:1.4}.inhouse-manifest-count{display:inline-flex;align-items:baseline;gap:6px;flex:none;padding:7px 10px;border:1px solid #dce9de;border-radius:9px;background:#f4f9f5;color:#52675a;font-size:.68rem;font-weight:650}.inhouse-manifest-count strong{color:#1c5a37;font-size:.95rem;font-variant-numeric:tabular-nums}.inhouse-table-wrap{width:100%;overflow-x:auto}.inhouse-table{width:100%;min-width:930px;border-collapse:separate;border-spacing:0;color:#273b2e;font-size:.72rem}.inhouse-table thead th{padding:9px 11px;border-bottom:1px solid #e2ebe4;background:#f5f9f6;color:#607066;font-size:.62rem;font-weight:850;letter-spacing:.055em;text-align:left;text-transform:uppercase;white-space:nowrap}.inhouse-table tbody td{padding:12px 11px;border-bottom:1px solid #edf1ed;vertical-align:top}.inhouse-table tbody tr:last-child td{border-bottom:0}.inhouse-table tbody tr:hover td{background:#fbfdfb}.inhouse-guest-cell{display:flex;min-width:165px;align-items:center;gap:7px}.inhouse-guest-name{color:#173d29;font-size:.79rem;font-weight:800;text-decoration:none}.inhouse-guest-name:hover,.inhouse-booking-ref:hover{color:#1c7544;text-decoration:underline}.inhouse-vip{padding:2px 6px;border:1px solid #f0d693;border-radius:999px;background:#fff7dc;color:#805a00;font-size:.57rem;font-weight:850;letter-spacing:.04em}.inhouse-booking-ref{display:inline-block;margin-top:3px;color:#2d6843;font-size:.66rem;font-weight:750;text-decoration:none}.inhouse-booking-meta{display:flex;flex-wrap:wrap;gap:3px 8px;margin-top:4px;color:#6a796e;font-size:.63rem}.inhouse-booking-meta span+span:before,.inhouse-booking-meta a:before{content:"·";margin-right:7px;color:#99a89c}.inhouse-booking-meta a{color:#416b50;text-decoration:none}.inhouse-stay-dates{display:flex;align-items:center;gap:5px;color:#31483a;font-size:.67rem;font-weight:750;white-space:nowrap}.inhouse-stay-dates span{color:#8da094}.inhouse-stay-meta,.inhouse-room-type,.inhouse-hk{display:block;margin-top:5px;color:#6a796e;font-size:.63rem;line-height:1.4}.inhouse-departure{display:inline-flex;margin-top:6px;padding:3px 7px;border:1px solid #d5e7d8;border-radius:999px;background:#eff7f0;color:#28613b;font-size:.59rem;font-weight:850;white-space:nowrap}.inhouse-departure-due{border-color:#f0dfb4;background:#fff9e7;color:#835b0d}.inhouse-departure-overdue{border-color:#f1d2c8;background:#fff2ee;color:#9c3528}.inhouse-departure-stayover{border-color:#d5e7d8;background:#eff7f0;color:#28613b}.inhouse-room-number{display:block;color:#243b2d;font-size:.74rem;font-weight:800;text-decoration:none}.inhouse-room-number:hover{color:#1c7544;text-decoration:underline}.inhouse-hk{color:#466950}.inhouse-hk-unassigned{color:#945717}.inhouse-balance-label{display:block;color:#758279;font-size:.58rem}.inhouse-balance{display:block;margin-top:3px;color:#263b2e;font-size:.73rem;font-variant-numeric:tabular-nums;font-weight:800;white-space:nowrap}.inhouse-folio-link{display:inline-block;margin-top:5px;color:#2d6843;font-size:.62rem;font-weight:750;text-decoration:none}.inhouse-folio-link:hover{text-decoration:underline}.inhouse-actions{min-width:190px;text-align:right;white-space:nowrap}.inhouse-actions .btn{margin:1px 0 1px 3px}.inhouse-empty{display:flex;align-items:center;gap:13px;padding:23px 18px;color:#596b5f}.inhouse-empty-mark{display:grid;width:38px;height:38px;flex:none;place-items:center;border:1px solid #d5e7d8;border-radius:12px;background:#eff7f0;color:#287044;font-size:1.15rem;font-weight:850}.inhouse-empty strong{display:block;color:#264332;font-size:.82rem}.inhouse-empty p{margin:3px 0 0;color:#6a796e;font-size:.7rem}.inhouse-empty .btn{margin-left:auto;flex:none}
.desk.dark[data-view="inhouse"] .inhouse-segments,.desk.dark[data-view="inhouse"] .inhouse-toolbar,.desk.dark[data-view="inhouse"] .inhouse-manifest{border-color:#294333;background:#13231a;color:#dceade}.desk.dark[data-view="inhouse"]>.inhouse-views .desk-view-link{color:#bfd0c2}.desk.dark[data-view="inhouse"]>.inhouse-views .desk-view-link.on{background:#21432c;color:#d8f0dd;box-shadow:inset 0 0 0 1px #36774d}.desk.dark[data-view="inhouse"] .inhouse-segments a{border-color:#30483a;background:#172a1f;color:#bfd0c2}.desk.dark[data-view="inhouse"] .inhouse-segments a.is-active{border-color:#36774d;background:#21432c;color:#d8f0dd}.desk.dark[data-view="inhouse"] .inhouse-segments b{background:#20362a;color:#c0d1c4}.desk.dark[data-view="inhouse"] .inhouse-search-group>label,.desk.dark[data-view="inhouse"] .inhouse-manifest-head h3,.desk.dark[data-view="inhouse"] .inhouse-guest-name,.desk.dark[data-view="inhouse"] .inhouse-room-number,.desk.dark[data-view="inhouse"] .inhouse-empty strong{color:#d7e9db}.desk.dark[data-view="inhouse"] .inhouse-search-control{border-color:#30483a;background:#102018;color:#e1eee4}.desk.dark[data-view="inhouse"] .inhouse-search-control input{color:#e1eee4}.desk.dark[data-view="inhouse"] .inhouse-manifest-head,.desk.dark[data-view="inhouse"] .inhouse-table thead th{border-color:#294333;background:#172a1f;color:#b8c9bc}.desk.dark[data-view="inhouse"] .inhouse-table tbody td{border-color:#263b31;color:#d5e2d8}.desk.dark[data-view="inhouse"] .inhouse-table tbody tr:hover td{background:#192f22}.desk.dark[data-view="inhouse"] .inhouse-booking-ref,.desk.dark[data-view="inhouse"] .inhouse-booking-meta a,.desk.dark[data-view="inhouse"] .inhouse-folio-link,.desk.dark[data-view="inhouse"] .inhouse-reset{color:#a4d7b1}.desk.dark[data-view="inhouse"] .inhouse-booking-meta,.desk.dark[data-view="inhouse"] .inhouse-stay-meta,.desk.dark[data-view="inhouse"] .inhouse-room-type,.desk.dark[data-view="inhouse"] .inhouse-hk,.desk.dark[data-view="inhouse"] .inhouse-manifest-head p,.desk.dark[data-view="inhouse"] .inhouse-empty p,.desk.dark[data-view="inhouse"] .inhouse-live-note{color:#a9bdae}.desk.dark[data-view="inhouse"] .inhouse-balance-label{color:#a9bdae}.desk.dark[data-view="inhouse"] .inhouse-balance{color:#e0eee3}.desk.dark[data-view="inhouse"] .inhouse-departure-stayover{border-color:#365341;background:#1a3323;color:#b8e2c4}.desk.dark[data-view="inhouse"] .inhouse-departure-due{border-color:#67512a;background:#332916;color:#f3d58d}.desk.dark[data-view="inhouse"] .inhouse-departure-overdue{border-color:#74362e;background:#351c1a;color:#ffc4ba}.desk.dark[data-view="inhouse"] .inhouse-empty-mark{border-color:#365341;background:#1a3323;color:#b8e2c4}
@media(max-width:1050px){.inhouse-hero{grid-template-columns:1fr}.inhouse-summary{grid-template-columns:repeat(4,minmax(0,1fr))}.inhouse-summary .inhouse-summary-primary{grid-column:auto;min-height:67px}.inhouse-summary-primary strong{font-size:1.55rem!important}}
@media(max-width:760px){.inhouse-hero{padding:18px}.inhouse-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.inhouse-toolbar{align-items:stretch;flex-wrap:wrap;gap:9px}.inhouse-search-group{flex-basis:100%}.inhouse-live-note,.inhouse-reset{padding-bottom:0}.inhouse-manifest-head{align-items:flex-start;padding:13px}.inhouse-manifest-head p{max-width:300px;line-height:1.4}.inhouse-table{min-width:930px}}
@media(max-width:560px){.inhouse-hero{gap:15px;padding:16px;border-radius:14px}.inhouse-summary>div{min-height:62px;padding:8px 10px}.inhouse-summary-primary strong{font-size:1.45rem!important}.inhouse-hero h2{font-size:1.5rem}.inhouse-date-meta{display:grid;gap:5px}.inhouse-manifest-head{gap:9px;flex-direction:column}.inhouse-manifest-count{align-self:flex-start}.inhouse-empty{align-items:flex-start;flex-wrap:wrap}.inhouse-empty .btn{margin:0 0 0 51px}.inhouse-toolbar{padding:10px}.inhouse-search-group{min-width:0}.inhouse-live-note{margin-right:auto}}
@media(prefers-reduced-motion:reduce){.inhouse-segments a{scroll-behavior:auto;transition:none}}
@media print{#desk[data-view="inhouse"]{gap:7px}.inhouse-hero{grid-template-columns:1fr;padding:12px;border:0;background:#fff!important;box-shadow:none;color:#17251b}.inhouse-hero h2,.inhouse-summary>div strong{color:#17251b}.inhouse-eyebrow,.inhouse-hero-copy>p,.inhouse-date-meta,.inhouse-date-meta b,.inhouse-summary>div span,.inhouse-summary>div small{color:#42564a}.inhouse-summary{grid-template-columns:repeat(4,1fr)}.inhouse-summary .inhouse-summary-primary{grid-column:auto;min-height:50px}.inhouse-summary>div{min-height:50px;border-color:#dbe5dd;background:#fff}.inhouse-summary-primary strong{font-size:1.35rem!important}.inhouse-hero-actions,.inhouse-segments,.inhouse-toolbar,.desk-views{display:none!important}.inhouse-manifest,.inhouse-table tbody td{border-color:#dfe7e0;box-shadow:none}.inhouse-table thead th{background:#f3f7f4!important;color:#48594d!important}.inhouse-actions{display:none}.inhouse-table{font-size:8pt}}
.unassigned-hero{position:relative;isolation:isolate;display:grid;grid-template-columns:minmax(0,1fr) minmax(310px,.82fr);gap:22px;align-items:stretch;overflow:hidden;padding:23px 25px;border:1px solid #164d37;border-radius:18px;background:radial-gradient(circle at 75% 0%,rgba(234,190,107,.17),transparent 38%),linear-gradient(120deg,#102d21 0%,#145437 60%,#1c6843 100%);box-shadow:0 14px 30px rgba(16,58,40,.13);color:#f3faf4}
.unassigned-hero-copy{min-width:0;align-self:center}.unassigned-eyebrow,.unassigned-manifest-kicker{display:inline-flex;align-items:center;gap:6px;color:#d7e9dc;font-size:.62rem;font-weight:850;letter-spacing:.11em;text-transform:uppercase}.unassigned-eyebrow i{font-style:normal;opacity:.65}.unassigned-hero h2{margin:10px 0 7px;color:#fff;font-size:clamp(1.55rem,2.4vw,2rem);line-height:1.08;letter-spacing:-.04em}.unassigned-hero-copy>p{max-width:550px;margin:0;color:#d9eadf;font-size:.82rem;line-height:1.55}.unassigned-date-meta{display:flex;flex-wrap:wrap;gap:6px 17px;margin-top:13px;color:#d3e5d8;font-size:.68rem}.unassigned-date-meta b{margin-right:4px;color:#fff}.unassigned-hero-actions{display:flex;flex-wrap:wrap;gap:7px;margin-top:15px}.unassigned-hero .btn{min-height:35px;border-color:rgba(255,255,255,.28);background:rgba(255,255,255,.1);color:#fff}.unassigned-hero .btn:hover{background:rgba(255,255,255,.2)}
.unassigned-summary{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;align-content:center}.unassigned-summary>div{display:flex;min-height:65px;flex-direction:column;justify-content:center;gap:2px;padding:9px 12px;border:1px solid rgba(255,255,255,.18);border-radius:12px;background:rgba(255,255,255,.085);backdrop-filter:blur(5px)}.unassigned-summary>div span{color:#d7e7dc;font-size:.61rem;font-weight:750;letter-spacing:.045em;text-transform:uppercase}.unassigned-summary>div strong{color:#fff;font-size:1.15rem;font-variant-numeric:tabular-nums;line-height:1.1}.unassigned-summary>div small{color:#bfd6c6;font-size:.59rem}.unassigned-summary .unassigned-summary-primary{grid-column:1/-1;min-height:77px;padding:11px 14px;background:rgba(255,255,255,.13)}.unassigned-summary-primary strong{font-size:1.8rem!important}
.unassigned-segments{display:flex;gap:7px;overflow-x:auto;padding:9px 13px;border-top:1px solid var(--c-border);border-bottom:1px solid var(--c-border);background:#fff;scrollbar-width:thin}.unassigned-segments a{display:inline-flex;min-height:34px;flex:none;align-items:center;gap:8px;padding:0 11px;border:1px solid #e5ece6;border-radius:999px;background:#f8faf8;color:#4a5e50;font-size:.68rem;font-weight:700;text-decoration:none;white-space:nowrap;transition:background .15s,border-color .15s,color .15s}.unassigned-segments a:hover{border-color:#b8d2bd;background:#eff7f0;color:#1e5e35}.unassigned-segments a.is-active{border-color:#1d6a3b;background:#174d2d;color:#fff;box-shadow:0 2px 7px rgba(20,77,45,.12)}.unassigned-segments b{display:inline-grid;min-width:20px;height:20px;place-items:center;padding:0 5px;border-radius:999px;background:#e8efe9;color:#52675a;font-size:.61rem;font-variant-numeric:tabular-nums}.unassigned-segments a.is-active b{background:rgba(255,255,255,.16);color:#fff}
.unassigned-toolbar{display:grid;grid-template-columns:minmax(240px,.8fr) minmax(300px,1.2fr) auto;align-items:end;gap:12px;padding:12px 14px;border-bottom:1px solid var(--c-border);background:#f8faf8}.unassigned-date-field,.unassigned-search-field{display:block;margin-bottom:5px;color:#52675a;font-size:.65rem;font-weight:750}.unassigned-date-control{display:flex;min-height:39px;align-items:center;gap:5px}.unassigned-date-control>a{display:grid;width:33px;height:34px;flex:none;place-items:center;border:1px solid #dbe5dd;border-radius:8px;background:#fff;color:#355644;font-size:1.1rem;text-decoration:none;transition:background .15s,border-color .15s}.unassigned-date-control>a:hover{border-color:#a8c7af;background:#eff7f0}.unassigned-date-control input{min-width:0;flex:1;height:34px;padding:0 7px;border:1px solid #dbe5dd;border-radius:8px;background:#fff;color:#24392b;font:inherit;font-size:.7rem}.unassigned-date-control .unassigned-today{width:auto;padding:0 9px;font-size:.65rem;font-weight:750}.unassigned-search-control{display:flex;min-height:39px;align-items:center;gap:8px;padding:3px 4px 3px 11px;border:1px solid #dbe5dd;border-radius:10px;background:#fff;color:#829187}.unassigned-search-control input{width:100%;min-width:0;border:0;outline:0;background:transparent;color:#24392b;font:inherit;font-size:.75rem}.unassigned-search-control .btn{flex:none}.unassigned-reset{padding:0 0 9px;color:#316744;font-size:.68rem;font-weight:700;text-decoration:none;white-space:nowrap}.unassigned-reset:hover{text-decoration:underline}
.unassigned-manifest{overflow:hidden;border:1px solid var(--c-border);border-radius:13px;background:#fff}.unassigned-manifest-head{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:14px 16px;border-bottom:1px solid #e5ece6;background:linear-gradient(180deg,#fff,#fbfdfb)}.unassigned-manifest-kicker{color:#357149;font-size:.58rem}.unassigned-manifest-head h3{margin:4px 0 3px;color:#23382a;font-size:.95rem;letter-spacing:-.015em}.unassigned-manifest-head p{margin:0;color:#718076;font-size:.67rem;line-height:1.4}.unassigned-manifest-count{display:inline-flex;align-items:baseline;gap:6px;flex:none;padding:7px 10px;border:1px solid #dce9de;border-radius:9px;background:#f4f9f5;color:#52675a;font-size:.68rem;font-weight:650}.unassigned-manifest-count strong{color:#1c5a37;font-size:.95rem;font-variant-numeric:tabular-nums}.unassigned-table-wrap{width:100%;overflow-x:auto}.unassigned-table{width:100%;min-width:980px;border-collapse:separate;border-spacing:0;color:#273b2e;font-size:.72rem}.unassigned-table thead th{padding:9px 11px;border-bottom:1px solid #e2ebe4;background:#f5f9f6;color:#607066;font-size:.62rem;font-weight:850;letter-spacing:.055em;text-align:left;text-transform:uppercase;white-space:nowrap}.unassigned-table tbody td{padding:12px 11px;border-bottom:1px solid #edf1ed;vertical-align:top}.unassigned-table tbody tr:last-child td{border-bottom:0}.unassigned-table tbody tr:hover td{background:#fbfdfb}.unassigned-guest-cell{display:flex;min-width:165px;align-items:center;gap:7px}.unassigned-guest-name{color:#173d29;font-size:.79rem;font-weight:800;text-decoration:none}.unassigned-guest-name:hover,.unassigned-booking-ref:hover{color:#1c7544;text-decoration:underline}.unassigned-vip{padding:2px 6px;border:1px solid #f0d693;border-radius:999px;background:#fff7dc;color:#805a00;font-size:.57rem;font-weight:850;letter-spacing:.04em}.unassigned-booking-ref{display:inline-block;margin-top:3px;color:#2d6843;font-size:.66rem;font-weight:750;text-decoration:none}.unassigned-booking-meta{display:flex;flex-wrap:wrap;gap:3px 8px;margin-top:4px;color:#6a796e;font-size:.63rem}.unassigned-booking-meta span+span:before,.unassigned-booking-meta a:before{content:"·";margin-right:7px;color:#99a89c}.unassigned-booking-meta a{color:#416b50;text-decoration:none}.unassigned-stay-dates{display:flex;align-items:center;gap:5px;color:#31483a;font-size:.67rem;font-weight:750;white-space:nowrap}.unassigned-stay-dates span{color:#8da094}.unassigned-stay-meta,.unassigned-room-code,.unassigned-assignment-note{display:block;margin-top:5px;color:#6a796e;font-size:.63rem;line-height:1.4}.unassigned-arrival{display:inline-flex;margin-top:6px;padding:3px 7px;border:1px solid #d5e7d8;border-radius:999px;background:#eff7f0;color:#28613b;font-size:.59rem;font-weight:850;white-space:nowrap}.unassigned-arrival-due{border-color:#f0dfb4;background:#fff9e7;color:#835b0d}.unassigned-arrival-overdue{border-color:#f1d2c8;background:#fff2ee;color:#9c3528}.unassigned-arrival-upcoming{border-color:#d5e7d8;background:#eff7f0;color:#28613b}.unassigned-room-type{display:block;color:#243b2d;font-size:.74rem;font-weight:800}.unassigned-status{display:inline-flex;padding:4px 8px;border:1px solid #f0dfb4;border-radius:999px;background:#fff9e7;color:#835b0d;font-size:.6rem;font-weight:850;white-space:nowrap}.unassigned-assignment-note{max-width:220px;margin-top:5px}.unassigned-actions{min-width:180px;text-align:right;white-space:nowrap}.unassigned-actions .btn{margin:1px 0 1px 3px}.unassigned-empty{display:flex;align-items:center;gap:13px;padding:23px 18px;color:#596b5f}.unassigned-empty-mark{display:grid;width:38px;height:38px;flex:none;place-items:center;border:1px solid #d5e7d8;border-radius:12px;background:#eff7f0;color:#287044;font-size:1.15rem;font-weight:850}.unassigned-empty strong{display:block;color:#264332;font-size:.82rem}.unassigned-empty p{margin:3px 0 0;color:#6a796e;font-size:.7rem}.unassigned-empty .btn{margin-left:auto;flex:none}
.desk.dark[data-view="unassigned"] .unassigned-segments,.desk.dark[data-view="unassigned"] .unassigned-toolbar,.desk.dark[data-view="unassigned"] .unassigned-manifest{border-color:#294333;background:#13231a;color:#dceade}.desk.dark[data-view="unassigned"]>.unassigned-views .desk-view-link{color:#bfd0c2}.desk.dark[data-view="unassigned"]>.unassigned-views .desk-view-link.on{background:#21432c;color:#d8f0dd;box-shadow:inset 0 0 0 1px #36774d}.desk.dark[data-view="unassigned"] .unassigned-segments a{border-color:#30483a;background:#172a1f;color:#bfd0c2}.desk.dark[data-view="unassigned"] .unassigned-segments a.is-active{border-color:#36774d;background:#21432c;color:#d8f0dd}.desk.dark[data-view="unassigned"] .unassigned-segments b{background:#20362a;color:#c0d1c4}.desk.dark[data-view="unassigned"] .unassigned-date-field,.desk.dark[data-view="unassigned"] .unassigned-search-field,.desk.dark[data-view="unassigned"] .unassigned-manifest-head h3,.desk.dark[data-view="unassigned"] .unassigned-guest-name,.desk.dark[data-view="unassigned"] .unassigned-room-type,.desk.dark[data-view="unassigned"] .unassigned-empty strong{color:#d7e9db}.desk.dark[data-view="unassigned"] .unassigned-date-control>a,.desk.dark[data-view="unassigned"] .unassigned-date-control input,.desk.dark[data-view="unassigned"] .unassigned-search-control{border-color:#30483a;background:#102018;color:#e1eee4}.desk.dark[data-view="unassigned"] .unassigned-search-control input{color:#e1eee4}.desk.dark[data-view="unassigned"] .unassigned-manifest-head,.desk.dark[data-view="unassigned"] .unassigned-table thead th{border-color:#294333;background:#172a1f;color:#b8c9bc}.desk.dark[data-view="unassigned"] .unassigned-table tbody td{border-color:#263b31;color:#d5e2d8}.desk.dark[data-view="unassigned"] .unassigned-table tbody tr:hover td{background:#192f22}.desk.dark[data-view="unassigned"] .unassigned-booking-ref,.desk.dark[data-view="unassigned"] .unassigned-booking-meta a,.desk.dark[data-view="unassigned"] .unassigned-reset{color:#a4d7b1}.desk.dark[data-view="unassigned"] .unassigned-booking-meta,.desk.dark[data-view="unassigned"] .unassigned-stay-meta,.desk.dark[data-view="unassigned"] .unassigned-room-code,.desk.dark[data-view="unassigned"] .unassigned-assignment-note,.desk.dark[data-view="unassigned"] .unassigned-manifest-head p,.desk.dark[data-view="unassigned"] .unassigned-empty p{color:#a9bdae}.desk.dark[data-view="unassigned"] .unassigned-arrival-upcoming{border-color:#365341;background:#1a3323;color:#b8e2c4}.desk.dark[data-view="unassigned"] .unassigned-arrival-due,.desk.dark[data-view="unassigned"] .unassigned-status{border-color:#67512a;background:#332916;color:#f3d58d}.desk.dark[data-view="unassigned"] .unassigned-arrival-overdue{border-color:#74362e;background:#351c1a;color:#ffc4ba}.desk.dark[data-view="unassigned"] .unassigned-empty-mark{border-color:#365341;background:#1a3323;color:#b8e2c4}
@media(max-width:1050px){.unassigned-hero{grid-template-columns:1fr}.unassigned-summary{grid-template-columns:repeat(4,minmax(0,1fr))}.unassigned-summary .unassigned-summary-primary{grid-column:auto;min-height:65px}.unassigned-summary-primary strong{font-size:1.55rem!important}}
@media(max-width:760px){.unassigned-hero{padding:18px}.unassigned-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.unassigned-toolbar{grid-template-columns:minmax(0,1fr) minmax(0,1.3fr)}.unassigned-table{min-width:980px}.unassigned-reset{grid-column:1/-1;justify-self:end}}
@media(max-width:560px){.unassigned-hero{gap:15px;padding:16px;border-radius:14px}.unassigned-summary>div{min-height:62px;padding:8px 10px}.unassigned-summary .unassigned-summary-primary{grid-column:1/-1}.unassigned-summary-primary strong{font-size:1.45rem!important}.unassigned-hero h2{font-size:1.5rem}.unassigned-date-meta{display:grid;gap:5px}.unassigned-manifest-head{align-items:flex-start;flex-direction:column;padding:13px}.unassigned-empty{align-items:flex-start;flex-wrap:wrap}.unassigned-empty .btn{margin:0 0 0 51px}.unassigned-toolbar{grid-template-columns:minmax(0,1fr);gap:5px}.unassigned-date-field,.unassigned-search-field{grid-column:auto}.unassigned-date-control,.unassigned-search-control{grid-row:auto}.unassigned-reset{grid-column:auto;justify-self:start;margin:2px 0 5px}}
@media(prefers-reduced-motion:reduce){.unassigned-segments a,.unassigned-date-control>a{scroll-behavior:auto;transition:none}}
@media print{#desk[data-view="unassigned"]{gap:7px}.unassigned-hero{grid-template-columns:1fr;padding:12px;border:0;background:#fff!important;box-shadow:none;color:#17251b}.unassigned-hero h2,.unassigned-summary>div strong{color:#17251b}.unassigned-eyebrow,.unassigned-hero-copy>p,.unassigned-date-meta,.unassigned-date-meta b,.unassigned-summary>div span,.unassigned-summary>div small{color:#42564a}.unassigned-summary{grid-template-columns:repeat(4,1fr)}.unassigned-summary .unassigned-summary-primary{grid-column:auto;min-height:50px}.unassigned-summary>div{min-height:50px;border-color:#dbe5dd;background:#fff}.unassigned-summary-primary strong{font-size:1.35rem!important}.unassigned-hero-actions,.unassigned-segments,.unassigned-toolbar,.desk-views{display:none!important}.unassigned-manifest,.unassigned-table tbody td{border-color:#dfe7e0;box-shadow:none}.unassigned-table thead th{background:#f3f7f4!important;color:#48594d!important}.unassigned-actions{display:none}.unassigned-table{font-size:8pt}}
.waitlist-hero{position:relative;isolation:isolate;display:grid;grid-template-columns:minmax(0,1fr) minmax(360px,.95fr);gap:22px;align-items:stretch;overflow:hidden;padding:23px 25px;border:1px solid #164d37;border-radius:18px;background:radial-gradient(circle at 77% 0%,rgba(234,190,107,.16),transparent 38%),linear-gradient(120deg,#102d21 0%,#145437 60%,#1c6843 100%);box-shadow:0 14px 30px rgba(16,58,40,.13);color:#f3faf4}.waitlist-hero-copy{min-width:0;align-self:center}.waitlist-eyebrow,.waitlist-section-kicker{display:inline-flex;align-items:center;gap:6px;color:#d7e9dc;font-size:.62rem;font-weight:850;letter-spacing:.11em;text-transform:uppercase}.waitlist-eyebrow i{font-style:normal;opacity:.65}.waitlist-hero h2{margin:10px 0 7px;color:#fff;font-size:clamp(1.5rem,2.35vw,1.95rem);line-height:1.08;letter-spacing:-.04em}.waitlist-hero-copy>p{max-width:600px;margin:0;color:#d9eadf;font-size:.8rem;line-height:1.55}.waitlist-date-meta{display:flex;flex-wrap:wrap;gap:6px 17px;margin-top:13px;color:#d3e5d8;font-size:.67rem}.waitlist-date-meta b{margin-right:4px;color:#fff}.waitlist-hero-actions{display:flex;flex-wrap:wrap;gap:7px;margin-top:15px}.waitlist-hero .btn{min-height:35px;border-color:rgba(255,255,255,.28);background:rgba(255,255,255,.1);color:#fff}.waitlist-hero .btn:hover{background:rgba(255,255,255,.2)}.waitlist-summary{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;align-content:center}.waitlist-summary>div{display:flex;min-height:62px;flex-direction:column;justify-content:center;gap:2px;padding:9px 11px;border:1px solid rgba(255,255,255,.18);border-radius:12px;background:rgba(255,255,255,.085);backdrop-filter:blur(5px)}.waitlist-summary>div span{color:#d7e7dc;font-size:.59rem;font-weight:750;letter-spacing:.045em;text-transform:uppercase}.waitlist-summary>div strong{color:#fff;font-size:1.1rem;font-variant-numeric:tabular-nums;line-height:1.1}.waitlist-summary>div small{color:#bfd6c6;font-size:.57rem}.waitlist-summary .waitlist-summary-primary{grid-column:1/-1;min-height:70px;padding:10px 13px;background:rgba(255,255,255,.13)}.waitlist-summary-primary strong{font-size:1.65rem!important}
.waitlist-segments{display:flex;gap:7px;overflow-x:auto;padding:9px 13px;border-top:1px solid var(--c-border);border-bottom:1px solid var(--c-border);background:#fff;scrollbar-width:thin}.waitlist-segments a{display:inline-flex;min-height:34px;flex:none;align-items:center;gap:8px;padding:0 11px;border:1px solid #e5ece6;border-radius:999px;background:#f8faf8;color:#4a5e50;font-size:.67rem;font-weight:700;text-decoration:none;white-space:nowrap;transition:background .15s,border-color .15s,color .15s}.waitlist-segments a:hover{border-color:#b8d2bd;background:#eff7f0;color:#1e5e35}.waitlist-segments a.is-active{border-color:#1d6a3b;background:#174d2d;color:#fff;box-shadow:0 2px 7px rgba(20,77,45,.12)}.waitlist-segments b{display:inline-grid;min-width:20px;height:20px;place-items:center;padding:0 5px;border-radius:999px;background:#e8efe9;color:#52675a;font-size:.6rem;font-variant-numeric:tabular-nums}.waitlist-segments a.is-active b{background:rgba(255,255,255,.16);color:#fff}
.waitlist-toolbar{display:grid;grid-template-columns:minmax(250px,.8fr) minmax(320px,1.2fr) auto;align-items:end;gap:12px;padding:12px 14px;border-bottom:1px solid var(--c-border);background:#f8faf8}.waitlist-date-field,.waitlist-search-field{display:block;margin-bottom:5px;color:#52675a;font-size:.65rem;font-weight:750}.waitlist-date-control{display:flex;min-height:39px;align-items:center;gap:5px}.waitlist-date-control>a{display:grid;width:33px;height:34px;flex:none;place-items:center;border:1px solid #dbe5dd;border-radius:8px;background:#fff;color:#355644;font-size:1.1rem;text-decoration:none;transition:background .15s,border-color .15s}.waitlist-date-control>a:hover{border-color:#a8c7af;background:#eff7f0}.waitlist-date-control input{min-width:0;flex:1;height:34px;padding:0 7px;border:1px solid #dbe5dd;border-radius:8px;background:#fff;color:#24392b;font:inherit;font-size:.7rem}.waitlist-date-control .waitlist-today{width:auto;padding:0 9px;font-size:.64rem;font-weight:750}.waitlist-search-control{display:flex;min-height:39px;align-items:center;gap:8px;padding:3px 4px 3px 11px;border:1px solid #dbe5dd;border-radius:10px;background:#fff;color:#829187}.waitlist-search-control input{width:100%;min-width:0;border:0;outline:0;background:transparent;color:#24392b;font:inherit;font-size:.74rem}.waitlist-search-control .btn{flex:none}.waitlist-reset{padding:0 0 9px;color:#316744;font-size:.67rem;font-weight:700;text-decoration:none;white-space:nowrap}.waitlist-reset:hover{text-decoration:underline}
.waitlist-add-form{overflow:hidden;border:1px solid #dbe7dc;border-radius:14px;background:#fff;box-shadow:0 4px 14px rgba(24,57,35,.045)}.waitlist-add-head{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:15px 17px 12px;border-bottom:1px solid #e8efe9;background:linear-gradient(180deg,#fff,#f9fcfa)}.waitlist-add-head .waitlist-section-kicker,.waitlist-manifest-head .waitlist-section-kicker{color:#357149;font-size:.57rem}.waitlist-add-head h3{margin:4px 0 3px;color:#23382a;font-size:.93rem;letter-spacing:-.015em}.waitlist-add-head p{margin:0;color:#718076;font-size:.67rem;line-height:1.4}.waitlist-add-icon{display:grid;width:34px;height:34px;flex:none;place-items:center;border:1px solid #d6e8d9;border-radius:11px;background:#eff7f0;color:#28613b;font-size:1.15rem;font-weight:700}.waitlist-form-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;padding:13px 16px}.waitlist-field{min-width:0}.waitlist-field-name{grid-column:span 2}.waitlist-field-notes{grid-column:span 2}.waitlist-field label{display:block;margin-bottom:4px;color:#52675a;font-size:.64rem;font-weight:750}.waitlist-field label>span:first-child{color:#a13e32}.waitlist-label-hint{float:right;color:#89978d;font-size:.58rem;font-weight:600}.waitlist-field input,.waitlist-field select{width:100%;min-width:0;height:36px;padding:0 9px;border:1px solid #dbe5dd;border-radius:8px;background:#fff;color:#24392b;font:inherit;font-size:.71rem;outline:0;transition:border-color .15s,box-shadow .15s}.waitlist-field input:focus,.waitlist-field select:focus{border-color:#5c9b6b;box-shadow:0 0 0 3px rgba(53,113,73,.12)}.waitlist-field input[aria-invalid="true"]{border-color:#bd574b}.waitlist-field-error{display:block;margin-top:3px;color:#a13e32;font-size:.61rem}.waitlist-add-footer{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 16px;border-top:1px solid #e8efe9;background:#f9fcfa}.waitlist-add-footer p{margin:0;color:#637368;font-size:.64rem;line-height:1.45}.waitlist-add-footer p strong{color:#355644}.waitlist-add-footer .btn{flex:none;min-height:35px}
.waitlist-manifest{overflow:hidden;border:1px solid var(--c-border);border-radius:13px;background:#fff}.waitlist-manifest-head{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:14px 16px;border-bottom:1px solid #e5ece6;background:linear-gradient(180deg,#fff,#fbfdfb)}.waitlist-manifest-head h3{margin:4px 0 3px;color:#23382a;font-size:.95rem;letter-spacing:-.015em}.waitlist-manifest-head p{margin:0;color:#718076;font-size:.67rem;line-height:1.4}.waitlist-manifest-count{display:flex;align-items:baseline;gap:5px;flex:none;padding:7px 10px;border:1px solid #dce9de;border-radius:9px;background:#f4f9f5;color:#52675a;font-size:.65rem;font-weight:650}.waitlist-manifest-count strong{color:#1c5a37;font-size:.92rem;font-variant-numeric:tabular-nums}.waitlist-manifest-count small{font-size:.58rem;font-weight:600}.waitlist-table-wrap{width:100%;overflow-x:auto}.waitlist-table{width:100%;min-width:1060px;border-collapse:separate;border-spacing:0;color:#273b2e;font-size:.7rem}.waitlist-table thead th{padding:9px 10px;border-bottom:1px solid #e2ebe4;background:#f5f9f6;color:#607066;font-size:.6rem;font-weight:850;letter-spacing:.055em;text-align:left;text-transform:uppercase;white-space:nowrap}.waitlist-table tbody td{padding:11px 10px;border-bottom:1px solid #edf1ed;vertical-align:top}.waitlist-table tbody tr:last-child td{border-bottom:0}.waitlist-table tbody tr:hover td{background:#fbfdfb}.waitlist-priority{display:inline-flex;min-width:67px;flex-direction:column;align-items:center;gap:2px;padding:5px 7px;border:1px solid #dce7df;border-radius:9px;background:#f7faf7;color:#54665a}.waitlist-priority strong{font-size:.85rem;font-variant-numeric:tabular-nums;line-height:1}.waitlist-priority span{font-size:.53rem;font-weight:800;letter-spacing:.035em;text-transform:uppercase}.waitlist-priority-high{border-color:#f0d3c8;background:#fff3ef;color:#9a3e2e}.waitlist-priority-raised{border-color:#efdfb8;background:#fff9e8;color:#806018}.waitlist-guest-name{display:block;max-width:230px;color:#173d29;font-size:.76rem;font-weight:800;overflow-wrap:anywhere}.waitlist-vip{display:inline-flex;margin-top:3px;padding:2px 6px;border:1px solid #f0d693;border-radius:999px;background:#fff7dc;color:#805a00;font-size:.55rem;font-weight:850;letter-spacing:.04em}.waitlist-contact{display:flex;flex-direction:column;gap:2px;margin-top:4px;color:#637368;font-size:.62rem;overflow-wrap:anywhere}.waitlist-notes{max-width:250px;margin-top:5px;color:#596b5f;font-size:.62rem;line-height:1.4;overflow-wrap:anywhere}.waitlist-created{display:block;margin-top:5px;color:#89978d;font-size:.57rem}.waitlist-stay-dates{display:flex;align-items:center;gap:5px;color:#31483a;font-size:.66rem;font-weight:750;white-space:nowrap}.waitlist-stay-dates span{color:#8da094}.waitlist-stay-meta,.waitlist-room-note{display:block;margin-top:5px;color:#6a796e;font-size:.61rem;line-height:1.4}.waitlist-room-type{display:block;color:#243b2d;font-size:.72rem;font-weight:800}.waitlist-type-warning{display:block;max-width:190px;margin-top:4px;color:#9b5c17;font-size:.6rem;line-height:1.35}.waitlist-status{display:inline-flex;padding:4px 8px;border:1px solid #d5e7d8;border-radius:999px;background:#eff7f0;color:#28613b;font-size:.59rem;font-weight:850}.waitlist-status-converted{border-color:#d6e1f0;background:#f0f5fc;color:#355a87}.waitlist-status-cancelled{border-color:#e0e5e1;background:#f2f4f2;color:#68756c}.waitlist-reservation-ref{display:block;max-width:170px;margin-top:5px;color:#65756a;font-size:.59rem;line-height:1.4}.waitlist-actions{min-width:190px;text-align:right;white-space:nowrap}.waitlist-actions form{display:inline-block;margin:1px 0 1px 3px}.waitlist-actions .btn{font-size:.62rem}.waitlist-actions .btn-quiet{border-color:#eadbd8;background:#fff8f7;color:#8c453e}.waitlist-empty{display:flex;align-items:center;gap:13px;padding:22px 18px;color:#596b5f}.waitlist-empty-mark{display:grid;width:38px;height:38px;flex:none;place-items:center;border:1px solid #d5e7d8;border-radius:12px;background:#eff7f0;color:#287044;font-size:1.2rem;font-weight:850}.waitlist-empty strong{display:block;color:#264332;font-size:.8rem}.waitlist-empty p{margin:3px 0 0;color:#6a796e;font-size:.68rem}.waitlist-empty .btn{margin-left:auto;flex:none}
.desk.dark[data-view="waitlist"] .waitlist-segments,.desk.dark[data-view="waitlist"] .waitlist-toolbar,.desk.dark[data-view="waitlist"] .waitlist-add-form,.desk.dark[data-view="waitlist"] .waitlist-manifest{border-color:#294333;background:#13231a;color:#dceade}.desk.dark[data-view="waitlist"]>.waitlist-views .desk-view-link{color:#bfd0c2}.desk.dark[data-view="waitlist"]>.waitlist-views .desk-view-link.on{background:#21432c;color:#d8f0dd;box-shadow:inset 0 0 0 1px #36774d}.desk.dark[data-view="waitlist"] .waitlist-segments a{border-color:#30483a;background:#172a1f;color:#bfd0c2}.desk.dark[data-view="waitlist"] .waitlist-segments a.is-active{border-color:#36774d;background:#21432c;color:#d8f0dd}.desk.dark[data-view="waitlist"] .waitlist-segments b{background:#20362a;color:#c0d1c4}.desk.dark[data-view="waitlist"] .waitlist-date-field,.desk.dark[data-view="waitlist"] .waitlist-search-field,.desk.dark[data-view="waitlist"] .waitlist-add-head h3,.desk.dark[data-view="waitlist"] .waitlist-manifest-head h3,.desk.dark[data-view="waitlist"] .waitlist-guest-name,.desk.dark[data-view="waitlist"] .waitlist-room-type,.desk.dark[data-view="waitlist"] .waitlist-empty strong{color:#d7e9db}.desk.dark[data-view="waitlist"] .waitlist-date-control>a,.desk.dark[data-view="waitlist"] .waitlist-date-control input,.desk.dark[data-view="waitlist"] .waitlist-search-control,.desk.dark[data-view="waitlist"] .waitlist-field input,.desk.dark[data-view="waitlist"] .waitlist-field select{border-color:#30483a;background:#102018;color:#e1eee4}.desk.dark[data-view="waitlist"] .waitlist-search-control input{color:#e1eee4}.desk.dark[data-view="waitlist"] .waitlist-add-head,.desk.dark[data-view="waitlist"] .waitlist-add-footer,.desk.dark[data-view="waitlist"] .waitlist-manifest-head,.desk.dark[data-view="waitlist"] .waitlist-table thead th{border-color:#294333;background:#172a1f;color:#b8c9bc}.desk.dark[data-view="waitlist"] .waitlist-add-head p,.desk.dark[data-view="waitlist"] .waitlist-add-footer p,.desk.dark[data-view="waitlist"] .waitlist-manifest-head p,.desk.dark[data-view="waitlist"] .waitlist-stay-meta,.desk.dark[data-view="waitlist"] .waitlist-contact,.desk.dark[data-view="waitlist"] .waitlist-notes,.desk.dark[data-view="waitlist"] .waitlist-room-note,.desk.dark[data-view="waitlist"] .waitlist-created,.desk.dark[data-view="waitlist"] .waitlist-reservation-ref,.desk.dark[data-view="waitlist"] .waitlist-empty p{color:#a9bdae}.desk.dark[data-view="waitlist"] .waitlist-field label{color:#b8c9bc}.desk.dark[data-view="waitlist"] .waitlist-table thead th{color:#b8c9bc}.desk.dark[data-view="waitlist"] .waitlist-table tbody td{border-color:#263b31;color:#d5e2d8}.desk.dark[data-view="waitlist"] .waitlist-table tbody tr:hover td{background:#192f22}.desk.dark[data-view="waitlist"] .waitlist-status-waiting{border-color:#365341;background:#1a3323;color:#b8e2c4}.desk.dark[data-view="waitlist"] .waitlist-status-converted{border-color:#354d6d;background:#1b2b3d;color:#b9d1f0}.desk.dark[data-view="waitlist"] .waitlist-status-cancelled{border-color:#3a4940;background:#202b24;color:#c1cec4}.desk.dark[data-view="waitlist"] .waitlist-type-warning{color:#f0c17d}.desk.dark[data-view="waitlist"] .waitlist-priority{border-color:#365341;background:#1a2e21;color:#c3d7c8}.desk.dark[data-view="waitlist"] .waitlist-priority-high{border-color:#74362e;background:#351c1a;color:#ffc4ba}.desk.dark[data-view="waitlist"] .waitlist-priority-raised{border-color:#67512a;background:#332916;color:#f3d58d}.desk.dark[data-view="waitlist"] .waitlist-empty-mark{border-color:#365341;background:#1a3323;color:#b8e2c4}
@media(max-width:1100px){.waitlist-hero{grid-template-columns:1fr}.waitlist-summary{grid-template-columns:repeat(5,minmax(0,1fr))}.waitlist-summary .waitlist-summary-primary{grid-column:auto;min-height:62px}.waitlist-summary-primary strong{font-size:1.4rem!important}.waitlist-form-grid{grid-template-columns:repeat(4,minmax(0,1fr))}.waitlist-field-name,.waitlist-field-notes{grid-column:span 2}}
@media(max-width:760px){.waitlist-hero{padding:18px}.waitlist-summary{grid-template-columns:repeat(3,minmax(0,1fr))}.waitlist-toolbar{grid-template-columns:minmax(0,1fr) minmax(0,1.3fr)}.waitlist-form-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.waitlist-field-name,.waitlist-field-notes{grid-column:span 2}.waitlist-table{min-width:1060px}.waitlist-reset{grid-column:1/-1;justify-self:end}}
@media(max-width:560px){.waitlist-hero{gap:15px;padding:16px;border-radius:14px}.waitlist-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.waitlist-summary .waitlist-summary-primary{grid-column:1/-1}.waitlist-summary-primary strong{font-size:1.4rem!important}.waitlist-hero h2{font-size:1.48rem}.waitlist-date-meta{display:grid;gap:5px}.waitlist-toolbar{grid-template-columns:minmax(0,1fr);gap:5px}.waitlist-reset{grid-column:auto;justify-self:start;margin:2px 0 5px}.waitlist-form-grid{gap:9px;padding:12px}.waitlist-field-name,.waitlist-field-notes{grid-column:span 2}.waitlist-add-head{padding:13px}.waitlist-add-footer{align-items:stretch;flex-direction:column;padding:11px 12px}.waitlist-add-footer .btn{align-self:flex-start}.waitlist-manifest-head{align-items:flex-start;flex-direction:column;padding:13px}.waitlist-empty{align-items:flex-start;flex-wrap:wrap}.waitlist-empty .btn{margin:0 0 0 51px}}
@media(prefers-reduced-motion:reduce){.waitlist-segments a,.waitlist-date-control>a,.waitlist-field input,.waitlist-field select{scroll-behavior:auto;transition:none}}
@media print{#desk[data-view="waitlist"]{gap:7px}.waitlist-hero{grid-template-columns:1fr;padding:12px;border:0;background:#fff!important;box-shadow:none;color:#17251b}.waitlist-hero h2,.waitlist-summary>div strong{color:#17251b}.waitlist-eyebrow,.waitlist-hero-copy>p,.waitlist-date-meta,.waitlist-date-meta b,.waitlist-summary>div span,.waitlist-summary>div small{color:#42564a}.waitlist-summary{grid-template-columns:repeat(5,1fr)}.waitlist-summary .waitlist-summary-primary{grid-column:auto;min-height:48px}.waitlist-summary>div{min-height:48px;border-color:#dbe5dd;background:#fff}.waitlist-summary-primary strong{font-size:1.25rem!important}.waitlist-hero-actions,.waitlist-segments,.waitlist-toolbar,.waitlist-add-form,.desk-views{display:none!important}.waitlist-manifest,.waitlist-table tbody td{border-color:#dfe7e0;box-shadow:none}.waitlist-table thead th{background:#f3f7f4!important;color:#48594d!important}.waitlist-actions{display:none}.waitlist-table{font-size:8pt}}
.groups-hero{position:relative;isolation:isolate;display:grid;grid-template-columns:minmax(0,1fr) minmax(350px,.9fr);gap:22px;align-items:stretch;overflow:hidden;padding:23px 25px;border:1px solid #164d37;border-radius:18px;background:radial-gradient(circle at 76% 0%,rgba(234,190,107,.16),transparent 38%),linear-gradient(120deg,#102d21 0%,#145437 60%,#1c6843 100%);box-shadow:0 14px 30px rgba(16,58,40,.13);color:#f3faf4}.groups-hero-copy{min-width:0;align-self:center}.groups-eyebrow,.groups-section-kicker{display:inline-flex;align-items:center;gap:6px;color:#d7e9dc;font-size:.62rem;font-weight:850;letter-spacing:.11em;text-transform:uppercase}.groups-eyebrow i{font-style:normal;opacity:.65}.groups-hero h2{margin:10px 0 7px;color:#fff;font-size:clamp(1.5rem,2.35vw,1.95rem);line-height:1.08;letter-spacing:-.04em}.groups-hero-copy>p{max-width:600px;margin:0;color:#d9eadf;font-size:.8rem;line-height:1.55}.groups-date-meta{display:flex;flex-wrap:wrap;gap:6px 17px;margin-top:13px;color:#d3e5d8;font-size:.67rem}.groups-date-meta b{margin-right:4px;color:#fff}.groups-hero-actions{display:flex;flex-wrap:wrap;gap:7px;margin-top:15px}.groups-hero .btn{min-height:35px;border-color:rgba(255,255,255,.28);background:rgba(255,255,255,.1);color:#fff}.groups-hero .btn:hover{background:rgba(255,255,255,.2)}.groups-summary{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;align-content:center}.groups-summary>div{display:flex;min-height:62px;flex-direction:column;justify-content:center;gap:2px;padding:9px 11px;border:1px solid rgba(255,255,255,.18);border-radius:12px;background:rgba(255,255,255,.085);backdrop-filter:blur(5px)}.groups-summary>div span{color:#d7e7dc;font-size:.59rem;font-weight:750;letter-spacing:.045em;text-transform:uppercase}.groups-summary>div strong{color:#fff;font-size:1.1rem;font-variant-numeric:tabular-nums;line-height:1.1}.groups-summary>div small{color:#bfd6c6;font-size:.57rem}.groups-summary .groups-summary-primary{grid-column:1/-1;min-height:70px;padding:10px 13px;background:rgba(255,255,255,.13)}.groups-summary-primary strong{font-size:1.65rem!important}
.groups-segments{display:flex;gap:7px;overflow-x:auto;padding:9px 13px;border-top:1px solid var(--c-border);border-bottom:1px solid var(--c-border);background:#fff;scrollbar-width:thin}.groups-segments a{display:inline-flex;min-height:34px;flex:none;align-items:center;gap:8px;padding:0 11px;border:1px solid #e5ece6;border-radius:999px;background:#f8faf8;color:#4a5e50;font-size:.66rem;font-weight:700;text-decoration:none;white-space:nowrap;transition:background .15s,border-color .15s,color .15s}.groups-segments a:hover{border-color:#b8d2bd;background:#eff7f0;color:#1e5e35}.groups-segments a.is-active{border-color:#1d6a3b;background:#174d2d;color:#fff;box-shadow:0 2px 7px rgba(20,77,45,.12)}.groups-segments b{display:inline-grid;min-width:20px;height:20px;place-items:center;padding:0 5px;border-radius:999px;background:#e8efe9;color:#52675a;font-size:.6rem;font-variant-numeric:tabular-nums}.groups-segments a.is-active b{background:rgba(255,255,255,.16);color:#fff}
.groups-toolbar{display:grid;grid-template-columns:minmax(230px,.75fr) minmax(125px,.35fr) minmax(300px,1fr) auto;align-items:end;gap:11px;padding:12px 14px;border-bottom:1px solid var(--c-border);background:#f8faf8}.groups-date-field,.groups-search-field{display:block;margin-bottom:5px;color:#52675a;font-size:.65rem;font-weight:750}.groups-date-control{display:flex;min-height:39px;align-items:center;gap:5px}.groups-date-control>a{display:grid;width:33px;height:34px;flex:none;place-items:center;border:1px solid #dbe5dd;border-radius:8px;background:#fff;color:#355644;font-size:1.1rem;text-decoration:none;transition:background .15s,border-color .15s}.groups-date-control>a:hover{border-color:#a8c7af;background:#eff7f0}.groups-date-control input,.groups-days-field select{min-width:0;height:34px;padding:0 7px;border:1px solid #dbe5dd;border-radius:8px;background:#fff;color:#24392b;font:inherit;font-size:.7rem}.groups-date-control input{flex:1}.groups-date-control .groups-today{width:auto;padding:0 9px;font-size:.64rem;font-weight:750}.groups-days-field{display:block;color:#52675a;font-size:.65rem;font-weight:750}.groups-days-field select{display:block;width:100%;margin-top:5px}.groups-search-control{display:flex;min-height:39px;align-items:center;gap:8px;padding:3px 4px 3px 11px;border:1px solid #dbe5dd;border-radius:10px;background:#fff;color:#829187}.groups-search-control input{width:100%;min-width:0;border:0;outline:0;background:transparent;color:#24392b;font:inherit;font-size:.73rem}.groups-search-control .btn{flex:none}.groups-reset{padding:0 0 9px;color:#316744;font-size:.66rem;font-weight:700;text-decoration:none;white-space:nowrap}.groups-reset:hover{text-decoration:underline}
.groups-manifest{overflow:hidden;border:1px solid var(--c-border);border-radius:13px;background:#fff}.groups-manifest-head{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:14px 16px;border-bottom:1px solid #e5ece6;background:linear-gradient(180deg,#fff,#fbfdfb)}.groups-manifest-head .groups-section-kicker{color:#357149;font-size:.57rem}.groups-manifest-head h3{margin:4px 0 3px;color:#23382a;font-size:.95rem;letter-spacing:-.015em}.groups-manifest-head p{margin:0;color:#718076;font-size:.67rem;line-height:1.4}.groups-manifest-count{display:flex;align-items:baseline;gap:5px;flex:none;padding:7px 10px;border:1px solid #dce9de;border-radius:9px;background:#f4f9f5;color:#52675a;font-size:.65rem;font-weight:650}.groups-manifest-count strong{color:#1c5a37;font-size:.92rem;font-variant-numeric:tabular-nums}.groups-manifest-count small{font-size:.58rem;font-weight:600}.groups-table-wrap{width:100%;overflow-x:auto}.groups-table{width:100%;min-width:1120px;border-collapse:separate;border-spacing:0;color:#273b2e;font-size:.69rem}.groups-table thead th{padding:9px 10px;border-bottom:1px solid #e2ebe4;background:#f5f9f6;color:#607066;font-size:.59rem;font-weight:850;letter-spacing:.055em;text-align:left;text-transform:uppercase;white-space:nowrap}.groups-table tbody td{padding:11px 10px;border-bottom:1px solid #edf1ed;vertical-align:top}.groups-table tbody tr:last-child td{border-bottom:0}.groups-table tbody tr:hover td{background:#fbfdfb}.groups-name{display:block;max-width:210px;color:#173d29;font-size:.75rem;font-weight:800;text-decoration:none;overflow-wrap:anywhere}.groups-name:hover,.groups-reference:hover,.groups-block-link:hover{color:#1c7544;text-decoration:underline}.groups-reference{display:inline-block;margin-top:3px;color:#2d6843;font-size:.62rem;font-weight:750;text-decoration:none}.groups-company{display:block;margin-top:4px;color:#69796d;font-size:.61rem}.groups-guest-name{display:block;color:#31483a;font-size:.71rem;font-weight:750}.groups-vip{display:inline-flex;margin-top:4px;padding:2px 6px;border:1px solid #f0d693;border-radius:999px;background:#fff7dc;color:#805a00;font-size:.54rem;font-weight:850}.groups-contact{display:block;margin-top:4px;color:#637368;font-size:.61rem;overflow-wrap:anywhere}.groups-contact a{color:#416b50;text-decoration:none}.groups-stay-dates{display:flex;align-items:center;gap:5px;color:#31483a;font-size:.65rem;font-weight:750;white-space:nowrap}.groups-stay-dates span{color:#8da094}.groups-stay-meta{display:block;margin-top:5px;color:#6a796e;font-size:.6rem;line-height:1.4}.groups-room-count{display:inline-block;color:#1c5a37;font-size:.9rem;font-variant-numeric:tabular-nums}.groups-room-count-label{margin-left:4px;color:#6a796e;font-size:.6rem}.groups-room-tags{display:flex;flex-wrap:wrap;gap:4px;margin-top:5px}.groups-room-tags span{padding:2px 6px;border:1px solid #dce7df;border-radius:6px;background:#f6faf7;color:#4e6555;font-size:.57rem;font-weight:700}.groups-no-rooms{display:block;margin-top:4px;color:#8b681c;font-size:.6rem}.groups-status{display:inline-flex}.groups-block-status{display:block;margin-top:5px;color:#68776c;font-size:.59rem}.groups-allocation-cell{min-width:260px}.groups-block-link{color:#245b39;font-size:.68rem;font-weight:800;text-decoration:none}.groups-block-meta{margin-top:3px;color:#66766b;font-size:.6rem}.groups-pickup-details{margin-top:7px}.groups-pickup-details summary{width:max-content;max-width:100%;padding:5px 8px;border:1px solid #d5e7d8;border-radius:8px;background:#f2f8f3;color:#28613b;cursor:pointer;font-size:.59rem;font-weight:750;list-style:none}.groups-pickup-details summary::-webkit-details-marker{display:none}.groups-pickup-details summary:after{content:'＋';margin-left:7px}.groups-pickup-details[open] summary:after{content:'−'}.groups-pickup-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px;margin-top:7px;padding:9px;border:1px solid #e0e9e2;border-radius:9px;background:#f9fcfa}.groups-pickup-form label{display:flex;min-width:0;flex-direction:column;gap:3px;color:#5b6d60;font-size:.58rem;font-weight:700}.groups-pickup-form .groups-rooming-label{grid-column:1/-1}.groups-pickup-form textarea,.groups-pickup-form input,.groups-pickup-form select{width:100%;min-width:0;min-height:31px;padding:5px 7px;border:1px solid #d7e2d9;border-radius:6px;background:#fff;color:#24392b;font:inherit;font-size:.62rem}.groups-pickup-form textarea{resize:vertical}.groups-pickup-actions{display:flex;flex-wrap:wrap;gap:5px;grid-column:1/-1}.groups-pickup-form .quote{grid-column:1/-1;margin:0;line-height:1.4}.groups-allocation-note,.groups-no-block{display:block;color:#738176;font-size:.61rem}.groups-engine-link{display:inline-block;margin-top:5px;color:#2d6843;font-size:.62rem;font-weight:750}.groups-empty{display:flex;align-items:center;gap:13px;padding:22px 18px;color:#596b5f}.groups-empty-mark{display:grid;width:38px;height:38px;flex:none;place-items:center;border:1px solid #d5e7d8;border-radius:12px;background:#eff7f0;color:#287044;font-size:1.15rem;font-weight:850}.groups-empty strong{display:block;color:#264332;font-size:.8rem}.groups-empty p{margin:3px 0 0;color:#6a796e;font-size:.68rem}.groups-empty .btn{margin-left:auto;flex:none}
.desk.dark[data-view="groups"] .groups-segments,.desk.dark[data-view="groups"] .groups-toolbar,.desk.dark[data-view="groups"] .groups-manifest{border-color:#294333;background:#13231a;color:#dceade}.desk.dark[data-view="groups"]>.groups-views .desk-view-link{color:#bfd0c2}.desk.dark[data-view="groups"]>.groups-views .desk-view-link.on{background:#21432c;color:#d8f0dd;box-shadow:inset 0 0 0 1px #36774d}.desk.dark[data-view="groups"] .groups-segments a{border-color:#30483a;background:#172a1f;color:#bfd0c2}.desk.dark[data-view="groups"] .groups-segments a.is-active{border-color:#36774d;background:#21432c;color:#d8f0dd}.desk.dark[data-view="groups"] .groups-segments b{background:#20362a;color:#c0d1c4}.desk.dark[data-view="groups"] .groups-date-field,.desk.dark[data-view="groups"] .groups-search-field,.desk.dark[data-view="groups"] .groups-days-field,.desk.dark[data-view="groups"] .groups-manifest-head h3,.desk.dark[data-view="groups"] .groups-name,.desk.dark[data-view="groups"] .groups-guest-name,.desk.dark[data-view="groups"] .groups-empty strong{color:#d7e9db}.desk.dark[data-view="groups"] .groups-date-control>a,.desk.dark[data-view="groups"] .groups-date-control input,.desk.dark[data-view="groups"] .groups-days-field select,.desk.dark[data-view="groups"] .groups-search-control{border-color:#30483a;background:#102018;color:#e1eee4}.desk.dark[data-view="groups"] .groups-search-control input{color:#e1eee4}.desk.dark[data-view="groups"] .groups-manifest-head,.desk.dark[data-view="groups"] .groups-table thead th{border-color:#294333;background:#172a1f;color:#b8c9bc}.desk.dark[data-view="groups"] .groups-table tbody td{border-color:#263b31;color:#d5e2d8}.desk.dark[data-view="groups"] .groups-table tbody tr:hover td{background:#192f22}.desk.dark[data-view="groups"] .groups-reference,.desk.dark[data-view="groups"] .groups-contact a,.desk.dark[data-view="groups"] .groups-block-link,.desk.dark[data-view="groups"] .groups-engine-link,.desk.dark[data-view="groups"] .groups-reset{color:#a4d7b1}.desk.dark[data-view="groups"] .groups-company,.desk.dark[data-view="groups"] .groups-contact,.desk.dark[data-view="groups"] .groups-stay-meta,.desk.dark[data-view="groups"] .groups-room-count-label,.desk.dark[data-view="groups"] .groups-block-status,.desk.dark[data-view="groups"] .groups-block-meta,.desk.dark[data-view="groups"] .groups-manifest-head p,.desk.dark[data-view="groups"] .groups-no-block,.desk.dark[data-view="groups"] .groups-empty p{color:#a9bdae}.desk.dark[data-view="groups"] .groups-room-tags span{border-color:#365341;background:#1a2e21;color:#c3d7c8}.desk.dark[data-view="groups"] .groups-pickup-details summary{border-color:#365341;background:#1a3323;color:#b8e2c4}.desk.dark[data-view="groups"] .groups-pickup-form{border-color:#294333;background:#172a1f}.desk.dark[data-view="groups"] .groups-pickup-form label{color:#b8c9bc}.desk.dark[data-view="groups"] .groups-pickup-form textarea,.desk.dark[data-view="groups"] .groups-pickup-form input,.desk.dark[data-view="groups"] .groups-pickup-form select{border-color:#30483a;background:#102018;color:#e1eee4}.desk.dark[data-view="groups"] .groups-empty-mark{border-color:#365341;background:#1a3323;color:#b8e2c4}
@media(max-width:1100px){.groups-hero{grid-template-columns:1fr}.groups-summary{grid-template-columns:repeat(5,minmax(0,1fr))}.groups-summary .groups-summary-primary{grid-column:auto;min-height:62px}.groups-summary-primary strong{font-size:1.4rem!important}.groups-toolbar{grid-template-columns:minmax(210px,.8fr) minmax(120px,.45fr) minmax(250px,1fr)}}
@media(max-width:760px){.groups-hero{padding:18px}.groups-summary{grid-template-columns:repeat(3,minmax(0,1fr))}.groups-toolbar{grid-template-columns:minmax(0,1fr) minmax(125px,.45fr)}.groups-search-group{grid-column:1/-1}.groups-table{min-width:1120px}.groups-reset{grid-column:1/-1;justify-self:end}}
@media(max-width:560px){.groups-hero{gap:15px;padding:16px;border-radius:14px}.groups-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.groups-summary .groups-summary-primary{grid-column:1/-1}.groups-summary-primary strong{font-size:1.4rem!important}.groups-hero h2{font-size:1.48rem}.groups-date-meta{display:grid;gap:5px}.groups-toolbar{grid-template-columns:minmax(0,1fr);gap:8px}.groups-search-group,.groups-reset{grid-column:auto}.groups-reset{justify-self:start;margin:2px 0 5px}.groups-manifest-head{align-items:flex-start;flex-direction:column;padding:13px}.groups-empty{align-items:flex-start;flex-wrap:wrap}.groups-empty .btn{margin:0 0 0 51px}}
@media(prefers-reduced-motion:reduce){.groups-segments a,.groups-date-control>a{scroll-behavior:auto;transition:none}}
@media print{#desk[data-view="groups"]{gap:7px}.groups-hero{grid-template-columns:1fr;padding:12px;border:0;background:#fff!important;box-shadow:none;color:#17251b}.groups-hero h2,.groups-summary>div strong{color:#17251b}.groups-eyebrow,.groups-hero-copy>p,.groups-date-meta,.groups-date-meta b,.groups-summary>div span,.groups-summary>div small{color:#42564a}.groups-summary{grid-template-columns:repeat(5,1fr)}.groups-summary .groups-summary-primary{grid-column:auto;min-height:48px}.groups-summary>div{min-height:48px;border-color:#dbe5dd;background:#fff}.groups-summary-primary strong{font-size:1.25rem!important}.groups-hero-actions,.groups-segments,.groups-toolbar,.groups-pickup-details,.desk-views{display:none!important}.groups-manifest,.groups-table tbody td{border-color:#dfe7e0;box-shadow:none}.groups-table thead th{background:#f3f7f4!important;color:#48594d!important}.groups-table{font-size:8pt}}
.noshow-hero{position:relative;isolation:isolate;display:grid;grid-template-columns:minmax(0,1fr) minmax(300px,.82fr);gap:22px;align-items:stretch;overflow:hidden;padding:23px 25px;border:1px solid #79451d;border-radius:18px;background:radial-gradient(circle at 78% 0%,rgba(247,210,143,.18),transparent 38%),linear-gradient(120deg,#30231b 0%,#60401f 57%,#79551f 100%);box-shadow:0 14px 30px rgba(69,46,24,.13);color:#fff8ed}.noshow-hero-copy{min-width:0;align-self:center}.noshow-eyebrow,.noshow-section-kicker{display:inline-flex;align-items:center;gap:6px;color:#f3ddb5;font-size:.62rem;font-weight:850;letter-spacing:.11em;text-transform:uppercase}.noshow-eyebrow i{font-style:normal;opacity:.65}.noshow-hero h2{margin:10px 0 7px;color:#fff;font-size:clamp(1.5rem,2.35vw,1.95rem);line-height:1.08;letter-spacing:-.04em}.noshow-hero-copy>p{max-width:610px;margin:0;color:#f0e1cc;font-size:.8rem;line-height:1.55}.noshow-date-meta{display:flex;flex-wrap:wrap;gap:6px 17px;margin-top:13px;color:#ecdec8;font-size:.67rem}.noshow-date-meta b{margin-right:4px;color:#fff7e9}.noshow-hero-actions{display:flex;flex-wrap:wrap;gap:7px;margin-top:15px}.noshow-hero .btn{min-height:35px;border-color:rgba(255,255,255,.28);background:rgba(255,255,255,.1);color:#fff}.noshow-hero .btn:hover{background:rgba(255,255,255,.2)}.noshow-summary{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;align-content:center}.noshow-summary>div{display:flex;min-height:62px;flex-direction:column;justify-content:center;gap:2px;padding:9px 11px;border:1px solid rgba(255,255,255,.18);border-radius:12px;background:rgba(255,255,255,.085);backdrop-filter:blur(5px)}.noshow-summary>div span{color:#f2e0c5;font-size:.59rem;font-weight:750;letter-spacing:.045em;text-transform:uppercase}.noshow-summary>div strong{color:#fff;font-size:1.1rem;font-variant-numeric:tabular-nums;line-height:1.1}.noshow-summary>div small{color:#e4cfad;font-size:.57rem}.noshow-summary .noshow-summary-primary{grid-column:1/-1;min-height:70px;padding:10px 13px;background:rgba(255,255,255,.13)}.noshow-summary-primary strong{font-size:1.65rem!important}
.noshow-segments{display:flex;gap:7px;overflow-x:auto;padding:9px 13px;border-top:1px solid var(--c-border);border-bottom:1px solid var(--c-border);background:#fff;scrollbar-width:thin}.noshow-segments a{display:inline-flex;min-height:34px;flex:none;align-items:center;gap:8px;padding:0 11px;border:1px solid #e5ece6;border-radius:99px;background:#fff;color:#53665a;font-size:.67rem;font-weight:750;text-decoration:none;white-space:nowrap}.noshow-segments a.is-active{border-color:#b78337;background:#fff7e8;color:#704716;box-shadow:inset 0 0 0 1px #e3c58c}.noshow-segments b{display:inline-grid;min-width:21px;height:21px;place-items:center;padding:0 5px;border-radius:99px;background:#f4eee2;color:#76511f;font-size:.59rem;font-variant-numeric:tabular-nums}
.noshow-toolbar{display:grid;grid-template-columns:auto minmax(130px,.45fr) minmax(260px,1fr) auto;gap:10px;align-items:end;padding:12px 14px;border:1px solid var(--c-border);border-radius:12px;background:#fff}.noshow-date-field,.noshow-search-field,.noshow-days-field{display:block;margin-bottom:4px;color:#5a6e61;font-size:.63rem;font-weight:750}.noshow-date-control{display:flex;align-items:center;gap:4px}.noshow-date-control>a,.noshow-date-control input,.noshow-days-field select{min-height:34px;border:1px solid #dce6de;border-radius:8px;background:#fff;color:#26392c}.noshow-date-control>a{display:inline-grid;min-width:29px;place-items:center;color:#385c43;text-decoration:none}.noshow-date-control input,.noshow-days-field select{padding:0 8px;font:inherit;font-size:.67rem}.noshow-today{padding:0 8px!important;font-size:.63rem}.noshow-days-field select{display:block;min-width:100%;margin-top:4px}.noshow-search-group{min-width:0}.noshow-search-control{display:flex;min-height:36px;align-items:center;gap:7px;padding:2px 5px 2px 9px;border:1px solid #dce6de;border-radius:9px;background:#fff;color:#6b7b70}.noshow-search-control input{width:100%;min-width:0;border:0;outline:0;background:transparent;color:#283b2e;font:inherit;font-size:.68rem}.noshow-reset{align-self:center;color:#436b4e;font-size:.65rem;font-weight:750;text-decoration:none}
.noshow-manifest{overflow:hidden;border:1px solid var(--c-border);border-radius:13px;background:#fff}.noshow-manifest-head{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:14px 16px;border-bottom:1px solid #e8e9e5;background:linear-gradient(180deg,#fff,#fdfcf9)}.noshow-manifest-head .noshow-section-kicker{color:#8a5b20;font-size:.57rem}.noshow-manifest-head h3{margin:4px 0 3px;color:#36291d;font-size:.95rem;letter-spacing:-.015em}.noshow-manifest-head p{margin:0;color:#766f65;font-size:.67rem;line-height:1.4}.noshow-manifest-count{display:flex;align-items:baseline;gap:5px;flex:none;padding:7px 10px;border:1px solid #ead9bd;border-radius:9px;background:#fcf7ed;color:#6b5a42;font-size:.65rem;font-weight:650}.noshow-manifest-count strong{color:#765019;font-size:.92rem;font-variant-numeric:tabular-nums}.noshow-manifest-count small{font-size:.58rem;font-weight:600}.noshow-table-wrap{width:100%;overflow-x:auto}.noshow-table{width:100%;min-width:1000px;border-collapse:separate;border-spacing:0;color:#35352f;font-size:.69rem}.noshow-table thead th{padding:9px 10px;border-bottom:1px solid #e8e6df;background:#faf8f2;color:#6e6659;font-size:.59rem;font-weight:850;letter-spacing:.055em;text-align:left;text-transform:uppercase;white-space:nowrap}.noshow-table tbody td{padding:11px 10px;border-bottom:1px solid #efede7;vertical-align:top}.noshow-table tbody tr:last-child td{border-bottom:0}.noshow-table tbody tr:hover td{background:#fdfbf6}.noshow-reference{display:block;color:#6f4e1d;font-size:.72rem;font-weight:850;text-decoration:none}.noshow-reference:hover{color:#9a661d;text-decoration:underline}.noshow-group-name,.noshow-guest-name{display:block;margin-top:4px;color:#384239;font-size:.7rem;font-weight:750}.noshow-company,.noshow-contact,.noshow-source,.noshow-channel,.noshow-stay-meta{display:block;margin-top:4px;color:#746e63;font-size:.6rem;line-height:1.35}.noshow-contact a{color:#66501f;text-decoration:none}.noshow-vip{display:inline-flex;margin-top:4px;padding:2px 6px;border:1px solid #e9d28f;border-radius:999px;background:#fff7dc;color:#805a00;font-size:.54rem;font-weight:850}.noshow-stay-dates{display:flex;align-items:center;gap:5px;color:#3e443a;font-size:.64rem;font-weight:750;white-space:nowrap}.noshow-stay-dates span{color:#a0998b}.noshow-room-count{display:inline-block;color:#79531e;font-size:.9rem;font-variant-numeric:tabular-nums}.noshow-room-count-label{margin-left:4px;color:#746e63;font-size:.6rem}.noshow-room-tags{display:flex;flex-wrap:wrap;gap:4px;margin-top:5px}.noshow-room-tags span{padding:2px 6px;border:1px solid #e8dfcc;border-radius:6px;background:#faf6ed;color:#69532f;font-size:.57rem;font-weight:700}.noshow-booking-type{display:block;color:#45483e;font-size:.65rem;font-weight:750}.noshow-status{display:inline-flex}.noshow-open-link{white-space:nowrap}.noshow-pagination{padding:10px 14px;border-top:1px solid #eeeae1}.noshow-empty{display:flex;align-items:center;gap:13px;padding:22px 18px;color:#645d52}.noshow-empty-mark{display:grid;width:38px;height:38px;flex:none;place-items:center;border:1px solid #ead9bd;border-radius:12px;background:#fcf5e7;color:#8a5b20;font-size:1.1rem;font-weight:850}.noshow-empty strong{display:block;color:#3c3227;font-size:.8rem}.noshow-empty p{margin:3px 0 0;color:#766f65;font-size:.68rem}.noshow-empty .btn{margin-left:auto;flex:none}
.desk.dark[data-view="noshow"] .noshow-segments,.desk.dark[data-view="noshow"] .noshow-toolbar,.desk.dark[data-view="noshow"] .noshow-manifest{border-color:#3b392f;background:#201f1a;color:#e6e2d8}.desk.dark[data-view="noshow"]>.noshow-views .desk-view-link{color:#d1c8b6}.desk.dark[data-view="noshow"]>.noshow-views .desk-view-link.on{background:#564222;color:#fff1d2;box-shadow:inset 0 0 0 1px #90713e}.desk.dark[data-view="noshow"] .noshow-segments a{border-color:#4a4335;background:#28251d;color:#d5c8ad}.desk.dark[data-view="noshow"] .noshow-segments a.is-active{border-color:#90713e;background:#3d321f;color:#f4dfb5}.desk.dark[data-view="noshow"] .noshow-segments b{background:#3c3425;color:#e2d2ae}.desk.dark[data-view="noshow"] .noshow-date-field,.desk.dark[data-view="noshow"] .noshow-search-field,.desk.dark[data-view="noshow"] .noshow-days-field,.desk.dark[data-view="noshow"] .noshow-manifest-head h3,.desk.dark[data-view="noshow"] .noshow-group-name,.desk.dark[data-view="noshow"] .noshow-guest-name,.desk.dark[data-view="noshow"] .noshow-empty strong{color:#e5dccb}.desk.dark[data-view="noshow"] .noshow-date-control>a,.desk.dark[data-view="noshow"] .noshow-date-control input,.desk.dark[data-view="noshow"] .noshow-days-field select,.desk.dark[data-view="noshow"] .noshow-search-control{border-color:#4a4335;background:#1b1a16;color:#eee6d7}.desk.dark[data-view="noshow"] .noshow-search-control input{color:#eee6d7}.desk.dark[data-view="noshow"] .noshow-manifest-head,.desk.dark[data-view="noshow"] .noshow-table thead th{border-color:#3b392f;background:#28251d;color:#d0c5b2}.desk.dark[data-view="noshow"] .noshow-table tbody td{border-color:#38362e;color:#ddd7cb}.desk.dark[data-view="noshow"] .noshow-table tbody tr:hover td{background:#29271f}.desk.dark[data-view="noshow"] .noshow-reference,.desk.dark[data-view="noshow"] .noshow-contact a,.desk.dark[data-view="noshow"] .noshow-reset{color:#e6c783}.desk.dark[data-view="noshow"] .noshow-company,.desk.dark[data-view="noshow"] .noshow-contact,.desk.dark[data-view="noshow"] .noshow-source,.desk.dark[data-view="noshow"] .noshow-channel,.desk.dark[data-view="noshow"] .noshow-stay-meta,.desk.dark[data-view="noshow"] .noshow-room-count-label,.desk.dark[data-view="noshow"] .noshow-manifest-head p,.desk.dark[data-view="noshow"] .noshow-empty p{color:#c1b7a4}.desk.dark[data-view="noshow"] .noshow-room-tags span{border-color:#514631;background:#302a1f;color:#e0d0ad}.desk.dark[data-view="noshow"] .noshow-empty-mark{border-color:#6d5735;background:#332a1a;color:#e8c77d}
@media(max-width:760px){.noshow-hero{padding:18px}.noshow-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.noshow-toolbar{grid-template-columns:minmax(0,1fr) minmax(125px,.45fr)}.noshow-search-group{grid-column:1/-1}.noshow-table{min-width:1000px}.noshow-reset{grid-column:1/-1;justify-self:end}}
@media(max-width:560px){.noshow-hero{gap:15px;padding:16px;border-radius:14px}.noshow-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.noshow-summary .noshow-summary-primary{grid-column:1/-1}.noshow-summary-primary strong{font-size:1.4rem!important}.noshow-hero h2{font-size:1.48rem}.noshow-date-meta{display:grid;gap:5px}.noshow-toolbar{grid-template-columns:minmax(0,1fr);gap:8px}.noshow-search-group,.noshow-reset{grid-column:auto}.noshow-reset{justify-self:start;margin:2px 0 5px}.noshow-manifest-head{align-items:flex-start;flex-direction:column;padding:13px}.noshow-empty{align-items:flex-start;flex-wrap:wrap}.noshow-empty .btn{margin:0 0 0 51px}}
@media print{@page{size:A4 landscape;margin:9mm}#desk[data-view="noshow"]{gap:7px}.noshow-hero{grid-template-columns:1fr;padding:12px;border:0;background:#fff!important;box-shadow:none;color:#29251e}.noshow-hero h2,.noshow-summary>div strong{color:#29251e}.noshow-eyebrow,.noshow-hero-copy>p,.noshow-date-meta,.noshow-date-meta b,.noshow-summary>div span,.noshow-summary>div small{color:#574a35}.noshow-summary{grid-template-columns:repeat(4,1fr)}.noshow-summary .noshow-summary-primary{grid-column:auto;min-height:48px}.noshow-summary>div{min-height:48px;border-color:#e7dfd1;background:#fff}.noshow-summary-primary strong{font-size:1.25rem!important}.noshow-hero-actions,.noshow-segments,.noshow-toolbar,.noshow-manifest .noshow-open-link,.mobile-tabs,.desk-views{display:none!important}.noshow-manifest,.noshow-table tbody td{border-color:#e5e0d5;box-shadow:none}.noshow-table thead th{background:#f8f5ed!important;color:#564c3c!important}.noshow-table{font-size:8pt}}
.cancelled-hero{position:relative;isolation:isolate;display:grid;grid-template-columns:minmax(0,1fr) minmax(300px,.82fr);gap:22px;align-items:stretch;overflow:hidden;padding:23px 25px;border:1px solid #59404a;border-radius:18px;background:radial-gradient(circle at 78% 0%,rgba(255,218,222,.15),transparent 38%),linear-gradient(120deg,#292126 0%,#49313b 57%,#5a3943 100%);box-shadow:0 14px 30px rgba(54,34,43,.13);color:#fff7f8}.cancelled-hero-copy{min-width:0;align-self:center}.cancelled-eyebrow,.cancelled-section-kicker{display:inline-flex;align-items:center;gap:6px;color:#f0d4dc;font-size:.62rem;font-weight:850;letter-spacing:.11em;text-transform:uppercase}.cancelled-eyebrow i{font-style:normal;opacity:.65}.cancelled-hero h2{margin:10px 0 7px;color:#fff;font-size:clamp(1.5rem,2.35vw,1.95rem);line-height:1.08;letter-spacing:-.04em}.cancelled-hero-copy>p{max-width:610px;margin:0;color:#eadde0;font-size:.8rem;line-height:1.55}.cancelled-date-meta{display:flex;flex-wrap:wrap;gap:6px 17px;margin-top:13px;color:#e6d5d9;font-size:.67rem}.cancelled-date-meta b{margin-right:4px;color:#fff4f6}.cancelled-hero-actions{display:flex;flex-wrap:wrap;gap:7px;margin-top:15px}.cancelled-hero .btn{min-height:35px;border-color:rgba(255,255,255,.28);background:rgba(255,255,255,.1);color:#fff}.cancelled-hero .btn:hover{background:rgba(255,255,255,.2)}.cancelled-summary{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;align-content:center}.cancelled-summary>div{display:flex;min-height:62px;flex-direction:column;justify-content:center;gap:2px;padding:9px 11px;border:1px solid rgba(255,255,255,.18);border-radius:12px;background:rgba(255,255,255,.085);backdrop-filter:blur(5px)}.cancelled-summary>div span{color:#ead8dc;font-size:.59rem;font-weight:750;letter-spacing:.045em;text-transform:uppercase}.cancelled-summary>div strong{color:#fff;font-size:1.1rem;font-variant-numeric:tabular-nums;line-height:1.1}.cancelled-summary>div small{color:#dbc4ca;font-size:.57rem}.cancelled-summary .cancelled-summary-primary{grid-column:1/-1;min-height:70px;padding:10px 13px;background:rgba(255,255,255,.13)}.cancelled-summary-primary strong{font-size:1.65rem!important}
.cancelled-segments{display:flex;gap:7px;overflow-x:auto;padding:9px 13px;border-top:1px solid var(--c-border);border-bottom:1px solid var(--c-border);background:#fff;scrollbar-width:thin}.cancelled-segments a{display:inline-flex;min-height:34px;flex:none;align-items:center;gap:8px;padding:0 11px;border:1px solid #e9e4e5;border-radius:99px;background:#fff;color:#60545a;font-size:.67rem;font-weight:750;text-decoration:none;white-space:nowrap}.cancelled-segments a.is-active{border-color:#c98b9a;background:#fff3f5;color:#793d4b;box-shadow:inset 0 0 0 1px #e7c1c9}.cancelled-segments b{display:inline-grid;min-width:21px;height:21px;place-items:center;padding:0 5px;border-radius:99px;background:#f5ecee;color:#754451;font-size:.59rem;font-variant-numeric:tabular-nums}
.cancelled-toolbar{display:grid;grid-template-columns:auto minmax(130px,.45fr) minmax(260px,1fr) auto;gap:10px;align-items:end;padding:12px 14px;border:1px solid var(--c-border);border-radius:12px;background:#fff}.cancelled-date-field,.cancelled-search-field,.cancelled-days-field{display:block;margin-bottom:4px;color:#635860;font-size:.63rem;font-weight:750}.cancelled-date-control{display:flex;align-items:center;gap:4px}.cancelled-date-control>a,.cancelled-date-control input,.cancelled-days-field select{min-height:34px;border:1px solid #e5dce0;border-radius:8px;background:#fff;color:#3a3035}.cancelled-date-control>a{display:inline-grid;min-width:29px;place-items:center;color:#714550;text-decoration:none}.cancelled-date-control input,.cancelled-days-field select{padding:0 8px;font:inherit;font-size:.67rem}.cancelled-today{padding:0 8px!important;font-size:.63rem}.cancelled-days-field select{display:block;min-width:100%;margin-top:4px}.cancelled-search-group{min-width:0}.cancelled-search-control{display:flex;min-height:36px;align-items:center;gap:7px;padding:2px 5px 2px 9px;border:1px solid #e5dce0;border-radius:9px;background:#fff;color:#786b71}.cancelled-search-control input{width:100%;min-width:0;border:0;outline:0;background:transparent;color:#3a3035;font:inherit;font-size:.68rem}.cancelled-reset{align-self:center;color:#714550;font-size:.65rem;font-weight:750;text-decoration:none}
.cancelled-manifest{overflow:hidden;border:1px solid var(--c-border);border-radius:13px;background:#fff}.cancelled-manifest-head{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:14px 16px;border-bottom:1px solid #ece5e7;background:linear-gradient(180deg,#fff,#fcfafb)}.cancelled-manifest-head .cancelled-section-kicker{color:#86505d;font-size:.57rem}.cancelled-manifest-head h3{margin:4px 0 3px;color:#392c31;font-size:.95rem;letter-spacing:-.015em}.cancelled-manifest-head p{margin:0;color:#756970;font-size:.67rem;line-height:1.4}.cancelled-manifest-count{display:flex;align-items:baseline;gap:5px;flex:none;padding:7px 10px;border:1px solid #ecd8dd;border-radius:9px;background:#fcf5f6;color:#6b565d;font-size:.65rem;font-weight:650}.cancelled-manifest-count strong{color:#7c4050;font-size:.92rem;font-variant-numeric:tabular-nums}.cancelled-manifest-count small{font-size:.58rem;font-weight:600}.cancelled-table-wrap{width:100%;overflow-x:auto}.cancelled-table{width:100%;min-width:1120px;border-collapse:separate;border-spacing:0;color:#383238;font-size:.69rem}.cancelled-table thead th{padding:9px 10px;border-bottom:1px solid #e9e2e4;background:#faf6f7;color:#70636a;font-size:.59rem;font-weight:850;letter-spacing:.055em;text-align:left;text-transform:uppercase;white-space:nowrap}.cancelled-table tbody td{padding:11px 10px;border-bottom:1px solid #f0ebed;vertical-align:top}.cancelled-table tbody tr:last-child td{border-bottom:0}.cancelled-table tbody tr:hover td{background:#fdfafb}.cancelled-reference{display:block;color:#78434f;font-size:.72rem;font-weight:850;text-decoration:none}.cancelled-reference:hover{color:#9a5263;text-decoration:underline}.cancelled-group-name,.cancelled-guest-name{display:block;margin-top:4px;color:#40373b;font-size:.7rem;font-weight:750}.cancelled-company,.cancelled-contact,.cancelled-source,.cancelled-channel,.cancelled-stay-meta,.cancelled-time-meta{display:block;margin-top:4px;color:#786e72;font-size:.6rem;line-height:1.35}.cancelled-contact a{color:#78434f;text-decoration:none}.cancelled-vip{display:inline-flex;margin-top:4px;padding:2px 6px;border:1px solid #e9d28f;border-radius:999px;background:#fff7dc;color:#805a00;font-size:.54rem;font-weight:850}.cancelled-time{display:block;color:#513b42;font-size:.66rem;font-weight:750;white-space:nowrap}.cancelled-stay-dates{display:flex;align-items:center;gap:5px;color:#433b3e;font-size:.64rem;font-weight:750;white-space:nowrap}.cancelled-stay-dates span{color:#a1989c}.cancelled-room-count{display:inline-block;color:#75414e;font-size:.9rem;font-variant-numeric:tabular-nums}.cancelled-room-count-label{margin-left:4px;color:#786e72;font-size:.6rem}.cancelled-room-tags{display:flex;flex-wrap:wrap;gap:4px;margin-top:5px}.cancelled-room-tags span{padding:2px 6px;border:1px solid #e8dfe2;border-radius:6px;background:#faf5f6;color:#6e4b54;font-size:.57rem;font-weight:700}.cancelled-booking-type{display:block;color:#494047;font-size:.65rem;font-weight:750}.cancelled-status{display:inline-flex}.cancelled-open-link{white-space:nowrap}.cancelled-pagination{padding:10px 14px;border-top:1px solid #eee8ea}.cancelled-empty{display:flex;align-items:center;gap:13px;padding:22px 18px;color:#665b60}.cancelled-empty-mark{display:grid;width:38px;height:38px;flex:none;place-items:center;border:1px solid #ecd8dd;border-radius:12px;background:#fcf4f6;color:#8a5160;font-size:1.1rem;font-weight:850}.cancelled-empty strong{display:block;color:#3f3338;font-size:.8rem}.cancelled-empty p{margin:3px 0 0;color:#756970;font-size:.68rem}.cancelled-empty .btn{margin-left:auto;flex:none}
.desk.dark[data-view="cancelled"] .cancelled-segments,.desk.dark[data-view="cancelled"] .cancelled-toolbar,.desk.dark[data-view="cancelled"] .cancelled-manifest{border-color:#44363b;background:#211c1f;color:#e7dfe1}.desk.dark[data-view="cancelled"]>.cancelled-views .desk-view-link{color:#d7c4ca}.desk.dark[data-view="cancelled"]>.cancelled-views .desk-view-link.on{background:#50303a;color:#ffe3e9;box-shadow:inset 0 0 0 1px #8d5261}.desk.dark[data-view="cancelled"] .cancelled-segments a{border-color:#4b3940;background:#2a2226;color:#d8c6cb}.desk.dark[data-view="cancelled"] .cancelled-segments a.is-active{border-color:#8d5261;background:#3d272e;color:#f3d6dc}.desk.dark[data-view="cancelled"] .cancelled-segments b{background:#3a2b30;color:#e1cbd1}.desk.dark[data-view="cancelled"] .cancelled-date-field,.desk.dark[data-view="cancelled"] .cancelled-search-field,.desk.dark[data-view="cancelled"] .cancelled-days-field,.desk.dark[data-view="cancelled"] .cancelled-manifest-head h3,.desk.dark[data-view="cancelled"] .cancelled-group-name,.desk.dark[data-view="cancelled"] .cancelled-guest-name,.desk.dark[data-view="cancelled"] .cancelled-empty strong{color:#e7dadd}.desk.dark[data-view="cancelled"] .cancelled-date-control>a,.desk.dark[data-view="cancelled"] .cancelled-date-control input,.desk.dark[data-view="cancelled"] .cancelled-days-field select,.desk.dark[data-view="cancelled"] .cancelled-search-control{border-color:#4b3940;background:#1b1719;color:#eee4e7}.desk.dark[data-view="cancelled"] .cancelled-search-control input{color:#eee4e7}.desk.dark[data-view="cancelled"] .cancelled-manifest-head,.desk.dark[data-view="cancelled"] .cancelled-table thead th{border-color:#44363b;background:#2a2226;color:#d2c2c7}.desk.dark[data-view="cancelled"] .cancelled-table tbody td{border-color:#3a2f33;color:#dfd6d9}.desk.dark[data-view="cancelled"] .cancelled-table tbody tr:hover td{background:#2c2327}.desk.dark[data-view="cancelled"] .cancelled-reference,.desk.dark[data-view="cancelled"] .cancelled-contact a,.desk.dark[data-view="cancelled"] .cancelled-reset{color:#e2aab6}.desk.dark[data-view="cancelled"] .cancelled-company,.desk.dark[data-view="cancelled"] .cancelled-contact,.desk.dark[data-view="cancelled"] .cancelled-source,.desk.dark[data-view="cancelled"] .cancelled-channel,.desk.dark[data-view="cancelled"] .cancelled-stay-meta,.desk.dark[data-view="cancelled"] .cancelled-time-meta,.desk.dark[data-view="cancelled"] .cancelled-room-count-label,.desk.dark[data-view="cancelled"] .cancelled-manifest-head p,.desk.dark[data-view="cancelled"] .cancelled-empty p{color:#c5b6bb}.desk.dark[data-view="cancelled"] .cancelled-room-tags span{border-color:#544047;background:#32262b;color:#e2cbd1}.desk.dark[data-view="cancelled"] .cancelled-empty-mark{border-color:#6b4650;background:#342229;color:#e5b4be}
@media(max-width:760px){.cancelled-hero{padding:18px}.cancelled-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.cancelled-toolbar{grid-template-columns:minmax(0,1fr) minmax(125px,.45fr)}.cancelled-search-group{grid-column:1/-1}.cancelled-table{min-width:1120px}.cancelled-reset{grid-column:1/-1;justify-self:end}}
@media(max-width:560px){.cancelled-hero{gap:15px;padding:16px;border-radius:14px}.cancelled-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.cancelled-summary .cancelled-summary-primary{grid-column:1/-1}.cancelled-summary-primary strong{font-size:1.4rem!important}.cancelled-hero h2{font-size:1.48rem}.cancelled-date-meta{display:grid;gap:5px}.cancelled-toolbar{grid-template-columns:minmax(0,1fr);gap:8px}.cancelled-search-group,.cancelled-reset{grid-column:auto}.cancelled-reset{justify-self:start;margin:2px 0 5px}.cancelled-manifest-head{align-items:flex-start;flex-direction:column;padding:13px}.cancelled-empty{align-items:flex-start;flex-wrap:wrap}.cancelled-empty .btn{margin:0 0 0 51px}}
@media print{@page{size:A4 landscape;margin:9mm}#desk[data-view="cancelled"]{gap:7px}.cancelled-hero{grid-template-columns:1fr;padding:12px;border:0;background:#fff!important;box-shadow:none;color:#30272b}.cancelled-hero h2,.cancelled-summary>div strong{color:#30272b}.cancelled-eyebrow,.cancelled-hero-copy>p,.cancelled-date-meta,.cancelled-date-meta b,.cancelled-summary>div span,.cancelled-summary>div small{color:#59464d}.cancelled-summary{grid-template-columns:repeat(4,1fr)}.cancelled-summary .cancelled-summary-primary{grid-column:auto;min-height:48px}.cancelled-summary>div{min-height:48px;border-color:#e7dde0;background:#fff}.cancelled-summary-primary strong{font-size:1.25rem!important}.cancelled-hero-actions,.cancelled-segments,.cancelled-toolbar,.cancelled-manifest .cancelled-open-link,.mobile-tabs,.desk-views{display:none!important}.cancelled-manifest,.cancelled-table tbody td{border-color:#e6dfe2;box-shadow:none}.cancelled-table thead th{background:#f8f3f5!important;color:#58474d!important}.cancelled-table{font-size:8pt}}
</style>
@endpush
@push('scripts')
<script>
(function () {
  const desk = document.getElementById('desk');
  const toast = document.getElementById('desk-toast');
  const dropUrl = @json(route('frontdesk.tapechart.drop'));
  const csrf = @json(csrf_token());
  function say(msg) { toast.hidden = false; toast.textContent = msg; setTimeout(() => toast.hidden = true, 5000); }
  let stayId = null;
  document.querySelectorAll('.stay-bar[draggable="true"], .timeline-loose-link[draggable="true"]').forEach(source => {
    source.addEventListener('dragstart', e => {
      stayId = source.dataset.stay;
      source.classList.add('is-dragging');
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', stayId);
    });
    source.addEventListener('dragend', () => {
      source.classList.remove('is-dragging');
      stayId = null;
      document.querySelectorAll('.room-row.drop').forEach(row => row.classList.remove('drop'));
    });
  });
  document.querySelectorAll('.room-row').forEach(row => {
    row.addEventListener('dragover', e => { e.preventDefault(); row.classList.add('drop'); });
    row.addEventListener('dragleave', () => row.classList.remove('drop'));
    row.addEventListener('drop', e => {
      e.preventDefault(); row.classList.remove('drop');
      const id = e.dataTransfer.getData('text/plain') || stayId;
      if (!id || !row.dataset.room) return;
      const roomNo = row.querySelector('.room-label b') ? row.querySelector('.room-label b').textContent : 'that room';
      fetch(@json(url('/reservations/stays')) + '/' + id + '/place-check', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify({ room_id: row.dataset.room }) })
        .then(r => r.json().then(j => ({ ok: r.ok, j })).catch(() => ({ ok: false, j: { message: 'The server refused the move.' } })))
        .then(({ ok, j }) => {
          if (!ok) { say(j.message || 'The room was not changed.'); return; }
          if (!window.confirm((j.message || ('Move this stay to room ' + roomNo + '?')) + '\n\nNothing is saved until you confirm.')) return;
          return fetch(dropUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify({ stay_id: id, room_id: row.dataset.room, reason: 'Tape chart drag & drop' }) })
            .then(r => r.json().then(j2 => ({ ok: r.ok, j: j2 })).catch(() => ({ ok: false, j: { message: 'The server refused the move.' } })))
            .then(({ ok: ok2, j: j2 }) => { if (!ok2) { say(j2.message || 'The room was not changed.'); return; } location.reload(); });
        })
        .catch(() => say('Could not reach the server. The room was not changed.'));
    });
  });
  const zoom = document.getElementById('col-zoom');
  if (zoom) zoom.addEventListener('input', () => {
    desk.style.setProperty('--day', zoom.value + 'px');
    zoom.setAttribute('aria-valuetext', `${zoom.value} pixels per day`);
  });
  const key = 'nowills-theme';
  const apply = mode => {
    desk.classList.toggle('dark', mode === 'dark');
    document.body.classList.toggle('dark', mode === 'dark');
  };
  apply(localStorage.getItem(key) || localStorage.getItem('nowills-desk-theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));
  let timelinePrintPage = null;
  let timelinePrintDetails = [];
  window.addEventListener('beforeprint', () => {
    if (desk?.dataset.view !== 'timeline' || timelinePrintPage) return;
    timelinePrintDetails = [...document.querySelectorAll('.timeline-loose-more:not([open])')];
    timelinePrintDetails.forEach(details => { details.open = true; });
    timelinePrintPage = document.createElement('style');
    timelinePrintPage.textContent = '@page { size: A4 landscape; margin: 8mm; }';
    document.head.appendChild(timelinePrintPage);
  });
  window.addEventListener('afterprint', () => {
    timelinePrintDetails.forEach(details => { details.open = false; });
    timelinePrintDetails = [];
    timelinePrintPage?.remove();
    timelinePrintPage = null;
  });
  document.getElementById('print-btn')?.addEventListener('click', () => window.print());
  const arrivalDateInput = document.getElementById('arrivalDeskDate');
  const arrivalLateEtaFocus = document.getElementById('arrivalLateEtaFocus');
  const arrivalBusinessDate = @json(bdate()->toDateString());
  arrivalDateInput?.addEventListener('change', () => {
    if (arrivalDateInput.value !== arrivalBusinessDate) arrivalLateEtaFocus?.remove();
  });
  const reservationSearch = document.querySelector('#reservationListFilter input[name="q"]');
  const clearReservationSearch = document.querySelector('[data-clear-reservation-search]');
  const reservationSearchShortcut = document.querySelector('[data-search-shortcut]');
  const syncReservationSearchControls = () => {
    const hasQuery = Boolean(reservationSearch?.value);
    if (clearReservationSearch) clearReservationSearch.hidden = !hasQuery;
    if (reservationSearchShortcut) reservationSearchShortcut.hidden = hasQuery;
  };
  reservationSearch?.addEventListener('input', syncReservationSearchControls);
  clearReservationSearch?.addEventListener('click', () => {
    if (!reservationSearch) return;
    reservationSearch.value = '';
    syncReservationSearchControls();
    reservationSearch.form?.requestSubmit();
  });
  document.addEventListener('keydown', event => {
    if (!reservationSearch || event.defaultPrevented || event.altKey || event.ctrlKey || event.metaKey) return;
    const target = event.target;
    const isEditing = target instanceof HTMLElement && (target.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName));
    if (event.key === '/' && !isEditing) {
      event.preventDefault();
      reservationSearch.focus();
    } else if (event.key === 'Escape' && target === reservationSearch && reservationSearch.value) {
      event.preventDefault();
      reservationSearch.value = '';
      syncReservationSearchControls();
      reservationSearch.form?.requestSubmit();
    }
  });
  function jsonPost(url, body) {
    return fetch(url, { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf }, body: body })
      .then(r => r.json().then(j => ({ ok: r.ok, j })).catch(() => ({ ok: false, j: { message: 'The server did not answer.' } })));
  }
  function previewKey(form) {
    const fd = new FormData(form);
    return ['arrival_date', 'departure_date', 'split_date', 'room_id', 'starts_on', 'ends_on', 'reason', 'rate', 'names', 'room_type_id', 'adults', 'amount', 'category', 'description', 'apply_tax', 'payment_mode_id', 'momo_network', 'momo_phone', 'reference', 'cheque_bank_name', 'cheque_number', 'cheque_bank_branch', 'type'].map(k => fd.get(k) || '').join('|');
  }
  document.addEventListener('click', e => {
    const quote = e.target.closest('[data-quote]');
    if (quote) {
      const form = quote.closest('form');
      jsonPost(form.dataset.preview, new FormData(form)).then(({ ok, j }) => {
        const p = form.querySelector('.quote');
        if (p) p.textContent = (j.message || 'No preview.') + (ok ? ' Press confirm to save.' : '');
        if (ok) form.dataset.seen = previewKey(form);
      });
    }
    const roomCheck = e.target.closest('[data-room-check]');
    if (roomCheck) {
      const form = roomCheck.closest('form');
      const note = form.querySelector('[data-room-check-note]');
      const checkedKey = previewKey(form);
      const originalLabel = roomCheck.textContent;
      roomCheck.disabled = true;
      roomCheck.setAttribute('aria-busy', 'true');
      roomCheck.textContent = 'Checking…';
      if (note) note.textContent = 'Checking room availability…';
      jsonPost(roomCheck.getAttribute('data-room-check'), new FormData(form)).then(({ ok, j }) => {
        if (previewKey(form) !== checkedKey) {
          delete form.dataset.roomCheckSeen;
          if (note) note.textContent = 'Room or rate changed while checking. Check again before confirming.';
          return;
        }
        if (note) note.textContent = (j.message || 'No preview.') + (ok ? ' Press Confirm room to save.' : '');
        else say(j.message || 'No preview.');
        if (ok) form.dataset.roomCheckSeen = checkedKey;
        else delete form.dataset.roomCheckSeen;
      }).catch(() => {
        delete form.dataset.roomCheckSeen;
        if (note) note.textContent = 'Could not reach the server. The room was not changed.';
        else say('Could not reach the server. The room was not changed.');
      }).finally(() => {
        roomCheck.disabled = false;
        roomCheck.removeAttribute('aria-busy');
        roomCheck.textContent = originalLabel;
      });
    }
  });
  document.addEventListener('submit', e => {
    const form = e.target;
    if (!form.dataset) return;
    const roomCheck = form.querySelector('[data-room-check]');
    if (roomCheck) {
      const roomKey = previewKey(form);
      if (form.dataset.roomCheckSeen !== roomKey) {
        e.preventDefault();
        const note = form.querySelector('[data-room-check-note]');
        if (note) note.textContent = roomCheck.disabled ? 'Room check is still in progress. Please confirm after it finishes.' : 'Checking the selected room before confirmation…';
        if (!roomCheck.disabled) roomCheck.click();
        return;
      }
      delete form.dataset.roomCheckSeen;
    }
    if (!form.dataset.preview) return;
    const key = previewKey(form);
    if (form.dataset.seen === key) return;
    e.preventDefault();
    jsonPost(form.dataset.preview, new FormData(form)).then(({ ok, j }) => {
      const p = form.querySelector('.quote');
      if (p) p.textContent = (j.message || 'No preview.') + (ok ? ' Press Confirm dates to save.' : '');
      if (ok) form.dataset.seen = previewKey(form);
    });
  });
  if (desk && desk.dataset.view !== 'list') {
    const tickUrl = @json(route('reservations.tick'));
    let tick = @json($tick ?? null);
    setInterval(() => {
      fetch(tickUrl + '?date=' + @json($date->toDateString()), { headers: { 'Accept': 'application/json' } })
        .then(r => r.ok ? r.json() : null)
        .then(j => { if (j && j.tick && j.tick !== tick) { tick = j.tick; say('The desk changed in another session. Reload to see it.'); } })
        .catch(() => {});
    }, 60000);
  }
})();
</script>
@endpush
