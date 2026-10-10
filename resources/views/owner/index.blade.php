@extends('layouts.app')
@section('title', 'Owner Dashboard')
@section('crumbs') Owner Reporting / Dashboard @endsection
@php
  $c = $st['cols']['m_act']; $cb = $st['cols']['m_bud']; $y = $st['cols']['y_act'];
  $OS = \App\Models\OwnerStatement::class; $CP = \App\Models\CapexProject::class;
  $canOp = can_do('owner.operate'); $canApprove = can_do('owner.approve');
  $vals = collect($trend)->flatMap(fn ($t) => [$t['tor'], $t['gop'], $t['bud_gop'], $t['bud_tor']])->filter(fn ($v) => $v !== null);
  $max = max(1, $vals->max() ?? 1); $min = min(0, $vals->min() ?? 0); $range = $max - $min;
  $yOf = fn ($v) => round(10 + (1 - ($v - $min) / $range) * 150, 1); $zero = $yOf(0);
  $pctOf = fn ($a, $b) => $b ? number_format($a * 100 / $b, 1).'%' : '–';
@endphp
@section('actions')
  <a class="btn" href="{{ route('owner.usali', ['period' => $month->format('Y-m')]) }}"><x-icon name="chart"/> USALI statement</a>
  <a class="btn" href="{{ route('owner.statements') }}"><x-icon name="book"/> Owner packs</a>
@endsection
@section('content')
@if(! $agreement)
  <div class="alert alert-info"><x-icon name="alert"/> No management agreement is set up – the USALI statement works without one, but management fees, the FF&E reserve and owner packs need the contract terms. @if(can_do('owner.manage'))<a href="{{ route('owner.setup') }}">Set up the agreement ›</a>@endif</div>
@endif
@if($canOp && $feesDue)
  <div class="alert alert-warn" id="feesDueAlert"><x-icon name="money"/> Management fees not yet accrued for {{ collect($feesDue)->map(fn ($p) => \Illuminate\Support\Carbon::parse($p.'-01')->format('M Y'))->implode(', ') }}. <a href="{{ route('owner.fees') }}">Accrue ›</a></div>
@endif
@if($canOp && $packsDue)
  <div class="alert alert-info" id="packsDueAlert"><x-icon name="book"/> Closed month(s) without an issued owner pack: {{ collect($packsDue)->map(fn ($p) => \Illuminate\Support\Carbon::parse($p.'-01')->format('M Y'))->implode(', ') }}. <a href="{{ route('owner.statements') }}">Owner packs ›</a></div>
@endif

<p class="small muted" style="margin:.1rem 0 .6rem">{{ $month->format('F Y') }}{{ $st['mtd'] ? ' (month to date, up to '.dmy(\App\Services\LedgerService::cutoff()).')' : '' }} · {{ $fyLabel }} year to date · figures from the general ledger, USALI 11th edition.</p>
<div class="stats tr-stats own-stats" id="ownerStats">
  <x-stat label="Total operating revenue" :value="money($c['tor'])" icon="trend" tone="blue" :sub="$st['has_budget'] ? 'budget '.money($cb['tor']).' · YTD '.money($y['tor']) : 'YTD '.money($y['tor'])" :href="route('owner.usali', ['period' => $month->format('Y-m')])"/>
  <x-stat label="Gross operating profit" :value="money($c['gop'])" icon="chart" :tone="$c['gop'] >= 0 ? 'green' : 'red'" :sub="'margin '.$pctOf($c['gop'], $c['tor']).' · YTD '.money($y['gop'])"/>
  <x-stat label="EBITDA" :value="money($c['ebitda'])" icon="layers" :tone="$c['ebitda'] >= 0 ? 'green' : 'red'" :sub="'YTD '.money($y['ebitda']).' · less reserve '.money($y['ebitda_rr'])"/>
  <x-stat label="RevPAR · ADR" :value="$kpiM['revpar'] !== null ? money($kpiM['revpar']) : '—'" icon="bed" tone="purple" :sub="'ADR '.($kpiM['adr'] !== null ? money($kpiM['adr']) : '—').' · occupancy '.($kpiM['occupancy'] ?? 0).'%'"/>
  <x-stat label="Management fees (fee year)" :value="money($feesYtd)" icon="money" tone="amber" :sub="$unpaid > 0 ? money($unpaid).' accrued, unpaid' : 'nothing unpaid'" :href="route('owner.fees')"/>
  <x-stat label="FF&E reserve" :value="money($reserve)" icon="wallet" :tone="$funded !== null && $funded + 0.01 < $reserve ? 'red' : 'green'" :sub="$funded === null ? 'no reserve bank account linked' : 'cash in reserve account '.money($funded)" :href="route('owner.reserve')"/>
</div>

<div class="grid g-2-1 mt-1">
<div>
  <div class="card" id="trendCard"><div class="card-head"><h3><x-icon name="chart"/> {{ $fyLabel }} – revenue and GOP by month</h3><span class="tiny muted"><span class="lg lg-tor"></span> revenue <span class="lg lg-gop"></span> GOP <span class="lg lg-bud"></span> budget GOP</span></div><div class="card-body">
    <svg viewBox="0 0 620 185" class="tr-chart" role="img" aria-label="Revenue and GOP by fiscal month">
      <line x1="0" y1="{{ $zero }}" x2="620" y2="{{ $zero }}" stroke="#cbd5e1"/>
      @foreach($trend as $i => $t) @php $x = ($i - 1) * 51 + 6; @endphp
        @if($t['tor'] !== null)<rect x="{{ $x }}" y="{{ min($yOf($t['tor']), $zero) }}" width="22" height="{{ max(1, abs($zero - $yOf($t['tor']))) }}" rx="2" fill="#93c5fd"><title>{{ $t['label'] }} revenue {{ money($t['tor']) }}</title></rect>@endif
        @if($t['gop'] !== null)<rect x="{{ $x + 22 }}" y="{{ min($yOf($t['gop']), $zero) }}" width="18" height="{{ max(1, abs($zero - $yOf($t['gop']))) }}" rx="2" fill="{{ $t['gop'] >= 0 ? '#16a34a' : '#dc2626' }}"><title>{{ $t['label'] }} GOP {{ money($t['gop']) }}</title></rect>@endif
        @if($t['bud_gop'] !== null)<circle cx="{{ $x + 31 }}" cy="{{ $yOf($t['bud_gop']) }}" r="3.5" fill="#fff" stroke="#7c3aed" stroke-width="2"><title>{{ $t['label'] }} budget GOP {{ money($t['bud_gop']) }}</title></circle>@endif
        <text x="{{ $x + 20 }}" y="181" font-size="10" text-anchor="middle" fill="{{ $i === $m ? '#0f172a' : '#64748b' }}" font-weight="{{ $i === $m ? 700 : 400 }}">{{ $t['label'] }}</text>
      @endforeach
    </svg>
  </div></div>

  <div class="card flush mt-1" id="kpiCard"><div class="card-head"><h3><x-icon name="star"/> Key performance indicators</h3><a class="small" href="{{ route('owner.usali', ['period' => $month->format('Y-m')]) }}#kpis">Full statement ›</a></div>
  <div class="card-body">@include('owner._kpis', ['kpis' => ['m_act' => $kpiM, 'm_bud' => $kpiMb, 'y_act' => $kpiY]])</div></div>
</div>

<div>
  <div class="card" id="packsCard"><div class="card-head"><h3><x-icon name="book"/> Owner packs</h3><a class="small" href="{{ route('owner.statements') }}">All ›</a></div><div class="card-body">
    @forelse($statements as $s)
      <div class="between-row"><a href="{{ route('owner.statements.show', $s->period) }}"><b>{{ $s->label() }}</b></a><span><span class="badge badge-{{ $OS::TONES[$s->status] }}">{{ $OS::STATUSES[$s->status] }}</span>@if($s->open_queries) <span class="badge badge-amber">{{ $s->open_queries }} quer{{ $s->open_queries > 1 ? 'ies' : 'y' }}</span>@endif</span></div>
    @empty<div class="tiny muted">No owner packs yet.</div>@endforelse
  </div></div>

  <div class="card mt-1" id="capexCard"><div class="card-head"><h3><x-icon name="tool"/> Capex awaiting the owner</h3><a class="small" href="{{ route('owner.reserve') }}">Projects ›</a></div><div class="card-body">
    @forelse($capexPending as $p)
      <div class="between-row"><span><a href="{{ route('owner.capex.show', $p) }}"><b>{{ $p->number }}</b></a> {{ $p->title }}<div class="tiny muted">{{ $CP::FUNDING[$p->funding] }} · by {{ $p->requester?->name }}</div></span><b>{{ money($p->budget) }}</b></div>
    @empty<div class="tiny muted">Nothing waiting for approval.</div>@endforelse
  </div></div>

  <div class="card mt-1" id="queriesCard"><div class="card-head"><h3><x-icon name="message"/> Owner queries</h3></div><div class="card-body">
    @forelse($openQueries as $q)
      <div class="oq-mini"><a href="{{ route('owner.statements.show', $q->statement->period) }}#q{{ $q->id }}"><b>{{ $q->statement->label() }}</b></a> <span class="badge badge-{{ $q->status === 'open' ? 'amber' : 'blue' }}">{{ \App\Models\OwnerQuery::STATUSES[$q->status] }}</span>
        <div class="small">{{ \Illuminate\Support\Str::limit($q->body, 110) }}</div><div class="tiny muted">{{ $q->raiser?->name }} · {{ $q->created_at->format('d M') }}</div></div>
    @empty<div class="tiny muted">No open queries.</div>@endforelse
  </div></div>
</div>
</div>
@endsection
