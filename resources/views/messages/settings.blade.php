@extends('layouts.app')
@section('title', 'SMS / WhatsApp Settings')
@section('crumbs') Administration / Messaging @endsection
@section('actions')<a class="btn" href="{{ route('messages.index') }}">Message log</a>@endsection
@section('content')
@php $val = fn ($k, $d = '') => old($k, $v[$k] ?? $d); @endphp
<div class="grid g-2-1">
<form method="post" action="{{ route('messages.settings.save') }}">@csrf
  <div class="card mb-1"><div class="card-head"><h3>Gateway</h3></div><div class="card-body form-grid">
    <label class="check span-all"><input type="checkbox" name="sms_enabled" value="1" @checked($val('sms_enabled', '0') === '1')> Messaging enabled (automatic messages and real gateways only work when this is on)</label>
    <x-field name="sms_driver" label="Provider" type="select" :options="\App\Services\MessageService::DRIVERS" :value="$val('sms_driver', 'log')" :placeholder="false"/>
    <x-field name="sms_default_channel" label="Automatic messages go by" type="select" :options="['sms' => 'SMS', 'whatsapp' => 'WhatsApp']" :value="$val('sms_default_channel', 'sms')" :placeholder="false"/>
    <x-field name="sms_sender" label="Sender ID (max 11 letters for SMS)" :value="$val('sms_sender', \Illuminate\Support\Str::limit(preg_replace('/[^A-Za-z0-9]/', '', hotel()->name), 11, ''))"/>
    <x-field name="sms_default_country_code" label="Default country code" :value="$val('sms_default_country_code', '+233')" hint="Local numbers like 024… become +233 24…" required/>
  </div></div>

  <div class="card mb-1"><div class="card-head"><h3>Twilio</h3><span class="small text-2">SMS and WhatsApp</span></div><div class="card-body form-grid">
    <x-field name="sms_twilio_sid" label="Account SID" :value="$val('sms_twilio_sid')" autocomplete="off"/>
    <div class="field"><label for="f_sms_twilio_token">Auth token</label><input type="password" name="sms_twilio_token" id="f_sms_twilio_token" autocomplete="new-password" placeholder="{{ $secretSet['sms_twilio_token'] ? '•••••••• saved – leave blank to keep' : '' }}"></div>
    <x-field name="sms_twilio_from" label="SMS from number" :value="$val('sms_twilio_from')" placeholder="+1…"/>
    <x-field name="sms_twilio_wa_from" label="WhatsApp sender number" :value="$val('sms_twilio_wa_from')" placeholder="+14155238886"/>
  </div></div>

  <div class="card mb-1"><div class="card-head"><h3>Hubtel</h3><span class="small text-2">SMS in Ghana</span></div><div class="card-body form-grid">
    <x-field name="sms_hubtel_client_id" label="Client ID" :value="$val('sms_hubtel_client_id')" autocomplete="off"/>
    <div class="field"><label for="f_sms_hubtel_client_secret">Client secret</label><input type="password" name="sms_hubtel_client_secret" id="f_sms_hubtel_client_secret" autocomplete="new-password" placeholder="{{ $secretSet['sms_hubtel_client_secret'] ? '•••••••• saved – leave blank to keep' : '' }}"></div>
  </div></div>

  <div class="card mb-1"><div class="card-head"><h3>Generic HTTP gateway</h3></div><div class="card-body form-grid">
    <x-field name="sms_http_url" label="Endpoint URL (JSON POST: to, from, message, channel)" :value="$val('sms_http_url')" class="span-all" placeholder="https://gateway.example.com/send"/>
    <div class="field span-all"><label for="f_sms_http_token">Bearer token</label><input type="password" name="sms_http_token" id="f_sms_http_token" autocomplete="new-password" placeholder="{{ $secretSet['sms_http_token'] ? '•••••••• saved – leave blank to keep' : '' }}"></div>
  </div></div>

  <div class="card mb-1"><div class="card-head"><h3>Automatic messages &amp; templates</h3></div><div class="card-body form-grid">
    <label class="check span-all"><input type="checkbox" name="sms_auto_confirmation" value="1" @checked($val('sms_auto_confirmation', '0') === '1')> Send the booking confirmation when a reservation is created</label>
    <label class="check span-all"><input type="checkbox" name="sms_auto_thankyou" value="1" @checked($val('sms_auto_thankyou', '0') === '1')> Send a thank-you after check-out</label>
    <x-field name="sms_pre_arrival_days" label="Pre-arrival message N days before arrival (0 = off)" type="number" min="0" max="30" :value="$val('sms_pre_arrival_days', '0')" class="span-all" hint="Sent daily at 10:00 by the scheduler (php artisan pms:pre-arrival). Each reservation gets it only once."/>
    @foreach(\App\Services\MessageService::TEMPLATES as $k => [$label, $default])
      <x-field :name="'sms_tpl_'.$k" :label="$label" type="textarea" rows="2" :value="$val('sms_tpl_'.$k) ?: $default" class="span-all"/>
    @endforeach
    <div class="span-all tiny text-2">Placeholders: {guest} {hotel} {res_no} {arrival} {departure} {checkin_link} {amount}</div>
  </div></div>
  @if(collect($secretSet)->contains(true))
  <div class="card mb-1"><div class="card-body">
    <div class="small text-2 mb-1">Remove saved secrets:</div>
    @foreach($secretSet as $k => $set) @if($set)<label class="check"><input type="checkbox" name="clear_secrets[]" value="{{ $k }}"> {{ ['sms_twilio_token' => 'Twilio token', 'sms_hubtel_client_secret' => 'Hubtel secret', 'sms_http_token' => 'Gateway token'][$k] }}</label>@endif @endforeach
  </div></div>
  @endif
  <button class="btn btn-primary">Save settings</button>
</form>
<div>
  <div class="card"><div class="card-head"><h3>Send a test</h3></div><div class="card-body">
    <form method="post" action="{{ route('messages.test') }}" class="form-grid">@csrf
      <x-field name="to" label="Mobile number" required placeholder="024 000 0000" class="span-all"/>
      <x-field name="channel" label="Channel" type="select" :options="['sms' => 'SMS', 'whatsapp' => 'WhatsApp']" :placeholder="false" class="span-all"/>
      <button class="btn span-all"><x-icon name="message"/> Send test</button>
    </form>
    <p class="tiny text-2 mt-1">Secrets are stored encrypted with the application key. With the <b>Log only</b> provider nothing leaves the server – use it for training.</p>
  </div></div>
</div>
</div>
@endsection
