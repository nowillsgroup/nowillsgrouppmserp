@extends('layouts.app')
@section('title', 'Traces')
@section('crumbs') Desk Operations / Traces @endsection
@section('actions')
  <button class="btn btn-primary" data-modal="traceModal" id="newTraceBtn"><x-icon name="plus"/> New trace</button>
@endsection
@section('content')
<div class="card"><div class="card-body"><form method="get" class="flex" style="gap:.5rem;flex-wrap:wrap;align-items:center">
  <input class="input" type="date" name="date" value="{{ $date->toDateString() }}" style="max-width:10rem">
  <select class="input" name="department" style="max-width:12rem"><option value="">All departments</option>@foreach(\App\Services\TraceService::DEPARTMENTS as $k => $l)<option value="{{ $k }}" @selected($dept === $k)>{{ $l }}</option>@endforeach</select>
  <button class="btn btn-sm">Show</button>
  @unless($isToday)<a class="btn btn-sm btn-ghost" href="{{ route('desk.traces') }}">Today</a>@endunless
  <span class="small muted">Open traces that are not done by the night audit move to the next day automatically.</span>
</form></div></div>

<div class="card flush" id="openTraces"><div class="card-head"><h3>{{ $isToday ? 'Due today' : 'Open on '.$date->format('d M Y') }} ({{ $open->count() }})</h3></div>
  <div class="card-body table-wrap"><table class="table"><thead><tr><th>Due</th><th>Department</th><th>Trace</th><th>Booking</th><th>Set by</th><th class="right"></th></tr></thead><tbody>
  @forelse($open as $t)
    <tr data-trace="{{ $t->id }}" style="{{ $t->isOverdue() ? 'background:#fef2f2' : '' }}">
      <td class="nowrap small">{{ $t->trace_date->format('d M') }}{{ $t->due_time ? ' '.substr($t->due_time, 0, 5) : '' }}@if($t->carried_from)<div class="text-amber">carried from {{ $t->carried_from->format('d M') }}</div>@endif</td>
      <td class="small">{{ \App\Services\TraceService::DEPARTMENTS[$t->department] ?? $t->department }}</td>
      <td><b>{{ $t->text }}</b></td>
      <td class="small">@if($t->reservation)<a href="{{ route('reservations.show', $t->reservation) }}">{{ $t->reservation->reservation_no }}</a>@endif @if($t->stay?->room) · room {{ $t->stay->room->room_no }}@endif @if($t->guest)<div class="muted">{{ $t->guest->full_name }}</div>@endif</td>
      <td class="small">{{ $t->creator?->name ?? '—' }}</td>
      <td class="right nowrap">
        <button class="btn btn-sm btn-success" data-modal="doneModal" data-action="{{ route('desk.traces.done', $t) }}" data-title="{{ \Illuminate\Support\Str::limit($t->text, 60) }}" data-act="done">Done</button>
        <button class="btn btn-sm btn-ghost" data-modal="tCancelModal" data-action="{{ route('desk.traces.cancel', $t) }}" data-act="cancel">Cancel</button>
      </td>
    </tr>
  @empty<tr><td colspan="6" class="muted" id="tracesEmpty">No open traces.</td></tr>@endforelse
  </tbody></table></div></div>

@if($upcoming->isNotEmpty())
<div class="card flush" id="upcomingTraces"><div class="card-head"><h3>Coming up</h3></div><div class="card-body table-wrap"><table class="table"><tbody>
  @foreach($upcoming as $t)<tr><td class="small nowrap">{{ $t->trace_date->format('D d M') }}{{ $t->due_time ? ' '.substr($t->due_time, 0, 5) : '' }}</td><td class="small">{{ \App\Services\TraceService::DEPARTMENTS[$t->department] ?? '' }}</td><td>{{ $t->text }}</td><td class="small">{{ $t->reservation?->reservation_no }}{{ $t->guest ? ' · '.$t->guest->full_name : '' }}</td></tr>@endforeach
</tbody></table></div></div>
@endif

<div class="card flush" id="closedTraces"><div class="card-head"><h3>Completed / cancelled</h3></div><div class="card-body table-wrap"><table class="table"><tbody>
  @forelse($closed as $t)<tr class="muted-row"><td class="small nowrap">{{ $t->done_at?->format('d M H:i') }}</td><td class="small">{{ \App\Services\TraceService::DEPARTMENTS[$t->department] ?? '' }}</td><td>{{ $t->text }}@if($t->done_note)<div class="small">→ {{ $t->done_note }}</div>@endif</td><td class="small"><span class="badge badge-{{ $t->status === 'done' ? 'green' : 'slate' }}">{{ ucfirst($t->status) }}</span> {{ $t->doer?->name }}</td></tr>
  @empty<tr><td class="muted">None.</td></tr>@endforelse
</tbody></table></div></div>

<div class="modal" id="traceModal"><div class="modal-box"><form method="post" action="{{ route('desk.traces.store') }}">@csrf
  <div class="modal-head"><h3>New trace</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="text" label="What needs to be done" required class="span-all" placeholder="e.g. Confirm airport pick-up with the guest"/>
    <x-field name="trace_date" label="Date" type="date" :value="bdate()->toDateString()" required/>
    <x-field name="due_time" label="By (time, optional)" type="time"/>
    <x-field name="department" label="Department" type="select" :options="\App\Services\TraceService::DEPARTMENTS" value="front_office" :placeholder="false"/>
    <x-field name="reservation_room_id" label="Guest / stay (optional)" type="select" :options="$stays" placeholder="— none —"/>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" id="saveTrace">Set trace</button></div>
</form></div></div>
<div class="modal" id="doneModal"><div class="modal-box"><form method="post" action="#">@csrf
  <div class="modal-head"><h3 data-title>Done</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="note" label="Note (optional)"/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Back</button><button class="btn btn-success" id="confirmDone">Mark done</button></div>
</form></div></div>
<div class="modal" id="tCancelModal"><div class="modal-box"><form method="post" action="#">@csrf
  <div class="modal-head"><h3>Cancel trace</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="reason" label="Reason" required/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Back</button><button class="btn btn-primary">Cancel trace</button></div>
</form></div></div>
@endsection
