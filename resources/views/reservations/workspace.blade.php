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
<div class="ws-screen">
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
  $canMoveRooms = ! $isHistory && (can_do('frontdesk.roommove') || can_do('reservations.edit'));
  $roomStatusLabels = ['available' => 'Available', 'reserved' => 'Reserved', 'occupied' => 'Occupied', 'out_of_order' => 'Out of Order', 'out_of_service' => 'Out of Service', 'leased' => 'Leased', 'maintenance' => 'Maintenance', 'house_use' => 'House Use', 'group_hold' => 'Group Hold', 'other_block' => 'Other Block'];
  $roomStatusKeys = array_flip($roomStatusLabels);
  $attentionItems = $attention ?? [];
  $attentionCount = collect($attentionItems)->sum('count');
  $trendInhouse = $trend['inhouse'] ?? [];
  $headStats = [
      ['Arrivals', $arrivals->count(), can_do('frontdesk.view') ? route('frontdesk.arrivals', ['date' => $date->toDateString()]) : null, 'login'],
      ['Departures', $departures->count(), can_do('frontdesk.view') ? route('frontdesk.departures', ['date' => $date->toDateString()]) : null, 'logout'],
      ['In house', $trendInhouse ? (int) $trendInhouse[count($trendInhouse) - 1] : 0, can_do('frontdesk.view') ? route('frontdesk.inhouse') : null, 'users'],
      ['Rooms sold', ($statusSummary['Occupied'][0] ?? 0) + ($statusSummary['Reserved'][0] ?? 0), null, 'bed'],
  ];
@endphp
@php
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
  <div class="ws-head-main">
    <div class="ws-eyebrow"><x-icon name="hotel" size="sm"/> Front Office <span aria-hidden="true">/</span> Daily room operations</div>
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
      @if ($isFuture)<span class="badge badge-blue ws-head-context">Planning ahead</span>
      @elseif ($isHistory)<span class="badge badge-slate ws-head-context">Historic board</span>
      @else<span class="badge badge-green ws-head-context">Business date</span>@endif
    </div>
    <div class="ws-head-stats">
      @foreach ($headStats as [$statLabel, $statValue, $statUrl, $statIcon])
        @if ($statUrl)<a class="ws-head-stat" href="{{ $statUrl }}"><x-icon name="{{ $statIcon }}" size="sm"/> <b>{{ $statValue }}</b> <span>{{ $statLabel }}</span></a>
        @else<span class="ws-head-stat"><x-icon name="{{ $statIcon }}" size="sm"/> <b>{{ $statValue }}</b> <span>{{ $statLabel }}</span></span>@endif
      @endforeach
    </div>
    @if ($isHistory)<div class="muted small ws-history-note">Past stays are inferred from current reservation records; dated outages and leases are honored, but room-status and housekeeping history is unavailable. VIP flags reflect current guest profiles.</div>
    @elseif ($isFuture)<div class="muted small ws-history-note">Forward reservations, active non-occupancy room flags and dated blocks apply; housekeeping is current and availability may change. VIP flags reflect current guest profiles.</div>@endif
  </div>
  <div class="ws-head-side">
    <span class="ws-head-snapshot" id="wsClock" title="Data generated when this page was rendered"><x-icon name="clock" size="sm"/> Board snapshot <time datetime="{{ now()->toIso8601String() }}">{{ now()->format('H:i:s') }}</time></span>
  </div>
</div>

{{-- ── KPI strip ── --}}
@php
  use App\Services\ReservationWorkspaceService as WS;
  $revenueExplanation = $isToday
      ? 'Current room-rate total includes checked-in stays covering the hotel business date; arrivals not yet checked in are excluded. Excludes taxes, fees, and folio postings.'
      : ($isFuture
          ? 'Booked room-rate value includes reserved and checked-in stays covering the selected date. Excludes taxes, fees, and folio postings.'
          : 'Historical room-rate value includes checked-in and checked-out stays covering the selected date. Excludes taxes, fees, and folio postings.');
@endphp
<div class="ws-kpis" style="--ws-kpi-count:{{ count($kpiTiles) }}">
  @foreach ($kpiTiles as $k)
  @php $canOpenKpi = $k['opens'] && can_do($k['permission']); @endphp
  @if ($canOpenKpi)<a class="ws-kpi" href="{{ $k['link'] }}" style="color:inherit;text-decoration:none" title="{{ $k['key'] === 'revenue' ? $revenueExplanation : 'Change compared with the previous selected day' }}">
  @else<div class="ws-kpi" title="{{ $k['key'] === 'revenue' ? $revenueExplanation : 'Change compared with the previous selected day' }}">
  @endif
    <div class="flex gap"><span class="ws-kpi-delta {{ $k['tone'] === 'neutral' ? 'neutral' : ($k['tone'] === 'red' ? 'down' : 'up') }}" aria-label="Change from previous selected day: {{ $k['delta'] === '—' ? 'unavailable' : $k['delta'] }}">{{ $k['delta'] }}</span></div>
    {!! WS::spark($k['series'], $k['tone']) !!}
    <div class="ws-kpi-value" title="{{ $k['value'] }}">{{ $k['value'] }}</div>
    <div class="ws-kpi-label">{{ $k['label'] }}</div>
  @if ($canOpenKpi)</a>@else</div>@endif
  @endforeach
</div>
@if (can_do('revenue.view'))<div class="ws-revenue-note muted small">{{ $revenueExplanation }}</div>@endif

{{-- ── Command bar: search, filters, board layout ── --}}
<div class="ws-cmd" id="wsCmd">
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
  <span class="ws-chips" id="wsChips" aria-label="Active filters"></span>
</div>
<div class="ws-toolbar">
  <div class="ws-viewswitch" role="tablist" aria-label="Room board layout">
    <button type="button" role="tab" id="wsTabBoard" class="ws-vs-btn is-on" data-ws-view="board" aria-selected="true" aria-controls="wsGrid"><x-icon name="grid" size="sm"/> Board</button>
    <button type="button" role="tab" id="wsTabRack" class="ws-vs-btn" data-ws-view="rack" aria-selected="false" aria-controls="wsRack"><x-icon name="list" size="sm"/> Rack</button>
  </div>
  <label class="ws-groupby" for="wsGroupBy"><span>Group by</span>
    <select id="wsGroupBy">
      <option value="">Nothing</option>
      <option value="floor">Floor</option>
      <option value="type">Room type</option>
      <option value="status">Status</option>
    </select>
  </label>
  {{-- v10.18 the rack sorts by columns that can retreat below ~720px, so the order is stated here, where
       it survives the fold, and the chip itself reverses or clears it. --}}
  <span class="ws-rack-sortstate" id="wsRackSortState" role="status" aria-live="polite" hidden></span>
  <span class="ws-keys" aria-hidden="true"><kbd>/</kbd> search <kbd>B</kbd> board <kbd>R</kbd> rack <kbd>G</kbd> group</span>
</div>
</div>
<div id="wsFilterStatus" class="sr-only" role="status" aria-live="polite" aria-atomic="true"></div>

<div class="ws-body">
  <div class="ws-main">
  {{-- ── Room board ── --}}
  @if ($canMoveRooms)<p class="ws-action-status" id="wsActionStatus" role="status" aria-live="polite"></p>@endif
  <div class="ws-grid" id="wsGrid" role="region" aria-label="Room board for {{ $date->format('j F Y') }}">
    @forelse ($cards as $c)
      @php
        $room = $c['room']; $stay = $c['stay']; $src = $stay?->reservation?->businessSource?->name ?? '';
        $roomSource = $stay ? ($src ?: 'Direct') : '';
        $isVip = (bool) ($stay?->guest?->is_vip || $stay?->reservation?->guest?->is_vip);
        $hkTone = ['dirty' => 'dirty', 'clean' => 'clean', 'inspected' => 'inspected', 'touch_up' => 'touchup'][$room->hk_status] ?? 'unknown';
        $stayGuestName = $guestDisplayName($stay);
        $stayGuestInitial = $guestInitial($stay);
        // v10.23 the card stopped printing the money, so what is due travels to the rack as data on the card
        // v10.26 the amenity icons live in a rail down the left of the photo: four slots, and the fourth becomes the
        // count when there are more than four, so the number is honest at every card width.
        $railAmenities = array_slice($c['amenities'], 0, count($c['amenities']) > 4 ? 3 : 4);
        $railMore = count($c['amenities']) - count($railAmenities);
        $cardNights = $stay ? max(1, \Illuminate\Support\Carbon::parse($stay->arrival_date)->diffInDays(\Illuminate\Support\Carbon::parse($stay->departure_date))) : '';
        $cardDue = $stay ? ($c['due'] ?? $c['balance']) : null;
        $cardDueLabel = ($cardDue === null || ! can_do('folio.view')) ? null
          : ($cardDue > 0 ? money($cardDue).' due' : ($cardDue < 0 ? money(abs($cardDue)).' credit' : 'Settled'));
        // the room's own photo first, then its type's, then the generated block
        $cardPhoto = $c['photo_full'] ?? $c['photo'] ?? $room->roomType?->image;
        $searchText = implode(' ', array_unique(array_map('strval', array_filter([
          $room->room_no, $room->roomType?->name, $room->roomType?->code, $room->floor?->name, $stay?->reservation?->reservation_no,
          $stay?->reservation?->group_name, $stay?->reservation?->ota_reference, $stay?->reservation?->company?->name, $roomSource,
          $stay?->guest?->first_name, $stay?->guest?->last_name, $stay?->guest?->phone, $stay?->guest?->email,
          $stay?->reservation?->guest?->first_name, $stay?->reservation?->guest?->last_name,
          $stay?->reservation?->guest?->phone, $stay?->reservation?->guest?->email,
          implode(' ', $c['amenities']),
        ], fn ($value) => is_scalar($value) && trim((string) $value) !== ''))));
      @endphp
      <div class="ws-card" data-drop-room="{{ $room->id }}" data-room-no="{{ $room->room_no }}" data-status="{{ $c['status'] }}" data-type="{{ $room->room_type_id }}" data-floor="{{ $room->floor_id }}" data-source="{{ $roomSource }}" data-hk="{{ $canViewHousekeeping ? $room->hk_status : '' }}" data-vip="{{ $isVip ? '1' : '0' }}" data-search="{{ $searchText }}" data-type-name="{{ $room->roomType?->name }}" data-floor-name="{{ $room->floor?->name }}" data-status-label="{{ str_replace('_', ' ', ucfirst((string) $c['status'])) }}" data-photo="{{ $cardPhoto }}"@if ($stay) data-guest="{{ $stayGuestName }}" data-guest-meta="{{ $roomSource }}{{ $isVip ? ' · VIP' : '' }}{{ $stay->guest?->nationality ? ' · '.$stay->guest->nationality : '' }}" data-nights="{{ $cardNights }}"@endif @if ($cardDueLabel !== null) data-due="{{ $cardDueLabel }}"@endif>
        <div class="ws-photo ws-photo-link" data-room="{{ $room->id }}" role="button" tabindex="0" aria-label="Room {{ $room->room_no }}, {{ $room->roomType?->name ?? 'room' }}, {{ str_replace('_', ' ', $c['status']) }}, {{ money($c['rate']) }} a night. Open room details" aria-haspopup="dialog" aria-controls="roomModal{{ $room->id }}" aria-expanded="false" title="Room details" style="background:linear-gradient(135deg, hsl({{ ($room->room_type_id * 47) % 360 }},32%,28%), hsl({{ ($room->room_type_id * 47 + 40) % 360 }},28%,42%))">
          @if ($cardPhoto)<img class="ws-photo-img" src="{{ $cardPhoto }}" alt="" loading="lazy" decoding="async">@endif
          {{-- v10.26 the facilities read down the left edge of the picture instead of as a row of glyphs under it:
               a column costs the card no height at all, and four names is what a 104px photo can carry without
               turning into a texture. The fourth slot becomes the count when there are more, and it opens the
               dialog, which names every one of them. --}}
          @if ($railAmenities)
          <div class="ws-amen-rail">
            @foreach ($railAmenities as $a)
            <span role="img" aria-label="{{ $a }}" title="{{ $a }}"><x-icon name="{{ WS::amenityIcon($a) }}"/></span>
            @endforeach
            @if ($railMore > 0)
            <button type="button" class="ws-amen-more" data-room="{{ $room->id }}" aria-label="Open room {{ $room->room_no }} details; {{ $railMore }} more {{ $railMore === 1 ? 'amenity' : 'amenities' }}" aria-haspopup="dialog" aria-controls="roomModal{{ $room->id }}" aria-expanded="false">+{{ $railMore }}</button>
            @endif
          </div>
          @endif
          <div class="ws-card-head">
            <div class="flex gap">
              <h2 class="ws-room-no" id="roomHeading{{ $room->id }}"><span class="sr-only">Room </span>{{ $room->room_no }}</h2>
              <div>
                <div class="bold small">{{ $room->roomType?->name ?? '—' }}</div>
                <div class="muted small">{{ $room->floor?->name ? (is_numeric($room->floor->name) ? 'Floor '.$room->floor->name : $room->floor->name) : '' }}{{ $room->roomType?->code ? ' · '.$room->roomType->code : '' }}</div>
              </div>
            </div>
            <div class="text-right">
              <span class="badge badge-{{ ['available' => 'green', 'reserved' => 'amber', 'occupied' => 'blue', 'out_of_order' => 'red', 'out_of_service' => 'red', 'leased' => 'slate', 'maintenance' => 'amber', 'house_use' => 'slate', 'group_hold' => 'blue', 'other_block' => 'slate'][$c['status']] }}">{{ strtoupper(str_replace('_', ' ', $c['status'])) }}</span>
              @if ($canViewHousekeeping)<div class="ws-hk-badge ws-hk-{{ $hkTone }}" title="Current housekeeping status"><span class="ws-hk-prefix">Current <span aria-hidden="true">HK</span><span class="sr-only">housekeeping</span>: </span>{{ $housekeepingLabel($room->hk_status) }}</div>@endif
              @if ($c['arriving_on_date'])<div class="ws-tag">ARRIVING ON THIS DATE</div>@endif
            </div>
          </div>
          <div class="ws-photo-foot">
            <span class="ws-photo-meta">
              <span><x-icon name="home"/>{{ $room->roomType?->size_sqm ? rtrim(rtrim(number_format($room->roomType->size_sqm, 1), '0'), '.').' m²' : ($room->roomType?->code ?? '—') }}</span>
              <span><x-icon name="user"/>{{ $room->roomType?->max_adults ?? 2 }} {{ \Illuminate\Support\Str::plural('guest', $room->roomType?->max_adults ?? 2) }}</span>
              <span><x-icon name="shield"/>{{ $room->is_smoking ? 'Smoking' : 'Non-smoking' }}</span>
            </span>
            @if (($c['rate'] ?? 0) > 0)
              <span class="ws-photo-rate">{{ money($c['rate']) }} <small>/ night</small></span>
            @else
              <span class="ws-photo-rate ws-rate-unset" title="No nightly rate is set on this room type — set one in Master Data">No rate</span>
            @endif
          </div>
        </div>
        <div class="ws-card-body">

        @if ($stay)
        {{-- v10.23 the card says what the room is and nothing else: who is in it, from when to when, and what it is
             worth are all in the dialog the card opens — and in the rack. What stays here is one sr-only sentence,
             so a screen reader still hears the guest, the stay and the money, and print — which has no dialog to
             open — puts that sentence on the sheet in place of the rows the screen gave up. --}}
        <span class="ws-guest-note sr-only">Guest {{ $stayGuestName }}{{ $roomSource ? ', '.$roomSource : '' }}{{ $isVip ? ', VIP' : '' }}{{ $stay->guest?->nationality ? ', '.$stay->guest->nationality : '' }}. Arrive {{ \Illuminate\Support\Carbon::parse($stay->arrival_date)->format($stayDateFormat($stay)) }}, depart {{ \Illuminate\Support\Carbon::parse($stay->departure_date)->format($stayDateFormat($stay)) }}, {{ $cardNights }} {{ $cardNights === 1 ? 'night' : 'nights' }} at {{ money($c['rate']) }} a night. @if ($cardDueLabel !== null) {{ $cardDueLabel }}. @endif</span>
        @else
        {{-- v10.25 an empty room used to get a block of its own — VACANT · RACK RATE, the rate again at 18px, and a
             Reserve Room button — which made every vacant card taller than its neighbours and left the occupied ones
             stretching to match. The state is the badge, the rate is the chip in the photo, and reserving is an
             action, so it belongs in the action row with the others. What remains here is the sentence a screen
             reader and the printed sheet use. --}}
        <span class="ws-guest-note sr-only">{{ $c['status'] === 'occupied' ? 'Occupied, with no linked stay on this date.' : ($c['status'] === 'available' ? 'Available, no guest on this date, '.money($c['rate']).' a night.' : 'Not sellable on this date.') }}</span>
        @endif

        <div class="ws-actions">
          @if ($canMoveRooms && $stay && in_array($stay->status, ['reserved', 'checked_in'], true))<button type="button" class="ws-drag-handle" draggable="true" data-stay="{{ $stay->id }}" data-room="{{ $room->id }}" data-room-no="{{ $room->room_no }}" data-label="{{ $stayGuestName }}" data-inhouse="{{ $stay->status === 'checked_in' ? '1' : '0' }}" aria-label="Change room for {{ $stayGuestName }}, currently room {{ $room->room_no }}. Drag onto a destination room, or press Enter to pick the stay up and then choose a room with the arrow keys." title="Change room — drag onto a destination room"><x-icon name="swap"/><span class="ws-act-label">Change room</span></button>@endif
          @if ($stay && $stay->status === 'reserved')
            @if ($isToday && $c['status'] === 'reserved' && $room->status === 'vacant' && ! isset($inventoryBlocks[$room->id]) && can_do('frontdesk.checkin'))<a class="btn btn-sm btn-primary" style="background:#16a34a" href="{{ route('frontdesk.checkin', $stay) }}" aria-label="Check in room {{ $room->room_no }}"><x-icon name="login"/> Check-In</a>
            @elseif ($isToday && ($c['status'] !== 'reserved' || $room->status !== 'vacant' || isset($inventoryBlocks[$room->id])) && can_do('frontdesk.checkin'))<span class="ws-checkin-blocker">Check-in blocked: {{ $checkInBlockReason($room, $inventoryBlocks) }}</span>@endif
            @if (can_do('reservations.view'))<a class="btn btn-sm ws-act-minor" href="{{ route('reservations.show', $stay->reservation_id) }}" aria-label="Reservation details for room {{ $room->room_no }}{{ $stay->reservation?->reservation_no ? ', '.$stay->reservation->reservation_no : '' }}">Reservation details</a>@endif
          @elseif ($stay && $stay->status === 'checked_in')
            @if ($isToday && can_do('frontdesk.checkout'))<a class="btn btn-sm" style="background:#ea580c;color:#fff" href="{{ route('frontdesk.checkout', $stay) }}" aria-label="Check out room {{ $room->room_no }}"><x-icon name="logout"/> Check-Out</a>@endif
            @if ($isToday && can_do('frontdesk.roommove'))
              @php $extendTo = $stay->departure_date->copy()->addDay()->max($businessDate->copy()->addDay()); @endphp
              <form method="POST" action="{{ route('frontdesk.amend', $stay) }}" class="ws-extend-form ws-act-minor" data-room-no="{{ $room->room_no }}" data-confirm="Extend the stay in room {{ $room->room_no }} through the selected departure date? Availability and stay charges will be rechecked.">
                @csrf
                <label class="sr-only" for="extend-{{ $stay->id }}">New departure date for room {{ $room->room_no }}</label>
                <input id="extend-{{ $stay->id }}" type="date" name="departure_date" min="{{ $extendTo->toDateString() }}" value="{{ $extendTo->toDateString() }}" required>
                <button type="submit" class="btn btn-sm" title="Extend this stay" aria-label="Extend stay in room {{ $room->room_no }}">Extend</button>
              </form>
            @endif
          @endif
          @if (! $isHistory && in_array($c['status'], ['available', 'reserved'], true) && can_do('housekeeping.manage') && $room->status === 'vacant' && $room->hk_status !== 'dirty')
            <form method="POST" action="{{ route('housekeeping.status') }}" class="ws-inline-action ws-act-minor" data-confirm="Mark room {{ $room->room_no }} dirty? This updates its current housekeeping status.">
              @csrf
              <input type="hidden" name="rooms[]" value="{{ $room->id }}">
              <input type="hidden" name="hk_status" value="dirty">
              <button type="submit" class="btn btn-sm btn-ghost" aria-label="Mark room {{ $room->room_no }} dirty" title="Mark current housekeeping status dirty"><x-icon name="broom"/> Mark Dirty</button>
            </form>
          @endif
          @if (! $stay && $c['status'] === 'available' && ! $isHistory && can_do('reservations.create'))
            <a class="btn btn-sm btn-primary ws-reserve" href="{{ route('reservations.create', ['room' => $room->id, 'arrival' => $date->toDateString(), 'departure' => $date->copy()->addDay()->toDateString()]) }}" aria-label="Reserve room {{ $room->room_no }} for {{ $date->format('j F Y') }}">Reserve</a>
          @endif
          <button type="button" class="btn btn-sm btn-ghost ws-all" data-room="{{ $room->id }}" aria-label="More actions for room {{ $room->room_no }}" aria-haspopup="dialog" aria-controls="roomModal{{ $room->id }}" aria-expanded="false">··· <span class="ws-act-label">All actions</span></button>
        </div>
        </div>
      </div>
    @empty
      <div class="card ws-empty"><div class="card-body"><span class="ws-empty-icon"><x-icon name="bed"/></span><div class="ws-empty-copy"><h2 class="ws-empty-title">No active rooms</h2><p class="muted small">There are no active rooms to show for this property.</p></div></div></div>
    @endforelse
    <div class="card ws-empty" id="wsNoResults" hidden><div class="card-body"><span class="ws-empty-icon"><x-icon name="search"/></span><div class="ws-empty-copy"><h2 class="ws-empty-title">No rooms match these filters</h2><p class="muted small">Clear the search or filters to see the full room board.</p><button type="button" class="btn btn-sm btn-ghost" id="clearWsFiltersEmpty">Clear search and filters</button></div></div></div>
  </div>

  {{-- ── Room rack: the same rooms as one dense table, for fast scanning ── --}}
  <section class="ws-rack" id="wsRack" data-hk-column="{{ $canViewHousekeeping ? '1' : '0' }}" role="region" aria-label="Room rack for {{ $date->format('j F Y') }}" hidden>
    <div class="ws-rack-scroll">
      <table class="ws-rack-table" id="wsRackTable">
        <caption class="sr-only">Every room on this date: status, guest, stay, rate, housekeeping and actions. The rows are built from the room board, so both views always agree.</caption>
        <thead>
          <tr>
            <th scope="col" data-col="room" aria-sort="ascending"><button type="button" class="ws-rack-sort" data-ws-sort="room">Room</button></th>
            <th scope="col" data-col="type"><button type="button" class="ws-rack-sort" data-ws-sort="type">Type · Floor</button></th>
            <th scope="col" data-col="status"><button type="button" class="ws-rack-sort" data-ws-sort="status">Status</button></th>
            <th scope="col" data-col="guest">Guest</th>
            <th scope="col" data-col="nights" class="num"><button type="button" class="ws-rack-sort" data-ws-sort="nights">Nights</button></th>
            <th scope="col" data-col="rate" class="num"><button type="button" class="ws-rack-sort" data-ws-sort="rate">Rate</button></th>
            <th scope="col" data-col="balance" class="num">Balance</th>
            @if ($canViewHousekeeping)<th scope="col" data-col="hk">HK</th>@endif
            <th scope="col" data-col="action">Action</th>
          </tr>
        </thead>
        <tbody id="wsRackBody"></tbody>
      </table>
    </div>
    <div class="card ws-empty" id="wsRackEmpty" hidden><div class="card-body"><span class="ws-empty-icon"><x-icon name="search"/></span><div class="ws-empty-copy"><h2 class="ws-empty-title">No rooms match these filters</h2><p class="muted small">Clear the search or filters to see the whole rack.</p><button type="button" class="btn btn-sm btn-ghost" id="clearWsFiltersRack">Clear search and filters</button></div></div></div>
  </section>
  </div>

  {{-- ── Right rail ── --}}
  <div class="ws-rail">
    <div class="card ws-rail-card ws-attention" id="wsAttention"><div class="card-body">
      <div class="ws-rail-title"><x-icon name="alert"/> NEEDS ATTENTION
        @if ($attentionCount > 0)<span class="ws-attention-count">{{ $attentionCount }}</span>@endif
      </div>
      @forelse ($attentionItems as $item)
        @if (! empty($item['url']))<a class="ws-attention-row tone-{{ $item['tone'] }}" href="{{ $item['url'] }}">
        @else<div class="ws-attention-row tone-{{ $item['tone'] }}">@endif
          <span class="ws-attention-n">{{ $item['count'] }}</span>
          <span class="ws-attention-copy"><b>{{ $item['label'] }}</b><span class="muted small">{{ $item['detail'] }}</span></span>
        @if (! empty($item['url']))</a>@else</div>@endif
      @empty
        <div class="ws-attention-clear"><x-icon name="check"/> <span class="ws-attention-copy"><b>All clear</b><span class="muted small">No unassigned arrivals, refused check-ins, held checkouts or rooms waiting for housekeeping.</span></span></div>
      @endforelse
    </div></div>

    <div class="card ws-rail-card"><div class="card-body">
      @php
        $m = $date->copy()->startOfMonth(); $days = $m->daysInMonth; $pad = ((int) $m->format('w') + 6) % 7;
        $prevMonthDate = $m->copy()->subDay();
        $nextMonthDate = $m->copy()->addMonth();
        $padCells = $pad > 0 ? range(1, $pad) : [];
        $monthDays = $days > 0 ? range(1, $days) : [];
      @endphp
      <div class="ws-cal-head">
        @if ($prevMonthDate->gte($dateMin))<a class="btn btn-sm btn-ghost" data-ws-nav href="{{ route('reservations.workspace', array_merge(request()->except('date'), ['date' => $prevMonthDate->toDateString()])) }}" aria-label="Previous month" title="Previous month">‹</a>@else<button type="button" class="btn btn-sm btn-ghost disabled" disabled aria-label="Previous month unavailable: date range limit" title="Date range limit">‹</button>@endif
        <div class="bold small" style="text-transform:uppercase">{{ $m->format('F Y') }}</div>
        @if ($nextMonthDate->lte($dateMax))<a class="btn btn-sm btn-ghost" data-ws-nav href="{{ route('reservations.workspace', array_merge(request()->except('date'), ['date' => $nextMonthDate->toDateString()])) }}" aria-label="Next month" title="Next month">›</a>@else<button type="button" class="btn btn-sm btn-ghost disabled" disabled aria-label="Next month unavailable: date range limit" title="Date range limit">›</button>@endif
      </div>
      <div class="ws-cal">
        @foreach ([['M', 'Monday'], ['T', 'Tuesday'], ['W', 'Wednesday'], ['T', 'Thursday'], ['F', 'Friday'], ['S', 'Saturday'], ['S', 'Sunday']] as [$initial, $weekday])<span class="ws-cal-d muted"><span aria-hidden="true">{{ $initial }}</span><span class="sr-only">{{ $weekday }}</span></span>@endforeach
        @foreach ($padCells as $padCell)<span></span>@endforeach
        @foreach ($monthDays as $day)
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
            @if ($inRange){{ $day }} @else
              <span aria-hidden="true">{{ $day }}</span><span class="sr-only">{{ \Illuminate\Support\Carbon::parse($ds)->format('l, j F Y') }}, outside selectable date range{{ $hasA ? ', arrivals' : '' }}{{ $hasD ? ', departures' : '' }}</span>
            @endif
            <span class="ws-cal-dots" aria-hidden="true">@if($hasA)<i class="dot-a"></i>@endif @if($hasD)<i class="dot-d"></i>@endif</span>
          @if ($inRange)</a>@else</span>@endif
        @endforeach
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
          @if ($statusFilterKey)<button type="button" class="ws-status-filter" data-ws-status-filter="{{ $statusFilterKey }}" aria-label="Filter room board to {{ strtolower($label) }} rooms ({{ $n }})" aria-pressed="{{ $selectedStatus === $statusFilterKey ? 'true' : 'false' }}">{{ $label }}</button>@else{{ $label }} @endif
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
        $freeRooms = WS::sortAssignable($freeRooms, $canViewHousekeeping);
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
            <span class="muted small">{{ $s->room?->room_no ? 'Rm '.$s->room->room_no : 'Unassigned' }} · {{ max(1, \Illuminate\Support\Carbon::parse($s->arrival_date)->diffInDays(\Illuminate\Support\Carbon::parse($s->departure_date))) }}n · {{ $s->reservation?->businessSource?->name ?? 'Direct' }} @if ($canViewHousekeeping && $s->room && $s->room->hk_status && ! in_array($s->room->hk_status, ['clean', 'inspected'], true)) · Current housekeeping: {{ $housekeepingLabel($s->room->hk_status) }} @endif</span></span>
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
            @if ($da !== null && can_do('folio.view')) · <b class="{{ $da > 0 ? 'ws-due' : 'muted' }}" title="{{ $s->status === 'checked_out' ? ($da < 0 ? 'Final folio credit' : 'Final folio balance') : ($da < 0 ? 'Estimated checkout credit, including nights and fixed charges still to post' : 'Estimated checkout amount, including nights and fixed charges still to post') }}">{{ $da > 0 ? money($da).' to settle' : ($da < 0 ? money(abs($da)).' credit' : 'Settled') }}</b>@endif
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

</div>{{-- /ws-screen: the room dialogs are fixed overlays and must not inherit its containing block --}}

{{-- Room detail modals --}}
@foreach ($cards as $c)
@php
  $room = $c['room']; $stay = $c['stay'];
  // the dialog is its own loop over the same cards: nothing may be assumed from
  // the board loop above it
  $cardPhoto = $c['photo'] ?? $room->roomType?->image;
  $stayGuestName = $guestDisplayName($stay);
  $stayGuestInitial = $guestInitial($stay);
  $guestId = $stay?->guest_id ?? $stay?->reservation?->guest_id;
  $modalGuestIsVip = (bool) ($stay?->guest?->is_vip || $stay?->reservation?->guest?->is_vip);
  // v10.15 the card folds these two away below 200px, so the dialog carries them: same conditions, same
  // forms. A narrow card gives up the second row; it does not give up the action.
  $modalCanMarkDirty = ! $isHistory && in_array($c['status'], ['available', 'reserved'], true)
      && can_do('housekeeping.manage') && $room->status === 'vacant' && $room->hk_status !== 'dirty';
  $modalCanExtend = $isToday && $stay && $stay->status === 'checked_in' && can_do('frontdesk.roommove');
  $modalExtendTo = $modalCanExtend ? $stay->departure_date->copy()->addDay()->max($businessDate->copy()->addDay()) : null;
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
  $maxAdults = $room->roomType?->max_adults ?? 2;
  $facts = [
    ['Size', $room->roomType?->size_sqm ? rtrim(rtrim(number_format($room->roomType->size_sqm, 1), '0'), '.').' m²' : ($room->roomType?->code ?? '—')],
    ['Max Occupancy', $maxAdults.' '.\Illuminate\Support\Str::plural('guest', $maxAdults)],
    ['Configuration', $room->roomType?->name ?? '—'],
    ['Smoking', $room->is_smoking ? 'Smoking' : 'Non-Smoking'],
  ];
  if ($canViewHousekeeping) $facts[] = ['Current housekeeping', $housekeepingLabel($room->hk_status)];
@endphp
<div class="modal ws-modal" id="roomModal{{ $room->id }}" role="dialog" aria-modal="true" aria-labelledby="roomModalTitle{{ $room->id }}" tabindex="-1"><div class="modal-box wide">
  <div class="ws-modal-hero" style="background:{{ $cardPhoto ? 'url('.$cardPhoto.') center/cover no-repeat, ' : '' }}linear-gradient(135deg, hsl({{ (($room->room_type_id ?? $room->id) * 47) % 360 }},32%,24%), hsl({{ (($room->room_type_id ?? $room->id) * 47 + 40) % 360 }},26%,38%))">
    <button type="button" class="btn btn-ghost btn-icon ws-modal-x" data-close aria-label="Close room details"><x-icon name="x"/></button>
    <div class="ws-modal-hero-row">
      <span class="ws-room-no huge">{{ $room->room_no }}</span>
      <div><h2 id="roomModalTitle{{ $room->id }}" class="bold ws-modal-title"><span class="sr-only">Room {{ $room->room_no }}: </span>{{ $room->roomType?->name ?? 'Room details' }} · {{ $room->roomType?->code ?? '' }}</h2>
        <div class="small" style="opacity:.85">{{ $room->floor?->name ? 'Floor '.$room->floor->name : '' }}{{ $room->roomType?->code ? ' · '.$room->roomType->name : '' }}</div></div>
      <div class="text-right"><span class="badge badge-{{ ['available' => 'green', 'reserved' => 'amber', 'occupied' => 'blue', 'out_of_order' => 'red', 'out_of_service' => 'red', 'leased' => 'slate', 'maintenance' => 'amber', 'house_use' => 'slate', 'group_hold' => 'blue', 'other_block' => 'slate'][$c['status']] }}">{{ strtoupper(str_replace('_', ' ', $c['status'])) }}</span>
        <div class="ws-photo-rate{{ ($c['rate'] ?? 0) > 0 ? '' : ' ws-rate-unset' }}" style="position:static;margin-top:6px" @if (($c['rate'] ?? 0) <= 0) title="No nightly rate is set on this room type — set one in Master Data" @endif>{{ ($c['rate'] ?? 0) > 0 ? money($c['rate']).' / night' : 'No rate set' }}</div></div>
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
        <tr><td class="muted">Rate</td><td class="bold">{{ ($c['rate'] ?? 0) > 0 ? money($c['rate']).'/n' : 'Not set' }}</td>@if (can_do('folio.view'))<td class="muted">Balance</td><td class="bold {{ ($c['balance'] ?? 0) > 0 ? 'ws-due' : '' }}">{{ $c['balance'] === null ? 'No folio' : ($c['balance'] > 0 ? money($c['balance']) : ($c['balance'] < 0 ? money(abs($c['balance'])).' in credit' : 'Settled')) }}</td>@else<td colspan="2"></td>@endif</tr>
        @if (can_do('folio.view') && ($c['due'] ?? null) !== null && abs(($c['due'] ?? 0) - ($c['balance'] ?? 0)) > 0.005)<tr><td class="muted">Estimated checkout</td><td class="bold ws-due" colspan="3">{{ money($c['due']) }} <span class="muted small">(includes nights and fixed charges still to post)</span></td></tr>@endif
        </tbody>
      </table>
    </div></div>@endif</div>
    @if ($modalCanExtend || $modalCanMarkDirty)
    <div class="ws-rail-title" style="margin-top:16px">ROOM ACTIONS</div>
    <div class="ws-modal-actions">
      @if ($modalCanExtend)
      <form method="POST" action="{{ route('frontdesk.amend', $stay) }}" class="ws-extend-form" data-room-no="{{ $room->room_no }}" data-confirm="Extend the stay in room {{ $room->room_no }} through the selected departure date? Availability and stay charges will be rechecked.">
        @csrf
        <label class="sr-only" for="modal-extend-{{ $stay->id }}">New departure date for room {{ $room->room_no }}</label>
        <input id="modal-extend-{{ $stay->id }}" type="date" name="departure_date" min="{{ $modalExtendTo->toDateString() }}" value="{{ $modalExtendTo->toDateString() }}" required>
        <button type="submit" class="btn btn-sm" title="Extend this stay" aria-label="Extend stay in room {{ $room->room_no }}">Extend stay</button>
      </form>
      @endif
      @if ($modalCanMarkDirty)
      <form method="POST" action="{{ route('housekeeping.status') }}" class="ws-inline-action" data-confirm="Mark room {{ $room->room_no }} dirty? This updates its current housekeeping status.">
        @csrf
        <input type="hidden" name="rooms[]" value="{{ $room->id }}">
        <input type="hidden" name="hk_status" value="dirty">
        <button type="submit" class="btn btn-sm btn-ghost" aria-label="Mark room {{ $room->room_no }} dirty" title="Mark current housekeeping status dirty"><x-icon name="broom"/> Mark dirty</button>
      </form>
      @endif
    </div>
    @endif
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
  function wsMatch(el) {
    const values = [status?.value || '', type?.value || '', floor?.value || '', source?.value || '', housekeeping?.value || ''];
    const vipSelected = Boolean(vipOnly?.checked);
    const queryText = normalizeSearchText(search?.value.trim() || '');
    const compactQuery = compactSearchText(queryText);
    const data = el.dataset || {};
    const matchesHousekeeping = !values[4] || (values[4] === 'ready'
      ? ['clean', 'inspected'].includes(data.hk)
      : data.hk === values[4]);
    const matchesFilters = (!values[0] || data.status === values[0])
      && (!values[1] || data.type === values[1])
      && (!values[2] || data.floor === values[2])
      && (!values[3] || data.source === values[3])
      && matchesHousekeeping
      && (!vipSelected || data.vip === '1');
    if (!matchesFilters) return false;
    const cardSearchText = normalizeSearchText(data.search || el.textContent);
    return !queryText || cardSearchText.includes(queryText)
      || (compactQuery && compactSearchText(cardSearchText).includes(compactQuery));
  }

  function applyWsFilters(announceDelay = 0) {
    const values = [status?.value || '', type?.value || '', floor?.value || '', source?.value || '', housekeeping?.value || ''];
    const vipSelected = Boolean(vipOnly?.checked);
    const queryText = normalizeSearchText(search?.value.trim() || '');
    const cards = [...document.querySelectorAll('#wsGrid .ws-card')];
    const rows = [...document.querySelectorAll('#wsRackBody tr')];
    const noResults = document.getElementById('wsNoResults');
    let visible = 0;
    cards.forEach(card => {
      card.hidden = !wsMatch(card);
      if (!card.hidden) visible++;
    });
    rows.forEach(row => { row.hidden = !wsMatch(row); });
    if (noResults) noResults.hidden = cards.length === 0 || visible > 0;
    const rackEmpty = document.getElementById('wsRackEmpty');
    if (rackEmpty) rackEmpty.hidden = !(rows.length > 0 && rows.every(row => row.hidden));
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
    updateGroups();
    renderChips();
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
      // moving through the month must not drop the layout or the grouping
      if (activeView === 'rack') targetParams.set('view', 'rack'); else targetParams.delete('view');
      if (groupMode) targetParams.set('group', groupMode); else targetParams.delete('group');
      target.search = targetParams.toString();
      link.href = target.pathname + target.search + target.hash;
    });
  }

  /* ── active filters as removable chips ───────────────────────────────── */
  function addChip(wrap, text, onRemove) {
    const chip = document.createElement('span');
    chip.className = 'ws-chip';
    const label = document.createElement('span');
    label.textContent = text;
    const button = document.createElement('button');
    button.type = 'button';
    button.setAttribute('aria-label', `Remove filter ${text}`);
    button.textContent = '×';
    button.addEventListener('click', onRemove);
    chip.append(label, button);
    wrap.appendChild(chip);
  }

  function renderChips() {
    const wrap = document.getElementById('wsChips');
    if (!wrap) return;
    wrap.textContent = '';
    [[status, 'Room status'], [type, 'Room type'], [floor, 'Floor'], [source, 'Source'], [housekeeping, 'Housekeeping']]
      .forEach(([element]) => {
        if (!element || !element.value) return;
        const option = element.options[element.selectedIndex];
        const text = (option ? option.textContent : element.value).trim();
        addChip(wrap, text, () => { element.value = ''; applyWsFilters(); element.focus(); });
      });
    if (vipOnly?.checked) addChip(wrap, 'Current VIP only', () => { vipOnly.checked = false; applyWsFilters(); vipOnly.focus(); });
    if (search?.value.trim()) addChip(wrap, `Search: ${search.value.trim()}`, () => { search.value = ''; applyWsFilters(); search.focus(); });
  }

  /* ── grouping: floor, room type or status ────────────────────────────── */
  const grid = document.getElementById('wsGrid');
  const rack = document.getElementById('wsRack');
  const groupSelect = document.getElementById('wsGroupBy');
  let groupMode = '';

  function updateGroups() {
    if (!groupMode || !grid) return;
    grid.querySelectorAll('.ws-group').forEach(group => {
      const shown = [...group.querySelectorAll('.ws-card')].filter(card => !card.hidden).length;
      const badge = group.querySelector('.ws-group-count');
      if (badge) badge.textContent = String(shown);
      group.hidden = shown === 0;
    });
  }

  function applyGrouping(mode) {
    groupMode = mode || '';
    syncUrlParam('group', groupMode);
    if (!grid) return;
    // read the cards before dropping their group wrappers, or they go with them
    const groupCards = [...grid.querySelectorAll('.ws-card')];
    const empties = [...grid.querySelectorAll(':scope > .ws-empty')];
    grid.querySelectorAll('.ws-group').forEach(group => group.remove());
    if (!groupMode) {
      groupCards.forEach(card => grid.appendChild(card));
      empties.forEach(node => grid.appendChild(node));
      return;
    }
    const key = groupMode === 'floor' ? 'floorName' : groupMode === 'type' ? 'typeName' : 'statusLabel';
    const buckets = new Map();
    groupCards.forEach(card => {
      const label = (card.dataset[key] || 'Unspecified').trim() || 'Unspecified';
      if (!buckets.has(label)) buckets.set(label, []);
      buckets.get(label).push(card);
    });
    [...buckets.keys()].sort((a, b) => a.localeCompare(b, undefined, { numeric: true })).forEach(label => {
      const section = document.createElement('section');
      section.className = 'ws-group';
      section.dataset.group = groupMode;
      const head = document.createElement('h3');
      head.className = 'ws-group-head';
      const title = document.createElement('span');
      title.textContent = label;
      const count = document.createElement('span');
      count.className = 'ws-group-count';
      count.textContent = String(buckets.get(label).length);
      head.append(title, count);
      const inner = document.createElement('div');
      inner.className = 'ws-group-grid';
      buckets.get(label).forEach(card => inner.appendChild(card));
      section.append(head, inner);
      grid.appendChild(section);
    });
    empties.forEach(node => grid.appendChild(node));
    updateGroups();
  }

  /* ── rack: the room board as one dense table ─────────────────────────── */
  function rackCell(main, sub) {
    const td = document.createElement('td');
    if (main) {
      const strong = document.createElement('b');
      strong.textContent = main;
      td.appendChild(strong);
    }
    if (sub) {
      const small = document.createElement('span');
      small.className = 'muted small';
      small.textContent = (main ? ' · ' : '') + sub;
      td.appendChild(small);
    }
    return td;
  }

  function buildRack() {
    const body = document.getElementById('wsRackBody');
    if (!body || body.dataset.built === '1') return;
    [...document.querySelectorAll('#wsGrid .ws-card')].forEach(card => {
      const roomNo = (card.querySelector('.ws-room-no')?.textContent || '').trim().replace(/^Room\s+/i, '');
      const roomId = card.querySelector('[data-room]')?.dataset.room || '';
      // v10.23 the card prints neither the guest nor the nights nor the money, so the rack takes all four from the
      // card's data rather than from markup that is no longer there.
      const guest = card.dataset.guest || '';
      const guestMeta = card.dataset.guestMeta || '';
      const nights = card.dataset.nights ? card.dataset.nights + 'n' : '';
      const rate = card.querySelector('.ws-photo-rate')?.textContent.trim() || '';
      const balance = card.dataset.due || '';
      // v10.17 the chip carries the words "Current HK: " in a span of its own, so the rack reads the state
      // from the chip's text minus that span: stripping a prefix by regex broke the moment the wording
      // changed, and the column started reading "housekeeping: Dirty".
      const hkBadge = card.querySelector('.ws-hk-badge');
      const hk = hkBadge
        ? [...hkBadge.childNodes]
          .filter(node => !(node.nodeType === 1 && node.classList && node.classList.contains('ws-hk-prefix')))
          .map(node => node.textContent).join('').replace(/\s+/g, ' ').trim()
        : '';
      const badge = card.querySelector('.ws-card-head .badge');
      const actions = card.querySelector('.ws-actions');
      const row = document.createElement('tr');
      ['status', 'type', 'floor', 'source', 'hk', 'vip', 'search', 'typeName', 'floorName', 'statusLabel', 'photo']
        .forEach(key => { row.dataset[key] = card.dataset[key] || ''; });
      row.dataset.room = roomId;
      row.dataset.rate = rate;
      row.dataset.nights = nights;

      const roomCell = document.createElement('td');
      const roomWrap = document.createElement('div');
      roomWrap.className = 'ws-rack-room';
      // the same picture the card shows, small enough for a table row
      if (card.dataset.photo) {
        const thumb = document.createElement('span');
        thumb.className = 'ws-rack-thumb';
        thumb.style.backgroundImage = `url(${card.dataset.photo})`;
        thumb.setAttribute('aria-hidden', 'true');
        roomWrap.appendChild(thumb);
      }
      const strong = document.createElement('b');
      strong.textContent = roomNo;
      // v10.20 below 520px the guest is no longer a column of its own: it moves under the room number, so the
      // row is a room with its guest rather than five columns squeezed into three. Wide racks ignore this line.
      const roomText = document.createElement('div');
      roomText.className = 'ws-rack-roomtext';
      roomText.appendChild(strong);
      const sub = document.createElement('span');
      sub.className = 'ws-rack-sub';
      sub.textContent = [guest || 'No guest', guestMeta].filter(Boolean).join(' · ');
      roomText.appendChild(sub);
      roomWrap.appendChild(roomText);
      if (roomId) {
        const open = document.createElement('button');
        open.type = 'button';
        open.className = 'ws-rack-open ws-room-open';
        open.dataset.room = roomId;
        open.textContent = 'Details';
        open.setAttribute('aria-label', `Open details for room ${roomNo}`);
        roomWrap.appendChild(open);
      }
      roomCell.appendChild(roomWrap);
      roomCell.dataset.col = 'room';
      row.appendChild(roomCell);

      const typeCell = rackCell(card.dataset.typeName || '—', card.dataset.floorName || '');
      typeCell.dataset.col = 'type';
      row.appendChild(typeCell);
      const statusCell = document.createElement('td');
      statusCell.dataset.col = 'status';
      if (badge) statusCell.appendChild(badge.cloneNode(true));
      row.appendChild(statusCell);

      const guestCell = document.createElement('td');
      const guestWrap = document.createElement('div');
      guestWrap.className = 'ws-rack-guest';
      const guestName = document.createElement('b');
      guestName.textContent = guest || 'No guest';
      guestWrap.appendChild(guestName);
      if (guestMeta) {
        const meta = document.createElement('span');
        meta.textContent = guestMeta;
        guestWrap.appendChild(meta);
      }
      guestCell.appendChild(guestWrap);
      guestCell.dataset.col = 'guest';
      row.appendChild(guestCell);

      const nightsCell = document.createElement('td');
      nightsCell.className = 'num';
      nightsCell.dataset.col = 'nights';
      nightsCell.textContent = nights || '—';
      row.appendChild(nightsCell);

      const rateCell = document.createElement('td');
      rateCell.className = 'num';
      rateCell.dataset.col = 'rate';
      rateCell.textContent = rate.replace(/\s*\/\s*night$/i, '') || '—';
      row.appendChild(rateCell);

      const balanceCell = document.createElement('td');
      balanceCell.className = 'num';
      balanceCell.dataset.col = 'balance';
      balanceCell.textContent = balance || '—';
      row.appendChild(balanceCell);

      if (document.getElementById('wsRack')?.dataset.hkColumn === '1') {
        const hkCell = document.createElement('td');
        hkCell.dataset.col = 'hk';
        hkCell.textContent = hk || '—';
        row.appendChild(hkCell);
      }

      const actionCell = document.createElement('td');
      actionCell.dataset.col = 'action';
      const actionWrap = document.createElement('div');
      actionWrap.className = 'ws-rack-actions';
      [...(actions?.children || [])].forEach(node => {
        // links and plain buttons come across as they are; the form-wrapped ones
        // (extend, mark dirty) have to be copied with their form, or the rack would
        // silently lose every action that posts
        if (node.matches('a.btn, button.btn')) {
          if (!node.matches('.ws-all')) {
            const copy = node.cloneNode(true);
            // v10.19 the card folds its secondary controls below 380/250px; the rack copied them all into
            // one cell, where they wrap into a column three lines deep. They keep their place at full width
            // and retreat with the card's own rule — the room dialog still carries every one of them.
            if (node.matches('.ws-act-minor')) copy.classList.add('ws-rack-extra');
            actionWrap.appendChild(copy);
          }
        } else if (node.matches('form')) {
          const copy = node.cloneNode(true);
          // the board keeps its ids; a second copy in the same page may not
          copy.querySelectorAll('label[for]').forEach(label => {
            const control = copy.querySelector(`[id="${label.getAttribute('for')}"]`);
            const text = label.textContent.trim();
            if (control && text) control.setAttribute('aria-label', text);
            label.removeAttribute('for');
            if (!label.querySelector('input,select,textarea')) label.remove();
          });
          copy.querySelectorAll('[id]').forEach(element => element.removeAttribute('id'));
          copy.classList.add('ws-rack-extra');
          actionWrap.appendChild(copy);
        }
      });
      if (!actionWrap.children.length) {
        const fallback = document.createElement('span');
        fallback.className = 'muted small';
        fallback.textContent = '—';
        actionWrap.appendChild(fallback);
      }
      actionCell.appendChild(actionWrap);
      row.appendChild(actionCell);

      body.appendChild(row);
    });
    body.dataset.built = '1';
    if (rackSort) applyRackSort(rackSort.key, rackSort.dir);
  }

  /* The chosen order survives a refresh, like the chosen layout. */
  let rackSort = null;
  try {
    const saved = sessionStorage.getItem('wsSort') || '';
    const [savedKey, savedDir] = saved.split(':');
    if (savedKey && (savedDir === 'asc' || savedDir === 'desc')) rackSort = { key: savedKey, dir: savedDir };
  } catch (error) { /* private mode */ }

  /* v10.18 the column a rack is sorted by may not be on the screen: nights and balance go first at 840px,
     then type and housekeeping at 720, then rate at 520. The order is therefore stated in the toolbar, and
     the statement is the control — activate it to reverse, or clear it to go back to room order. */
  function renderSortState() {
    const chip = document.getElementById('wsRackSortState');
    if (!chip) return;
    chip.textContent = '';
    const inRack = activeView === 'rack';
    if (!rackSort || !inRack) { chip.hidden = true; return; }
    const source = document.querySelector(`.ws-rack-sort[data-ws-sort="${rackSort.key}"]`);
    const label = (source?.textContent || rackSort.key).trim();
    const arrow = rackSort.dir === 'asc' ? '↑' : '↓';
    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'ws-sortstate-toggle';
    toggle.textContent = `Sorted: ${label} ${arrow}`;
    toggle.setAttribute('aria-label', `Sorted by ${label}, ${rackSort.dir === 'asc' ? 'ascending' : 'descending'}. Activate to reverse the order.`);
    toggle.addEventListener('click', () => applyRackSort(rackSort.key, rackSort.dir === 'asc' ? 'desc' : 'asc'));
    const clear = document.createElement('button');
    clear.type = 'button';
    clear.className = 'ws-sortstate-clear';
    clear.textContent = 'Room order';
    clear.setAttribute('aria-label', 'Clear the sort and go back to room order');
    clear.addEventListener('click', () => applyRackSort(null, null));
    chip.append(toggle, clear);
    chip.hidden = false;
  }

  function applyRackSort(key, dir) {
    rackSort = key ? { key, dir } : null;
    document.querySelectorAll('.ws-rack-table th').forEach(cell => cell.removeAttribute('aria-sort'));
    if (rackSort) {
      const header = document.querySelector(`.ws-rack-sort[data-ws-sort="${rackSort.key}"]`)?.closest('th');
      header?.setAttribute('aria-sort', rackSort.dir === 'asc' ? 'ascending' : 'descending');
      sortRack(rackSort.key, rackSort.dir);
    }
    try {
      if (rackSort) sessionStorage.setItem('wsSort', `${rackSort.key}:${rackSort.dir}`);
      else sessionStorage.removeItem('wsSort');
    } catch (error) { /* private mode */ }
    renderSortState();
  }

  function sortRack(key, dir) {
    const body = document.getElementById('wsRackBody');
    if (!body) return;
    const rows = [...body.querySelectorAll('tr')];
    const numeric = key === 'rate' || key === 'nights';
    const value = row => {
      if (key === 'room') return row.querySelector('b')?.textContent.trim() || '';
      if (key === 'type') return row.dataset.typeName || '';
      if (key === 'status') return row.dataset.statusLabel || '';
      if (key === 'rate') return Number((row.dataset.rate || '').replace(/[^\d.]/g, '')) || 0;
      return Number((row.dataset.nights || '').replace(/[^\d.]/g, '')) || 0;
    };
    rows.sort((a, b) => {
      const first = value(a);
      const second = value(b);
      const order = numeric ? first - second : String(first).localeCompare(String(second), undefined, { numeric: true });
      return dir === 'desc' ? -order : order;
    });
    rows.forEach(row => body.appendChild(row));
  }

  /* ── the two layouts ─────────────────────────────────────────────────── */
  // A board or rack worth looking at is worth linking to, so the layout and the
  // grouping ride in the address bar beside the filters (and follow date changes).
  function syncUrlParam(key, value) {
    const params = new URLSearchParams(window.location.search);
    if (value) params.set(key, value); else params.delete(key);
    const query = params.toString();
    history.replaceState(null, '', window.location.pathname + (query ? '?' + query : '') + window.location.hash);
  }

  let activeView = 'board';
  function setView(next) {
    activeView = next === 'rack' ? 'rack' : 'board';
    const isRack = activeView === 'rack';
    if (isRack) buildRack();
    if (grid) grid.hidden = isRack;
    if (rack) rack.hidden = !isRack;
    document.querySelectorAll('[data-ws-view]').forEach(button => {
      const on = button.dataset.wsView === activeView;
      button.classList.toggle('is-on', on);
      button.setAttribute('aria-selected', String(on));
      button.tabIndex = on ? 0 : -1;
    });
    if (groupSelect) groupSelect.disabled = isRack;
    renderSortState();
    syncUrlParam('view', isRack ? 'rack' : '');
    applyWsFilters();
    try { sessionStorage.setItem('wsView', activeView); } catch (error) { /* private mode */ }
  }

  document.querySelectorAll('[data-ws-view]').forEach(button => {
    button.addEventListener('click', () => setView(button.dataset.wsView));
  });
  groupSelect?.addEventListener('change', () => {
    applyGrouping(groupSelect.value);
    applyWsFilters();
  });
  document.querySelectorAll('.ws-rack-sort').forEach(button => {
    button.addEventListener('click', () => {
      // a new column starts ascending; the same column again reverses
      const key = button.dataset.wsSort;
      applyRackSort(key, rackSort?.key === key && rackSort.dir === 'asc' ? 'desc' : 'asc');
    });
  });
  document.addEventListener('click', event => {
    const trigger = event.target.closest('.ws-room-open');
    if (trigger) {
      event.preventDefault();
      openRoomModal(trigger.dataset.room, trigger);
    }
  });

  /* ── keyboard: / search, B board, R rack, G group ────────────────────── */
  document.addEventListener('keydown', event => {
    if (event.defaultPrevented || event.metaKey || event.ctrlKey || event.altKey) return;
    const tag = String(event.target?.tagName || '').toLowerCase();
    const typing = tag === 'input' || tag === 'select' || tag === 'textarea' || event.target?.isContentEditable;
    if (typing) {
      // Escape clears the search first, so a half-typed filter never has to be
      // deleted by hand; a second Escape leaves the field.
      if (event.key === 'Escape') {
        if (event.target === search && search.value) {
          event.preventDefault();
          search.value = '';
          applyWsFilters();
        } else {
          event.target.blur();
        }
      }
      return;
    }
    if (event.key === '/') {
      event.preventDefault();
      search?.focus();
    } else if (event.key === 'b' || event.key === 'B') {
      setView('board');
    } else if (event.key === 'r' || event.key === 'R') {
      setView('rack');
    } else if (event.key === 'g' || event.key === 'G') {
      if (!groupSelect || groupSelect.disabled) return;
      const options = [...groupSelect.options];
      const index = (groupSelect.selectedIndex + 1) % options.length;
      groupSelect.selectedIndex = index;
      applyGrouping(groupSelect.value);
      applyWsFilters();
    }
  });

  const opening = new URLSearchParams(window.location.search);
  // The server renders the filters from the address bar; seeding them here as well
  // keeps a pasted link honest, and an invalid value clears the filter instead of
  // quietly emptying the board.
  [[status, 'status'], [type, 'type'], [floor, 'floor'], [source, 'source'], [housekeeping, 'hk']]
    .forEach(([element, key]) => {
      if (!element || !opening.has(key)) return;
      const wanted = opening.get(key) || '';
      element.value = [...element.options].some(option => option.value === wanted) ? wanted : '';
    });
  if (vipOnly && opening.get('vip') === '1') vipOnly.checked = true;
  if (search && opening.get('q')) search.value = opening.get('q');

  let storedView = 'board';
  try { storedView = sessionStorage.getItem('wsView') === 'rack' ? 'rack' : 'board'; } catch (error) { /* private mode */ }
  if (opening.get('view') === 'rack' || opening.get('view') === 'board') storedView = opening.get('view');
  const openingGroup = ['floor', 'type', 'status'].includes(opening.get('group') || '') ? opening.get('group') : null;
  if (openingGroup && groupSelect) groupSelect.value = openingGroup;
  setView(storedView);
  if (groupSelect) applyGrouping(groupSelect.value);
  applyWsFilters();

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
  document.querySelectorAll('#clearWsFilters, #clearWsFiltersEmpty, #clearWsFiltersRack').forEach(button => button.addEventListener('click', clearWsFilters));
  document.getElementById('refreshWsBoard')?.addEventListener('click', () => window.location.reload());
  // Delegated, in the capture phase: the rack's action forms are copies of the
  // board's, so only a listener on the document can reach them — and it has to run
  // before the page's confirm interceptor reads data-confirm.
  document.addEventListener('submit', event => {
    const form = event.target;
    if (!(form instanceof Element)) return;
    if (form.classList.contains('ws-extend-form')) {
      const departureDate = form.querySelector('[name="departure_date"]')?.value;
      if (departureDate) form.dataset.confirm = `Extend the stay in room ${form.dataset.roomNo} through ${departureDate}? Availability and stay charges will be rechecked.`;
    } else if (form.classList.contains('ws-assign')) {
      const selectedRoom = form.querySelector('.ws-assign-select')?.selectedOptions?.[0]?.textContent?.trim().replace(/\s+/g, ' ');
      if (selectedRoom) form.dataset.confirm = `Assign ${selectedRoom} to ${form.dataset.guestName}? Room availability will be rechecked before saving.`;
    }
  }, true);
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

  /* ── change room: drag a stay onto a destination room card ─────────────────
     Same endpoint as the tape chart (frontdesk.tapechart.drop): reserved stays are re-placed, in-house
     moves mark the current room vacant and dirty. The server re-checks clashes and outages under a lock,
     so a drop that looked legal a second ago still gets a clean message instead of a broken board.
     Keyboard: Enter/Space on the handle picks the stay up, arrows walk the visible cards (with up/down
     jumping a row at the grid's measured column count), Enter/Space drops, Escape cancels. */
  const moveStatus = document.getElementById('wsActionStatus');
  let moveDrag = null;
  let moveKeyboard = false;
  const setMoveStatus = message => { if (moveStatus) moveStatus.textContent = message; };
  const moveDestinations = () => [...document.querySelectorAll('#wsGrid .ws-card[data-drop-room]:not([hidden])')]
    .filter(card => !moveDrag || card.dataset.dropRoom !== String(moveDrag.room));
  const clearMoveState = () => {
    document.body.classList.remove('dragging');
    document.querySelectorAll('.ws-card.drop-over').forEach(card => card.classList.remove('drop-over'));
    document.querySelectorAll('.ws-card.ws-drop-source').forEach(card => card.classList.remove('ws-drop-source'));
    document.querySelectorAll('.ws-card.ws-kbd-dest').forEach(card => { card.classList.remove('ws-kbd-dest'); card.removeAttribute('tabindex'); });
    document.querySelectorAll('.ws-drag-handle.ws-source-selected').forEach(handle => handle.classList.remove('ws-source-selected'));
    moveKeyboard = false;
  };
  const cancelMove = message => { moveDrag = null; clearMoveState(); setMoveStatus(message); };
  const completeMoveDrop = card => {
    if (!moveDrag || !card) return;
    const selected = moveDrag;
    const destinationNo = card.dataset.roomNo;
    moveDrag = null;
    clearMoveState();
    if (card.dataset.dropRoom === String(selected.room)) {
      setMoveStatus(`${selected.label} is already in room ${destinationNo}.`);
      return;
    }
    const inhouse = selected.inhouse === '1';
    const verb = inhouse ? 'Move in-house guest' : 'Assign';
    const impact = inhouse ? '\nThe current room will be marked vacant and dirty.' : '';
    if (!confirm(`${verb} ${selected.label} to room ${destinationNo}?${impact}`)) {
      setMoveStatus('Room change cancelled.');
      return;
    }
    setMoveStatus(`Updating room for ${selected.label}…`);
    fetch(@json(route('frontdesk.tapechart.drop')), {
      method: 'POST',
      headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content},
      body: JSON.stringify({stay_id: Number(selected.stay), room_id: Number(card.dataset.dropRoom)})
    }).then(response => response.json().then(data => ({ok: response.ok, data})))
      .then(({ok, data}) => {
        if (ok) { setMoveStatus(data.message || 'Room updated.'); location.reload(); }
        else setMoveStatus(data.message || Object.values(data.errors || {})[0] || 'The stay could not be moved to that room.');
      })
      .catch(() => setMoveStatus('Network error. The room was not changed; please try again.'));
  };
  document.querySelectorAll('.ws-drag-handle[data-stay]').forEach(handle => {
    handle.addEventListener('dragstart', event => {
      moveDrag = handle.dataset;
      moveKeyboard = false;
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('text/plain', handle.dataset.stay);
      document.body.classList.add('dragging');
      handle.closest('.ws-card')?.classList.add('ws-drop-source');
      setMoveStatus(`Moving ${handle.dataset.label}. Drop it on a destination room.`);
    });
    handle.addEventListener('dragend', () => { if (moveDrag) cancelMove('Move cancelled.'); });
    handle.addEventListener('keydown', event => {
      if (!['Enter', ' '].includes(event.key)) return;
      event.preventDefault();
      if (moveDrag && moveDrag.stay === handle.dataset.stay) { cancelMove('Move cancelled.'); return; }
      if (moveDrag) return;
      moveDrag = handle.dataset;
      moveKeyboard = true;
      handle.classList.add('ws-source-selected');
      handle.closest('.ws-card')?.classList.add('ws-drop-source');
      document.body.classList.add('dragging');
      const destinations = moveDestinations();
      destinations.forEach(card => { card.setAttribute('tabindex', '0'); card.classList.add('ws-kbd-dest'); });
      setMoveStatus(`Selected ${handle.dataset.label}. Use the arrow keys to choose a destination room and press Enter; press Escape to cancel.`);
      destinations[0]?.focus();
    });
  });
  document.querySelectorAll('#wsGrid .ws-card[data-drop-room]').forEach(card => {
    card.addEventListener('dragover', event => {
      if (!moveDrag) return;
      if (card.dataset.dropRoom === String(moveDrag.room)) { if (event.dataTransfer) event.dataTransfer.dropEffect = 'none'; return; }
      event.preventDefault();
      event.dataTransfer.dropEffect = 'move';
      card.classList.add('drop-over');
    });
    card.addEventListener('dragleave', event => { if (!card.contains(event.relatedTarget)) card.classList.remove('drop-over'); });
    card.addEventListener('drop', event => {
      event.preventDefault();
      card.classList.remove('drop-over');
      if (!moveDrag) return;
      if (card.dataset.dropRoom === String(moveDrag.room)) { cancelMove(`${moveDrag.label} is already in room ${card.dataset.roomNo}.`); return; }
      completeMoveDrop(card);
    });
    card.addEventListener('keydown', event => {
      if (!moveDrag || !moveKeyboard) return;
      if (!['Enter', ' '].includes(event.key)) return;
      event.preventDefault();
      completeMoveDrop(card);
    });
  });
  document.addEventListener('keydown', event => {
    if (!moveDrag || !moveKeyboard) return;
    if (event.key === 'Escape') { event.preventDefault(); cancelMove('Move cancelled.'); return; }
    if (!['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(event.key)) return;
    const destinations = moveDestinations();
    if (!destinations.length) return;
    const gridEl = document.getElementById('wsGrid');
    const columns = Math.max(1, Math.floor(getComputedStyle(gridEl).gridTemplateColumns.split(' ').filter(Boolean).length));
    const delta = {ArrowLeft: -1, ArrowRight: 1, ArrowUp: -columns, ArrowDown: columns}[event.key];
    const index = destinations.indexOf(document.activeElement?.closest?.('#wsGrid .ws-card'));
    if (index < 0) return;
    event.preventDefault();
    const next = destinations[index + delta];
    if (next) next.focus();
  });

  applyWsFilters();
})();
</script>
@endpush

@push('head')
<style>
.ws-screen{container-type:inline-size;container-name:wsscreen}
.ws-head{position:relative;isolation:isolate;display:flex;justify-content:space-between;align-items:flex-end;gap:16px;overflow:hidden;margin:2px 0 12px;padding:16px 18px;border:1px solid rgba(20,83,45,.12);border-radius:14px;color:#fff;background:radial-gradient(circle at 86% 5%,rgba(191,235,201,.2),transparent 27%),linear-gradient(118deg,#103a23,#176239 66%,#247849);box-shadow:0 12px 28px rgba(20,83,45,.12)}
.ws-head:after{position:absolute;z-index:-1;right:11%;bottom:-76px;width:200px;height:200px;border:1px solid rgba(255,255,255,.12);border-radius:50%;content:"";pointer-events:none}
.ws-head-main{position:relative;z-index:1;flex:1;min-width:0}
.ws-eyebrow{display:flex;align-items:center;gap:6px;margin-bottom:5px;color:#c8e8d0;font-size:10px;font-weight:800;letter-spacing:.12em;text-transform:uppercase}
.ws-eyebrow .i{width:14px;height:14px}
.ws-title{font-size:clamp(22px,2.2vw,27px);font-weight:820;letter-spacing:-.04em;line-height:1.1;margin:0;color:#fff}
.ws-head .ws-date-nav{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:11px;color:rgba(255,255,255,.82)}
.ws-head .ws-date-nav .btn{min-height:30px;padding:4px 9px;border:1px solid rgba(255,255,255,.22);border-radius:7px;background:rgba(255,255,255,.09);color:#fff;text-decoration:none}
.ws-head .ws-date-nav a.btn:hover,.ws-head .ws-date-nav a.btn:focus-visible{border-color:rgba(255,255,255,.42);background:rgba(255,255,255,.18);color:#fff}
.ws-head .ws-date-nav .disabled{opacity:.55;color:rgba(255,255,255,.7)}
.ws-head .ws-date-nav .disabled:hover{background:rgba(255,255,255,.06)}
.ws-date-jump-label{font-size:11px;color:rgba(255,255,255,.75);margin-left:4px}
.ws-date-jump{width:132px;min-height:30px;padding:4px 6px;border:1px solid rgba(255,255,255,.35);border-radius:7px;background:#fff;color:#24452f;font-size:12px}
.ws-head .ws-head-context{margin-left:3px;border:1px solid rgba(255,255,255,.24);background:rgba(255,255,255,.13);color:#fff}
.ws-head .ws-head-context.badge-green{background:rgba(187,247,208,.18);color:#eaffef}
.ws-head .ws-head-context.badge-blue{background:rgba(191,219,254,.16);color:#eef5ff}
.ws-head .ws-head-context.badge-slate{background:rgba(226,232,240,.15);color:#f3f6f8}
.ws-history-note{margin-top:6px;max-width:760px;color:rgba(255,255,255,.72)}
.ws-head-side{position:relative;z-index:1;display:flex;align-items:flex-end;justify-content:flex-end}
.ws-head-snapshot{display:inline-flex;align-items:center;gap:6px;padding:7px 9px;border:1px solid rgba(255,255,255,.2);border-radius:9px;background:rgba(255,255,255,.08);color:rgba(255,255,255,.8);font-size:10px;font-weight:650;white-space:nowrap}
.ws-head-snapshot .i{width:13px;height:13px;color:#c8e8d0}
.ws-head-snapshot time{color:#fff;font-variant-numeric:tabular-nums;font-weight:800}
.ws-photo-link:focus-visible{outline:3px solid #fff;outline-offset:-4px;box-shadow:inset 0 0 0 5px rgba(21,128,61,.85)}
.ws-extend-form .sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
.ws-kpis{display:grid;grid-template-columns:repeat(var(--ws-kpi-count),minmax(0,1fr));gap:9px;margin-bottom:8px}
.ws-revenue-note{margin:0 0 12px 2px}
.ws-kpi{position:relative;display:flex;min-width:0;min-height:132px;flex-direction:column;justify-content:space-between;overflow:hidden;padding:11px 12px 12px;border:1px solid #e2e8f0;border-radius:12px;background:linear-gradient(150deg,#fff 72%,#f3faf5);box-shadow:0 2px 7px rgba(15,23,42,.035)}
.ws-kpi:before{position:absolute;inset:0 auto 0 0;width:3px;background:linear-gradient(180deg,#16a34a,#86efac);content:""}
.ws-kpi>.flex{justify-content:flex-end;min-height:18px}
.ws-kpi-delta{display:inline-flex;align-items:center;min-height:19px;padding:2px 7px;border-radius:999px;background:#f1f5f9;font-size:10px;font-variant-numeric:tabular-nums;font-weight:750;line-height:1}
.ws-kpi-delta.up{background:#ecfdf3;color:#15803d}.ws-kpi-delta.down{background:#fff1f2;color:#dc2626}.ws-kpi-delta.neutral{background:#f1f5f9;color:#64748b}
.ws-spark{width:100%;height:22px;display:block;margin:2px 0;overflow:visible}
.ws-kpi-value{overflow:hidden;font-size:clamp(17px,1.45vw,21px);font-variant-numeric:tabular-nums;font-weight:800;letter-spacing:-.035em;line-height:1.15;text-overflow:ellipsis;white-space:nowrap}
.ws-kpi-label{font-size:9px;font-weight:750;letter-spacing:.055em;line-height:1.3;text-transform:uppercase;color:#64748b}
a.ws-kpi{transition:transform .16s ease,border-color .16s ease,box-shadow .16s ease}
a.ws-kpi:hover{transform:translateY(-2px);border-color:#86c79b;box-shadow:0 8px 18px rgba(20,83,45,.1)}
a.ws-kpi:focus-visible{z-index:1;outline:3px solid #15803d;outline-offset:2px}
.ws-filters{display:flex;gap:8px;align-items:center;margin-bottom:14px;flex-wrap:wrap;padding:10px;border:1px solid #e2e8f0;border-radius:12px;background:#fff;box-shadow:0 2px 8px rgba(15,23,42,.035)}
.ws-filters>span:first-child{color:#14532d;font-size:10px;font-weight:850;letter-spacing:.11em}
.ws-filter-count{margin-left:auto;white-space:nowrap;font-variant-numeric:tabular-nums}
.ws-select{max-width:220px}
.ws-filters .ws-select{flex:0 1 165px;min-width:135px;max-width:205px;min-height:36px;border-radius:8px;font-size:12px}
.ws-vip-filter{display:inline-flex;align-items:center;gap:6px;min-height:36px;padding:0 9px;border:1px solid #cbd5e1;border-radius:8px;font-size:12px;white-space:nowrap;cursor:pointer}
.ws-vip-filter:focus-within{border-color:#15803d;box-shadow:0 0 0 3px rgba(21,128,61,.12)}
.ws-vip-filter input{width:15px;height:15px;accent-color:var(--c-primary)}
.ws-search{width:230px;max-width:min(100%,260px)}
.ws-filters .ws-search{flex:1 1 230px;width:auto;min-width:min(100%,220px);max-width:none;min-height:36px;border-radius:8px}
.ws-search:focus{border-color:#15803d;box-shadow:0 0 0 3px rgba(21,128,61,.12)}
.ws-body{display:grid;grid-template-columns:1fr;gap:12px;align-items:start}
.ws-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px;align-items:start}
/* v10.13 the board is read across, not down: a front desk scans the house by state, and two-and-a-bit cards
   to a row meant scrolling past two thirds of it to find the one dirty room. Six across, then five, four,
   three, two and one — and the card was cut to that size in the same round, because a wide grid of full-size
   cards only makes shorter ones. The right-hand rail (needs attention, calendar, arrivals, departures and the
   rest) now lays out full-width under the board, so the board gets the whole page; the ladders below are drawn
   against the board's own width and re-run against the screen's width as a fallback.

   v10.14 the count is the board's own width, not the window's — the sidebar is all that competes for width
   once the rail sits underneath. The ladder below is a fallback for browsers without container queries; where
   they are understood the same ladder is re-run against .ws-main's width, which is the only number that
   decides how wide a card actually is.

   The thresholds are card widths written backwards: six across needs a board of 1320px to leave each card
   205px ((1320 − 5×12) / 6), five needs 1100, four needs 880, three needs 660, two needs 440. Seven across
   starts at 1550px and eight at 1770px. */
@media (max-width:1599px){.ws-grid{grid-template-columns:repeat(5,minmax(0,1fr))}}
@media (max-width:1339px){.ws-grid{grid-template-columns:repeat(4,minmax(0,1fr))}}
@media (max-width:1079px){.ws-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media (max-width:819px){.ws-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:559px){.ws-grid{grid-template-columns:minmax(0,1fr)}}
@container wsboard (max-width:1319px){.ws-grid{grid-template-columns:repeat(5,minmax(0,1fr))}}
@container wsboard (max-width:1097px){.ws-grid{grid-template-columns:repeat(4,minmax(0,1fr))}}
@container wsboard (max-width:875px){.ws-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
@container wsboard (max-width:653px){.ws-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@container wsboard (max-width:431px){.ws-grid{grid-template-columns:minmax(0,1fr)}}
/* a board wider than six cards of 205px gets a seventh and an eighth rather than six fat ones: the point of
   the row was density, and density is what a 2560px screen would otherwise lose. */
@container wsboard (min-width:1550px){.ws-grid{grid-template-columns:repeat(7,minmax(0,1fr))}}
@container wsboard (min-width:1770px){.ws-grid{grid-template-columns:repeat(8,minmax(0,1fr))}}
.ws-card{position:relative;display:flex;min-width:0;flex-direction:column;overflow:hidden;container-type:inline-size;container-name:wscard;border:1px solid #e2e8f0;border-top:3px solid var(--ws-status-accent,#94a3b8);border-radius:12px;background:#fff;box-shadow:0 2px 8px rgba(15,23,42,.05);transition:box-shadow .16s ease,border-color .16s ease,transform .16s ease}
.ws-card:hover{border-color:#cbd5e1;box-shadow:0 8px 20px rgba(15,23,42,.10);transform:translateY(-1px)}
.ws-card:focus-within{outline:2px solid #86c79b;outline-offset:2px}
.ws-card[data-status="available"]{--ws-status-accent:#16a34a}
.ws-card[data-status="reserved"]{--ws-status-accent:#f59e0b}
.ws-card[data-status="occupied"]{--ws-status-accent:#3b82f6}
.ws-card[data-status="out_of_order"],.ws-card[data-status="out_of_service"]{--ws-status-accent:#ef4444}
.ws-card[data-status="maintenance"]{--ws-status-accent:#d97706}
.ws-card[data-status="group_hold"]{--ws-status-accent:#2563eb}
.ws-card[data-status="leased"],.ws-card[data-status="house_use"],.ws-card[data-status="other_block"]{--ws-status-accent:#64748b}
.ws-amen-rail{position:absolute;z-index:2;top:0;bottom:0;left:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;padding:5px 3px;background:linear-gradient(90deg,rgba(15,23,42,.58),rgba(15,23,42,.26) 70%,rgba(15,23,42,0))}
.ws-amen-rail>span{display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;flex:0 0 16px;border-radius:50%;background:rgba(15,23,42,.42);color:#fff}
.ws-amen-rail .i{width:11px;height:11px}
.ws-amen-rail .ws-amen-more{display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;flex:0 0 16px;padding:0;border:1px solid rgba(255,255,255,.55);border-radius:50%;background:rgba(255,255,255,.92);color:#14532d;font-size:8px;font-weight:800;line-height:1;cursor:pointer}
.ws-amen-rail .ws-amen-more:hover{background:#fff}
/* the rail owns 22px of the photo's left edge, so the head and the foot start to the right of it */
.ws-card-head{position:relative;z-index:2;display:flex;justify-content:space-between;gap:6px;padding:8px 9px 0 24px;min-height:0;color:#fff}
.ws-card-head>.flex{min-width:0;align-items:flex-start}
.ws-card-head>.flex>div{min-width:0}
.ws-card-head .text-right{max-width:44%;flex:0 0 auto}
/* v10.25 at five across the type name had 51px beside a badge allowed 54% of the card, so "Deluxe Sea View" wrapped
   to three lines and pushed the room number out of line with its neighbours. One line each, cut with an ellipsis:
   the card is a way of finding the room, and the dialog names the type in full. */
.ws-card-head .bold.small,.ws-card-head .muted.small{display:block;min-width:0;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}
.ws-card-head .bold.small{color:#fff;text-shadow:0 1px 6px rgba(15,23,42,.5)}
.ws-card-head .muted.small{color:rgba(255,255,255,.86);text-shadow:0 1px 6px rgba(15,23,42,.5)}
.ws-card-head .badge{white-space:normal;padding:1px 5px;font-size:8.5px;letter-spacing:.3px;box-shadow:0 1px 7px rgba(15,23,42,.28)}
.ws-room-no{font-size:21px;font-weight:850;margin:0;line-height:1;color:#fff;text-shadow:0 1px 10px rgba(15,23,42,.55)}
.ws-tag{display:inline-block;margin-top:3px;font-size:8px;font-weight:700;letter-spacing:.2px;background:#fef3c7;color:#b45309;padding:2px 4px;border-radius:5px}
.ws-hk-badge{display:inline-block;max-width:100%;margin-top:3px;padding:2px 5px;border-radius:5px;font-size:8.5px;font-weight:700;line-height:1.25;white-space:normal;overflow-wrap:anywhere;box-shadow:0 1px 6px rgba(15,23,42,.24)}
/* v10.14 the chip wraps rather than shedding the words "Current HK:" and the smoking rule stays on the card.
   v10.13 hid both at the five-across width to buy the fifth column; the board now knows its own width, so the
   card no longer has to buy anything — a two-line chip is cheaper than a card that withholds what it knows. */
.ws-hk-dirty{background:#fef3c7;color:#92400e}.ws-hk-clean{background:#dcfce7;color:#166534}.ws-hk-inspected{background:#d1fae5;color:#065f46}.ws-hk-touchup{background:#dbeafe;color:#1e40af}.ws-hk-unknown{background:#f1f5f9;color:#475569}
.ws-photo{position:relative;aspect-ratio:16/11;min-height:104px;max-height:172px;margin:0;overflow:hidden;border-radius:0;background:#eef2f0}
.ws-photo-img{position:absolute;inset:0;display:block;width:100%;height:100%;object-fit:cover}
.ws-photo:before{position:absolute;z-index:1;inset:0;border-radius:inherit;background:linear-gradient(180deg,rgba(15,23,42,.72),rgba(15,23,42,.16) 36%,rgba(15,23,42,.06) 60%,rgba(15,23,42,.66));content:"";pointer-events:none}
.ws-photo-link{cursor:pointer;transition:filter .16s ease}
.ws-photo-foot{position:absolute;z-index:2;right:0;bottom:0;left:0;display:flex;align-items:flex-end;justify-content:space-between;gap:6px;padding:7px 9px 7px 24px}
.ws-photo-meta{display:flex;flex-wrap:wrap;gap:2px 7px;color:rgba(255,255,255,.94);font-size:9.5px;font-weight:600;line-height:1.3;text-shadow:0 1px 6px rgba(15,23,42,.55)}
.ws-photo-meta>span{display:inline-flex;align-items:center;gap:4px}
.ws-photo-meta .i{width:12px;height:12px;flex:0 0 12px;color:rgba(255,255,255,.88)}
.ws-photo-foot .ws-photo-rate{position:static;flex:0 0 auto}
.ws-photo-link:hover{filter:saturate(1.08)}
.ws-person-row{display:flex;flex-direction:column;gap:4px}
.ws-person-row+.ws-person-row{margin-top:4px;padding-top:8px;border-top:1px solid #f1f5f9}
.ws-assign{display:flex;gap:6px;align-items:center;padding:6px 7px 6px 34px;border:1px solid #e2e8f0;border-radius:9px;background:#f8fafc}
.ws-assign-select{flex:1;min-width:0;min-height:34px;padding:5px 8px;border:1px solid #cbd5e1;border-radius:7px;background:#fff;font-size:12px}
.ws-assign .btn{min-height:34px;border-radius:7px;white-space:nowrap}
.ws-person-row>.ws-assign-none{margin-left:34px;padding:7px 9px;border:1px dashed #cbd5e1;border-radius:8px;background:#f8fafc;line-height:1.4}
.ws-assign-none a{color:#166534;font-weight:650;text-decoration:underline;text-underline-offset:2px}
.ws-checkin-blocker{display:block;margin-top:5px;padding:6px 8px;border:1px solid #fcd34d;border-radius:7px;background:#fffbeb;color:#92400e;font-size:11px;line-height:1.35}
.ws-photo-rate{position:absolute;z-index:1;right:8px;bottom:7px;background:rgba(255,255,255,.97);color:#14532d;font-weight:800;padding:2px 6px;border:1px solid rgba(255,255,255,.7);border-radius:6px;font-size:11px;font-variant-numeric:tabular-nums;box-shadow:0 2px 8px rgba(15,23,42,.16)}
.ws-photo-rate small{font-weight:650;color:#64748b}
.ws-photo-rate.ws-rate-unset{color:#b45309;background:rgba(255,251,235,.96);border-color:rgba(253,230,138,.8)}
.ws-photo-rate.ws-rate-unset small{color:#b45309}
.ws-card-body{display:flex;flex-direction:column;padding-bottom:0}
.ws-avatar{width:28px;height:28px;flex:0 0 28px;border-radius:50%;background:var(--c-primary);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:700}
.ws-avatar.sm{width:28px;height:28px;flex-basis:28px;font-size:12px}
.ws-avatar.vip{background:#b45309}
.ws-due{color:#dc2626;font-weight:700}
.ws-hold{display:block;color:#f59e0b;font-weight:600}
/* v10.25 Reserve sits where the other primary actions sit — first in the row — and ··· keeps the far edge. Two auto
   margins in one row would have split the free space and left the button floating in the middle. */
.ws-reserve{margin-left:0}
.ws-actions{display:flex;align-items:center;gap:5px;min-height:34px;margin-top:auto;padding:5px 8px;border-top:1px solid #f1f5f9;flex-wrap:wrap}
.ws-actions .btn{display:inline-flex;min-height:26px;align-items:center;justify-content:center;gap:4px;padding:3px 7px;border-radius:6px;font-size:10px;font-weight:650;line-height:1.2;white-space:nowrap}
.ws-actions .btn .i{width:12px;height:12px;flex:0 0 12px}
/* v10.17 the action row folds in three steps rather than one, because the controls are not one size.
   Measured at their own type: the primary button is ~80px, Reservation details ~119px, Mark Dirty ~85px,
   ··· All actions ~30px wordless, and the extend form — a 140px date field and a button — is ~200px on its
   own. The row has 18px of padding. So:
     · under 380px the extend form is a row of its own whatever else is there — it goes first;
     · under 250px primary + details + Mark Dirty no longer fit one line (80+119+85+8 gaps = 292) — they go;
     · and from 250px down the ··· loses its words, so the primary action and ··· share one line at any width.
   A folded control is not a lost one: the room dialog carries the extend and mark-dirty forms under their own
   ROOM ACTIONS heading, and Reservation details is in the dialog's Quick Links. */
/* v10.26 the rail takes 22px off the left of the photo and the badge asks for up to 44% of the card, which between
   them left the room number 30px of the 36px it needs at five across. The badge gives two points back and the head
   tightens its own edges, so "101" stays whole and the type name is what gets the ellipsis. */
@container wscard (max-width:199px){
  .ws-card-head{padding-left:22px;padding-right:7px}
  .ws-card-head .text-right{max-width:40%}
  .ws-amen-rail>span,.ws-amen-rail .ws-amen-more{width:15px;height:15px;flex-basis:15px}
  .ws-amen-rail .i{width:10px;height:10px}
  .ws-photo-foot{padding-left:22px}
}
@container wscard (max-width:379px){.ws-actions .ws-extend-form{display:none}}
@container wscard (max-width:249px){.ws-actions .ws-act-minor{display:none}}
@container wscard (max-width:379px){
  .ws-all .ws-act-label{position:absolute;width:1px;height:1px;overflow:hidden;white-space:nowrap;clip-path:inset(50%)}
}
/* v10.21 put the rate and what was due on a line each below 200px; v10.23 took those rows off the card
   altogether, so the narrow-card treatment is now the action fold above and nothing else. */
.ws-actions .btn-ghost{border:1px solid transparent}
.ws-actions .btn-ghost:hover{border-color:#bbf7d0;background:#f0fdf4;color:#14532d}
.ws-all{margin-left:auto;border:1px solid #d1e7d7;background:#f5fbf6;color:#14532d;font-weight:750}
.ws-all:hover{border-color:#86c79b;background:#ecfdf3;color:#14532d}
.ws-extend-form{display:flex;gap:5px;align-items:center;flex-wrap:wrap}
/* ── change-room drag & drop: drag the stay's handle onto a destination room card ──
   The board is the same data as the tape chart; the drop posts to the same endpoint and gets the same
   re-check server side. The handle keeps a word at every width the action row does, folds to an icon at
   379px like the rest, and both the source card and every destination get a visible state while a move
   is in flight so the board never looks dead mid-drag. */
.ws-action-status{margin:0 0 8px;padding:6px 10px;border-radius:6px;background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a;font-size:12px;font-weight:600}
.ws-action-status:empty{display:none}
.ws-drag-handle{display:inline-flex;min-height:26px;align-items:center;justify-content:center;gap:4px;padding:3px 7px;border-radius:6px;font-size:10px;font-weight:650;line-height:1.2;white-space:nowrap;background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;cursor:grab}
.ws-drag-handle .i{width:12px;height:12px;flex:0 0 12px}
.ws-drag-handle:hover{background:#dbeafe;border-color:#93c5fd;color:#1e3a8a}
.ws-drag-handle:focus-visible{outline:3px solid #2563eb;outline-offset:1px}
.ws-drag-handle:active{cursor:grabbing}
.ws-drag-handle.ws-source-selected{outline:3px solid #2563eb;outline-offset:1px;background:#dbeafe;border-color:#2563eb}
@container wscard (max-width:379px){.ws-drag-handle .ws-act-label{position:absolute;width:1px;height:1px;overflow:hidden;white-space:nowrap;clip-path:inset(50%)}}
body.dragging .ws-drag-handle{cursor:grabbing}
body.dragging .ws-card[data-drop-room]{outline:2px dashed #93c5fd;outline-offset:-2px}
.ws-card.drop-over{outline:3px solid #2563eb!important;outline-offset:-3px;box-shadow:0 0 0 4px rgba(37,99,235,.18)}
.ws-card.ws-drop-source{opacity:.55}
.ws-card.ws-kbd-dest:focus-visible{outline:3px solid #2563eb;outline-offset:-3px;box-shadow:0 0 0 4px rgba(37,99,235,.18)}
.ws-inline-action{margin:0}
.ws-checkin-blocker{flex-basis:100%}
.ws-extend-form input{min-height:30px;max-width:140px;padding:4px 7px;border:1px solid #cbd5e1;border-radius:7px;font-size:11px;font-variant-numeric:tabular-nums}
.ws-empty{grid-column:1/-1;border-style:dashed;border-radius:12px}
.ws-empty .card-body{display:flex;min-height:112px;align-items:center;gap:14px;padding:20px;background:linear-gradient(135deg,#fff,#f8fafc)}
.ws-empty-icon{display:inline-flex;width:42px;height:42px;flex:0 0 42px;align-items:center;justify-content:center;border:1px solid #d1e7d7;border-radius:12px;background:#f0fdf4;color:#166534}
.ws-empty-icon .i{width:20px;height:20px}
.ws-empty-copy{min-width:0}
.ws-empty-title{margin:0 0 4px;font-size:15px;font-weight:800}
.ws-empty-copy p{margin:0 0 9px}
.ws-empty-copy p:last-child{margin-bottom:0}
.ws-rail{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:10px;align-items:start}
.ws-modal-actions{display:flex;flex-wrap:wrap;align-items:center;gap:8px}
.ws-rail-card{overflow:hidden;border-radius:12px;box-shadow:0 3px 10px rgba(15,23,42,.045)}
.ws-rail-card .card-body{padding:14px}
.ws-rail-title{display:flex;align-items:center;gap:7px;margin-bottom:10px;padding-bottom:9px;border-bottom:1px solid #e2e8f0;color:#475569;font-size:10px;font-weight:800;letter-spacing:.075em;line-height:1.35;text-transform:uppercase}
.ws-rail-title .i{width:14px;height:14px;color:#15803d;flex:0 0 14px}
.ws-rail-title>a{min-width:0}
.ws-waitlist-all{margin-left:auto;color:#166534;font-size:10px;font-weight:750;text-decoration:underline;text-transform:none;letter-spacing:0}
.ws-rail-open{margin-left:auto;color:#166534;font-size:10px;font-weight:750;text-decoration:underline;text-transform:none;letter-spacing:0;white-space:nowrap}
.ws-stack{display:flex;height:9px;border-radius:999px;overflow:hidden;margin:4px 0 11px;background:#f1f5f9}
.ws-rail-list{width:100%;font-size:12px;border-collapse:collapse}
.ws-rail-list td{padding:5px 0}
.ws-rail-list tr+tr td{border-top:1px solid #f1f5f9}
.ws-rail-list td:last-child{font-variant-numeric:tabular-nums}
.ws-status-filter{border:0;padding:0;background:transparent;color:inherit;font:inherit;text-align:left;cursor:pointer}
.ws-status-filter:hover,.ws-status-filter[aria-pressed="true"]{font-weight:750;text-decoration:underline}
.ws-status-filter:focus-visible{outline:2px solid #15803d;outline-offset:2px;border-radius:2px}
.dot{display:inline-block;width:7px;height:7px;border-radius:50%;margin-right:6px}
.ws-person{display:flex;gap:8px;align-items:center;min-width:0;padding:7px 6px;border-radius:8px;color:inherit;text-decoration:none;transition:background-color .14s ease}
.ws-person>span:last-child{min-width:0;flex:1;overflow-wrap:anywhere}
.ws-person .muted.small{display:block;margin-top:2px;line-height:1.35}
.ws-person:hover{background:#f0fdf4;color:inherit}
.ws-cal-head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:9px}
.ws-cal-head>div{font-size:11px;letter-spacing:.06em}
.ws-cal-head .btn{display:inline-flex;width:30px;height:30px;align-items:center;justify-content:center;padding:0;border-radius:8px}
.ws-cal-head .disabled{opacity:.65}
.ws-cal-head .disabled:hover{background:transparent}
.ws-cal{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:3px;font-size:11px}
.ws-cal-d{text-align:center;color:#64748b;font-size:9px;font-weight:750}
.ws-cal-day{position:relative;display:flex;min-height:34px;flex-direction:column;align-items:center;justify-content:flex-start;padding:4px 0 2px;border-radius:7px;color:inherit;text-align:center;text-decoration:none}
.ws-cal-day:hover:not(.today):not(.disabled){background:#f0fdf4;color:#14532d}
.ws-cal-day:focus-visible{z-index:1;outline:3px solid #15803d;outline-offset:1px}
.ws-cal-day.selected{outline:2px solid #15803d;outline-offset:-2px;font-weight:800}
.ws-cal-day.today{background:var(--c-primary-600);color:#fff;font-weight:800}
.ws-cal-day.today.selected{box-shadow:0 0 0 2px #bbf7d0}
.ws-cal-day.disabled{color:#64748b;cursor:not-allowed}
.ws-cal-dots{display:block;height:5px;margin-top:auto;line-height:5px}
.dot-a,.dot-d{display:inline-block;width:4px;height:4px;border-radius:50%;margin:0 1px}
.dot-a{background:#16a34a}.dot-d{background:#ef4444}
.ws-cal-legend{display:flex;flex-wrap:wrap;gap:5px 10px;margin-top:10px}
.ws-legend{margin-right:0}
.ws-modal{overscroll-behavior:contain}
.ws-modal .modal-box{max-width:900px;max-height:90vh;overflow-y:auto;overscroll-behavior:contain}
.ws-modal .modal-box.wide{width:min(920px,calc(100vw - 2rem))}
.ws-modal .modal-body{padding:20px}
.ws-modal-hero{position:relative;isolation:isolate;display:flex;min-height:216px;align-items:flex-end;overflow:hidden;padding:58px 22px 20px;color:#fff;border-radius:0}
.ws-modal-hero:before{position:absolute;z-index:0;inset:0;background:linear-gradient(90deg,rgba(6,28,17,.78),rgba(10,40,24,.38)),linear-gradient(0deg,rgba(6,28,17,.45),transparent 75%);content:"";pointer-events:none}
.ws-modal-hero>*{position:relative;z-index:1}
.ws-modal-x{position:absolute;z-index:2;top:10px;right:10px;color:#fff;background:rgba(7,28,17,.42);border:1px solid rgba(255,255,255,.3)}
.ws-modal-x:hover{background:rgba(7,28,17,.72);color:#fff}
.ws-modal-x:focus-visible{outline:3px solid #fff;outline-offset:3px}
.ws-modal-hero-row{display:flex;gap:16px;align-items:flex-end;justify-content:space-between}
.ws-modal-hero-row>div{min-width:0;flex:1}
.ws-modal-title{margin:0;font-size:inherit;line-height:1.2;color:inherit;text-shadow:0 1px 8px rgba(0,0,0,.24)}
.ws-modal-grid{display:grid;grid-template-columns:minmax(0,.9fr) minmax(0,1.1fr);gap:16px;align-items:start}
.ws-facts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.ws-fact{min-width:0;background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px;padding:9px 10px;font-size:13px;line-height:1.35}
.ws-fact b{display:block;margin-top:3px;overflow-wrap:anywhere}
.ws-fac{display:flex;flex-wrap:wrap;gap:6px}
.ws-fac-more{margin-top:8px;color:#166534;font-size:12px}
.ws-fac-more summary{width:max-content;max-width:100%;cursor:pointer;font-weight:750;text-decoration:underline;text-underline-offset:2px}
.ws-fac-more summary:focus-visible{outline:2px solid #166534;outline-offset:3px;border-radius:3px}
.ws-fac-chip{display:inline-flex;gap:6px;align-items:center;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;padding:6px 9px;font-size:12px}
.ws-fac-chip .i{width:14px;height:14px;color:#15803d;flex:0 0 14px}
.ws-resv{border:1px solid #e2e8f0;border-radius:11px;padding:13px;background:linear-gradient(150deg,#fff,#f8fafc)}
.ws-resv>.flex{align-items:center}
.ws-resv>.flex>div{min-width:0;overflow-wrap:anywhere}
.ws-resv-t{width:100%;margin-top:10px;border-collapse:collapse;font-size:12px;table-layout:fixed}
.ws-resv-t td{padding:6px 5px 6px 0;vertical-align:top;overflow-wrap:anywhere}
.ws-resv-t tr+tr td{border-top:1px solid #e2e8f0}
.ws-resv-t td:nth-child(odd){width:19%;font-size:10px}
.ws-resv-t td:nth-child(even){width:31%}
.ws-quick{display:grid;grid-template-columns:repeat(auto-fill,minmax(112px,1fr));gap:8px}
.ws-quick-tile{display:flex;min-height:82px;flex-direction:column;gap:7px;align-items:center;justify-content:center;padding:12px 7px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;color:#1e293b;text-align:center;text-decoration:none;font-size:11px;line-height:1.35;transition:transform .14s ease,border-color .14s ease,box-shadow .14s ease}
.ws-quick-tile .i{width:17px;height:17px;color:#15803d}
.ws-quick-tile:hover{transform:translateY(-1px);border-color:#86c79b;background:#f0fdf4;color:#14532d;box-shadow:0 5px 12px rgba(20,83,45,.08)}
.ws-quick-tile:focus-visible{outline:3px solid #15803d;outline-offset:2px}
@container wsscreen (max-width:908px){.ws-kpis{grid-template-columns:repeat(5,minmax(0,1fr))}}
/* v10.15/v10.16 the rail used to step aside on narrow screens. v10.17 the rail leaves the side entirely: it
   now lays out full-width under the board in .ws-body's single column, in a grid that keeps each card at least
   300px and packs as many per row as the page allows — needs attention first, then the calendar, then the
   lists. The v10.16 note still holds for the reading order below the board. */
@container wsscreen (max-width:688px){.ws-kpis{grid-template-columns:repeat(3,minmax(0,1fr))}}
@container wsscreen (max-width:388px){
  .ws-head{align-items:flex-start;flex-direction:column;gap:9px;padding:14px}
  .ws-head>div:first-child{width:100%;min-width:0}
  .ws-head-side{width:100%;justify-content:flex-start}
  .ws-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}
  .ws-filters{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px;padding:9px}
  .ws-filters>span:first-child,.ws-filters .ws-search,.ws-filter-count{grid-column:1/-1}
  .ws-filters .ws-search{width:100%;min-width:0;max-width:none}
  .ws-filters .ws-select{width:100%;min-width:0;max-width:none}
  .ws-vip-filter{justify-content:center}
  #clearWsFilters{width:100%;padding-right:5px;padding-left:5px}
  .ws-filter-count{margin-left:0;text-align:right}
  .ws-modal-grid{grid-template-columns:1fr}
  .ws-modal .modal-body{padding:14px}
  .ws-modal-hero-row{align-items:flex-start;flex-wrap:wrap}
  .ws-resv-t{table-layout:fixed}
  .ws-resv-t td{overflow-wrap:anywhere;vertical-align:top}
  .ws-assign,.ws-assign-none{padding-left:0}
  .ws-person-row>.ws-assign{padding-left:7px}
  .ws-person-row>.ws-assign-none{margin-left:0;padding-left:9px}
  .ws-photo{min-height:118px}
  .ws-extend-form{width:100%}
  .ws-extend-form input{flex:1;max-width:none}
}
@media (prefers-reduced-motion:reduce){.ws-card,a.ws-kpi,.ws-photo-link,.ws-person,.ws-quick-tile{transition:none}}
body.dark .ws-kpi,
body.dark .ws-card,
body.dark .ws-fact,
body.dark .ws-fac-chip,
body.dark .ws-resv,
body.dark .ws-quick-tile,
body.dark .ws-date-jump,
body.dark .ws-assign-select{background:var(--c-surface);color:var(--c-text);border-color:var(--c-border)}
body.dark .ws-kpi-label,body.dark .ws-rail-title,body.dark .ws-date-jump-label{color:var(--c-muted)}
body.dark .ws-empty .card-body{background:linear-gradient(135deg,var(--c-surface),#18281e)}
body.dark .ws-empty-icon{border-color:#3b5946;background:#193525;color:#86efac}
body.dark .ws-guest.vacant{background:#18281e}
body.dark .ws-rail-title{border-color:var(--c-border)}
body.dark .ws-rail-list tr+tr td{border-top-color:var(--c-border)}
body.dark .ws-cal-day:hover:not(.today):not(.disabled){background:#193525;color:#d7f5df}
body.dark .ws-cal-day.today.selected{box-shadow:0 0 0 2px #3b5946}
body.dark .ws-quick-tile:hover{border-color:#3b5946;background:#193525;color:#d7f5df}
body.dark .ws-quick-tile:hover .i{color:#86efac}
body.dark .ws-stack{background:var(--c-border)}
body.dark .ws-filters{background:var(--c-surface);border-color:var(--c-border)}
body.dark .ws-filters>span:first-child{color:#86efac}
body.dark .ws-filters .ws-select{background:var(--c-surface);color:var(--c-text);border-color:var(--c-border)}
body.dark .ws-kpi{background:linear-gradient(150deg,var(--c-surface) 72%,#143323)}
body.dark .ws-kpi-delta.up{background:#143323;color:#86efac}
body.dark .ws-kpi-delta.down{background:#3b1d24;color:#fda4af}
body.dark .ws-kpi-delta.neutral{background:#243229;color:#c1d2c5}
body.dark .ws-waitlist-all,body.dark .ws-rail-open{color:#86efac}
body.dark .ws-hk-dirty{background:#422006;color:#fcd34d}body.dark .ws-hk-clean{background:#143323;color:#86efac}body.dark .ws-hk-inspected{background:#12362e;color:#6ee7b7}body.dark .ws-hk-touchup{background:#172b4d;color:#93c5fd}body.dark .ws-hk-unknown{background:#243229;color:#c1d2c5}
body.dark .ws-due,body.dark .ws-kpi-delta.down{color:#f87171}
body.dark .ws-person:hover{background:#193525}
body.dark .ws-person-row+.ws-person-row{border-top-color:var(--c-border)}
body.dark .ws-assign{background:#18291f;border-color:var(--c-border)}
body.dark .ws-person-row>.ws-assign-none{background:#18291f;border-color:var(--c-border)}
body.dark .ws-assign-none a{color:#86efac}
body.dark .ws-checkin-blocker{background:#422006;border-color:#854d0e;color:#fcd34d}
body.dark .ws-guest{border-top-color:var(--c-border)}
body.dark .ws-guest.arriving{background:rgba(245,158,11,.08);border-left-color:#fbbf24}
body.dark .ws-guest.inhouse{background:rgba(59,130,246,.10);border-left-color:#60a5fa}
body.dark .ws-actions{border-top-color:var(--c-border)}
body.dark .ws-actions .btn-ghost:hover{border-color:#3b5946;background:#193525;color:#d7f5df}
body.dark .ws-actions .ws-all{border-color:#3b5946;background:#193525;color:#86efac}
body.dark .ws-actions .ws-all:hover{background:#234630;color:#d7f5df}
body.dark .ws-extend-form input{background:var(--c-surface);color:var(--c-text);border-color:var(--c-border)}
body.dark .ws-action-status{background:#172554;border-color:#1e40af;color:#bfdbfe}
body.dark .ws-drag-handle{background:#172554;border-color:#1e40af;color:#93c5fd}
body.dark .ws-drag-handle:hover{background:#1e3a8a;border-color:#3b82f6;color:#dbeafe}
body.dark .ws-drag-handle.ws-source-selected{background:#1e3a8a;outline-color:#60a5fa}
body.dark.dragging .ws-card[data-drop-room]{outline-color:#3b82f6}
body.dark .ws-card.drop-over{outline-color:#60a5fa!important;box-shadow:0 0 0 4px rgba(96,165,250,.25)}
body.dark .ws-card.ws-kbd-dest:focus-visible{outline-color:#60a5fa}
body.dark .ws-fac-more{color:#86efac}
body.dark .ws-fac-more summary:focus-visible{outline-color:#86efac}
body.dark .ws-vip-filter{border-color:var(--c-border)}
body.dark .ws-cal-day.disabled{color:#94a3b8}
/* ────────────────────────────────────────────────────────────────────────
   v7.79 — the desk command bar, the rack, grouping and the attention rail
   ──────────────────────────────────────────────────────────────────────── */
.ws-head-stats{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}
.ws-head-stat{display:inline-flex;align-items:center;gap:5px;padding:4px 9px;border:1px solid rgba(255,255,255,.22);border-radius:999px;background:rgba(255,255,255,.1);color:rgba(255,255,255,.86);font-size:11px;text-decoration:none}
.ws-head-stat b{color:#fff;font-size:13px;font-variant-numeric:tabular-nums}
.ws-head-stat .i{width:13px;height:13px;color:#c8e8d0}
a.ws-head-stat:hover,a.ws-head-stat:focus-visible{background:rgba(255,255,255,.2);color:#fff}
.ws-cmd{position:sticky;top:var(--top-h,58px);z-index:12;margin:0 0 12px;padding:8px 8px 6px;border:1px solid #e2e8f0;border-radius:14px;background:rgba(255,255,255,.95);backdrop-filter:blur(8px);box-shadow:0 4px 14px rgba(15,23,42,.06)}
.ws-cmd .ws-filters{margin-bottom:6px;border:0;padding:2px;box-shadow:none;background:transparent}
.ws-toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:0 4px 6px;border-top:1px solid #f1f5f9;margin-top:2px;padding-top:8px}
.ws-viewswitch{display:inline-flex;padding:2px;border:1px solid #e2e8f0;border-radius:9px;background:#f8fafc}
.ws-vs-btn{display:inline-flex;align-items:center;gap:5px;padding:6px 12px;border:0;border-radius:7px;background:transparent;color:#475569;font-size:12px;font-weight:650;cursor:pointer;transition:background-color .14s ease,color .14s ease}
.ws-vs-btn .i{width:14px;height:14px}
.ws-vs-btn.is-on{background:#fff;color:#14532d;box-shadow:0 1px 3px rgba(15,23,42,.12)}
.ws-vs-btn:hover{color:#14532d}
.ws-vs-btn:focus-visible{outline:3px solid #15803d;outline-offset:1px}
.ws-groupby{display:inline-flex;align-items:center;gap:6px;color:#64748b;font-size:10px;font-weight:800;letter-spacing:.07em;text-transform:uppercase}
.ws-groupby select{min-height:32px;padding:4px 8px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#1e293b;font-size:12px;font-weight:600;letter-spacing:0;text-transform:none}
.ws-groupby select:focus-visible{outline:3px solid rgba(21,128,61,.35);outline-offset:1px}
.ws-groupby select:disabled{opacity:.5}
.ws-keys{margin-left:auto;display:inline-flex;align-items:center;gap:5px;color:#94a3b8;font-size:10px;white-space:nowrap}
.ws-keys kbd{display:inline-block;min-width:17px;padding:1px 4px;border:1px solid #e2e8f0;border-bottom-width:2px;border-radius:5px;background:#f8fafc;color:#475569;font-family:inherit;font-size:10px;font-weight:750;text-align:center}
.ws-chips{display:flex;flex-wrap:wrap;gap:6px;flex-basis:100%}
.ws-chips:empty{display:none}
.ws-chip{display:inline-flex;align-items:center;gap:5px;padding:3px 5px 3px 9px;border:1px solid #bbf7d0;border-radius:999px;background:#f0fdf4;color:#14532d;font-size:11px;font-weight:650}
.ws-chip button{display:inline-flex;width:16px;height:16px;align-items:center;justify-content:center;padding:0;border:0;border-radius:50%;background:#dcfce7;color:#166534;font-size:12px;line-height:1;cursor:pointer}
.ws-chip button:hover{background:#bbf7d0}
.ws-chip button:focus-visible{outline:2px solid #15803d;outline-offset:1px}
.ws-main{min-width:0;container-type:inline-size;container-name:wsboard}
.ws-group{grid-column:1/-1}
.ws-group-head{display:flex;align-items:center;gap:9px;margin:4px 0 9px;color:#14532d;font-size:11px;font-weight:850;letter-spacing:.08em;text-transform:uppercase}
.ws-group-head:after{flex:1;height:1px;background:#e2e8f0;content:""}
.ws-group-count{padding:1px 8px;border-radius:999px;background:#ecfdf3;color:#166534;font-size:11px;font-variant-numeric:tabular-nums;font-weight:750;letter-spacing:0}
.ws-group-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px;align-items:start}
@media (max-width:1599px){.ws-group-grid{grid-template-columns:repeat(5,minmax(0,1fr))}}
@media (max-width:1339px){.ws-group-grid{grid-template-columns:repeat(4,minmax(0,1fr))}}
@media (max-width:1079px){.ws-group-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media (max-width:819px){.ws-group-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:559px){.ws-group-grid{grid-template-columns:minmax(0,1fr)}}
@container wsboard (max-width:1319px){.ws-group-grid{grid-template-columns:repeat(5,minmax(0,1fr))}}
@container wsboard (max-width:1097px){.ws-group-grid{grid-template-columns:repeat(4,minmax(0,1fr))}}
@container wsboard (max-width:875px){.ws-group-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
@container wsboard (max-width:653px){.ws-group-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@container wsboard (max-width:431px){.ws-group-grid{grid-template-columns:minmax(0,1fr)}}
@container wsboard (min-width:1550px){.ws-group-grid{grid-template-columns:repeat(7,minmax(0,1fr))}}
@container wsboard (min-width:1770px){.ws-group-grid{grid-template-columns:repeat(8,minmax(0,1fr))}}
.ws-rack{min-width:0}
.ws-rack-scroll{overflow-x:auto;border:1px solid #e2e8f0;border-radius:12px;background:#fff;box-shadow:0 3px 10px rgba(15,23,42,.045)}
.ws-rack-table{width:100%;min-width:840px;border-collapse:collapse;font-size:12px}
.ws-rack-table th{padding:0;border-bottom:1px solid #e2e8f0;background:#f8fafc;color:#475569;font-size:10px;font-weight:800;letter-spacing:.07em;text-align:left;text-transform:uppercase;white-space:nowrap}
.ws-rack-table th.num{text-align:right}
.ws-rack-sort{display:block;width:100%;padding:10px 12px;border:0;background:transparent;color:inherit;font:inherit;letter-spacing:inherit;text-align:inherit;text-transform:inherit;cursor:pointer}
/* v10.18 the order of the rack, stated where the columns cannot take it away */
.ws-rack-sortstate{display:inline-flex;align-items:center;gap:6px;margin-left:auto;padding:2px 6px 2px 9px;border:1px solid #d1e7d7;border-radius:999px;background:#f5fbf6;color:#14532d;font-size:10.5px;font-weight:700;white-space:nowrap}
.ws-rack-sortstate[hidden]{display:none}
.ws-sortstate-toggle,.ws-sortstate-clear{padding:2px 7px;border:1px solid #bbf7d0;border-radius:999px;background:#fff;color:#166534;font:inherit;font-size:10px;font-weight:700;cursor:pointer}
.ws-sortstate-toggle{border-color:transparent;background:transparent;padding-left:0}
.ws-sortstate-toggle:hover,.ws-sortstate-clear:hover{background:#ecfdf3;border-color:#86c79b;color:#14532d}
.ws-sortstate-toggle:focus-visible,.ws-sortstate-clear:focus-visible{outline:2px solid #15803d;outline-offset:1px}
.ws-rack-table th.num .ws-rack-sort{text-align:right}
.ws-rack-sort:hover{background:#f0fdf4;color:#14532d}
.ws-rack-sort:focus-visible{outline:2px solid #15803d;outline-offset:-2px}
.ws-rack-table th[aria-sort="ascending"] .ws-rack-sort:after{content:" ↑"}
.ws-rack-table th[aria-sort="descending"] .ws-rack-sort:after{content:" ↓"}
.ws-rack-table td{padding:9px 12px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.ws-rack-table td.num{text-align:right;font-variant-numeric:tabular-nums}
.ws-rack-table tbody tr:last-child td{border-bottom:0}
.ws-rack-table tbody tr:hover{background:#f0fdf4}
.ws-rack-room{display:flex;align-items:center;gap:8px}
.ws-rack-roomtext{display:flex;min-width:0;flex-direction:column;gap:1px}
.ws-rack-sub{display:none;color:#64748b;font-size:11px;line-height:1.3;overflow-wrap:anywhere}
.ws-rack-thumb{width:38px;height:28px;flex:0 0 38px;border:1px solid #e2e8f0;border-radius:6px;background:#f1f5f9 center/cover no-repeat}
.ws-rack-room b{font-size:14px;font-weight:800}
.ws-rack-open{padding:2px 7px;border:1px solid #d1e7d7;border-radius:6px;background:#f5fbf6;color:#14532d;font-size:10px;font-weight:750;cursor:pointer}
.ws-rack-open:hover{background:#ecfdf3;border-color:#86c79b}
.ws-rack-open:focus-visible{outline:2px solid #15803d;outline-offset:2px}
.ws-rack-guest{display:flex;min-width:0;flex-direction:column;gap:1px}
.ws-rack-guest b{font-weight:700;overflow-wrap:anywhere}
.ws-rack-guest span{color:#64748b;font-size:11px}
.ws-rack-actions{display:flex;gap:5px;flex-wrap:wrap;align-items:center}
/* v10.19 the action cell is a column, not a row: at 840px and below it keeps the one action that moves the
   room — check in, check out — and the secondary controls retreat, exactly as they do on the card. The rack's
   own room cell already carries a Details button that opens the dialog, where ROOM ACTIONS has the rest. */
@container wsscreen (max-width:839px){.ws-rack-table [data-col="action"] .ws-rack-extra{display:none}}
.ws-rack-actions form{display:inline-flex;gap:4px;align-items:center;margin:0}
.ws-rack-actions input[type="date"]{width:116px;padding:3px 4px;border:1px solid #d8e3dc;border-radius:6px;font-size:11px}
.ws-rack-actions .btn{min-height:27px;padding:3px 7px;border-radius:6px;font-size:11px}
.ws-attention{border-color:#fcd34d;box-shadow:0 3px 12px rgba(180,83,9,.08)}
.ws-attention-count{display:inline-flex;min-width:19px;align-items:center;justify-content:center;padding:1px 6px;border-radius:999px;background:#b45309;color:#fff;font-size:10px;font-variant-numeric:tabular-nums;font-weight:800}
.ws-attention-row{display:flex;gap:9px;align-items:flex-start;padding:8px 6px;border-radius:8px;color:inherit;text-decoration:none}
.ws-attention-row+.ws-attention-row{border-top:1px solid #f1f5f9}
a.ws-attention-row:hover,a.ws-attention-row:focus-visible{background:#fffbeb;color:inherit}
.ws-attention-n{display:inline-flex;width:24px;height:24px;flex:0 0 24px;align-items:center;justify-content:center;border-radius:7px;font-size:12px;font-variant-numeric:tabular-nums;font-weight:800}
.ws-attention-row.tone-red .ws-attention-n{background:#fee2e2;color:#b91c1c}
.ws-attention-row.tone-amber .ws-attention-n{background:#fef3c7;color:#92400e}
.ws-attention-row.tone-slate .ws-attention-n{background:#f1f5f9;color:#475569}
.ws-attention-copy{display:flex;min-width:0;flex-direction:column;gap:1px}
.ws-attention-copy b{font-size:12px}
.ws-attention-copy .small{line-height:1.35}
.ws-attention-clear{display:flex;gap:9px;align-items:flex-start;padding:6px;color:#15803d}
.ws-attention-clear .i{width:18px;height:18px;flex:0 0 18px}
.ws-attention-clear b{color:#14532d;font-size:12px}
/* hidden must beat the flex/grid display these elements ask for */
.ws-card[hidden],.ws-grid[hidden],.ws-rack[hidden],.ws-group[hidden],.ws-empty[hidden],.ws-rack-table tr[hidden],.ws-cmd[hidden]{display:none!important}
body.dark .ws-cmd{background:rgba(19,32,24,.96);border-color:var(--c-border)}
body.dark .ws-toolbar{border-top-color:var(--c-border)}
body.dark .ws-viewswitch{background:var(--c-surface);border-color:var(--c-border)}
body.dark .ws-vs-btn{color:#c1d2c5}
body.dark .ws-vs-btn.is-on{background:#193525;color:#86efac;box-shadow:none}
body.dark .ws-groupby{color:#c1d2c5}
body.dark .ws-groupby select{background:var(--c-surface);border-color:var(--c-border);color:var(--c-text)}
body.dark .ws-group-head{color:#86efac}
body.dark .ws-group-head:after{background:var(--c-border)}
body.dark .ws-group-count{background:#143323;color:#86efac}
body.dark .ws-rack-scroll{background:var(--c-surface);border-color:var(--c-border)}
body.dark .ws-rack-table th{background:#18281e;border-bottom-color:var(--c-border);color:#c1d2c5}
body.dark .ws-rack-table td{border-bottom-color:var(--c-border)}
body.dark .ws-rack-table tbody tr:hover{background:#193525}
body.dark .ws-rack-open{background:#193525;border-color:#3b5946;color:#86efac}
body.dark .ws-rack-open:hover{background:#234630}
body.dark .ws-rack-sort:hover{background:#193525;color:#d7f5df}
body.dark .ws-attention{background:var(--c-surface);border-color:#854d0e}
body.dark .ws-attention-row+.ws-attention-row{border-top-color:var(--c-border)}
body.dark .ws-attention-row.tone-red .ws-attention-n{background:#3b1d24;color:#fda4af}
body.dark .ws-attention-row.tone-amber .ws-attention-n{background:#422006;color:#fcd34d}
body.dark .ws-attention-row.tone-slate .ws-attention-n{background:#243229;color:#c1d2c5}
body.dark .ws-attention-clear{color:#86efac}
body.dark .ws-attention-clear b{color:#d7f5df}
body.dark .ws-attention-count{background:#b45309;color:#fff}
body.dark .ws-keys kbd{background:#18281e;border-color:var(--c-border);color:#c1d2c5}
body.dark .ws-chip{background:#143323;border-color:#3b5946;color:#86efac}
body.dark .ws-chip button{background:#193525;color:#86efac}
body.dark a.ws-attention-row:hover{background:#193525}
@container wsscreen (max-width:888px){.ws-keys{display:none}}
@container wsscreen (max-width:688px){.ws-cmd{position:static}}
/* v10.17 the rack is the "scan it fast" tab, and it was the one place still asking the reader to scroll
   sideways: an 840px table inside a 600px panel. The columns now retreat in the order that costs least —
   the floor and the nights count first, then the balance, then housekeeping, then the rate — until the rack
   is room, state, guest and the action, which is what a room is on this screen. Nothing is dropped from the
   page: every one of these cells is still built, still sorted, and still in the dialogs. */
@container wsscreen (max-width:839px){
  .ws-rack-table{min-width:0}
  .ws-rack-table [data-col="nights"],.ws-rack-table [data-col="balance"]{display:none}}
@container wsscreen (max-width:719px){
  .ws-rack-table [data-col="type"],.ws-rack-table [data-col="hk"]{display:none}}
@container wsscreen (max-width:519px){
  .ws-rack-table [data-col="rate"]{display:none}
  /* a room, its guest under it, its state and the one action: a two-line row instead of three columns of
     table. The guest cell is hidden and its content is already in the room cell, so nothing leaves the page. */
  .ws-rack-table [data-col="guest"]{display:none}
  .ws-rack-table .ws-rack-sub{display:block}
  .ws-rack-table td{padding:7px 9px}}
@container wsscreen (max-width:388px){
  .ws-toolbar{gap:8px}
  .ws-viewswitch{flex:1}
  .ws-vs-btn{flex:1;justify-content:center}
  .ws-groupby{flex:1;justify-content:space-between}
  .ws-groupby select{flex:1}
  .ws-head-stat{flex:1 1 auto;justify-content:center}
}
@media print{
  .ws-cmd,.ws-rail,.ws-head-side,.ws-actions,.ws-rack-actions,.ws-keys,.ws-rack-sortstate{display:none!important}
  .ws-action-status{display:none!important}
  /* v10.18 paper has its own width: without this the sheet inherited whatever the screen's container said,
     so printing from a wide window put five 150px cards on A4 and printing from a narrow one put two. Three
     across is what a portrait sheet holds at a size the room number and the guest are still readable at. */
  .ws-grid,.ws-group-grid{grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:8px}
  .ws-body{display:block}
  .ws-body{grid-template-columns:1fr}
  .ws-main{grid-column:1/-1}
  .ws-head{box-shadow:none;background:#14532d!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .ws-card,.ws-rack-scroll{break-inside:avoid;box-shadow:none}
  /* v10.19 three across on paper means a group can straddle a page break: a heading at the foot of one page
     with its rooms overleaf reads as a heading for nothing. The heading stays with what it names. */
  .ws-group-head{break-after:avoid;break-inside:avoid}
  .ws-group{break-inside:auto}
  .ws-rack-scroll{overflow:visible;border:0}
  .ws-rack-table{min-width:0;font-size:10px}
  .ws-photo{aspect-ratio:auto;min-height:104px}
  .ws-card-head{padding:8px 10px 0 24px}
  .ws-amen-rail{padding-left:2px;background:#f1f5f9}
  .ws-amen-rail>span{background:#e2e8f0;color:#334155}
  .ws-amen-rail .ws-amen-more{background:#fff;border-color:#cbd5e1;color:#14532d}
  /* v10.23 the screen card carries one sr-only sentence — guest, source, arrive, depart, nights, rate, due — and
     nothing else about the stay. A sheet of paper has no dialog to open, so on paper that sentence is printed, and
     it is the only place the printed board says who is in the room. */
  #wsGrid .ws-guest-note{position:static;display:block;width:auto;height:auto;margin:0 10px 6px;overflow:visible;white-space:normal;clip-path:none;color:#64748b;font-size:10px;line-height:1.35}
  .ws-room-no{font-size:20px}
  .ws-photo-foot{padding:8px 10px}
  .ws-rack-thumb{display:none}
  .ws-rack-table th,.ws-rack-table td{padding:4px 6px}
  .ws-rack-sort{padding:4px 6px;cursor:auto}
  .ws-rack-open,.ws-chip button,.ws-empty{display:none!important}
  .ws-cmd{position:static}
}
</style>
@endpush
