@extends('layouts.app')
@section('title', 'Initiatives')
@section('crumbs') Business Development / Initiatives @endsection
@php $w = can_do('bd.edit') || can_do('bd.manage'); $I = \App\Models\BdInitiative::class; @endphp
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'bd_initiatives']) }}"><x-icon name="download"/> Portfolio report</a>
  @if($w)<button class="btn btn-primary" data-modal="initiativeModalNew" id="newInitiativeBtn"><x-icon name="plus"/> New initiative</button>@endif
@endsection
@section('content')
<div class="stats">
  <x-stat label="Open initiatives" :value="$pipeline['open']" icon="layers" tone="blue" :sub="$pipeline['awaiting'].' awaiting approval'"/>
  <x-stat label="Committed annual revenue" :value="money_short($pipeline['committed_revenue'])" icon="money" tone="green" sub="approved, implementing and live"/>
  <x-stat label="Committed investment" :value="money_short($pipeline['committed_investment'])" icon="wallet" tone="amber"/>
  <x-stat label="Ideas & cases in the funnel" :value="money_short($pipeline['pipeline_revenue'])" icon="trend" tone="purple" sub="annual revenue not yet approved"/>
</div>
<div class="tabs mt-1"><a class="{{ $view === 'board' ? 'active' : '' }}" href="{{ route('bd.initiatives') }}">Stage board</a><a class="{{ $view === 'list' ? 'active' : '' }}" href="{{ route('bd.initiatives', ['view' => 'list']) }}" id="listTab">Portfolio list</a></div>
@if($view === 'board')
<div class="sa-board" id="initBoard">@foreach($I::PIPELINE as $st)@php $col = $all->where('stage', $st); @endphp
  <div class="sa-lane" data-lane="{{ $st }}"><div class="sa-lane-head"><span class="badge badge-{{ $I::STAGES[$st][1] }}">{{ $I::STAGES[$st][0] }}</span><span class="tiny muted">{{ $col->count() }} · {{ money_short($col->sum('annual_revenue')) }}/yr</span></div><div class="sa-lane-sub"></div>
  @forelse($col as $i)@php $s = $stats[$i->id]; @endphp
    <a class="sa-card" href="{{ route('bd.initiative', $i) }}" data-initiative="{{ $i->number }}"><div class="between"><b class="small">{{ $i->number }}</b>@if($i->awaitingApproval())<span class="badge badge-amber">Approval</span>@elseif($s['milestones_overdue'])<span class="tiny text-red bold">{{ $s['milestones_overdue'] }} late</span>@endif</div>
      <div class="small bold">{{ \Illuminate\Support\Str::limit($i->name, 52) }}</div><div class="tiny muted">{{ $I::CATEGORIES[$i->category] }}{{ $i->target_market ? ' · '.$i->target_market : '' }}</div>
      <div class="between mt-05"><span class="small">{{ money_short($i->annual_revenue) }}/yr</span><span class="tiny muted">{{ $s['payback_months'] !== null ? 'payback '.$s['payback_months'].' m' : '' }}</span></div></a>
  @empty<div class="tiny muted" style="padding:.5rem">—</div>@endforelse
  </div>@endforeach</div>
@php $gone = $all->whereIn('stage', ['closed', 'rejected']); @endphp
@if($gone->count())<p class="small muted mt-1">{{ $gone->where('stage', 'closed')->count() }} closed and {{ $gone->where('stage', 'rejected')->count() }} rejected initiative(s) – see the <a href="{{ route('bd.initiatives', ['view' => 'list']) }}">portfolio list</a>.</p>@endif
@else
<div class="card flush"><div class="card-body table-wrap"><table class="table table-compact" id="initTable"><thead><tr><th>Initiative</th><th>Stage</th><th>Owner</th><th class="num">Investment</th><th class="num">Annual net</th><th class="num">Payback</th><th class="num">NPV</th><th class="num">Actual net</th><th>vs plan</th><th>Milestones</th></tr></thead><tbody>
  @forelse($all as $i)@php $s = $stats[$i->id]; @endphp<tr data-initiative="{{ $i->number }}"><td><a href="{{ route('bd.initiative', $i) }}"><b>{{ $i->number }}</b> {{ $i->name }}</a><div class="tiny muted">{{ $I::CATEGORIES[$i->category] }}</div></td>
    <td><span class="badge badge-{{ $i->stageTone() }}">{{ $i->stageLabel() }}</span></td><td class="small">{{ $i->owner?->name }}</td><td class="num">{{ number_format($i->investment) }}</td><td class="num">{{ number_format($s['net']) }}</td>
    <td class="num">{{ $s['payback_months'] !== null ? $s['payback_months'].' m' : '—' }}</td><td class="num {{ $s['npv'] >= 0 ? 'text-green' : 'text-red' }}">{{ number_format($s['npv']) }}</td>
    <td class="num">{{ $s['months'] ? number_format($s['actual_net']) : '' }}</td><td>@if($s['achievement'] !== null)<div class="dm-bar {{ $s['achievement'] < 80 ? 'over' : '' }}"><span style="width:{{ min(100, max(0, $s['achievement'])) }}%"></span></div><span class="tiny">{{ $s['achievement'] }}%</span>@endif</td>
    <td class="small">{{ $s['milestones_done'] }}/{{ $s['milestones'] }}@if($s['milestones_overdue'])<span class="text-red"> · {{ $s['milestones_overdue'] }} late</span>@endif</td></tr>
  @empty<tr><td colspan="10" class="muted">No initiatives yet.</td></tr>@endforelse
</tbody></table><p class="tiny muted" style="padding:.5rem .9rem">NPV at the {{ \App\Services\BusinessDevService::cfg()['discount_rate'] }}% hurdle rate over each initiative's horizon. vs plan = actual net result since going live against the business case pro rata.</p></div></div>
@endif
@if($w)@include('bd._initiative_modal', ['i' => null])@endif
@endsection
