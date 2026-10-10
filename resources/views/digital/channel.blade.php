@extends('layouts.app')
@section('title', $c->name)
@section('crumbs') <a href="{{ route('digital.channels') }}">Digital Marketing / Channels & listings</a> / {{ $c->name }} @endsection
@php $CH = \App\Models\DmChannel::class; $w = can_do('digital.content') || can_do('digital.manage'); $h = $c->healthScore(); @endphp
@section('actions')
  @if($c->url)<a class="btn" href="{{ $c->url }}" target="_blank" rel="noopener"><x-icon name="globe"/> Open</a>@endif
  @if(can_do('digital.manage'))<button class="btn" data-modal="channelModal" id="editChannelBtn"><x-icon name="edit"/> Edit</button>@endif
  @if($w)<button class="btn btn-primary" data-modal="statsModal" id="statsBtn"><x-icon name="chart"/> Monthly figures</button>@endif
  @if($w && $c->kind() === 'listing')<button class="btn btn-primary" data-modal="auditModal" id="auditBtn"><x-icon name="check"/> Audit listing</button>@endif
@endsection
@section('content')
<div class="flex gap-1 wrap mb-1"><span class="badge badge-slate"><span class="dm-dot" style="background:{{ $c->colour() }}"></span> {{ $c->platformLabel() }}</span><span class="badge badge-{{ $c->is_active ? 'green' : 'slate' }}">{{ $c->is_active ? 'Active' : 'Inactive' }}</span>@if($c->handle)<span class="badge badge-slate">{{ $c->handle }}</span>@endif</div>
<div class="g-2-1">
<div>
@if($c->kind() === 'listing')
<div class="card" id="healthCard"><div class="card-head"><h3><x-icon name="star"/> Listing health {{ $h !== null ? $h.'%' : '' }}</h3><span class="tiny muted">{{ $c->audited_at ? 'audited '.$c->audited_at->format('d M Y').' by '.$c->auditor?->name : 'never audited' }}</span></div>
<div class="card-body">@foreach($CH::HEALTH_ITEMS as $k => $l)<div class="small dm-hc"><span class="{{ ! empty($c->health[$k]) ? 'text-green' : 'text-red' }}">{!! ! empty($c->health[$k]) ? '&#10003;' : '&#10007;' !!}</span> {{ $l }}</div>@endforeach
  <div class="mt-1 small">Rating <b>{{ $c->rating ?? '—' }}</b> · {{ $c->reviews !== null ? number_format($c->reviews).' reviews' : 'reviews not recorded' }} · respond to reviews in <a href="{{ route('rep.reviews.index') }}">Reputation → Online reviews</a></div></div></div>
@endif
<div class="card flush {{ $c->kind() === 'listing' ? 'mt-1' : '' }}" id="statsTable"><div class="card-head"><h3><x-icon name="chart"/> Monthly figures</h3></div><div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>Month</th><th class="num">Followers</th><th class="num">Reach</th><th class="num">Impressions</th><th class="num">Engagements</th><th class="num">Eng. rate</th><th class="num">Clicks</th></tr></thead><tbody>
@forelse($stats as $s)<tr><td>{{ $s->month->format('M Y') }}</td><td class="num">{{ number_format($s->followers) }}</td><td class="num">{{ number_format($s->reach) }}</td><td class="num">{{ number_format($s->impressions) }}</td><td class="num">{{ number_format($s->engagements) }}</td><td class="num">{{ $s->reach ? round(100 * $s->engagements / $s->reach, 2).'%' : '—' }}</td><td class="num">{{ number_format($s->clicks) }}</td></tr>
@empty<tr><td colspan="7" class="empty">No figures recorded – enter them from the platform's insights at month end.</td></tr>@endforelse</tbody></table></div></div>
@if($posts->count())<div class="card flush mt-1"><div class="card-head"><h3><x-icon name="message"/> Recent posts</h3></div><div class="card-body">@foreach($posts as $p)<a class="dm-item between-row" href="{{ route('digital.post', $p) }}"><span>{{ $p->title }} <span class="tiny muted">{{ $p->published_at?->format('d M') }}</span></span><span class="small">{{ number_format($p->engagements) }} eng.</span></a>@endforeach</div></div>@endif
@if($campaigns->count())<div class="card flush mt-1"><div class="card-head"><h3><x-icon name="trend"/> Campaigns</h3></div><div class="card-body">@foreach($campaigns as $x)<a class="dm-item" href="{{ route('digital.campaign', $x) }}"><span class="badge badge-{{ $x->statusTone() }}">{{ $x->statusLabel() }}</span> {{ $x->number }} {{ $x->name }}</a>@endforeach</div></div>@endif
</div>
<div><div class="card"><div class="card-body"><dl class="kv"><dt>Followers</dt><dd>{{ number_format($c->followers) }}</dd><dt>Owner</dt><dd>{{ $c->owner?->name ?? '—' }}</dd><dt>Address</dt><dd class="small" style="word-break:break-all">{{ $c->url ?? '—' }}</dd></dl>@if($c->notes)<p class="small sa-notes mt-1">{{ $c->notes }}</p>@endif</div></div></div>
</div>
@if(can_do('digital.manage'))@include('digital._channel_modal', ['c' => $c])@endif
@if($w)
<div class="modal" id="statsModal"><div class="modal-box" style="max-width:36rem"><form method="post" action="{{ route('digital.channel.stats', $c) }}">@csrf
  <div class="modal-head"><h3>Monthly figures – {{ $c->name }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid"><div class="field span-all"><label>Month</label><input type="month" name="month" required value="{{ bdate()->format('Y-m') }}" max="{{ bdate()->format('Y-m') }}"></div>
    <div class="field"><label>Followers at month end</label><input type="number" min="0" name="followers" value="{{ $c->followers }}"></div><div class="field"><label>Reach</label><input type="number" min="0" name="reach" value="0"></div>
    <div class="field"><label>Impressions</label><input type="number" min="0" name="impressions" value="0"></div><div class="field"><label>Engagements</label><input type="number" min="0" name="engagements" value="0"></div>
    <div class="field"><label>Website / profile clicks</label><input type="number" min="0" name="clicks" value="0"></div></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save figures</button></div></form></div></div>
@if($c->kind() === 'listing')
<div class="modal" id="auditModal"><div class="modal-box" style="max-width:40rem"><form method="post" action="{{ route('digital.channel.audit', $c) }}">@csrf
  <div class="modal-head"><h3>Audit {{ $c->name }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">@foreach($CH::HEALTH_ITEMS as $k => $l)<label class="dm-check block"><input type="checkbox" name="h[{{ $k }}]" value="1" @checked(! empty($c->health[$k]))> {{ $l }}</label>@endforeach
    <div class="form-grid mt-1"><div class="field"><label>Rating now</label><input type="number" min="0" max="10" step="0.01" name="rating" value="{{ $c->rating }}"></div><div class="field"><label>Review count</label><input type="number" min="0" name="reviews" value="{{ $c->reviews }}"></div></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save audit</button></div></form></div></div>
@endif
@endif
@endsection
