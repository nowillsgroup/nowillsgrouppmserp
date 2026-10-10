@extends('layouts.print')
@section('title', 'Reservation day summary')
@section('doctitle', 'Reservation day summary')
@section('docmeta')
  <div class="small">{{ $date->format('l, d M Y') }} · business date {{ bdate()->format('d M Y') }}</div>
  <div class="small">Printed {{ now()->format('d M Y H:i') }} by {{ auth()->user()->name }}</div>
@endsection
@section('content')
@php
  $pax = (int) $inhouse->sum(fn ($s) => (int) $s->adults + (int) $s->children);
@endphp
<p class="small">Live reservation data. Balance-to-settle figures are the checkout quote and are not posted by printing.</p>

<h3>Summary</h3>
<table class="table">
  <thead><tr><th>Occupancy</th><th>Arrivals</th><th>Departures</th><th>In-house guests</th><th>On the books (14 days)</th></tr></thead>
  <tbody><tr>
    <td>{{ $kpis['sold'] }} of {{ $kpis['sellable'] }} sellable ({{ $kpis['occupancy'] === null ? '—' : $kpis['occupancy'].'%' }})</td>
    <td>{{ $kpis['arrivals'] }}</td>
    <td>{{ $kpis['due_out'] }}</td>
    <td>{{ $pax }}</td>
    <td>{{ money($onTheBooks['days14']) }}</td>
  </tr></tbody>
</table>

<h3>Expected arrivals — {{ $date->format('d M Y') }}</h3>
<table class="table">
  <thead><tr><th>Reservation</th><th>Guest</th><th>Room</th><th>ETA</th><th>Check-in status</th></tr></thead>
  <tbody>
  @forelse($arrivals as $s)
    <tr>
      <td>{{ $s->reservation->reservation_no }}</td>
      <td>{{ $s->reservation->guest->full_name }}@if($s->reservation->guest->is_vip) (VIP)@endif</td>
      <td>{{ $s->room->room_no ?? 'Unassigned' }}</td>
      <td>{{ $s->reservation->eta ? substr((string) $s->reservation->eta, 0, 5) : '—' }}</td>
      <td class="small">{{ \App\Services\StayService::checkInReady($s) }}</td>
    </tr>
  @empty
    <tr><td colspan="5">No arrivals on file.</td></tr>
  @endforelse
  </tbody>
</table>

<h3>Due departures — {{ $date->format('d M Y') }}</h3>
<table class="table">
  <thead><tr><th>Reservation</th><th>Guest</th><th>Room</th><th>Balance to settle (not posted)</th><th>Holds</th></tr></thead>
  <tbody>
  @forelse($departures as $s)
    @php
      $due = $s->status === 'checked_in' ? \App\Services\StayService::quoteCheckoutFigures($s)['due'] : null;
      $holds = $s->status === 'checked_in' ? \App\Services\StayService::checkoutHolds($s) : null;
    @endphp
    <tr>
      <td>{{ $s->reservation->reservation_no }}</td>
      <td>{{ $s->reservation->guest->full_name }}</td>
      <td>{{ $s->room->room_no ?? '—' }}</td>
      <td>{{ $s->status !== 'checked_in' ? 'Checked out' : ($due === null ? 'No folio' : money($due)) }}</td>
      <td class="small">{{ $s->status !== 'checked_in' ? '—' : (($holds['trips']['count'] || $holds['activities']['count']) ? 'Checkout blocked' : 'No hold on file') }}</td>
    </tr>
  @empty
    <tr><td colspan="5">No departures on file.</td></tr>
  @endforelse
  </tbody>
</table>

@if($inhouse->isNotEmpty())
<h3>In house</h3>
<table class="table">
  <thead><tr><th>Reservation</th><th>Guest</th><th>Room</th><th>Departure</th></tr></thead>
  <tbody>
  @foreach($inhouse as $s)
    <tr><td>{{ $s->reservation->reservation_no }}</td><td>{{ $s->reservation->guest->full_name }}</td><td>{{ $s->room->room_no ?? 'Unassigned' }}</td><td>{{ $s->departure_date->format('d M Y') }}</td></tr>
  @endforeach
  </tbody>
</table>
@endif

@if($unassigned->isNotEmpty())
<h3>Unassigned</h3>
<ul>
  @foreach($unassigned as $s)<li>{{ $s->reservation->reservation_no }} · {{ $s->reservation->guest->full_name }} · {{ $s->roomType->name }} · arrives {{ $s->arrival_date->format('d M Y') }}</li>@endforeach
</ul>
@endif

<h3>Next 7 days</h3>
<table class="table">
  <thead><tr><th>Day</th><th>Arrivals</th><th>Departures</th><th>Rooms expected</th><th>Room revenue (pre-tax)</th></tr></thead>
  <tbody>
  @foreach(array_slice($pipeline, 0, 7) as $p)
    <tr><td>{{ $p['date']->format('l, d M') }}</td><td>{{ $p['arrivals'] }}</td><td>{{ $p['departures'] }}</td><td>{{ $p['rooms'] }}</td><td>{{ money($p['revenue']) }}</td></tr>
  @endforeach
  </tbody>
</table>
@endsection
