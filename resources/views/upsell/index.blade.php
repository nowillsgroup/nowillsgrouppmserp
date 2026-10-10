@extends('layouts.app')
@section('title', 'Upselling')
@section('crumbs') Revenue / Upselling / {{ \App\Http\Controllers\UpsellController::TABS[$tab] }} @endsection
@section('actions')
  @if(can_do('upsell.manage'))
    <form method="post" action="{{ route('upsell.run') }}" class="inline" data-confirm="E-mail offers now to every confirmed guest arriving in the next {{ $cfg['days_before'] }} day(s) who has not had them yet?">@csrf<button class="btn" id="runNowBtn"><x-icon name="mail"/> Send due offers now</button></form>
    <button class="btn btn-primary" data-modal="offerModal" id="addOfferBtn"><x-icon name="plus"/> New offer</button>
  @endif
@endsection
@section('content')
@if(! $cfg['enabled'])<div class="alert alert-warn" id="upsellOffAlert"><x-icon name="alert"/><div><b>Upselling is switched off.</b> Guests see no offers and the desk cannot sell them. @if(can_do('upsell.manage'))<a href="{{ route('upsell.index', ['tab' => 'settings']) }}">Settings</a>@endif</div></div>@endif

<div class="stats">
  <x-stat label="Upsell revenue (month to date)" :value="money($mtd['revenue'])" icon="trend" tone="green" :href="route('upsell.index', ['tab' => 'orders', 'status' => 'confirmed'])" :sub="$mtd['orders'].' confirmed order(s)'"/>
  <x-stat label="Conversion (30 days)" :value="$last30['conversion'] === null ? '–' : $last30['conversion'].'%'" icon="chart" tone="blue" :sub="$last30['converted'].' of '.$last30['presented'].' guests offered bought'"/>
  <x-stat label="Requests awaiting confirmation" :value="$pending" icon="clock" :tone="$pending ? 'amber' : 'green'" :href="route('upsell.index', ['tab' => 'requests'])" sub="early check-in / late check-out"/>
  <x-stat label="Staff incentives (month to date)" :value="money($mtd['incentives'])" icon="wallet" tone="purple" sub="earned on desk sales"/>
</div>

<div class="card flush">
  <div class="card-head"><div class="tabs" style="margin:0;border:0">
    @foreach(\App\Http\Controllers\UpsellController::TABS as $k => $l)
      @if($k !== 'settings' || can_do('upsell.manage'))<a href="{{ route('upsell.index', ['tab' => $k]) }}" class="{{ $tab === $k ? 'active' : '' }}" data-tab="{{ $k }}">{{ $l }}@if($k === 'requests' && $pending) <span class="badge badge-amber">{{ $pending }}</span>@endif</a>@endif
    @endforeach
  </div></div>

@if($tab === 'overview')
  <div class="card-body pad"><div class="grid g-2-1" style="gap:1.2rem">
    <div>
      <h4 class="mb-1"><x-icon name="clock"/> Today: early arrivals &amp; late departures</h4>
      @if($today->isEmpty())<p class="small muted" id="todayEmpty">No paid early check-ins or late check-outs today.</p>
      @else
      <div class="table-wrap"><table class="table" id="todayTable"><thead><tr><th>Guest</th><th>Room</th><th>Arranged</th><th class="right">Charge</th></tr></thead><tbody>
        @foreach($today as $o)<tr><td><a href="{{ route('reservations.show', $o->reservation_id) }}">{{ $o->reservation->guest?->full_name }}</a><div class="tiny muted">{{ $o->reservation->reservation_no }}</div></td>
          <td>{{ $o->stay->room?->room_no ?? 'not assigned' }}</td><td><span class="badge badge-{{ $o->kind === 'early_checkin' ? 'blue' : 'purple' }}">{{ $o->offer->kindLabel() }}</span> {{ $o->offer->timeLabel() }}</td><td class="right">{{ money($o->amount) }}</td></tr>@endforeach
      </tbody></table></div>
      <p class="tiny muted">Housekeeping: prioritise early-arrival rooms; hold late-departure rooms until the time shown.</p>
      @endif

      <h4 class="mb-1 mt-2"><x-icon name="calendar"/> Arriving in the next {{ $cfg['days_before'] }} day(s)</h4>
      <div class="table-wrap"><table class="table" id="arrivalTable"><thead><tr><th>Reservation</th><th>Arrival</th><th>Offers e-mailed</th><th class="right">Upsells</th><th></th></tr></thead><tbody>
      @forelse($arrivals as $r)
        <tr data-res="{{ $r->reservation_no }}"><td><a href="{{ route('reservations.show', $r) }}">{{ $r->reservation_no }}</a> · {{ $r->guest?->full_name }}<div class="tiny muted">{{ $r->guest?->email ?: 'no e-mail on file' }}</div></td>
          <td class="nowrap">{{ $r->arrival_date->format('D d M') }}</td>
          <td>@if($r->offered)<span class="badge badge-green">Sent</span>@else<span class="badge badge-slate">Not yet</span>@endif</td>
          <td class="right">{{ $r->upsells ?: '–' }}</td>
          <td class="right">@if(can_do('upsell.sell') && ! $r->offered && $r->guest?->email)<form method="post" action="{{ route('upsell.send', $r) }}" class="inline">@csrf<button class="btn btn-sm send-offers"><x-icon name="mail" size="sm"/> E-mail offers</button></form>@endif</td></tr>
      @empty<tr><td colspan="5" class="muted small">No confirmed arrivals in the window.</td></tr>@endforelse
      </tbody></table></div>
      <p class="tiny muted">@if($cfg['auto_email'])Offers are e-mailed automatically {{ $cfg['days_before'] }} day(s) before arrival (hourly, once per reservation).@else Automatic e-mails are off – send offers from here or from the reservation.@endif</p>
    </div>
    <div>
      <h4 class="mb-1">This month by type</h4>
      <ul class="list" id="byKind">
        @foreach(\App\Models\UpsellOffer::KINDS as $k => [$l, $ic])
          @php $row = $mtd['by_kind'][$k] ?? null; @endphp
          <li><span><x-icon name="{{ $ic }}" size="sm"/> {{ $l }}</span><span>{{ $row ? $row->n.' · ' : '' }}<b>{{ money($row->amt ?? 0) }}</b></span></li>
        @endforeach
      </ul>
      <h4 class="mb-1 mt-2">By channel</h4>
      <ul class="list" id="byChannel">
        @foreach(\App\Models\UpsellOrder::CHANNELS as $k => $l)
          @php $row = $mtd['by_channel'][$k] ?? null; @endphp
          <li><span>{{ $l }}</span><span>{{ $row ? $row->n.' · ' : '' }}<b>{{ money($row->amt ?? 0) }}</b></span></li>
        @endforeach
      </ul>
      <h4 class="mb-1 mt-2">Top offers this month</h4>
      <ul class="list" id="topOffers">
        @forelse($top as $t)<li><span>{{ $t->offer?->name }}</span><span>{{ $t->n }} · <b>{{ money($t->amt) }}</b></span></li>
        @empty<li class="muted small">Nothing sold yet this month.</li>@endforelse
      </ul>
      <p class="tiny muted mt-1">Last 30 days: {{ $last30['presented'] }} guests offered, {{ $last30['opened'] }} opened, {{ $last30['converted'] }} bought · {{ money($last30['revenue']) }}.</p>
    </div>
  </div></div>

@elseif($tab === 'requests')
  <div class="table-wrap"><table class="table" id="requestTable">
    <thead><tr><th>#</th><th>For</th><th>Guest / stay</th><th>Request</th><th class="right">Charge</th><th>Guest note</th>@if(can_do('upsell.sell'))<th></th>@endif</tr></thead>
    <tbody>
    @forelse($requests as $o)
      <tr data-order="{{ $o->order_no }}">
        <td class="nowrap">{{ $o->order_no }}<div class="tiny muted">{{ $o->channelLabel() }} · {{ $o->created_at->diffForHumans() }}</div></td>
        <td class="nowrap"><b>{{ $o->service_date->format('D d M') }}</b>@if($o->service_date->isSameDay(bdate()))<div class="tiny text-amber">today</div>@endif</td>
        <td><a href="{{ route('reservations.show', $o->reservation_id) }}">{{ $o->reservation->reservation_no }}</a> · {{ $o->reservation->guest?->full_name }}
          <div class="tiny muted">{{ $o->stay->roomType?->name }} · Rm {{ $o->stay->room?->room_no ?? '–' }} · {{ str_replace('_', ' ', $o->stay->status) }}</div></td>
        <td><b>{{ $o->offer->name }}</b><div class="tiny muted">{{ $o->offer->kindLabel() }} {{ $o->offer->timeLabel() }}</div></td>
        <td class="right">{{ money($o->amount) }}</td>
        <td class="small">{{ $o->guest_note ?: '–' }}</td>
        @if(can_do('upsell.sell'))
        <td class="nowrap right">
          <form method="post" action="{{ route('upsell.confirm', $o) }}" class="inline">@csrf<button class="btn btn-sm btn-success confirm-btn"><x-icon name="check" size="sm"/> Confirm</button></form>
          <button class="btn btn-sm" data-modal="declineModal" data-action="{{ route('upsell.decline', $o) }}" data-title="Decline {{ $o->order_no }} – {{ $o->offer->name }}">Decline</button>
        </td>
        @endif
      </tr>
    @empty<tr><td colspan="7"><x-empty icon="check" text="No requests waiting. Guest requests for early check-in or late check-out that need the desk's OK appear here."/></td></tr>@endforelse
    </tbody>
  </table></div>
  <div class="card-body pad small muted">Confirm when the room can be ready early / held late – the charge is then added to the stay. Unconfirmed requests are declined automatically once their date has passed.</div>

@elseif($tab === 'orders')
  <div class="card-body pad" style="padding-bottom:.4rem">
    <form method="get" class="flex" style="gap:.5rem;flex-wrap:wrap" id="orderFilter"><input type="hidden" name="tab" value="orders">
      <input class="input" name="q" value="{{ request('q') }}" placeholder="Order or reservation no." style="max-width:220px">
      <select class="input" style="width:auto" name="status"><option value="">Any status</option>@foreach(\App\Models\UpsellOrder::STATUSES as $k => [$l])<option value="{{ $k }}" @selected(request('status') === $k)>{{ $l }}</option>@endforeach</select>
      <select class="input" style="width:auto" name="channel"><option value="">Any channel</option>@foreach(\App\Models\UpsellOrder::CHANNELS as $k => $l)<option value="{{ $k }}" @selected(request('channel') === $k)>{{ $l }}</option>@endforeach</select>
      <select class="input" style="width:auto" name="offer"><option value="">Any offer</option>@foreach($offerNames as $id => $n)<option value="{{ $id }}" @selected((string) request('offer') === (string) $id)>{{ $n }}</option>@endforeach</select>
      <button class="btn"><x-icon name="search"/> Filter</button>
    </form>
  </div>
  <div class="table-wrap"><table class="table" id="orderTable">
    <thead><tr><th>#</th><th>Reservation</th><th>Offer</th><th>Channel</th><th class="right">Amount</th><th class="right">Posted</th><th>Sold by</th><th>Status</th>@if(can_do('upsell.sell'))<th></th>@endif</tr></thead>
    <tbody>
    @forelse($orders as $o)
      <tr data-order="{{ $o->order_no }}" data-status="{{ $o->status }}">
        <td class="nowrap">{{ $o->order_no }}<div class="tiny muted">{{ $o->created_at->format('d M H:i') }}</div></td>
        <td><a href="{{ route('reservations.show', $o->reservation_id) }}">{{ $o->reservation->reservation_no }}</a><div class="tiny muted">{{ $o->reservation->guest?->full_name }} · {{ $o->service_date->format('d M') }}</div></td>
        <td>{{ $o->offer->name }}<div class="tiny muted">{{ money($o->unit_price) }}{{ $o->quantity > 1 ? ' × '.$o->quantity : '' }}{{ $o->nights > 1 || $o->offer->pricing !== 'per_stay' && $o->nights ? ' × '.$o->nights.'N' : '' }}</div></td>
        <td class="small">{{ $o->channelLabel() }}</td>
        <td class="right">{{ money($o->amount) }}</td>
        <td class="right small">@if($o->charge){{ money($o->postedAmount()) }}@if(! $o->charge->is_active)<div class="tiny muted">stopped</div>@endif @else<span class="muted">–</span>@endif</td>
        <td class="small">{{ $o->seller?->name ?? 'guest' }}@if((float) $o->incentive_amount > 0)<div class="tiny text-purple">+{{ money($o->incentive_amount) }}</div>@endif</td>
        <td><span class="badge badge-{{ $o->statusTone() }}">{{ $o->statusLabel() }}</span>@if($o->staff_note && ! $o->isOpen())<div class="tiny muted" style="max-width:180px">{{ \Illuminate\Support\Str::limit($o->staff_note, 60) }}</div>@endif</td>
        @if(can_do('upsell.sell'))<td class="right">@if($o->isOpen())<button class="btn btn-sm btn-ghost btn-icon" title="Cancel" data-modal="cancelModal" data-action="{{ route('upsell.cancel', $o) }}" data-title="Cancel {{ $o->order_no }} – {{ $o->offer->name }}"><x-icon name="x"/></button>@endif</td>@endif
      </tr>
    @empty<tr><td colspan="9"><x-empty icon="star" text="No orders. Nothing matches these filters."/></td></tr>@endforelse
    </tbody>
  </table></div>
  <div class="card-body pad">{{ $orders->links() }}</div>

@elseif($tab === 'offers')
  <div class="table-wrap"><table class="table" id="offerTable">
    <thead><tr><th>Offer</th><th>Price</th><th>Rules</th><th>Incentive</th><th class="right">30 days</th><th>Status</th>@if(can_do('upsell.manage'))<th></th>@endif</tr></thead>
    <tbody>
    @forelse($offers as $o)
      @php $st = $offerStats[$o->id] ?? null; @endphp
      <tr data-offer="{{ $o->code }}" class="{{ $o->is_active ? '' : 'muted' }}">
        <td style="max-width:320px"><div class="flex gap-1 items-center"><x-icon name="{{ $o->icon }}"/><b>{{ $o->name }}</b> <code class="tiny">{{ $o->code }}</code></div>
          <div class="tiny muted">{{ $o->kindLabel() }}{{ $o->kind === 'upgrade' ? ' → '.$o->toRoomType?->name : '' }} {{ $o->timeLabel() }}@if($o->description) · {{ \Illuminate\Support\Str::limit($o->description, 70) }}@endif</div></td>
        <td class="small">{{ $o->priceLabel() }}<div class="tiny muted">{{ config('pms.charge_categories.'.$o->category) }}{{ $o->apply_tax ? ' + tax' : ', no tax' }}</div></td>
        <td class="small">@foreach($o->channels ?? [] as $c)<span class="badge badge-slate" style="margin:.1rem">{{ \App\Models\UpsellOrder::CHANNELS[$c] ?? $c }}</span>@endforeach
          <div class="tiny muted">{{ implode(' · ', array_filter([$o->min_nights > 1 ? 'min '.$o->min_nights.' nights' : null, $o->lead_hours ? 'online until '.$o->lead_hours.' h before' : null,
            $o->daily_cap ? $o->daily_cap.'/day' : null, $o->isTimed() ? ($o->auto_confirm ? 'auto-confirmed' : 'desk confirms') : null,
            $o->valid_from ? 'arrivals from '.$o->valid_from->format('d M Y') : null, $o->valid_to ? 'until '.$o->valid_to->format('d M Y') : null])) }}</div></td>
        <td class="small">{{ $o->incentiveLabel() }}</td>
        <td class="right">@if($st && $st->n)<a href="{{ route('upsell.index', ['tab' => 'orders', 'offer' => $o->id]) }}">{{ (int) $st->n }}</a><div class="tiny muted">{{ money($st->amt) }}</div>@else<span class="muted">0</span>@endif</td>
        <td>@if($o->is_active)<span class="badge badge-green">On sale</span>@else<span class="badge badge-slate">Off</span>@endif</td>
        @if(can_do('upsell.manage'))
        <td class="nowrap right">
          <button class="btn btn-sm btn-ghost btn-icon" data-modal="offerEdit{{ $o->id }}" title="Edit"><x-icon name="edit"/></button>
          <form method="post" action="{{ route('upsell.offers.toggle', $o) }}" class="inline">@csrf<button class="btn btn-sm btn-ghost toggle-btn">{{ $o->is_active ? 'Stop' : 'Start' }}</button></form>
          <form method="post" action="{{ route('upsell.offers.delete', $o) }}" class="inline" data-confirm="Delete {{ $o->name }}?">@csrf<button class="btn btn-sm btn-ghost btn-icon" title="Delete"><x-icon name="trash"/></button></form>
        </td>
        @endif
      </tr>
    @empty<tr><td colspan="7"><x-empty icon="star" text="No offers yet. Create upgrades, add-ons and early / late options to sell before and during the stay."/></td></tr>@endforelse
    </tbody>
  </table></div>
  <div class="card-body pad small muted">Prices are before tax. Confirmed orders become fixed charges on the stay and are billed with the room by the night audit (nightly items) or at check-in / check-out (one-off items). An upgrade switches the stay's room type straight away and is only offered while that type still has a room free for the whole stay{{ $cfg['keep_free'] ? ' (keeping '.$cfg['keep_free'].' in reserve)' : '' }}.</div>

@elseif($tab === 'settings')
  <div class="card-body pad">
    <form method="post" action="{{ route('upsell.settings') }}" id="upsellSettings" class="form-grid" style="max-width:760px">@csrf
      <label class="check span-2"><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" @checked($cfg['enabled'])> <b>Upselling on</b> <span class="small muted">– guests see offers, the desk can sell them</span></label>
      <label class="check span-2"><input type="hidden" name="auto_email" value="0"><input type="checkbox" name="auto_email" value="1" @checked($cfg['auto_email'])> E-mail offers automatically before arrival</label>
      <x-field name="days_before" label="Send offers this many days before arrival" type="number" min="1" max="30" required :value="$cfg['days_before']"/>
      <x-field name="guest_cancel_hours" label="Guests may cancel online until (hours before arrival)" type="number" min="0" max="240" required :value="$cfg['guest_cancel_hours']"/>
      <x-field name="keep_free" label="Rooms of the target type kept out of upgrades" type="number" min="0" max="50" required :value="$cfg['keep_free']" hint="Protects the last rooms of a type for full-price bookings"/>
      <div class="span-2"><button class="btn btn-primary" type="submit">Save settings</button></div>
    </form>
  </div>
@endif
</div>

@if(can_do('upsell.manage'))
<div class="modal" id="offerModal"><div class="modal-box" style="max-width:860px"><form method="post" action="{{ route('upsell.offers.store') }}" class="offer-form">@csrf
  <div class="modal-head"><h3>New upsell offer</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">@include('upsell._offer', ['o' => null])</div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button type="submit" class="btn btn-primary">Create offer</button></div>
</form></div></div>
@if($tab === 'offers')
  @foreach($offers as $o)
  <div class="modal" id="offerEdit{{ $o->id }}"><div class="modal-box" style="max-width:860px"><form method="post" action="{{ route('upsell.offers.update', $o) }}" class="offer-form">@csrf
    <div class="modal-head"><h3>Edit {{ $o->name }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
    <div class="modal-body form-grid">@include('upsell._offer', ['o' => $o])</div>
    <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button type="submit" class="btn btn-primary">Save</button></div>
  </form></div></div>
  @endforeach
@endif
@endif
@if(can_do('upsell.sell'))
<div class="modal" id="declineModal"><div class="modal-box" style="max-width:480px"><form method="post" action="">@csrf
  <div class="modal-head"><h3 data-title>Decline request</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><p class="small">The guest is not charged. Let them know when they arrive.</p><x-field name="reason" label="Reason" required maxlength="255" placeholder="House is full the night before – room not ready before 14:00"/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button type="submit" class="btn btn-danger">Decline</button></div>
</form></div></div>
<div class="modal" id="cancelModal"><div class="modal-box" style="max-width:480px"><form method="post" action="">@csrf
  <div class="modal-head"><h3 data-title>Cancel order</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><p class="small">The charge stops; an upgrade is moved back to the original room type. Anything already posted to the folio must be voided there first.</p><x-field name="reason" label="Reason" required maxlength="255" placeholder="Guest changed their mind"/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button type="submit" class="btn btn-danger">Cancel order</button></div>
</form></div></div>
@endif
<script>
document.querySelectorAll('.offer-form').forEach(function (f) {
  var sel = f.querySelector('[data-kind-select]');
  function sync() { f.querySelectorAll('[data-kinds]').forEach(function (el) { el.style.display = el.dataset.kinds.split(',').indexOf(sel.value) >= 0 ? '' : 'none'; }); }
  if (sel) { sel.addEventListener('change', sync); sync(); }
});
</script>
@endsection
