@extends('layouts.app')
@section('title', 'Check-in · '.$stay->reservation->reservation_no)
@section('crumbs') <a href="{{ route('frontdesk.arrivals') }}">Arrivals</a> / Check-in @endsection
@section('content')
@if($stay->reservation->prechecked_in_at)<div class="alert alert-success"><x-icon name="check"/><div><b>Guest checked in online</b> on {{ $stay->reservation->prechecked_in_at->format('d M Y H:i') }}{{ $stay->reservation->eta ? ' (ETA '.substr($stay->reservation->eta,0,5).')' : '' }}. The details below were confirmed by the guest, and the ID scan and e-signature are on file.@if($stay->grc_signed_at) The registration card is already signed.@endif</div></div>@endif
<form method="post" action="{{ route('frontdesk.checkin.store', $stay) }}">@csrf
<div class="grid g-3-1">
  <div>
    <div class="card"><div class="card-head"><h3>Room Assignment</h3>{!! status_badge($stay->reservation->status) !!}</div>
      <div class="card-body form-grid">
        <div class="field"><label>Booked type</label><input readonly value="{{ $stay->roomType->name }} · {{ $stay->ratePlan?->name ?? 'Rack' }}"></div>
        <div class="field"><label>Stay</label><input readonly value="{{ bdate()->format('d M') }} → {{ $stay->departure_date->format('d M Y') }}"></div>
        <div class="field"><label>Room <span class="req">*</span></label>
          <select name="room_id" required>
            @foreach($rooms as $r)<option value="{{ $r->id }}" @selected(old('room_id', $stay->room_id) == $r->id)>{{ $r->room_no }} – {{ $r->roomType->name }}{{ $r->room_type_id !== $stay->room_type_id ? ' (upgrade/change)' : '' }}{{ $r->hk_status === 'dirty' ? ' ⚠ dirty' : '' }}</option>@endforeach
          </select>
          @if($rooms->isEmpty())<span class="err">No vacant rooms available.</span>@endif
        </div>
        <x-field name="rate" label="Rate / night" type="number" step="0.01" :value="$stay->rate" required/>
        <div class="field"><label>Pax</label><input readonly value="{{ $stay->adults }} adults, {{ $stay->children }} children"></div>
      </div></div>
    <div class="card"><div class="card-head"><h3>Guest Registration (KYC)</h3></div><div class="card-body">@include('reservations._guest')</div></div>
  </div>
  <div>
    @if($stay->reservation->special_request)<div class="alert alert-info"><x-icon name="bell"/><div>{{ $stay->reservation->special_request }}</div></div>@endif
    <div class="card"><div class="card-head"><h3>Deposit</h3></div><div class="card-body">
      <x-field name="advance" label="Amount" type="number" step="0.01" min="0" value="0"/><div class="mt-1"></div>
      <x-field name="advance_mode" label="Mode" type="select" :options="$paymentModes" data-payment-mode/><div class="mt-1"></div>
      <x-field name="advance_ref" label="Reference"/>
      <x-cheque-fields/>
    </div></div>
    <button class="btn btn-success btn-lg btn-block" @disabled($rooms->isEmpty())><x-icon name="login"/> Complete Check-in</button>
  </div>
</div>
</form>
@if(can_do('upsell.sell'))@include('upsell._checkin')@endif
@endsection
