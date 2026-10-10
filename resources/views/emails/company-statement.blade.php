@extends('emails.layout')
@section('body')
@php $p = $position; $c = $company; @endphp
<p style="font-size:16px;margin:0 0 12px">Dear {{ $c->contact_person ?: 'Accounts team' }},</p>
<p>Please find below your statement of account with {{ $hotel->name }} as on {{ bdate()->format('d M Y') }}. Our payment terms with {{ $c->name }} are {{ $c->credit_days }} days from the bill date.</p>
<table cellpadding="6" cellspacing="0" style="border-collapse:collapse;margin:12px 0;font-size:13px;width:100%">
<tr style="background:#f1f5f9"><th align="left">Bill date</th><th align="left">Due</th><th align="right">Open amount</th><th align="left">Status</th></tr>
@forelse($p['open_bills'] as $b)
<tr style="border-top:1px solid #e2e8f0"><td>{{ $b['date']->format('d M Y') }}</td><td>{{ $b['due']->format('d M Y') }}</td><td align="right">{{ number_format($b['amount'], 2) }}</td>
<td style="color:{{ $b['overdue_days'] > 0 ? '#b91c1c' : '#475569' }}">{{ $b['overdue_days'] > 0 ? $b['overdue_days'].' days overdue' : 'Not yet due' }}</td></tr>
@empty
<tr><td colspan="4">No open bills – thank you.</td></tr>
@endforelse
<tr style="border-top:2px solid #0f172a"><td colspan="2"><b>Total outstanding</b></td><td align="right"><b>{{ $hotel->currency ?? '' }} {{ number_format(max(0, $p['outstanding']), 2) }}</b></td><td></td></tr>
@if($p['overdue'] > 0)<tr><td colspan="2" style="color:#b91c1c"><b>Of which overdue</b></td><td align="right" style="color:#b91c1c"><b>{{ number_format($p['overdue'], 2) }}</b></td><td></td></tr>@endif
@if($p['outstanding'] < 0)<tr><td colspan="2">Credit on account</td><td align="right">{{ number_format(abs($p['outstanding']), 2) }}</td><td></td></tr>@endif
</table>
@if($p['overdue'] > 0)<p>We would be grateful if you could arrange payment of the overdue amount at your earliest convenience and share the remittance details with us.</p>@endif
<p style="margin-top:18px">Warm regards,<br><b>Accounts, {{ $hotel->name }}</b>@if($hotel->phone)<br>{{ $hotel->phone }}@endif</p>
@endsection
