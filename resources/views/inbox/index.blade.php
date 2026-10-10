@extends('layouts.app')
@section('title', 'Inbox')
@section('crumbs') Guest Messaging / Inbox @endsection
@php
  $C = \App\Models\Conversation::class; $IS = \App\Services\InboxService::class; $sla = $cfg['sla'];
  $folders = ['open' => 'Open', 'waiting' => 'Needs reply', 'mine' => 'Mine', 'unassigned' => 'Unassigned', 'pending' => 'Waiting for guest', 'snoozed' => 'Snoozed', 'closed' => 'Closed', 'all' => 'All'];
  $keep = request()->only('channel', 'department', 'q');
@endphp
@section('actions')
  <a class="btn" href="{{ route('inbox.dashboard') }}"><x-icon name="chart"/> Dashboard</a>
  @if($canReply)<button class="btn btn-primary" data-modal="inbNewModal" id="inbNewBtn"><x-icon name="plus"/> New conversation</button>@endif
@endsection
@section('content')
@if(! $cfg['enabled'])<div class="alert alert-warn"><x-icon name="alert"/><div>Guest messaging is switched off – the portal chat is hidden and inbound messages are refused. @if(\App\Services\InboxService::canManage(auth()->user()))<a href="{{ route('inbox.settings') }}">Messaging settings</a>@endif</div></div>@endif
<div class="tabs inb-tabs" id="inbFolders">
  @foreach($folders as $k => $l)<a href="{{ route('inbox.index', ['f' => $k] + $keep) }}" class="{{ $f === $k ? 'active' : '' }}" data-folder="{{ $k }}">{{ $l }}@if(isset($counts[$k]) && $counts[$k])<span class="inb-count {{ $k === 'waiting' ? 'hot' : '' }}">{{ $counts[$k] }}</span>@endif</a>@endforeach
</div>
<div class="inb" id="inbox" data-poll="{{ route('inbox.poll') }}" data-c="{{ $c?->id }}" data-last="{{ $thread->max('id') ?? 0 }}">
  <section class="inb-list card">
    <form method="get" action="{{ route('inbox.index') }}" class="inb-filter" id="inbFilter">
      <input type="hidden" name="f" value="{{ $f }}">
      <input name="q" class="input" placeholder="Search name, room, number, text…" value="{{ request('q') }}">
      <div class="inb-filter-row">
        <select name="channel" class="input" onchange="this.form.submit()"><option value="">All channels</option>@foreach($C::CHANNELS as $k => $l)<option value="{{ $k }}" @selected(request('channel') === $k)>{{ $l }}</option>@endforeach</select>
        <select name="department" class="input" onchange="this.form.submit()"><option value="">All teams</option>@foreach($C::DEPARTMENTS as $k => $l)<option value="{{ $k }}" @selected(request('department') === $k)>{{ $l }}</option>@endforeach</select>
      </div>
    </form>
    <div class="inb-items" id="inbItems">
      @forelse($list as $x)
        @php $w = $x->waitingMinutes(); $over = $x->isOverdue($sla); @endphp
        <a href="{{ route('inbox.index', ['c' => $x->id, 'f' => $f] + $keep) }}" class="inb-item {{ $c?->id === $x->id ? 'on' : '' }} {{ $x->unread ? 'unread' : '' }}" data-conv="{{ $x->number }}">
          <span class="inb-av inb-ch-{{ $x->channel }}" title="{{ $x->channelLabel() }}">{{ mb_strtoupper(mb_substr($x->guest?->first_name ?: ($x->contact_name ?: '#'), 0, 1).mb_substr($x->guest?->last_name ?? '', 0, 1)) }}<i><x-icon :name="$C::CHANNEL_ICONS[$x->channel] ?? 'message'" size="sm"/></i></span>
          <span class="inb-it-main">
            <span class="inb-it-top"><b>{{ $x->displayName() }}</b>@if($x->priority === 'high')<span class="badge badge-red">urgent</span>@endif<time>{{ $x->last_message_at?->isToday() ? $x->last_message_at->format('H:i') : $x->last_message_at?->format('d M') }}</time></span>
            <span class="inb-it-sub">{{ $x->roomNo() ? 'Room '.$x->roomNo().' · ' : '' }}{{ $x->departmentLabel() }}{{ $x->assignee ? ' · '.\Illuminate\Support\Str::before($x->assignee->name, ' ') : '' }}</span>
            <span class="inb-it-snip">{{ $x->lastMessage ? ($x->lastMessage->direction === 'out' ? 'You: ' : '').\Illuminate\Support\Str::limit($x->lastMessage->body, 70) : '—' }}</span>
          </span>
          <span class="inb-it-side">
            @if($x->unread)<span class="inb-unread">{{ $x->unread }}</span>@endif
            @if($w !== null && $x->status !== 'snoozed')<span class="inb-wait {{ $over ? 'over' : '' }}" title="Waiting for an answer">{{ $w < 60 ? $w.'m' : floor($w / 60).'h' }}</span>@endif
            @if($x->status !== 'open')<span class="badge badge-{{ $x->statusTone() }}">{{ $x->statusLabel() }}</span>@endif
          </span>
        </a>
      @empty
        <p class="muted small center p-3" id="inbEmpty">No conversations here.</p>
      @endforelse
    </div>
    <div class="inb-pages">{{ $list->links() }}</div>
  </section>

  <section class="inb-conv card" id="inbConv">
    @if($c)
      <div class="inb-head">
        <div>
          <h3 class="m-0">{{ $c->displayName() }} @if($c->guest?->is_vip)<span class="badge badge-amber">VIP</span>@endif</h3>
          <div class="tiny muted"><x-icon :name="$C::CHANNEL_ICONS[$c->channel] ?? 'message'" size="sm"/> {{ $c->channelLabel() }}{{ $c->channel !== 'portal' ? ' · '.$c->handle : '' }} · {{ $c->number }}{{ $c->subject ? ' · '.$c->subject : '' }}</div>
        </div>
        <div class="inb-head-act">
          <span class="badge badge-{{ $c->statusTone() }}" id="inbStatus">{{ $c->statusLabel() }}{{ $c->status === 'snoozed' && $c->snoozed_until ? ' until '.$c->snoozed_until->format('D H:i') : '' }}</span>
          @if($canReply)
            @if($c->assigned_to !== auth()->id())<form method="post" action="{{ route('inbox.assign', $c) }}" class="inline">@csrf<input type="hidden" name="user_id" value="me"><button class="btn btn-sm" id="inbTake">Take it</button></form>@endif
            @if($c->status !== 'closed')
              <div class="inb-dd"><button type="button" class="btn btn-sm" id="inbSnoozeBtn" onclick="this.nextElementSibling.toggleAttribute('hidden')"><x-icon name="clock" size="sm"/> Snooze</button>
                <form method="post" action="{{ route('inbox.snooze', $c) }}" class="inb-dd-menu" hidden id="inbSnooze">@csrf
                  <button name="for" value="30m" class="btn btn-sm">30 minutes</button><button name="for" value="2h" class="btn btn-sm">2 hours</button><button name="for" value="tomorrow" class="btn btn-sm">Tomorrow 08:00</button>
                </form></div>
              <form method="post" action="{{ route('inbox.close', $c) }}" class="inline" id="inbCloseForm">@csrf<input type="hidden" name="f" value="{{ $f }}"><button class="btn btn-sm btn-primary" id="inbClose"><x-icon name="check" size="sm"/> Close</button></form>
            @else
              <form method="post" action="{{ route('inbox.reopen', $c) }}" class="inline">@csrf<button class="btn btn-sm" id="inbReopen"><x-icon name="refresh" size="sm"/> Reopen</button></form>
            @endif
          @endif
        </div>
      </div>
      <div class="inb-thread" id="inbThread">
        @foreach($thread as $m)@include('inbox._msg', ['m' => $m, 'c' => $c])@endforeach
      </div>
      @if($canReply)
        @if(! $waOpen)<div class="alert alert-warn m-0 inb-wa"><x-icon name="clock"/><div class="small">The guest last wrote on WhatsApp more than 24 hours ago – WhatsApp only allows free replies within 24 hours. Reach the guest by SMS or phone, or wait for them to write.</div></div>@endif
        <form method="post" action="{{ route('inbox.reply', $c) }}" class="inb-compose" id="inbCompose">@csrf
          <input type="hidden" name="f" value="{{ $f }}"><input type="hidden" name="quick_reply_id" id="inbQr" value="">
          <div class="inb-modes" role="tablist">
            <label class="inb-mode"><input type="radio" name="mode_tab" value="reply" checked> Reply to guest</label>
            <label class="inb-mode note"><input type="radio" name="mode_tab" value="note"> Internal note</label>
            <select class="input inb-qr-pick" id="inbQrPick" title="Insert a quick reply"><option value="">Quick reply… (or type /shortcut)</option>
              @foreach($quick as $q)<option value="{{ $q->id }}" data-short="{{ $q->shortcut }}" data-body="{{ $IS::render($q->body, $IS::vars($c, auth()->user())) }}">/{{ $q->shortcut }} – {{ $q->title }}</option>@endforeach
            </select>
          </div>
          <textarea name="body" id="inbBody" class="input" rows="3" maxlength="2000" placeholder="Write to {{ $c->guest?->first_name ?: 'the guest' }}… (Enter to send, Shift+Enter for a new line)" required>{{ old('body') }}</textarea>
          <div class="inb-send">
            <span class="tiny muted" id="inbHint">{{ $c->channel === 'sms' ? 'Sent as SMS' : ($c->channel === 'whatsapp' ? 'Sent on WhatsApp' : ($c->channel === 'email' ? 'Sent by e-mail' : 'Appears in the guest portal chat')) }}</span>
            <button name="mode" value="wait" class="btn btn-sm" id="inbSendWait" title="Send and move to 'Waiting for guest'">Send &amp; wait for guest</button>
            <button name="mode" value="reply" class="btn btn-primary" id="inbSend"><x-icon name="chev" size="sm"/> Send</button>
          </div>
        </form>
      @endif
    @else
      <div class="inb-none"><x-icon name="message"/><p>Choose a conversation on the left.</p><p class="tiny muted">Guests write from the guest-portal chat, WhatsApp, SMS or e-mail – every message lands here. Response-time target: {{ $sla }} minutes.</p></div>
    @endif
  </section>

  <aside class="inb-side">
    @if($c)
      <div class="card"><div class="card-head"><h3>Guest</h3>@if($c->guest)<a class="small" href="{{ route('guests.show', $c->guest) }}">profile</a>@endif</div><div class="card-body small" id="inbGuest">
        @if($c->guest)
          <div class="bold">{{ $c->guest->full_name }}</div>
          <div class="muted">{{ $c->guest->phone ?: 'no phone' }}{{ $c->guest->email ? ' · '.$c->guest->email : '' }}</div>
          @if($c->guest->language)<div class="muted">Language: {{ strtoupper($c->guest->language) }}</div>@endif
        @else
          <div class="muted">Not matched to a guest profile ({{ $c->handleLabel() }}).</div>
          @if($canReply)
          <form method="post" action="{{ route('inbox.link', $c) }}" class="mt-1" id="inbLinkForm">@csrf
            <input class="input" id="inbGuestQ" placeholder="Find guest by name / phone…" autocomplete="off" data-src="{{ route('inbox.guests') }}">
            <select name="guest_id" class="input mt-1" id="inbGuestSel" required><option value="">—</option></select>
            <button class="btn btn-sm mt-1">Link to guest</button>
          </form>
          @endif
        @endif
        @if($c->stay)
          <hr class="inb-hr">
          <div class="inb-kv"><span>Stay</span><b>{{ $c->stay->status === 'checked_in' ? 'In house' : ($c->stay->status === 'reserved' ? 'Arriving' : 'Departed') }}</b></div>
          @if($c->stay->room)<div class="inb-kv"><span>Room</span><b>{{ $c->stay->room->room_no }}</b></div>@endif
          <div class="inb-kv"><span>Dates</span><b>{{ $c->stay->arrival_date->format('d M') }} → {{ $c->stay->departure_date->format('d M') }}</b></div>
          @if($c->reservation)<div class="inb-kv"><span>Booking</span><a href="{{ route('reservations.show', $c->reservation) }}">{{ $c->reservation->reservation_no }}</a></div>@endif
          @if(($balance ?? null) !== null)<div class="inb-kv"><span>Balance</span><b>{{ money($balance) }}</b></div>@endif
        @endif
      </div></div>
      @if($canReply)
      <div class="card"><div class="card-head"><h3>Handling</h3></div><div class="card-body small">
        <form method="post" action="{{ route('inbox.assign', $c) }}" class="inb-row-form">@csrf<label>Assigned</label>
          <select name="user_id" class="input" onchange="this.form.submit()" id="inbAssign"><option value="">Unassigned</option>@foreach($agents as $a)<option value="{{ $a->id }}" @selected($c->assigned_to === $a->id)>{{ $a->name }}</option>@endforeach</select></form>
        <form method="post" action="{{ route('inbox.department', $c) }}" class="inb-row-form">@csrf<label>Team</label>
          <select name="department" class="input" onchange="this.form.submit()" id="inbDept">@foreach($C::DEPARTMENTS as $k => $l)<option value="{{ $k }}" @selected($c->department === $k)>{{ $l }}</option>@endforeach</select></form>
        <form method="post" action="{{ route('inbox.priority', $c) }}" class="inb-row-form">@csrf<label>Priority</label>
          <select name="priority" class="input" onchange="this.form.submit()" id="inbPrio"><option value="normal" @selected($c->priority === 'normal')>Normal</option><option value="high" @selected($c->priority === 'high')>Urgent</option></select></form>
        @if($c->waitingMinutes() !== null)<p class="m-0 mt-1 {{ $c->isOverdue($sla) ? 'text-red bold' : 'muted' }}">Guest waiting {{ $c->waitingMinutes() }} min (target {{ $sla }} min)</p>@endif
        @if($c->csat)<p class="m-0 mt-1">Guest rating: <b>{{ str_repeat('★', $c->csat) }}{{ str_repeat('☆', 5 - $c->csat) }}</b></p>@endif
      </div></div>
      @endif
      @if($c->stay && $c->stay->status === 'checked_in')
      <div class="card"><div class="card-head"><h3>Guest requests</h3><a class="small" href="{{ route('requests.index') }}">all</a></div><div class="card-body small" id="inbRequests">
        @forelse($requests as $q)<div class="inb-kv"><span>{{ $q->request_no }} · {{ $q->item }}</span><span class="badge badge-{{ in_array($q->status, \App\Models\GuestRequest::OPEN) ? 'amber' : 'green' }}">{{ \App\Models\GuestRequest::STATUSES[$q->status] ?? $q->status }}</span></div>@empty<p class="muted m-0">No requests this stay.</p>@endforelse
        @if($canReply)
        <form method="post" action="{{ route('inbox.request', $c) }}" class="mt-1" id="inbRaise">@csrf
          <select name="request" class="input" id="inbRaiseSel" required onchange="const [a,b]=this.value.split('|');this.form.category.value=a||'';this.form.item.value=b||''"><option value="">Raise a request…</option>
            @foreach($categories as $ck => [$cl]) @if(! in_array($ck, ['checkout', 'wakeup', 'transport', 'late_checkout', 'front_desk']))<optgroup label="{{ $cl }}">@foreach(\App\Services\GuestPortalService::items($ck) as $it)<option value="{{ $ck }}|{{ $it }}">{{ $it }}</option>@endforeach</optgroup>@endif @endforeach
          </select>
          <input type="hidden" name="category"><input type="hidden" name="item">
          <input name="notes" class="input mt-1" maxlength="300" placeholder="Note for the team (optional)">
          <button class="btn btn-sm mt-1">Raise request</button>
        </form>
        @endif
      </div></div>
      @endif
      @if(($history ?? collect())->isNotEmpty())
      <div class="card"><div class="card-head"><h3>Earlier conversations</h3></div><div class="card-body small">
        @foreach($history as $h)<div class="inb-kv"><a href="{{ route('inbox.index', ['c' => $h->id, 'f' => 'all']) }}">{{ $h->number }} · {{ $h->channelLabel() }}</a><span class="muted">{{ $h->last_message_at?->format('d M') }}</span></div>@endforeach
      </div></div>
      @endif
    @else
      <div class="card"><div class="card-body small">
        <p class="m-0"><b>{{ $counts['waiting'] ?? 0 }}</b> conversation(s) need a reply · <b>{{ $counts['unassigned'] ?? 0 }}</b> unassigned.</p>
        <p class="muted m-0 mt-1">Desk hours {{ $cfg['from'] }}–{{ $cfg['to'] }} · {{ \App\Services\InboxService::inHours() ? 'open now' : 'outside hours: guests get the automatic reply' }}.</p>
      </div></div>
    @endif
  </aside>
</div>

@if($canReply)
<div class="modal" id="inbNewModal"><div class="modal-box"><form method="post" action="{{ route('inbox.start') }}" data-reset>@csrf
  <div class="modal-head"><h3>Message an in-house guest</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body form-grid">
    <div class="field span-all"><label>Guest</label><select name="stay_id" class="input" required id="inbNewStay"><option value="">Choose…</option>@foreach($inhouse as $s)<option value="{{ $s->id }}">{{ $s->room?->room_no }} – {{ ($s->guest ?? $s->reservation?->guest)?->full_name }}</option>@endforeach</select></div>
    <div class="field span-all"><label>Channel</label><select name="channel" class="input" id="inbNewCh"><option value="portal">Guest portal chat</option><option value="sms">SMS</option><option value="email">E-mail</option></select>
      <small class="muted">WhatsApp can only answer a guest who has written to the hotel's WhatsApp number in the last 24 hours.</small></div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" id="inbNewGo">Open conversation</button></div>
</form></div></div>
@endif
@endsection
@push('scripts')<script src="{{ asset('js/inbox.js') }}?v=73"></script>@endpush
