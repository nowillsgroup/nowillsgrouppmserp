@extends('layouts.app')
@section('title', 'Side dishes sold')
@section('crumbs') F&amp;B Cost Control / Side dishes @endsection
@section('content')
<div class="card"><div class="card-body"><form method="get" class="flex gap-1 wrap" style="align-items:center">
  <input type="date" name="from" value="{{ $from }}" aria-label="From"><input type="date" name="to" value="{{ $to }}" aria-label="To">
  <select name="outlet" aria-label="Outlet"><option value="">All outlets</option>@foreach($outlets as $o)<option value="{{ $o->id }}" @selected((int) $outlet === $o->id)>{{ $o->name }}</option>@endforeach</select>
  <button class="btn btn-sm">Show</button>
  <span class="small muted">Settled bills only; complimentary bills are excluded, as they are from the menu mix.</span>
</form></div></div>

@php $gaps = collect($sides['rows'])->where('no_cost', true); @endphp
@if($gaps->isNotEmpty())
<div class="alert alert-warn" id="fbcSidesNoCost"><x-icon name="alert"/><div>
  <b>{{ $gaps->count() }}</b> side dish(es) sold without a cost on the menu master – {{ number_format($gaps->sum('units')) }} portion(s) and
  {{ money($sides['no_cost_revenue']) }} of revenue whose gross profit is a guess:
  @foreach($gaps as $g)<b>{{ $g['name'] }}</b>{{ $loop->last ? '.' : ' · ' }}@endforeach
  <a href="{{ route('masters.index', 'menu-items') }}">Set the cost on the menu master</a>.
</div></div>
@endif

<div class="stats" id="fbcSideStats">
  <x-stat label="Side dishes sold" :value="number_format($sides['units'], $sides['units'] == (int) $sides['units'] ? 0 : 3)" icon="cup" tone="blue" :sub="count($sides['rows']).' different side dishes'"/>
  <x-stat label="Side dish revenue" :value="money_short($sides['revenue'])" icon="money" tone="green" sub="charged on top of the dish"/>
  <x-stat label="Side dish cost" :value="money_short($sides['cost'])" icon="box" tone="amber" sub="at the cost in force when sold"/>
  <x-stat label="Gross profit" :value="money_short($sides['gp'])" icon="trend" tone="purple" :sub="$sides['gp_pct'] !== null ? $sides['gp_pct'].'% margin' : 'no revenue in the period'"/>
</div>

<div class="card flush"><div class="card-head"><h3>What the sides earned and what they cost</h3>
  <span class="small muted">{{ $from }} to {{ $to }}</span></div>
  <div class="card-body table-wrap"><table class="table table-compact" id="fbcSides">
    <thead><tr><th>Side dish</th><th class="num">Portions</th><th class="num">Revenue</th><th class="num">Share</th><th class="num">Unit cost</th>
      <th class="num">Cost</th><th class="num">Gross profit</th><th class="num">GP %</th></tr></thead>
    <tbody>
    @forelse($sides['rows'] as $r)
      <tr class="{{ ($r['gp_pct'] !== null && $r['gp_pct'] < 50) || $r['no_cost'] ? 'row-amber' : '' }}">
        <td><b>{{ $r['name'] }}</b>@if($r['no_cost']) <span class="badge badge-amber">no cost</span>@endif</td>
        <td class="num">{{ number_format($r['units'], $r['units'] == (int) $r['units'] ? 0 : 3) }}</td>
        <td class="num">{{ money($r['revenue']) }}</td>
        <td class="num">{{ $r['share'] }}%</td>
        <td class="num">{{ $r['units'] > 0 ? money($r['cost'] / $r['units']) : '—' }}</td>
        <td class="num">{{ money($r['cost']) }}</td>
        <td class="num"><b>{{ money($r['gp']) }}</b></td>
        <td class="num">{{ $r['gp_pct'] !== null ? $r['gp_pct'].'%' : '—' }}</td>
      </tr>
    @empty
      <tr><td colspan="8" class="muted">No side dishes were sold in this period.</td></tr>
    @endforelse
    </tbody>
    @if(count($sides['rows']))
    <tfoot><tr class="bold-row"><td>Total</td><td class="num">{{ number_format($sides['units'], $sides['units'] == (int) $sides['units'] ? 0 : 3) }}</td>
      <td class="num">{{ money($sides['revenue']) }}</td><td class="num">100%</td><td></td><td class="num">{{ money($sides['cost']) }}</td>
      <td class="num">{{ money($sides['gp']) }}</td><td class="num">{{ $sides['gp_pct'] !== null ? $sides['gp_pct'].'%' : '—' }}</td></tr></tfoot>
    @endif
  </table></div>
</div>

<div class="card"><div class="card-body small muted">
  <p class="m-0">A side dish is priced at its own rate and costed at its own cost, both taken from the bill line at the moment of sale — a
    side re-priced today does not rewrite last month. The cost is the side dish's cost on the menu master, so keep it current:
    the food cost of the dish it was sold with, the menu-engineering margin and the theoretical cost of the day all include it.</p>
  @if($sides['no_cost'] > 0)
    <p class="m-0" style="margin-top:.5rem">A side dish sold with no cost shows a 100 % margin that is not earned: its cost is missing on the master,
      so the food cost of the dish it was sold with is understated by that cost. The {{ $sides['no_cost'] }} side dish(es) marked above are worth
      {{ money($sides['no_cost_revenue']) }} of revenue in this period.</p>
  @endif
  @if($sides['uncosted'] > 0)
    <p class="m-0" style="margin-top:.5rem">Of the cost above, {{ money($sides['uncosted']) }} comes from bills written before the side cost was recorded on the line:
      it is the side's cost on the master today, the best figure available for those sales.</p>
  @endif
  <p class="m-0" style="margin-top:.5rem"><a href="{{ route('fbc.menu') }}">Menu engineering</a> ·
    <a href="{{ route('fbc.statement') }}">Cost statement</a> ·
    <a href="{{ route('masters.index', 'menu-items') }}">Menu master – side dishes and their costs</a></p>
</div></div>
@endsection
