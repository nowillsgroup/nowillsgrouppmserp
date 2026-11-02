@extends('layouts.app')
@section('title', 'Partnerships')
@section('crumbs') Business Development / Partnerships @endsection
@php $w = can_do('bd.edit') || can_do('bd.manage'); $PT = \App\Models\BdPartner::class; @endphp
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'bd_partners']) }}"><x-icon name="download"/> Production report</a>
  @if($w)<button class="btn btn-primary" data-modal="partnerModalNew" id="newPartnerBtn"><x-icon name="plus"/> New partnership</button>@endif
@endsection
@section('content')
@include('bd._workspace-nav')
<div class="stats" id="partnerPortfolioStats">
  <x-stat label="Active partnerships" :value="$totals['active']" icon="users" tone="blue"/>
  <x-stat label="Tracked production {{ bdate()->year }}" :value="money_short($totals['revenue'])" icon="money" tone="green" :sub="$totals['bookings'].' bookings · '.number_format($totals['nights']).' nights'"/>
  <x-stat label="Estimated commission YTD" :value="money_short($totals['commission'])" icon="wallet" tone="amber" sub="calculated from credited room value; not an AP balance"/>
</div>
<div class="between-row mt-1 mb-1">
  <nav class="tabs" id="partnerTabs" aria-label="Partnership status"><a class="{{ ! $status ? 'active' : '' }}" href="{{ route('bd.partners', $q !== '' ? ['q' => $q] : []) }}" @if(! $status) aria-current="page" @endif>All except terminated</a>@foreach($PT::STATUSES as $k => $s)<a class="{{ $status === $k ? 'active' : '' }}" href="{{ route('bd.partners', ['status' => $k] + ($q !== '' ? ['q' => $q] : [])) }}" @if($status === $k) aria-current="page" @endif>{{ $s[0] }}</a>@endforeach</nav>
  <form method="get" class="flex gap-1 wrap" id="partnerSearch"><label class="sr-only" for="partnerQuery">Search partners, contacts, or tracking records</label><input type="search" id="partnerQuery" name="q" value="{{ $q }}" maxlength="120" placeholder="Partner, contact or tracker">@if($status)<input type="hidden" name="status" value="{{ $status }}">@endif<button class="btn btn-sm">Search</button>@if($q !== '')<a class="btn btn-sm btn-ghost" href="{{ route('bd.partners', $status ? ['status' => $status] : []) }}">Clear</a>@endif</form>
</div>
<div class="card flush"><div class="card-head"><h3><x-icon name="users"/> Partnership register</h3><span class="tiny muted">{{ number_format($rows->count()) }} result{{ $rows->count() === 1 ? '' : 's' }}</span></div><div class="card-body table-wrap"><table class="table table-compact" id="partnerTable"><thead><tr><th scope="col">Partner</th><th scope="col">Type / agreement</th><th scope="col">Status</th><th scope="col">Term</th><th scope="col">Tracks</th><th scope="col" class="num">Nights YTD</th><th scope="col" class="num">Revenue YTD</th><th scope="col">vs target</th><th scope="col">Owner</th></tr></thead><tbody>
  @forelse($rows as [$p, $v, $flags])<tr data-partner="{{ $p->number }}"><td><a href="{{ route('bd.partner', $p) }}"><b>{{ $p->number }}</b> {{ $p->name }}</a>@foreach($flags as $f)<div><span class="badge badge-{{ $f[2] }}">{{ $f[1] }}</span></div>@endforeach</td>
    <td class="small">{{ $p->typeLabel() }}<div class="tiny muted">{{ $PT::AGREEMENTS[$p->agreement] ?? '' }}{{ $p->commission_pct !== null ? ' · '.rtrim(rtrim($p->commission_pct, '0'), '.').'%' : '' }}</div></td>
    <td><span class="badge badge-{{ $p->statusTone() }}">{{ $p->statusLabel() }}</span></td><td class="small nowrap">{{ $p->start_date?->format('M Y') ?? '—' }} – {{ $p->end_date?->format('M Y') ?? '—' }}</td>
    <td class="tiny">{{ collect([$p->source?->name, $p->promo ? 'code '.$p->promo->code : null, $p->company?->name])->filter()->implode(' · ') ?: '—' }}</td>
    <td class="num">{{ number_format($v['nights']) }}</td><td class="num">{{ money($v['revenue']) }}</td>
    <td>@if($v['revenue_pct'] !== null || $v['nights_pct'] !== null)@php $pc = $v['revenue_pct'] ?? $v['nights_pct']; @endphp<div class="dm-bar"><span style="width:{{ min(100, $pc) }}%"></span></div><span class="tiny">{{ $pc }}%</span>@else<span class="tiny muted">—</span>@endif</td>
    <td class="small">{{ $p->owner?->name }}</td></tr>
  @empty<tr><td colspan="9" class="empty">{{ $q !== '' ? 'No partnerships match this search.' : ($status ? 'No partnerships have this status yet.' : 'No partnerships yet. Add a partner to start tracking production.') }}</td></tr>@endforelse
</tbody></table></div></div>
@if($w)@include('bd._partner_modal', ['p' => null])@endif
@endsection
