@extends('emails.layout')
@section('body')
@php $g = $inv->guest; $stay = $inv->reservation; @endphp
<p style="font-size:16px;margin:0 0 12px">Dear {{ $g?->first_name ?: 'Guest' }},</p>
@if($reminder)
<p>A few days ago we asked about your recent stay with us. If you have a minute, we would still love to hear from you.</p>
@else
<p>Thank you for staying at {{ $hotel->name }}@if($stay?->arrival_date) from {{ $stay->arrival_date->format('d M') }} to {{ $stay->departure_date?->format('d M Y') }}@endif. We hope you had a wonderful time.</p>
@endif
<p>{{ $inv->survey->intro ?: 'How did we do? It takes about one minute.' }}</p>
<p style="margin:18px 0 6px;font-weight:bold">How likely are you to recommend us to a friend?</p>
<table cellpadding="0" cellspacing="0" style="margin:0 0 18px"><tr>
@for($i = 0; $i <= 10; $i++)<td style="padding:0 2px"><a href="{{ $url }}?nps={{ $i }}" style="display:inline-block;width:30px;line-height:30px;text-align:center;border-radius:6px;text-decoration:none;font-weight:bold;color:#fff;background:{{ $i <= 6 ? '#dc2626' : ($i <= 8 ? '#d97706' : '#16a34a') }}">{{ $i }}</a></td>@endfor
</tr></table>
<table cellpadding="0" cellspacing="0" style="margin:6px 0 18px"><tr><td style="background:#166534;border-radius:8px"><a href="{{ $url }}" style="display:inline-block;padding:12px 24px;color:#fff;text-decoration:none;font-weight:bold">Share your feedback</a></td></tr></table>
<p style="font-size:12px;color:#64748b">The survey is open until {{ ($inv->expires_at ?? now()->addDays($inv->survey->expiry_days))->format('d M Y') }}. Your answers are read by our management team and used only to improve our service.</p>
<p style="margin-top:18px">With warm regards,<br><b>{{ setting('fb_signature', 'Guest Relations Manager') }}, {{ $hotel->name }}</b></p>
@endsection
