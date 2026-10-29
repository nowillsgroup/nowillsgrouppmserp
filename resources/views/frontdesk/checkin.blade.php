@extends('layouts.app')
@section('title', 'Check-in · '.$stay->reservation->reservation_no)
@section('crumbs') @if(can_do('frontdesk.view'))<a href="{{ route('frontdesk.arrivals') }}">Arrivals</a>@elseif(can_do('reservations.view'))<a href="{{ route('reservations.workspace') }}">Reservation Workspace</a>@else<span>Arrivals</span>@endif / Check-in @endsection
@section('content')
@if($stay->reservation->prechecked_in_at)<div class="alert alert-success"><x-icon name="check"/><div><b>Guest checked in online</b> on {{ $stay->reservation->prechecked_in_at->format('d M Y H:i') }}{{ $stay->reservation->eta ? ' (ETA '.substr($stay->reservation->eta,0,5).')' : '' }}. The details below were confirmed by the guest, and the ID scan and e-signature are on file.@if($stay->grc_signed_at) The registration card is already signed.@endif</div></div>@endif
<form id="checkInForm" method="post" action="{{ route('frontdesk.checkin.store', $stay) }}">@csrf
<div class="grid g-3-1">
  <div>
    <div class="card"><div class="card-head"><h3>Room Assignment</h3>{!! status_badge($stay->reservation->status) !!}</div>
      <div class="card-body form-grid">
        <div class="field"><label>Booked type</label><input readonly value="{{ $stay->roomType->name }} · {{ $stay->ratePlan?->name ?? 'Rack' }}"></div>
        <div class="field"><label>Stay</label><input readonly value="{{ bdate()->format('d M') }} → {{ $stay->departure_date->format('d M Y') }}"></div>
        <div class="field"><label for="checkinRoom">Room <span class="req">*</span></label>
          @php
            $selectedRoomId = old('room_id', $stay->room_id);
            if (! is_scalar($selectedRoomId)) $selectedRoomId = null;
            $hasSelectedRoom = $rooms->contains(fn ($room) => (string) $room->id === (string) $selectedRoomId);
            $hasAssignedRoom = $stay->room_id && $rooms->contains(fn ($room) => (string) $room->id === (string) $stay->room_id);
            $roomWasSubmitted = old('room_id') !== null;
            $roomSelectionIssue = $rooms->isEmpty() || ($roomWasSubmitted && ! $hasSelectedRoom) || ($stay->room_id && ! $hasAssignedRoom);
          @endphp
          <select id="checkinRoom" name="room_id" required @if($roomSelectionIssue) aria-describedby="checkinRoomStatus" @endif>
            @if (! $hasSelectedRoom)<option value="" disabled selected>Choose a room…</option>@endif
            @foreach($rooms as $r)
              @php $roomReadinessLabel = match ($r->hk_status) { 'inspected' => ' · Inspected', 'clean' => ' · Clean', 'dirty' => ' · ⚠ Dirty', 'touch_up' => ' · ⚠ Touch-up', default => '' }; @endphp
              <option value="{{ $r->id }}" data-room-no="{{ $r->room_no }}" data-hk-status="{{ $r->hk_status }}" @selected((string) $selectedRoomId === (string) $r->id)>{{ $r->room_no }} – {{ $r->roomType->name }}{{ $r->room_type_id !== $stay->room_type_id ? ' (upgrade/change)' : '' }}{{ $roomReadinessLabel }}</option>
            @endforeach
          </select>
          @if($rooms->isEmpty())<span id="checkinRoomStatus" class="err" role="status">No vacant rooms are available for this stay. Review availability before checking in.</span>
          @elseif($stay->room_id && ! $hasAssignedRoom)<span id="checkinRoomStatus" class="err" role="status">The previously assigned room is no longer available. Choose another room.</span>
          @elseif($roomWasSubmitted && ! $hasSelectedRoom)<span id="checkinRoomStatus" class="err" role="status">{{ $selectedRoomId ? 'The selected room is no longer available. Choose another room.' : 'Choose a room to continue.' }}</span>@endif
        </div>
        <x-field name="rate" label="Rate / night" type="number" step="0.01" :value="$stay->rate" required/>
        <div class="field"><label>Pax</label><input readonly value="{{ $stay->adults }} adults, {{ $stay->children }} children"></div>
      </div></div>
    <div class="card"><div class="card-head"><h3>Guest Registration (KYC)</h3></div><div class="card-body">@include('reservations._guest')</div></div>
  </div>
  <div>
    @if($stay->reservation->special_request)<div class="alert alert-info"><x-icon name="bell"/><div>{{ $stay->reservation->special_request }}</div></div>@endif
    <div class="card"><div class="card-head"><h3>Deposit</h3></div><div class="card-body">
      <div id="checkinAdvanceSingle">
        <x-field name="advance" label="Amount" type="number" step="0.01" min="0" value="0"/><div class="mt-1"></div>
        <x-field name="advance_mode" label="Mode" type="select" :options="$paymentModes" data-payment-mode/><div class="mt-1"></div>
        <x-field name="advance_ref" label="Reference"/>
        <x-cheque-fields/>
      </div>
      <x-payment-split-tender widget-id="checkinAdvanceSplit" single-id="checkinAdvanceSingle" :modes="$paymentModes" label="deposit"/>
    </div></div>
    <button class="btn btn-success btn-lg btn-block" @disabled($rooms->isEmpty())><x-icon name="login"/> Complete Check-in</button>
  </div>
</div>
</form>
<script>
  document.getElementById('checkInForm')?.addEventListener('submit', event => {
    const form = event.currentTarget;
    const option = form.querySelector('#checkinRoom option:checked');
    const status = option?.dataset.hkStatus;
    if (!['dirty', 'touch_up'].includes(status)) {
      delete form.dataset.confirm;
      return;
    }
    const label = status === 'touch_up' ? 'Touch-up' : 'Dirty';
    form.dataset.confirm = `Room ${option.dataset.roomNo} is ${label}. Check-in will not clean the room. Continue?`;
  });
</script>
@if(can_do('upsell.sell'))@include('upsell._checkin')@endif
@endsection
