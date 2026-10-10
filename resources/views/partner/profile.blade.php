@extends('partner.layout')
@section('title', 'My profile')
@section('content')
@if($errors->has('password'))<div class="alert alert-error"><x-icon name="alert"/><div>{{ $errors->first('password') }}</div></div>@endif
<div class="g-2">
  <div class="card"><div class="card-head"><h3><x-icon name="user"/> {{ $partner->name }}</h3></div><div class="card-body">
    <div class="kv-grid">
      <div><span>E-mail</span><b>{{ $partner->email }}</b></div><div><span>Company</span><b>{{ $company->name }}</b></div>
      <div><span>Role</span><b>{{ \App\Models\PartnerUser::ROLES[$partner->role] }}</b></div><div><span>Password changed</span><b>{{ $partner->password_changed_at?->format('d M Y') ?? '—' }}</b></div>
    </div>
    <h4 class="mt-2">Change password</h4>
    <form method="post" action="{{ route('partner.password') }}" id="ptnPwChange">
      @csrf
      <div class="field"><label>Current password</label><input class="input" type="password" name="current_password" required autocomplete="current-password"></div>
      <div class="g-2"><div class="field"><label>New password</label><input class="input" type="password" name="password" required minlength="10" autocomplete="new-password"></div>
        <div class="field"><label>Repeat</label><input class="input" type="password" name="password_confirmation" required autocomplete="new-password"></div></div>
      <button class="btn btn-primary" type="submit"><x-icon name="lock"/> Change password</button>
    </form>
  </div></div>
  <div class="card"><div class="card-head"><h3><x-icon name="clock"/> My recent activity</h3></div><div class="card-body table-wrap">
    <table class="table table-compact" id="ptnMyActivity"><tbody>
    @forelse($activity as $a)<tr><td class="small nowrap">{{ $a->created_at->format('d M H:i') }}</td><td><span class="badge badge-slate">{{ str_replace('_', ' ', $a->action) }}</span></td><td class="small">{{ $a->detail }}<div class="tiny muted">{{ $a->ip }}</div></td></tr>@empty<tr><td class="muted">No activity yet.</td></tr>@endforelse
    </tbody></table>
  </div></div>
</div>
@endsection
