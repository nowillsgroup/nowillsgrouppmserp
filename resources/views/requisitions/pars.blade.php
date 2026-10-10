@extends('layouts.app')
@section('title', 'Store Par Levels')
@section('crumbs') <a href="{{ route('req.index') }}">Requisitions</a> / <a href="{{ route('req.setup') }}">Budgets</a> / Par levels @endsection
@section('actions')
@if($store && can_do('requisitions.raise') && $rows->where('suggest', '>', 0)->count())
<a class="btn btn-primary" id="topUp" href="{{ route('req.create', ['department' => $dept?->id, 'to_store' => $store->id, 'fill' => 1]) }}"><x-icon name="refresh"/> Requisition to top up ({{ $rows->where('suggest', '>', 0)->count() }})</a>
@endif
@endsection
@section('content')
@php $qf = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.'); $withPar = $rows->filter(fn ($r) => $r->par !== null); @endphp
<div class="tabs">@foreach($stores as $s)<a href="?store={{ $s->id }}" class="{{ $store?->id === $s->id ? 'active' : '' }}">{{ $s->name }}</a>@endforeach</div>
@if($store)
<div class="stats wide">
  <x-stat label="Items with a par" :value="$withPar->count()" icon="layers" tone="blue" :sub="$dept ? 'serves '.$dept->name : 'no department delivers here'"/>
  <x-stat label="Below par" :value="$withPar->filter(fn ($r) => $r->stock < $r->par)->count()" icon="alert" :tone="$withPar->filter(fn ($r) => $r->stock < $r->par)->count() ? 'amber' : 'green'"/>
  <x-stat label="To top up" :value="$rows->where('suggest', '>', 0)->count()" icon="box" tone="purple" sub="after open requisitions"/>
</div>
<form method="post" action="{{ route('req.pars.save') }}" class="card flush">@csrf<input type="hidden" name="store_id" value="{{ $store->id }}">
  <div class="card-head"><h3>{{ $store->name }}</h3>@if(can_do('requisitions.manage'))<button class="btn btn-primary btn-sm" id="savePars"><x-icon name="check"/> Save par levels</button>@endif</div>
  <div class="card-body table-wrap"><table class="table" id="parTable">
    <thead><tr><th>Item</th><th>Category</th><th class="num">Par</th><th class="num">In store</th><th style="min-width:140px">Cover</th><th class="num">On open requisitions</th><th class="num">Top up</th></tr></thead><tbody>
    @foreach($rows as $r)
    <tr class="{{ $r->par === null ? 'muted-row' : '' }}">
      <td>{{ $r->item->name }} <span class="tiny muted">{{ $r->item->unit }}</span></td>
      <td class="small muted">{{ $r->item->category?->name }}</td>
      <td class="num">@if(can_do('requisitions.manage'))<input type="number" name="par[{{ $r->item->id }}]" value="{{ $r->par !== null ? $qf($r->par) : '' }}" min="0" step="0.01" class="input input-sm" style="width:84px" placeholder="—">@else{{ $r->par !== null ? $qf($r->par) : '—' }}@endif</td>
      <td class="num">{{ $qf($r->stock) }}</td>
      <td>@if($r->par)<div class="progress"><span style="width:{{ min(100, max(0, $r->stock / $r->par * 100)) }}%;{{ $r->stock < $r->par * .5 ? 'background:#dc2626' : ($r->stock < $r->par ? 'background:#f59e0b' : '') }}"></span></div>@endif</td>
      <td class="num small">{{ $r->pending ? $qf($r->pending) : '' }}</td>
      <td class="num bold">{{ $r->suggest ? $qf($r->suggest) : '' }}</td>
    </tr>
    @endforeach
    </tbody></table></div>
</form>
<p class="tiny muted mt-1">Par = what the store should hold. Top up = par − in store − already coming on open requisitions. <i>Requisition to top up</i> starts a requisition from the main store with these quantities. Leave par blank for items the store doesn't keep.</p>
@else<x-empty text="No sub-stores – par levels apply to department stores (kitchen, bar, housekeeping)." icon="box"/>@endif
@endsection
