@extends('layouts.app')
@section('title', 'Staff Meals')
@section('crumbs') F&amp;B Cost Control / Staff meals @endsection
@php $canCost = \App\Services\FbCostService::canCost(auth()->user()); @endphp
@section('actions')
  @if($canCost)<button class="btn btn-primary" data-modal="fbcMealModal" id="fbcMealNew"><x-icon name="plus"/> Log staff meal</button>@endif
@endsection
@section('content')
<div class="stats" id="fbcMealStats">
  <x-stat label="Covers" :value="number_format($covers)" icon="users" tone="blue" :sub="$days.' day(s) logged'"/>
  <x-stat label="Employee meal cost" :value="money_short($total)" icon="coffee" tone="purple" sub="credited out of the cost of food sold"/>
  <x-stat label="Average per cover" :value="$covers ? money($total / $covers) : '—'" icon="money" tone="slate" :sub="'standard '.money($cfg['meal_cost'])"/>
</div>
<div class="card"><div class="card-body"><form method="get" class="flex gap-1 wrap" style="align-items:center">
  <input type="date" name="from" value="{{ $from }}" aria-label="From"><input type="date" name="to" value="{{ $to }}" aria-label="To"><button class="btn btn-sm">Show</button>
</form></div></div>
<div class="card flush"><div class="card-body table-wrap"><table class="table table-compact" id="fbcMealTable">
  <thead><tr><th>Date</th><th>Meal</th><th class="num">Covers</th><th class="num">Cost / cover</th><th class="num">Total</th><th>Notes</th><th>By</th><th></th></tr></thead><tbody>
  @forelse($rows as $r)<tr><td class="nowrap">{{ $r->business_date->format('D d M') }}</td><td>{{ \App\Models\FbStaffMeal::MEALS[$r->meal] ?? $r->meal }}</td>
    <td class="num">{{ $r->covers }}</td><td class="num">{{ money($r->cost_per_cover) }}</td><td class="num"><b>{{ money($r->total) }}</b></td><td class="small">{{ $r->notes }}</td><td class="small">{{ $r->user?->name }}</td>
    <td class="nowrap">@if($canCost)<button type="button" class="btn btn-sm" data-modal="fbcMealModal" data-fbusiness_date="{{ $r->business_date->toDateString() }}" data-fmeal="{{ $r->meal }}" data-fcovers="{{ $r->covers }}" data-fcost_per_cover="{{ $r->cost_per_cover }}" data-fnotes="{{ $r->notes }}">Edit</button>
      <form method="post" action="{{ route('fbc.meals.delete', $r) }}" style="display:inline" onsubmit="return confirm('Remove this entry?')">@csrf<button class="btn btn-sm btn-danger">Remove</button></form>@endif</td></tr>
  @empty<tr><td colspan="8" class="muted">No staff meals logged in the period.</td></tr>@endforelse
  </tbody></table></div></div>
<p class="tiny muted">USALI: the cost of employee meals is moved out of the cost of food sold to employee benefits of the departments. The kitchen issues canteen ingredients through a store issue; this log records the covers served.</p>
@if($canCost)
<div class="modal" id="fbcMealModal"><div class="modal-box"><form method="post" action="{{ route('fbc.meals.store') }}" data-reset>@csrf
  <div class="modal-head"><h3>Staff meal</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field"><label>Business date</label><input type="date" name="business_date" value="{{ bdate()->toDateString() }}" max="{{ bdate()->toDateString() }}" required></div>
    <div class="field"><label>Meal</label><select name="meal" required>@foreach(\App\Models\FbStaffMeal::MEALS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label>Covers</label><input type="number" name="covers" min="1" max="2000" required></div>
    <div class="field"><label>Cost per cover</label><input type="number" step="0.01" min="0.01" name="cost_per_cover" value="{{ $cfg['meal_cost'] }}"></div>
    <div class="field span-all"><label>Notes</label><input name="notes" maxlength="190"></div>
  </div><p class="tiny muted">Logging the same date and meal again replaces the entry.</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save</button></div>
</form></div></div>
@endif
@endsection
