@extends('layouts.app')
@section('title', 'Marketing Calendar')
@section('crumbs') Sales & Marketing / Marketing calendar @endsection
@php $M = \App\Models\MarketingEvent::class; $mk = can_do('sales.marketing') || can_do('sales.manage'); @endphp
@section('actions')
  <a class="btn" href="{{ route('sales.budget', ['year' => $year]) }}"><x-icon name="wallet"/> Budget</a>
  <a class="btn" href="{{ route('reports.show', ['report' => 'marketing_roi']) }}"><x-icon name="download"/> ROI report</a>
  @if($mk)<button class="btn btn-primary" data-modal="meventModalNew" id="newEventBtn"><x-icon name="plus"/> New event</button>@endif
@endsection
@section('content')
<div class="stats" id="mkStats">
  <x-stat label="Budget {{ $year }}" :value="money_short($totals['budget'])" icon="wallet" tone="blue" :sub="$events->where('status', '!=', 'cancelled')->count().' events'"/>
  <x-stat label="Spent" :value="money_short($totals['spent'])" icon="money" tone="amber" sub="closed & cancelled events"/>
  <x-stat label="Leads generated" :value="number_format($totals['leads'])" icon="users" tone="purple" :sub="$totals['upcoming'].' events still to come'"/>
  <x-stat label="Won from events" :value="money_short($totals['won'])" icon="star" tone="green" sub="opportunities attributed to an event"/>
</div>
<div class="card flush mt-1" id="calCard"><div class="card-head"><h3><x-icon name="calendar"/> {{ $month->format('F Y') }}</h3>
  <div class="flex gap-05"><a class="btn btn-sm" href="?month={{ $month->copy()->subMonthNoOverflow()->format('Y-m') }}" id="calPrev">←</a><a class="btn btn-sm" href="?month={{ bdate()->format('Y-m') }}">Today</a><a class="btn btn-sm" href="?month={{ $month->copy()->addMonthNoOverflow()->format('Y-m') }}" id="calNext">→</a></div></div>
<div class="card-body pad"><div class="mk-cal" id="mkCal">@foreach(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d)<div class="mk-head">{{ $d }}</div>@endforeach
  @foreach($days as $d)<div class="mk-day {{ $d['in'] ? '' : 'out' }} {{ $d['date']->isSameDay(bdate()) ? 'today' : '' }}" data-date="{{ $d['date']->toDateString() }}"><div class="tiny muted">{{ $d['date']->day }}</div>
    @foreach($d['events'] as $e)<a class="mk-ev st-{{ $e->status }}" href="{{ route('sales.mevent', $e) }}" title="{{ $e->number }} {{ $e->name }} ({{ $e->statusLabel() }})">{{ $e->name }}</a>@endforeach</div>@endforeach
</div></div></div>

<div class="card flush mt-1"><div class="card-head"><h3><x-icon name="layers"/> {{ $year }} marketing plan</h3>
  <form method="get" class="flex gap-05"><input type="hidden" name="month" value="{{ $month->format('Y-m') }}"><select name="year" aria-label="Year">@foreach(range(bdate()->year - 2, bdate()->year + 1) as $y)<option @selected($y === $year)>{{ $y }}</option>@endforeach</select>
    <select name="type" aria-label="Type"><option value="">Any type</option>@foreach($M::TYPES as $k => [$l])<option value="{{ $k }}" @selected(request('type') === $k)>{{ $l }}</option>@endforeach</select>
    <select name="status" aria-label="Status"><option value="">Any status</option>@foreach($M::STATUSES as $k => [$l])<option value="{{ $k }}" @selected(request('status') === $k)>{{ $l }}</option>@endforeach</select><button class="btn btn-sm">Filter</button></form></div>
<div class="card-body table-wrap"><table class="table table-compact" id="eventsTable">
  <thead><tr><th>Event</th><th>Dates</th><th>Segment / market</th><th>Status</th><th class="num">Budget</th><th class="num">Actual</th><th class="num">Leads</th><th class="num">Opps</th><th class="num">Won</th><th class="num">ROI</th></tr></thead>
  <tbody>@forelse($events as $e)@php $x = $roi[$e->id]; @endphp
    <tr data-event="{{ $e->number }}"><td><a href="{{ route('sales.mevent', $e) }}"><b>{{ $e->number }}</b> {{ $e->name }}</a><div class="tiny muted"><x-icon :name="$e->typeIcon()"/> {{ $e->typeLabel() }} · {{ $e->owner?->name }}</div></td>
      <td class="small nowrap">{{ $e->start_date->format('d M') }}{{ $e->end_date->ne($e->start_date) ? ' – '.$e->end_date->format('d M') : '' }}</td>
      <td class="small">{{ $M::SEGMENTS[$e->segment] ?? '—' }}<div class="tiny muted">{{ $M::MARKETS[$e->market] ?? '' }}</div></td>
      <td><span class="badge badge-{{ $e->statusColor() }}">{{ $e->statusLabel() }}</span></td><td class="num">{{ money($e->budget) }}</td>
      <td class="num {{ $e->status === 'done' && (float) $e->actual_cost > (float) $e->budget ? 'text-red bold' : '' }}">{{ in_array($e->status, ['done', 'cancelled']) ? money($e->actual_cost) : '·' }}</td>
      <td class="num">{{ $e->status === 'done' ? $e->leads.($e->target_leads ? ' / '.$e->target_leads : '') : ($e->target_leads ? '/ '.$e->target_leads : '·') }}</td><td class="num">{{ $x['opps'] ?: '·' }}</td><td class="num">{{ $x['won_value'] ? money($x['won_value']) : '·' }}</td>
      <td class="num {{ ($x['roi'] ?? 0) < 0 ? 'text-red' : 'text-green' }}">{{ $x['roi'] !== null ? number_format($x['roi'], 0).'%' : '·' }}</td></tr>
  @empty<tr><td colspan="10" class="empty">No marketing events in {{ $year }}.</td></tr>@endforelse</tbody>
</table></div></div>
@if($mk)@include('sales._mevent_modal', ['e' => null])@endif
@endsection
