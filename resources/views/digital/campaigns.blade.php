@extends('layouts.app')
@section('title', 'Paid Campaigns')
@section('crumbs') Digital Marketing / Paid campaigns @endsection
@php $C = \App\Models\DmCampaign::class; $tot = ['spend' => 0, 'budget' => 0, 'rev' => 0, 'bk' => 0, 'clicks' => 0]; foreach ($rows as [$c, $s]) { $tot['spend'] += $s['spend']; $tot['budget'] += (float) $c->budget; $tot['rev'] += $s['revenue']; $tot['bk'] += $s['bookings']; $tot['clicks'] += $s['clicks']; } @endphp
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'digital_paid']) }}"><x-icon name="download"/> Paid media report</a>
  @if(can_do('digital.manage'))<button class="btn btn-primary" data-modal="campaignModalNew" id="newCampaignBtn"><x-icon name="plus"/> New campaign</button>@endif
@endsection
@section('content')
@include('digital._workspace-nav')
<div class="stats" id="campStats">
  <x-stat label="Spend" :value="money_short($tot['spend'])" icon="wallet" tone="amber" :sub="'of '.money_short($tot['budget']).' budgeted'"/>
  <x-stat label="Ad clicks" :value="number_format($tot['clicks'])" icon="link" tone="blue" :sub="$tot['clicks'] ? money($tot['spend'] / $tot['clicks']).' per click' : null"/>
  <x-stat label="Attributed bookings" :value="$tot['bk']" icon="bed" tone="purple" :sub="$tot['bk'] ? money($tot['spend'] / $tot['bk']).' per booking' : 'via tracked links'"/>
  <x-stat label="Booked revenue" :value="money_short($tot['rev'])" icon="money" tone="green" :sub="$tot['spend'] > 0 ? 'ROAS '.round($tot['rev'] / $tot['spend'], 2).'×' : null"/>
</div>
<nav class="tabs mt-1" aria-label="Paid campaign status"> <a href="{{ route('digital.campaigns') }}" class="{{ ! $status ? 'active' : '' }}" @if(! $status) aria-current="page" @endif>All</a>@foreach($C::STATUSES as $k => [$l])<a href="{{ route('digital.campaigns', ['status' => $k]) }}" class="{{ $status === $k ? 'active' : '' }}" @if($status === $k) aria-current="page" @endif>{{ $l }}</a>@endforeach</nav>
<div class="card flush"><div class="card-head"><h3><x-icon name="trend"/> Paid campaign performance</h3><span class="tiny muted">{{ number_format($rows->count()) }} campaign{{ $rows->count() === 1 ? '' : 's' }}{{ $status ? ' · '.$C::STATUSES[$status][0] : '' }}</span></div><div class="card-body table-wrap"><table class="table table-compact" id="campaignTable"><thead><tr><th scope="col">Campaign</th><th scope="col">Status</th><th scope="col">Dates</th><th scope="col" class="num">Budget</th><th scope="col" class="num">Pacing</th><th scope="col" class="num">Spend</th><th scope="col" class="num">CTR</th><th scope="col" class="num">CPC</th><th scope="col" class="num">Platform ROAS</th><th scope="col" class="num">Bookings</th><th scope="col" class="num">Revenue</th><th scope="col" class="num">PMS ROAS</th></tr></thead><tbody>
@forelse($rows as [$c, $s])<tr data-campaign="{{ $c->number }}"><td><a href="{{ route('digital.campaign', $c) }}"><b>{{ $c->number }}</b> {{ $c->name }}</a><div class="tiny muted">{{ $c->channel?->platformLabel() ?? '—' }} · {{ $C::OBJECTIVES[$c->objective] ?? ucfirst($c->objective) }} · {{ $c->owner?->name ?? 'Unassigned' }} · UTM {{ $c->utm_campaign }}</div></td>
  <td><span class="badge badge-{{ $c->statusTone() }}">{{ $c->statusLabel() }}</span></td><td class="small nowrap">{{ $c->start_date->format('d M') }} – {{ $c->end_date?->format('d M y') ?? 'open' }}</td>
  <td class="num">{{ money($c->budget) }}</td><td class="num" style="min-width:7rem">@if($s['pacing'] !== null)<div class="dm-bar {{ $s['pacing'] > 100 ? 'over' : '' }}" role="img" aria-label="{{ $s['pacing'] }} percent of budget used"><span style="width:{{ min(100, $s['pacing']) }}%"></span></div><span class="tiny {{ $s['pacing'] > 100 ? 'text-red bold' : 'muted' }}">{{ $s['pacing'] }}%</span>@else<span class="muted">—</span>@endif</td><td class="num {{ $s['pacing'] !== null && $s['pacing'] > 100 ? 'text-red bold' : '' }}">{{ money($s['spend']) }}</td><td class="num">{{ $s['ctr'] !== null ? $s['ctr'].'%' : '—' }}</td><td class="num">{{ $s['cpc'] !== null ? money($s['cpc']) : '—' }}</td>
  <td class="num">{{ $s['roas'] !== null ? $s['roas'].'×' : '—' }}</td><td class="num">{{ $s['bookings'] }}</td><td class="num">{{ money($s['revenue']) }}</td>
  <td class="num {{ $c->target_roas && $s['pms_roas'] !== null ? ($s['pms_roas'] >= (float) $c->target_roas ? 'text-green bold' : 'text-red') : '' }}">{{ $s['pms_roas'] !== null ? $s['pms_roas'].'×' : '—' }}</td></tr>
@empty<tr><td colspan="12" class="empty">{{ $status ? 'No campaigns match this status.' : 'No paid campaigns yet. Create a draft to start tracking media spend and bookings.' }}</td></tr>@endforelse</tbody></table></div></div>
<p class="tiny muted mt-1">Platform ROAS = conversion value reported by the ad platform ÷ spend. PMS ROAS = room revenue of direct bookings made through the campaign's tracked links (cancelled excluded) ÷ spend.</p>
@if(can_do('digital.manage'))@include('digital._campaign_modal', ['c' => null])@endif
@endsection
