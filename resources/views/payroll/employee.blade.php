@extends('layouts.app')
@section('title', 'Pay Setup · '.$e->name)
@section('crumbs') <a href="{{ route('payroll.index') }}">Payroll</a> / <a href="{{ route('masters.index', 'employees') }}">Employees</a> / {{ $e->emp_code }} @endsection
@section('actions')<a class="btn" href="{{ route('masters.edit', ['employees', $e->id]) }}"><x-icon name="user"/> Edit employee</a>@endsection
@section('content')
<div class="card"><div class="card-body grid g-3">
  <dl class="kv"><dt>Employee</dt><dd><b>{{ $e->name }}</b> · {{ $e->emp_code }}</dd><dt>Designation</dt><dd>{{ $e->designation ?? '—' }} · {{ $e->department?->name ?? '—' }}</dd><dt>Joined</dt><dd>{{ $e->joining_date ? dmy($e->joining_date) : '—' }}@if($e->leaving_date) · leaves {{ dmy($e->leaving_date) }}@endif</dd></dl>
  <dl class="kv"><dt>Monthly basic</dt><dd><b>{{ money($e->salary) }}</b></dd><dt>Paid by</dt><dd>{{ $e->paymentMode?->name ?? 'Default (bank)' }}@if($e->bank_account) · {{ $e->bank_name }} {{ $e->bank_account }}@endif</dd><dt>Overtime</dt><dd>{{ $e->ot_eligible ? 'Eligible' : 'Not eligible' }}</dd></dl>
  <dl class="kv"><dt>TIN / Ghana Card</dt><dd>{{ $e->tax_id ?? '—' }}</dd><dt>SSNIT no.</dt><dd>{{ $e->social_security_no ?? '—' }}</dd></dl>
</div></div>

<div class="grid g-2 mt-2">
<form method="post" action="{{ route('payroll.employee.save', $e) }}" class="card flush">@csrf
<div class="card-head"><h3>Pay components</h3><span class="small muted">blank value = component default</span></div>
<div class="card-body table-wrap"><table class="table table-compact" id="empComps">
<thead><tr><th>Component</th><th>Default rule</th><th>Applies</th><th class="num">Own value</th></tr></thead><tbody>
@foreach($components as $c)@php $a = $assigned[$c->id] ?? null; $state = $a ? ($a->is_excluded ? 'off' : 'on') : 'default'; @endphp
<tr><td><span class="badge badge-slate">{{ $c->code }}</span> {{ $c->name }}<div class="tiny muted">{{ \App\Models\PayComponent::KINDS[$c->kind] }}</div></td><td class="small">{{ $c->ruleLabel() }}</td>
<td><select name="c[{{ $c->id }}][state]" class="input input-sm"><option value="default" @selected($state === 'default')>Default ({{ $c->is_default ? 'yes' : 'no' }})</option><option value="on" @selected($state === 'on')>Yes</option><option value="off" @selected($state === 'off')>No – excluded</option></select></td>
<td class="num">@if(in_array($c->calc, ['fixed', 'percent_basic', 'percent_gross']))<input name="c[{{ $c->id }}][value]" type="number" step="0.01" min="0" class="input input-sm" style="width:110px" value="{{ $a?->value !== null ? (float) $a->value : '' }}" placeholder="{{ (float) $c->value }}">@else<span class="muted small">—</span>@endif</td></tr>
@endforeach
</tbody></table></div>
<div class="card-foot"><button class="btn btn-primary"><x-icon name="check"/> Save pay setup</button></div></form>

<div class="card"><div class="card-head"><h3>Preview · {{ $month->format('F Y') }}</h3><span class="small muted">attendance to date, remaining days as present</span></div><div class="card-body">
  @php $s = $preview['stats']; @endphp
  <div class="small muted mb-1">Paid days {{ rtrim(rtrim(number_format($s['paid'], 1), '0'), '.') }} / {{ $preview['days'] }} · LOP {{ rtrim(rtrim(number_format($s['lop'], 1), '0'), '.') }} · OT {{ $s['ot_hours'] }} h</div>
  <table class="table table-compact" id="preview"><tbody>
  @foreach(['earning' => 'Earnings', 'deduction' => 'Deductions', 'employer' => 'Employer'] as $k => $t)
    @foreach(collect($preview['lines'])->where('kind', $k) as $l)<tr><td class="{{ $k === 'employer' ? 'muted' : '' }}">{{ $l['name'] }}</td><td class="num {{ $k === 'deduction' ? 'text-red' : '' }}">{{ $k === 'deduction' ? '−' : '' }}{{ money($l['amount'], false) }}</td></tr>@endforeach
    @if($k === 'earning')<tr class="bold"><td>Gross</td><td class="num">{{ money($preview['gross'], false) }}</td></tr>@endif
    @if($k === 'deduction')<tr class="bold"><td>Net pay</td><td class="num text-green">{{ money($preview['net'], false) }}</td></tr>@endif
  @endforeach
  </tbody></table>
</div></div>
</div>

<div class="grid g-2 mt-2">
<div class="card flush"><div class="card-head"><h3>Payslips</h3></div><div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>Month</th><th>Status</th><th class="num">Gross</th><th class="num">Net</th><th></th></tr></thead><tbody>
@forelse($slips as $p)<tr><td>{{ $p->run->label() }}</td><td>{!! status_badge($p->run->status) !!}</td><td class="num">{{ money($p->gross, false) }}</td><td class="num bold">{{ money($p->net, false) }}</td><td class="right"><a class="btn btn-sm" target="_blank" href="{{ route('payroll.payslip', $p) }}"><x-icon name="eye"/></a></td></tr>
@empty<tr><td colspan="5" class="muted small">No payslips yet.</td></tr>@endforelse</tbody></table></div></div>
<div class="card flush"><div class="card-head"><h3>Salary advances</h3><a class="btn btn-sm" href="{{ route('payroll.advances', ['employee_id' => $e->id]) }}">All</a></div><div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>No.</th><th>Date</th><th class="num">Amount</th><th class="num">Outstanding</th></tr></thead><tbody>
@forelse($advances as $a)<tr><td>{{ $a->advance_no }}@if($a->cancelled_at) <span class="badge badge-red">cancelled</span>@endif</td><td>{{ dmy($a->date) }}</td><td class="num">{{ money($a->amount, false) }}</td><td class="num bold">{{ money($a->outstanding(), false) }}</td></tr>
@empty<tr><td colspan="4" class="muted small">No advances.</td></tr>@endforelse</tbody></table></div></div>
</div>
@endsection
