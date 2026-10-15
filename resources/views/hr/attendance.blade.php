@extends('layouts.app')
@section('title', 'Staff Attendance')
@section('actions')<a class="btn" href="{{ route('hr.register', ['month' => substr($date, 0, 7)]) }}"><x-icon name="calendar"/> Monthly register</a>@if(can_do('hr.leave'))<a class="btn" href="{{ route('leave.index') }}"><x-icon name="book"/> Leave</a>@endif @endsection
@section('content')
@if($holiday)<div class="alert alert-info"><x-icon name="calendar"/><div><b>{{ $holiday['name'] }}</b> – a gazetted public holiday is a paid, non-working day (Act 651 s. 72) and does not use annual leave (s. 22). Mark the day <b>PH</b>; anyone who actually worked keeps their clock times and payroll pays the hours at {{ rtrim(rtrim(number_format((float) setting('payroll_holiday_multiplier', '2'), 2), '0'), '.') }}× (s. 77).</div></div>
@elseif($nextHoliday)<div class="alert alert-info"><x-icon name="calendar"/><div>Next public holiday: <b>{{ $nextHoliday['name'] }}</b> on {{ dmy($nextHoliday['date']) }} (in {{ $nextHoliday['days'] }} day(s)) – <a href="{{ route('hr.holidays') }}">see the calendar</a>.</div></div>@endif
@if($locked)<div class="alert alert-warn"><x-icon name="lock"/><div>Payroll <b>{{ $locked->run_no }}</b> is {{ $locked->status }} – attendance for {{ $locked->label() }} is locked{{ $locked->status === 'approved' ? ' until the run is reopened' : '' }}.</div></div>@endif
<div class="card"><div class="card-body"><form class="filters"><div class="field"><label>Date</label><input type="date" name="date" value="{{ $date }}" data-autosubmit></div><div class="field"><label>Department</label><select name="department" data-autosubmit><option value="">All</option>@foreach($departments as $id=>$n)<option value="{{ $id }}" @selected(request('department')==$id)>{{ $n }}</option>@endforeach</select></div>
<div class="legend small muted">P Present · A Absent · L Leave · H Half day · WO Week off · PH Public holiday (paid)</div></form></div></div>
<form method="post" action="{{ route('hr.attendance.save') }}" class="card flush">@csrf<input type="hidden" name="date" value="{{ $date }}">
<div class="card-body table-wrap"><table class="table">
<thead><tr><th>Code</th><th>Employee</th><th>Department</th><th>Status</th><th>In</th><th>Out</th><th>Remarks</th><th class="num" title="This month">P / A / L</th></tr></thead><tbody>
@forelse($employees as $e)@php $a = $e->attendances->first(); $s = $summary[$e->id] ?? collect(); $lv = $a?->leave_request_id; @endphp
<tr><td class="small muted">{{ $e->emp_code }}</td><td class="bold">{{ $e->name }}<div class="tiny muted">{{ $e->designation }}</div></td><td>{{ $e->department?->name }}</td>
<td>@if($lv)<input type="hidden" name="att[{{ $e->id }}][status]" value="{{ $a->status }}"><span class="badge {{ $a->status === 'H' ? 'badge-amber' : 'badge-purple' }}" title="Approved leave – cancel it from Leave to change">{{ $a->status === 'H' ? 'Half day' : 'On leave' }} · {{ $a->leaveRequest?->leaveType?->code }}</span>@else<select class="input input-sm" name="att[{{ $e->id }}][status]">@foreach(['P'=>'Present','A'=>'Absent','L'=>'Leave','H'=>'Half day','WO'=>'Week off','PH'=>'Public holiday'] as $k=>$v)<option value="{{ $k }}" @selected(($a->status ?? ($holiday ? 'PH' : 'P'))===$k)>{{ $v }}</option>@endforeach</select>@endif</td>
<td><input class="input input-sm" type="time" name="att[{{ $e->id }}][in_time]" value="{{ $a?->in_time ? substr($a->in_time,0,5) : '' }}"></td>
<td><input class="input input-sm" type="time" name="att[{{ $e->id }}][out_time]" value="{{ $a?->out_time ? substr($a->out_time,0,5) : '' }}"></td>
<td><input class="input input-sm" name="att[{{ $e->id }}][remarks]" value="{{ $a?->remarks }}" maxlength="120"></td>
<td class="num small">{{ $s['P'] ?? 0 }} / {{ $s['A'] ?? 0 }} / {{ $s['L'] ?? 0 }}</td></tr>
@empty<tr><td colspan="8"><x-empty text="No employees. Add employees in HR → Employees." icon="users"/></td></tr>@endforelse</tbody></table></div>
@if($employees->count() && ! $locked)<div class="card-foot"><button class="btn btn-primary"><x-icon name="check"/> Save Attendance</button></div>@endif
</form>
@endsection
