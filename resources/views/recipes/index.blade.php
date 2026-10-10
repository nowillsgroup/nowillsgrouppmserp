@extends('layouts.app')
@section('title', 'Recipes & F&B Costing')
@section('crumbs') Inventory / Recipes @endsection
@section('content')
<div class="grid g-3 mb-2">
  <div class="stat"><div class="stat-label">Menu items costed</div><div class="stat-value">{{ $costed }} / {{ $total }}</div><div class="stat-sub">items with a recipe deduct stock on settlement</div></div>
  <div class="stat"><div class="stat-label">Target food cost</div><div class="stat-value">{{ rtrim(rtrim(number_format($target, 1), '0'), '.') }}%</div><div class="stat-sub">change in Settings</div></div>
  <div class="stat"><div class="stat-label">Stock is taken from</div><div class="stat-value" style="font-size:1rem">the outlet's store</div><div class="stat-sub"><a href="{{ route('masters.index', 'outlets') }}">Configure per outlet →</a></div></div>
</div>
<div class="card flush">
  <div class="card-head"><h3>Menu items</h3>
    <form method="get" class="filters"><div class="field"><input name="q" value="{{ request('q') }}" placeholder="Search item…"></div>
      <div class="field"><select name="status" data-autosubmit><option value="">All</option><option value="costed" @selected(request('status')==='costed')>With recipe</option><option value="missing" @selected(request('status')==='missing')>Missing recipe</option></select></div><button class="btn">Filter</button></form></div>
  <div class="card-body table-wrap"><table class="table">
    <thead><tr><th>Item</th><th>Category</th><th>Ingredients</th><th class="num">Price</th><th class="num">Portion cost</th><th class="num">Food cost %</th><th></th></tr></thead><tbody>
    @forelse($items as $m)
      <tr><td class="bold">{{ $m->name }}</td><td class="small">{{ $m->category?->name }}</td>
        <td class="small">@forelse($m->recipes as $r){{ $r->inventoryItem->name }} <span class="muted">{{ rtrim(rtrim(number_format($r->qty, 3), '0'), '.') }} {{ $r->inventoryItem->unit }}</span>@if(!$loop->last), @endif @empty<span class="badge badge-amber">No recipe</span>@endforelse</td>
        <td class="num">{{ money($m->price, false) }}</td><td class="num">{{ $m->recipes->isNotEmpty() ? money($m->portion_cost, false) : '—' }}</td>
        <td class="num bold {{ $m->cost_pct === null ? '' : ($m->cost_pct > $target ? 'text-red' : 'text-green') }}">{{ $m->cost_pct !== null ? $m->cost_pct.'%' : '—' }}</td>
        <td class="right"><a class="btn btn-sm" href="{{ route('recipes.edit', $m) }}">{{ $m->recipes->isNotEmpty() ? 'Edit' : 'Add' }} recipe</a></td></tr>
    @empty<tr><td colspan="7"><x-empty text="No menu items found." icon="box"/></td></tr>@endforelse
    </tbody></table></div>
  @if($items->hasPages())<div class="card-foot">{{ $items->links() }}</div>@endif
</div>
@endsection
