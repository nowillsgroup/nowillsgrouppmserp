@extends('layouts.app')
@section('title', $e->name.' – training')
@section('crumbs') Learning & SOPs / Staff / {{ $e->name }} @endsection
@php $L = \App\Services\LearningService::STATES; @endphp
@section('actions')
  @if(can_do('learning.train'))<button class="btn" data-modal="extModal" id="addRecordBtn"><x-icon name="plus"/> Add training record</button>@endif
  @if(can_do('learning.manage'))<button class="btn" data-modal="exemptModal" id="exemptBtn"><x-icon name="shield"/> Exempt</button>@endif
@endsection
@section('content')
<div class="stats tr-stats" id="empStats">
  <x-stat :label="$e->designation ?: 'Employee'" :value="$e->name" icon="user" tone="blue" :sub="($e->department?->name ?? 'No department').' · '.$e->emp_code.($e->joining_date ? ' · joined '.$e->joining_date->format('d M Y') : '')"/>
  <x-stat label="Compliance" :value="$mine['pct'] === null ? '—' : $mine['pct'].'%'" icon="shield" :tone="($mine['pct'] ?? 100) >= 90 ? 'green' : 'amber'" :sub="$mine['required']->count().' required course(s)'"/>
  <x-stat label="SOPs to sign" :value="$mine['pending']->count()" icon="edit" :tone="$mine['pending']->count() ? 'amber' : 'green'" :sub="$mine['signed']->count().' signed'"/>
  <x-stat label="Booked sessions" :value="$mine['upcoming']->count()" icon="calendar" tone="purple" :sub="$mine['upcoming']->first() ? 'next '.$mine['upcoming']->first()->session->date->format('d M') : 'none'"/>
</div>
<div class="g-2 mt-1">
<div class="card flush" id="reqCard"><div class="card-head"><h3><x-icon name="shield"/> Required training</h3></div>
<div class="card-body table-wrap"><table class="table table-compact" id="reqTable">
  <thead><tr><th>Course</th><th>State</th><th>Certificate</th></tr></thead>
  <tbody>@forelse($mine['required'] as $r)
    <tr data-course="{{ $r['course']->code }}"><td><a href="{{ route('learning.course', $r['course']) }}"><b>{{ $r['course']->name }}</b></a><div class="tiny muted">{{ $r['course']->validityLabel() }}</div></td><td>@include('learning._cell', ['cell' => $r['cell']])</td>
      <td class="small">{{ $r['cell']['record']?->certificate_no ?? '—' }}{{ $r['cell']['session'] ? ' · booked '.$r['cell']['booked']->format('d M') : '' }}</td></tr>
  @empty<tr><td colspan="3" class="empty">No training is required for this role.</td></tr>@endforelse</tbody>
</table></div></div>
<div class="card flush" id="pendingSopCard"><div class="card-head"><h3><x-icon name="book"/> SOPs to sign</h3></div>
<div class="card-body">@forelse($mine['pending'] as $d)
  <div class="between-row"><a href="{{ route('learning.sop', $d) }}"><b>{{ $d->code }}</b> {{ $d->title }}</a><span class="tiny muted">v{{ $d->version }}</span></div>
@empty<div class="muted small">All signed.</div>@endforelse
@if($mine['signed']->count())<div class="tiny muted mt-1">Signed: @foreach($mine['signed'] as $a)<span class="chip-lite">{{ $a->document?->code }} v{{ $a->version }} · {{ $a->acknowledged_at->format('d M y') }}</span>@endforeach</div>@endif
</div></div>
</div>
<div class="card flush mt-1" id="historyCard"><div class="card-head"><h3><x-icon name="receipt"/> Training history</h3></div>
<div class="card-body table-wrap"><table class="table table-compact" id="historyTable">
  <thead><tr><th>Date</th><th>Course</th><th>Source</th><th>Result</th><th class="num">Score</th><th>Expires</th><th>Certificate</th><th class="num">Cost</th></tr></thead>
  <tbody>@foreach($mine['upcoming'] as $r)<tr class="row-due"><td class="small">{{ $r->session->date->format('d M Y') }}</td><td>{{ $r->course->name }}</td><td class="small"><a href="{{ route('learning.session', $r->session) }}">{{ $r->session->number }}</a></td><td><span class="badge badge-blue">Enrolled</span></td><td colspan="4"></td></tr>@endforeach
  @forelse($mine['records'] as $r)
    <tr><td class="small">{{ $r->completed_on?->format('d M Y') }}</td><td>{{ $r->course->name }}</td><td class="small">{{ $r->session ? $r->session->number : \App\Models\TrainingRecord::SOURCES[$r->source] }}{{ $r->provider ? ' · '.$r->provider : '' }}</td>
      <td><span class="badge badge-{{ $r->statusColor() }}">{{ $r->statusLabel() }}</span>@if($r->notes)<div class="tiny muted">{{ $r->notes }}</div>@endif</td><td class="num">{{ $r->score !== null ? rtrim(rtrim((string) $r->score, '0'), '.').'%' : '' }}</td>
      <td class="small">{{ $r->expires_on?->format('d M Y') ?? ($r->status === 'passed' ? 'no expiry' : '') }}</td>
      <td class="small">@if($r->status === 'passed')<a href="{{ route('learning.certificate', $r) }}" target="_blank">{{ $r->certificate_no ?: 'certificate' }}</a>@endif</td><td class="num">{{ (float) $r->cost ? money($r->cost, false) : '' }}</td></tr>
  @empty<tr><td colspan="8" class="empty">No training recorded yet.</td></tr>@endforelse</tbody>
</table></div></div>
@include('learning._record_modals', ['employees' => collect([$e]), 'fixedEmployee' => $e])
@endsection
