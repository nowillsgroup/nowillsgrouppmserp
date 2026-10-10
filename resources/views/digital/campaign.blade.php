@extends('layouts.app')
@section('title', $c->number.' '.$c->name)
@section('crumbs') <a href="{{ route('digital.campaigns') }}">Digital Marketing / Paid campaigns</a> / {{ $c->number }} @endsection
@php $C = \App\Models\DmCampaign::class; $mg = can_do('digital.manage'); @endphp
@section('actions')
  @if($mg && $c->status !== 'ended')<button class="btn" data-modal="campaignModal" id="editCampaignBtn"><x-icon name="edit"/> Edit</button>
    @foreach(['draft' => ['active' => 'Activate'], 'active' => ['paused' => 'Pause', 'ended' => 'End'], 'paused' => ['active' => 'Resume', 'ended' => 'End']][$c->status] ?? [] as $to => $label)
      <form method="post" action="{{ route('digital.campaign.status', $c) }}" @if($to === 'ended') data-confirm="End this campaign? Its results are then locked." @endif>@csrf<input type="hidden" name="status" value="{{ $to }}"><button class="btn {{ $to === 'active' ? 'btn-primary' : '' }}" id="status_{{ $to }}">{{ $label }}</button></form>@endforeach
  @endif
@endsection
@section('content')
<div class="flex gap-1 wrap mb-1"><span class="badge badge-{{ $c->statusTone() }}" id="campaignStatus" data-status="{{ $c->status }}">{{ $c->statusLabel() }}</span><span class="badge badge-slate">{{ $C::OBJECTIVES[$c->objective] }}</span><span class="badge badge-slate">utm_campaign={{ $c->utm_campaign }}</span>@if($c->promo)<span class="badge badge-purple">promo {{ $c->promo->code }}</span>@endif</div>
<div class="stats" id="campaignStats">
  <x-stat label="Spend" :value="money_short($s['spend'])" icon="wallet" :tone="$s['pacing'] > 100 ? 'red' : 'amber'" :sub="$s['pacing'] !== null ? $s['pacing'].'% of '.money_short($c->budget) : 'no budget'"/>
  <x-stat label="Impressions / clicks" :value="number_format($s['clicks'])" icon="link" tone="blue" :sub="number_format($s['impressions']).' impr.'.($s['ctr'] !== null ? ' · CTR '.$s['ctr'].'%' : '').($s['cpc'] !== null ? ' · CPC '.number_format($s['cpc'], 2) : '')"/>
  <x-stat label="Platform conversions" :value="$s['conversions']" icon="check" tone="purple" :sub="$s['roas'] !== null ? money_short($s['conversion_value']).' · ROAS '.$s['roas'].'×' : null"/>
  <x-stat label="Booked in the PMS" :value="money_short($s['revenue'])" icon="bed" tone="green" :sub="$s['bookings'].' bookings'.($s['pms_roas'] !== null ? ' · ROAS '.$s['pms_roas'].'×' : '').($c->target_roas ? ' (target '.(float) $c->target_roas.'×)' : '')"/>
</div>
<div class="g-2-1 mt-1">
<div>
<div class="card flush" id="adDays"><div class="card-head"><h3><x-icon name="chart"/> Daily results</h3><span class="tiny muted">{{ $days->count() }} day(s){{ $s['last'] ? ' · last '.\Carbon\Carbon::parse($s['last'])->format('d M') : '' }}</span></div>
<div class="card-body table-wrap" style="max-height:28rem"><table class="table table-compact"><thead><tr><th>Date</th><th class="num">Impr.</th><th class="num">Clicks</th><th class="num">Spend</th><th class="num">Conv.</th><th class="num">Value</th><th>Source</th><th></th></tr></thead><tbody>
@forelse($days as $d)<tr><td class="small nowrap">{{ $d->date->format('D d M') }}</td><td class="num">{{ number_format($d->impressions) }}</td><td class="num">{{ number_format($d->clicks) }}</td><td class="num">{{ number_format($d->spend, 2) }}</td><td class="num">{{ $d->conversions }}</td><td class="num">{{ number_format($d->conversion_value) }}</td><td class="tiny muted">{{ $d->source }}</td>
  <td>@if($mg && $c->status !== 'ended')<form method="post" action="{{ route('digital.adstat.delete', $d) }}" data-confirm="Remove this day?">@csrf<button class="btn btn-sm btn-ghost" title="Remove"><x-icon name="trash"/></button></form>@endif</td></tr>
@empty<tr><td colspan="8" class="empty">No results yet – add a day or import the platform's CSV export.</td></tr>@endforelse</tbody></table></div></div>

<div class="card flush mt-1" id="campaignLinks"><div class="card-head"><h3><x-icon name="link"/> Tracked links</h3><a class="btn btn-sm btn-ghost" href="{{ route('digital.links') }}">All links →</a></div>
<div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>Link</th><th>Source / medium</th><th class="num">Clicks</th><th class="num">Bookings</th><th class="num">Revenue</th></tr></thead><tbody>
@forelse($links as [$l, $ls])<tr><td><a href="{{ route('digital.link', $l) }}">/go/{{ $l->code }}</a><div class="tiny muted">{{ $l->name }}</div></td><td class="small">{{ $l->utm_source }} / {{ $l->utm_medium }}</td><td class="num">{{ number_format($ls['clicks']) }}</td><td class="num">{{ $ls['bookings'] }}</td><td class="num">{{ number_format($ls['revenue']) }}</td></tr>
@empty<tr><td colspan="5" class="empty">No tracked links for this campaign – create one so bookings can be credited.</td></tr>@endforelse</tbody></table></div></div>
@if($posts->count())<div class="card flush mt-1"><div class="card-head"><h3><x-icon name="message"/> Organic posts</h3></div><div class="card-body">@foreach($posts as $p)<a class="dm-item" href="{{ route('digital.post', $p) }}"><span class="badge badge-{{ $p->statusTone() }}">{{ $p->statusLabel() }}</span> {{ $p->title }} <span class="tiny muted">{{ $p->scheduled_at->format('d M') }}</span></a>@endforeach</div></div>@endif
</div>
<div>
@if($mg && $c->status !== 'draft' && $c->status !== 'ended')
<div class="card" id="addDay"><div class="card-head"><h3><x-icon name="plus"/> Add / correct a day</h3></div><div class="card-body"><form method="post" action="{{ route('digital.campaign.adstat', $c) }}">@csrf<div class="form-grid">
  <div class="field span-all"><label>Date</label><input type="date" name="date" required value="{{ bdate()->toDateString() }}" max="{{ bdate()->toDateString() }}"></div>
  <div class="field"><label>Impressions</label><input type="number" min="0" name="impressions" value="0"></div><div class="field"><label>Clicks</label><input type="number" min="0" name="clicks" value="0"></div>
  <div class="field"><label>Spend (GH₵)</label><input type="number" min="0" step="0.01" name="spend" value="0"></div><div class="field"><label>Conversions</label><input type="number" min="0" name="conversions" value="0"></div>
  <div class="field span-all"><label>Conversion value (GH₵)</label><input type="number" min="0" step="0.01" name="conversion_value" value="0"></div></div>
  <button class="btn btn-primary mt-1" id="addDayBtn">Save day</button></form></div></div>
<div class="card mt-1" id="importCard"><div class="card-head"><h3><x-icon name="download"/> Import CSV</h3></div><div class="card-body"><form method="post" action="{{ route('digital.campaign.import', $c) }}" enctype="multipart/form-data">@csrf
  <p class="tiny muted">Export the daily breakdown from Meta Ads Manager, Google Ads or TikTok and upload it here. Columns recognised: Day / Date / Reporting starts, Impressions, Clicks / Link clicks, Amount spent / Cost, Results / Conversions / Purchases, Conversion value. Dates as YYYY-MM-DD or DD/MM/YYYY. Existing days are updated.</p>
  <input type="file" name="file" accept=".csv,.txt" required><button class="btn mt-1" id="importBtn">Import</button></form></div></div>
@elseif($c->status === 'draft')<div class="alert alert-info">Activate the campaign to start recording results.</div>@endif
<div class="card mt-1"><div class="card-body"><dl class="kv"><dt>Platform</dt><dd>{{ $c->channel?->name ?? '—' }}</dd><dt>Dates</dt><dd>{{ $c->start_date->format('d M Y') }} – {{ $c->end_date?->format('d M Y') ?? 'open' }}</dd><dt>Owner</dt><dd>{{ $c->owner?->name }}</dd>
  <dt>CPM</dt><dd>{{ $s['cpm'] !== null ? money($s['cpm']) : '—' }}</dd><dt>Cost per booking</dt><dd>{{ $s['cpa'] !== null ? money($s['cpa']) : '—' }}</dd><dt>Link clicks</dt><dd>{{ number_format($s['link_clicks']) }}</dd></dl>
  @if($c->notes)<p class="small sa-notes mt-1">{{ $c->notes }}</p>@endif</div></div>
</div>
</div>
@if($mg && $c->status !== 'ended')@include('digital._campaign_modal', ['c' => $c])@endif
@endsection
