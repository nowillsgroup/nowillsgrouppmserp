@extends('layouts.app')
@section('title', 'Marketing Settings')
@section('crumbs') <a href="{{ route('crm.index') }}">Marketing</a> / Settings @endsection
@section('content')
<form method="post" action="{{ route('crm.settings.save') }}" id="crmSettings">@csrf
<div class="grid g-2-1">
  <div class="card"><div class="card-head"><h3>Sending rules</h3></div><div class="card-body form-grid">
    <x-field name="crm_frequency_cap" label="Frequency cap (campaigns / guest / 7 days)" type="number" min="0" max="14" :value="$s['crm_frequency_cap']" required hint="Guests who already received this many campaign messages in the last 7 days are skipped. 0 = no cap. Automations are not capped."/>
    <x-field name="crm_attribution_days" label="Attribution window (days)" type="number" min="0" max="180" :value="$s['crm_attribution_days']" required hint="A booking made within this many days of a message is credited to it (links and promo codes are always credited)."/>
    <x-field name="crm_sms_optout" label="SMS / WhatsApp opt-out line" :value="$s['crm_sms_optout']" required class="span-all" hint="Appended to every SMS / WhatsApp marketing message. Must contain {unsubscribe_link}."/>
    <x-field name="crm_consent_text" label="Consent checkbox wording (booking engine)" type="textarea" rows="3" :value="$s['crm_consent_text']" required class="span-all"/>
  </div></div>
  <div class="card"><div class="card-head"><h3>Compliance</h3></div><div class="card-body small">
    <ul class="chk-list" style="font-size:.85rem">
      <li>Marketing goes only to guests who opted in on that channel – never to blacklisted guests.</li>
      <li>Every e-mail carries an unsubscribe link plus one-click <code>List-Unsubscribe</code> headers; every SMS carries the opt-out line.</li>
      <li>Opt-ins and opt-outs are logged with source, time, user and IP on the guest profile.</li>
      <li>Booking confirmations, receipts and check-in links are service messages and are not affected by marketing consent.</li>
    </ul>
    <p class="muted">Delivery uses the providers in @if(can_do('settings.manage'))<a href="{{ route('messages.settings') }}">Messaging settings</a>@else Messaging settings @endif (SMS / WhatsApp) and the mail configuration (e-mail).</p>
  </div></div>
</div>
<div class="flex" style="justify-content:flex-end"><button class="btn btn-primary" id="saveCrm"><x-icon name="check"/> Save settings</button></div>
</form>
@endsection
