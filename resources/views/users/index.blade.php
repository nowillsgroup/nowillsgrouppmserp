@extends('layouts.app')
@section('title', 'Users')
@section('actions')<a class="btn" href="{{ route('roles.index') }}"><x-icon name="shield"/> Roles</a><a class="btn btn-primary" href="{{ route('users.create') }}"><x-icon name="plus"/> New User</a>@endsection
@section('content')
<div class="card"><div class="card-body"><form class="filters"><div class="field"><input name="q" value="{{ request('q') }}" placeholder="Search name / username"></div><button class="btn btn-primary">Search</button></form></div></div>
<div class="card flush"><div class="card-body table-wrap"><table class="table">
<thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Contact</th><th>Last login</th><th>Status</th><th></th></tr></thead><tbody>
@foreach($users as $u)<tr>
<td class="bold">{{ $u->name }} @if($u->is_super_admin)<span class="badge badge-blue">Admin</span>@endif @if($u->is_group_admin)<span class="badge badge-blue">Group</span>@endif @if($u->two_factor_confirmed_at)<span class="badge badge-green" title="Two-factor authentication on">2FA</span>@endif</td>
<td>{{ $u->username }}</td><td>{{ $u->role?->name ?? ($u->is_super_admin ? 'Full access' : '—') }}</td><td class="small">{{ $u->email }} {{ $u->phone }}</td>
<td class="small">{{ $u->last_login_at?->format('d M Y H:i') ?? 'Never' }} <span class="muted">{{ $u->last_login_ip }}</span></td>
<td>@if(!$u->is_active)<span class="badge badge-slate">Inactive</span>@elseif($u->locked_until && $u->locked_until->isFuture())<span class="badge badge-red">Locked</span>@else<span class="badge badge-green">Active</span>@endif</td>
<td><a class="btn btn-sm" href="{{ route('users.edit', $u->id) }}"><x-icon name="edit" size="sm"/></a></td></tr>@endforeach
</tbody></table>{{ $users->links() }}</div></div>
@endsection
