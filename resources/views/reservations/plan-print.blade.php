@extends('layouts.print')
@section('title', 'Room plan')
@section('doctitle', 'Room plan')
@section('docmeta')
  <div class="small">{{ $date->format('d M Y') }} · {{ $days }} days</div>
  <div class="small">Printed {{ now()->format('d M Y H:i') }}</div>
@endsection
@section('content')
<p class="small">Live rooms and stays. A room with no photo on file is listed by number only.</p>
<table class="table">
  <thead><tr><th>Room</th><th>Type</th><th>HK</th><th>Status</th><th>Stays in this window</th></tr></thead>
  <tbody>
  @foreach($rooms as $room)
    <tr>
      <td>{{ $room->room_no }}</td>
      <td>{{ $room->roomType->name }}</td>
      <td>{{ $room->hk_status }}</td>
      <td>{{ $room->status }}</td>
      <td class="small">
        @forelse($byRoom[$room->id] ?? [] as $s)
          {{ $s->reservation->guest->full_name }} ({{ $s->reservation->reservation_no }}, {{ \App\Services\ReservationDeskService::stayLabel($s, $date) }}) {{ $s->arrival_date->format('d M') }}–{{ $s->departure_date->format('d M') }}@if(!$loop->last); @endif
        @empty
          —
        @endforelse
      </td>
    </tr>
  @endforeach
  </tbody>
</table>
@if($loose->isNotEmpty())
<h3>Unassigned</h3>
<ul>
  @foreach($loose as $s)<li>{{ $s->reservation->reservation_no }} · {{ $s->reservation->guest->full_name }} · {{ $s->roomType->name }} · {{ $s->arrival_date->format('d M') }}–{{ $s->departure_date->format('d M') }}</li>@endforeach
</ul>
@endif
@endsection
