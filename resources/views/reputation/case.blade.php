@extends('layouts.app')
@section('title', 'Case '.$c->case_no)
@section('crumbs') <a href="{{ route('rep.cases.index') }}">Guest cases</a> / {{ $c->case_no }} @endsection
@section('content')
@include('reputation._nav')
<div class="card"><div class="card-body flex justify-between" style="flex-wrap:wrap;gap:1rem" id="caseHead">
  <div><h2 style="margin:0">{{ $c->case_no }} {!! status_badge($c->status) !!} @include('reputation._sev', ['s' => $c->severity])@if($c->in_house) <span class="badge badge-purple">guest in-house</span>@endif</h2>
    <p class="mt-1">{{ $c->title }}</p>
    <p class="tiny muted">{{ \App\Services\FeedbackService::DEPTS[$c->department] ?? $c->department }} · opened {{ $c->created_at->format('d M Y H:i') }}{{ $c->opener ? ' by '.$c->opener->name : ' automatically' }}</p></div>
  <div class="right">
    @if($c->isActive())<div class="{{ $c->isOverdue() ? 'text-red' : '' }}"><b>Resolve by {{ $c->due_at->format('d M H:i') }}</b></div>
      <div class="tiny muted" id="slaLeft">{{ $c->isOverdue() ? 'SLA missed by '.$c->due_at->diffForHumans(now(), true) : $c->due_at->diffForHumans(now(), true).' left' }}</div>
    @elseif($c->resolved_at)<div class="small">Resolved {{ $c->resolved_at->format('d M H:i') }} by {{ $c->resolver?->name }}</div><div class="tiny {{ $c->resolved_at->lte($c->due_at) ? 'text-green' : 'text-red' }}">{{ $c->resolved_at->lte($c->due_at) ? 'within SLA' : 'after the SLA' }}</div>@endif
    <div class="tiny muted mt-1">First response: {{ $c->first_response_at ? $c->first_response_at->format('d M H:i') : '—' }}</div>
  </div>
</div></div>

<div class="grid g-2-1">
  <div>
    @if($c->feedback)
    @php $f = $c->feedback; @endphp
    <div class="card" id="sourceFeedback"><div class="card-head"><h3>Guest feedback · {{ $f->created_at->format('d M Y') }}</h3>@if(can_do('feedback.view'))<a class="small" href="{{ route('rep.responses.show', $f) }}">Open →</a>@endif</div><div class="card-body">
      <p>{!! nps_badge($f->nps) !!} {!! stars($f->rating_overall) !!} {!! sentiment_badge($f->sentiment) !!} <span class="small muted">{{ \App\Models\GuestFeedback::SOURCES[$f->source] ?? '' }}{{ $f->stay?->room ? ' · room '.$f->stay->room->room_no : '' }}</span></p>
      @foreach($f->answers->filter(fn ($a) => in_array($a->question?->type, ['rating', 'yesno']))->sortBy(fn ($a) => $a->question?->sort) as $a)<div class="small flex justify-between" style="max-width:420px"><span class="muted">{{ $a->question->label }}</span><span class="{{ $a->question->type === 'rating' && $a->value_int <= 2 ? 'text-red' : 'text-amber' }}">{{ $a->display() }}</span></div>@endforeach
      @if(! $f->answers->count())@foreach(['rating_room' => 'Room', 'rating_service' => 'Service', 'rating_food' => 'Food', 'rating_cleanliness' => 'Cleanliness'] as $col => $l)@if($f->{$col})<div class="small flex justify-between" style="max-width:420px"><span class="muted">{{ $l }}</span>{!! stars($f->{$col}) !!}</div>@endif @endforeach @endif
      @if($txt = \App\Services\FeedbackService::feedbackText($f))<blockquote class="mt-1" style="white-space:pre-line;border-left:3px solid #fca5a5;padding-left:.8rem">{{ $txt }}</blockquote>@endif
    </div></div>
    @elseif($c->review)
    @php $r = $c->review; @endphp
    <div class="card" id="sourceReview"><div class="card-head"><h3>{{ $r->sourceName() }} review · {{ $r->review_date->format('d M Y') }}</h3>@if(can_do('feedback.view'))<a class="small" href="{{ route('rep.reviews.show', $r) }}">Respond →</a>@endif</div><div class="card-body">
      <p>{!! stars($r->rating5) !!} <b>{{ $r->title }}</b> <span class="small muted">by {{ $r->reviewer_name }}</span></p>
      <blockquote style="white-space:pre-line;border-left:3px solid #fca5a5;padding-left:.8rem">{{ $r->body }}</blockquote>
    </div></div>
    @elseif($c->details)
    <div class="card"><div class="card-head"><h3>Details</h3></div><div class="card-body" style="white-space:pre-line">{{ $c->details }}</div></div>
    @endif

    <div class="card"><div class="card-head"><h3><x-icon name="clock"/> Timeline</h3></div><div class="card-body">
      <ul class="timeline" id="caseTimeline">@foreach($c->events as $e)
        <li class="{{ in_array($e->type, ['resolved', 'closed']) ? 'tl-ok' : (in_array($e->type, ['escalated', 'reopened']) ? 'tl-bad' : 'tl-info') }} small">
          <b>{{ \App\Models\FeedbackCaseEvent::LABELS[$e->type] ?? $e->type }}</b> <span class="muted">· {{ $e->created_at->format('d M H:i') }}{{ $e->user ? ' · '.$e->user->name : '' }}</span>
          @if($e->note)<div style="white-space:pre-line">{{ $e->note }}</div>@endif</li>
      @endforeach</ul>
      @if($c->status !== 'closed')
      <form method="post" action="{{ route('rep.cases.note', $c) }}" class="flex gap-1 mt-1">@csrf<input name="note" placeholder="Add an internal note" style="flex:1" required maxlength="2000" id="noteInput"><button class="btn" id="addNote">Add note</button></form>
      @endif
    </div></div>
  </div>

  <div>
    <div class="card"><div class="card-head"><h3>Guest</h3></div><div class="card-body"><div class="kv">
      @if($c->guest)
        <div><span>Name</span><b><a href="{{ route('guests.show', $c->guest) }}">{{ $c->guest->full_name }}</a>{{ $c->guest->is_vip ? ' ⭐' : '' }}</b></div>
        <div><span>Loyalty points</span><b>{{ number_format($c->guest->loyalty_points) }}</b></div>
      @else<div><span>Guest</span><b class="muted">not linked</b></div>@endif
      @if($c->reservation)<div><span>Reservation</span><b><a href="{{ route('reservations.show', $c->reservation) }}">{{ $c->reservation->reservation_no }}</a> {!! status_badge($c->reservation->status) !!}</b></div>@endif
      <div><span>Owner</span><b>{{ $c->assignee?->name ?? 'Unassigned' }}</b></div>
      @if($c->recovery_action)<div><span>Recovery</span><b>{{ \App\Models\FeedbackCase::ACTIONS[$c->recovery_action] }}{{ $c->goodwill_points ? ' · '.number_format($c->goodwill_points).' pts' : '' }}</b></div>@endif
      @if($c->outcome)<div><span>Outcome</span><b>{{ \App\Models\FeedbackCase::OUTCOMES[$c->outcome] }}</b></div>@endif
    </div>
    @if($c->resolution)<div class="mt-1 small" id="resolutionText"><div class="tiny muted">Resolution</div><div style="white-space:pre-line">{{ $c->resolution }}</div></div>@endif
    </div></div>

    @if($c->isActive())
    <div class="card" id="caseActions"><div class="card-head"><h3>Actions</h3></div><div class="card-body">
      <form method="post" action="{{ route('rep.cases.assign', $c) }}" class="flex gap-1">@csrf
        <select name="assigned_to" style="flex:1" id="assignSel"><option value="">Unassigned</option>@foreach($staff as $id => $n)<option value="{{ $id }}" @selected($c->assigned_to === $id)>{{ $n }}</option>@endforeach</select><button class="btn" id="assignBtn">Assign</button></form>
      @if($c->status === 'open')<form method="post" action="{{ route('rep.cases.start', $c) }}" class="mt-1">@csrf<button class="btn w-100" id="startBtn"><x-icon name="check"/> Guest acknowledged – start working</button></form>@endif
      <div class="flex gap-1 mt-1">
        @if($c->guest && ! $c->guest->privacy_status)<button type="button" class="btn" data-modal="contactModal" id="contactBtn" style="flex:1"><x-icon name="mail"/> Contact guest</button>@endif
        <button type="button" class="btn btn-primary" data-modal="resolveModal" id="resolveBtn" style="flex:1"><x-icon name="check"/> Resolve</button>
      </div>
    </div></div>
    @elseif($c->status === 'resolved')
    <div class="card" id="closeCard"><div class="card-head"><h3>Close the case</h3></div><div class="card-body">
      <form method="post" action="{{ route('rep.cases.close', $c) }}">@csrf
        <x-field name="outcome" label="How did the guest respond?" type="select" :options="\App\Models\FeedbackCase::OUTCOMES" required/>
        <x-field name="note" label="Note" type="textarea"/>
        <button class="btn btn-primary mt-1" id="closeBtn">Close case</button></form>
    </div></div>
    @endif
    @if(! $c->isActive())
    <div class="card"><div class="card-body"><form method="post" action="{{ route('rep.cases.reopen', $c) }}" class="flex gap-1">@csrf<input name="reason" placeholder="Reason to re-open" required minlength="5" style="flex:1"><button class="btn" id="reopenBtn">Re-open</button></form></div></div>
    @endif
  </div>
</div>

@if($c->isActive() && $c->guest)
<div class="modal" id="contactModal"><div class="modal-box"><form method="post" action="{{ route('rep.cases.contact', $c) }}">@csrf
  <div class="modal-head"><h3>Contact {{ $c->guest->first_name }}</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <x-field name="channel" label="Send by" type="select" :options="array_filter(['email' => $c->guest->email ? 'E-mail ('.$c->guest->email.')' : null, 'sms' => $c->guest->phone ? 'SMS / WhatsApp ('.$c->guest->phone.')' : null])" :placeholder="false" required/>
    <x-field name="subject" label="Subject (e-mail)" :value="'Your stay at '.$currentHotel->name"/>
    @php $contactBody = 'Dear '.$c->guest->first_name.",\n\nThank you for telling us about your experience, and I am truly sorry that we let you down.\n\nKind regards,\n".auth()->user()->name.', '.$currentHotel->name; @endphp
    <x-field name="body" label="Message" type="textarea" required class="span-all" :value="$contactBody"/>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" id="sendContact">Send</button></div>
</form></div></div>
@endif
@if($c->isActive())
<div class="modal" id="resolveModal"><div class="modal-box"><form method="post" action="{{ route('rep.cases.resolve', $c) }}">@csrf
  <div class="modal-head"><h3>Resolve {{ $c->case_no }}</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <x-field name="recovery_action" label="What did we do?" type="select" :options="\App\Models\FeedbackCase::ACTIONS" required class="span-all"/>
    <x-field name="resolution" label="Resolution" type="textarea" required class="span-all" placeholder="Root cause, what was fixed, what the guest was told"/>
    @if($c->guest && $loyaltyOn)<x-field name="goodwill_points" label="Goodwill loyalty points" type="number" value="0" :hint="'up to '.number_format($goodwillMax).' without a manager'"/>@endif
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" id="confirmResolve">Resolve</button></div>
</form></div></div>
@endif
@endsection
