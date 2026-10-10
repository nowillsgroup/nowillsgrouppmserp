@extends('layouts.print')
@section('title', $c->number)
@section('doctitle', $kind === 'query' ? 'QUERY – REQUEST FOR EXPLANATION' : strtoupper($c->outcomeLabel()))
@section('docmeta')<div class="small"><b>{{ $c->number }}</b></div><div class="small">{{ ($kind === 'query' ? $c->query_issued_on : $c->outcome_on)->format('d M Y') }}</div>@endsection
@section('content')
<div class="tal-letter" id="talCaseLetter">
<p><b>{{ $c->employee->name }}</b><br>{{ $c->employee->designation }}, {{ $c->employee->department?->name }} ({{ $c->employee->emp_code }})</p>
@if($kind === 'query')
<p>It has been reported that on <b>{{ $c->incident_on->format('d F Y') }}</b>:</p><p class="pre-line">{{ $c->description }}</p>
<p>This is alleged {{ strtolower($c->categoryLabel()) }}, which is regarded as {{ strtolower(\App\Models\TalentCase::SEVERITY[$c->severity]) }} under the hotel's disciplinary rules.</p>
<p>You are requested to explain in writing why disciplinary action should not be taken against you. Your reply must reach Human Resources by <b>{{ $c->reply_due->format('l d F Y') }}</b>. If no reply is received by then, the matter will proceed on the information available. You may be invited to a hearing, where you may be accompanied by a colleague or union representative.</p>
@else
<p>Following the query dated {{ $c->query_issued_on?->format('d F Y') }}{{ $c->hearing_held ? ' and the disciplinary hearing held on '.$c->hearing_at->format('d F Y') : '' }} regarding {{ strtolower($c->categoryLabel()) }} on {{ $c->incident_on->format('d F Y') }}, the allegation was found <b>{{ $c->finding === 'proven' ? 'proven' : 'not proven' }}</b>.</p>
<p>The decision is: <b>{{ $c->outcomeLabel() }}</b>{{ $c->suspension_days ? ' for '.$c->suspension_days.' days '.($c->suspension_paid ? 'with' : 'without').' pay' : '' }}.</p>
@if($c->resolution)<p class="pre-line">{{ $c->resolution }}</p>@endif
@if($c->expires_on)<p>This will remain on your record until <b>{{ $c->expires_on->format('d F Y') }}</b>. Any further misconduct during this period may lead to more serious action.</p>@endif
@if($c->outcome === 'dismissal')<p>Your employment ends on {{ $c->exitRecord?->last_day?->format('d F Y') ?? $c->outcome_on->format('d F Y') }}. All monies due to you will be paid by {{ $c->exitRecord?->final_pay_due?->format('d F Y') ?? 'the next working day' }} (Labour Act s. 18). If you believe the dismissal is unfair you may complain to the National Labour Commission within six months (s. 64).</p>@endif
<p>You may appeal in writing to the General Manager within 7 days of receiving this letter.</p>
@endif
<div class="tal-sig"><div>Human Resources<br>{{ $kind === 'outcome' ? $c->decider?->name : '' }}</div><div>Received by {{ $c->employee->name }}<br>Date:</div></div>
</div>
@endsection
