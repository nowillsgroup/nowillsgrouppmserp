@extends('layouts.app')
@section('title', 'Campaigns')
@section('crumbs') <a href="{{ route('crm.index') }}">Marketing</a> / Campaigns @endsection
@section('actions')
<a class="btn" href="{{ route('reports.show', 'crm_campaigns') }}"><x-icon name="chart"/> Performance report</a>
@if(can_do('crm.manage'))<a class="btn btn-primary" href="{{ route('crm.campaigns.create') }}" id="newCampaign"><x-icon name="plus"/> New campaign</a>@endif
@endsection
@section('content')
<div class="stats req5">
  <x-stat label="Campaigns sent" :value="$summary['count']" icon="mail" tone="blue" sub="last 90 days"/>
  <x-stat label="Delivered" :value="number_format($summary['sent'])" icon="check" tone="purple" :sub="$summary['sent'] ? round($summary['clicks'] / $summary['sent'] * 100, 1).'% click-through' : 'no sends yet'"/>
  <x-stat label="Bookings" :value="$summary['bookings']" icon="calendar" tone="green" sub="credited to those campaigns"/>
  <x-stat label="Room revenue" :value="money($summary['revenue'])" icon="money" tone="green" sub="before tax"/>
  <x-stat label="Queued" :value="$summary['scheduled']" icon="clock" :tone="$summary['scheduled'] ? 'amber' : 'slate'" :sub="$summary['drafts'].' draft(s)'" href="?show=scheduled"/>
</div>
<div class="tabs">@foreach(\App\Http\Controllers\CampaignController::TABS as $k => $v)<a href="?{{ http_build_query(array_filter(['show' => $k, 'channel' => request('channel')])) }}" class="{{ $tab === $k ? 'active' : '' }}">{{ $v }}</a>@endforeach</div>
<form class="filters" method="get"><input type="hidden" name="show" value="{{ $tab }}">
  <input name="q" class="input" placeholder="Campaign name" value="{{ request('q') }}">
  <select name="channel" class="input"><option value="">All channels</option>@foreach(\App\Services\CrmService::CHANNELS as $k => $v)<option value="{{ $k }}" @selected(request('channel') === $k)>{{ $v }}</option>@endforeach</select>
  <button class="btn"><x-icon name="search"/> Filter</button>
</form>
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="campTable">
<thead><tr><th>Campaign</th><th>Audience</th><th>Offer</th><th>Status</th><th class="num">Delivered</th><th class="num">Skipped</th><th class="num">Clicks</th><th class="num">Bookings</th><th class="num">Revenue</th></tr></thead><tbody>
@forelse($campaigns as $c) @php $st = $c->stats; @endphp
<tr>
  <td><a class="bold" href="{{ route('crm.campaigns.show', $c) }}">{{ $c->name }}</a><div class="tiny muted">{{ \App\Services\CrmService::CHANNELS[$c->channel] }} · {{ $c->creator?->name }}</div></td>
  <td class="small">{{ $c->segment?->name ?? 'All opted-in guests' }}</td>
  <td class="small">{{ $c->promo?->code ?? '—' }}</td>
  <td>{!! status_badge($c->status) !!}<div class="tiny muted">{{ $c->status === 'scheduled' ? $c->scheduled_at?->format('d M H:i') : ($c->sent_at?->format('d M Y') ?? '') }}</div></td>
  <td class="num">{{ $st['sent'] ?? '—' }}</td><td class="num">{{ $st['skipped'] ?? '—' }}</td>
  <td class="num">{{ $st ? $st['clicks'].' · '.$st['ctr'].'%' : '—' }}</td><td class="num">{{ $st['bookings'] ?? '—' }}</td><td class="num">{{ $st ? money($st['revenue'], false) : '—' }}</td>
</tr>
@empty<tr><td colspan="9"><x-empty text="No campaigns here." icon="mail"/></td></tr>@endforelse
</tbody></table></div></div>
{{ $campaigns->links() }}
@endsection
