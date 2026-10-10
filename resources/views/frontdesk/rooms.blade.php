@extends('layouts.app')
@section('title', 'Room View')
@section('crumbs') Front Office / Room View @endsection
@section('actions')
<form class="filters" method="get">
  <div class="field"><select name="type" data-autosubmit><option value="">All room types</option>@foreach($types as $id => $n)<option value="{{ $id }}" @selected(request('type') == $id)>{{ $n }}</option>@endforeach</select></div>
  <div class="field"><select name="status" data-autosubmit><option value="">All statuses</option>@foreach(['vacant'=>'Vacant','occupied'=>'Occupied','reserved'=>'Arriving','due_out'=>'Due out','out_of_order'=>'Out of order','dirty'=>'Dirty','clean'=>'Clean'] as $k=>$v)<option value="{{ $k }}" @selected(request('status')==$k)>{{ $v }}</option>@endforeach</select></div>
</form>
<a href="{{ route('frontdesk.tapechart') }}" class="btn"><x-icon name="calendar"/> Tape Chart</a>
@endsection
@section('content')
<div class="card"><div class="card-body flex justify-between wrap gap-2 items-center">
  <div class="legend">
    <span><i style="background:#86efac"></i>Vacant ({{ $counts['vacant'] ?? 0 }})</span>
    <span><i style="background:#fca5a5"></i>Occupied ({{ $counts['occupied'] ?? 0 }})</span>
    <span><i style="background:#93c5fd"></i>Arriving ({{ $counts['reserved'] ?? 0 }})</span>
    <span><i style="background:#fcd34d"></i>Due out ({{ $counts['due_out'] ?? 0 }})</span>
    <span><i style="background:#cbd5e1"></i>Out of order ({{ ($counts['out_of_order'] ?? 0) + ($counts['out_of_service'] ?? 0) }})</span>
  </div>
  <div class="legend">
    <span><i class="hk-clean" style="border-radius:50%"></i>Clean ({{ $hk['clean'] ?? 0 }})</span>
    <span><i class="hk-dirty" style="border-radius:50%"></i>Dirty ({{ $hk['dirty'] ?? 0 }})</span>
    <span><i class="hk-inspected" style="border-radius:50%"></i>Inspected ({{ $hk['inspected'] ?? 0 }})</span>
  </div>
</div></div>

@foreach($floors as $floor => $rooms)
<div class="floor-block">
  <div class="floor-title">{{ $floor }}</div>
  <div class="rooms">
    @foreach($rooms as $room)
      @php $stay = $room->currentStay; @endphp
      <div class="room st-{{ $room->view_status }}" data-dropdown="rm{{ $room->id }}" tabindex="0">
        <span class="hk hk-{{ $room->hk_status }}" title="{{ ucfirst($room->hk_status) }}"></span>
        <div class="no">{{ $room->room_no }}</div>
        <div class="type">{{ $room->roomType->name }}</div>
        @if($stay)
          <div class="guest" title="{{ $stay->reservation->guest->full_name }}">{{ $stay->reservation->guest->full_name }}</div>
          <div class="tiny muted">Out: {{ $stay->departure_date->format('d M') }}</div>
        @elseif(isset($room->arrival))
          <div class="guest">{{ $room->arrival->reservation->guest->full_name }}</div><div class="tiny muted">Arriving today</div>
        @else
          <div class="guest muted" style="font-weight:500">{{ ucwords(str_replace('_',' ', $room->status)) }}</div>
        @endif
        <div class="dropdown room-menu" id="rm{{ $room->id }}">
          @if($stay)
            <a href="{{ route('reservations.show', $stay->reservation_id) }}"><x-icon name="eye" size="sm"/> View reservation</a>
            @if($stay->folio)<a href="{{ route('folios.show', $stay->folio) }}"><x-icon name="receipt" size="sm"/> Folio / Post charge</a>@endif
            @if(can_do('frontdesk.roommove'))<a href="{{ route('frontdesk.move', $stay) }}"><x-icon name="swap" size="sm"/> Room move / Amend</a>@endif
            @if(can_do('frontdesk.checkout'))<a href="{{ route('frontdesk.checkout', $stay) }}"><x-icon name="logout" size="sm"/> Check-out</a>@endif
            <a href="{{ route('frontdesk.grc', $stay) }}" target="_blank"><x-icon name="print" size="sm"/> Print GRC</a>
            @if(can_do('frontdesk.checkin'))<a href="{{ route('frontdesk.grc.sign', $stay) }}"><x-icon name="edit" size="sm"/> e-GRC sign / ID</a>@endif
          @elseif(isset($room->arrival))
            @if(can_do('frontdesk.checkin'))<a href="{{ route('frontdesk.checkin', $room->arrival) }}"><x-icon name="login" size="sm"/> Check-in {{ $room->arrival->reservation->reservation_no }}</a>@endif
          @elseif($room->status === 'vacant')
            @if(can_do('frontdesk.checkin'))<a href="{{ route('frontdesk.walkin', ['room_id' => $room->id]) }}"><x-icon name="login" size="sm"/> Walk-in check-in</a>@endif
            @if(can_do('reservations.create'))<a href="{{ route('reservations.create', ['room_type_id' => $room->room_type_id, 'room_id' => $room->id]) }}"><x-icon name="calendar" size="sm"/> New reservation</a>@endif
          @endif
          @if(can_do('housekeeping.manage'))
            <hr style="margin:.3rem 0">
            @foreach(['clean' => 'Mark clean', 'dirty' => 'Mark dirty', 'inspected' => 'Mark inspected'] as $k => $v)
              @if($room->hk_status !== $k)<form method="post" action="{{ route('housekeeping.status') }}">@csrf<input type="hidden" name="rooms[]" value="{{ $room->id }}"><input type="hidden" name="hk_status" value="{{ $k }}"><button><x-icon name="broom" size="sm"/> {{ $v }}</button></form>@endif
            @endforeach
            @if($room->status === 'vacant')<form method="post" action="{{ route('housekeeping.status') }}">@csrf<input type="hidden" name="rooms[]" value="{{ $room->id }}"><input type="hidden" name="status" value="out_of_order"><button><x-icon name="tool" size="sm"/> Set out of order</button></form>@endif
            @if(in_array($room->status, ['out_of_order','out_of_service']))<form method="post" action="{{ route('housekeeping.status') }}">@csrf<input type="hidden" name="rooms[]" value="{{ $room->id }}"><input type="hidden" name="status" value="vacant"><button><x-icon name="check" size="sm"/> Return to service</button></form>@endif
          @endif
        </div>
      </div>
    @endforeach
  </div>
</div>
@endforeach
@endsection
