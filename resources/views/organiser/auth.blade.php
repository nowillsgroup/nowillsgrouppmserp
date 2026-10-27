<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
<title>@yield('title') · Organiser Portal</title>
<link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
<link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}?v={{ filemtime(public_path('favicon.svg')) }}">
</head><body>
@include('partials.icons')
<div class="auth">
  <div class="auth-art" style="background:linear-gradient(140deg,#10271c,#166534)">
    <div class="brand" style="padding:0;border:0;height:auto"><img class="brand-logo-auth-mark" src="{{ asset('images/brand/nowills-pms-mark-white.png') }}" alt=""><div>Organiser Portal<small>MEETINGS · EVENTS · WEDDINGS</small></div></div>
    <div>
      <h1>Your events, BEOs, guest lists and payments – in one place.</h1>
      <p>Follow every function, sign the banquet event order online, set guest-number guarantees, keep the guest list, request changes and pay deposits – any time.</p>
    </div>
    <div class="auth-tiles">
      <div><x-icon name="calendar"/><br>Events</div><div><x-icon name="edit"/><br>Sign BEO</div><div><x-icon name="users"/><br>Guests</div><div><x-icon name="wallet"/><br>Pay online</div><div><x-icon name="message"/><br>Requests</div>
    </div>
  </div>
  <div class="auth-form">
    @yield('form')
  </div>
</div>
</body></html>
