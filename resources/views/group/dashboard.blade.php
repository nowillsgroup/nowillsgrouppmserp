@extends('layouts.app')
@section('title', 'Group Dashboard')
@section('crumbs') {{ $group?->name ?? 'Hotel group' }} / Consolidated view @endsection
@section('actions')
  <span class="small muted">{{ $rows->count() }} properties · live</span>
  {{-- v10.12 the dashboard's figures, in the engine that prints, exports and schedules them: a group
       administrator reading this page should not have to go looking for the report afterwards. --}}
  @if(can_do('reports.view'))
    <a class="btn" href="{{ route('reports.show', 'group_operating_summary') }}"><x-icon name="download" size="sm"/> Consolidated report</a>
    <a class="btn btn-ghost" href="{{ route('reports.show', ['group_operating_summary', 'by' => 'brand']) }}"><x-icon name="layers" size="sm"/> Compare brands</a>
  @endif
@endsection
@section('content')
<div class="stats">
  <x-stat label="Group occupancy" :value="$totals['occ'].'%'" icon="bed" tone="blue" :sub="$totals['occupied'].' of '.$totals['rooms'].' sellable rooms'"/>
  <x-stat label="Revenue today" :value="money($totals['rev_today'])" icon="chart" tone="green" :sub="'Collections '.money($totals['collections'])"/>
  <x-stat label="Revenue MTD" :value="money($totals['rev_mtd'])" icon="wallet" tone="purple" :sub="'Rooms '.money($totals['room_rev_mtd'])"/>
  <x-stat label="In-house guests" :value="number_format($totals['in_house'])" icon="users" tone="teal" :sub="$totals['arrivals'].' arrivals · '.$totals['departures'].' departures'"/>
</div>

@if($closed->isNotEmpty())
  <div class="alert alert-warn"><x-icon name="alert" /><div>
    <b>{{ $closed->count() }} property{{ $closed->count() === 1 ? ' is' : 'ies are' }} licensed for no modules and {{ $closed->count() === 1 ? 'is' : 'are' }} not in these numbers.</b>
    @foreach($closed as $c){{ $c->name }}@if(! $loop->last), @endif @endforeach
    — nobody can post there, and counting its rooms would flatten the group's occupancy. Its history is untouched: licensing a module again puts it straight back in this view.
    @if(can_do('properties.view'))
      <div class="tiny muted" style="margin-top:4px"><a href="{{ route('properties.index') }}">Open the property register</a> to see what each property runs.</div>
    @endif
  </div></div>
@endif

<div class="card flush"><div class="card-head"><h3>Properties</h3><span class="small muted">Each property uses its own business date</span></div>
  <div class="card-body table-wrap"><table class="table">
    <thead><tr><th>Property</th><th>Business date</th><th class="center">Occupancy</th><th class="num">In-house</th><th class="num">Arr / Dep</th><th class="num">Revenue today</th><th class="num">ADR</th><th class="num">Revenue MTD</th><th class="center">Alerts</th><th></th></tr></thead>
    <tbody>
    @foreach($rows as $r)
      <tr>
        <td style="min-width:200px"><b>{{ $r['hotel']->name }}</b><div class="tiny muted">{{ $r['hotel']->code }} · {{ ucwords(str_replace('_',' ',$r['hotel']->property_type)) }}{{ $r['hotel']->city ? ' · '.$r['hotel']->city : '' }}</div></td>
        <td class="small">{{ $r['business_date']->format('d M Y') }}</td>
        <td style="min-width:150px"><div class="flex items-center gap-1"><div class="progress" style="flex:1"><span style="width:{{ $r['occ'] }}%"></span></div><b class="small">{{ $r['occ'] }}%</b></div><div class="tiny muted">{{ $r['occupied'] }}/{{ $r['rooms'] }} sellable</div></td>
        <td class="num">{{ $r['in_house'] }}</td>
        <td class="num">{{ $r['arrivals'] }} / {{ $r['departures'] }}</td>
        <td class="num bold">{{ money($r['rev_today']) }}</td>
        <td class="num">{{ money($r['adr']) }}</td>
        <td class="num">{{ money($r['rev_mtd']) }}</td>
        <td class="center">
          @if($r['ooo'])<span class="badge badge-red" title="Out of order rooms">{{ $r['ooo'] }} OOO</span>@endif
          @if($r['dirty'])<span class="badge badge-amber" title="Dirty rooms">{{ $r['dirty'] }} dirty</span>@endif
          @if($r['open_pos'])<span class="badge badge-slate" title="Open POS orders">{{ $r['open_pos'] }} POS</span>@endif
        </td>
        <td class="right">@if($r['hotel']->id !== hotel()->id)<form method="post" action="{{ route('switch.hotel') }}">@csrf<input type="hidden" name="hotel_id" value="{{ $r['hotel']->id }}"><button class="btn btn-sm">Open</button></form>@else<span class="badge badge-blue">Current</span>@endif</td>
      </tr>
    @endforeach
    </tbody>
    <tfoot><tr><td colspan="2" class="bold">Group total</td><td class="center bold">{{ $totals['occ'] }}%</td><td class="num bold">{{ $totals['in_house'] }}</td><td class="num bold">{{ $totals['arrivals'] }} / {{ $totals['departures'] }}</td><td class="num bold">{{ money($totals['rev_today']) }}</td><td></td><td class="num bold">{{ money($totals['rev_mtd']) }}</td><td colspan="2"></td></tr></tfoot>
  </table></div></div>

  <div class="card mt-2"><div class="card-head"><h3>Group revenue – last 14 days</h3></div><div class="card-body">
    {{-- One expression per bar, no @php temporaries: the same rule the preview renderer can read,
         so the chart in the preview is the chart the application draws. --}}
    <div class="bar-chart" style="max-width:900px">
      @for($i = 0; $i < 14; $i++)
        <div class="col"><div class="b" style="height: {{ round(max(2, ($trend[$i] ?? 0) * 100 / max(1, max($trend ?: [0]))), 1) }}%" data-v="{{ money($trend[$i] ?? 0) }}"></div><small>{{ $i === 13 ? 'Today' : '-'.(13 - $i) }}</small></div>
      @endfor
    </div>
    <p class="tiny muted mt-1">Net revenue (room + F&amp;B + other, before tax). Days are relative to each property's business date. Amounts assume a common currency.</p>
  </div></div>
@endsection
