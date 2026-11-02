@extends('layouts.app')
@section('title', 'Channel Manager')
@section('crumbs') Reservations / Channel Manager @endsection
@section('actions')
@if(can_do('reports.view'))<a class="btn btn-icon cm-action" href="{{ route('reports.show', 'channel_production') }}" aria-label="Open channel production report" title="Channel production"><x-icon name="chart"/></a>@endif
@if(can_do('channels.manage'))<a class="btn btn-primary btn-icon cm-action" href="{{ route('cm.connections.create') }}" id="newConnection" aria-label="Add channel connection" title="New connection"><x-icon name="plus"/></a>@endif
@endsection
@section('content')
<div class="cm-page">
@include('channel-manager._nav')
@php $active = $connections->where('is_active', true); $failing = $connections->filter(fn ($c) => $c->health() === 'failing'); $att = \App\Services\ChannelService::attentionCount(); @endphp
<header class="cm-hero"><div class="cm-hero-copy"><div class="cm-kicker"><x-icon name="globe"/> Distribution workspace</div><h2>Channel activity at a glance</h2><p>Monitor connectivity, OTA production, inventory parity and incoming reservations across your connected channels.</p></div><span class="cm-hero-mark" aria-hidden="true"><x-icon name="layers"/></span></header>
<div class="cm-stat-grid" role="group" aria-label="Channel performance summary">
  <x-stat label="Connected channels" :value="$active->count().' / '.$connections->count()" icon="globe" :tone="$failing->isNotEmpty() ? 'red' : 'blue'" :sub="$failing->isNotEmpty() ? $failing->count().' failing to sync' : 'all syncing normally'"/>
  <x-stat label="OTA bookings" :value="$totals['bookings']" icon="calendar" tone="purple" :sub="number_format($totals['nights']).' room nights · last 30 days'"/>
  <x-stat label="OTA revenue" :value="money($totals['revenue'])" icon="money" tone="green" sub="gross, booked in the last 30 days"/>
  <x-stat label="Commission" :value="money($totals['commission'])" icon="wallet" tone="slate" :sub="$totals['revenue'] > 0 ? round($totals['commission'] / $totals['revenue'] * 100, 1).'% of OTA revenue' : 'no OTA revenue yet'"/>
  <x-stat label="Needs attention" :value="$att" icon="alert" :tone="$att ? 'amber' : 'slate'" :sub="$overbooked->count().' overbooked · '.$failedMsgs.' rejected (7 d)'" :href="route('cm.inbox')"/>
</div>

@if($overbooked->isNotEmpty())
<div class="alert alert-error" id="overbookAlert" role="alert"><x-icon name="alert"/><div style="flex:1;min-width:0"><b>{{ $overbooked->count() }} OTA booking(s) arrived with no inventory left.</b> Channel bookings are guaranteed, so they were accepted. Find a room, upgrade or relocate the guest, then record the resolution.
  <div class="table-wrap" role="region" aria-label="Overbooked channel reservations" tabindex="0"><table class="table cm-overbook-table" id="overbookTable"><caption class="cm-sr-only">Reservations requiring resolution because the channel booking exceeded available inventory.</caption><thead><tr><th scope="col">Reservation</th><th scope="col">Channel</th><th scope="col">Guest</th><th scope="col">Stay</th><th scope="col">Rooms</th><th scope="col">Action</th></tr></thead><tbody>
  @foreach($overbooked as $r)
    <tr><td>@if(can_do('reservations.view'))<a href="{{ route('reservations.show', $r) }}">{{ $r->reservation_no }}</a>@else{{ $r->reservation_no }}@endif<div class="tiny muted">{{ $r->ota_reference }}</div></td><td>{{ $r->channel?->name }}</td><td>{{ $r->guest?->full_name }}</td>
      <td class="small nowrap"><time datetime="{{ $r->arrival_date->toDateString() }}">{{ $r->arrival_date->format('d M') }}</time> – <time datetime="{{ $r->departure_date->toDateString() }}">{{ $r->departure_date->format('d M Y') }}</time></td><td class="small">{{ $r->rooms->where('status', '!=', 'cancelled')->groupBy(fn ($s) => $s->roomType?->name)->map->count()->map(fn ($n, $t) => "$n × $t")->implode(', ') }}</td>
      <td>@if(can_do('channels.resolve'))<button type="button" class="btn btn-sm" data-resolve="{{ route('cm.reservation.resolve', $r) }}" data-title="Resolve overbooking {{ $r->reservation_no }}">Resolve</button>@else<span class="muted">Contact a channel manager</span>@endif</td></tr>
  @endforeach
  </tbody></table></div></div></div>
@endif

<section class="card flush cm-section-anchor" id="connections" aria-labelledby="connectionsTitle"><div class="card-head"><div><h3 id="connectionsTitle">Channel connections</h3><span class="tiny muted">Changes queue automatically; full refresh runs nightly.</span></div><a class="small" href="{{ route('cm.pushes') }}">View sync log →</a></div><div class="card-body table-wrap" role="region" aria-label="Channel connections table" tabindex="0">
<table class="table cm-connection-table" id="connTable"><caption class="cm-sr-only">Connection status, synchronization, mapping, commercial terms and recent channel production.</caption><thead><tr><th scope="col">Channel</th><th scope="col">Status</th><th scope="col">Last sync</th><th scope="col" class="num">Pending</th><th scope="col">Mapping</th><th scope="col">Terms</th><th scope="col" class="num">Bookings (30 d)</th><th scope="col" class="num">Revenue</th><th scope="col" class="num">Commission</th><th scope="col">Share</th><th scope="col"><span class="cm-sr-only">Actions</span></th></tr></thead><tbody>
@forelse($connections as $conn) @php $s = $stats->get($conn->id, ['bookings' => 0, 'revenue' => 0, 'commission' => 0, 'cancelled' => 0, 'nights' => 0]); $share = $totals['revenue'] > 0 ? round($s['revenue'] / $totals['revenue'] * 100) : 0; @endphp
<tr>
  <td><a class="bold" href="{{ route('cm.connections.show', $conn) }}">{{ $conn->name }}</a><div class="tiny muted">{{ $conn->code }} · {{ $conn->isSimulator() ? 'Test channel' : 'Channel API' }}</div></td>
  <td>@include('channel-manager._health')@if($conn->fail_count > 0)<div class="tiny cm-error-text">{{ $conn->fail_count }} failed attempt(s)@if($conn->next_retry_at) · retry {{ $conn->next_retry_at->diffForHumans() }}@endif</div>@endif</td>
  <td class="small">{{ $conn->last_push_at?->diffForHumans() ?? '—' }}<div class="tiny muted" style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $conn->last_push_status }}</div></td>
  <td class="num">{!! ! $conn->is_active ? '<span class="muted">—</span>' : ($conn->full_sync_due ? '<span class="badge badge-blue">full</span>' : ($conn->pending ? '<b>'.$conn->pending.'</b>' : '<span class="muted">0</span>')) !!}</td>
  <td class="small nowrap">{{ $conn->room_maps_count }} room<div class="tiny muted">{{ $conn->rate_maps_count }} rate codes</div></td>
  <td class="small nowrap">{{ rtrim(rtrim((string) $conn->commission_pct, '0'), '.') }}% comm. @if((float) $conn->rate_markup_pct != 0) · {{ $conn->rate_markup_pct > 0 ? '+' : '' }}{{ rtrim(rtrim((string) $conn->rate_markup_pct, '0'), '.') }}% rate @endif<div class="tiny muted">{{ $conn->payment_model === 'channel_collect' ? 'Channel collect' : 'Hotel collect' }}</div></td>
  <td class="num">{{ $s['bookings'] }}@if($s['cancelled'])<div class="tiny muted">{{ $s['cancelled'] }} cxl</div>@endif</td>
  <td class="num">{{ money($s['revenue']) }}</td><td class="num">{{ money($s['commission']) }}</td>
  <td><div class="progress" role="meter" aria-label="{{ $conn->name }} share of channel revenue" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $share }}"><span style="width:{{ $share }}%"></span></div><div class="tiny muted">{{ $share }}%</div></td>
  <td class="right nowrap">@if(can_do('channels.manage') && $conn->is_active)<form method="post" action="{{ route('cm.connections.push', $conn) }}" class="inline">@csrf<button type="submit" class="btn btn-ghost btn-icon cm-action" title="Push pending changes for {{ $conn->name }}" aria-label="Push pending changes for {{ $conn->name }}"><x-icon name="refresh"/></button></form>@endif</td>
</tr>
@empty
<tr><td colspan="11"><x-empty text="No two-way channel connections yet. Add Booking.com, Expedia, Agoda… or the built-in test channel." icon="globe"/>@if(can_do('channels.manage'))<div class="center mt-1"><a class="btn btn-primary" href="{{ route('cm.connections.create') }}"><x-icon name="plus"/> Add the first connection</a></div>@endif</td></tr>
@endforelse
</tbody></table></div></section>

<div class="grid g-2-1">
  <section class="card flush" aria-labelledby="recentOtaTitle"><div class="card-head"><h3 id="recentOtaTitle">Latest OTA bookings</h3><a class="small" href="{{ route('cm.inbox', ['tab' => 'all']) }}">All messages →</a></div><div class="card-body table-wrap" role="region" aria-label="Latest channel bookings" tabindex="0">
    <table class="table cm-recent-table" id="recentOta"><caption class="cm-sr-only">The ten most recently received OTA bookings and their payment summaries.</caption><thead><tr><th scope="col">Reservation</th><th scope="col">Channel</th><th scope="col">Guest</th><th scope="col">Stay</th><th scope="col" class="num">Amount</th><th scope="col">Payment</th><th scope="col">Status</th></tr></thead><tbody>
    @forelse($recent as $r)
      <tr><td>@if(can_do('reservations.view'))<a href="{{ route('reservations.show', $r) }}">{{ $r->reservation_no }}</a>@else{{ $r->reservation_no }}@endif<div class="tiny muted">{{ $r->ota_reference }}</div></td>
        <td class="small">{{ $r->channel?->name }}</td><td>{{ $r->guest?->full_name }}</td><td class="small nowrap"><time datetime="{{ $r->arrival_date->toDateString() }}">{{ $r->arrival_date->format('d M') }}</time> – <time datetime="{{ $r->departure_date->toDateString() }}">{{ $r->departure_date->format('d M Y') }}</time></td>
        <td class="num">{{ money($r->channel_amount) }}<div class="tiny muted">comm. {{ money($r->channel_commission) }}</div>
          @if($r->channel_currency)<div class="tiny muted">sent {{ $r->channel_currency }} {{ number_format((float) $r->channel_amount_fx, 2) }} @ {{ number_format((float) $r->channel_fx_rate, 4) }}</div>@endif</td>
        <td class="small">{{ $r->channel_payment === 'channel_collect' ? 'Prepaid (OTA)' : 'Pay at hotel' }}</td>
        <td>{!! status_badge($r->status) !!}@if($r->channel_attention)<span class="badge badge-red">overbooked</span>@endif</td></tr>
    @empty<tr><td colspan="7"><x-empty text="No channel bookings yet." icon="calendar"/></td></tr>@endforelse
    </tbody></table></div></section>
  <div class="cm-side-stack">
    <section class="card" aria-labelledby="attentionTitle"><div class="card-head"><h3 id="attentionTitle">Needs attention</h3><a class="small" href="{{ route('cm.inbox') }}">Open inbox →</a></div><div class="card-body">
      @forelse($attention as $m)
        <div class="flex between" style="padding:.5rem 0;border-bottom:1px solid var(--c-border);gap:.7rem"><div class="min-w-0"><a href="{{ route('cm.message', $m) }}" class="bold small">{{ $m->connection?->name }} · {{ $m->channel_ref }}</a><div class="tiny muted">{{ \App\Models\ChannelMessage::ATTENTION[$m->attention] ?? $m->attention }} · {{ $m->created_at->diffForHumans() }}</div></div><span class="badge badge-amber">{{ $m->event }}</span></div>
      @empty<x-empty text="Nothing needs attention." icon="check"/>@endforelse
    </div></section>
    <section class="card" aria-labelledby="pushesTitle"><div class="card-head"><h3 id="pushesTitle">Recent pushes</h3><a class="small" href="{{ route('cm.pushes') }}">Sync log →</a></div><div class="card-body">
      <ul class="list" id="recentPushes">@forelse($pushes as $p)<li><span class="small">{{ $p->connection?->name }} <span class="muted">{{ $p->kind }} · {{ $p->room_days }} RN</span></span><span class="small">{!! $p->status === 'ok' ? '<span class="badge badge-green">ok</span>' : '<span class="badge badge-red" title="'.e($p->error).'">failed</span>' !!} <span class="muted">{{ $p->created_at->diffForHumans(null, true) }}</span></span></li>@empty<li class="muted small">No pushes yet.</li>@endforelse</ul>
    </div></section>
  </div>
</div>
</div>
@include('channel-manager._resolve_modal')
@endsection
