@extends('partner.layout')
@section('title', 'Users')
@section('content')
@if($errors->has('user'))<div class="alert alert-error"><x-icon name="alert"/><div>{{ $errors->first('user') }}</div></div>@endif
<div class="g-2-1">
  <div class="card"><div class="card-head"><h3><x-icon name="users"/> {{ $company->name }} logins</h3></div><div class="card-body table-wrap">
    <table class="table" id="ptnUsers"><thead><tr><th>Name</th><th>Role</th><th>Status</th><th>Last sign-in</th><th></th></tr></thead><tbody>
    @foreach($rows as $x)
      <tr><td><b>{{ $x->name }}</b><div class="tiny muted">{{ $x->email }}</div></td>
        <td>@if($x->id !== $partner->id && $x->is_active)<form method="post" action="{{ route('partner.users.action', $x->id) }}" class="flex gap-1">@csrf<input type="hidden" name="do" value="role"><select class="input" name="role" onchange="this.form.submit()">@foreach(\App\Models\PartnerUser::ROLES as $k => $l)<option value="{{ $k }}" @selected($x->role === $k)>{{ $l }}</option>@endforeach</select></form>@else{{ \App\Models\PartnerUser::ROLES[$x->role] }}@endif</td>
        <td><span class="badge {{ $x->statusBadge() }}">{{ $x->statusLabel() }}</span></td><td class="small">{{ $x->last_login_at?->format('d M Y H:i') ?? '—' }}</td>
        <td class="nowrap">@if($x->id !== $partner->id)
          @if($x->isPending() && $x->is_active)<form method="post" action="{{ route('partner.users.action', $x->id) }}" style="display:inline">@csrf<input type="hidden" name="do" value="resend"><button class="btn btn-sm" type="submit">Resend invite</button></form>@endif
          <form method="post" action="{{ route('partner.users.action', $x->id) }}" style="display:inline">@csrf<input type="hidden" name="do" value="{{ $x->is_active ? 'disable' : 'enable' }}"><button class="btn btn-sm {{ $x->is_active ? 'btn-danger' : '' }}" type="submit">{{ $x->is_active ? 'Disable' : 'Enable' }}</button></form>
        @else<span class="tiny muted">you</span>@endif</td></tr>
    @endforeach
    </tbody></table>
  </div></div>
  <div class="card"><div class="card-head"><h3><x-icon name="plus"/> Invite a colleague</h3></div><div class="card-body">
    <form method="post" action="{{ route('partner.users.invite') }}" id="ptnInviteForm">
      @csrf
      <div class="field"><label>Name</label><input class="input" name="name" value="{{ old('name') }}" required maxlength="120"></div>
      <div class="field"><label>E-mail</label><input class="input" type="email" name="email" value="{{ old('email') }}" required maxlength="190"></div>
      <div class="field"><label>Mobile</label><input class="input" name="phone" value="{{ old('phone') }}" maxlength="30"></div>
      <div class="field"><label>Role</label><select class="input" name="role">@foreach(\App\Models\PartnerUser::ROLES as $k => $l)<option value="{{ $k }}" @selected(old('role', 'booker') === $k)>{{ $l }}</option>@endforeach</select></div>
      <button class="btn btn-primary btn-block" type="submit"><x-icon name="mail"/> Send invitation</button>
    </form>
    <p class="tiny muted mt-1 m-0"><b>Booker</b>: book &amp; manage stays. <b>Finance</b>: statement, invoices, payments{{ $company->type === 'travel_agent' ? ', commissions' : '' }}. <b>Administrator</b>: everything plus users.</p>
  </div></div>
</div>
@endsection
