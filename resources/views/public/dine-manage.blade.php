@extends('public.layout')
@section('title', 'Your table booking '.$r->booking_no)
@section('content')
<div class="card" style="max-width:620px;margin:0 auto" id="dineBooking">
  <div class="card-head"><h3><x-icon name="cup"/> Your table at {{ $r->outlet->name }}</h3>{!! tb_badge($r->status) !!}</div>
  <div class="card-body">
    @if($r->type === 'waitlist')
      <p>Hi {{ $r->name }}, you are on the waitlist for {{ $r->party_size }}.@if($r->isWaiting() && $r->quoted_minutes !== null) Estimated wait about {{ $r->quoted_minutes }} minutes from {{ $r->created_at->format('H:i') }}.@endif</p>
    @else
      <div class="kv"><span>Booking reference</span><b>{{ $r->booking_no }}</b></div>
      <div class="kv"><span>Name</span><b>{{ $r->name }}</b></div>
      <div class="kv"><span>Date</span><b>{{ $r->start_at->format('l, d F Y') }}</b></div>
      <div class="kv"><span>Time</span><b>{{ $r->start_at->format('H:i') }}</b></div>
      <div class="kv"><span>Guests</span><b>{{ $r->party_size }}</b></div>
      @if($r->occasion)<div class="kv"><span>Occasion</span><b>{{ \App\Models\TableReservation::OCCASIONS[$r->occasion] ?? $r->occasion }}</b></div>@endif
      @if($r->status === 'booked')<div class="alert alert-info mt-1"><x-icon name="clock"/><div>We have your request and will confirm it shortly.</div></div>@endif
      @if($r->isUpcoming())<p class="small muted mt-1">Running late? We keep your table for {{ \App\Services\TableBookingService::cfg($r->outlet)['hold_minutes'] }} minutes. To change the booking, please call us{{ $hotel->phone ? ' on '.$hotel->phone : '' }}.</p>@endif
      @if($r->status === 'cancelled')<p class="muted">This booking was cancelled. <a href="{{ route('dine.show', $hotel->code) }}">Book another table</a>.</p>@endif
    @endif
  </div>
  @if($canCancel)
  <div class="card-foot"><form method="post" action="{{ route('dine.cancel', $token) }}" id="cancelForm" onsubmit="return confirm('Cancel this booking?')">@csrf
    <div class="flex" style="gap:.5rem;flex-wrap:wrap"><input name="reason" placeholder="Reason (optional)" maxlength="200" class="input grow" style="flex:1 1 220px"><button class="btn btn-danger" id="guestCancel">Cancel my booking</button></div>
  </form></div>
  @endif
</div>
@endsection
