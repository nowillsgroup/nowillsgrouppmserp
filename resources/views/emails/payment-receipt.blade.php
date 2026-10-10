@extends('emails.layout')
@section('body')
@php $p = $payment; @endphp
<p style="font-size:16px;margin:0 0 12px">Dear {{ $p->payer_name }},</p>
<p>Thank you – we have received your payment.</p>
<table cellpadding="6" cellspacing="0" style="border-collapse:collapse;margin:12px 0;font-size:14px">
<tr><td style="color:#64748b">Receipt</td><td><b>{{ $p->pay_no }}</b></td></tr>
<tr><td style="color:#64748b">For</td><td>{{ $p->description }}</td></tr>
@if($p->reservation)<tr><td style="color:#64748b">Reservation</td><td>{{ $p->reservation->reservation_no }}</td></tr>@endif
<tr><td style="color:#64748b">Amount paid</td><td style="font-size:18px"><b>{{ $p->currency }} {{ number_format($p->received(), 2) }}</b></td></tr>
<tr><td style="color:#64748b">Date</td><td>{{ $p->paid_at?->format('d M Y, H:i') }}</td></tr>
<tr><td style="color:#64748b">Transaction</td><td>{{ $p->provider_payment_id }}{{ $p->channel ? ' · '.str_replace('_', ' ', $p->channel) : '' }}</td></tr>
</table>
<p>The payment has been credited to your bill. You can view the receipt at any time: <a href="{{ $p->url() }}">{{ $p->url() }}</a></p>
<p style="margin-top:18px">Warm regards,<br><b>Front Office, {{ $hotel->name }}</b></p>
@endsection
