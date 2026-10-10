@extends('layouts.app')
@section('title', 'My Profile')
@section('content')
<div class="grid g-2">
<form method="post" action="{{ route('profile.update') }}" class="card" autocomplete="off">@csrf
<div class="card-head"><h3>Profile &amp; password</h3></div>
<div class="card-body form-grid">
  <x-field name="name" label="Full name" :value="$user->name" required/>
  <div class="field"><label>Username</label><input value="{{ $user->username }}" disabled></div>
  <x-field name="email" label="Email" type="email" :value="$user->email"/><x-field name="phone" label="Phone" :value="$user->phone"/>
  <x-field name="current_password" label="Current password" type="password" autocomplete="current-password" class="span-all"/>
  <x-field name="password" label="New password" type="password" autocomplete="new-password"/><x-field name="password_confirmation" label="Confirm new password" type="password" autocomplete="new-password"/>
</div>
<div class="card-foot"><button class="btn btn-primary">Update Profile</button></div>
</form>
<div class="card"><div class="card-head"><h3>Account</h3></div><div class="card-body"><dl class="kv">
  <dt>Property</dt><dd>{{ hotel()->name }} ({{ hotel()->code }})</dd>
  <dt>Role</dt><dd>{{ $user->role?->name ?? ($user->is_super_admin ? 'Administrator (full access)' : '—') }}</dd>
  <dt>Group admin</dt><dd>{{ $user->is_group_admin ? 'Yes – '.$user->group?->name : 'No' }}</dd>
  <dt>Last login</dt><dd>{{ $user->last_login_at?->format('d M Y H:i') }} from {{ $user->last_login_ip }}</dd>
  <dt>Two-factor</dt><dd>@if($user->hasTwoFactor())<span class="badge badge-green">On</span>@else<span class="badge badge-amber">Off</span>@endif <a href="{{ route('profile.2fa') }}" class="small">Manage</a></dd>
</dl>
<a href="{{ route('profile.2fa') }}" class="btn btn-block mt-2"><x-icon name="shield"/> {{ $user->hasTwoFactor() ? 'Manage two-factor authentication' : 'Set up two-factor authentication' }}</a>
</div></div>
</div>
@endsection
