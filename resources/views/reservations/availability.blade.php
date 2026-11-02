@extends('layouts.app')
@section('title', 'Room Availability')
@section('crumbs') <a href="{{ route('reservations.index') }}">Reservations</a> / Availability @endsection
@section('actions')
  <a class="btn" href="{{ route('reservations.index') }}"><x-icon name="layers"/> Reservations</a>
  @if(can_do('reservations.create'))<a class="btn btn-primary" href="{{ route('reservations.create') }}"><x-icon name="plus"/> New reservation</a>@endif
@endsection
@section('content')
@php
  $periodEnd = $from->copy()->addDays($days - 1);
  $sellableRooms = (int) $types->sum('rooms_count');
  $availableRoomNights = 0;
  $limitedCells = 0;
  $unavailableCells = 0;
  $overbookedCells = 0;
  $dailyAvailable = [];
  foreach ($types as $type) {
    for ($i = 0; $i < $days; $i++) {
      $dayKey = $from->copy()->addDays($i)->toDateString();
      $count = (int) ($matrix[$type->id][$dayKey] ?? 0);
      $dailyAvailable[$dayKey] = ($dailyAvailable[$dayKey] ?? 0) + $count;
      $availableRoomNights += max(0, $count);
      if ($count < 0) $overbookedCells++;
      elseif ($count === 0) $unavailableCells++;
      elseif ($count <= max(1, $type->rooms_count * .25)) $limitedCells++;
    }
  }
  $noTypes = $types->isEmpty();
@endphp
<style>
  .availability-page{--av-green:#14532d;--av-green-2:#176239;--av-ink:var(--c-text);--av-muted:var(--c-text-2);--av-line:var(--c-border);--av-surface:var(--c-surface);--av-bg:var(--c-bg)}
  .availability-hero{position:relative;display:flex;align-items:flex-end;justify-content:space-between;gap:1rem;flex-wrap:wrap;overflow:hidden;margin-bottom:1rem;padding:1.2rem 1.35rem;border:1px solid rgba(20,83,45,.12);border-radius:16px;color:#fff;background:radial-gradient(circle at 88% 12%,rgba(174,228,188,.24),transparent 30%),linear-gradient(118deg,#103a23,#176239 66%,#247849);box-shadow:0 14px 32px rgba(20,83,45,.14)}
  .availability-hero:after{position:absolute;right:8%;bottom:-62px;width:190px;height:190px;border:1px solid rgba(255,255,255,.12);border-radius:50%;content:"";pointer-events:none}
  .availability-kicker{display:flex;align-items:center;gap:.45rem;margin-bottom:.45rem;color:#c6e5ce;font-size:.66rem;font-weight:800;letter-spacing:.13em;text-transform:uppercase}
  .availability-hero h1{position:relative;z-index:1;margin:0;font-size:clamp(1.35rem,2.4vw,1.85rem);font-weight:820;letter-spacing:-.04em;line-height:1.1}
  .availability-hero p{position:relative;z-index:1;max-width:670px;margin:.45rem 0 0;color:rgba(255,255,255,.78);font-size:.79rem;line-height:1.55}
  .availability-period{position:relative;z-index:1;display:flex;align-items:center;gap:.6rem;padding:.62rem .8rem;border:1px solid rgba(255,255,255,.22);border-radius:11px;background:rgba(255,255,255,.08);backdrop-filter:blur(5px)}
  .availability-period .period-icon{display:grid;place-items:center;width:33px;height:33px;border-radius:9px;background:rgba(255,255,255,.13);color:#d8f0de}
  .availability-period small{display:block;color:rgba(255,255,255,.7);font-size:.62rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase}
  .availability-period strong{display:block;margin-top:.08rem;font-size:.83rem;font-weight:760}
  .availability-controls{display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;margin-bottom:.9rem;padding:.75rem;border:1px solid var(--av-line);border-radius:13px;background:var(--av-surface);box-shadow:var(--shadow-sm)}
  .availability-controls-left,.availability-controls-right{display:flex;align-items:flex-end;gap:.5rem;flex-wrap:wrap}
  .availability-controls .field{min-width:132px;margin:0}
  .availability-controls .field label{margin-bottom:.24rem;color:var(--av-muted);font-size:.65rem;font-weight:750}
  .availability-controls input,.availability-controls select{min-height:38px}
  .availability-controls .btn{min-height:38px;display:inline-flex;align-items:center;justify-content:center;gap:.35rem}
  .availability-period-nav{display:flex;gap:.35rem}
  .availability-period-nav .btn{width:39px;padding:0}.availability-chevron-prev{transform:rotate(180deg)}
  .availability-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.65rem;margin-bottom:.9rem}
  .availability-stat{position:relative;min-height:93px;padding:.78rem .85rem;border:1px solid var(--av-line);border-radius:12px;background:var(--av-surface);box-shadow:var(--shadow-sm)}
  .availability-stat-top{display:flex;align-items:center;justify-content:space-between;gap:.5rem;color:var(--av-muted);font-size:.65rem;font-weight:750;letter-spacing:.04em;text-transform:uppercase}
  .availability-stat-icon{display:grid;place-items:center;width:29px;height:29px;border-radius:8px;background:#eaf5eb;color:var(--av-green)}
  .availability-stat strong{display:block;margin-top:.37rem;color:var(--av-ink);font-size:1.3rem;font-weight:830;letter-spacing:-.04em;line-height:1}
  .availability-stat small{display:block;margin-top:.24rem;color:var(--av-muted);font-size:.63rem}
  .availability-table-card{overflow:hidden;border:1px solid var(--av-line);border-radius:14px;background:var(--av-surface);box-shadow:var(--shadow-sm)}
  .availability-table-head{display:flex;align-items:center;justify-content:space-between;gap:.8rem;flex-wrap:wrap;padding:.85rem 1rem;border-bottom:1px solid var(--av-line)}
  .availability-table-head h2{margin:0;color:var(--av-ink);font-size:.93rem;font-weight:800;letter-spacing:-.02em}
  .availability-table-head p{margin:.18rem 0 0;color:var(--av-muted);font-size:.69rem}
  .availability-table-head-tools{display:flex;align-items:flex-end;justify-content:flex-end;gap:.55rem;flex-wrap:wrap;margin-left:auto}
  .availability-filter-control{display:flex;flex-direction:column;gap:.18rem;min-width:178px}
  .availability-filter-control label{color:var(--av-muted);font-size:.59rem;font-weight:750}
  .availability-filter-control input{min-height:34px;width:100%;padding:.38rem .55rem;border:1px solid var(--av-line);border-radius:8px;background:var(--av-bg);color:var(--av-ink);font:inherit;font-size:.69rem}
  .availability-filter-control input:focus-visible{outline:3px solid rgba(34,128,73,.25);border-color:#2f8552}
  .availability-filter-count{padding-bottom:.42rem;color:var(--av-muted);font-size:.62rem;white-space:nowrap}
  .availability-no-match td{height:64px;text-align:center;background:var(--av-surface);color:var(--av-muted);font-size:.7rem}
  .availability-live-badge{display:inline-flex;align-items:center;gap:.35rem;padding:.34rem .55rem;border:1px solid #cbe7d1;border-radius:999px;background:#f0fdf4;color:#166534;font-size:.64rem;font-weight:750;white-space:nowrap}
  .availability-live-badge i{width:6px;height:6px;border-radius:50%;background:#22a05a;box-shadow:0 0 0 3px rgba(34,160,90,.12)}
  .availability-scroll{overflow:auto;max-height:calc(100vh - 390px);min-height:260px;overscroll-behavior:contain;scrollbar-color:#94a3b8 transparent;scrollbar-width:thin}
  .availability-scroll:focus-visible{outline:3px solid rgba(34,128,73,.35);outline-offset:-3px}
  .availability-table{width:max-content;min-width:100%;border-collapse:separate;border-spacing:0;font-size:.71rem}
  .availability-table th,.availability-table td{height:47px;padding:.38rem .42rem;border-right:1px solid #edf0ed;border-bottom:1px solid #edf0ed;text-align:center;white-space:nowrap}
  .availability-table thead th{position:sticky;top:0;z-index:4;height:61px;background:#f7faf7;color:#536359;font-size:.64rem;font-weight:780}
  .availability-table thead th:first-child,.availability-table thead th:nth-child(2){z-index:6;background:#f2f7f2}
  .availability-table tbody th{font-weight:normal;text-align:left}
  .availability-table .av-type-col{position:sticky;left:0;z-index:2;width:190px;min-width:190px;max-width:190px;background:var(--av-surface);text-align:left}
  .availability-table .av-total-col{position:sticky;left:190px;z-index:2;width:72px;min-width:72px;background:var(--av-surface);box-shadow:4px 0 7px -7px rgba(17,24,39,.6)}
  .availability-table thead .av-type-col,.availability-table thead .av-total-col{z-index:6}
  .availability-type-name{display:block;overflow:hidden;color:var(--av-ink);font-size:.73rem;font-weight:780;text-overflow:ellipsis}
  .availability-type-rate{display:block;margin-top:.12rem;color:var(--av-muted);font-size:.62rem}
  .availability-table .av-date{min-width:64px;width:64px}
  .availability-table .av-date .weekday{display:block;font-size:.62rem;font-weight:800}
  .availability-table .av-date .date-num{display:block;margin-top:.12rem;color:#76837a;font-size:.62rem;font-weight:650}.availability-table .av-date .today-mark{display:block;margin-top:.08rem;color:#267145;font-size:.52rem;font-weight:850;line-height:1;text-transform:uppercase;letter-spacing:.04em}
  .availability-table .av-date.is-weekend .weekday{color:#9a6813}
  .availability-table .av-date.is-today{background:#e8f4eb;color:#14532d}
  .availability-table .av-date.is-today .date-num{color:#267145}
  .availability-table td.av-cell{font-size:.76rem;font-weight:800;font-variant-numeric:tabular-nums}
  .availability-table td.av-good{background:#edf8ef;color:#176239}
  .availability-table td.av-limited{background:#fff8e8;color:#8a5a0a}
  .availability-table td.av-soldout{background:#fff0ef;color:#a12d26}
  .availability-table td.av-overbooked{background:#a9322b;color:#fff}
  .availability-table td.av-cell a{display:flex;align-items:center;justify-content:center;min-width:28px;min-height:28px;margin:-.12rem auto;border-radius:7px;color:inherit;text-decoration:none}
  .availability-table td.av-cell a:hover{outline:2px solid currentColor;outline-offset:1px;background:rgba(255,255,255,.48)}
  .availability-table td.av-cell a:focus-visible{outline:3px solid #14532d;outline-offset:1px}
  .availability-table .av-total-row th,.availability-table .av-total-row td{height:43px;background:#f2f7f2;color:#253c2e;font-weight:850}
  .availability-table .av-total-row .av-type-col,.availability-table .av-total-row .av-total-col{background:#eaf2eb}
  .availability-table .av-total-row th{font-size:.68rem}.availability-table .av-total-row td.av-overbooked{background:#a9322b;color:#fff}
  .availability-empty{padding:2.5rem 1rem;text-align:center}
  .availability-empty-icon{display:grid;place-items:center;width:46px;height:46px;margin:0 auto .7rem;border-radius:13px;background:#eaf5eb;color:#14532d}
  .availability-empty h3{margin:0;color:var(--av-ink);font-size:.9rem}
  .availability-empty p{max-width:420px;margin:.35rem auto .8rem;color:var(--av-muted);font-size:.73rem;line-height:1.5}
  .availability-legend{display:flex;align-items:center;gap:.55rem;flex-wrap:wrap;margin-top:.75rem;padding:.65rem .8rem;border:1px solid var(--av-line);border-radius:11px;background:var(--av-surface)}
  .availability-legend-title{margin-right:.1rem;color:var(--av-muted);font-size:.65rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em}
  .availability-key{display:inline-flex;align-items:center;gap:.35rem;padding:.28rem .48rem;border:1px solid var(--av-line);border-radius:999px;color:var(--av-muted);font-size:.64rem;font-weight:650}
  .availability-swatch{width:10px;height:10px;border:1px solid rgba(0,0,0,.08);border-radius:3px}
  .availability-footnote{margin:.6rem .1rem 0;color:var(--av-muted);font-size:.65rem;line-height:1.5}
  body.dark .availability-stat-icon,body.dark .availability-empty-icon{background:#21402a;color:#b9e8c2}
  body.dark .availability-live-badge{border-color:#315d40;background:#173322;color:#b9e8c2}
  body.dark .availability-live-badge i{background:#55bf7b}
  body.dark .availability-table th,body.dark .availability-table td{border-color:#34413a}
  body.dark .availability-table thead th,body.dark .availability-table thead th:first-child,body.dark .availability-table thead th:nth-child(2){background:#202c24;color:#c3cec6}
  body.dark .availability-table .av-type-col,body.dark .availability-table .av-total-col{background:var(--av-surface)}
  body.dark .availability-table .av-date .date-num{color:#a5b3a9}
  body.dark .availability-table .av-date.is-today{background:#203c29;color:#b9e8c2}
  body.dark .availability-table .av-date.is-today .date-num{color:#b9e8c2}
  body.dark .availability-table td.av-good{background:#1d3827;color:#b9e8c2}
  body.dark .availability-table td.av-limited{background:#3a301b;color:#f1d18a}
  body.dark .availability-table td.av-soldout{background:#402423;color:#ffb6ae}
  body.dark .availability-table td.av-overbooked{background:#8a2925;color:#fff}
  body.dark .availability-table .av-total-row th,body.dark .availability-table .av-total-row td{background:#24332a;color:#dbe8de}
  body.dark .availability-table .av-total-row .av-type-col,body.dark .availability-table .av-total-row .av-total-col{background:#1e3024}body.dark .availability-table .av-total-row td.av-overbooked{background:#8a2925;color:#fff}
  @media(max-width:900px){.availability-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.availability-scroll{max-height:calc(100vh - 450px)}}
  @media(max-width:600px){.availability-hero{align-items:flex-start;padding:1rem}.availability-period{width:100%}.availability-controls{align-items:stretch}.availability-controls-left,.availability-controls-right{width:100%}.availability-controls-left .field{flex:1;min-width:125px}.availability-controls-right{justify-content:space-between}.availability-summary{gap:.45rem}.availability-stat{min-height:84px;padding:.65rem}.availability-stat strong{font-size:1.12rem}.availability-table-head-tools{width:100%;justify-content:space-between;margin-left:0}.availability-filter-control{flex:1;min-width:155px;max-width:260px}.availability-table .av-type-col{width:158px;min-width:158px;max-width:158px}.availability-table .av-total-col{left:158px;width:60px;min-width:60px}.availability-table .av-date{min-width:58px;width:58px}.availability-table th,.availability-table td{height:43px;padding:.3rem}.availability-scroll{max-height:calc(100vh - 500px);min-height:220px}}
  @media(max-width:370px){.availability-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.availability-stat-top{font-size:.58rem}}
</style>
<div class="availability-page">
  <header class="availability-hero">
    <div>
      <div class="availability-kicker"><x-icon name="grid" size="sm"/> Reservations · Inventory planning</div>
      <h1>Room availability</h1>
      <p>Review sellable room counts by room type and night. Reservations, group blocks, contract allotments, leases and dated outages are reflected in the matrix.</p>
    </div>
    <div class="availability-period" aria-label="Selected availability period">
      <span class="period-icon"><x-icon name="calendar"/></span>
      <span><small>Selected window · {{ $days }} nights</small><strong>{{ $from->format('d M') }} – {{ $periodEnd->format('d M Y') }}</strong></span>
    </div>
  </header>

  <section class="availability-controls" aria-label="Availability date controls">
    <form method="get" class="availability-controls-left">
      <div class="field"><label for="availabilityFrom">Start date</label><input id="availabilityFrom" type="date" name="from" value="{{ $from->toDateString() }}"></div>
      <div class="field"><label for="availabilityDays">Date window</label><select id="availabilityDays" name="days">@foreach([7,14,21,30] as $option)<option value="{{ $option }}" @selected($days === $option)>{{ $option }} nights</option>@endforeach</select></div>
      <button class="btn btn-primary"><x-icon name="search" size="sm"/> Apply</button>
    </form>
    <div class="availability-controls-right">
      <div class="availability-period-nav" aria-label="Move the availability window">
        <a class="btn btn-ghost" href="{{ route('reservations.availability', ['from' => $from->copy()->subDays($days)->toDateString(), 'days' => $days]) }}" aria-label="Previous {{ $days }} nights" title="Previous {{ $days }} nights"><x-icon name="chev" class="availability-chevron-prev"/></a>
        <a class="btn btn-ghost" href="{{ route('reservations.availability', ['from' => $from->copy()->addDays($days)->toDateString(), 'days' => $days]) }}" aria-label="Next {{ $days }} nights" title="Next {{ $days }} nights"><x-icon name="chev"/></a>
      </div>
      <a class="btn btn-ghost" href="{{ route('reservations.availability', ['from' => bdate()->toDateString(), 'days' => $days]) }}"><x-icon name="calendar" size="sm"/> Today</a>
    </div>
  </section>

  <section class="availability-summary" aria-label="Availability summary">
    <article class="availability-stat"><div class="availability-stat-top"><span>Room types</span><span class="availability-stat-icon"><x-icon name="layers" size="sm"/></span></div><strong>{{ number_format($types->count()) }}</strong><small>Active sellable categories</small></article>
    <article class="availability-stat"><div class="availability-stat-top"><span>Sellable rooms</span><span class="availability-stat-icon"><x-icon name="bed" size="sm"/></span></div><strong>{{ number_format($sellableRooms) }}</strong><small>Across the active room types</small></article>
    <article class="availability-stat"><div class="availability-stat-top"><span>Room-nights available</span><span class="availability-stat-icon"><x-icon name="calendar" size="sm"/></span></div><strong>{{ number_format($availableRoomNights) }}</strong><small>Available inventory across this window</small></article>
    <article class="availability-stat"><div class="availability-stat-top"><span>Unavailable type-nights</span><span class="availability-stat-icon"><x-icon name="alert" size="sm"/></span></div><strong>{{ number_format($unavailableCells + $overbookedCells) }}</strong><small>{{ number_format($limitedCells) }} limited · {{ number_format($overbookedCells) }} overbooked type-nights</small></article>
  </section>

  <section class="availability-table-card" aria-labelledby="availabilityMatrixTitle">
    <div class="availability-table-head">
      <div><h2 id="availabilityMatrixTitle">Availability by room type</h2><p>Nightly counts for the selected window. Filtering room types leaves the all-type date totals unchanged.</p></div>
      <div class="availability-table-head-tools">
        @if(!$noTypes)<div class="availability-filter-control"><label for="availabilityRoomTypeFilter">Find a room type</label><input id="availabilityRoomTypeFilter" type="search" placeholder="e.g. Deluxe, Suite" autocomplete="off" aria-controls="availabilityTypeRows"></div><span class="availability-filter-count" id="availabilityFilterCount" role="status" aria-live="polite">{{ $types->count() }} room types</span>@endif
        <span class="availability-live-badge"><i aria-hidden="true"></i> Current inventory</span>
      </div>
    </div>
    @if($noTypes)
      <div class="availability-empty"><span class="availability-empty-icon"><x-icon name="bed"/></span><h3>No sellable room types found</h3><p>Activate a room type and at least one sellable room to populate the availability matrix.</p><a class="btn" href="{{ route('reservations.index') }}">Back to reservations</a></div>
    @else
      <div class="availability-scroll" tabindex="0" role="region" aria-label="Room availability matrix. Scroll horizontally to see more dates and vertically to see more room types." aria-describedby="availabilityLegend">
        <table class="availability-table">
          <caption class="sr-only">Available rooms per active room type and night from {{ $from->format('d M Y') }} through {{ $periodEnd->format('d M Y') }}.</caption>
          <colgroup><col style="width:190px"><col style="width:72px">@for($i=0;$i<$days;$i++)<col style="width:64px">@endfor</colgroup>
          <thead><tr>
            <th class="av-type-col" scope="col">Room type</th><th class="av-total-col" scope="col">Rooms</th>
            @for($i=0;$i<$days;$i++) @php $headerDate = $from->copy()->addDays($i); @endphp
              <th class="av-date {{ $headerDate->isWeekend() ? 'is-weekend' : '' }} {{ $headerDate->isToday() ? 'is-today' : '' }}" scope="col" aria-label="{{ $headerDate->format('l, d F Y') }}{{ $headerDate->isToday() ? ', today' : '' }}"><span class="weekday">{{ $headerDate->format('D') }}</span><span class="date-num">{{ $headerDate->format('d/m') }}</span>@if($headerDate->isToday())<span class="today-mark">Today</span>@endif</th>
            @endfor
          </tr></thead>
          <tbody id="availabilityTypeRows">
            @foreach($types as $type)
              <tr data-availability-type data-type-name="{{ \Illuminate\Support\Str::lower($type->name) }}">
                <th class="av-type-col" scope="row"><span class="availability-type-name">{{ $type->name }}</span><span class="availability-type-rate">From {{ money($type->base_rate) }} / night</span></th>
                <td class="av-total-col">{{ number_format($type->rooms_count) }}</td>
                @for($i=0;$i<$days;$i++)
                  @php
                    $cellDate = $from->copy()->addDays($i);
                    $dateKey = $cellDate->toDateString();
                    $count = (int) ($matrix[$type->id][$dateKey] ?? 0);
                    $cellClass = $count < 0 ? 'av-overbooked' : ($count === 0 ? 'av-soldout' : ($count <= max(1, $type->rooms_count * .25) ? 'av-limited' : 'av-good'));
                    $cellLabel = $count < 0 ? 'Overbooked by '.abs($count) : ($count === 0 ? 'Sold out' : ($count === 1 ? '1 room available' : $count.' rooms available'));
                    $cellLabel .= ' for '.$type->name.' on '.$cellDate->format('l, d F Y');
                  @endphp
                  <td class="av-cell {{ $cellClass }}" title="{{ $cellLabel }}" aria-label="{{ $cellLabel }}">
                    @if($count > 0 && can_do('reservations.create'))
                      <a href="{{ route('reservations.create', ['arrival' => $dateKey, 'departure' => $cellDate->copy()->addDay()->toDateString(), 'room_type_id' => $type->id]) }}" aria-label="{{ $cellLabel }}. Start a reservation">{{ $count }}</a>
                    @else{{ $count }}@endif
                  </td>
                @endfor
              </tr>
            @endforeach
            <tr class="availability-no-match" id="availabilityNoMatch" hidden><td colspan="{{ $days + 2 }}">No room types match that search. Try a shorter name.</td></tr>
            <tr class="av-total-row"><th class="av-type-col" scope="row">Total available</th><td class="av-total-col">{{ number_format($sellableRooms) }}</td>
              @for($i=0;$i<$days;$i++) @php $totalDate = $from->copy()->addDays($i)->toDateString(); $totalCount = (int) ($dailyAvailable[$totalDate] ?? 0); @endphp<td class="{{ $totalCount < 0 ? 'av-overbooked' : '' }}" aria-label="Total inventory {{ $totalCount }} available on {{ $from->copy()->addDays($i)->format('l, d F Y') }}">{{ number_format($totalCount) }}</td>@endfor
            </tr>
          </tbody>
        </table>
      </div>
    @endif
  </section>

  <div class="availability-legend" id="availabilityLegend" aria-label="Availability legend">
    <span class="availability-legend-title">Legend</span>
    <span class="availability-key"><i class="availability-swatch" style="background:#edf8ef"></i>Good availability</span>
    <span class="availability-key"><i class="availability-swatch" style="background:#fff8e8"></i>Limited</span>
    <span class="availability-key"><i class="availability-swatch" style="background:#fff0ef"></i>Sold out</span>
    <span class="availability-key"><i class="availability-swatch" style="background:#a9322b"></i>Overbooked</span>
    @if(can_do('reservations.create'))<span class="availability-key"><x-icon name="plus" size="sm"/> Select a positive count to book</span>@endif
  </div>
  <p class="availability-footnote">Inventory is presented by room type and night. Final reservation creation rechecks availability before saving.</p>
</div>
@push('scripts')
<script>
(() => {
  const search = document.getElementById('availabilityRoomTypeFilter');
  const rows = [...document.querySelectorAll('[data-availability-type]')];
  const empty = document.getElementById('availabilityNoMatch');
  const count = document.getElementById('availabilityFilterCount');
  if (!search || !count) return;
  const update = () => {
    const query = search.value.trim().toLocaleLowerCase();
    let visible = 0;
    rows.forEach(row => {
      const matches = !query || row.dataset.typeName.includes(query);
      row.hidden = !matches;
      if (matches) visible++;
    });
    if (empty) empty.hidden = visible !== 0;
    count.textContent = query ? `${visible} of ${rows.length} room types` : `${rows.length} room types`;
  };
  search.addEventListener('input', update);
})();
</script>
@endpush
@endsection
