@extends('layouts.app')
@section('title', 'QA Audits')
@section('crumbs') Quality Assurance / Audits @endsection
@php $A = \App\Models\QaAudit::class; $T = \App\Models\QaTemplate::class; $canAudit = \App\Services\QaService::canAudit(auth()->user()); $fmt = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 1), '0'), '.').'%'; @endphp
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'qa_audits']) }}"><x-icon name="download"/> Register</a>
  @if($canAudit)<button class="btn btn-primary" data-modal="qaScheduleModal" id="qaScheduleBtn"><x-icon name="calendar"/> Schedule audit</button>@endif
@endsection
@section('content')
<div class="tabs" id="auditTabs"><a class="{{ ! request('status') ? 'active' : '' }}" href="{{ route('qa.audits') }}">All</a>@foreach($A::STATUSES as $k => $s)<a class="{{ request('status') === $k ? 'active' : '' }}" href="{{ route('qa.audits', ['status' => $k]) }}">{{ $s[0] }} <span class="muted">{{ $counts[$k] ?? 0 }}</span></a>@endforeach</div>
<div class="card"><div class="card-body"><form method="get" class="flex gap-1 wrap" style="align-items:center">@if(request('status'))<input type="hidden" name="status" value="{{ request('status') }}">@endif
  <select name="type" aria-label="Type"><option value="">All types</option>@foreach($T::TYPES as $k => $l)<option value="{{ $k }}" @selected(request('type') === $k)>{{ $l }}</option>@endforeach</select>
  <select name="template" aria-label="Checklist"><option value="">All checklists</option>@foreach($templates as $t)<option value="{{ $t->id }}" @selected((int) request('template') === $t->id)>{{ $t->name }}</option>@endforeach</select>
  <select name="dept" aria-label="Department"><option value="">All departments</option>@foreach($departments as $d)<option value="{{ $d->id }}" @selected((int) request('dept') === $d->id)>{{ $d->name }}</option>@endforeach</select>
  <select name="result" aria-label="Result"><option value="">Any result</option><option value="passed" @selected(request('result') === 'passed')>Passed</option><option value="failed" @selected(request('result') === 'failed')>Failed</option></select>
  <input name="q" value="{{ request('q') }}" placeholder="QA no. or title" aria-label="Search"><button class="btn btn-sm">Filter</button>
</form></div></div>
<div class="card flush"><div class="card-body table-wrap"><table class="table table-compact" id="auditTable"><thead><tr><th>Audit</th><th>Checklist</th><th>Department</th><th>Auditor</th><th>Date</th><th>Status</th><th class="num">Score</th><th class="num">Findings</th></tr></thead><tbody>
  @forelse($audits as $a)<tr data-audit="{{ $a->number }}" class="{{ $a->isOverdue() ? 'row-alert' : ($a->passed === false ? 'row-red' : '') }}">
    <td class="nowrap"><a href="{{ route('qa.audit', $a) }}"><b>{{ $a->number }}</b></a><div class="tiny muted">{{ \Illuminate\Support\Str::limit($a->title, 40) }}</div></td>
    <td class="small">{{ $a->template?->name }}<div class="tiny muted">{{ $a->typeLabel() }}</div></td>
    <td class="small">{{ $a->department?->name ?? 'Whole hotel' }}</td><td class="small">{{ $a->auditorName() }}</td>
    <td class="small nowrap">{{ $a->completed_at ? $a->completed_at->format('d M Y') : $a->scheduled_for->format('d M Y') }}</td>
    <td><span class="badge badge-{{ $a->isOverdue() ? 'red' : $a->statusTone() }}">{{ $a->isOverdue() ? 'Late' : $a->statusLabel() }}</span></td>
    <td class="num nowrap">@if($a->status === 'completed')<b>{{ $fmt($a->score_pct) }}</b> <span class="badge badge-{{ $a->resultTone() }}">{{ $a->passed ? 'pass' : 'fail' }}</span>@else<span class="muted">—</span>@endif</td>
    <td class="num">@if($a->status === 'completed'){{ $a->fails }}@if($a->critical_fails) <span class="badge badge-red" title="critical">{{ $a->critical_fails }} crit</span>@endif @if($a->repeat_fails) <span class="badge badge-amber" title="repeat findings">{{ $a->repeat_fails }} rep</span>@endif @endif</td></tr>
  @empty<tr><td colspan="8" class="muted">No audits match.</td></tr>@endforelse
</tbody></table></div></div>
{{ $audits->links() }}
@if($canAudit)@include('qa._schedule_modal')@endif
@endsection
