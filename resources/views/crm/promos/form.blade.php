@extends('layouts.app')
@section('title', $promo->exists ? 'Edit Promo Code' : 'New Promo Code')
@section('crumbs') <a href="{{ route('crm.index') }}">Marketing</a> / <a href="{{ route('crm.promos.index') }}">Promo codes</a> / {{ $promo->exists ? $promo->code : 'New' }} @endsection
@section('content')
<form method="post" action="{{ $promo->exists ? route('crm.promos.update', $promo) : route('crm.promos.store') }}" class="card" id="promoForm" style="max-width:900px">@csrf @if($promo->exists) @method('PUT') @endif
<div class="card-head"><h3>Promo code</h3></div>
<div class="card-body form-grid">
  <x-field name="code" label="Code" :value="$promo->code" required placeholder="HARVEST15" hint="Letters, digits, - and _. Guests type it on the booking engine." style="text-transform:uppercase"/>
  <x-field name="name" label="Offer name" :value="$promo->name" required placeholder="Harvest weekend escape" class="span-2"/>
  <x-field name="discount_type" label="Discount type" type="select" :options="['percent' => '% off the room rate', 'amount' => 'Fixed amount off per room-night']" :value="$promo->discount_type" :placeholder="false" required/>
  <x-field name="value" label="Discount" type="number" step="0.01" min="0.01" :value="$promo->value" required hint="Percent (max 90) or amount per night."/>
  <x-field name="min_nights" label="Minimum nights" type="number" min="1" max="30" :value="$promo->min_nights" required/>
  <div class="form-section span-all">Validity</div>
  <x-field name="book_from" label="Bookable from" type="date" :value="$promo->book_from?->toDateString()"/>
  <x-field name="book_to" label="Bookable until" type="date" :value="$promo->book_to?->toDateString()" hint="Business date the booking is made."/>
  <div></div>
  <x-field name="stay_from" label="Arrivals from" type="date" :value="$promo->stay_from?->toDateString()"/>
  <x-field name="stay_to" label="Arrivals until" type="date" :value="$promo->stay_to?->toDateString()"/>
  <div></div>
  <div class="form-section span-all">Limits</div>
  <x-field name="max_uses" label="Total uses" type="number" min="1" :value="$promo->max_uses" placeholder="Unlimited"/>
  <x-field name="per_guest_limit" label="Uses per guest" type="number" min="1" max="50" :value="$promo->per_guest_limit" placeholder="Unlimited"/>
  <div></div>
  <div class="field span-all"><label>Room types</label>
    <div class="seg">@foreach($types as $id => $n)<label class="check"><input type="checkbox" name="room_type_ids[]" value="{{ $id }}" @checked(in_array($id, array_map('intval', old('room_type_ids', $promo->room_type_ids ?? []))))> {{ $n }}</label>@endforeach</div>
    <span class="hint">None ticked = valid for every room type shown online.</span></div>
  <label class="check span-all"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $promo->is_active))> Active</label>
</div>
<div class="card-foot"><button class="btn btn-primary" id="savePromo"><x-icon name="check"/> Save promo code</button> <a class="btn btn-ghost" href="{{ route('crm.promos.index') }}">Cancel</a></div>
</form>
@endsection
