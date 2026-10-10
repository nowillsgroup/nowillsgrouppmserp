@extends('layouts.app')
@section('title', 'Vacancies')
@section('crumbs') Talent Management / Vacancies @endsection
@section('actions')
  @if(auth()->user()->hasPermission('talent.recruit'))<button class="btn btn-primary" data-modal="talVacModal" id="talVacNew"><x-icon name="plus"/> Request a vacancy</button>@endif
@endsection
@section('content')
<div class="tabs" id="talVacTabs">
  @foreach(['active' => 'Active', 'pending' => 'Awaiting approval', 'open' => 'Open', 'filled' => 'Filled', 'cancelled' => 'Cancelled', 'all' => 'All'] as $k => $l)
  <a href="{{ route('talent.vacancies', ['status' => $k]) }}" class="{{ $status === $k ? 'active' : '' }}">{{ $l }}</a>@endforeach
</div>
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="talVacTable"><thead><tr><th>Vacancy</th><th>Department</th><th>Type</th><th>Salary range</th><th class="num">Posts</th><th class="num">Applicants</th><th>Requested by</th><th>Status</th></tr></thead><tbody>
@forelse($rows as $v)<tr class="{{ $v->status === 'pending' ? 'row-amber' : '' }}"><td><a href="{{ route('talent.vacancy', $v) }}"><b>{{ $v->title }}</b></a><div class="tiny muted">{{ $v->number }} · {{ \App\Models\TalentVacancy::REASONS[$v->reason] }}</div></td>
  <td class="small">{{ $v->department->name }}</td><td class="small">{{ $v->typeLabel() }}</td><td class="small nowrap">{{ $v->range() }}</td>
  <td class="num">{{ $v->filled }}/{{ $v->headcount }}</td><td class="num">{{ $v->pipeline_count }} <span class="tiny muted">/ {{ $v->applicants_count }}</span></td>
  <td class="small">{{ $v->requester?->name }}</td><td>@include('talent._badge', ['s' => $v->status, 'l' => $v->statusLabel()])</td></tr>
@empty<tr><td colspan="8" class="muted">No vacancies.</td></tr>@endforelse
</tbody></table></div></div>
<div class="modal" id="talVacModal"><div class="modal-box lg"><form method="post" action="{{ route('talent.vacancies.store') }}" data-reset id="talVacForm">@csrf
  <div class="modal-head"><h3>Request a vacancy</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body"><div class="form-grid">@include('talent._vacancy_fields')</div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save draft</button></div>
</form></div></div>
<script>if (location.hash === '#new') document.getElementById('talVacNew')?.click();</script>
@endsection
