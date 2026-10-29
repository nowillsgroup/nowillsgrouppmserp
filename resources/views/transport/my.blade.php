@extends('layouts.app')
@section('title', 'My Trips')
@section('actions')
@if($drivers->isNotEmpty())
<form class="filters"><div class="field"><select name="as" data-autosubmit aria-label="View as driver"><option value="">{{ $driver && !request('as') ? 'Me ('.$driver->name.')' : '— view driver —' }}</option>@foreach($drivers as $id => $n)<option value="{{ $id }}" @selected(request('as') == $id)>{{ $n }}</option>@endforeach</select></div></form>
@endif
@endsection
@section('content')
<div class="hk-wrap">
@if(! $driver)
  <div class="card"><div class="card-body">
    <h3 style="margin-top:0">Your login is not linked to a driver</h3>
    <p class="text-2">Ask the transport manager to open <b>Transport › Drivers</b>, edit your record and choose your login. Your trips will then appear here.@if($drivers->isNotEmpty()) Or pick a driver above to see their trips.@endif</p>
  </div></div>
@else
  @php $today = $trips->filter(fn ($t) => $t->scheduled_at->isToday()); @endphp
  <div class="flex justify-between items-center mb-1"><div><div class="small text-2">{{ now()->format('l, d M Y') }}</div><h2 style="margin:.1rem 0 0" id="drvName">{{ $driver->name }}</h2></div></div>
  <div class="hk-summary">
    <div><b>{{ $today->count() }}</b><span>Today</span></div>
    <div><b class="text-green">{{ $today->where('status', 'completed')->count() }}</b><span>Done</span></div>
    <div><b>{{ $trips->whereIn('status', ['assigned', 'en_route', 'onboard'])->count() }}</b><span>To do</span></div>
    <div><b>{{ $trips->filter(fn ($t) => $t->scheduled_at->isTomorrow())->count() }}</b><span>Tomorrow</span></div>
  </div>
  @forelse($trips as $t)
    <div class="hk-card st-{{ $t->status }}" data-trip="{{ $t->trip_no }}">
      <div class="flex justify-between items-center"><div><div class="hk-room">{{ $t->scheduled_at->format('H:i') }}</div><div class="small text-2">{{ $t->scheduled_at->isToday() ? 'Today' : $t->scheduled_at->format('D d M') }} · {{ $t->typeLabel() }} · {{ $t->trip_no }}</div></div>{!! tr_badge($t->status) !!}</div>
      <div class="mt-1"><b>{{ $t->passenger_name }}</b> · {{ $t->pax }} pax{{ $t->bags ? ' · '.$t->bags.' bags' : '' }}@if($t->stay?->room) · Room {{ $t->stay->room->room_no }}@endif</div>
      <div class="small">{{ $t->route() }}</div>
      @if($t->flight_no)<div class="small"><x-icon name="plane" size="sm"/> {{ $t->flight_no }} · leave by <b>{{ $t->busy_from->format('H:i') }}</b></div>@endif
      <div class="small text-2">{{ $t->vehicle?->code }} · {{ $t->vehicle?->reg_no }}@if($t->billing === 'direct') · <b class="text-amber">collect {{ money($t->total) }} + tax</b>@endif</div>
      @if($t->notes)<div class="small mt-1"><x-icon name="message" size="sm"/> {{ $t->notes }}</div>@endif
      @if($t->type === 'pickup' && $t->isOpen())<div class="drv-board">{{ $t->nameBoard() }}</div>@endif
      <div class="more">
        @if($t->phone)<a class="btn btn-sm" href="tel:{{ $t->phone }}"><x-icon name="phone" size="sm"/> Call guest</a>@endif
        <a class="btn btn-sm" href="https://www.google.com/maps/dir/?api=1&destination={{ urlencode($t->type === 'pickup' ? $t->pickup_point : $t->dropoff_point) }}" target="_blank" rel="noopener"><x-icon name="globe" size="sm"/> Map</a>
      </div>
      @if($t->status === 'assigned')
        <form method="post" action="{{ route('tr.my.action', [$t, 'start']) }}" class="acts" style="grid-template-columns:1fr 1fr">@csrf
          <input type="number" name="odo_start" class="input" placeholder="Odometer km" value="{{ $t->vehicle?->odometer }}" aria-label="Odometer">
          <button class="btn btn-success drv-start">Start trip</button></form>
      @elseif($t->status === 'en_route')
        <div class="acts" style="grid-template-columns:1fr 1fr">
          <form method="post" action="{{ route('tr.my.action', [$t, 'onboard']) }}">@csrf<button class="btn btn-success btn-block drv-onboard">Guest on board</button></form>
          @if($t->type === 'pickup')<form method="post" action="{{ route('tr.my.action', [$t, 'noshow']) }}" data-confirm="Mark {{ $t->passenger_name }} as not found?">@csrf<button class="btn btn-block">Guest not found</button></form>@endif
        </div>
      @endif
      @if(in_array($t->status, ['en_route', 'onboard']))
        <form method="post" action="{{ route('tr.my.action', [$t, 'complete']) }}" class="acts" style="grid-template-columns:1fr 1fr">@csrf
          <input type="hidden" name="split_trip_id" value="{{ $t->id }}">
          <input type="number" name="odo_end" class="input" placeholder="Closing km" aria-label="Closing odometer">
          <input type="number" name="extra_amount" class="input" placeholder="Waiting / tolls" step="0.01" aria-label="Extras">
          @if($t->billing === 'direct')
            <div id="driverTransportSingle-{{ $t->id }}" class="form-grid span-all">
              <select name="payment_mode_id" class="input" aria-label="Paid by" data-payment-mode><option value="">Choose payment mode</option>@foreach($paymentModes as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>
              <input name="momo_network" class="input" maxlength="20" placeholder="MoMo network" aria-label="MoMo network">
              <input name="momo_phone" class="input" maxlength="30" placeholder="MoMo phone" aria-label="MoMo phone">
              <input name="reference" class="input span-all" maxlength="80" placeholder="Payment reference" aria-label="Payment reference"><x-cheque-fields class="span-all"/>
            </div>
            <x-payment-split-tender widget-id="driverTransportSplit-{{ $t->id }}" single-id="driverTransportSingle-{{ $t->id }}" :modes="$paymentModes" :old-lines="((int) old('split_trip_id') === (int) $t->id) ? old('payments', []) : []" label="payment"/>
          @endif
          <button class="btn btn-primary drv-complete" style="grid-column:1/-1">Trip completed</button></form>
      @endif
    </div>
  @empty
    <x-empty text="No trips for you today or tomorrow." icon="car"/>
  @endforelse

  <div class="card mt-2"><div class="card-head"><h3>Log fuel / toll / parking</h3></div><div class="card-body">
    <form method="post" action="{{ route('tr.my.expense') }}" class="form-grid" id="fuelForm">@csrf
      @if(request('as'))<input type="hidden" name="as" value="{{ request('as') }}">@endif
      <x-field name="vehicle_id" label="Vehicle" type="select" :options="$vehicles->pluck('code', 'id')" :value="$trips->first()?->vehicle_id" required/>
      <x-field name="kind" label="What" type="select" :options="['fuel' => 'Fuel', 'toll' => 'Toll', 'parking' => 'Parking']" :placeholder="false"/>
      <x-field name="amount" label="Amount" type="number" step="0.01" required/>
      <x-field name="litres" label="Litres" type="number" step="0.01"/>
      <x-field name="odometer" label="Odometer" type="number"/>
      <x-field name="vendor" label="Pump / place"/>
      <div class="span-all"><button class="btn btn-block" id="saveFuel">Save</button></div>
    </form>
  </div></div>
@endif
</div>
@endsection
