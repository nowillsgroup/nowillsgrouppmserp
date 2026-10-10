@extends('layouts.app')
@section('title', 'Table Reservations')
@section('crumbs') Point of Sale / Table reservations @endsection
@section('actions')
@if($outlet)
  <a class="btn" href="{{ route('tb.floor', ['outlet' => $outlet->id]) }}"><x-icon name="grid"/> Floor plan</a>
  @if(can_do('reports.view'))<a class="btn" href="{{ route('reports.show', 'table_bookings') }}"><x-icon name="chart"/> Register</a>@endif
  @if(can_do('tables.book'))<a class="btn btn-primary" href="#" data-modal="bookModal" id="newBookingBtn"><x-icon name="plus"/> New booking</a>@endif
@endif
@endsection
@section('content')
@if(!$outlet)
  <div class="alert alert-warn"><x-icon name="alert"/><div>No outlet takes table reservations yet. @if(can_do('tables.manage'))<a href="{{ route('tb.settings') }}">Switch them on in Reservation Settings</a>.@else Ask a manager to switch them on.@endif</div></div>
@else
@if($outlets->count() > 1)<div class="tabs">@foreach($outlets as $o)<a href="{{ route('tb.index', ['outlet' => $o->id, 'date' => $date->toDateString()]) }}" class="{{ $o->id === $outlet->id ? 'active' : '' }}">{{ $o->name }}</a>@endforeach</div>@endif
@php
  $isToday = $date->isSameDay(now());
  $hours = $to - $from;
  $base = $date->copy()->setTime($from, 0);
  $pct = fn ($min) => round(max(0, min(100, $min / ($hours * 60) * 100)), 3);
  $flagged = collect($history)->filter(fn ($h) => $flag > 0 && $h['no_shows'] >= $flag)->keys()->all();
@endphp
<form class="filters" id="dayNav">
  <input type="hidden" name="outlet" value="{{ $outlet->id }}">
  <a class="btn btn-sm" href="{{ route('tb.index', ['outlet' => $outlet->id, 'date' => $date->copy()->subDay()->toDateString()]) }}" title="Previous day">‹</a>
  <input type="date" name="date" value="{{ $date->toDateString() }}" onchange="this.form.submit()" id="dayPick">
  <a class="btn btn-sm" href="{{ route('tb.index', ['outlet' => $outlet->id, 'date' => $date->copy()->addDay()->toDateString()]) }}" title="Next day">›</a>
  @unless($isToday)<a class="btn btn-sm" href="{{ route('tb.index', ['outlet' => $outlet->id]) }}">Today</a>@endunless
  <span class="bold ml-1">{{ $date->format('l, d M Y') }}</span>
  <span class="muted small">@forelse($periods as $p){{ $p->name }} {{ substr($p->start_time, 0, 5) }}–{{ substr($p->end_time, 0, 5) }}@if(!$loop->last) · @endif @empty Closed (no service periods)@endforelse</span>
</form>
<div class="stats">
  <x-stat label="Bookings" :value="$stats['bookings']" icon="calendar" tone="blue" :sub="$stats['covers'].' covers'.($stats['unconfirmed'] ? ' · '.$stats['unconfirmed'].' unconfirmed' : '')"/>
  <x-stat label="Still to arrive" :value="$stats['to_come']" icon="clock" tone="amber" :sub="$stats['to_come_covers'].' covers'"/>
  <x-stat label="Seated now" :value="$stats['seated']" icon="cup" tone="green" :sub="$stats['served_covers'].' covers served incl. walk-ins'"/>
  <x-stat label="No-shows / cancelled" :value="$stats['no_shows'].' / '.$stats['cancelled']" icon="x" :tone="$stats['no_shows'] ? 'red' : 'slate'" :sub="$stats['walkins'].' walk-ins · '.$stats['waiting'].' waiting'"/>
</div>

<div class="card"><div class="card-head"><h3>Tables · {{ $date->format('d M') }}</h3>
  <div class="legend"><span><i style="background:#fef3c7;border:1px solid #f59e0b"></i>Unconfirmed</span><span><i style="background:#dbeafe;border:1px solid #3b82f6"></i>Confirmed</span><span><i style="background:#dcfce7;border:1px solid #16a34a"></i>Seated</span><span><i style="background:#f1f5f9;border:1px solid #cbd5e1"></i>Completed</span>@if($isToday)<span><i style="background:#dc2626;width:3px"></i>Now</span>@endif</div></div>
  <div class="card-body"><div class="tb-tl" id="tbTimeline" style="--hours: {{ $hours }}"><div class="tb-tl-inner">
    <div class="tb-tl-row tb-tl-head"><div class="tb-tl-label">Table</div><div class="tb-tl-track">@for($h = $from; $h < $to; $h++)<span>{{ sprintf('%02d:00', $h % 24) }}</span>@endfor</div></div>
    @foreach($tables->groupBy(fn ($t) => $t->section ?: 'Tables') as $sec => $group)
      <div class="tb-tl-sec">{{ $sec }}</div>
      @foreach($group as $t)
      <div class="tb-tl-row"><div class="tb-tl-label">{{ $t->table_no }}<small>{{ $t->capacity }} seats</small>@if($t->blocked_reason) <span class="badge badge-slate" title="{{ $t->blocked_reason }}">blocked</span>@endif</div>
        <div class="tb-tl-track">
          @foreach($periods as $p)@php $ps = $base->diffInMinutes($date->copy()->setTimeFromTimeString($p->start_time), false); $pe = $base->diffInMinutes($date->copy()->setTimeFromTimeString($p->end_time), false) + 30; @endphp<div class="tb-period" style="left:{{ $pct($ps) }}%;width:{{ max(0, $pct($pe) - $pct($ps)) }}%"></div>@endforeach
          @if($isToday)<div class="tb-now" style="left:{{ $pct($base->diffInMinutes(now(), false)) }}%"></div>@endif
          @foreach($timeline->filter(fn ($r) => $r->tables->contains('id', $t->id)) as $r)
            @php $s = $base->diffInMinutes($r->start_at, false); $e = $base->diffInMinutes($r->status === 'completed' && $r->completed_at ? $r->completed_at : $r->end_at, false); @endphp
            <a class="tb-blk {{ $r->status }} {{ in_array($r->id, $flagged) ? 'flag' : '' }}" href="{{ route('tb.show', $r) }}" style="left:{{ $pct($s) }}%;width:calc({{ max(1.2, $pct($e) - $pct($s)) }}% - 2px)"
               title="{{ $r->start_at->format('H:i') }} {{ $r->name }} ({{ $r->party_size }}) · {{ $r->tableLabel() }} · {{ strip_tags(tb_badge($r->status)) }}">{{ $r->start_at->format('H:i') }} <b>{{ $r->name }}</b> · {{ $r->party_size }}@if($r->tables->count() > 1) · {{ $r->tableLabel() }}@endif</a>
          @endforeach
        </div></div>
      @endforeach
    @endforeach
  </div></div></div></div>

<div class="card flush">
  <div class="card-head"><div class="tabs" style="margin:0;border:0">
    @foreach(\App\Http\Controllers\TableBookingController::TABS as $k => $l)<a href="{{ route('tb.index', array_filter(['outlet' => $outlet->id, 'date' => $date->toDateString(), 'tab' => $k, 'q' => request('q')])) }}" class="{{ $tab === $k ? 'active' : '' }}">{{ $l }} <span class="muted">{{ $counts[$k] }}</span></a>@endforeach
  </div>
  <form class="filters" style="margin:0"><input type="hidden" name="outlet" value="{{ $outlet->id }}"><input type="hidden" name="date" value="{{ $date->toDateString() }}"><input type="hidden" name="tab" value="{{ $tab }}"><input name="q" value="{{ request('q') }}" placeholder="Name, phone or ref"></form></div>
  <div class="card-body table-wrap"><table class="table" id="tbTable">
    <thead><tr><th>Time</th><th>Guest</th><th class="num">Pax</th><th>Table</th><th>Source</th><th>Status</th><th class="right">Actions</th></tr></thead><tbody>
    @forelse($items as $r)
    <tr class="{{ $r->status === 'booked' ? 'bold-row' : '' }} {{ in_array($r->status, ['cancelled', 'no_show', 'left']) ? 'muted-row' : '' }}" data-ref="{{ $r->booking_no }}">
      <td class="nowrap"><b>{{ $r->type === 'waitlist' ? $r->created_at->format('H:i') : $r->start_at->format('H:i') }}</b>@if($r->isUpcoming() && $r->start_at->lt(now()))<div class="tiny text-red bold">late {{ (int) $r->start_at->diffInMinutes(now()) }} min</div>@endif<div class="tiny muted"><a href="{{ route('tb.show', $r) }}">{{ $r->booking_no }}</a></div></td>
      <td><a href="{{ route('tb.show', $r) }}" class="bold">{{ $r->name }}</a>@if($r->stay?->room) <span class="badge badge-purple">Rm {{ $r->stay->room->room_no }}</span>@endif @if($r->guest?->is_vip)<span class="badge badge-amber">VIP</span>@endif
        @if(in_array($r->id, $flagged))<span class="badge badge-red" title="Previous no-shows">{{ $history[$r->id]['no_shows'] }} no-shows</span>@elseif(($history[$r->id]['visits'] ?? 0) > 0)<span class="badge badge-green">{{ $history[$r->id]['visits'] }} visits</span>@endif
        <div class="tiny muted">{{ $r->phone }}@if($r->occasion) · {{ \App\Models\TableReservation::OCCASIONS[$r->occasion] ?? $r->occasion }}@endif @foreach((array) $r->tags as $tg)· {{ \App\Models\TableReservation::TAGS[$tg] ?? $tg }} @endforeach</div>
        @if($r->notes)<div class="tiny" style="max-width:360px">{{ \Illuminate\Support\Str::limit($r->notes, 90) }}</div>@endif</td>
      <td class="num bold">{{ $r->party_size }}</td>
      <td class="nowrap">{{ $r->tableLabel() ?: '—' }}</td>
      <td class="small">{{ $r->type === 'waitlist' ? 'Waitlist' : (\App\Models\TableReservation::SOURCES[$r->source] ?? $r->source) }}</td>
      <td>{!! tb_badge($r->status) !!}@if($r->status === 'seated' && $r->seated_at)<div class="tiny muted">since {{ $r->seated_at->format('H:i') }}</div>@endif</td>
      <td class="right nowrap">@if(can_do('tables.book'))
        @if($r->status === 'booked')<form method="post" action="{{ route('tb.confirm', $r) }}" class="inline">@csrf<button class="btn btn-sm">Confirm</button></form>@endif
        @if(in_array($r->status, ['booked', 'confirmed', 'waiting', 'notified']) && $isToday)<form method="post" action="{{ route('tb.seat', $r) }}" class="inline">@csrf<button class="btn btn-sm btn-success seat-btn">Seat</button></form>@endif
        @if($r->isUpcoming() && $r->start_at->lte(now()))<form method="post" action="{{ route('tb.noshow', $r) }}" class="inline">@csrf<button class="btn btn-sm btn-ghost" title="Mark as no-show">No-show</button></form>@endif
        @if($r->status === 'seated' && $r->pos_order_id && can_do('pos.view'))<a class="btn btn-sm" href="{{ route('pos.terminal', $r->pos_order_id) }}">Order</a>@endif
      @endif</td></tr>
    @empty<tr><td colspan="7"><x-empty text="No bookings here." icon="calendar"/></td></tr>@endforelse
  </tbody></table></div>
</div>
@if(can_do('tables.book'))@include('tables._form', ['outlet' => $outlet, 'tables' => $tables, 'inHouse' => $inHouse])@endif
@endif
@endsection
