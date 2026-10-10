@extends('layouts.app')
@section('title', 'Stock Summary')
@section('actions')
@if(can_do('inventory.manage'))<a class="btn" href="{{ route('inventory.movement') }}"><x-icon name="swap"/> Issue / Adjust</a><a class="btn btn-primary" href="{{ route('purchases.create') }}"><x-icon name="plus"/> Purchase Order</a>@endif
@endsection
@section('content')
<div class="stats"><x-stat label="Active items" :value="$items->count()" icon="box" tone="blue"/><x-stat label="Stock value (last cost)" :value="money($value)" icon="money" tone="green"/><x-stat label="Below reorder level" :value="$items->filter(fn($i)=>$i->total <= $i->reorder_level)->count()" icon="alert" tone="red" :href="route('inventory.index',['low'=>1])"/></div>
<div class="card"><div class="card-body"><form class="filters"><div class="field"><input name="q" value="{{ request('q') }}" placeholder="Search item"></div><div class="field"><select name="category"><option value="">All categories</option>@foreach($categories as $id=>$n)<option value="{{ $id }}" @selected(request('category')==$id)>{{ $n }}</option>@endforeach</select></div><label class="check"><input type="checkbox" name="low" value="1" @checked(request('low'))> Low stock only</label><button class="btn btn-primary">Filter</button></form></div></div>
<div class="card flush"><div class="card-body table-wrap"><table class="table">
<thead><tr><th>Code</th><th>Item</th><th>Category</th><th>Unit</th>@foreach($stores as $s)<th class="num">{{ $s->name }}</th>@endforeach<th class="num">Total</th><th class="num">Reorder</th><th class="num">Value</th></tr></thead><tbody>
@forelse($items as $i)
<tr><td class="small muted">{{ $i->code }}</td><td class="bold">{{ $i->name }}</td><td>{{ $i->category?->name }}</td><td>{{ $i->unit }}</td>
@foreach($stores as $s)<td class="num">{{ ($v = (float)($i->by_store[$s->id] ?? 0)) ? rtrim(rtrim(number_format($v,2),'0'),'.') : '—' }}</td>@endforeach
<td class="num bold {{ $i->total <= $i->reorder_level ? 'text-red' : '' }}">{{ rtrim(rtrim(number_format($i->total,2),'0'),'.') }}</td><td class="num">{{ (float)$i->reorder_level }}</td><td class="num">{{ money($i->total * $i->last_cost, false) }}</td></tr>
@empty<tr><td colspan="{{ 7 + $stores->count() }}"><x-empty text="No items." icon="box"/></td></tr>@endforelse
</tbody></table></div></div>
@endsection
