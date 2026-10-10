@extends('layouts.app')
@section('title', 'Transport Settings')
@section('crumbs') <a href="{{ route('tr.index') }}">Transport</a> / Settings @endsection
@section('content')
<form method="post" action="{{ route('tr.settings.save') }}" id="trSettings">@csrf
<div class="grid g-2">
  <div class="card"><div class="card-head"><h3>Places &amp; scheduling</h3></div><div class="card-body form-grid">
    <x-field name="airport" label="Airport (default pick-up / drop point)" :value="$c['airport']" required class="span-all"/>
    <x-field name="station" label="Railway / bus station" :value="$c['station']" required class="span-all"/>
    <x-field name="pickup_lead" label="Pick-ups leave the hotel (min before landing)" type="number" :value="$c['pickup_lead']" required hint="drive time to the airport"/>
    <x-field name="buffer" label="Turnaround between trips (min)" type="number" :value="$c['buffer']" required hint="used for double-booking checks"/>
    <x-field name="default_billing" label="Default billing for hotel guests" type="select" :options="\App\Models\TransportTrip::BILLING" :value="$c['default_billing']" :placeholder="false"/>
  </div></div>
  <div class="card"><div class="card-head"><h3>Messages &amp; alerts</h3></div><div class="card-body form-grid">
    <label class="check span-all"><input type="checkbox" name="notify_guest" value="1" @checked($c['notify_guest'] === '1')> Send the guest the driver's name, mobile &amp; car when a trip is assigned</label>
    <label class="check span-all"><input type="checkbox" name="notify_driver" value="1" @checked($c['notify_driver'] === '1')> Send the driver the trip sheet when assigned</label>
    <x-field name="driver_reminder" label="Remind the driver (min before leaving, 0 = off)" type="number" :value="$c['driver_reminder']" required/>
    <x-field name="alert_hours" label="Alert when a trip without a car leaves within (h)" type="number" :value="$c['alert_hours']" required/>
    <x-field name="paper_days" label="Warn before papers / licences expire (days)" type="number" :value="$c['paper_days']" required/>
    <x-field name="request_expiry_hours" label="Close unconfirmed guest requests after (h, 0 = never)" type="number" :value="$c['request_expiry_hours']" required/>
  </div></div>
</div>
<button class="btn btn-primary" id="saveTrSettings"><x-icon name="check"/> Save settings</button>
<span class="small muted ml-1">Tariffs: <a href="{{ route('masters.index', 'transport-tariffs') }}">Transport › Tariff Card</a> · Revenue posts to GL 4070 Transport Revenue (change in GL Mapping).</span>
</form>
@endsection
