@extends('layouts.app')
@section('title', 'Sales Diary')
@section('crumbs') Sales / Diary @endsection
@php $A = \App\Models\SalesActivity::class; $seller = can_do('sales.edit') || can_do('sales.manage'); @endphp
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'sales_activity']) }}"><x-icon name="download"/> Report</a>
  @if($seller)<button class="btn" data-modal="actModal" data-title="Log a completed activity" data-fstatus="done" id="logActBtn"><x-icon name="check"/> Log activity</button>
  <button class="btn btn-primary" data-modal="actModal" data-title="Plan an activity" data-fstatus="planned" id="planActBtn"><x-icon name="plus"/> Plan activity</button>@endif
@endsection
@section('content')
@include('sales._owner_toggle')
<div class="stats" id="diaryStats">
  <x-stat label="Overdue" :value="$alerts['overdue']" icon="alert" :tone="$alerts['overdue'] ? 'red' : 'green'" :href="route('sales.activities', ['status' => 'overdue'] + request()->only('owner'))"/>
  <x-stat label="Due today" :value="$alerts['today']" icon="clock" tone="amber"/>
  <x-stat label="Spend this month" :value="money_short($spend)" icon="coffee" tone="slate" sub="entertainment & travel on completed activities"/>
</div>
<div class="tabs mt-1" id="statusTabs">@foreach(['planned' => 'Planned', 'overdue' => 'Overdue', 'done' => 'Done', 'cancelled' => 'Cancelled', 'all' => 'All'] as $k => $l)<a href="?{{ http_build_query(array_merge(request()->except('page', 'status'), ['status' => $k])) }}" class="{{ $status === $k ? 'active' : '' }}">{{ $l }}</a>@endforeach</div>
<div class="card"><div class="card-body"><form method="get" class="flex gap-1 wrap" style="align-items:center" id="actFilter"><input type="hidden" name="status" value="{{ $status }}">@if(request('owner'))<input type="hidden" name="owner" value="{{ request('owner') }}">@endif
  <select name="type" aria-label="Type"><option value="">Any activity</option>@foreach($A::TYPES as $k => [$l])<option value="{{ $k }}" @selected(request('type') === $k)>{{ $l }}</option>@endforeach</select>
  <select name="company" aria-label="Account"><option value="">Any account</option>@foreach($accounts as $a)<option value="{{ $a->id }}" @selected(request('company') == $a->id)>{{ $a->name }}</option>@endforeach</select>
  <label class="small">From <input type="date" name="from" value="{{ request('from') }}"></label><label class="small">to <input type="date" name="to" value="{{ request('to') }}"></label>
  <button class="btn">Filter</button>
</form></div></div>
<div class="card flush mt-1" id="diaryCard"><div class="card-body">@include('sales._activity_rows', ['acts' => $list, 'showAccount' => true])</div></div>
{{ $list->links() }}
@if($seller)@include('sales._activity_modals')@endif
@endsection
