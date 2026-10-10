@extends('layouts.app')
@section('title', 'Digital Marketing')
@section('crumbs') Digital Marketing / Dashboard @endsection
@section('actions')
  <a class="btn" href="?month={{ $month->copy()->subMonthNoOverflow()->format('Y-m') }}" id="prevMonth">← {{ $month->copy()->subMonthNoOverflow()->format('M Y') }}</a>
  @if($month->lt(bdate()->copy()->startOfMonth()))<a class="btn" href="?month={{ $month->copy()->addMonthNoOverflow()->format('Y-m') }}">{{ $month->copy()->addMonthNoOverflow()->format('M Y') }} →</a>@endif
  @if(can_do('digital.content') || can_do('digital.manage'))<a class="btn btn-primary" href="{{ route('digital.calendar') }}#new" id="newPostBtn"><x-icon name="plus"/> New post</a>@endif
@endsection
@section('content')
<h3 class="mb-1">{{ $month->format('F Y') }}</h3>
@php $alertN = $alerts['review'] + $alerts['missed'] + $alerts['audits'] + $alerts['over_budget'] + $alerts['influencers']; @endphp
@if($alertN)<div class="alert alert-warn" id="dmAlerts"><x-icon name="alert"/> <span>
  @if($alerts['review'])<a href="{{ route('digital.calendar', ['status' => 'review']) }}"><b>{{ $alerts['review'] }}</b> post(s) awaiting approval</a> · @endif
  @if($alerts['missed'])<a href="{{ route('digital.calendar', ['status' => 'approved']) }}" class="text-red"><b>{{ $alerts['missed'] }}</b> approved post(s) past their slot</a> · @endif
  @if($alerts['audits'])<a href="{{ route('digital.channels') }}"><b>{{ $alerts['audits'] }}</b> listing audit(s) due</a> · @endif
  @if($alerts['over_budget'])<a href="{{ route('digital.campaigns', ['status' => 'active']) }}" class="text-red"><b>{{ $alerts['over_budget'] }}</b> campaign(s) over budget</a> · @endif
  @if($alerts['influencers'])<a href="{{ route('digital.influencers', ['status' => 'hosted']) }}"><b>{{ $alerts['influencers'] }}</b> creator deliverable(s) overdue</a>@endif
</span></div>@endif

<div class="stats" id="dmStats">
  <x-stat label="Social followers" :value="number_format($k['followers'])" icon="users" tone="blue" :sub="$k['growth'] !== null ? ($k['growth'] >= 0 ? '+' : '').$k['growth'].'% vs last month' : ($k['stats_missing'] ? $k['stats_missing'].' channel(s) without figures' : 'no previous month')"/>
  <x-stat label="Reach" :value="number_format($k['reach'])" icon="eye" tone="purple" :sub="$k['engagement_rate'] !== null ? $k['engagement_rate'].'% engagement · '.$k['published'].' posts' : $k['published'].' posts published'"/>
  <x-stat label="Digital spend" :value="money_short($k['spend'] + $k['fees'])" icon="wallet" :tone="$k['budget'] > 0 && ($k['spend'] + $k['fees']) > $k['budget'] ? 'red' : 'amber'" :sub="$k['budget'] > 0 ? 'budget '.money_short($k['budget']) : 'no digital budget set'"/>
  <x-stat label="Direct bookings from digital" :value="money_short($k['revenue'])" icon="bed" tone="green" :sub="$k['bookings'].' bookings · '.$k['nights'].' nights'.($k['roas'] !== null ? ' · ROAS '.$k['roas'].'×' : '')"/>
  <x-stat label="Listing health" :value="$k['health'] !== null ? $k['health'].'%' : '—'" icon="star" :tone="($k['health'] ?? 0) >= 80 ? 'green' : 'amber'" :sub="$k['rating'] !== null ? 'avg rating '.$k['rating'].' / 5' : 'no audits yet'" :href="route('digital.channels')"/>
</div>

<div class="g-2-1 mt-1">
<div>
<div class="card flush" id="trendCard"><div class="card-head"><h3><x-icon name="chart"/> Six-month trend</h3></div>
<div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>Month</th><th class="num">Followers</th><th class="num">Reach</th><th class="num">Engagements</th><th class="num">Ad spend</th><th class="num">Link clicks</th><th class="num">Booked revenue</th></tr></thead>
@php $maxRev = max(1, $trend->max('revenue')); @endphp
<tbody>@foreach($trend as $t)<tr><td>{{ $t['month']->format('M Y') }}</td><td class="num">{{ number_format($t['followers']) }}</td><td class="num">{{ number_format($t['reach']) }}</td><td class="num">{{ number_format($t['engagements']) }}</td>
  <td class="num">{{ number_format($t['spend']) }}</td><td class="num">{{ number_format($t['clicks']) }}</td><td class="num"><div class="dm-bar"><span style="width:{{ round(100 * $t['revenue'] / $maxRev) }}%"></span></div>{{ number_format($t['revenue']) }}</td></tr>@endforeach</tbody></table></div></div>

<div class="card flush mt-1" id="campaignsCard"><div class="card-head"><h3><x-icon name="trend"/> Active paid campaigns</h3><a class="btn btn-sm btn-ghost" href="{{ route('digital.campaigns') }}">All →</a></div>
<div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>Campaign</th><th class="num">Spend</th><th>Budget used</th><th class="num">Clicks</th><th class="num">Bookings</th><th class="num">ROAS</th></tr></thead>
<tbody>@forelse($campaigns as [$c, $s])<tr><td><a href="{{ route('digital.campaign', $c) }}"><b>{{ $c->number }}</b> {{ $c->name }}</a><div class="tiny muted">{{ $c->channel?->platformLabel() ?? '—' }}</div></td>
  <td class="num">{{ money($s['spend']) }}</td><td style="min-width:7rem">@if($s['pacing'] !== null)<div class="dm-bar {{ $s['pacing'] > 100 ? 'over' : '' }}"><span style="width:{{ min(100, $s['pacing']) }}%"></span></div><span class="tiny {{ $s['pacing'] > 100 ? 'text-red bold' : 'muted' }}">{{ $s['pacing'] }}%</span>@else<span class="muted">—</span>@endif</td>
  <td class="num">{{ number_format($s['clicks']) }}</td><td class="num">{{ $s['bookings'] }}</td><td class="num">{{ $s['pms_roas'] !== null ? $s['pms_roas'].'×' : '—' }}</td></tr>
@empty<tr><td colspan="6" class="empty">No active campaigns.</td></tr>@endforelse</tbody></table></div></div>

<div class="card flush mt-1" id="topPosts"><div class="card-head"><h3><x-icon name="star"/> Top posts (last 60 days)</h3></div>
<div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>Post</th><th>Published</th><th class="num">Reach</th><th class="num">Engagements</th><th class="num">Rate</th></tr></thead>
<tbody>@forelse($topPosts as $p)<tr><td><a href="{{ route('digital.post', $p) }}">{{ $p->title }}</a></td><td class="small">{{ $p->published_at->format('d M') }}</td><td class="num">{{ number_format($p->reach) }}</td><td class="num">{{ number_format($p->engagements) }}</td><td class="num">{{ $p->engagementRate() !== null ? $p->engagementRate().'%' : '—' }}</td></tr>
@empty<tr><td colspan="5" class="empty">No published posts with results yet.</td></tr>@endforelse</tbody></table></div></div>
</div>

<div>
<div class="card flush" id="upcomingCard"><div class="card-head"><h3><x-icon name="calendar"/> Coming up</h3><a class="btn btn-sm btn-ghost" href="{{ route('digital.calendar') }}">Calendar →</a></div>
<div class="card-body">@forelse($upcoming as $p)<a class="dm-item" href="{{ route('digital.post', $p) }}"><span class="badge badge-{{ $p->isMissed() ? 'red' : $p->statusTone() }}">{{ $p->isMissed() ? 'Missed' : $p->statusLabel() }}</span> <b>{{ $p->title }}</b><div class="tiny muted">{{ $p->scheduled_at->format('D d M H:i') }} · {{ $p->author?->name }}</div></a>
@empty<p class="muted small pad">Nothing scheduled – plan the next posts on the calendar.</p>@endforelse</div></div>

<div class="card flush mt-1" id="channelsCard"><div class="card-head"><h3><x-icon name="globe"/> Channels</h3><a class="btn btn-sm btn-ghost" href="{{ route('digital.channels') }}">Manage →</a></div>
<div class="card-body">@foreach($channels as $c)<a class="dm-item between-row" href="{{ route('digital.channel', $c) }}"><span><span class="dm-dot" style="background:{{ $c->colour() }}"></span> {{ $c->name }}</span>
  <span class="small">@if($c->kind() === 'listing'){{ $c->rating !== null ? $c->rating.' ★' : '' }} @if(($h = $c->healthScore()) !== null)<span class="badge badge-{{ $h >= 80 ? 'green' : ($h >= 50 ? 'amber' : 'red') }}">{{ $h }}%</span>@endif @elseif($c->kind() === 'social'){{ number_format($c->followers) }}@else<span class="muted">ads</span>@endif</span></a>@endforeach</div></div>
</div>
</div>
@endsection
