@extends('layouts.app')
@section('title', 'Payroll '.$run->label())
@section('crumbs') <a href="{{ route('payroll.index') }}">Payroll</a> / {{ $run->run_no }} @endsection
@section('actions')
{!! status_badge($run->status) !!}
<a class="btn" href="{{ route('payroll.print', $run) }}" target="_blank"><x-icon name="print"/> Payslips</a>
<a class="btn" href="{{ route('payroll.bankadvice', $run) }}"><x-icon name="download"/> Bank advice</a>
@if($run->isLocked())<a class="btn" id="runStatLink" href="{{ route('payroll.statutory', ['period' => $run->period_start->format('Y-m')]) }}"><x-icon name="shield"/> SSNIT / PAYE returns</a>@endif
@if($run->status === 'draft' && can_do('payroll.manage'))
<form method="post" action="{{ route('payroll.recalc', $run) }}" class="inline">@csrf<button class="btn"><x-icon name="refresh"/> Recalculate</button></form>
@endif
@if($run->status === 'draft' && can_do('payroll.approve'))
<form method="post" action="{{ route('payroll.approve', $run) }}" class="inline" onsubmit="return confirm('Approve {{ $run->run_no }}? Attendance and leave for {{ $run->label() }} will be locked and the payroll cost accrued in the ledger.')">@csrf
<button class="btn btn-primary" @disabled($segregated) title="{{ $segregated ? 'You prepared this run – another approver must approve it' : '' }}"><x-icon name="check"/> Approve</button></form>
@endif
@if($run->status === 'approved' && can_do('payroll.approve'))
<button class="btn" data-modal="reopenModal"><x-icon name="edit"/> Reopen</button>
<button class="btn btn-success" data-modal="payModal"><x-icon name="money"/> Mark paid</button>
@endif
@if($run->status === 'draft' && can_do('payroll.manage'))
<form method="post" action="{{ route('payroll.destroy', $run) }}" class="inline" onsubmit="return confirm('Delete this draft run and its payslips?')">@csrf @method('DELETE')<button class="btn btn-danger btn-icon" title="Delete draft"><x-icon name="trash"/></button></form>
@endif
@endsection
@section('content')
@if($run->status === 'draft')
<div class="alert alert-info"><x-icon name="edit"/><div><b>Draft.</b> Figures follow the attendance register, approved leave and open advances – recalculate after changes. @if($segregated)<b>You prepared this run</b>, so someone else must approve it.@endif</div></div>
@elseif($run->status === 'approved')
<div class="alert alert-warn"><x-icon name="clock"/><div><b>Approved {{ $run->approved_at->format('d M Y H:i') }} by {{ $run->approver?->name }}.</b> {{ money($run->net) }} net pay is owed to staff (salaries payable). Attendance and leave for {{ $run->label() }} are locked. Pay the bank advice, then mark the run paid.</div></div>
@else
<div class="alert alert-success"><x-icon name="check"/><div><b>Paid on {{ dmy($run->paid_date) }}</b> by {{ $run->payer?->name ?? '—' }}. This run is final.</div></div>
@endif

@php $runBelowMin = \App\Services\StatutoryService::belowMinimumWage()->whereIn('id', \App\Models\Payslip::where('payroll_run_id', $run->id)->pluck('employee_id')->all()); @endphp
@if($runBelowMin->isNotEmpty())
<div class="alert alert-warn" id="runMinWage"><x-icon name="alert"/><div><b>Below the national minimum wage</b> ({{ money(\App\Services\StatutoryService::minWageMonthly()) }} a month): {{ $runBelowMin->pluck('name')->join(', ') }}. Raise the basic salary before approving.</div></div>
@endif

<div class="card"><div class="card-body grid g-2">
  <dl class="kv"><dt>Period</dt><dd>{{ dmy($run->period_start) }} – {{ dmy($run->period_end) }} ({{ $run->period_start->daysInMonth }} days)</dd>
    <dt>Prepared by</dt><dd>{{ $run->generator?->name ?? '—' }} · {{ $run->generated_at?->format('d M H:i') }}</dd>
    @if($run->approved_at)<dt>Approved by</dt><dd>{{ $run->approver?->name }} · {{ $run->approved_at->format('d M H:i') }}</dd>@endif
    @if($run->notes)<dt>Notes</dt><dd>{{ $run->notes }}</dd>@endif
    <dt>Paid via</dt><dd>@foreach($modes as $m => $x)<span class="badge badge-slate">{{ $m }} · {{ $x['n'] }} · {{ money($x['net']) }}</span> @endforeach</dd></dl>
  <div class="ap-summary" id="runTotals">
    <div><div class="l">Employees</div><div class="v">{{ $run->employees }}</div></div>
    <div><div class="l">Gross pay</div><div class="v">{{ money($run->gross) }}</div></div>
    <div><div class="l">Deductions</div><div class="v red">{{ money($run->deductions) }}</div></div>
    <div><div class="l">Net pay</div><div class="v green">{{ money($run->net) }}</div></div>
    <div><div class="l">Employer contributions</div><div class="v">{{ money($run->employer_cost) }}</div></div>
    <div><div class="l">Total cost to hotel</div><div class="v">{{ money($run->gross + $run->employer_cost) }}</div></div>
  </div>
</div></div>

<div class="grid g-3 mt-2">
@foreach(['earning' => 'Earnings', 'deduction' => 'Deductions', 'employer' => 'Employer contributions'] as $k => $title)
<div class="card"><div class="card-head"><h3>{{ $title }}</h3></div><div class="card-body table-wrap"><table class="table table-compact">
<tbody>@forelse($byCode[$k] ?? [] as $c)<tr><td><span class="badge badge-slate">{{ $c->code }}</span> {{ \Illuminate\Support\Str::before($c->name, ' (') }}</td><td class="num small muted">{{ $c->n }}</td><td class="num">{{ money($c->amt, false) }}</td></tr>
@empty<tr><td class="muted small">None</td></tr>@endforelse</tbody>
<tfoot><tr class="bold"><td>Total</td><td></td><td class="num">{{ money(($byCode[$k] ?? collect())->sum('amt'), false) }}</td></tr></tfoot></table></div></div>
@endforeach
</div>

<div class="card flush mt-2"><div class="card-head"><h3>Payslips</h3>@if($run->status === 'draft' && can_do('payroll.manage'))<button class="btn btn-sm btn-primary" data-modal="adjModal"><x-icon name="plus"/> One-off earning / recovery</button>@endif</div>
<div class="card-body table-wrap"><table class="table table-compact" id="slipTable">
<thead><tr><th>Code</th><th>Employee</th><th class="num" title="Paid days / days in month">Paid days</th><th class="num">LOP</th><th class="num">OT h</th><th class="num">Monthly basic</th><th class="num">Gross</th><th class="num">Deductions</th><th class="num">Net pay</th><th>Pay mode</th><th></th></tr></thead><tbody>
@foreach($slips as $s)@php $manual = $s->lines->where('is_manual', true); @endphp
<tr data-emp="{{ $s->employee->emp_code }}"><td class="small muted">{{ $s->employee->emp_code }}</td>
<td><span class="bold">{{ $s->employee->name }}</span><div class="tiny muted">{{ $s->employee->designation }} · {{ $s->employee->department?->name }}</div>
@foreach($manual as $m)<div class="tiny"><span class="badge {{ $m->kind === 'earning' ? 'badge-green' : 'badge-red' }}">{{ $m->kind === 'earning' ? '+' : '−' }} {{ money($m->amount, false) }}</span> {{ $m->name }}
@if($run->status === 'draft' && can_do('payroll.manage'))<form method="post" action="{{ route('payroll.lines.remove', $m) }}" class="inline" onsubmit="return confirm('Remove this adjustment?')">@csrf @method('DELETE')<button class="btn btn-ghost btn-sm" style="padding:0 .25rem" title="Remove">×</button></form>@endif</div>@endforeach</td>
<td class="num">{{ rtrim(rtrim(number_format($s->paid_days, 1), '0'), '.') }} / {{ $s->days_in_period }}</td>
<td class="num {{ $s->lop_days > 0 ? 'text-red' : 'muted' }}">{{ $s->lop_days > 0 ? rtrim(rtrim(number_format($s->lop_days, 1), '0'), '.') : '–' }}</td>
<td class="num {{ $s->ot_hours > 0 ? '' : 'muted' }}">{{ $s->ot_hours > 0 ? rtrim(rtrim(number_format($s->ot_hours, 2), '0'), '.') : '–' }}</td>
<td class="num small">{{ money($s->basic, false) }}</td><td class="num">{{ money($s->gross, false) }}</td><td class="num">{{ money($s->deductions, false) }}</td><td class="num bold">{{ money($s->net, false) }}</td>
<td class="small">{{ $s->paymentMode?->name ?? '—' }}</td>
<td class="right nowrap"><a class="btn btn-sm" href="{{ route('payroll.payslip', $s) }}" target="_blank" title="Payslip"><x-icon name="eye"/></a>@if(can_do('payroll.manage'))<a class="btn btn-sm btn-ghost" href="{{ route('payroll.employee', $s->employee_id) }}" title="Pay setup"><x-icon name="edit"/></a>@endif</td></tr>
@endforeach
</tbody>
<tfoot><tr class="bold"><td colspan="6">Total · {{ $slips->count() }} employees</td><td class="num">{{ money($run->gross, false) }}</td><td class="num">{{ money($run->deductions, false) }}</td><td class="num">{{ money($run->net, false) }}</td><td colspan="2"></td></tr></tfoot></table></div></div>

@if($journals->count())
<div class="card mt-2"><div class="card-head"><h3>Ledger postings</h3></div><div class="card-body table-wrap"><table class="table table-compact">
<thead><tr><th>Journal</th><th>Date</th><th>Source</th><th>Narration</th><th class="num">Amount</th><th></th></tr></thead><tbody>
@foreach($journals as $j)<tr><td>@if(can_do('gl.view'))<a href="{{ route('gl.journals.show', $j) }}">{{ $j->journal_no }}</a>@else{{ $j->journal_no }}@endif</td><td>{{ dmy($j->date) }}</td><td>{{ \App\Models\GlJournal::SOURCES[$j->source_type] }}</td><td class="small">{{ $j->narration }}</td><td class="num">{{ money($j->total, false) }}</td>
<td>@if($j->reversal_of_id)<span class="badge badge-amber">reversal</span>@elseif($j->reversed_by_id)<span class="badge badge-slate">reversed</span>@endif</td></tr>@endforeach
</tbody></table></div></div>
@elseif($run->status !== 'draft')
<p class="small muted mt-1">The ledger posting is made by the night audit once {{ dmy($run->status === 'paid' ? $run->paid_date : $run->period_end) }} is closed.</p>
@endif

@if($run->status === 'draft' && can_do('payroll.manage'))
<div class="modal" id="adjModal"><div class="modal-box"><form method="post" id="adjForm" action="#">@csrf
<div class="modal-head"><h3>One-off earning / recovery</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
<div class="modal-body form-grid">
  <div class="field span-2"><label>Employee <span class="req">*</span></label><select class="input" id="adjSlip" required><option value="">— choose —</option>@foreach($slips as $s)<option value="{{ route('payroll.lines.add', $s) }}">{{ $s->employee->name }} ({{ $s->employee->emp_code }})</option>@endforeach</select></div>
  <div class="field"><label>Type</label><select name="kind" class="input"><option value="earning">Earning (bonus, incentive, arrears)</option><option value="deduction">Deduction (recovery, fine)</option></select></div>
  <div class="field"><label>Amount <span class="req">*</span></label><input name="amount" type="number" step="0.01" min="0.01" class="input" required></div>
  <div class="field span-2"><label>Description <span class="req">*</span></label><input name="name" class="input" maxlength="80" required placeholder="e.g. Festival bonus"></div>
  <label class="check span-2"><input type="checkbox" name="taxable" value="1" checked> Taxable earning</label>
  <p class="small muted span-2">Adjustments survive recalculation. Earnings are charged to Salaries &amp; Wages; recoveries reduce it.</p>
</div>
<div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Add &amp; recalculate</button></div></form></div></div>
<script>document.getElementById('adjSlip').addEventListener('change', e => document.getElementById('adjForm').action = e.target.value);</script>
@endif

@if($run->status === 'approved' && can_do('payroll.approve'))
<div class="modal" id="reopenModal"><div class="modal-box"><form method="post" action="{{ route('payroll.reopen', $run) }}">@csrf
<div class="modal-head"><h3>Reopen {{ $run->run_no }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
<div class="modal-body"><p class="small">The run returns to draft, attendance and leave for {{ $run->label() }} are unlocked and the ledger accrual is reversed.</p>
<div class="field"><label>Reason <span class="req">*</span></label><input name="reason" required maxlength="200" class="input"></div></div>
<div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Reopen</button></div></form></div></div>
<div class="modal" id="payModal"><div class="modal-box"><form method="post" action="{{ route('payroll.pay', $run) }}">@csrf
<div class="modal-head"><h3>Mark {{ $run->run_no }} as paid</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
<div class="modal-body"><p class="small">Net pay of <b>{{ money($run->net) }}</b> is credited to each employee's payment mode:</p>
<ul class="small">@foreach($modes as $m => $x)<li>{{ $m }}: {{ $x['n'] }} employee(s), {{ money($x['net']) }}</li>@endforeach</ul>
<div class="field"><label>Payment date <span class="req">*</span></label><input type="date" name="paid_date" class="input" required value="{{ bdate()->toDateString() }}" min="{{ $run->period_end->toDateString() }}" max="{{ bdate()->toDateString() }}"></div></div>
<div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-success">Confirm payment</button></div></form></div></div>
@endif
@endsection
