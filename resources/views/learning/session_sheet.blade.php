@extends('layouts.print')
@section('title', 'Attendance '.$s->number)
@section('doctitle', 'TRAINING ATTENDANCE')
@section('docmeta')<div class="small"><b>{{ $s->number }}</b> · {{ $s->statusLabel() }}</div><div class="small">{{ $s->date->format('D d M Y') }} · {{ $s->start_time }}{{ $s->end_time ? '–'.$s->end_time : '' }}</div>@endsection
@section('content')
<div class="flex gap-1 wrap mt-1 small" id="sheetHead">
  <span><b>Course:</b> {{ $s->course->code }} – {{ $s->course->name }}</span><span><b>Duration:</b> {{ rtrim(rtrim((string) $s->course->duration_hours, '0'), '.') }} h</span>
  <span><b>Venue:</b> {{ $s->venue }}</span><span><b>Trainer:</b> {{ $s->trainerLabel() }}</span>@if($s->course->pass_mark)<span><b>Pass mark:</b> {{ $s->course->pass_mark }}%</span>@endif
</div>
<table class="table table-compact mt-1" id="sheetTable">
  <thead><tr><th>#</th><th>Employee</th><th>Job title</th><th>Department</th><th style="width:5rem">Score</th><th style="width:11rem">Signature</th></tr></thead>
  <tbody>@foreach($s->records->sortBy('employee.name')->values() as $i => $r)
    <tr style="height:2.1rem"><td>{{ $i + 1 }}</td><td>{{ $r->employee->name }}</td><td class="small">{{ $r->employee->designation }}</td><td class="small">{{ $r->employee->department?->name }}</td><td>{{ $r->score !== null ? rtrim(rtrim((string) $r->score, '0'), '.').'%' : '' }}</td><td></td></tr>
  @endforeach
  @for($i = 0; $i < 3; $i++)<tr style="height:2.1rem"><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td></tr>@endfor</tbody>
</table>
@if($s->course->description)<div class="small mt-1"><b>Content covered:</b> <span style="white-space:pre-line">{{ $s->course->description }}</span></div>@endif
<div class="flex gap-1 mt-1 small" style="justify-content:space-between;margin-top:2.5rem"><span>Trainer: ______________________</span><span>Department head: ______________________</span><span>HR: ______________________</span></div>
@endsection
