@extends('layouts.app')
@section('title', 'Packages & Meal Plans')
@section('crumbs') Packages & meals / Dashboard @endsection
@section('actions')
@if(can_do('packages.redeem'))<a class="btn btn-primary" href="{{ route('packages.desk') }}"><x-icon name="coffee"/> Meal desk</a>@endif
<a class="btn" href="{{ route('packages.register') }}"><x-icon name="book"/> Entitlements</a>
<a class="btn" href="{{ route('packages.catalogue') }}"><x-icon name="layers"/> Catalogue</a>
@endsection
@section('content')
<div class="stats">
  <x-stat label="Covers entitled today" :value="$stats['covers_today']" icon="coffee" tone="blue" :sub="$stats['served_today'].' served so far'"/>
  <x-stat label="Open package liability" :value="money($stats['liability'])" icon="wallet" tone="amber" sub="allocated, not yet used"/>
  <x-stat label="Allocated this month" :value="money($stats['allocated_mtd'])" icon="swap" tone="slate" sub="out of room revenue & add-ons"/>
  <x-stat label="Breakage this month" :value="money($stats['breakage_mtd'])" icon="trend" :tone="$stats['breakage_mtd'] > 0 ? 'red' : 'green'" :sub="'extras charged '.money($stats['extras_mtd'])"/>
</div>

<div class="card flush" id="forecastCard"><div class="card-head"><h3><x-icon name="calendar"/> Meal cover forecast – next 7 days</h3><span class="small muted">in-house + confirmed reservations, included & add-on packages</span></div>
<div class="card-body table-wrap"><table class="table pkg-forecast">
  <thead><tr><th>Service date</th>@foreach($pkgs as $p)<th class="num">{{ $p->name }}<div class="tiny muted">{{ \App\Models\Package::SERVICE_DAYS[$p->service_day] }}</div></th>@endforeach</tr></thead>
  <tbody>@foreach($forecast as $date => $cells)
    <tr class="{{ $date === bdate()->toDateString() ? 'row-today' : '' }}"><td class="nowrap small bold">{{ \Carbon\Carbon::parse($date)->format('D d M') }}@if($date === bdate()->toDateString()) <span class="badge badge-blue">today</span>@endif</td>
      @foreach($pkgs as $p) @php $c = $cells[$p->id] ?? null; @endphp
        <td class="num">@if($c)<b>{{ $c['covers'] }}</b> <span class="tiny muted">cov</span><div class="tiny muted">{{ $c['rooms'] }} rm · {{ $c['adults'] }}A{{ $c['children'] ? ' '.$c['children'].'C' : '' }}</div>@else<span class="muted">–</span>@endif</td>
      @endforeach</tr>
  @endforeach</tbody>
</table></div></div>

<div class="grid g-2">
<div class="card flush"><div class="card-head"><h3><x-icon name="chart"/> This month by package</h3></div>
<div class="card-body table-wrap"><table class="table">
  <thead><tr><th>Package</th><th class="num">Covers</th><th class="num">Allocated</th><th class="num">Used</th><th class="num">Breakage</th><th class="num">Take-up</th></tr></thead>
  <tbody>@forelse($byPkg as $pid => $r) @php $p = $allPkgs[$pid] ?? null; $take = $r->covers > 0 ? round($r->used * 100 / $r->covers) : 0; @endphp
    <tr><td class="bold">{{ $p?->name }} <span class="tiny muted">{{ $p?->code }}</span></td><td class="num">{{ (int) $r->used }}/{{ (int) $r->covers }}</td>
      <td class="num">{{ money($r->allocated, false) }}</td><td class="num">{{ money($r->consumed, false) }}</td><td class="num {{ $r->breakage > 0 ? 'text-red' : '' }}">{{ money($r->breakage, false) }}</td>
      <td class="num"><span class="badge badge-{{ $take >= 80 ? 'green' : ($take >= 50 ? 'amber' : 'red') }}">{{ $take }}%</span></td></tr>
  @empty <tr><td colspan="6"><x-empty text="No packages allocated this month." icon="coffee"/></td></tr> @endforelse</tbody>
</table></div></div>

<div class="card flush"><div class="card-head"><h3><x-icon name="receipt"/> Latest redemptions</h3>@if(can_do('reports.view'))<a class="small" href="{{ route('reports.show', 'package_consumption') }}">Consumption register →</a>@endif</div>
<div class="card-body table-wrap"><table class="table">
  <thead><tr><th>No.</th><th>Room / guest</th><th>Package</th><th class="num">Used</th><th class="num">Extra</th></tr></thead>
  <tbody>@forelse($recent as $r)
    <tr class="{{ $r->voided_at ? 'muted strike' : '' }}"><td class="small nowrap">{{ $r->redemption_no }}<div class="tiny muted">{{ $r->business_date->format('d M') }} · {{ $r->recorder?->name }}</div></td>
      <td class="small"><b>{{ $r->stay?->room?->room_no }}</b> {{ $r->stay?->guest?->full_name }}</td>
      <td class="small">{{ $r->package?->name }}</td>
      <td class="num small">{{ $r->package?->redeem_mode === 'amount' ? money($r->amount, false) : $r->covers.' cov' }}</td>
      <td class="num small">@if((float) $r->extra_amount > 0){{ money($r->extra_amount, false) }}<div class="tiny muted">{{ \App\Models\PackageRedemption::OVERAGE[$r->overage_mode] }}</div>@else – @endif</td></tr>
  @empty <tr><td colspan="5"><x-empty text="Nothing redeemed yet." icon="coffee"/></td></tr> @endforelse</tbody>
</table></div></div>
</div>
@endsection
