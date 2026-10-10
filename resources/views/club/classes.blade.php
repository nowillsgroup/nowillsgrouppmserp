@extends('layouts.app')
@section('title', 'Class Timetable')
@section('crumbs') <a href="{{ route('club.index') }}">Health Club</a> / Class timetable @endsection
@section('actions')
<a class="btn" href="{{ route('club.classes', ['week' => $week['monday']->copy()->subWeek()->toDateString()]) }}">← Previous week</a>
<a class="btn" href="{{ route('club.classes') }}">This week</a>
<a class="btn" href="{{ route('club.classes', ['week' => $week['monday']->copy()->addWeek()->toDateString()]) }}" id="nextWeek">Next week →</a>
@if(can_do('club.manage'))<button class="btn btn-primary" data-modal="classModal" data-title="New class" data-action="{{ route('club.classes.store') }}" data-f-residents="1" data-f-is_active="1" data-f-duration_min="60" data-f-capacity="12" id="newClass"><x-icon name="plus"/> New class</button>@endif
@endsection
@section('content')
<h3 class="m-0" style="margin-bottom:.6rem">Week of {{ $week['monday']->format('d M Y') }}</h3>
<div class="club-tt" id="timetable">
  @foreach($week['days'] as $wd => $day)
    <div class="day {{ $day['date']->isSameDay(bdate()) ? 'today' : '' }}"><h4>{{ $day['date']->format('D d/m') }}</h4>
      @forelse($day['sessions'] as $x)@php $c = $x['class']; $past = \App\Services\ClubClassService::sessionStart($c, $day['date']->toDateString())->lt(now()); @endphp
        <a class="sess {{ $x['booked'] >= $c->capacity ? 'full' : '' }} {{ $past ? 'past' : '' }}" href="{{ route('club.classes.session', [$c, $day['date']->toDateString()]) }}" data-session="{{ $c->id }}-{{ $day['date']->toDateString() }}">
          <b>{{ $c->timeLabel() }} {{ $c->name }}</b><div class="tiny">{{ $c->instructorName() }} · {{ $c->duration_min }} min</div>
          <div class="tiny">{{ $x['booked'] }}/{{ $c->capacity }} booked{{ $x['waitlist'] ? ' · '.$x['waitlist'].' waiting' : '' }}{{ $past && $x['attended'] ? ' · '.$x['attended'].' attended' : '' }}</div></a>
      @empty <div class="tiny muted">No classes</div> @endforelse
    </div>
  @endforeach
</div>

<div class="card flush mt-1"><div class="card-head"><h3>Classes</h3></div><div class="card-body table-wrap"><table class="table" id="classTable">
  <thead><tr><th>Class</th><th>When</th><th>Where</th><th>Instructor</th><th class="num">Capacity</th><th>Hotel guests</th><th></th></tr></thead>
  <tbody>@foreach($classes as $c)
    <tr class="{{ $c->is_active ? '' : 'muted' }}"><td class="bold">{{ $c->name }}{{ $c->is_active ? '' : ' (off)' }}</td><td class="small">{{ \App\Models\ClubClass::DAYS[$c->weekday] }} {{ $c->timeLabel() }} · {{ $c->duration_min }} min</td><td class="small">{{ \App\Models\ClubPlan::FACILITIES[$c->facility] ?? $c->facility }}</td>
      <td class="small">{{ $c->instructorName() }}</td><td class="num">{{ $c->capacity }}</td><td class="small">{{ $c->residents ? 'Yes' : 'Members only' }}</td>
      <td class="num">@if(can_do('club.manage'))<button class="btn btn-sm" data-modal="classModal" data-title="Edit {{ $c->name }}" data-action="{{ route('club.classes.update', $c) }}" data-f-name="{{ $c->name }}" data-f-facility="{{ $c->facility }}" data-f-employee_id="{{ $c->employee_id }}" data-f-instructor="{{ $c->instructor }}" data-f-weekday="{{ $c->weekday }}" data-f-start_time="{{ $c->timeLabel() }}" data-f-duration_min="{{ $c->duration_min }}" data-f-capacity="{{ $c->capacity }}" data-f-residents="{{ $c->residents ? 1 : 0 }}" data-f-is_active="{{ $c->is_active ? 1 : 0 }}">Edit</button>@endif</td></tr>
  @endforeach</tbody></table></div></div>

@if(can_do('club.manage'))
<div class="modal" id="classModal"><div class="modal-box"><form method="post" action="" data-reset>@csrf
  <div class="modal-head"><h3 data-title>Class</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="name" label="Class" required class="span-2"/>
    <x-field name="weekday" label="Day" type="select" :options="\App\Models\ClubClass::DAYS" :placeholder="false"/>
    <x-field name="start_time" label="Start" type="time" required/>
    <x-field name="duration_min" label="Minutes" type="number" min="15" max="240" required/>
    <x-field name="capacity" label="Capacity" type="number" min="1" max="200" required/>
    <x-field name="facility" label="Where" type="select" :options="\App\Models\ClubPlan::FACILITIES" :placeholder="false"/>
    <x-field name="employee_id" label="Staff instructor" type="select" :options="$employees->mapWithKeys(fn ($e) => [$e->id => $e->name.' ('.$e->designation.')'])->all()" placeholder="— external —"/>
    <x-field name="instructor" label="External instructor" class="span-2"/>
    <label class="check"><input type="checkbox" name="residents" value="1"> <span>Hotel guests may book</span></label>
    <label class="check"><input type="checkbox" name="is_active" value="1"> <span>On the timetable</span></label>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="classSave">Save</button></div>
</form></div></div>
@endif
@endsection
