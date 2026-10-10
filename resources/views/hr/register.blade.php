@extends('layouts.app')
@section('title', 'Attendance Register · '.$month->format('F Y'))
@section('crumbs') HR &amp; Payroll / <a href="{{ route('hr.attendance') }}">Attendance</a> / Register @endsection
@section('actions')<a class="btn" href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}"><x-icon name="download"/> CSV</a>@endsection
@section('content')
<div class="card"><div class="card-body"><form class="filters">
  <div class="field"><label>Month</label><input type="month" name="month" value="{{ $month->format('Y-m') }}" data-autosubmit></div>
  <div class="field"><label>Department</label><select name="department" data-autosubmit><option value="">All</option>@foreach($departments as $id => $n)<option value="{{ $id }}" @selected(request('department') == $id)>{{ $n }}</option>@endforeach</select></div>
  <div class="legend small muted">P present · A absent · L leave · H half day · WO week off · <span style="box-shadow:inset 0 -3px 0 #f59e0b">overtime</span> · · unmarked</div>
</form></div></div>
@if($locked)<div class="alert alert-warn"><x-icon name="lock"/><div>Payroll <a href="{{ route('payroll.show', $locked) }}">{{ $locked->run_no }}</a> is {{ $locked->status }} – this month is locked.</div></div>@endif
<div class="card flush"><div class="card-body table-wrap"><table class="table att-grid" id="attGrid">
<thead><tr><th>Code</th><th>Employee</th>
@for($d = 1; $d <= $month->daysInMonth; $d++)@php $dt = $month->copy()->day($d); @endphp<th class="{{ $dt->isWeekend() ? 'we' : '' }}" title="{{ $dt->format('D d M') }}">{{ $d }}<div class="tiny muted">{{ substr($dt->format('D'), 0, 2) }}</div></th>@endfor
<th title="Paid days to date">Paid</th><th title="Loss of pay days">LOP</th><th title="Overtime hours">OT</th></tr></thead><tbody>
@foreach($employees as $e)@php $row = $att[$e->id] ?? collect(); $s = $summary[$e->id]; $shift = (float) setting('payroll_shift_hours', 9); @endphp
<tr><td class="small muted">{{ $e->emp_code }}</td><td class="bold small">{{ $e->name }}</td>
@for($d = 1; $d <= $month->daysInMonth; $d++)@php
  $a = $row[$d] ?? null; $dt = $month->copy()->day($d);
  $pre = $e->joining_date && $dt->lt($e->joining_date);
  $ot = $a && $a->in_time && $a->out_time && (\Illuminate\Support\Carbon::parse($a->in_time)->diffInMinutes(\Illuminate\Support\Carbon::parse($a->out_time)) / 60 - $shift) >= .5;
@endphp
<td class="{{ $pre ? 's-U' : ($a ? 's-'.$a->status.($ot ? ' s-OT' : '') : 's-U') }}" title="{{ $dt->format('d M') }}{{ $a?->remarks ? ' · '.$a->remarks : '' }}{{ $a && $a->in_time ? ' · '.substr($a->in_time, 0, 5).'–'.substr((string) $a->out_time, 0, 5) : '' }}">{{ $pre ? '' : ($a->status ?? ($dt->lte($until) ? '·' : '')) }}</td>
@endfor
<td class="sum">{{ $s ? rtrim(rtrim(number_format($s['paid'], 1), '0'), '.') : '' }}</td><td class="sum {{ ($s['lop'] ?? 0) > 0 ? 'text-red' : 'muted' }}">{{ $s ? rtrim(rtrim(number_format($s['lop'], 1), '0'), '.') : '' }}</td><td class="sum">{{ $s && $s['ot_hours'] ? rtrim(rtrim(number_format($s['ot_hours'], 2), '0'), '.') : '' }}</td></tr>
@endforeach
</tbody></table></div></div>
<p class="small muted mt-1">Paid days / LOP / OT are computed exactly as payroll will ({{ $until->lt($month->copy()->endOfMonth()) ? 'to '.dmy($until) : 'full month' }}). Mark days on the <a href="{{ route('hr.attendance') }}">daily attendance</a> screen; leave comes from <a href="{{ route('leave.index') }}">approved requests</a>.</p>
@endsection
