@extends('layouts.app')
@section('title', 'New Trip')
@section('crumbs') <a href="{{ route('tr.index') }}">Transport</a> / New trip @endsection
@section('content')
@php $v = fn ($k, $d = null) => old($k, $p[$k] ?? $d); $hotelName = hotel()->name; @endphp
@include('transport._clash')
@if($req)<div class="alert alert-info"><x-icon name="message"/><div>Booking a car for guest request <b>{{ $req->request_no }}</b> – “{{ $req->item }}” from room {{ $req->room?->room_no }}. The request is marked done when the trip is completed.</div></div>@endif
<form method="post" action="{{ route('tr.store') }}" id="tripForm">@csrf
<input type="hidden" name="guest_request_id" value="{{ $v('guest_request_id') }}">
<input type="hidden" name="reservation_id" value="{{ $v('reservation_id') }}" id="f_reservation_id">
<div class="grid g-2-1">
<div>
  <div class="card"><div class="card-head"><h3>Trip</h3></div><div class="card-body">
    <div class="tr-type mb-1" id="typePick">@foreach(\App\Models\TransportTrip::TYPES as $k => $l)<label><input type="radio" name="type" value="{{ $k }}" @checked($v('type') === $k)><span>{{ $k === 'pickup' ? '↓' : ($k === 'drop' ? '↑' : '↔') }} {{ $l }}</span></label>@endforeach</div>
    <div class="form-grid">
      <div class="field span-all"><label for="f_reservation_room_id">Guest / booking</label>
        <select name="reservation_room_id" id="f_reservation_room_id"><option value="">— not a hotel guest (walk-in / visitor) —</option>
          @foreach($stays as $s)@php $g = $s->guest ?? $s->reservation?->guest; @endphp
            <option value="{{ $s->id }}" @selected((string) $v('reservation_room_id') === (string) $s->id) data-name="{{ $g?->full_name }}" data-phone="{{ $g?->phone }}" data-pax="{{ $s->adults + $s->children }}"
              data-arr="{{ $s->arrival_date->toDateString() }}" data-dep="{{ $s->departure_date->toDateString() }}" data-eta="{{ $s->reservation?->eta ? substr($s->reservation->eta, 0, 5) : '12:00' }}" data-res="{{ $s->reservation_id }}">
              {{ $s->status === 'checked_in' ? 'In-house · Rm '.$s->room?->room_no : 'Arriving '.$s->arrival_date->format('d M') }} · {{ $g?->full_name }} · {{ $s->reservation?->reservation_no }}</option>
          @endforeach
        </select><span class="hint">Pick the stay to fill the guest's details and allow posting to the room bill.</span></div>
      <x-field name="passenger_name" label="Passenger name" :value="$v('passenger_name')" required/>
      <x-field name="phone" label="Mobile" :value="$v('phone')" hint="the driver's details are sent here"/>
      <x-field name="pax" label="Passengers" type="number" :value="$v('pax', 1)" required min="1" max="60"/>
      <x-field name="bags" label="Bags" type="number" :value="$v('bags', 0)" min="0" max="99"/>
      <x-field name="scheduled_at" label="Date & time" type="datetime-local" :value="$v('scheduled_at')" required hint="pick-up: when the flight / train arrives · drop & local: when the car leaves the hotel"/>
      <x-field name="flight_no" label="Flight / train no." :value="$v('flight_no')" placeholder="6E 2134"/>
      <x-field name="pickup_point" label="From" :value="$v('pickup_point')" required list="trPlaces"/>
      <x-field name="dropoff_point" label="To" :value="$v('dropoff_point')" required list="trPlaces"/>
      <datalist id="trPlaces"><option value="{{ $hotelName }}"><option value="{{ $cfg['airport'] }}"><option value="{{ $cfg['station'] }}"></datalist>
      <x-field name="notes" label="Notes for the driver" type="textarea" :value="$v('notes')" class="span-all" placeholder="Child seat, wheelchair, meet at gate 4, VIP…"/>
    </div>
  </div></div>
</div>
<div>
  <div class="card"><div class="card-head"><h3>Fare &amp; billing</h3></div><div class="card-body">
    <div class="field"><label for="f_transport_tariff_id">Tariff</label><select name="transport_tariff_id" id="f_transport_tariff_id"><option value="" data-rate="">— custom fare —</option>
      @foreach($tariffs as $tf)<option value="{{ $tf->id }}" data-rate="{{ (float) $tf->flat_rate }}" data-vtype="{{ $tf->vehicle_type }}" data-dur="{{ $tf->duration_min }}" @selected((string) $v('transport_tariff_id') === (string) $tf->id)>{{ $tf->name }} – {{ money($tf->flat_rate) }}</option>@endforeach
    </select></div>
    <x-field name="vehicle_type" label="Vehicle type wanted" type="select" :options="\App\Models\Vehicle::TYPES" :value="$v('vehicle_type')" placeholder="Any"/>
    <x-field name="amount" label="Fare (before tax)" type="number" step="0.01" :value="$v('amount', 0)"/>
    <x-field name="billing" label="Billing" type="select" :options="\App\Models\TransportTrip::BILLING" :value="$v('billing', 'direct')" :placeholder="false"/>
    <p class="tiny muted">Room bill: posted to the folio when the trip is completed (or at check-out). Direct: paid to the desk / driver at completion.</p>
  </div></div>
  <div class="card"><div class="card-head"><h3>Assign now <span class="muted small">(optional)</span></h3></div><div class="card-body">
    <x-field name="vehicle_id" label="Vehicle" type="select" :options="$vehicles->mapWithKeys(fn ($x) => [$x->id => $x->code.' · '.$x->name.' · '.$x->seats.' seats'.($x->status === 'maintenance' ? ' (workshop)' : '')])" :value="old('vehicle_id')" placeholder="— later —"/>
    <x-field name="driver_id" label="Driver" type="select" :options="$drivers->pluck('name', 'id')" :value="old('driver_id')" placeholder="— later —"/>
    <label class="check"><input type="checkbox" name="notify" value="1" checked> Message the driver and the guest</label>
    <label class="check"><input type="checkbox" name="force" value="1"> Assign even if the car / driver is busy</label>
  </div>
  <div class="card-foot"><button class="btn btn-primary btn-block" id="saveTrip"><x-icon name="check"/> Book trip</button></div></div>
</div>
</div>
</form>
@push('scripts')
<script>
(() => {
  const f = document.getElementById('tripForm'), hotel = @json($hotelName), airport = @json($cfg['airport']);
  const stay = document.getElementById('f_reservation_room_id'), tariff = document.getElementById('f_transport_tariff_id');
  const set = (n, val) => { const el = f.elements[n]; if (el && val !== undefined && val !== null) el.value = val; };
  const type = () => (f.querySelector('[name=type]:checked') || {}).value;
  const whenFor = (o, t) => o ? (t === 'pickup' ? o.dataset.arr + 'T' + (o.dataset.eta || '12:00') : (t === 'drop' ? o.dataset.dep + 'T{{ substr((string) (hotel()->checkout_time ?: '11:00'), 0, 5) }}' : null)) : null;
  f.querySelectorAll('[name=type]').forEach(r => r.addEventListener('change', () => {
    const t = type();
    if (t === 'pickup') { set('pickup_point', airport); set('dropoff_point', hotel); }
    if (t === 'drop') { set('pickup_point', hotel); set('dropoff_point', airport); }
    if (t === 'transfer') { set('pickup_point', hotel); set('dropoff_point', ''); }
    const o = stay.selectedOptions[0]; const w = whenFor(o && o.value ? o : null, t); if (w) set('scheduled_at', w);
  }));
  stay.addEventListener('change', () => {
    const o = stay.selectedOptions[0];
    if (!o.value) { set('reservation_id', ''); if (f.elements.billing.value === 'room') set('billing', 'direct'); return; }
    set('passenger_name', o.dataset.name); set('phone', o.dataset.phone); set('pax', o.dataset.pax); set('reservation_id', o.dataset.res);
    const w = whenFor(o, type()); if (w) set('scheduled_at', w);
    set('billing', 'room');
  });
  tariff.addEventListener('change', () => { const o = tariff.selectedOptions[0]; if (o.dataset.rate !== '') set('amount', o.dataset.rate); if (o.dataset.vtype) set('vehicle_type', o.dataset.vtype); });
})();
</script>
@endpush
@endsection
