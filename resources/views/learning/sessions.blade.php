@extends('layouts.app')
@section('title', 'Training Sessions')
@section('crumbs') Learning & SOPs / Sessions @endsection
@php $train = can_do('learning.train'); @endphp
@section('content')
<div class="g-2-1">
<div>
<div class="card flush" id="upcomingCard"><div class="card-head"><h3><x-icon name="calendar"/> Scheduled</h3></div>
<div class="card-body table-wrap"><table class="table table-compact" id="upcomingTable">
  <thead><tr><th>No.</th><th>Date</th><th>Course</th><th>Venue</th><th class="num">Enrolled</th><th></th></tr></thead>
  <tbody>@forelse($upcoming as $s)
    <tr data-session="{{ $s->number }}" class="{{ $s->date->lt(bdate()) ? 'row-due' : '' }}"><td><a href="{{ route('learning.session', $s) }}"><b>{{ $s->number }}</b></a></td><td class="small">{{ $s->date->format('D d M') }} · {{ $s->start_time }}{{ $s->end_time ? '–'.$s->end_time : '' }}</td>
      <td>{{ $s->course->name }}</td><td class="small">{{ $s->venue }}</td><td class="num">{{ $s->records_count }}{{ $s->capacity ? ' / '.$s->capacity : '' }}</td>
      <td class="small">{{ $s->date->lt(bdate()) ? 'record results' : '' }}</td></tr>
  @empty<tr><td colspan="6" class="empty">Nothing scheduled.</td></tr>@endforelse</tbody>
</table></div></div>

<div class="card flush mt-1" id="allCard"><div class="card-head"><h3><x-icon name="book"/> All sessions</h3>
  <form method="get" class="flex gap-1"><select name="status" onchange="this.form.submit()" aria-label="Status"><option value="">Any status</option>@foreach(\App\Models\TrainingSession::STATUSES as $k => [$l])<option value="{{ $k }}" @selected(request('status') === $k)>{{ $l }}</option>@endforeach</select>
    <select name="course" onchange="this.form.submit()" aria-label="Course"><option value="">Any course</option>@foreach($courses as $c)<option value="{{ $c->id }}" @selected(request('course') == $c->id)>{{ $c->name }}</option>@endforeach</select></form></div>
<div class="card-body table-wrap"><table class="table table-compact" id="sessionsTable">
  <thead><tr><th>No.</th><th>Date</th><th>Course</th><th>Trainer</th><th class="num">Staff</th><th class="num">Passed</th><th class="num">Cost</th><th>Status</th></tr></thead>
  <tbody>@forelse($sessions as $s)
    <tr data-session="{{ $s->number }}"><td><a href="{{ route('learning.session', $s) }}"><b>{{ $s->number }}</b></a></td><td class="small">{{ $s->date->format('d M Y') }}</td><td>{{ $s->course->name }}</td><td class="small">{{ $s->trainerLabel() }}</td>
      <td class="num">{{ $s->records_count }}</td><td class="num">{{ $s->status === 'completed' ? $s->passed_count : '' }}</td><td class="num">{{ (float) $s->cost ? money($s->cost, false) : '' }}</td><td><span class="badge badge-{{ $s->statusColor() }}">{{ $s->statusLabel() }}</span></td></tr>
  @empty<tr><td colspan="8" class="empty">No sessions.</td></tr>@endforelse</tbody>
</table></div>{{ $sessions->links() }}</div>
</div>

<div>
@if($train)
<div class="card" id="schedule"><div class="card-head"><h3><x-icon name="plus"/> Schedule a session</h3></div>
<div class="card-body"><form method="post" action="{{ route('learning.sessions.store') }}" class="form-grid" id="scheduleForm">@csrf
  <div class="field span-all"><label for="sCourse">Course</label><select name="training_course_id" id="sCourse" required><option value="">Choose…</option>@foreach($courses as $c)<option value="{{ $c->id }}" @selected(old('training_course_id', $preset) == $c->id)>{{ $c->name }} ({{ rtrim(rtrim((string) $c->duration_hours, '0'), '.') }} h)</option>@endforeach</select></div>
  <div class="field"><label for="sDate">Date</label><input type="date" name="date" id="sDate" required value="{{ old('date', bdate()->copy()->addDays(7)->toDateString()) }}"></div>
  <div class="field"><label for="sCap">Capacity</label><input type="number" name="capacity" id="sCap" min="1" max="500" value="{{ old('capacity', 12) }}"></div>
  <div class="field"><label for="sStart">Start</label><input type="time" name="start_time" id="sStart" required value="{{ old('start_time', '09:00') }}"></div>
  <div class="field"><label for="sEnd">End</label><input type="time" name="end_time" id="sEnd" value="{{ old('end_time', '12:00') }}"></div>
  <div class="field span-all"><label for="sVenue">Venue</label><input name="venue" id="sVenue" maxlength="120" value="{{ old('venue', $cfg['venue']) }}"></div>
  <div class="field"><label for="sTrainer">Trainer (staff)</label><select name="trainer_id" id="sTrainer"><option value="">—</option>@foreach($users as $u)<option value="{{ $u->id }}" @selected(old('trainer_id') == $u->id)>{{ $u->name }}</option>@endforeach</select></div>
  <div class="field"><label for="sTrainerName">…or external trainer</label><input name="trainer_name" id="sTrainerName" maxlength="120" value="{{ old('trainer_name') }}" placeholder="blank = course provider"></div>
  <div class="field"><label for="sCost">Total cost (GH₵)</label><input type="number" name="cost" id="sCost" min="0" step="0.01" value="{{ old('cost') }}" placeholder="blank = per-head rate"></div>
  <div class="field"><label for="sNotes">Notes</label><input name="notes" id="sNotes" maxlength="255" value="{{ old('notes') }}"></div>
  <div class="span-all"><button class="btn btn-primary" id="sSubmit"><x-icon name="calendar"/> Schedule</button> <span class="tiny muted">You can enrol staff on the next page – the ones who need the course are suggested.</span></div>
</form></div></div>
@endif
</div>
</div>
@endsection
