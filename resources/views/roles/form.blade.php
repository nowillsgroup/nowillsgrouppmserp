@extends('layouts.app')
@section('title', $role->exists ? 'Edit Role – '.$role->name : 'New Role')
@section('crumbs') <a href="{{ route('roles.index') }}">Roles</a> / {{ $role->exists ? 'Edit' : 'New' }} @endsection
@section('content')
@php $sel = old('permissions', $selected); @endphp
<form method="post" action="{{ $role->exists ? route('roles.update', $role) : route('roles.store') }}">@csrf @if($role->exists) @method('PUT') @endif
<div class="card"><div class="card-body form-grid">
  <x-field name="name" label="Role name" :value="$role->name" required/><x-field name="description" label="Description" :value="$role->description"/>
</div></div>
<div class="card"><div class="card-head"><h3>Permissions</h3><label class="check"><input type="checkbox" onclick="document.querySelectorAll('.pchk').forEach(c=>c.checked=this.checked)"> Select all</label></div>
<div class="card-body perm-grid">
@foreach($groups as $module => $perms)
  <div class="card mb-0"><div class="card-body">
    <label class="check bold"><input type="checkbox" onclick="this.closest('.card').querySelectorAll('.pchk').forEach(c=>c.checked=this.checked)"> {{ $module }}</label>
    @foreach($perms as $p)<label class="check small"><input type="checkbox" class="pchk" name="permissions[]" value="{{ $p->id }}" @checked(in_array($p->id, $sel))> {{ $p->label }} <span class="tiny muted">{{ $p->key }}</span></label>@endforeach
  </div></div>
@endforeach
</div>
<div class="card-foot"><a class="btn" href="{{ route('roles.index') }}">Cancel</a><button class="btn btn-primary"><x-icon name="check"/> Save Role</button></div></div>
</form>
@endsection
