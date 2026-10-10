@extends('layouts.app')
@section('title', 'Shift Log Book')
@section('crumbs') Desk Operations / Log Book @endsection
@section('actions')
  <a class="btn" href="{{ route('desk.handover') }}" id="toHandover"><x-icon name="swap"/> Shift hand-over</a>
  @if(can_do('logbook.write'))<button class="btn btn-primary" data-modal="entryModal" id="newEntryBtn"><x-icon name="plus"/> New entry</button>@endif
@endsection
@section('content')
@php $c = $counts; @endphp
@if($c['log_urgent'] || $c['log_follow_ups'])
<div class="alert alert-warn" id="logAlerts"><x-icon name="alert"/><div class="flex" style="gap:.9rem;flex-wrap:wrap">
  @if($c['log_urgent'])<a href="{{ route('desk.logbook', ['tab' => 'open']) }}" class="text-red"><b>{{ $c['log_urgent'] }}</b> urgent item(s) still open</a>@endif
  @if($c['log_follow_ups'])<a href="{{ route('desk.logbook', ['tab' => 'open']) }}"><b>{{ $c['log_follow_ups'] }}</b> follow-up(s) due now</a>@endif
</div></div>
@endif

<div class="stats">
  <x-stat label="Unread by me" :value="$c['log_unread']" icon="eye" :tone="$c['log_unread'] ? 'amber' : 'green'" :sub="'last '.\App\Services\FrontDeskOpsService::cfg()['log_unread_days'].' days'"/>
  <x-stat label="Open / follow-up" :value="$c['log_open']" icon="alert" :tone="$c['log_urgent'] ? 'red' : ($c['log_open'] ? 'amber' : 'green')" :sub="$c['log_urgent'].' urgent · '.$c['log_follow_ups'].' due'"/>
  <x-stat label="Current shift" :value="\App\Models\LogEntry::SHIFTS[$shift]" icon="clock" tone="purple" :sub="'since '.\App\Services\FrontDeskOpsService::shiftStart()->format('H:i').' · business date '.bdate()->format('d M')"/>
  <x-stat label="Wake-ups next 12 h" :value="$c['wakeups_next12']" icon="bell" tone="blue" :sub="$c['messages'].' messages · '.$c['parcels'].' parcels waiting'"/>
</div>

<div class="card flush">
  <div class="card-head"><div class="tabs" style="margin:0;border:0">
    @foreach(\App\Http\Controllers\FrontDeskOpsController::LOG_TABS as $k => $l)<a href="{{ route('desk.logbook', array_filter(['tab' => $k, 'department' => request('department'), 'category' => request('category'), 'q' => request('q')])) }}" class="{{ $tab === $k ? 'active' : '' }}" data-tab="{{ $k }}">{{ $l }}@if($k === 'unread' && $c['log_unread']) <span class="badge badge-amber">{{ $c['log_unread'] }}</span>@elseif($k === 'open' && $c['log_open']) <span class="badge badge-{{ $c['log_urgent'] ? 'red' : 'blue' }}">{{ $c['log_open'] }}</span>@endif</a>@endforeach
  </div>
  @if($entries->count())<form method="post" action="{{ route('desk.logbook.ack') }}" id="ackForm">@csrf @foreach($entries as $e)@unless($e->readBy(auth()->id()))<input type="hidden" name="ids[]" value="{{ $e->id }}">@endunless @endforeach<button class="btn btn-sm" id="ackBtn"><x-icon name="check"/> Mark these as read</button></form>@endif
  </div>
  <form method="get" class="filters" style="display:flex;gap:.5rem;flex-wrap:wrap;padding:.7rem 1rem;border-bottom:1px solid var(--c-border)">
    <input type="hidden" name="tab" value="{{ $tab }}">
    <input class="input" name="q" value="{{ request('q') }}" placeholder="Search title / text" style="max-width:14rem">
    <select class="input" name="department" style="max-width:12rem"><option value="">All departments</option>@foreach(\App\Models\LogEntry::DEPARTMENTS as $k => $l)<option value="{{ $k }}" @selected(request('department') === $k)>{{ $l }}</option>@endforeach</select>
    <select class="input" name="category" style="max-width:11rem"><option value="">All categories</option>@foreach(\App\Models\LogEntry::CATEGORIES as $k => $l)<option value="{{ $k }}" @selected(request('category') === $k)>{{ $l[0] }}</option>@endforeach</select>
    @if($tab === 'all')<input class="input" type="date" name="date" value="{{ request('date') }}" style="max-width:10rem">@endif
    <button class="btn btn-sm">Filter</button>
  </form>
  <div class="card-body" style="padding:0">
  @forelse($entries as $e)
    @php $unread = ! $e->readBy(auth()->id()); @endphp
    <div class="log-row" data-entry="{{ $e->id }}" style="display:flex;gap:.8rem;padding:.8rem 1rem;border-bottom:1px solid var(--c-border);{{ $unread ? 'background:#fffbeb;' : '' }}{{ $e->priority === 'urgent' && $e->isOpen() ? 'border-left:4px solid #dc2626;' : ($e->priority === 'high' && $e->isOpen() ? 'border-left:4px solid #f59e0b;' : '') }}">
      <div style="min-width:5.5rem" class="small muted">{{ $e->created_at->format('d M') }}<br><b style="color:var(--c-text)">{{ $e->created_at->format('H:i') }}</b><br>{{ \App\Models\LogEntry::SHIFTS[$e->shift] ?? $e->shift }}</div>
      <div style="flex:1;min-width:0">
        <div class="flex" style="gap:.4rem;flex-wrap:wrap;align-items:center">
          @if($e->pinned)<span class="badge badge-purple" title="Pinned">📌 Pinned</span>@endif
          <span class="badge badge-{{ $e->categoryTone() }}">{{ $e->categoryLabel() }}</span>
          @if($e->priority !== 'normal')<span class="badge badge-{{ $e->priority === 'urgent' ? 'red' : 'amber' }}">{{ \App\Models\LogEntry::PRIORITIES[$e->priority] }}</span>@endif
          @if($e->status === 'open')<span class="badge badge-blue">Open</span>@elseif($e->status === 'resolved')<span class="badge badge-green">Resolved</span>@endif
          @if($e->followUpDue())<span class="badge badge-red">Follow-up due</span>@elseif($e->isOpen() && $e->follow_up_at)<span class="small muted">follow up {{ $e->follow_up_at->format('d M H:i') }}</span>@endif
          <a href="{{ route('desk.logbook.show', $e) }}" style="font-weight:600">{{ $e->title }}</a>
          @if($unread)<span class="badge badge-amber">New</span>@endif
        </div>
        @if($e->body)<div class="small" style="margin-top:.25rem;white-space:pre-line">{{ \Illuminate\Support\Str::limit($e->body, 260) }}</div>@endif
        <div class="small muted" style="margin-top:.25rem">{{ \App\Models\LogEntry::DEPARTMENTS[$e->department] ?? $e->department }} · {{ $e->source === 'system' ? 'System' : ($e->author?->name ?? '—') }}
          @if($e->stay?->room) · Room {{ $e->stay->room->room_no }}@elseif($e->room) · Room {{ $e->room->room_no }}@endif
          @if($e->guest) · {{ $e->guest->full_name }}@endif
          @if($e->comments_count) · 💬 {{ $e->comments_count }}@endif
          · read by {{ $e->reads->count() }}</div>
      </div>
    </div>
  @empty
    <div class="empty" style="padding:2rem;text-align:center" id="logEmpty">No log book entries here.</div>
  @endforelse
  </div>
  @if($entries->hasPages())<div class="card-foot">{{ $entries->links() }}</div>@endif
</div>

@if(can_do('logbook.write'))
<div class="modal" id="entryModal"><div class="modal-box" style="max-width:720px"><form method="post" action="{{ route('desk.logbook.store') }}">@csrf
  <div class="modal-head"><h3>New log book entry</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="title" label="Title" required class="span-all" placeholder="e.g. Room 305 – AC noisy, engineering informed"/>
    <x-field name="body" label="Details" type="textarea" class="span-all" rows="4"/>
    <x-field name="category" label="Category" type="select" :options="collect(\App\Models\LogEntry::CATEGORIES)->map(fn ($x) => $x[0])->all()" value="handover" :placeholder="false"/>
    <x-field name="department" label="For department" type="select" :options="\App\Models\LogEntry::DEPARTMENTS" value="front_office" :placeholder="false"/>
    <x-field name="priority" label="Priority" type="select" :options="\App\Models\LogEntry::PRIORITIES" value="normal" :placeholder="false"/>
    <x-field name="reservation_room_id" label="Guest / stay (optional)" type="select" :options="$stays" placeholder="— none —"/>
    <x-field name="room_id" label="Room (optional)" type="select" :options="$rooms" placeholder="— none —"/>
    <x-field name="follow_up_at" label="Follow up at" type="datetime-local"/>
    <label class="flex span-all" style="gap:.4rem;align-items:center"><input type="checkbox" name="needs_action" value="1"> Needs action – keep open until someone resolves it <span class="small muted">(complaints, incidents, maintenance and urgent entries are always open)</span></label>
    @if(can_do('logbook.manage'))<label class="flex" style="gap:.4rem;align-items:center"><input type="checkbox" name="pinned" value="1"> Pin to the hand-over</label>
    <x-field name="pinned_until" label="Pinned until" type="date"/>@endif
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" id="saveEntry">Add entry</button></div>
</form></div></div>
@endif
@endsection
