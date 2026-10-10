@extends('public.layout')
@section('title', $r->status === 'tentative' ? 'Deposit pending' : ($r->status === 'cancelled' ? 'Booking released' : 'Booking confirmed'))
@section('content')
<div class="card"><div class="card-body center" style="padding:2.5rem">
@if($r->status === 'tentative')
  <div style="width:64px;height:64px;border-radius:50%;background:#fef3c7;color:#b45309;display:grid;place-items:center;margin:0 auto 1rem"><x-icon name="clock" style="width:32px;height:32px"/></div>
  <h2>Almost done, {{ $r->guest->first_name }} – your rooms are on hold.</h2>
  <p class="text-2">Reservation <b style="font-size:1.2rem">{{ $r->reservation_no }}</b> is confirmed as soon as the deposit is paid.</p>
  @if($link && $link->isPayable())<p class="small muted">Hold expires {{ $link->expires_at->format('d M, H:i') }}.</p><a class="btn btn-primary mt-1" href="{{ $link->url() }}"><x-icon name="lock"/> Pay deposit {{ money($link->amount) }}</a>@endif
@elseif($r->status === 'cancelled')
  <div style="width:64px;height:64px;border-radius:50%;background:#fee2e2;color:#b91c1c;display:grid;place-items:center;margin:0 auto 1rem"><x-icon name="x" style="width:32px;height:32px"/></div>
  <h2>This booking was released</h2>
  <p class="text-2">{{ $r->cancel_reason ?: 'The reservation was cancelled.' }}</p>
  <a class="btn btn-primary mt-2" href="{{ route('booking.show', $hotel->code) }}">Book again</a>
@else
  <div style="width:64px;height:64px;border-radius:50%;background:#dcfce7;color:#16a34a;display:grid;place-items:center;margin:0 auto 1rem"><x-icon name="check" style="width:32px;height:32px"/></div>
  <h2>Thank you, {{ $r->guest->first_name }}! Your booking is confirmed.</h2>
  <p class="text-2">Confirmation number <b style="font-size:1.2rem">{{ $r->reservation_no }}</b></p>
@endif
  <p>{{ $r->rooms->count() }} × {{ $r->rooms->first()->roomType->name }} · {{ $r->arrival_date->format('D d M Y') }} → {{ $r->departure_date->format('D d M Y') }} ({{ $r->nights }} nights)</p>
@if($r->status === 'confirmed' || $r->status === 'checked_in')
  <p class="small muted">@if($link && $link->status === 'paid')Deposit of {{ money($link->received()) }} received ({{ $link->pay_no }}) – the balance is payable at the hotel.@else Payment at the hotel.@endif Please carry a valid photo ID. A copy has been sent to {{ $r->guest->email }}.</p>
  <a class="btn btn-primary mt-2" href="{{ route('booking.show', $hotel->code) }}">Make another booking</a>
@endif
</div></div>
@endsection
