@extends('layouts.app')
@section('title', 'Reservation Workspace')

@section('actions')
@if (can_do('reservations.view'))
  <a class="btn btn-icon" href="{{ route('reservations.workspace.report', ['date' => $date->toDateString()]) }}" target="_blank" rel="noopener" aria-label="Open day report" title="Day report"><x-icon name="chart"/></a>
  <a class="btn btn-icon" href="{{ route('reservations.daysummary', ['date' => $date->toDateString()]) }}" aria-label="Open day summary" title="Day summary"><x-icon name="calendar"/></a>
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
  $filters = request()->only(['status', 'type', 'floor', 'source', 'hk', 'vip', 'q']);
  $selectedStatus = is_string($filters['status'] ?? null) ? $filters['status'] : '';
  $selectedType = is_string($filters['type'] ?? null) ? $filters['type'] : '';
  $selectedFloor = is_string($filters['floor'] ?? null) ? $filters['floor'] : '';
  $selectedSource = is_string($filters['source'] ?? null) ? $filters['source'] : '';
  $selectedHousekeeping = is_string($filters['hk'] ?? null) ? $filters['hk'] : '';
  $canViewHousekeeping = can_do('housekeeping.view') || can_do('housekeeping.attendant') || can_do('housekeeping.manage');
  $selectedVip = ($filters['vip'] ?? '') === '1';
  $searchQuery = is_string($filters['q'] ?? null) ? trim($filters['q']) : '';
  $roomStatusLabels = ['available' => 'Available', 'reserved' => 'Reserved', 'occupied' => 'Occupied', 'out_of_order' => 'Out of Order', 'out_of_service' => 'Out of Service', 'leased' => 'Leased', 'maintenance' => 'Maintenance', 'house_use' => 'House Use', 'group_hold' => 'Group Hold', 'other_block' => 'Other Block'];
  $roomStatusKeys = array_flip($roomStatusLabels);
  $checkInBlockReason = static function ($room, array $inventoryBlocks) use ($roomStatusLabels): string {
      if (! $room) return 'No room is assigned to this stay.';
      if (! $room->is_active) return 'The assigned room is inactive.';
      if ($room->status !== 'vacant') return 'Assigned room status: '.str_replace('_', ' ', ucfirst((string) $room->status)).'.';
      $blockStatus = $inventoryBlocks[$room->id] ?? null;
      if ($blockStatus) {
          $blockLabel = $roomStatusLabels[$blockStatus] ?? str_replace('_', ' ', ucfirst((string) $blockStatus));
          return 'Room has an active '.$blockLabel.' inventory block.';
      }
      return 'Room is not check-in ready; review its availability.';
  };
  $guestDisplayName = static function ($stay, string $fallback = 'Guest details unavailable'): string {
      $guest = $stay?->guest ?? $stay?->reservation?->guest;
      $name = trim(trim((string) ($guest?->first_name ?? '')).' '.trim((string) ($guest?->last_name ?? '')));
      return $name !== '' ? $name : $fallback;
  };
  $guestInitial = static function ($stay): string {
      $guest = $stay?->guest ?? $stay?->reservation?->guest;
      $first = trim((string) ($guest?->first_name ?? ''));
      $last = trim((string) ($guest?->last_name ?? ''));
      $name = $first !== '' ? $first : $last;
      return $name !== '' ? mb_substr($name, 0, 1) : 'G';
  };
  $housekeepingLabel = static function ($status): string {
      $labels = ['dirty' => 'Dirty', 'clean' => 'Clean', 'inspected' => 'Inspected', 'touch_up' => 'Touch-up'];
      $status = (string) $status;
      return $status === '' ? 'Unknown' : ($labels[$status] ?? str_replace('_', ' ', ucfirst($status)));
  };
  $stayDateFormat = static fn ($stay): string => $stay && $stay->arrival_date->year !== $stay->departure_date->year ? 'd M Y' : 'd M';
  $cards = $cards->sort(fn ($a, $b) => strnatcasecmp((string) $a['room']->room_no, (string) $b['room']->room_no))->values();
  $sourceOptions = collect(['Direct'])->merge($cards->pluck('stay.reservation.businessSource.name')->filter())->unique()->sort()->values();
@endphp
<div class="ws-head">
  <div>
    <h1 class="ws-title">Reservation Workspace</h1>
    <div class="muted ws-date-nav">
      @if ($date->gt($dateMin))
        <a class="btn btn-sm" data-ws-nav href="{{ route('reservations.workspace', array_merge(request()->except('date'), ['date' => $date->copy()->subDay()->toDateString()])) }}" aria-label="Previous day" title="Previous day">←</a>
      @else
        <button type="button" class="btn btn-sm disabled" disabled aria-label="Previous day unavailable: date range limit" title="Date range limit">←</button>
      @endif
      <b style="margin:0 8px">{{ $date->format('l, j F Y') }}</b>
      @if ($date->lt($dateMax))
        <a class="btn btn-sm" data-ws-nav href="{{ route('reservations.workspace', array_merge(request()->except('date'), ['date' => $date->copy()->addDay()->toDateString()])) }}" aria-label="Next day" title="Next day">→</a>
      @else
        <button type="button" class="btn btn-sm disabled" disabled aria-label="Next day unavailable: date range limit" title="Date range limit">→</button>
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
    @if ($isHistory)<div class="muted small ws-history-note">Past stays are inferred from current reservation records; dated outages and leases are honored, but room-status and housekeeping history is unavailable. VIP flags reflect current guest profiles.</div>
    @elseif ($isFuture)<div class="muted small ws-history-note">Forward reservations, active non-occupancy room flags and dated blocks apply; housekeeping is current and availability may change. VIP flags reflect current guest profiles.</div>@endif
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
  $revenueExplanation = $isToday
      ? 'Current room-rate total includes checked-in stays covering the hotel business date; arrivals not yet checked in are excluded. Excludes taxes, fees, and folio postings.'
      : ($isFuture
          ? 'Booked room-rate value includes reserved and checked-in stays covering the selected date. Excludes taxes, fees, and folio postings.'
          : 'Historical room-rate value includes checked-in and checked-out stays covering the selected date. Excludes taxes, fees, and folio postings.');
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
      $tone = ! in_array($key, ['occupancy', 'revenue'], true) || $delta === null || $delta == 0
          ? 'neutral'
          : ($delta > 0 ? 'green' : 'red');
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
<div class="ws-kpis" style="--ws-kpi-count:{{ count($kpis) }}">
  @foreach ($kpis as $k)
  @php $canOpenKpi = $isToday && $k['key'] !== 'revenue' && can_do($k['permission']); @endphp
  @if ($canOpenKpi)<a class="ws-kpi" href="{{ $k['link'] }}" style="color:inherit;text-decoration:none" title="{{ $k['key'] === 'revenue' ? $revenueExplanation : 'Change compared with the previous selected day' }}">
  @else<div class="ws-kpi" title="{{ $k['key'] === 'revenue' ? $revenueExplanation : 'Change compared with the previous selected day' }}">
  @endif
    <div class="flex gap"><span class="ws-kpi-delta {{ $k['tone'] === 'neutral' ? 'neutral' : ($k['tone'] === 'red' ? 'down' : 'up') }}" aria-label="Change from previous selected day: {{ $k['delta'] === '—' ? 'unavailable' : $k['delta'] }}">{{ $k['delta'] }}</span></div>
    {!! WS::spark($k['series'], $k['tone']) !!}
    <div class="ws-kpi-value">{{ $k['value'] }}</div>
    <div class="ws-kpi-label">{{ $k['label'] }}</div>
  @if ($canOpenKpi)</a>@else</div>@endif
  @endforeach
</div>
@if (can_do('revenue.view'))<div class="ws-revenue-note muted small">{{ $revenueExplanation }}</div>@endif

{{-- ── Filters ── --}}
<div class="ws-filters" role="group" aria-label="Room board search and filters">
  <span class="muted small">FILTERS</span>
  <input id="fSearch" class="input ws-select ws-search" type="search" value="{{ $searchQuery }}" placeholder="Room, type, floor, guest or reference" autocomplete="off" aria-label="Search this room board by room, room type, floor, amenity, guest, phone, email, company, group or reservation">
  <select id="fStatus" class="ws-select" aria-label="Filter rooms by status"><option value="">Room Status: All</option>
    @foreach ($roomStatusLabels as $k => $l)<option value="{{ $k }}" @selected($selectedStatus === $k)>Room Status: {{ $l }}</option>@endforeach
    @if ($selectedStatus !== '' && ! array_key_exists($selectedStatus, $roomStatusLabels))<option value="{{ $selectedStatus }}" selected>Room Status: {{ str_replace('_', ' ', ucfirst($selectedStatus)) }} (not a current status)</option>@endif
  </select>
  <select id="fType" class="ws-select" aria-label="Filter rooms by room type"><option value="">Room Type: All</option>
    @foreach ($roomTypes as $rt)<option value="{{ $rt->id }}" @selected($selectedType === (string) $rt->id)>Room Type: {{ $rt->name }}</option>@endforeach
    @if ($selectedType !== '' && ! $roomTypes->contains(fn ($rt) => (string) $rt->id === $selectedType))<option value="{{ $selectedType }}" selected>Room Type: {{ $selectedType }} (not on this date)</option>@endif
  </select>
  <select id="fFloor" class="ws-select" aria-label="Filter rooms by floor"><option value="">Floor: All</option>
    @foreach ($floors as $f)<option value="{{ $f->id }}" @selected($selectedFloor === (string) $f->id)>Floor: {{ $f->name }}</option>@endforeach
    @if ($selectedFloor !== '' && ! $floors->contains(fn ($f) => (string) $f->id === $selectedFloor))<option value="{{ $selectedFloor }}" selected>Floor: {{ $selectedFloor }} (not on this date)</option>@endif
  </select>
  <select id="fSource" class="ws-select" aria-label="Filter rooms by booking source"><option value="">Source: All</option>
    @foreach ($sourceOptions as $src)<option value="{{ $src }}" @selected($selectedSource === (string) $src)>Source: {{ $src }}</option>@endforeach
    @if ($selectedSource !== '' && ! $sourceOptions->contains(fn ($src) => (string) $src === $selectedSource))<option value="{{ $selectedSource }}" selected>Source: {{ $selectedSource }} (not on this date)</option>@endif
  </select>
  @if ($canViewHousekeeping)
  <select id="fHousekeeping" class="ws-select" aria-label="Filter rooms by current housekeeping status"><option value="">Current housekeeping: All</option>
    @foreach (['dirty' => 'Dirty', 'clean' => 'Clean', 'inspected' => 'Inspected', 'ready' => 'Clean / inspected', 'touch_up' => 'Touch-up'] as $hkKey => $hkLabel)<option value="{{ $hkKey }}" @selected($selectedHousekeeping === $hkKey)>Current housekeeping: {{ $hkLabel }}</option>@endforeach
    @if ($selectedHousekeeping !== '' && ! in_array($selectedHousekeeping, ['dirty', 'clean', 'inspected', 'ready', 'touch_up'], true))<option value="{{ $selectedHousekeeping }}" selected>Current housekeeping: {{ $selectedHousekeeping }} (not a current status)</option>@endif
  </select>
  @endif
  <label class="ws-vip-filter"><input id="fVip" type="checkbox" value="1" @checked($selectedVip) aria-label="Filter to guests currently marked VIP" aria-controls="wsGrid"> Current VIP only</label>
  <button type="button" class="btn btn-sm btn-ghost" id="clearWsFilters">Clear filters</button>
  <span id="wsVisibleCount" class="muted small ws-filter-count" aria-hidden="true"></span>
</div>
<div id="wsFilterStatus" class="sr-only" role="status" aria-live="polite" aria-atomic="true"></div>

<div class="ws-body">
  {{-- ── Room board ── --}}
  <div class="ws-grid" id="wsGrid" role="region" aria-label="Room board for {{ $date->format('j F Y') }}">
    @forelse ($cards as $c)
      @php
        $room = $c['room']; $stay = $c['stay']; $src = $stay?->reservation?->businessSource?->name ?? '';
        $roomSource = $stay ? ($src ?: 'Direct') : '';
        $isVip = (bool) ($stay?->guest?->is_vip || $stay?->reservation?->guest?->is_vip);
        $hkTone = match ($room->hk_status) { 'dirty' => 'dirty', 'clean' => 'clean', 'inspected' => 'inspected', 'touch_up' => 'touchup', default => 'unknown' };
        $stayGuestName = $guestDisplayName($stay);
        $stayGuestInitial = $guestInitial($stay);
        $searchText = implode(' ', array_map('strval', array_filter([
          $room->room_no, $room->roomType?->name, $room->roomType?->code, $room->floor?->name, $stay?->reservation?->reservation_no,
          $stay?->reservation?->group_name, $stay?->reservation?->ota_reference, $stay?->reservation?->company?->name, $roomSource,
          $stay?->guest?->first_name, $stay?->guest?->last_name, $stay?->guest?->phone, $stay?->guest?->email,
          $stay?->reservation?->guest?->first_name, $stay?->reservation?->guest?->last_name,
          $stay?->reservation?->guest?->phone, $stay?->reservation?->guest?->email,
          implode(' ', $c['amenities']),
        ], fn ($value) => is_scalar($value) && trim((string) $value) !== '')));
      @endphp
      <div class="ws-card" data-status="{{ $c['status'] }}" data-type="{{ $room->room_type_id }}" data-floor="{{ $room->floor_id }}" data-source="{{ $roomSource }}" data-hk="{{ $canViewHousekeeping ? $room->hk_status : '' }}" data-vip="{{ $isVip ? '1' : '0' }}" data-search="{{ $searchText }}">
        <div class="ws-card-head">
          <div class="flex gap">
            <h2 class="ws-room-no" id="roomHeading{{ $room->id }}"><span class="sr-only">Room </span>{{ $room->room_no }}</h2>
            <div>
              <div class="bold small">{{ $room->roomType?->name ?? '—' }}</div>
              <div class="muted small">{{ $room->floor?->name ? 'F'.$room->floor->name : '' }}{{ $room->roomType?->code ? ' · '.$room->roomType->code : '' }}</div>
            </div>
          </div>
          <div class="text-right">
            <span class="badge badge-{{ ['available' => 'green', 'reserved' => 'amber', 'occupied' => 'blue', 'out_of_order' => 'red', 'out_of_service' => 'red', 'leased' => 'slate', 'maintenance' => 'amber', 'house_use' => 'slate', 'group_hold' => 'blue', 'other_block' => 'slate'][$c['status']] }}">{{ strtoupper(str_replace('_', ' ', $c['status'])) }}</span>
            @if ($canViewHousekeeping)<div class="ws-hk-badge ws-hk-{{ $hkTone }}" title="Current housekeeping status">Current <span aria-hidden="true">HK</span><span class="sr-only">housekeeping</span>: {{ $housekeepingLabel($room->hk_status) }}</div>@endif
            @if ($c['arriving_on_date'])<div class="ws-tag">ARRIVING ON THIS DATE</div>@endif
          </div>
        </div>

        <div class="ws-photo ws-photo-link" data-room="{{ $room->id }}" role="button" tabindex="0" aria-label="Open room {{ $room->room_no }} details" aria-haspopup="dialog" aria-controls="roomModal{{ $room->id }}" aria-expanded="false" title="Room details" style="background:{{ $room->roomType?->image ? 'url('.asset($room->roomType->image).') center/cover no-repeat, ' : '' }}linear-gradient(135deg, hsl({{ ($room->room_type_id * 47) % 360 }},32%,28%), hsl({{ ($room->room_type_id * 47 + 40) % 360 }},28%,42%))">
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
            <span role="img" aria-label="{{ $a }}" title="{{ $a }}"><x-icon name="{{ WS::amenityIcon($a) }}"/></span>
          @endforeach
          @if (count($c['amenities']) > 10)
            <button type="button" class="ws-amen-more" data-room="{{ $room->id }}" aria-label="Open room {{ $room->room_no }} details; {{ count($c['amenities']) - 10 }} more amenities" aria-haspopup="dialog" aria-controls="roomModal{{ $room->id }}" aria-expanded="false">+{{ count($c['amenities']) - 10 }}</button>
          @endif
        </div>

        @if ($stay)
        <div class="ws-guest {{ $c['status'] === 'reserved' ? 'arriving' : ($c['status'] === 'occupied' ? 'inhouse' : '') }}">
          <div class="flex gap">
            <span class="ws-avatar">{{ $stayGuestInitial }}</span>
            <div>
              <div class="bold small">{{ $stayGuestName }}</div>
              <div class="muted small">{{ $roomSource }}{{ $isVip ? ' · VIP' : '' }}{{ $stay->guest?->nationality ? ' · '.$stay->guest->nationality : '' }}</div>
            </div>
          </div>
          <div class="ws-stay small">
            <span><b>{{ \Illuminate\Support\Carbon::parse($stay->arrival_date)->format($stayDateFormat($stay)) }}</b><br><span class="muted">Arrive</span></span>
            <span class="ws-nights">{{ max(1, \Illuminate\Support\Carbon::parse($stay->arrival_date)->diffInDays(\Illuminate\Support\Carbon::parse($stay->departure_date))) }}n</span>
            <span><b>{{ \Illuminate\Support\Carbon::parse($stay->departure_date)->format($stayDateFormat($stay)) }}</b><br><span class="muted">Depart</span></span>
          </div>
          <div class="ws-rate-row small">
            <div><span class="muted">Nightly rate</span><br><span class="bold">{{ money($c['rate']) }}</span> <span class="muted">/n</span></div>
            @if (can_do('folio.view'))
              @php $cardDue = $c['due'] ?? $c['balance']; @endphp
              @if ($cardDue !== null)
              <div class="text-right {{ $cardDue > 0 ? 'ws-due' : 'muted' }}" title="{{ $stay->status === 'checked_out' ? ($cardDue < 0 ? 'Final folio credit' : 'Final folio balance') : ($cardDue < 0 ? 'Estimated checkout credit, including nights and fixed charges still to post' : 'Estimated checkout amount, including nights and fixed charges still to post') }}">{{ $cardDue > 0 ? money($cardDue).' due' : ($cardDue < 0 ? money(abs($cardDue)).' credit' : 'Settled') }}</div>
              @endif
            @endif
          </div>
        </div>
        @else
        <div class="ws-guest vacant">
          <div class="muted small">{{ $c['status'] === 'occupied' ? 'OCCUPIED · NO LINKED STAY' : ($c['status'] === 'available' ? 'VACANT · RACK RATE' : 'ROOM UNAVAILABLE') }}</div>
          <div class="ws-vacant-rate">{{ money($c['rate']) }} <small class="muted">/night</small></div>
          @if ($c['status'] === 'available' && ! $isHistory && can_do('reservations.create'))
            <a class="btn btn-sm btn-primary ws-reserve" href="{{ route('reservations.create', ['room' => $room->id, 'arrival' => $date->toDateString(), 'departure' => $date->copy()->addDay()->toDateString()]) }}" aria-label="Reserve room {{ $room->room_no }} for {{ $date->format('j F Y') }}">Reserve Room</a>
          @endif
        </div>
        @endif

        <div class="ws-actions">
          @if ($stay && $stay->status === 'reserved')
            @if ($isToday && $c['status'] === 'reserved' && $room->status === 'vacant' && ! isset($inventoryBlocks[$room->id]) && can_do('frontdesk.checkin'))<a class="btn btn-sm btn-primary" style="background:#16a34a" href="{{ route('frontdesk.checkin', $stay) }}" aria-label="Check in room {{ $room->room_no }}"><x-icon name="login"/> Check-In</a>
            @elseif ($isToday && ($c['status'] !== 'reserved' || $room->status !== 'vacant' || isset($inventoryBlocks[$room->id])) && can_do('frontdesk.checkin'))<span class="ws-checkin-blocker">Check-in blocked: {{ $checkInBlockReason($room, $inventoryBlocks) }}</span>@endif
            @if (can_do('reservations.view'))<a class="btn btn-sm" href="{{ route('reservations.show', $stay->reservation_id) }}" aria-label="Reservation details for room {{ $room->room_no }}{{ $stay->reservation?->reservation_no ? ', '.$stay->reservation->reservation_no : '' }}">Reservation details</a>@endif
          @elseif ($stay && $stay->status === 'checked_in')
            @if ($isToday && can_do('frontdesk.checkout'))<a class="btn btn-sm" style="background:#ea580c;color:#fff" href="{{ route('frontdesk.checkout', $stay) }}" aria-label="Check out room {{ $room->room_no }}"><x-icon name="logout"/> Check-Out</a>@endif
            @if ($isToday && can_do('frontdesk.roommove'))
              @php $extendTo = $stay->departure_date->copy()->addDay()->max($businessDate->copy()->addDay()); @endphp
              <form method="POST" action="{{ route('frontdesk.amend', $stay) }}" class="ws-extend-form" data-room-no="{{ $room->room_no }}" data-confirm="Extend the stay in room {{ $room->room_no }} through the selected departure date? Availability and stay charges will be rechecked.">
                @csrf
                <label class="sr-only" for="extend-{{ $stay->id }}">New departure date for room {{ $room->room_no }}</label>
                <input id="extend-{{ $stay->id }}" type="date" name="departure_date" min="{{ $extendTo->toDateString() }}" value="{{ $extendTo->toDateString() }}" required>
                <button type="submit" class="btn btn-sm" title="Extend this stay" aria-label="Extend stay in room {{ $room->room_no }}">Extend</button>
              </form>
            @endif
          @endif
          @if (! $isHistory && in_array($c['status'], ['available', 'reserved'], true) && can_do('housekeeping.manage') && $room->status === 'vacant' && $room->hk_status !== 'dirty')
            <form method="POST" action="{{ route('housekeeping.status') }}" class="ws-inline-action" data-confirm="Mark room {{ $room->room_no }} dirty? This updates its current housekeeping status.">
              @csrf
              <input type="hidden" name="rooms[]" value="{{ $room->id }}">
              <input type="hidden" name="hk_status" value="dirty">
              <button type="submit" class="btn btn-sm btn-ghost" aria-label="Mark room {{ $room->room_no }} dirty" title="Mark current housekeeping status dirty"><x-icon name="broom"/> Mark Dirty</button>
            </form>
          @endif
          <button type="button" class="btn btn-sm btn-ghost ws-all" data-room="{{ $room->id }}" aria-label="More actions for room {{ $room->room_no }}" aria-haspopup="dialog" aria-controls="roomModal{{ $room->id }}" aria-expanded="false">··· All actions</button>
        </div>
      </div>
    @empty
      <div class="card ws-empty"><div class="card-body"><b>No active rooms</b><div class="muted small">There are no active rooms to show for this property.</div></div></div>
    @endforelse
    <div class="card ws-empty" id="wsNoResults" hidden><div class="card-body"><b>No rooms match these filters</b><div class="muted small">Clear the search or filters to see the full room board.</div><button type="button" class="btn btn-sm btn-ghost" id="clearWsFiltersEmpty">Clear search and filters</button></div></div>
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
        @if ($prevMonthDate->gte($dateMin))<a class="btn btn-sm btn-ghost" data-ws-nav href="{{ route('reservations.workspace', array_merge(request()->except('date'), ['date' => $prevMonthDate->toDateString()])) }}" aria-label="Previous month" title="Previous month">‹</a>@else<button type="button" class="btn btn-sm btn-ghost disabled" disabled aria-label="Previous month unavailable: date range limit" title="Date range limit">‹</button>@endif
        <div class="bold small" style="text-transform:uppercase">{{ $m->format('F Y') }}</div>
        @if ($nextMonthDate->lte($dateMax))<a class="btn btn-sm btn-ghost" data-ws-nav href="{{ route('reservations.workspace', array_merge(request()->except('date'), ['date' => $nextMonthDate->toDateString()])) }}" aria-label="Next month" title="Next month">›</a>@else<button type="button" class="btn btn-sm btn-ghost disabled" disabled aria-label="Next month unavailable: date range limit" title="Date range limit">›</button>@endif
      </div>
      <div class="ws-cal">
        @foreach ([['M', 'Monday'], ['T', 'Tuesday'], ['W', 'Wednesday'], ['T', 'Thursday'], ['F', 'Friday'], ['S', 'Saturday'], ['S', 'Sunday']] as [$initial, $weekday])<span class="ws-cal-d muted"><span aria-hidden="true">{{ $initial }}</span><span class="sr-only">{{ $weekday }}</span></span>@endforeach
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
            <a class="ws-cal-day {{ $selectedDay ? 'selected' : '' }} {{ $businessDay ? 'today' : '' }}" data-ws-nav href="{{ route('reservations.workspace', array_merge(request()->except('date'), ['date' => $ds])) }}" aria-label="{{ \Illuminate\Support\Carbon::parse($ds)->format('l, j F Y') }}{{ $businessDay ? ', business date' : '' }}{{ $hasA ? ', arrivals' : '' }}{{ $hasD ? ', departures' : '' }}" @if ($selectedDay) aria-current="page" @endif>
          @else
            <span class="ws-cal-day disabled" aria-disabled="true" title="Outside the selectable date range">
          @endif
            @if ($inRange){{ $day }}@else
              <span aria-hidden="true">{{ $day }}</span><span class="sr-only">{{ \Illuminate\Support\Carbon::parse($ds)->format('l, j F Y') }}, outside selectable date range{{ $hasA ? ', arrivals' : '' }}{{ $hasD ? ', departures' : '' }}</span>
            @endif
            <span class="ws-cal-dots" aria-hidden="true">@if($hasA)<i class="dot-a"></i>@endif @if($hasD)<i class="dot-d"></i>@endif</span>
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
        <caption class="sr-only">Room status summary by room count</caption>
        <thead class="sr-only"><tr><th scope="col">Room status</th><th scope="col">Room count</th></tr></thead>
        <tbody>
        @foreach ($statusSummary as $label => [$n, $color])
        @php $statusFilterKey = $roomStatusKeys[$label] ?? null; @endphp
        <tr><td><span class="dot" style="background:{{ $color }}"></span>
          @if ($statusFilterKey)<button type="button" class="ws-status-filter" data-ws-status-filter="{{ $statusFilterKey }}" aria-label="Filter room board to {{ strtolower($label) }} rooms ({{ $n }})" aria-pressed="{{ $selectedStatus === $statusFilterKey ? 'true' : 'false' }}">{{ $label }}</button>@else{{ $label }}@endif
        </td><td class="text-right bold">{{ $n }}</td></tr>
        @endforeach
        </tbody>
      </table>
    </div></div>

    <div class="card ws-rail-card"><div class="card-body">
      <div class="ws-rail-title"><x-icon name="login"/> ARRIVALS · {{ $arrivals->count() }}
        @if (can_do('frontdesk.view'))<a class="ws-rail-open" href="{{ route('frontdesk.arrivals', ['date' => $date->toDateString()]) }}" aria-label="Open pending arrivals for {{ $date->format('j F Y') }}">Open pending</a>@endif
      </div>
      @forelse ($arrivals as $s)
      @php
        $canAssign = can_do('reservations.edit') && ! $s->room_id && $s->status === 'reserved' && $date->gte($businessDate);
        $canCheckIn = $isToday && $s->status === 'reserved' && $s->room?->is_active && $s->room?->status === 'vacant' && ! isset($inventoryBlocks[$s->room_id]) && can_do('frontdesk.checkin');
        $freeRooms = $canAssign ? ($assignableByStay[$s->id] ?? collect()) : collect();
        $freeRooms = $freeRooms->sort(function ($a, $b) use ($canViewHousekeeping) {
            $aPriority = ! $canViewHousekeeping ? 1 : match ($a->hk_status) { 'inspected' => 0, 'clean' => 1, default => 2 };
            $bPriority = ! $canViewHousekeeping ? 1 : match ($b->hk_status) { 'inspected' => 0, 'clean' => 1, default => 2 };
            return ($aPriority <=> $bPriority) ?: strnatcasecmp((string) $a->room_no, (string) $b->room_no);
        })->values();
        $assignGuestName = $guestDisplayName($s, 'guest');
        $assignReservationNo = $s->reservation?->reservation_no;
        $freeSameType = $freeRooms->where('room_type_id', $s->room_type_id);
        $freeOther = $freeRooms->where('room_type_id', '!=', $s->room_type_id);
      @endphp
      <div class="ws-person-row">
        @if ($canCheckIn)<a class="ws-person" href="{{ route('frontdesk.checkin', $s) }}">
        @elseif (can_do('reservations.view'))<a class="ws-person" href="{{ route('reservations.show', $s->reservation_id) }}">
        @else<div class="ws-person">
        @endif
          <span class="ws-avatar sm">{{ $guestInitial($s) }}</span>
          <span><b>{{ $guestDisplayName($s) }}</b>
            <span class="muted small">{{ $s->room?->room_no ? 'Rm '.$s->room->room_no : 'Unassigned' }} · {{ max(1, \Illuminate\Support\Carbon::parse($s->arrival_date)->diffInDays(\Illuminate\Support\Carbon::parse($s->departure_date))) }}n · {{ $s->reservation?->businessSource?->name ?? 'Direct' }}@if ($canViewHousekeeping && $s->room?->hk_status && ! in_array($s->room->hk_status, ['clean', 'inspected'], true)) · Current housekeeping: {{ $housekeepingLabel($s->room->hk_status) }}@endif</span></span>
        @if ($canCheckIn || can_do('reservations.view'))</a>@else</div>@endif
        @if ($isToday && $s->status === 'reserved' && $s->room_id && can_do('frontdesk.checkin') && ! $canCheckIn)
          <div class="ws-checkin-blocker ws-assign-none">Check-in blocked: {{ $checkInBlockReason($s->room, $inventoryBlocks) }} Resolve the issue or have an authorized user reassign the stay.</div>
        @endif
        @if ($canAssign)
          @if ($freeSameType->isNotEmpty() || $freeOther->isNotEmpty())
          <form method="POST" action="{{ route('reservations.assign', $s->reservation_id) }}" class="ws-assign" data-guest-name="{{ $assignGuestName }}" data-confirm="Assign the selected room to {{ $assignGuestName }}? Availability will be rechecked before saving.">
            @csrf
            <select name="assign[{{ $s->id }}]" class="ws-assign-select" aria-label="Room for {{ $assignGuestName ?: 'guest' }}{{ $assignReservationNo ? ', reservation '.$assignReservationNo : '' }}" required>
              <option value="" disabled selected>Choose a room…</option>
              @if ($freeSameType->isNotEmpty())<optgroup label="Same room type">
                @foreach ($freeSameType as $r)
                <option value="{{ $r->id }}">{{ $r->room_no }} · {{ $r->roomType?->name }}{{ $canViewHousekeeping ? ' · Current: '.$housekeepingLabel($r->hk_status) : '' }}</option>
                @endforeach
              </optgroup>@endif
              @if ($freeOther->isNotEmpty())<optgroup label="{{ $freeSameType->isNotEmpty() ? 'Other room types' : 'Available room types' }}">
                @foreach ($freeOther as $r)
                <option value="{{ $r->id }}">{{ $r->room_no }} · {{ $r->roomType?->name }}{{ $canViewHousekeeping ? ' · Current: '.$housekeepingLabel($r->hk_status) : '' }}</option>
                @endforeach
              </optgroup>@endif
            </select>
            <button type="submit" class="btn btn-sm" aria-label="Assign room to {{ $assignGuestName ?: 'guest' }}{{ $assignReservationNo ? ', reservation '.$assignReservationNo : '' }}">Assign</button>
          </form>
          @else
          <div class="muted small ws-assign-none">No room is free for these stay dates. <a href="{{ route('reservations.index', ['view' => 'waitlist']) }}" aria-label="Review waitlist while assigning a room to {{ $assignGuestName }}">Review waitlist</a> or review the dates and room availability.</div>
          @endif
        @endif
      </div>
      @empty<div class="muted small">No arrivals on this date.</div>@endforelse
    </div></div>

    <div class="card ws-rail-card"><div class="card-body">
      <div class="ws-rail-title"><x-icon name="logout"/> DEPARTURES · {{ $departures->count() }}
        @if (can_do('frontdesk.view'))<a class="ws-rail-open" href="{{ route('frontdesk.departures', ['date' => $date->toDateString()]) }}" aria-label="Open departures and overdue checkout queue through {{ $date->format('j F Y') }}">Open checkout queue</a>@endif
      </div>
      @forelse ($departures as $s)
        @php $departureHref = $s->status === 'checked_in' && $isToday && can_do('frontdesk.checkout') ? route('frontdesk.checkout', $s) : (can_do('reservations.view') ? route('reservations.show', $s->reservation_id) : null); @endphp
        @if ($departureHref)<a class="ws-person" href="{{ $departureHref }}">@else<div class="ws-person">@endif
          <span class="ws-avatar sm">{{ $guestInitial($s) }}</span>
          <span><b>{{ $guestDisplayName($s) }}</b>
          <span class="muted small">{{ $s->room?->room_no ? 'Rm '.$s->room->room_no : 'Unassigned' }} · {{ $s->reservation?->businessSource?->name ?? 'Direct' }}
            @php $df = $departureFigures[$s->id] ?? null; $da = $df['amount'] ?? null; @endphp
            @if ($da !== null && can_do('folio.view')) · <b class="{{ $da > 0 ? 'ws-due' : 'muted' }}" title="{{ $s->status === 'checked_out' ? ($da < 0 ? 'Final folio credit' : 'Final folio balance') : ($da < 0 ? 'Estimated checkout credit, including nights and fixed charges still to post' : 'Estimated checkout amount, including nights and fixed charges still to post') }}">{{ $da > 0 ? money($da).' to settle' : ($da < 0 ? money(abs($da)).' credit' : 'settled') }}</b>@endif
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
      @if (can_do('reservations.view') && $s->reservation_id)<a class="ws-person" href="{{ route('reservations.show', $s->reservation_id) }}">
      @elseif (can_do('frontdesk.view'))<a class="ws-person" href="{{ route('frontdesk.inhouse') }}">
      @else<div class="ws-person">
      @endif
        <span class="ws-avatar sm vip">{{ $guestInitial($s) }}</span>
        <span><b>{{ $guestDisplayName($s) }}</b>
          <span class="muted small">{{ $s->room?->room_no ? 'Rm '.$s->room->room_no : 'Unassigned' }}</span></span>
      @if ((can_do('reservations.view') && $s->reservation_id) || can_do('frontdesk.view'))</a>@else</div>@endif
      @empty<div class="muted small">No VIP guests for this date.</div>@endforelse
    </div></div>

    <div class="card ws-rail-card"><div class="card-body">
      <div class="ws-rail-title"><x-icon name="clock"/> WAITING LIST · {{ $waitlistCount }}
        @if (can_do('reservations.view'))<a class="ws-waitlist-all" href="{{ route('reservations.index', ['view' => 'waitlist']) }}" aria-label="Open full waitlist: {{ $waitlistCount }} waiting entries">View all</a>@endif
      </div>
      @forelse ($waitlist as $w)
      @php
        $waitlistGuestName = trim(trim((string) ($w->guest?->first_name ?? '')).' '.trim((string) ($w->guest?->last_name ?? '')));
        if ($waitlistGuestName === '') $waitlistGuestName = trim((string) ($w->contact_name ?? ''));
        if ($waitlistGuestName === '') $waitlistGuestName = 'Guest details unavailable';
        $waitlistInitialSource = trim((string) ($w->guest?->first_name ?? '')) ?: $waitlistGuestName;
      @endphp
      <div class="ws-person"><span class="ws-avatar sm">{{ mb_substr($waitlistInitialSource, 0, 1) }}</span>
        <span><b>{{ $waitlistGuestName }}</b><span class="muted small">{{ $w->arrival_date->format('d M Y') }} → {{ $w->departure_date->format('d M Y') }} · {{ max(1, $w->arrival_date->diffInDays($w->departure_date)) }}n · {{ $w->roomType?->name ?? 'Any' }}</span></span></div>
      @empty<div class="muted small">Waiting list is empty.</div>@endforelse
      @if ($waitlistCount > $waitlist->count())<div class="muted small">Showing the top {{ $waitlist->count() }} of {{ $waitlistCount }} entries by priority.</div>@endif
    </div></div>

    @if ($canViewHousekeeping)
    @php $housekeepingUrl = can_do('housekeeping.view') ? route('housekeeping.index') : route('hk.my'); @endphp
    <div class="card ws-rail-card"><div class="card-body">
      <a class="ws-rail-title" href="{{ $housekeepingUrl }}" style="color:inherit"><x-icon name="broom"/> CURRENT HOUSEKEEPING</a>
      <table class="ws-rail-list">
        <caption class="sr-only">Current housekeeping status by room count</caption>
        <thead class="sr-only"><tr><th scope="col">Housekeeping status</th><th scope="col">Room count</th></tr></thead>
        <tbody>
          <tr><td><span class="dot" style="background:#f59e0b"></span> <button type="button" class="ws-status-filter" data-ws-hk-filter="dirty" aria-label="Filter room board to dirty rooms ({{ $hk['Dirty'] }})" aria-pressed="{{ $selectedHousekeeping === 'dirty' ? 'true' : 'false' }}">Dirty</button></td><td class="text-right bold">{{ $hk['Dirty'] }}</td></tr>
          <tr><td><span class="dot" style="background:#10b981"></span> <button type="button" class="ws-status-filter" data-ws-hk-filter="ready" aria-label="Filter room board to clean or inspected rooms ({{ $hk['Clean'] }})" aria-pressed="{{ $selectedHousekeeping === 'ready' ? 'true' : 'false' }}">Clean / inspected</button></td><td class="text-right bold">{{ $hk['Clean'] }}</td></tr>
          <tr><td><span class="dot" style="background:#3b82f6"></span> <button type="button" class="ws-status-filter" data-ws-hk-filter="touch_up" aria-label="Filter room board to touch-up rooms ({{ $hk['Touch-up'] }})" aria-pressed="{{ $selectedHousekeeping === 'touch_up' ? 'true' : 'false' }}">Touch-up</button></td><td class="text-right bold">{{ $hk['Touch-up'] }}</td></tr>
        </tbody>
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
  $modalGuestIsVip = (bool) ($stay?->guest?->is_vip || $stay?->reservation?->guest?->is_vip);
  $modalGuestContext = collect([
      $stay?->reservation?->businessSource?->name ?: 'Direct',
      $stay?->guest?->nationality ?? $stay?->reservation?->guest?->nationality,
      $modalGuestIsVip ? 'VIP' : null,
  ])->filter(fn ($value) => is_string($value) && trim($value) !== '')->implode(' · ');
  $roomActions = [];
  if (! $isHistory && $c['status'] === 'available' && can_do('reservations.create')) $roomActions[] = ['Reserve Room', 'building', route('reservations.create', ['room' => $room->id, 'arrival' => $date->toDateString(), 'departure' => $date->copy()->addDay()->toDateString()])];
  if ($isToday && $stay && $stay->status === 'reserved' && $stay->room_id && $c['status'] === 'reserved' && $room->status === 'vacant' && ! isset($inventoryBlocks[$room->id]) && can_do('frontdesk.checkin')) $roomActions[] = ['Check-In', 'login', route('frontdesk.checkin', $stay)];
  if ($isToday && $stay && $stay->status === 'checked_in' && can_do('frontdesk.checkout')) $roomActions[] = ['Check-Out', 'logout', route('frontdesk.checkout', $stay)];
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
  if ($canViewHousekeeping) $facts[] = ['Current housekeeping', $housekeepingLabel($room->hk_status)];
@endphp
<div class="modal ws-modal" id="roomModal{{ $room->id }}" role="dialog" aria-modal="true" aria-labelledby="roomModalTitle{{ $room->id }}" tabindex="-1"><div class="modal-box wide">
  <div class="ws-modal-hero" style="background:{{ $room->roomType?->image ? 'url('.asset($room->roomType->image).') center/cover no-repeat, ' : '' }}linear-gradient(135deg, hsl({{ (($room->room_type_id ?? $room->id) * 47) % 360 }},32%,24%), hsl({{ (($room->room_type_id ?? $room->id) * 47 + 40) % 360 }},26%,38%))">
    <button type="button" class="btn btn-ghost btn-icon ws-modal-x" data-close aria-label="Close room details"><x-icon name="x"/></button>
    <div class="ws-modal-hero-row">
      <span class="ws-room-no huge">{{ $room->room_no }}</span>
      <div><h2 id="roomModalTitle{{ $room->id }}" class="bold ws-modal-title"><span class="sr-only">Room {{ $room->room_no }}: </span>{{ $room->roomType?->name ?? 'Room details' }} · {{ $room->roomType?->code ?? '' }}</h2>
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
    </div>
    @if (count($c['amenities']) > 12)<details class="ws-fac-more"><summary>Show {{ count($c['amenities']) - 12 }} more {{ count($c['amenities']) - 12 === 1 ? 'amenity' : 'amenities' }}</summary><div class="ws-fac" style="margin-top:6px">
      @foreach (array_slice($c['amenities'], 12) as $a)<span class="ws-fac-chip"><x-icon name="{{ WS::amenityIcon($a) }}"/> {{ $a }}</span>@endforeach
    </div></details>@endif
    </div>
    @if ($stay)<div><div class="ws-rail-title">{{ $isHistory ? 'STAY ON THIS DATE' : ($isFuture ? 'RESERVATION ON THIS DATE' : 'CURRENT RESERVATION') }}</div><div class="ws-resv">
      <div class="flex gap"><span class="ws-avatar">{{ $stayGuestInitial }}</span>
        <div><div class="bold">{{ $stayGuestName }}</div>
          <div class="muted small">{{ $modalGuestContext }}</div></div></div>
      <table class="ws-resv-t" aria-label="Stay, reservation and folio details for room {{ $room->room_no }}">
        <thead class="sr-only"><tr><th scope="col">Detail</th><th scope="col">Value</th><th scope="col">Detail</th><th scope="col">Value</th></tr></thead>
        <tbody>
        <tr><td class="muted">Confirmation</td><td class="bold">{{ $stay->reservation?->reservation_no ?? '—' }}</td><td class="muted">Source</td><td class="bold">{{ $stay->reservation?->businessSource?->name ?? 'Direct' }}</td></tr>
        <tr><td class="muted">Stay</td><td class="bold">{{ \Illuminate\Support\Carbon::parse($stay->arrival_date)->format($stayDateFormat($stay)) }} → {{ \Illuminate\Support\Carbon::parse($stay->departure_date)->format($stayDateFormat($stay)) }} · {{ max(1, \Illuminate\Support\Carbon::parse($stay->arrival_date)->diffInDays(\Illuminate\Support\Carbon::parse($stay->departure_date))) }}n</td><td class="muted">Guests</td><td class="bold">{{ $stay->adults }} Adults · {{ $stay->children }} Children</td></tr>
        <tr><td class="muted">Rate</td><td class="bold">{{ money($c['rate']) }}/n</td>@if (can_do('folio.view'))<td class="muted">Balance</td><td class="bold {{ ($c['balance'] ?? 0) > 0 ? 'ws-due' : '' }}">{{ $c['balance'] === null ? 'No folio' : ($c['balance'] > 0 ? money($c['balance']) : ($c['balance'] < 0 ? money(abs($c['balance'])).' in credit' : 'Settled')) }}</td>@else<td colspan="2"></td>@endif</tr>
        @if (can_do('folio.view') && ($c['due'] ?? null) !== null && abs(($c['due'] ?? 0) - ($c['balance'] ?? 0)) > 0.005)<tr><td class="muted">Estimated checkout</td><td class="bold ws-due" colspan="3">{{ money($c['due']) }} <span class="muted small">(includes nights and fixed charges still to post)</span></td></tr>@endif
        </tbody>
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
  const housekeeping = document.getElementById('fHousekeeping');
  const vipOnly = document.getElementById('fVip');
  const search = document.getElementById('fSearch');
  const filters = [status, type, floor, source, housekeeping].filter(Boolean);
  let filterAnnouncementTimer = null;

  const normalizeSearchText = value => value.normalize('NFD').replace(/\p{M}/gu, '').toLocaleLowerCase();
  const compactSearchText = value => normalizeSearchText(value).replace(/[^\p{L}\p{N}]/gu, '');
  function applyWsFilters(announceDelay = 0) {
    const values = [status?.value || '', type?.value || '', floor?.value || '', source?.value || '', housekeeping?.value || ''];
    const vipSelected = Boolean(vipOnly?.checked);
    const queryText = normalizeSearchText(search?.value.trim() || '');
    const compactQuery = compactSearchText(queryText);
    const cards = [...document.querySelectorAll('#wsGrid .ws-card')];
    const noResults = document.getElementById('wsNoResults');
    let visible = 0;
    cards.forEach(card => {
      const matchesHousekeeping = !values[4] || (values[4] === 'ready'
        ? ['clean', 'inspected'].includes(card.dataset.hk)
        : card.dataset.hk === values[4]);
      const matchesFilters = (!values[0] || card.dataset.status === values[0])
        && (!values[1] || card.dataset.type === values[1])
        && (!values[2] || card.dataset.floor === values[2])
        && (!values[3] || card.dataset.source === values[3])
        && matchesHousekeeping
        && (!vipSelected || card.dataset.vip === '1');
      const cardSearchText = normalizeSearchText(card.dataset.search || card.textContent);
      const matchesSearch = !queryText || cardSearchText.includes(queryText)
        || (compactQuery && compactSearchText(cardSearchText).includes(compactQuery));
      card.hidden = !(matchesFilters && matchesSearch);
      if (!card.hidden) visible++;
    });
    if (noResults) noResults.hidden = cards.length === 0 || visible > 0;
    const totalNoun = cards.length === 1 ? 'room' : 'rooms';
    const filterStatus = document.getElementById('wsFilterStatus');
    if (filterStatus) {
      clearTimeout(filterAnnouncementTimer);
      const announcement = `${visible} of ${cards.length} ${totalNoun} shown`;
      if (announceDelay > 0) filterAnnouncementTimer = setTimeout(() => { filterStatus.textContent = announcement; }, announceDelay);
      else filterStatus.textContent = announcement;
    }
    const visibleCount = document.getElementById('wsVisibleCount');
    if (visibleCount) visibleCount.textContent = `${visible} / ${cards.length} ${totalNoun}`;
    document.querySelectorAll('[data-ws-status-filter]').forEach(button => {
      button.setAttribute('aria-pressed', String(status?.value === button.dataset.wsStatusFilter));
    });
    document.querySelectorAll('[data-ws-hk-filter]').forEach(button => {
      button.setAttribute('aria-pressed', String(housekeeping?.value === button.dataset.wsHkFilter));
    });
    const params = new URLSearchParams(window.location.search);
    ['status', 'type', 'floor', 'source', 'hk'].forEach((key, i) => values[i] ? params.set(key, values[i]) : params.delete(key));
    if (vipSelected) params.set('vip', '1'); else params.delete('vip');
    if (queryText) params.set('q', search.value.trim()); else params.delete('q');
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

  filters.forEach(select => select.addEventListener('change', () => applyWsFilters()));
  document.querySelectorAll('[data-ws-status-filter]').forEach(button => button.addEventListener('click', () => {
    if (!status) return;
    const nextStatus = button.dataset.wsStatusFilter || '';
    status.value = status.value === nextStatus ? '' : nextStatus;
    applyWsFilters();
    status.focus();
  }));
  document.querySelectorAll('[data-ws-hk-filter]').forEach(button => button.addEventListener('click', () => {
    if (!housekeeping) return;
    const nextStatus = button.dataset.wsHkFilter || '';
    housekeeping.value = housekeeping.value === nextStatus ? '' : nextStatus;
    applyWsFilters();
    housekeeping.focus();
  }));
  vipOnly?.addEventListener('change', () => applyWsFilters());
  search?.addEventListener('input', () => applyWsFilters(300));
  const clearWsFilters = event => {
    filters.forEach(select => { select.value = ''; });
    if (vipOnly) vipOnly.checked = false;
    if (search) search.value = '';
    applyWsFilters();
    if (event.currentTarget.id === 'clearWsFiltersEmpty') search?.focus();
  };
  document.querySelectorAll('#clearWsFilters, #clearWsFiltersEmpty').forEach(button => button.addEventListener('click', clearWsFilters));
  document.getElementById('refreshWsBoard')?.addEventListener('click', () => window.location.reload());
  document.querySelectorAll('.ws-extend-form').forEach(form => form.addEventListener('submit', () => {
    const departureDate = form.querySelector('[name="departure_date"]')?.value;
    if (departureDate) form.dataset.confirm = `Extend the stay in room ${form.dataset.roomNo} through ${departureDate}? Availability and stay charges will be rechecked.`;
  }));
  document.querySelectorAll('.ws-assign').forEach(form => form.addEventListener('submit', () => {
    const selectedRoom = form.querySelector('.ws-assign-select')?.selectedOptions?.[0]?.textContent?.trim().replace(/\s+/g, ' ');
    if (selectedRoom) form.dataset.confirm = `Assign ${selectedRoom} to ${form.dataset.guestName}? Room availability will be rechecked before saving.`;
  }));
  document.getElementById('wsDateJump')?.addEventListener('change', event => {
    const input = event.currentTarget;
    if (!input.value || input.value < input.min || input.value > input.max) return;
    const target = new URL(window.location.href);
    target.searchParams.set('date', input.value);
    window.location.assign(target.pathname + target.search + target.hash);
  });

  let activeModal = null;
  let returnFocus = null;
  let previousBodyOverflow = null;
  let isolatedNodes = [];
  function isolateRoomModal(modal) {
    const saved = [];
    let branch = modal;
    while (branch && branch !== document.body) {
      const parent = branch.parentElement;
      if (!parent) break;
      [...parent.children].forEach(sibling => {
        if (sibling === branch) return;
        saved.push({ node: sibling, inert: sibling.hasAttribute('inert'), ariaHidden: sibling.getAttribute('aria-hidden') });
        sibling.setAttribute('inert', '');
        sibling.setAttribute('aria-hidden', 'true');
      });
      branch = parent;
    }
    return saved;
  }
  function restoreRoomBackground() {
    isolatedNodes.reverse().forEach(({ node, inert, ariaHidden }) => {
      if (!node.isConnected) return;
      if (!inert) node.removeAttribute('inert');
      if (ariaHidden === null) node.removeAttribute('aria-hidden');
      else node.setAttribute('aria-hidden', ariaHidden);
    });
    isolatedNodes = [];
  }
  function closeRoomModal() {
    if (!activeModal) return;
    const focusTarget = returnFocus;
    activeModal.classList.remove('show');
    restoreRoomBackground();
    document.body.style.overflow = previousBodyOverflow || '';
    previousBodyOverflow = null;
    activeModal = null;
    returnFocus = null;
    if (focusTarget?.isConnected) {
      focusTarget.setAttribute('aria-expanded', 'false');
      focusTarget.focus();
    }
  }
  function openRoomModal(id, trigger) {
    const modal = document.getElementById('roomModal' + id);
    if (!modal) return;
    closeRoomModal();
    returnFocus = trigger || document.activeElement;
    returnFocus?.setAttribute('aria-expanded', 'true');
    previousBodyOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    activeModal = modal;
    isolatedNodes = isolateRoomModal(modal);
    modal.classList.add('show');
    modal.focus();
  }
  function trapRoomModalFocus(event) {
    if (!activeModal || event.key !== 'Tab') return;
    const focusable = [...activeModal.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])')]
      .filter(element => !element.hidden && element.getAttribute('aria-hidden') !== 'true' && element.getClientRects().length > 0);
    if (!focusable.length) {
      event.preventDefault();
      activeModal.focus();
      return;
    }
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (!activeModal.contains(document.activeElement)) {
      event.preventDefault();
      (event.shiftKey ? last : first).focus();
    } else if (event.shiftKey && (document.activeElement === first || document.activeElement === activeModal)) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === activeModal) {
      event.preventDefault();
      first.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }
  document.querySelectorAll('.ws-all, .ws-photo-link, .ws-amen-more').forEach(trigger => {
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
    else trapRoomModalFocus(event);
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
.ws-date-nav .disabled{opacity:.65}
.ws-date-nav .disabled:hover{background:var(--c-surface)}
.ws-date-jump-label{font-size:11px;color:#64748b;margin-left:4px}
.ws-date-jump{width:132px;padding:4px 6px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;color:#334155;font-size:12px}
.ws-history-note{margin-top:5px;max-width:720px}
.ws-photo-link:focus-visible{outline:3px solid var(--c-primary);outline-offset:2px}
.ws-extend-form .sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
.ws-kpis{display:grid;grid-template-columns:repeat(var(--ws-kpi-count),minmax(0,1fr));gap:8px;margin-bottom:6px}
.ws-revenue-note{margin:0 0 12px 2px}
.ws-kpi{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:10px 12px}
.ws-kpi-delta{font-size:11px;font-weight:600}
.ws-kpi-delta.up{color:#16a34a}.ws-kpi-delta.down{color:#ef4444}.ws-kpi-delta.neutral{color:#64748b}
.ws-spark{width:100%;height:24px;display:block;margin:4px 0}
.ws-kpi-value{font-size:18px;font-weight:700}
.ws-kpi-label{font-size:10px;letter-spacing:.4px;text-transform:uppercase;color:#64748b}
.ws-filters{display:flex;gap:8px;align-items:center;margin-bottom:12px;flex-wrap:wrap}
.ws-filter-count{margin-left:auto;white-space:nowrap;font-variant-numeric:tabular-nums}
.ws-select{max-width:220px}
.ws-vip-filter{display:inline-flex;align-items:center;gap:6px;min-height:34px;padding:0 8px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px;white-space:nowrap;cursor:pointer}
.ws-vip-filter:focus-within{border-color:#15803d;box-shadow:0 0 0 3px rgba(21,128,61,.12)}
.ws-vip-filter input{width:15px;height:15px;accent-color:var(--c-primary)}
.ws-search{width:230px;max-width:min(100%,260px)}
.ws-search:focus{border-color:#15803d;box-shadow:0 0 0 3px rgba(21,128,61,.12)}
.ws-body{display:grid;grid-template-columns:1fr 320px;gap:12px;align-items:start}
.ws-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(255px,1fr));gap:12px}
.ws-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden}
.ws-card-head{display:flex;justify-content:space-between;padding:10px 12px 6px}
.ws-room-no{font-size:22px;font-weight:800;margin:0;line-height:1.1}
.ws-tag{display:inline-block;margin-top:4px;font-size:9px;font-weight:700;letter-spacing:.4px;background:#fef3c7;color:#b45309;padding:2px 6px;border-radius:5px}
.ws-hk-badge{display:inline-block;margin-top:4px;padding:2px 5px;border-radius:5px;font-size:9px;font-weight:700;line-height:1.2;white-space:nowrap}
.ws-hk-dirty{background:#fef3c7;color:#92400e}.ws-hk-clean{background:#dcfce7;color:#166534}.ws-hk-inspected{background:#d1fae5;color:#065f46}.ws-hk-touchup{background:#dbeafe;color:#1e40af}.ws-hk-unknown{background:#f1f5f9;color:#475569}
.ws-photo{height:128px;position:relative;border-radius:8px;margin:0 10px}
.ws-photo-link{cursor:pointer}
.ws-person-row{display:flex;flex-direction:column;gap:4px}
.ws-assign{display:flex;gap:6px;align-items:center;padding-left:34px}
.ws-assign-select{flex:1;min-width:0;font-size:12px;padding:3px 6px;border:1px solid #cbd5e1;border-radius:6px;background:#fff}
.ws-assign-none{padding-left:34px}.ws-assign-none a{color:#166534;font-weight:600;text-decoration:underline;text-underline-offset:2px}
.ws-checkin-blocker{display:block;margin-top:5px;padding:6px 8px;border:1px solid #fcd34d;border-radius:7px;background:#fffbeb;color:#92400e;font-size:11px;line-height:1.35}
.ws-kpi:hover{border-color:#94a3b8}
.ws-photo-tag{position:absolute;left:10px;top:8px;background:rgba(15,23,42,.72);color:#fff;font-size:9px;font-weight:700;letter-spacing:.6px;padding:2px 6px;border-radius:4px}
.ws-photo-rate{position:absolute;right:10px;bottom:8px;background:#f59e0b;color:#1f2937;font-weight:800;padding:3px 8px;border-radius:6px;font-size:13px}
.ws-photo-rate small{font-weight:500}
.ws-room-meta{display:flex;gap:10px;padding:8px 12px 2px}
.ws-amen{display:flex;flex-wrap:wrap;gap:6px;padding:6px 12px 10px;color:#94a3b8}
.ws-amen .i{width:15px;height:15px}
.ws-amen-more{padding:0 5px;border:1px solid #bbf7d0;border-radius:999px;background:#f0fdf4;color:#166534;font-size:10px;font-weight:700;cursor:pointer}
.ws-amen-more:hover{background:#dcfce7}
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
.ws-waitlist-all{margin-left:auto;color:#166534;font-size:10px;font-weight:700;text-decoration:underline;text-transform:none;letter-spacing:0}
.ws-rail-open{margin-left:auto;color:#166534;font-size:10px;font-weight:700;text-decoration:underline;text-transform:none;letter-spacing:0;white-space:nowrap}
.ws-stack{display:flex;height:8px;border-radius:6px;overflow:hidden;margin:4px 0 10px}
.ws-rail-list{width:100%;font-size:12px}
.ws-rail-list td{padding:2px 0}
.ws-status-filter{border:0;padding:0;background:transparent;color:inherit;font:inherit;text-align:left;cursor:pointer}
.ws-status-filter:hover,.ws-status-filter[aria-pressed="true"]{font-weight:700;text-decoration:underline}
.dot{display:inline-block;width:7px;height:7px;border-radius:50%;margin-right:6px}
.ws-person{display:flex;gap:8px;align-items:center;padding:5px 0;text-decoration:none;color:inherit}
.ws-person:hover{background:#f8fafc}
.ws-cal-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:5px}
.ws-cal-head .disabled{opacity:.65}
.ws-cal-head .disabled:hover{background:transparent}
.ws-cal{display:grid;grid-template-columns:repeat(7,1fr);gap:2px;font-size:11px}
.ws-cal-d{text-align:center;font-size:9px}
.ws-cal-day{text-align:center;padding:3px 0;border-radius:6px;position:relative;color:inherit;text-decoration:none;display:block}
.ws-cal-day.selected{outline:2px solid #64748b;outline-offset:-2px}
.ws-cal-day.today{background:var(--c-primary-600);color:#fff}
.ws-cal-day.disabled{color:#64748b;cursor:not-allowed}
.ws-cal-dots{display:block;height:5px;line-height:5px}
.dot-a,.dot-d{display:inline-block;width:4px;height:4px;border-radius:50%;margin:0 1px}
.dot-a{background:#16a34a}.dot-d{background:#ef4444}
.ws-legend{margin-right:10px}
.ws-modal{overscroll-behavior:contain}
.ws-modal .modal-box{max-width:900px}
.ws-modal .modal-box.wide{width:min(920px,calc(100vw - 2rem))}
.ws-modal-hero{position:relative;padding:56px 22px 18px;color:#fff;border-radius:0}
.ws-modal-x{position:absolute;top:10px;right:10px;color:#fff}
.ws-modal-hero-row{display:flex;gap:16px;align-items:center;justify-content:space-between}
.ws-modal-title{margin:0;font-size:inherit;line-height:1.2;color:inherit}
.ws-modal-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.ws-facts{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.ws-fact{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:8px 10px;font-size:13px}
.ws-fac{display:flex;flex-wrap:wrap;gap:6px}
.ws-fac-more{margin-top:7px;color:#166534;font-size:12px}
.ws-fac-more summary{width:max-content;max-width:100%;cursor:pointer;font-weight:700;text-decoration:underline;text-underline-offset:2px}
.ws-fac-more summary:focus-visible{outline:2px solid #166534;outline-offset:3px;border-radius:3px}
.ws-fac-chip{display:inline-flex;gap:5px;align-items:center;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;padding:5px 9px;font-size:12px}
.ws-resv{border:1px solid #e2e8f0;border-radius:10px;padding:12px}
.ws-resv-t{width:100%;margin-top:10px;font-size:12px}
.ws-resv-t td{padding:3px 6px 3px 0}
.ws-quick{display:grid;grid-template-columns:repeat(auto-fill,minmax(112px,1fr));gap:8px}
.ws-quick-tile{display:flex;flex-direction:column;gap:6px;align-items:center;justify-content:center;padding:12px 6px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;color:#1e293b;text-decoration:none;font-size:11px}
.ws-quick-tile:hover{background:#f8fafc}
@media (max-width:1200px){.ws-kpis{grid-template-columns:repeat(5,1fr)}.ws-body{grid-template-columns:1fr}}
@media (max-width:680px){
  .ws-head{align-items:flex-start;flex-direction:column;gap:8px}
  .ws-head>div:first-child{width:100%;min-width:0}
  .ws-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}
  .ws-search{width:100%;max-width:none}
  .ws-modal-grid{grid-template-columns:1fr}
  .ws-modal-hero-row{align-items:flex-start;flex-wrap:wrap}
  .ws-resv-t{table-layout:fixed}
  .ws-resv-t td{overflow-wrap:anywhere;vertical-align:top}
  .ws-assign,.ws-assign-none{padding-left:0}
  .ws-extend-form{width:100%}
  .ws-extend-form input{flex:1;max-width:none}
}
body.dark .ws-kpi,
body.dark .ws-card,
body.dark .ws-fact,
body.dark .ws-fac-chip,
body.dark .ws-resv,
body.dark .ws-quick-tile,
body.dark .ws-date-jump,
body.dark .ws-assign-select{background:var(--c-surface);color:var(--c-text);border-color:var(--c-border)}
body.dark .ws-kpi-label,body.dark .ws-rail-title,body.dark .ws-date-jump-label{color:var(--c-muted)}
body.dark .ws-kpi-delta.neutral{color:#c1d2c5}
body.dark .ws-waitlist-all,body.dark .ws-rail-open{color:#86efac}
body.dark .ws-hk-dirty{background:#422006;color:#fcd34d}body.dark .ws-hk-clean{background:#143323;color:#86efac}body.dark .ws-hk-inspected{background:#12362e;color:#6ee7b7}body.dark .ws-hk-touchup{background:#172b4d;color:#93c5fd}body.dark .ws-hk-unknown{background:#243229;color:#c1d2c5}
body.dark .ws-due,body.dark .ws-kpi-delta.down{color:#f87171}
body.dark .ws-person:hover{background:#193525}
body.dark .ws-assign-none a{color:#86efac}
body.dark .ws-checkin-blocker{background:#422006;border-color:#854d0e;color:#fcd34d}
body.dark .ws-guest{border-top-color:var(--c-border)}
body.dark .ws-guest.arriving{background:rgba(245,158,11,.08);border-left-color:#fbbf24}
body.dark .ws-guest.inhouse{background:rgba(59,130,246,.10);border-left-color:#60a5fa}
body.dark .ws-actions{border-top-color:var(--c-border)}
body.dark .ws-fac-more{color:#86efac}
body.dark .ws-fac-more summary:focus-visible{outline-color:#86efac}
body.dark .ws-amen-more{background:#193525;border-color:#3b5946;color:#86efac}
body.dark .ws-vip-filter{border-color:var(--c-border)}
body.dark .ws-cal-day.disabled{color:#94a3b8}
</style>
@endpush
