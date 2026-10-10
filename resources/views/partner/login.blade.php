@extends('partner.auth')
@section('title', 'Sign in')
@section('form')
<form class="auth-card" method="post" action="{{ route('partner.login.attempt') }}" autocomplete="off" id="ptnLoginForm">
  @csrf
  <h2>Partner sign-in</h2>
  <p class="text-2 mb-2">For corporate travel bookers and travel agents with a hotel agreement.</p>
  @if(session('success'))<div class="alert alert-success"><x-icon name="check"/><div>{{ session('success') }}</div></div>@endif
  @if($errors->any())<div class="alert alert-error" id="ptnLoginError"><x-icon name="alert"/><div>{{ $errors->first() }}</div></div>@endif
  <div class="field"><label for="hotel_code">Hotel ID</label><input class="input" id="hotel_code" name="hotel_code" value="{{ old('hotel_code', $code) }}" required maxlength="30"></div>
  <div class="field"><label for="email">E-mail</label><input class="input" id="email" type="email" name="email" value="{{ old('email') }}" required maxlength="190" autocomplete="username"></div>
  <div class="field"><label for="password">Password</label><input class="input" id="password" type="password" name="password" required autocomplete="current-password"></div>
  <button class="btn btn-primary btn-lg btn-block" type="submit"><x-icon name="login"/> Sign in</button>
  <p class="small mt-2 center"><a href="{{ route('partner.forgot', ['h' => old('hotel_code', $code)]) }}" id="ptnForgot">Forgot your password?</a></p>
  <p class="tiny muted center">No login yet? Ask your hotel account manager for an invitation. Five wrong passwords lock the login for 15 minutes.</p>
  @if(app()->environment('local'))
  <div class="alert alert-info small mt-2"><div><b>Demo:</b> Hotel ID <code>NOWILLS</code><br>Corporate: <code>travel.desk@infosys.com</code> · Agent: <code>bookings@yatra.com</code><br>Password <code>Partner2026</code></div></div>
  @endif
</form>
@endsection
