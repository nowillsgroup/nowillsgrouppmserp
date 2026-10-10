@extends('organiser.layout')
@section('title', 'Users')
@section('content')
@if($errors->has('user'))<div class="alert alert-error"><x-icon name="alert"/><div>{{ $errors->first('user') }}</div></div>@endif
<p class="small muted">Everyone who can sign in for {{ $company?->name ?? 'your events' }}. Planners run events, Finance sees money and documents, Administrators also manage users.</p>
<div class="card"><div class="card-body table-wrap">
  <table class="table" id="orgUsers"><thead><tr><th>Name</th><th>E-mail</th><th>Role</th><th>Status</th><th>Last sign-in</th><th></th></tr></thead><tbody>
  @foreach($rows as $x)
    <tr><td><b>{{ $x->name }}</b></td><td class="small">{{ $x->email }}</td>
      <td>@if($x->id === $organiser->id){{ \App\Models\OrganiserUser::ROLES[$x->role] }}
        @else<form method="post" action="{{ route('organiser.users.action', $x->id) }}" class="flex gap-1">@csrf<input type="hidden" name="do" value="role">
          <select class="input" name="role" aria-label="Role for {{ $x->name }}">@foreach(\App\Models\OrganiserUser::ROLES as $k => $v)<option value="{{ $k }}" {{ $x->role === $k ? 'selected' : '' }}>{{ $v }}</option>@endforeach</select>
          <button class="btn btn-sm" type="submit">Set</button></form>@endif</td>
      <td><span class="badge {{ $x->statusBadge() }}">{{ $x->statusLabel() }}</span></td>
      <td class="small nowrap">{{ $x->last_login_at?->format('d M H:i') ?? '—' }}</td>
      <td>@if($x->id !== $organiser->id)
        <form method="post" action="{{ route('organiser.users.action', $x->id) }}" class="flex gap-1">@csrf
          @if($x->isPending() && $x->is_active)<button class="btn btn-sm" type="submit" name="do" value="resend">Resend invite</button>@endif
          <button class="btn btn-sm" type="submit" name="do" value="{{ $x->is_active ? 'disable' : 'enable' }}">{{ $x->is_active ? 'Disable' : 'Enable' }}</button>
        </form>@endif</td></tr>
  @endforeach
  </tbody></table>
</div></div>
<div class="card no-print mt-2"><div class="card-head"><h3><x-icon name="plus"/> Invite a colleague</h3></div><div class="card-body">
  <form method="post" action="{{ route('organiser.users.invite') }}" id="orgInviteForm">
    @csrf
    <div class="g-2">
      <div class="field"><label for="u_name">Full name</label><input class="input" id="u_name" name="name" value="{{ old('name') }}" required maxlength="120"></div>
      <div class="field"><label for="u_email">E-mail</label><input class="input" id="u_email" type="email" name="email" value="{{ old('email') }}" required maxlength="190"></div>
      <div class="field"><label for="u_phone">Phone (optional)</label><input class="input" id="u_phone" name="phone" value="{{ old('phone') }}" maxlength="30"></div>
      <div class="field"><label for="u_role">Role</label><select class="input" id="u_role" name="role">@foreach(\App\Models\OrganiserUser::ROLES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
    </div>
    <button class="btn btn-primary" type="submit">Send invitation</button>
  </form>
</div></div>
@endsection
