@extends('layouts.app')
@section('title', 'Channel Sync Log')
@section('crumbs') <a href="{{ route('cm.index') }}">Channel Manager</a> / Sync log @endsection
@section('content')
@include('channel-manager._nav')
@if($failed24)<div class="alert alert-warn"><x-icon name="alert"/><div><b>{{ $failed24 }}</b> failed push(es) in the last 24 hours. Failed pushes retry automatically after 1, 5, 15, 30 and then every 60 minutes; nothing is lost – the next successful push sends every pending change.</div></div>@endif
<form class="filters" method="get" id="pushFilter">
  <select name="connection"><option value="">All channels</option>@foreach($connections as $id => $n)<option value="{{ $id }}" @selected(request('connection') == $id)>{{ $n }}</option>@endforeach</select>
  <select name="status"><option value="">Any result</option><option value="ok" @selected(request('status') === 'ok')>OK</option><option value="failed" @selected(request('status') === 'failed')>Failed</option></select>
  <button class="btn">Filter</button></form>
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="pushTable">
<thead><tr><th>Time</th><th>Channel</th><th>Type</th><th>Dates</th><th class="num">Room-nights</th><th class="num">Requests</th><th class="num">Duration</th><th>Result</th><th>By</th></tr></thead><tbody>
@forelse($pushes as $p)
<tr><td class="small nowrap">{{ $p->created_at->format('d M H:i:s') }}</td><td class="small">{{ $p->connection?->name }}</td>
  <td><span class="badge badge-{{ $p->kind === 'full' ? 'purple' : 'blue' }}">{{ $p->kind }}</span></td>
  <td class="small nowrap">{{ $p->date_from?->format('d M') }} – {{ $p->date_to?->format('d M Y') }}</td>
  <td class="num">{{ number_format($p->room_days) }}</td><td class="num">{{ $p->requests }}</td><td class="num">{{ number_format($p->duration_ms) }} ms</td>
  <td>@if($p->status === 'ok')<span class="badge badge-green">OK</span>@if($p->http_status) <span class="tiny muted">HTTP {{ $p->http_status }}</span>@endif @else<span class="badge badge-red">Failed</span> <span class="tiny muted">attempt {{ $p->attempt }}</span><div class="tiny" style="color:#b91c1c;max-width:320px">{{ $p->error }}</div>@endif</td>
  <td class="small">{{ $p->user?->name ?? 'Scheduler' }}</td></tr>
@empty<tr><td colspan="9"><x-empty text="No pushes yet." icon="refresh"/></td></tr>@endforelse
</tbody></table></div></div>
{{ $pushes->links() }}
@endsection
