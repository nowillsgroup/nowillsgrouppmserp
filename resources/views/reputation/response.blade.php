@extends('layouts.app')
@section('title', 'Feedback – '.($f->guest_name ?: 'Anonymous'))
@section('crumbs') <a href="{{ route('feedback.index') }}">Guest feedback</a> / {{ $f->created_at->format('d M Y') }} @endsection
@section('actions')
@if($f->status === 'new')<form method="post" action="{{ route('rep.responses.reviewed', $f) }}" class="inline">@csrf<button class="btn" id="markRead"><x-icon name="check"/> Mark as read</button></form>@endif
@if(can_do('feedback.cases') && ! $f->recoveryCase)<form method="post" action="{{ route('rep.cases.from_feedback', $f) }}" class="inline">@csrf<button class="btn btn-primary" id="openCase"><x-icon name="shield"/> Open a guest case</button></form>@endif
@endsection
@section('content')
@include('reputation._nav')
<div class="grid g-2-1">
  <div class="card">
    <div class="card-head"><h3>{{ $f->survey?->name ?? (\App\Models\GuestFeedback::SOURCES[$f->source] ?? 'Feedback') }}</h3><span class="small muted">{{ $f->created_at->format('d M Y H:i') }}</span></div>
    <div class="card-body">
      @if($f->answers->count())
        <table class="table" id="answerTable"><tbody>
        @foreach($f->answers->sortBy(fn ($a) => $a->question?->sort) as $a)
          <tr><td class="small muted" style="width:45%">{{ $a->question?->label }}@if($a->question?->department) <span class="tiny">({{ \App\Services\FeedbackService::DEPTS[$a->question->department] ?? '' }})</span>@endif</td>
            <td class="{{ $a->question?->type === 'rating' ? 'text-amber' : '' }}">@if($a->question?->type === 'nps'){!! nps_badge($a->value_int) !!} <span class="small muted">/ 10</span>@elseif($a->question?->type === 'text')<div style="white-space:pre-line">{{ $a->value_text }}</div>@else{{ $a->display() }}@endif</td></tr>
        @endforeach
        </tbody></table>
      @else
        <div class="kv">
          @foreach(['rating_overall' => 'Overall', 'rating_room' => 'Room', 'rating_service' => 'Staff & service', 'rating_food' => 'Food & beverage', 'rating_cleanliness' => 'Cleanliness'] as $col => $l)
            @if($f->{$col})<div><span>{{ $l }}</span><b>{!! stars($f->{$col}) !!}</b></div>@endif
          @endforeach
        </div>
        @if($f->comments)<blockquote class="mt-2" style="white-space:pre-line;border-left:3px solid var(--c-border);padding-left:.8rem" id="fbComments">{{ $f->comments }}</blockquote>@endif
      @endif
      @if($f->consent_publish)<p class="tiny text-green mt-1"><x-icon name="check" size="sm"/> The guest agreed that their comments may be quoted (first name only).</p>@endif
    </div>
  </div>
  <div>
    <div class="card"><div class="card-head"><h3>Analysis</h3></div><div class="card-body">
      <div class="kv" id="analysis">
        <div><span>Recommend score</span><b>{!! nps_badge($f->nps) !!} {{ $f->nps_group ? ucfirst($f->nps_group) : '' }}</b></div>
        <div><span>Score (0-100)</span><b>{{ $f->score ?? '–' }}</b></div>
        <div><span>Sentiment</span><b>{!! sentiment_badge($f->sentiment) !!}</b></div>
        <div><span>Source</span><b>{{ \App\Models\GuestFeedback::SOURCES[$f->source] ?? $f->source }}{{ $f->in_house ? ' · in-house' : '' }}</b></div>
        @if($f->review_click_at)<div><span>Public review</span><b class="text-green">clicked {{ ucfirst($f->review_click_site) }} · {{ $f->review_click_at->format('d M') }}</b></div>@endif
        @if($f->reviewed_at)<div><span>Read by</span><b>{{ $f->reviewer?->name }} · {{ $f->reviewed_at->format('d M H:i') }}</b></div>@endif
      </div>
      @if($f->aspects)<div class="mt-2" id="aspectChips"><div class="tiny muted mb-1">Departments mentioned</div>@foreach($f->aspects as $d => $s)<span class="badge {{ ['positive' => 'badge-green', 'negative' => 'badge-red', 'mixed' => 'badge-amber'][$s] ?? 'badge-slate' }}">{{ \App\Services\FeedbackService::DEPTS[$d] ?? $d }} · {{ $s }}</span> @endforeach</div>@endif
    </div></div>
    <div class="card"><div class="card-head"><h3>Guest &amp; stay</h3></div><div class="card-body"><div class="kv">
      <div><span>Guest</span><b>@if($f->guest)<a href="{{ route('guests.show', $f->guest) }}">{{ $f->guest->full_name }}</a>@else{{ $f->guest_name ?: 'Anonymous' }}@endif</b></div>
      @if($f->contact)<div><span>Contact</span><b>{{ $f->contact }}</b></div>@endif
      @if($f->reservation)<div><span>Reservation</span><b><a href="{{ route('reservations.show', $f->reservation) }}">{{ $f->reservation->reservation_no }}</a></b></div>
        <div><span>Stay</span><b>{{ $f->reservation->arrival_date?->format('d M') }} – {{ $f->reservation->departure_date?->format('d M Y') }}{{ $f->stay?->room ? ' · room '.$f->stay->room->room_no : '' }}</b></div>@endif
    </div></div></div>
    @if($c = $f->recoveryCase)
    <div class="card" id="caseBox"><div class="card-head"><h3>Guest case</h3>{!! status_badge($c->status) !!}</div><div class="card-body">
      <p><a href="{{ can_do('feedback.cases') ? route('rep.cases.show', $c) : '#' }}" class="bold">{{ $c->case_no }}</a> @include('reputation._sev', ['s' => $c->severity])</p>
      <p class="small">{{ $c->title }}</p><p class="tiny muted">Owner: {{ $c->assignee?->name ?? 'unassigned' }} · due {{ $c->due_at->format('d M H:i') }}</p>
    </div></div>
    @endif
  </div>
</div>
@endsection
