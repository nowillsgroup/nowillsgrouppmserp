@extends('layouts.print')
@section('title', 'Trip sheet '.$t->trip_no)
@section('doctitle', 'Trip Sheet')
@section('docmeta')<div class="small"><b>{{ $t->trip_no }}</b></div><div class="small">{{ $t->typeLabel() }} · {{ $t->scheduled_at->format('D d M Y') }}</div>@endsection
@section('content')
<table class="table" style="margin-top:1rem">
  <tr><th style="width:30%">{{ $t->type === 'pickup' ? 'Flight / train lands' : 'Leave the hotel at' }}</th><td><b style="font-size:1.2rem">{{ $t->scheduled_at->format('H:i') }}</b> · {{ $t->scheduled_at->format('D d M Y') }}</td></tr>
  @if($t->type === 'pickup')<tr><th>Leave the hotel by</th><td><b>{{ $t->busy_from->format('H:i') }}</b></td></tr>@endif
  @if($t->flight_no)<tr><th>Flight / train</th><td><b>{{ $t->flight_no }}</b></td></tr>@endif
  <tr><th>Passenger</th><td><b>{{ $t->passenger_name }}</b> · {{ $t->pax }} pax · {{ $t->bags }} bags @if($t->stay?->room)· Room {{ $t->stay->room->room_no }}@endif</td></tr>
  <tr><th>Guest mobile</th><td>{{ $t->phone ?: '—' }}</td></tr>
  <tr><th>From</th><td>{{ $t->pickup_point }}</td></tr>
  <tr><th>To</th><td>{{ $t->dropoff_point }}</td></tr>
  <tr><th>Vehicle</th><td>{{ $t->vehicle ? $t->vehicle->code.' · '.$t->vehicle->name.' · '.$t->vehicle->reg_no : '—' }}</td></tr>
  <tr><th>Driver</th><td>{{ $t->driver ? $t->driver->name.' · '.$t->driver->phone : '—' }}</td></tr>
  <tr><th>Billing</th><td>{{ \App\Models\TransportTrip::BILLING[$t->billing] }}@if($t->billing === 'direct') – collect {{ money($t->total) }} + tax @endif</td></tr>
  @if($t->notes)<tr><th>Notes</th><td>{{ $t->notes }}</td></tr>@endif
  <tr><th>Odometer out / in</th><td>{{ $t->odo_start !== null ? number_format($t->odo_start) : '________' }} km &nbsp; / &nbsp; {{ $t->odo_end !== null ? number_format($t->odo_end) : '________' }} km</td></tr>
  <tr><th>Waiting / tolls / parking</th><td>____________________________</td></tr>
  <tr><th>Guest signature</th><td style="height:3rem"></td></tr>
</table>
<div class="name-board" id="nameBoard"><div class="who">{{ $t->nameBoard() }}</div><div class="hotel">{{ $currentHotel->name }}</div></div>
@endsection
