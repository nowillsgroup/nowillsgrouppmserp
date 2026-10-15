@extends('layouts.app')
@section('title', 'Leave Requests')
@section('crumbs') HR &amp; Payroll / Leave @endsection
@section('actions')@if(can_do('hr.manage'))<a class="btn" href="{{ route('masters.index', 'leave-types') }}"><x-icon name="layers"/> Leave types</a>@endif<button class="btn btn-primary" data-modal="leaveModal"><x-icon name="plus"/> Record leave</button>@endsection
@section('content')
@if($onLeave->count())
<div class="alert alert-info"><x-icon name="calendar"/><div><b>On leave today:</b> @foreach($onLeave as $r){{ $r->employee->name }} ({{ $r->leaveType->code }}{{ $r->half_day ? ' ½' : '' }}, back {{ $r->date_to->copy()->addDay()->format('d M') }})@if(! $loop->last), @endif @endforeach</div></div>
@endif
@php $tab = request('tab') === 'balances' ? 'balances' : $status; @endphp
<div class="tabs">
@foreach(['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled', 'all' => 'All'] as $k => $v)<a href="?status={{ $k }}" class="{{ $tab === $k ? 'active' : '' }}">{{ $v }}@if($k !== 'all' && ($counts[$k] ?? 0)) <span class="badge {{ $k === 'pending' ? 'badge-amber' : 'badge-slate' }}">{{ $counts[$k] }}</span>@endif</a>@endforeach
<a href="?tab=balances" class="{{ $tab === 'balances' ? 'active' : '' }}">Balances {{ $year }}</a>
</div>

@if($tab === 'balances')
<div class="card flush"><div class="card-body table-wrap"><table class="table table-compact" id="balTable">
<thead><tr><th>Employee</th>@foreach($types as $t)<th class="num" title="{{ $t->name }}">{{ $t->code }}{{ $t->is_paid ? '' : ' (unpaid)' }}</th>@endforeach</tr></thead><tbody>
@foreach($employees as $e)<tr><td class="bold">{{ $e->name }}<div class="tiny muted">{{ $e->emp_code }} · {{ $e->department?->name }}</div></td>
@foreach($types as $t)@php $b = $balances[$e->id][$t->id] ?? null; @endphp
<td class="num">@if($t->is_paid)<b class="{{ $b['available'] <= 0 ? 'text-red' : '' }}">{{ \App\Services\LeaveService::fmt($b['available']) }}</b><div class="tiny muted">of {{ \App\Services\LeaveService::fmt($b['entitled'] + $b['carried']) }}{{ $b['carried'] ? ' (incl. '.\App\Services\LeaveService::fmt($b['carried']).' c/f)' : '' }}@if($b['pending']) · {{ \App\Services\LeaveService::fmt($b['pending']) }} pending @endif</div>@else{{ \App\Services\LeaveService::fmt($b['taken']) }} taken @endif</td>
@endforeach</tr>@endforeach
</tbody></table></div></div>
<p class="small muted mt-1">Available = entitlement (pro-rata in the joining year) + carried forward − approved − pending. Unpaid leave has no limit and becomes loss of pay in payroll.</p>
<p class="small muted mt-1"><b>Which of these the law gives:</b> EL is statutory — Act 651 s. 20 floors it at 15 working days on full pay, and s. 22 keeps a gazetted public holiday, certified sickness or confinement from eating into it. ML is statutory — s. 57, 12 weeks on full remuneration plus two more for an abnormal confinement or multiple births. CL, SL and <b>PL are contractual</b>: Ghana gives a father no statutory paternity leave, so the days shown for PL are this hotel's own policy or collective agreement — set them under Masters › Leave Types. Every paid type is paid by payroll the same way, and a public holiday inside any of them is not counted against the balance.</p>
@else
<form class="filters mb-1 flex gap-1 items-center" method="get"><input type="hidden" name="status" value="{{ $status }}"><select name="employee_id" class="input input-sm" style="width:auto;min-width:220px" data-autosubmit><option value="">All employees</option>@foreach($employees as $e)<option value="{{ $e->id }}" @selected(request('employee_id') == $e->id)>{{ $e->name }}</option>@endforeach</select></form>
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="leaveTable">
<thead><tr><th>Employee</th><th>Type</th><th>Dates</th><th class="num">Days</th><th>Reason</th><th>Status</th><th></th></tr></thead><tbody>
@forelse($list as $r)
<tr data-id="{{ $r->id }}"><td class="bold">{{ $r->employee->name }}<div class="tiny muted">{{ $r->employee->designation }} · {{ $r->employee->department?->name }}</div></td>
<td><span class="badge {{ $r->leaveType->is_paid ? 'badge-blue' : 'badge-amber' }}">{{ $r->leaveType->code }}</span> <span class="small">{{ $r->leaveType->name }}</span></td>
<td>{{ $r->periodLabel() }}</td><td class="num">{{ \App\Services\LeaveService::fmt($r->days) }}</td>
<td class="small">{{ $r->reason }}<div class="tiny muted">requested {{ $r->created_at->format('d M') }}{{ $r->requester ? ' by '.$r->requester->name : '' }}</div></td>
<td>{!! status_badge($r->status) !!}@if($r->decider)<div class="tiny muted">{{ $r->decider->name }} · {{ $r->decided_at?->format('d M') }}</div>@endif @if($r->decision_note)<div class="tiny">{{ $r->decision_note }}</div>@endif</td>
<td class="right nowrap">
@if($r->status === 'pending')
<form method="post" action="{{ route('leave.approve', $r) }}" class="inline">@csrf<button class="btn btn-sm btn-success" title="Approve"><x-icon name="check"/></button></form>
<button class="btn btn-sm btn-danger" data-modal="rejectModal" data-action="{{ route('leave.reject', $r) }}" data-who="{{ $r->employee->name }} – {{ $r->leaveType->code }} {{ $r->periodLabel() }}" title="Reject"><x-icon name="x"/></button>
@endif
@if(in_array($r->status, ['pending', 'approved']))
<form method="post" action="{{ route('leave.cancel', $r) }}" class="inline" onsubmit="return confirm('Cancel this leave?{{ $r->status === 'approved' ? ' The attendance marked for it is removed.' : '' }}')">@csrf<button class="btn btn-sm btn-ghost" title="Cancel">Cancel</button></form>
@endif
</td></tr>
@empty<tr><td colspan="7"><x-empty text="No {{ $status === 'all' ? '' : $status }} leave requests." icon="calendar"/></td></tr>@endforelse
</tbody></table></div></div>
{{ $list->links() }}
@endif

<div class="modal" id="rejectModal"><div class="modal-box"><form method="post" id="rejectForm" action="#">@csrf
<div class="modal-head"><h3>Reject leave</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
<div class="modal-body"><p class="small bold" id="rejectWho"></p><div class="field"><label>Reason (shown to the employee) <span class="req">*</span></label><input name="note" class="input" required maxlength="255"></div></div>
<div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger">Reject</button></div></form></div></div>

<div class="modal" id="leaveModal"><div class="modal-box"><form method="post" action="{{ route('leave.store') }}">@csrf
<div class="modal-head"><h3>Record leave</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
<div class="modal-body form-grid">
  <div class="field span-2"><label>Employee <span class="req">*</span></label><select name="employee_id" class="input" required><option value="">— choose —</option>@foreach($employees as $e)<option value="{{ $e->id }}" @selected(old('employee_id') == $e->id)>{{ $e->name }} ({{ $e->emp_code }})</option>@endforeach</select></div>
  <div class="field span-2"><label>Leave type <span class="req">*</span></label><select name="leave_type_id" class="input" required>@foreach($types as $t)<option value="{{ $t->id }}" @selected(old('leave_type_id') == $t->id)>{{ $t->name }} ({{ $t->code }}){{ $t->is_paid ? '' : ' – unpaid' }}</option>@endforeach</select></div>
  <div class="field span-2"><p class="tiny muted" style="margin:-.4rem 0 0">EL and ML are statutory (Act 651 ss. 20 and 57). CL, SL and PL are contractual — Ghana gives a father no statutory paternity leave, so PL is whatever this hotel's policy or collective agreement provides; set its days under Masters › Leave Types.</p></div>
  <div class="field"><label>From <span class="req">*</span></label><input type="date" name="date_from" class="input" required value="{{ old('date_from', bdate()->toDateString()) }}"></div>
  <div class="field"><label>To <span class="req">*</span></label><input type="date" name="date_to" class="input" required value="{{ old('date_to', bdate()->toDateString()) }}"></div>
  <label class="check span-2"><input type="checkbox" name="half_day" value="1"> Half day (single date)</label>
  <div class="field span-2"><label>Reason</label><input name="reason" class="input" maxlength="255" value="{{ old('reason') }}"></div>
  <label class="check span-2"><input type="checkbox" name="approve" value="1" checked> Approve now (updates the attendance register)</label>
</div>
<div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save</button></div></form></div></div>
<script>
document.querySelectorAll('[data-modal="rejectModal"]').forEach(b => b.addEventListener('click', () => {
  document.getElementById('rejectForm').action = b.dataset.action; document.getElementById('rejectWho').textContent = b.dataset.who;
}));
</script>
@endsection
