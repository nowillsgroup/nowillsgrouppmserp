@extends('layouts.app')
@section('title', 'Time Clock')
@section('crumbs') HR &amp; Payroll / Time clock @endsection
@section('actions')
<a class="btn" href="{{ route('roster.index', ['dept' => $dept]) }}"><x-icon name="calendar"/> Duty roster</a>
@if(can_do('timeclock.kiosk'))<a class="btn" href="{{ route('clock.kiosk') }}"><x-icon name="key"/> Kiosk</a>@endif
@if(can_do('reports.view'))<a class="btn" href="{{ route('reports.show', ['timesheet']) }}"><x-icon name="chart"/> Timesheet</a>@endif
@if($canApprove)<button class="btn btn-primary" data-modal="punchModal" data-title="Add a time punch" data-action="{{ route('clock.punch') }}" data-f-in="{{ $date }}T08:00" id="addPunch"><x-icon name="plus"/> Add punch</button>@endif
@endsection
@section('content')
@php
  $cnt = $rows->countBy('status'); $isPast = $date < now()->toDateString();
  $labels = ['on_duty' => 'On duty', 'done' => 'Completed', 'upcoming' => 'Upcoming', 'no_show' => 'No show', 'missing_out' => 'No clock-out', 'leave' => 'On leave', 'off' => 'Day off', 'unrostered' => 'Not rostered'];
@endphp
<form method="get" class="filters">
  <div class="field"><label for="f_date">Date</label><input type="date" name="date" id="f_date" value="{{ $date }}" max="{{ now()->toDateString() }}" onchange="this.form.submit()"></div>
  <div class="field"><label for="f_dept">Department</label><select name="dept" id="f_dept" onchange="this.form.submit()"><option value="">All departments</option>@foreach($departments as $id => $n)<option value="{{ $id }}" @selected($dept == $id)>{{ $n }}</option>@endforeach</select></div>
</form>
<div class="stats" id="clockStats">
  <x-stat label="On duty now" :value="$cnt['on_duty'] ?? 0" icon="user" tone="green"/>
  <x-stat label="Late arrivals" :value="$rows->where('late', '>', 0)->count()" icon="clock" tone="amber" :sub="$rows->sum('late').' min in total'"/>
  <x-stat label="No show" :value="$cnt['no_show'] ?? 0" icon="alert" tone="red"/>
  <x-stat label="No clock-out" :value="$cnt['missing_out'] ?? 0" icon="logout" tone="red"/>
  <x-stat label="Hours worked" :value="number_format($rows->sum('worked'), 1)" icon="chart" :sub="'planned '.number_format($rows->sum('planned'), 1).' h'"/>
</div>

@if($stale->isNotEmpty())
<div class="alert alert-warn mt-1" id="staleAlert"><x-icon name="alert"/><div><b>Still clocked in from an earlier day:</b> @foreach($stale as $p){{ $p->employee?->name }} ({{ $p->clock_in->format('d M H:i') }})@if(! $loop->last), @endif @endforeach – enter the clock-out time.</div></div>
@endif

<div class="card flush mt-1">
  <div class="card-head"><h3><x-icon name="clock"/> {{ dmy($date) }} @if($date === now()->toDateString())<span class="badge badge-blue">today · {{ now()->format('H:i') }}</span>@endif</h3>
    @if($canApprove && $date < now()->toDateString())
    <form method="post" action="{{ route('clock.approve') }}" data-confirm="Approve time &amp; attendance for {{ dmy($date) }}? Attendance will be written from the punches.">@csrf<input type="hidden" name="date" value="{{ $date }}"><input type="hidden" name="dept" value="{{ $dept }}"><button class="btn btn-sm btn-primary" id="approveDay"><x-icon name="check"/> Approve day → attendance</button></form>
    @endif
  </div>
  <div class="card-body table-wrap"><table class="table" id="clockBoard">
    <thead><tr><th>Employee</th><th>Rostered</th><th>Punches</th><th class="num">Worked h</th><th>Status</th><th></th></tr></thead>
    <tbody>
    @forelse($rows as $r)
      <tr data-emp="{{ $r['e']->emp_code }}" data-status="{{ $r['status'] }}">
        <td><b>{{ $r['e']->name }}</b><div class="tiny muted">{{ $r['e']->emp_code }} · {{ $r['e']->department?->name }}</div></td>
        <td class="small">@if($r['shift']?->isShift())<b>{{ $r['shift']->code() }}</b> {{ $r['shift']->label() }}@elseif($r['shift'])OFF @else — @endif
          @if($r['leave'])<div class="tiny text-blue">{{ $r['leave']->half_day ? '½ day ' : '' }}{{ $r['leave']->leaveType?->name ?? 'leave' }}</div>@endif</td>
        <td class="small">@foreach($r['punches'] as $p)
          <div data-punch="{{ $p->id }}">{{ $p->clock_in->format('H:i') }} → {{ $p->clock_out?->format($p->clock_out->isSameDay($p->clock_in) ? 'H:i' : 'd M H:i') ?? '…' }}
            <span class="tiny muted">{{ $p->in_source }}{{ $p->status === 'approved' ? ' · ✔' : '' }}</span>
            @if($p->edit_reason)<span class="tiny text-amber" title="{{ $p->edit_reason }}">✎</span>@endif
            @if($canApprove)<a href="#" class="tiny" data-modal="punchModal" data-title="Correct punch – {{ $r['e']->name }}" data-action="{{ route('clock.punch.update', $p) }}" data-f-employee_id="{{ $r['e']->id }}" data-f-in="{{ $p->clock_in->format('Y-m-d\TH:i') }}" data-f-out="{{ $p->clock_out?->format('Y-m-d\TH:i') }}" data-edit-punch="{{ $r['e']->emp_code }}">edit</a>
              @if($p->status !== 'approved')<a href="#" class="tiny text-red" data-modal="delPunchModal" data-title="Delete punch – {{ $r['e']->name }} {{ $p->clock_in->format('H:i') }}" data-action="{{ route('clock.punch.delete', $p) }}">delete</a>@endif @endif
          </div>@endforeach
          @if($r['flags'])<div class="tiny text-amber">{{ implode(' · ', $r['flags']) }}</div>@endif</td>
        <td class="num">{{ $r['punches']->isNotEmpty() ? number_format($r['worked'], 2) : '—' }}@if($r['planned'])<div class="tiny muted">of {{ number_format($r['planned'], 1) }}</div>@endif</td>
        <td><b class="st-{{ $r['status'] }}">{{ $labels[$r['status']] ?? $r['status'] }}</b>@if($r['approved'])<div class="tiny muted">approved</div>@endif</td>
        <td class="nowrap">@if($canApprove && in_array($r['status'], ['no_show', 'missing_out', 'upcoming']))<button class="btn btn-sm" data-modal="punchModal" data-title="Add punch – {{ $r['e']->name }}" data-action="{{ route('clock.punch') }}" data-f-employee_id="{{ $r['e']->id }}" data-f-in="{{ $r['shift']?->startsAt()?->format('Y-m-d\TH:i') ?? $date.'T08:00' }}" data-f-out="{{ $r['shift']?->endsAt()?->format('Y-m-d\TH:i') }}">+ punch</button>@endif</td>
      </tr>
    @empty
      <tr><td colspan="6"><x-empty text="Nobody rostered or clocked in on this day." icon="clock"/></td></tr>
    @endforelse
    </tbody></table></div>
</div>

@if($canApprove)
<div class="grid g-2 mt-2">
  <div class="card"><div class="card-head"><h3><x-icon name="check"/> Days waiting for approval</h3></div><div class="card-body" id="pendingDays">
    @forelse($pendingDays as $d => $n)<a class="btn btn-sm mb-1" href="{{ route('clock.index', ['date' => \Carbon\Carbon::parse($d)->toDateString(), 'dept' => $dept]) }}">{{ dmy($d) }} · {{ $n }}</a> @empty<p class="small muted">All past days are approved.</p>@endforelse
    <p class="tiny muted mt-1">Approving a day writes attendance (Present / Half day / Absent / Week-off) from the roster and the punches; payroll reads attendance. Days owned by approved leave are left as they are.</p>
  </div></div>
  <div class="card"><div class="card-head"><h3><x-icon name="key"/> Kiosk PINs</h3></div><div class="card-body">
    <form method="post" action="#" id="pinForm" onsubmit="this.action=this.dataset.base.replace('/0', '/' + this.employee.value)" data-base="{{ route('clock.pin', 0) }}">@csrf
      <div class="form-grid">
        <div class="field"><label for="pin_emp">Employee</label><select name="employee" id="pin_emp" required>@foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->name }} ({{ $e->emp_code }}){{ $e->clock_pin ? ' · PIN set' : '' }}</option>@endforeach</select></div>
        <div class="field"><label for="pin_val">New PIN (4–6 digits)</label><input type="password" inputmode="numeric" pattern="[0-9]{4,6}" name="pin" id="pin_val" required autocomplete="new-password"></div>
        <div><button class="btn btn-primary" id="pinSave">Set PIN</button></div>
      </div></form>
    <p class="tiny muted">{{ $employees->whereNotNull('clock_pin')->count() }} of {{ $employees->count() }} staff have a PIN. Staff can also change their own PIN in self-service.</p>
  </div></div>
</div>

<div class="card mt-2"><div class="card-head"><h3><x-icon name="tool"/> Time clock settings</h3></div><div class="card-body">
  <form method="post" action="{{ route('clock.settings') }}" class="form-grid" id="clockSettings">@csrf
    <x-field name="timeclock_grace_min" label="Grace before 'late' (min)" type="number" min="0" max="60" :value="setting('timeclock_grace_min', '5')" required/>
    <x-field name="timeclock_early_min" label="Earliest clock-in before shift (min)" type="number" min="0" max="480" :value="setting('timeclock_early_min', '120')" required/>
    <x-field name="timeclock_break_min" label="Break deducted when not rostered (min)" type="number" min="0" max="120" :value="setting('timeclock_break_min', '60')" required/>
    <x-field name="timeclock_self_ips" label="Self-service clocking allowed from (IPs / CIDR, blank = anywhere)" :value="setting('timeclock_self_ips', '')" placeholder="e.g. 10.10.0.0/16, 41.66.200.14"/>
    <div class="field"><label><input type="checkbox" name="timeclock_self" value="1" @checked(setting('timeclock_self', '1') === '1')> Staff may clock in from self-service</label>
      <label><input type="checkbox" name="timeclock_unrostered" value="1" @checked(setting('timeclock_unrostered', '1') === '1')> Allow clocking in without a rostered shift</label></div>
    <div><button class="btn btn-primary">Save</button></div>
  </form>
</div></div>

<div class="modal" id="punchModal"><div class="modal-box"><form method="post" action="#" data-reset>@csrf
  <div class="modal-head"><h3 data-title>Time punch</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <div class="field"><label for="pm_emp">Employee</label><select name="employee_id" id="pm_emp">@foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->name }} ({{ $e->emp_code }})</option>@endforeach</select></div>
    <div class="form-grid">
      <div class="field"><label for="pm_in">Clock in</label><input type="datetime-local" name="in" id="pm_in" required></div>
      <div class="field"><label for="pm_out">Clock out (blank = still on duty)</label><input type="datetime-local" name="out" id="pm_out"></div>
    </div>
    <x-field name="reason" label="Reason for the correction" required maxlength="255" placeholder="e.g. forgot to clock out – confirmed with supervisor"/>
    <p class="tiny muted">Corrections are marked on the timesheet with your name and reason, and reset the day's approval.</p>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="punchSave">Save punch</button></div>
</form></div></div>
<div class="modal" id="delPunchModal"><div class="modal-box"><form method="post" action="#" data-reset>@csrf
  <div class="modal-head"><h3 data-title>Delete punch</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="reason" label="Reason" required maxlength="255"/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger">Delete</button></div>
</form></div></div>
@endif
@endsection
