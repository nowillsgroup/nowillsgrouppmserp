@extends('layouts.print')
@section('title', 'Welcome card')
@section('nohead', true)
@section('content')
<div style="max-width:420px;margin:0 auto;text-align:center;border:1px dashed #cbd5e1;border-radius:16px;padding:1.6rem 1.4rem">
  @if($currentHotel->logo_path)<img src="{{ asset($currentHotel->logo_path) }}" alt="" style="max-height:48px;margin:0 auto .4rem;display:block">@endif
  <h2 style="margin:0">{{ $currentHotel->name }}</h2>
  <p style="margin:.3rem 0 1rem">Welcome{{ $guest?->first_name ? ', '.$guest->first_name : '' }}! · Room <b>{{ $stay->room?->room_no }}</b></p>
  <div class="portal-qr" style="width:220px;margin:0 auto">{!! $svg !!}</div>
  <p style="font-weight:700;margin:.8rem 0 .2rem">Scan for your personal guest page</p>
  <p class="small" style="margin:0 0 .8rem">Room service · housekeeping &amp; amenities · spa booking · your bill · express check-out</p>
  @if(setting('portal_wifi_name'))<div style="background:#f1f5f9;border-radius:10px;padding:.55rem;margin-bottom:.6rem"><b>Wi-Fi</b> {{ setting('portal_wifi_name') }}@if(setting('portal_wifi_password')) · <span style="font-family:monospace">{{ setting('portal_wifi_password') }}</span>@endif</div>@endif
  <p class="tiny muted" style="margin:0">Check-out {{ $stay->departure_date->format('D d M') }} by {{ substr($currentHotel->checkout_time, 0, 5) }} · Front desk {{ $currentHotel->phone }}<br>Private to your stay – please keep this card.</p>
</div>
@endsection
