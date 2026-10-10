@extends('layouts.app')
@section('title', $c->name)
@section('crumbs') Business Development / <a href="{{ route('bd.compset') }}">Competitive set</a> / {{ $c->name }} @endsection
@php $P = \App\Models\BdCompetitor::class; $CH = \App\Models\BdRateShop::class; @endphp
@section('actions')
  @if(can_do('bd.manage'))<button class="btn" data-modal="competitorModal{{ $c->id }}" id="editCompetitorBtn"><x-icon name="edit"/> Edit</button>@endif
  @if($c->website)<a class="btn" href="{{ $c->website }}" target="_blank" rel="noopener"><x-icon name="globe"/> Website</a>@endif
@endsection
@section('content')
<div class="flex gap-1 wrap mb-1">@if(! $c->is_active)<span class="badge badge-slate">Inactive</span>@endif @if($c->stars)<span class="badge badge-amber">{{ str_repeat('★', $c->stars) }}</span>@endif @if($c->positioning)<span class="badge badge-blue">{{ $P::POSITIONING[$c->positioning] }}</span>@endif</div>
<div class="g-2-1">
  <div class="card flush" id="compRates"><div class="card-head"><h3><x-icon name="chart"/> Recent shopped rates</h3></div><div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>Stay date</th><th>Where</th><th class="num">Their rate</th><th class="num">Ours</th><th class="num">Difference</th><th>Shopped</th></tr></thead><tbody>
    @forelse($lines as $l)@php $s = $l->shop; $d = ! $l->sold_out && $l->rate !== null && $s->own_rate !== null ? round(100 * ((float) $l->rate - (float) $s->own_rate) / (float) $s->own_rate, 1) : null; @endphp
    <tr><td class="nowrap">{{ $s->stay_date->format('D d M Y') }}</td><td class="small">{{ $CH::CHANNELS[$s->channel] ?? $s->channel }}</td><td class="num">{{ $l->sold_out ? 'Sold out' : money($l->rate) }}</td><td class="num">{{ $s->own_sold_out ? 'Sold out' : ($s->own_rate !== null ? money($s->own_rate) : '—') }}</td>
      <td class="num {{ $d === null ? '' : ($d > 0 ? 'text-green' : 'text-red') }}">{{ $d === null ? '' : ($d > 0 ? '+' : '').$d.'%' }}</td><td class="tiny muted">{{ $s->shopped_on->format('d M') }}</td></tr>
    @empty<tr><td colspan="6" class="muted">Not shopped yet.</td></tr>@endforelse
  </tbody></table><p class="tiny muted" style="padding:.5rem .9rem">Difference = their rate vs ours (green = they are dearer).</p></div></div>
  <div class="card"><div class="card-body"><dl class="kv">
    <dt>Location</dt><dd>{{ $c->location ?? '—' }}{{ $c->distance_km !== null ? ' · '.$c->distance_km.' km' : '' }}</dd>
    <dt>Rooms</dt><dd>{{ $c->rooms ?? '—' }}</dd>
    <dt>Strengths</dt><dd class="small">{!! nl2br(e($c->strengths ?? '—')) !!}</dd>
    <dt>Weaknesses</dt><dd class="small">{!! nl2br(e($c->weaknesses ?? '—')) !!}</dd>
  </dl></div></div>
</div>
@if(can_do('bd.manage'))@include('bd._competitor_modal', ['c' => $c])@endif
@endsection
