@extends('layouts.app')
@section('title', $user->exists ? 'Edit User – '.$user->username : 'New User')
@section('crumbs') <a href="{{ route('users.index') }}">Users</a> / {{ $user->exists ? 'Edit' : 'New' }} @endsection
@section('content')
<form method="post" action="{{ $user->exists ? route('users.update', $user->id) : route('users.store') }}" class="card" style="max-width:820px" autocomplete="off">@csrf @if($user->exists) @method('PUT') @endif
<div class="card-body form-grid">
  <x-field name="name" label="Full name" :value="$user->name" required/>
  <x-field name="username" label="Username" :value="$user->username" required hint="Letters, numbers, dash, underscore"/>
  <x-field name="email" label="Email" type="email" :value="$user->email"/><x-field name="phone" label="Phone" :value="$user->phone"/>
  <x-field name="role_id" label="Role" type="select" :options="$roles" :value="$user->role_id" placeholder="— No role —"/>
  <div class="field"><label>&nbsp;</label>
    <label class="check"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $user->is_active))> Active</label>
    @if(auth()->user()->is_super_admin)<label class="check"><input type="hidden" name="is_super_admin" value="0"><input type="checkbox" name="is_super_admin" value="1" @checked(old('is_super_admin', $user->is_super_admin))> Hotel administrator (full access)</label>@endif
  </div>
  <x-field name="password" label="{{ $user->exists ? 'New password (leave blank to keep)' : 'Password' }}" type="password" :required="!$user->exists" hint="Min 8 characters with letters and numbers" autocomplete="new-password"/>
  <x-field name="password_confirmation" label="Confirm password" type="password" autocomplete="new-password"/>
  @if($user->exists && $user->locked_until && $user->locked_until->isFuture())<label class="check span-all"><input type="checkbox" name="unlock" value="1"> Unlock account (locked until {{ $user->locked_until->format('H:i') }} after {{ $user->failed_logins }} failed logins)</label>@endif
</div>
<div class="card-foot"><a class="btn" href="{{ route('users.index') }}">Cancel</a><button class="btn btn-primary"><x-icon name="check"/> Save User</button></div>
</form>
@if($user->exists && $user->two_factor_confirmed_at)
<div class="card" style="max-width:820px"><div class="card-body flex items-center justify-between gap-1">
  <div class="small"><x-icon name="shield" size="sm"/> Two-factor authentication is <b>on</b> for this user (since {{ $user->two_factor_confirmed_at->format('d M Y') }}).</div>
  <form method="post" action="{{ route('users.2fa.reset', $user->id) }}" data-confirm="Reset 2FA for {{ $user->username }}? They will sign in with just their password until they enrol again.">@csrf<button class="btn btn-sm btn-danger">Reset 2FA</button></form>
</div></div>
@endif
@endsection
