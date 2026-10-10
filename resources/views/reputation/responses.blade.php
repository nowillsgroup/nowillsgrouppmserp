@extends('layouts.app')
@section('title', 'Guest Feedback')
@section('crumbs') Guests &amp; CRM / Feedback &amp; Reputation @endsection
@section('actions')
<a class="btn" href="{{ route('feedback.form', $currentHotel->code) }}" target="_blank"><x-icon name="globe"/> Public feedback form</a>
@if(can_do('reports.view'))<a class="btn" href="{{ route('reports.show', 'feedback_register') }}"><x-icon name="chart"/> Feedback register</a>@endif
@endsection
@section('content')
@include('reputation._nav')
<div class="stats">
  <x-stat label="Responses (filtered)" :value="$count" icon="message" tone="blue" :sub="$unread.' not yet read'"/>
  <x-stat label="NPS" :value="$nps['nps'] === null ? '—' : ($nps['nps'] > 0 ? '+' : '').$nps['nps']" icon="trend" :tone="($nps['nps'] ?? 0) >= 30 ? 'green' : 'amber'" :sub="$nps['promoters'].' promoters · '.$nps['detractors'].' detractors'"/>
  <x-stat label="Satisfaction" :value="$csat === null ? '—' : number_format($csat, 2).' ★'" icon="star" tone="amber" sub="average overall rating"/>
</div>
<div class="card flush">
  <div class="card-head"><h3>Guest feedback</h3>
  <form class="filters" style="margin:0" id="fbFilters">
    <select name="source" onchange="this.form.submit()"><option value="">All sources</option>@foreach(\App\Models\GuestFeedback::SOURCES as $k => $l)<option value="{{ $k }}" @selected(request('source') === $k)>{{ $l }}</option>@endforeach</select>
    <select name="sentiment" onchange="this.form.submit()"><option value="">Any sentiment</option>@foreach(['positive', 'neutral', 'negative'] as $k)<option value="{{ $k }}" @selected(request('sentiment') === $k)>{{ ucfirst($k) }}</option>@endforeach</select>
    <select name="group" onchange="this.form.submit()"><option value="">Any NPS group</option>@foreach(['promoter' => 'Promoters (9-10)', 'passive' => 'Passives (7-8)', 'detractor' => 'Detractors (0-6)'] as $k => $l)<option value="{{ $k }}" @selected(request('group') === $k)>{{ $l }}</option>@endforeach</select>
    <select name="dept" onchange="this.form.submit()"><option value="">Criticised department</option>@foreach(\App\Services\FeedbackService::DEPTS as $k => $l)@continue($k === 'overall')<option value="{{ $k }}" @selected(request('dept') === $k)>{{ $l }}</option>@endforeach</select>
    <select name="status" onchange="this.form.submit()"><option value="">Read &amp; unread</option><option value="new" @selected(request('status') === 'new')>Unread only</option></select>
    <input name="q" value="{{ request('q') }}" placeholder="Guest or comment">
  </form></div>
  <div class="card-body table-wrap"><table class="table" id="fbTable">
    <thead><tr><th>Date</th><th>Guest</th><th>Source</th><th>NPS</th><th>Overall</th><th class="num">Score</th><th>Sentiment</th><th>Comments</th><th>Case</th></tr></thead><tbody>
    @forelse($items as $f)
    <tr class="{{ $f->status === 'new' ? 'bold-row' : '' }}"><td class="nowrap small">{{ $f->created_at->format('d M Y') }}@if($f->status === 'new') <span class="badge badge-blue">new</span>@endif</td>
      <td><a href="{{ route('rep.responses.show', $f) }}" class="bold">{{ $f->guest_name ?? 'Anonymous' }}</a>@if($f->in_house) <span class="badge badge-purple" title="Given while in the hotel">in-house</span>@endif
        <div class="tiny muted">{{ $f->stay?->room ? 'Room '.$f->stay->room->room_no : $f->contact }}</div></td>
      <td class="small">{{ \App\Models\GuestFeedback::SOURCES[$f->source] ?? $f->source }}</td>
      <td>{!! nps_badge($f->nps) !!}</td><td class="nowrap">{!! stars($f->rating_overall) !!}</td><td class="num">{{ $f->score ?? '–' }}</td><td>{!! sentiment_badge($f->sentiment) !!}</td>
      <td class="small" style="max-width:340px">{{ \Illuminate\Support\Str::limit($f->comments, 120) }}
        @if($f->aspects)<div class="mt-1">@foreach($f->aspects as $d => $s)<span class="badge {{ ['positive' => 'badge-green', 'negative' => 'badge-red', 'mixed' => 'badge-amber'][$s] ?? 'badge-slate' }}" style="font-size:.66rem">{{ \App\Services\FeedbackService::DEPTS[$d] ?? $d }}</span> @endforeach</div>@endif</td>
      <td class="small nowrap">@if($f->recoveryCase)<a href="{{ can_do('feedback.cases') ? route('rep.cases.show', $f->recoveryCase) : '#' }}">{{ $f->recoveryCase->case_no }}</a><div>{!! status_badge($f->recoveryCase->status) !!}</div>@endif</td></tr>
    @empty<tr><td colspan="9"><x-empty text="No feedback matches." icon="star"/></td></tr>@endforelse
  </tbody></table>{{ $items->links() }}</div>
</div>
@endsection
