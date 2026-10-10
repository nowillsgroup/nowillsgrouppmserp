@extends('emails.layout')
@section('body')
<p style="font-size:16px;margin:0 0 12px">Dear {{ $req->requester_name }},</p>
<p>We received a <b>{{ strtolower(\App\Models\PrivacyRequest::SHORT[$req->type]) }}</b> request about your personal data at {{ $hotel->name }} (reference <b>{{ $req->request_no }}</b>).</p>
<p>To protect your data, please confirm that you made this request. Nothing happens until you do.</p>
<table cellpadding="0" cellspacing="0" style="margin:18px 0"><tr><td style="background:#1d4ed8;border-radius:8px"><a href="{{ $url }}" style="display:inline-block;padding:12px 24px;color:#fff;text-decoration:none;font-weight:bold">Confirm my request</a></td></tr></table>
<p style="font-size:12px;color:#64748b">The link is valid for 48 hours. If you did not make this request, ignore this e-mail – it will be closed automatically. For requests to access or erase data we may also ask you to confirm your identity.</p>
<p style="margin-top:18px">Kind regards,<br><b>Data Protection, {{ $hotel->name }}</b></p>
@endsection
