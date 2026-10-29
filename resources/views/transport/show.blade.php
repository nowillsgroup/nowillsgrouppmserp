@extends('layouts.app')
@section('title', $t->trip_no)
@section('crumbs') <a href="{{ route('tr.index', ['date' => $t->scheduled_at->toDateString()]) }}">Transport</a> / {{ $t->trip_no }} @endsection
@section('actions')
<a class="btn" href="{{ route('tr.index', ['date' => $t->scheduled_at->toDateString()]) }}"><x-icon name="calendar"/> Dispatch board</a>
<a class="btn" href="{{ route('tr.sheet', $t) }}" id="sheetBtn"><x-icon name="print"/> Trip sheet &amp; name board</a>
@endsection
@section('content')
@php $canBook = can_do('transport.book'); @endphp
@include('transport._clash')
<div class="grid g-2-1">
<div>
  <div class="card" id="trHead"><div class="card-body">
    <div class="flex items-center" style="gap:.6rem;flex-wrap:wrap">
      <h2 style="margin:0">{{ $t->passenger_name }}</h2> {!! tr_badge($t->status) !!}
      <span class="badge badge-slate">{{ $t->typeLabel() }}</span>
      @if($t->stay?->room)<span class="badge badge-purple">Room {{ $t->stay->room->room_no }}</span>@elseif($t->stay)<span class="badge badge-blue">Arriving {{ $t->stay->arrival_date->format('d M') }}</span>@endif
      @if($t->guest?->is_vip)<span class="badge badge-amber">VIP</span>@endif
      <span class="badge badge-slate">{{ \App\Models\TransportTrip::SOURCES[$t->source] ?? $t->source }}</span>
    </div>
    <div class="tr-route mt-1">{{ $t->pickup_point }} <span class="arrow">→</span> {{ $t->dropoff_point }}</div>
    <div class="kv-grid mt-2">
      <div class="kv"><span>Trip</span><b>{{ $t->trip_no }}</b></div>
      <div class="kv"><span>{{ $t->type === 'pickup' ? 'Flight / train lands' : 'Leaves the hotel' }}</span><b>{{ $t->scheduled_at->format('D d M Y, H:i') }}</b></div>
      <div class="kv"><span>Car &amp; driver busy</span><b>{{ $t->busy_from->format('H:i') }} – {{ $t->busy_to->format('H:i') }} <span class="muted small">({{ $t->duration_min }} min)</span></b></div>
      @if($t->flight_no)<div class="kv"><span>Flight / train</span><b><x-icon name="plane" size="sm"/> {{ $t->flight_no }}</b></div>@endif
      <div class="kv"><span>Passengers · bags</span><b>{{ $t->pax }} · {{ $t->bags }}</b></div>
      <div class="kv"><span>Mobile</span><b>{{ $t->phone ?: '—' }}</b></div>
      <div class="kv"><span>Vehicle</span><b id="trVehicle">{{ $t->vehicle ? $t->vehicle->code.' · '.$t->vehicle->name.' · '.$t->vehicle->reg_no : 'not assigned' }}</b></div>
      <div class="kv"><span>Driver</span><b id="trDriver">{{ $t->driver ? $t->driver->name.' · '.$t->driver->phone : 'not assigned' }}</b></div>
      @if($t->vehicle_type)<div class="kv"><span>Vehicle wanted</span><b>{{ \App\Models\Vehicle::TYPES[$t->vehicle_type] ?? $t->vehicle_type }}</b></div>@endif
      @if($t->reservation)<div class="kv"><span>Reservation</span><b>@if(can_do('reservations.view'))<a href="{{ route('reservations.show', $t->reservation) }}">{{ $t->reservation->reservation_no }}</a>@else{{ $t->reservation->reservation_no }}@endif</b></div>@endif
      @if($t->guestRequest)<div class="kv"><span>Guest request</span><b>{{ $t->guestRequest->request_no }} · {{ \App\Models\GuestRequest::STATUSES[$t->guestRequest->status] }}</b></div>@endif
      @if($t->started_at)<div class="kv"><span>Left</span><b>{{ $t->started_at->format('H:i') }}@if($t->lateMinutes() > 5) <span class="text-amber">({{ $t->lateMinutes() }} min late)</span>@endif @if($t->odo_start !== null)· {{ number_format($t->odo_start) }} km @endif</b></div>@endif
      @if($t->onboard_at)<div class="kv"><span>Guest on board</span><b>{{ $t->onboard_at->format('H:i') }}</b></div>@endif
      @if($t->completed_at && $t->status === 'completed')<div class="kv"><span>Completed</span><b>{{ $t->completed_at->format('d M H:i') }}@if($t->km() !== null) · {{ $t->km() }} km @endif</b></div>@endif
      @if($t->cancel_reason)<div class="kv"><span>Reason</span><b>{{ $t->cancel_reason }}</b></div>@endif
    </div>
    @if($t->notes)<div class="alert alert-info mt-1"><x-icon name="message"/><div>{{ $t->notes }}</div></div>@endif
  </div>
  @if($canBook)
  <div class="card-foot flex" style="gap:.4rem;flex-wrap:wrap" id="trActions">
    @if($t->status === 'requested')<form method="post" action="{{ route('tr.confirm', $t) }}">@csrf<button class="btn btn-primary" id="confirmBtn"><x-icon name="check"/> Confirm request</button></form>@endif
    @if($t->isPlanned())<button class="btn {{ $t->vehicle_id ? '' : 'btn-primary' }}" data-assign="{{ route('tr.options', $t) }}" id="assignBtn"><x-icon name="car"/> {{ $t->vehicle_id ? 'Change car / driver' : 'Assign car & driver' }}</button>@endif
    @if($t->status === 'assigned')<button class="btn btn-success" data-modal="startModal" id="startBtn"><x-icon name="car"/> Car has left</button>@endif
    @if($t->status === 'en_route')<form method="post" action="{{ route('tr.onboard', $t) }}">@csrf<button class="btn btn-success" id="onboardBtn"><x-icon name="users"/> Guest on board</button></form>@endif
    @if(in_array($t->status, ['assigned', 'en_route', 'onboard']))<button class="btn btn-primary" data-modal="completeModal" id="completeBtn"><x-icon name="check"/> Complete</button>@endif
    @if($t->isPlanned())<button class="btn" data-modal="editModal" id="editBtn"><x-icon name="edit"/> Change</button>@endif
    @if($t->status === 'assigned')<form method="post" action="{{ route('tr.unassign', $t) }}">@csrf<button class="btn btn-ghost" id="unassignBtn">Un-assign</button></form>@endif
    @if($t->isOpen())<form method="post" action="{{ route('tr.resend', $t) }}">@csrf<input type="hidden" name="to" value="guest"><button class="btn btn-ghost" id="resendGuest"><x-icon name="message"/> Re-send to guest</button></form>@endif
    @if($t->isOpen() && $t->driver_id)<form method="post" action="{{ route('tr.resend', $t) }}">@csrf<input type="hidden" name="to" value="driver"><button class="btn btn-ghost" id="resendDriver"><x-icon name="phone"/> Re-send to driver</button></form>@endif
    @if($t->unbilled() && $t->stay && in_array($t->stay->status, ['reserved', 'checked_in']))<form method="post" action="{{ route('tr.charge', $t) }}">@csrf<button class="btn btn-ghost" id="chargeBtn"><x-icon name="receipt"/> Post fare to room bill now</button></form>@endif
    @if(in_array($t->status, ['confirmed', 'assigned', 'en_route', 'onboard']) && $t->busy_from->lte(now()->addMinutes(30)))<button class="btn btn-ghost" data-modal="noShowModal" id="noShowBtn">No-show</button>@endif
    @if($t->isPlanned())<button class="btn btn-danger ml-auto" data-modal="cancelModal" id="cancelBtn"><x-icon name="x"/> Cancel trip</button>@endif
  </div>
  @endif
  </div>

  @if($suggest && !$t->vehicle_id && $canBook)
  <div class="card"><div class="card-head"><h3>Free right now for this trip</h3></div><div class="card-body small" id="trSuggest">
    @if($suggest['vehicle'] && $suggest['driver'])
      Best fit: <b>{{ $suggest['vehicle']->code }}</b> ({{ $suggest['vehicle']->name }}, {{ $suggest['vehicle']->seats }} seats) with <b>{{ $suggest['driver']->name }}</b>.
      <form method="post" action="{{ route('tr.assign', $t) }}" class="inline">@csrf<input type="hidden" name="vehicle_id" value="{{ $suggest['vehicle']->id }}"><input type="hidden" name="driver_id" value="{{ $suggest['driver']->id }}"><input type="hidden" name="notify" value="1">
        <button class="btn btn-sm btn-primary" id="quickAssign">Assign these</button></form>
      <div class="tiny muted mt-1">{{ $suggest['vehicles']->count() }} vehicle(s) and {{ $suggest['drivers']->count() }} driver(s) are free {{ $t->busy_from->format('H:i') }}–{{ $t->busy_to->format('H:i') }}.</div>
    @else
      <span class="text-red">No {{ $suggest['vehicle'] ? 'driver' : 'vehicle' }} is free {{ $t->busy_from->format('H:i') }}–{{ $t->busy_to->format('H:i') }} for {{ $t->pax }} passengers.</span> Move another trip, hire a car, or assign anyway from the dialog.
    @endif
  </div></div>
  @endif
</div>

<div>
  <div class="card" id="trBilling"><div class="card-head"><h3>Fare &amp; billing</h3></div><div class="card-body">
    <div class="kv"><span>Tariff</span><b>{{ $t->tariff?->name ?? 'Custom fare' }}</b></div>
    <div class="kv"><span>Fare</span><b>{{ money($t->amount) }}</b></div>
    @if((float) $t->extra_amount > 0)<div class="kv"><span>Extras</span><b>{{ money($t->extra_amount) }}</b></div>@if($t->extra_note)<div class="tiny muted">{{ $t->extra_note }}</div>@endif @endif
    <div class="kv"><span>Total (before tax)</span><b>{{ money($t->total) }}</b></div>
    <div class="kv"><span>Billing</span><b>{{ \App\Models\TransportTrip::BILLING[$t->billing] }}</b></div>
    @if($t->folio)<div class="kv"><span>{{ $t->billing === 'room' ? 'On bill' : 'Invoice' }}</span><b>@if(can_do('folio.view'))<a href="{{ route('folios.show', $t->folio) }}">{{ $t->folio->folio_no }}</a>@else{{ $t->folio->folio_no }}@endif {{ $t->folio->invoice_no ? '· '.$t->folio->invoice_no : '' }}</b></div>@endif
    @if($t->paymentMode)<div class="kv"><span>Paid by</span><b>{{ $t->paymentMode->name }}</b></div>@endif
    @if($t->unbilled())<p class="tiny text-amber mt-1" id="unbilledNote">Not on the room bill yet – it posts when the trip is completed, or at check-out.</p>@endif
  </div></div>
  <div class="card"><div class="card-head"><h3>Messages</h3></div><div class="card-body">
    @forelse($msgs as $m)<div class="small mb-1"><span class="badge {{ $m->status === 'sent' ? 'badge-green' : 'badge-red' }}">{{ $m->status }}</span> <b>{{ str_starts_with($m->template, 'transport_driver') ? 'Driver' : 'Guest' }}</b> · {{ $m->created_at->format('d M H:i') }}<div class="tiny muted">{{ \Illuminate\Support\Str::limit($m->body, 160) }}</div></div>
    @empty<p class="small muted m-0">Nothing sent yet.</p>@endforelse
  </div></div>
  <div class="card"><div class="card-head"><h3>Activity</h3></div><div class="card-body"><ul class="timeline" id="trLog">
    <li class="tl-info"><b>Booked</b> <span class="muted small">· {{ $t->created_at->format('d M H:i') }}{{ $t->creator ? ' · '.$t->creator->name : ($t->source === 'portal' ? ' · guest portal' : '') }}</span></li>
    @foreach($log->where('action', '!=', 'book') as $l)<li class="{{ in_array($l->action, ['cancel', 'no_show']) ? 'tl-bad' : (in_array($l->action, ['complete', 'assign', 'confirm']) ? 'tl-ok' : 'tl-info') }}"><b>{{ ucfirst(str_replace('_', ' ', $l->action)) }}</b> <span class="muted small">· {{ $l->created_at->format('d M H:i') }}{{ $l->user ? ' · '.$l->user->name : '' }}</span><div class="small">{{ $l->description }}</div></li>@endforeach
  </ul></div></div>
</div>
</div>

@if($canBook)
<div class="modal" id="startModal"><div class="modal-box"><form method="post" action="{{ route('tr.start', $t) }}">@csrf
  <div class="modal-head"><h3>{{ $t->vehicle?->code }} is leaving</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="odo_start" label="Odometer (km)" type="number" :value="$t->vehicle?->odometer" hint="last known reading pre-filled"/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-success" id="confirmStart">Car has left</button></div>
</form></div></div>
<div class="modal" id="completeModal"><div class="modal-box"><form method="post" action="{{ route('tr.complete', $t) }}">@csrf
  <div class="modal-head"><h3>Complete {{ $t->trip_no }}</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    @if($t->odo_start === null)<x-field name="odo_start" label="Opening odometer" type="number" :value="$t->vehicle?->odometer"/>@endif
    <x-field name="odo_end" label="Closing odometer (km)" type="number" :hint="$t->tariff && (float) $t->tariff->per_km > 0 ? $t->tariff->included_km.' km included, then '.money($t->tariff->per_km).'/km – added automatically' : null"/>
    <x-field name="extra_amount" label="Extras on the guest" type="number" step="0.01" value="0" hint="waiting, parking, tolls"/>
    <x-field name="extra_note" label="Extras note" placeholder="1 h waiting (flight delayed)"/>
    @if($t->billing === 'direct')
      <div id="transportSingleTender-{{ $t->id }}" class="form-grid span-all">
        <x-field name="payment_mode_id" label="Guest paid by" type="select" :options="$paymentModes" data-payment-mode/>
        <x-field name="momo_network" label="MoMo network"/><x-field name="momo_phone" label="MoMo phone"/>
        <x-field name="reference" label="Reference" class="span-all"/><x-cheque-fields/>
      </div>
      <x-payment-split-tender widget-id="transportSplitTender-{{ $t->id }}" single-id="transportSingleTender-{{ $t->id }}" :modes="$paymentModes" label="payment"/>
    @endif
    <p class="tiny muted span-all">{{ $t->billing === 'room' ? 'The fare '.($t->folio_transaction_id ? 'is already on the bill – any extras will be added.' : 'will be posted to the room bill.') : ($t->billing === 'direct' ? 'A paid invoice is issued for the fare + extras.' : 'Complimentary – nothing is charged.') }}</p>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" id="confirmComplete">Complete trip</button></div>
</form></div></div>
<div class="modal" id="noShowModal"><div class="modal-box"><form method="post" action="{{ route('tr.noshow', $t) }}">@csrf
  <div class="modal-head"><h3>Guest didn't turn up</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="reason" label="What happened" required value="Guest not at the pick-up point"/>
    @if($t->billing === 'room' && (float) $t->total > 0)<label class="check"><input type="checkbox" name="charge" value="1"> Charge the fare as a no-show fee to the room bill</label>@endif</div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Back</button><button class="btn btn-danger" id="confirmNoShow">Mark no-show</button></div>
</form></div></div>
<div class="modal" id="cancelModal"><div class="modal-box"><form method="post" action="{{ route('tr.cancel', $t) }}">@csrf
  <div class="modal-head"><h3>Cancel {{ $t->trip_no }}</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="reason" label="Reason" required placeholder="Guest arranged own transport"/>
    <label class="check"><input type="checkbox" name="notify" value="1" checked> Tell the guest</label>
    @if($t->folio_transaction_id)<p class="tiny text-amber">The fare already on the bill will be voided.</p>@endif</div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Back</button><button class="btn btn-danger" id="confirmCancel">Cancel trip</button></div>
</form></div></div>
@if($t->isPlanned())
<div class="modal" id="editModal"><div class="modal-box" style="max-width:720px"><form method="post" action="{{ route('tr.update', $t) }}">@csrf @method('PUT')
  <input type="hidden" name="type" value="{{ $t->type }}"><input type="hidden" name="reservation_room_id" value="{{ $t->reservation_room_id }}"><input type="hidden" name="reservation_id" value="{{ $t->reservation_id }}">
  <div class="modal-head"><h3>Change {{ $t->trip_no }}</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <x-field name="scheduled_at" label="Date & time" type="datetime-local" :value="$t->scheduled_at->format('Y-m-d\TH:i')" required/>
    <x-field name="flight_no" label="Flight / train" :value="$t->flight_no"/>
    <x-field name="passenger_name" label="Passenger" :value="$t->passenger_name" required/>
    <x-field name="phone" label="Mobile" :value="$t->phone"/>
    <x-field name="pax" label="Passengers" type="number" :value="$t->pax" required/>
    <x-field name="bags" label="Bags" type="number" :value="$t->bags"/>
    <x-field name="pickup_point" label="From" :value="$t->pickup_point" required/>
    <x-field name="dropoff_point" label="To" :value="$t->dropoff_point" required/>
    <x-field name="transport_tariff_id" label="Tariff" type="select" :options="$tariffs->mapWithKeys(fn ($x) => [$x->id => $x->name.' – '.money($x->flat_rate)])" :value="$t->transport_tariff_id" placeholder="— custom fare —"/>
    <x-field name="amount" label="Fare (before tax)" type="number" step="0.01" :value="(float) $t->amount"/>
    <x-field name="billing" label="Billing" type="select" :options="\App\Models\TransportTrip::BILLING" :value="$t->billing" :placeholder="false"/>
    <x-field name="duration_min" label="Car busy for (min)" type="number" :value="$t->duration_min"/>
    <x-field name="notes" label="Notes for the driver" type="textarea" :value="$t->notes" class="span-all"/>
    <label class="check span-all"><input type="checkbox" name="notify" value="1" checked> Tell the guest if the time changes</label>
    @if($t->vehicle_id)<label class="check span-all"><input type="checkbox" name="force" value="1"> Keep the car &amp; driver even if the new time overlaps another trip</label>@endif
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" id="saveEdit">Save changes</button></div>
</form></div></div>
@endif
@endif
@include('transport._assign')
@endsection
