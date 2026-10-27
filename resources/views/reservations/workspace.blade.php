@extends('layouts.app')
@section('title', 'Reservation Workspace')

@section('actions')
@if (can_do('reservations.view'))
  <a class="btn btn-icon" href="{{ route('reservations.workspace.report', ['date' => $date->toDateString()]) }}" target="_blank" rel="noopener" aria-label="Open day report" title="Day report"><x-icon name="chart"/></a>
  <a class="btn btn-icon" href="{{ route('reservations.daysummary') }}" aria-label="Open day summary" title="Day summary"><x-icon name="calendar"/></a>
@endif
@if (can_do('frontdesk.checkin'))
  <a class="btn btn-icon" href="{{ route('frontdesk.walkin') }}" aria-label="Create walk-in stay" title="Walk-in"><x-icon name="login"/></a>
@endif
@if (can_do('reservations.create'))
  <a class="btn btn-icon" href="{{ route('blocks.create') }}" aria-label="Create group block" title="Group block"><x-icon name="users"/></a>
  <a class="btn btn-icon" href="{{ route('reservations.create') }}" aria-label="Create reservation" title="New reservation"><x-icon name="building"/></a>
@endif
@if (can_do('channels.view'))
  <a class="btn btn-icon" href="{{ route('cm.index') }}" aria-label="Open channel manager" title="Channel manager"><x-icon name="globe"/></a>
@endif
<button type="button" class="btn btn-icon" id="refreshWsBoard" aria-label="Refresh reservation board" title="Refresh board"><x-icon name="refresh"/></button>
@endsection

@section('content')
@php
  $businessDate = $businessDate ?? bdate()->copy()->startOfDay();
  $isToday = $date->toDateString() === $businessDate->toDateString();
  $isHistory = $date->lt($businessDate);
  $isFuture = $date->gt($businessDate);
  $filters = request()->only(['status', 'type', 'floor', 'source']);
  $selectedType = is_string($filters['type'] ?? null) ? $filters['type'] : '';
  $selectedFloor = is_string($filters['floor'] ?? null) ? $filters['floor'] : '';
  $selectedSource = is_string($filters['source'] ?? null) ? $filters['source'] : '';
  $sourceOptions = $cards->pluck('stay.reservation.businessSource.name')->filter()->unique()->sort()->values();
@endphp
<div class="ws-head">
  <div>
    <h1 class="ws-title">Reservation Workspace</h1>
    <div class="muted ws-date-nav">
      @if ($date->gt($dateMin))
        <a class="btn btn-sm" data-ws-nav href="{{ route('reservations.workspace', array_merge(request()->except('date'), ['date' => $date->copy()->subDay()->toDateString()])) }}" aria-label="Previous day" title="Previous day">←</a>
      @else
        <span class="btn btn-sm disabled" aria-disabled="true" title="Date range limit">←</span>
      @endif
      <b style="margin:0 8px">{{ $date->format('l, j F Y') }}</b>
      @if ($date->lt($dateMax))
        <a class="btn btn-sm" data-ws-nav href="{{ route('reservations.workspace', array_merge(request()->except('date'), ['date' => $date->copy()->addDay()->toDateString()])) }}" aria-label="Next day" title="Next day">→</a>
      @else
        <span class="btn btn-sm disabled" aria-disabled="true" title="Date range limit">→</span>
      @endif
      @if (! $isToday)
        <a class="btn btn-sm btn-ghost" data-ws-nav href="{{ route('reservations.workspace', array_merge(request()->except('date'), ['date' => $businessDate->toDateString()])) }}">Business date</a>
      @endif
      <label class="ws-date-jump-label" for="wsDateJump">Go to</label>
      <input id="wsDateJump" class="ws-date-jump" type="date" value="{{ $date->toDateString() }}" min="{{ $dateMin->toDateString() }}" max="{{ $dateMax->toDateString() }}" aria-label="Jump to reservation board date">
      @if ($isFuture)<span class="badge badge-blue" style="margin-left:6px">Planning ahead</span>
      @elseif ($isHistory)<span class="badge badge-slate" style="margin-left:6px">Historic board</span>
      @else<span class="badge badge-green" style="margin-left:6px">Business date</span>@endif
    </div>
    @if ($isHistory)<div class="muted small ws-history-note">Past stays are inferred from current reservation records; dated outages and leases are honored, but room-status and housekeeping history is unavailable.</div>
    @elseif ($isFuture)<div class="muted small ws-history-note">Forward reservations, active non-occupancy room flags and dated blocks apply; housekeeping is current and availability may change.</div>@endif
  </div>
  <div class="flex gap">
    <span class="badge badge-slate" id="wsClock" title="Data generated when this page was rendered">Board snapshot · {{ now()->format('H:i:s') }}</span>
  </div>
</div>

{{-- ── KPI strip ── --}}
@php
  use App\Services\ReservationWorkspaceService as WS;
  $guestLabel = $isFuture ? 'Guests expected' : ($isHistory ? 'Guests staying' : 'In-house guests');
  $revenueLabel = $isFuture ? 'Booked room-rate value' : ($isHistory ? 'Room-rate value' : 'Room-rate total');
  $tile = [
    'occupancy' => ['Occupancy', fn ($v) => $v.'%'],
    'revenue' => [$revenueLabel, fn ($v) => money($v)],
    'available' => ['Available', fn ($v) => $v],
    'occupied' => ['Occupied', fn ($v) => $v],
    'reserved' => ['Reserved', fn ($v) => $v],
    'arrivals' => ['Arrivals', fn ($v) => $v],
    'departures' => ['Departures', fn ($v) => $v],
    'inhouse' => [$guestLabel, fn ($v) => $v],
    'vips' => [$isFuture ? 'VIP guests expected' : 'VIP guests', fn ($v) => $v],
  ];
  $tileLinks = [
    'occupancy' => [route('frontdesk.tapechart'), 'frontdesk.view'],
    'revenue' => [route('revenue.index'), 'revenue.view'],
    'available' => [route('frontdesk.rooms'), 'frontdesk.view'],
    'occupied' => [route('frontdesk.rooms'), 'frontdesk.view'],
    'reserved' => [route('frontdesk.rooms'), 'frontdesk.view'],
    'arrivals' => [route('frontdesk.arrivals'), 'frontdesk.view'],
    'departures' => [route('frontdesk.departures'), 'frontdesk.view'],
    'inhouse' => [route('frontdesk.inhouse'), 'frontdesk.view'],
    'vips' => [route('frontdesk.inhouse'), 'frontdesk.view'],
  ];
  $kpis = [];
  foreach ($tile as $key => [$label, $fmt]) {
      if ($key === 'revenue' && ! can_do('revenue.view')) continue;
      $delta = $deltas[$key] ?? null;
      $tone = $delta === null || $delta == 0 ? 'green' : ($delta > 0 ? 'green' : 'red');
      $kpis[] = [
          'key' => $key,
          'label' => $label,
          'value' => $fmt($trend[$key][count($trend[$key]) - 1]),
          'delta' => $delta === null ? '—' : ($delta > 0 ? '+' : '').$delta.'%',
          'tone' => $tone,
          'series' => $trend[$key],
          'link' => $tileLinks[$key][0],
          'permission' => $tileLinks[$key][1],
      ];
  }
@endphp
<div class="ws-kpis">
  @foreach ($kpis as $k)
  @php $canOpenKpi = $isToday && $k['key'] !== 'revenue' && can_do($k['permission']); @endphp
  @if ($canOpenKpi)<a class="ws-kpi" href="{{ $k['link'] }}" style="color:inherit;text-decoration:none" title="{{ $k['label'] === $revenueLabel ? 'Sum of nightly rates for stays covering this date; excludes taxes, fees, and folio postings.' : 'Change compared with the previous selected day' }}">
  @else<div class="ws-kpi" title="{{ $k['label'] === $revenueLabel ? 'Sum of nightly rates for stays covering this date; excludes taxes, fees, and folio postings.' : 'Change compared with the previous selected day' }}">
  @endif
    <div class="flex gap"><span class="ws-kpi-delta {{ $k['tone'] === 'red' ? 'down' : 'up' }}">{{ $k['delta'] }}</span></div>
    {!! WS::spark($k['series'], $k['tone']) !!}
    <div class="ws-kpi-value">{{ $k['value'] }}</div>
    <div class="ws-kpi-label">{{ $k['label'] }}</div>
  @if ($canOpenKpi)</a>@else</div>@endif
  @endforeach
</div>
@if (can_do('revenue.view'))<div class="ws-revenue-note muted small">Room-rate value totals the nightly rate for each covering stay; it excludes taxes, fees, and folio postings.</div>@endif

{{-- ── Filters ── --}}
<div class="ws-filters">
  <span class="muted small">FILTERS</span>
  <select id="fStatus" class="ws-select"><option value="">Room Status: All</option>
    @foreach (['available' => 'Available', 'reserved' => 'Reserved', 'occupied' => 'Occupied', 'out_of_order' => 'Out of Order', 'out_of_service' => 'Out of Service', 'leased' => 'Leased', 'maintenance' => 'Maintenance', 'house_use' => 'House Use', 'group_hold' => 'Group Hold', 'other_block' => 'Other Block'] as $k => $l)
    <option value="{{ $k }}" @selected(($filters['status'] ?? '') === $k)>Room Status: {{ $l }}</option>@endforeach
  </select>
  <select id="fType" class="ws-select"><option value="">Room Type: All</option>
    @foreach ($roomTypes as $rt)<option value="{{ $rt->id }}" @selected($selectedType === (string) $rt->id)>Room Type: {{ $rt->name }}</option>@endforeach
    @if ($selectedType !== '' && ! $roomTypes->contains(fn ($rt) => (string) $rt->id === $selectedType))<option value="{{ $selectedType }}" selected>Room Type: {{ $selectedType }} (not on this date)</option>@endif
  </select>
  <select id="fFloor" class="ws-select"><option value="">Floor: All</option>
    @foreach ($floors as $f)<option value="{{ $f->id }}" @selected($selectedFloor === (string) $f->id)>Floor: {{ $f->name }}</option>@endforeach
    @if ($selectedFloor !== '' && ! $floors->contains(fn ($f) => (string) $f->id === $selectedFloor))<option value="{{ $selectedFloor }}" selected>Floor: {{ $selectedFloor }} (not on this date)</option>@endif
  </select>
  <select id="fSource" class="ws-select"><option value="">Source: All</option>
    @foreach ($sourceOptions as $src)<option value="{{ $src }}" @selected($selectedSource === (string) $src)>Source: {{ $src }}</option>@endforeach
    @if ($selectedSource !== '' && ! $sourceOptions->contains(fn ($src) => (string) $src === $selectedSource))<option value="{{ $selectedSource }}" selected>Source: {{ $selectedSource }} (not on this date)</option>@endif
  </select>
  <button type="button" class="btn btn-sm btn-ghost" id="clearWsFilters">Clear filters</button>
</div>

<div class="ws-body">
  {{-- ── Room board ── --}}
  <div class="ws-grid" id="wsGrid">
    @forelse ($cards as $c)
      @php $room = $c['room']; $stay = $c['stay']; $src = $stay?->reservation?->businessSource?->name ?? ''; @endphp
      <div class="ws-card" data-status="{{ $c['status'] }}" data-type="{{ $room->room_type_id }}" data-floor="{{ $room->floor_id }}" data-source="{{ $src }}">
        <div class="ws-card-head">
          <div class="flex gap">
            <span class="ws-room-no">{{ $room->room_no }}</span>
            <div>
              <div class="bold small">{{ $room->roomType?->name ?? '—' }}</div>
              <div class="muted small">{{ $room->floor?->name ? 'F'.$room->floor->name : '' }}{{ $room->roomType?->code ? ' · '.$room->roomType->code : '' }}</div>
            </div>
          </div>
          <div class="text-right">
            <span class="badge badge-{{ ['available' => 'green', 'reserved' => 'amber', 'occupied' => 'blue', 'out_of_order' => 'red', 'out_of_service' => 'red', 'leased' => 'slate', 'maintenance' => 'amber', 'house_use' => 'slate', 'group_hold' => 'blue', 'other_block' => 'slate'][$c['status']] }}">{{ strtoupper(str_replace('_', ' ', $c['status'])) }}</span>
            @if ($c['arriving_on_date'])<div class="ws-tag">ARRIVING ON THIS DATE</div>@endif
          </div>
        </div>

        <div class="ws-photo ws-photo-link" data-room="{{ $room->id }}" role="button" tabindex="0" aria-label="Open room {{ $room->room_no }} details" title="Room details" style="background:{{ $room->roomType?->image ? 'url('.asset($room->roomType->image).') center/cover no-repeat, ' : '' }}linear-gradient(135deg, hsl({{ ($room->room_type_id * 47) % 360 }},32%,28%), hsl({{ ($room->room_type_id * 47 + 40) % 360 }},28%,42%))">
          <span class="ws-photo-tag">{{ strtoupper($room->roomType?->code ?? $room->roomType?->name ?? 'ROOM') }}</span>
          <span class="ws-photo-rate">{{ money($c['rate']) }} <small>/ night</small></span>
        </div>
        <div class="ws-room-meta small muted">
          <span><x-icon name="home" /> {{ $room->roomType?->size_sqm ? rtrim(rtrim(number_format($room->roomType->size_sqm, 1), '0'), '.').' m²' : ($room->roomType?->code ?? '—') }}</span>
          <span><x-icon name="user"/> {{ $room->roomType?->max_adults ?? 2 }}</span>
          <span><x-icon name="shield"/> {{ $room->is_smoking ? 'Smoking' : 'Non-smoking' }}</span>
        </div>
        <div class="ws-amen">
          @foreach (array_slice($c['amenities'], 0, 10) as $a)
            <span title="{{ $a }}"><x-icon name="{{ WS::amenityIcon($a) }}"/></span>
          @endforeach
        </div>

        @if ($stay)
        <div class="ws-guest {{ $c['status'] === 'reserved' ? 'arriving' : ($c['status'] === 'occupied' ? 'inhouse' : '') }}">
          <div class="flex gap">
            <span class="ws-avatar">{{ mb_substr($stay->guest?->first_name ?? $stay->reservation?->guest?->first_name ?? 'G', 0, 1) }}</span>
            <div>
              <div class="bold small">{{ $stay->guest?->first_name ?? $stay->reservation?->guest?->first_name }} {{ $stay->guest?->last_name ?? $stay->reservation?->guest?->last_name }}</div>
              <div class="muted small">{{ $src ?: 'Direct' }}{{ ($stay->guest?->is_vip ?? $stay->reservation?->guest?->is_vip) ? ' · VIP' : '' }}{{ $stay->guest?->nationality ? ' · '.$stay->guest->nationality : '' }}</div>
            </div>
          </div>
          <div class="ws-stay small">
            <span><b>{{ \Illuminate\Support\Carbon::parse($stay->arrival_date)->format('d M') }}</b><br><span class="muted">Arrive</span></span>
            <span class="ws-nights">{{ max(1, \Illuminate\Support\Carbon::parse($stay->arrival_date)->diffInDays(\Illuminate\Support\Carbon::parse($stay->departure_date))) }}n</span>
            <span><b>{{ \Illuminate\Support\Carbon::parse($stay->departure_date)->format('d M') }}</b><br><span class="muted">Depart</span></span>
          </div>
          <div class="ws-rate-row small">
            <div><span class="muted">Nightly rate</span><br><span class="bold">{{ money($c['rate']) }}</span> <span class="muted">/n</span></div>
            @if (can_do('folio.view'))
              @php $cardDue = $c['due'] ?? $c['balance']; @endphp
              @if ($cardDue !== null)
              <div class="text-right {{ $cardDue > 0 ? 'ws-due' : 'muted' }}" title="{{ $stay->status === 'checked_out' ? 'Final folio balance' : 'Estimated checkout amount, including nights and fixed charges still to post' }}">{{ $cardDue > 0 ? money($cardDue).' due' : 'Settled' }}</div>
              @endif
            @endif
          </div>
        </div>
        @else
        <div class="ws-guest vacant">
          <div class="muted small">{{ $c['status'] === 'occupied' ? 'OCCUPIED · NO LINKED STAY' : ($c['status'] === 'available' ? 'VACANT · RACK RATE' : 'ROOM UNAVAILABLE') }}</div>
          <div class="ws-vacant-rate">{{ money($c['rate']) }} <small class="muted">/night</small></div>
          @if ($c['status'] === 'available' && ! $isHistory && can_do('reservations.create'))
            <a class="btn btn-sm btn-primary ws-reserve" href="{{ route('reservations.create', ['room' => $room->id, 'arrival' => $date->toDateString(), 'departure' => $date->copy()->addDay()->toDateString()]) }}">Reserve Room</a>
          @endif
        </div>
        @endif

        <div class="ws-actions">
          @if ($stay && $stay->status === 'reserved')
            @if ($isToday && $c['status'] === 'reserved' && can_do('frontdesk.checkin'))<a class="btn btn-sm btn-primary" style="background:#16a34a" href="{{ route('frontdesk.checkin', $stay) }}"><x-icon name="login"/> Check-In</a>
            @elseif ($isToday && $c['status'] !== 'reserved' && can_do('frontdesk.checkin'))<span class="muted small">Resolve room status before check-in.</span>@endif
            @if (can_do('reservations.view'))<a class="btn btn-sm" href="{{ route('reservations.show', $stay->reservation_id) }}">Reservation details</a>@endif
          @elseif ($stay && $stay->status === 'checked_in')
            @if ($isToday && can_do('frontdesk.checkout'))<a class="btn btn-sm" style="background:#ea580c;color:#fff" href="{{ route('frontdesk.checkout', $stay) }}"><x-icon name="logout"/> Check-Out</a>@endif
            @if ($isToday && can_do('frontdesk.roommove'))
              @php $extendTo = $stay->departure_date->copy()->addDay()->max($businessDate->copy()->addDay()); @endphp
              <form method="POST" action="{{ route('frontdesk.amend', $stay) }}" class="ws-extend-form">
                @csrf
                <label class="sr-only" for="extend-{{ $stay->id }}">New departure date for room {{ $room->room_no }}</label>
                <input id="extend-{{ $stay->id }}" type="date" name="departure_date" min="{{ $extendTo->toDateString() }}" value="{{ $extendTo->toDateString() }}" required>
                <button type="submit" class="btn btn-sm" title="Extend this stay">Extend</button>
              </form>
            @endif
          @endif
          @if (! $isHistory && in_array($c['status'], ['available', 'reserved'], true) && can_do('housekeeping.manage') && $room->status === 'vacant' && $room->hk_status !== 'dirty')
            <form method="POST" action="{{ route('housekeeping.status') }}" class="ws-inline-action">
              @csrf
              <input type="hidden" name="rooms[]" value="{{ $room->id }}">
              <input type="hidden" name="hk_status" value="dirty">
              <button type="submit" class="btn btn-sm btn-ghost" aria-label="Mark room {{ $room->room_no }} dirty" title="Mark current housekeeping status dirty"><x-icon name="broom"/> Mark Dirty</button>
            </form>
          @endif
          <button type="button" class="btn btn-sm btn-ghost ws-all" data-room="{{ $room->id }}" aria-label="More actions for room {{ $room->room_no }}">··· All actions</button>
        </div>
      </div>
    @empty
      <div class="card ws-empty"><div class="card-body"><b>No active rooms</b><div class="muted small">There are no active rooms to show for this property.</div></div></div>
    @endforelse
    <div class="card ws-empty" id="wsNoResults" hidden><div class="card-body"><b>No rooms match these filters</b><div class="muted small">Clear one or more filters to see the full room board.</div></div></div>
  </div>

  {{-- ── Right rail ── --}}
  <div class="ws-rail">
    <div class="card ws-rail-card"><div class="card-body">
      @php
        $m = $date->copy()->startOfMonth(); $days = $m->daysInMonth; $pad = ((int) $m->format('w') + 6) % 7;
        $prevMonthDate = $m->copy()->subDay();
        $nextMonthDate = $m->copy()->addMonth();
      @endphp
      <div class="ws-cal-head">
        @if ($prevMonthDate->gte($dateMin))<a class="btn btn-sm btn-ghost" data-ws-nav href="{{ route('reservations.workspace', array_merge(request()->except('date'), ['date' => $prevMonthDate->toDateString()])) }}" aria-label="Previous month" title="Previous month">‹</a>@else<span class="btn btn-sm btn-ghost disabled" aria-disabled="true">‹</span>@endif
        <div class="bold small" style="text-transform:uppercase">{{ $m->format('F Y') }}</div>
        @if ($nextMonthDate->lte($dateMax))<a class="btn btn-sm btn-ghost" data-ws-nav href="{{ route('reservations.workspace', array_merge(request()->except('date'), ['date' => $nextMonthDate->toDateString()])) }}" aria-label="Next month" title="Next month">›</a>@else<span class="btn btn-sm btn-ghost disabled" aria-disabled="true">›</span>@endif
      </div>
      <div class="ws-cal">
        @foreach (['M','T','W','T','F','S','S'] as $d)<span class="ws-cal-d muted">{{ $d }}</span>@endforeach
        @for ($i = 0; $i < $pad; $i++)<span></span>@endfor
        @for ($day = 1; $day <= $days; $day++)
          @php
            $ds = $m->copy()->day($day)->toDateString();
            $selectedDay = $ds === $date->toDateString();
            $businessDay = $ds === $businessDate->toDateString();
            $inRange = $ds >= $dateMin->toDateString() && $ds <= $dateMax->toDateString();
            $hasA = isset($monthEvents['arrivals'][$ds]);
            $hasD = isset($monthEvents['departures'][$ds]);
          @endphp
          @if ($inRange)
            <a class="ws-cal-day {{ $selectedDay ? 'selected' : '' }} {{ $businessDay ? 'today' : '' }}" data-ws-nav href="{{ route('reservations.workspace', array_merge(request()->except('date'), ['date' => $ds])) }}" aria-label="{{ \Illuminate\Support\Carbon::parse($ds)->format('l, j F Y') }}{{ $hasA ? ', arrivals' : '' }}{{ $hasD ? ', departures' : '' }}">
          @else
            <span class="ws-cal-day disabled" aria-disabled="true" title="Outside the selectable date range">
          @endif
            {{ $day }}
            <span class="ws-cal-dots">@if($hasA)<i class="dot-a"></i>@endif @if($hasD)<i class="dot-d"></i>@endif</span>
          @if ($inRange)</a>@else</span>@endif
        @endfor
      </div>
      <div class="ws-cal-legend small muted"><span class="ws-legend"><i class="dot-a"></i> Arrivals</span> <span class="ws-legend"><i class="dot-d"></i> Departures</span></div>
    </div></div>

    <div class="card ws-rail-card"><div class="card-body">
      <div class="ws-rail-title">ROOM STATUS SUMMARY</div>
      <div class="ws-stack">
        @foreach ($statusSummary as $label => [$n, $color])
          @if ($n > 0)<span style="flex:{{ $n }};background:{{ $color }}" title="{{ $label }} {{ $n }}"></span>@endif
        @endforeach
      </div>
      <table class="ws-rail-list">
        @foreach ($statusSummary as $label => [$n, $color])
        <tr><td><span class="dot" style="background:{{ $color }}"></span> {{ $label }}</td><td class="text-right bold">{{ $n }}</td></tr>
        @endforeach
      </table>
    </div></div>

    <div class="card ws-rail-card"><div class="card-body">
      @if (can_do('frontdesk.view'))<a class="ws-rail-title" href="{{ route('frontdesk.arrivals') }}" style="color:inherit"><x-icon name="login"/> ARRIVALS · {{ $arrivals->count() }}</a>@else<div class="ws-rail-title"><x-icon name="login"/> ARRIVALS · {{ $arrivals->count() }}</div>@endif
      @forelse ($arrivals as $s)
      @php
        $canAssign = can_do('reservations.edit') && ! $s->room_id && $s->status === 'reserved' && $date->gte($businessDate);
        $canCheckIn = $isToday && $s->status === 'reserved' && $s->room?->status === 'vacant' && ! isset($inventoryBlocks[$s->room_id]) && can_do('frontdesk.checkin');
        $freeRooms = $canAssign ? ($assignableByStay[$s->id] ?? collect()) : collect();
        $freeSameType = $freeRooms->where('room_type_id', $s->room_type_id);
        $freeOther = $freeRooms->where('room_type_id', '!=', $s->room_type_id);
      @endphp
      <div class="ws-person-row">
        @if ($canCheckIn)<a class="ws-person" href="{{ route('frontdesk.checkin', $s) }}">
        @elseif (can_do('reservations.view'))<a class="ws-person" href="{{ route('reservations.show', $s->reservation_id) }}">
        @else<div class="ws-person">
        @endif
          <span class="ws-avatar sm">{{ mb_substr($s->guest?->first_name ?? $s->reservation?->guest?->first_name ?? 'G', 0, 1) }}</span>
          <span><b>{{ $s->guest?->first_name ?? $s->reservation?->guest?->first_name }} {{ $s->guest?->last_name ?? $s->reservation?->guest?->last_name }}</b>
            <span class="muted small">Rm {{ $s->room?->room_no ?? '—' }} · {{ max(1, \Illuminate\Support\Carbon::parse($s->arrival_date)->diffInDays(\Illuminate\Support\Carbon::parse($s->departure_date))) }}n · {{ $s->reservation?->businessSource?->name ?? 'Direct' }}</span></span>
        @if ($canCheckIn || can_do('reservations.view'))</a>@else</div>@endif
        @if ($canAssign)
          @if ($freeSameType->isNotEmpty() || $freeOther->isNotEmpty())
          <form method="POST" action="{{ route('reservations.assign', $s->reservation_id) }}" class="ws-assign">
            @csrf
            <select name="assign[{{ $s->id }}]" class="ws-assign-select" aria-label="Room for {{ $s->guest?->first_name ?? $s->reservation?->guest?->first_name }}">
              @foreach ($freeSameType as $r)
              <option value="{{ $r->id }}">{{ $r->room_no }} · {{ $r->roomType?->name }}{{ $r->hk_status === 'clean' || $r->hk_status === 'inspected' ? '' : ' · '.$r->hk_status }}</option>
              @endforeach
              @if ($freeSameType->isNotEmpty() && $freeOther->isNotEmpty())<option disabled>── other types ──</option>@endif
              @foreach ($freeOther as $r)
              <option value="{{ $r->id }}">{{ $r->room_no }} · {{ $r->roomType?->name }}{{ $r->hk_status === 'clean' || $r->hk_status === 'inspected' ? '' : ' · '.$r->hk_status }}</option>
              @endforeach
            </select>
            <button type="submit" class="btn btn-sm">Assign</button>
          </form>
          @else
          <div class="muted small ws-assign-none">No free room for these dates — waitlist or extend a departure.</div>
          @endif
        @endif
      </div>
      @empty<div class="muted small">No arrivals on this date.</div>@endforelse
    </div></div>

    <div class="card ws-rail-card"><div class="card-body">
      @if (can_do('frontdesk.view'))<a class="ws-rail-title" href="{{ route('frontdesk.departures') }}" style="color:inherit"><x-icon name="logout"/> DEPARTURES · {{ $departures->count() }}</a>@else<div class="ws-rail-title"><x-icon name="logout"/> DEPARTURES · {{ $departures->count() }}</div>@endif
      @forelse ($departures as $s)
        @php $departureHref = $s->status === 'checked_in' && $isToday && can_do('frontdesk.checkout') ? route('frontdesk.checkout', $s) : (can_do('reservations.view') ? route('reservations.show', $s->reservation_id) : null); @endphp
        @if ($departureHref)<a class="ws-person" href="{{ $departureHref }}">@else<div class="ws-person">@endif
          <span class="ws-avatar sm">{{ mb_substr($s->guest?->first_name ?? $s->reservation?->guest?->first_name ?? 'G', 0, 1) }}</span>
          <span><b>{{ $s->guest?->first_name ?? $s->reservation?->guest?->first_name }} {{ $s->guest?->last_name ?? $s->reservation?->guest?->last_name }}</b>
          <span class="muted small">Rm {{ $s->room?->room_no ?? '—' }} · {{ $s->reservation?->businessSource?->name ?? 'Direct' }}
            @php $df = $departureFigures[$s->id] ?? null; $da = $df['amount'] ?? null; @endphp
            @if ($da !== null && can_do('folio.view')) · <b class="{{ $da > 0 ? 'ws-due' : 'muted' }}">{{ $da > 0 ? money($da).' to settle' : 'settled' }}</b>@endif
            @if (! empty($df['blocked']))
              @if (can_do('folio.view'))<span class="ws-hold" title="Check-out will refuse until these are cleared">⚠ held: {{ implode(' · ', $df['blocked']) }}</span>
              @else<span class="ws-hold" title="Check-out will refuse until these are cleared">⚠ checkout blocked · {{ count($df['blocked']) }} item(s)</span>@endif
            @endif
          </span></span>
        @if ($departureHref)</a>@else</div>@endif
      @empty<div class="muted small">No departures on this date.</div>@endforelse
    </div></div>

    <div class="card ws-rail-card"><div class="card-body">
      <div class="ws-rail-title"><x-icon name="star"/> {{ $isFuture ? 'EXPECTED VIP GUESTS' : ($isHistory ? 'VIP STAYS' : 'VIP GUESTS IN-HOUSE') }} · {{ $vip->count() }}</div>
      @forelse ($vip as $s)
      @if (can_do('frontdesk.view'))<a class="ws-person" href="{{ route('frontdesk.inhouse') }}">@else<div class="ws-person">@endif
        <span class="ws-avatar sm vip">{{ mb_substr($s->guest?->first_name ?? $s->reservation?->guest?->first_name ?? 'G', 0, 1) }}</span>
        <span><b>{{ $s->guest?->first_name ?? $s->reservation?->guest?->first_name }} {{ $s->guest?->last_name ?? $s->reservation?->guest?->last_name }}</b>
          <span class="muted small">Rm {{ $s->room?->room_no ?? '—' }}</span></span>
      @if (can_do('frontdesk.view'))</a>@else</div>@endif
      @empty<div class="muted small">No VIP guests for this date.</div>@endforelse
    </div></div>

    <div class="card ws-rail-card"><div class="card-body">
      <div class="ws-rail-title"><x-icon name="clock"/> WAITING LIST · {{ $waitlist->count() }}</div>
      @forelse ($waitlist as $w)
      <div class="ws-person"><span class="ws-avatar sm">{{ mb_substr($w->guest?->first_name ?? $w->contact_name ?? 'G', 0, 1) }}</span>
        <span><b>{{ $w->guest?->first_name ?? $w->contact_name }}</b><span class="muted small">{{ \Illuminate\Support\Carbon::parse($w->arrival_date)->format('Y-m-d') }} · {{ $w->roomType?->name ?? 'Any' }}</span></span></div>
      @empty<div class="muted small">Waiting list is empty.</div>@endforelse
    </div></div>

    @if (can_do('housekeeping.view') || can_do('housekeeping.attendant') || can_do('housekeeping.manage'))
    @php $housekeepingUrl = can_do('housekeeping.view') ? route('housekeeping.index') : route('hk.my'); @endphp
    <div class="card ws-rail-card"><div class="card-body">
      <a class="ws-rail-title" href="{{ $housekeepingUrl }}" style="color:inherit"><x-icon name="broom"/> CURRENT HOUSEKEEPING</a>
      <table class="ws-rail-list">
        <tr><td><span class="dot" style="background:#f59e0b"></span> Dirty</td><td class="text-right bold">{{ $hk['Dirty'] }}</td></tr>
        <tr><td><span class="dot" style="background:#10b981"></span> Clean / inspected</td><td class="text-right bold">{{ $hk['Clean'] }}</td></tr>
        <tr><td><span class="dot" style="background:#3b82f6"></span> Touch-up</td><td class="text-right bold">{{ $hk['Touch-up'] }}</td></tr>
      </table>
    </div></div>
    @endif
  </div>
</div>

{{-- Room detail modals --}}
@foreach ($cards as $c)
@php
  $room = $c['room']; $stay = $c['stay'];
  $guestId = $stay?->guest_id ?? $stay?->reservation?->guest_id;
  $roomActions = [];
  if ($isToday && $stay && $stay->status === 'reserved' && $stay->room_id && $c['status'] === 'reserved' && can_do('frontdesk.checkin')) $roomActions[] = ['Check-In', 'login', route('frontdesk.checkin', $stay)];
  if ($isToday && $stay && $stay->status === 'checked_in' && can_do('frontdesk.roommove')) $roomActions[] = ['Move Room', 'swap', route('frontdesk.move', $stay)];
  if ($stay && can_do('reservations.view')) $roomActions[] = ['Reservation Details', 'calendar', route('reservations.show', $stay->reservation_id)];
  if ($stay && can_do('reservations.edit')) $roomActions[] = ['Edit Reservation', 'edit', route('reservations.edit', $stay->reservation_id)];
  if (can_do('folio.view'))
  {
    $roomActions[] = ['Folio Desk', 'wallet', route('folios.index', $stay ? ['stay' => $stay->id] : [])];
    $roomActions[] = ['Invoices', 'receipt', route('folios.invoices')];
  }
  if ($guestId && can_do('guests.view')) $roomActions[] = ['Guest Profile', 'user', route('guests.show', $guestId)];
  if (can_do('frontdesk.view')) $roomActions[] = ['Room Board', 'clock', route('frontdesk.rooms')];
  if ($stay && can_do('frontdesk.view')) $roomActions[] = ['Print Registration Card', 'print', route('frontdesk.grc', $stay)];
  if (can_do('desk.messages')) $roomActions[] = ['Guest Messages', 'message', route('desk.messages')];
  if (can_do('maintenance.manage')) $roomActions[] = ['Maintenance', 'tool', route('maintenance.index')];
  $facts = [
    ['Size', $room->roomType?->size_sqm ? rtrim(rtrim(number_format($room->roomType->size_sqm, 1), '0'), '.').' m²' : ($room->roomType?->code ?? '—')],
    ['Max Occupancy', ($room->roomType?->max_adults ?? 2).' guests'],
    ['Configuration', $room->roomType?->name ?? '—'],
    ['Smoking', $room->is_smoking ? 'Smoking' : 'Non-Smoking'],
  ];
@endphp
<div class="modal ws-modal" id="roomModal{{ $room->id }}" role="dialog" aria-modal="true" aria-labelledby="roomModalTitle{{ $room->id }}" tabindex="-1"><div class="modal-box wide">
  <div class="ws-modal-hero" style="background:{{ $room->roomType?->image ? 'url('.asset($room->roomType->image).') center/cover no-repeat, ' : '' }}linear-gradient(135deg, hsl({{ (($room->room_type_id ?? $room->id) * 47) % 360 }},32%,24%), hsl({{ (($room->room_type_id ?? $room->id) * 47 + 40) % 360 }},26%,38%))">
    <button type="button" class="btn btn-ghost btn-icon ws-modal-x" data-close aria-label="Close room details"><x-icon name="x"/></button>
    <div class="ws-modal-hero-row">
      <span class="ws-room-no huge">{{ $room->room_no }}</span>
      <div><div id="roomModalTitle{{ $room->id }}" class="bold">{{ $room->roomType?->name ?? 'Room details' }} · {{ $room->roomType?->code ?? '' }}</div>
        <div class="small" style="opacity:.85">{{ $room->floor?->name ? 'Floor '.$room->floor->name : '' }}{{ $room->roomType?->code ? ' · '.$room->roomType->name : '' }}</div></div>
      <div class="text-right"><span class="badge badge-{{ ['available' => 'green', 'reserved' => 'amber', 'occupied' => 'blue', 'out_of_order' => 'red', 'out_of_service' => 'red', 'leased' => 'slate', 'maintenance' => 'amber', 'house_use' => 'slate', 'group_hold' => 'blue', 'other_block' => 'slate'][$c['status']] }}">{{ strtoupper(str_replace('_', ' ', $c['status'])) }}</span>
        <div class="ws-photo-rate" style="position:static;margin-top:6px">{{ money($c['rate']) }} <small>/ night</small></div></div>
    </div>
  </div>
  <div class="modal-body">
    <div class="ws-modal-grid"><div><div class="ws-rail-title">ROOM FACTS</div><div class="ws-facts">
      @foreach ($facts as [$l, $v])<div class="ws-fact"><span class="muted small">{{ $l }}</span><br><b>{{ $v }}</b></div>@endforeach
    </div><div class="ws-rail-title" style="margin-top:14px">FACILITIES & AMENITIES</div><div class="ws-fac">
      @foreach (array_slice($c['amenities'], 0, 12) as $a)<span class="ws-fac-chip"><x-icon name="{{ WS::amenityIcon($a) }}"/> {{ $a }}</span>@endforeach
      @if ($room->is_smoking === false)<span class="ws-fac-chip"><x-icon name="shield"/> Non-Smoking</span>@endif
    </div></div>
    @if ($stay)<div><div class="ws-rail-title">{{ $isHistory ? 'STAY ON THIS DATE' : ($isFuture ? 'RESERVATION ON THIS DATE' : 'CURRENT RESERVATION') }}</div><div class="ws-resv">
      <div class="flex gap"><span class="ws-avatar">{{ mb_substr($stay->guest?->first_name ?? $stay->reservation?->guest?->first_name ?? 'G', 0, 1) }}</span>
        <div><div class="bold">{{ $stay->guest?->first_name ?? $stay->reservation?->guest?->first_name }} {{ $stay->guest?->last_name ?? $stay->reservation?->guest?->last_name }}</div>
          <div class="muted small">{{ $stay->reservation?->businessSource?->name ?? 'Direct' }} · {{ $stay->guest?->nationality ?? '' }}</div></div></div>
      <table class="ws-resv-t">
        <tr><td class="muted">Confirmation</td><td class="bold">{{ $stay->reservation?->reservation_no ?? '—' }}</td><td class="muted">Source</td><td class="bold">{{ $stay->reservation?->businessSource?->name ?? 'Direct' }}</td></tr>
        <tr><td class="muted">Stay</td><td class="bold">{{ \Illuminate\Support\Carbon::parse($stay->arrival_date)->format('d M') }} → {{ \Illuminate\Support\Carbon::parse($stay->departure_date)->format('d M') }} · {{ max(1, \Illuminate\Support\Carbon::parse($stay->arrival_date)->diffInDays(\Illuminate\Support\Carbon::parse($stay->departure_date))) }}n</td><td class="muted">Guests</td><td class="bold">{{ $stay->adults }} Adults · {{ $stay->children }} Children</td></tr>
        <tr><td class="muted">Rate</td><td class="bold">{{ money($c['rate']) }}/n</td>@if (can_do('folio.view'))<td class="muted">Balance</td><td class="bold {{ ($c['balance'] ?? 0) > 0 ? 'ws-due' : '' }}">{{ $c['balance'] === null ? 'No folio' : ($c['balance'] > 0 ? money($c['balance']) : ($c['balance'] < 0 ? money(abs($c['balance'])).' in credit' : 'Settled')) }}</td>@else<td colspan="2"></td>@endif</tr>
        @if (can_do('folio.view') && ($c['due'] ?? null) !== null && abs(($c['due'] ?? 0) - ($c['balance'] ?? 0)) > 0.005)<tr><td class="muted">Estimated checkout</td><td class="bold ws-due" colspan="3">{{ money($c['due']) }} <span class="muted small">(includes nights and fixed charges still to post)</span></td></tr>@endif
      </table>
    </div></div>@endif</div>
    @if (count($roomActions))<div class="ws-rail-title" style="margin-top:16px">QUICK LINKS</div><div class="ws-quick">
      @foreach ($roomActions as [$label, $icon, $url])<a class="ws-quick-tile" href="{{ $url }}"><x-icon name="{{ $icon }}"/><span>{{ $label }}</span></a>@endforeach
    </div>@else<div class="muted small" style="margin-top:12px">No room actions are available for your permissions.</div>@endif
  </div>
</div></div>
@endforeach
@endsection

@push('scripts')
<script>
(() => {
  const status = document.getElementById('fStatus');
  const type = document.getElementById('fType');
  const floor = document.getElementById('fFloor');
  const source = document.getElementById('fSource');
  const filters = [status, type, floor, source].filter(Boolean);

  function applyWsFilters() {
    const values = [status?.value || '', type?.value || '', floor?.value || '', source?.value || ''];
    const cards = [...document.querySelectorAll('#wsGrid .ws-card')];
    let visible = 0;
    cards.forEach(card => {
      const show = (!values[0] || card.dataset.status === values[0])
        && (!values[1] || card.dataset.type === values[1])
        && (!values[2] || card.dataset.floor === values[2])
        && (!values[3] || card.dataset.source === values[3]);
      card.hidden = !show;
      if (show) visible++;
    });
    const noResults = document.getElementById('wsNoResults');
    if (noResults) noResults.hidden = cards.length === 0 || visible > 0;
    const params = new URLSearchParams(window.location.search);
    ['status', 'type', 'floor', 'source'].forEach((key, i) => values[i] ? params.set(key, values[i]) : params.delete(key));
    const query = params.toString();
    history.replaceState(null, '', window.location.pathname + (query ? '?' + query : '') + window.location.hash);
    document.querySelectorAll('a[data-ws-nav]').forEach(link => {
      const target = new URL(link.href, window.location.href);
      const targetDate = target.searchParams.get('date');
      const targetParams = new URLSearchParams(window.location.search);
      if (targetDate) targetParams.set('date', targetDate);
      else targetParams.delete('date');
      target.search = targetParams.toString();
      link.href = target.pathname + target.search + target.hash;
    });
  }

  filters.forEach(select => select.addEventListener('change', applyWsFilters));
  document.getElementById('clearWsFilters')?.addEventListener('click', () => {
    filters.forEach(select => { select.value = ''; });
    applyWsFilters();
  });
  document.getElementById('refreshWsBoard')?.addEventListener('click', () => window.location.reload());
  document.getElementById('wsDateJump')?.addEventListener('change', event => {
    const input = event.currentTarget;
    if (!input.value || input.value < input.min || input.value > input.max) return;
    const target = new URL(window.location.href);
    target.searchParams.set('date', input.value);
    window.location.assign(target.pathname + target.search + target.hash);
  });

  let activeModal = null;
  let returnFocus = null;
  function closeRoomModal() {
    if (!activeModal) return;
    activeModal.classList.remove('open');
    activeModal = null;
    returnFocus?.focus();
  }
  function openRoomModal(id, trigger) {
    const modal = document.getElementById('roomModal' + id);
    if (!modal) return;
    closeRoomModal();
    returnFocus = trigger || document.activeElement;
    activeModal = modal;
    modal.classList.add('open');
    modal.querySelector('[data-close]')?.focus();
  }
  document.querySelectorAll('.ws-all, .ws-photo-link').forEach(trigger => {
    trigger.addEventListener('click', () => openRoomModal(trigger.dataset.room, trigger));
    if (trigger.classList.contains('ws-photo-link')) trigger.addEventListener('keydown', event => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        openRoomModal(trigger.dataset.room, trigger);
      }
    });
  });
  document.addEventListener('click', event => {
    if (event.target.closest('[data-close]')) closeRoomModal();
    else if (activeModal && event.target === activeModal) closeRoomModal();
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') closeRoomModal();
  });

  applyWsFilters();
})();
</script>
@endpush

@push('head')
<style>
.ws-head{display:flex;justify-content:space-between;align-items:flex-end;margin:2px 0 12px}
.ws-title{font-size:22px;margin:0}
.ws-date-nav{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.ws-date-nav .disabled{opacity:.4;pointer-events:none}
.ws-date-jump-label{font-size:11px;color:#64748b;margin-left:4px}
.ws-date-jump{width:132px;padding:4px 6px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;color:#334155;font-size:12px}
.ws-history-note{margin-top:5px;max-width:720px}
.ws-photo-link:focus-visible{outline:3px solid var(--c-primary);outline-offset:2px}
.ws-extend-form .sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
.ws-kpis{display:grid;grid-template-columns:repeat(9,1fr);gap:8px;margin-bottom:6px}
.ws-revenue-note{margin:0 0 12px 2px}
.ws-kpi{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:10px 12px}
.ws-kpi-delta{font-size:11px;font-weight:600}
.ws-kpi-delta.up{color:#16a34a}.ws-kpi-delta.down{color:#ef4444}
.ws-spark{width:100%;height:24px;display:block;margin:4px 0}
.ws-kpi-value{font-size:18px;font-weight:700}
.ws-kpi-label{font-size:10px;letter-spacing:.4px;text-transform:uppercase;color:#64748b}
.ws-filters{display:flex;gap:8px;align-items:center;margin-bottom:12px;flex-wrap:wrap}
.ws-select{max-width:220px}
.ws-body{display:grid;grid-template-columns:1fr 320px;gap:12px;align-items:start}
.ws-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(255px,1fr));gap:12px}
.ws-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden}
.ws-card-head{display:flex;justify-content:space-between;padding:10px 12px 6px}
.ws-room-no{font-size:22px;font-weight:800}
.ws-tag{display:inline-block;margin-top:4px;font-size:9px;font-weight:700;letter-spacing:.4px;background:#fef3c7;color:#b45309;padding:2px 6px;border-radius:5px}
.ws-photo{height:128px;position:relative;border-radius:8px;margin:0 10px}
.ws-photo-link{cursor:pointer}
.ws-person-row{display:flex;flex-direction:column;gap:4px}
.ws-assign{display:flex;gap:6px;align-items:center;padding-left:34px}
.ws-assign-select{flex:1;min-width:0;font-size:12px;padding:3px 6px;border:1px solid #cbd5e1;border-radius:6px;background:#fff}
.ws-assign-none{padding-left:34px}
.ws-kpi:hover{border-color:#94a3b8}
.ws-photo-tag{position:absolute;left:10px;top:8px;background:rgba(15,23,42,.72);color:#fff;font-size:9px;font-weight:700;letter-spacing:.6px;padding:2px 6px;border-radius:4px}
.ws-photo-rate{position:absolute;right:10px;bottom:8px;background:#f59e0b;color:#1f2937;font-weight:800;padding:3px 8px;border-radius:6px;font-size:13px}
.ws-photo-rate small{font-weight:500}
.ws-room-meta{display:flex;gap:10px;padding:8px 12px 2px}
.ws-amen{display:flex;flex-wrap:wrap;gap:6px;padding:6px 12px 10px;color:#94a3b8}
.ws-amen .i{width:15px;height:15px}
.ws-guest{border-top:1px solid #f1f5f9;padding:10px 12px;margin:0 0 0;border-left:3px solid transparent}
.ws-guest.arriving{border-left-color:#f59e0b;background:#fffbeb66}
.ws-guest.inhouse{border-left-color:#3b82f6;background:#eff6ff66}
.ws-guest.vacant{text-align:center;border-top-style:dashed}
.ws-avatar{width:34px;height:34px;flex:0 0 34px;border-radius:50%;background:var(--c-primary);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-weight:700}
.ws-avatar.sm{width:28px;height:28px;flex-basis:28px;font-size:12px}
.ws-avatar.vip{background:#b45309}
.ws-stay{display:flex;justify-content:space-between;align-items:center;padding:8px 0 2px;text-align:center}
.ws-nights{font-size:10px;background:#f1f5f9;border-radius:10px;padding:2px 8px;color:#64748b}
.ws-rate-row{display:flex;justify-content:space-between;align-items:center;gap:8px;padding:6px 0 2px}
.ws-due{color:#dc2626;font-weight:700}
.ws-hold{display:block;color:#f59e0b;font-weight:600}
.ws-vacant-rate{font-size:22px;font-weight:800;margin:4px 0 8px}
.ws-reserve{width:calc(100% - 8px);margin:0 4px 4px}
.ws-actions{display:flex;gap:6px;padding:8px 12px 12px;border-top:1px solid #f1f5f9;flex-wrap:wrap}
.ws-all{margin-left:auto}
.ws-extend-form{display:flex;gap:4px;align-items:center}
.ws-inline-action{margin:0}
.ws-extend-form input{max-width:140px;font-size:11px;padding:3px 5px;border:1px solid #cbd5e1;border-radius:6px}
.ws-empty{grid-column:1/-1}
.ws-rail{display:flex;flex-direction:column;gap:10px}
.ws-rail-card .card-body{padding:12px}
.ws-rail-title{font-size:10px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:#64748b;margin-bottom:8px;display:flex;gap:6px;align-items:center}
.ws-stack{display:flex;height:8px;border-radius:6px;overflow:hidden;margin:4px 0 10px}
.ws-rail-list{width:100%;font-size:12px}
.ws-rail-list td{padding:2px 0}
.dot{display:inline-block;width:7px;height:7px;border-radius:50%;margin-right:6px}
.ws-person{display:flex;gap:8px;align-items:center;padding:5px 0;text-decoration:none;color:inherit}
.ws-person:hover{background:#f8fafc}
.ws-cal-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:5px}
.ws-cal-head .disabled{opacity:.35;pointer-events:none}
.ws-cal{display:grid;grid-template-columns:repeat(7,1fr);gap:2px;font-size:11px}
.ws-cal-d{text-align:center;font-size:9px}
.ws-cal-day{text-align:center;padding:3px 0;border-radius:6px;position:relative;color:inherit;text-decoration:none;display:block}
.ws-cal-day.selected{outline:2px solid #64748b;outline-offset:-2px}
.ws-cal-day.today{background:var(--c-primary-600);color:#fff}
.ws-cal-day.disabled{color:#cbd5e1;cursor:not-allowed}
.ws-cal-dots{display:block;height:5px;line-height:5px}
.dot-a,.dot-d{display:inline-block;width:4px;height:4px;border-radius:50%;margin:0 1px}
.dot-a{background:#16a34a}.dot-d{background:#ef4444}
.ws-legend{margin-right:10px}
.ws-modal .modal-box{max-width:900px}
.ws-modal .modal-box.wide{width:min(920px,94vw)}
.ws-modal-hero{position:relative;padding:56px 22px 18px;color:#fff;border-radius:0}
.ws-modal-x{position:absolute;top:10px;right:10px;color:#fff}
.ws-modal-hero-row{display:flex;gap:16px;align-items:center;justify-content:space-between}
.ws-modal-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.ws-facts{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.ws-fact{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:8px 10px;font-size:13px}
.ws-fac{display:flex;flex-wrap:wrap;gap:6px}
.ws-fac-chip{display:inline-flex;gap:5px;align-items:center;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;padding:5px 9px;font-size:12px}
.ws-resv{border:1px solid #e2e8f0;border-radius:10px;padding:12px}
.ws-resv-t{width:100%;margin-top:10px;font-size:12px}
.ws-resv-t td{padding:3px 6px 3px 0}
.ws-quick{display:grid;grid-template-columns:repeat(auto-fill,minmax(112px,1fr));gap:8px}
.ws-quick-tile{display:flex;flex-direction:column;gap:6px;align-items:center;justify-content:center;padding:12px 6px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;color:#1e293b;text-decoration:none;font-size:11px}
.ws-quick-tile:hover{background:#f8fafc}
@media (max-width:1200px){.ws-kpis{grid-template-columns:repeat(5,1fr)}.ws-body{grid-template-columns:1fr}}
@media (max-width:680px){.ws-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.ws-modal-grid{grid-template-columns:1fr}.ws-modal-hero-row{align-items:flex-start;flex-wrap:wrap}.ws-extend-form{width:100%}.ws-extend-form input{flex:1;max-width:none}}
</style>
@endpush
