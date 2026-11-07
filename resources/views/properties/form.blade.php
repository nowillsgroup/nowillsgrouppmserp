@extends('layouts.app')
@section('title', $isNew ? 'Open a property' : 'Edit '.$property->name)
@section('crumbs')<a href="{{ route('properties.index') }}">Properties</a> / {{ $isNew ? 'New property' : $property->code }}@endsection
@section('content')

<form method="post" action="{{ $isNew ? route('properties.store') : route('properties.update', $property) }}">
@csrf
@unless($isNew) @method('PUT') @endunless

<div class="grid-2">
  <div>
    <div class="card"><div class="card-head"><h3>{{ $isNew ? 'The property' : 'Identity' }}</h3><span class="small muted">{{ $group?->name ?? 'Group' }}</span></div>
      <div class="card-body form-grid">
        @if($isNew)
          <x-field name="hotel_group_id" label="Group account" type="select" required :placeholder="false" :options="$groups->pluck('name', 'id')->all()" :value="$group?->id" hint="The property inherits this group's country, currency, clock and brand." />
        @else
          <div class="field"><label>Group account</label><div class="small bold">{{ $group?->name ?? 'Standalone property' }}</div><span class="hint">A property cannot be moved between groups; open it in the right group from the start.</span></div>
        @endif
        <x-field name="code" label="Hotel ID (login code)" required :value="$property->code" hint="Uppercase letters, digits, dash or underscore. Staff type it at sign-in." />
        <x-field name="name" label="Property name" required :value="$property->name" />
        <x-field name="legal_name" label="Legal name" :value="$property->legal_name" hint="The invoicing entity, if it differs from the trading name." />
        <x-field name="property_type" label="Type" type="select" required :placeholder="false" :options="$types" :value="$property->property_type" />
        <x-field name="brand" label="Brand" :value="$property->brand" hint="Inherited from the group when left blank." />
        <x-field name="star_rating" label="Star rating" type="number" min="1" max="5" :value="$property->star_rating" />
        <x-field name="opened_on" label="Trading since" type="date" :value="$property->opened_on?->toDateString()" />
        <x-field name="sort_order" label="Position in the switcher" type="number" min="0" max="9999" :value="$property->sort_order ?? 0" hint="Lower numbers appear first." />
        <label class="check"><input type="checkbox" name="is_head_office" value="1" @checked(old('is_head_office', $property->is_head_office))> This is the group's head office</label>
      </div></div>

    <div class="card mt-2"><div class="card-head"><h3>Address &amp; contact</h3></div>
      <div class="card-body form-grid">
        <x-field name="address" label="Street address" :value="$property->address" />
        <x-field name="city" label="City" :value="$property->city" />
        <x-field name="state" label="Region / state" :value="$property->state" />
        <x-field name="country" label="Country" :value="$property->country" />
        <x-field name="pincode" label="Postal code" :value="$property->pincode" />
        <x-field name="phone" label="Phone" :value="$property->phone" />
        <x-field name="email" label="E-mail" type="email" :value="$property->email" />
        <x-field name="website" label="Website" :value="$property->website" />
        <x-field name="tax_number" label="Tax / VAT / TIN" :value="$property->tax_number" />
      </div></div>
  </div>

  <div>
    <div class="card"><div class="card-head"><h3>Money &amp; clock</h3><span class="small muted">The property's own, not the browser's</span></div>
      <div class="card-body form-grid">
        <x-field name="currency" label="Currency code" required :value="$property->currency ?: 'GHS'" hint="Three letters, e.g. GHS." />
        <x-field name="currency_symbol" label="Currency symbol" :value="$property->currency_symbol" hint="Defaults to the code's symbol." />
        <x-field name="timezone" label="Time zone" type="select" required :placeholder="false" :options="$timezones" :value="$property->timezone" hint="Posting times and the business date follow this clock." />
        <x-field name="checkin_time" label="Check-in time" :value="$property->checkin_time" hint="24-hour, e.g. 14:00." />
        <x-field name="checkout_time" label="Check-out time" :value="$property->checkout_time" hint="24-hour, e.g. 11:00." />
        <x-field name="invoice_footer" label="Invoice footer" type="textarea" :value="$property->invoice_footer" hint="Printed under every invoice of this property." />
      </div></div>

    @if($isNew)
      <div class="card mt-2"><div class="card-head"><h3>Opening state &amp; first administrator</h3></div>
        <div class="card-body form-grid">
          <div class="field"><label for="f_status">Open as</label>
            <select id="f_status" name="status" required>
              <option value="draft" @selected(old('status', 'draft') === 'draft')>Draft — configure first, staff cannot sign in</option>
              <option value="active" @selected(old('status') === 'active')>Live — trading immediately</option>
            </select>
            <span class="hint">A draft still gets its full set of roles, permissions and base masters.</span>
          </div>
          <x-field name="business_date" label="Business (audit) date" type="date" :value="now()->toDateString()" hint="Required before a property can go live." />
          <x-field name="admin_username" label="First administrator — username" :value="old('admin_username', 'admin')" hint="They will administer this property only." />
          <x-field name="admin_name" label="Administrator name" :value="old('admin_name', 'Administrator')" />
          <x-field name="admin_email" label="Administrator e-mail" type="email" />
          <x-field name="admin_password" label="Administrator password" type="password" hint="At least 8 characters." />
        </div></div>
    @endif

    <div class="card mt-2"><div class="card-body">
      <p class="small muted">Opening a property provisions its own roles, permissions, chart of accounts, tax setup, departments, stores and base masters, and — when you give one — its first administrator. Nothing is shared with another property except the group account.</p>
      <button class="btn btn-primary btn-block">{{ $isNew ? 'Create the property' : 'Save the profile' }}</button>
      <a class="btn btn-ghost btn-block mt-1" href="{{ $isNew ? route('properties.index') : route('properties.show', $property) }}">Cancel</a>
    </div></div>
  </div>
</div>
</form>
@endsection
