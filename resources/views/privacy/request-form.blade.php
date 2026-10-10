@extends('layouts.app')
@section('title', 'Log privacy request')
@section('crumbs') <a href="{{ route('privacy.index') }}">Privacy</a> / New request @endsection
@section('content')
<form method="post" action="{{ route('privacy.requests.store') }}" class="card" id="requestForm" style="max-width:980px">@csrf
  <div class="card-head"><h3>Data-subject request</h3><span class="tiny muted">Reply due {{ $settings['privacy_response_days'] }} days after receipt</span></div>
  <div class="card-body form-grid">
    <x-field name="type" label="Request type" type="select" :options="\App\Models\PrivacyRequest::TYPES" :value="$req->type" required :placeholder="false"/>
    <x-field name="channel" label="Received via" type="select" :options="\App\Models\PrivacyRequest::CHANNELS" :value="$req->channel" required :placeholder="false"/>
    <x-field name="received_at" label="Received on" type="date" :value="now()->toDateString()" required :max="now()->toDateString()"/>
    <x-field name="requester_name" label="Requester name" :value="$req->requester_name" required/>
    <x-field name="requester_email" label="E-mail" type="email" :value="$req->requester_email"/>
    <x-field name="requester_phone" label="Mobile" :value="$req->requester_phone"/>
    <x-field name="relationship" label="Requester is" type="select" :options="['self' => 'The guest themself', 'nominee' => 'Nominee (guest deceased / incapacitated)', 'authorised' => 'Authorised agent / guardian']" value="self" required :placeholder="false"/>
    <x-field name="booking_ref" label="Booking / stay reference" :value="$req->booking_ref"/>
    <x-field name="guest_id" label="Guest profile ID" type="number" :value="$req->guest_id" hint="{{ $guest ? 'Linked: '.$guest->full_name : 'Optional – matched by e-mail / mobile if left blank' }}"/>
    <x-field name="details" label="Details of the request" type="textarea" :value="$req->details" class="span-all" placeholder="What the person asked for, in their words (e.g. which details are wrong, which consent to withdraw)"/>
    <x-field name="verification_method" label="Identity already verified?" type="select" :options="\App\Models\PrivacyRequest::METHODS" placeholder="Not yet" hint="e.g. the guest is at the desk with photo ID"/>
  </div>
  <div class="card-foot"><button class="btn btn-primary" id="saveRequest"><x-icon name="check"/> Log request</button> <a class="btn" href="{{ route('privacy.index') }}">Cancel</a></div>
</form>
@endsection
