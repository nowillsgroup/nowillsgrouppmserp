@extends('layouts.app')
@section('title', 'Stock Ledger')
@section('content')
<div class="card"><div class="card-body"><form class="filters">
<div class="field"><label>From</label><input type="date" name="from" value="{{ $from }}"></div><div class="field"><label>To</label><input type="date" name="to" value="{{ $to }}"></div>
<div class="field"><label>Item</label><select name="item"><option value="">All</option>@foreach($items as $id=>$n)<option value="{{ $id }}" @selected(request('item')==$id)>{{ $n }}</option>@endforeach</select></div>
<div class="field"><label>Store</label><select name="store"><option value="">All</option>@foreach($stores as $id=>$n)<option value="{{ $id }}" @selected(request('store')==$id)>{{ $n }}</option>@endforeach</select></div>
<div class="field"><label>Type</label><select name="type"><option value="">All</option>@foreach(['opening','purchase','issue','transfer_in','transfer_out','adjustment','wastage','consumption'] as $t)<option @selected(request('type')==$t)>{{ $t }}</option>@endforeach</select></div>
<button class="btn btn-primary">Filter</button></form></div></div>
<div class="card flush"><div class="card-body table-wrap"><table class="table">
<thead><tr><th>Date</th><th>Item</th><th>Store</th><th>Type</th><th class="num">In</th><th class="num">Out</th><th class="num">Rate</th><th>Dept / Ref</th><th>Remarks</th><th>By</th></tr></thead><tbody>
@forelse($moves as $m)<tr><td>{{ $m->date->format('d M Y') }}</td><td class="bold">{{ $m->item->name }}</td><td>{{ $m->store->name }}</td><td><span class="badge {{ $m->qty > 0 ? 'badge-green' : 'badge-red' }}">{{ str_replace('_',' ',$m->type) }}</span></td>
<td class="num">{{ $m->qty > 0 ? (float)$m->qty : '' }}</td><td class="num">{{ $m->qty < 0 ? abs((float)$m->qty) : '' }}</td><td class="num">{{ money($m->rate, false) }}</td><td>{{ $m->department }} {{ $m->reference }}</td><td class="small">{{ $m->remarks }}</td><td class="tiny muted">{{ $m->user?->username }}</td></tr>
@empty<tr><td colspan="10"><x-empty text="No movements." icon="box"/></td></tr>@endforelse</tbody></table>{{ $moves->links() }}</div></div>
@endsection
