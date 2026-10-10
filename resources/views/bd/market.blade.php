@extends('layouts.app')
@section('title', 'Market Mix')
@section('crumbs') Business Development / Market mix @endsection
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'bd_market_mix', 'from' => $from->toDateString(), 'to' => $to->toDateString()]) }}"><x-icon name="download"/> Report / CSV</a>
@endsection
@section('content')
<div class="between-row mb-1">
  <div class="tabs" id="mixTabs">@foreach(['nationality' => 'Source market (nationality)', 'segment' => 'Segment', 'source' => 'Business source'] as $k => $l)<a class="{{ $by === $k ? 'active' : '' }}" href="{{ route('bd.market', ['by' => $k, 'from' => $from->toDateString(), 'to' => $to->toDateString()]) }}">{{ $l }}</a>@endforeach</div>
  <form method="get" class="flex gap-1 wrap"><input type="hidden" name="by" value="{{ $by }}"><input type="date" name="from" value="{{ $from->toDateString() }}" aria-label="From"><input type="date" name="to" value="{{ $to->toDateString() }}" aria-label="To"><button class="btn btn-sm">Show</button></form>
</div>
@php $tot = ['b' => $rows->sum('bookings'), 'n' => $rows->sum('nights'), 'r' => $rows->sum('revenue'), 'ln' => $rows->sum('ly_nights'), 'lr' => $rows->sum('ly_revenue')]; $g = $tot['lr'] > 0 ? round(100 * ($tot['r'] - $tot['lr']) / $tot['lr'], 1) : null; @endphp
<div class="stats">
  <x-stat label="Room revenue (arrivals)" :value="money_short($tot['r'])" icon="money" tone="green" :sub="$g === null ? 'no stays last year' : (($g >= 0 ? '+' : '').$g.'% vs same period last year')"/>
  <x-stat label="Room nights" :value="number_format($tot['n'])" icon="moon" tone="blue" :sub="'LY '.number_format($tot['ln'])"/>
  <x-stat label="Bookings" :value="number_format($tot['b'])" icon="bed" tone="purple"/>
  <x-stat label="Average rate" :value="$tot['n'] ? money_short($tot['r'] / $tot['n']) : '—'" icon="chart" tone="amber"/>
</div>
<div class="card flush mt-1"><div class="card-body table-wrap"><table class="table table-compact" id="mixTable"><thead><tr><th>{{ ['nationality' => 'Source market', 'segment' => 'Segment', 'source' => 'Business source'][$by] }}</th><th class="num">Bookings</th><th class="num">Nights</th><th class="num">ADR</th><th class="num">Room revenue</th><th style="min-width:10rem">Share</th><th class="num">LY nights</th><th class="num">LY revenue</th><th class="num">Growth</th></tr></thead><tbody>
  @forelse($rows as $x)<tr><td>{{ $x['label'] }}</td><td class="num">{{ $x['bookings'] }}</td><td class="num">{{ number_format($x['nights']) }}</td><td class="num">{{ $x['adr'] !== null ? number_format($x['adr'], 2) : '—' }}</td><td class="num">{{ number_format($x['revenue'], 2) }}</td>
    <td><div class="dm-bar"><span style="width:{{ min(100, $x['share']) }}%"></span></div><span class="tiny">{{ $x['share'] }}%</span></td><td class="num muted">{{ number_format($x['ly_nights']) }}</td><td class="num muted">{{ number_format($x['ly_revenue'], 2) }}</td>
    <td class="num {{ $x['growth'] === null ? 'muted' : ($x['growth'] >= 0 ? 'text-green' : 'text-red') }}">{{ $x['growth'] === null ? ($x['revenue'] > 0 ? 'new' : 'lost') : ($x['growth'] >= 0 ? '+' : '').$x['growth'].'%' }}</td></tr>
  @empty<tr><td colspan="9" class="muted">No stays arriving in this period.</td></tr>@endforelse
  @if($rows->count())<tr class="bold-row"><td>Total</td><td class="num">{{ $tot['b'] }}</td><td class="num">{{ number_format($tot['n']) }}</td><td class="num">{{ $tot['n'] ? number_format($tot['r'] / $tot['n'], 2) : '—' }}</td><td class="num">{{ number_format($tot['r'], 2) }}</td><td>100%</td><td class="num">{{ number_format($tot['ln']) }}</td><td class="num">{{ number_format($tot['lr'], 2) }}</td><td class="num">{{ $g === null ? '' : ($g >= 0 ? '+' : '').$g.'%' }}</td></tr>@endif
</tbody></table><p class="tiny muted" style="padding:.5rem .9rem">Stays arriving {{ $from->format('d M Y') }} – {{ $to->format('d M Y') }} (cancelled excluded), room revenue = booked rate × nights; last year = the same dates a year earlier. Record guest nationality at check-in to sharpen the source-market view.</p></div></div>
@endsection
