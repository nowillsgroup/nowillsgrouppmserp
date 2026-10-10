@extends('layouts.app')
@section('title', 'POS Orders & Bills')
@section('content')
@include('pos._workspace-nav')
<div class="card"><div class="card-body"><form class="filters">
  <div class="field"><label>From</label><input type="date" name="from" value="{{ $from }}"></div><div class="field"><label>To</label><input type="date" name="to" value="{{ $to }}"></div>
  <div class="field"><label>Search</label><input type="search" name="q" value="{{ $q }}" placeholder="Bill, order, customer, phone…"></div>
  <div class="field"><label>Outlet</label><select name="outlet"><option value="">All</option>@foreach($outlets as $id=>$n)<option value="{{ $id }}" @selected(request('outlet')==$id)>{{ $n }}</option>@endforeach</select></div>
  <div class="field"><label>Status</label><select name="status"><option value="">All</option><option value="unsettled" @selected(request('status') === 'unsettled')>Unsettled (open / billed)</option>@foreach(['open','billed','settled','void'] as $s)<option value="{{ $s }}" @selected(request('status') === $s)>{{ $s }}</option>@endforeach</select></div>
  <button class="btn btn-primary">Filter</button></form></div></div>
<div class="stats">
  <x-stat label="Bills settled" :value="$orders->where('status','settled')->count()" icon="receipt" tone="blue"/>
  <x-stat label="Net sales" :value="money($orders->where('status','settled')->sum(fn($o)=>$o->total-$o->tax))" icon="money" tone="green"/>
  <x-stat label="Tax" :value="money($orders->where('status','settled')->sum('tax'))" icon="chart" tone="purple"/>
  <x-stat label="Posted to rooms" :value="money($orders->where('settlement','room_post')->sum('total'))" icon="bed" tone="teal"/>
</div>
<div class="card flush"><div class="card-body table-wrap"><table class="table">
<thead><tr><th>Order</th><th>Bill</th><th>Date</th><th>Outlet</th><th>Table/Room</th><th>Settlement</th><th class="num">Discount</th><th class="num">Tax</th><th class="num">Total</th><th>Status</th><th></th></tr></thead><tbody>
@forelse($orders as $o)
<tr><td><a href="{{ route('pos.terminal', $o) }}">{{ $o->order_no }}</a></td><td>{{ $o->bill_no }}</td><td class="nowrap">{{ $o->business_date->format('d M') }} {{ $o->created_at->format('H:i') }}</td><td>{{ $o->outlet->name }}</td>
<td>{{ $o->table?->table_no ?? ($o->stay ? 'Room '.$o->stay->room?->room_no : ucfirst(str_replace('_',' ',$o->order_type))) }}</td>
<td>{{ $o->settlement ? ucwords(str_replace('_',' ',$o->settlement)) : '—' }} @if($o->settlement === 'payment') · {{ $o->payments->where('kind', 'payment')->map(fn ($p) => $p->paymentMode?->name)->filter()->unique()->join(', ') ?: ($o->paymentMode?->name ?? 'Payment') }}@endif</td>
<td class="num">{{ money($o->discount, false) }}</td><td class="num">{{ money($o->tax, false) }}</td><td class="num bold">{{ money($o->total, false) }}</td><td>{!! status_badge($o->status) !!}</td>
<td><a class="btn btn-sm btn-ghost" target="_blank" href="{{ route('pos.print', $o) }}"><x-icon name="print" size="sm"/></a></td></tr>
@empty<tr><td colspan="11"><x-empty text="No orders in this period." icon="cup"/></td></tr>@endforelse</tbody></table></div></div>
@endsection
