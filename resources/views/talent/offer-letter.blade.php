@extends('layouts.print')
@section('title', 'Offer '.$o->number)
@section('doctitle', 'OFFER OF EMPLOYMENT')
@section('docmeta')<div class="small"><b>{{ $o->number }}</b></div><div class="small">{{ $o->created_at->format('d M Y') }}</div>@endsection
@section('content')
<div class="tal-letter" id="talOfferLetter">
<p>{{ $o->applicant->name }}<br>{{ $o->applicant->phone }}{{ $o->applicant->email ? ' · '.$o->applicant->email : '' }}</p>
<p>Dear {{ \Illuminate\Support\Str::of($o->applicant->name)->explode(' ')->first() }},</p>
<p>Following your interview, we are pleased to offer you the position of <b>{{ $o->vacancy->title }}</b> in our {{ $o->vacancy->department->name }} department on the following terms:</p>
<table class="tal-terms">
  <tr><th>Type of employment</th><td>{{ \App\Models\TalentVacancy::TYPES[$o->employment_type] ?? $o->employment_type }}{{ $o->contract_months ? ' for '.$o->contract_months.' months' : '' }}</td></tr>
  <tr><th>Start date</th><td>{{ $o->start_date->format('l d F Y') }}</td></tr>
  <tr><th>Salary</th><td>{{ money($o->salary) }} per month, paid monthly, less PAYE income tax and your 5.5 % SSNIT contribution</td></tr>
  <tr><th>Probation</th><td>{{ $o->probation_months ? $o->probation_months.' months, with a review before it ends' : 'None' }}</td></tr>
  <tr><th>Annual leave</th><td>Not less than 15 working days a year (Labour Act 2003, s. 20)</td></tr>
  <tr><th>Notice</th><td>{{ $o->probation_months ? $cfg['probation_notice'].' days during probation; afterwards ' : '' }}two weeks (one month after three years' service), as provided by s. 17 of the Labour Act</td></tr>
  @if($o->notes)<tr><th>Conditions</th><td>{{ $o->notes }}</td></tr>@endif
</table>
<p>A contract of employment and a written statement of your particulars of employment will be given to you on joining. Please confirm your acceptance by signing below{{ $o->respond_by ? ' by '.$o->respond_by->format('d F Y') : '' }}.</p>
<p>Yours sincerely,</p>
<div class="tal-sig"><div>For {{ $currentHotel->name }}<br>{{ $o->creator?->name }}</div><div>Accepted: {{ $o->applicant->name }}<br>Date:</div></div>
</div>
@endsection
