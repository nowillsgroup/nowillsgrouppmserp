@extends('layouts.app')
@section('title', 'Market Mix')
@section('crumbs') Business Development / Market mix @endsection
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'bd_market_mix', 'from' => $from->toDateString(), 'to' => $to->toDateString()]) }}"><x-icon name="download"/> Report / CSV</a>
@endsection
@section('content')
@include('bd._workspace-nav')
<div class="between-row mb-1">
  <nav class="tabs" id="mixTabs" aria-label="Market mix breakdown">@foreach(['nationality' => 'Source market (nationality)', 'segment' => 'Segment', 'source' => 'Business source'] as $k => $l)<a class="{{ $by === $k ? 'active' : '' }}" href="{{ route('bd.market', ['by' => $k, 'from' => $from->toDateString(), 'to' => $to->toDateString()]) }}" @if($by === $k) aria-current="page" @endif>{{ $l }}</a>@endforeach</nav>
  <form method="get" class="flex gap-1 wrap" id="marketDateFilter"><input type="hidden" name="by" value="{{ $by }}"><label class="sr-only" for="marketFrom">Arrival start date</label><input type="date" id="marketFrom" name="from" value="{{ $from->toDateString() }}"><label class="sr-only" for="marketTo">Arrival end date</label><input type="date" id="marketTo" name="to" value="{{ $to->toDateString() }}"><button class="btn btn-sm">Show</button></form>
</div>
<div class="flex gap-1 wrap mb-1" role="group" aria-label="Common arrival ranges">
  <a class="btn btn-sm btn-ghost" href="{{ route('bd.market', ['by' => $by, 'from' => bdate()->copy()->startOfYear()->toDateString(), 'to' => bdate()->toDateString()]) }}">Year to date</a>
  <a class="btn btn-sm btn-ghost" href="{{ route('bd.market', ['by' => $by, 'from' => bdate()->copy()->subDays(29)->toDateString(), 'to' => bdate()->toDateString()]) }}">Past 30 days</a>
</div>
@php $tot = ['b' => $rows->sum('bookings'), 'n' => $rows->sum('nights'), 'r' => $rows->sum('revenue'), 'ln' => $rows->sum('ly_nights'), 'lr' => $rows->sum('ly_revenue')]; $g = $tot['lr'] > 0 ? round(100 * ($tot['r'] - $tot['lr']) / $tot['lr'], 1) : null; @endphp
<div class="stats">
  <x-stat label="Booked room revenue" :value="money_short($tot['r'])" icon="money" tone="green" :sub="$g === null ? 'no bookings last year' : (($g >= 0 ? '+' : '').$g.'% vs same period last year')"/>
  <x-stat label="Room nights" :value="number_format($tot['n'])" icon="moon" tone="blue" :sub="'LY '.number_format($tot['ln'])"/>
  <x-stat label="Bookings" :value="number_format($tot['b'])" icon="bed" tone="purple"/>
  <x-stat label="Booked ADR" :value="$tot['n'] ? money_short($tot['r'] / $tot['n']) : '—'" icon="chart" tone="amber" sub="booked room revenue ÷ booked nights"/>
</div>
<div class="card flush mt-1"><div class="card-head"><h3><x-icon name="globe"/> {{ ['nationality' => 'Source market', 'segment' => 'Segment', 'source' => 'Business source'][$by] }}</h3><span class="tiny muted">{{ $from->format('d M Y') }} – {{ $to->format('d M Y') }} · {{ number_format($rows->count()) }} group{{ $rows->count() === 1 ? '' : 's' }}</span></div><div class="card-body table-wrap"><table class="table table-compact" id="mixTable"><thead><tr><th scope="col">{{ ['nationality' => 'Source market', 'segment' => 'Segment', 'source' => 'Business source'][$by] }}</th><th scope="col" class="num">Bookings</th><th scope="col" class="num">Nights</th><th scope="col" class="num">ADR</th><th scope="col" class="num">Room revenue</th><th scope="col" style="min-width:10rem">Share</th><th scope="col" class="num">LY nights</th><th scope="col" class="num">LY revenue</th><th scope="col" class="num">Growth</th></tr></thead><tbody>
  @forelse($rows as $x)<tr><td>{{ $x['label'] }}</td><td class="num">{{ $x['bookings'] }}</td><td class="num">{{ number_format($x['nights']) }}</td><td class="num">{{ $x['adr'] !== null ? money($x['adr']) : '—' }}</td><td class="num">{{ money($x['revenue']) }}</td>
    <td><div class="dm-bar"><span style="width:{{ min(100, $x['share']) }}%"></span></div><span class="tiny">{{ $x['share'] }}%</span></td><td class="num muted">{{ number_format($x['ly_nights']) }}</td><td class="num muted">{{ money($x['ly_revenue']) }}</td>
    <td class="num {{ $x['growth'] === null ? 'muted' : ($x['growth'] >= 0 ? 'text-green' : 'text-red') }}">{{ $x['growth'] === null ? ($x['revenue'] > 0 ? 'new' : 'lost') : ($x['growth'] >= 0 ? '+' : '').$x['growth'].'%' }}</td></tr>
  @empty<tr><td colspan="9" class="muted">No stays arriving in this period.</td></tr>@endforelse
  @if($rows->count())<tr class="bold-row"><td>Total</td><td class="num">{{ $tot['b'] }}</td><td class="num">{{ number_format($tot['n']) }}</td><td class="num">{{ $tot['n'] ? money($tot['r'] / $tot['n']) : '—' }}</td><td class="num">{{ money($tot['r']) }}</td><td>100%</td><td class="num">{{ number_format($tot['ln']) }}</td><td class="num">{{ money($tot['lr']) }}</td><td class="num">{{ $g === null ? '' : ($g >= 0 ? '+' : '').$g.'%' }}</td></tr>@endif
</tbody></table><p class="tiny muted" style="padding:.5rem .9rem">Includes reservations arriving {{ $from->format('d M Y') }} – {{ $to->format('d M Y') }}; cancelled reservations and cancelled room lines are excluded. Revenue is booked room rate × nights, not posted or recognized revenue. Last year uses the same dates a year earlier. Record guest nationality at check-in to improve source-market reporting.</p></div></div>
@endsection
