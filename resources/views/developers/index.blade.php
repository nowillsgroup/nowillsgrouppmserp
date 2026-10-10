@extends('layouts.app')
@section('title', 'API & Webhooks')
@section('crumbs') Administration / API & Webhooks / {{ \App\Http\Controllers\DeveloperController::TABS[$tab] }} @endsection
@section('actions')
  <a class="btn" href="{{ route('apiv1.openapi') }}" target="_blank"><x-icon name="download"/> OpenAPI spec</a>
  @if(can_do('api.manage'))
    @if($tab === 'webhooks' || $tab === 'deliveries')<button class="btn btn-primary" data-modal="hookModal" id="addHookBtn"><x-icon name="plus"/> Add webhook</button>
    @else<button class="btn btn-primary" data-modal="clientModal" id="addClientBtn"><x-icon name="plus"/> New API key</button>@endif
  @endif
@endsection
@section('content')
@php $base = url('/api/v1'); @endphp

@if($sec = session('api_secret'))
  <div class="secret-box" id="secretBox">
    <b><x-icon name="key"/> {{ $sec['kind'] === 'token' ? 'API key' : 'Webhook signing secret' }} for “{{ $sec['label'] }}” – copy it now.</b>
    <div class="small">It is shown only this once; Nowills stores only a {{ $sec['kind'] === 'token' ? 'one-way hash' : 'encrypted copy' }} and cannot show it again. If it is lost, rotate it.</div>
    <div class="val"><input class="input" id="secretValue" readonly value="{{ $sec['value'] }}" onclick="this.select()"><button type="button" class="btn btn-primary" id="copySecret" data-copy="#secretValue"><x-icon name="check"/> Copy</button></div>
  </div>
@endif
@if(! $cfg['enabled'])<div class="alert alert-warn" id="apiOffAlert"><x-icon name="alert"/><div><b>The API is switched off.</b> Every call is answered with <code>503 api_disabled</code>; webhook events are not queued. @if(can_do('api.manage'))<a href="{{ route('developers.index', ['tab' => 'settings']) }}">Settings</a>@endif</div></div>@endif
@if($wh['disabled'])<div class="alert alert-error" id="hookDisabledAlert"><x-icon name="alert"/><div><b>{{ $wh['disabled'] }} webhook endpoint(s) were switched off</b> after repeated failures – their subscribers are missing events. <a href="{{ route('developers.index', ['tab' => 'webhooks']) }}">Review</a></div></div>@endif

<div class="stats">
  <x-stat label="Active API keys" :value="$snap['clients']" icon="key" tone="blue" :href="route('developers.index', ['tab' => 'clients'])" sub="issued to partners & apps"/>
  <x-stat label="API requests (24 h)" :value="number_format($snap['requests'])" icon="chart" :tone="$snap['requests'] && $snap['errors'] * 10 > $snap['requests'] ? 'amber' : 'green'" :href="route('developers.index', ['tab' => 'log'])" :sub="$snap['errors'].' returned an error'"/>
  <x-stat label="Webhook deliveries (24 h)" :value="$wh['total']" icon="link" :tone="$wh['rate'] !== null && $wh['rate'] < 95 ? 'amber' : 'green'" :href="route('developers.index', ['tab' => 'deliveries'])" :sub="$wh['rate'] === null ? 'nothing sent yet' : $wh['rate'].'% delivered · '.$wh['pending'].' pending'"/>
  <x-stat label="Failed deliveries (7 d)" :value="$wh['failed']" icon="alert" :tone="$wh['failed'] ? 'red' : 'green'" :href="route('developers.index', ['tab' => 'deliveries', 'status' => 'failed'])" :sub="$snap['endpoints'].' endpoint(s) active'"/>
</div>

<div class="card flush">
  <div class="card-head"><div class="tabs" style="margin:0;border:0">
    @foreach(\App\Http\Controllers\DeveloperController::TABS as $k => $l)
      @if($k !== 'settings' || can_do('api.manage'))<a href="{{ route('developers.index', ['tab' => $k]) }}" class="{{ $tab === $k ? 'active' : '' }}" data-tab="{{ $k }}">{{ $l }}</a>@endif
    @endforeach
  </div></div>

@if($tab === 'clients')
  <div class="table-wrap"><table class="table" id="clientTable">
    <thead><tr><th>Client</th><th>Key</th><th>Scopes</th><th>Limits</th><th>Last used</th><th class="right">24 h calls</th><th>Status</th>@if(can_do('api.manage'))<th></th>@endif</tr></thead>
    <tbody>
    @forelse($clients as $c)
      @php $u = $usage[$c->id] ?? null; $st = $c->state(); @endphp
      <tr data-client="{{ $c->id }}" data-state="{{ $st }}">
        <td><b>{{ $c->name }}</b>@if($c->description)<div class="tiny muted">{{ $c->description }}</div>@endif
          <div class="tiny muted">by {{ $c->creator?->name ?? 'system' }} · {{ $c->created_at->format('d M Y') }}@if($c->owner) · acts as {{ $c->owner->name }}@endif</div></td>
        <td><code class="small">{{ $c->maskedToken() }}</code>@if($c->rotated_at)<div class="tiny muted">rotated {{ $c->rotated_at->diffForHumans() }}</div>@endif</td>
        <td style="max-width:280px">@foreach($c->scopes as $s)<span class="badge {{ str_ends_with($s, ':write') ? 'badge-amber' : 'badge-slate' }}" style="margin:.1rem">{{ $s }}</span>@endforeach</td>
        <td class="small">{{ $c->rate_limit }}/min<div class="tiny muted">@if($c->ip_allowlist){{ count(explode("\n", $c->ip_allowlist)) }} IP rule(s)@else<span class="text-amber">any IP</span>@endif @if($c->expires_on)· until {{ $c->expires_on->format('d M Y') }}@endif</div></td>
        <td class="small">@if($c->last_used_at){{ $c->last_used_at->diffForHumans() }}<div class="tiny muted mono">{{ $c->last_ip }}</div>@else<span class="muted">never</span>@endif</td>
        <td class="right">@if($u)<a href="{{ route('developers.index', ['tab' => 'log', 'client' => $c->id]) }}">{{ $u->n }}</a>@if($u->errors)<div class="tiny text-red">{{ $u->errors }} errors</div>@endif @else<span class="muted">0</span>@endif</td>
        <td><span class="badge badge-{{ $c->stateColor() }}">{{ ucfirst($st) }}</span>@if($c->revoked_at)<div class="tiny muted">{{ $c->revoked_at->format('d M Y') }}</div>@endif</td>
        @if(can_do('api.manage'))
        <td class="nowrap right">@if(! $c->revoked_at)
          <button class="btn btn-sm btn-ghost btn-icon" data-modal="clientEdit{{ $c->id }}" title="Edit"><x-icon name="edit"/></button>
          <form method="post" action="{{ route('developers.clients.rotate', $c) }}" style="display:inline" data-confirm="Issue a new key for {{ $c->name }}? The current key stops working immediately.">@csrf<button class="btn btn-sm btn-ghost btn-icon rotate-btn" title="Rotate key"><x-icon name="refresh"/></button></form>
          <button class="btn btn-sm btn-ghost btn-icon" data-modal="revokeModal" data-action="{{ route('developers.clients.revoke', $c) }}" data-title="Revoke {{ $c->name }}" title="Revoke"><x-icon name="trash"/></button>
        @endif</td>
        @endif
      </tr>
    @empty
      <tr><td colspan="8" class="empty">No API keys yet. Issue one per partner or app – e.g. your website’s booking widget, a revenue-management tool or a BI dashboard.</td></tr>
    @endforelse
    </tbody>
  </table></div>
  <div class="card-body pad small muted"><x-icon name="shield"/> Each key belongs to this hotel only and can do no more than its scopes allow. Keys are stored as SHA-256 hashes; every call is written to the <a href="{{ route('developers.index', ['tab' => 'log']) }}">request log</a>. Rotate a key when staff or vendors change, revoke it when an integration ends.</div>

@elseif($tab === 'webhooks')
  <div class="table-wrap"><table class="table" id="hookTable">
    <thead><tr><th>Endpoint</th><th>Events</th><th class="right">7 d delivered</th><th>Last success / failure</th><th>Status</th>@if(can_do('api.manage'))<th></th>@endif</tr></thead>
    <tbody>
    @forelse($endpoints as $e)
      @php $h = $health[$e->id] ?? null; $st = $e->state(); @endphp
      <tr data-hook="{{ $e->id }}" data-state="{{ $st }}">
        <td><b>{{ $e->name }}</b><div class="tiny mono muted" style="word-break:break-all">{{ $e->url }}</div>@if($e->description)<div class="tiny muted">{{ $e->description }}</div>@endif</td>
        <td style="max-width:260px">@if(in_array('*', $e->events, true))<span class="badge badge-purple">all events</span>@else @foreach($e->events as $ev)<span class="badge badge-slate" style="margin:.1rem">{{ $ev }}</span>@endforeach @endif</td>
        <td class="right">@if($h && $h->n){{ round($h->ok * 100 / $h->n) }}%<div class="tiny muted">{{ $h->ok }}/{{ $h->n }}@if($h->pending) · {{ $h->pending }} pending @endif</div>@else<span class="muted">–</span>@endif</td>
        <td class="small">@if($e->last_success_at)<span class="text-green">✓ {{ $e->last_success_at->diffForHumans() }}</span>@endif @if($e->last_failure_at)<div class="text-red">✗ {{ $e->last_failure_at->diffForHumans() }}</div>@endif @if(! $e->last_success_at && ! $e->last_failure_at)<span class="muted">nothing sent</span>@endif</td>
        <td><span class="badge badge-{{ $e->stateColor() }}">{{ ucfirst($st) }}</span>@if($e->consecutive_failures && ! $e->disabled_reason)<div class="tiny text-red">{{ $e->consecutive_failures }} failure(s) in a row</div>@endif
          @if($e->disabled_reason)<div class="tiny text-red" style="max-width:220px">{{ $e->disabled_reason }}</div>@endif</td>
        @if(can_do('api.manage'))
        <td class="nowrap right">
          <form method="post" action="{{ route('developers.webhooks.ping', $e) }}" style="display:inline">@csrf<button class="btn btn-sm ping-btn" title="Send a signed test event now"><x-icon name="link"/> Test</button></form>
          <form method="post" action="{{ route('developers.webhooks.toggle', $e) }}" style="display:inline">@csrf<button class="btn btn-sm toggle-btn">{{ $e->is_active ? 'Pause' : 'Enable' }}</button></form>
          <button class="btn btn-sm btn-ghost btn-icon" data-modal="hookEdit{{ $e->id }}" title="Edit"><x-icon name="edit"/></button>
          <form method="post" action="{{ route('developers.webhooks.rotate', $e) }}" style="display:inline" data-confirm="Rotate the signing secret? For 24 hours deliveries carry signatures for both the old and the new secret, so the receiver can switch over.">@csrf<button class="btn btn-sm btn-ghost btn-icon" title="Rotate secret"><x-icon name="key"/></button></form>
          <form method="post" action="{{ route('developers.webhooks.delete', $e) }}" style="display:inline" data-confirm="Delete this endpoint and its delivery history?">@csrf<button class="btn btn-sm btn-ghost btn-icon" title="Delete"><x-icon name="trash"/></button></form>
        </td>
        @endif
      </tr>
    @empty
      <tr><td colspan="6" class="empty">No webhook endpoints. Add one to push reservations, check-ins, room status changes, payments and night-audit figures to another system as they happen.</td></tr>
    @endforelse
    </tbody>
  </table></div>
  <div class="card-body pad" style="border-top:1px solid var(--c-border)"><div class="grid g-2" style="gap:1.2rem">
    <div class="small">
      <h4 style="margin:0 0 .4rem">How delivery works</h4>
      <ul class="chk-list" style="font-size:.84rem">
        <li>Each event is POSTed as JSON within about a minute (<code>pms:webhooks</code> runs every minute), with a 10-second timeout.</li>
        <li>Any 2xx answer counts as delivered. Otherwise it is retried after 1 min, 5 min, 30 min, 2 h, 6 h, 12 h and 24 h – {{ \App\Services\WebhookService::MAX_ATTEMPTS }} attempts in all.</li>
        <li>After {{ $cfg['disable_after'] }} failures in a row the endpoint is switched off and the log book is notified; enabling it again delivers everything still pending.</li>
        <li>Only public HTTPS addresses are called and redirects are not followed.</li>
        <li>Delivery is at least once: use <code>X-Nowills-Event-Id</code> to ignore repeats.</li>
      </ul>
    </div>
    <div class="small">
      <h4 style="margin:0 0 .4rem">Verifying the signature (PHP)</h4>
<pre class="codeblock">$sig = $_SERVER['HTTP_X_NOWILLS_SIGNATURE'];   // t=…,v1=…[,v1=…]
parse_str(str_replace(',', '&amp;', $sig), $p);         // t, v1 (last one)
preg_match_all('/v1=([a-f0-9]{64})/', $sig, $m);
$body = file_get_contents('php://input');
$expected = hash_hmac('sha256', $p['t'].'.'.$body, $secret);
$ok = abs(time() - (int) $p['t']) &lt;= 300
   &amp;&amp; in_array(true, array_map(fn ($v) =&gt; hash_equals($expected, $v), $m[1]), true);</pre>
    </div>
  </div></div>

@elseif($tab === 'deliveries')
  <div class="card-body pad" style="padding-bottom:.4rem">
    <form method="get" class="flex" style="gap:.5rem;flex-wrap:wrap" id="delFilter"><input type="hidden" name="tab" value="deliveries">
      <select class="input" style="width:auto" name="endpoint"><option value="">All endpoints</option>@foreach($endpoints as $id => $n)<option value="{{ $id }}" @selected((string) request('endpoint') === (string) $id)>{{ $n }}</option>@endforeach</select>
      <select class="input" style="width:auto" name="status"><option value="">Any status</option>@foreach(\App\Models\WebhookDelivery::STATUSES as $k => [$l])<option value="{{ $k }}" @selected(request('status') === $k)>{{ $l }}</option>@endforeach</select>
      <select class="input" style="width:auto" name="event"><option value="">Any event</option>@foreach(array_merge(array_keys(\App\Services\WebhookService::EVENTS), ['ping']) as $ev)<option @selected(request('event') === $ev)>{{ $ev }}</option>@endforeach</select>
      <button class="btn"><x-icon name="search"/> Filter</button>
      @if(can_do('api.manage'))<span style="flex:1"></span><button class="btn" form="deliverNowForm" id="deliverNowBtn" title="Send everything that is due now instead of waiting for the scheduler"><x-icon name="refresh"/> Deliver due now</button>@endif
    </form>
    @if(can_do('api.manage'))<form method="post" action="{{ route('developers.deliver') }}" id="deliverNowForm">@csrf</form>@endif
  </div>
  <div class="table-wrap"><table class="table" id="delTable">
    <thead><tr><th>#</th><th>Event</th><th>Endpoint</th><th>Created</th><th class="right">Attempts</th><th>Last result</th><th>Status</th><th></th></tr></thead>
    <tbody>
    @forelse($deliveries as $d)
      <tr data-delivery="{{ $d->id }}" data-status="{{ $d->status }}">
        <td><a href="{{ route('developers.delivery', $d) }}">{{ $d->id }}</a></td>
        <td><code class="small">{{ $d->event }}</code><div class="tiny muted mono">{{ $d->event_id }}</div></td>
        <td class="small">{{ $d->endpoint?->name }}</td>
        <td class="small">{{ $d->created_at->format('d M H:i:s') }}</td>
        <td class="right">{{ $d->attempts }}</td>
        <td class="small">@if($d->response_code)<span class="{{ $d->response_code < 300 ? 'text-green' : 'text-red' }}">HTTP {{ $d->response_code }}</span> · {{ $d->duration_ms }} ms @endif
          @if($d->error && $d->error !== 'HTTP '.$d->response_code)<div class="tiny text-red">{{ \Illuminate\Support\Str::limit($d->error, 80) }}</div>@endif
          @if($d->status === 'pending' && $d->next_attempt_at)<div class="tiny muted">next {{ $d->next_attempt_at->diffForHumans() }}</div>@endif</td>
        <td><span class="badge badge-{{ $d->statusColor() }}">{{ $d->statusLabel() }}</span></td>
        <td class="nowrap right"><a class="btn btn-sm btn-ghost" href="{{ route('developers.delivery', $d) }}"><x-icon name="eye"/></a>
          @if(can_do('api.manage') && $d->status !== 'success')<form method="post" action="{{ route('developers.deliveries.retry', $d) }}" style="display:inline">@csrf<button class="btn btn-sm retry-btn">Retry now</button></form>@endif</td>
      </tr>
    @empty
      <tr><td colspan="8" class="empty">No deliveries match.</td></tr>
    @endforelse
    </tbody>
  </table></div>
  <div class="card-body pad">{{ $deliveries->links() }}</div>

@elseif($tab === 'log')
  <div class="card-body pad" style="padding-bottom:.4rem">
    <form method="get" class="flex" style="gap:.5rem;flex-wrap:wrap" id="logFilter"><input type="hidden" name="tab" value="log">
      <select class="input" style="width:auto" name="client"><option value="">All clients</option>@foreach($clients as $id => $n)<option value="{{ $id }}" @selected((string) request('client') === (string) $id)>{{ $n }}</option>@endforeach</select>
      <select class="input" style="width:auto" name="result"><option value="">Any result</option><option value="ok" @selected(request('result') === 'ok')>Success (2xx)</option><option value="client" @selected(request('result') === 'client')>Client error (4xx)</option><option value="server" @selected(request('result') === 'server')>Server error (5xx)</option></select>
      <input class="input" name="route" value="{{ request('route') }}" placeholder="Route, e.g. reservations.store" style="max-width:220px">
      <button class="btn"><x-icon name="search"/> Filter</button>
    </form>
  </div>
  @if($routes->count())
  <div class="card-body pad" style="padding-top:.2rem">
    <div class="tiny muted" style="margin-bottom:.3rem">Busiest calls – last 24 h @if($refused)· <span class="text-red" id="refusedCount">{{ $refused }} call(s) refused with no valid key (all hotels’ public endpoint)</span>@endif</div>
    <div class="flex" style="gap:.4rem;flex-wrap:wrap">@foreach($routes as $r)<a class="badge badge-slate" href="{{ route('developers.index', ['tab' => 'log', 'route' => $r->route]) }}">{{ $r->route }} · {{ $r->n }}@if($r->errors) · <span class="text-red">{{ $r->errors }} err</span>@endif · {{ $r->ms }} ms</a>@endforeach</div>
  </div>
  @endif
  <div class="table-wrap"><table class="table" id="logTable">
    <thead><tr><th>Time</th><th>Client</th><th>Request</th><th class="right">Status</th><th class="right">ms</th><th>IP</th><th>Request id</th></tr></thead>
    <tbody>
    @forelse($logs as $l)
      <tr data-status="{{ $l->status }}">
        <td class="small nowrap">{{ $l->created_at->format('d M H:i:s') }}</td>
        <td class="small">{{ $l->client?->name ?? '—' }}</td>
        <td><span class="http http-{{ $l->method }}">{{ $l->method }}</span> <code class="small">{{ \Illuminate\Support\Str::limit($l->path, 70) }}</code></td>
        <td class="right"><span class="badge badge-{{ $l->statusColor() }}">{{ $l->status }}</span>@if($l->error_code)<div class="tiny muted">{{ $l->error_code }}</div>@endif</td>
        <td class="right small">{{ $l->duration_ms }}</td>
        <td class="small mono">{{ $l->ip }}</td>
        <td class="tiny mono muted">{{ $l->request_id }}</td>
      </tr>
    @empty
      <tr><td colspan="7" class="empty">No API requests logged{{ request()->hasAny(['client', 'result', 'route']) ? ' for this filter' : ' yet' }}. Entries are kept for {{ $cfg['log_days'] }} days.</td></tr>
    @endforelse
    </tbody>
  </table></div>
  <div class="card-body pad">{{ $logs->links() }}</div>

@elseif($tab === 'docs')
  <div class="card-body pad">
    <div class="grid g-2" style="gap:1.2rem">
      <div>
        <h3 style="margin:0 0 .4rem">Getting started</h3>
        <p class="small">Base URL <code id="baseUrl">{{ $base }}</code> · JSON in, JSON out · dates <code>YYYY-MM-DD</code>, times ISO 8601 in the hotel’s time zone ({{ \App\Support\Tenant::hotel()->timezone ?? config('app.timezone') }}).</p>
        <p class="small">Send the key as a bearer token. Successful responses are wrapped in <code>{"data": …}</code> (lists add <code>meta</code> and <code>links</code>). Every response carries <code>X-Request-Id</code>, <code>X-RateLimit-Limit</code> and <code>X-RateLimit-Remaining</code>.</p>
<pre class="codeblock">curl {{ $base }}/availability?from={{ bdate()->copy()->addDays(10)->toDateString() }}&amp;to={{ bdate()->copy()->addDays(12)->toDateString() }} \
  -H "Authorization: Bearer nwp_xxxxxxxx_…"</pre>
<pre class="codeblock">curl -X POST {{ $base }}/reservations \
  -H "Authorization: Bearer nwp_xxxxxxxx_…" \
  -H "Idempotency-Key: 7f9c2b1e-web-10293" \
  -H "Content-Type: application/json" \
  -d '{"arrival":"{{ bdate()->copy()->addDays(10)->toDateString() }}","departure":"{{ bdate()->copy()->addDays(12)->toDateString() }}","room_type":"DLX",
       "guest":{"first_name":"Ama","last_name":"Mensah","email":"ama@example.com"},
       "external_ref":"WEB-10293"}'</pre>
      </div>
      <div>
        <h3 style="margin:0 0 .4rem">Scopes</h3>
        <table class="table small"><tbody>@foreach(\App\Models\ApiClient::SCOPES as $s => [$l])<tr><td><code>{{ $s }}</code></td><td>{{ $l }}</td></tr>@endforeach</tbody></table>
        <h3 style="margin:.8rem 0 .4rem">Errors</h3>
        <p class="tiny muted" style="margin:0 0 .3rem"><code>{"error": {"code": "…", "message": "…", "fields": {…}}, "request_id": "…"}</code></p>
        <table class="table small" id="errorTable"><tbody>@foreach(\App\Support\ApiSpec::ERRORS as $code => [$http, $l])<tr><td class="right">{{ $http }}</td><td><code>{{ $code }}</code></td><td>{{ $l }}</td></tr>@endforeach</tbody></table>
      </div>
    </div>
  </div>
  <div id="endpointDocs">
  @foreach($endpoints as [$method, $path, $scope, $summary, $desc, $params, $body, $tag])
    <div class="ep-doc" data-endpoint="{{ $method }} {{ $path }}">
      <h4><span class="http http-{{ $method }}">{{ $method }}</span> <code>/api/v1{{ $path === '/' ? '' : $path }}</code> <span>{{ $summary }}</span>
        @if($scope)<span class="badge {{ str_ends_with($scope, ':write') ? 'badge-amber' : 'badge-slate' }}">{{ $scope }}</span>@else<span class="badge badge-green">any key</span>@endif <span class="tiny muted">{{ $tag }}</span></h4>
      <div class="small">{{ $desc }}</div>
      @if($params)<table class="table small" style="margin-top:.4rem;max-width:860px"><tbody>@foreach($params as [$pn, $pin, $pt, $preq, $pd])<tr><td style="width:160px"><code>{{ $pn }}</code>@if($preq) <span class="req">*</span>@endif</td><td style="width:70px" class="muted">{{ $pin }}</td><td style="width:80px" class="muted">{{ $pt }}</td><td>{{ $pd }}</td></tr>@endforeach</tbody></table>@endif
      @if($body)<pre class="codeblock">{{ json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>@endif
    </div>
  @endforeach
  </div>
  <div class="ep-doc">
    <h4>Webhook events</h4>
    <table class="table small" style="max-width:860px"><tbody>@foreach(\App\Services\WebhookService::EVENTS as $ev => [$l, $g])<tr><td style="width:220px"><code>{{ $ev }}</code></td><td class="muted" style="width:110px">{{ $g }}</td><td>{{ $l }}</td></tr>@endforeach</tbody></table>
<pre class="codeblock">POST https://your-endpoint.example/…
X-Nowills-Event: reservation.created
X-Nowills-Delivery: 1289
X-Nowills-Event-Id: evt_5b0c…
X-Nowills-Signature: t=1790000000,v1=&lt;HMAC-SHA256(secret, "t.body") hex&gt;

{"id":"evt_5b0c…","type":"reservation.created","created_at":"…","api_version":"v1",
 "hotel":{"code":"{{ \App\Support\Tenant::hotel()->code ?? 'HOTEL' }}"},"data":{"reservation":{…same shape as GET /reservations/{no}…}}}</pre>
  </div>

@elseif($tab === 'settings')
  <form method="post" action="{{ route('developers.settings') }}" class="card-body pad" id="apiSettings" style="max-width:760px">@csrf
    <div class="form-grid">
      <div class="field span-2"><label class="check"><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" @checked($cfg['enabled'])> <b>API enabled</b> – switch off to refuse every API call at once (503) and stop queuing webhook events</label></div>
      <x-field name="log_days" label="Keep request log & finished deliveries (days)" type="number" min="1" max="365" required :value="$cfg['log_days']"/>
      <x-field name="disable_after" label="Switch an endpoint off after N failures in a row" type="number" min="3" max="500" required :value="$cfg['disable_after']"/>
      <div class="field span-2"><label class="check"><input type="hidden" name="allow_http" value="0"><input type="checkbox" name="allow_http" value="1" @checked($cfg['allow_http'])> Allow plain-HTTP webhook URLs <span class="tiny muted">(signatures still protect integrity, but payloads with guest data travel unencrypted)</span></label></div>
      <div class="field span-2"><label class="check"><input type="hidden" name="allow_private" value="0"><input type="checkbox" name="allow_private" value="1" @checked($cfg['allow_private'])> Allow private / internal network addresses <span class="tiny muted">(only for on-premise systems on the hotel LAN – otherwise keep off to prevent server-side request forgery)</span></label></div>
    </div>
    <div style="margin-top:.8rem"><button type="submit" class="btn btn-primary">Save settings</button></div>
  </form>
@endif
</div>

@if(can_do('api.manage'))
<div class="modal" id="clientModal"><div class="modal-box" style="max-width:820px"><form method="post" action="{{ route('developers.clients.store') }}" data-reset class="client-form">@csrf
  <div class="modal-head"><h3>New API key</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">@include('developers._client', ['c' => null])</div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button type="submit" class="btn btn-primary">Create key</button></div>
</form></div></div>

<div class="modal" id="revokeModal"><div class="modal-box" style="max-width:480px"><form method="post" action="">@csrf
  <div class="modal-head"><h3 data-title>Revoke key</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><p class="small">The key stops working immediately and cannot be re-activated. Reservations it created stay as they are.</p>
    <x-field name="reason" label="Reason" required maxlength="190" placeholder="Contract with vendor ended"/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button type="submit" class="btn btn-danger">Revoke</button></div>
</form></div></div>

<div class="modal" id="hookModal"><div class="modal-box" style="max-width:820px"><form method="post" action="{{ route('developers.webhooks.store') }}" data-reset>@csrf
  <div class="modal-head"><h3>Add webhook endpoint</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">@include('developers._hook', ['e' => null])</div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button type="submit" class="btn btn-primary">Add endpoint</button></div>
</form></div></div>

@if($tab === 'clients')
  @foreach($clients->whereNull('revoked_at') as $c)
  <div class="modal" id="clientEdit{{ $c->id }}"><div class="modal-box" style="max-width:820px"><form method="post" action="{{ route('developers.clients.update', $c) }}" class="client-form">@csrf
    <div class="modal-head"><h3>Edit {{ $c->name }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
    <div class="modal-body form-grid">@include('developers._client', ['c' => $c])</div>
    <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button type="submit" class="btn btn-primary">Save</button></div>
  </form></div></div>
  @endforeach
@endif
@if($tab === 'webhooks')
  @foreach($endpoints as $e)
  <div class="modal" id="hookEdit{{ $e->id }}"><div class="modal-box" style="max-width:820px"><form method="post" action="{{ route('developers.webhooks.update', $e) }}">@csrf
    <div class="modal-head"><h3>Edit {{ $e->name }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
    <div class="modal-body form-grid">@include('developers._hook', ['e' => $e])</div>
    <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button type="submit" class="btn btn-primary">Save</button></div>
  </form></div></div>
  @endforeach
@endif
@endif

<script>
document.addEventListener('click', function (e) {
  var p = e.target.closest('[data-preset]');
  if (p) {
    var set = p.dataset.preset.split(','), form = p.closest('form');
    form.querySelectorAll('input[name="scopes[]"]').forEach(function (cb) { cb.checked = set.indexOf(cb.value) !== -1; });
  }
  var c = e.target.closest('[data-copy]');
  if (c) {
    var inp = document.querySelector(c.dataset.copy); inp.select();
    (navigator.clipboard ? navigator.clipboard.writeText(inp.value) : Promise.reject()).catch(function () { document.execCommand('copy'); });
    c.textContent = 'Copied';
  }
});
@if($errors->any() && old('name') !== null && ! old('_edit'))
  document.addEventListener('DOMContentLoaded', function () { openModal('{{ old('url') !== null ? 'hookModal' : 'clientModal' }}'); });
@endif
</script>
@endsection
