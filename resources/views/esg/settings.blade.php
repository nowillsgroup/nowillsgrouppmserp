@extends('layouts.app')
@section('title', 'ESG Settings')
@section('crumbs') Sustainability &amp; ESG / Settings @endsection
@php $v = fn ($k) => old(substr($k, 4), $raw[$k]); @endphp
@section('content')
<form method="post" action="{{ route('esg.settings.save') }}" id="esgSettingsForm">@csrf
<div class="g-2">
  <div>
    <div class="card"><div class="card-head"><h3>Building areas (HCMI allocation)</h3></div><div class="card-body"><div class="form-grid">
      <div class="field"><label>Total floor area (m²)</label><input type="number" name="area_total" step="0.1" min="0" value="{{ $v('esg_area_total') }}" required></div>
      <div class="field"><label>Guest rooms &amp; corridors (m²)</label><input type="number" name="area_rooms" step="0.1" min="0" value="{{ $v('esg_area_rooms') }}" required></div>
      <div class="field"><label>Meeting &amp; event space (m²)</label><input type="number" name="area_meeting" step="0.1" min="0" value="{{ $v('esg_area_meeting') }}" required></div>
      <div class="field"><label>Private / tenant space (m²)</label><input type="number" name="area_private" step="0.1" min="0" value="{{ $v('esg_area_private') }}" required></div>
    </div><p class="tiny muted m-0">HCMI removes the footprint of private space (residences, leased shops that pay their own utilities) pro-rata to area, then splits the rest between guest rooms and meeting space by their floor areas. Meeting-space use (m²·h) comes from the banquet hall areas and function times.</p></div></div>
    <div class="card"><div class="card-head"><h3>Guest green stay</h3></div><div class="card-body"><div class="form-grid">
      <label class="small span-all"><input type="checkbox" name="green_enabled" value="1" @checked($cfg['green_enabled'])> Offer the green-stay choice (front desk and guest portal)</label>
      <div class="field"><label>Full service every (nights)</label><input type="number" name="green_service_every" min="2" max="7" value="{{ $v('esg_green_service_every') }}" required></div>
      <div class="field"><label>Loyalty points per green night</label><input type="number" name="green_points" min="0" max="10000" value="{{ $v('esg_green_points') }}" required></div>
      <div class="field"><label>Energy saved per night (kWh, estimate)</label><input type="number" name="green_kwh" step="0.1" min="0" value="{{ $v('esg_green_kwh') }}" required></div>
      <div class="field"><label>Water saved per night (litres, estimate)</label><input type="number" name="green_litres" step="1" min="0" value="{{ $v('esg_green_litres') }}" required></div>
    </div><p class="tiny muted m-0">Housekeeping does not generate the stay-over clean on green nights; the departure clean always happens. Points are credited by the night audit when the loyalty programme is on.</p></div></div>
    <div class="card"><div class="card-head"><h3>Controls</h3></div><div class="card-body"><div class="form-grid">
      <div class="field"><label>Refrigerant leak-rate alert (% of charge / yr)</label><input type="number" name="leak_alert_pct" step="0.1" min="1" max="100" value="{{ $v('esg_leak_alert_pct') }}" required></div>
      <label class="small span-all"><input type="checkbox" name="four_eyes" value="1" @checked($cfg['four_eyes'])> An initiative must be approved by someone other than the person who proposed it</label>
    </div></div></div>
  </div>
  <div>
    <div class="card"><div class="card-head"><h3>Scope 3 emission factors (kg CO₂e per unit)</h3></div><div class="card-body"><div class="form-grid">
      <div class="field"><label>Water supply (per m³)</label><input type="number" name="ef_water_supply" step="0.001" min="0" value="{{ $v('esg_ef_water_supply') }}" required></div>
      <div class="field"><label>Wastewater treatment (per m³)</label><input type="number" name="ef_wastewater" step="0.001" min="0" value="{{ $v('esg_ef_wastewater') }}" required></div>
      <div class="field"><label>Landfill – mixed (per kg)</label><input type="number" name="ef_landfill" step="0.001" min="0" value="{{ $v('esg_ef_landfill') }}" required></div>
      <div class="field"><label>Landfill – food / garden (per kg)</label><input type="number" name="ef_landfill_food" step="0.001" min="0" value="{{ $v('esg_ef_landfill_food') }}" required></div>
      <div class="field"><label>Recycling (per kg)</label><input type="number" name="ef_recycling" step="0.001" min="0" value="{{ $v('esg_ef_recycling') }}" required></div>
      <div class="field"><label>Composting (per kg)</label><input type="number" name="ef_composting" step="0.001" min="0" value="{{ $v('esg_ef_composting') }}" required></div>
      <div class="field"><label>Anaerobic digestion (per kg)</label><input type="number" name="ef_anaerobic" step="0.001" min="0" value="{{ $v('esg_ef_anaerobic') }}" required></div>
      <div class="field"><label>Incineration (per kg)</label><input type="number" name="ef_incineration" step="0.001" min="0" value="{{ $v('esg_ef_incineration') }}" required></div>
      <div class="field"><label>Donation / reuse (per kg)</label><input type="number" name="ef_donation" step="0.001" min="0" value="{{ $v('esg_ef_donation') }}" required></div>
      <div class="field"><label>Rendering / biodiesel (per kg)</label><input type="number" name="ef_rendering" step="0.001" min="0" value="{{ $v('esg_ef_rendering') }}" required></div>
    </div><p class="tiny muted m-0">Defaults: DEFRA / DESNZ conversion factors (water 0.149, commercial waste to landfill 0.520, food waste to landfill 0.700, mixed recycling 0.021). Composting, anaerobic digestion and wastewater are indicative – replace them with local figures where you have them. The grid electricity and fuel factors live in <a href="{{ route('utilities.index') }}">Utilities</a>.</p></div></div>
    <div class="card"><div class="card-head"><h3>Outsourced laundry</h3></div><div class="card-body"><div class="form-grid">
      <label class="small span-all"><input type="checkbox" name="laundry_outsourced" value="1" @checked($cfg['laundry_outsourced'])> Linen is washed by an outside laundry (HCMI includes it)</label>
      <div class="field"><label>kg CO₂e per kg of linen</label><input type="number" name="ef_laundry" step="0.001" min="0" value="{{ $v('esg_ef_laundry') }}" required></div>
      <div class="field"><label>Estimate: kg linen per occupied room</label><input type="number" name="laundry_kg_room" step="0.0001" min="0" value="{{ $v('esg_laundry_kg_room') }}" required></div>
    </div><p class="tiny muted m-0">When a month has no tonnage from the laundry, HCMI's default of 5.1256 kg of linen per occupied room is used. The 0.60 kg CO₂e/kg factor is an indicative default – ask your laundry for its own figure.</p></div></div>
  </div>
</div>
<button class="btn btn-primary">Save settings</button>
</form>
@endsection
