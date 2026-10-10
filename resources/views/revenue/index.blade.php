@extends('layouts.app')
@section('title', 'Revenue Dashboard')
@section('crumbs') Revenue / Dashboard @endsection
@section('actions')
<span class="rm-mode {{ $mode }}" title="Pricing mode"><x-icon name="trend" size="sm"/> {{ \App\Services\RevenueService::MODES[$mode] }}</span>
<form class="filters" method="get"><div class="field"><select name="days" data-autosubmit>@foreach([14,30,60,90] as $d)<option value="{{ $d }}" @selected($days==$d)>Next {{ $d }} days</option>@endforeach</select></div></form>
<a class="btn btn-primary" href="{{ route('revenue.pricing') }}"><x-icon name="trend"/> Pricing grid @if($pending && $mode === 'recommend')<span class="nav-badge">{{ $pending }}</span>@endif</a>
@endsection
@section('content')
@php
  $k7 = $kpi['d7']; $k30 = $kpi['d30']; $p = $kpi['pickup'];
  $rowsV = array_values($rows); $n = count($rowsV); $cap = $rowsV[0]['cap'] ?? 0;
  $W = 1000; $H = 250; $L = 38; $R = 10; $T = 12; $B = 34; $pw = $W - $L - $R; $ph = $H - $T - $B;
  $bw = $n ? $pw / $n : 0; $y = fn ($rooms) => $T + $ph - ($cap ? min(1, $rooms / $cap) : 0) * $ph;
  $every = $n <= 31 ? 1 : 7;
@endphp
@if($mode === 'recommend' && $pending)
<div class="alert alert-info"><x-icon name="trend" size="sm"/> <b>{{ $pending }}</b> rate recommendation(s) for the next 14 days differ from what is live. <a href="{{ route('revenue.pricing') }}">Review &amp; publish →</a></div>
@elseif($mode === 'off')
<div class="alert alert-warn">Revenue management is <b>off</b> – forecasts are shown but no prices change. @if(can_do('revenue.manage'))<a href="{{ route('revenue.rules') }}">Choose a pricing mode →</a>@endif</div>
@endif

<div class="stats">
  <x-stat label="Occupancy on the books · next 7 days" :value="$k7['occ'].'%'" icon="bed" tone="blue" :sub="'Forecast '.$k7['fc_occ'].'% · ADR '.money($k7['adr'])"/>
  <x-stat label="Occupancy on the books · next 30 days" :value="$k30['occ'].'%'" icon="calendar" tone="purple" :sub="'Forecast '.$k30['fc_occ'].'% · '.$k30['rooms'].' room nights'"/>
  <x-stat label="Room revenue on the books · 30 days" :value="money($k30['revenue'])" icon="wallet" tone="green" :sub="'Forecast '.money($k30['fc_revenue']).' · RevPAR '.money($k30['revpar'])"/>
  <x-stat label="Pickup · bookings made in the last 7 days" :value="$p['bookings'].' ('.$p['nights'].' nts)'" icon="trend" tone="amber" :sub="money($p['revenue']).' · '.$p['cancellations'].' cancelled ('.money($p['cx_revenue']).')'"/>
</div>

<div class="card"><div class="card-head"><h3>Demand outlook – next {{ $days }} days</h3>
  <div class="legend"><span><i style="background:#2563eb"></i>On the books</span><span><i style="background:#8b5cf6"></i>Group blocks held</span><span><i style="background:#fbbf24;opacity:.6"></i>Expected pickup</span><span><i style="background:#fff;border:1px dashed #dc2626"></i>Capacity {{ $cap }} rooms</span><span><i style="background:#fff7ed;border:1px solid #fed7aa"></i>Fri / Sat</span></div></div>
<div class="card-body">
@if($n)
<svg class="rm-chart" viewBox="0 0 {{ $W }} {{ $H }}" role="img" aria-label="Occupancy on the books and forecast">
  @foreach($rowsV as $i => $r)@if($r['weekend'])<rect class="wk" x="{{ round($L + $i * $bw, 1) }}" y="{{ $T }}" width="{{ round($bw, 1) }}" height="{{ $ph }}"/>@endif @endforeach
  @foreach([0, 25, 50, 75, 100] as $g)@php $gy = round($T + $ph - $g / 100 * $ph, 1); @endphp
    <line class="grid-l" x1="{{ $L }}" x2="{{ $W - $R }}" y1="{{ $gy }}" y2="{{ $gy }}"/><text x="{{ $L - 6 }}" y="{{ $gy + 4 }}" text-anchor="end">{{ $g }}%</text>@endforeach
  <line class="cap" x1="{{ $L }}" x2="{{ $W - $R }}" y1="{{ $T }}" y2="{{ $T }}"/>
  @foreach($rowsV as $i => $r)
    @php $x = $L + $i * $bw + $bw * .14; $w = max(1, $bw * .72); $y1 = $y($r['rooms']); $y2 = $y($r['rooms'] + $r['held']); $y3 = $y(max($r['fc_rooms'], $r['rooms'] + $r['held'])); @endphp
    <g><title>{{ \Illuminate\Support\Carbon::parse($r['date'])->format('D d M') }} · {{ $r['rooms'] }} booked{{ $r['held'] ? ' + '.$r['held'].' held' : '' }} ({{ $r['occ'] }}%) · forecast {{ $r['fc_rooms'] }} ({{ $r['fc_occ'] }}%) · ADR {{ money($r['adr']) }}</title>
    @if($y3 < $y2)<rect class="b-fc" x="{{ round($x, 1) }}" y="{{ round($y3, 1) }}" width="{{ round($w, 1) }}" height="{{ round($y2 - $y3, 1) }}"/>@endif
    @if($r['held'])<rect class="b-held" x="{{ round($x, 1) }}" y="{{ round($y2, 1) }}" width="{{ round($w, 1) }}" height="{{ round($y1 - $y2, 1) }}"/>@endif
    @if($r['rooms'])<rect class="b-otb" x="{{ round($x, 1) }}" y="{{ round($y1, 1) }}" width="{{ round($w, 1) }}" height="{{ round($T + $ph - $y1, 1) }}" rx="1.5"/>@endif
    <rect x="{{ round($L + $i * $bw, 1) }}" y="{{ $T }}" width="{{ round($bw, 1) }}" height="{{ $ph }}" fill="transparent"/></g>
    @if($i % $every === 0)<text x="{{ round($L + $i * $bw + $bw / 2, 1) }}" y="{{ $H - $B + 15 }}" text-anchor="middle" @if($r['weekend']) style="font-weight:700;fill:#b45309" @endif>{{ \Illuminate\Support\Carbon::parse($r['date'])->format('d') }}</text>
      @if($i === 0 || \Illuminate\Support\Carbon::parse($r['date'])->day <= $every)<text x="{{ round($L + $i * $bw + $bw / 2, 1) }}" y="{{ $H - 4 }}" text-anchor="middle">{{ \Illuminate\Support\Carbon::parse($r['date'])->format('M') }}</text>@endif
    @endif
  @endforeach
</svg>
@endif
</div></div>

<div class="grid g-2 mt-2">
<div class="card"><div class="card-head"><h3>Pickup by source · 7 days</h3></div><div class="card-body">
@forelse($sources as $s)
<div class="flex justify-between small mb-1"><span><b>{{ $s['name'] }}</b> <span class="muted">{{ $s['bookings'] }} bkg · {{ $s['nights'] }} nts</span></span><span class="bold">{{ money($s['revenue']) }}</span></div>
@php $max = max(1, $sources->max('revenue')); @endphp
<div class="progress mb-2"><span style="width:{{ round($s['revenue'] * 100 / $max) }}%"></span></div>
@empty
<x-empty title="No new bookings" text="Nothing was booked in the last 7 days."/>
@endforelse
</div></div>

<div class="card"><div class="card-head"><h3>How it works</h3></div><div class="card-body small">
<p class="mb-1"><b>On the books</b> = confirmed rooms per night; group-block holds count as sold.</p>
<p class="mb-1"><b>Forecast</b> adds the pickup your hotel normally gets in the remaining days before arrival, learned from the last 6 weeks of actual stays (by weekday).</p>
<p class="mb-1"><b>Pickup</b> compares with the snapshots the night audit records every day.</p>
<p class="mb-0"><b>{{ $rulesCount }}</b> active pricing rule(s). @if(can_do('revenue.view'))<a href="{{ route('revenue.rules') }}">Manage rules →</a>@endif</p>
@if(can_do('revenue.manage'))<form method="post" action="{{ route('revenue.snapshot') }}" class="mt-2" onsubmit="return confirm('Rebuild the last 14 days of on-the-books history from reservation records?')">@csrf<button class="btn btn-sm">Rebuild pickup history</button></form>@endif
</div></div>
</div>

<div class="card flush mt-2"><div class="card-head"><h3>Forecast &amp; pickup by night</h3><span class="muted small">Pickup = rooms gained since yesterday / last week{{ $lastSnapshot ? ' · last snapshot '.dmy($lastSnapshot) : '' }}</span></div>
<div class="card-body table-wrap"><table class="table table-compact">
<thead><tr><th>Date</th><th class="center">Lead</th><th class="num">Booked</th><th class="num">Held</th><th class="num">Occ %</th><th class="num">ADR</th><th class="num">Revenue</th><th class="num" title="Rooms picked up since yesterday">Pickup 1d</th><th class="num" title="Rooms picked up in the last 7 days">Pickup 7d</th><th class="num">Forecast</th><th class="num">Fcst revenue</th><th></th></tr></thead>
<tbody>
@foreach($rows as $r)
@php $oc = $r['fc_occ'] >= 80 ? 'occ-hi' : ($r['fc_occ'] >= 50 ? 'occ-mid' : 'occ-lo'); $pk = fn ($v) => $v === null ? '<span class="muted">—</span>' : ($v > 0 ? '<span class="pickup-pos">+'.$v.'</span>' : ($v < 0 ? '<span class="pickup-neg">'.$v.'</span>' : '0')); @endphp
<tr @if($r['weekend']) style="background:#fffaf3" @endif><td class="nowrap"><b>{{ $r['dow'] }}</b> {{ \Illuminate\Support\Carbon::parse($r['date'])->format('d M') }}</td>
<td class="center small muted">{{ $r['lead'] }}d</td><td class="num">{{ $r['rooms'] }}</td><td class="num">{{ $r['held'] ?: '—' }}</td><td class="num">{{ $r['occ'] }}%</td>
<td class="num">{{ $r['adr'] ? money($r['adr']) : '—' }}</td><td class="num">{{ money($r['revenue']) }}</td><td class="num">{!! $pk($r['pickup1']) !!}</td><td class="num">{!! $pk($r['pickup7']) !!}</td>
<td class="num {{ $oc }} bold">{{ $r['fc_rooms'] }} · {{ $r['fc_occ'] }}%</td><td class="num">{{ money($r['fc_revenue']) }}</td>
<td class="right"><a class="btn btn-sm btn-ghost" href="{{ route('revenue.pricing', ['from' => $r['date'], 'days' => 7]) }}" title="Price this date">Price</a></td></tr>
@endforeach
</tbody>
<tfoot><tr class="bold"><td colspan="2">Total</td><td class="num">{{ array_sum(array_column($rows, 'rooms')) }}</td><td class="num">{{ array_sum(array_column($rows, 'held')) }}</td><td></td><td></td>
<td class="num">{{ money(array_sum(array_column($rows, 'revenue'))) }}</td><td class="num">{{ array_sum(array_map(fn ($r) => (int) $r['pickup1'], $rows)) }}</td><td class="num">{{ array_sum(array_map(fn ($r) => (int) $r['pickup7'], $rows)) }}</td>
<td class="num">{{ array_sum(array_column($rows, 'fc_rooms')) }}</td><td class="num">{{ money(array_sum(array_column($rows, 'fc_revenue'))) }}</td><td></td></tr></tfoot>
</table></div></div>

@endsection
