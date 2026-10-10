@extends('layouts.app')
@section('title', 'Courses & Requirements')
@section('crumbs') Learning & SOPs / Courses @endsection
@php $manage = can_do('learning.manage'); @endphp
@section('actions')
  @if($manage)<button class="btn btn-primary" data-modal="courseModalNew" id="newCourseBtn"><x-icon name="plus"/> New course</button>@endif
@endsection
@section('content')
<div class="card flush"><div class="card-body table-wrap"><table class="table table-compact" id="coursesTable">
  <thead><tr><th>Code</th><th>Course</th><th>Category</th><th>Delivery</th><th class="num">Hours</th><th>Validity</th><th>Pass</th><th>Required for</th><th class="num">Staff</th><th class="num">Held</th><th class="num">Booked</th></tr></thead>
  <tbody>@forelse($courses as $c)
    @php $held = 0; $need = 0; foreach ($m['cells'] as $cells) { if (isset($cells[$c->id])) { $need++; if (in_array($cells[$c->id]['state'], ['current', 'expiring'])) $held++; } } @endphp
    <tr data-course="{{ $c->code }}" class="{{ $c->is_active ? '' : 'muted' }}"><td><b>{{ $c->code }}</b></td><td><a href="{{ route('learning.course', $c) }}"><b>{{ $c->name }}</b></a><div class="tiny muted">{{ $c->provider }}{{ $c->sop ? ' · SOP '.$c->sop->code : '' }}{{ $c->fs_type ? ' · renews food-hygiene certificate' : '' }}</div></td>
      <td class="small">{{ $c->categoryLabel() }}</td><td class="small">{{ \App\Models\TrainingCourse::DELIVERY[$c->delivery] ?? $c->delivery }}</td><td class="num">{{ rtrim(rtrim((string) $c->duration_hours, '0'), '.') }}</td>
      <td class="small">{{ $c->validityLabel() }}</td><td class="small">{{ $c->pass_mark ? $c->pass_mark.'%' : 'attend' }}</td>
      <td class="small">@forelse($c->requirements as $r)<span class="chip-lite">{{ $r->label() }}</span>@empty<span class="muted">optional</span>@endforelse</td>
      <td class="num">{{ $need ?: '' }}</td><td class="num {{ $need && $held < $need ? 'text-red' : '' }}">{{ $need ? $held : '' }}</td><td class="num">{{ $c->sessions_count ?: '' }}</td></tr>
  @empty<tr><td colspan="11" class="empty">No courses yet.</td></tr>@endforelse</tbody>
</table></div></div>
@if($manage)@include('learning._course_modal', ['c' => null])@endif
@endsection
