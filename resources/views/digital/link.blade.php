@extends('layouts.app')
@section('title', $l->name)
@section('crumbs') <a href="{{ route('digital.links') }}">Digital Marketing / Tracked links</a> / {{ $l->code }} @endsection
@php $w = can_do('digital.content') || can_do('digital.manage'); @endphp
@section('actions')
  <a class="btn" href="{{ route('digital.link.qr', $l) }}" id="qrBtn"><x-icon name="qr"/> QR code (SVG)</a>
  @if($w)<button class="btn" data-modal="linkModal" id="editLinkBtn"><x-icon name="edit"/> Edit</button>
  <form method="post" action="{{ route('digital.link.toggle', $l) }}">@csrf<button class="btn" id="toggleLinkBtn">{{ $l->is_active ? 'Pause tracking' : 'Re-activate' }}</button></form>@endif
@endsection
@section('content')
<div class="flex gap-1 wrap mb-1"><span class="badge badge-{{ $l->is_active ? 'green' : 'slate' }}" id="linkStatus" data-status="{{ $l->is_active ? 'active' : 'paused' }}">{{ $l->is_active ? 'Tracking' : 'Paused' }}</span>@if($l->campaign)<a class="badge badge-blue" href="{{ route('digital.campaign', $l->campaign) }}">{{ $l->campaign->number }} {{ $l->campaign->name }}</a>@endif</div>
<div class="stats" id="linkStats">
  <x-stat label="Clicks" :value="number_format($s['clicks'])" icon="link" tone="blue" :sub="number_format($s['unique']).' unique visitors'"/>
  <x-stat label="Bookings" :value="$s['bookings']" icon="bed" tone="purple" :sub="$s['conversion'] !== null ? $s['conversion'].'% of clicks' : null"/>
  <x-stat label="Room nights" :value="number_format($s['nights'])" icon="moon" tone="amber"/>
  <x-stat label="Booked revenue" :value="money_short($s['revenue'])" icon="money" tone="green"/>
</div>
<div class="g-2-1 mt-1">
<div>
<div class="card" id="linkUrls"><div class="card-body"><dl class="kv"><dt>Short link</dt><dd><code id="shortUrl">{{ $l->shortUrl() }}</code></dd><dt>Opens</dt><dd class="small" style="word-break:break-all"><code>{{ \App\Services\DigitalMarketingService::buildUrl($l) }}</code></dd>
  <dt>Tags</dt><dd class="small">utm_source={{ $l->utm_source }} · utm_medium={{ $l->utm_medium }} · utm_campaign={{ $l->utm_campaign }}{{ $l->utm_content ? ' · utm_content='.$l->utm_content : '' }}</dd><dt>Created</dt><dd>{{ $l->created_at->format('d M Y') }} by {{ $l->creator?->name ?? '—' }}</dd></dl></div></div>
<div class="card flush mt-1" id="linkBookings"><div class="card-head"><h3><x-icon name="bed"/> Credited bookings</h3></div><div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>Reservation</th><th>Guest</th><th>Stay</th><th>Status</th><th>Booked</th></tr></thead><tbody>
@forelse($res as $r)<tr><td><a href="{{ route('reservations.show', $r) }}">{{ $r->reservation_no }}</a></td><td class="small">{{ $r->guest?->full_name }}</td><td class="small nowrap">{{ $r->arrival_date->format('d M') }} – {{ $r->departure_date->format('d M') }}</td><td><span class="badge badge-slate">{{ $r->status }}</span></td><td class="small">{{ $r->created_at->format('d M') }}</td></tr>
@empty<tr><td colspan="5" class="empty">No bookings credited yet.</td></tr>@endforelse</tbody></table></div></div>
<div class="card flush mt-1" id="clickLog"><div class="card-head"><h3><x-icon name="clock"/> Recent clicks</h3></div><div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>When</th><th>Device</th><th>From</th></tr></thead><tbody>
@forelse($recent as $k)<tr><td class="small">{{ $k->clicked_at->format('d M H:i') }}</td><td class="small">{{ $k->device }}</td><td class="small">{{ $k->referrer_host ?? 'direct / app' }}</td></tr>@empty<tr><td colspan="3" class="empty">No clicks yet.</td></tr>@endforelse</tbody></table></div></div>
</div>
<div>
<div class="card" id="qrCard"><div class="card-body" style="text-align:center"><div class="dm-qr">{!! $qr !!}</div><div class="tiny muted">Scan to open – for flyers, table tents, room directories.</div></div></div>
<div class="card mt-1"><div class="card-head"><h3>Devices</h3></div><div class="card-body">@forelse($s['devices'] as $d => $n)<div class="between-row small"><span>{{ ucfirst($d) }}</span><b>{{ $n }}</b></div>@empty<p class="muted small">—</p>@endforelse
  <h4 class="mt-1">Top referrers</h4>@forelse($s['referrers'] as $h => $n)<div class="between-row small"><span>{{ $h }}</span><b>{{ $n }}</b></div>@empty<p class="muted small">—</p>@endforelse</div></div>
</div>
</div>
@if($w)@include('digital._link_modal', ['l' => $l])@endif
@endsection
