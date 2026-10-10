@extends('layouts.app')
@section('title', 'Wake-up Calls')
@section('crumbs') Desk Operations / Wake-up Calls @endsection
@section('actions')
  <a class="btn" href="{{ route('desk.wakeups.print') }}" target="_blank" id="printWakeups"><x-icon name="print"/> Wake-up sheet (24 h)</a>
  <button class="btn btn-primary" data-modal="wakeModal" id="newWakeBtn"><x-icon name="plus"/> Book wake-up</button>
@endsection
@section('content')
@php $c = $counts; @endphp
@if($c['wakeups_late'])
<div class="alert alert-error" id="wakeLate"><x-icon name="alert"/><div><b>{{ $c['wakeups_late'] }}</b> wake-up call(s) are more than {{ $cfg['wakeup_grace'] }} minutes late – call now. The duty manager has been alerted through the log book.</div></div>
@endif
<div class="stats">
  <x-stat label="Due now" :value="$c['wakeups_due']" icon="bell" :tone="$c['wakeups_late'] ? 'red' : ($c['wakeups_due'] ? 'amber' : 'green')" :sub="'within '.$cfg['wakeup_lead'].' min · '.$c['wakeups_late'].' late'"/>
  <x-stat label="Next 12 hours" :value="$c['wakeups_next12']" icon="clock" tone="blue" :sub="$queue->count().' scheduled in total'"/>
  <x-stat label="Retry rule" :value="$cfg['wakeup_max_attempts'].' tries'" icon="refresh" tone="purple" :sub="'every '.$cfg['wakeup_retry'].' min, then send someone'"/>
  <x-stat label="Answered (24 h)" :value="$last24->filter(fn ($s) => $s === 'done')->count().' / '.$last24->filter(fn ($s) => in_array($s, ['done', 'no_answer', 'missed']))->count()" icon="check" tone="green" :sub="$last24->filter(fn ($s) => $s === 'no_answer')->count().' no answer · '.$last24->filter(fn ($s) => $s === 'missed')->count().' missed'"/>
</div>

<div class="card flush" id="wakeQueue"><div class="card-head"><h3>Queue</h3><span class="small muted">now {{ now()->format('H:i') }}</span></div>
  <div class="card-body table-wrap"><table class="table"><thead><tr><th>Time</th><th>Room</th><th>Guest</th><th>Instructions</th><th>Attempts</th><th class="right">Log the call</th></tr></thead><tbody>
  @forelse($queue as $w)
    @php $g = \App\Services\GuestMessageService::stayGuest($w->stay); $retry = \App\Services\WakeupService::retryAt($w); $late = $w->isLate($cfg['wakeup_grace']); $due = $w->isDue($cfg['wakeup_lead']); @endphp
    <tr data-wakeup="{{ $w->id }}" style="{{ $late ? 'background:#fef2f2' : ($due ? 'background:#fffbeb' : '') }}">
      <td class="nowrap"><b style="font-size:1.05rem">{{ $w->call_at->format('H:i') }}</b> <span class="small muted">{{ $w->call_at->isToday() ? 'today' : $w->call_at->format('D d M') }}</span>
        @if($late)<div class="small text-red">late {{ (int) $w->call_at->diffInMinutes(now()) }} min</div>@elseif($due)<div class="small text-amber">due</div>@endif</td>
      <td><b>{{ $w->stay?->room?->room_no ?? '—' }}</b></td>
      <td>{{ $g?->full_name ?? '—' }}<div class="small muted">{{ \App\Models\WakeupCall::SOURCES[$w->source] ?? $w->source }}@if($w->daily) · <span class="text-purple">daily until {{ $w->stay?->departure_date?->format('d M') }}</span>@endif</div></td>
      <td class="small">{{ $w->note ?: '—' }}</td>
      <td class="small">{{ $w->attempts }}@if($retry)<div class="{{ $retry->lte(now()) ? 'text-red' : 'muted' }}">retry {{ $retry->format('H:i') }}</div>@endif</td>
      <td class="right nowrap">
        @if($due)
          <form method="post" action="{{ route('desk.wakeups.attempt', $w) }}" style="display:inline">@csrf<input type="hidden" name="outcome" value="answered"><button class="btn btn-sm btn-success" data-act="answered">✓ Answered</button></form>
          <form method="post" action="{{ route('desk.wakeups.attempt', $w) }}" style="display:inline">@csrf<input type="hidden" name="outcome" value="no_answer"><button class="btn btn-sm" data-act="no_answer">No answer</button></form>
        @endif
        <form method="post" action="{{ route('desk.wakeups.cancel', $w) }}" style="display:inline" data-confirm="Cancel this wake-up call{{ $w->daily ? ' (and the daily series)' : '' }}?">@csrf<button class="btn btn-sm btn-ghost" data-act="cancel">Cancel</button></form>
      </td>
    </tr>
  @empty
    <tr><td colspan="6" class="muted" id="wakeEmpty">No wake-up calls scheduled.</td></tr>
  @endforelse
  </tbody></table></div></div>

<div class="card flush" id="wakeDone"><div class="card-head"><h3>{{ $date ? 'Calls on '.$date->format('d M Y') : 'Last 24 hours' }}</h3>
  <form method="get" class="flex" style="gap:.4rem"><input class="input" type="date" name="date" value="{{ $date?->toDateString() }}" style="max-width:10rem"><button class="btn btn-sm">Show</button></form></div>
  <div class="card-body table-wrap"><table class="table"><thead><tr><th>Time</th><th>Room</th><th>Guest</th><th>Result</th><th>Attempts</th><th>By</th></tr></thead><tbody>
  @forelse($done as $w)
    <tr><td class="nowrap">{{ $w->call_at->format('d M H:i') }}</td><td>{{ $w->stay?->room?->room_no ?? $w->room?->room_no ?? '—' }}</td><td class="small">{{ \App\Services\GuestMessageService::stayGuest($w->stay)?->full_name }}</td>
      <td><span class="badge badge-{{ $w->statusTone() }}">{{ $w->statusLabel() }}</span>@if($w->outcome_note)<div class="small muted">{{ $w->outcome_note }}</div>@endif</td>
      <td class="small">@foreach($w->attemptsLog as $a){{ $a->attempted_at->format('H:i') }} {{ str_replace('_', ' ', $a->outcome) }}@if(! $loop->last) · @endif @endforeach</td>
      <td class="small">{{ $w->completer?->name ?? '—' }}</td></tr>
  @empty<tr><td colspan="6" class="muted">Nothing yet.</td></tr>@endforelse
  </tbody></table></div></div>

<div class="modal" id="wakeModal"><div class="modal-box"><form method="post" action="{{ route('desk.wakeups.store') }}">@csrf
  <div class="modal-head"><h3>Book a wake-up call</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="reservation_room_id" label="Guest / room" type="select" :options="$stays" required class="span-all"/>
    <x-field name="call_date" label="Date" type="date" :value="now()->addDay()->toDateString()" required/>
    <x-field name="call_time" label="Time" type="time" value="06:30" required/>
    <x-field name="source" label="Requested" type="select" :options="['phone' => 'Guest phoned', 'desk' => 'At the desk']" value="phone" :placeholder="false"/>
    <label class="flex" style="gap:.4rem;align-items:center;align-self:end"><input type="checkbox" name="daily" value="1"> Every day until check-out</label>
    <x-field name="note" label="Instructions" class="span-all" placeholder="e.g. call twice, send coffee with the call"/>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" id="saveWake">Book</button></div>
</form></div></div>
@endsection
