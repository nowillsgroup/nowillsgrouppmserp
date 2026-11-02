@extends('layouts.app')
@section('title', 'Channels & Listings')
@section('crumbs') Digital Marketing / Channels & listings @endsection
@php
  $CH = \App\Models\DmChannel::class;
  $DM = \App\Services\DigitalMarketingService::class;
  $allChannels = $groups->flatten(1);
  $activeSocials = $groups->get('social', collect())->where('is_active', true);
  $activeListings = $groups->get('listing', collect())->where('is_active', true);
  $auditedListings = $activeListings->filter(fn ($c) => $c->healthScore() !== null);
  $averageHealth = $auditedListings->count() ? (int) round($auditedListings->avg(fn ($c) => $c->healthScore())) : null;
  $auditDueCount = $activeListings->filter(fn ($c) => $DM::auditDue($c))->count();
  $socialsWithPrevious = $activeSocials->filter(fn ($c) => isset($prev[$c->id]));
  $followerDelta = (int) $socialsWithPrevious->sum(fn ($c) => $c->followers - $prev[$c->id]);
  $followerSub = $socialsWithPrevious->count()
    ? (($followerDelta >= 0 ? '+' : '').number_format($followerDelta).' vs last month · '.$socialsWithPrevious->count().' comparable profiles')
    : 'No prior-month figures recorded';
@endphp
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'digital_social']) }}"><x-icon name="download"/> Social report</a>
  @if(can_do('digital.manage'))<button class="btn btn-primary" data-modal="channelModalNew" id="newChannelBtn"><x-icon name="plus"/> Add channel</button>@endif
@endsection
@section('content')
@include('digital._workspace-nav')
<div class="stats" id="channelsStats">
  <x-stat label="Active channels" :value="number_format($allChannels->where('is_active', true)->count())" icon="globe" tone="blue" :sub="$allChannels->where('is_active', true)->count().' active of '.$allChannels->count().' configured'"/>
  <x-stat label="Social followers" :value="number_format($activeSocials->sum('followers'))" icon="users" tone="purple" :sub="$followerSub"/>
  <x-stat label="Average listing health" :value="$averageHealth === null ? '—' : $averageHealth.'%'" icon="star" :tone="$averageHealth === null ? 'slate' : ($averageHealth >= 80 ? 'green' : 'amber')" :sub="$auditedListings->count().' of '.$activeListings->count().' active listings audited'"/>
  <x-stat label="Listing audits due" :value="number_format($auditDueCount)" icon="alert" :tone="$auditDueCount ? 'amber' : 'green'" :sub="$activeListings->count().' active listings'"/>
</div>
<nav class="tabs mb-1" aria-label="Jump to channel group">
  @foreach($CH::KINDS as $kind => $label)<a href="#kind_{{ $kind }}">{{ $label }} <span class="tiny">{{ ($groups->get($kind) ?? collect())->count() }}</span></a>@endforeach
</nav>
@foreach($CH::KINDS as $kind => $label)@php $list = $groups[$kind] ?? collect(); @endphp
<div class="card flush {{ $loop->first ? '' : 'mt-1' }}" id="kind_{{ $kind }}"><div class="card-head"><h3><x-icon :name="$kind === 'social' ? 'users' : ($kind === 'listing' ? 'star' : 'wallet')"/> {{ $label }}</h3><span class="tiny muted">{{ $list->where('is_active', true)->count() }} active · {{ $list->count() }} total</span></div>
<div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th scope="col">Channel</th><th scope="col">Platform</th>
  @if($kind === 'social')<th scope="col" class="num">Followers</th><th scope="col" class="num">vs last month</th>@elseif($kind === 'listing')<th scope="col" class="num">Rating</th><th scope="col" class="num">Reviews</th><th scope="col">Health</th><th scope="col">Last audit</th>@endif<th scope="col">Status</th><th scope="col">Owner</th></tr></thead><tbody>
@forelse($list as $c)<tr data-channel="{{ $c->id }}"><td><span class="dm-dot" style="background:{{ $c->colour() }}"></span> <a href="{{ route('digital.channel', $c) }}"><b>{{ $c->name }}</b></a>@if($c->handle)<div class="tiny muted">{{ $c->handle }}</div>@endif</td><td class="small">{{ $c->platformLabel() }}</td>
  @if($kind === 'social')<td class="num">{{ number_format($c->followers) }}</td><td class="num">@if(isset($prev[$c->id]))@php $g = $c->followers - $prev[$c->id]; @endphp<span class="{{ $g >= 0 ? 'text-green' : 'text-red' }}">{{ $g >= 0 ? '+' : '' }}{{ number_format($g) }}</span>@else — @endif</td>
  @elseif($kind === 'listing')<td class="num">{{ $c->rating ?? '—' }}</td><td class="num">{{ $c->reviews !== null ? number_format($c->reviews) : '—' }}</td>
    <td>@if(($h = $c->healthScore()) !== null)<span class="badge badge-{{ $h >= 80 ? 'green' : ($h >= 50 ? 'amber' : 'red') }}">{{ $h }}%</span>@else<span class="muted">—</span>@endif</td>
    <td class="small">{{ $c->audited_at?->format('d M Y') ?? 'never' }} @if($DM::auditDue($c))<span class="badge badge-amber">due</span>@endif</td>@endif
  <td><span class="badge badge-{{ $c->is_active ? 'green' : 'slate' }}">{{ $c->is_active ? 'Active' : 'Inactive' }}</span></td><td class="small">{{ $c->owner?->name ?? '—' }}</td></tr>
@empty<tr><td colspan="{{ $kind === 'social' ? 6 : ($kind === 'listing' ? 8 : 4) }}" class="empty">No {{ strtolower($label) }} configured yet.</td></tr>@endforelse</tbody></table></div></div>
@endforeach
@if(can_do('digital.manage'))@include('digital._channel_modal', ['c' => null])@endif
@endsection
