@extends('layouts.app')
@section('title', 'Training Records')
@section('crumbs') Learning & SOPs / Records @endsection
@php $R = \App\Models\TrainingRecord::class; @endphp
@section('actions')
  @if(can_do('learning.train'))<button class="btn btn-primary" data-modal="extModal" id="addRecordBtn"><x-icon name="plus"/> Add training record</button>@endif
  @if(can_do('learning.manage'))<button class="btn" data-modal="exemptModal" id="exemptBtn"><x-icon name="shield"/> Exempt</button>@endif
  <a class="btn" href="{{ route('reports.show', ['report' => 'training_records']) }}"><x-icon name="download"/> Report</a>
@endsection
@section('content')
<div class="card"><div class="card-body"><form method="get" class="flex gap-1 wrap" style="align-items:center" id="recFilter">
  <input name="q" value="{{ request('q') }}" placeholder="Name or certificate no." aria-label="Search">
  <select name="course" aria-label="Course"><option value="">Any course</option>@foreach($courses as $c)<option value="{{ $c->id }}" @selected(request('course') == $c->id)>{{ $c->name }}</option>@endforeach</select>
  <select name="department" aria-label="Department"><option value="">Any department</option>@foreach($depts as $d)<option value="{{ $d->id }}" @selected(request('department') == $d->id)>{{ $d->name }}</option>@endforeach</select>
  <select name="status" aria-label="Result"><option value="">Any result</option>@foreach($R::STATUSES as $k => [$l])@continue($k === 'enrolled')<option value="{{ $k }}" @selected(request('status') === $k)>{{ $l }}</option>@endforeach</select>
  <select name="source" aria-label="Source"><option value="">Any source</option>@foreach($R::SOURCES as $k => $l)<option value="{{ $k }}" @selected(request('source') === $k)>{{ $l }}</option>@endforeach</select>
  <select name="expiring" aria-label="Expiring"><option value="">Any expiry</option>@foreach([30, 60, 90, 180] as $n)<option value="{{ $n }}" @selected(request('expiring') == $n)>Expiring within {{ $n }} days</option>@endforeach</select>
  <button class="btn">Filter</button>@if(request()->query())<a class="btn btn-ghost" href="{{ route('learning.records') }}">Clear</a>@endif
</form></div></div>
<div class="card flush mt-1"><div class="card-body table-wrap"><table class="table table-compact" id="recordsTable">
  <thead><tr><th>Date</th><th>Employee</th><th>Course</th><th>Source</th><th>Result</th><th class="num">Score</th><th>Expires</th><th>Certificate</th><th class="num">Cost</th><th></th></tr></thead>
  <tbody>@forelse($records as $r)
    <tr data-record="{{ $r->id }}"><td class="small">{{ $r->completed_on?->format('d M Y') }}</td><td><a href="{{ route('learning.employee', $r->employee) }}"><b>{{ $r->employee->name }}</b></a><div class="tiny muted">{{ $r->employee->department?->name }}</div></td>
      <td class="small">{{ $r->course->name }}</td><td class="small">@if($r->session)<a href="{{ route('learning.session', $r->session) }}">{{ $r->session->number }}</a>@else{{ $R::SOURCES[$r->source] }}@endif{{ $r->provider ? ' · '.$r->provider : '' }}</td>
      <td><span class="badge badge-{{ $r->statusColor() }}">{{ $r->statusLabel() }}</span>@if($r->status === 'exempt' && $r->notes)<div class="tiny muted">{{ $r->notes }}</div>@endif</td><td class="num">{{ $r->score !== null ? rtrim(rtrim((string) $r->score, '0'), '.').'%' : '' }}</td>
      <td class="small {{ $r->expires_on && $r->expires_on->lt(bdate()) ? 'text-red' : '' }}">{{ $r->expires_on?->format('d M Y') ?? (in_array($r->status, ['passed', 'exempt']) ? 'none' : '') }}</td>
      <td class="small">@if($r->status === 'passed')<a href="{{ route('learning.certificate', $r) }}" target="_blank">{{ $r->certificate_no ?: 'view' }}</a>@endif</td><td class="num">{{ (float) $r->cost ? money($r->cost, false) : '' }}</td>
      <td class="right">@if(can_do('learning.manage') && $r->source !== 'session')<form method="post" action="{{ route('learning.record.delete', $r) }}" class="del-rec" onsubmit="const x = prompt('Why delete this record?'); if (!x) return false; this.reason.value = x;">@csrf<input type="hidden" name="reason"><button class="btn btn-sm btn-ghost" title="Delete"><x-icon name="trash"/></button></form>@endif</td></tr>
  @empty<tr><td colspan="10" class="empty">No training records match.</td></tr>@endforelse</tbody>
</table></div>{{ $records->links() }}</div>
@include('learning._record_modals', ['employees' => $employees])
@endsection
