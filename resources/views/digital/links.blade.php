@extends('layouts.app')
@section('title', 'Tracked Links')
@section('crumbs') Digital Marketing / Tracked links @endsection
@php $w = can_do('digital.content') || can_do('digital.manage'); @endphp
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'digital_links']) }}"><x-icon name="download"/> Attribution report</a>
  @if($w)<button class="btn btn-primary" data-modal="linkModalNew" id="newLinkBtn"><x-icon name="plus"/> New tracked link</button>@endif
@endsection
@section('content')
<div class="alert alert-info"><x-icon name="link"/> Share <code>{{ url('/go') }}/&lt;code&gt;</code> links in bios, posts, ads, e-mails and QR codes. Each click is logged (bots and repeat clicks ignored, no raw IP stored), UTM tags are added, and a booking-engine booking within {{ \App\Services\DigitalMarketingService::cfg()['attribution_days'] }} days is credited to the link.</div>
<div class="between-row mb-1"><form method="get" class="flex gap-05"><input name="q" value="{{ $q }}" placeholder="Search name, code, campaign" aria-label="Search"><button class="btn btn-sm">Search</button></form><span class="small muted">{{ number_format($clicks30) }} clicks in the last 30 days</span></div>
<div class="card flush"><div class="card-body table-wrap"><table class="table table-compact" id="linkTable"><thead><tr><th>Link</th><th>Short URL</th><th>source / medium / campaign</th><th class="num">Clicks</th><th>Last click</th><th class="num">Bookings</th><th></th></tr></thead><tbody>
@forelse($links as $l)<tr data-link="{{ $l->code }}" class="{{ $l->is_active ? '' : 'muted' }}"><td><a href="{{ route('digital.link', $l) }}"><b>{{ $l->name }}</b></a>@if($l->campaign)<div class="tiny muted">{{ $l->campaign->number }} {{ $l->campaign->name }}</div>@endif</td>
  <td><code class="small">/go/{{ $l->code }}</code></td><td class="small">{{ $l->utm_source }} / {{ $l->utm_medium }} / {{ $l->utm_campaign }}</td><td class="num">{{ number_format($l->clicks) }}</td>
  <td class="small nowrap">{{ $l->last_click_at?->diffForHumans() ?? '—' }}</td><td class="num">{{ $bookings[$l->id] ?? 0 }}</td><td>@unless($l->is_active)<span class="badge badge-slate">paused</span>@endunless</td></tr>
@empty<tr><td colspan="7" class="empty">No tracked links yet.</td></tr>@endforelse</tbody></table></div></div>
@if($w)@include('digital._link_modal', ['l' => null])@endif
@endsection
