@extends('layouts.app')
@section('title', 'Organiser Logins')
@section('crumbs') Organiser Portal / Logins @endsection
@section('content')
@if($errors->has('invite'))<div class="alert alert-error"><x-icon name="alert"/><div>{{ $errors->first('invite') }}</div></div>@endif
<div class="flex justify-between items-center mb-2"><div></div>
  <form method="get" action="{{ route('organiser-admin.organisers') }}" class="flex gap-1"><input class="input" name="q" value="{{ request('q') }}" placeholder="Name or e-mail" style="width:14em"><button class="btn" type="submit">Search</button></form>
</div>
<div class="card"><div class="card-body table-wrap">
  @if($rows->isEmpty())<x-empty text="No organiser logins yet." icon="users"/>@else
  <table class="table" id="ogaLogins"><thead><tr><th>Name</th><th>Account</th><th>Role</th><th>Status</th><th>Last sign-in</th><th></th></tr></thead><tbody>
  @foreach($rows as $x)
    <tr><td><b>{{ $x->name }}</b><div class="tiny muted">{{ $x->email }}{{ $x->banquet_event_id ? ' · linked event #'.$x->banquet_event_id : '' }}</div></td>
      <td class="small">{{ $x->company?->name ?? 'Private client' }}</td>
      <td class="small">{{ \App\Models\OrganiserUser::ROLES[$x->role] }}</td>
      <td><span class="badge {{ $x->statusBadge() }}">{{ $x->statusLabel() }}</span></td>
      <td class="small nowrap">{{ $x->last_login_at?->format('d M H:i') ?? '—' }}</td>
      <td>@if(can_do('organiser.manage'))
        <form method="post" action="{{ route('organiser-admin.user', $x->id) }}" class="flex gap-1">@csrf
          @if($x->isLocked())<button class="btn btn-sm" type="submit" name="do" value="unlock">Unlock</button>@endif
          @if($x->isPending() && $x->is_active)<button class="btn btn-sm" type="submit" name="do" value="resend">Resend invite</button>@endif
          @if(! $x->isPending())<button class="btn btn-sm" type="submit" name="do" value="reset">Reset link</button>@endif
          <button class="btn btn-sm" type="submit" name="do" value="{{ $x->is_active ? 'disable' : 'enable' }}">{{ $x->is_active ? 'Disable' : 'Enable' }}</button>
        </form>@endif</td></tr>
  @endforeach
  </tbody></table>
  {{ $rows->links('partials.pagination') }}
  @endif
</div></div>
@if(can_do('organiser.manage'))
<div class="card mt-2" id="ogaInvite"><div class="card-head"><h3><x-icon name="mail"/> Invite an organiser</h3></div><div class="card-body">
  <form method="post" action="{{ route('organiser-admin.invite') }}">
    @csrf
    <div class="g-2">
      <div class="field"><label for="i_name">Full name</label><input class="input" id="i_name" name="name" value="{{ old('name') }}" required maxlength="120"></div>
      <div class="field"><label for="i_email">E-mail</label><input class="input" id="i_email" type="email" name="email" value="{{ old('email') }}" required maxlength="190"></div>
      <div class="field"><label for="i_company">Company (optional – leave empty for a private client)</label><select class="input" id="i_company" name="company_id"><option value="">— Private client —</option>@foreach($companies as $c)<option value="{{ $c->id }}" {{ (string) old('company_id') === (string) $c->id ? 'selected' : '' }}>{{ $c->name }}</option>@endforeach</select></div>
      <div class="field"><label for="i_event">Link to one event (optional)</label><select class="input" id="i_event" name="banquet_event_id"><option value="">— No direct link —</option>@foreach($events as $e)<option value="{{ $e->id }}" {{ (string) old('banquet_event_id') === (string) $e->id ? 'selected' : '' }}>{{ $e->event_no }} · {{ $e->title }}</option>@endforeach</select></div>
      <div class="field"><label for="i_phone">Phone (optional)</label><input class="input" id="i_phone" name="phone" value="{{ old('phone') }}" maxlength="30"></div>
      <div class="field"><label for="i_role">Role</label><select class="input" id="i_role" name="role">@foreach(\App\Models\OrganiserUser::ROLES as $k => $v)<option value="{{ $k }}" {{ old('role', 'planner') === $k ? 'selected' : '' }}>{{ $v }}</option>@endforeach</select></div>
    </div>
    <button class="btn btn-primary" type="submit">Send invitation</button>
    <span class="tiny muted">Without a company or event the login still sees events booked under its e-mail.</span>
  </form>
</div></div>
@endif
@endsection
