@extends('layouts.app')
@section('title', 'Reservations')
@section('actions')
  <a href="{{ route('reservations.availability') }}" class="btn"><x-icon name="grid"/> Availability</a>
  @if(can_do('reservations.create'))<a href="{{ route('reservations.create') }}" class="btn btn-primary"><x-icon name="plus"/> New Reservation</a>@endif
@endsection
@section('content')
@php
  $q = request()->except('page');
  $go = fn (array $extra = []) => route('reservations.index', array_merge($q, $extra));
  $tabCounts = $tabs;
  $nav = [
    'arrivals' => 'Arrivals', 'duein' => 'Due in', 'inhouse' => 'In-house', 'stayover' => 'Stayover', 'dueout' => 'Due out',
    'departures' => 'Departures', 'unassigned' => 'Unassigned', 'vip' => 'VIP', 'groups' => 'Groups',
    'waitlist' => 'Waitlist', 'noshow' => 'No-show', 'cancelled' => 'Cancelled',
  ];
  $views = ['dashboard' => 'Dashboard', 'timeline' => 'Timeline', 'board' => 'Room board', 'list' => 'List', 'arrivals' => 'Arrivals', 'departures' => 'Departures', 'inhouse' => 'In-house', 'unassigned' => 'Unassigned', 'waitlist' => 'Waitlist', 'groups' => 'Groups'];
@endphp
<div id="desk" class="desk">
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
      <button type="button" class="btn btn-sm" id="theme-btn">Dark / light</button>
      <button type="button" class="btn btn-sm" id="full-btn">Fullscreen</button>
    </div>
  </header>

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
        ['RevPAR', $kpis['revpar'] === null ? '—' : money($kpis['revpar']), ['view' => 'board'], 'Posted room revenue divided by active rooms'],
        ['Deposits', money($kpis['deposits']), ['view' => 'timeline', 'focus' => 'deposit'], 'Advance payments posted today. Click shows stays with a payment on file'],
        ['Outstanding', $credit, ['view' => 'inhouse', 'focus' => 'balance'], 'Open folio balance. Credit means payments already exceed charges'],
      ],
    ];
  @endphp
  <section class="kpi-strip" aria-label="Today's figures">
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
  </section>

  <nav class="desk-nav" aria-label="Reservation status">
    <a href="{{ $go(['tab' => null, 'view' => 'timeline']) }}" class="{{ !request('tab') && $view === 'timeline' ? 'on' : '' }}">All</a>
    @foreach($nav as $key => $label)
      @php $target = in_array($key, ['waitlist','groups','unassigned','arrivals','departures','inhouse'], true) ? $key : (in_array($key, ['noshow','cancelled'], true) ? 'list' : ($view === 'list' ? 'list' : 'timeline')); @endphp
      <a href="{{ $go(['tab' => $key, 'view' => $target]) }}" class="{{ request('tab') === $key || $view === $key ? 'on' : '' }}">{{ $label }} <em>{{ $tabCounts[$key] ?? 0 }}</em></a>
    @endforeach
  </nav>

  <form class="desk-tools" method="get">
    <input type="hidden" name="view" value="{{ $view }}">
    @if(request('tab'))<input type="hidden" name="tab" value="{{ request('tab') }}">@endif
    <label>Date
      <span class="date-nav">
        <a href="{{ $go(['date' => $date->copy()->subDay()->toDateString()]) }}" aria-label="Previous day">‹</a>
        <input type="date" name="date" value="{{ $date->toDateString() }}">
        <a href="{{ $go(['date' => $date->copy()->addDay()->toDateString()]) }}" aria-label="Next day">›</a>
        <a href="{{ $go(['date' => bdate()->toDateString()]) }}">Today</a>
      </span>
    </label>
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
  </form>

  <div class="desk-views" role="tablist">
    @foreach($views as $key => $label)
      <a href="{{ $go(['view' => $key]) }}" class="{{ $view === $key ? 'on' : '' }}" role="tab">{{ $label }}</a>
    @endforeach
    <label class="zoom">Column <input id="col-zoom" type="range" min="36" max="92" value="52" aria-label="Timeline column width"></label>
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
  </div>

  @if($view === 'dashboard')
    @include('reservations.desk-dashboard')
  @elseif($view === 'timeline')
    <p class="tiny muted legend">Each bar is labelled, not colour alone: Confirmed, Tentative, In house, Due out, Due in, Checked out, Complimentary, House use. Gold border is VIP. Purple border is a group. “dep” means a payment is on file. A room with no photo shows its type code, not a stock picture. Drag asks for confirmation, then the server accepts or refuses the move. Keyboard: open the stay and use Check room, then Confirm room.</p>
    @if($loose->isNotEmpty())
      <div class="loose">
        <strong>Unassigned in this window</strong>
        @foreach($loose as $s)
          <a href="{{ route('reservations.stay', $s) }}" data-drawer="{{ route('reservations.stay', $s) }}">{{ $s->reservation->reservation_no }} {{ $s->reservation->guest->full_name }} · {{ $s->roomType->code }} · {{ $s->arrival_date->format('d M') }}</a>
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
            $photo = \App\Services\ReservationDeskService::photo($room);
            $names = \App\Services\ReservationDeskService::amenityNames($room);
          @endphp
          <div class="room-row {{ $room->status }}" data-room="{{ $room->id }}" style="--days:{{ $days }}">
            <a class="room-label" href="{{ route('reservations.room', $room) }}" data-drawer="{{ route('reservations.room', $room) }}">
              @if($photo)<img src="{{ asset($photo->path) }}" alt="">@else<span class="type-code" title="No photo on file">{{ $room->roomType->code }}</span>@endif
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
                <a class="stay-bar {{ $cls }}" draggable="{{ $s->status !== 'checked_out' && (can_do('frontdesk.roommove') || can_do('reservations.edit')) ? 'true' : 'false' }}" data-stay="{{ $s->id }}" href="{{ route('reservations.stay', $s) }}" data-drawer="{{ route('reservations.stay', $s) }}" style="grid-column: {{ $offset + 2 }} / span {{ $len }}" title="{{ $s->reservation->guest->full_name }} · {{ $s->reservation->reservation_no }} · {{ $marks }}">
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
              $photo = \App\Services\ReservationDeskService::photo($room);
            @endphp
            <a class="board-card {{ $room->status }} {{ $room->hk_status }}" href="{{ route('reservations.room', $room) }}" data-drawer="{{ route('reservations.room', $room) }}">
              @if($photo)<img src="{{ asset($photo->path) }}" alt="{{ $photo->caption ?: 'Room '.$room->room_no }}">@else<span class="type-code" title="No photo on file">{{ $room->roomType->code }}</span>@endif
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
    <div class="tabs">
      <a href="{{ $go(['view' => 'list', 'status' => null]) }}" class="{{ !request('status') ? 'active' : '' }}">All ({{ $counts->sum() }})</a>
      @foreach(['confirmed','tentative','checked_in','checked_out','cancelled','no_show'] as $s)
        <a href="{{ $go(['view' => 'list', 'status' => $s]) }}" class="{{ request('status') === $s ? 'active' : '' }}">{{ ucwords(str_replace('_',' ',$s)) }} ({{ $counts[$s] ?? 0 }})</a>
      @endforeach
    </div>
    <div class="card"><div class="card-body">
      <form class="filters" method="get">
        <input type="hidden" name="view" value="list">
        <input type="hidden" name="status" value="{{ request('status') }}">
        <div class="field" style="flex:1;min-width:220px"><label>Search</label><input name="q" value="{{ request('q') }}" placeholder="Res. no, guest, phone, group, OTA ref…"></div>
        <div class="field"><label>Arrival from</label><input type="date" name="from" value="{{ request('from') }}"></div>
        <div class="field"><label>Arrival to</label><input type="date" name="to" value="{{ request('to') }}"></div>
        <div class="field"><label>Source</label><select name="source"><option value="">All</option>@foreach($sources as $id => $n)<option value="{{ $id }}" @selected(request('source') == $id)>{{ $n }}</option>@endforeach</select></div>
        <div class="field"><label>Type</label><select name="type"><option value="">All</option><option value="individual" @selected(request('type')=='individual')>Individual</option><option value="group" @selected(request('type')=='group')>Group</option></select></div>
        @if($channels->isNotEmpty())
        <div class="field"><label>Channel</label><select name="channel"><option value="">All</option>@foreach($channels as $id => $n)<option value="{{ $id }}" @selected(request('channel') == $id)>{{ $n }}</option>@endforeach</select></div>
        @endif
        <div class="field"><label>Sort</label><select name="sort"><option value="arrival">Arrival date</option><option value="created" @selected(request('sort')=='created')>Newest first</option></select></div>
        <button class="btn btn-primary">Filter</button>
      </form>
    </div></div>
    <div class="card flush"><div class="card-body table-wrap">
      <table class="table">
        <thead><tr><th>Res. No</th><th>Guest</th><th>Arrival</th><th>Departure</th><th>N</th><th>Rooms</th><th>Pax</th><th>Source</th><th>Status</th><th></th></tr></thead>
        <tbody>
        @forelse($reservations as $r)
          <tr>
            <td><a href="{{ route('reservations.show', $r) }}" class="bold">{{ $r->reservation_no }}</a>@if($r->booking_type==='group')<div class="tiny"><span class="badge badge-purple">Group</span> {{ $r->group_name }}</div>@endif</td>
            <td>{{ $r->guest->full_name }}@if($r->guest->is_vip) <span class="badge badge-purple">VIP</span>@endif<div class="tiny muted">{{ $r->guest->phone }}{{ $r->company ? ' · '.$r->company->name : '' }}</div></td>
            <td class="nowrap">{{ $r->arrival_date->format('d M Y') }}</td><td class="nowrap">{{ $r->departure_date->format('d M Y') }}</td><td>{{ $r->nights }}</td>
            <td class="small">{{ $r->rooms->where('status','!=','cancelled')->map(fn($s) => $s->room?->room_no ?? $s->roomType->code)->join(', ') }}</td>
            <td>{{ $r->adults }}/{{ $r->children }}</td>
            <td class="small">{{ $r->source?->name }}</td>
            <td>{!! status_badge($r->status) !!}</td>
            <td class="nowrap">
              @php $openStay = $r->rooms->first(fn ($s) => in_array($s->status, ['reserved', 'checked_in'], true)); @endphp
              @if($openStay)<a class="btn btn-sm" href="{{ route('reservations.stay', $openStay) }}" data-drawer="{{ route('reservations.stay', $openStay) }}">Open</a>@endif
              <a class="btn btn-sm" href="{{ route('reservations.show', $r) }}"><x-icon name="eye" size="sm"/></a>
            </td>
          </tr>
        @empty
          <tr><td colspan="10"><x-empty text="No reservations match your filters." icon="calendar"/></td></tr>
        @endforelse
        </tbody>
      </table>
      {{ $reservations->links() }}
    </div></div>
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
<aside id="drawer" class="drawer" hidden>
  <div class="drawer-bar"><strong>Details</strong><button type="button" id="drawer-close" class="btn btn-sm">Close</button></div>
  <div id="drawer-body" class="drawer-body"></div>
</aside>
@endsection
@push('head')
<style>
.desk{--navy:#0b1b3a;--day:52px;background:#fff;border:1px solid var(--c-border);border-radius:16px;overflow:hidden;box-shadow:var(--shadow)}
.desk-head{display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;padding:1.1rem 1.2rem;background:linear-gradient(120deg,#0b1b3a,#16315f);color:#fff}
.desk-head h1{color:#fff;font-size:1.45rem;margin:.15rem 0}
.desk-head p{margin:0;color:#c9d6ee;font-size:.85rem}
.desk-head .btn{background:#fff;color:var(--navy);border-color:transparent}
.kpi-strip{display:flex;flex-direction:column;gap:.45rem;padding:.8rem 1rem;background:#f7f9fc}
.kpi-group{display:grid;grid-template-columns:5.5rem 1fr;gap:.4rem;align-items:center}
.kpi-label{font-size:.68rem;letter-spacing:.08em;text-transform:uppercase;color:#5b6b86;font-weight:700}
.kpi-row{display:flex;gap:.4rem;overflow:auto;padding-bottom:.15rem}
.kpi{background:#fff;border:1px solid var(--c-border);border-radius:10px;padding:.4rem .55rem;min-width:6.2rem;color:inherit;text-decoration:none}
.kpi.on{border-color:#0b1b3a;box-shadow:inset 0 0 0 1px #0b1b3a}
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
.drawer{position:fixed;top:0;right:0;height:100vh;width:min(440px,100%);background:#fff;z-index:60;box-shadow:-12px 0 40px rgba(15,23,42,.18);display:flex;flex-direction:column}
.desk.dark .drawer,.desk.dark .drawer-body,body.dark .drawer,body.dark .drawer-body{background:#132033;color:#e8eef8}
.drawer-bar{display:flex;justify-content:space-between;align-items:center;padding:.7rem .9rem;background:var(--navy);color:#fff}
.drawer-body{overflow:auto;padding:1rem}
.desk-form{margin:.8rem 0;padding-top:.6rem;border-top:1px solid var(--c-border)}
.desk-form h3{font-size:.9rem;margin-bottom:.35rem}
.desk-toast{position:fixed;bottom:1rem;left:50%;transform:translateX(-50%);background:#0b1b3a;color:#fff;padding:.6rem 1rem;border-radius:10px;z-index:70}
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
  .drawer{width:100%}
  .room-label{width:140px}
  .tape-head,.room-row{grid-template-columns:140px repeat(var(--days),minmax(var(--day),1fr))}
  .desk{padding-bottom:3.2rem}
  .mobile-tabs{display:flex}
}
@media print{
  .sidebar,.topbar,.mobile-tabs,.drawer,.desk-toast,.desk-head .btn,#print-btn{display:none !important}
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
</style>
@endpush
@push('scripts')
<script>
(function () {
  const desk = document.getElementById('desk');
  const drawer = document.getElementById('drawer');
  const body = document.getElementById('drawer-body');
  const toast = document.getElementById('desk-toast');
  const dropUrl = @json(route('frontdesk.tapechart.drop'));
  const csrf = @json(csrf_token());
  function say(msg) { toast.hidden = false; toast.textContent = msg; setTimeout(() => toast.hidden = true, 5000); }
  function openCard(url) {
    drawer.hidden = false;
    body.innerHTML = '<p class="muted">Loading the live record…</p>';
    const sep = url.indexOf('?') >= 0 ? '&' : '?';
    fetch(url + sep + 'partial=1', { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' } })
      .then(r => { if (!r.ok) throw new Error('refused'); return r.text(); })
      .then(html => { body.innerHTML = html; })
      .catch(() => { body.innerHTML = '<p>Could not open the panel. <a href="' + url + '">Open the full page</a>.</p>'; });
  }
  document.querySelectorAll('[data-drawer]').forEach(el => {
    el.addEventListener('click', e => { if (e.metaKey || e.ctrlKey || e.shiftKey) return; e.preventDefault(); openCard(el.getAttribute('data-drawer')); });
  });
  document.getElementById('drawer-close').addEventListener('click', () => { drawer.hidden = true; });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') drawer.hidden = true; });
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
    const appBtn = document.getElementById('app-theme');
    if (appBtn) appBtn.textContent = mode === 'dark' ? 'Light' : 'Dark';
  };
  apply(localStorage.getItem(key) || localStorage.getItem('nowills-desk-theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));
  document.getElementById('theme-btn').addEventListener('click', () => {
    const next = desk.classList.contains('dark') ? 'light' : 'dark';
    localStorage.setItem(key, next);
    localStorage.setItem('nowills-desk-theme', next);
    apply(next);
  });
  document.getElementById('full-btn').addEventListener('click', () => {
    if (!document.fullscreenElement) desk.requestFullscreen(); else document.exitFullscreen();
  });
  document.getElementById('print-btn').addEventListener('click', () => window.print());
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
  const tickUrl = @json(route('reservations.tick'));
  let tick = @json($tick);
  setInterval(() => {
    fetch(tickUrl + '?date=' + @json($date->toDateString()), { headers: { 'Accept': 'application/json' } })
      .then(r => r.ok ? r.json() : null)
      .then(j => { if (j && j.tick && j.tick !== tick) { tick = j.tick; say('The desk changed in another session. Reload to see it.'); } })
      .catch(() => {});
  }, 60000);
})();
</script>
@endpush
