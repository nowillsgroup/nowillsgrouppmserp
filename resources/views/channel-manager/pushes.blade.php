@extends('layouts.app')
@section('title', 'Channel Sync Log')
@section('crumbs') <a href="{{ route('cm.index') }}">Channel Manager</a> / Sync log @endsection
@section('content')
<div class="cm-page">
@include('channel-manager._nav')
<header class="cm-hero"><div class="cm-hero-copy"><div class="cm-kicker"><x-icon name="refresh"/> Outbound ARI history</div><h2>Channel sync log</h2><p>Review availability, rate and restriction pushes, including retry state, request counts and failure details.</p></div><span class="cm-hero-mark" aria-hidden="true"><x-icon name="refresh"/></span></header>
@if($failed24)<div class="alert alert-warn" role="status"><x-icon name="alert"/><div><b>{{ $failed24 }} failed push(es)</b> in the last 24 hours. Failed pushes retry automatically after 1, 5, 15, 30 and then every 60 minutes; pending changes remain queued until a successful push.</div></div>@endif
<form class="cm-filter" method="get" id="pushFilter">
  <label class="field"><span>Connection</span><select name="connection" aria-label="Filter sync history by channel"><option value="">All channels</option>@foreach($connections as $id => $n)<option value="{{ $id }}" @selected(request('connection') == $id)>{{ $n }}</option>@endforeach</select></label>
  <label class="field"><span>Result</span><select name="status" aria-label="Filter sync history by result"><option value="">Any result</option><option value="ok" @selected(request('status') === 'ok')>Successful</option><option value="failed" @selected(request('status') === 'failed')>Failed</option></select></label>
  <button class="btn btn-primary"><x-icon name="search"/> Apply filters</button>
  @if(request('connection') || request('status'))<a class="btn btn-ghost" href="{{ route('cm.pushes') }}">Clear filters</a>@endif
</form>
<section class="card flush" aria-label="Channel push history"><div class="card-body table-wrap" role="region" aria-label="Scrollable channel sync log" tabindex="0"><table class="table cm-push-table" id="pushTable">
<caption class="cm-sr-only">Outbound channel pushes with dates, room-night volume, request timing and outcomes.</caption>
<thead><tr><th scope="col">Time</th><th scope="col">Channel</th><th scope="col">Type</th><th scope="col">Dates</th><th scope="col" class="num">Room-nights</th><th scope="col" class="num">Requests</th><th scope="col" class="num">Duration</th><th scope="col">Result</th><th scope="col">By</th></tr></thead><tbody>
@forelse($pushes as $p)
<tr><td class="small nowrap"><time datetime="{{ $p->created_at->toIso8601String() }}">{{ $p->created_at->format('d M H:i:s') }}</time></td><td class="small">{{ $p->connection?->name ?? 'Deleted connection' }}</td>
  <td><span class="badge badge-{{ $p->kind === 'full' ? 'purple' : 'blue' }}">{{ $p->kind }}</span></td>
  <td class="small nowrap">{{ $p->date_from?->format('d M') }} – {{ $p->date_to?->format('d M Y') }}</td>
  <td class="num">{{ number_format($p->room_days) }}</td><td class="num">{{ $p->requests }}</td><td class="num">{{ number_format($p->duration_ms) }} ms</td>
  <td>@if($p->status === 'ok')<span class="badge badge-green">OK</span>@if($p->http_status) <span class="tiny muted">HTTP {{ $p->http_status }}</span>@endif @else<span class="badge badge-red">Failed</span> <span class="tiny muted">attempt {{ $p->attempt }}</span><div class="tiny cm-error-text" style="max-width:320px">{{ $p->error }}</div>@endif</td>
  <td class="small">{{ $p->user?->name ?? 'Scheduler' }}</td></tr>
@empty<tr><td colspan="9"><x-empty text="No pushes match these filters." icon="refresh"/></td></tr>@endforelse
</tbody></table></div></section>
{{ $pushes->links() }}
</div>
@endsection
