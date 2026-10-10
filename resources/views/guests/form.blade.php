@extends('layouts.app')
@section('title', $guest->exists ? 'Edit Guest' : 'New Guest')
@section('crumbs') <a href="{{ route('guests.index') }}">Guests</a> / {{ $guest->exists ? $guest->full_name : 'New' }} @endsection
@section('content')
<form method="post" action="{{ $guest->exists ? route('guests.update', $guest) : route('guests.store') }}" class="card">@csrf @if($guest->exists) @method('PUT') @endif
<div class="card-body form-grid">
  <div class="form-section">Personal</div>
  <x-field name="title" label="Title" type="select" :options="['Mr'=>'Mr','Mrs'=>'Mrs','Ms'=>'Ms','Dr'=>'Dr']" :value="$guest->title"/>
  <x-field name="first_name" label="First name" :value="$guest->first_name" required/>
  <x-field name="last_name" label="Last name" :value="$guest->last_name"/>
  <x-field name="gender" label="Gender" type="select" :options="['Male'=>'Male','Female'=>'Female','Other'=>'Other']" :value="$guest->gender"/>
  <x-field name="dob" label="Date of birth" type="date" :value="$guest->dob?->toDateString()"/>
  <x-field name="anniversary" label="Anniversary" type="date" :value="$guest->anniversary?->toDateString()"/>
  <div class="form-section">Contact</div>
  <x-field name="phone" label="Mobile" :value="$guest->phone"/>
  <x-field name="email" label="Email" type="email" :value="$guest->email"/>
  <x-field name="address" label="Address" :value="$guest->address" class="span-2"/>
  <x-field name="city" label="City" :value="$guest->city"/><x-field name="state" label="State" :value="$guest->state"/>
  <x-field name="country" label="Country" :value="$guest->country"/><x-field name="pincode" label="Postal code / GPS address" :value="$guest->pincode"/>
  <div class="form-section">Identity & compliance</div>
  <x-field name="nationality" label="Nationality" :value="$guest->nationality"/>
  <x-field name="id_type" label="ID type" type="select" :options="config('pms.id_types')" :value="$guest->id_type"/>
  <x-field name="id_number" label="ID number" :value="$guest->id_number"/>
  <x-field name="passport_no" label="Passport no." :value="$guest->passport_no"/>
  <x-field name="visa_expiry" label="Visa expiry" type="date" :value="$guest->visa_expiry?->toDateString()"/>
  <x-field name="tax_number" label="TIN / Ghana Card PIN" :value="$guest->tax_number"/>
  <div class="form-section">CRM</div>
  <x-field name="company_id" label="Company" type="select" :options="$companies" :value="$guest->company_id"/>
  <x-field name="preferences" label="Preferences" :value="$guest->preferences" placeholder="High floor, feather pillow, vegetarian…" class="span-2"/>
  <x-field name="notes" label="Internal notes" type="textarea" :value="$guest->notes" class="span-all"/>
  <label class="check"><input type="checkbox" name="is_vip" value="1" @checked(old('is_vip', $guest->is_vip))> VIP guest</label>
  <label class="check"><input type="checkbox" name="is_blacklisted" value="1" @checked(old('is_blacklisted', $guest->is_blacklisted))> Blacklisted (blocks bookings)</label>
  <x-field name="tags" label="Tags" :value="$guest->tags" placeholder="vip, corporate, wedding-2026, foodie" hint="Comma separated – used by marketing segments." class="span-2"/>
  <div class="form-section span-all">Marketing consent</div>
  <input type="hidden" name="consent_form" value="1">
  <div class="seg span-all" id="consentBoxes">
    @foreach(\App\Services\CrmService::CHANNELS as $ch => $label)
      <label class="check"><input type="checkbox" name="{{ $ch }}_opt_in" value="1" @checked(old($ch.'_opt_in', $guest->{$ch.'_opt_in'}))> {{ $label }} offers</label>
    @endforeach
  </div>
  <p class="tiny muted span-all m-0">Tick only with the guest's explicit agreement. Every change is recorded with your name and the time; guests can opt out from any message.</p>
</div>
<div class="card-foot"><button class="btn btn-primary">Save Guest</button> <a href="{{ url()->previous() }}" class="btn btn-ghost">Cancel</a></div>
</form>
@endsection
