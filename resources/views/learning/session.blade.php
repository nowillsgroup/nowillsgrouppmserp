@extends('layouts.app')
@section('title', $s->number.' '.$s->course->name)
@section('crumbs') Learning & SOPs / Sessions / {{ $s->number }} @endsection
@php $train = can_do('learning.train'); $c = $s->course; $open = $s->status === 'scheduled'; $canComplete = $open && $s->date->lte(bdate()); $enrolled = $s->records->where('status', 'enrolled'); @endphp
@section('actions')
  <a class="btn" href="{{ route('learning.session.sheet', $s) }}" target="_blank"><x-icon name="print"/> Attendance sheet</a>
  @if($train && $open)<button class="btn btn-danger" data-modal="cancelModal" id="cancelSessionBtn"><x-icon name="x"/> Cancel session</button>@endif
@endsection
@section('content')
<div class="stats tr-stats" id="sessionStats">
  <x-stat :label="$c->categoryLabel()" :value="$c->name" icon="book" tone="blue" :sub="$c->code.' · '.rtrim(rtrim((string) $c->duration_hours, '0'), '.').' h · '.($c->pass_mark ? 'pass mark '.$c->pass_mark.'%' : 'attendance only')" :href="route('learning.course', $c)"/>
  <x-stat label="When" :value="$s->date->format('D d M Y')" icon="calendar" tone="purple" :sub="$s->start_time.($s->end_time ? '–'.$s->end_time : '').' · '.$s->venue"/>
  <x-stat label="Enrolled" :value="$s->records->count().($s->capacity ? ' / '.$s->capacity : '')" icon="users" tone="green" :sub="'trainer '.$s->trainerLabel()"/>
  <x-stat label="Status" :value="$s->statusLabel()" icon="check" :tone="$s->statusColor() === 'red' ? 'red' : ($s->status === 'completed' ? 'green' : 'amber')" :sub="$s->status === 'cancelled' ? $s->cancel_reason : ((float) $s->cost ? money($s->cost).' total cost' : money($c->cost_per_head).' per head')" />
</div>
<div id="sStatus" data-status="{{ $s->status }}" hidden></div>

<div class="g-2-1 mt-1">
<div class="card flush" id="rosterCard"><div class="card-head"><h3><x-icon name="users"/> {{ $open ? 'Enrolled staff' : 'Results' }}</h3></div>
@php $withResults = $open && $canComplete && $train && $enrolled->count(); @endphp
@if($withResults)<form method="post" action="{{ route('learning.session.complete', $s) }}" id="resultsForm" data-confirm="Record these results and close {{ $s->number }}? Certificates are issued to everyone who passes.">@csrf</form>@endif
<div class="card-body table-wrap"><table class="table table-compact" id="rosterTable">
  <thead><tr><th>Employee</th><th>Department</th>@if($open)<th>{{ $canComplete ? 'Result' : '' }}</th>@if($canComplete && $c->pass_mark)<th>Score %</th>@endif<th></th>@else<th>Result</th><th class="num">Score</th><th>Certificate</th><th>Expires</th>@endif</tr></thead>
  <tbody>@forelse($s->records->sortBy('employee.name') as $r)
    <tr data-employee="{{ $r->employee_id }}"><td><a href="{{ route('learning.employee', $r->employee) }}"><b>{{ $r->employee->name }}</b></a><div class="tiny muted">{{ $r->employee->designation }}</div></td><td class="small">{{ $r->employee->department?->name }}</td>
    @if($open)
      <td>@if($canComplete && $train)<select form="resultsForm" name="results[{{ $r->employee_id }}][status]" aria-label="Result for {{ $r->employee->name }}" class="res-status">
          <option value="attended">{{ $c->pass_mark ? 'Attended (scored)' : 'Attended' }}</option>@if(! $c->pass_mark)<option value="failed">Did not complete</option>@endif<option value="no_show">No-show</option></select>@else<span class="badge badge-blue">Enrolled</span>@endif</td>
      @if($canComplete && $c->pass_mark)<td><input form="resultsForm" type="number" name="results[{{ $r->employee_id }}][score]" min="0" max="100" step="0.5" style="width:6rem" aria-label="Score for {{ $r->employee->name }}" class="res-score"></td>@endif
      <td class="right">@if($train)<form method="post" action="{{ route('learning.unenrol', $r) }}" data-confirm="Remove {{ $r->employee->name }} from this session?">@csrf<button class="btn btn-sm btn-ghost" title="Remove"><x-icon name="trash"/></button></form>@endif</td>
    @else
      <td><span class="badge badge-{{ $r->statusColor() }}">{{ $r->statusLabel() }}</span></td><td class="num">{{ $r->score !== null ? rtrim(rtrim((string) $r->score, '0'), '.').'%' : '' }}</td>
      <td class="small">@if($r->status === 'passed')<a href="{{ route('learning.certificate', $r) }}" target="_blank">{{ $r->certificate_no }}</a>@endif</td><td class="small">{{ $r->expires_on?->format('d M Y') ?? ($r->status === 'passed' ? 'no expiry' : '') }}</td>
    @endif</tr>
  @empty<tr><td colspan="6" class="empty">Nobody enrolled yet.</td></tr>@endforelse</tbody>
</table></div>
@if($withResults)
<div class="card-body"><button form="resultsForm" class="btn btn-primary" id="completeBtn"><x-icon name="check"/> Record results & close session</button> <span class="tiny muted">{{ $c->pass_mark ? 'A score of '.$c->pass_mark.'% or more passes.' : 'Everyone who attended passes.' }} {{ $c->validity_months ? 'Certificates are valid for '.$c->validityLabel().'.' : '' }}</span></div>
@elseif($open && ! $canComplete)
<div class="card-body tiny muted">Results can be recorded from {{ $s->date->format('d M') }}, once the session has taken place.</div>
@endif
</div>

<div>
@if($open && $train)
<div class="card" id="enrolCard"><div class="card-head"><h3><x-icon name="plus"/> Enrol staff</h3></div>
<div class="card-body"><form method="post" action="{{ route('learning.session.enrol', $s) }}" id="enrolForm">@csrf
  @if($needing->count())<div class="small bold mb-1">Need this course</div>
    @foreach($needing as $g)<label class="between-row small" data-need="{{ $g['employee']->id }}"><span><input type="checkbox" name="employees[]" value="{{ $g['employee']->id }}" checked> {{ $g['employee']->name }} <span class="tiny muted">{{ $g['employee']->designation }}</span></span>@include('learning._cell', ['cell' => $g['cell']])</label>@endforeach
  @else<div class="small muted mb-1">Everyone who needs this course is trained or already booked.</div>@endif
  <details class="mt-1"><summary class="small">Other staff</summary>
    @foreach($others->reject(fn ($e) => $needing->contains(fn ($g) => $g['employee']->id === $e->id)) as $e)<label class="small" style="display:block"><input type="checkbox" name="employees[]" value="{{ $e->id }}"> {{ $e->name }} <span class="tiny muted">{{ $e->designation }}</span></label>@endforeach
  </details>
  <button class="btn btn-primary mt-1" id="enrolBtn"><x-icon name="users"/> Enrol</button>
</form></div></div>
@endif
@if($s->notes)<div class="card mt-1"><div class="card-body small">{{ $s->notes }}</div></div>@endif
</div>
</div>

@if($train && $open)
<div class="modal" id="cancelModal"><div class="modal-box" style="max-width:30rem"><form method="post" action="{{ route('learning.session.cancel', $s) }}">@csrf
  <div class="modal-head"><h3>Cancel {{ $s->number }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="field"><label>Reason (enrolled staff are told)</label><input name="reason" required maxlength="190" id="cancelReason"></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger">Cancel session</button></div>
</form></div></div>
@endif
@endsection
