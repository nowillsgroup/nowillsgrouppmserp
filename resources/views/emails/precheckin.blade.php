@extends('emails.layout')
@section('body')
@php $r = $reservation; @endphp
<p style="font-size:16px;margin:0 0 12px">Dear {{ $r->guest?->full_name ?? 'Guest' }},</p>
<p>We look forward to welcoming you on <b>{{ $r->arrival_date->format('l, d M Y') }}</b> (reservation <b>{{ $r->reservation_no }}</b>, {{ $r->nights }} night(s)).</p>
<p>Skip the paperwork: check in online in two minutes. Confirm your details, tell us your arrival time, upload your ID and sign the registration card from your phone.</p>
<table cellpadding="0" cellspacing="0" style="margin:18px 0"><tr><td style="background:#1d4ed8;border-radius:8px"><a href="{{ $r->checkinUrl() }}" style="display:inline-block;padding:12px 24px;color:#fff;text-decoration:none;font-weight:bold">Check in online</a></td></tr></table>
<p style="font-size:12px;color:#64748b">If the button does not work, copy this link: {{ $r->checkinUrl() }}</p>
<p style="margin-top:18px">Warm regards,<br><b>Front Office, {{ $hotel->name }}</b></p>
@endsection
