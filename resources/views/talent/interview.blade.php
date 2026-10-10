@extends('layouts.app')
@section('title', 'Interview – '.$a->name)
@section('crumbs') Talent Management / Interview / {{ $a->name }} @endsection
@section('actions')@if($a->cv_path)<a class="btn" href="{{ route('talent.applicant.cv', $a) }}"><x-icon name="download"/> CV</a>@endif @endsection
@section('content')
<div class="g-2-1">
  <div>
    <div class="card"><div class="card-head"><h3>{{ $a->name }} – {{ $a->vacancy->title }}</h3>@include('talent._badge', ['s' => $i->status, 'l' => \App\Models\TalentInterview::STATUSES[$i->status]])</div><div class="card-body">
      <div class="kv-grid"><div><span>Round</span><b>#{{ $i->round }} · {{ $i->kindLabel() }}</b></div><div><span>When</span><b>{{ $i->scheduled_at->format('D d M Y H:i') }}</b></div>
        <div><span>Where</span><b>{{ $i->location ?? '—' }}</b></div><div><span>Panel</span><b>{{ $i->panelUsers()->pluck('name')->implode(', ') }}</b></div>
        <div><span>Experience</span><b>{{ $a->experience_years !== null ? $a->experience_years.' years' : '—' }}</b></div><div><span>Current employer</span><b>{{ $a->current_employer ?? '—' }}</b></div></div>
      @if($a->cover_note)<p class="small pre-line mt-1">{{ $a->cover_note }}</p>@endif
    </div></div>
    @if($onPanel)
    <form method="post" action="{{ route('talent.interview.score', $i) }}" class="card" id="talScoreForm">@csrf
      <div class="card-head"><h3>My scorecard</h3>@if($i->scheduled_at->isFuture())<span class="small text-amber">opens {{ $i->scheduled_at->format('d M H:i') }}</span>@endif</div>
      <div class="card-body"><table class="table table-compact"><thead><tr><th>Criterion</th>@for($n = 1; $n <= 5; $n++)<th class="center">{{ $n }}</th>@endfor</tr></thead><tbody>
      @foreach(\App\Services\TalentService::CRITERIA as $k => $l)<tr><td>{{ $l }}</td>@for($n = 1; $n <= 5; $n++)<td class="center"><input type="radio" name="scores[{{ $k }}]" value="{{ $n }}" @checked((int) old('scores.'.$k, $mine?->scores[$k] ?? 0) === $n) required></td>@endfor</tr>@endforeach
      </tbody></table>
      <div class="form-grid mt-1"><x-field name="recommendation" label="Recommendation" type="select" :options="\App\Models\TalentScorecard::RECOMMEND" :value="$mine?->recommendation" required/>
        <x-field name="comments" label="Comments" type="textarea" :value="$mine?->comments" class="span-all"/></div></div>
      <div class="card-foot"><button class="btn btn-primary" id="talScoreSave">Save scorecard</button></div>
    </form>
    @endif
  </div>
  <div><div class="card flush"><div class="card-head"><h3>Scorecards</h3></div><div class="card-body table-wrap"><table class="table table-compact" id="talScores">
    <thead><tr><th>Panellist</th><th class="num">Avg</th><th>Rec.</th></tr></thead><tbody>
    @forelse($i->scorecards as $s)<tr><td class="small">{{ $s->user->name }}@if($s->comments)<div class="tiny muted">{{ $s->comments }}</div>@endif</td><td class="num">{{ number_format($s->average, 2) }}</td><td class="small">{{ \App\Models\TalentScorecard::RECOMMEND[$s->recommendation] }}</td></tr>
    @empty<tr><td colspan="3" class="muted small">No scorecards yet.</td></tr>@endforelse
    @if($i->scorecards->count() > 1)<tr><th>Panel average</th><th class="num">{{ number_format($i->average(), 2) }}</th><th></th></tr>@endif
    </tbody></table></div></div></div>
</div>
@endsection
