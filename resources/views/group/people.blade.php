@extends('layouts.app')
@section('title', 'Group people')
@section('crumbs')<a href="{{ route('properties.index') }}">Properties</a> / <a href="{{ route('group.account', ['group' => $group->id]) }}">{{ $group->name }}</a> / People@endsection
@section('actions')
  <span class="small muted">{{ $people->count() }} shown · {{ $activePeople }} active of {{ $group->max_users ? number_format($group->max_users).' seat(s)' : 'no seat cap' }} · {{ $groupAdmins }} group administrator(s)</span>
  <a class="btn" href="{{ route('group.account', ['group' => $group->id]) }}"><x-icon name="layers" size="sm"/> Group account</a>
@endsection
@section('content')

<div class="alert alert-info"><x-icon name="info"/><div>
  <b>A person is created once, at group level.</b> Their <em>home property</em> owns their role; any extra property is an explicit grant, and the role chosen with a grant governs that property only. Switching somebody off stops them entering every property but leaves their history, their shifts and their folios exactly where they are.
</div></div>

<div class="card">
  <form method="get" action="{{ route('group.people.index') }}" class="card-body flex flex-wrap items-end gap-2">
    <div class="field" style="min-width:260px"><label for="gq">Search</label><input id="gq" name="q" value="{{ $search }}" placeholder="Name, username or e-mail"></div>
    <button class="btn">Search</button>
    @if($search)<a class="btn btn-ghost" href="{{ route('group.people.index') }}">Clear</a>@endif
  </form>
</div>

<div class="card flush mt-2"><div class="card-head"><h3>Everyone in this tenant</h3><span class="small muted">Home property, role there, and the other properties they may enter</span></div>
<div class="card-body table-wrap">
  @if($people->isEmpty())
    <x-empty icon="users" text="Nobody matches this search." />
  @else
  <table class="table">
    <thead><tr><th>Person</th><th>Home property</th><th>Role there</th><th>Also works in</th><th>Group admin</th><th>State</th><th></th></tr></thead>
    <tbody>
    @foreach($people as $p)
      @php $grants = $grantsByUser[$p->id] ?? []; @endphp
      <tr class="{{ $p->is_active ? '' : 'muted' }}">
        <td style="min-width:210px"><b>{{ $p->name }}</b><div class="tiny muted">{{ $p->username }}{{ $p->email ? ' · '.$p->email : '' }}</div></td>
        <td class="small">{{ $p->hotel?->name ?? '—' }}@if($p->hotel?->isHeadOffice()) <span class="badge badge-blue">HQ</span>@endif</td>
        <td class="small">{{ $p->role?->name ?? 'No role' }}</td>
        <td class="small">
          @if($grants)
            {{ implode(', ', $grants) }}
          @else
            <span class="muted">—</span>
          @endif
        </td>
        <td class="center">@if($p->is_group_admin)<span class="badge badge-purple">Group admin</span>@else<span class="muted">—</span>@endif</td>
        <td>@if($p->is_active)<span class="badge badge-green">Active</span>@else<span class="badge badge-slate">Switched off</span>@endif</td>
        <td class="right" style="white-space:nowrap">
          @if($canStaff)
            <form method="post" action="{{ route('group.people.toggle', $p) }}" style="display:inline">@csrf
              @if($p->is_active)
                <button class="btn btn-sm btn-ghost" name="revoke_grants" value="" onclick="return confirm('Switch {{ $p->username }} off? They lose access to every property; their grants are kept for a later return.')">Switch off</button>
                <button class="btn btn-sm btn-ghost" name="revoke_grants" value="1" onclick="return confirm('Switch {{ $p->username }} off AND take back their cross-property grants? Re-enabling them later would not restore any property.')">Switch off &amp; revoke</button>
              @else
                <button class="btn btn-sm btn-ghost" onclick="return confirm('Let {{ $p->username }} sign in again?')">Re-enable</button>
              @endif
            </form>
          @endif
          @if($p->hotel)<a class="btn btn-sm" href="{{ route('properties.show', $p->hotel) }}">Home property</a>@endif
        </td>
      </tr>
    @endforeach
    </tbody>
  </table>
  @endif
</div></div>

@if($canStaff)
<div class="card mt-2"><div class="card-head"><h3>Add a person to this group</h3><span class="small muted">One account, one home property, any number of extra properties</span></div>
<form method="post" action="{{ route('group.people.store') }}" class="card-body">
@csrf
<div class="form-grid">
  <x-field name="name" label="Full name" required />
  <x-field name="username" label="Username" required hint="What they type at sign-in. Letters, digits, dash or underscore." />
  <x-field name="email" label="E-mail" type="email" />
  <x-field name="phone" label="Phone" />
  <x-field name="password" label="First password" type="password" required hint="At least 8 characters. They can change it after signing in." />
  <x-field name="hotel_id" label="Home property" type="select" required :placeholder="false" :options="$homeOptions" hint="Their role belongs to this property." />
  <div class="field"><label for="f_role_id">Role at the home property</label>
    <select id="f_role_id" name="role_id">
      <option value="">No role (grant access explicitly)</option>
      @foreach($hotels as $h)
        @php $hotelRoles = $rolesByHotel[$h->id] ?? []; @endphp
        @if($hotelRoles)
          <optgroup label="{{ $h->name }}" data-hotel="{{ $h->id }}">
            @foreach($hotelRoles as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
          </optgroup>
        @endif
      @endforeach
    </select>
    <span class="hint">Roles are per property in this application, so the role must belong to the home property chosen above.</span>
  </div>
</div>

<div class="field"><label>Also grant access to</label>
  <div class="flex flex-wrap gap-2">
    @foreach($hotels as $h)
      @continue($h->isArchived())
      <label class="check"><input type="checkbox" name="grants[]" value="{{ $h->id }}"> {{ $h->name }} <span class="tiny muted">{{ $h->statusLabel() }}</span></label>
    @endforeach
  </div>
  <span class="hint">Each grant is recorded in the property's audit trail. Their home property does not need one.</span>
</div>

@if($canMintAdmins)
  <label class="check"><input type="checkbox" name="is_group_admin" value="1"> Group administrator — may enter every property of this group and staff it</label>
@else
  <p class="tiny muted">Only a platform administrator can make somebody a group administrator.</p>
@endif

<div class="mt-1"><button class="btn btn-primary"><x-icon name="plus" size="sm"/> Add the person</button></div>
</form></div>
@endif
@endsection
