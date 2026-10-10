@extends('emails.layout')
@section('body')
@php $tone = ['critical' => '#b91c1c', 'warning' => '#b45309', 'info' => '#1d4ed8'][$alert->severity] ?? '#1e293b'; @endphp
<p style="font-size:12px;margin:0 0 4px;color:{{ $tone }};font-weight:700;letter-spacing:.05em">{{ strtoupper($alert->severity) }} KPI ALERT</p>
<p style="font-size:16px;margin:0 0 10px"><b>{{ $alert->name }}</b></p>
<p style="margin:0 0 12px">{{ $event->message }}</p>
<table cellpadding="7" cellspacing="0" style="border-collapse:collapse;font-size:13px">
  <tr><td style="color:#64748b">Rule</td><td><b>{{ $alert->rule() }}</b></td></tr>
  <tr><td style="color:#64748b">Business date</td><td>{{ $event->business_date->format('l, d M Y') }}</td></tr>
  <tr><td style="color:#64748b">Actual</td><td style="color:{{ $tone }}"><b>{{ \App\Services\KpiService::format((float) $event->value, \App\Services\KpiService::METRICS[$alert->metric][1] ?? 'int') }}</b></td></tr>
</table>
<p style="font-size:12px;color:#64748b;margin-top:14px">Acknowledge it in Nowills PMS under Reports › KPI alerts. <a href="{{ route('reports.alerts') }}">Open</a></p>
@endsection
