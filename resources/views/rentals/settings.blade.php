@extends('layouts.app')
@section('title', 'Car Rental Settings')
@section('crumbs') Car Rental / <b>Settings</b> @endsection
@section('actions')
  <a class="btn" href="{{ route('rentals.index') }}"><x-icon name="chev"/> Rental desk</a>
@endsection
@section('content')

<form method="post" action="{{ route('rentals.settings.save') }}">@csrf

<div class="grid g-2-1">
  <div>
    <div class="card">
      <div class="card-head"><h3>What a rental includes</h3></div>
      <div class="card-body form-grid">
        <x-field name="rental_free_km" label="Free kilometres per day" type="number" min="0" max="2000" step="1"
                 :value="$c['rental_free_km']" required
                 hint="Included in the daily rate. Everything beyond it is charged per kilometre below. A weekend rental of three days carries three times this allowance."/>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h3>What the return desk charges</h3><span class="badge badge-blue">On return</span></div>
      <div class="card-body form-grid">
        <x-field name="rental_mileage_per_km" label="Excess kilometre" type="number" min="0" max="1000" step="0.01"
                 :value="$c['rental_mileage_per_km']" required
                 hint="Charged for each kilometre beyond the free allowance — the return sheet adds it to the bill and names it."/>
        <x-field name="rental_late_per_hour" label="Late return, per hour" type="number" min="0" max="100000" step="0.01"
                 :value="$c['rental_late_per_hour']" required
                 hint="The first three hours after the due time are free; each hour after that is charged at this rate, and at least one hour is always charged once the grace is passed."/>
        <x-field name="rental_fuel_price" label="Fuel refill" type="number" min="0" max="100000" step="0.01"
                 :value="$c['rental_fuel_price']" required
                 hint="A flat charge when the car comes back below the level it went out with — one refill, whatever the shortfall."/>
      </div>
    </div>
    <button class="btn btn-primary"><x-icon name="check"/> Save settings</button>
  </div>

  <div>
    <div class="card">
      <div class="card-head"><h3>How these are used</h3></div>
      <div class="card-body small">
        <p>Every figure here is applied by the return desk, not by the booking screen: the car is booked at its daily or weekly rate, and the adjustments above are added when it comes back.</p>
        <p class="muted">Each adjustment is written to the return as a named line — “late return (2h)”, “fuel 60 % vs 100 % out”, “mileage 420 km (120 over)” — so the guest sees why the bill changed and the cashier can defend it.</p>
        <p class="muted">A property that has never opened this page charges exactly what it charged before: a flat tank refill, and the constants the desk shipped with.</p>
      </div>
    </div>
    <div class="card">
      <div class="card-head"><h3>The desk itself</h3></div>
      <div class="card-body small">
        <p><a href="{{ route('rentals.index') }}">Rental desk</a> — today's pick-ups, returns and open rentals.</p>
        <p><a href="{{ route('rentals.fleet') }}">Fleet</a> — the vehicles, their class and their daily rate.</p>
      </div>
    </div>
  </div>
</div>
</form>
@endsection
