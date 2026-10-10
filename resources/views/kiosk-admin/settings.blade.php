@extends('layouts.app')
@section('title', 'Kiosk Settings')
@section('crumbs') Self-Service Kiosk / Settings @endsection
@php $KS = \App\Services\KioskService::class; $cb = fn ($k, $on, $label) => '<label class="small tv-cb"><input type="checkbox" name="'.$k.'" value="1" '.($on ? 'checked' : '').'> '.e($label).'</label>'; @endphp
@section('content')
@if(! $payOn)<div class="alert alert-warn"><x-icon name="alert"/><div>Online payments are switched off, so the kiosk cannot take deposits or walk-in bookings – guests with something to pay are sent to the desk. <a href="{{ route('payments.settings') }}">Online payment settings</a></div></div>@endif
@if(! $lockOn)<div class="alert alert-info"><x-icon name="key"/><div>The door-lock interface is off – kiosk guests collect their key cards at the desk.</div></div>@endif
<form method="post" action="{{ route('kiosks.settings.save') }}" id="kskSettingsForm">@csrf
<div class="g-2">
  <div>
    <div class="card"><div class="card-head"><h3>Services</h3></div><div class="card-body form-grid">
      <div class="span-all">{!! $cb('kiosk_enabled', $cfg['enabled'], 'Self-service kiosks switched on') !!}{!! $cb('kiosk_checkin', $cfg['checkin'], 'Self check-in') !!}{!! $cb('kiosk_checkout', $cfg['checkout'], 'Self check-out') !!}{!! $cb('kiosk_walkin', $cfg['walkin'], 'Walk-in booking (paid in full by QR)') !!}{!! $cb('kiosk_feedback', $cfg['feedback'], 'Star rating after check-out') !!}</div>
      <div class="field span-all"><label>Welcome line on the home screen</label><input name="kiosk_welcome" class="input" maxlength="200" value="{{ $cfg['welcome'] }}"></div>
      <div class="field"><label>Reset screen after (seconds idle)</label><input type="number" name="kiosk_idle" class="input" min="30" max="600" value="{{ $cfg['idle'] }}"></div>
      <div class="field"><label>Payment QR valid for (minutes)</label><input type="number" name="kiosk_pay_minutes" class="input" min="5" max="60" value="{{ $cfg['pay_minutes'] }}"></div>
    </div></div>
    <div class="card"><div class="card-head"><h3>Check-in rules</h3></div><div class="card-body form-grid">
      <div class="field"><label>Self check-in from</label><input type="time" name="kiosk_checkin_from" class="input" value="{{ $cfg['checkin_from'] }}"></div>
      <div class="field"><label>Rooms the kiosk may hand out</label><select name="kiosk_room_ready" class="input">@foreach($KS::READY as $k => $l)<option value="{{ $k }}" @selected($cfg['room_ready'] === $k)>{{ $l }}</option>@endforeach</select></div>
      <div class="span-all">{!! $cb('kiosk_early', $cfg['early'], 'Allow early check-in when a ready room is free') !!}{!! $cb('kiosk_room_choice', $cfg['room_choice'], 'Let guests choose from up to 4 ready rooms') !!}{!! $cb('kiosk_require_id', $cfg['require_id'], 'Photograph the ID document (unless already on file)') !!}{!! $cb('kiosk_require_sign', $cfg['require_sign'], 'Sign the registration card on screen') !!}{!! $cb('kiosk_vip_desk', $cfg['vip_desk'], 'Send VIP guests to the desk for a personal welcome') !!}</div>
      <div class="field"><label>Collect at check-in</label><select name="kiosk_deposit" class="input">@foreach($KS::DEPOSITS as $k => $l)<option value="{{ $k }}" @selected($cfg['deposit'] === $k)>{{ $l }}</option>@endforeach</select><small class="muted">company-billed bookings pay the incidentals deposit only</small></div>
      <div class="field"><label>Incidentals deposit (GH₵, refundable)</label><input type="number" step="0.01" name="kiosk_incidentals" class="input" min="0" max="100000" value="{{ $cfg['incidentals'] }}"></div>
      <div class="field"><label>Key cards a guest may encode</label><input type="number" name="kiosk_max_keys" class="input" min="1" max="4" value="{{ $cfg['max_keys'] }}"></div>
      <div class="field span-all"><label>Line on the welcome screen</label><input name="kiosk_breakfast" class="input" maxlength="200" value="{{ $breakfast }}"></div>
      <div class="field span-all"><label>Registration terms the guest signs</label><textarea name="kiosk_terms" rows="4" maxlength="1500" required>{{ $cfg['terms'] }}</textarea></div>
    </div></div>
  </div>
  <div>
    <div class="card"><div class="card-head"><h3>Check-out</h3></div><div class="card-body form-grid">
      <div class="span-all">{!! $cb('kiosk_early_checkout', $cfg['early_checkout'], 'Allow check-out before the booked departure date') !!}</div>
      <p class="small muted span-all m-0">Guests review the bill, pay any balance by QR and check out; refunds, split bills and anything that blocks check-out (unsettled restaurant bills, Form C not filed…) go to the desk.</p>
    </div></div>
    <div class="card"><div class="card-head"><h3>Walk-in bookings</h3></div><div class="card-body form-grid">
      <div class="field"><label>Longest stay (nights)</label><input type="number" name="kiosk_walkin_nights" class="input" min="1" max="30" value="{{ $cfg['walkin_nights'] }}"></div>
      <div class="field"><label>Most adults per room</label><input type="number" name="kiosk_walkin_adults" class="input" min="1" max="6" value="{{ $cfg['walkin_adults'] }}"></div>
      <p class="small muted span-all m-0">Only room types with a ready room today are offered, at today's best available rate incl. taxes; the whole stay is paid by QR before the key is encoded. A walk-in that is not paid within the visit is released automatically.</p>
    </div></div>
    <div class="card"><div class="card-head"><h3>Security & privacy</h3></div><div class="card-body small">
      <ul class="m-0">
        <li>A booking is only shown after the booking / room number <b>and</b> the surname match, or the pre-check-in QR code is scanned; after {{ $KS::LOOKUP_LIMIT }} misses in 10 minutes the kiosk pauses look-ups, and three misses in one visit call the desk.</li>
        <li>Phone, e-mail and ID numbers are masked on screen; the guest types new values instead of seeing the old ones.</li>
        <li>A visit ends after {{ $KS::SESSION_IDLE_MIN }} minutes without a tap, and the screen resets after the idle time above.</li>
        <li>Card and mobile-money details never touch the kiosk – the guest pays on their own phone through the payment gateway.</li>
      </ul>
    </div></div>
  </div>
</div>
<button class="btn btn-primary">Save settings</button>
</form>
@endsection
