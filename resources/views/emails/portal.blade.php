@extends('emails.layout')
@section('body')
@php $g = $stay->guest ?? $stay->reservation?->guest; $url = $stay->portalUrl(); @endphp
<p style="font-size:16px;margin:0 0 12px">Dear {{ $g?->full_name ?? 'Guest' }},</p>
<p>Welcome to {{ $hotel->name }}{{ $stay->room ? ' – you are in room '.$stay->room->room_no : '' }}. Your personal guest page lets you, from your phone:</p>
<ul style="padding-left:18px;margin:8px 0"><li>request housekeeping, amenities or repairs and follow their progress</li><li>order in-room dining and book spa treatments</li><li>see your bill at any time and request express check-out</li></ul>
<table cellpadding="0" cellspacing="0" style="margin:18px 0"><tr><td style="background:#1d4ed8;border-radius:8px"><a href="{{ $url }}" style="display:inline-block;padding:12px 24px;color:#fff;text-decoration:none;font-weight:bold">Open my stay</a></td></tr></table>
@if(setting('portal_wifi_name'))<p>Wi-Fi: <b>{{ setting('portal_wifi_name') }}</b>@if(setting('portal_wifi_password')) · password <b>{{ setting('portal_wifi_password') }}</b>@endif</p>@endif
<p style="font-size:12px;color:#64748b">This link is private to your stay – please do not forward it. If the button does not work, copy: {{ $url }}</p>
<p style="margin-top:18px">Warm regards,<br><b>Front Office, {{ $hotel->name }}</b></p>
@endsection
