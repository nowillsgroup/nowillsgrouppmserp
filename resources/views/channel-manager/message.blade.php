@extends('layouts.app')
@section('title', 'Channel message '.$m->channel_ref)
@section('crumbs') <a href="{{ route('cm.index') }}">Channel Manager</a> / <a href="{{ route('cm.inbox') }}">OTA inbox</a> / {{ $m->channel_ref }} @endsection
@section('actions')
@if(can_do('channels.manage') && in_array($m->status, ['attention', 'failed']) && $m->attention !== 'overbooked')
  <form method="post" action="{{ route('cm.reprocess', $m) }}" class="inline">@csrf<button type="submit" class="btn btn-primary btn-icon cm-action" id="reprocessBtn" aria-label="Reprocess message {{ $m->channel_ref }}" title="Reprocess message"><x-icon name="refresh"/></button></form>
@endif
@if(can_do('channels.resolve') && in_array($m->status, ['attention', 'failed']))<button type="button" class="btn btn-icon cm-action" id="resolveBtn" data-resolve="{{ route('cm.resolve', $m) }}" data-title="Resolve {{ $m->channel_ref }}" aria-label="Mark message {{ $m->channel_ref }} resolved" title="Mark resolved"><x-icon name="check"/></button>@endif
@endsection
@section('content')
<div class="cm-page">
@include('channel-manager._nav')
@php $p = $m->payload; @endphp
<header class="cm-hero"><div class="cm-hero-copy"><div class="cm-kicker"><x-icon name="mail"/> {{ $m->connection->name }} · {{ ucfirst($m->event) }}</div><h2>Message {{ $m->channel_ref }}</h2><p>Received {{ $m->created_at->format('d M Y · H:i:s') }} · {{ ucfirst($m->status) }}</p></div><span class="cm-hero-mark" aria-hidden="true"><x-icon name="{{ $m->status === 'failed' ? 'alert' : 'mail' }}"/></span></header>
@if($m->status === 'attention')<div class="alert alert-warn" id="attNote"><x-icon name="alert"/><div><b>{{ \App\Models\ChannelMessage::ATTENTION[$m->attention] ?? 'Needs attention' }}.</b> {{ $m->error }}
  @if($m->attention === 'unmapped_room' && can_do('channels.manage'))<div class="mt-1"><a href="{{ route('cm.connections.show', $m->connection) }}#mapping">Open the mapping for {{ $m->connection->name }}</a>, then press <b>Reprocess</b>.</div>@endif</div></div>
@elseif($m->status === 'failed')<div class="alert alert-error"><x-icon name="x"/><div><b>Rejected.</b> {{ $m->error }} <span class="muted">The channel was answered with HTTP 422.</span></div></div>
@elseif($m->resolved_at)<div class="alert alert-success"><x-icon name="check"/><div>Resolved by {{ $m->resolver?->name ?? 'system' }} {{ $m->resolved_at->format('d M Y H:i') }}: {{ $m->resolution }}</div></div>@endif
@if(! empty($m->warnings))<div class="alert alert-warn"><x-icon name="alert"/><div>@foreach($m->warnings as $w)<div>{{ $w }}</div>@endforeach</div></div>@endif
<div class="cm-detail-grid">
  <div class="cm-main-stack">
    <div class="card"><div class="card-head"><div><h3>{{ ucfirst($m->event) }} · {{ $m->channel_ref }}</h3><span class="tiny muted">Inbound reservation message and current processing outcome.</span></div>{!! $m->statusBadge() !!}</div><div class="card-body">
      <dl class="kv">
        <dt>Channel</dt><dd>{{ $m->connection->name }}</dd>
        <dt>Received</dt><dd>{{ $m->created_at->format('d M Y H:i:s') }}</dd>
        @if(! empty($p['arrival']))<dt>Stay</dt><dd>{{ \Illuminate\Support\Carbon::parse($p['arrival'])->format('D d M Y') }} → {{ \Illuminate\Support\Carbon::parse($p['departure'])->format('D d M Y') }}</dd>@endif
        @if(! empty($p['guest']))<dt>Guest</dt><dd>{{ $m->guestName() }}{{ ! empty($p['guest']['email']) ? ' · '.$p['guest']['email'] : '' }}{{ ! empty($p['guest']['phone']) ? ' · '.$p['guest']['phone'] : '' }}</dd>@endif
        @if($m->amount !== null)<dt>Total</dt><dd>{{ money($m->amount) }} {{ $p['currency'] ?? '' }}@if(isset($p['commission'])) · commission {{ money($p['commission']) }}@endif</dd>@endif
        @if(! empty($p['payment']['model']))<dt>Payment</dt><dd>{{ \App\Models\ChannelConnection::PAYMENT_MODELS[$p['payment']['model']] ?? $p['payment']['model'] }}</dd>@endif
        <dt>Reservation</dt><dd>@if($m->reservation)@if(can_do('reservations.view'))<a href="{{ route('reservations.show', $m->reservation) }}">{{ $m->reservation->reservation_no }}</a>@else{{ $m->reservation->reservation_no }}@endif · {!! status_badge($m->reservation->status) !!}@else<span class="muted">not created</span>@endif</dd>
      </dl>
      @if(! empty($p['rooms']))
      <table class="table mt-1" id="msgRooms"><caption class="cm-sr-only">Room and rate codes, guest counts and amounts received from this channel.</caption><thead><tr><th scope="col">Room code</th><th scope="col">Rate code</th><th scope="col" class="num">Adults</th><th scope="col" class="num">Children</th><th scope="col" class="num">Amount</th></tr></thead><tbody>
        @foreach($p['rooms'] as $l)<tr><td><code>{{ $l['room_code'] ?? '?' }}</code></td><td><code>{{ $l['rate_code'] ?? '—' }}</code></td><td class="num">{{ $l['adults'] ?? '' }}</td><td class="num">{{ $l['children'] ?? 0 }}</td><td class="num">{{ isset($l['amount']) && is_numeric($l['amount']) ? money($l['amount']) : '—' }}</td></tr>@endforeach
      </tbody></table>@endif
      @if(! empty($p['remarks']))<div class="cm-provider-card small mt-1" style="white-space:pre-line">{{ $p['remarks'] }}</div>@endif
    </div></div>
    <div class="card"><div class="card-head"><h3>Raw message</h3><span class="tiny muted">as stored – payment-card fields are removed on receipt</span></div><div class="card-body">
      <pre id="rawPayload" class="cm-raw">{{ json_encode($p, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
    </div></div>
  </div>
  <aside class="cm-side-stack">
    <div class="card"><div class="card-head"><h3>Message history</h3><span class="tiny muted">{{ $m->channel_ref }}</span></div><div class="card-body">
      <ul class="list" id="msgHistory">@foreach($history as $h)<li><span class="small">@if($h->id === $m->id)<b>{{ $h->event }}</b>@else<a href="{{ route('cm.message', $h) }}">{{ $h->event }}</a>@endif <span class="muted">{{ $h->created_at->format('d M H:i') }}</span></span>{!! $h->statusBadge() !!}</li>@endforeach</ul>
    </div></div>
  </aside>
</div>
</div>
@include('channel-manager._resolve_modal')
@endsection
