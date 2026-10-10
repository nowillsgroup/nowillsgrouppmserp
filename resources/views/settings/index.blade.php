@extends('layouts.app')
@section('title', 'Hotel Settings')
@section('content')
<form method="post" action="{{ route('settings.update') }}" enctype="multipart/form-data">@csrf
<div class="grid g-2-1">
<div>
  <div class="card"><div class="card-head"><h3>Property profile</h3><span class="badge badge-blue">Hotel ID: {{ $hotel->code }}</span></div><div class="card-body form-grid">
    <x-field name="name" label="Property name" :value="$hotel->name" required/>
    <x-field name="legal_name" label="Legal / company name" :value="$hotel->legal_name"/>
    <x-field name="property_type" label="Property type" type="select" :options="['hotel'=>'Hotel','resort'=>'Resort','guest_house'=>'Guest House','service_apartment'=>'Service Apartment','restaurant'=>'Restaurant','cafe'=>'Café']" :value="$hotel->property_type" :placeholder="false"/>
    <x-field name="tax_number" label="TIN (GRA) / Ghana Card PIN" :value="$hotel->tax_number"/>
    <x-field name="address" label="Address" :value="$hotel->address" class="span-2"/>
    <x-field name="city" label="City" :value="$hotel->city"/><x-field name="state" label="State / Region" :value="$hotel->state"/>
    <x-field name="country" label="Country" :value="$hotel->country"/><x-field name="pincode" label="Postal code / GPS address" :value="$hotel->pincode"/>
    <x-field name="phone" label="Phone" :value="$hotel->phone"/><x-field name="email" label="Email" type="email" :value="$hotel->email"/>
    <x-field name="website" label="Website" type="url" :value="$hotel->website" class="span-2"/>
  </div></div>
  <div class="card"><div class="card-head"><h3>Operations</h3></div><div class="card-body form-grid">
    <x-field name="currency" label="Currency code" :value="$hotel->currency" required/>
    <x-field name="currency_symbol" label="Currency symbol" :value="$hotel->currency_symbol" required/>
    <div class="field"><label>Time zone</label><select name="timezone">@foreach($timezones as $tz)<option @selected(old('timezone',$hotel->timezone)===$tz)>{{ $tz }}</option>@endforeach</select></div>
    <div class="field"><label>Business date</label><input value="{{ bdate()->format('d M Y') }}" disabled><span class="hint">Advances only through Night Audit.</span></div>
    <x-field name="checkin_time" label="Standard check-in time" type="time" :value="substr($hotel->checkin_time,0,5)" required/>
    <x-field name="checkout_time" label="Standard check-out time" type="time" :value="substr($hotel->checkout_time,0,5)" required/>
    <x-field name="invoice_footer" label="Invoice footer (bank details, thank-you note)" type="textarea" :value="$hotel->invoice_footer" class="span-all" rows="2"/>
  </div></div>
  <div class="card" id="loyalty"><div class="card-head"><h3>Preferences &amp; policies</h3></div><div class="card-body form-grid">
    @foreach($keys as $k => [$label, $type])
      @if($type === 'bool')
        <label class="check span-2"><input type="checkbox" name="settings[{{ $k }}]" value="1" @checked(($values[$k] ?? ($keys[$k][2] ?? '0')) === '1')> {{ $label }}</label>
      @elseif($type === 'number')
        <x-field :name="'settings['.$k.']'" :label="$label" type="number" step="0.01" min="0" :value="$values[$k] ?? ($keys[$k][2] ?? '')"/>
      @elseif($type === 'select')
        <x-field :name="'settings['.$k.']'" :label="$label" type="select" :options="$keys[$k][3]" :value="$values[$k] ?? $keys[$k][2]"/>
      @elseif($type === 'time')
        <x-field :name="'settings['.$k.']'" :label="$label" type="time" :value="$values[$k] ?? $keys[$k][2]"/>
      @elseif($type === 'store')
        <x-field :name="'settings['.$k.']'" :label="$label" type="select" :options="$stores" :value="$values[$k] ?? ''" placeholder="— no stock deduction —"/>
      @elseif($type === 'outlet')
        <x-field :name="'settings['.$k.']'" :label="$label" type="select" :options="$outlets" :value="$values[$k] ?? ''" placeholder="— in-room dining off —"/>
      @elseif($type === 'text')
        <div class="field"><label>{{ $label }}</label><input name="settings[{{ $k }}]" value="{{ $values[$k] ?? '' }}" maxlength="120" autocomplete="off"></div>
      @elseif($type === 'emails')
        <x-field :name="'settings['.$k.']'" :label="$label" :value="$values[$k] ?? ''" class="span-all" placeholder="gm@hotel.com, owner@hotel.com"/>
      @else
        <x-field :name="'settings['.$k.']'" :label="$label" type="textarea" :value="$values[$k] ?? ($keys[$k][2] ?? '')" class="span-all" :rows="in_array($k, ['hk_checklist', 'portal_info', 'portal_amenities']) ? 5 : 2"/>
      @endif
    @endforeach
  </div></div>
</div>
<div>
  <div class="card"><div class="card-head"><h3>Logo</h3></div><div class="card-body">
    @if($hotel->logo_path)<img src="{{ asset($hotel->logo_path) }}" alt="Logo" style="max-height:80px;max-width:100%;margin-bottom:.75rem"><label class="check"><input type="checkbox" name="remove_logo" value="1"> Remove logo</label>@endif
    <div class="field"><label>Upload (PNG/JPG, max 512 KB)</label><input type="file" name="logo" accept="image/png,image/jpeg,image/webp"></div>
  </div></div>
  <div class="card"><div class="card-head"><h3>Public links</h3></div><div class="card-body small">
    <p><b>Booking engine</b><br><a href="{{ route('booking.show', $hotel->code) }}" target="_blank">{{ route('booking.show', $hotel->code) }}</a></p>
    <p><b>Guest feedback</b><br><a href="{{ route('feedback.form', $hotel->code) }}" target="_blank">{{ route('feedback.form', $hotel->code) }}</a></p>
  </div></div>
  <button class="btn btn-primary btn-block"><x-icon name="check"/> Save Settings</button>
</div>
</div>
</form>

@if($properties->count())
<div class="card flush mt-2"><div class="card-head"><h3>Group properties</h3><button class="btn btn-sm btn-primary" data-modal="propModal"><x-icon name="plus" size="sm"/> Add property</button></div>
<div class="card-body table-wrap"><table class="table"><thead><tr><th>Hotel ID</th><th>Name</th><th>Type</th><th>City</th><th>Business date</th><th>Status</th></tr></thead><tbody>
@foreach($properties as $p)<tr><td class="bold">{{ $p->code }}</td><td>{{ $p->name }}</td><td>{{ str_replace('_',' ',$p->property_type) }}</td><td>{{ $p->city }}</td><td>{{ $p->business_date->format('d M Y') }}</td><td>{!! $p->is_active ? '<span class="badge badge-green">Active</span>' : '<span class="badge badge-slate">Inactive</span>' !!}</td></tr>@endforeach
</tbody></table></div></div>
<div class="modal" id="propModal"><div class="modal-box"><form method="post" action="{{ route('settings.hotels.store') }}">@csrf
<div class="modal-head"><h3>Add property to group</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
<div class="modal-body form-grid">
<x-field name="code" label="Hotel ID (login code)" required/><x-field name="name" label="Property name" required/>
<x-field name="property_type" label="Type" type="select" :options="['hotel'=>'Hotel','resort'=>'Resort','guest_house'=>'Guest House','service_apartment'=>'Service Apartment','restaurant'=>'Restaurant','cafe'=>'Café']" :placeholder="false"/>
<x-field name="city" label="City"/><x-field name="admin_username" label="Admin username" value="admin" required/><x-field name="admin_password" label="Admin password" type="password" required hint="Min 8 characters"/>
</div>
<div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Create Property</button></div></form></div></div>
@endif
@endsection
