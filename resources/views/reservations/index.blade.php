@extends('layouts.app')
@section('title', ($view ?? null) === 'dashboard' ? 'Reservation Desk' : 'Reservations')
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
    }

    return route('reservations.index', $query);
  };
  $tabCounts = $tabs;
  $nav = [
    'arrivals' => 'Arrivals', 'duein' => 'Due in', 'inhouse' => 'In-house', 'stayover' => 'Stayover', 'dueout' => 'Due out',
    'departures' => 'Departures', 'unassigned' => 'Unassigned', 'vip' => 'VIP', 'groups' => 'Groups',
    'waitlist' => 'Waitlist', 'noshow' => 'No-show', 'cancelled' => 'Cancelled',
  ];
  $views = ['dashboard' => 'Dashboard', 'timeline' => 'Timeline', 'board' => 'Room board', 'list' => 'List', 'arrivals' => 'Arrivals', 'departures' => 'Departures', 'inhouse' => 'In-house', 'unassigned' => 'Unassigned', 'waitlist' => 'Waitlist', 'groups' => 'Groups'];
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
  @elseif($view === 'dashboard')
    <header class="desk-head desk-dashboard-hero">
      <div class="desk-dashboard-hero-copy">
        <span class="desk-dashboard-eyebrow"><x-icon name="hotel" size="sm"/> FRONT OFFICE <span aria-hidden="true">/</span> DAILY OPERATIONS</span>
        <h2>Today at a glance</h2>
        <div class="desk-dashboard-date-line">
          <span>{{ $date->format('l, d M Y') }}</span>
          <span>Business date · {{ bdate()->format('d M Y') }}</span>
          <span>{{ number_format($kpis['sellable']) }} rooms on sale</span>
        </div>
      </div>
      <form class="desk-dashboard-search" action="{{ route('reservations.index') }}" method="get" role="search">
        <input type="hidden" name="view" value="list">
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
  @else
    <header class="desk-head">
      <div>
        <div class="tiny" style="letter-spacing:.14em;text-transform:uppercase;opacity:.75">Nowills · Front office</div>
        <h1>Reservation desk</h1>
        <p>{{ $date->format('l, d M Y') }} · business date {{ bdate()->format('d M Y') }} · {{ $kpis['sellable'] }} rooms on sale</p>
      </div>
      <div class="flex gap-1 wrap">
        <a class="btn btn-sm" href="{{ $go(['view' => 'dashboard']) }}">Dashboard</a>
        <a class="btn btn-sm" href="{{ route('reservations.plan', request()->only(['date','days','group'])) }}" target="_blank">Print plan</a>
        <a class="btn btn-sm" href="{{ route('reservations.daysummary', request()->only(['date','days','group'])) }}" target="_blank">Day summary</a>
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

  @if($view !== 'list')
  @php
    $credit = $kpis['outstanding'] < 0 ? money(abs($kpis['outstanding'])).' credit' : money($kpis['outstanding']);
    $kpiGroups = [
      'Rooms' => [
        ['Total', $kpis['total'], ['view' => 'board', 'focus' => null, 'tab' => null], 'Every active room'],
        ['Available', $kpis['available'], ['view' => 'board', 'focus' => 'available'], 'Free for tonight after bookings and blocks'],
        ['Occupied', $kpis['occupied'], ['view' => 'board', 'focus' => 'occupied'], 'Room status occupied'],
        ['Reserved', $kpis['reserved'], ['view' => 'timeline', 'focus' => 'reserved'], 'Reserved stays covering this date'],
        ['Vacant clean', $kpis['vacant_clean'], ['view' => 'board', 'focus' => 'vacant_clean'], 'Vacant and clean'],
        ['Vacant dirty', $kpis['vacant_dirty'], ['view' => 'board', 'focus' => 'vacant_dirty'], 'Vacant and dirty'],
        ['Cleaning', $kpis['cleaning'], ['view' => 'board', 'focus' => 'cleaning'], 'Rooms with a housekeeping task in progress today'],
        ['Inspected', $kpis['inspected'], ['view' => 'board', 'focus' => 'inspected'], 'Housekeeping status inspected'],
        ['Maintenance', $kpis['maintenance'], ['view' => 'board', 'focus' => 'maintenance'], 'Rooms with an open maintenance ticket'],
        ['OOO', $kpis['ooo'], ['view' => 'board', 'focus' => 'ooo'], 'Out of order'],
        ['OOS', $kpis['oos'], ['view' => 'board', 'focus' => 'oos'], 'Out of service'],
        ['Blocked', $kpis['blocked'], ['view' => 'board', 'focus' => 'blocked'], 'Dated blocks covering tonight'],
      ],
      'Reservations' => [
        ['Arrivals', $kpis['arrivals'], ['view' => 'arrivals', 'focus' => null, 'due' => null], 'Stays arriving this date'],
        ['Due in', $kpis['due_in'], ['view' => 'arrivals', 'due' => 'in'], 'Arriving and not yet checked in'],
        ['Departures', $kpis['due_out'], ['view' => 'departures', 'tab' => 'dueout'], 'In-house guests departing this date'],
        ['In-house', $kpis['inhouse'], ['view' => 'inhouse'], 'Checked in now'],
        ['Stayovers', $kpis['stayover'], ['view' => 'timeline', 'tab' => 'stayover'], 'In house and not departing today'],
        ['Walk-ins', $kpis['walkins'], ['view' => 'arrivals', 'focus' => 'walkin'], 'Arrivals whose source type is walk-in'],
        ['VIP', $kpis['vip'], ['view' => 'timeline', 'tab' => 'vip'], 'VIP stays covering this date'],
        ['Groups', $kpis['groups'], ['view' => 'groups'], 'Group reservations covering this date'],
        ['Waitlist', $kpis['waitlist'], ['view' => 'waitlist'], 'Waiting, not yet a reservation'],
        ['No-shows', $kpis['noshow'], ['view' => 'list', 'tab' => 'noshow'], 'Marked no-show on this date'],
        ['Cancelled', $kpis['cancelled'], ['view' => 'list', 'status' => 'cancelled', 'tab' => null], 'Cancelled on this business date'],
        ['Unassigned', $kpis['unassigned'], ['view' => 'unassigned'], 'Reserved stays with no room'],
      ],
      'Revenue' => [
        ['Expected', money($kpis['on_books']), ['view' => 'timeline', 'focus' => null], 'Room rate of stays covering this date, before tax'],
        ['Posted room', money($kpis['room_posted']), ['view' => 'inhouse'], 'Room charges posted on this business date'],
        ['Posted all', money($kpis['posted']), ['view' => 'inhouse'], 'All folio charges posted on this business date'],
        ['Occupancy', $kpis['occupancy'] === null ? '—' : $kpis['occupancy'].'%', ['view' => 'board'], $kpis['sold'].' sold / '.$kpis['sellable'].' sellable'],
        ['ADR', $kpis['adr'] === null ? '—' : money($kpis['adr']), ['view' => 'timeline'], 'Average rate of stays covering this date'],
        ['RevPAR', $kpis['revpar'] === null ? '—' : money($kpis['revpar']), ['view' => 'board'], 'Posted room revenue divided by sellable rooms'],
        ['Deposits', money($kpis['deposits']), ['view' => 'timeline', 'focus' => 'deposit'], 'Advance payments posted today. Click shows stays with a payment on file'],
        ['Outstanding', $credit, ['view' => 'inhouse', 'focus' => 'balance'], 'Open folio balance. Credit means payments already exceed charges'],
      ],
    ];
    $dashboardStats = [
      ['Rooms available', $kpis['available'], 'of '.$kpis['sellable'].' sellable rooms', ['view' => 'board', 'focus' => 'available'], 'key', 'green'],
      ['Arrivals', $kpis['arrivals'], $kpis['due_in'].' still due to check in', ['view' => 'arrivals'], 'calendar', 'green'],
      ['Departures', $kpis['due_out'], 'scheduled for today', ['view' => 'departures'], 'door', 'amber'],
      ['In-house', $kpis['inhouse'], $kpis['occupied'].' rooms occupied', ['view' => 'inhouse'], 'users', 'blue'],
      ['Occupancy', $kpis['occupancy'] === null ? '—' : $kpis['occupancy'].'%', $kpis['sold'].' sold · '.$kpis['sellable'].' sellable', ['view' => 'board'], 'chart', 'forest'],
      ['Unassigned', $kpis['unassigned'], 'reserved stays need a room', ['view' => 'unassigned'], 'alert', $kpis['unassigned'] > 0 ? 'red' : 'green'],
    ];
  @endphp
  @endif
  @if($view !== 'list')
  <section class="kpi-strip {{ $view === 'dashboard' ? 'dashboard-kpi-strip' : '' }}" aria-label="Today's figures">
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
                  <a class="kpi {{ request('focus') === ($extra['focus'] ?? null) && request('view') === ($extra['view'] ?? null) ? 'on' : '' }}" href="{{ $go($extra) }}" title="{{ $hint }}"><span>{{ $label }}</span><strong>{{ $value }}</strong></a>
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
              <a class="kpi {{ request('focus') === ($extra['focus'] ?? null) && request('view') === ($extra['view'] ?? null) ? 'on' : '' }}" href="{{ $go($extra) }}" title="{{ $hint }}"><span>{{ $label }}</span><strong>{{ $value }}</strong></a>
            @endforeach
          </div>
        </div>
      @endforeach
    @endif
  </section>

  @if($view !== 'dashboard')
  <nav class="desk-nav" aria-label="Reservation status">
    <a href="{{ $go(['tab' => null, 'view' => 'timeline']) }}" class="{{ !request('tab') && $view === 'timeline' ? 'on' : '' }}">All</a>
    @foreach($nav as $key => $label)
      @php $target = in_array($key, ['waitlist','groups','unassigned','arrivals','departures','inhouse'], true) ? $key : (in_array($key, ['noshow','cancelled'], true) ? 'list' : ($view === 'list' ? 'list' : 'timeline')); @endphp
      <a href="{{ $go(['tab' => $key, 'view' => $target]) }}" class="{{ request('tab') === $key || $view === $key ? 'on' : '' }}">{{ $label }} <em>{{ $tabCounts[$key] ?? 0 }}</em></a>
    @endforeach
  </nav>
  @endif

  <form class="desk-tools {{ $view === 'dashboard' ? 'desk-dashboard-date-tools' : '' }}" method="get">
    <input type="hidden" name="view" value="{{ $view }}">
    @if(request('tab'))<input type="hidden" name="tab" value="{{ request('tab') }}">@endif
    <label>{{ $view === 'dashboard' ? 'Desk date' : 'Date' }}
      <span class="date-nav">
        <a href="{{ $go(['date' => $date->copy()->subDay()->toDateString()]) }}" aria-label="Previous day">‹</a>
        <input type="date" name="date" value="{{ $date->toDateString() }}">
        <a href="{{ $go(['date' => $date->copy()->addDay()->toDateString()]) }}" aria-label="Next day">›</a>
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
    <a class="btn btn-sm" href="{{ route('reservations.index') }}">Reset</a>
    @endif
  </form>
  @endif

  <nav class="desk-views {{ $view === 'list' ? 'reservation-list-views' : '' }}" aria-label="Reservation desk views">
    @foreach($views as $key => $label)
      <a href="{{ $go(['view' => $key]) }}" class="desk-view-link {{ $view === $key ? 'on' : '' }}" @if($view === $key) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
    @if($view === 'timeline')<label class="zoom">Column <input id="col-zoom" type="range" min="36" max="92" value="52" aria-label="Timeline column width"></label>@endif
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
    <p class="tiny muted legend">Each bar is labelled, not colour alone: Confirmed, Tentative, In house, Due out, Due in, Checked out, Complimentary, House use. Gold border is VIP. Purple border is a group. “dep” means a payment is on file. A room with no photo shows its type code, not a stock picture. Drag asks for confirmation, then the server accepts or refuses the move. Keyboard: open the stay and use Check room, then Confirm room.</p>
    @if($loose->isNotEmpty())
      <div class="loose">
        <strong>Unassigned in this window</strong>
        @foreach($loose as $s)
          <a href="{{ route('reservations.stay', $s) }}">{{ $s->reservation->reservation_no }} {{ $s->reservation->guest->full_name }} · {{ $s->roomType->code }} · {{ $s->arrival_date->format('d M') }}</a>
        @endforeach
      </div>
    @endif
    <div class="tape" id="tape">
      <div class="tape-head" style="--days:{{ $days }}">
        <div class="room-label">Room</div>
        @foreach($dates as $d)
          <div class="{{ $d->isSameDay(bdate()) ? 'today' : '' }}">{{ $d->format('d') }}<small>{{ $d->format('D') }}</small></div>
        @endforeach
      </div>
      @forelse($grouped as $name => $groupRooms)
        <div class="tape-group">{{ $name }} <span>{{ $groupRooms->count() }}</span></div>
        @foreach($groupRooms as $room)
          @php
            $photo = \App\Services\ReservationDeskService::photoPath($room);
            $names = \App\Services\ReservationDeskService::amenityNames($room);
          @endphp
          <div class="room-row {{ $room->status }}" data-room="{{ $room->id }}" style="--days:{{ $days }}">
            <a class="room-label" href="{{ route('reservations.room', $room) }}">
              @if($photo)<img src="{{ asset($photo) }}" alt="">@else<span class="type-code" title="No photo on file">{{ $room->roomType->code }}</span>@endif
              <span>
                <b>{{ $room->room_no }}</b>
                <small>{{ $room->hk_status }}@if($names->isNotEmpty()) · {{ $names->take(2)->join(', ') }}@if($names->count() > 2) <span title="{{ $names->join(', ') }}">+{{ $names->count() - 2 }} more</span>@endif @endif</small>
              </span>
            </a>
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
                @endphp
                <a class="stay-bar {{ $cls }}" draggable="{{ $s->status !== 'checked_out' && (can_do('frontdesk.roommove') || can_do('reservations.edit')) ? 'true' : 'false' }}" data-stay="{{ $s->id }}" href="{{ route('reservations.stay', $s) }}" style="grid-column: {{ $offset + 2 }} / span {{ $len }}" title="{{ $s->reservation->guest->full_name }} · {{ $s->reservation->reservation_no }} · {{ $marks }}">
                  <b>{{ $s->reservation->guest->full_name }}</b>
                  <small>{{ $barLabel }} · {{ money($s->rate) }}{{ $s->reservation->special_request ? ' · request' : '' }}{{ $flag && $flag['deposit'] !== 'none' ? ' · dep' : '' }}</small>
                </a>
              @endif
            @endforeach
            @foreach($outages[$room->id] ?? [] as $o)
              @php $span = \App\Services\ReservationDeskService::span($date, $to, $o->starts_on, $o->ends_on); @endphp
              @if($span)
                <span class="outage-bar" style="grid-column: {{ $span[0] + 2 }} / span {{ $span[1] }}" title="Blocked: {{ $o->reason }}">Blocked</span>
              @endif
            @endforeach
          </div>
        @endforeach
      @empty
        <div class="card"><div class="card-body">No rooms match these filters. Nothing has been invented to fill the board.</div></div>
      @endforelse
    </div>
  @elseif($view === 'board')
    <div class="board">
      @forelse($grouped as $name => $groupRooms)
        <h3>{{ $name }}</h3>
        <div class="board-grid">
          @foreach($groupRooms as $room)
            @php
              $occ = ($byRoom[$room->id] ?? collect())->first(fn ($s) => $s->status === 'checked_in') ?? ($byRoom[$room->id] ?? collect())->first();
              $photo = \App\Services\ReservationDeskService::photoPath($room);
            @endphp
            <a class="board-card {{ $room->status }} {{ $room->hk_status }}" href="{{ route('reservations.room', $room) }}">
              @if($photo)<img src="{{ asset($photo) }}" alt="Room {{ $room->room_no }}">@else<span class="type-code" title="No photo on file">{{ $room->roomType->code }}</span>@endif
              <b>{{ $room->room_no }}</b>
              <span>{{ $room->roomType->code }} · {{ ucfirst(str_replace('_', ' ', $room->hk_status)) }}</span>
              <em>{{ $occ ? $occ->reservation->guest->full_name : ($room->status === 'vacant' ? 'Vacant' : ucwords(str_replace('_',' ',$room->status))) }}</em>
              @if($occ)
                <small>{{ \App\Services\ReservationDeskService::stayLabel($occ, $date) }}@if($occ->reservation->special_request) · request @endif @if(($flags[$occ->id]['balance'] ?? 0) > 0.009) · {{ money($flags[$occ->id]['balance']) }} due @endif</small>
              @endif
            </a>
          @endforeach
        </div>
      @empty
        <p>No rooms match these filters.</p>
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
    <div class="card flush"><div class="card-body table-wrap">
      <table class="table"><thead><tr><th>Group</th><th>Guest</th><th>Dates</th><th>Rooms</th><th>Status</th><th>Allocation</th></tr></thead><tbody>
      @forelse($groups as $r)
        <tr>
          <td><a href="{{ route('reservations.show', $r) }}">{{ $r->group_name ?: $r->reservation_no }}</a><div class="tiny muted">{{ $r->reservation_no }}</div></td>
          <td>{{ $r->guest->full_name }}</td>
          <td>{{ $r->arrival_date->format('d M') }} – {{ $r->departure_date->format('d M') }}</td>
          <td class="small">{{ $r->rooms->map(fn ($s) => $s->room?->room_no ?? $s->roomType->code)->join(', ') }}</td>
          <td>{!! status_badge($r->status) !!}</td>
          <td class="small">
            @if($r->block)
              <a href="{{ route('blocks.show', $r->block) }}">{{ $r->block->block_no }} · {{ $r->block->name }}</a>
              <div class="tiny muted">{{ $r->block->status }} · {{ (int) $r->block->lines->sum('rooms') }} room(s) held · {{ \App\Services\BlockService::pickupPct($r->block) }}% picked up</div>
              @if($r->block->isHolding() && can_do('reservations.create') && $r->block->lines->isNotEmpty())
              <form method="post" action="{{ route('blocks.pickup', $r->block) }}" data-preview="{{ route('blocks.pickup.preview', $r->block) }}" class="desk-form">
                @csrf
                <textarea name="names" required rows="2" placeholder="One guest name per line" aria-label="Rooming list for {{ $r->block->block_no }}" style="width:100%;max-width:16rem"></textarea>
                <div class="flex gap-1 wrap">
                  <select name="room_type_id" required aria-label="Block room type">
                    @foreach($r->block->lines as $line)<option value="{{ $line->room_type_id }}">{{ $line->roomType->name ?? 'Type' }} · {{ $line->rooms }} held</option>@endforeach
                  </select>
                  <input type="number" name="adults" value="1" min="1" max="6" required aria-label="Adults" style="width:4rem">
                  <input type="date" name="arrival_date" required value="{{ $r->block->arrival_date->toDateString() }}" aria-label="Pickup arrival">
                  <input type="date" name="departure_date" required value="{{ $r->block->departure_date->toDateString() }}" aria-label="Pickup departure">
                  <button class="btn btn-sm" type="button" data-quote>Preview pickup</button>
                  <button class="btn btn-sm">Confirm pickup</button>
                </div>
                <p class="quote tiny muted">Preview uses the existing block engine. It does not create a guest or a reservation.</p>
              </form>
              @endif
            @else
              <span class="muted">No room-block allocation on file.</span>
              @if(can_do('reservations.create'))<a href="{{ route('blocks.create') }}">Open the group engine</a>@endif
            @endif
          </td>
        </tr>
      @empty
        <tr><td colspan="6">No group reservations in this window.</td></tr>
      @endforelse
      </tbody></table>
    </div></div>
  @else
    <div class="card"><div class="card-body">
      <h3>Waitlist</h3>
      <p class="small muted">A waitlist entry is not a reservation. Convert creates one only when the room type is free.</p>
      @if(can_do('reservations.create'))
      <form method="post" action="{{ route('reservations.waitlist.store') }}" class="filters">
        @csrf
        <div class="field"><label>Name</label><input name="contact_name" required></div>
        <div class="field"><label>Phone</label><input name="phone"></div>
        <div class="field"><label>Email</label><input name="email" type="email"></div>
        <div class="field"><label>Arrival</label><input type="date" name="arrival_date" required value="{{ $date->toDateString() }}"></div>
        <div class="field"><label>Departure</label><input type="date" name="departure_date" required value="{{ $date->copy()->addDay()->toDateString() }}"></div>
        <div class="field"><label>Room type</label><select name="room_type_id"><option value="">Later</option>@foreach($roomTypes as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select></div>
        <div class="field"><label>Adults</label><input type="number" name="adults" value="1" min="1" required></div>
        <div class="field"><label>Children</label><input type="number" name="children" value="0" min="0"></div>
        <div class="field"><label>Priority</label><input type="number" name="priority" value="0" min="0" max="9"></div>
        <div class="field" style="flex:1"><label>Notes</label><input name="notes"></div>
        <button class="btn btn-primary">Add to waitlist</button>
      </form>
      @endif
      <div class="table-wrap"><table class="table"><thead><tr><th>Priority</th><th>Guest</th><th>Dates</th><th>Type</th><th>Status</th><th></th></tr></thead><tbody>
      @forelse($waitlist as $w)
        <tr>
          <td>{{ $w->priority }}</td>
          <td>{{ $w->contact_name }}<div class="tiny muted">{{ $w->phone }} {{ $w->email }}</div></td>
          <td>{{ $w->arrival_date->format('d M') }} – {{ $w->departure_date->format('d M') }}</td>
          <td>{{ $w->roomType->name ?? '—' }}</td>
          <td>{{ $w->status }}@if($w->reservation) · <a href="{{ route('reservations.show', $w->reservation) }}">{{ $w->reservation->reservation_no }}</a>@endif</td>
          <td class="nowrap">
            @if($w->status === 'waiting')
              <form method="post" action="{{ route('reservations.waitlist.check', $w) }}" class="inline">@csrf<button class="btn btn-sm">Check availability</button></form>
            @endif
            @if($w->status === 'waiting' && can_do('reservations.create'))
              <form method="post" action="{{ route('reservations.waitlist.convert', $w) }}" class="inline">@csrf<button class="btn btn-sm btn-primary">Convert</button></form>
            @endif
            @if($w->status === 'waiting' && can_do('reservations.edit'))
              <form method="post" action="{{ route('reservations.waitlist.cancel', $w) }}" class="inline">@csrf<button class="btn btn-sm">Cancel</button></form>
            @endif
          </td>
        </tr>
      @empty
        <tr><td colspan="6">The waitlist is empty.</td></tr>
      @endforelse
      </tbody></table></div>
    </div></div>
  @endif
  <p id="desk-toast" class="desk-toast" hidden></p>
  <nav class="mobile-tabs" aria-label="Desk views">
    @foreach(['timeline' => 'Timeline', 'board' => 'Rooms', 'arrivals' => 'Arrivals', 'departures' => 'Depart', 'inhouse' => 'In house', 'list' => 'List'] as $key => $label)
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
  document.querySelectorAll('.stay-bar[draggable="true"]').forEach(bar => {
    bar.addEventListener('dragstart', e => { stayId = bar.dataset.stay; e.dataTransfer.setData('text/plain', stayId); });
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
  if (zoom) zoom.addEventListener('input', () => desk.style.setProperty('--day', zoom.value + 'px'));
  const key = 'nowills-theme';
  const apply = mode => {
    desk.classList.toggle('dark', mode === 'dark');
    document.body.classList.toggle('dark', mode === 'dark');
  };
  apply(localStorage.getItem(key) || localStorage.getItem('nowills-desk-theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));
  document.getElementById('print-btn')?.addEventListener('click', () => window.print());
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
      jsonPost(roomCheck.getAttribute('data-room-check'), new FormData(form)).then(({ j }) => {
        const note = form.querySelector('.tiny');
        if (note) note.textContent = j.message || 'No preview.';
        else say(j.message || 'No preview.');
      });
    }
  });
  document.addEventListener('submit', e => {
    const form = e.target;
    if (!form.dataset || !form.dataset.preview) return;
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
