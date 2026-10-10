@extends('layouts.print')
@section('title', 'Business case '.$i->number)
@section('doctitle', 'Business case')
@section('docmeta') {{ $i->number }} · {{ $i->stageLabel() }} · {{ bdate()->format('d M Y') }} @endsection
@section('content')
@php $I = \App\Models\BdInitiative::class; @endphp
<h2 style="margin:0 0 .5rem">{{ $i->name }}</h2>
<table class="table" id="casePrint">
  <tr><th style="width:32%">Category</th><td>{{ $I::CATEGORIES[$i->category] }}{{ $i->target_market ? ' – '.$i->target_market : '' }}</td></tr>
  <tr><th>Owner</th><td>{{ $i->owner?->name ?? '—' }}</td></tr>
  @if($i->partner)<tr><th>Partnership</th><td>{{ $i->partner->number }} {{ $i->partner->name }}</td></tr>@endif
  <tr><th>Target launch</th><td>{{ $i->launch_date?->format('d M Y') ?? '—' }}</td></tr>
  <tr><th>Investment</th><td>{{ money($i->investment) }}</td></tr>
  <tr><th>Annual revenue / running cost</th><td>{{ money($i->annual_revenue) }} / {{ money($i->annual_cost) }}</td></tr>
  <tr><th>Annual net contribution</th><td><b>{{ money($s['net']) }}</b>{{ $s['margin'] !== null ? ' ('.$s['margin'].'% margin)' : '' }}</td></tr>
  <tr><th>Payback</th><td>{{ $s['payback_months'] !== null ? $s['payback_months'].' months' : 'not paid back' }}</td></tr>
  <tr><th>NPV ({{ $s['horizon'] }} years at {{ $s['rate'] }}%)</th><td><b>{{ money($s['npv']) }}</b></td></tr>
  <tr><th>ROI over the horizon</th><td>{{ $s['roi'] !== null ? $s['roi'].'%' : '—' }}</td></tr>
</table>
<h3>Opportunity &amp; approach</h3>
<p>{!! nl2br(e($i->description ?? '—')) !!}</p>
<h3>Implementation plan</h3>
<table class="table"><tr><th>Milestone</th><th>Due</th><th>Owner</th><th>Status</th></tr>
@forelse($i->milestones as $m)<tr><td>{{ $m->title }}</td><td>{{ $m->due_date->format('d M Y') }}</td><td>{{ $m->owner?->name }}</td><td>{{ $m->done_at ? 'Done '.$m->done_at->format('d M') : ($m->isOverdue() ? 'Overdue' : 'Open') }}</td></tr>@empty<tr><td colspan="4">—</td></tr>@endforelse
</table>
@if($i->decided_at)<p class="small"><b>Decision:</b> {{ $i->decider?->name }}, {{ $i->decided_at->format('d M Y') }}{{ $i->decision_note ? ' – '.$i->decision_note : '' }}</p>@endif
<table class="table" style="margin-top:2rem"><tr><td style="width:50%;height:4rem;vertical-align:bottom">Prepared by: ______________________</td><td style="vertical-align:bottom">Approved by: ______________________</td></tr></table>
@endsection
