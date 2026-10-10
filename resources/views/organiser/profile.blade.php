@extends('organiser.layout')
@section('title', 'My profile')
@section('content')
<div class="g-2">
  <div class="card"><div class="card-head"><h3><x-icon name="user"/> {{ $organiser->name }}</h3></div><div class="card-body">
    <dl class="dl-grid">
      <div><dt>E-mail</dt><dd>{{ $organiser->email }}</dd></div>
      <div><dt>Account</dt><dd>{{ $company?->name ?? 'Private client' }}</dd></div>
      <div><dt>Role</dt><dd>{{ \App\Models\OrganiserUser::ROLES[$organiser->role] }}</dd></div>
      <div><dt>Last sign-in</dt><dd>{{ $organiser->last_login_at?->format('d M Y H:i') ?? '—' }}</dd></div>
    </dl>
  </div></div>
  <div class="card no-print"><div class="card-head"><h3><x-icon name="lock"/> Change password</h3></div><div class="card-body">
    @if($errors->has('password'))<div class="alert alert-error"><x-icon name="alert"/><div>{{ $errors->first('password') }}</div></div>@endif
    <form method="post" action="{{ route('organiser.password') }}" id="orgPasswordForm">
      @csrf
      <div class="field"><label for="current_password">Current password</label><input class="input" id="current_password" type="password" name="current_password" required autocomplete="current-password"></div>
      <div class="field"><label for="password">New password</label><input class="input" id="password" type="password" name="password" required autocomplete="new-password"></div>
      <div class="field"><label for="password_confirmation">Repeat new password</label><input class="input" id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password"></div>
      <button class="btn btn-primary" type="submit">Change password</button>
      <p class="tiny muted">At least 10 characters with letters and numbers.</p>
    </form>
  </div></div>
</div>
<div class="card mt-2"><div class="card-head"><h3><x-icon name="clock"/> My recent activity</h3></div><div class="card-body table-wrap">
  @if($activity->isEmpty())<p class="muted m-0">Nothing yet.</p>@else
  <table class="table table-compact"><thead><tr><th>When</th><th>What</th><th>Detail</th></tr></thead><tbody>
  @foreach($activity as $a)<tr><td class="nowrap small">{{ $a->created_at?->format('d M H:i') }}</td><td class="small">{{ ucfirst(str_replace('_', ' ', $a->action)) }}</td><td class="small">{{ $a->detail }}</td></tr>@endforeach
  </tbody></table>@endif
</div></div>
@endsection
