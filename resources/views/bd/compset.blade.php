@extends('layouts.app')
@section('title', 'Competitive Set')
@section('crumbs') Business Development / Competitive set @endsection
@php $w = can_do('bd.edit') || can_do('bd.manage'); $mg = can_do('bd.manage'); $CH = \App\Models\BdRateShop::class; $P = \App\Models\BdCompetitor::class;
  $ix = fn ($v) => $v === null ? '—' : '<span class="'.($v >= 100 ? 'text-green' : 'text-red').' bold">'.number_format($v, 1).'</span>'; @endphp
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'bd_rate_shop']) }}"><x-icon name="download"/> Rate shop report</a>
  @if($mg)<button class="btn" data-modal="competitorModalNew" id="newCompetitorBtn"><x-icon name="plus"/> Add hotel</button>@endif
  @if($w && $active->count())<button class="btn btn-primary" data-modal="shopModal" data-fstay_date="{{ $from->toDateString() }}" data-fchannel="{{ $channel }}" id="newShopBtn"><x-icon name="search"/> Enter rate shop</button>@endif
@endsection
@section('content')
@include('bd._workspace-nav')
<div class="between-row mb-1">
  <form method="get" class="flex gap-1 wrap" id="shopFilter"><input type="date" name="from" value="{{ $from->toDateString() }}" aria-label="Start date">
    <select name="channel" aria-label="Shopping channel">@foreach($CH::CHANNELS as $k => $l)<option value="{{ $k }}" @selected($channel === $k)>{{ $l }}</option>@endforeach</select><button class="btn btn-sm">Show</button>
    <a class="btn btn-sm btn-ghost" href="{{ route('bd.compset', ['from' => $from->copy()->subDays(14)->toDateString(), 'channel' => $channel]) }}">← 14 days</a><a class="btn btn-sm btn-ghost" href="{{ route('bd.compset', ['from' => $from->copy()->addDays(14)->toDateString(), 'channel' => $channel]) }}">14 days →</a></form>
  <span class="small muted">Our rate: {{ $type?->name ?? '—' }} (BAR after revenue management) · latest rate shop {{ $lastShop ? $lastShop->format('d M Y') : 'never' }}</span>
</div>
<div class="stats mt-1" id="rateShopSummary">
  <x-stat :label="$CH::CHANNELS[$channel].' price index'" :value="$position['index'] !== null ? number_format($position['index'], 1) : '—'" icon="trend" tone="purple" :sub="$position['index'] !== null ? 'ours '.money($position['own']).' vs comp '.money($position['comp']).' · '.$position['shops'].' comparable dates' : 'no comparable rates in this window'"/>
  <x-stat label="Below comp average" :value="number_format($position['cheaper'])" icon="chart" tone="blue" :sub="'of '.$position['shops'].' comparable dates'"/>
  <x-stat label="Above comp average" :value="number_format($position['dearer'])" icon="trend" tone="amber" :sub="'of '.$position['shops'].' comparable dates'"/>
</div>

<div class="card flush" id="shopGrid"><div class="card-head"><h3><x-icon name="chart"/> Rate shop – {{ $CH::CHANNELS[$channel] }}</h3><span class="tiny muted">{{ hotel()->currency_symbol ?? 'GH₵' }} per night · SO = sold out · <i>italic</i> = our live rate (not shopped) · price rank 1 = dearest</span></div>
<div class="card-body table-wrap"><table class="table table-compact bd-grid"><thead><tr><th scope="col">Hotel</th>@foreach($days as $d)@php $sh = $grid[$d->toDateString()] ?? null; @endphp<th scope="col" class="num {{ $d->isWeekend() ? 'bd-we' : '' }}">{{ $d->format('D') }}<br>{{ $d->format('d M') }}@if($sh && $w)<form method="post" action="{{ route('bd.shop.delete', $sh) }}" data-confirm="Remove the rate shop for {{ $d->format('d M Y') }}?" class="bd-x">@csrf<button class="btn btn-sm btn-ghost" aria-label="Remove rate shop for {{ $d->format('d M Y') }}" title="Remove shop">×</button></form>@endif</th>@endforeach</tr></thead><tbody>
  <tr class="bold-row" data-row="own"><td class="nowrap"><b>{{ $currentHotel->name }}</b></td>@foreach($days as $d)@php $sh = $grid[$d->toDateString()] ?? null; $live = $ownRates[$d->toDateString()] ?? null; @endphp
    <td class="num">@if($sh){{ $sh->own_sold_out ? 'SO' : ($sh->own_rate !== null ? number_format($sh->own_rate) : '—') }}@elseif($live !== null)<i class="muted">{{ number_format($live) }}</i>@else — @endif</td>@endforeach</tr>
  @foreach($active as $c)<tr data-competitor="{{ $c->id }}"><td class="nowrap"><a href="{{ route('bd.competitor', $c) }}">{{ $c->name }}</a>@if($c->stars)<span class="tiny muted"> {{ str_repeat('★', $c->stars) }}</span>@endif</td>
    @foreach($days as $d)@php $sh = $grid[$d->toDateString()] ?? null; $l = $sh?->lines->firstWhere('competitor_id', $c->id); $own = $sh?->own_rate; @endphp
    <td class="num {{ $l && ! $l->sold_out && $own !== null && $l->rate !== null ? ((float) $l->rate > (float) $own ? 'bd-dear' : ((float) $l->rate < (float) $own ? 'bd-cheap' : '')) : '' }}">{{ $l ? ($l->sold_out ? 'SO' : number_format($l->rate)) : '' }}</td>@endforeach</tr>@endforeach
  <tr class="muted"><td>Comp-set average</td>@foreach($days as $d)@php $sh = $grid[$d->toDateString()] ?? null; @endphp<td class="num">{{ $sh && $sh->compAverage() !== null ? number_format($sh->compAverage()) : '' }}</td>@endforeach</tr>
  <tr><td>Us vs average</td>@foreach($days as $d)@php $sh = $grid[$d->toDateString()] ?? null; $avg = $sh?->compAverage(); $diff = $sh && $avg && $sh->own_rate !== null ? round(100 * ((float) $sh->own_rate - $avg) / $avg, 1) : null; @endphp<td class="num {{ $diff === null ? '' : ($diff >= 0 ? 'text-green' : 'text-red') }}">{{ $diff === null ? '' : ($diff >= 0 ? '+' : '').$diff.'%' }}</td>@endforeach</tr>
  <tr><td>Our price rank</td>@foreach($days as $d)@php $sh = $grid[$d->toDateString()] ?? null; $rk = $sh?->ownRank(); @endphp<td class="num">{{ $rk ? '#'.$rk.' / '.($sh->lines->where('sold_out', false)->whereNotNull('rate')->count() + 1) : '' }}</td>@endforeach</tr>
</tbody></table>@if($active->isEmpty())<p class="muted small" style="padding:.75rem .9rem">No hotels in the competitive set yet.</p>@endif</div></div>

<div class="g-2 mt-1">
  <div class="card flush" id="benchCard"><div class="card-head"><h3><x-icon name="trend"/> Monthly benchmark (STR-style)</h3><a class="btn btn-sm btn-ghost" href="{{ route('reports.show', ['report' => 'bd_benchmark']) }}">Report →</a></div>
    <div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>Month</th><th class="num">Occ. us / comp</th><th class="num">ADR us / comp</th><th class="num">MPI</th><th class="num">ARI</th><th class="num">RGI</th><th></th></tr></thead><tbody>
    @foreach($series->reverse() as $s)<tr data-month="{{ $s['month']->format('Y-m') }}"><td class="nowrap">{{ $s['month']->format('M Y') }}</td>
      <td class="num">{{ number_format($s['own']['occupancy'], 1) }} / {{ $s['comp'] ? number_format($s['comp']['occupancy'], 1) : '—' }}</td><td class="num">{{ number_format($s['own']['adr']) }} / {{ $s['comp'] ? number_format($s['comp']['adr']) : '—' }}</td>
      <td class="num">{!! $ix($s['mpi']) !!}</td><td class="num">{!! $ix($s['ari']) !!}</td><td class="num">{!! $ix($s['rgi']) !!}</td>
      <td class="tiny muted">{{ $s['benchmark'] ? \App\Models\BdBenchmark::SOURCES[$s['benchmark']->source] ?? '' : '' }}{{ $s['own']['source'] === 'report' ? ' · our figures from the report' : '' }}</td></tr>@endforeach
    </tbody></table></div>
    @if($w)<div class="card-body pad"><form method="post" action="{{ route('bd.benchmarks.save') }}" class="form-grid" id="benchForm">@csrf
      <div class="field"><label>Month</label><input type="month" name="month" required value="{{ bdate()->copy()->startOfMonth()->subMonthNoOverflow()->format('Y-m') }}" max="{{ bdate()->copy()->startOfMonth()->subMonthNoOverflow()->format('Y-m') }}"></div>
      <div class="field"><label>Comp-set occupancy %</label><input type="number" name="comp_occupancy" min="0.01" max="100" step="0.01" required></div>
      <div class="field"><label>Comp-set ADR ({{ hotel()->currency_symbol ?? 'GH₵' }})</label><input type="number" name="comp_adr" min="0.01" step="0.01" required></div>
      <div class="field"><label>Our occupancy % <span class="tiny muted">(optional)</span></label><input type="number" name="own_occupancy" min="0" max="100" step="0.01" placeholder="from the PMS"></div>
      <div class="field"><label>Our ADR ({{ hotel()->currency_symbol ?? 'GH₵' }}) <span class="tiny muted">(optional)</span></label><input type="number" name="own_adr" min="0" step="0.01" placeholder="from the PMS"></div>
      <div class="field"><label>Source</label><select name="source">@foreach(\App\Models\BdBenchmark::SOURCES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
      <div class="field span-all"><label>Note</label><input name="note" maxlength="255" placeholder="e.g. STR monthly STAR report, 5 hotels"></div>
      <div class="field span-all"><button class="btn btn-primary" id="benchSaveBtn">Save benchmark</button></div></form></div>@endif
  </div>
  <div class="card flush" id="competitorsCard"><div class="card-head"><h3><x-icon name="building"/> Hotels in the set</h3></div><div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>Hotel</th><th>Class</th><th class="num">Rooms</th><th class="num">km</th><th></th></tr></thead><tbody>
    @forelse($competitors as $c)<tr class="{{ $c->is_active ? '' : 'muted' }}"><td><a href="{{ route('bd.competitor', $c) }}">{{ $c->name }}</a><div class="tiny muted">{{ $c->location }}</div></td><td class="small">{{ $c->stars ? $c->stars.'★ ' : '' }}{{ $P::POSITIONING[$c->positioning] ?? '' }}</td><td class="num">{{ $c->rooms ?? '—' }}</td><td class="num">{{ $c->distance_km ?? '—' }}</td>
      <td>@if(! $c->is_active)<span class="badge badge-slate">Inactive</span>@endif @if($mg)<button class="btn btn-sm btn-ghost" data-modal="competitorModal{{ $c->id }}" title="Edit"><x-icon name="edit"/></button>@endif</td></tr>
    @empty<tr><td colspan="5" class="muted">Add the 4–8 hotels your guests compare you with.</td></tr>@endforelse
    @php $rooms = $active->sum('rooms'); @endphp
    @if($rooms)<tr class="bold-row"><td colspan="2">Comp set ({{ $active->count() }} active)</td><td class="num">{{ number_format($rooms) }}</td><td colspan="2" class="tiny muted">fair share {{ round(100 * \App\Models\Room::where('is_active', true)->count() / max(1, $rooms + \App\Models\Room::where('is_active', true)->count()), 1) }}%</td></tr>@endif
  </tbody></table></div></div>
</div>

@if($w && $active->count())
<div class="modal {{ session()->hasOldInput('stay_date') ? 'show' : '' }}" id="shopModal"><div class="modal-box" style="max-width:40rem"><form method="post" action="{{ route('bd.shops.store') }}">@csrf
  <div class="modal-head"><h3>Rate shop</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field"><label for="shopStayDate">Stay date</label><input type="date" id="shopStayDate" name="stay_date" required min="{{ bdate()->toDateString() }}" max="{{ bdate()->copy()->addYear()->toDateString() }}" value="{{ old('stay_date', $from->toDateString()) }}"></div>
    <div class="field"><label for="shopChannel">Where shopped</label><select id="shopChannel" name="channel">@foreach($CH::CHANNELS as $k => $l)<option value="{{ $k }}" @selected(old('channel', $channel) === $k)>{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label for="ownRate">Our rate <span class="tiny muted">(blank = our live BAR)</span></label><input type="number" id="ownRate" name="own_rate" min="0" step="0.01" value="{{ old('own_rate') }}"></div>
    <div class="field"><label>&nbsp;</label><label class="dm-check"><input type="checkbox" name="own_sold_out" value="1" @checked(old('own_sold_out'))> We are sold out</label></div>
  </div>
  <table class="table table-compact mt-1" id="shopLines"><thead><tr><th scope="col">Hotel</th><th scope="col">Rate ({{ hotel()->currency_symbol ?? 'GH₵' }})</th><th scope="col">Sold out</th></tr></thead><tbody>
    @foreach($active as $c)<tr><td>{{ $c->name }}</td><td><input type="number" name="rates[{{ $c->id }}]" min="0" step="0.01" aria-label="{{ $c->name }} rate" value="{{ old('rates.'.$c->id) }}" style="max-width:9rem"></td><td><input type="checkbox" name="sold_out[{{ $c->id }}]" value="1" aria-label="{{ $c->name }} sold out" @checked(old('sold_out.'.$c->id))></td></tr>@endforeach
  </tbody></table>
  <div class="field mt-1"><label for="shopNote">Note</label><input id="shopNote" name="note" maxlength="255" value="{{ old('note') }}" placeholder="Room type compared, breakfast included …"></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save rate shop</button></div>
</form></div></div>
@endif
@if($mg)
  @include('bd._competitor_modal', ['c' => null])
  @foreach($competitors as $c)@include('bd._competitor_modal', ['c' => $c])@endforeach
@endif
@endsection
