@extends('layouts.app')
@section('title', 'Messaging Settings')
@section('crumbs') Guest Messaging / Settings @endsection
@php $C = \App\Models\Conversation::class; $cb = fn ($k, $on, $label) => '<label class="small tv-cb"><input type="checkbox" name="'.$k.'" value="1" '.($on ? 'checked' : '').'> '.e($label).'</label>'; @endphp
@section('content')
@if(session('secret'))<div class="alert alert-success" id="inbSecret"><x-icon name="key"/><div>Webhook secret: <code class="bold">{{ session('secret') }}</code><div class="tiny">Copy it now – it is stored encrypted and not shown again.</div></div></div>@endif
<div class="g-2-1">
  <form method="post" action="{{ route('inbox.settings.save') }}" id="inbSettingsForm">@csrf
    <div class="card"><div class="card-head"><h3>Guest messaging</h3></div><div class="card-body form-grid">
      <div class="span-all">{!! $cb('inbox_enabled', $cfg['enabled'], 'Guest messaging switched on (inbox, inbound WhatsApp / SMS / e-mail)') !!}{!! $cb('inbox_portal_chat', $cfg['portal_chat'], 'Show the Chat page in the guest portal') !!}{!! $cb('inbox_csat', $cfg['csat'], 'Ask the guest to rate the chat when it is closed') !!}</div>
      <div class="field"><label>Guest services hours from</label><input type="time" name="inbox_hours_from" class="input" value="{{ $cfg['from'] }}" required></div>
      <div class="field"><label>to</label><input type="time" name="inbox_hours_to" class="input" value="{{ $cfg['to'] }}" required></div>
      <div class="field"><label>Response-time target (minutes)</label><input type="number" name="inbox_sla" class="input" min="1" max="240" value="{{ $cfg['sla'] }}" required><small class="muted">conversations past it turn red and count in the sidebar badge</small></div>
      <div class="field"><label>Close "waiting for guest" after (hours)</label><input type="number" name="inbox_auto_close" class="input" min="1" max="168" value="{{ $cfg['auto_close'] }}" required></div>
      <div class="field span-all"><label>New conversations go to</label><select name="inbox_default_dept" class="input">@foreach($C::DEPARTMENTS as $k => $l)<option value="{{ $k }}" @selected($cfg['dept'] === $k)>{{ $l }}</option>@endforeach</select></div>
    </div></div>
    <div class="card"><div class="card-head"><h3>Automatic replies</h3></div><div class="card-body form-grid">
      <div class="span-all">{!! $cb('inbox_after_hours', $cfg['after_hours'], 'Reply automatically outside guest services hours') !!}</div>
      <div class="field span-all"><label>Outside-hours reply</label><textarea name="inbox_after_hours_reply" rows="3" maxlength="600">{{ $cfg['after_hours_reply'] }}</textarea></div>
      <div class="span-all">{!! $cb('inbox_ack', $cfg['ack'], 'Acknowledge the first message of every new conversation') !!}</div>
      <div class="field span-all"><label>Acknowledgement</label><textarea name="inbox_ack_reply" rows="2" maxlength="600">{{ $cfg['ack_reply'] }}</textarea><small class="muted">Placeholders: {guest} {first_name} {room} {hotel} {from} {to}. A routing rule's own answer takes priority; each automatic reply is sent at most once per conversation per hour.</small></div>
      <div class="span-all"><button class="btn btn-primary" id="inbSettingsSave">Save settings</button></div>
    </div></div>
  </form>
  <div>
    <div class="card" id="inbHooks"><div class="card-head"><h3>Inbound WhatsApp / SMS</h3></div><div class="card-body small">
      <p class="mt-0">Point your messaging gateway's <i>incoming message</i> and <i>status</i> webhooks at:</p>
      <div class="field"><label>Twilio (WhatsApp &amp; SMS)</label><input class="input" readonly value="{{ $hooks['twilio'] }}" onclick="this.select()"></div>
      <div class="field mt-1"><label>Generic JSON gateway</label><input class="input" readonly value="{{ $hooks['generic'] }}" onclick="this.select()"></div>
      <p class="tiny muted">Twilio requests are verified with the X-Twilio-Signature (auth token from Messaging settings); generic requests must carry <code>X-Nowills-Signature: sha256=HMAC-SHA256(body, secret)</code>. Unsigned or wrongly signed calls are refused.</p>
      <p class="m-0">Secret: <b>{{ $hasSecret ? 'set' : 'not set – inbound generic webhooks are refused' }}</b></p>
      <form method="post" action="{{ route('inbox.settings.secret') }}" class="mt-1" onsubmit="return confirm('Create a new secret? The old one stops working immediately.')">@csrf<button class="btn btn-sm" id="inbSecretBtn"><x-icon name="key" size="sm"/> {{ $hasSecret ? 'Rotate secret' : 'Create secret' }}</button></form>
    </div></div>
    <div class="card"><div class="card-head"><h3>E-mail</h3></div><div class="card-body small">Replies to hotel e-mails reach the inbox through the same generic webhook (<code>channel: email</code>) from your mail provider's inbound-parse service. Staff replies are sent with the hotel's outgoing mail settings.</div></div>
    <div class="card"><div class="card-head"><h3>Privacy</h3></div><div class="card-body small"><ul class="m-0"><li>Guests are matched by phone number / e-mail; unknown senders stay unmatched until staff link them.</li><li>SMS and WhatsApp are only sent to guests who have not opted out; the 24-hour WhatsApp window is respected.</li><li>Guests may reply STOP to opt out – it is recorded in the consent log.</li><li>Internal notes are never shown to guests.</li></ul></div></div>
  </div>
</div>
@endsection
