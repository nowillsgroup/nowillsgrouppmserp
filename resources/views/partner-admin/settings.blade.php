@extends('layouts.app')
@section('title', 'Portal Settings')
@section('crumbs') Partner Portal / Settings @endsection
@section('content')
@if($errors->has('settings'))<div class="alert alert-error"><x-icon name="alert"/><div>{{ $errors->first('settings') }}</div></div>@endif
<form method="post" action="{{ route('partner-admin.settings.save') }}" id="ptaSettings">
  @csrf
  <div class="g-2">
    <div class="card"><div class="card-head"><h3><x-icon name="globe"/> Portal</h3></div><div class="card-body">
      <label class="check mb-1"><input type="checkbox" name="partner_enabled" value="1" @checked($s['partner_enabled'] === '1')> Partner portal on (partners can sign in)</label><br>
      <label class="check mb-1"><input type="checkbox" name="partner_cancel" value="1" @checked($s['partner_cancel'] === '1')> Partners may cancel online (house / contract fee applies)</label><br>
      <label class="check mb-1"><input type="checkbox" name="partner_amend" value="1" @checked($s['partner_amend'] === '1')> Partners may send change requests</label><br>
      <label class="check mb-1"><input type="checkbox" name="partner_pay" value="1" @checked($s['partner_pay'] === '1')> Partners may pay their account online</label>
      <div class="field mt-1"><label>Welcome line on the partner home page</label><input class="input" name="partner_welcome" value="{{ old('partner_welcome', $s['partner_welcome']) }}" maxlength="250"></div>
      <div class="field"><label>E-mail new approvals &amp; requests to</label><input class="input" type="email" name="partner_notify_email" value="{{ old('partner_notify_email', $s['partner_notify_email']) }}" placeholder="reservations@hotel.com"></div>
      <p class="tiny muted m-0">Portal address for partners: <code>{{ $loginUrl }}</code></p>
    </div></div>
    <div class="card"><div class="card-head"><h3><x-icon name="lock"/> Booking window &amp; security</h3></div><div class="card-body">
      <div class="g-2">
        <div class="field"><label>Book up to (days ahead)</label><input class="input" type="number" name="partner_window_days" min="30" max="730" value="{{ old('partner_window_days', $s['partner_window_days']) }}"></div>
        <div class="field"><label>Longest stay (nights)</label><input class="input" type="number" name="partner_max_nights" min="1" max="90" value="{{ old('partner_max_nights', $s['partner_max_nights']) }}"></div>
        <div class="field"><label>Sign out after idle (minutes)</label><input class="input" type="number" name="partner_idle" min="5" max="240" value="{{ old('partner_idle', $s['partner_idle']) }}"></div>
        <div class="field"><label>Invitation valid (days)</label><input class="input" type="number" name="partner_invite_days" min="1" max="30" value="{{ old('partner_invite_days', $s['partner_invite_days']) }}"></div>
      </div>
      <p class="tiny muted m-0">Partner passwords: 10+ characters with letters and numbers. {{ \App\Services\PartnerService::MAX_FAILS }} wrong passwords lock a login for {{ \App\Services\PartnerService::LOCK_MINUTES }} minutes. Reset links last 60 minutes. Partner sessions are separate from staff sign-in.</p>
    </div></div>
  </div>
  <button class="btn btn-primary" type="submit"><x-icon name="check"/> Save settings</button>
</form>
@endsection
