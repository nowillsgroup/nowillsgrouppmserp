@extends('public.layout')
@section('title', 'Privacy & your data')
@section('content')
<div class="grid g-2" style="align-items:start">
  <div class="card"><div class="card-head"><h3><x-icon name="shield"/> Privacy notice</h3></div><div class="card-body">
    <div class="notice-text small" id="privacyNotice">{{ $settings['privacy_notice'] }}</div>
    @if($settings['dpo_name'] || $settings['dpo_email'])
    <p class="small mt-1"><b>Data Protection / Grievance Officer:</b> {{ $settings['dpo_name'] }}{{ $settings['dpo_email'] ? ' · '.$settings['dpo_email'] : '' }}{{ $settings['dpo_phone'] ? ' · '.$settings['dpo_phone'] : '' }}</p>@endif
    <p class="tiny muted">We reply within {{ $settings['privacy_response_days'] }} days. If you are not satisfied with our reply you may complain to the Data Protection Commission of Ghana (Data Protection Act 2012, Act 843).</p>
  </div></div>
  <form method="post" action="{{ route('privacy.public.submit', $hotel->code) }}" class="card" id="privacyForm">@csrf
    <div class="card-head"><h3>Make a request about your data</h3></div>
    <div class="card-body form-grid" style="grid-template-columns:1fr 1fr">
      <x-field name="type" label="I would like to" type="select" :options="['access' => 'Get a copy of my data', 'correction' => 'Correct my data', 'erasure' => 'Delete (erase) my data', 'consent' => 'Stop marketing / withdraw consent', 'grievance' => 'Raise a grievance']" required class="span-all"/>
      <x-field name="requester_name" label="Full name" required/>
      <x-field name="requester_email" label="E-mail" type="email" required hint="We send a confirmation link here"/>
      <x-field name="requester_phone" label="Mobile (optional)"/>
      <x-field name="booking_ref" label="Booking reference (optional)" hint="Helps us find your records"/>
      <x-field name="relationship" label="I am" type="select" :options="['self' => 'The guest', 'nominee' => 'A nominee of the guest', 'authorised' => 'Acting for the guest (authorised)']" value="self" required :placeholder="false" class="span-all"/>
      <x-field name="details" label="Details" type="textarea" class="span-all" placeholder="Tell us what you need – e.g. which details are wrong"/>
      <div style="position:absolute;left:-9999px" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>
      <label class="check span-all small"><input type="checkbox" name="confirm" value="1" required> The information above is accurate. I understand the hotel may ask me to confirm my identity.</label>
    </div>
    <div class="card-foot"><button class="btn btn-primary" id="sendPrivacy">Send request</button></div>
  </form>
</div>
@endsection
