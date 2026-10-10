@extends('layouts.app')
@section('title', 'Goods Receipts (GRN)')
@section('content')
<div class="grid g-3">
  <x-stat label="Receipts" :value="(int) $tot->n" icon="box" tone="blue"/>
  <x-stat label="Accepted value" :value="money($tot->v ?? 0)" icon="check" tone="green"/>
  <x-stat label="Rejected & returned" :value="money($tot->r ?? 0)" icon="alert" tone="red" href="?rejections=1&from={{ $from }}&to={{ $to }}"/>
</div>
@if($overdue->count())
<div class="card" id="grnOverdue"><div class="card-head"><h3>Deliveries overdue</h3><span class="badge badge-red">{{ $overdue->count() }}</span></div><div class="card-body table-wrap"><table class="table table-compact"><tbody>
@foreach($overdue as $po)<tr><td><a class="bold" href="{{ route('purchases.show', $po) }}">{{ $po->po_no }}</a></td><td>{{ $po->supplier->name }}</td><td>{!! status_badge($po->status) !!}</td><td class="small">expected {{ $po->expected_date->format('d M Y') }}</td><td class="text-red small">{{ $po->expected_date->diffInDays(bdate()) }} day(s) late</td></tr>@endforeach
</tbody></table></div></div>
@endif
<form class="card" method="get"><div class="card-body flex gap-1 wrap items-end">
  <div class="field"><label>From</label><input type="date" name="from" value="{{ $from }}" class="input"></div><div class="field"><label>To</label><input type="date" name="to" value="{{ $to }}" class="input"></div>
  <div class="field"><label>Supplier</label><select name="supplier" class="input"><option value="">All</option>@foreach($suppliers as $id => $n)<option value="{{ $id }}" @selected(request('supplier') == $id)>{{ $n }}</option>@endforeach</select></div>
  <label class="check"><input type="checkbox" name="rejections" value="1" @checked(request()->boolean('rejections'))> <span>With rejections only</span></label>
  <button class="btn">Show</button></div></form>
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="grnTable">
<thead><tr><th>GRN</th><th>Date</th><th>PO</th><th>Supplier</th><th>Store</th><th>Delivery note</th><th class="num">Accepted</th><th class="num">Rejected</th><th>By</th><th></th></tr></thead><tbody>
@forelse($grns as $g)<tr><td class="bold">{{ $g->grn_no }}</td><td>{{ $g->date->format('d M Y') }}@if(! $g->onTime()) <span class="badge badge-amber">late</span>@endif</td><td><a href="{{ route('purchases.show', $g->purchase_order_id) }}">{{ $g->order->po_no }}</a></td><td>{{ $g->supplier->name }}</td><td>{{ $g->store->name }}</td><td class="small">{{ $g->delivery_note ?: '—' }}</td>
  <td class="num">{{ money($g->total, false) }}</td><td class="num {{ (float) $g->rejected_value ? 'text-red' : 'muted' }}">{{ (float) $g->rejected_value ? money($g->rejected_value, false) : '—' }}</td><td class="small">{{ $g->receiver?->name }}</td>
  <td><a class="btn btn-sm" href="{{ route('grn.print', $g) }}?autoprint=1" target="_blank"><x-icon name="print" size="sm"/></a></td></tr>
@empty<tr><td colspan="10"><x-empty text="No goods receipts in this period." icon="box"/></td></tr>@endforelse
</tbody></table>{{ $grns->links() }}</div></div>
@endsection
