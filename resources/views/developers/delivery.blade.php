@extends('layouts.app')
@section('title', 'Webhook delivery #'.$d->id)
@section('crumbs') Administration / <a href="{{ route('developers.index', ['tab' => 'deliveries']) }}">API & Webhooks</a> / Delivery #{{ $d->id }} @endsection
@section('actions')
  <a class="btn" href="{{ route('developers.index', ['tab' => 'deliveries']) }}"><x-icon name="chev"/> Deliveries</a>
  @if(can_do('api.manage') && $d->status !== 'success')<form method="post" action="{{ route('developers.deliveries.retry', $d) }}" style="display:inline">@csrf<button class="btn btn-primary" id="retryBtn"><x-icon name="refresh"/> Retry now</button></form>@endif
@endsection
@section('content')
<div class="grid g-2-1" style="gap:1rem">
  <div class="card">
    <div class="card-head"><h3><code>{{ $d->event }}</code></h3><span class="badge badge-{{ $d->statusColor() }}" id="delStatus">{{ $d->statusLabel() }}</span></div>
    <div class="card-body">
      <div class="tiny muted" style="margin-bottom:.3rem">Payload (the exact body that is signed and sent)</div>
      <pre class="codeblock" id="payload">{{ json_encode($d->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
      @if($d->response_body !== null)
        <div class="tiny muted" style="margin:.8rem 0 .3rem">Last response body (first 2 KB)</div>
        <pre class="codeblock" style="max-height:200px">{{ $d->response_body }}</pre>
      @endif
    </div>
  </div>
  <div>
    <div class="card"><div class="card-body small">
      <table class="table small"><tbody>
        <tr><td class="muted">Endpoint</td><td><b>{{ $d->endpoint?->name }}</b><div class="tiny mono" style="word-break:break-all">{{ $d->endpoint?->url }}</div></td></tr>
        <tr><td class="muted">Event id</td><td class="mono tiny">{{ $d->event_id }}</td></tr>
        <tr><td class="muted">Created</td><td>{{ $d->created_at->format('d M Y H:i:s') }}</td></tr>
        <tr><td class="muted">Attempts</td><td>{{ $d->attempts }} <span class="muted small">(automatic limit {{ \App\Services\WebhookService::MAX_ATTEMPTS }} – manual retries come on top)</span></td></tr>
        @if($d->delivered_at)<tr><td class="muted">Delivered</td><td class="text-green">{{ $d->delivered_at->format('d M Y H:i:s') }}</td></tr>@endif
        @if($d->status === 'pending' && $d->next_attempt_at)<tr><td class="muted">Next attempt</td><td>{{ $d->next_attempt_at->format('d M H:i:s') }} ({{ $d->next_attempt_at->diffForHumans() }})</td></tr>@endif
        @if($d->error)<tr><td class="muted">Last error</td><td class="text-red">{{ $d->error }}</td></tr>@endif
      </tbody></table>
    </div></div>
    <div class="card"><div class="card-head"><h3>Attempts</h3></div>
      <div class="table-wrap"><table class="table small" id="attemptTable"><thead><tr><th>When</th><th>Result</th><th class="right">ms</th></tr></thead><tbody>
        @forelse(array_reverse($d->attempt_log ?? []) as $a)
          <tr><td class="nowrap">{{ \Illuminate\Support\Carbon::parse($a['at'])->format('d M H:i:s') }}@if(! empty($a['manual'])) <span class="badge badge-blue">manual</span>@endif</td>
            <td>@if(! empty($a['code']))<span class="{{ $a['code'] < 300 ? 'text-green' : 'text-red' }}">HTTP {{ $a['code'] }}</span>@endif @if(! empty($a['error']) && $a['error'] !== 'HTTP '.($a['code'] ?? ''))<div class="tiny text-red">{{ $a['error'] }}</div>@endif</td>
            <td class="right">{{ $a['ms'] ?? '' }}</td></tr>
        @empty
          <tr><td colspan="3" class="empty">Not attempted yet.</td></tr>
        @endforelse
      </tbody></table></div>
    </div>
  </div>
</div>
@endsection
