@extends('layouts.app')
@section('title', $r->booking_no)
@section('crumbs') <a href="{{ route('tb.index', ['outlet' => $r->outlet_id, 'date' => $r->date->toDateString()]) }}">Table reservations</a> / {{ $r->booking_no }} @endsection
@section('actions')
<a class="btn" href="{{ route('tb.index', ['outlet' => $r->outlet_id, 'date' => $r->date->toDateString()]) }}"><x-icon name="calendar"/> Day sheet</a>
<a class="btn" href="{{ route('tb.floor', ['outlet' => $r->outlet_id]) }}"><x-icon name="grid"/> Floor plan</a>
@endsection
@section('content')
@php $canBook = can_do('tables.book'); $today = $r->date->isSameDay(now()) || $r->isWaiting(); @endphp
<div class="grid g-2-1">
  <div>
    <div class="card" id="tbHead"><div class="card-body">
      <div class="flex items-center" style="gap:.6rem;flex-wrap:wrap">
        <h2 style="margin:0">{{ $r->name }}</h2> {!! tb_badge($r->status) !!}
        <span class="badge badge-slate">{{ $r->type === 'waitlist' ? 'Waitlist' : ($r->type === 'walkin' ? 'Walk-in' : \App\Models\TableReservation::SOURCES[$r->source] ?? $r->source) }}</span>
        @if($r->stay?->room)<span class="badge badge-purple">In-house · Room {{ $r->stay->room->room_no }}</span>@endif
        @if($r->guest?->is_vip)<span class="badge badge-amber">VIP</span>@endif
        @if($flagged)<span class="badge badge-red" id="noShowFlag">{{ $history['no_shows'] }} previous no-shows</span>@endif
      </div>
      <div class="kv-grid mt-2">
        <div class="kv"><span>Reference</span><b>{{ $r->booking_no }}</b></div>
        <div class="kv"><span>{{ $r->type === 'waitlist' ? 'Added' : 'Date & time' }}</span><b>{{ ($r->type === 'waitlist' ? $r->created_at : $r->start_at)->format('D d M Y, H:i') }}</b></div>
        <div class="kv"><span>Guests</span><b>{{ $r->party_size }}</b></div>
        <div class="kv"><span>Table</span><b id="tbTables">{{ $r->tableLabel() ?: 'not assigned yet' }}</b></div>
        <div class="kv"><span>Dining time</span><b>{{ $r->duration }} min @if($r->type === 'booking')(until {{ $r->end_at->format('H:i') }})@endif</b></div>
        <div class="kv"><span>Outlet</span><b>{{ $r->outlet->name }}</b></div>
        <div class="kv"><span>Phone</span><b>{{ $r->phone ?: '—' }}</b></div>
        <div class="kv"><span>E-mail</span><b>{{ $r->email ?: '—' }}</b></div>
        @if($r->occasion)<div class="kv"><span>Occasion</span><b>{{ \App\Models\TableReservation::OCCASIONS[$r->occasion] ?? $r->occasion }}</b></div>@endif
        @if($r->section_pref)<div class="kv"><span>Seating preference</span><b>{{ $r->section_pref }}</b></div>@endif
        @if($r->quoted_minutes !== null)<div class="kv"><span>Quoted wait</span><b>{{ $r->quoted_minutes }} min @if($r->waitedMinutes() !== null)· waited {{ $r->waitedMinutes() }} min @elseif($r->isWaiting())· waiting {{ (int) $r->created_at->diffInMinutes(now()) }} min @endif</b></div>@endif
        @if($r->seated_at)<div class="kv"><span>Seated</span><b>{{ $r->seated_at->format('H:i') }}@if($r->type === 'booking' && $r->seated_at->gt($r->start_at->copy()->addMinutes(5))) <span class="text-amber">({{ (int) $r->start_at->diffInMinutes($r->seated_at) }} min late)</span>@endif</b></div>@endif
        @if($r->completed_at)<div class="kv"><span>Left</span><b>{{ $r->completed_at->format('H:i') }} · {{ $r->dinedMinutes() }} min at the table</b></div>@endif
        @if($r->order)<div class="kv"><span>POS order</span><b>@if(can_do('pos.view'))<a href="{{ route('pos.terminal', $r->order) }}">{{ $r->order->order_no }}</a>@else{{ $r->order->order_no }}@endif · {{ money($r->order->total) }} · {!! status_badge($r->order->status) !!}</b></div>@endif
        @if($r->cancel_reason)<div class="kv"><span>Reason</span><b>{{ $r->cancel_reason }}</b></div>@endif
      </div>
      @if($r->tags)<div class="mt-1">@foreach($r->tags as $tg)<span class="tb-tag">{{ \App\Models\TableReservation::TAGS[$tg] ?? $tg }}</span> @endforeach</div>@endif
      @if($r->notes)<div class="alert alert-info mt-1"><x-icon name="message"/><div>{{ $r->notes }}</div></div>@endif
    </div>
    @if($canBook)
    <div class="card-foot flex" style="gap:.4rem;flex-wrap:wrap" id="tbActions">
      @if($r->status === 'booked')<form method="post" action="{{ route('tb.confirm', $r) }}">@csrf<button class="btn btn-primary" id="confirmBtn"><x-icon name="check"/> Confirm</button></form>@endif
      @if(in_array($r->status, ['booked', 'confirmed', 'waiting', 'notified']) && $today)<button class="btn btn-success" data-modal="seatModal" id="seatBtn"><x-icon name="cup"/> Seat</button>@endif
      @if($r->isWaiting())<form method="post" action="{{ route('tb.notify', $r) }}">@csrf<button class="btn" id="notifyBtn"><x-icon name="phone"/> Table ready – {{ $r->status === 'notified' ? 'call again' : 'call guest' }}</button></form>@endif
      @if($r->isUpcoming())<button class="btn" data-modal="editModal" id="editBtn"><x-icon name="edit"/> Change</button>@endif
      @if($r->isUpcoming() && ($r->phone || $r->email))<form method="post" action="{{ route('tb.resend', $r) }}">@csrf<button class="btn btn-ghost" id="resendBtn"><x-icon name="mail"/> Re-send confirmation</button></form>@endif
      @if($r->isUpcoming() && $r->start_at->lte(now()))<form method="post" action="{{ route('tb.noshow', $r) }}">@csrf<button class="btn btn-ghost" id="noShowBtn">No-show</button></form>@endif
      @if($r->isUpcoming() || $r->isWaiting())<button class="btn btn-danger ml-auto" data-modal="cancelModal" id="cancelBtn"><x-icon name="x"/> {{ $r->isWaiting() ? 'Remove from waitlist' : 'Cancel booking' }}</button>@endif
      @if($r->order && can_do('pos.view'))<a class="btn {{ $r->status === 'seated' ? 'btn-primary' : '' }}" id="orderBtn" href="{{ route('pos.terminal', $r->order) }}"><x-icon name="receipt"/> {{ $r->status === 'seated' ? 'Open POS order' : 'View order' }} {{ $r->order->order_no }}</a>@endif
      @if($r->status === 'seated')<a class="btn btn-ghost" href="{{ route('tb.floor', ['outlet' => $r->outlet_id]) }}"><x-icon name="grid"/> Move / join tables on the floor plan</a>@endif
      @if(in_array($r->status, ['cancelled', 'no_show', 'left']))<form method="post" action="{{ route('tb.reinstate', $r) }}">@csrf<button class="btn" id="reinstateBtn"><x-icon name="refresh"/> Reinstate</button></form>@endif
    </div>
    @endif
    </div>
  </div>
  <div>
    <div class="card" id="tbHistory"><div class="card-head"><h3>Guest history</h3>@if($r->guest && can_do('guests.view'))<a href="{{ route('guests.show', $r->guest) }}" class="small">Profile →</a>@endif</div><div class="card-body">
      <div class="kv"><span>Visits</span><b>{{ $history['visits'] }}</b></div>
      <div class="kv"><span>No-shows</span><b class="{{ $history['no_shows'] ? 'text-red' : '' }}">{{ $history['no_shows'] }}</b></div>
      <div class="kv"><span>Cancelled</span><b>{{ $history['cancelled'] }}</b></div>
      <div class="kv"><span>Last visit</span><b>{{ $history['last'] ? \Illuminate\Support\Carbon::parse($history['last'])->format('d M Y') : '—' }}</b></div>
      <p class="tiny muted mt-1">Other bookings with this phone number or guest profile.</p>
    </div></div>
    <div class="card"><div class="card-head"><h3>Activity</h3></div><div class="card-body"><ul class="timeline" id="tbLog">
      <li class="tl-info"><b>Created</b> <span class="muted small">· {{ $r->created_at->format('d M H:i') }}{{ $r->creator ? ' · '.$r->creator->name : ($r->source === 'online' ? ' · online' : '') }}</span></li>
      @foreach($log->where('action', '!=', 'book') as $l)<li class="{{ in_array($l->action, ['cancel', 'no_show', 'waitlist_left']) ? 'tl-bad' : (in_array($l->action, ['seat', 'confirm']) ? 'tl-ok' : 'tl-info') }}"><b>{{ ucfirst(str_replace('_', ' ', $l->action)) }}</b> <span class="muted small">· {{ $l->created_at->format('d M H:i') }}{{ $l->user ? ' · '.$l->user->name : '' }}</span><div class="small">{{ $l->description }}</div></li>@endforeach
      @if($r->completed_at)<li class="tl-ok"><b>Bill settled – party left</b> <span class="muted small">· {{ $r->completed_at->format('d M H:i') }}</span></li>@endif
    </ul></div></div>
  </div>
</div>

@if($canBook)
<div class="modal" id="seatModal"><div class="modal-box"><form method="post" action="{{ route('tb.seat', $r) }}">@csrf
  <div class="modal-head"><h3>Seat {{ $r->name }} ({{ $r->party_size }})</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><p class="small muted">{{ $r->tables->isNotEmpty() ? 'Assigned: '.$r->tableLabel().'. Tick other tables to seat the party elsewhere.' : 'Tick the tables, or leave empty to use the best free table.' }}</p>
    <div class="tbl-picks">@foreach($tables as $t)<label><input type="checkbox" name="table_ids[]" value="{{ $t->id }}" @checked($r->tables->contains('id', $t->id))><span>{{ $t->table_no }} · {{ $t->capacity }}</span></label>@endforeach</div>
    @if(can_do('pos.order'))<label class="check mt-2"><input type="checkbox" name="go" value="pos" checked> Open the POS order next</label>@endif
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-success" id="confirmSeat">Seat now</button></div>
</form></div></div>
<div class="modal" id="cancelModal"><div class="modal-box"><form method="post" action="{{ route('tb.cancel', $r) }}">@csrf
  <div class="modal-head"><h3>{{ $r->isWaiting() ? 'Remove from the waitlist' : 'Cancel booking '.$r->booking_no }}</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="reason" label="Reason" required :placeholder="$r->isWaiting() ? 'Left / found elsewhere' : 'Guest called to cancel'"/>
    @if(!$r->isWaiting() && ($r->phone || $r->email))<label class="check"><input type="checkbox" name="notify" value="1" checked> Send the guest a cancellation message</label>@endif</div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Back</button><button class="btn btn-danger" id="confirmCancel">{{ $r->isWaiting() ? 'Remove' : 'Cancel booking' }}</button></div>
</form></div></div>
@if($r->isUpcoming())
<div class="modal" id="editModal"><div class="modal-box" style="max-width:720px"><form method="post" action="{{ route('tb.update', $r) }}">@csrf @method('PUT')
  <div class="modal-head"><h3>Change {{ $r->booking_no }}</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <x-field name="date" label="Date" type="date" :value="$r->date->toDateString()" required/>
    <x-field name="time" label="Time" type="time" :value="$r->start_at->format('H:i')" required/>
    <x-field name="party_size" label="Guests" type="number" :value="$r->party_size" required/>
    <x-field name="duration" label="Dining time (min)" type="number" :value="$r->duration" hint="changing the party size resets this"/>
    <x-field name="name" label="Guest name" :value="$r->name" required/>
    <x-field name="phone" label="Phone" :value="$r->phone"/>
    <x-field name="email" label="E-mail" type="email" :value="$r->email"/>
    <x-field name="occasion" label="Occasion" type="select" :options="\App\Models\TableReservation::OCCASIONS" :value="$r->occasion" placeholder="—"/>
    <div class="field span-all"><label>Requests</label><input type="hidden" name="tags" value=""><div class="tbl-picks">@foreach(\App\Models\TableReservation::TAGS as $k => $l)<label><input type="checkbox" name="tags[]" value="{{ $k }}" @checked(in_array($k, (array) $r->tags))><span>{{ $l }}</span></label>@endforeach</div></div>
    <x-field name="notes" label="Notes" type="textarea" :value="$r->notes" class="span-all"/>
    <div class="field span-all"><label>Tables <span class="muted small">(leave all unticked to re-assign automatically)</span></label><div class="tbl-picks">@foreach($tables as $t)<label><input type="checkbox" name="table_ids[]" value="{{ $t->id }}" @checked($r->tables->contains('id', $t->id))><span>{{ $t->table_no }} · {{ $t->capacity }}</span></label>@endforeach</div></div>
    @if($r->phone || $r->email)<label class="check span-all"><input type="checkbox" name="notify" value="1" checked> Tell the guest if the date, time or party size changes</label>@endif
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" id="saveEdit">Save changes</button></div>
</form></div></div>
@endif
@endif
@endsection
