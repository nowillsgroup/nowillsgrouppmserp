@extends('layouts.app')
@section('title', 'Tracked Links')
@section('crumbs') Digital Marketing / Tracked links @endsection
@php $w = can_do('digital.content') || can_do('digital.manage'); @endphp
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'digital_links']) }}"><x-icon name="download"/> Attribution report</a>
  @if($w)<button class="btn btn-primary" data-modal="linkModalNew" id="newLinkBtn"><x-icon name="plus"/> New tracked link</button>@endif
@endsection
@section('content')
@include('digital._workspace-nav')
<div class="alert alert-info"><x-icon name="link"/> Share <code>{{ url('/go') }}/&lt;code&gt;</code> links in bios, posts, ads, e-mails and QR codes. Each click is logged (bots and repeat clicks ignored, no raw IP stored), UTM tags are added, and a booking-engine booking within {{ \App\Services\DigitalMarketingService::cfg()['attribution_days'] }} days is credited to the link.</div>
@php $activeLinkCount = $links->where('is_active', true)->count(); $attributedBookingCount = (int) $bookings->sum(); @endphp
<div class="stats" id="linksStats">
  <x-stat label="Links in view" :value="number_format($links->count())" icon="link" tone="slate"/>
  <x-stat label="Active links" :value="number_format($activeLinkCount)" icon="check" tone="green"/>
  <x-stat label="Clicks · 30 days" :value="number_format($clicks30)" icon="chart" tone="blue"/>
  <x-stat label="Credited bookings" :value="number_format($attributedBookingCount)" icon="bed" tone="purple" sub="cancelled stays excluded"/>
</div>
<div class="between-row mb-1 mt-1"><form method="get" class="flex gap-05"><input name="q" value="{{ $q }}" placeholder="Search name, code, campaign" aria-label="Search"><button class="btn btn-sm">Search</button>@if($q !== '')<a class="btn btn-sm btn-ghost" href="{{ route('digital.links') }}">Clear</a>@endif</form><span class="small muted">Clicks and bookings follow the current search results.</span></div>
<div class="card flush"><div class="card-body table-wrap"><table class="table table-compact" id="linkTable"><thead><tr><th scope="col">Link</th><th scope="col">Short URL</th><th scope="col">Source / medium / campaign</th><th scope="col" class="num">Total clicks</th><th scope="col">Last click</th><th scope="col" class="num">Bookings</th><th scope="col">Status</th></tr></thead><tbody>
@forelse($links as $l)<tr data-link="{{ $l->code }}"><td><a href="{{ route('digital.link', $l) }}"><b>{{ $l->name }}</b></a>@if($l->campaign)<div class="tiny muted">{{ $l->campaign->number }} {{ $l->campaign->name }}</div>@endif</td>
  <td><code class="small">/go/{{ $l->code }}</code></td><td class="small">{{ $l->utm_source }} / {{ $l->utm_medium }} / {{ $l->utm_campaign }}</td><td class="num">{{ number_format($l->clicks) }}</td>
  <td class="small nowrap" @if($l->last_click_at) title="{{ $l->last_click_at->format('D d M Y H:i') }}" @endif>{{ $l->last_click_at?->diffForHumans() ?? '—' }}</td><td class="num">{{ $bookings[$l->id] ?? 0 }}</td><td><span class="badge badge-{{ $l->is_active ? 'green' : 'slate' }}">{{ $l->is_active ? 'Active' : 'Paused' }}</span></td></tr>
@empty<tr><td colspan="7" class="empty">{{ $q !== '' ? 'No tracked links match this search.' : 'No tracked links yet.' }}</td></tr>@endforelse</tbody></table></div></div>
@if($w)@include('digital._link_modal', ['l' => null])@endif
@endsection
