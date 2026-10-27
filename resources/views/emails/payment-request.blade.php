@extends('emails.layout')
@section('body')
@php $p = $payment; @endphp
<p style="font-size:16px;margin:0 0 12px">Dear {{ $p->payer_name }},</p>
<p>{{ $hotel->name }} has sent you a secure payment request.</p>
<table cellpadding="6" cellspacing="0" style="border-collapse:collapse;margin:12px 0;font-size:14px">
<tr><td style="color:#64748b">For</td><td><b>{{ $p->description }}</b></td></tr>
@if($p->reservation)<tr><td style="color:#64748b">Reservation</td><td>{{ $p->reservation->reservation_no }} · {{ $p->reservation->arrival_date->format('d M Y') }} – {{ $p->reservation->departure_date->format('d M Y') }}</td></tr>@endif
<tr><td style="color:#64748b">Amount</td><td style="font-size:18px"><b>{{ $p->currency }} {{ number_format((float) $p->amount, 2) }}</b></td></tr>
@if($p->expires_at)<tr><td style="color:#64748b">Pay by</td><td>{{ $p->expires_at->format('d M Y, H:i') }}</td></tr>@endif
</table>
<table cellpadding="0" cellspacing="0" style="margin:18px 0"><tr><td style="background:#166534;border-radius:8px"><a href="{{ $p->url() }}" style="display:inline-block;padding:12px 24px;color:#fff;text-decoration:none;font-weight:bold">Pay securely</a></td></tr></table>
<p style="font-size:12px;color:#64748b">You will be taken to our payment provider's secure page – we never see your card details. If the button does not work, copy this link: {{ $p->url() }}</p>
<p style="margin-top:18px">Warm regards,<br><b>Front Office, {{ $hotel->name }}</b></p>
@endsection
