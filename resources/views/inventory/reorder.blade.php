@extends('layouts.app')
@section('title', 'Re-order Suggestions')
@section('crumbs') Inventory / Re-order Suggestions @endsection
@section('content')
<div class="alert alert-info"><x-icon name="refresh"/><div>These items are at or below their re-order level across all stores. The suggested quantity brings stock up to twice the re-order level, less anything already on open POs. The supplier defaults to the last one used. Tick the items to order, and one draft PO is created per supplier.</div></div>
<form method="post" action="{{ route('inventory.reorder.store') }}">@csrf
<div class="card flush"><div class="card-body table-wrap"><table class="table table-compact">
  <thead><tr><th style="width:28px"><input type="checkbox" checked onclick="document.querySelectorAll('.ro-pick').forEach(c => c.checked = this.checked)"></th><th>Item</th><th class="num">In stock</th><th class="num">Re-order level</th><th class="num">On order</th><th>Supplier</th><th style="width:110px">Order qty</th><th style="width:110px">Rate</th></tr></thead><tbody>
  @forelse($rows as $i => $r)<tr>
    <td><input type="hidden" name="lines[{{ $i }}][item_id]" value="{{ $r->item->id }}"><input type="checkbox" class="ro-pick" name="lines[{{ $i }}][pick]" value="1" @checked($r->suggest > 0)></td>
    <td class="bold">{{ $r->item->name }} <span class="tiny muted">{{ $r->item->category->name ?? '' }}</span></td>
    <td class="num {{ $r->stock <= 0 ? 'text-red bold' : 'text-amber' }}">{{ (float) $r->stock }} {{ $r->item->unit }}</td><td class="num">{{ (float) $r->level }}</td><td class="num">{{ $r->on_order ?: '—' }}</td>
    <td><select name="lines[{{ $i }}][supplier_id]"><option value="">– choose –</option>@foreach($suppliers as $sid => $sn)<option value="{{ $sid }}" @selected($sid == $r->supplier_id)>{{ $sn }}</option>@endforeach</select></td>
    <td><input type="number" name="lines[{{ $i }}][qty]" value="{{ $r->suggest }}" min="0" step="0.01" style="width:90px"></td>
    <td><input type="number" name="lines[{{ $i }}][rate]" value="{{ $r->rate }}" min="0" step="0.01" style="width:90px"></td>
  </tr>@empty<tr><td colspan="8"><x-empty text="Nothing to re-order. All items are above their re-order level." icon="check"/></td></tr>@endforelse
  </tbody></table></div></div>
@if($rows->isNotEmpty() && can_do('inventory.manage'))
<div class="card"><div class="card-body flex gap-1 wrap items-end">
  <x-field name="store_id" label="Deliver to store" type="select" :options="$stores" required/>
  <x-field name="expected_date" label="Expected delivery" type="date" :value="bdate()->copy()->addDays(3)->toDateString()"/>
  <button class="btn btn-primary"><x-icon name="receipt"/> Create draft POs</button>
</div></div>
@endif
</form>
@endsection
