@extends('layouts.app')
@section('title', 'User Activity Log')
@section('content')
<div class="card"><div class="card-body"><form class="filters">
<div class="field"><label>From</label><input type="date" name="from" value="{{ $from }}"></div><div class="field"><label>To</label><input type="date" name="to" value="{{ $to }}"></div>
<div class="field"><label>User</label><select name="user_id"><option value="">All</option>@foreach($users as $id=>$n)<option value="{{ $id }}" @selected(request('user_id')==$id)>{{ $n }}</option>@endforeach</select></div>
<div class="field"><label>Module</label><select name="module"><option value="">All</option>@foreach($modules as $m)<option @selected(request('module')==$m)>{{ $m }}</option>@endforeach</select></div>
<div class="field"><label>Search</label><input name="q" value="{{ request('q') }}" placeholder="Description contains…"></div>
<button class="btn btn-primary">Filter</button></form></div></div>
<div class="card flush"><div class="card-body table-wrap"><table class="table table-compact">
<thead><tr><th>When</th><th>User</th><th>Module</th><th>Action</th><th>Description</th><th>IP</th></tr></thead><tbody>
@forelse($logs as $l)<tr><td class="small" style="white-space:nowrap">{{ $l->created_at->format('d M Y H:i:s') }}</td><td>{{ $l->user?->name ?? 'System' }}</td><td><span class="badge badge-slate">{{ $l->module }}</span></td><td class="small">{{ str_replace('_',' ',$l->action) }}</td><td>{{ $l->description }}</td><td class="tiny muted">{{ $l->ip }}</td></tr>
@empty<tr><td colspan="6"><x-empty text="No activity for these filters." icon="shield"/></td></tr>@endforelse
</tbody></table>{{ $logs->links() }}</div></div>
@endsection
