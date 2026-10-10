@extends('layouts.app')
@section('title', $r->sourceName().' review – '.$r->reviewer_name)
@section('crumbs') <a href="{{ route('rep.reviews.index') }}">Online reviews</a> / {{ $r->reviewer_name }} @endsection
@section('actions')
@if($r->url)<a class="btn" href="{{ $r->url }}" target="_blank" rel="noopener noreferrer"><x-icon name="globe"/> Open on {{ $r->sourceName() }}</a>@endif
@if(can_do('feedback.cases'))
  @if($r->recoveryCase)<a class="btn" href="{{ route('rep.cases.show', $r->recoveryCase) }}"><x-icon name="shield"/> Case {{ $r->recoveryCase->case_no }}</a>
  @else<form method="post" action="{{ route('rep.cases.from_review', $r) }}" class="inline">@csrf<button class="btn" id="reviewCase"><x-icon name="shield"/> Open a guest case</button></form>@endif
@endif
@endsection
@section('content')
@include('reputation._nav')
<div class="grid g-2-1">
  <div>
    <div class="card" id="reviewCard"><div class="card-body">
      <div class="flex justify-between"><div><b style="font-size:1.1rem">{{ $r->reviewer_name }}</b> <span class="small muted">{{ $r->reviewer_location }}</span>
        <div class="small muted">{{ $r->sourceName() }} · {{ $r->review_date->format('d M Y') }}{{ $r->trip_type ? ' · '.\App\Models\OnlineReview::TRIP_TYPES[$r->trip_type] : '' }}</div></div>
        <div class="right">{!! stars($r->rating5) !!}<div class="small"><b>{{ rtrim(rtrim(number_format((float) $r->rating, 1), '0'), '.') }}</b> / {{ $r->rating_scale }}</div></div></div>
      @if($r->title)<h3 class="mt-2">{{ $r->title }}</h3>@endif
      <p style="white-space:pre-line" class="mt-1" id="reviewBody">{{ $r->body }}</p>
      <div class="mt-1">{!! sentiment_badge($r->sentiment) !!} @foreach((array) $r->aspects as $d => $s)<span class="badge {{ ['positive' => 'badge-green', 'negative' => 'badge-red', 'mixed' => 'badge-amber'][$s] ?? 'badge-slate' }}">{{ \App\Services\FeedbackService::DEPTS[$d] ?? $d }} · {{ $s }}</span> @endforeach</div>
    </div></div>

    <div class="card" id="responseCard"><div class="card-head"><h3><x-icon name="message"/> Hotel response</h3><span>{!! status_badge($r->status) !!} <span class="small muted">{{ \App\Models\OnlineReview::STATUSES[$r->status] }}</span></span></div>
    <div class="card-body">
      @if($r->return_note && $r->status === 'draft')<div class="alert alert-warn" id="returnNote"><x-icon name="alert"/><div><b>Returned by the approver:</b> {{ $r->return_note }}</div></div>@endif
      @if($r->isLocked())
        @if($r->status === 'posted')<div style="white-space:pre-line" id="postedText">{{ $r->response_text }}</div>
          <p class="tiny muted mt-1">Posted {{ $r->posted_at->format('d M Y H:i') }} by {{ $r->poster?->name }}@if($r->approver) · approved by {{ $r->approver->name }}@endif · {{ $r->responseHours() }} h after the review came in</p>
        @else<p>No response: <b>{{ $r->ignore_reason }}</b></p>
          @if(can_do('feedback.respond'))<form method="post" action="{{ route('rep.reviews.restore', $r) }}">@csrf<button class="btn" id="restoreBtn">Back to the inbox</button></form>@endif
        @endif
      @elseif(in_array($r->status, ['pending', 'approved']))
        <div style="white-space:pre-line;background:var(--c-slate-50,#f8fafc);padding:.8rem;border-radius:8px" id="responseText">{{ $r->response_text }}</div>
        <p class="tiny muted mt-1">Written by {{ $r->drafter?->name }} · {{ $r->drafted_at?->format('d M H:i') }}@if($r->status === 'approved' && $r->approver) · approved by {{ $r->approver->name }}@endif</p>
        <div class="flex gap-1 mt-1" style="flex-wrap:wrap">
          @if($r->status === 'pending' && can_do('feedback.approve'))
            @if($r->drafted_by !== auth()->id())<form method="post" action="{{ route('rep.reviews.approve', $r) }}">@csrf<button class="btn btn-primary" id="approveBtn"><x-icon name="check"/> Approve</button></form>@else<span class="small muted">You wrote this response – another manager must approve it.</span>@endif
            <form method="post" action="{{ route('rep.reviews.return', $r) }}" class="flex gap-1">@csrf<input name="return_note" placeholder="What should change?" required maxlength="255"><button class="btn" id="returnBtn">Return</button></form>
          @elseif($r->status === 'pending')<span class="small muted">Waiting for a manager's approval.</span>@endif
          @if($r->status === 'approved' && can_do('feedback.respond'))
            <button type="button" class="btn" id="copyResponse" data-copy="responseText"><x-icon name="book"/> Copy text</button>
            <form method="post" action="{{ route('rep.reviews.posted', $r) }}">@csrf<button class="btn btn-primary" id="postedBtn"><x-icon name="check"/> I've posted it on {{ $r->sourceName() }}</button></form>
          @endif
        </div>
      @elseif(can_do('feedback.respond'))
        <form method="post" id="responseForm" action="{{ route('rep.reviews.submit', $r) }}">@csrf
          @if($templates->count())<div class="flex gap-1 mb-1"><select id="tplSel" style="flex:1"><option value="">Start from a template…</option>@foreach($templates as $id => $t)<option value="{{ $id }}" @selected($suggested === $id && ! $r->response_text)>{{ $t['name'] }}{{ $suggested === $id ? ' (suggested)' : '' }}</option>@endforeach</select>
            <button type="button" class="btn" id="useTpl">Use</button></div>@endif
          <textarea name="response_text" id="f_response_text" rows="10" style="width:100%" maxlength="{{ \App\Services\ReviewService::MAX_RESPONSE }}" placeholder="Write the hotel's public response">{{ old('response_text', $r->response_text) }}</textarea>
          @error('response_text')<div class="err">{{ $message }}</div>@enderror
          <div class="flex justify-between mt-1"><span class="tiny muted"><span id="charCount">0</span> / {{ number_format(\App\Services\ReviewService::MAX_RESPONSE) }} characters{{ $needsApproval ? ' · critical review: a manager approves before posting' : '' }}</span>
            <span class="flex gap-1"><button class="btn" formaction="{{ route('rep.reviews.draft', $r) }}" id="saveDraft">Save draft</button><button class="btn btn-primary" id="submitResponse">{{ $needsApproval ? 'Send for approval' : 'Ready to post' }}</button></span></div>
        </form>
        <details class="mt-2"><summary class="small muted">No response needed?</summary>
          <form method="post" action="{{ route('rep.reviews.ignore', $r) }}" class="flex gap-1 mt-1">@csrf<input name="ignore_reason" placeholder="Reason, e.g. duplicate / spam / not our hotel" required maxlength="160" style="flex:1"><button class="btn" id="ignoreBtn">Mark "no response"</button></form></details>
        <script type="application/json" id="tplData">@json($templates)</script>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
          const ta = document.getElementById('f_response_text'), cc = document.getElementById('charCount'), data = JSON.parse(document.getElementById('tplData').textContent || '{}');
          const count = () => cc.textContent = ta.value.length; ta.addEventListener('input', count); count();
          const use = () => { const t = data[document.getElementById('tplSel').value]; if (t && (!ta.value.trim() || confirm('Replace the current text with the template?'))) { ta.value = t.text; count(); ta.focus(); } };
          document.getElementById('useTpl')?.addEventListener('click', use);
          if (!ta.value.trim() && document.getElementById('tplSel')?.value) { ta.value = data[document.getElementById('tplSel').value].text; count(); }
        });
        </script>
      @else<p class="muted">No response yet.</p>@endif
    </div></div>
  </div>
  <div>
    <div class="card"><div class="card-head"><h3>Guest &amp; stay</h3></div><div class="card-body">
      @if($r->guest)<div class="kv" id="matchedGuest"><div><span>Guest</span><b><a href="{{ route('guests.show', $r->guest) }}">{{ $r->guest->full_name }}</a></b></div>
        @if($r->reservation)<div><span>Stay</span><b><a href="{{ route('reservations.show', $r->reservation) }}">{{ $r->reservation->reservation_no }}</a> · {{ $r->reservation->arrival_date?->format('d M') }} – {{ $r->reservation->departure_date?->format('d M Y') }}</b></div>@endif</div>
      @else<p class="small muted">Not matched to a stay. Reviews are matched automatically when exactly one guest with the reviewer's full name checked out in the 60 days before the review.</p>@endif
      @if(can_do('feedback.respond'))<form method="post" action="{{ route('rep.reviews.link', $r) }}" class="flex gap-1 mt-1">@csrf<input name="reservation_no" placeholder="Reservation no." value="{{ $r->reservation?->reservation_no }}" style="flex:1"><button class="btn" id="linkBtn">{{ $r->guest ? 'Change' : 'Link' }}</button></form>@endif
    </div></div>
    <div class="card"><div class="card-head"><h3>Details</h3></div><div class="card-body"><div class="kv">
      <div><span>Imported</span><b>{{ $r->created_at->format('d M Y H:i') }}</b></div>
      @if($r->external_id)<div><span>Review ID</span><b>{{ $r->external_id }}</b></div>@endif
      <div><span>Normalised</span><b>{{ $r->rating5 }} / 5</b></div>
    </div></div></div>
  </div>
</div>
<script>
document.addEventListener('click', function (e) { const b = e.target.closest('[data-copy]'); if (!b) return; const t = document.getElementById(b.dataset.copy).innerText;
  (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(() => { b.textContent = 'Copied ✓'; }).catch(() => { b.textContent = 'Select the text and copy'; }); });
</script>
@endsection
