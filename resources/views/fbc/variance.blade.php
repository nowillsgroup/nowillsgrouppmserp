@extends('layouts.app')
@section('title', 'Usage Variance')
@section('crumbs') F&amp;B Cost Control / Usage variance @endsection
@php $over = $rows->where('value', '>', 0); @endphp
@section('content')
<div class="card"><div class="card-body"><form method="get" class="flex gap-1 wrap" style="align-items:center">
  <input type="date" name="from" value="{{ $from }}" aria-label="From"><input type="date" name="to" value="{{ $to }}" aria-label="To">
  <select name="class" aria-label="Class"><option value="">Food &amp; beverage</option><option value="food" @selected($class === 'food')>Food</option><option value="beverage" @selected($class === 'beverage')>Beverage (bar)</option></select>
  <button class="btn btn-sm">Show</button>
</form></div></div>
<div class="stats" id="fbcVarStats">
  <x-stat label="Ideal usage" :value="money_short($rows->sum(fn ($r) => $r->ideal * $r->unit_cost))" icon="book" tone="blue" sub="portions sold & comped × recipe"/>
  <x-stat label="Actual usage" :value="money_short($rows->sum(fn ($r) => $r->actual * $r->unit_cost))" icon="box" tone="slate" sub="consumed, issued, wasted, counted"/>
  <x-stat label="Over-usage" :value="money_short($over->sum('value'))" icon="alert" :tone="$over->sum('value') > 0 ? 'red' : 'green'" :sub="$over->count().' ingredient(s)'"/>
  <x-stat label="Net variance" :value="money_short($rows->sum('value'))" icon="trend" :tone="$rows->sum('value') > 0 ? 'amber' : 'green'"/>
</div>
<div class="card flush"><div class="card-body table-wrap"><table class="table table-compact" id="fbcVarTable">
  <thead><tr><th>Ingredient</th><th class="num">Ideal</th><th class="num">Recipe consumption</th><th class="num">Issued</th><th class="num">Wasted</th><th class="num">Count adj.</th><th class="num">Actual</th><th class="num">Variance</th><th class="num">%</th><th class="num">Value</th></tr></thead><tbody>
  @forelse($rows as $r)@php $q = fn ($v) => rtrim(rtrim(number_format($v, 3), '0'), '.'); @endphp
  <tr class="{{ $r->pct !== null && $r->pct > 10 && $r->value > 0 ? 'row-amber' : '' }}">
    <td><b>{{ $r->item->name }}</b><div class="tiny muted">{{ $r->item->category?->name }} · {{ $r->item->unit }} · {{ money($r->unit_cost) }}</div></td>
    <td class="num">{{ $q($r->ideal) }}</td><td class="num">{{ $q($r->consumed) }}</td><td class="num">{{ $q($r->issued) }}</td><td class="num">{{ $q($r->wasted) }}</td><td class="num">{{ $q($r->counted) }}</td>
    <td class="num"><b>{{ $q($r->actual) }}</b></td><td class="num">{{ $r->variance > 0 ? '+' : '' }}{{ $q($r->variance) }}</td><td class="num">{{ $r->pct !== null ? $r->pct.'%' : '—' }}</td>
    <td class="num"><b class="{{ $r->value > 0 ? 'text-red' : '' }}">{{ money($r->value) }}</b></td></tr>
  @empty<tr><td colspan="10" class="muted">No F&amp;B stock movement in the period.</td></tr>@endforelse
  </tbody></table></div></div>
<p class="tiny muted">Ideal = portions sold and complimentary × net recipe quantity ÷ trim yield. Actual = outflows from the kitchen, bar and outlet stores. Issues are ingredients sent without a sale (staff canteen, banquets, room amenities); bar bottles over-poured or given away show as count adjustments.</p>
@endsection
