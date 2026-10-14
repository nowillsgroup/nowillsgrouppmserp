@extends('layouts.app')
@section('title', 'Channel Manager')
@section('crumbs') Reservations / Channel Manager @endsection
@section('actions')
@if(can_do('reports.view'))<a class="btn" href="{{ route('reports.show', 'channel_production') }}"><x-icon name="chart"/> Channel production</a>@endif
@if(can_do('channels.manage'))<a class="btn btn-primary" href="{{ route('cm.connections.create') }}" id="newConnection"><x-icon name="plus"/> New connection</a>@endif
@endsection
@section('content')
@include('channel-manager._nav')
@php $active = $connections->where('is_active', true); $failing = $connections->filter(fn ($c) => $c->health() === 'failing'); $att = \App\Services\ChannelService::attentionCount(); @endphp
<div class="stats req5">
  <x-stat label="Connected channels" :value="$active->count().' / '.$connections->count()" icon="globe" :tone="$failing->isNotEmpty() ? 'red' : 'blue'" :sub="$failing->isNotEmpty() ? $failing->count().' failing to sync' : 'all syncing normally'"/>
  <x-stat label="OTA bookings" :value="$totals['bookings']" icon="calendar" tone="purple" :sub="number_format($totals['nights']).' room nights · last 30 days'"/>
  <x-stat label="OTA revenue" :value="money($totals['revenue'])" icon="money" tone="green" sub="gross, booked in the last 30 days"/>
  <x-stat label="Commission" :value="money($totals['commission'])" icon="wallet" tone="slate" :sub="$totals['revenue'] > 0 ? round($totals['commission'] / $totals['revenue'] * 100, 1).'% of OTA revenue' : 'no OTA revenue yet'"/>
  <x-stat label="Needs attention" :value="$att" icon="alert" :tone="$att ? 'amber' : 'slate'" :sub="$overbooked->count().' overbooked · '.$failedMsgs.' rejected (7 d)'" :href="route('cm.inbox')"/>
</div>

@if($overbooked->isNotEmpty())
<div class="alert alert-error" id="overbookAlert"><x-icon name="alert"/><div style="flex:1"><b>{{ $overbooked->count() }} OTA booking(s) arrived with no inventory left.</b> Channel bookings are guaranteed, so they were accepted – find a room (upgrade / move) or relocate the guest, then mark resolved.
  <table class="table mt-1" id="overbookTable" style="background:#fff"><thead><tr><th>Reservation</th><th>Channel</th><th>Guest</th><th>Stay</th><th>Rooms</th><th></th></tr></thead><tbody>
  @foreach($overbooked as $r)
    <tr><td>@if(can_do('reservations.view'))<a href="{{ route('reservations.show', $r) }}">{{ $r->reservation_no }}</a>@else{{ $r->reservation_no }}@endif<div class="tiny muted">{{ $r->ota_reference }}</div></td><td>{{ $r->channel?->name }}</td><td>{{ $r->guest?->full_name }}</td>
      <td class="small nowrap">{{ $r->arrival_date->format('d M') }} – {{ $r->departure_date->format('d M Y') }}</td><td class="small">{{ $r->rooms->where('status', '!=', 'cancelled')->groupBy(fn ($s) => $s->roomType?->name)->map->count()->map(fn ($n, $t) => "$n × $t")->implode(', ') }}</td>
      <td class="right">@if(can_do('channels.resolve'))<button type="button" class="btn btn-sm" data-resolve="{{ route('cm.reservation.resolve', $r) }}" data-title="Resolve overbooking {{ $r->reservation_no }}">Resolve</button>@endif</td></tr>
  @endforeach
  </tbody></table></div></div>
@endif

<div class="card flush"><div class="card-head"><h3>Connections</h3><span class="tiny muted">ARI is pushed every minute when something changes; full refresh nightly</span></div><div class="card-body table-wrap">
<table class="table" id="connTable"><thead><tr><th>Channel</th><th>Status</th><th>Last sync</th><th class="num">Pending</th><th>Mapping</th><th>Terms</th><th class="num">Bookings (30 d)</th><th class="num">Revenue</th><th class="num">Commission</th><th>Share</th><th style="width:40px"></th></tr></thead><tbody>
@forelse($connections as $conn) @php $s = $stats->get($conn->id, ['bookings' => 0, 'revenue' => 0, 'commission' => 0, 'cancelled' => 0, 'nights' => 0]); $share = $totals['revenue'] > 0 ? round($s['revenue'] / $totals['revenue'] * 100) : 0; @endphp
<tr>
  <td><a class="bold" href="{{ route('cm.connections.show', $conn) }}">{{ $conn->name }}</a><div class="tiny muted">{{ $conn->code }} · {{ $conn->isSimulator() ? 'Test channel' : 'Channel API' }}</div></td>
  <td style="max-width:150px">@include('channel-manager._health')@if($conn->fail_count > 0)<div class="tiny" style="color:#b91c1c">{{ $conn->fail_count }} failed attempt(s)@if($conn->next_retry_at) · retry {{ $conn->next_retry_at->diffForHumans() }}@endif</div>@endif</td>
  <td class="small">{{ $conn->last_push_at?->diffForHumans() ?? '—' }}<div class="tiny muted" style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $conn->last_push_status }}</div></td>
  <td class="num">{!! ! $conn->is_active ? '<span class="muted">—</span>' : ($conn->full_sync_due ? '<span class="badge badge-blue">full</span>' : ($conn->pending ? '<b>'.$conn->pending.'</b>' : '<span class="muted">0</span>')) !!}</td>
  <td class="small nowrap">{{ $conn->room_maps_count }} room<div class="tiny muted">{{ $conn->rate_maps_count }} rate codes</div></td>
  <td class="small nowrap">{{ rtrim(rtrim((string) $conn->commission_pct, '0'), '.') }}% comm. @if((float) $conn->rate_markup_pct != 0) · {{ $conn->rate_markup_pct > 0 ? '+' : '' }}{{ rtrim(rtrim((string) $conn->rate_markup_pct, '0'), '.') }}% rate @endif<div class="tiny muted">{{ $conn->payment_model === 'channel_collect' ? 'Channel collect' : 'Hotel collect' }}</div></td>
  <td class="num">{{ $s['bookings'] }}@if($s['cancelled'])<div class="tiny muted">{{ $s['cancelled'] }} cxl</div>@endif</td>
  <td class="num">{{ money($s['revenue']) }}</td><td class="num">{{ money($s['commission']) }}</td>
  <td style="min-width:90px"><div class="progress"><span style="width:{{ $share }}%"></span></div><div class="tiny muted">{{ $share }}%</div></td>
  <td class="right nowrap">@if(can_do('channels.manage') && $conn->is_active)<form method="post" action="{{ route('cm.connections.push', $conn) }}" class="inline">@csrf<button class="btn btn-ghost btn-icon" title="Push pending changes now"><x-icon name="refresh"/></button></form>@endif</td>
</tr>
@empty
<tr><td colspan="11"><x-empty text="No two-way channel connections yet. Add Booking.com, Expedia, Agoda… or the built-in test channel." icon="globe"/></td></tr>
@endforelse
</tbody></table></div></div>

<div class="grid g-2-1">
  <div>
    <div class="card flush"><div class="card-head"><h3>Latest OTA bookings</h3><a class="small" href="{{ route('cm.inbox', ['tab' => 'all']) }}">All messages →</a></div><div class="card-body table-wrap">
    <table class="table" id="recentOta"><thead><tr><th>Reservation</th><th>Channel</th><th>Guest</th><th>Stay</th><th class="num">Amount</th><th>Payment</th><th>Status</th></tr></thead><tbody>
    @forelse($recent as $r)
      <tr><td>@if(can_do('reservations.view'))<a href="{{ route('reservations.show', $r) }}">{{ $r->reservation_no }}</a>@else{{ $r->reservation_no }}@endif<div class="tiny muted">{{ $r->ota_reference }}</div></td>
        <td class="small">{{ $r->channel?->name }}</td><td>{{ $r->guest?->full_name }}</td><td class="small nowrap">{{ $r->arrival_date->format('d M') }} – {{ $r->departure_date->format('d M') }}</td>
        <td class="num">{{ money($r->channel_amount) }}<div class="tiny muted">comm. {{ money($r->channel_commission) }}</div>
          @if($r->channel_currency)<div class="tiny muted">sent {{ $r->channel_currency }} {{ number_format((float) $r->channel_amount_fx, 2) }} @ {{ number_format((float) $r->channel_fx_rate, 4) }}</div>@endif</td>
        <td class="small">{{ $r->channel_payment === 'channel_collect' ? 'Prepaid (OTA)' : 'Pay at hotel' }}</td>
        <td>{!! status_badge($r->status) !!}@if($r->channel_attention)<span class="badge badge-red">overbooked</span>@endif</td></tr>
    @empty<tr><td colspan="7"><x-empty text="No channel bookings yet." icon="calendar"/></td></tr>@endforelse
    </tbody></table></div></div>
  </div>
  <div>
    <div class="card"><div class="card-head"><h3>Needs attention</h3><a class="small" href="{{ route('cm.inbox') }}">Inbox →</a></div><div class="card-body">
      @forelse($attention as $m)
        <div class="flex between" style="padding:.45rem 0;border-bottom:1px solid #f1f5f9"><div><a href="{{ route('cm.message', $m) }}" class="bold small">{{ $m->connection?->name }} · {{ $m->channel_ref }}</a>
          <div class="tiny muted">{{ \App\Models\ChannelMessage::ATTENTION[$m->attention] ?? $m->attention }} · {{ $m->created_at->diffForHumans() }}</div></div><span class="badge badge-amber">{{ $m->event }}</span></div>
      @empty<x-empty text="Nothing needs attention." icon="check"/>@endforelse
    </div></div>
    <div class="card"><div class="card-head"><h3>Recent pushes</h3><a class="small" href="{{ route('cm.pushes') }}">Sync log →</a></div><div class="card-body">
      <ul class="list" id="recentPushes">@forelse($pushes as $p)<li><span class="small">{{ $p->connection?->name }} <span class="muted">{{ $p->kind }} · {{ $p->room_days }} RN</span></span><span class="small">{!! $p->status === 'ok' ? '<span class="badge badge-green">ok</span>' : '<span class="badge badge-red" title="'.e($p->error).'">failed</span>' !!} <span class="muted">{{ $p->created_at->diffForHumans(null, true) }}</span></span></li>@empty<li class="muted small">No pushes yet.</li>@endforelse</ul>
    </div></div>
  </div>
</div>
@include('channel-manager._resolve_modal')
@endsection
