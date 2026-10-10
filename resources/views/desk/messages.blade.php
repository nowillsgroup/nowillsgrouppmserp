@extends('layouts.app')
@section('title', 'Messages & Parcels')
@section('crumbs') Desk Operations / Messages & Parcels @endsection
@section('actions')
  <button class="btn" data-modal="msgModal" data-f-kind="parcel" data-title="Log a parcel" id="newParcelBtn"><x-icon name="box"/> Log parcel</button>
  <button class="btn btn-primary" data-modal="msgModal" data-f-kind="message" data-title="Take a message" id="newMsgBtn"><x-icon name="mail"/> Take message</button>
@endsection
@section('content')
@php $c = $counts; @endphp
@if(session('slip'))<div class="alert alert-info" id="slipHint"><x-icon name="print"/><div><a href="{{ route('desk.messages.slip', session('slip')) }}" target="_blank" id="slipLink">Print the message slip</a> to put in the guest's key box or slide under the door.</div></div>@endif
@if($c['departed_parcels'] || $c['messages_old'])
<div class="alert alert-warn" id="msgAlerts"><x-icon name="alert"/><div class="flex" style="gap:.9rem;flex-wrap:wrap">
  @if($c['departed_parcels'])<span class="text-red"><b>{{ $c['departed_parcels'] }}</b> parcel(s) for guests who have checked out – forward or return to sender</span>@endif
  @if($c['messages_old'])<span><b>{{ $c['messages_old'] }}</b> item(s) waiting more than {{ $cfg['msg_remind_minutes'] }} minutes</span>@endif
</div></div>
@endif
<div class="stats">
  <x-stat label="Messages waiting" :value="$c['messages']" icon="mail" :tone="$c['messages'] ? 'amber' : 'green'" sub="not yet delivered"/>
  <x-stat label="Parcels held" :value="$c['parcels']" icon="box" :tone="$c['departed_parcels'] ? 'red' : 'blue'" :sub="$c['departed_parcels'].' for departed guests'"/>
  <x-stat label="Auto-notify" :value="['none' => 'Off', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp'][$cfg['msg_notify']]" icon="phone" tone="purple" :sub="$cfg['msg_include_text'] === '1' ? 'message text included' : 'text not included (privacy)'"/>
</div>

<div class="card flush">
  <div class="card-head"><div class="tabs" style="margin:0;border:0">
    @foreach(['open' => 'Waiting', 'parcels' => 'Parcels held', 'closed' => 'Delivered / closed'] as $k => $l)<a href="{{ route('desk.messages', ['tab' => $k]) }}" class="{{ $tab === $k ? 'active' : '' }}" data-tab="{{ $k }}">{{ $l }}</a>@endforeach
  </div>
  <form method="get" class="flex" style="gap:.4rem"><input type="hidden" name="tab" value="{{ $tab }}"><input class="input" name="q" value="{{ request('q') }}" placeholder="Room, guest, sender, AWB" style="max-width:14rem"><button class="btn btn-sm">Search</button></form></div>
  <div class="card-body table-wrap"><table class="table" id="msgTable"><thead><tr><th>No.</th><th>Room · guest</th><th>From</th><th>Message / parcel</th><th>Received</th><th>Status</th><th class="right">Actions</th></tr></thead><tbody>
  @forelse($messages as $m)
    @php $departed = $m->isOpen() && in_array($m->stay?->status, ['checked_out', 'cancelled', 'no_show']); @endphp
    <tr data-msg="{{ $m->id }}" style="{{ $departed ? 'background:#fef2f2' : ($m->urgent && $m->isOpen() ? 'background:#fffbeb' : '') }}">
      <td class="small nowrap"><a href="{{ route('desk.messages.slip', $m) }}" target="_blank">{{ $m->message_no }}</a><div>{{ $m->isParcel() ? '📦 Parcel' : '✉ Message' }}</div></td>
      <td><b>{{ $m->stay?->room?->room_no ?? '—' }}</b> · {{ $m->guest?->full_name ?? '—' }}
        <div class="small muted">@if($m->stay?->status === 'reserved')arriving {{ $m->stay->arrival_date->format('d M') }}@elseif($departed)<span class="text-red">checked out</span>@else in-house @endif</div></td>
      <td class="small">{{ $m->from_name }}@if($m->from_company)<div class="muted">{{ $m->from_company }}</div>@endif @if($m->from_phone)<div class="muted">{{ $m->from_phone }}</div>@endif<div class="muted">{{ \App\Models\GuestMessage::CHANNELS[$m->channel] ?? '' }}</div></td>
      <td class="small" style="max-width:22rem">@if($m->urgent)<span class="badge badge-red">Urgent</span> @endif
        @if($m->isParcel()){{ $m->pieces }} pc{{ $m->pieces > 1 ? 's' : '' }}{{ $m->courier ? ' · '.$m->courier : '' }}{{ $m->tracking_no ? ' · AWB '.$m->tracking_no : '' }} · <i>{{ $m->storage_location }}</i>@endif
        @if($m->body)<div style="white-space:pre-line">{{ \Illuminate\Support\Str::limit($m->body, 200) }}</div>@endif</td>
      <td class="small nowrap">{{ $m->created_at->format('d M H:i') }}<div class="muted">{{ $m->taker?->name }}</div>@if($m->isOpen() && $m->ageMinutes() > $cfg['msg_remind_minutes'])<div class="text-amber">{{ $m->created_at->diffForHumans(null, true) }}</div>@endif</td>
      <td class="small"><span class="badge badge-{{ $m->statusTone() }}">{{ $m->statusLabel() }}</span>
        @if($m->notified_at)<div class="muted">{{ strtoupper($m->notified_via) }} {{ $m->notified_at->format('H:i') }}</div>@endif
        @if($m->portal_read_at)<div class="muted">seen on portal {{ $m->portal_read_at->format('H:i') }}</div>@endif
        @if(! $m->isOpen())<div class="muted">{{ $m->collected_by ? 'by '.$m->collected_by.' · ' : '' }}{{ $m->delivery_note ?? $m->close_reason }}</div>@endif</td>
      <td class="right nowrap">@if($m->isOpen())
        @unless($departed)
          @if($m->status === 'pending')<form method="post" action="{{ route('desk.messages.notify', $m) }}" style="display:inline">@csrf<input type="hidden" name="via" value="call"><button class="btn btn-sm btn-ghost" title="The desk phoned the room" data-act="call">Phoned</button></form>
          <form method="post" action="{{ route('desk.messages.notify', $m) }}" style="display:inline">@csrf<input type="hidden" name="via" value="{{ \App\Services\MessageService::defaultChannel() }}"><button class="btn btn-sm btn-ghost" data-act="sms">{{ \App\Services\MessageService::defaultChannel() === 'whatsapp' ? 'WhatsApp' : 'SMS' }}</button></form>@endif
          <button class="btn btn-sm btn-success" data-modal="deliverModal" data-action="{{ route('desk.messages.deliver', $m) }}" data-title="Deliver {{ $m->message_no }}{{ $m->isParcel() ? ' (parcel)' : '' }}" data-f-collected_by="{{ $m->guest?->full_name }}" data-act="deliver">Deliver</button>
        @endunless
        <button class="btn btn-sm btn-ghost" data-modal="closeModal" data-action="{{ route('desk.messages.close', $m) }}" data-title="Close {{ $m->message_no }}" data-f-status="{{ $m->isParcel() ? 'returned' : 'cancelled' }}" data-act="close">{{ $m->isParcel() ? 'Return / forward' : 'Cancel' }}</button>
      @endif</td>
    </tr>
  @empty
    <tr><td colspan="7" class="muted" id="msgEmpty">Nothing here.</td></tr>
  @endforelse
  </tbody></table></div>
  @if($messages->hasPages())<div class="card-foot">{{ $messages->links() }}</div>@endif
</div>

<div class="modal" id="msgModal"><div class="modal-box" style="max-width:680px"><form method="post" action="{{ route('desk.messages.store') }}" id="msgForm">@csrf
  <div class="modal-head"><h3 data-title>Take a message</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="kind" label="Type" type="select" :options="['message' => '✉ Message', 'parcel' => '📦 Parcel / delivery']" value="message" :placeholder="false"/>
    <x-field name="reservation_room_id" label="For guest / room" type="select" :options="$stays" :value="$preselect ?: null" required/>
    <x-field name="from_name" label="From (name / sender)" required/>
    <x-field name="from_company" label="Company"/>
    <x-field name="from_phone" label="Call-back number"/>
    <x-field name="channel" label="Received by" type="select" :options="\App\Models\GuestMessage::CHANNELS" value="phone" :placeholder="false"/>
    <x-field name="body" label="Message / parcel description" type="textarea" rows="3" class="span-all"/>
    <x-field name="courier" label="Courier (parcels)" placeholder="e.g. DHL, Blue Dart"/>
    <x-field name="tracking_no" label="Tracking / AWB no."/>
    <x-field name="pieces" label="Pieces" type="number" value="1" min="1" max="50"/>
    <x-field name="storage_location" label="Stored at" placeholder="Front desk / luggage room"/>
    <label class="flex" style="gap:.4rem;align-items:center"><input type="checkbox" name="urgent" value="1"> Urgent</label>
    <label class="flex" style="gap:.4rem;align-items:center"><input type="checkbox" name="notify" value="1" @checked($cfg['msg_notify'] !== 'none')> Notify an in-house guest by {{ $cfg['msg_notify'] === 'none' ? 'SMS (off in settings)' : strtoupper($cfg['msg_notify']) }} now</label>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" id="saveMsg">Save</button></div>
</form></div></div>

<div class="modal" id="deliverModal"><div class="modal-box"><form method="post" action="#">@csrf
  <div class="modal-head"><h3 data-title>Deliver</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <x-field name="collected_by" label="Handed to / collected by" hint="Required for parcels – check ID if it is not the guest"/>
    <x-field name="delivery_note" label="How" type="select" :options="['Handed to the guest' => 'Handed to the guest', 'Collected at the desk' => 'Collected at the desk', 'Slip under the door' => 'Slip under the door', 'Read to the guest by phone' => 'Read to the guest by phone', 'Delivered to the room' => 'Delivered to the room']" :placeholder="false"/>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-success" id="confirmDeliver">Delivered</button></div>
</form></div></div>

<div class="modal" id="closeModal"><div class="modal-box"><form method="post" action="#">@csrf
  <div class="modal-head"><h3 data-title>Close</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <x-field name="status" label="Action" type="select" :options="['returned' => 'Returned to sender / forwarded', 'cancelled' => 'Cancel (not needed)']" :placeholder="false"/>
    <x-field name="reason" label="Details" required placeholder="e.g. forwarded to guest's home address, AWB 123…"/>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Back</button><button class="btn btn-primary" id="confirmClose">Close item</button></div>
</form></div></div>
@endsection
