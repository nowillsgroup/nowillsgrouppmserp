@extends('layouts.app')
@section('title', 'Room View')
@section('crumbs') Front Office / Room View @endsection
@section('actions')
  <a class="btn" href="{{ route('frontdesk.arrivals') }}"><x-icon name="login" size="sm"/> Arrivals</a>
  <a class="btn btn-primary" href="{{ route('frontdesk.tapechart') }}"><x-icon name="calendar" size="sm"/> Tape chart</a>
@endsection
@section('content')
@php
  $statusLabels = [
    'vacant' => 'Vacant', 'occupied' => 'Occupied', 'reserved' => 'Arriving today',
    'due_out' => 'Due out', 'out_of_order' => 'Out of order', 'out_of_service' => 'Out of service',
  ];
  $housekeepingLabels = ['clean' => 'Clean', 'dirty' => 'Dirty', 'inspected' => 'Inspected', 'touch_up' => 'Touch-up'];
  $roomTypesForMetrics = $types->count() > 0 && request('type') ? ($types[request('type')] ?? 'Selected room type') : 'All active room types';
@endphp
<style>
  .room-view-page { color:var(--c-text); }
  .room-view-hero { display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; margin:0 0 1rem; padding:1rem 1.15rem; border:1px solid #dcebe0; border-radius:14px; background:linear-gradient(115deg,#f0f8f2 0%,var(--c-surface) 72%); }
  .room-view-kicker { display:flex; align-items:center; gap:.45rem; color:#17623b; font-size:.72rem; font-weight:800; letter-spacing:.09em; text-transform:uppercase; }
  .room-view-kicker .i { width:15px; height:15px; }
  .room-view-hero p { max-width:680px; margin:.4rem 0 0; color:var(--c-text-2); font-size:.9rem; }
  .room-view-date { display:inline-flex; flex:0 0 auto; align-items:center; gap:.5rem; padding:.58rem .72rem; border:1px solid #dcebe0; border-radius:10px; background:#fff; color:#24583a; font-weight:700; font-variant-numeric:tabular-nums; }
  .room-view-date .i { color:#17623b; }
  .room-view-metrics { display:grid; grid-template-columns:repeat(6,minmax(0,1fr)); gap:.7rem; margin:0 0 1rem; }
  .room-view-metric { min-width:0; padding:.78rem .85rem; border:1px solid var(--c-border); border-radius:12px; background:var(--c-surface); box-shadow:var(--shadow-sm); }
  .room-view-metric b { display:block; font-size:1.4rem; line-height:1.1; font-weight:780; font-variant-numeric:tabular-nums; }
  .room-view-metric span { display:block; margin-top:.25rem; color:var(--c-text-2); font-size:.75rem; }
  .room-view-metric.total { border-top:3px solid #17623b; }
  .room-view-metric.vacant { border-top:3px solid #16a34a; }
  .room-view-metric.occupied { border-top:3px solid #dc2626; }
  .room-view-metric.arriving { border-top:3px solid #2563eb; }
  .room-view-metric.due { border-top:3px solid #d97706; }
  .room-view-metric.offline { border-top:3px solid #64748b; }
  .room-view-hk-summary { display:flex; align-items:center; gap:.65rem; flex-wrap:wrap; margin:-.2rem 0 1rem; padding:.65rem .8rem; border:1px solid var(--c-border); border-radius:11px; background:var(--c-surface); }
  .room-view-hk-summary > strong { margin-right:.15rem; color:var(--c-text-2); font-size:.75rem; }
  .room-view-hk-chip { display:inline-flex; align-items:center; gap:.3rem; color:var(--c-text-2); font-size:.72rem; }
  .room-view-hk-chip i { width:8px; height:8px; border-radius:50%; background:#94a3b8; }
  .room-view-hk-chip.hk-clean i { background:#16a34a; }.room-view-hk-chip.hk-dirty i { background:#dc2626; }.room-view-hk-chip.hk-inspected i { background:#2563eb; }.room-view-hk-chip.hk-touch_up i { background:#d97706; }
  .room-view-filter-card { margin-bottom:1rem; padding:.85rem 1rem; border:1px solid var(--c-border); border-radius:14px; background:var(--c-surface); box-shadow:var(--shadow-sm); }
  .room-view-filter-form { display:flex; align-items:flex-end; gap:.65rem; flex-wrap:wrap; }
  .room-view-filter-field { width:185px; min-width:155px; }
  .room-view-filter-field label { display:block; margin-bottom:.28rem; color:var(--c-text-2); font-size:.76rem; font-weight:700; }
  .room-view-filter-field select { width:100%; min-height:39px; padding:.48rem .65rem; border:1px solid var(--c-border-strong); border-radius:8px; background:var(--c-surface); color:var(--c-text); font:inherit; }
  .room-view-filter-tools { display:flex; align-items:flex-end; gap:.55rem; flex:1; min-width:210px; }
  .room-view-search-wrap { position:relative; flex:1; min-width:180px; }
  .room-view-search-wrap .i { position:absolute; top:50%; left:.65rem; width:15px; height:15px; color:var(--c-muted); transform:translateY(-50%); }
  .room-view-search { width:100%; min-height:39px; padding:.48rem .65rem .48rem 2rem; border:1px solid var(--c-border-strong); border-radius:8px; background:var(--c-surface); color:var(--c-text); font:inherit; }
  .room-view-result-count { display:block; margin:.6rem 0 0; color:var(--c-text-2); font-size:.75rem; }
  .room-view-floor { margin:0 0 1.1rem; }
  .room-view-floor[hidden] { display:none; }
  .room-view-floor-heading { display:flex; align-items:center; gap:.55rem; margin:0 0 .58rem; color:var(--c-text-2); font-size:.78rem; font-weight:800; letter-spacing:.075em; text-transform:uppercase; }
  .room-view-floor-heading::after { height:1px; flex:1; background:var(--c-border); content:""; }
  .room-view-floor-count { padding:.13rem .45rem; border:1px solid var(--c-border); border-radius:999px; color:var(--c-text-2); font-size:.68rem; letter-spacing:0; }
  .room-view-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(min(100%,225px),1fr)); gap:.7rem; }
  .room-view-tile { position:relative; min-width:0; min-height:158px; padding:.85rem; border:1px solid var(--c-border); border-left:4px solid var(--c-border-strong); border-radius:13px; background:var(--c-surface); box-shadow:var(--shadow-sm); transition:transform .14s ease,box-shadow .14s ease,border-color .14s ease; }
  .room-view-tile:hover { transform:translateY(-2px); box-shadow:var(--shadow); }
  .room-view-tile.room-state-vacant { border-left-color:#16a34a; }
  .room-view-tile.room-state-occupied { border-left-color:#dc2626; }
  .room-view-tile.room-state-reserved { border-left-color:#2563eb; }
  .room-view-tile.room-state-due_out { border-left-color:#d97706; }
  .room-view-tile.room-state-out_of_order,.room-view-tile.room-state-out_of_service { border-left-color:#64748b; }
  .room-view-tile-top { display:flex; justify-content:space-between; align-items:flex-start; gap:.5rem; }
  .room-view-room-number { display:block; color:var(--c-text); font-size:1.23rem; font-weight:800; line-height:1.1; letter-spacing:-.025em; }
  .room-view-room-type { display:block; margin-top:.2rem; color:var(--c-text-2); font-size:.72rem; }
  .room-view-menu-trigger { display:grid; flex:0 0 32px; width:32px; height:32px; place-items:center; border:1px solid var(--c-border); border-radius:8px; background:var(--c-surface); color:var(--c-text-2); cursor:pointer; }
  .room-view-menu-trigger:hover,.room-view-menu-trigger[aria-expanded="true"] { border-color:#9bc8aa; background:#eff8f1; color:#17623b; }
  .room-view-menu-trigger:focus-visible { outline:3px solid rgba(34,139,78,.3); outline-offset:2px; }
  .room-view-tile-status { display:inline-flex; align-items:center; gap:.35rem; margin-top:.7rem; padding:.2rem .5rem; border:1px solid transparent; border-radius:999px; font-size:.68rem; font-weight:750; line-height:1.2; }
  .room-view-tile-status::before { width:7px; height:7px; border-radius:50%; background:currentColor; content:""; }
  .room-view-tile-status.room-state-vacant { background:#edf8f0; border-color:#cce7d3; color:#17623b; }
  .room-view-tile-status.room-state-occupied { background:#fff1ef; border-color:#f0d5d1; color:#943b31; }
  .room-view-tile-status.room-state-reserved { background:#eef5ff; border-color:#cfe0fb; color:#1d4e89; }
  .room-view-tile-status.room-state-due_out { background:#fff7e8; border-color:#f3ddb0; color:#805000; }
  .room-view-tile-status.room-state-out_of_order,.room-view-tile-status.room-state-out_of_service { background:#f1f5f9; border-color:#d7e0ea; color:#475569; }
  .room-view-tile-context { min-height:2.25rem; margin:.55rem 0 .6rem; color:var(--c-text); font-size:.8rem; font-weight:650; line-height:1.35; }
  .room-view-tile-detail { display:block; margin-top:.14rem; color:var(--c-text-2); font-size:.72rem; font-weight:450; }
  .room-view-tile-footer { display:flex; justify-content:space-between; align-items:center; gap:.5rem; padding-top:.5rem; border-top:1px solid var(--c-border); }
  .room-view-floor-name { overflow:hidden; color:var(--c-muted); font-size:.68rem; text-overflow:ellipsis; white-space:nowrap; }
  .room-view-hk { display:inline-flex; align-items:center; gap:.32rem; flex:0 0 auto; color:var(--c-text-2); font-size:.68rem; font-weight:650; }
  .room-view-hk::before { width:8px; height:8px; border:1px solid rgba(15,23,42,.08); border-radius:50%; background:var(--hk-color,#94a3b8); content:""; }
  .room-view-hk.hk-clean { --hk-color:#16a34a; }
  .room-view-hk.hk-dirty { --hk-color:#dc2626; }
  .room-view-hk.hk-inspected { --hk-color:#2563eb; }
  .room-view-hk.hk-touch_up { --hk-color:#d97706; }
  .room-view-hk.hk-clean,.room-view-hk.hk-dirty,.room-view-hk.hk-inspected,.room-view-hk.hk-touch_up { background:transparent; }
  .room-view-menu { right:.7rem; top:3rem; min-width:230px; max-width:min(290px,calc(100vw - 2rem)); padding:.35rem; }
  .room-view-menu a,.room-view-menu button { font-size:.8rem; }
  .room-view-menu hr { margin:.35rem .25rem; }
  .room-view-menu form { margin:0; }
  .room-view-menu-empty { padding:.55rem .7rem; color:var(--c-text-2); font-size:.78rem; }
  .room-view-empty,.room-view-search-empty { padding:2.4rem 1rem; border:1px dashed var(--c-border-strong); border-radius:14px; background:var(--c-surface); text-align:center; }
  .room-view-empty-icon { display:grid; width:46px; height:46px; place-items:center; margin:0 auto .65rem; border-radius:14px; background:#eff8f1; color:#17623b; }
  .room-view-empty-icon .i { width:22px; height:22px; }
  .room-view-empty h2,.room-view-search-empty h2 { margin:0 0 .3rem; font-size:1rem; }
  .room-view-empty p,.room-view-search-empty p { max-width:460px; margin:0 auto .8rem; color:var(--c-text-2); font-size:.83rem; }
  body.dark .room-view-hero { border-color:var(--c-border); background:linear-gradient(115deg,#172c1f 0%,var(--c-surface) 75%); }
  body.dark .room-view-kicker,body.dark .room-view-date,body.dark .room-view-date .i { color:#bce8c9; }
  body.dark .room-view-date { border-color:var(--c-border); background:#172c1f; }
  body.dark .room-view-tile { background:var(--c-surface); }
  body.dark .room-view-menu-trigger:hover,body.dark .room-view-menu-trigger[aria-expanded="true"] { border-color:#42654d; background:#193525; color:#bce8c9; }
  body.dark .room-view-tile-status.room-state-vacant { background:#193525; border-color:#315a3e; color:#a8e8b8; }
  body.dark .room-view-tile-status.room-state-occupied { background:#3a2422; border-color:#64403c; color:#ffc1b8; }
  body.dark .room-view-tile-status.room-state-reserved { background:#1b2d46; border-color:#334d70; color:#b4d1ff; }
  body.dark .room-view-tile-status.room-state-due_out { background:#3b301d; border-color:#66512b; color:#ffdc91; }
  body.dark .room-view-tile-status.room-state-out_of_order,body.dark .room-view-tile-status.room-state-out_of_service { background:#25303a; border-color:#465462; color:#d0d9e2; }
  body.dark .room-view-empty-icon { background:#1b3825; color:#9fe0b2; }
  @media(max-width:1100px) { .room-view-metrics { grid-template-columns:repeat(3,minmax(0,1fr)); } }
  @media(max-width:700px) { .room-view-metrics { grid-template-columns:repeat(2,minmax(0,1fr)); gap:.5rem; } .room-view-metric { padding:.68rem .72rem; } .room-view-metric b { font-size:1.2rem; } }
  @media(max-width:620px) { .room-view-hero { display:block; padding:.85rem; } .room-view-date { margin-top:.7rem; } .room-view-filter-form { align-items:stretch; } .room-view-filter-field { width:100%; } .room-view-filter-tools { min-width:100%; flex-wrap:wrap; } .room-view-search-wrap { min-width:100%; } .room-view-filter-tools .btn { flex:1; } .room-view-grid { grid-template-columns:repeat(2,minmax(0,1fr)); gap:.5rem; } .room-view-tile { min-height:164px; padding:.72rem; } .room-view-room-number { font-size:1.08rem; } .room-view-tile-context { font-size:.75rem; } }
  @media(max-width:390px) { .room-view-grid { grid-template-columns:1fr; } }
  @media(prefers-reduced-motion:reduce) { .room-view-tile { transition:none; } .room-view-tile:hover { transform:none; } }
  @media print { .room-view-filter-card,.room-view-menu-trigger,.room-view-menu,.room-view-hero .room-view-date,.no-print { display:none!important; } .room-view-metrics { grid-template-columns:repeat(6,1fr); } .room-view-metric,.room-view-tile { box-shadow:none; break-inside:avoid; } .room-view-grid { grid-template-columns:repeat(4,1fr); } .room-view-tile { min-height:120px; } .room-view-floor { break-inside:avoid; } }
</style>

<div class="room-view-page">
  <section class="room-view-hero" aria-label="Room inventory overview">
    <div><div class="room-view-kicker"><x-icon name="bed"/> Front office · Live room inventory</div><p>See today's room status at a glance, find guests and arrivals, and open the right operational actions without leaving the room map.</p></div>
    <div class="room-view-date"><x-icon name="calendar" size="sm"/><time datetime="{{ $today->toDateString() }}">Business date · {{ $today->format('j M Y') }}</time></div>
  </section>

  <section class="room-view-metrics" aria-label="Room status summary for {{ $roomTypesForMetrics }}">
    <div class="room-view-metric total"><b>{{ number_format($totalRooms) }}</b><span>Active rooms</span></div>
    <div class="room-view-metric vacant"><b>{{ number_format($counts['vacant'] ?? 0) }}</b><span>Vacant</span></div>
    <div class="room-view-metric occupied"><b>{{ number_format($counts['occupied'] ?? 0) }}</b><span>Occupied</span></div>
    <div class="room-view-metric arriving"><b>{{ number_format($counts['reserved'] ?? 0) }}</b><span>Arriving today</span></div>
    <div class="room-view-metric due"><b>{{ number_format($counts['due_out'] ?? 0) }}</b><span>Due out</span></div>
    <div class="room-view-metric offline"><b>{{ number_format(($counts['out_of_order'] ?? 0) + ($counts['out_of_service'] ?? 0)) }}</b><span>Out of service</span></div>
  </section>

  @if($canViewHousekeeping)
    <section class="room-view-hk-summary" aria-label="Housekeeping summary">
      <strong>Housekeeping</strong>
      @foreach($housekeepingLabels as $key => $label)
        <span class="room-view-hk-chip hk-{{ $key }}"><i aria-hidden="true"></i>{{ $label }} <b>{{ number_format($hk[$key] ?? 0) }}</b></span>
      @endforeach
    </section>
  @endif

  <section class="room-view-filter-card" aria-label="Filter room inventory">
    <form class="room-view-filter-form" method="get" action="{{ route('frontdesk.rooms') }}">
      <div class="room-view-filter-field"><label for="room-type-filter">Room type</label><select id="room-type-filter" name="type"><option value="">All active types</option>@foreach($types as $id => $name)<option value="{{ $id }}" @selected(request('type') == $id)>{{ $name }}</option>@endforeach</select></div>
      <div class="room-view-filter-field"><label for="room-status-filter">Room status</label><select id="room-status-filter" name="room_status"><option value="">All room statuses</option>@foreach($statusLabels as $key => $label)<option value="{{ $key }}" @selected($roomStatusFilter === $key)>{{ $label }}</option>@endforeach</select></div>
      @if($canViewHousekeeping)
        <div class="room-view-filter-field"><label for="room-hk-filter">Housekeeping</label><select id="room-hk-filter" name="hk_status"><option value="">All HK statuses</option>@foreach($housekeepingLabels as $key => $label)<option value="{{ $key }}" @selected($housekeepingStatusFilter === $key)>{{ $label }}</option>@endforeach</select></div>
      @endif
      <div class="room-view-filter-tools">
        <label class="room-view-search-wrap"><span class="sr-only">Search rooms, guests or bookings</span><x-icon name="search"/><input class="room-view-search" type="search" placeholder="Search room, guest or booking…" autocomplete="off" data-room-search></label>
        <button class="btn btn-primary" type="submit"><x-icon name="search" size="sm"/> Apply</button>
        @if(request()->hasAny(['type','room_status','hk_status','status']))<a class="btn" href="{{ route('frontdesk.rooms') }}">Clear filters</a>@endif
      </div>
    </form>
    <p class="room-view-result-count" id="room-view-result-count" role="status" aria-live="polite">Showing {{ number_format($visibleRooms) }} of {{ number_format($totalRooms) }} rooms{{ $roomTypesForMetrics !== 'All active room types' ? ' · '.$roomTypesForMetrics : '' }}.</p>
  </section>

  <section class="room-view-inventory" aria-label="Room inventory, grouped by floor">
  @forelse($floors as $floor => $rooms)
    <section class="room-view-floor" data-room-group aria-label="{{ $floor }} floor rooms">
      <h2 class="room-view-floor-heading">{{ $floor }} <span class="room-view-floor-count">{{ $rooms->count() }} {{ \Illuminate\Support\Str::plural('room', $rooms->count()) }}</span></h2>
      <div class="room-view-grid">
        @foreach($rooms as $room)
          @php
            $stay = $room->currentStay;
            $arrival = $room->arrival ?? null;
            $stayGuest = $stay?->reservation?->guest;
            $arrivalGuest = $arrival?->reservation?->guest;
            $guestName = $stayGuest?->full_name ?? $arrivalGuest?->full_name;
            $reservationNo = $stay?->reservation?->reservation_no ?? $arrival?->reservation?->reservation_no;
            $statusLabel = $statusLabels[$room->view_status] ?? ucwords(str_replace('_', ' ', $room->view_status));
            $hkLabel = $housekeepingLabels[$room->hk_status] ?? ucwords(str_replace('_', ' ', $room->hk_status ?? 'Not set'));
            $searchText = mb_strtolower(implode(' ', array_filter([
                $room->room_no, $room->roomType?->name, $room->floor?->name, $statusLabel,
                $guestName, $reservationNo, $canViewHousekeeping ? $hkLabel : null,
            ])));
            $hasActions = can_do('housekeeping.manage') || (bool) $stay
                || ($arrival && (can_do('frontdesk.checkin') || can_do('reservations.view')))
                || ($room->view_status === 'vacant' && (can_do('frontdesk.checkin') || can_do('reservations.create')));
          @endphp
          <article class="room-view-tile room-state-{{ $room->view_status }}" data-room-card data-room-search="{{ $searchText }}">
            <div class="room-view-tile-top">
              <div><span class="room-view-room-number">Room {{ $room->room_no }}</span><span class="room-view-room-type">{{ $room->roomType?->name ?? $room->roomType?->code ?? 'Room type not set' }}</span></div>
              @if($hasActions)
                <button type="button" class="room-view-menu-trigger" data-dropdown="room-actions-{{ $room->id }}" data-room-menu-trigger aria-controls="room-actions-{{ $room->id }}" aria-haspopup="menu" aria-expanded="false" aria-label="Actions for room {{ $room->room_no }}" title="Room actions"><x-icon name="menu"/></button>
              @endif
            </div>
            <span class="room-view-tile-status room-state-{{ $room->view_status }}">{{ $statusLabel }}</span>
            <div class="room-view-tile-context">
              @if($stay)
                <span title="{{ $guestName ?? 'Guest details unavailable' }}">{{ $guestName ?? 'Guest details unavailable' }}</span>
                <span class="room-view-tile-detail">Due out {{ $stay->departure_date->format('D, d M') }}@if($reservationNo) · {{ $reservationNo }}@endif</span>
              @elseif($arrival)
                <span title="{{ $guestName ?? 'Guest details unavailable' }}">{{ $guestName ?? 'Guest details unavailable' }}</span>
                <span class="room-view-tile-detail">Expected today @if($reservationNo) · {{ $reservationNo }}@endif</span>
              @elseif($room->view_status === 'vacant')
                <span>Available for assignment</span>
                @if($canViewHousekeeping && !in_array($room->hk_status, ['clean','inspected'], true))<span class="room-view-tile-detail">Needs housekeeping attention</span>@endif
              @else
                <span>{{ ucwords(str_replace('_', ' ', $room->status)) }}</span>
                @if($room->notes)<span class="room-view-tile-detail">{{ $room->notes }}</span>@endif
              @endif
            </div>
            <div class="room-view-tile-footer">
              <span class="room-view-floor-name">{{ $room->floor?->name ?? 'Floor not set' }}</span>
              @if($canViewHousekeeping)<span class="room-view-hk hk-{{ $room->hk_status }}" aria-label="Housekeeping status: {{ $hkLabel }}" title="Housekeeping: {{ $hkLabel }}">{{ $hkLabel }}</span>@endif
            </div>

            @if($hasActions)
              <div class="dropdown room-view-menu" id="room-actions-{{ $room->id }}" role="menu" aria-label="Actions for room {{ $room->room_no }}">
                @if($stay)
                  @if(can_do('reservations.view'))<a role="menuitem" href="{{ route('reservations.show', $stay->reservation_id) }}"><x-icon name="eye" size="sm"/> View reservation</a>@endif
                  @if($canViewFolio && $stay->folio)<a role="menuitem" href="{{ route('folios.show', $stay->folio) }}"><x-icon name="receipt" size="sm"/> Folio / Post charge</a>@endif
                  @if(can_do('frontdesk.roommove'))<a role="menuitem" href="{{ route('frontdesk.move', $stay) }}"><x-icon name="swap" size="sm"/> Room move / Amend</a>@endif
                  @if(can_do('frontdesk.checkout'))<a role="menuitem" href="{{ route('frontdesk.checkout', $stay) }}"><x-icon name="logout" size="sm"/> Check-out</a>@endif
                  <a role="menuitem" href="{{ route('frontdesk.grc', $stay) }}" target="_blank" rel="noopener noreferrer"><x-icon name="print" size="sm"/> Print GRC</a>
                  @if(can_do('frontdesk.checkin'))<a role="menuitem" href="{{ route('frontdesk.grc.sign', $stay) }}"><x-icon name="edit" size="sm"/> e-GRC sign / ID</a>@endif
                @elseif($arrival)
                  @if(can_do('frontdesk.checkin'))<a role="menuitem" href="{{ route('frontdesk.checkin', $arrival) }}"><x-icon name="login" size="sm"/> Check-in {{ $arrival->reservation->reservation_no }}</a>@endif
                  @if(can_do('reservations.view'))<a role="menuitem" href="{{ route('reservations.show', $arrival->reservation_id) }}"><x-icon name="eye" size="sm"/> View reservation</a>@endif
                @elseif($room->view_status === 'vacant')
                  @if(can_do('frontdesk.checkin'))<a role="menuitem" href="{{ route('frontdesk.walkin', ['room_id' => $room->id]) }}"><x-icon name="login" size="sm"/> Walk-in check-in</a>@endif
                  @if(can_do('reservations.create'))<a role="menuitem" href="{{ route('reservations.create', ['room_type_id' => $room->room_type_id, 'room_id' => $room->id]) }}"><x-icon name="calendar" size="sm"/> New reservation</a>@endif
                @endif
                @if(can_do('housekeeping.manage'))
                  @if($stay || $arrival)<hr>@endif
                  @foreach(['clean' => 'Mark clean', 'dirty' => 'Mark dirty', 'inspected' => 'Mark inspected'] as $key => $label)
                    @if($room->hk_status !== $key)<form method="post" action="{{ route('housekeeping.status') }}" role="none">@csrf<input type="hidden" name="rooms[]" value="{{ $room->id }}"><input type="hidden" name="hk_status" value="{{ $key }}"><button type="submit" role="menuitem"><x-icon name="broom" size="sm"/> {{ $label }}</button></form>@endif
                  @endforeach
                  @if($room->view_status === 'vacant')<form method="post" action="{{ route('housekeeping.status') }}" role="none">@csrf<input type="hidden" name="rooms[]" value="{{ $room->id }}"><input type="hidden" name="status" value="out_of_order"><button type="submit" role="menuitem"><x-icon name="tool" size="sm"/> Set out of order</button></form>@endif
                  @if(in_array($room->status, ['out_of_order','out_of_service'], true))<form method="post" action="{{ route('housekeeping.status') }}" role="none">@csrf<input type="hidden" name="rooms[]" value="{{ $room->id }}"><input type="hidden" name="status" value="vacant"><button type="submit" role="menuitem"><x-icon name="check" size="sm"/> Return to service</button></form>@endif
                @endif
              </div>
            @endif
          </article>
        @endforeach
      </div>
    </section>
  @empty
    <section class="room-view-empty" aria-labelledby="room-empty-heading"><span class="room-view-empty-icon"><x-icon name="bed"/></span><h2 id="room-empty-heading">No rooms match these filters</h2><p>Try another room type or status, or clear the filters to see the full active inventory.</p><a class="btn btn-primary" href="{{ route('frontdesk.rooms') }}">Clear filters</a></section>
  @endforelse
  </section>

  <section class="room-view-search-empty" id="room-view-search-empty" role="status" hidden><span class="room-view-empty-icon"><x-icon name="search"/></span><h2>No rooms match that search</h2><p>Try a room number, guest name or reservation reference.</p></section>
</div>
@endsection
@push('scripts')
<script>
(() => {
  const search = document.querySelector('[data-room-search]');
  const cards = [...document.querySelectorAll('[data-room-card]')];
  const groups = [...document.querySelectorAll('[data-room-group]')];
  const resultCount = document.getElementById('room-view-result-count');
  const searchEmpty = document.getElementById('room-view-search-empty');
  const totalRooms = {{ (int) $totalRooms }};

  search?.addEventListener('input', () => {
    const query = search.value.trim().toLocaleLowerCase();
    let visible = 0;
    cards.forEach(card => {
      const matches = !query || (card.dataset.roomSearch || '').toLocaleLowerCase().includes(query);
      card.hidden = !matches;
      if (matches) visible++;
    });
    groups.forEach(group => { group.hidden = !group.querySelector('[data-room-card]:not([hidden])'); });
    if (resultCount) resultCount.textContent = `Showing ${visible} of ${cards.length} rooms matching current filters · ${totalRooms} in this inventory view.`;
    if (searchEmpty) searchEmpty.hidden = visible > 0 || cards.length === 0;
  });

  const syncRoomMenus = () => document.querySelectorAll('[data-room-menu-trigger]').forEach(trigger => {
    const menu = document.getElementById(trigger.dataset.dropdown);
    trigger.setAttribute('aria-expanded', menu?.classList.contains('show') ? 'true' : 'false');
  });
  document.addEventListener('click', event => {
    const trigger = event.target.closest('[data-room-menu-trigger]');
    requestAnimationFrame(() => {
      syncRoomMenus();
      if (trigger && document.getElementById(trigger.dataset.dropdown)?.classList.contains('show')) {
        document.getElementById(trigger.dataset.dropdown)?.querySelector('[role="menuitem"]')?.focus();
      }
    });
  });
  document.addEventListener('keydown', event => {
    const trigger = event.target.closest('[data-room-menu-trigger]');
    if (trigger && event.key === 'ArrowDown') {
      event.preventDefault();
      if (trigger.getAttribute('aria-expanded') !== 'true') trigger.click();
      requestAnimationFrame(() => document.getElementById(trigger.dataset.dropdown)?.querySelector('[role="menuitem"]')?.focus());
      return;
    }
    const activeItem = event.target.closest('.room-view-menu [role="menuitem"]');
    if (activeItem && ['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
      const items = [...activeItem.closest('[role="menu"]')?.querySelectorAll('[role="menuitem"]') || []];
      const index = items.indexOf(activeItem);
      if (event.key === 'Home') items[0]?.focus();
      else if (event.key === 'End') items.at(-1)?.focus();
      else items[(index + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length]?.focus();
      event.preventDefault();
      return;
    }
    if (event.key !== 'Escape') return;
    const open = [...document.querySelectorAll('[data-room-menu-trigger][aria-expanded="true"]')];
    open.forEach(menuButton => {
      document.getElementById(menuButton.dataset.dropdown)?.classList.remove('show');
      menuButton.setAttribute('aria-expanded', 'false');
    });
    open[0]?.focus();
  });
})();
</script>
@endpush
