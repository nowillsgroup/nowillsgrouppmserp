@extends('layouts.app')
@section('title', 'Training Matrix')
@section('crumbs') Learning & SOPs / Training matrix @endsection
@php $L = \App\Services\LearningService::STATES; $t = $m['totals']; @endphp
@section('actions')
  <a class="btn" href="{{ route('learning.matrix.print', array_filter(['department' => $dept])) }}" target="_blank"><x-icon name="print"/> Print</a>
  <a class="btn" href="{{ route('reports.show', ['report' => 'training_compliance']) }}"><x-icon name="download"/> Compliance report</a>
@endsection
@section('content')
<div class="card"><div class="card-body flex gap-1 wrap" style="align-items:center;justify-content:space-between">
  <form method="get" class="flex gap-1" style="align-items:center"><label for="mxDept" class="small">Department</label>
    <select name="department" id="mxDept" onchange="this.form.submit()"><option value="">All departments</option>@foreach($depts as $d)<option value="{{ $d->id }}" @selected($dept == $d->id)>{{ $d->name }}</option>@endforeach</select></form>
  <div class="flex gap-1 wrap small" id="mxLegend">@foreach($L as $k => [$l, $c])<span class="mx-cell mx-{{ $k }}">{{ $l }} · {{ $t[$k] }}</span>@endforeach<span class="mx-cell mx-na">not required</span>
    <span class="muted"><x-icon name="calendar"/> = booked on a session</span></div>
  <div class="bold" id="mxPct">Compliance {{ $t['pct'] === null ? '—' : $t['pct'].'%' }}</div>
</div></div>

<div class="card flush mt-1"><div class="card-body table-wrap mx-wrap">
<table class="table table-compact mx-table" id="matrixTable">
  <thead><tr><th class="mx-sticky">Employee</th>@foreach($m['courses'] as $c)<th class="mx-head" data-course="{{ $c->code }}"><a href="{{ route('learning.course', $c) }}" title="{{ $c->name }} · {{ $c->validityLabel() }}">{{ $c->code }}</a><div class="tiny muted">{{ \Illuminate\Support\Str::limit($c->name, 18) }}</div></th>@endforeach<th class="num">%</th></tr></thead>
  <tbody>@forelse($m['employees'] as $e) @php $row = $m['rows'][$e->id]; @endphp
    <tr data-employee="{{ $e->id }}"><td class="mx-sticky"><a href="{{ route('learning.employee', $e) }}"><b>{{ $e->name }}</b></a><div class="tiny muted">{{ $e->designation }} · {{ $e->department?->name }}</div></td>
      @foreach($m['courses'] as $c)<td class="center">@if(isset($m['cells'][$e->id][$c->id]))@include('learning._cell', ['cell' => $m['cells'][$e->id][$c->id]])@else<span class="mx-cell mx-na">–</span>@endif</td>@endforeach
      <td class="num bold {{ ($row['pct'] ?? 100) < 75 ? 'text-red' : '' }}">{{ $row['pct'] === null ? '—' : round($row['pct']).'%' }}</td></tr>
  @empty<tr><td colspan="{{ $m['courses']->count() + 2 }}" class="empty">No staff in this department.</td></tr>@endforelse</tbody>
</table>
@if($m['courses']->isEmpty())<div class="empty">No training requirements yet – add them on <a href="{{ route('learning.courses') }}">Courses & Requirements</a>.</div>@endif
</div></div>
<p class="tiny muted mt-1">A course appears for an employee when a requirement on <a href="{{ route('learning.courses') }}">Courses & Requirements</a> matches their department and / or job title. New starters have the rule's grace period from their joining date before a missing course counts as overdue.</p>
@endsection
