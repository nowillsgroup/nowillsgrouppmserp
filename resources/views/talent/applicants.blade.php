@extends('layouts.app')
@section('title', 'Applicants')
@section('crumbs') Talent Management / Applicants @endsection
@section('content')
<div class="card"><div class="card-body"><form method="get" class="flex gap-1 wrap" style="align-items:center" id="talAppFilter">
  <select name="vacancy" class="input" style="width:auto" onchange="this.form.submit()" aria-label="Vacancy"><option value="">All open vacancies</option>@foreach($vacancies as $v)<option value="{{ $v->id }}" @selected($vac === $v->id)>{{ $v->title }} ({{ $v->number }})</option>@endforeach</select>
  <input name="q" class="input" style="width:260px" value="{{ request('q') }}" placeholder="Name, phone, e-mail, APL-…" aria-label="Search"><button class="btn">Search</button>
</form></div></div>
<div class="tal-board" id="talBoard">
@foreach(['applied', 'screening', 'interview', 'offer', 'hired', 'rejected'] as $st)
  <div class="tal-col"><div class="tal-col-head">{{ \App\Models\TalentApplicant::STAGES[$st] }} <span class="badge badge-slate">{{ ($cols[$st] ?? collect())->count() }}</span></div>
  @foreach(($cols[$st] ?? collect())->take(30) as $a)
    <a class="tal-card" href="{{ route('talent.applicant', $a) }}"><b>{{ $a->name }}</b><div class="tiny muted">{{ $a->vacancy->title }}</div>
      <div class="tiny">{{ $a->sourceLabel() }}{{ $a->experience_years !== null ? ' · '.rtrim(rtrim(number_format($a->experience_years, 1), '0'), '.').' y' : '' }}{{ $a->screen_rating ? ' · '.str_repeat('★', $a->screen_rating) : '' }}</div>
      @if($st === 'rejected' && $a->reject_reason)<div class="tiny muted">{{ \Illuminate\Support\Str::limit($a->reject_reason, 40) }}</div>@endif</a>
  @endforeach
  </div>
@endforeach
</div>
@if(($cols['withdrawn'] ?? collect())->count())<p class="small muted">{{ $cols['withdrawn']->count() }} withdrawn applicant(s) not shown.</p>@endif
@endsection
