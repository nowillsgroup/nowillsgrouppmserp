@extends('layouts.app')
@section('title', 'Two-factor authentication')
@section('crumbs') <a href="{{ route('profile') }}">My Profile</a> / Two-factor authentication @endsection
@section('content')
@if($codes)
<div class="card" style="border-color:var(--c-amber)"><div class="card-head"><h3><x-icon name="key"/> Your recovery codes</h3></div><div class="card-body">
  <p class="small">Keep these somewhere safe, such as a password manager or a printed copy in the safe. Each code works <b>once</b> if you lose your phone. <b>They will not be shown again.</b></p>
  <div id="recCodes" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:.4rem;font-family:var(--mono);font-size:1rem;background:var(--c-slate-50);padding:.8rem;border-radius:var(--radius-sm)">
    @foreach($codes as $c)<div>{{ $c }}</div>@endforeach
  </div>
  <button type="button" class="btn btn-sm mt-2" onclick="navigator.clipboard?.writeText(document.getElementById('recCodes').innerText)"><x-icon name="download" size="sm"/> Copy</button>
  <button type="button" class="btn btn-sm mt-2" onclick="window.print()"><x-icon name="print" size="sm"/> Print</button>
</div></div>
@endif

@if($user->hasTwoFactor())
<div class="grid g-2">
  <div class="card"><div class="card-head"><h3><x-icon name="shield"/> Two-factor authentication</h3><span class="badge badge-green">On</span></div><div class="card-body">
    <p class="small">Enabled {{ $user->two_factor_confirmed_at->format('d M Y H:i') }}. Every sign-in needs your password <b>and</b> a code from your authenticator app.</p>
    <p class="small">Recovery codes left: <b>{{ count($user->two_factor_recovery_codes ?? []) }}</b> of 8.</p>
    <form method="post" action="{{ route('profile.2fa.recovery') }}" class="filters">@csrf
      <div class="field"><label>Current password</label><input type="password" name="password" required autocomplete="current-password"></div>
      <button class="btn"><x-icon name="refresh" size="sm"/> New recovery codes</button>
    </form>
  </div></div>
  <div class="card"><div class="card-head"><h3>Turn off</h3></div><div class="card-body">
    @if($required)
      <div class="alert alert-info small"><div>Two-factor authentication is <b>mandatory for administrators</b> at this property, so it cannot be turned off here. If you change phones, generate new recovery codes or ask another administrator to reset it.</div></div>
    @else
      <p class="small muted">Your account will be protected by the password only.</p>
      <form method="post" action="{{ route('profile.2fa.disable') }}" class="filters" data-confirm="Turn off two-factor authentication?">@csrf
        <div class="field"><label>Current password</label><input type="password" name="password" required autocomplete="current-password"></div>
        <button class="btn btn-danger">Turn off 2FA</button>
      </form>
    @endif
  </div></div>
</div>
@else
<div class="card" style="max-width:860px"><div class="card-head"><h3><x-icon name="shield"/> Set up two-factor authentication</h3><span class="badge badge-amber">Off</span></div><div class="card-body">
  @if($required)<div class="alert alert-warn small"><x-icon name="alert"/><div>Your property requires administrators to use two-factor authentication. Finish the set-up to continue using the system.</div></div>@endif
  <div class="grid g-2">
    <div>
      <ol class="small" style="padding-left:1.1rem;line-height:1.7">
        <li>Install an authenticator app on your phone: Google Authenticator, Microsoft Authenticator, Authy or 1Password.</li>
        <li>In the app, choose <b>Add account → Scan QR code</b> and scan the code shown here.</li>
        <li>Type the 6-digit code the app shows and press <b>Enable</b>.</li>
      </ol>
      <p class="tiny muted">Can't scan? Enter this key manually (time-based):</p>
      <code id="tfSecret" style="display:block;font-size:.95rem;word-break:break-all;background:var(--c-slate-50);padding:.5rem;border-radius:6px">{{ trim(chunk_split($secret, 4, ' ')) }}</code>
      <form method="post" action="{{ route('profile.2fa.enable') }}" class="filters" style="margin-top:1rem">@csrf
        <div class="field"><label>6-digit code</label><input name="code" inputmode="numeric" maxlength="7" required autocomplete="one-time-code" style="letter-spacing:.3em;font-size:1.2rem;width:160px"></div>
        <button class="btn btn-primary"><x-icon name="check" size="sm"/> Enable</button>
      </form>
      @error('code')<div class="alert alert-error small mt-2">{{ $message }}</div>@enderror
    </div>
    <div class="center"><img src="{{ $qr }}" alt="QR code for your authenticator app" id="tfQr" style="width:220px;height:220px;background:#fff;border:1px solid var(--c-border);border-radius:var(--radius)"></div>
  </div>
</div></div>
@endif
@endsection
