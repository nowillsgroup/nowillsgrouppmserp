@extends('emails.layout')
@section('body')
@php $r = $reservation; $url = $r->offersUrl(); @endphp
<p style="font-size:16px;margin:0 0 12px">Dear {{ $r->guest?->full_name ?? 'Guest' }},</p>
<p>We look forward to welcoming you on <b>{{ $r->arrival_date->format('l, d M Y') }}</b> (reservation <b>{{ $r->reservation_no }}</b>, {{ $r->nights }} night{{ $r->nights > 1 ? 's' : '' }}). Here are a few ways to make your stay even better, reserved for you at a special price:</p>
<table cellpadding="0" cellspacing="0" width="100%" style="margin:14px 0;border-collapse:collapse">
@foreach($offers as $x)
  <tr><td style="padding:10px 0;border-top:1px solid #e2e8f0"><b style="font-size:15px">{{ $x['offer']->name }}</b>
    @if($x['offer']->description)<div style="color:#475569;font-size:13px;margin-top:2px">{{ \Illuminate\Support\Str::limit($x['offer']->description, 140) }}</div>@endif</td>
    <td style="padding:10px 0 10px 12px;border-top:1px solid #e2e8f0;text-align:right;white-space:nowrap;vertical-align:top"><b>{{ money($x['quote']['amount']) }}</b>
    <div style="color:#64748b;font-size:11px">{{ $x['quote']['breakdown'] }}</div></td></tr>
@endforeach
</table>
<table cellpadding="0" cellspacing="0" style="margin:18px 0"><tr><td style="background:#1d4ed8;border-radius:8px"><a href="{{ $url }}" style="display:inline-block;padding:12px 24px;color:#fff;text-decoration:none;font-weight:bold">See offers &amp; add to my stay</a></td></tr></table>
<p style="font-size:12px;color:#64748b">Prices are before taxes and are added to your room bill; nothing is charged now. If the button does not work, copy this link: {{ $url }}</p>
<p style="margin-top:18px">Warm regards,<br><b>Front Office, {{ $hotel->name }}</b></p>
@endsection
