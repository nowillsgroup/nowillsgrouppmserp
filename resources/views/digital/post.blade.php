@extends('layouts.app')
@section('title', $p->title)
@section('crumbs') <a href="{{ route('digital.calendar', ['month' => $p->scheduled_at->format('Y-m')]) }}">Digital Marketing / Content calendar</a> / Post @endsection
@php $P = \App\Models\DmPost::class; $me = auth()->user(); $DM = \App\Services\DigitalMarketingService::class; @endphp
@section('actions')
  @if($canEdit)<button class="btn" data-modal="postModal" id="editPostBtn"><x-icon name="edit"/> Edit</button>
    <form method="post" action="{{ route('digital.post.submit', $p) }}">@csrf<button class="btn btn-primary" id="submitPostBtn"><x-icon name="check"/> Send for approval</button></form>@endif
  @if($p->status === 'review' && $DM::canApprove($me) && $p->author_id !== $me->id)<button class="btn btn-primary" data-modal="reviewModal" id="reviewBtn"><x-icon name="check"/> Review</button>@endif
  @if($p->status === 'approved' && $DM::canContent($me))<button class="btn btn-primary" data-modal="publishModal" id="publishBtn"><x-icon name="globe"/> Mark published</button>@endif
  @if($p->status === 'published' && $DM::canContent($me))<button class="btn" data-modal="resultsModal" id="resultsBtn"><x-icon name="chart"/> Record results</button>@endif
  @if(! in_array($p->status, ['published', 'cancelled'], true) && ($canEdit || $DM::canApprove($me) || $DM::canManage($me)))<form method="post" action="{{ route('digital.post.cancel', $p) }}" data-confirm="Cancel this post?">@csrf<button class="btn btn-danger" id="cancelPostBtn">Cancel post</button></form>@endif
@endsection
@section('content')
<div class="flex gap-1 wrap mb-1"><span class="badge badge-{{ $p->isMissed() ? 'red' : $p->statusTone() }}" id="postStatus" data-status="{{ $p->status }}">{{ $p->isMissed() ? 'Approved – slot missed' : $p->statusLabel() }}</span>
  @foreach($chs as $c)<span class="badge badge-slate"><span class="dm-dot" style="background:{{ $c->colour() }}"></span> {{ $c->name }}</span>@endforeach
  @if($p->pillar)<span class="badge badge-purple">{{ $P::PILLARS[$p->pillar] ?? $p->pillar }}</span>@endif</div>
@if($p->status === 'rejected' && $p->review_note)<div class="alert alert-error" id="reviewNote"><x-icon name="alert"/> Changes requested by {{ $p->reviewer?->name }}: {{ $p->review_note }}</div>@endif
@if($p->status === 'review' && $p->author_id === $me->id)<div class="alert alert-info">Waiting for another approver – you cannot approve your own post.</div>@endif
<div class="g-2-1">
<div>
<div class="card" id="postPreview"><div class="card-body"><h3 class="mb-1">{{ $p->title }}</h3><div class="dm-caption">{{ $p->caption }}</div>
  <div class="tiny muted mt-1">{{ mb_strlen($p->caption) }} characters @if($p->media_url) · <a href="{{ $p->media_url }}" target="_blank" rel="noopener">media file</a>@endif @if($p->link) · link <code>{{ $p->link->shortUrl() }}</code>@endif</div></div></div>
@if($p->status === 'published')
<div class="stats mt-1" id="postStats">
  <x-stat label="Reach" :value="number_format($p->reach)" icon="eye" tone="purple"/>
  <x-stat label="Engagements" :value="number_format($p->engagements)" icon="star" tone="green" :sub="$p->engagementRate() !== null ? $p->engagementRate().'% of reach' : null"/>
  <x-stat label="Link clicks" :value="number_format($p->clicks)" icon="link" tone="blue"/>
</div>@endif
</div>
<div><div class="card"><div class="card-body"><dl class="kv">
  <dt>Slot</dt><dd>{{ $p->scheduled_at->format('D d M Y H:i') }}</dd><dt>Author</dt><dd>{{ $p->author?->name }}</dd>
  <dt>Campaign</dt><dd>@if($p->campaign)<a href="{{ route('digital.campaign', $p->campaign) }}">{{ $p->campaign->number }} {{ $p->campaign->name }}</a>@else — @endif</dd>
  <dt>Submitted</dt><dd>{{ $p->submitted_at?->format('d M H:i') ?? '—' }}</dd><dt>Reviewed</dt><dd>{{ $p->reviewed_at ? $p->reviewed_at->format('d M H:i').' by '.$p->reviewer?->name : '—' }}</dd>
  <dt>Published</dt><dd>{{ $p->published_at?->format('d M H:i') ?? '—' }} @if($p->post_url)<a href="{{ $p->post_url }}" target="_blank" rel="noopener">view</a>@endif</dd></dl></div></div></div>
</div>
@if($canEdit)@include('digital._post_modal', ['p' => $p])@endif
<div class="modal" id="reviewModal"><div class="modal-box" style="max-width:34rem"><form method="post" action="{{ route('digital.post.review', $p) }}">@csrf
  <div class="modal-head"><h3>Review post</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid"><div class="field span-all"><label>Decision</label><select name="decision"><option value="approve">Approve for publishing</option><option value="reject">Request changes</option></select></div>
    <div class="field span-all"><label>Note to the author <span class="tiny muted">(required when requesting changes)</span></label><textarea name="note" rows="3" maxlength="500"></textarea></div></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save decision</button></div></form></div></div>
<div class="modal" id="publishModal"><div class="modal-box" style="max-width:34rem"><form method="post" action="{{ route('digital.post.publish', $p) }}">@csrf
  <div class="modal-head"><h3>Mark as published</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid"><div class="field span-all"><label>Link to the live post (optional)</label><input type="url" name="post_url" maxlength="500" placeholder="https://www.instagram.com/p/…"></div></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Mark published</button></div></form></div></div>
<div class="modal" id="resultsModal"><div class="modal-box" style="max-width:34rem"><form method="post" action="{{ route('digital.post.stats', $p) }}">@csrf
  <div class="modal-head"><h3>Post results</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid"><div class="field"><label>Reach</label><input type="number" min="0" name="reach" value="{{ $p->reach }}"></div><div class="field"><label>Engagements</label><input type="number" min="0" name="engagements" value="{{ $p->engagements }}"></div>
    <div class="field"><label>Link clicks</label><input type="number" min="0" name="clicks" value="{{ $p->clicks }}"></div><div class="field span-all"><label>Post link</label><input type="url" name="post_url" value="{{ $p->post_url }}"></div></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save results</button></div></form></div></div>
@endsection
