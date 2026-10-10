@extends('layouts.app')
@section('title', 'Opportunities')
@section('crumbs') Sales / Opportunities @endsection
@php $O = \App\Models\SalesOpportunity::class; $seller = can_do('sales.edit') || can_do('sales.manage'); @endphp
@section('actions')
  <a class="btn" href="?{{ http_build_query(array_merge(request()->except('view', 'page'), $list ? [] : ['view' => 'list'])) }}" id="viewToggle"><x-icon name="{{ $list ? 'grid' : 'layers' }}"/> {{ $list ? 'Board' : 'List' }}</a>
  <a class="btn" href="{{ route('reports.show', ['report' => 'sales_pipeline']) }}"><x-icon name="download"/> Report</a>
  @if($seller)<button class="btn btn-primary" data-modal="oppModalNew" id="newOppBtn"><x-icon name="plus"/> New opportunity</button>@endif
@endsection
@section('content')
@include('sales._owner_toggle')
<div class="card"><div class="card-body"><form method="get" class="flex gap-1 wrap" style="align-items:center" id="oppFilter">
  @if($list)<input type="hidden" name="view" value="list">@endif @if(request('owner'))<input type="hidden" name="owner" value="{{ request('owner') }}">@endif
  <input name="q" value="{{ request('q') }}" placeholder="Number, name or account" aria-label="Search">
  <select name="type" aria-label="Type"><option value="">Any type</option>@foreach($O::TYPES as $k => $l)<option value="{{ $k }}" @selected(request('type') === $k)>{{ $l }}</option>@endforeach</select>
  @if($list)<select name="stage" aria-label="Stage"><option value="">Any stage</option><option value="open" @selected(request('stage') === 'open')>All open</option>@foreach($O::STAGES as $k => [$l])<option value="{{ $k }}" @selected(request('stage') === $k)>{{ $l }}</option>@endforeach</select>
  <label class="small">Closing <input type="date" name="from" value="{{ request('from') }}"></label><label class="small">to <input type="date" name="to" value="{{ request('to') }}"></label>@endif
  <button class="btn">Filter</button>
</form></div></div>
@if($list)
<div class="card flush mt-1"><div class="card-body table-wrap"><table class="table table-compact" id="oppList">
  <thead><tr><th>No.</th><th>Opportunity</th><th>Account</th><th>Stage</th><th>Owner</th><th>Close</th><th class="num">RN</th><th class="num">Value</th><th class="num">Prob.</th><th class="num">Weighted</th></tr></thead>
  <tbody>@forelse($opps as $o)
    <tr data-opp="{{ $o->number }}"><td><a href="{{ route('sales.opportunity', $o) }}"><b>{{ $o->number }}</b></a></td><td>{{ $o->name }}<div class="tiny muted">{{ $o->typeLabel() }}</div></td><td class="small"><a href="{{ route('sales.account', $o->company) }}">{{ $o->company->name }}</a></td>
      <td><span class="badge badge-{{ $o->stageColor() }}">{{ $o->stageLabel() }}</span>@if($o->stage === 'lost')<div class="tiny muted">{{ $O::LOST_REASONS[$o->lost_reason] ?? '' }}</div>@endif</td><td class="small">{{ $o->owner?->name }}</td>
      <td class="small nowrap {{ $o->isOpen() && $o->expected_close->lt(bdate()->copy()->startOfDay()) ? 'text-red bold' : '' }}">{{ ($o->closed_on ?? $o->expected_close)->format('d M Y') }}</td><td class="num">{{ $o->room_nights ?: '·' }}</td><td class="num">{{ money($o->total()) }}</td><td class="num">{{ $o->probability }}%</td><td class="num">{{ money($o->weighted()) }}</td></tr>
  @empty<tr><td colspan="10" class="empty">No opportunities match.</td></tr>@endforelse</tbody>
</table></div></div>
{{ $opps->links() }}
@else
<div class="sa-board mt-1" id="oppBoard">@foreach(array_merge($O::OPEN, ['won', 'lost']) as $s)@php $col = $opps->get($s, collect()); @endphp
  <div class="sa-lane" data-lane="{{ $s }}"><div class="sa-lane-head"><span class="badge badge-{{ $O::STAGES[$s][1] }}">{{ $O::STAGES[$s][0] }}</span><span class="tiny muted">{{ $col->count() }} · {{ money($col->sum(fn ($o) => $o->total())) }}</span></div>
    @if(in_array($s, ['won', 'lost']))<div class="tiny muted sa-lane-sub">last 60 days</div>@else<div class="tiny muted sa-lane-sub">{{ $cfg['prob_'.$s] }}% · weighted {{ money($pipe[$s]['weighted']) }}</div>@endif
    @forelse($col as $o)
    <a class="sa-card" href="{{ route('sales.opportunity', $o) }}" data-opp="{{ $o->number }}"><div class="between"><b class="small">{{ $o->number }}</b><span class="tiny {{ $o->isOpen() && $o->expected_close->lt(bdate()->copy()->startOfDay()) ? 'text-red bold' : 'muted' }}">{{ ($o->closed_on ?? $o->expected_close)->format('d M') }}</span></div>
      <div class="small bold">{{ \Illuminate\Support\Str::limit($o->name, 48) }}</div><div class="tiny muted">{{ $o->company->name }}</div>
      <div class="between mt-05"><span class="small">{{ money($o->total()) }}</span><span class="tiny muted">{{ $o->owner ? \Illuminate\Support\Str::of($o->owner->name)->explode(' ')->map(fn ($w) => mb_substr($w, 0, 1))->implode('') : '' }}</span></div></a>
    @empty<div class="tiny muted pad">—</div>@endforelse
  </div>
@endforeach</div>
@endif
@if($seller)@include('sales._opp_modal', ['o' => null])@endif
@endsection
