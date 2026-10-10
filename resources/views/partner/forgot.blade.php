@extends('partner.auth')
@section('title', 'Reset password')
@section('form')
<form class="auth-card" method="post" action="{{ route('partner.forgot.send') }}" autocomplete="off">
  @csrf
  <h2>Reset your password</h2>
  <p class="text-2 mb-2">We will e-mail a link valid for 60 minutes.</p>
  @if($errors->any())<div class="alert alert-error"><x-icon name="alert"/><div>{{ $errors->first() }}</div></div>@endif
  <div class="field"><label for="hotel_code">Hotel ID</label><input class="input" id="hotel_code" name="hotel_code" value="{{ old('hotel_code', $code) }}" required maxlength="30"></div>
  <div class="field"><label for="email">E-mail</label><input class="input" id="email" type="email" name="email" value="{{ old('email') }}" required maxlength="190"></div>
  <button class="btn btn-primary btn-lg btn-block" type="submit"><x-icon name="mail"/> Send reset link</button>
  <p class="small mt-2 center"><a href="{{ route('partner.login', ['h' => $code]) }}">Back to sign-in</a></p>
</form>
@endsection
