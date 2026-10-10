@extends('layouts.print')
@section('title', $x->number)
@section('doctitle', $x->isVoluntary() ? 'ACCEPTANCE OF RESIGNATION' : 'NOTICE OF TERMINATION')
@section('docmeta')<div class="small"><b>{{ $x->number }}</b></div><div class="small">{{ $x->notice_on->format('d M Y') }}</div>@endsection
@section('content')
<div class="tal-letter" id="talExitLetterDoc">
<p><b>{{ $x->employee->name }}</b><br>{{ $x->employee->designation }}, {{ $x->employee->department?->name }} ({{ $x->employee->emp_code }})</p>
@if($x->isVoluntary())
<p>We acknowledge your {{ $x->type === 'retirement' ? 'notice of retirement' : 'resignation' }} given on {{ $x->notice_on->format('d F Y') }} and accept it. Your last working day will be <b>{{ $x->last_day->format('l d F Y') }}</b>.</p>
@else
<p>This letter gives you notice that your employment will end for the following reason: <b>{{ $x->typeLabel() }}</b>.</p>
@if($x->reason)<p class="pre-line">{{ $x->reason }}</p>@endif
<p>{{ $x->pilon ? 'You will be paid '.money($x->pilon_amount).' in lieu of notice and your employment ends on '.$x->last_day->format('l d F Y').'.' : 'Notice: '.$x->notice_days.' days ('.$x->notice_basis.'). Your last day of employment is '.$x->last_day->format('l d F Y').'.' }}</p>
@endif
<p>All wages, accrued leave ({{ rtrim(rtrim(number_format($facts['leave_days'], 1), '0'), '.') }} days earned leave outstanding) and other monies due to you, less any amounts you owe the hotel{{ $facts['advance'] > 0 ? ' (salary advance '.money($facts['advance']).')' : '' }}, will be paid not later than <b>{{ $x->final_pay_due->format('d F Y') }}</b> (Labour Act s. 18).</p>
<p>Please return your uniform, keys, and any hotel property to your head of department before you leave.</p>
@unless($x->isVoluntary())<p>If you consider the termination unfair you may present a complaint to the National Labour Commission (s. 64).</p>@endunless
<p>We thank you for your service and wish you well.</p>
<div class="tal-sig"><div>Human Resources<br>{{ $x->creator?->name }}</div><div>Received by {{ $x->employee->name }}<br>Date:</div></div>
</div>
@endsection
