@extends('layouts.app')
@section('title', 'Revenue Dashboard')
@section('crumbs') Revenue / Dashboard @endsection
@section('actions')
<span class="rm-mode {{ $mode }}" title="Pricing mode"><x-icon name="trend" size="sm"/> {{ \App\Services\RevenueService::MODES[$mode] }}</span>
<form class="rvd-controls" method="get" aria-label="Revenue forecast window">
  <label for="rvdDays">Forecast window</label>
  <select id="rvdDays" name="days" aria-label="Forecast window" data-autosubmit>@foreach([14,30,60,90] as $d)<option value="{{ $d }}" @selected($days==$d)>Next {{ $d }} days</option>@endforeach</select>
</form>
<a class="btn btn-icon rvd-action" href="{{ route('revenue.pricing') }}" aria-label="Open Revenue Pricing Grid{{ $pending && $mode === 'recommend' ? ', '.$pending.' recommendations pending' : '' }}" title="Pricing Grid{{ $pending && $mode === 'recommend' ? ' · '.$pending.' pending' : '' }}"><x-icon name="chart"/>@if($pending && $mode === 'recommend')<span class="nav-badge">{{ $pending }}</span>@endif</a>
@endsection
@push('head')
<style>
.rvd-page{min-width:0}.rvd-controls{display:flex;align-items:center;gap:.35rem;margin:0}.rvd-controls label{color:var(--c-text-2);font-size:.65rem;font-weight:750;letter-spacing:.04em;text-transform:uppercase}.rvd-controls select{min-height:36px;max-width:145px;padding:.35rem .5rem;font-size:.76rem}.rvd-action{position:relative;min-width:37px;min-height:37px;justify-content:center}.rvd-action .nav-badge{position:absolute;top:-5px;right:-6px;min-width:17px;height:17px;line-height:17px;font-size:.61rem}
.rvd-hero{display:flex;align-items:center;justify-content:space-between;gap:1rem;margin:0 0 1rem;padding:1.05rem 1.25rem;border:1px solid #d4e6d8;border-radius:16px;background:linear-gradient(118deg,#103a23,#176239 68%,#247849);color:#f5fff7;box-shadow:0 10px 26px rgba(16,58,35,.12)}.rvd-hero-copy{min-width:0}.rvd-kicker{display:flex;align-items:center;gap:.4rem;color:#c7efd0;font-size:.65rem;font-weight:800;letter-spacing:.13em;text-transform:uppercase}.rvd-kicker svg{width:15px;height:15px}.rvd-hero h2{margin:.25rem 0 0;font-size:1.28rem;line-height:1.25}.rvd-hero p{max-width:720px;margin:.3rem 0 0;color:#e0f2e5;font-size:.82rem}.rvd-window{display:grid;gap:.14rem;min-width:130px;padding:.6rem .8rem;border:1px solid #ffffff35;border-radius:11px;background:#ffffff12;text-align:right}.rvd-window span{color:#c7efd0;font-size:.6rem;font-weight:750;letter-spacing:.08em;text-transform:uppercase}.rvd-window strong{font-size:.91rem}
.rvd-notice{display:flex;align-items:flex-start;gap:.55rem;margin:0 0 .85rem;padding:.72rem .85rem;border:1px solid;border-radius:11px;font-size:.79rem;line-height:1.45}.rvd-notice svg{width:17px;height:17px;flex:none;margin-top:.02rem}.rvd-notice p{margin:0}.rvd-notice a{font-weight:750;text-decoration:underline;text-underline-offset:2px}.rvd-notice.recommend{border-color:#b8dfc2;background:#eff9f1;color:#1b5430}.rvd-notice.off{border-color:#f3d08b;background:#fff8e8;color:#754b0b}.rvd-notice.auto{border-color:#d7c7fa;background:#f6f2ff;color:#493582}
.rvd-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.7rem;margin-bottom:1rem}.rvd-stats .stat{min-width:0;min-height:104px;border:1px solid var(--c-border);border-radius:13px;background:var(--c-surface);box-shadow:var(--shadow-sm)}.rvd-stats .stat .v{font-size:1.06rem;font-variant-numeric:tabular-nums}.rvd-stats .stat .l{font-size:.68rem;font-weight:750}.rvd-stats .stat .tiny{margin-top:.18rem;font-size:.67rem;line-height:1.35}
.rvd-card{min-width:0;overflow:hidden}.rvd-card-head{display:flex;align-items:center;justify-content:space-between;gap:.75rem}.rvd-card-head h3{margin:0}.rvd-card-head p{margin:.2rem 0 0}.rvd-chart-range{display:inline-flex;align-items:center;gap:.3rem;padding:.33rem .5rem;border:1px solid var(--c-border);border-radius:8px;background:var(--c-bg);color:var(--c-text-2);font-size:.68rem;white-space:nowrap}.rvd-chart-range svg{width:13px;height:13px}.rvd-legend{display:flex;flex-wrap:wrap;gap:.42rem .85rem;padding:.55rem 1rem .2rem;color:var(--c-text-2);font-size:.67rem}.rvd-legend span{display:inline-flex;align-items:center;gap:.32rem}.rvd-swatch{width:10px;height:10px;border:1px solid transparent;border-radius:3px}.rvd-swatch.otb{background:#2563eb}.rvd-swatch.held{background:#8b5cf6}.rvd-swatch.forecast{background:#fbbf24}.rvd-swatch.capacity{height:0;border:0;border-top:2px dashed #dc2626;border-radius:0}.rvd-swatch.weekend{background:#fff7ed;border-color:#fed7aa}.rvd-chart-wrap{max-width:100%;overflow-x:auto;overscroll-behavior-x:contain;scrollbar-gutter:stable}.rvd-chart-wrap:focus-visible{outline:2px solid var(--c-primary);outline-offset:-2px}.rvd-chart{width:100%;min-width:640px;height:auto;display:block}.rvd-chart text{font-size:11px;fill:#64748b}.rvd-chart .wk{fill:#fff7ed}.rvd-chart .grid-l{stroke:#e2e8f0;stroke-width:1}.rvd-chart .cap{stroke:#dc2626;stroke-width:1.2;stroke-dasharray:5 4}.rvd-chart .b-otb{fill:#2563eb}.rvd-chart .b-held{fill:#8b5cf6}.rvd-chart .b-fc{fill:#fbbf24;opacity:.58}.rvd-chart-note{margin:.1rem 1rem .7rem;color:var(--c-text-2);font-size:.66rem}
.rvd-lower{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(280px,.9fr);gap:.9rem;align-items:stretch;margin-top:.9rem}.rvd-source-list{display:grid;gap:.8rem}.rvd-source-row{min-width:0}.rvd-source-top{display:flex;align-items:baseline;justify-content:space-between;gap:.6rem}.rvd-source-name{min-width:0;font-size:.78rem;font-weight:750;overflow-wrap:anywhere}.rvd-source-meta{margin-left:.25rem;color:var(--c-text-2);font-size:.67rem;font-weight:500}.rvd-source-value{flex:none;font-size:.77rem;font-weight:800;font-variant-numeric:tabular-nums}.rvd-source-track{height:6px;margin-top:.35rem;overflow:hidden;border-radius:999px;background:var(--c-bg)}.rvd-source-track span{display:block;height:100%;min-width:2px;border-radius:inherit;background:linear-gradient(90deg,#176239,#3c9b5f)}.rvd-how-list{display:grid;gap:.62rem}.rvd-how-item{display:grid;grid-template-columns:25px minmax(0,1fr);gap:.55rem;align-items:start}.rvd-how-num{display:grid;width:24px;height:24px;place-items:center;border-radius:8px;background:#eaf5ed;color:#176239;font-size:.69rem;font-weight:850}.rvd-how-item p{margin:0;color:var(--c-text-2);font-size:.74rem;line-height:1.45}.rvd-how-item b{color:var(--c-text)}.rvd-snapshot{margin-top:.85rem;padding-top:.75rem;border-top:1px solid var(--c-border)}.rvd-snapshot p{margin:0 0 .5rem;color:var(--c-text-2);font-size:.69rem;line-height:1.45}.rvd-snapshot .btn{min-height:33px}.rvd-rule-count{margin-top:.75rem;padding:.6rem .7rem;border-radius:9px;background:var(--c-bg);font-size:.73rem}.rvd-rule-count a{font-weight:750}
.rvd-table-card{margin-top:.9rem;overflow:hidden}.rvd-table-head{display:flex;align-items:center;justify-content:space-between;gap:.75rem}.rvd-table-head h3{margin:0}.rvd-table-head p{margin:.15rem 0 0;color:var(--c-text-2);font-size:.68rem}.rvd-table-scroll{max-width:100%;overflow:auto;overscroll-behavior-x:contain;scrollbar-gutter:stable}.rvd-table{width:100%;min-width:1000px;font-variant-numeric:tabular-nums}.rvd-table th{white-space:nowrap}.rvd-table th:first-child,.rvd-table td:first-child{position:sticky;left:0;z-index:1;background:var(--c-surface)}.rvd-table thead th:first-child{z-index:2}.rvd-table tr.rvd-weekend td{background:#fffaf3}.rvd-table tr.rvd-weekend td:first-child{background:#fffaf3}.rvd-table .rvd-date{white-space:nowrap}.rvd-table .rvd-date time{font-weight:750}.rvd-table .rvd-today{color:#176239}.rvd-table .rvd-forecast{font-weight:800}.rvd-table tfoot td{font-weight:800;background:var(--c-bg)}.rvd-table .rvd-price-action{white-space:nowrap}.rvd-table .btn{min-height:28px}.rvd-no-data{padding:1.2rem;color:var(--c-text-2);text-align:center;font-size:.78rem}.rvd-sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
body.dark .rvd-hero{background:linear-gradient(118deg,#0d2718,#143d25 68%,#1d5433);border-color:#244a32;box-shadow:none}body.dark .rvd-notice.recommend{background:#173324;border-color:#315f3d;color:#c3ebcc}body.dark .rvd-notice.off{background:#332914;border-color:#695321;color:#f2d895}body.dark .rvd-notice.auto{background:#281e3a;border-color:#4c3b70;color:#e2d5ff}body.dark .rvd-how-num{background:#183225;color:#a9e1b7}body.dark .rvd-chart .wk{fill:#29291f}body.dark .rvd-chart .grid-l{stroke:#3c4653}body.dark .rvd-chart text{fill:#a8b3c2}body.dark .rvd-table tr.rvd-weekend td,body.dark .rvd-table tr.rvd-weekend td:first-child{background:#29291f}
@media(max-width:1050px){.rvd-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.rvd-lower{grid-template-columns:1fr}}
@media(max-width:650px){.rvd-hero{align-items:flex-start;flex-direction:column;padding:1rem}.rvd-window{min-width:0;text-align:left}.rvd-card-head,.rvd-table-head{align-items:flex-start;flex-direction:column}.rvd-chart-range{align-self:flex-start}.rvd-stats{gap:.5rem}.rvd-stats .stat{min-height:96px;padding:.7rem}.rvd-stats .stat .v{font-size:.95rem}.rvd-controls label{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}.rvd-controls select{max-width:126px}.rvd-legend{gap:.4rem .65rem;padding-right:.7rem;padding-left:.7rem}.rvd-source-top{align-items:flex-start}.rvd-source-meta{display:block;margin:.12rem 0 0}}
@media(max-width:390px){.rvd-stats{grid-template-columns:1fr}.rvd-stats .stat{min-height:80px}.rvd-action{min-width:34px}.rvd-controls select{max-width:112px}}
@media(prefers-reduced-motion:reduce){.rvd-page *{scroll-behavior:auto!important;transition:none!important}}
</style>
@endpush
@section('content')
@php
  $k7 = $kpi['d7']; $k30 = $kpi['d30']; $p = $kpi['pickup'];
  $rowsV = array_values($rows); $n = count($rowsV);
  $W = 1000; $H = 250; $L = 38; $R = 10; $T = 12; $B = 34; $pw = $W - $L - $R; $ph = $H - $T - $B;
  $bw = $n ? $pw / $n : 0; $y = fn ($rooms, $dayCap) => $T + $ph - ($dayCap ? min(1, max(0, $rooms / $dayCap)) : 0) * $ph;
  $every = $n <= 31 ? 1 : 7; $maxSourceRevenue = max(1, (float) $sources->max('revenue'));
  $pickup1Values = array_filter(array_column($rows, 'pickup1'), fn ($value) => $value !== null);
  $pickup7Values = array_filter(array_column($rows, 'pickup7'), fn ($value) => $value !== null);
@endphp
<div class="rvd-page">
  <section class="rvd-hero" aria-label="Revenue outlook overview">
    <div class="rvd-hero-copy"><span class="rvd-kicker"><x-icon name="calendar"/> Revenue intelligence</span><h2>Forward demand &amp; pickup</h2><p>See what is already booked, what your recent booking pace suggests, and how room revenue is shaping up across the selected window.</p></div>
    <div class="rvd-window"><span>Forecast window</span><strong>Next {{ $days }} days</strong></div>
  </section>

  @if($mode === 'recommend' && $pending)
    <div class="rvd-notice recommend" role="status"><x-icon name="trend"/><p><b>{{ $pending }}</b> rate recommendation(s) for the next 14 days differ from live prices. <a href="{{ route('revenue.pricing') }}">Review the Pricing Grid →</a></p></div>
  @elseif($mode === 'off')
    <div class="rvd-notice off" role="status"><x-icon name="alert"/><p>Revenue management is <b>off</b>. Forecasts remain available, but no live yield pricing is applied.@if(can_do('revenue.manage')) <a href="{{ route('revenue.rules') }}">Review pricing settings →</a>@endif</p></div>
  @elseif($mode === 'auto')
    <div class="rvd-notice auto" role="status"><x-icon name="trend"/><p><b>Auto-pilot is live.</b> Future quotes use active yield rules and room-type guard-rails. <a href="{{ route('revenue.rules') }}">Review rules and limits →</a></p></div>
  @endif

  <div class="rvd-stats" role="group" aria-label="Revenue performance summary">
    <x-stat label="Occupancy on the books · next 7 days" :value="$k7['occ'].'%'" icon="bed" tone="blue" :sub="'Forecast '.$k7['fc_occ'].'% · ADR '.money($k7['adr'])"/>
    <x-stat label="Occupancy on the books · next 30 days" :value="$k30['occ'].'%'" icon="calendar" tone="purple" :sub="'Forecast '.$k30['fc_occ'].'% · '.$k30['rooms'].' room nights'"/>
    <x-stat label="Room revenue on the books · 30 days" :value="money($k30['revenue'])" icon="wallet" tone="green" :sub="'Forecast '.money($k30['fc_revenue']).' · RevPAR '.money($k30['revpar'])"/>
    <x-stat label="Pickup · bookings made in the last 7 days" :value="$p['bookings'].' ('.$p['nights'].' nts)'" icon="trend" tone="amber" :sub="money($p['revenue']).' · '.$p['cancellations'].' cancelled ('.money($p['cx_revenue']).')'"/>
  </div>

  <section class="card rvd-card" aria-labelledby="rvdChartTitle">
    <div class="card-head rvd-card-head"><div><h3 id="rvdChartTitle">Demand outlook</h3><p class="tiny muted">On-the-books rooms, group holds, and expected pickup by stay date.</p></div><span class="rvd-chart-range"><x-icon name="calendar"/> Next {{ $days }} days</span></div>
    <div class="rvd-legend" role="list" aria-label="Demand outlook chart legend"><span role="listitem"><i class="rvd-swatch otb" aria-hidden="true"></i>Booked rooms</span><span role="listitem"><i class="rvd-swatch held" aria-hidden="true"></i>Group holds</span><span role="listitem"><i class="rvd-swatch forecast" aria-hidden="true"></i>Expected pickup</span><span role="listitem"><i class="rvd-swatch capacity" aria-hidden="true"></i>100% of date capacity</span><span role="listitem"><i class="rvd-swatch weekend" aria-hidden="true"></i>Fri / Sat</span></div>
    @if($n)
      <div class="rvd-chart-wrap" role="region" aria-label="Scrollable demand outlook chart" tabindex="0">
        <svg class="rvd-chart" viewBox="0 0 {{ $W }} {{ $H }}" role="img" aria-labelledby="rvdSvgTitle rvdSvgDesc">
          <title id="rvdSvgTitle">Booked rooms and demand forecast for the next {{ $days }} days</title><desc id="rvdSvgDesc">Daily bars are scaled to that date’s available room capacity. Booked rooms and group holds are shown separately, with expected pickup above current demand.</desc>
          @foreach($rowsV as $i => $r)@if($r['weekend'])<rect class="wk" x="{{ round($L + $i * $bw, 1) }}" y="{{ $T }}" width="{{ round($bw, 1) }}" height="{{ $ph }}"/>@endif @endforeach
          @foreach([0, 25, 50, 75, 100] as $g)@php $gy = round($T + $ph - $g / 100 * $ph, 1); @endphp
            <line class="grid-l" x1="{{ $L }}" x2="{{ $W - $R }}" y1="{{ $gy }}" y2="{{ $gy }}"/><text x="{{ $L - 6 }}" y="{{ $gy + 4 }}" text-anchor="end">{{ $g }}%</text>
          @endforeach
          <line class="cap" x1="{{ $L }}" x2="{{ $W - $R }}" y1="{{ $T }}" y2="{{ $T }}"/>
          @foreach($rowsV as $i => $r)
            @php $x = $L + $i * $bw + $bw * .14; $w = max(1, $bw * .72); $y1 = $y($r['rooms'], $r['cap']); $y2 = $y($r['rooms'] + $r['held'], $r['cap']); $y3 = $y(max($r['fc_rooms'], $r['rooms'] + $r['held']), $r['cap']); @endphp
            <g><title>{{ \Illuminate\Support\Carbon::parse($r['date'])->format('D d M') }} · {{ $r['rooms'] }} booked{{ $r['held'] ? ' + '.$r['held'].' held' : '' }} of {{ $r['cap'] }} available rooms ({{ $r['occ'] }}% occupied) · forecast {{ $r['fc_rooms'] }} rooms ({{ $r['fc_occ'] }}%) · ADR {{ money($r['adr']) }}</title>
              @if($y3 < $y2)<rect class="b-fc" x="{{ round($x, 1) }}" y="{{ round($y3, 1) }}" width="{{ round($w, 1) }}" height="{{ round($y2 - $y3, 1) }}"/>@endif
              @if($r['held'])<rect class="b-held" x="{{ round($x, 1) }}" y="{{ round($y2, 1) }}" width="{{ round($w, 1) }}" height="{{ round($y1 - $y2, 1) }}"/>@endif
              @if($r['rooms'])<rect class="b-otb" x="{{ round($x, 1) }}" y="{{ round($y1, 1) }}" width="{{ round($w, 1) }}" height="{{ round($T + $ph - $y1, 1) }}" rx="1.5"/>@endif
              <rect x="{{ round($L + $i * $bw, 1) }}" y="{{ $T }}" width="{{ round($bw, 1) }}" height="{{ $ph }}" fill="transparent"/>
            </g>
            @if($i % $every === 0)<text x="{{ round($L + $i * $bw + $bw / 2, 1) }}" y="{{ $H - $B + 15 }}" text-anchor="middle" @if($r['weekend']) style="font-weight:700;fill:#b45309" @endif>{{ \Illuminate\Support\Carbon::parse($r['date'])->format('d') }}</text>
              @if($i === 0 || \Illuminate\Support\Carbon::parse($r['date'])->day <= $every)<text x="{{ round($L + $i * $bw + $bw / 2, 1) }}" y="{{ $H - 4 }}" text-anchor="middle">{{ \Illuminate\Support\Carbon::parse($r['date'])->format('M') }}</text>@endif
            @endif
          @endforeach
        </svg>
      </div>
      <p class="rvd-chart-note">Bars show booked rooms and holds as a share of each night’s sellable capacity; forecast pickup is added above them. Hover over a bar for its date, occupancy, and ADR; the nightly table below lists exact values.</p>
    @else
      <div class="rvd-no-data">No stay-date forecast is available for this window.</div>
    @endif
  </section>

  <div class="rvd-lower">
    <section class="card rvd-card" aria-labelledby="rvdSourceTitle"><div class="card-head"><div><h3 id="rvdSourceTitle">Pickup by booking source</h3><p class="tiny muted">Reservations created in the last 7 days · excluding cancelled bookings.</p></div></div><div class="card-body">
      @forelse($sources as $s)
        <div class="rvd-source-row" role="group" aria-label="{{ $s['name'] }}: {{ $s['bookings'] }} bookings, {{ $s['nights'] }} room nights, {{ money($s['revenue']) }} revenue">
          <div class="rvd-source-top"><span class="rvd-source-name">{{ $s['name'] }}<span class="rvd-source-meta">{{ $s['bookings'] }} bookings · {{ $s['nights'] }} room nights</span></span><span class="rvd-source-value">{{ money($s['revenue']) }}</span></div>
          <div class="rvd-source-track" aria-hidden="true"><span style="width:{{ round($s['revenue'] * 100 / $maxSourceRevenue) }}%"></span></div>
        </div>
      @empty
        <div class="rvd-no-data">No non-cancelled bookings were created in the last 7 days.</div>
      @endforelse
    </div></section>

    <section class="card rvd-card" aria-labelledby="rvdMethodTitle"><div class="card-head"><div><h3 id="rvdMethodTitle">Reading this forecast</h3><p class="tiny muted">Definitions behind the dashboard figures.</p></div></div><div class="card-body">
      <div class="rvd-how-list">
        <div class="rvd-how-item"><span class="rvd-how-num">1</span><p><b>On the books</b> counts confirmed room nights; held group-block rooms are shown separately and included in occupancy.</p></div>
        <div class="rvd-how-item"><span class="rvd-how-num">2</span><p><b>Forecast</b> adds the hotel’s historical net pickup for the remaining lead time, learned from recent stays by weekday.</p></div>
        <div class="rvd-how-item"><span class="rvd-how-num">3</span><p><b>Pickup</b> compares room bookings with the nightly snapshots recorded by the night audit.</p></div>
      </div>
      <div class="rvd-rule-count"><b>{{ $rulesCount }}</b> active yield rule(s). @if(can_do('revenue.view'))<a href="{{ route('revenue.rules') }}">Review Pricing Rules →</a>@endif</div>
      @if(can_do('revenue.manage'))<form method="post" action="{{ route('revenue.snapshot') }}" class="rvd-snapshot" onsubmit="return confirm('Rebuild the last 14 days of on-the-books history from reservation records?')">@csrf<p>Rebuild historical pickup snapshots from reservation records if the last two weeks of nightly history need refreshing.</p><button type="submit" class="btn btn-sm"><x-icon name="refresh" size="sm"/> Rebuild pickup history</button></form>@endif
    </div></section>
  </div>

  <section class="card rvd-table-card" aria-labelledby="rvdTableTitle"><div class="card-head rvd-table-head"><div><h3 id="rvdTableTitle">Forecast &amp; pickup by night</h3><p>Pickup shows rooms gained since yesterday or the prior week{{ $lastSnapshot ? ' · last snapshot '.dmy($lastSnapshot) : '' }}.@if(count($pickup1Values) < $n || count($pickup7Values) < $n) A pickup total marked <b>*</b> covers only the nights that have a snapshot, not the whole window.@endif</p></div><span class="rvd-chart-range"><x-icon name="calendar"/> {{ $days }} nights</span></div>
    <div class="rvd-table-scroll" role="region" aria-label="Scrollable nightly forecast and pickup table" tabindex="0"><table class="table table-compact rvd-table">
      <thead><tr><th scope="col">Date</th><th scope="col" class="center">Lead</th><th scope="col" class="num">Booked</th><th scope="col" class="num">Held</th><th scope="col" class="num">Occ %</th><th scope="col" class="num">ADR</th><th scope="col" class="num">Revenue</th><th scope="col" class="num" title="Rooms picked up since yesterday">Pickup 1d</th><th scope="col" class="num" title="Rooms picked up in the last 7 days">Pickup 7d</th><th scope="col" class="num">Forecast</th><th scope="col" class="num">Fcst revenue</th><th scope="col"><span class="rvd-sr-only">Actions</span></th></tr></thead>
      <tbody>
        @foreach($rows as $r)
          @php $oc = $r['fc_occ'] >= 80 ? 'occ-hi' : ($r['fc_occ'] >= 50 ? 'occ-mid' : 'occ-lo'); $pk = fn ($v) => $v === null ? '<span class="muted">—</span>' : ($v > 0 ? '<span class="pickup-pos">+'.$v.'</span>' : ($v < 0 ? '<span class="pickup-neg">'.$v.'</span>' : '0')); $isToday = $r['date'] === bdate()->toDateString(); @endphp
          <tr class="{{ $r['weekend'] ? 'rvd-weekend ' : '' }}{{ $isToday ? 'rvd-today' : '' }}"><td class="rvd-date"><b>{{ $r['dow'] }}</b> <time datetime="{{ $r['date'] }}">{{ \Illuminate\Support\Carbon::parse($r['date'])->format('d M') }}</time>@if($isToday) <span class="rvd-sr-only">(today)</span>@endif</td>
            <td class="center small muted">{{ $r['lead'] }}d</td><td class="num">{{ $r['rooms'] }}</td><td class="num">{{ $r['held'] ?: '—' }}</td><td class="num {{ $oc }}">{{ $r['occ'] }}%</td>
            <td class="num">{{ $r['adr'] ? money($r['adr']) : '—' }}</td><td class="num">{{ money($r['revenue']) }}</td><td class="num">{!! $pk($r['pickup1']) !!}</td><td class="num">{!! $pk($r['pickup7']) !!}</td>
            <td class="num rvd-forecast {{ $oc }}">{{ $r['fc_rooms'] }} · {{ $r['fc_occ'] }}%</td><td class="num">{{ money($r['fc_revenue']) }}</td>
            <td class="right rvd-price-action"><a class="btn btn-sm btn-ghost" href="{{ route('revenue.pricing', ['from' => $r['date'], 'days' => 7]) }}" aria-label="Open Pricing Grid starting {{ \Illuminate\Support\Carbon::parse($r['date'])->format('D j M') }}" title="Price this date">Price</a></td>
          </tr>
        @endforeach
      </tbody>
      @if($n)
        <tfoot><tr><td colspan="2">Total</td><td class="num">{{ array_sum(array_column($rows, 'rooms')) }}</td><td class="num">{{ array_sum(array_column($rows, 'held')) }}</td><td></td><td></td><td class="num">{{ money(array_sum(array_column($rows, 'revenue'))) }}</td>
          @php
            // A total over a column where some nights have no snapshot is a total over fewer
            // nights than the row beside it. It is marked rather than silently short.
            $pk1 = count($pickup1Values); $pk7 = count($pickup7Values);
          @endphp
          <td class="num" title="{{ $pk1 ? 'Rooms picked up across '.$pk1.' of '.$n.' nights with a snapshot' : 'No snapshot in this window' }}">{{ $pk1 ? array_sum($pickup1Values) : '—' }}@if($pk1 && $pk1 < $n)<b>*</b>@endif</td><td class="num" title="{{ $pk7 ? 'Rooms picked up across '.$pk7.' of '.$n.' nights with a snapshot' : 'No snapshot in this window' }}">{{ $pk7 ? array_sum($pickup7Values) : '—' }}@if($pk7 && $pk7 < $n)<b>*</b>@endif</td><td class="num">{{ array_sum(array_column($rows, 'fc_rooms')) }}</td><td class="num">{{ money(array_sum(array_column($rows, 'fc_revenue'))) }}</td><td></td></tr></tfoot>
      @endif
    </table></div>
  </section>
</div>
@endsection
