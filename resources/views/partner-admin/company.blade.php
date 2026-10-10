@extends('layouts.app')
@section('title', $c->name.' – Partner Portal')
@section('crumbs') <a href="{{ route('partner-admin.accounts') }}">Partner Accounts</a> / {{ $c->name }} @endsection
@section('actions')
  @if(can_do('credit.view'))<a class="btn" href="{{ route('credit.show', $c) }}"><x-icon name="shield"/> Credit control</a>@endif
  @if(can_do('accounts.view'))<a class="btn" href="{{ route('accounts.company', $c) }}"><x-icon name="receipt"/> City ledger</a>@endif
@endsection
@section('content')
@foreach(['terms', 'invite'] as $k)@if($errors->has($k))<div class="alert alert-error"><x-icon name="alert"/><div>{{ $errors->first($k) }}</div></div>@endif @endforeach
<div class="g-2-1">
  <div>
    <div class="card" id="ptaUsers"><div class="card-head"><h3><x-icon name="users"/> Partner logins</h3><span class="tiny muted">portal address: {{ $loginUrl }}</span></div><div class="card-body table-wrap">
      @if($users->isEmpty())<p class="muted m-0">No logins yet – invite the company's travel desk below.</p>@else
      <table class="table table-compact"><thead><tr><th>Name</th><th>Role</th><th>Status</th><th>Last sign-in</th><th></th></tr></thead><tbody>
      @foreach($users as $u)
        <tr><td><b>{{ $u->name }}</b><div class="tiny muted">{{ $u->email }}{{ $u->phone ? ' · '.$u->phone : '' }}</div></td><td>{{ \App\Models\PartnerUser::ROLES[$u->role] }}</td>
          <td><span class="badge {{ $u->statusBadge() }}">{{ $u->statusLabel() }}</span>@if($u->isPending() && $u->invite_expires_at)<div class="tiny muted">invite {{ $u->invite_expires_at->isPast() ? 'expired' : 'valid to '.$u->invite_expires_at->format('d M') }}</div>@endif</td>
          <td class="small">{{ $u->last_login_at?->format('d M Y H:i') ?? '—' }}<div class="tiny muted">{{ $u->last_login_ip }}</div></td>
          <td class="nowrap">@if(can_do('partner.manage'))
            @foreach(array_filter(['unlock' => $u->isLocked() ? 'Unlock' : null, 'resend' => $u->isPending() && $u->is_active ? 'Resend invite' : null, 'reset' => ! $u->isPending() && $u->is_active ? 'Send reset link' : null, $u->is_active ? 'disable' : 'enable' => $u->is_active ? 'Disable' : 'Enable']) as $do => $label)
              <form method="post" action="{{ route('partner-admin.user', $u) }}" style="display:inline">@csrf<input type="hidden" name="do" value="{{ $do }}"><button class="btn btn-sm {{ $do === 'disable' ? 'btn-danger' : '' }}" type="submit">{{ $label }}</button></form>
            @endforeach
          @endif</td></tr>
      @endforeach
      </tbody></table>@endif
    </div></div>
    @if(can_do('partner.manage'))
    <div class="card" id="ptaInvite"><div class="card-head"><h3><x-icon name="mail"/> Invite a partner user</h3></div><div class="card-body">
      <form method="post" action="{{ route('partner-admin.invite', $c) }}" class="g-4" style="align-items:end">
        @csrf
        <div class="field"><label>Name</label><input class="input" name="name" value="{{ old('name', $users->isEmpty() ? $c->contact_person : '') }}" required maxlength="120"></div>
        <div class="field"><label>E-mail</label><input class="input" type="email" name="email" value="{{ old('email', $users->isEmpty() ? $c->email : '') }}" required maxlength="190"></div>
        <div class="field"><label>Role</label><select class="input" name="role">@foreach(\App\Models\PartnerUser::ROLES as $k => $l)<option value="{{ $k }}" @selected(old('role', $users->isEmpty() ? 'admin' : 'booker') === $k)>{{ $l }}</option>@endforeach</select></div>
        <div class="field"><button class="btn btn-primary btn-block" type="submit"><x-icon name="mail"/> Send invitation</button></div>
      </form>
      <p class="tiny muted m-0">The invitation link is valid {{ setting('partner_invite_days', 7) }} days; the person chooses their own password. A company administrator can invite colleagues from the portal.</p>
    </div></div>
    @endif
    <div class="card"><div class="card-head"><h3><x-icon name="message"/> Recent requests</h3></div><div class="card-body table-wrap">
      @if($requests->isEmpty())<p class="muted m-0">None.</p>@else
      <table class="table table-compact"><tbody>@foreach($requests as $q)<tr><td>{{ $q->number }}</td><td>{{ \App\Models\PartnerRequest::TYPES[$q->type] }}</td><td>{{ $q->reservation?->reservation_no }}</td><td><span class="badge {{ $q->badge() }}">{{ \App\Models\PartnerRequest::STATUSES[$q->status] }}</span></td><td class="small">{{ $q->created_at->format('d M H:i') }}</td></tr>@endforeach</tbody></table>@endif
    </div></div>
  </div>
  <div>
    <div class="card" id="ptaTerms"><div class="card-head"><h3><x-icon name="shield"/> Portal terms</h3></div><div class="card-body">
      <form method="post" action="{{ route('partner-admin.terms', $c) }}">
        @csrf
        <label class="check mb-1"><input type="checkbox" name="portal_enabled" value="1" @checked($c->portal_enabled) @disabled(! can_do('partner.manage'))> Portal access on</label><br>
        <label class="check mb-1"><input type="checkbox" name="portal_guest_pay" value="1" @checked($c->portal_guest_pay) @disabled(! can_do('partner.manage'))> May book stays the traveller pays</label>
        <div class="field mt-1"><label>Hotel approval needed above (booking value)</label><input class="input" type="number" step="0.01" min="0" name="portal_approval_over" value="{{ $c->portal_approval_over }}" placeholder="no threshold" @disabled(! can_do('partner.manage'))></div>
        <div class="field"><label>Max rooms per portal booking</label><input class="input" type="number" min="1" max="50" name="portal_max_rooms" value="{{ $c->portal_max_rooms }}" @disabled(! can_do('partner.manage'))></div>
        @if(can_do('partner.manage'))<button class="btn btn-primary btn-block" type="submit">Save terms</button>@endif
      </form>
    </div></div>
    <div class="card"><div class="card-head"><h3><x-icon name="wallet"/> Commercial position</h3></div><div class="card-body">
      <div class="kv-grid">
        <div><span>Rate</span><b>{{ $contract ? 'Contract '.$contract->code.' ('.$contract->pricing.')' : ((float) $c->discount_pct > 0 ? 'BAR − '.(float) $c->discount_pct.'%' : 'BAR') }}</b></div>
        <div><span>Direct billing</span><b>{{ $c->direct_billing ? 'Yes · '.$c->credit_days.' days' : 'No' }}</b></div>
        <div><span>Outstanding</span><b>{{ money($position['outstanding']) }}</b></div>
        <div><span>Limit / available</span><b>{{ $position['limit'] > 0 ? money($position['limit']).' / '.money($position['available']) : 'no limit' }}</b></div>
        <div><span>Credit status</span><b>{{ $c->onHold() ? 'ON HOLD' : 'Open' }}</b></div>
        @if($c->type === 'travel_agent')<div><span>Commission</span><b>{{ (float) $c->commission_pct }}%</b></div>@endif
      </div>
    </div></div>
    <div class="card"><div class="card-head"><h3><x-icon name="eye"/> Activity</h3></div><div class="card-body table-wrap">
      <table class="table table-compact"><tbody>@forelse($audit as $a)<tr><td class="small nowrap">{{ $a->created_at->format('d M H:i') }}</td><td class="small"><b>{{ str_replace('_', ' ', $a->action) }}</b> {{ $a->detail }}<div class="tiny muted">{{ $a->partnerUser?->name }}</div></td></tr>@empty<tr><td class="muted">None.</td></tr>@endforelse</tbody></table>
    </div></div>
  </div>
</div>
@endsection
