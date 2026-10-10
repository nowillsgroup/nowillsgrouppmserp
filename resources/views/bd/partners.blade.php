@extends('layouts.app')
@section('title', 'Partnerships')
@section('crumbs') Business Development / Partnerships @endsection
@php $w = can_do('bd.edit') || can_do('bd.manage'); $PT = \App\Models\BdPartner::class; @endphp
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'bd_partners']) }}"><x-icon name="download"/> Production report</a>
  @if($w)<button class="btn btn-primary" data-modal="partnerModalNew" id="newPartnerBtn"><x-icon name="plus"/> New partnership</button>@endif
@endsection
@section('content')
<div class="stats">
  <x-stat label="Active partnerships" :value="$totals['active']" icon="users" tone="blue"/>
  <x-stat label="Production {{ bdate()->year }}" :value="money_short($totals['revenue'])" icon="money" tone="green" :sub="$totals['bookings'].' bookings · '.number_format($totals['nights']).' nights'"/>
  <x-stat label="Commission due on it" :value="money_short($totals['commission'])" icon="wallet" tone="amber"/>
</div>
<div class="tabs mt-1" id="partnerTabs"><a class="{{ ! $status ? 'active' : '' }}" href="{{ route('bd.partners') }}">Current</a>@foreach($PT::STATUSES as $k => $s)<a class="{{ $status === $k ? 'active' : '' }}" href="{{ route('bd.partners', ['status' => $k]) }}">{{ $s[0] }}</a>@endforeach</div>
<div class="card flush"><div class="card-body table-wrap"><table class="table table-compact" id="partnerTable"><thead><tr><th>Partner</th><th>Type / agreement</th><th>Status</th><th>Term</th><th>Tracks</th><th class="num">Nights YTD</th><th class="num">Revenue YTD</th><th>vs target</th><th>Owner</th></tr></thead><tbody>
  @forelse($rows as [$p, $v, $flags])<tr data-partner="{{ $p->number }}"><td><a href="{{ route('bd.partner', $p) }}"><b>{{ $p->number }}</b> {{ $p->name }}</a>@foreach($flags as $f)<div><span class="badge badge-{{ $f[2] }}">{{ $f[1] }}</span></div>@endforeach</td>
    <td class="small">{{ $p->typeLabel() }}<div class="tiny muted">{{ $PT::AGREEMENTS[$p->agreement] ?? '' }}{{ $p->commission_pct !== null ? ' · '.rtrim(rtrim($p->commission_pct, '0'), '.').'%' : '' }}</div></td>
    <td><span class="badge badge-{{ $p->statusTone() }}">{{ $p->statusLabel() }}</span></td><td class="small nowrap">{{ $p->start_date?->format('M Y') ?? '—' }} – {{ $p->end_date?->format('M Y') ?? '—' }}</td>
    <td class="tiny">{{ collect([$p->source?->name, $p->promo ? 'code '.$p->promo->code : null, $p->company?->name])->filter()->implode(' · ') ?: '—' }}</td>
    <td class="num">{{ number_format($v['nights']) }}</td><td class="num">{{ number_format($v['revenue'], 2) }}</td>
    <td>@if($v['revenue_pct'] !== null || $v['nights_pct'] !== null)@php $pc = $v['revenue_pct'] ?? $v['nights_pct']; @endphp<div class="dm-bar"><span style="width:{{ min(100, $pc) }}%"></span></div><span class="tiny">{{ $pc }}%</span>@else<span class="tiny muted">—</span>@endif</td>
    <td class="small">{{ $p->owner?->name }}</td></tr>
  @empty<tr><td colspan="9" class="muted">No partnerships here yet.</td></tr>@endforelse
</tbody></table></div></div>
@if($w)@include('bd._partner_modal', ['p' => null])@endif
@endsection
