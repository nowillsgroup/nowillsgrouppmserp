@extends('emails.layout')
@section('body')
<p style="font-size:16px;margin:0 0 4px"><b>Licences &amp; statutory compliance</b></p>
<p style="margin:0 0 14px;color:#64748b">{{ $hotel->name }} · as of {{ bdate()->format('d M Y') }}</p>
@foreach(['Expired / overdue – act today' => [$expired, '#b91c1c'], 'Due for renewal' => [$due, '#b45309']] as $title => [$list, $col])
@if(count($list))
<p style="margin:14px 0 6px;font-weight:700;color:{{ $col }}">{{ $title }} ({{ count($list) }})</p>
<table width="100%" cellpadding="6" cellspacing="0" style="border-collapse:collapse;font-size:13px">
  <tr style="background:#1e293b;color:#fff"><th align="left">Item</th><th align="left">Authority</th><th align="left">Reference</th><th align="right">Valid until</th><th align="right">Days</th></tr>
  @foreach($list as $i)
  <tr style="border-bottom:1px solid #e2e8f0"><td>{{ $i->name }}@if($i->is_critical) <b style="color:#b91c1c">· critical</b>@endif</td><td>{{ $i->authority }}</td><td>{{ $i->reference_no ?: '—' }}</td>
    <td align="right">{{ $i->expires_on->format('d M Y') }}</td><td align="right" style="color:{{ $col }}">{{ $i->daysLeft() }}</td></tr>
  @endforeach
</table>
@endif
@endforeach
<p style="font-size:12px;color:#64748b;margin-top:14px">Record the renewal in Nowills PMS › Engineering › Licences &amp; Compliance to stop these reminders.</p>
@endsection
