@extends('portal.layout')
@section('title', 'Transport')
@section('heading', 'Airport & city transfers')
@section('sub', $state === 'upcoming' ? 'Book your pick-up – our driver will be waiting with your name.' : 'Book a drop to the airport or station, or a car in the city.')
@section('content')
<style>
.st.requested{background:#fef3c7;color:#92400e}.st.confirmed{background:#e0e7ff;color:#3730a3}.st.assigned{background:#ede9fe;color:#5b21b6}.st.en_route,.st.onboard{background:#dcfce7;color:#166534}
.gp-drv{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:.6rem .75rem;margin-top:.45rem;font-size:.85rem}
.gp-fare{display:flex;justify-content:space-between;font-size:.85rem;padding:.3rem 0;border-bottom:1px dashed #e2e8f0}.gp-fare:last-child{border:0}
</style>

@if($trips->isNotEmpty())
<div class="gp-card" id="myTransfers"><h3>My transfers</h3>
@foreach($trips as $t)
  <div class="gp-row" data-trip="{{ $t->trip_no }}"><div style="flex:1">
    <b>{{ $t->typeLabel() }} · {{ $t->scheduled_at->format('D d M, H:i') }}</b>
    <div class="tiny muted">{{ $t->route() }}{{ $t->flight_no ? ' · '.$t->flight_no : '' }} · {{ $t->pax }} pax</div>
    @if($t->driver && in_array($t->status, ['assigned', 'en_route', 'onboard']))
      <div class="gp-drv">Your driver <b>{{ $t->driver->name }}</b> · <a href="tel:{{ $t->driver->phone }}">{{ $t->driver->phone }}</a><br>
        {{ $t->vehicle?->color }} {{ $t->vehicle?->name }} · <b>{{ $t->vehicle?->reg_no }}</b>
        @if($t->type === 'pickup')<br><span class="tiny muted">Look for a board with your name at arrivals.</span>@endif</div>
    @elseif($t->status === 'requested')
      <div class="tiny muted mt-1">Waiting for the front desk to confirm.</div>
    @endif
    @if((float) $t->total > 0 && $t->billing !== 'complimentary' && $t->isOpen())<div class="tiny muted">{{ money($t->total) }} + tax · {{ $t->billing === 'room' ? 'added to your room bill' : 'pay the driver' }}</div>@endif
    @if($t->isPlanned() && $t->busy_from->gte(now()->addHours(2)))
      <form method="post" action="{{ route('portal.transport.cancel', [$token, $t->id]) }}" class="mt-1" onsubmit="return confirm('Cancel this transfer?')">@csrf<button class="btn btn-sm">Cancel</button></form>
    @endif
  </div><span class="st {{ $t->status }}">{{ $t->statusLabel() }}</span></div>
@endforeach
</div>
@endif

@php $defType = old('type', $state === 'upcoming' ? 'pickup' : 'drop'); @endphp
<div class="gp-card" id="bookTransfer"><h3>Book a transfer</h3>
<form method="post" action="{{ route('portal.transport.request', $token) }}" id="trForm">@csrf
  <input type="text" name="website" value="" style="display:none" tabindex="-1" autocomplete="off">
  <div class="gp-chips mb-1">
    @if($state === 'upcoming')<label class="gp-chip"><input type="radio" name="type" value="pickup" @checked($defType === 'pickup')> Pick me up</label>@endif
    <label class="gp-chip"><input type="radio" name="type" value="drop" @checked($defType === 'drop')> Drop me</label>
    @if($state === 'inhouse')<label class="gp-chip"><input type="radio" name="type" value="transfer" @checked($defType === 'transfer')> Car in the city</label>@endif
  </div>
  @php $defDate = $state === 'upcoming' ? $stay->arrival_date : ($defType === 'drop' ? $stay->departure_date : now()); @endphp
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
    <div class="field"><label for="tr_date" id="lblDate">{{ $defType === 'pickup' ? 'Landing date' : 'Date' }}</label><input type="date" id="tr_date" name="date" class="input" required min="{{ now()->toDateString() }}" max="{{ $stay->departure_date->toDateString() }}" value="{{ old('date', $defDate->toDateString()) }}"></div>
    <div class="field"><label for="tr_time" id="lblTime">{{ $defType === 'pickup' ? 'Landing time' : 'Leave the hotel at' }}</label><input type="time" id="tr_time" name="time" class="input" required value="{{ old('time', $defType === 'pickup' ? '10:00' : '09:00') }}"></div>
    <div class="field"><label for="tr_flight">Flight / train no.</label><input id="tr_flight" name="flight_no" class="input" maxlength="20" placeholder="6E 5302" value="{{ old('flight_no') }}"></div>
    <div class="field"><label for="tr_pax">Passengers</label><select id="tr_pax" name="pax" class="input">@for($i = 1; $i <= 8; $i++)<option @selected(old('pax', $stay->adults + $stay->children) == $i)>{{ $i }}</option>@endfor</select></div>
  </div>
  <div class="field"><label for="tr_place" id="lblPlace">{{ $defType === 'pickup' ? 'Pick me up from' : 'Take me to' }}</label>
    <input id="tr_place" name="place" class="input" maxlength="150" list="gpPlaces" placeholder="{{ $cfg['airport'] }}" value="{{ old('place') }}">
    <datalist id="gpPlaces"><option value="{{ $cfg['airport'] }}"><option value="{{ $cfg['station'] }}"></datalist></div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
    <div class="field"><label for="tr_bags">Bags</label><select id="tr_bags" name="bags" class="input">@for($i = 0; $i <= 10; $i++)<option @selected(old('bags', 2) == $i)>{{ $i }}</option>@endfor</select></div>
    <div class="field"><label for="tr_phone">Mobile on the day</label><input id="tr_phone" name="phone" class="input" maxlength="30" value="{{ old('phone', $guest?->phone) }}"></div>
  </div>
  <div class="field"><label for="tr_notes">Anything else? (optional)</label><textarea id="tr_notes" name="notes" rows="2" maxlength="300" class="input" placeholder="Child seat, wheelchair, extra luggage…">{{ old('notes') }}</textarea></div>
  <button class="btn btn-primary btn-block" id="sendTr">Request transfer</button>
  <p class="tiny muted mt-1 m-0">Please book at least 2 hours ahead. The front desk confirms every request and sends your driver's name, mobile and car number by SMS / WhatsApp.</p>
</form>
</div>

@if($tariffs->isNotEmpty())
<div class="gp-card" id="trFares"><h3>Fares</h3>
  @foreach($tariffs as $f)<div class="gp-fare"><span>{{ $f->name }}<span class="tiny muted">{{ $f->vehicle_type ? ' · '.(\App\Models\Vehicle::TYPES[$f->vehicle_type] ?? '') : '' }}</span></span><b>{{ money($f->flat_rate) }}</b></div>@endforeach
  <p class="tiny muted m-0 mt-1">Plus tax. Added to your room bill. Waiting beyond 45 minutes, tolls and parking are extra.</p>
</div>
@endif
@push('scripts')
<script>
(function () {
  const f = document.getElementById('trForm'); if (!f) return;
  const lab = { pickup: ['Landing date', 'Landing time', 'Pick me up from'], drop: ['Date', 'Leave the hotel at', 'Take me to'], transfer: ['Date', 'Leave the hotel at', 'Take me to'] };
  f.querySelectorAll('input[name=type]').forEach(r => r.addEventListener('change', () => {
    const l = lab[r.value]; document.getElementById('lblDate').textContent = l[0]; document.getElementById('lblTime').textContent = l[1]; document.getElementById('lblPlace').textContent = l[2];
    document.getElementById('tr_place').placeholder = r.value === 'transfer' ? 'Where to?' : @json($cfg['airport']);
  }));
})();
</script>
@endpush
@endsection
