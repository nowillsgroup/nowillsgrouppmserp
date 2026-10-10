@extends('layouts.app')
@section('title', $c->name)
@section('crumbs') Learning & SOPs / Courses / {{ $c->code }} @endsection
@php $manage = can_do('learning.manage'); $train = can_do('learning.train'); $L = \App\Services\LearningService::STATES; @endphp
@section('actions')
  @if($manage)<button class="btn" data-modal="courseModal{{ $c->id }}" id="editCourseBtn"><x-icon name="edit"/> Edit</button>@endif
  @if($train)<a class="btn btn-primary" href="{{ route('learning.sessions', ['course' => $c->id]) }}#schedule" id="scheduleCourseBtn"><x-icon name="calendar"/> Schedule a session</a>@endif
@endsection
@section('content')
<div class="stats tr-stats" id="courseStats">
  <x-stat :label="$c->categoryLabel().' · '.(\App\Models\TrainingCourse::DELIVERY[$c->delivery] ?? '')" :value="$c->code" icon="book" tone="blue" :sub="rtrim(rtrim((string) $c->duration_hours, '0'), '.').' h · '.$c->validityLabel().' · '.($c->pass_mark ? 'pass '.$c->pass_mark.'%' : 'attendance')"/>
  <x-stat label="Hold it" :value="$holders->whereIn('cell.state', ['current', 'expiring'])->count().' / '.$holders->count()" icon="shield" tone="green" :sub="$holders->where('cell.state', 'expiring')->count().' expiring soon'"/>
  <x-stat label="Need it now" :value="$needing->count()" icon="alert" :tone="$needing->count() ? 'red' : 'green'" sub="not held or expiring, no seat booked"/>
  <x-stat label="Cost per head" :value="money($c->cost_per_head)" icon="money" tone="purple" :sub="$c->provider ?: 'in-house'"/>
</div>
@if($c->description)<div class="card mt-1"><div class="card-body small" style="white-space:pre-line">{{ $c->description }}</div></div>@endif
<div class="g-2 mt-1">
<div class="card flush" id="reqCard"><div class="card-head"><h3><x-icon name="users"/> Required for</h3></div>
<div class="card-body">
  @forelse($c->requirements as $r)
    <div class="between-row" data-req="{{ $r->id }}"><div><b>{{ $r->label() }}</b><div class="tiny muted">new starters within {{ $r->due_days }} days of joining</div></div>
      @if($manage)<form method="post" action="{{ route('learning.requirement.destroy', $r) }}" data-confirm="Stop requiring {{ $c->name }} for {{ $r->label() }}?">@csrf @method('DELETE')<button class="btn btn-sm btn-ghost" title="Remove"><x-icon name="trash"/></button></form>@endif</div>
  @empty<div class="muted small">Optional – not required for anyone.</div>@endforelse
  @if($manage)
  <form method="post" action="{{ route('learning.requirement.store', $c) }}" class="form-grid mt-1" id="reqForm">@csrf
    <div class="field"><label>Department</label><select name="department_id" id="reqDept"><option value="">Any department</option>@foreach($depts as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></div>
    <div class="field"><label>Job title</label><input name="designation" maxlength="80" list="designationList" placeholder="Any job title" id="reqDesignation"><datalist id="designationList">@foreach($designations as $x)<option value="{{ $x }}">@endforeach</datalist></div>
    <div class="field"><label>Due within (days of joining)</label><input type="number" name="due_days" min="0" max="730" value="30" required id="reqDue"></div>
    <div class="field" style="align-self:end"><button class="btn btn-primary" id="reqAdd"><x-icon name="plus"/> Add requirement</button></div>
  </form>@endif
</div></div>

<div class="card flush" id="sessionsCard"><div class="card-head"><h3><x-icon name="calendar"/> Sessions</h3></div>
<div class="card-body table-wrap"><table class="table table-compact">
  <thead><tr><th>No.</th><th>Date</th><th>Trainer</th><th class="num">Staff</th><th>Status</th></tr></thead>
  <tbody>@forelse($sessions as $s)<tr><td><a href="{{ route('learning.session', $s) }}"><b>{{ $s->number }}</b></a></td><td class="small">{{ $s->date->format('d M Y') }} {{ $s->start_time }}</td><td class="small">{{ $s->trainerLabel() }}</td><td class="num">{{ $s->records_count }}</td><td><span class="badge badge-{{ $s->statusColor() }}">{{ $s->statusLabel() }}</span></td></tr>
  @empty<tr><td colspan="5" class="empty">No sessions yet.</td></tr>@endforelse</tbody>
</table></div></div>
</div>

<div class="card flush mt-1" id="holdersCard"><div class="card-head"><h3><x-icon name="shield"/> Staff who must hold it</h3></div>
<div class="card-body table-wrap"><table class="table table-compact" id="holdersTable">
  <thead><tr><th>Employee</th><th>Department</th><th>State</th><th>Certificate</th><th>Last completed</th></tr></thead>
  <tbody>@forelse($holders as $h) @php $rec = $h['cell']['record']; @endphp
    <tr data-employee="{{ $h['employee']->id }}"><td><a href="{{ route('learning.employee', $h['employee']) }}"><b>{{ $h['employee']->name }}</b></a><div class="tiny muted">{{ $h['employee']->designation }}</div></td><td class="small">{{ $h['employee']->department?->name }}</td>
      <td>@include('learning._cell', ['cell' => $h['cell']])</td><td class="small">@if($rec && $rec->status === 'passed')<a href="{{ route('learning.certificate', $rec) }}" target="_blank">{{ $rec->certificate_no ?: 'view' }}</a>@else — @endif</td><td class="small">{{ $rec?->completed_on?->format('d M Y') ?? '—' }}</td></tr>
  @empty<tr><td colspan="5" class="empty">Nobody is required to take this course.</td></tr>@endforelse</tbody>
</table></div></div>
@if($manage)@include('learning._course_modal', ['c' => $c])@endif
@endsection
