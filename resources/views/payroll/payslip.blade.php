@extends('layouts.print')
@section('title', $slips->count() === 1 ? 'Payslip '.$slips->first()->employee->name.' '.$run->label() : 'Payslips '.$run->label())
@section('nohead', true)
@section('pageclass', 'payslips')
@section('content')
@foreach($slips as $s)@php
  $e = $s->employee; $earn = $s->lines->where('kind', 'earning'); $ded = $s->lines->where('kind', 'deduction'); $er = $s->lines->where('kind', 'employer');
  $rows = max($earn->count(), $ded->count());
  $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 2), '0'), '.');
@endphp
<div class="slip">
  <div class="print-head">
    <div>@if($currentHotel->logo_path)<img src="{{ asset($currentHotel->logo_path) }}" alt="" style="max-height:44px;margin-bottom:.3rem;display:block">@endif<h2>{{ $currentHotel->legal_name ?: $currentHotel->name }}</h2>
      <div class="small text-2">{{ $currentHotel->address }}{{ $currentHotel->city ? ', '.$currentHotel->city : '' }}</div></div>
    <div class="right"><h2 style="color:#1d4ed8">PAYSLIP</h2><div class="small"><b>{{ $run->label() }}</b></div><div class="small">{{ $run->run_no }}@if($run->status === 'draft') · <span style="color:#b45309;font-weight:700">DRAFT</span>@endif</div></div>
  </div>
  <table class="slip-info"><tr>
    <td><span>Employee</span><b>{{ $e->name }}</b></td><td><span>Code</span>{{ $e->emp_code }}</td><td><span>Designation</span>{{ $e->designation ?? '—' }}</td><td><span>Department</span>{{ $e->department?->name ?? '—' }}</td>
  </tr><tr>
    <td><span>Date of joining</span>{{ $e->joining_date ? dmy($e->joining_date) : '—' }}</td><td><span>TIN / Ghana Card</span>{{ $e->tax_id ?? '—' }}</td><td><span>SSNIT no.</span>{{ $e->social_security_no ?? '—' }}</td>
    <td><span>Paid by</span>{{ $s->paymentMode?->name ?? '—' }}@if($s->bank_account) · {{ $e->bank_name }} ****{{ substr($s->bank_account, -4) }}@endif</td>
  </tr><tr>
    <td><span>Days in month</span>{{ $s->days_in_period }}</td><td><span>Paid days</span><b>{{ $fmt($s->paid_days) }}</b></td>
    <td><span>Present / paid leave</span>{{ $fmt($s->present_days) }} / {{ $fmt($s->paid_leave_days) }}</td><td><span>Loss of pay / overtime</span>{{ $fmt($s->lop_days) }} d / {{ $fmt($s->ot_hours) }} h</td>
  </tr><tr>
    <td><span>Public holidays</span>{{ $fmt($s->holiday_days) }} d{{ (float) $s->holiday_hours > 0 ? ' · '.$fmt($s->holiday_hours).' h worked' : '' }}</td>
    <td><span>Chargeable income</span>{{ money($s->chargeable_income, false) }}</td>
    <td colspan="2"><span>Income tax (PAYE)</span>{{ $s->paye_basis ?: 'GRA monthly bands' }}</td>
  </tr></table>
  <table class="table table-compact slip-lines">
    <thead><tr><th>Earnings</th><th class="num">Amount</th><th>Deductions</th><th class="num">Amount</th></tr></thead>
    <tbody>
    @for($i = 0; $i < $rows; $i++)@php $a = $earn->values()[$i] ?? null; $b = $ded->values()[$i] ?? null; @endphp
      <tr><td>{{ $a?->name }}</td><td class="num">{{ $a ? money($a->amount, false) : '' }}</td><td>{{ $b?->name }}</td><td class="num">{{ $b ? money($b->amount, false) : '' }}</td></tr>
    @endfor
    </tbody>
    <tfoot><tr class="bold"><td>Gross earnings</td><td class="num">{{ money($s->gross, false) }}</td><td>Total deductions</td><td class="num">{{ money($s->deductions, false) }}</td></tr></tfoot>
  </table>
  <div class="slip-net"><div><span>Net pay</span><b>{{ money($s->net) }}</b></div><div class="small">{{ amount_words((float) $s->net) }}</div></div>
  @if($er->count())
  <div class="small text-2" style="margin-top:.6rem"><b>Employer contributions (not deducted from pay):</b> @foreach($er as $x){{ $x->name }} {{ money($x->amount, false) }}@if(! $loop->last) · @endif @endforeach · Cost to company {{ money($s->gross + $s->employer_cost) }}</div>
  @endif
  <div class="tiny text-2" style="margin-top:.5rem"><b>Statutory (Ghana):</b> SSNIT Tier 1 13.5% of basic (employee 5.5% + employer 8%) and Tier 2 5% (employer) up to {{ money(\App\Services\PayrollService::SSNIT_CAP, false) }} insurable earnings · employer SSNIT no. {{ \App\Services\StatutoryService::cfg('stat_ssnit_employer_no') ?: '—' }} · Tier 2 trustee: {{ \App\Services\StatutoryService::payee('tier2') }} · PAYE is paid to GRA by the 15th of the following month and SSNIT by the 14th (Act 766, Act 896).</div>
  <div class="tiny text-2" style="margin-top:1rem;display:flex;justify-content:space-between"><span>Computer-generated payslip – no signature required.</span><span>{{ $run->status === 'paid' ? 'Paid '.dmy($run->paid_date) : ucfirst($run->status) }} · printed {{ now()->format('d M Y H:i') }}</span></div>
</div>
@endforeach
@endsection
