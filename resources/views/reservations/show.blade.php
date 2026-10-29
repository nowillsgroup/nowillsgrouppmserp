@extends('layouts.app')
@section('title', 'Reservation '.$r->reservation_no)
@section('crumbs') <a href="{{ route('reservations.index') }}">Reservations</a> / {{ $r->reservation_no }} @endsection
@section('actions')
  {!! status_badge($r->status) !!}
  <a class="btn" href="{{ route('reservations.print', $r) }}" target="_blank"><x-icon name="print"/> Confirmation</a>
  <button class="btn" data-modal="mailModal"><x-icon name="mail"/> E-mail</button>
  @if(can_do('messages.send'))<button class="btn" data-modal="smsModal"><x-icon name="message"/> SMS / WhatsApp</button>@endif
  @if(in_array($r->status, ['confirmed','tentative']))
    @if($r->status === 'tentative' && can_do('reservations.edit'))<form method="post" action="{{ route('reservations.confirm', $r) }}">@csrf<button class="btn btn-success"><x-icon name="check"/> Confirm</button></form>@endif
    @if(can_do('reservations.edit'))<a class="btn" href="{{ route('reservations.edit', $r) }}"><x-icon name="edit"/> Edit</a>@endif
  @endif
  @if(can_do('folio.payment') && !in_array($r->status, ['checked_out','cancelled','no_show']))<button class="btn btn-accent" data-modal="advModal"><x-icon name="money"/> Receive Advance</button>@endif
  @if(can_do('payments.request') && !in_array($r->status, ['checked_out','cancelled','no_show']))<button class="btn" data-modal="payLinkModal"><x-icon name="link"/> Request payment</button>@endif
  @if(can_do('reservations.cancel') && $r->rooms->where('status','reserved')->count())
    <button class="btn btn-danger" data-modal="cancelModal"><x-icon name="x"/> Cancel</button>
    @if($r->rooms->where('status','reserved')->contains(fn($s) => $s->arrival_date->lte(bdate())))<button class="btn btn-warning" data-modal="noshowModal">No-Show</button>@endif
  @endif
@endsection
@section('content')
@if($r->channel_attention === 'overbooked' && $r->status !== 'cancelled')<div class="alert alert-error" id="resOverbooked"><x-icon name="alert"/><div style="flex:1"><b>Overbooked OTA booking.</b> {{ $r->channel?->name }} delivered this booking when no {{ $r->rooms->where('status', '!=', 'cancelled')->map(fn ($s) => $s->roomType?->name)->unique()->implode(' / ') }} inventory was left. Upgrade, move or relocate the guest, then mark it resolved.
  @if(can_do('channels.resolve'))<form method="post" action="{{ route('cm.reservation.resolve', $r) }}" class="flex gap-1 mt-1">@csrf<input name="note" required minlength="5" placeholder="What was done? e.g. Upgraded to Executive Suite" style="flex:1"><button class="btn btn-sm" id="resResolve">Mark resolved</button></form>@endif</div></div>
@elseif($r->channel_connection_id && in_array($r->status, ['confirmed', 'tentative']))<div class="alert alert-warn" id="resChannelNote"><x-icon name="globe"/><div>Booked on <b>{{ $r->channel?->name }}</b> ({{ $r->ota_reference }}). Date changes and cancellations should also be made on the channel – availability is synced automatically.</div></div>@endif
<div class="grid g-2-1">
  <div>
    <div class="card">
      <div class="card-head"><h3>Booking Summary</h3><span class="small muted">Created {{ $r->created_at->format('d M Y H:i') }} by {{ $r->creator?->name ?? ($r->channel?->name ?? 'Online') }}</span></div>
      <div class="card-body grid g-2">
        <dl class="kv">
          <dt>Arrival</dt><dd>{{ $r->arrival_date->format('D, d M Y') }} {{ $r->eta ? '· ETA '.substr($r->eta,0,5) : '' }}</dd>
          <dt>Departure</dt><dd>{{ $r->departure_date->format('D, d M Y') }}</dd>
          <dt>Nights</dt><dd>{{ $r->nights }}</dd>
          <dt>Pax</dt><dd>{{ $r->adults }} Adults, {{ $r->children }} Children</dd>
          <dt>Booking type</dt><dd>{{ ucfirst($r->booking_type) }} {{ $r->group_name ? '– '.$r->group_name : '' }}</dd>@if($r->room_block_id && $r->block)<dt>Group block</dt><dd><a href="{{ route('blocks.show', $r->block) }}">{{ $r->block->block_no }}</a></dd>@endif
        </dl>
        <dl class="kv">
          <dt>Source</dt><dd>{{ $r->source?->name ?? '—' }}</dd>
          <dt>Company</dt><dd>@if($r->company && can_do('credit.view'))<a href="{{ route('credit.show', $r->company) }}">{{ $r->company->name }}</a>@else{{ $r->company?->name ?? '—' }}@endif</dd>
          @if($r->rate_contract_id && $r->rateContract)<dt>Contract</dt><dd id="resContract">@if(can_do('contracts.view'))<a href="{{ route('contracts.show', $r->rateContract) }}">{{ $r->rateContract->code }}</a>@else{{ $r->rateContract->code }}@endif <span class="tiny muted">{{ $r->rateContract->basisLabel() }} · {{ $r->rateContract->meal_plan }}</span></dd>@endif
          <dt>Bill to</dt><dd>{{ ucfirst($r->billing_instruction) }}</dd>
          <dt>OTA Ref</dt><dd>{{ $r->ota_reference ?? '—' }}</dd>
          @if($r->channel_connection_id)<dt>Channel</dt><dd id="resChannel">@if(can_do('channels.view') && $r->channel)<a href="{{ route('cm.connections.show', $r->channel) }}">{{ $r->channel->name }}</a>@else{{ $r->channel?->name ?? '—' }}@endif
            <div class="tiny muted">{{ $r->channel_payment === 'channel_collect' ? 'Prepaid to channel – bill the OTA' : 'Guest pays at hotel' }} · {{ money($r->channel_amount) }} gross · commission {{ money($r->channel_commission) }}</div>
            @if($r->channel_currency)<div class="tiny" id="resFx">Sent as {{ $r->channel_currency }} {{ number_format((float) $r->channel_amount_fx, 2) }} · converted at {{ number_format((float) $r->channel_fx_rate, 4) }} ({{ $r->channel_fx_date?->format('d M Y') }} board)</div>@endif</dd>@endif
          <dt>Purpose</dt><dd>{{ $r->purpose ?? '—' }}</dd>
        </dl>
        @if($r->billing_instruction === 'company' && $r->company && in_array($r->status, ['confirmed', 'tentative', 'checked_in']) && ($r->company->onHold() || ! $r->company->direct_billing || (float) $r->company->credit_limit > 0))
          @php $cp = \App\Services\CreditService::position($r->company); @endphp
          @if($r->company->onHold() || ! $r->company->direct_billing || $cp['over_limit'])
          <div class="span-all alert alert-error mb-0" id="resCreditAlert"><x-icon name="lock"/><div><b>Credit:</b>
            @if(! $r->company->direct_billing) {{ $r->company->name }} is not approved for direct billing – collect payment from the guest.
            @elseif($r->company->onHold()) {{ $r->company->name }} is on credit hold ({{ $r->company->credit_hold_reason }}). Collect a deposit or get a credit override before billing the company.
            @else {{ $r->company->name }} is over its credit limit ({{ money($cp['exposure']) }} of {{ money($cp['limit']) }}).@endif</div></div>
          @endif
        @endif
        @if($r->partner_user_id)<div class="span-all alert alert-info mb-0" id="resPartner"><x-icon name="building"/><div><b>Partner portal booking</b> by {{ $r->partnerUser?->name }} ({{ $r->company?->name }}){{ $r->partner_ref ? ' · ref '.$r->partner_ref : '' }}@if(($pq = \App\Models\PartnerRequest::where('reservation_id', $r->id)->where('status', 'pending')->first()) && can_do('partner.view')) · <a href="{{ route('partner-admin.requests') }}#preq-{{ $pq->id }}">{{ $pq->number }} {{ strtolower(\App\Models\PartnerRequest::TYPES[$pq->type]) }} pending</a>@endif</div></div>@endif
        @if($r->special_request)<div class="span-all alert alert-info mb-0"><x-icon name="bell"/><div><b>Special request:</b> {{ $r->special_request }}</div></div>@endif
        @if($r->cancel_reason)<div class="span-all alert alert-error mb-0"><x-icon name="x"/><div><b>Cancelled:</b> {{ $r->cancel_reason }} ({{ $r->cancelled_at?->format('d M Y H:i') }})</div></div>@endif
        @if($r->cancellation_fee > 0)<div class="span-all alert alert-warn mb-0"><x-icon name="money"/><div><b>Cancellation / no-show fee charged:</b> {{ money($r->cancellation_fee) }}</div></div>@endif
      </div>
    </div>

    <div class="card flush">
      <div class="card-head"><h3>Rooms ({{ $r->rooms->count() }})</h3>
        @if($r->rooms->where('status','reserved')->count() && can_do('reservations.edit'))<button class="btn btn-sm btn-soft" data-modal="assignModal"><x-icon name="key" size="sm"/> Assign rooms</button>@endif
      </div>
      <div class="card-body table-wrap">
        <table class="table">
          <thead><tr><th>Room</th><th>Type / Plan</th><th>Stay</th><th>Pax</th><th class="num">Rate</th><th>Status</th><th class="right">Actions</th></tr></thead>
          <tbody>
          @foreach($r->rooms as $s)
            <tr>
              <td class="bold">{{ $s->room?->room_no ?? '—' }}</td>
              <td>{{ $s->roomType->name }}<div class="tiny muted">{{ $s->ratePlan?->name ?? 'Rack rate' }}</div></td>
              <td class="small nowrap">{{ $s->arrival_date->format('d M') }} → {{ $s->departure_date->format('d M') }} ({{ $s->nights }}N)</td>
              <td>{{ $s->adults }}/{{ $s->children }}</td>
              <td class="num">{{ money($s->rate) }}@if($s->extra_charge > 0)<div class="tiny muted">+{{ money($s->extra_charge) }} extra</div>@endif</td>
              <td>{!! status_badge($s->status) !!}</td>
              <td class="right nowrap">
                @if($s->status === 'reserved')
                  @if(can_do('frontdesk.checkin') && $s->arrival_date->lte(bdate()))<a class="btn btn-sm btn-success" href="{{ route('frontdesk.checkin', $s) }}"><x-icon name="login" size="sm"/> Check-in</a>@endif
                  @if(can_do('reservations.cancel'))<button class="btn btn-sm btn-ghost" data-modal="cancelModal" data-fstay_id="{{ $s->id }}" title="Cancel this room"><x-icon name="x" size="sm"/></button>@endif
                @elseif($s->status === 'checked_in')
                  <a class="btn btn-sm" href="{{ route('folios.show', $s->folio) }}"><x-icon name="receipt" size="sm"/> Folio</a>
                  @if(can_do('frontdesk.checkout'))<a class="btn btn-sm btn-warning" href="{{ route('frontdesk.checkout', $s) }}">Check-out</a>@endif
                  <a class="btn btn-sm btn-ghost" href="{{ route('frontdesk.grc', $s) }}" target="_blank" title="Registration card"><x-icon name="print" size="sm"/></a>@if(can_do('requests.manage') && in_array($s->status, ['reserved','checked_in','checked_out']))<a class="btn btn-sm btn-ghost" href="{{ route('requests.portal', $s) }}" title="Guest portal link / QR"><x-icon name="qr" size="sm"/></a>@endif @if(can_do('frontdesk.checkin') && !in_array($s->status, ['cancelled','no_show']))<a class="btn btn-sm btn-ghost" href="{{ route('frontdesk.grc.sign', $s) }}" title="e-GRC: guest signature &amp; ID scan"><x-icon name="edit" size="sm"/>@if($s->grc_signed_at)<span class="tiny text-green">✓</span>@endif</a>@endif
                @elseif($s->folio)
                  <a class="btn btn-sm" href="{{ route('folios.show', $s->folio) }}"><x-icon name="receipt" size="sm"/> Folio</a>
                  @if($s->folio->invoice_no)<a class="btn btn-sm btn-ghost" href="{{ route('folios.invoice', $s->folio) }}" target="_blank"><x-icon name="print" size="sm"/></a>@endif
                @endif
              </td>
            </tr>
          @endforeach
          </tbody>
        </table>
      </div>
    </div>

    <div class="card flush">
      <div class="card-head"><h3>Payments & Advances</h3></div>
      <div class="card-body table-wrap">
        <table class="table"><thead><tr><th>Date</th><th>Folio</th><th>Mode</th><th>Reference</th><th class="num">Amount</th></tr></thead><tbody>
        @forelse($advances as $a)<tr><td>{{ $a->business_date->format('d M Y') }}</td><td>{{ $a->folio->folio_no }}</td><td>{{ $a->paymentMode?->name ?? 'City Ledger' }}</td><td>{{ $a->reference }}</td><td class="num">{{ money($a->amount) }}</td></tr>
        @empty<tr><td colspan="5" class="muted center">No payments recorded.</td></tr>@endforelse
        </tbody>@if($advances->count())<tfoot><tr><td colspan="4" class="right">Total received</td><td class="num">{{ money($advances->sum('amount')) }}</td></tr></tfoot>@endif</table>
      </div>
    </div>
    @if($onlinePayments->count() && can_do('payments.view'))
    <div class="card flush" id="resOnline">
      <div class="card-head"><h3><x-icon name="link"/> Online payment links</h3></div>
      <div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>Link</th><th>Created</th><th>For</th><th>Status</th><th class="num">Amount</th></tr></thead><tbody>
      @foreach($onlinePayments as $op)<tr><td><a class="bold" href="{{ route('payments.show', $op) }}">{{ $op->pay_no }}</a></td><td class="small">{{ $op->created_at->format('d M H:i') }}</td><td class="small">{{ $op->description }}</td>
        <td><span class="badge badge-{{ ['paid' => 'green', 'part_refunded' => 'amber', 'refunded' => 'slate', 'pending' => 'blue', 'review' => 'red', 'expired' => 'slate', 'cancelled' => 'red'][$op->displayStatus()] ?? 'slate' }}">{{ $op->statusLabel() }}</span></td><td class="num">{{ money($op->amount) }}</td></tr>@endforeach
      </tbody></table></div>
    </div>
    @endif
  </div>

  <div>
    <div class="card">
      <div class="card-head"><h3>Guest</h3><a href="{{ route('guests.show', $r->guest) }}" class="small">Profile →</a></div>
      <div class="card-body">
        <div class="flex items-center gap-1 mb-2"><div class="avatar">{{ strtoupper(substr($r->guest->first_name,0,1).substr($r->guest->last_name ?? '',0,1)) }}</div>
          <div><b>{{ $r->guest->full_name }}</b> @if($r->guest->is_vip)<span class="badge badge-purple">VIP</span>@endif<div class="small muted">{{ $r->guest->nationality }}</div></div></div>
        <dl class="kv">
          <dt>Mobile</dt><dd>{{ $r->guest->phone ?? '—' }}</dd><dt>Email</dt><dd>{{ $r->guest->email ?? '—' }}</dd>
          <dt>ID</dt><dd>{{ config('pms.id_types.'.$r->guest->id_type, '—') }} {{ \App\Models\Guest::mask($r->guest->id_number) }}</dd>
          <dt>City</dt><dd>{{ $r->guest->city ?? '—' }}</dd><dt>Stays</dt><dd>{{ $r->guest->reservations()->where('status','checked_out')->count() }} previous</dd>
        </dl>
        @if($r->guest->notes)<div class="alert alert-warn small mt-2 mb-0">{{ $r->guest->notes }}</div>@endif
      </div>
    </div>
    @if($r->prechecked_in_at || $r->onlineCheckinOpen())
    <div class="card"><div class="card-head"><h3><x-icon name="globe"/> Online check-in</h3>@if($r->prechecked_in_at)<span class="badge badge-green">Pre-checked-in</span>@else<span class="badge badge-slate">Not yet</span>@endif</div><div class="card-body">
      @if($r->prechecked_in_at)<p class="small mb-1">Completed by the guest on <b>{{ $r->prechecked_in_at->format('d M Y H:i') }}</b>{{ $r->eta ? ' · ETA '.substr($r->eta,0,5) : '' }}. The ID and e-signature are on file.</p>@endif
      @if($r->onlineCheckinOpen() && can_do('reservations.edit'))
        <div class="flex gap-1"><input class="input input-sm" readonly value="{{ $r->checkinUrl() }}" onclick="this.select()" style="flex:1;font-size:.72rem">
          <button type="button" class="btn btn-sm" onclick="navigator.clipboard?.writeText(this.previousElementSibling.value);this.textContent='Copied'">Copy</button></div>
        <form method="post" action="{{ route('reservations.checkin-link', $r) }}" class="mt-1">@csrf<button class="btn btn-sm" @disabled(!$r->guest->email)><x-icon name="mail" size="sm"/> E-mail link to guest</button>@unless($r->guest->email)<span class="tiny muted"> (no e-mail on file)</span>@endunless</form>
      @endif
    </div></div>
    @endif
    @if(can_do('transport.view') && ($trips->isNotEmpty() || (can_do('transport.book') && in_array($r->status, ['confirmed', 'tentative', 'checked_in']))))
    <div class="card" id="resTransport"><div class="card-head"><h3><x-icon name="car"/> Transport</h3>
      @if(can_do('transport.book') && in_array($r->status, ['confirmed', 'tentative', 'checked_in']))<span class="flex gap-1">
        @if($r->status !== 'checked_in')<a class="btn btn-sm" id="addPickup" href="{{ route('tr.create', ['reservation' => $r->id, 'type' => 'pickup']) }}"><x-icon name="plane" size="sm"/> Pick-up</a>@endif
        <a class="btn btn-sm" id="addDrop" href="{{ route('tr.create', ['reservation' => $r->id, 'type' => 'drop']) }}">+ Drop</a></span>@endif
    </div><div class="card-body">
      @forelse($trips as $t)
        <div class="flex justify-between items-center small mb-1"><div><a href="{{ route('tr.show', $t) }}"><b>{{ $t->typeLabel() }}</b> {{ $t->trip_no }}</a> · {{ $t->scheduled_at->format('d M H:i') }}{{ $t->flight_no ? ' · '.$t->flight_no : '' }}
          <div class="tiny muted">{{ $t->vehicle ? $t->vehicle->code.' · '.$t->driver?->name : 'no car yet' }} · {{ $t->billing === 'complimentary' ? 'complimentary' : money($t->total).($t->billing === 'room' ? ' to room' : ' direct') }}</div></div>{!! tr_badge($t->status) !!}</div>
      @empty<p class="small muted m-0">No airport pick-up or drop booked.@if($r->special_request && preg_match('/pick ?-?up|airport|drop|cab|taxi/i', $r->special_request))<br><span class="text-amber" id="pickupHint">Special request mentions transport: “{{ \Illuminate\Support\Str::limit($r->special_request, 80) }}”</span>@endif</p>@endforelse
    </div></div>
    @endif
    @if(can_do('formc.view') && ($formCs->isNotEmpty() || ($foreignGuest && \App\Services\FormCService::enabled())))
    <div class="card" id="resFormC"><div class="card-head"><h3><x-icon name="id"/> Form C (FRRO)</h3></div><div class="card-body">
      @forelse($formCs as $fc)
        <div class="flex justify-between items-center small mb-1" data-form="{{ $fc->form_no }}"><div><a href="{{ route('fg.formc.show', $fc) }}"><b>{{ $fc->form_no }}</b></a> {{ $fc->name() }}{{ $fc->is_companion ? ' (companion)' : '' }} · Rm {{ $fc->stay?->room?->room_no ?? '—' }}
          <div class="tiny muted">{{ $fc->status === 'pending' ? 'due '.$fc->deadline_at?->format('d M H:i') : ($fc->frro_ref ? 'ref '.$fc->frro_ref : '') }}</div></div>{!! formc_badge($fc->state()) !!}</div>
      @empty
        <p class="small muted m-0">{{ $r->guest->nationality }} national – a Form C opens at check-in and must be filed within {{ \App\Services\FormCService::cfg()['deadline_hours'] }} h.@if($r->formc_prefill) <span class="text-green">Details given at online check-in.</span>@endif</p>
      @endforelse
      @if(can_do('formc.manage') && $formCs->isEmpty() && $foreignGuest)
        @foreach($r->rooms->where('status', 'checked_in') as $st)<form method="post" action="{{ route('fg.formc.create', $st) }}" class="mt-1">@csrf<button class="btn btn-sm" id="openFormC">Open Form C · Rm {{ $st->room?->room_no }}</button></form>@endforeach
      @endif
    </div></div>
    @endif
    @if(can_do('desk.traces') || can_do('desk.messages') || can_do('desk.wakeup'))
    @php $deskStay = $r->rooms->first(fn ($x) => in_array($x->status, ['checked_in', 'reserved'])); @endphp
    <div class="card" id="resDesk"><div class="card-head"><h3><x-icon name="bell"/> Front desk</h3>
      <span class="flex gap-1">@if($deskStay && can_do('desk.messages'))<a class="btn btn-sm" href="{{ route('desk.messages', ['stay' => $deskStay->id]) }}" id="resMsgLink">+ Message</a>@endif
      @if($deskStay && can_do('desk.wakeup'))<a class="btn btn-sm" href="{{ route('desk.wakeups') }}">Wake-ups</a>@endif</span></div><div class="card-body">
      @foreach($deskWakeups as $w)<div class="flex justify-between small mb-1" data-wakeup="{{ $w->id }}"><span>⏰ {{ $w->call_at->format('D d M H:i') }}{{ $w->daily ? ' · daily' : '' }}</span><span class="badge badge-{{ $w->statusTone() }}">{{ $w->statusLabel() }}</span></div>@endforeach
      @foreach($deskMessages as $m)<div class="flex justify-between small mb-1" data-msg="{{ $m->id }}"><span>{{ $m->isParcel() ? '📦' : '✉' }} {{ $m->message_no }} · {{ $m->from_name }}</span><span class="badge badge-{{ $m->statusTone() }}">{{ $m->statusLabel() }}</span></div>@endforeach
      @foreach($deskTraces as $t)<div class="flex justify-between small mb-1" data-trace="{{ $t->id }}"><span>{{ $t->isOpen() ? '☐' : '☑' }} {{ $t->trace_date->format('d M') }} · {{ \App\Services\TraceService::DEPARTMENTS[$t->department] ?? '' }}: {{ $t->text }}</span>@if($t->isOpen() && can_do('desk.traces'))<form method="post" action="{{ route('desk.traces.done', $t) }}" class="inline">@csrf<button class="btn btn-sm btn-ghost">Done</button></form>@endif</div>@endforeach
      @if($deskWakeups->isEmpty() && $deskMessages->isEmpty() && $deskTraces->isEmpty())<p class="small muted m-0">No messages, wake-up calls or traces.</p>@endif
      @if(can_do('desk.traces') && ! in_array($r->status, ['cancelled', 'no_show', 'checked_out']))
      <form method="post" action="{{ route('desk.traces.store') }}" class="mt-1" id="resTraceForm" style="display:grid;grid-template-columns:1fr auto;gap:.4rem">@csrf
        <input type="hidden" name="reservation_id" value="{{ $r->id }}">@if($deskStay)<input type="hidden" name="reservation_room_id" value="{{ $deskStay->id }}">@endif
        <input class="input" name="text" required placeholder="Add a trace, e.g. confirm late arrival" style="grid-column:1/-1">
        <input class="input" type="date" name="trace_date" value="{{ max(bdate()->toDateString(), $r->arrival_date->toDateString() > bdate()->toDateString() ? $r->arrival_date->toDateString() : bdate()->toDateString()) }}" required>
        <select class="input" name="department">@foreach(\App\Services\TraceService::DEPARTMENTS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
        <button class="btn btn-sm btn-primary" style="grid-column:1/-1">Add trace</button>
      </form>
      @endif
    </div></div>
    @endif
    @if(can_do('iptv.view') || can_do('iptv.message') || can_do('iptv.manage'))
    @php $tvStays = $r->rooms->where('status', 'checked_in')->whereNotNull('room_id'); @endphp
    @if($tvStays->isNotEmpty())
    <div class="card" id="resTv"><div class="card-head"><h3><x-icon name="grid"/> In-room TV</h3></div><div class="card-body">
      @foreach($tvStays as $st)@php $tvs = \App\Models\TvDevice::where('room_id', $st->room_id)->where('status', 'active')->get(); $tvSess = \App\Models\TvSession::where('reservation_room_id', $st->id)->latest('id')->first(); @endphp
        <div class="small mb-1" data-tvstay="{{ $st->id }}"><b>Room {{ $st->room?->room_no }}</b> ·
          @if($tvs->isEmpty())<span class="muted">no TV registered</span>@else @foreach($tvs as $tv)<span class="badge badge-{{ $tv->stateTone() }}">{{ $tv->stateLabel() }}</span> @endforeach @endif
          <div class="tiny muted">TV PIN <b class="tv-code" id="resTvPin{{ $st->id }}">{{ \App\Services\IptvService::pinFor($st) }}</b> (movies{{ \App\Services\IptvService::cfg()['pin_bill'] ? ' & bill' : '' }}) · welcome {{ $tvSess?->welcomed_at ? 'seen '.$tvSess->welcomed_at->format('d M H:i') : 'not seen yet' }}{{ $tvSess?->language ? ' · '.(\App\Support\TvLang::LANGS[$tvSess->language] ?? '') : '' }}</div>
          @if(can_do('iptv.message') || can_do('iptv.manage'))<div class="flex gap-1 mt-1">
            <a class="btn btn-sm" href="{{ route('iptv.messages', ['target' => 'room', 'room' => $st->room_id]) }}#new">Message the TV</a>
            @if($tvs->isNotEmpty())<form method="post" action="{{ route('iptv.stay.reset', $st) }}">@csrf<button class="btn btn-sm" id="resTvReset{{ $st->id }}">Re-show welcome</button></form>@endif
          </div>@endif
        </div>
      @endforeach
    </div></div>
    @endif
    @endif
    @if($commissions->isNotEmpty())
    <div class="card"><div class="card-head"><h3><x-icon name="wallet"/> Agent commission</h3></div><div class="card-body"><ul class="list">
      @foreach($commissions as $c)<li><span>{{ $c->company->name }} · {{ (float)$c->pct }}% of {{ money($c->room_revenue) }}</span><span><b>{{ money($c->amount) }}</b> {!! status_badge($c->status) !!}</span></li>@endforeach
    </ul></div></div>
    @endif
    @if(can_do('upsell.view') && \App\Services\UpsellService::enabled())@include('upsell._reservation')@endif
    @php $est = $r->rooms->whereNotIn('status',['cancelled','no_show'])->sum(fn($s) => ($s->rate + $s->extra_charge) * $s->nights); @endphp
    <div class="card"><div class="card-head"><h3>Estimate</h3></div><div class="card-body">
      <ul class="list">
        <li><span>Room charges ({{ $r->nights }}N)</span><b>{{ money($est) }}</b></li>
        @if($r->discount_pct > 0)<li><span>Discount {{ $r->discount_pct }}%</span><b>- {{ money($est * $r->discount_pct / 100) }}</b></li>@endif
        <li><span>Advance received</span><b class="text-green">{{ money($advances->sum('amount')) }}</b></li>
        <li><span>Balance (excl. tax & extras)</span><b>{{ money($est * (1 - $r->discount_pct/100) - $advances->sum('amount')) }}</b></li>
      </ul>
    </div></div>
  </div>
</div>

<div class="card" id="reservationHistory">
  <div class="card-head"><h3>Reservation history</h3><span class="small muted">{{ $amendments->count() }} version(s) · immutable audit trail</span></div>
  <div class="card-body">
    @forelse($amendments as $amendment)
      <details class="mb-1" {{ $loop->first ? 'open' : '' }}>
        <summary class="flex justify-between gap-1" style="cursor:pointer">
          <span><b>v{{ $amendment->version }} · {{ $amendment->eventLabel() }}</b> <span class="tiny muted">via {{ str_replace('_', ' ', $amendment->source) }}</span></span>
          <span class="tiny muted">{{ $amendment->created_at?->format('d M Y H:i') }} · {{ $amendment->user?->name ?? 'System' }}</span>
        </summary>
        @if($amendment->reason)<p class="small muted mt-1 mb-1"><b>Reason:</b> {{ $amendment->reason }}</p>@endif
        @if($amendment->changes)
          <div class="table-wrap"><table class="table table-compact"><thead><tr><th>Field</th><th>Before</th><th>After</th></tr></thead><tbody>
            @foreach($amendment->changes as $path => $change)
              @php $beforeValue = $change['before'] ?? null; $afterValue = $change['after'] ?? null; @endphp
              <tr><td>{{ ucwords(str_replace(['.', '_'], [' · ', ' '], $path)) }}</td><td class="muted">{{ is_scalar($beforeValue) ? ($beforeValue ?? '—') : json_encode($beforeValue) }}</td><td>{{ is_scalar($afterValue) ? ($afterValue ?? '—') : json_encode($afterValue) }}</td></tr>
            @endforeach
          </tbody></table></div>
        @else
          <p class="small muted mt-1 mb-0">No field-level changes recorded for this event.</p>
        @endif
      </details>
    @empty
      <p class="small muted mb-0">No reservation history has been recorded yet.</p>
    @endforelse
  </div>
</div>

<div class="modal" id="cancelModal"><div class="modal-box"><form method="post" action="{{ route('reservations.cancel', $r) }}">@csrf
  <div class="modal-head"><h3>Cancel reservation</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><input type="hidden" name="stay_id" value=""><x-field name="reason" label="Cancellation reason" type="textarea" required/>
    <div class="alert {{ $cancelQuote['amount'] > 0 ? 'alert-warn' : 'alert-info' }} small mt-1 mb-1"><x-icon name="money"/><div>
      @if($cancelQuote['amount'] > 0)<b>Cancellation fee: {{ money($cancelQuote['amount']) }}</b> for all pending rooms ({{ $cancelQuote['basis'] }}).
        @if($stayQuotes->count() > 1)<br><span class="tiny">Single room: {{ $r->rooms->where('status','reserved')->map(fn($s) => ($s->room?->room_no ?? $s->roomType->code ?? $s->roomType->name).' '.money($stayQuotes[$s->id]))->join(' · ') }}</span>@endif
        <br><span class="tiny">Posted to the advance folio, where any advance is netted off.</span>
      @else {{ $cancelQuote['basis'] }}. No fee applies. Refund any advance from the folio. @endif
    </div></div>
    @if($cancelQuote['amount'] > 0)
      <label class="check"><input type="checkbox" name="waive_fee" value="1" onchange="this.closest('form').querySelector('.waive').hidden=!this.checked"> Waive the fee</label>
      <div class="waive mt-1" hidden><x-field name="waive_reason" label="Reason for waiver (logged)"/></div>
    @endif
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger">Confirm Cancellation</button></div>
</form></div></div>

<div class="modal" id="noshowModal"><div class="modal-box"><form method="post" action="{{ route('reservations.noshow', $r) }}">@csrf
  <div class="modal-head"><h3>Mark as no-show</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><p>{{ $r->rooms->where('status','reserved')->filter(fn($s) => $s->arrival_date->lte(bdate()))->count() }} pending room(s) due today or earlier will be released as no-show. Future-arrival rooms remain pending.</p>
    <div class="alert {{ $noshowQuote['amount'] > 0 ? 'alert-warn' : 'alert-info' }} small mb-1"><x-icon name="money"/><div>@if($noshowQuote['amount'] > 0)<b>No-show fee: {{ money($noshowQuote['amount']) }}</b> ({{ $noshowQuote['basis'] }})@else{{ $noshowQuote['basis'] }}@endif</div></div>
    @if($noshowQuote['amount'] > 0)
      <label class="check"><input type="checkbox" name="waive_fee" value="1" onchange="this.closest('form').querySelector('.waive').hidden=!this.checked"> Waive the fee</label>
      <div class="waive mt-1" hidden><x-field name="waive_reason" label="Reason for waiver (logged)"/></div>
    @endif
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-warning">Confirm No-Show</button></div>
</form></div></div>

<div class="modal" id="advModal"><div class="modal-box"><form method="post" action="{{ route('reservations.advance', $r) }}">@csrf
  <div class="modal-head"><h3>Receive advance</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <div id="advanceSingleTender" class="form-grid"><x-field name="amount" label="Amount" type="number" step="0.01" min="1" required/><x-field name="payment_mode_id" label="Payment mode" type="select" :options="$paymentModes" required data-payment-mode/><x-field name="reference" label="Reference" class="span-all"/><x-cheque-fields/></div>
    <div class="mt-1"><button type="button" class="btn btn-sm" id="advanceSplitToggle">Split advance across methods</button></div>
    <div id="advanceSplitTender" class="mt-1" hidden><p class="small muted">Enter one amount per method. Total allocated: <b id="advanceAllocationTotal">0.00</b>.</p><div id="advanceAllocations"></div><button type="button" class="btn btn-sm mt-1" id="advanceAddAllocation">Add payment method</button></div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save Payment</button></div>
</form></div></div>

<div class="modal" id="assignModal"><div class="modal-box"><form method="post" action="{{ route('reservations.assign', $r) }}">@csrf
  <div class="modal-head"><h3>Assign rooms</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    @foreach($r->rooms->where('status','reserved') as $s)
      <div class="field mb-1"><label>{{ $s->roomType->name }} ({{ $s->arrival_date->format('d M') }}–{{ $s->departure_date->format('d M') }})</label>
        <select name="assign[{{ $s->id }}]"><option value="">Unassigned</option>
          @foreach($freeRooms[$s->id] as $room)<option value="{{ $room->id }}" @selected($s->room_id == $room->id)>{{ $room->room_no }} – {{ $room->roomType->name }}{{ $room->room_type_id != $s->room_type_id ? ' (different type)' : '' }}</option>@endforeach
        </select></div>
    @endforeach
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save</button></div>
</form></div></div>
@if(can_do('messages.send'))
@php $smsLogs = \App\Models\MessageLog::where('related_type', 'Reservation')->where('related_id', $r->id)->latest('id')->limit(5)->get(); @endphp
<div class="modal" id="smsModal"><div class="modal-box"><form method="post" action="{{ route('reservations.message', $r) }}">@csrf
  <div class="modal-head"><h3>Message the guest</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <x-field name="template" label="Message" type="select" :options="['confirmation' => 'Booking confirmation', 'pre_arrival' => 'Pre-arrival + online check-in link', 'thank_you' => 'Thank-you', 'custom' => 'Custom text']" :value="$r->status === 'checked_out' ? 'thank_you' : ($r->onlineCheckinOpen() ? 'pre_arrival' : 'confirmation')" :placeholder="false"/>
    <x-field name="channel" label="Send by" type="select" :options="['sms' => 'SMS', 'whatsapp' => 'WhatsApp']" :value="\App\Services\MessageService::defaultChannel()" :placeholder="false"/>
    <x-field name="to" label="Mobile number" :value="$r->guest?->phone" placeholder="Guest's mobile" class="span-all"/>
    <x-field name="body" label="Custom text (only for “Custom text”)" type="textarea" rows="3" class="span-all" placeholder="Dear {guest}, …"/>
    <div class="span-all tiny text-2">Placeholders: {guest} {hotel} {res_no} {arrival} {departure} {checkin_link} {amount}</div>
    @if($smsLogs->isNotEmpty())
    <div class="span-all"><div class="small text-2 mb-1">Recent messages</div>
      @foreach($smsLogs as $m)<div class="tiny" style="padding:.3rem 0;border-top:1px solid var(--c-border)"><span class="badge {{ $m->status === 'sent' ? 'badge-green' : 'badge-red' }}">{{ $m->status }}</span> {{ $m->created_at->format('d M H:i') }} · {{ strtoupper($m->channel) }} · {{ str_replace('_', ' ', $m->template) }} → {{ $m->to }}@if($m->error)<span class="text-red"> – {{ $m->error }}</span>@endif</div>@endforeach
    </div>
    @endif
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary"><x-icon name="message"/> Send</button></div>
</form></div></div>
@endif
<div class="modal" id="mailModal"><div class="modal-box"><form method="post" action="{{ route('reservations.email', $r) }}">@csrf
  <div class="modal-head"><h3>E-mail {{ $r->status === 'cancelled' ? 'cancellation' : 'confirmation' }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="to" label="Send to" type="email" :value="$r->guest?->email" required placeholder="guest@example.com"/><p class="hint mt-1">Every send is recorded in Administration › E-mail Log.</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary"><x-icon name="mail"/> Send</button></div>
</form></div></div>
@if(can_do('payments.request') && !in_array($r->status, ['checked_out','cancelled','no_show']))
@include('payments._request-modal', ['payRes' => $r, 'payFolio' => null, 'paySuggest' => \App\Services\OnlinePaymentService::suggest($r, null), 'payGuest' => $r->guest])
@endif
@push('scripts')
<script>
(() => {
  const form = document.querySelector('#advModal form');
  const toggle = document.getElementById('advanceSplitToggle');
  const single = document.getElementById('advanceSingleTender');
  const split = document.getElementById('advanceSplitTender');
  const rows = document.getElementById('advanceAllocations');
  const total = document.getElementById('advanceAllocationTotal');
  if (!form || !toggle || !single || !split || !rows) return;
  const modes = @json($paymentModes);
  const oldLines = @js(old('payments', []));
  const esc = value => String(value).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  let sequence = 0, restored = false;
  const update = () => {
    const sum = [...rows.querySelectorAll('input[name$="[amount]"]')].reduce((n, input) => n + (Number(input.value) || 0), 0);
    if (total) total.textContent = sum.toFixed(2);
  };
  const syncCheque = () => rows.querySelectorAll('[data-advance-row]').forEach(row => {
    const mode = row.querySelector('select[name$="[payment_mode_id]"]');
    const cheque = (mode?.selectedOptions?.[0]?.textContent || '').toLowerCase().includes('cheque');
    row.querySelectorAll('input[name$="[cheque_bank_name]"],input[name$="[cheque_number]"],input[name$="[cheque_bank_branch]"]').forEach(input => { input.required = cheque; });
  });
  const addRow = (line = {}) => {
    if (!line || typeof line !== 'object') line = {};
    const i = sequence++;
    const options = Object.entries(modes).map(([id, name]) => `<option value="${esc(id)}">${esc(name)}</option>`).join('');
    rows.insertAdjacentHTML('beforeend', `<div class="form-grid mt-1" data-advance-row style="border:1px solid var(--c-border);padding:.6rem;border-radius:var(--radius)">
      <div class="field"><label>Payment method</label><select name="payments[${i}][payment_mode_id]" required>${options}</select></div>
      <div class="field"><label>Amount</label><input name="payments[${i}][amount]" type="number" min="0.01" step="0.01" required></div>
      <div class="field"><label>MoMo network</label><input name="payments[${i}][momo_network]" maxlength="20"></div>
      <div class="field"><label>MoMo phone</label><input name="payments[${i}][momo_phone]" maxlength="30"></div>
      <div class="field span-all"><label>Reference</label><input name="payments[${i}][reference]" maxlength="80"></div>
      <details class="span-all"><summary class="tiny bold">Cheque details</summary><div class="form-grid mt-1">
        <div class="field"><label>Bank name</label><input name="payments[${i}][cheque_bank_name]" maxlength="100"></div>
        <div class="field"><label>Cheque number</label><input name="payments[${i}][cheque_number]" maxlength="20"></div>
        <div class="field"><label>Bank branch</label><input name="payments[${i}][cheque_bank_branch]" maxlength="100"></div>
      </div></details>
      <button type="button" class="btn btn-sm btn-ghost" data-remove-advance>Remove tender</button>
    </div>`);
    const row = rows.lastElementChild;
    for (const key of ['payment_mode_id','amount','reference','momo_network','momo_phone','cheque_bank_name','cheque_number','cheque_bank_branch']) {
      const input = row.querySelector(`[name="payments[${i}][${key}]"]`);
      if (input && line[key] !== undefined && line[key] !== null) input.value = String(line[key]);
    }
    syncCheque(); update();
  };
  const setSplit = enabled => {
    single.hidden = enabled; split.hidden = !enabled;
    single.querySelectorAll('input,select').forEach(input => { input.disabled = enabled; });
    toggle.textContent = enabled ? 'Use one payment method' : 'Split advance across methods';
    if (enabled && !rows.children.length) {
      if (!restored && Array.isArray(oldLines) && oldLines.length) { oldLines.slice(0,10).forEach(addRow); restored = true; }
      while (rows.children.length < 2) addRow();
    }
    if (!enabled) rows.replaceChildren();
    update();
  };
  toggle.addEventListener('click', () => setSplit(split.hidden));
  document.getElementById('advanceAddAllocation')?.addEventListener('click', () => addRow());
  rows.addEventListener('input', update);
  rows.addEventListener('change', event => { update(); if (event.target.matches('select[name$="[payment_mode_id]"]')) syncCheque(); });
  rows.addEventListener('click', event => { if (event.target.closest('[data-remove-advance]')) { event.target.closest('[data-advance-row]')?.remove(); update(); } });
  form.addEventListener('submit', event => {
    if (!split.hidden && rows.querySelectorAll('[data-advance-row]').length < 2) {
      event.preventDefault(); alert('Add at least two payment methods, or switch back to one method.');
    }
  });
  if (Array.isArray(oldLines) && oldLines.length) setSplit(true);
})();
</script>
@endpush
@endsection
