@extends('layouts.app')
@section('title', 'Salary Advances')
@section('crumbs') <a href="{{ route('payroll.index') }}">Payroll</a> / Advances @endsection
@section('actions')@if(can_do('payroll.manage'))<button class="btn btn-primary" data-modal="advModal"><x-icon name="plus"/> Give advance</button>@endif @endsection
@section('content')
<form class="filters mb-1 flex gap-1 items-center" method="get"><select name="employee_id" class="input input-sm" style="width:auto;min-width:240px" data-autosubmit><option value="">All employees</option>@foreach($employees as $id => $n)<option value="{{ $id }}" @selected(request('employee_id') == $id)>{{ $n }}</option>@endforeach</select></form>
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="advTable">
<thead><tr><th>No.</th><th>Date</th><th>Employee</th><th>Reason</th><th>Paid via</th><th class="num">Amount</th><th class="num">Instalment</th><th class="num">Recovered</th><th class="num">Outstanding</th><th></th></tr></thead><tbody>
@forelse($advances as $a)@php $rec = $a->cancelled_at ? 0 : $a->recovered(); $out = $a->outstanding(); @endphp
<tr><td class="bold">{{ $a->advance_no }}</td><td>{{ dmy($a->date) }}</td><td>@if(can_do('payroll.manage'))<a href="{{ route('payroll.employee', $a->employee_id) }}">{{ $a->employee->name }}</a>@else{{ $a->employee->name }}@endif<div class="tiny muted">{{ $a->employee->emp_code }}</div></td>
<td class="small">{{ $a->reason }}<div class="tiny muted">by {{ $a->creator?->name ?? '—' }}</div></td><td class="small">{{ $a->paymentMode?->name }}</td>
<td class="num">{{ money($a->amount, false) }}</td><td class="num">{{ money($a->installment, false) }}</td><td class="num text-green">{{ money($rec, false) }}</td>
<td class="num bold">@if($a->cancelled_at)<span class="badge badge-red">cancelled</span>@elseif($out <= 0)<span class="badge badge-green">settled</span>@else{{ money($out, false) }}@endif</td>
<td class="right">@if(! $a->cancelled_at && $rec == 0 && can_do('payroll.manage') && ! $a->recoveries()->exists())<form method="post" action="{{ route('payroll.advances.cancel', $a) }}" class="inline" onsubmit="return confirm('Cancel advance {{ $a->advance_no }}? Its ledger posting is reversed.')">@csrf<button class="btn btn-sm btn-ghost" title="Cancel"><x-icon name="x"/></button></form>@endif</td></tr>
@empty<tr><td colspan="10"><x-empty text="No salary advances." icon="money"/></td></tr>@endforelse
</tbody></table></div></div>
{{ $advances->links() }}
<p class="small muted mt-1">One instalment per open advance is deducted in each payroll run (never more than the net pay left). Recovery counts once the run is approved.</p>

@if(can_do('payroll.manage'))
<div class="modal" id="advModal"><div class="modal-box"><form method="post" action="{{ route('payroll.advances.store') }}">@csrf
<div class="modal-head"><h3>Give salary advance</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
<div class="modal-body form-grid">
  <div class="field span-2"><label>Employee <span class="req">*</span></label><select name="employee_id" class="input" required><option value="">— choose —</option>@foreach($employees as $id => $n)<option value="{{ $id }}" @selected(old('employee_id', request('employee_id')) == $id)>{{ $n }}</option>@endforeach</select></div>
  <div class="field"><label>Date <span class="req">*</span></label><input type="date" name="date" class="input" required value="{{ old('date', bdate()->toDateString()) }}" max="{{ bdate()->toDateString() }}"></div>
  <div class="field"><label>Paid via <span class="req">*</span></label><select name="payment_mode_id" class="input" required>@foreach($modes as $id => $n)<option value="{{ $id }}" @selected(old('payment_mode_id') == $id)>{{ $n }}</option>@endforeach</select></div>
  <div class="field"><label>Amount <span class="req">*</span></label><input type="number" name="amount" step="0.01" min="1" class="input" required value="{{ old('amount') }}"></div>
  <div class="field"><label>Monthly instalment <span class="req">*</span></label><input type="number" name="installment" step="0.01" min="1" class="input" required value="{{ old('installment') }}"></div>
  <div class="field span-2"><label>Reason</label><input name="reason" class="input" maxlength="200" value="{{ old('reason') }}"></div>
  <p class="small muted span-2">Limit: outstanding advances may not exceed {{ setting('payroll_advance_limit_months', 2) }} × monthly salary.</p>
</div>
<div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Record advance</button></div></form></div></div>
@endif
@endsection
