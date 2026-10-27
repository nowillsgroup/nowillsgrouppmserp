<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Two-factor verification · Nowills PMS</title>
<link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
<link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}?v={{ filemtime(public_path('favicon.svg')) }}">
</head><body>
@include('partials.icons')
<div class="auth" style="grid-template-columns:1fr">
  <div class="auth-form">
    <form class="auth-card" method="post" action="{{ route('login.2fa.verify') }}" autocomplete="off" id="tfForm">
      @csrf
      <div class="brand brand-pms-auth" style="padding:0;border:0;height:auto;margin-bottom:1rem"><img class="brand-logo-auth" src="{{ asset('images/brand/nowills-pms-logo-white.png') }}" alt="Nowills PMS — Cloud Hospitality Suite"></div>
      <h2><x-icon name="shield"/> Two-factor verification</h2>
      <p class="text-2 mb-2" id="tfHelp">Open your authenticator app and enter the 6-digit code for <b>Nowills PMS</b>.</p>
      @if($errors->any())<div class="alert alert-error"><x-icon name="alert"/><div>{{ $errors->first() }}</div></div>@endif
      <div class="field" id="codeField"><label for="code">Authentication code</label>
        <input class="input" id="code" name="code" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" autofocus style="font-size:1.6rem;letter-spacing:.4em;text-align:center"></div>
      <div class="field" id="recField" hidden><label for="recovery_code">Recovery code</label>
        <input class="input" id="recovery_code" name="recovery_code" maxlength="20" placeholder="xxxxxx-xxxxxx" style="font-family:var(--mono)"></div>
      <button class="btn btn-primary btn-lg btn-block" type="submit"><x-icon name="check"/> Verify &amp; sign in</button>
      <p class="small center mt-2"><a href="#" id="toggleRec">Lost your phone? Use a recovery code</a></p>
      <p class="small muted center"><a href="{{ route('login') }}">Back to sign in</a></p>
    </form>
  </div>
</div>
<script>
document.getElementById('toggleRec').addEventListener('click', e => {
  e.preventDefault();
  const c = document.getElementById('codeField'), r = document.getElementById('recField'), rec = c.hidden;
  c.hidden = !rec; r.hidden = rec;
  document.getElementById('code').value = ''; document.getElementById('recovery_code').value = '';
  (rec ? document.getElementById('code') : document.getElementById('recovery_code')).focus();
  e.target.textContent = rec ? 'Lost your phone? Use a recovery code' : 'Use the authenticator code instead';
});
</script>
</body></html>
