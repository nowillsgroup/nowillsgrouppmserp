<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
<title>@yield('title') · Partner Portal</title>
<link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
<link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}?v={{ filemtime(public_path('favicon.svg')) }}">
</head><body>
@include('partials.icons')
<div class="auth">
  <div class="auth-art" style="background:linear-gradient(140deg,#10271c,#166534)">
    <div class="brand" style="padding:0;border:0;height:auto"><div class="brand-mark">N</div><div>Partner Portal<small>CORPORATE &amp; TRAVEL AGENTS</small></div></div>
    <div>
      <h1>Your negotiated rates, bookings and account – in one place.</h1>
      <p>Book contracted rates against live availability, manage your travellers' stays, request changes, download invoices and statements, and settle your account online.</p>
    </div>
    <div class="auth-tiles">
      <div><x-icon name="calendar"/><br>Book</div><div><x-icon name="book"/><br>Manage</div><div><x-icon name="receipt"/><br>Invoices</div><div><x-icon name="wallet"/><br>Pay online</div><div><x-icon name="users"/><br>Bookers</div>
    </div>
  </div>
  <div class="auth-form">
    @yield('form')
  </div>
</div>
</body></html>
