@extends('layouts.app')
@section('title', 'Paid Campaigns')
@section('crumbs') Digital Marketing / Paid campaigns @endsection
@php $C = \App\Models\DmCampaign::class; $tot = ['spend' => 0, 'budget' => 0, 'rev' => 0, 'bk' => 0, 'clicks' => 0]; foreach ($rows as [$c, $s]) { $tot['spend'] += $s['spend']; $tot['budget'] += (float) $c->budget; $tot['rev'] += $s['revenue']; $tot['bk'] += $s['bookings']; $tot['clicks'] += $s['clicks']; } @endphp
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'digital_paid']) }}"><x-icon name="download"/> Paid media report</a>
  @if(can_do('digital.manage'))<button class="btn btn-primary" data-modal="campaignModalNew" id="newCampaignBtn"><x-icon name="plus"/> New campaign</button>@endif
@endsection
@section('content')
<div class="stats" id="campStats">
  <x-stat label="Spend" :value="money_short($tot['spend'])" icon="wallet" tone="amber" :sub="'of '.money_short($tot['budget']).' budgeted'"/>
  <x-stat label="Ad clicks" :value="number_format($tot['clicks'])" icon="link" tone="blue" :sub="$tot['clicks'] ? money($tot['spend'] / $tot['clicks']).' per click' : null"/>
  <x-stat label="Attributed bookings" :value="$tot['bk']" icon="bed" tone="purple" :sub="$tot['bk'] ? money($tot['spend'] / $tot['bk']).' per booking' : 'via tracked links'"/>
  <x-stat label="Booked revenue" :value="money_short($tot['rev'])" icon="money" tone="green" :sub="$tot['spend'] > 0 ? 'ROAS '.round($tot['rev'] / $tot['spend'], 2).'×' : null"/>
</div>
<div class="tabs mt-1"><a href="?" class="{{ ! $status ? 'active' : '' }}">All</a>@foreach($C::STATUSES as $k => [$l])<a href="?status={{ $k }}" class="{{ $status === $k ? 'active' : '' }}">{{ $l }}</a>@endforeach</div>
<div class="card flush"><div class="card-body table-wrap"><table class="table table-compact" id="campaignTable"><thead><tr><th>Campaign</th><th>Status</th><th>Dates</th><th class="num">Budget</th><th class="num">Spend</th><th class="num">CTR</th><th class="num">CPC</th><th class="num">Platform ROAS</th><th class="num">Bookings</th><th class="num">Revenue</th><th class="num">PMS ROAS</th></tr></thead><tbody>
@forelse($rows as [$c, $s])<tr data-campaign="{{ $c->number }}"><td><a href="{{ route('digital.campaign', $c) }}"><b>{{ $c->number }}</b> {{ $c->name }}</a><div class="tiny muted">{{ $c->channel?->platformLabel() ?? '—' }} · {{ $C::OBJECTIVES[$c->objective] }} · utm {{ $c->utm_campaign }}</div></td>
  <td><span class="badge badge-{{ $c->statusTone() }}">{{ $c->statusLabel() }}</span></td><td class="small nowrap">{{ $c->start_date->format('d M') }} – {{ $c->end_date?->format('d M y') ?? 'open' }}</td>
  <td class="num">{{ number_format($c->budget) }}</td><td class="num {{ $s['pacing'] > 100 ? 'text-red bold' : '' }}">{{ number_format($s['spend']) }}</td><td class="num">{{ $s['ctr'] !== null ? $s['ctr'].'%' : '—' }}</td><td class="num">{{ $s['cpc'] !== null ? number_format($s['cpc'], 2) : '—' }}</td>
  <td class="num">{{ $s['roas'] !== null ? $s['roas'].'×' : '—' }}</td><td class="num">{{ $s['bookings'] }}</td><td class="num">{{ number_format($s['revenue']) }}</td>
  <td class="num {{ $c->target_roas && $s['pms_roas'] !== null ? ($s['pms_roas'] >= (float) $c->target_roas ? 'text-green bold' : 'text-red') : '' }}">{{ $s['pms_roas'] !== null ? $s['pms_roas'].'×' : '—' }}</td></tr>
@empty<tr><td colspan="11" class="empty">No campaigns yet.</td></tr>@endforelse</tbody></table></div></div>
<p class="tiny muted mt-1">Platform ROAS = conversion value reported by the ad platform ÷ spend. PMS ROAS = room revenue of direct bookings made through the campaign's tracked links (cancelled excluded) ÷ spend.</p>
@if(can_do('digital.manage'))@include('digital._campaign_modal', ['c' => null])@endif
@endsection
