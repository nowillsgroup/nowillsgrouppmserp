@extends('layouts.app')
@section('title', 'Payroll Runs')
@section('crumbs') HR &amp; Payroll / Payroll @endsection
@section('actions')
<a class="btn" href="{{ route('payroll.advances') }}"><x-icon name="money"/> Salary advances</a>
@if(can_do('payroll.manage'))<a class="btn" href="{{ route('payroll.components') }}"><x-icon name="layers"/> Pay components</a>@endif
@if(can_do('payroll.manage') && $months->count())<button class="btn btn-primary" data-modal="runModal"><x-icon name="plus"/> New payroll run</button>@endif
@endsection
@section('content')
<div class="stats">
  <x-stat label="Gross pay this year" :value="money($ytd->g ?? 0)" icon="chart" tone="blue" :sub="'net '.money($ytd->n ?? 0)"/>
  <x-stat label="Employer contributions YTD" :value="money($ytd->e ?? 0)" icon="building" tone="purple"/>
  <x-stat label="Approved, awaiting payment" :value="money($unpaid)" icon="clock" :tone="$unpaid > 0 ? 'amber' : 'green'" sub="salaries payable"/>
  <x-stat label="Staff advances outstanding" :value="money($advances)" icon="wallet" tone="slate" :href="route('payroll.advances')"/>
</div>

@if($drafts)
<div class="alert alert-info"><x-icon name="edit"/><div>{{ $drafts }} draft run(s) – review the payslips, then an approver (not the preparer) approves them. Attendance and leave in approved months are locked.</div></div>
@endif

<div class="card flush"><div class="card-body table-wrap"><table class="table" id="runsTable">
<thead><tr><th>Run</th><th>Period</th><th>Status</th><th class="num">Employees</th><th class="num">Gross</th><th class="num">Deductions</th><th class="num">Net pay</th><th class="num">Employer cost</th><th>Prepared / approved</th><th>Paid</th></tr></thead><tbody>
@forelse($runs as $r)
<tr><td><a class="bold" href="{{ route('payroll.show', $r) }}">{{ $r->run_no }}</a></td><td>{{ $r->label() }}</td><td>{!! status_badge($r->status) !!}</td>
<td class="num">{{ $r->employees }}</td><td class="num">{{ money($r->gross, false) }}</td><td class="num">{{ money($r->deductions, false) }}</td><td class="num bold">{{ money($r->net, false) }}</td><td class="num">{{ money($r->employer_cost, false) }}</td>
<td class="small">{{ $r->generator?->name ?? '—' }}@if($r->approver)<div class="tiny muted">✓ {{ $r->approver->name }} · {{ $r->approved_at->format('d M') }}</div>@endif</td>
<td class="small">{{ $r->paid_date ? dmy($r->paid_date) : '—' }}</td></tr>
@empty<tr><td colspan="10"><x-empty text="No payroll runs yet. Start with last month." icon="money"/></td></tr>@endforelse
</tbody></table></div></div>
{{ $runs->links() }}

<div class="card mt-2"><div class="card-head"><h3>How payroll works</h3></div><div class="card-body rm-steps">
  <div><b>1 · Attendance &amp; leave</b>Mark daily attendance; approved leave fills the register. Absent days and unpaid leave are loss of pay (LOP); unmarked days count as {{ setting('payroll_unmarked', 'present') }}.</div>
  <div><b>2 · Generate</b>Basic is pro-rated by paid days; allowances, overtime ({{ setting('payroll_ot_multiplier', 1.5) }}× beyond {{ setting('payroll_shift_hours', 9) }} h), statutory deductions, income tax and advance instalments are calculated.</div>
  <div><b>3 · Review &amp; approve</b>Add one-off earnings or recoveries, recalculate, then an approver locks the month and the cost is accrued in the ledger.</div>
  <div><b>4 · Pay</b>Download the bank advice, pay, and mark the run paid – salaries payable is cleared against each employee's payment mode.</div>
</div></div>

@if(can_do('payroll.manage') && $months->count())
<div class="modal" id="runModal"><div class="modal-box"><form method="post" action="{{ route('payroll.store') }}">@csrf
<div class="modal-head"><h3>New payroll run</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
<div class="modal-body">
  <div class="field"><label>Month <span class="req">*</span></label><select name="month" class="input" required>@foreach($months as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
  <div class="field"><label>Notes</label><input name="notes" class="input" maxlength="255"></div>
  <p class="small muted">Every active employee with a salary is included; joiners and leavers are pro-rated. The run stays a draft until approved – you can recalculate it as often as needed.</p>
</div>
<div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Generate payslips</button></div></form></div></div>
@endif
@endsection
