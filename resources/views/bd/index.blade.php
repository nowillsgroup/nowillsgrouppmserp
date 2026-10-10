@extends('layouts.app')
@section('title', 'Business Development')
@section('crumbs') Business Development / Dashboard @endsection
@php $w = can_do('bd.edit') || can_do('bd.manage'); $ix = fn ($v) => $v === null ? '—' : '<span class="'.($v >= 100 ? 'text-green' : 'text-red').' bold">'.number_format($v, 1).'</span>'; @endphp
@section('actions')
  <a class="btn" href="{{ route('bd.compset') }}"><x-icon name="chart"/> Competitive set</a>
  @if($w)<a class="btn btn-primary" href="{{ route('bd.initiatives') }}" id="initiativesLink"><x-icon name="layers"/> Initiatives</a>@endif
@endsection
@section('content')
@php $a = $alerts; $any = array_sum($a); @endphp
@if($any)
<div class="alert alert-warn" id="bdAlerts"><x-icon name="alert"/>
  @if($a['approvals'])<a href="{{ route('bd.initiatives', ['view' => 'list']) }}"><b>{{ $a['approvals'] }}</b> business case(s) awaiting a decision</a> · @endif
  @if($a['milestones'])<span class="text-red"><b>{{ $a['milestones'] }}</b> milestone(s) overdue</span> · @endif
  @if($a['lapsed'])<a class="text-red" href="{{ route('bd.partners', ['status' => 'active']) }}"><b>{{ $a['lapsed'] }}</b> partnership(s) past their end date</a> · @endif
  @if($a['expiring'])<a href="{{ route('bd.partners', ['status' => 'active']) }}"><b>{{ $a['expiring'] }}</b> partnership(s) up for renewal</a> · @endif
  @if($a['reviews'])<a href="{{ route('bd.partners') }}"><b>{{ $a['reviews'] }}</b> partnership review(s) due</a> · @endif
  @if($a['shops_stale'])<a href="{{ route('bd.compset') }}">rate shop overdue{{ $lastShop ? ' (last '.$lastShop->format('d M').')' : '' }}</a> · @endif
  @if($a['benchmark_missing'])<a href="{{ route('bd.compset') }}#benchCard">comp-set benchmark for {{ bdate()->copy()->startOfMonth()->subMonthNoOverflow()->format('M Y') }} missing</a>@endif
</div>
@endif
<div class="stats" id="bdStats">
  <x-stat label="RGI (RevPAR index) {{ $idx['month']->format('M') }}" :value="$idx['rgi'] !== null ? number_format($idx['rgi'], 1) : '—'" icon="trend" :tone="$idx['rgi'] === null ? 'slate' : ($idx['rgi'] >= 100 ? 'green' : 'red')" :sub="$idx['rgi'] !== null ? 'MPI '.$idx['mpi'].' · ARI '.$idx['ari'].' (100 = fair share)' : 'enter the comp-set benchmark'" :href="route('bd.compset').'#benchCard'"/>
  <x-stat label="Rate position – next 14 days" :value="$position['index'] !== null ? number_format($position['index'], 1) : '—'" icon="chart" tone="purple" :sub="$position['index'] !== null ? 'us '.money_short($position['own']).' vs comp '.money_short($position['comp']).' · '.$position['shops'].' shops' : 'no rate shops yet'" :href="route('bd.compset')"/>
  <x-stat label="Partnership production YTD" :value="money_short($partners['revenue'])" icon="users" tone="green" :sub="$partners['active'].' active · '.$partners['bookings'].' bookings · '.number_format($partners['nights']).' nights'" :href="route('bd.partners')"/>
  <x-stat label="Committed initiatives (annual revenue)" :value="money_short($pipeline['committed_revenue'])" icon="layers" tone="amber" :sub="$pipeline['open'].' open · pipeline '.money_short($pipeline['pipeline_revenue']).' · invest '.money_short($pipeline['committed_investment'])" :href="route('bd.initiatives')"/>
  @php $top = $mix->first(); @endphp
  <x-stat label="Top source market YTD" :value="$top['label'] ?? '—'" icon="globe" tone="blue" :sub="$top ? $top['share'].'% of room revenue'.($top['growth'] !== null ? ' · '.($top['growth'] >= 0 ? '+' : '').$top['growth'].'% vs LY' : '') : 'no stays yet'" :href="route('bd.market')"/>
</div>

<div class="g-2-1 mt-1">
  <div>
    <div class="card flush" id="benchTrend"><div class="card-head"><h3><x-icon name="trend"/> Comp-set benchmark – last 6 months</h3><a class="btn btn-sm btn-ghost" href="{{ route('reports.show', ['report' => 'bd_benchmark']) }}">Report →</a></div>
      <div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>Month</th><th class="num">Occ. us</th><th class="num">Occ. comp</th><th class="num">ADR us</th><th class="num">ADR comp</th><th class="num">RevPAR us</th><th class="num">RevPAR comp</th><th class="num">MPI</th><th class="num">ARI</th><th class="num">RGI</th></tr></thead><tbody>
      @foreach($series as $s)<tr><td class="nowrap">{{ $s['month']->format('M Y') }}</td><td class="num">{{ number_format($s['own']['occupancy'], 1) }}%</td><td class="num">{{ $s['comp'] ? number_format($s['comp']['occupancy'], 1).'%' : '—' }}</td>
        <td class="num">{{ number_format($s['own']['adr']) }}</td><td class="num">{{ $s['comp'] ? number_format($s['comp']['adr']) : '—' }}</td><td class="num">{{ number_format($s['own']['revpar']) }}</td><td class="num">{{ $s['comp'] ? number_format($s['comp']['revpar']) : '—' }}</td>
        <td class="num">{!! $ix($s['mpi']) !!}</td><td class="num">{!! $ix($s['ari']) !!}</td><td class="num">{!! $ix($s['rgi']) !!}</td></tr>@endforeach
      </tbody></table><p class="tiny muted" style="padding:.5rem .9rem">Our figures come from the report’s “My Property” line when entered, otherwise from the PMS (closed days, ledger room revenue); comp-set figures from the monthly STR / comp-set exchange. Index = ours ÷ comp set × 100.</p></div></div>

    <div class="card flush mt-1" id="segmentsCard"><div class="card-head"><h3><x-icon name="layers"/> Segment mix – {{ bdate()->year }} to date</h3><a class="btn btn-sm btn-ghost" href="{{ route('bd.market', ['by' => 'segment']) }}">Market mix →</a></div>
      <div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>Segment</th><th class="num">Bookings</th><th class="num">Nights</th><th class="num">ADR</th><th>Share of room revenue</th><th class="num">vs LY</th></tr></thead><tbody>
      @forelse($segments as $s)<tr><td>{{ $s['label'] }}</td><td class="num">{{ $s['bookings'] }}</td><td class="num">{{ number_format($s['nights']) }}</td><td class="num">{{ $s['adr'] !== null ? number_format($s['adr']) : '—' }}</td>
        <td><div class="dm-bar"><span style="width:{{ min(100, $s['share']) }}%"></span></div><span class="tiny">{{ $s['share'] }}% · {{ money_short($s['revenue']) }}</span></td>
        <td class="num {{ $s['growth'] === null ? 'muted' : ($s['growth'] >= 0 ? 'text-green' : 'text-red') }}">{{ $s['growth'] === null ? 'new' : ($s['growth'] >= 0 ? '+' : '').$s['growth'].'%' }}</td></tr>
      @empty<tr><td colspan="6" class="muted">No stays yet this year.</td></tr>@endforelse
      </tbody></table></div></div>
  </div>
  <div>
    <div class="card flush" id="approvalsCard"><div class="card-head"><h3><x-icon name="check"/> Business cases to decide</h3></div><div class="card-body">
      @forelse($awaiting as $i)<a class="dm-item" href="{{ route('bd.initiative', $i) }}"><b>{{ $i->number }}</b> {{ $i->name }}<div class="tiny muted">{{ $i->owner?->name }} · {{ money_short($i->annual_revenue) }} / yr · invest {{ money_short($i->investment) }} · submitted {{ $i->submitted_at->format('d M') }}</div></a>
      @empty<p class="muted small" style="padding:.75rem .9rem">Nothing awaiting approval.</p>@endforelse
    </div></div>
    <div class="card flush mt-1" id="milestonesCard"><div class="card-head"><h3><x-icon name="clock"/> Milestones due (14 days)</h3></div><div class="card-body">
      @forelse($milestones as $m)<a class="dm-item" href="{{ route('bd.initiative', $m->initiative_id) }}"><span class="{{ $m->isOverdue() ? 'text-red bold' : '' }}">{{ $m->due_date->format('d M') }}</span> · {{ $m->title }}<div class="tiny muted">{{ $m->initiative->number }} {{ \Illuminate\Support\Str::limit($m->initiative->name, 40) }}{{ $m->owner ? ' · '.$m->owner->name : '' }}</div></a>
      @empty<p class="muted small" style="padding:.75rem .9rem">No milestones due.</p>@endforelse
    </div></div>
    <div class="card flush mt-1" id="marketsCard"><div class="card-head"><h3><x-icon name="globe"/> Source markets YTD</h3><a class="btn btn-sm btn-ghost" href="{{ route('bd.market') }}">All →</a></div><div class="card-body table-wrap"><table class="table table-compact"><tbody>
      @forelse($mix as $m)<tr><td>{{ $m['label'] }}</td><td class="num">{{ number_format($m['nights']) }} n</td><td class="num">{{ $m['share'] }}%</td><td class="num tiny {{ $m['growth'] === null ? 'muted' : ($m['growth'] >= 0 ? 'text-green' : 'text-red') }}">{{ $m['growth'] === null ? 'new' : ($m['growth'] >= 0 ? '+' : '').$m['growth'].'%' }}</td></tr>
      @empty<tr><td class="muted">No stays yet.</td></tr>@endforelse
    </tbody></table></div></div>
    @if($competitorCount === 0)<div class="alert alert-info mt-1"><x-icon name="building"/> Set up the competitive set (4–8 hotels your guests compare you with) to start rate shopping and benchmarking.</div>@endif
  </div>
</div>
@endsection
