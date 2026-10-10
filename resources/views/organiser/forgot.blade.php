@extends('organiser.auth')
@section('title', 'Forgot password')
@section('form')
<form class="auth-card" method="post" action="{{ route('organiser.forgot.send') }}" autocomplete="off" id="orgForgotForm">
  @csrf
  <h2>Forgot your password?</h2>
  <p class="text-2 mb-2">We will e-mail a reset link that works for 60 minutes.</p>
  <div class="field"><label for="hotel_code">Hotel ID</label><input class="input" id="hotel_code" name="hotel_code" value="{{ old('hotel_code', $code) }}" required maxlength="30"></div>
  <div class="field"><label for="email">E-mail</label><input class="input" id="email" type="email" name="email" value="{{ old('email') }}" required maxlength="190"></div>
  <button class="btn btn-primary btn-lg btn-block" type="submit"><x-icon name="mail"/> Send reset link</button>
  <p class="small mt-2 center"><a href="{{ route('organiser.login', ['h' => old('hotel_code', $code)]) }}">Back to sign-in</a></p>
</form>
@endsection
