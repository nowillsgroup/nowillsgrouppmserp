@extends('emails.layout')
@section('body')
{!! \App\Mail\MarketingMail::renderBody($text) !!}
@if($ctaLabel && $ctaUrl)
<table cellpadding="0" cellspacing="0" style="margin:18px 0"><tr><td style="background:#1d4ed8;border-radius:8px"><a href="{{ $ctaUrl }}" style="display:inline-block;padding:12px 26px;color:#fff;text-decoration:none;font-weight:bold">{{ $ctaLabel }}</a></td></tr></table>
@endif
<p style="margin-top:22px;font-size:11px;color:#94a3b8;line-height:1.5">You receive this e-mail because you agreed to hear from {{ $hotel->name }}.
  <a href="{{ $unsubscribeUrl }}" style="color:#64748b">Unsubscribe or change your preferences</a>.</p>
@endsection
