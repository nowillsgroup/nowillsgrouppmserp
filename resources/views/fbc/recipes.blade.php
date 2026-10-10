@extends('layouts.app')
@section('title', 'Recipe Cards & Plate Cost')
@section('crumbs') F&amp;B Cost Control / Recipe cards @endsection
@php $canCost = \App\Services\FbCostService::canCost(auth()->user()); @endphp
@section('content')
<div class="stats" id="fbcRecipeStats">
  <x-stat label="Dishes costed" :value="$all->where('st', '!=', 'no_recipe')->count().' / '.$all->count()" icon="book" tone="blue" :href="route('fbc.recipes', ['status' => 'ok'])"/>
  <x-stat label="Above target" :value="$all->where('st', 'over')->count()" icon="alert" :tone="$all->where('st', 'over')->count() ? 'red' : 'green'" :href="route('fbc.recipes', ['status' => 'over'])"/>
  <x-stat label="Without a recipe" :value="$all->where('st', 'no_recipe')->count()" icon="edit" :tone="$all->where('st', 'no_recipe')->count() ? 'amber' : 'green'" :href="route('fbc.recipes', ['status' => 'no_recipe'])"/>
  @php $cst = $all->where('st', '!=', 'no_recipe'); $avg = $cst->sum('price') > 0 ? round($cst->sum('cost') / $cst->sum('price') * 100, 1) : null; @endphp
  <x-stat label="Average plate cost %" :value="$avg !== null ? $avg.'%' : '—'" icon="chart" tone="slate" sub="unweighted – see menu engineering for the sales mix"/>
</div>
<div class="card"><div class="card-body"><form method="get" class="flex gap-1 wrap" style="align-items:center">
  <input type="search" name="q" value="{{ request('q') }}" placeholder="Dish or code" aria-label="Search">
  <select name="outlet" aria-label="Outlet"><option value="">All outlets</option>@foreach($outlets as $o)<option value="{{ $o->id }}" @selected((int) request('outlet') === $o->id)>{{ $o->name }}</option>@endforeach</select>
  <select name="category" aria-label="Category"><option value="">All categories</option>@foreach($categories as $c)<option value="{{ $c->id }}" @selected((int) request('category') === $c->id)>{{ $c->name }}</option>@endforeach</select>
  <select name="status" aria-label="Status"><option value="">Any status</option><option value="ok" @selected(request('status') === 'ok')>Within target</option><option value="over" @selected(request('status') === 'over')>Above target</option><option value="no_recipe" @selected(request('status') === 'no_recipe')>No recipe</option></select>
  <button class="btn btn-sm">Filter</button>
</form></div></div>
<div class="card flush"><div class="card-body table-wrap"><table class="table table-compact" id="fbcRecipeTable">
  <thead><tr><th>Dish</th><th>Category</th><th>Class</th><th class="num">Price</th><th class="num">Plate cost</th><th class="num">GP</th><th class="num">Cost %</th><th class="num">Target</th><th class="num">Creep</th><th>Card</th><th></th></tr></thead><tbody>
  @forelse($dishes as $m)<tr class="{{ $m->st === 'over' ? 'row-amber' : '' }}">
    <td><a href="{{ route('fbc.card', $m) }}"><b>{{ $m->name }}</b></a><div class="tiny muted mono">{{ $m->code }}@if(! $m->is_available) · not available @endif</div></td>
    <td class="small">{{ $m->category?->name }}<div class="tiny muted">{{ $m->outlet?->name ?? 'all outlets' }}</div></td>
    <td><span class="badge badge-{{ $m->cls === 'beverage' ? 'purple' : 'blue' }}">{{ $m->cls }}</span></td>
    <td class="num">{{ money($m->price) }}</td>
    @if($m->st === 'no_recipe')<td colspan="5" class="muted small">no recipe – plate cost unknown</td>
    @else<td class="num">{{ money($m->cost) }}</td><td class="num">{{ money($m->gp) }}</td>
      <td class="num"><b class="{{ $m->st === 'over' ? 'text-red' : '' }}">{{ $m->pct }}%</b></td><td class="num">{{ $m->target }}%</td>
      <td class="num">@if($m->creep)<span class="{{ $m->creep['pct'] >= $cfg['creep_pct'] ? 'badge badge-red' : 'small muted' }}">{{ $m->creep['pct'] > 0 ? '+' : '' }}{{ $m->creep['pct'] }}%</span>@else<span class="muted">—</span>@endif</td>
    @endif
    <td class="small">@if($m->card?->approved_at)<span class="badge badge-green">v{{ $m->card->version }} approved</span>@elseif($m->card)<span class="badge badge-amber">v{{ $m->card->version }} draft</span>@else<span class="muted">—</span>@endif</td>
    <td class="nowrap">@if($canCost)<a class="btn btn-sm" href="{{ route('fbc.card.edit', $m) }}">{{ $m->st === 'no_recipe' ? 'Write recipe' : 'Edit' }}</a>@endif</td>
  </tr>
  @empty<tr><td colspan="11" class="muted">No dishes match.</td></tr>@endforelse
  </tbody></table></div></div>
<p class="tiny muted">Plate cost = net quantity per portion ÷ trim yield × {{ $cfg['basis'] === 'average' ? '90-day weighted average purchase cost' : 'last purchase cost' }}. Prices exclude VAT, NHIL, GETFund and the tourism levy.</p>
@endsection
