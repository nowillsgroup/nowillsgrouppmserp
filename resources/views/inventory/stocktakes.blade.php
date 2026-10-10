@extends('layouts.app')
@section('title', 'Stock Take')
@section('crumbs') Inventory / Stock Take (Physical Count) @endsection
@section('actions')<a class="btn" href="{{ route('inventory.reorder') }}"><x-icon name="refresh"/> Re-order suggestions</a>@endsection
@section('content')
<div class="grid g-2-1">
  <div class="card flush"><div class="card-head"><h3>Count sheets</h3></div><div class="card-body table-wrap"><table class="table">
    <thead><tr><th>No.</th><th>Store</th><th>Count date</th><th class="num">Items</th><th class="num">Counted</th><th class="num">Variance value</th><th>Status</th><th>By</th></tr></thead><tbody>
    @forelse($takes as $t)<tr>
      <td><a class="bold" href="{{ route('stocktakes.show', $t) }}">{{ $t->take_no }}</a></td><td>{{ $t->store->name }}</td><td>{{ $t->count_date->format('d M Y') }}</td>
      <td class="num">{{ $t->lines_count }}</td><td class="num">{{ $t->counted_count }}</td>
      <td class="num {{ $t->variance_value < 0 ? 'text-red' : '' }}">{{ $t->status === 'posted' ? money($t->variance_value, false) : '—' }}</td>
      <td>{!! status_badge($t->status) !!}</td><td class="small">{{ $t->creator?->name }}@if($t->poster)<div class="tiny muted">posted by {{ $t->poster->name }}</div>@endif</td>
    </tr>@empty<tr><td colspan="8"><x-empty text="No stock takes yet. Create a count sheet to begin." icon="box"/></td></tr>@endforelse
    </tbody></table></div></div>
  @if(can_do('inventory.manage'))
  <div class="card"><div class="card-head"><h3><x-icon name="plus"/> New count sheet</h3></div><div class="card-body">
    <form method="post" action="{{ route('stocktakes.store') }}">@csrf
      <div class="form-grid" style="grid-template-columns:1fr">
        <x-field name="store_id" label="Store" type="select" :options="$stores" required/>
        <x-field name="count_date" label="Count date" type="date" :value="bdate()->toDateString()" required/>
        <x-field name="item_category_id" label="Category (optional)" type="select" :options="$categories" placeholder="All items"/>
        <x-field name="notes" label="Notes" type="textarea"/>
      </div>
      <p class="tiny muted">System quantities are frozen now. Receipts or issues made during the count are not double-counted.</p>
      <button class="btn btn-primary mt-1">Create count sheet</button>
    </form>
  </div></div>
  @endif
</div>
{{ $takes->links() }}
@endsection
