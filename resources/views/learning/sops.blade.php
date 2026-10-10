@extends('layouts.app')
@section('title', 'SOP Library')
@section('crumbs') Learning & SOPs / SOP library @endsection
@php $S = \App\Models\SopDocument::class; @endphp
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'sop_acknowledgement']) }}"><x-icon name="download"/> Sign-off report</a>
  @if(can_do('learning.sop'))<a class="btn btn-primary" href="{{ route('learning.sop.create') }}" id="newSopBtn"><x-icon name="plus"/> New SOP</a>@endif
@endsection
@section('content')
<div class="card"><div class="card-body"><form method="get" class="flex gap-1 wrap" style="align-items:center" id="sopFilter">
  <input name="q" value="{{ request('q') }}" placeholder="Search code, title or text" aria-label="Search" id="sopSearch">
  <select name="department" aria-label="Department"><option value="">Any department</option><option value="all" @selected(request('department') === 'all')>Hotel-wide</option>@foreach($depts as $d)<option value="{{ $d->id }}" @selected(request('department') == $d->id)>{{ $d->name }}</option>@endforeach</select>
  <select name="category" aria-label="Category"><option value="">Any category</option>@foreach($S::CATEGORIES as $k => $l)<option value="{{ $k }}" @selected(request('category') === $k)>{{ $l }}</option>@endforeach</select>
  <select name="status" aria-label="Status"><option value="">Current (draft + published)</option>@foreach($S::STATUSES as $k => [$l])<option value="{{ $k }}" @selected(request('status') === $k)>{{ $l }}</option>@endforeach<option value="review" @selected(request('status') === 'review')>Review overdue</option></select>
  <button class="btn">Filter</button>
</form></div></div>
<div class="card flush mt-1"><div class="card-body table-wrap"><table class="table table-compact" id="sopsTable">
  <thead><tr><th>Code</th><th>Title</th><th>Department</th><th>Category</th><th>Version</th><th>Status</th><th>Review due</th><th style="width:14%">Signed</th></tr></thead>
  <tbody>@forelse($docs as $d) @php $a = $acks->get($d->id); @endphp
    <tr data-sop="{{ $d->code }}"><td><b>{{ $d->code }}</b></td><td><a href="{{ route('learning.sop', $d) }}"><b>{{ $d->title }}</b></a>@if($d->summary)<div class="tiny muted">{{ \Illuminate\Support\Str::limit($d->summary, 90) }}</div>@endif</td>
      <td class="small">{{ $d->department?->name ?? 'Hotel-wide' }}</td><td class="small">{{ $d->categoryLabel() }}</td>
      <td class="small">{{ $d->version ? 'v'.$d->version : '—' }}@if($d->has_draft && $d->version) <span class="badge badge-slate" title="Unpublished edits">draft v{{ $d->version + 1 }}</span>@endif</td>
      <td><span class="badge badge-{{ $d->statusColor() }}">{{ $d->statusLabel() }}</span></td>
      <td class="small {{ $d->reviewOverdue() ? 'text-red bold' : '' }}">{{ $d->review_due?->format('d M Y') ?? '—' }}</td>
      <td>@if($a && $a['required'])<div class="flex gap-1" style="align-items:center"><div class="cp-bar" style="flex:1"><span style="width:{{ $a['pct'] }}%;background:{{ $a['pct'] >= 100 ? '#16a34a' : '#f59e0b' }}"></span></div><span class="tiny">{{ $a['signed'] }}/{{ $a['required'] }}</span></div>@elseif($d->status === 'published')<span class="tiny muted">{{ $d->requires_ack ? 'nobody in scope' : 'no sign-off' }}</span>@endif</td></tr>
  @empty<tr><td colspan="8" class="empty">No SOPs match.</td></tr>@endforelse</tbody>
</table></div></div>
@endsection
