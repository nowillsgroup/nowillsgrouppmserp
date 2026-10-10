@extends('layouts.app')
@section('title', 'Channels & Listings')
@section('crumbs') Digital Marketing / Channels & listings @endsection
@php $CH = \App\Models\DmChannel::class; $DM = \App\Services\DigitalMarketingService::class; @endphp
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'digital_social']) }}"><x-icon name="download"/> Social report</a>
  @if(can_do('digital.manage'))<button class="btn btn-primary" data-modal="channelModalNew" id="newChannelBtn"><x-icon name="plus"/> Add channel</button>@endif
@endsection
@section('content')
@foreach($CH::KINDS as $kind => $label)@php $list = $groups[$kind] ?? collect(); @endphp
<div class="card flush {{ $loop->first ? '' : 'mt-1' }}" id="kind_{{ $kind }}"><div class="card-head"><h3><x-icon :name="$kind === 'social' ? 'users' : ($kind === 'listing' ? 'star' : 'wallet')"/> {{ $label }}</h3></div>
<div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>Channel</th><th>Platform</th>
  @if($kind === 'social')<th class="num">Followers</th><th class="num">vs last month</th>@elseif($kind === 'listing')<th class="num">Rating</th><th class="num">Reviews</th><th>Health</th><th>Last audit</th>@endif<th>Owner</th></tr></thead><tbody>
@forelse($list as $c)<tr data-channel="{{ $c->id }}" class="{{ $c->is_active ? '' : 'muted' }}"><td><span class="dm-dot" style="background:{{ $c->colour() }}"></span> <a href="{{ route('digital.channel', $c) }}"><b>{{ $c->name }}</b></a>@if($c->handle)<div class="tiny muted">{{ $c->handle }}</div>@endif</td><td class="small">{{ $c->platformLabel() }}</td>
  @if($kind === 'social')<td class="num">{{ number_format($c->followers) }}</td><td class="num">@if(isset($prev[$c->id]))@php $g = $c->followers - $prev[$c->id]; @endphp<span class="{{ $g >= 0 ? 'text-green' : 'text-red' }}">{{ $g >= 0 ? '+' : '' }}{{ number_format($g) }}</span>@else — @endif</td>
  @elseif($kind === 'listing')<td class="num">{{ $c->rating ?? '—' }}</td><td class="num">{{ $c->reviews !== null ? number_format($c->reviews) : '—' }}</td>
    <td>@if(($h = $c->healthScore()) !== null)<span class="badge badge-{{ $h >= 80 ? 'green' : ($h >= 50 ? 'amber' : 'red') }}">{{ $h }}%</span>@else<span class="muted">—</span>@endif</td>
    <td class="small">{{ $c->audited_at?->format('d M Y') ?? 'never' }} @if($DM::auditDue($c))<span class="badge badge-amber">due</span>@endif</td>@endif
  <td class="small">{{ $c->owner?->name ?? '—' }}</td></tr>
@empty<tr><td colspan="7" class="empty">None yet.</td></tr>@endforelse</tbody></table></div></div>
@endforeach
@if(can_do('digital.manage'))@include('digital._channel_modal', ['c' => null])@endif
@endsection
