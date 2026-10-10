@extends('layouts.app')
@section('title', 'Leases')
@section('crumbs') <a href="{{ route('leases.index') }}">Apartments &amp; Leases</a> / Leases @endsection
@section('actions')
@if(can_do('leases.manage'))<a class="btn btn-primary" href="{{ route('leases.create') }}"><x-icon name="plus"/> New lease</a>@endif
@endsection
@section('content')
<form class="filters" method="get" id="leaseFilter">
  <select name="status" onchange="this.form.submit()">@foreach(['live' => 'Live (draft, active, notice)', 'all' => 'All'] + \App\Models\Lease::STATUSES as $k => $v)<option value="{{ $k }}" @selected($status === $k)>{{ $v }}</option>@endforeach</select>
  <input type="search" name="q" value="{{ $q }}" placeholder="Lease no., tenant, phone, unit"><button class="btn">Search</button>
</form>
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="leasesTable">
  <thead><tr><th>Lease</th><th>Tenant</th><th>Unit</th><th>Term</th><th>Cycle</th><th class="num">Rent / mo</th><th class="num">Balance</th><th>Status</th></tr></thead>
  <tbody>@forelse($leases as $l)
    <tr data-lease="{{ $l->lease_no }}"><td><a class="bold" href="{{ route('leases.show', $l) }}">{{ $l->lease_no }}</a></td><td>{{ $l->tenant_name }}@if($l->company)<div class="tiny muted">{{ $l->company->name }}</div>@endif</td>
      <td>{{ $l->unit->code }}<div class="tiny muted">{{ ucfirst($l->tax_class) }}</div></td><td class="small">{{ dmy($l->start_date) }} – {{ dmy($l->end_date) }}@if($l->move_out_on)<div class="tiny text-amber">out {{ dmy($l->move_out_on) }}</div>@endif</td>
      <td class="small">{{ \App\Models\Lease::CYCLES[$l->cycle_months] }}</td><td class="num">{{ money($l->rent, false) }}</td>
      <td class="num {{ $bal[$l->id] > 0.004 ? 'text-red bold' : '' }}">{{ money($bal[$l->id], false) }}</td><td>@include('leases._status', ['l' => $l])</td></tr>
  @empty <tr><td colspan="8"><x-empty text="No leases found." icon="building"/></td></tr> @endforelse</tbody></table></div></div>
<div class="mt-1">{{ $leases->links() }}</div>
@endsection
