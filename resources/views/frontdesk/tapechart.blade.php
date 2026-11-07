@extends('layouts.app')
@section('title', 'Tape Chart')
@section('crumbs') Front Office / Tape Chart @endsection
@section('actions')
  <a class="btn" href="{{ route('frontdesk.rooms') }}"><x-icon name="bed" size="sm"/> Room view</a>
  <a class="btn btn-soft" href="{{ route('frontdesk.inhouse') }}"><x-icon name="users" size="sm"/> In-house</a>
  <button class="btn" type="button" onclick="window.print()"><x-icon name="print" size="sm"/> Print</button>
@endsection
@push('head')
<style>
  .tape-chart-page { color:var(--c-text); }
  .tape-chart-hero { display:flex; align-items:flex-start; justify-content:space-between; gap:1rem; margin:0 0 1rem; padding:1rem 1.15rem; border:1px solid #dcebe0; border-radius:14px; background:linear-gradient(115deg,#f0f8f2 0%,var(--c-surface) 72%); }
  .tape-chart-kicker { display:flex; align-items:center; gap:.45rem; color:#17623b; font-size:.72rem; font-weight:800; letter-spacing:.09em; text-transform:uppercase; }
  .tape-chart-kicker .i { width:15px; height:15px; }
  .tape-chart-hero p { max-width:700px; margin:.4rem 0 0; color:var(--c-text-2); font-size:.9rem; }
  .tape-chart-window { display:inline-flex; flex:0 0 auto; align-items:center; gap:.5rem; padding:.58rem .72rem; border:1px solid #dcebe0; border-radius:10px; background:#fff; color:#24583a; font-weight:700; font-variant-numeric:tabular-nums; }
  .tape-chart-window .i { color:#17623b; }
  .tape-chart-metrics { display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:.7rem; margin-bottom:1rem; }
  .tape-chart-metric { min-width:0; padding:.78rem .85rem; border:1px solid var(--c-border); border-radius:12px; background:var(--c-surface); box-shadow:var(--shadow-sm); }
  .tape-chart-metric b { display:block; font-size:1.32rem; line-height:1.1; font-weight:780; font-variant-numeric:tabular-nums; }
  .tape-chart-metric span { display:block; margin-top:.22rem; color:var(--c-text-2); font-size:.75rem; }
  .tape-chart-metric.rooms { border-top:3px solid #17623b; }.tape-chart-metric.arrivals { border-top:3px solid #2563eb; }.tape-chart-metric.staying { border-top:3px solid #dc2626; }.tape-chart-metric.departures { border-top:3px solid #d97706; }.tape-chart-metric.unassigned { border-top:3px solid #7c3aed; }
  .tape-chart-controls { display:flex; align-items:flex-end; justify-content:space-between; gap:.75rem; flex-wrap:wrap; margin-bottom:1rem; padding:.85rem 1rem; border:1px solid var(--c-border); border-radius:14px; background:var(--c-surface); box-shadow:var(--shadow-sm); }
  .tape-chart-controls-main { display:flex; align-items:flex-end; gap:.55rem; flex-wrap:wrap; }
  .tape-chart-control label { display:block; margin-bottom:.26rem; color:var(--c-text-2); font-size:.74rem; font-weight:700; }
  .tape-chart-control input,.tape-chart-control select { min-height:38px; padding:.45rem .62rem; border:1px solid var(--c-border-strong); border-radius:8px; background:var(--c-surface); color:var(--c-text); font:inherit; }
  .tape-chart-control input { width:160px; }.tape-chart-control select { min-width:105px; }
  .tape-chart-window-nav { display:flex; align-items:center; gap:.35rem; }
  .tape-chart-window-nav a { min-height:37px; display:inline-flex; align-items:center; justify-content:center; gap:.35rem; padding:.45rem .7rem; border:1px solid var(--c-border); border-radius:8px; background:var(--c-surface); color:var(--c-text-2); font-size:.8rem; font-weight:650; white-space:nowrap; }
  .tape-chart-window-nav a:hover { border-color:#9bc8aa; background:#eff8f1; color:#17623b; text-decoration:none; }
  .tape-chart-window-nav .i { width:14px; height:14px; }
  .tape-chart-help { margin:0 0 1rem; padding:.65rem .8rem; border:1px solid #dcebe0; border-radius:10px; background:#f5faf6; color:#3c5946; font-size:.78rem; }
  .tape-chart-help b { color:#195a35; }
  .tape-chart-unassigned { margin-bottom:1rem; padding:.75rem .9rem; border:1px solid var(--c-border); border-radius:13px; background:var(--c-surface); box-shadow:var(--shadow-sm); }
  .tape-chart-unassigned-head { display:flex; align-items:center; justify-content:space-between; gap:.7rem; margin-bottom:.55rem; }
  .tape-chart-unassigned-head h2 { margin:0; font-size:.9rem; }
  .tape-chart-unassigned-head p { margin:.12rem 0 0; color:var(--c-text-2); font-size:.72rem; }
  .tape-chart-unassigned-list { display:flex; flex-wrap:wrap; align-items:center; gap:.45rem; }
  .tape-chart-chip { display:inline-flex; align-items:center; gap:.38rem; max-width:100%; padding:.36rem .62rem; border:1px solid #b9dbc3; border-radius:9px; background:#edf8f0; color:#174f2f; font-size:.76rem; font-weight:700; cursor:grab; }
  .tape-chart-chip .i { width:14px; height:14px; flex:none; }
  .tape-chart-chip:active { cursor:grabbing; }
  .tape-chart-chip small { color:#496956; font-size:.68rem; font-weight:500; }
  .tape-chart-scroll { position:relative; overflow:auto; max-height:min(70vh,780px); border:1px solid var(--c-border); border-radius:13px; background:var(--c-surface); box-shadow:var(--shadow-sm); scrollbar-width:thin; }
  .tape-chart-scroll:focus-visible { outline:3px solid rgba(34,139,78,.28); outline-offset:2px; }
  .tape-chart-table { --tape-day-width:58px; width:max-content; min-width:100%; border-collapse:separate; border-spacing:0; background:var(--c-surface); }
  .tape-chart-table th,.tape-chart-table td { height:41px; padding:0; border-right:1px solid #e5ece7; border-bottom:1px solid #e5ece7; font-size:.76rem; }
  .tape-chart-table thead th { position:sticky; top:0; z-index:4; min-width:var(--tape-day-width); width:var(--tape-day-width); height:49px; padding:.3rem .12rem; background:#f7faf8; color:var(--c-text-2); text-align:center; font-weight:600; }
  .tape-chart-table thead th.tape-today { background:#e9f7ed; color:#14532d; }
  .tape-chart-table thead th.tape-weekend { background:#f8f5ee; }
  .tape-chart-table thead th.tape-today.tape-weekend { background:#e9f7ed; }
  .tape-chart-table thead time { font-variant-numeric:tabular-nums; }
  .tape-chart-table .tape-room-heading,.tape-chart-table .tape-room-label { position:sticky; left:0; z-index:5; width:150px; min-width:150px; max-width:150px; padding:0 .65rem; background:var(--c-surface); text-align:left; white-space:nowrap; }
  .tape-chart-table thead .tape-room-heading { z-index:7; background:#edf3ef; }
  .tape-chart-table .tape-room-label { font-weight:750; }
  .tape-chart-room-copy { display:inline-flex; align-items:center; gap:.35rem; min-width:0; }
  .tape-chart-room-copy small { color:var(--c-muted); font-size:.66rem; font-weight:500; }
  .tape-chart-room-state { display:inline-flex; align-items:center; padding:.12rem .32rem; border:1px solid #d2dce5; border-radius:999px; background:#f1f5f9; color:#475569; font-size:.6rem; font-weight:750; }
  .tape-chart-room-hk { display:inline-block; width:8px; height:8px; flex:none; border-radius:50%; }
  .tape-chart-room-hk.hk-clean { background:#16a34a; }.tape-chart-room-hk.hk-dirty { background:#dc2626; }.tape-chart-room-hk.hk-inspected { background:#2563eb; }.tape-chart-room-hk.hk-touch_up { background:#d97706; }
  .tape-chart-group td { position:static; height:30px; padding:.35rem .65rem; background:#eff4f0; color:#52645a; font-size:.7rem; font-weight:800; letter-spacing:.06em; text-transform:uppercase; }
  .tape-chart-table td.tape-cell { position:relative; min-width:var(--tape-day-width); width:var(--tape-day-width); max-width:var(--tape-day-width); }
  .tape-chart-table tbody td.tape-today { background:#f1faf4; box-shadow:inset 1px 0 0 #d7e9de, inset -1px 0 0 #d7e9de; }
  body.dark .tape-chart-table tbody td.tape-today { background:#152419; box-shadow:inset 1px 0 0 #29422f, inset -1px 0 0 #29422f; }
  .tape-chart-table tbody td.tape-out { background-color:#f4f6f8; background-image:repeating-linear-gradient(135deg,rgba(148,163,184,.34) 0 5px,transparent 5px 10px); }
  .tape-chart-table tbody td.tape-out.tape-today { background-color:#eaf2ec; }
  body.dark .tape-chart-table tbody td.tape-out { background-color:#1b2229; background-image:repeating-linear-gradient(135deg,rgba(148,163,184,.22) 0 5px,transparent 5px 10px); }
  .tape-chart-table tbody tr.tape-row-out .tape-room-label { background:#f2f5f7; }
  body.dark .tape-chart-table tbody tr.tape-row-out .tape-room-label { background:#1b2229; }
  .tape-chart-legend i.tape-legend-out { background:repeating-linear-gradient(135deg,rgba(148,163,184,.6) 0 3px,#f4f6f8 3px 6px); box-shadow:inset 0 0 0 1px #cfd8e0; }
  .tape-chart-table .tape-bar.tape-block { background:#e5e9ee repeating-linear-gradient(135deg,#c8d2dc 0 5px,transparent 5px 10px); color:#33415a; cursor:default; box-shadow:none; }
  body.dark .tape-chart-table .tape-bar.tape-block { background:#28323c repeating-linear-gradient(135deg,#3b4854 0 5px,transparent 5px 10px); color:#cbd5e1; }
  .tape-chart-legend i.tape-legend-block { background:repeating-linear-gradient(135deg,#c8d2dc 0 3px,#eef2f6 3px 6px); box-shadow:inset 0 0 0 1px #cfd8e0; }
  .tape-chart-legend i.tape-legend-dayuse { background:#eef2ff; box-shadow:inset 0 0 0 1.5px #6366f1; }
  .tape-chart-table tbody tr.tape-room-row:hover td { background-color:#f7fbf8; }
  .tape-chart-table tfoot th,.tape-chart-table tfoot td { position:sticky; bottom:0; z-index:3; height:32px; padding:.2rem .3rem; border-top:1px solid #d5e5db; border-right:1px solid #e5ece7; background:#f1f7f3; color:#25422f; font-size:.72rem; font-weight:780; text-align:center; font-variant-numeric:tabular-nums; }
  .tape-chart-table tfoot tr:first-child th,.tape-chart-table tfoot tr:first-child td { bottom:32px; }
  .tape-chart-table tfoot .tape-room-label { z-index:6; padding:0 .65rem; background:#e8f1ea; color:#3c5946; font-size:.7rem; font-weight:800; letter-spacing:.04em; text-transform:uppercase; text-align:left; }
  .tape-chart-table tfoot .tape-total-pct { font-size:.7rem; }
  .tape-chart-table tfoot .pct-high { background:#fde8e8; color:#991b1b; }
  .tape-chart-table tfoot .pct-mid { background:#fdf3df; color:#8a5a09; }
  /* A night with rooms off the market runs on a smaller base: say so without
     changing the colour, which still means "how full". */
  .tape-chart-table tfoot .tape-blocked-night { background-image:repeating-linear-gradient(135deg,rgba(120,138,156,.36) 0 4px,transparent 4px 8px); }
  body.dark .tape-chart-table tfoot .tape-blocked-night { background-image:repeating-linear-gradient(135deg,rgba(148,163,184,.28) 0 4px,transparent 4px 8px); }
  .tape-chart-room-row[tabindex="0"]:focus-visible .tape-room-label { outline:3px solid #2563eb; outline-offset:-3px; }
  .tape-chart-table tbody tr.tape-room-row:hover .tape-room-label { background:#f7fbf8; }
  .tape-chart-table tbody tr.drop-over td { background:#e4f1ff!important; }
  .tape-chart-table tbody tr.drop-over .tape-room-label { box-shadow:inset 4px 0 0 #2563eb; }
  .tape-chart-table .tape-cell a.tape-new { position:absolute; inset:0; z-index:0; }
  .tape-chart-table .tape-cell a.tape-new:hover { background:#eff8f1; }
  .tape-chart-table .tape-bar { position:absolute; top:5px; bottom:5px; left:2px; z-index:2; display:flex; align-items:center; overflow:hidden; padding:0 .42rem; border-radius:6px; color:#fff; font-size:.7rem; font-weight:700; text-overflow:ellipsis; white-space:nowrap; cursor:grab; box-shadow:0 1px 3px rgba(15,23,42,.18); }
  .tape-chart-table .tape-bar:active { cursor:grabbing; }
  .tape-chart-table .tape-bar.b-day_use { background:#eef2ff; color:#3730a3; box-shadow:inset 0 0 0 1.5px #6366f1; }
  body.dark .tape-chart-table .tape-bar.b-day_use { background:#242a52; color:#c7ccff; box-shadow:inset 0 0 0 1.5px #6b74c9; }
  .tape-chart-table .tape-bar.b-reserved { background:#166534; }.tape-chart-table .tape-bar.b-checked_in { background:#b91c1c; }.tape-chart-table .tape-bar.b-checked_out { background:#475569; }.tape-chart-table .tape-bar.b-tentative { background:#92400e; }
  .tape-chart-table .tape-bar:focus-visible,.tape-chart-chip:focus-visible,.tape-chart-source-selected { outline:3px solid #2563eb; outline-offset:2px; }
  .tape-chart-status { min-height:1.3rem; margin:.5rem .1rem; color:var(--c-text-2); font-size:.76rem; }
  .tape-chart-summary { display:flex; align-items:center; gap:.4rem; flex-wrap:wrap; margin:.15rem .1rem .1rem; color:var(--c-text-2); font-size:.76rem; }
  .tape-chart-summary b { color:var(--c-text); font-variant-numeric:tabular-nums; }
  .tape-chart-basis { margin:.55rem .1rem 0; color:var(--c-text-2); font-size:.74rem; }
  .tape-chart-basis b { color:var(--c-text); font-variant-numeric:tabular-nums; }
  .tape-chart-summary .i { width:14px; height:14px; color:#17623b; }
  body.dark .tape-chart-summary .i { color:#86efac; }
  .tape-chart-legend { display:flex; align-items:center; gap:.75rem; flex-wrap:wrap; margin-top:.65rem; color:var(--c-text-2); font-size:.74rem; }
  .tape-chart-legend span { display:inline-flex; align-items:center; gap:.34rem; }
  .tape-chart-legend i { width:11px; height:11px; border-radius:3px; }
  .tape-chart-legend .tape-legend-note { color:var(--c-muted); font-style:normal; font-size:.68rem; }
  .tape-unassigned-details { margin-top:1rem; border:1px solid var(--c-border); border-radius:12px; background:var(--c-surface); overflow:hidden; }
  .tape-unassigned-details summary { padding:.75rem .9rem; color:var(--c-text); font-size:.84rem; font-weight:700; cursor:pointer; }
  .tape-unassigned-details[open] summary { border-bottom:1px solid var(--c-border); }
  .tape-unassigned-table-wrap { overflow-x:auto; }
  .tape-unassigned-table { width:100%; border-collapse:collapse; font-size:.8rem; }
  .tape-unassigned-table th,.tape-unassigned-table td { padding:.55rem .7rem; border-bottom:1px solid var(--c-border); text-align:left; }
  .tape-unassigned-table th { color:var(--c-text-2); background:#f7faf8; font-size:.68rem; text-transform:uppercase; letter-spacing:.05em; }
  .tape-chart-empty { padding:2rem 1rem; color:var(--c-text-2); text-align:center; font-size:.85rem; }
  body.dark .tape-chart-hero { border-color:var(--c-border); background:linear-gradient(115deg,#172c1f 0%,var(--c-surface) 75%); }
  body.dark .tape-chart-kicker,body.dark .tape-chart-window,body.dark .tape-chart-window .i { color:#bce8c9; }
  body.dark .tape-chart-window { border-color:var(--c-border); background:#172c1f; }
  body.dark .tape-chart-help { border-color:#34553d; background:#172c1f; color:#c9dfce; }
  body.dark .tape-chart-help b { color:#bce8c9; }
  body.dark .tape-chart-chip { border-color:#315a3e; background:#193525; color:#bce8c9; }
  body.dark .tape-chart-chip small { color:#b1caba; }
  body.dark .tape-chart-table th,body.dark .tape-chart-table .tape-room-heading { background:#18271e; color:var(--c-text-2); }
  body.dark .tape-chart-table thead th.tape-today { background:#193525; color:#bce8c9; }
  body.dark .tape-chart-table thead th.tape-weekend { background:#2b281e; }
  body.dark .tape-chart-table thead th.tape-today.tape-weekend { background:#193525; }
  body.dark .tape-chart-table .tape-room-label,body.dark .tape-chart-table tbody tr.tape-room-row:hover .tape-room-label { background:var(--c-surface); }
  body.dark .tape-chart-group td { background:#1d2c23; color:var(--c-text-2); }
  body.dark .tape-chart-table tbody tr.tape-room-row:hover td { background:#18271e; }
  body.dark .tape-chart-table tfoot th,body.dark .tape-chart-table tfoot td { background:#1b2b21; border-top-color:var(--c-border); border-right-color:var(--c-border); color:#cfe6d6; }
  body.dark .tape-chart-table tfoot .tape-room-label { background:#16241c; color:#a9c3b3; }
  body.dark .tape-chart-table tfoot .pct-high { background:#3b1d24; color:#fda4af; }
  body.dark .tape-chart-table tfoot .pct-mid { background:#3a2a12; color:#fcd34d; }
  body.dark .tape-chart-room-state { border-color:#465462; background:#25303a; color:#d0d9e2; }
  body.dark .tape-chart-unassigned-details summary,body.dark .tape-unassigned-table th { background:#18271e; color:var(--c-text-2); }
  @media(max-width:1000px) { .tape-chart-metrics { grid-template-columns:repeat(3,minmax(0,1fr)); } }
  @media(max-width:700px) { .tape-chart-metrics { grid-template-columns:repeat(2,minmax(0,1fr)); gap:.5rem; }.tape-chart-metric { padding:.68rem .72rem; }.tape-chart-metric b { font-size:1.18rem; }.tape-chart-hero { display:block; padding:.85rem; }.tape-chart-window { margin-top:.7rem; }.tape-chart-controls { align-items:stretch; }.tape-chart-controls-main { width:100%; }.tape-chart-window-nav { width:100%; justify-content:space-between; }.tape-chart-window-nav a { flex:1; }.tape-chart-table { --tape-day-width:50px; }.tape-chart-table .tape-room-heading,.tape-chart-table .tape-room-label { width:126px; min-width:126px; max-width:126px; }.tape-chart-scroll { max-height:70vh; } }
  @media(prefers-reduced-motion:reduce) { .tape-chart-table .tape-bar { scroll-behavior:auto; } }
  @media print { .tape-chart-controls,.tape-chart-help,.tape-chart-status,.tape-chart-chip,.tape-chart-unassigned,.tape-unassigned-details,.no-print { display:none!important; }.tape-chart-hero .tape-chart-window { border:0; background:none; }.tape-chart-metrics { grid-template-columns:repeat(5,1fr); }.tape-chart-scroll { max-height:none; overflow:visible; border:0; box-shadow:none; }.tape-chart-table { --tape-day-width:32px; width:100%; }.tape-chart-table .tape-room-heading,.tape-chart-table .tape-room-label { width:92px; min-width:92px; max-width:92px; }.tape-chart-table th,.tape-chart-table td { font-size:7pt; }.tape-chart-table .tape-bar { font-size:6pt; } .tape-chart-table tfoot th,.tape-chart-table tfoot td { position:static; font-size:6.5pt; }.tape-chart-group { break-after:avoid; } }
</style>
@endpush

@section('content')
@php
  $windowLastDay = $to->copy()->subDay();
  $windowLabel = $from->format('j M Y').' – '.$windowLastDay->format('j M Y');
  $dnd = $canDrag;
  $dayOptions = [7, 14, 21, 30];
  if (! in_array($days, $dayOptions, true)) { $dayOptions[] = $days; sort($dayOptions); }
@endphp

<div class="tape-chart-page">
  <section class="tape-chart-hero" aria-label="Tape chart overview">
    <div><div class="tape-chart-kicker"><x-icon name="calendar"/> Front office · Availability map</div><p>Track room stays across the selected window. Select a booking to open its reservation; with move permission, drag a stay onto another room to reassign it.</p></div>
    <div class="tape-chart-window"><x-icon name="calendar" size="sm"/><time datetime="{{ $from->toDateString() }}">{{ $windowLabel }}</time></div>
  </section>

  <section class="tape-chart-metrics" aria-label="Tape chart summary">
    <div class="tape-chart-metric rooms"><b>{{ number_format($summary['rooms']) }}</b><span>Active rooms</span></div>
    <div class="tape-chart-metric arrivals"><b>{{ number_format($summary['arrivals']) }}</b><span>Arrivals in window</span></div>
    <div class="tape-chart-metric staying"><b>{{ number_format($summary['inHouse']) }}</b><span>In-house stays in window</span></div>
    <div class="tape-chart-metric departures"><b>{{ number_format($summary['departures']) }}</b><span>Due-outs in window</span></div>
    <div class="tape-chart-metric unassigned"><b>{{ number_format($summary['unassigned']) }}</b><span>Unassigned stays</span></div>
  </section>

  <section class="tape-chart-controls" aria-label="Tape chart date controls">
    <form class="tape-chart-controls-main" method="get" action="{{ route('frontdesk.tapechart') }}">
      <div class="tape-chart-control"><label for="tape-from">Window starts</label><input id="tape-from" type="date" name="from" value="{{ $from->toDateString() }}"></div>
      <div class="tape-chart-control"><label for="tape-days">Window length</label><select id="tape-days" name="days">@foreach($dayOptions as $option)<option value="{{ $option }}" @selected($days === $option)>{{ $option }} days</option>@endforeach</select></div>
      <button class="btn btn-primary" type="submit"><x-icon name="search" size="sm"/> Update chart</button>
    </form>
    <nav class="tape-chart-window-nav" aria-label="Navigate chart window">
      <a href="{{ route('frontdesk.tapechart', ['from' => $from->copy()->subDays(7)->toDateString(), 'days' => $days]) }}"><x-icon name="chev"/> Previous week</a>
      <a href="{{ route('frontdesk.tapechart') }}">Today</a>
      <a href="{{ route('frontdesk.tapechart', ['from' => $from->copy()->addDays(7)->toDateString(), 'days' => $days]) }}">Next week <x-icon name="chev"/></a>
    </nav>
  </section>

  @if($dnd)
    <p class="tape-chart-help"><b>Move / assign:</b> drag a stay bar or unassigned chip onto an active room. Keyboard: focus a movable stay and press Space, use the Up/Down arrows to choose a room, then press Enter; Escape cancels. In-house moves mark the previous room vacant and dirty. The destination is rechecked before saving.</p>
  @endif

  @if($unassigned->isNotEmpty())
    <section class="tape-chart-unassigned" aria-labelledby="tape-unassigned-title">
      <div class="tape-chart-unassigned-head"><div><h2 id="tape-unassigned-title">Unassigned stays <span class="badge badge-purple">{{ $unassigned->count() }}</span></h2><p>{{ $dnd ? 'Drag a stay chip onto a room row to assign it.' : 'These reservations need a room assignment.' }}</p></div></div>
      <div class="tape-chart-unassigned-list">
        @foreach($unassigned as $stay)
          @php $chipLabel = $stay->reservation?->reservation_no.' · '.($stay->reservation?->guest?->full_name ?? 'Guest unavailable'); @endphp
          @if($dnd)<span class="tape-chart-chip" draggable="true" tabindex="0" role="button" aria-label="Unassigned stay {{ $chipLabel }}. Press Space to select, use the arrow keys to choose a room, then press Enter to assign." data-stay="{{ $stay->id }}" data-label="{{ $chipLabel }}" data-inhouse="0" title="{{ $stay->roomType?->name }} · {{ $stay->arrival_date->format('d M') }} to {{ $stay->departure_date->format('d M') }}"><x-icon name="grid"/>{{ $stay->reservation?->guest?->full_name ?? 'Guest unavailable' }} <small>{{ $stay->roomType?->code ?? $stay->roomType?->name }} · {{ $stay->arrival_date->format('j M') }}–{{ $stay->departure_date->format('j M') }}</small></span>
          @elseif($canViewReservation)<a class="tape-chart-chip" href="{{ route('reservations.show', $stay->reservation_id) }}"><x-icon name="eye"/>{{ $stay->reservation?->reservation_no }} · {{ $stay->reservation?->guest?->full_name }}</a>
          @else<span class="tape-chart-chip">{{ $stay->reservation?->reservation_no }} · {{ $stay->reservation?->guest?->full_name }}</span>@endif
        @endforeach
      </div>
    </section>
  @endif

  <div class="tape-chart-scroll" tabindex="0" role="region" aria-label="Room availability chart. Scroll horizontally to see more dates and vertically to see more rooms.">
    <table class="tape-chart-table">
      <caption class="sr-only">Room bookings by date from {{ $from->format('j F Y') }} through {{ $windowLastDay->format('j F Y') }}.</caption>
      <thead><tr><th class="tape-room-heading" scope="col">Room</th>
        @for($i=0; $i<$days; $i++) @php $day = $from->copy()->addDays($i); @endphp
          <th scope="col" @if($day->isSameDay($today)) aria-current="date" @endif class="{{ $day->isSameDay($today) ? 'tape-today' : '' }} {{ $day->isWeekend() ? 'tape-weekend' : '' }}"><span>{{ $day->format('D') }}</span><br><time datetime="{{ $day->toDateString() }}">{{ $day->format('d M') }}</time></th>
        @endfor
      </tr></thead>
      <tbody>
      @forelse($rooms as $type => $roomList)
        <tr class="tape-chart-group"><td colspan="{{ $days + 1 }}">{{ $type }} · {{ $roomList->count() }} {{ \Illuminate\Support\Str::plural('room', $roomList->count()) }}</td></tr>
        @foreach($roomList as $room)
          @php $roomDropDisabled = in_array($room->status, ['out_of_order','out_of_service'], true); @endphp
          <tr class="tape-chart-room-row{{ $roomDropDisabled ? ' tape-row-out' : '' }}" @if($dnd && ! $roomDropDisabled) tabindex="0" aria-label="Destination room {{ $room->room_no }}. Press Enter to assign or move the selected stay here." @endif data-room="{{ $room->id }}" data-room-no="{{ $room->room_no }}" @if($roomDropDisabled) data-room-disabled="1" @endif>
            <th scope="row" class="tape-room-label"><span class="tape-chart-room-copy">{{ $room->room_no }} <small>{{ $room->roomType?->code }}</small>
              @if($roomDropDisabled)<span class="tape-chart-room-state">{{ $room->status === 'out_of_service' ? 'OOS' : 'OOO' }}</span>@endif
              @if($canViewHousekeeping)<span class="tape-chart-room-hk hk-{{ $room->hk_status }}" role="img" aria-label="Housekeeping {{ str_replace('_', ' ', $room->hk_status) }}" title="Housekeeping: {{ str_replace('_', ' ', $room->hk_status) }}"></span>@endif
            </span></th>
            @for($i=0; $i<$days; $i++) @php $day = $from->copy()->addDays($i); @endphp
              <td class="tape-cell{{ $day->isSameDay($today) ? ' tape-today' : '' }}{{ $roomDropDisabled ? ' tape-out' : '' }}">
                @foreach(($byRoom[$room->id] ?? collect()) as $stay)
                  @php
                    $barStart = $stay->arrival_date->gt($from) ? $stay->arrival_date : $from;
                    $barEnd = $stay->departure_date->lt($to) ? $stay->departure_date : $to;
                    $spanDays = max(1, (int) $barStart->diffInDays($barEnd));
                    $barTitle = ($stay->reservation?->reservation_no ?? 'Reservation').' · '.($stay->reservation?->guest?->full_name ?? 'Guest unavailable').' · '.$stay->arrival_date->format('d M Y').' to '.$stay->departure_date->format('d M Y');
                    // A day use holds the room for hours, not a night: drawn hollow, never counted.
                    $barClass = 'b-'.($stay->is_day_use ? 'day_use' : ($stay->reservation?->status === 'tentative' ? 'tentative' : $stay->status));
                    $barCanMove = $dnd && ($stay->status === 'reserved' || ($stay->status === 'checked_in' && $canMoveInHouse));
                  @endphp
                  @if($barStart->isSameDay($day))
                    @if($canViewReservation)<a class="bar tape-bar {{ $barClass }}" @if($dnd && ($stay->status === 'reserved' || ($stay->status === 'checked_in' && $canMoveInHouse))) draggable="true" data-stay="{{ $stay->id }}" data-label="{{ $stay->reservation?->reservation_no }} · {{ $stay->reservation?->guest?->full_name }}" data-inhouse="{{ $stay->status === 'checked_in' ? 1 : 0 }}" @endif data-span-days="{{ $spanDays }}" href="{{ route('reservations.show', $stay->reservation_id) }}" aria-label="{{ $barTitle }}" title="{{ $barTitle }}">{{ $stay->reservation?->guest?->full_name ?? $stay->reservation?->reservation_no }}</a>
                    @else<span class="bar tape-bar {{ $barClass }}" @if($dnd && ($stay->status === 'reserved' || ($stay->status === 'checked_in' && $canMoveInHouse))) draggable="true" data-stay="{{ $stay->id }}" data-label="{{ $stay->reservation?->reservation_no }} · {{ $stay->reservation?->guest?->full_name }}" data-inhouse="{{ $stay->status === 'checked_in' ? 1 : 0 }}" @endif data-span-days="{{ $spanDays }}" @if($barCanMove) tabindex="0" role="button" aria-label="{{ $barTitle }}. Press Space to select, use the arrow keys to choose a room, then press Enter to move." @else role="img" aria-label="{{ $barTitle }}" @endif title="{{ $barTitle }}">{{ $stay->reservation?->guest?->full_name ?? $stay->reservation?->reservation_no }}</span>@endif
                  @endif
                @endforeach
                @foreach(($blocksByRoom[$room->id] ?? []) as $block)
                  @if($block['from']->isSameDay($day) || ($day->isSameDay($from) && $block['from']->lt($from)))
                    @php $blockSpan = max(1, (int) $block['from']->diffInDays($block['to']->lt($to) ? $block['to'] : $to)); @endphp
                    <span class="bar tape-bar tape-block" data-span-days="{{ $blockSpan }}" role="img" aria-label="{{ $block['title'] }}" title="{{ $block['title'] }}">{{ $block['short'] }}</span>
                  @endif
                @endforeach
                @if($day->gte($today) && !in_array($room->status, ['out_of_order','out_of_service'], true) && ! isset($blockedNights[$room->id.'|'.$day->toDateString()]) && can_do('reservations.create'))<a class="tape-new" href="{{ route('reservations.create', ['arrival' => $day->toDateString(), 'departure' => $day->copy()->addDay()->toDateString(), 'room_type_id' => $room->room_type_id, 'room_id' => $room->id]) }}" aria-label="New reservation in room {{ $room->room_no }} arriving {{ $day->format('j F Y') }}" title="New reservation · room {{ $room->room_no }} · {{ $day->format('d M') }}"></a>@endif
              </td>
            @endfor
          </tr>
        @endforeach
      @empty
        <tr><td colspan="{{ $days + 1 }}"><div class="tape-chart-empty">No active rooms in this property.</div></td></tr>
      @endforelse
      </tbody>
      <tfoot>
        <tr class="tape-chart-total">
          <th scope="row" class="tape-room-label">Occupied</th>
          @for($i=0; $i<$days; $i++) @php $daily = $dailyTotals[$i]; @endphp
            <td class="tape-cell tape-total{{ $daily['blocked'] > 0 ? ' tape-blocked-night' : '' }}"@if($daily['blocked'] > 0) title="{{ $daily['blocked'] }} {{ \Illuminate\Support\Str::plural('room', $daily['blocked']) }} blocked · {{ $daily['sellable'] }} of {{ $totalsSummary['sellable'] }} rooms for sale"@endif aria-label="{{ $daily['label'] }}: {{ $daily['occupied'] }} of {{ $daily['sellable'] }} sellable rooms occupied">{{ $daily['occupied'] }}</td>
          @endfor
        </tr>
        <tr class="tape-chart-total">
          <th scope="row" class="tape-room-label">Occupancy</th>
          @for($i=0; $i<$days; $i++) @php $daily = $dailyTotals[$i]; @endphp
            <td class="tape-cell tape-total tape-total-pct {{ $daily['pct'] >= 85 ? 'pct-high' : ($daily['pct'] >= 50 ? 'pct-mid' : '') }}{{ $daily['blocked'] > 0 ? ' tape-blocked-night' : '' }}"@if($daily['blocked'] > 0) title="{{ $daily['blocked'] }} {{ \Illuminate\Support\Str::plural('room', $daily['blocked']) }} blocked · share of {{ $daily['sellable'] }} rooms for sale"@endif aria-label="{{ $daily['label'] }}: {{ $daily['pct'] }} percent of {{ $daily['sellable'] }} sellable rooms">{{ $daily['pct'] }}%</td>
          @endfor
        </tr>
      </tfoot>
    </table>
  </div>
  <p class="tape-chart-basis">
    @if(count($totalsSummary['excluded']) === 0)
      Occupancy is measured against all <b>{{ $totalsSummary['active'] }}</b> active {{ \Illuminate\Support\Str::plural('room', $totalsSummary['active']) }}.
    @elseif(count($totalsSummary['excluded']) === 1)
      Occupancy is measured against <b>{{ $totalsSummary['sellable'] }}</b> of <b>{{ $totalsSummary['active'] }}</b> active rooms —
      room {{ $totalsSummary['excluded'][0]['room_no'] }} is {{ $totalsSummary['excluded'][0]['reason'] }}, so it is not for sale.
    @else
      Occupancy is measured against <b>{{ $totalsSummary['sellable'] }}</b> of <b>{{ $totalsSummary['active'] }}</b> active rooms —
      @foreach($totalsSummary['excluded'] as $room){{ $loop->first ? '' : ($loop->last ? ' and ' : ', ') }}room {{ $room['room_no'] }} ({{ $room['reason'] }})@endforeach are not for sale.
    @endif
    @if($totalsSummary['blockedNights'] > 0)
      <br>Dated blocks take those nights out of the count too:
      @foreach($totalsSummary['blockNotes'] as $note){{ $loop->first ? '' : ($loop->last ? ' and ' : ', ') }}{{ $note }}@endforeach.
    @endif
  </p>
  <p class="tape-chart-status" id="tape-chart-status" role="status" aria-live="polite"></p>

  <p class="tape-chart-summary">
    <x-icon name="chart" size="sm"/>
    <b>{{ number_format($totalsSummary['roomNights']) }}</b> {{ \Illuminate\Support\Str::plural('room-night', $totalsSummary['roomNights']) }} sold over
    {{ $days }} {{ \Illuminate\Support\Str::plural('night', $days) }} ·
    average occupancy <b>{{ $totalsSummary['avgPct'] }}%</b>
    @if($totalsSummary['peak'] && $totalsSummary['peak']['occupied'] > 0)
      · fullest night <b>{{ $totalsSummary['peak']['short'] }}</b> with
      {{ $totalsSummary['peak']['occupied'] }} {{ \Illuminate\Support\Str::plural('room', $totalsSummary['peak']['occupied']) }}
      ({{ $totalsSummary['peak']['pct'] }}%)
    @endif
  </p>

  <div class="tape-chart-legend" aria-label="Booking status legend">
    <span><i style="background:#166534"></i>Reserved</span><span><i style="background:#b91c1c"></i>In-house</span><span><i style="background:#92400e"></i>Tentative <em class="tape-legend-note">not counted</em></span><span><i style="background:#475569"></i>Checked out</span>
    @if($canViewHousekeeping)
      <span aria-hidden="true"><i class="tape-chart-room-hk hk-clean"></i>Clean</span>
      <span aria-hidden="true"><i class="tape-chart-room-hk hk-inspected"></i>Inspected</span>
      <span aria-hidden="true"><i class="tape-chart-room-hk hk-dirty"></i>Dirty</span>
      <span aria-hidden="true"><i class="tape-chart-room-hk hk-touch_up"></i>Touch-up</span>
    @endif
    @if(count($totalsSummary['excluded']) > 0)<span aria-hidden="true"><i class="tape-legend-out"></i>Not for sale</span>@endif
    @if($totalsSummary['blockedNights'] > 0)<span aria-hidden="true"><i class="tape-legend-block"></i>Blocked nights</span>@endif
    @if($hasDayUse)<span aria-hidden="true"><i class="tape-legend-dayuse"></i>Day use <em class="tape-legend-note">not a night</em></span>@endif
    @if(can_do('reservations.create'))<span>Click an empty cell to start a booking.</span>@endif
  </div>

  @if($unassigned->isNotEmpty())
    <details class="tape-unassigned-details">
      <summary>Unassigned reservation details · {{ $unassigned->count() }}</summary>
      <div class="tape-unassigned-table-wrap"><table class="tape-unassigned-table"><thead><tr><th scope="col">Reservation</th><th scope="col">Guest</th><th scope="col">Room type</th><th scope="col">Stay dates</th><th scope="col">Action</th></tr></thead><tbody>
        @foreach($unassigned as $stay)<tr><td>{{ $stay->reservation?->reservation_no }}</td><td>{{ $stay->reservation?->guest?->full_name }}</td><td>{{ $stay->roomType?->name }}</td><td><time datetime="{{ $stay->arrival_date->toDateString() }}">{{ $stay->arrival_date->format('d M Y') }}</time> → <time datetime="{{ $stay->departure_date->toDateString() }}">{{ $stay->departure_date->format('d M Y') }}</time></td><td>@if($canViewReservation)<a class="btn btn-sm" href="{{ route('reservations.show', $stay->reservation_id) }}">View booking</a>@else<span class="muted">—</span>@endif</td></tr>@endforeach
      </tbody></table></div>
    </details>
  @endif
</div>
@endsection
@if($dnd)
@push('scripts')
<script>
(() => {
  let drag = null;
  const status = document.getElementById('tape-chart-status');
  const clearSource = () => {
    document.body.classList.remove('dragging');
    document.querySelectorAll('.tape-chart-source-selected').forEach(el => el.classList.remove('tape-chart-source-selected'));
    document.querySelectorAll('.tape-chart-room-row.drop-over').forEach(row => row.classList.remove('drop-over'));
  };
  const cancelDrag = (message = 'Room assignment cancelled.') => {
    drag = null;
    clearSource();
    if (status) status.textContent = message;
  };
  const completeDrop = roomRow => {
    if (!drag || !roomRow) return;
    const selected = drag;
    drag = null;
    clearSource();
    const verb = selected.inhouse === '1' ? 'Move in-house guest' : 'Assign';
    const impact = selected.inhouse === '1' ? '\nThe current room will be marked vacant and dirty.' : '';
    if (!confirm(`${verb} ${selected.label} to room ${roomRow.dataset.roomNo}?${impact}`)) {
      if (status) status.textContent = 'Room assignment cancelled.';
      return;
    }
    if (status) status.textContent = `Updating room assignment for ${selected.label}…`;
    fetch(@json(route('frontdesk.tapechart.drop')), {
      method:'POST',
      headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},
      body:JSON.stringify({stay_id:Number(selected.stay),room_id:Number(roomRow.dataset.room)})
    }).then(response => response.json().then(data => ({ok:response.ok,data})))
      .then(({ok,data}) => {
        if (ok) { if (status) status.textContent = data.message || 'Room assignment updated.'; location.reload(); }
        else if (status) status.textContent = data.message || Object.values(data.errors || {})[0] || 'The stay could not be assigned to that room.';
      }).catch(() => { if (status) status.textContent = 'Network error. Room assignment was not saved; please try again.'; });
  };
  const roomRows = [...document.querySelectorAll('.tape-chart-room-row[data-room]:not([data-room-disabled="1"])')];
  const focusInitialDestination = item => {
    const sourceRoom = item.closest('.tape-chart-room-row');
    const destination = roomRows.find(row => !sourceRoom || row.dataset.room !== sourceRoom.dataset.room);
    if (destination) destination.focus();
    else if (status) status.textContent = 'No other active room is available as a destination.';
  };
  const draggableItems = document.querySelectorAll('[draggable="true"][data-stay]');
  draggableItems.forEach(item => {
    item.addEventListener('dragstart', event => {
      drag = item.dataset;
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('text/plain', item.dataset.stay);
      document.body.classList.add('dragging');
      if (status) status.textContent = `Moving ${item.dataset.label}. Drop it on a destination room.`;
    });
    item.addEventListener('dragend', () => { if (drag) cancelDrag('Move cancelled.'); });
    item.addEventListener('keydown', event => {
      if (event.key === 'Enter' && item.tagName === 'A' && !drag) return;
      if (!['Enter',' '].includes(event.key)) return;
      event.preventDefault();
      if (!drag) {
        drag = item.dataset;
        item.classList.add('tape-chart-source-selected');
        document.body.classList.add('dragging');
        if (status) status.textContent = `Selected ${item.dataset.label}. Use the Up and Down arrows to choose a destination room, then press Enter; press Escape to cancel.`;
        focusInitialDestination(item);
      } else if (drag.stay === item.dataset.stay) {
        cancelDrag('Move cancelled.');
      }
    });
  });
  roomRows.forEach(row => {
    row.addEventListener('dragover', event => { if (!drag) return; event.preventDefault(); event.dataTransfer.dropEffect = 'move'; row.classList.add('drop-over'); });
    row.addEventListener('dragleave', event => { if (!row.contains(event.relatedTarget)) row.classList.remove('drop-over'); });
    row.addEventListener('drop', event => { event.preventDefault(); row.classList.remove('drop-over'); completeDrop(row); });
    row.addEventListener('keydown', event => {
      if (!drag || !['Enter',' '].includes(event.key)) return;
      event.preventDefault();
      completeDrop(row);
    });
  });
  document.addEventListener('keydown', event => {
    if (!drag) return;
    if (event.key === 'Escape') { cancelDrag(); return; }
    if (!['ArrowDown','ArrowUp'].includes(event.key)) return;
    const currentRow = event.target.closest?.('.tape-chart-room-row[data-room]');
    const index = currentRow ? roomRows.indexOf(currentRow) : -1;
    if (index < 0) return;
    const nextRow = roomRows[index + (event.key === 'ArrowDown' ? 1 : -1)];
    if (nextRow) { event.preventDefault(); nextRow.focus(); }
  });

  const sizeBars = () => {
    const dayCell = document.querySelector('.tape-chart-table tbody .tape-cell');
    if (!dayCell) return;
    const dayWidth = dayCell.getBoundingClientRect().width;
    document.querySelectorAll('.tape-chart-table .tape-bar[data-span-days]').forEach(bar => {
      const span = Math.max(1, Number(bar.dataset.spanDays) || 1);
      bar.style.width = `${Math.max(dayWidth - 4, span * dayWidth - 4)}px`;
    });
  };
  sizeBars();
  window.addEventListener('resize', () => requestAnimationFrame(sizeBars));
})();
</script>
@endpush
@else
@push('scripts')
<script>
(() => {
  const sizeBars = () => {
    const cell = document.querySelector('.tape-chart-table tbody .tape-cell');
    if (!cell) return;
    const width = cell.getBoundingClientRect().width;
    document.querySelectorAll('.tape-chart-table .tape-bar[data-span-days]').forEach(bar => {
      const span = Math.max(1, Number(bar.dataset.spanDays) || 1);
      bar.style.width = `${Math.max(width - 4, span * width - 4)}px`;
    });
  };
  sizeBars();
  window.addEventListener('resize', () => requestAnimationFrame(sizeBars));
})();
</script>
@endpush
@endif
