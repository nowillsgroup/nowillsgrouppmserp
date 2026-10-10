@extends('layouts.app')
@section('title', 'Partner Accounts')
@section('crumbs') Partner Portal / Accounts @endsection
@section('content')
<p class="small muted">Corporate clients and travel agents. Switch the portal on for a company, set its booking terms and invite its bookers.</p>
<div class="card"><div class="card-body table-wrap">
  <table class="table" id="ptaAccounts"><thead><tr><th>Company</th><th>Type</th><th>Portal</th><th>Active logins</th><th>Billing</th><th>Approval over</th><th>Last partner sign-in</th><th></th></tr></thead><tbody>
  @foreach($rows as $c)
    <tr><td><b>{{ $c->name }}</b>@if(! $c->is_active)<span class="badge badge-red">inactive</span>@endif</td><td>{{ $c->type === 'travel_agent' ? 'Travel agent' : 'Corporate' }}</td>
      <td><span class="badge {{ $c->portal_enabled ? 'badge-green' : 'badge-slate' }}">{{ $c->portal_enabled ? 'On' : 'Off' }}</span></td><td>{{ $c->users_count }}</td>
      <td class="small">{{ $c->direct_billing ? 'Direct billing' : 'Guest pays' }}@if($c->onHold()) <span class="badge badge-red">hold</span>@endif</td>
      <td>{{ $c->portal_approval_over !== null ? money($c->portal_approval_over) : '—' }}</td>
      <td class="small">{{ isset($last[$c->id]) ? \Carbon\Carbon::parse($last[$c->id])->format('d M Y H:i') : '—' }}</td>
      <td><a class="btn btn-sm" href="{{ route('partner-admin.company', $c) }}">Manage</a></td></tr>
  @endforeach
  </tbody></table>
</div></div>
@endsection
