@extends('partner.auth')
@section('title', $mode === 'invite' ? 'Accept invitation' : 'Choose a new password')
@section('form')
@if(! $u)
<div class="auth-card" id="ptnTokenBad">
  <h2>Link expired</h2>
  <div class="alert alert-error"><x-icon name="alert"/><div>This {{ $mode === 'invite' ? 'invitation' : 'reset' }} link is no longer valid – it was already used or has expired.</div></div>
  <p class="small">{{ $mode === 'invite' ? 'Ask your company administrator or the hotel to send a new invitation.' : 'Request a new link from the sign-in page.' }}</p>
  <a class="btn btn-primary btn-block" href="{{ route('partner.login') }}">Go to sign-in</a>
</div>
@else
<form class="auth-card" method="post" action="{{ $action }}" autocomplete="off" id="ptnPwForm">
  @csrf
  <h2>{{ $mode === 'invite' ? 'Welcome, '.$u->name : 'New password' }}</h2>
  <p class="text-2 mb-2">{{ $u->company->name }} · {{ $u->email }}</p>
  @if($errors->any())<div class="alert alert-error"><x-icon name="alert"/><div>{{ $errors->first() }}</div></div>@endif
  <div class="field"><label for="password">Choose a password</label><input class="input" id="password" type="password" name="password" required minlength="10" autocomplete="new-password"></div>
  <div class="field"><label for="password_confirmation">Repeat it</label><input class="input" id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password"></div>
  <p class="tiny muted">At least 10 characters with letters and numbers, not containing your e-mail name.</p>
  <button class="btn btn-primary btn-lg btn-block" type="submit"><x-icon name="lock"/> {{ $mode === 'invite' ? 'Set password & continue' : 'Save new password' }}</button>
</form>
@endif
@endsection
