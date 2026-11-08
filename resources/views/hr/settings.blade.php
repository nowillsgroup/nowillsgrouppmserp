@extends('layouts.app')
@section('title', 'HR Settings')
@section('crumbs') People / Human resources / <b>Settings</b> @endsection
@section('actions')
  <a class="btn" href="{{ route('hr.attendance') }}"><x-icon name="chev"/> Attendance</a>
  <a class="btn btn-soft" href="{{ route('leave.index') }}"><x-icon name="calendar"/> Leave</a>
  <a class="btn btn-soft" href="{{ route('hr.holidays') }}"><x-icon name="calendar"/> Public holidays</a>
@endsection
@section('content')

<form method="post" action="{{ route('hr.settings.save') }}">@csrf
<div class="grid g-2-1">
  <div>
    <div class="card">
      <div class="card-head"><h3>Answering a leave request</h3></div>
      <div class="card-body form-grid">
        <x-field name="hr_leave_sla_hours" label="A leave request must be answered within (hours)" type="number" min="1" max="168" step="1"
                 :value="$c['hr_leave_sla_hours']" required
                 hint="The nightly HR run escalates every request still pending past this window to the line manager and the HR manager. A request that sits unanswered is a person who cannot plan."/>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h3>How leave is counted</h3></div>
      <div class="card-body form-grid">
        <label class="check span-all">
          <input type="hidden" name="leave_count_all_days" value="0">
          <input type="checkbox" name="leave_count_all_days" value="1" @checked($c['leave_count_all_days'] === '1')>
          Count every calendar day, including public holidays and rostered days off
        </label>
        <p class="small muted span-all">
          Left unticked — the Ghana default under Act 651 — annual leave is measured in <b>working</b> days: a public holiday,
          certified sickness or the worker's own rostered day off inside the leave period is not taken off the balance.
          Tick this only where the property's own policy or a collective agreement counts calendar days instead.
        </p>
      </div>
    </div>
    <button class="btn btn-primary"><x-icon name="check"/> Save settings</button>
  </div>

  <div>
    <div class="card">
      <div class="card-head"><h3>What this changes</h3></div>
      <div class="card-body small">
        <p>The answer window drives the escalation in the nightly HR run — it does not change how leave is approved, only how quickly an unanswered request is put in front of somebody.</p>
        <p class="muted">The counting rule changes the <b>days charged</b> against a balance for every new request. It does not re-price leave already granted: a past request keeps the days it was charged at the time.</p>
        <p class="muted">Payroll › Components carries the same counting rule, because the payroll run reads it when it values a leave day. Both screens write the same setting — changing it here changes it there.</p>
      </div>
    </div>
    <div class="card">
      <div class="card-head"><h3>The people desk</h3></div>
      <div class="card-body small">
        <p><a href="{{ route('hr.attendance') }}">Attendance</a> — who is in today, by department.</p>
        <p><a href="{{ route('hr.register') }}">Employee register</a> — contracts, probation and pay setup.</p>
        <p><a href="{{ route('leave.index') }}">Leave</a> — the queue of requests waiting on a decision.</p>
      </div>
    </div>
  </div>
</div>
</form>
@endsection
