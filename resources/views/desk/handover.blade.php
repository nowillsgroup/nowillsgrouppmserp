@extends('layouts.app')
@section('title', 'Shift Hand-over')
@section('crumbs') Desk Operations / Shift Hand-over @endsection
@section('actions')
  <a class="btn" href="{{ route('desk.handover.print') }}" target="_blank" id="printHandover"><x-icon name="print"/> Print hand-over sheet</a>
  <a class="btn" href="{{ route('desk.logbook') }}"><x-icon name="book"/> Log book</a>
@endsection
@section('content')
@php $s = $snap; $all = $recent->merge($open)->merge($pinned)->unique('id'); $unread = $all->filter(fn ($e) => ! $e->readBy(auth()->id())); @endphp
<div class="alert alert-info" id="handoverHead"><x-icon name="swap"/><div><b>{{ \App\Models\LogEntry::SHIFTS[$shift] }} shift</b> · business date {{ bdate()->format('D d M Y') }} · showing everything logged since <b>{{ $since->format('d M H:i') }}</b> (start of the previous shift), all open items and pinned notes.
  @if($unread->count())<form method="post" action="{{ route('desk.logbook.ack') }}" style="display:inline;margin-left:.6rem">@csrf @foreach($unread as $e)<input type="hidden" name="ids[]" value="{{ $e->id }}">@endforeach<button class="btn btn-sm btn-primary" id="ackHandover"><x-icon name="check"/> I have read the hand-over ({{ $unread->count() }})</button></form>
  @else <span class="text-green" id="handoverRead">✓ You have read everything.</span>@endif
</div></div>

<div class="stats">
  <x-stat label="In house" :value="$s['inhouse']" icon="bed" tone="blue" :sub="$s['arrivals_pending'].' arrivals · '.$s['departures_pending'].' departures pending'"/>
  <x-stat label="Wake-ups next 12 h" :value="$s['wakeups_next12']" icon="bell" :tone="$s['wakeups_late'] ? 'red' : 'purple'" :sub="$s['wakeups_late'].' late · '.$s['wakeups_due'].' due now'"/>
  <x-stat label="Messages / parcels" :value="$s['messages'].' / '.$s['parcels']" icon="mail" :tone="$s['messages_old'] ? 'amber' : 'green'" :sub="$s['messages_old'].' waiting long · '.$s['departed_parcels'].' for departed guests'"/>
  <x-stat label="Traces today" :value="$s['traces_today']" icon="check" :tone="$s['traces_today'] ? 'amber' : 'green'" :sub="$s['log_open'].' open log items · '.$s['lf_today'].' items found today'"/>
</div>

@if($pinned->isNotEmpty())
<div class="card" id="pinnedCard"><div class="card-head"><h3>📌 Pinned</h3></div><div class="card-body table-wrap"><table class="table"><tbody>
  @foreach($pinned as $e) @include('desk._entry-line') @endforeach
</tbody></table></div></div>
@endif

<div class="card" id="recentCard"><div class="card-head"><h3>Since {{ $since->format('H:i') }} ({{ $recent->count() }})</h3></div><div class="card-body table-wrap">
  <table class="table"><thead><tr><th>Time</th><th>Type</th><th>Entry</th><th>Room</th><th>By</th><th>Status</th></tr></thead><tbody>
  @forelse($recent as $e) @include('desk._entry-line') @empty<tr><td colspan="6" class="muted">Nothing logged since the previous shift started.</td></tr>@endforelse
  </tbody></table></div></div>

@if($open->isNotEmpty())
<div class="card" id="openCard"><div class="card-head"><h3>Still open from earlier ({{ $open->count() }})</h3></div><div class="card-body table-wrap">
  <table class="table"><thead><tr><th>Time</th><th>Type</th><th>Entry</th><th>Room</th><th>By</th><th>Status</th></tr></thead><tbody>
  @foreach($open as $e) @include('desk._entry-line') @endforeach
  </tbody></table></div></div>
@endif

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:1rem">
  <div class="card" id="hoWakeups"><div class="card-head"><h3>Wake-up calls</h3>@if(can_do('desk.wakeup'))<a href="{{ route('desk.wakeups') }}" class="small">Open queue →</a>@endif</div><div class="card-body table-wrap">
    <table class="table"><tbody>
    @forelse($wakeups as $w)<tr class="{{ $w->isLate() ? 'text-red' : '' }}"><td class="nowrap"><b>{{ $w->call_at->format('H:i') }}</b> <span class="small muted">{{ $w->call_at->format('d M') }}</span></td><td>{{ $w->stay?->room?->room_no ?? '—' }}</td><td class="small">{{ \App\Services\GuestMessageService::stayGuest($w->stay)?->full_name }}@if($w->daily) · daily @endif @if($w->note)<div class="muted">{{ $w->note }}</div>@endif</td></tr>
    @empty<tr><td class="muted">No wake-up calls in the next 12 hours.</td></tr>@endforelse
    </tbody></table></div></div>
  <div class="card" id="hoTraces"><div class="card-head"><h3>Traces due</h3>@if(can_do('desk.traces'))<a href="{{ route('desk.traces') }}" class="small">All traces →</a>@endif</div><div class="card-body table-wrap">
    <table class="table"><tbody>
    @forelse($traces as $t)<tr><td class="small nowrap">{{ $t->due_time ? substr($t->due_time, 0, 5) : 'Today' }}@if($t->carried_from)<div class="text-amber">since {{ $t->carried_from->format('d M') }}</div>@endif</td><td class="small">{{ \App\Services\TraceService::DEPARTMENTS[$t->department] ?? '' }}</td><td>{{ $t->text }}@if($t->stay?->room)<span class="small muted"> · room {{ $t->stay->room->room_no }}</span>@endif</td></tr>
    @empty<tr><td class="muted">No open traces for today.</td></tr>@endforelse
    </tbody></table></div></div>
  <div class="card" id="hoMessages"><div class="card-head"><h3>Messages & parcels waiting</h3>@if(can_do('desk.messages'))<a href="{{ route('desk.messages') }}" class="small">Open desk →</a>@endif</div><div class="card-body table-wrap">
    <table class="table"><tbody>
    @forelse($messages as $m)<tr><td class="small nowrap">{{ $m->message_no }}</td><td>{{ $m->stay?->room?->room_no ?? '—' }}</td><td class="small">{{ $m->isParcel() ? '📦 Parcel' : '✉ Message' }} from {{ $m->from_name }}@if($m->urgent) <span class="badge badge-red">Urgent</span>@endif</td><td class="small"><span class="badge badge-{{ $m->statusTone() }}">{{ $m->statusLabel() }}</span></td></tr>
    @empty<tr><td class="muted">Nothing waiting.</td></tr>@endforelse
    </tbody></table></div></div>
</div>
@endsection
