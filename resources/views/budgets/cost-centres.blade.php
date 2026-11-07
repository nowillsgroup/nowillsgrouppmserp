@extends('layouts.app')
@section('title', 'Cost centre budgets')
@section('crumbs') <a href="{{ route('budgets.index') }}">Budgets</a> / Cost centres @endsection
@section('actions')
<a class="btn" href="{{ route('budgets.index') }}"><x-icon name="wallet"/> Budgets</a>
<a class="btn" href="{{ route('budgets.bva') }}" id="bvaLink"><x-icon name="chart"/> Budget vs Actual</a>
@endsection
@section('content')
@php
  $rows = $data['rows'];
  $t = $data['totals'];
  $w = $data['whole'];
  $m = $data['meta'];
  $unattributed = $data['not_allocated'];
@endphp
<div class="stats">
  <x-stat label="Departments with an approved budget" :value="$m['budgeted'].' / '.$m['departments']" icon="users" tone="blue" :sub="\App\Services\BudgetService::fyLabel($fy).($m['pending'] ? ' · '.$m['pending'].' awaiting approval' : '')"/>
  <x-stat label="Allocated to cost centres (year)" :value="money($t['plan'])" icon="wallet" tone="slate" :sub="'whole-hotel expense plan '.money($w['plan'])"/>
  <x-stat label="Charged to cost centres" :value="money($t['actual'])" icon="chart" tone="purple" :sub="$data['tagged_pct'] === null ? 'nothing posted yet' : $data['tagged_pct'].' % of expense carries a cost centre'"/>
  <x-stat label="Expense with no cost centre" :value="money($data['unallocated'])" icon="alert" :tone="$data['unallocated'] > 0.005 ? 'amber' : 'green'" sub="tag it on the journal"/>
</div>

<div class="card flush">
  <div class="card-head">
    <div class="tabs" style="margin:0;border:0">
      @foreach($years as $y => $l)<a href="{{ route('budgets.costcentres', ['fy' => $y]) }}" class="{{ $fy === $y ? 'active' : '' }}">{{ $l }}</a>@endforeach
    </div>
    <span class="muted small">Expense charged to the ledger up to {{ $m['cutoff']->format('d M Y') }}</span>
  </div>
  <div class="card-body table-wrap"><table class="table" id="ccTable">
    <thead><tr><th>Department</th><th>Budget</th><th class="num">Plan (year)</th><th class="num">Plan to date</th><th class="num">Charged</th><th class="num">Variance</th><th class="num">Share</th><th></th></tr></thead>
    <tbody>
    @forelse($rows as $r)
      <tr data-dept="{{ $r->department->name }}">
        <td class="bold">{{ $r->department->code ? $r->department->code.' · ' : '' }}{{ $r->department->name }}
          @if($r->department->is_cost_centre === false)<span class="badge badge-slate" title="Left out of the analysis on purpose">not a cost centre</span>@endif</td>
        <td>
          @if($r->budget)
            <a href="{{ route('budgets.show', $r->budget) }}" class="bold">{{ $r->budget->budget_no }}</a>
            <span class="badge badge-{{ $r->budget->statusTone() }}">{{ $r->budget->statusLabel() }}</span>
            @if($r->budget->is_active)<span class="badge badge-green">Active</span>@endif
            <div class="tiny muted">{{ $r->budget->name }}</div>
          @elseif($r->pending)
            <a href="{{ route('budgets.show', $r->pending) }}" class="bold">{{ $r->pending->budget_no }}</a>
            <span class="badge badge-{{ $r->pending->statusTone() }}">{{ $r->pending->statusLabel() }}</span>
            <div class="tiny muted">not in force until it is approved – no plan counted</div>
          @else
            <span class="tiny muted">no budget</span>
          @endif
        </td>
        <td class="num">{{ $r->plan === null ? '—' : money($r->plan, false) }}</td>
        <td class="num">{{ $r->plan_ytd === null ? '—' : money($r->plan_ytd, false) }}</td>
        <td class="num">{{ money($r->actual, false) }}</td>
        <td class="num {{ $r->variance !== null && $r->variance > 0.005 ? 'text-red' : ($r->variance !== null && $r->variance < -0.005 ? 'text-green' : '') }}">
          @if($r->variance === null)—@else{{ money(abs($r->variance), false) }} {{ $r->variance > 0.005 ? 'over' : ($r->variance < -0.005 ? 'under' : 'on plan') }}@endif
          @if($r->variance_pct !== null)<div class="tiny muted">{{ $r->variance_pct > 0 ? '+' : '' }}{{ $r->variance_pct }} %</div>@endif
        </td>
        <td class="num">{{ $r->per_cent === null ? '—' : $r->per_cent.' %' }}</td>
        <td>
          @if($r->budget)
            <a class="btn btn-sm" href="{{ route('budgets.show', $r->budget) }}"><x-icon name="eye" size="sm"/> Open</a>
          @elseif($r->pending)
            <a class="btn btn-sm" href="{{ route('budgets.show', $r->pending) }}"><x-icon name="eye" size="sm"/> Open</a>
          @elseif(can_do('budget.manage'))
            <form method="post" action="{{ route('budgets.store') }}" class="inline">@csrf
              <input type="hidden" name="fiscal_year" value="{{ $fy }}">
              <input type="hidden" name="department_id" value="{{ $r->department->id }}">
              <input type="hidden" name="method" value="last_year">
              <button class="btn btn-sm" data-dept="{{ $r->department->name }}"><x-icon name="plus" size="sm"/> Start</button>
            </form>
          @else
            <span class="tiny muted">—</span>
          @endif
        </td>
      </tr>
    @empty
      <tr><td colspan="8"><x-empty icon="users" text="No departments yet. Departments are set up under Requisitions → Budgets &amp; setup; each one can then carry its own budget version."/></td></tr>
    @endforelse
    </tbody>
    <tfoot>
      <tr><td colspan="2"><b>Total allocated to cost centres</b></td><td class="num"><b>{{ money($t['plan'], false) }}</b></td><td class="num"><b>{{ money($t['plan_ytd'], false) }}</b></td><td class="num"><b>{{ money($t['actual'], false) }}</b></td><td colspan="3"></td></tr>
      <tr><td colspan="2">Whole-hotel expense plan</td><td class="num">{{ money($w['plan'], false) }}</td><td class="num">{{ money($w['plan_ytd'], false) }}</td><td class="num">{{ money($w['actual'], false) }}</td><td colspan="3"></td></tr>
      <tr><td colspan="2">Plan not yet given to a department</td><td class="num {{ $unattributed > 0.005 ? 'text-amber' : '' }}">{{ money($unattributed, false) }}</td><td class="num">{{ money($data['not_allocated_ytd'], false) }}</td><td class="num">—</td><td colspan="3" class="tiny muted">negative = the departments add up to more than the whole-hotel plan</td></tr>
      <tr><td colspan="2">Expense with no cost centre on the line</td><td class="num">—</td><td class="num">—</td><td class="num {{ $data['unallocated'] > 0.005 ? 'text-amber' : '' }}">{{ money($data['unallocated'], false) }}</td><td colspan="3" class="tiny muted">postings made before the dimension shipped, or journals nobody allocated</td></tr>
    </tfoot>
  </table></div>
</div>

<div class="grid g-2-1">
  <div class="card"><div class="card-head"><h3>How a department gets an actual</h3></div><div class="card-body small muted">
    <p class="m-0 mb-1">Every ledger line can name a <b>cost centre</b>, and these four postings fill it in by themselves:</p>
    <ul class="list">
      <li><span>Stores issued to a department</span><b>the consuming department</b></li>
      <li><span>Petty cash spent from a float</span><b>the float's department</b></li>
      <li><span>An expense claim</span><b>the claimant's department</b></li>
      <li><span>A manual journal</span><b>the cost centre typed on each line</b></li>
    </ul>
    <p class="m-0 mt-1">Everything else – supplier bills, payroll, depreciation – posts with no cost centre until someone allocates the journal.
      Those amounts are not hidden: they sit in <b>Expense with no cost centre</b> above, so the departmental figures always add up to the whole-hotel P&amp;L.</p>
  </div></div>
  <div class="card"><div class="card-head"><h3>Two budgets, one number</h3></div><div class="card-body small muted">
    <p class="m-0 mb-1">A requisition is capped by the department's own budget for the month when it has one, and by the single monthly figure on
      <a href="{{ route('req.setup') }}">Requisitions → Budgets &amp; setup</a> only as a fallback. The requisition screens say which of the two is in force.</p>
    <p class="m-0">Rooms statistics (occupancy, ADR) stay on the whole-hotel budget – a departmental version holds money only.</p>
  </div></div>
</div>
@endsection
