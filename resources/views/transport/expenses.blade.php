@extends('layouts.app')
@section('title', 'Vehicle Expenses')
@section('crumbs') <a href="{{ route('tr.index') }}">Transport</a> / Vehicle expenses @endsection
@section('content')
<form class="filters">
  <input type="month" name="month" value="{{ $month->format('Y-m') }}" data-autosubmit aria-label="Month">
  <select name="vehicle" data-autosubmit aria-label="Vehicle"><option value="">All vehicles</option>@foreach($vehicles as $v)<option value="{{ $v->id }}" @selected(request('vehicle') == $v->id)>{{ $v->code }}</option>@endforeach</select>
  <select name="kind" data-autosubmit aria-label="Kind"><option value="">All kinds</option>@foreach(\App\Models\VehicleExpense::KINDS as $k => $l)<option value="{{ $k }}" @selected(request('kind') === $k)>{{ $l }}</option>@endforeach</select>
</form>
<div class="stats">
  <x-stat label="Total {{ $month->format('M Y') }}" :value="money($rows->sum('amount'))" icon="wallet" tone="blue" :sub="$rows->count().' entries'"/>
  <x-stat label="Fuel" :value="money($byKind['fuel'] ?? 0)" icon="car" tone="amber" :sub="rtrim(rtrim(number_format((float) $rows->where('kind', 'fuel')->sum('litres'), 2), '0'), '.').' litres'"/>
  <x-stat label="Service & repair" :value="money(($byKind['service'] ?? 0) + ($byKind['repair'] ?? 0))" icon="tool" tone="purple"/>
  <x-stat label="Tolls, parking & other" :value="money(($byKind['toll'] ?? 0) + ($byKind['parking'] ?? 0) + ($byKind['other'] ?? 0))" icon="receipt" tone="slate"/>
</div>
<div class="grid g-2-1">
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="expTable">
  <thead><tr><th>Date</th><th>Vehicle</th><th>Kind</th><th class="num">Litres</th><th class="num">Amount</th><th class="num">Odometer</th><th>Vendor · notes</th><th>By</th><th></th></tr></thead><tbody>
  @forelse($rows as $e)
    <tr><td class="nowrap">{{ $e->date->format('d M') }}</td><td><b>{{ $e->vehicle->code }}</b></td><td>{{ \App\Models\VehicleExpense::KINDS[$e->kind] }}</td>
      <td class="num">{{ $e->litres !== null ? rtrim(rtrim(number_format((float) $e->litres, 2), '0'), '.') : '' }}</td><td class="num">{{ money($e->amount) }}</td>
      <td class="num">{{ $e->odometer ? number_format($e->odometer) : '' }}</td><td class="small">{{ $e->vendor }}{{ $e->notes ? ' · '.$e->notes : '' }}</td>
      <td class="small">{{ $e->driver?->name ?? $e->creator?->name }}</td>
      <td class="right">@if(can_do('transport.manage'))<form method="post" action="{{ route('tr.expenses.destroy', $e) }}" data-confirm="Delete this entry?">@csrf @method('DELETE')<button class="btn btn-sm btn-ghost" title="Delete"><x-icon name="trash"/></button></form>@endif</td></tr>
  @empty<tr><td colspan="9"><x-empty text="No expenses this month." icon="wallet"/></td></tr>@endforelse
</tbody></table></div></div>
@if(can_do('transport.manage'))
<div class="card"><div class="card-head"><h3>Add expense</h3></div><div class="card-body">
  <form method="post" action="{{ route('tr.expenses.store') }}" id="expForm">@csrf
    <x-field name="vehicle_id" label="Vehicle" type="select" :options="$vehicles->pluck('code', 'id')" required/>
    <x-field name="date" label="Date" type="date" :value="now()->toDateString()" required/>
    <x-field name="kind" label="Kind" type="select" :options="\App\Models\VehicleExpense::KINDS" :placeholder="false"/>
    <x-field name="litres" label="Litres / kWh (fuel)" type="number" step="0.01"/>
    <x-field name="amount" label="Amount" type="number" step="0.01" required/>
    <x-field name="odometer" label="Odometer (km)" type="number"/>
    <x-field name="driver_id" label="Driver" type="select" :options="$drivers" placeholder="—"/>
    <x-field name="vendor" label="Vendor / pump"/>
    <x-field name="notes" label="Notes"/>
    <button class="btn btn-primary btn-block" id="saveExp">Save</button>
  </form>
  <p class="tiny muted mt-1">Running costs feed the Vehicle Utilisation &amp; Running Cost report (cost per km, km per litre). Pay suppliers through Accounts Payable as usual.</p>
</div></div>
@endif
</div>
@endsection
