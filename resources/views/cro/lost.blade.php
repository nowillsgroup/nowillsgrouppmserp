@extends('layouts.app')
@section('title', 'Lost Business')
@section('crumbs') Central Reservations / Lost Business @endsection
@php $E = \App\Models\CroEnquiry::class; $maxU = max(1, $demand->max('capacity'), $demand->max('unconstrained')); @endphp
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'cro_lost_business']) }}"><x-icon name="download"/> Lost business report</a>
  <a class="btn" href="{{ route('reports.show', ['report' => 'cro_demand']) }}"><x-icon name="download"/> Unconstrained demand</a>
@endsection
@section('content')
<div class="card"><div class="card-body"><form method="get" class="flex gap-1 wrap" style="align-items:center" id="lostFilter"><span class="small">Enquiries logged</span><input type="date" name="from" value="{{ $from->toDateString() }}" aria-label="From"><input type="date" name="to" value="{{ $to->toDateString() }}" aria-label="To">
  <span class="small">· demand from</span><input type="date" name="stay" value="{{ $stay->toDateString() }}" aria-label="Stay dates from"><button class="btn btn-sm">Show</button></form></div></div>
<div class="stats" id="lostStats">
  <x-stat label="Lost revenue" :value="money_short($kpi['lost_revenue'])" icon="alert" tone="red" :sub="$kpi['lost'].' enquiries lost'"/>
  <x-stat label="Denials" :value="$kpi['denials']" icon="lock" tone="red" :sub="number_format($kpi['denied_nights']).' room nights turned away'"/>
  <x-stat label="Regrets" :value="$kpi['regrets']" icon="minus" tone="amber" :sub="number_format($kpi['regret_nights']).' room nights declined'"/>
  <x-stat label="Kept in the group" :value="money_short($kpi['referred_revenue'])" icon="swap" tone="purple" :sub="$kpi['referred'].' referrals to sister hotels'"/>
</div>
<div class="g-2-1 mt-1">
  <div class="card flush" id="reasonsCard"><div class="card-head"><h3><x-icon name="chart"/> Why we lose business</h3></div><div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>Reason</th><th>Type</th><th class="num">Lost</th><th>Share</th><th class="num">Room nights</th><th class="num">Value</th><th class="num">Referred</th></tr></thead><tbody>
    @forelse($reasons as $x)<tr data-reason="{{ $x['reason'] }}"><td>{{ $x['label'] }}</td><td><span class="badge badge-{{ $E::LOSS_TYPES[$x['type']][1] ?? 'slate' }}">{{ $E::LOSS_TYPES[$x['type']][0] ?? '' }}</span></td><td class="num">{{ $x['count'] }}</td>
      <td style="min-width:6rem"><div class="dm-bar"><span style="width:{{ min(100, $x['share']) }}%"></span></div><span class="tiny">{{ $x['share'] }}%</span></td><td class="num">{{ number_format($x['nights']) }}</td><td class="num">{{ number_format($x['value'], 2) }}</td><td class="num">{{ $x['referred'] ?: '' }}</td></tr>
    @empty<tr><td colspan="7" class="muted">No lost business in this period.</td></tr>@endforelse
  </tbody></table></div></div>
  <div class="card flush" id="competitorsCard"><div class="card-head"><h3><x-icon name="building"/> Regrets won by competitors</h3><a class="btn btn-sm btn-ghost" href="{{ route('bd.compset') }}">Comp set →</a></div><div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>Competitor</th><th class="num">Enquiries</th><th class="num">Value</th></tr></thead><tbody>
    @forelse($competitors as $c)<tr><td>{{ $c['name'] }}</td><td class="num">{{ $c['count'] }}</td><td class="num">{{ number_format($c['value'], 2) }}</td></tr>
    @empty<tr><td colspan="3" class="muted">None recorded.</td></tr>@endforelse
  </tbody></table><p class="tiny muted" style="padding:.5rem .9rem">Recorded when a regret is closed as “Booked another hotel” with the competitor named.</p></div></div>
</div>
<div class="card flush mt-1" id="demandCard"><div class="card-head"><h3><x-icon name="trend"/> Unconstrained demand – next 21 nights</h3><span class="tiny muted">on the books + denied room nights vs capacity</span></div><div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>Night</th><th class="num">Capacity</th><th class="num">On books</th><th class="num">Denied</th><th class="num">Regrets</th><th class="num">Occ.</th><th class="num">Unconstrained</th><th style="width:30%"></th></tr></thead><tbody>
  @foreach($demand as $d)<tr class="{{ $d['uocc'] > 100 ? 'row-alert' : '' }}" data-night="{{ $d['date']->toDateString() }}"><td class="nowrap">{{ $d['date']->format('D d M') }}</td><td class="num">{{ $d['capacity'] }}</td><td class="num">{{ $d['sold'] }}</td><td class="num">{{ $d['denied'] ?: '' }}</td><td class="num">{{ $d['regret'] ?: '' }}</td>
    <td class="num">{{ $d['occ'] }}%</td><td class="num"><b>{{ $d['uocc'] }}%</b></td>
    <td><div class="cro-demand"><span class="cro-sold" style="width:{{ round($d['sold'] / $maxU * 100, 1) }}%"></span><span class="cro-denied" style="width:{{ round($d['denied'] / $maxU * 100, 1) }}%"></span><i style="left:{{ round($d['capacity'] / $maxU * 100, 1) }}%"></i></div></td></tr>@endforeach
</tbody></table><p class="tiny muted" style="padding:.5rem .9rem">Unconstrained occupancy above 100 % means real demand exceeded the hotel – a signal to raise rates or tighten restrictions on those nights (see Revenue Management), and to refer the overflow to sister hotels.</p></div></div>
@endsection
