@extends('public.layout')
@section('title', 'Careers')
@section('content')
<div class="card"><div class="card-head"><h3><x-icon name="users"/> Careers at {{ $hotel->name }}</h3></div><div class="card-body">
  <p>{{ $intro }}</p>
  <div class="tal-jobs" id="careersJobs">
  @forelse($jobs as $v)<a class="tal-job" href="{{ route('careers.show', [$hotel->code, $v->id]) }}"><b>{{ $v->title }}</b><div class="small muted">{{ $v->department->name }} · {{ \App\Models\TalentVacancy::TYPES[$v->employment_type] }}</div>
    <div class="tiny">{{ $v->closing_on ? 'Apply by '.$v->closing_on->format('d M Y') : 'Open until filled' }}</div></a>
  @empty<p class="muted" id="careersNone">There are no vacancies at the moment – please check again soon.</p>@endforelse
  </div>
  <p class="tiny muted mt-1">{{ $hotel->name }} is an equal-opportunity employer. We do not charge any fee to apply.</p>
</div></div>
@endsection
