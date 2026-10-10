@extends('organiser.auth')
@section('title', $mode === 'invite' ? 'Accept invitation' : 'Reset password')
@section('form')
@if($u)
<form class="auth-card" method="post" action="{{ $action }}" autocomplete="off" id="orgPasswordForm">
  @csrf
  <h2>{{ $mode === 'invite' ? 'Welcome, '.e(\Illuminate\Support\Str::before($u->name, ' ')) : 'Choose a new password' }}</h2>
  <p class="text-2 mb-2">{{ $mode === 'invite' ? $u->company?->name.' · '.$u->email : $u->email }} – use at least 10 characters with letters and numbers.</p>
  @if($errors->any())<div class="alert alert-error"><x-icon name="alert"/><div>{{ $errors->first() }}</div></div>@endif
  <div class="field"><label for="password">Password</label><input class="input" id="password" type="password" name="password" required autocomplete="new-password"></div>
  <div class="field"><label for="password_confirmation">Repeat password</label><input class="input" id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password"></div>
  <button class="btn btn-primary btn-lg btn-block" type="submit"><x-icon name="check"/> {{ $mode === 'invite' ? 'Accept invitation' : 'Change password' }}</button>
</form>
@else
<div class="auth-card" id="orgExpired"><h2>Link expired</h2><p class="text-2">This invitation or reset link is unknown, already used, or past its time. Ask for a new one below.</p><p><a class="btn btn-primary" href="{{ route('organiser.forgot') }}">Get a reset link</a></p></div>
@endif
@endsection
