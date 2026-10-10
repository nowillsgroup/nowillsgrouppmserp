@extends('layouts.app')
@section('title', 'PII access log')
@section('crumbs') <a href="{{ route('privacy.index') }}">Privacy</a> / PII access log @endsection
@section('actions')@if(can_do('reports.view'))<a class="btn" href="{{ route('reports.show', 'pii_access') }}"><x-icon name="chart"/> Report</a>@endif @endsection
@section('content')
@include('privacy._nav')
<div class="grid g-2-1">
<div class="card flush"><div class="card-head"><h3>Who looked at identity data</h3>
  <form class="filters" style="margin:0">
    <select name="user_id" onchange="this.form.submit()"><option value="">All staff</option>@foreach($users as $id => $n)<option value="{{ $id }}" @selected(request('user_id') == $id)>{{ $n }}</option>@endforeach</select>
    <select name="action" onchange="this.form.submit()"><option value="">All actions</option>@foreach(\App\Models\PiiAccessLog::ACTIONS as $k => $l)<option value="{{ $k }}" @selected(request('action') === $k)>{{ $l }}</option>@endforeach</select>
    <input type="date" name="from" value="{{ request('from') }}"><input type="date" name="to" value="{{ request('to') }}"><button class="btn btn-sm">Filter</button></form></div>
  <div class="card-body table-wrap"><table class="table" id="accessTable"><thead><tr><th>When</th><th>Staff</th><th>Action</th><th>Guest</th><th>Detail</th><th>Reason</th><th>IP</th></tr></thead><tbody>
  @forelse($logs as $l)<tr><td class="small nowrap">{{ $l->created_at->format('d M Y H:i') }}</td><td class="small">{{ $l->user?->name ?? 'system' }}</td>
    <td class="small">{{ \App\Models\PiiAccessLog::ACTIONS[$l->action] ?? $l->action }}</td>
    <td class="small">@if($l->guest)<a href="{{ route('guests.show', $l->guest) }}">{{ $l->guest->full_name }}</a> <span class="tiny muted">G-{{ $l->guest_id }}</span>@else—@endif</td>
    <td class="small">{{ $l->detail }}</td><td class="small">{{ $l->reason ?? '—' }}</td><td class="tiny muted">{{ $l->ip }}</td></tr>
  @empty<tr><td colspan="7"><x-empty text="No access recorded." icon="eye"/></td></tr>@endforelse</tbody></table></div>
  @if($logs->hasPages())<div class="card-foot">{{ $logs->links() }}</div>@endif
</div>
<div class="card"><div class="card-head"><h3>Last 30 days by user</h3></div><div class="card-body"><ul class="plan-list" id="topUsers">
  @forelse($top as $t)<li><span>{{ $t->user?->name ?? 'system' }}</span><b>{{ $t->n }} <span class="tiny muted">· {{ $t->g }} guest(s)</span></b></li>@empty<li class="zero"><span>No access recorded</span></li>@endforelse</ul>
  <p class="tiny muted mt-1">Unusual volumes (many different guests in a short time) can point to misuse – review them with the user's manager.</p></div></div>
</div>
@endsection
