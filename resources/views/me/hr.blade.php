@extends('layouts.app')
@section('title', 'My Payslips & Leave')
@section('crumbs') {{ $e->name }} · {{ $e->emp_code }} @endsection
@section('actions')<button class="btn btn-primary" data-modal="applyModal"><x-icon name="plus"/> Apply for leave</button>@endsection
@section('content')
@php $f = fn ($n) => \App\Services\LeaveService::fmt((float) $n); @endphp
<div class="card"><div class="card-head"><h3>Leave balance {{ bdate()->year }}</h3></div><div class="card-body leave-bal" id="myBalances">
@foreach($balances as $b)
<div><div class="code">{{ $b['type']->code }} · {{ $b['type']->name }}</div>
@if($b['type']->is_paid)<div class="v {{ $b['available'] <= 0 ? 'text-red' : '' }}">{{ $f($b['available']) }}</div><div class="meta">available of {{ $f($b['entitled'] + $b['carried']) }}{{ $b['carried'] ? ' (incl. '.$f($b['carried']).' carried)' : '' }} · {{ $f($b['taken']) }} taken{{ $b['pending'] ? ' · '.$f($b['pending']).' pending' : '' }}</div>
@else<div class="v">{{ $f($b['taken']) }}</div><div class="meta">days taken (unpaid – deducted from salary)</div>@endif</div>
@endforeach
</div></div>

<div class="grid g-2 mt-2">
<div class="card flush"><div class="card-head"><h3>My leave requests</h3></div><div class="card-body table-wrap"><table class="table table-compact" id="myLeave">
<thead><tr><th>Type</th><th>Dates</th><th class="num">Days</th><th>Status</th><th></th></tr></thead><tbody>
@forelse($requests as $r)<tr><td><span class="badge badge-slate">{{ $r->leaveType->code }}</span></td><td>{{ $r->periodLabel() }}<div class="tiny muted">{{ $r->reason }}</div></td><td class="num">{{ $f($r->days) }}</td>
<td>{!! status_badge($r->status) !!}@if($r->decision_note)<div class="tiny">{{ $r->decision_note }}</div>@endif</td>
<td class="right">@if($r->status === 'pending')<form method="post" action="{{ route('me.leave.cancel', $r) }}" class="inline" onsubmit="return confirm('Withdraw this request?')">@csrf<button class="btn btn-sm btn-ghost">Withdraw</button></form>@endif</td></tr>
@empty<tr><td colspan="5" class="muted small">No leave requests yet.</td></tr>@endforelse
</tbody></table></div></div>

<div class="card flush"><div class="card-head"><h3>My payslips</h3></div><div class="card-body table-wrap"><table class="table table-compact" id="mySlips">
<thead><tr><th>Month</th><th class="num">Paid days</th><th class="num">Gross</th><th class="num">Net pay</th><th>Status</th><th></th></tr></thead><tbody>
@forelse($slips as $s)<tr><td class="bold">{{ $s->run->label() }}</td><td class="num">{{ $f($s->paid_days) }}</td><td class="num">{{ money($s->gross, false) }}</td><td class="num bold">{{ money($s->net, false) }}</td><td>{!! status_badge($s->run->status) !!}</td>
<td class="right"><a class="btn btn-sm" target="_blank" href="{{ route('me.payslip', $s) }}"><x-icon name="print"/> View</a></td></tr>
@empty<tr><td colspan="6" class="muted small">Payslips appear here once payroll is approved.</td></tr>@endforelse
</tbody></table></div></div>
</div>

<div class="grid g-2 mt-2">
<div class="card"><div class="card-head"><h3>Attendance · {{ $month->format('F') }} to date</h3></div><div class="card-body">
  <div class="ap-summary"><div><div class="l">Present</div><div class="v">{{ $f($att['present']) }}</div></div><div><div class="l">Week off</div><div class="v">{{ $f($att['week_off']) }}</div></div><div><div class="l">Paid leave</div><div class="v">{{ $f($att['paid_leave']) }}</div></div><div><div class="l">Public holidays</div><div class="v">{{ $f($att['holiday'] ?? 0) }}</div></div><div><div class="l">Loss of pay</div><div class="v {{ $att['lop'] > 0 ? 'red' : '' }}">{{ $f($att['lop']) }}</div></div><div><div class="l">Overtime</div><div class="v">{{ $f($att['ot_hours']) }} h</div></div></div>
  @if(($att['holiday_hours'] ?? 0) > 0)<p class="tiny muted mt-1">You worked {{ $f($att['holiday_hours']) }} h on a public holiday – paid at {{ setting('payroll_holiday_multiplier', '2') }}× your hourly rate on the OTH line, on top of the day you are paid for not working (Act 651 ss. 72, 77).</p>@endif
  <div class="flex gap-1 wrap mt-2">@foreach($recent as $a)<span class="badge {{ ['P' => 'badge-green', 'A' => 'badge-red', 'L' => 'badge-purple', 'H' => 'badge-amber', 'WO' => 'badge-slate', 'PH' => 'badge-amber'][$a->status] ?? 'badge-slate' }}" title="{{ $a->remarks }}">{{ $a->date->format('D d') }} · {{ $a->status }}</span>@endforeach</div>
</div></div>
<div class="card"><div class="card-head"><h3>Salary advances</h3></div><div class="card-body">
@forelse($advances as $a)<div class="flex justify-between small" style="padding:.3rem 0;border-bottom:1px solid var(--c-border)"><span>{{ $a->advance_no }} · {{ dmy($a->date) }} · {{ money($a->amount) }}</span><span><b>{{ money($a->outstanding()) }}</b> outstanding · {{ money($a->installment) }}/month</span></div>
@empty<p class="muted small">No outstanding advances.</p>@endforelse
</div></div>
</div>

<div class="modal" id="applyModal"><div class="modal-box"><form method="post" action="{{ route('me.leave') }}">@csrf
<div class="modal-head"><h3>Apply for leave</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
<div class="modal-body form-grid">
  <div class="field span-2"><label>Leave type <span class="req">*</span></label><select name="leave_type_id" class="input" required>@foreach($types as $t)<option value="{{ $t->id }}" @selected(old('leave_type_id') == $t->id)>{{ $t->name }}{{ $t->is_paid ? '' : ' (unpaid)' }}</option>@endforeach</select></div>
  <div class="field"><label>From <span class="req">*</span></label><input type="date" name="date_from" class="input" required value="{{ old('date_from', bdate()->copy()->addDay()->toDateString()) }}"></div>
  <div class="field"><label>To <span class="req">*</span></label><input type="date" name="date_to" class="input" required value="{{ old('date_to', bdate()->copy()->addDay()->toDateString()) }}"></div>
  <label class="check span-2"><input type="checkbox" name="half_day" value="1"> Half day</label>
  <div class="field span-2"><label>Reason <span class="req">*</span></label><input name="reason" class="input" required maxlength="255" value="{{ old('reason') }}"></div>
</div>
<div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Send request</button></div></form></div></div>
@endsection
