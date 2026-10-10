@extends('layouts.app')
@section('title', 'Log entry')
@section('crumbs') Desk Operations / <a href="{{ route('desk.logbook') }}">Log Book</a> / Entry @endsection
@section('actions')
  @if(can_do('logbook.manage'))
  <form method="post" action="{{ route('desk.logbook.pin', $e) }}" style="display:inline">@csrf<input type="hidden" name="pinned" value="{{ $e->pinned ? 0 : 1 }}"><button class="btn" id="pinBtn">{{ $e->pinned ? 'Unpin' : '📌 Pin to hand-over' }}</button></form>
  @endif
  @if(can_do('logbook.write') && $e->status === 'resolved')<form method="post" action="{{ route('desk.logbook.reopen', $e) }}" style="display:inline">@csrf<button class="btn" id="reopenBtn"><x-icon name="refresh"/> Re-open</button></form>@endif
@endsection
@section('content')
<div class="grid-2" style="display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr);gap:1rem;align-items:start">
<div>
  <div class="card"><div class="card-head"><h3 id="entryTitle">{{ $e->title }}</h3>
    <div class="flex" style="gap:.35rem">
      <span class="badge badge-{{ $e->categoryTone() }}">{{ $e->categoryLabel() }}</span>
      @if($e->priority !== 'normal')<span class="badge badge-{{ $e->priority === 'urgent' ? 'red' : 'amber' }}">{{ \App\Models\LogEntry::PRIORITIES[$e->priority] }}</span>@endif
      <span class="badge badge-{{ ['open' => 'blue', 'resolved' => 'green'][$e->status] ?? 'slate' }}" id="entryStatus">{{ ['open' => 'Open', 'resolved' => 'Resolved', 'note' => 'Note'][$e->status] }}</span>
    </div></div>
    <div class="card-body">
      <div class="small muted" style="margin-bottom:.6rem">{{ $e->created_at->format('D d M Y, H:i') }} · {{ \App\Models\LogEntry::SHIFTS[$e->shift] ?? '' }} shift · {{ \App\Models\LogEntry::DEPARTMENTS[$e->department] ?? '' }} · by {{ $e->source === 'system' ? 'System' : ($e->author?->name ?? '—') }}</div>
      <div style="white-space:pre-line">{{ $e->body ?: '—' }}</div>
      @if($e->status === 'resolved')<div class="alert alert-success" style="margin-top:1rem"><x-icon name="check"/><div><b>Resolved</b> by {{ $e->resolver?->name ?? '—' }} · {{ $e->resolved_at?->format('d M H:i') }}<div style="white-space:pre-line">{{ $e->resolution }}</div></div></div>@endif
    </div></div>

  <div class="card"><div class="card-head"><h3>Comments ({{ $e->comments->count() }})</h3></div><div class="card-body">
    @forelse($e->comments as $cm)
      <div style="padding:.5rem 0;border-bottom:1px solid var(--c-border)" class="log-comment"><div class="small muted">{{ $cm->user?->name ?? '—' }} · {{ $cm->created_at->format('d M H:i') }}</div><div style="white-space:pre-line">{{ $cm->body }}</div></div>
    @empty<div class="small muted">No comments yet.</div>@endforelse
    @if(can_do('logbook.write'))
    <form method="post" action="{{ route('desk.logbook.comment', $e) }}" style="margin-top:.8rem">@csrf
      <textarea class="input" name="body" rows="2" required placeholder="Add an update…" style="width:100%"></textarea>
      <div style="margin-top:.4rem"><button class="btn btn-sm btn-primary" id="commentBtn">Add comment</button></div></form>
    @endif
  </div></div>
</div>
<div>
  <div class="card"><div class="card-head"><h3>Details</h3></div><div class="card-body small">
    <dl class="kv">
      @if($e->stay)<dt>Stay</dt><dd>Room {{ $e->stay->room?->room_no ?? '—' }} · <a href="{{ route('reservations.show', $e->stay->reservation_id) }}">{{ $e->stay->reservation?->reservation_no }}</a></dd>@endif
      @if($e->room && ! $e->stay)<dt>Room</dt><dd>{{ $e->room->room_no }}</dd>@endif
      @if($e->guest)<dt>Guest</dt><dd><a href="{{ route('guests.show', $e->guest) }}">{{ $e->guest->full_name }}</a></dd>@endif
      <dt>Business date</dt><dd>{{ $e->business_date->format('d M Y') }}</dd>
      @if($e->follow_up_at)<dt>Follow up</dt><dd class="{{ $e->followUpDue() ? 'text-red' : '' }}">{{ $e->follow_up_at->format('d M H:i') }}</dd>@endif
      @if($e->pinned)<dt>Pinned</dt><dd>{{ $e->pinned_until ? 'until '.$e->pinned_until->format('d M') : 'until unpinned' }}</dd>@endif
    </dl>
  </div></div>
  @if($e->isOpen() && can_do('logbook.write'))
  <div class="card"><div class="card-head"><h3>Resolve</h3></div><div class="card-body">
    <form method="post" action="{{ route('desk.logbook.resolve', $e) }}">@csrf
      <textarea class="input" name="resolution" rows="3" required placeholder="What was done?" style="width:100%"></textarea>
      <div style="margin-top:.4rem"><button class="btn btn-success btn-sm" id="resolveBtn"><x-icon name="check"/> Mark resolved</button></div></form>
  </div></div>
  @endif
  <div class="card"><div class="card-head"><h3>Read by ({{ $e->reads->count() }})</h3></div><div class="card-body small" id="readBy">
    @forelse($e->reads->sortBy('read_at') as $r)<div>{{ $r->user?->name ?? '—' }} <span class="muted">· {{ $r->read_at->format('d M H:i') }}</span></div>@empty<span class="muted">Nobody else yet.</span>@endforelse
  </div></div>
</div>
</div>
@endsection
