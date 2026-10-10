@extends('layouts.app')
@section('title', 'Booking '.$b->number)
@section('crumbs') <a href="{{ route('concierge.bookings') }}">Concierge</a> / {{ $b->number }} @endsection
@php $B = \App\Models\ActivityBooking::class; $a = $b->activity; $canBook = can_do('concierge.book'); $open = $b->isOpen(); @endphp
@section('actions')
  @if(in_array($b->status, ['confirmed', 'completed'], true))
    <a class="btn" href="{{ route('concierge.voucher', $b) }}" target="_blank"><x-icon name="print"/> Voucher</a>
    @if($canBook)<form method="post" action="{{ route('concierge.send', $b) }}" class="inline">@csrf<button class="btn" id="sendVoucherBtn"><x-icon name="message"/> Send to guest</button></form>@endif
  @endif
  @if($b->departure)<a class="btn" href="{{ route('concierge.manifest', $b->departure) }}" target="_blank"><x-icon name="users"/> Manifest</a>@endif
@endsection
@section('content')
<div class="flex gap-1 mb-1" style="align-items:center"><h2 class="m-0">{{ $a->name }}</h2><span class="badge badge-{{ $B::TONES[$b->status] }}" id="bStatus">{{ $b->statusLabel() }}</span>@if($b->activity_settlement_id)<a class="badge badge-slate" href="{{ route('concierge.settlement', $b->activity_settlement_id) }}">settled · {{ $b->settlement?->number }}</a>@endif</div>

<div class="g-2-1">
<div>
<div class="card"><div class="card-head"><h3><x-icon name="calendar"/> Booking</h3></div><div class="card-body"><dl class="kv" id="bookingKv">
  <dt>Number</dt><dd>{{ $b->number }}{{ $b->vendor_ref ? ' · vendor ref '.$b->vendor_ref : '' }}</dd>
  <dt>When</dt><dd>{{ $b->date->format('l d M Y') }} at {{ $b->time }} · {{ $a->durationLabel() }}</dd>
  <dt>Guests</dt><dd>{{ $b->paxLabel() }}</dd>
  <dt>Operator</dt><dd>{{ $b->vendor->name }}{{ $b->vendor->phone ? ' · '.$b->vendor->phone : '' }}</dd>
  <dt>Meet / pick-up</dt><dd>{{ $b->pickup_point ? $b->pickup_point.($b->pickup_time ? ' at '.$b->pickup_time : '') : ($a->pickup_included ? 'Hotel pick-up (operator sets the time)' : ($a->meeting_point ?: '—')) }}</dd>
  <dt>Guest</dt><dd>{{ $b->guest_name }}{{ $b->room_no ? ' · room '.$b->room_no : ' · non-resident' }}@if($b->stay) · <a href="{{ route('reservations.show', $b->reservation_id) }}">{{ $b->stay->reservation?->reservation_no }}</a>@endif</dd>
  <dt>Contact</dt><dd>{{ collect([$b->phone, $b->email])->filter()->implode(' · ') ?: '—' }}</dd>
  <dt>Source</dt><dd>{{ $B::SOURCES[$b->source] ?? $b->source }} · {{ $b->creator?->name ?? 'Guest' }} · {{ $b->created_at->format('d M H:i') }}</dd>
  @if($b->confirmed_at)<dt>Confirmed</dt><dd>{{ $b->confirmed_at->format('d M H:i') }}{{ $b->confirmer ? ' by '.$b->confirmer->name : '' }}</dd>@endif
  @if($b->notes)<dt>Notes</dt><dd>{{ $b->notes }}</dd>@endif
  @if($b->cancel_reason)<dt>{{ $b->status === 'no_show' ? 'No-show' : 'Cancelled' }}</dt><dd>{{ $b->cancel_reason }}{{ $b->cancelled_at ? ' · '.$b->cancelled_at->format('d M H:i') : '' }}</dd>@endif
</dl></div></div>

@if($canBook && $open)
<div class="card mt-1" id="actionsCard"><div class="card-head"><h3><x-icon name="edit"/> Actions</h3></div><div class="card-body">
  <div class="flex gap-1 wrap">
    @if($b->status === 'requested')
      <form method="post" action="{{ route('concierge.confirm', $b) }}" class="flex gap-1">@csrf<input name="vendor_ref" placeholder="Vendor ref (optional)" maxlength="60" aria-label="Vendor reference"><button class="btn btn-primary" id="confirmBtn"><x-icon name="check"/> Vendor confirmed</button></form>
    @endif
    @if($b->status === 'confirmed')
      <form method="post" action="{{ route('concierge.complete', $b) }}" data-confirm="Mark as completed and book the commission?">@csrf<button class="btn btn-primary" id="completeBtn"><x-icon name="check"/> Completed</button></form>
      @if($b->date->lte(bdate()))<form method="post" action="{{ route('concierge.noshow', $b) }}" data-confirm="Record a no-show? The guest is still charged.">@csrf<button class="btn" id="noShowBtn">No-show</button></form>@endif
    @endif
    <button class="btn" data-modal="editModal" id="editBtn"><x-icon name="edit"/> Change</button>
    <button class="btn btn-danger" data-modal="cancelModal" id="cancelBtn"><x-icon name="x"/> Cancel booking</button>
  </div>
  <p class="tiny muted mt-1 m-0">Free cancellation until {{ $b->freeCancelUntil()->format('D d M H:i') }}.@if($fee > 0) Cancelling now costs <b id="feeNow">{{ money($fee) }}</b> ({{ rtrim(rtrim(number_format((float) $a->cancel_fee_pct, 1), '0'), '.') }}%).@endif</p>
</div></div>
@endif

@if($log->isNotEmpty() || $messages->isNotEmpty())
<div class="card mt-1 flush"><div class="card-head"><h3><x-icon name="clock"/> History</h3></div><div class="card-body table-wrap"><table class="table table-compact">
  <tbody>@foreach($log as $l)<tr><td class="small nowrap">{{ $l->created_at->format('d M H:i') }}</td><td class="small">{{ $l->description }}</td><td class="tiny muted">{{ $l->user?->name }}</td></tr>@endforeach
  @foreach($messages as $m)<tr><td class="small nowrap">{{ $m->created_at->format('d M H:i') }}</td><td class="small"><x-icon name="message" size="sm"/> {{ strtoupper($m->channel) }} to {{ $m->to }} – {{ \Illuminate\Support\Str::limit($m->body, 110) }}</td><td class="tiny muted">{{ $m->status }}</td></tr>@endforeach</tbody>
</table></div></div>
@endif
</div>

<div>
<div class="card" id="moneyCard"><div class="card-head"><h3><x-icon name="money"/> Money</h3></div><div class="card-body"><dl class="kv">
  <dt>Price</dt><dd>{{ $a->pricing === 'group' ? money($b->price_adult).' private group' : money($b->price_adult).' adult'.((int) $b->children ? ' · '.money($b->price_child).' child' : '') }}</dd>
  <dt>{{ $b->status === 'cancelled' ? 'Booked value' : 'Guest pays' }}</dt><dd id="mGross">{{ money($b->gross) }}</dd>
  <dt>Commission</dt><dd id="mComm">{{ money($b->commission) }} ({{ rtrim(rtrim(number_format((float) $b->commission_pct, 2), '0'), '.') }}%)</dd>
  <dt>Operator's share</dt><dd>{{ money($b->vendor_net) }}</dd>
  @if((float) $b->cancel_fee > 0)<dt>Cancellation fee</dt><dd>{{ money($b->cancel_fee) }}</dd>@endif
  <dt>Paid</dt><dd>{{ $b->collectionLabel() }}</dd>
  @if($b->folioTransaction)<dt>Room charge</dt><dd>{{ $b->folio?->folio_no ?? 'Folio' }} · {{ money($b->folioTransaction->amount) }}{{ $b->folioTransaction->is_void ? ' (void)' : '' }}</dd>@endif
  @if($b->journal)<dt>Commission JE</dt><dd>{{ $b->journal->journal_no }} · {{ $b->recognised_on?->format('d M Y') }}</dd>@endif
</dl>
@if($b->collection === 'folio')<p class="tiny muted mt-1 m-0">The hotel collects the full price on the room bill and owes the operator {{ money($b->vendor_net) }}; only the commission is hotel revenue.</p>
@else<p class="tiny muted mt-1 m-0">The guest pays the operator, who owes the hotel {{ money($b->commission) }} commission once the activity takes place.</p>@endif
</div></div>
@if($b->departure)
<div class="card mt-1"><div class="card-head"><h3><x-icon name="users"/> Departure</h3></div><div class="card-body small">
  {{ $b->departure->date->format('D d M') }} {{ $b->departure->time }} · {{ \App\Models\ActivityDeparture::STATUSES[$b->departure->status] }} · {{ $b->departure->booked() }}{{ $b->departure->capacity ? ' / '.$b->departure->capacity : '' }} seats taken{{ $b->departure->guide ? ' · guide '.$b->departure->guide : '' }}
</div></div>
@endif
</div>
</div>

@if($canBook && $open)
<div class="modal" id="editModal"><div class="modal-box"><form method="post" action="{{ route('concierge.update', $b) }}">@csrf @method('PUT')
  <div class="modal-head"><h3>Change {{ $b->number }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field"><label>Date</label><input type="date" name="date" value="{{ $b->date->toDateString() }}" required></div>
    <div class="field"><label>Time</label>@if($a->isScheduled())<select name="time">@foreach($a->timesList() as $t)<option @selected($b->time === $t)>{{ $t }}</option>@endforeach</select>@else<input type="time" name="time" value="{{ $b->time }}" required>@endif</div>
    <div class="field"><label>Adults</label><input type="number" name="adults" min="1" max="30" value="{{ $b->adults }}" required></div>
    <div class="field"><label>Children</label><input type="number" name="children" min="0" max="30" value="{{ $b->children }}"></div>
    <div class="field"><label>Pick-up point</label><input name="pickup_point" maxlength="150" value="{{ $b->pickup_point }}"></div>
    <div class="field"><label>Pick-up time</label><input type="time" name="pickup_time" value="{{ $b->pickup_time }}"></div>
    <div class="field"><label>Mobile</label><input name="phone" maxlength="30" value="{{ $b->phone }}"></div>
    <div class="field span-all"><label>Notes</label><input name="notes" maxlength="500" value="{{ $b->notes }}"></div>
    @if(can_do('concierge.manage'))<label class="flex gap-1 span-all small"><input type="checkbox" name="override_cutoff" value="1"> Inside the operator's cut-off (the vendor has agreed)</label>@endif
  </div><p class="tiny muted">A confirmed booking's room charge is re-posted at the new price.</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save</button></div>
</form></div></div>

<div class="modal" id="cancelModal"><div class="modal-box"><form method="post" action="{{ route('concierge.cancel', $b) }}">@csrf
  <div class="modal-head"><h3>Cancel {{ $b->number }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <div class="field"><label>Reason</label><input name="reason" required maxlength="190" placeholder="Guest changed plans"></div>
    @if($fee > 0)<div class="alert alert-warn">A cancellation fee of <b>{{ money($fee) }}</b> will be charged to the room; the hotel keeps its share of it as commission.</div>
      @if(can_do('concierge.manage'))<label class="flex gap-1 small"><input type="checkbox" name="waive" value="1" id="waiveFee"> Waive the fee (manager)</label>@endif
    @else<p class="small muted">No charge – within the free cancellation period.</p>@endif
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Keep booking</button><button class="btn btn-danger">Cancel booking</button></div>
</form></div></div>
@endif
@endsection
