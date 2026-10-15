@extends('layouts.app')
@section('title', $c->name)
@section('crumbs') <a href="{{ route('cm.index') }}">Channel Manager</a> / {{ $c->name }} @endsection
@php $manage = can_do('channels.manage'); @endphp
@section('actions')
@if($manage)
  @if($c->is_active)
  <form method="post" action="{{ route('cm.connections.push', $c) }}" class="inline">@csrf<button class="btn" id="pushBtn"><x-icon name="refresh"/> Push changes</button></form>
  <form method="post" action="{{ route('cm.connections.push', $c) }}" class="inline" data-confirm="Send the full {{ $c->push_days }}-day availability, rates and restrictions to {{ $c->name }}?">@csrf<input type="hidden" name="full" value="1"><button class="btn" id="fullSyncBtn"><x-icon name="download"/> Full sync</button></form>
  <form method="post" action="{{ route('cm.connections.test', $c) }}" class="inline">@csrf<button class="btn" id="testConnBtn"><x-icon name="globe"/> Test connection</button></form>
  @if(can_do('channels.manage') && $c->provider !== 'simulator')<form method="post" action="{{ route('cm.connections.pull', $c) }}" class="inline">@csrf<button class="btn" id="pullBtn"><x-icon name="download"/> Pull bookings</button></form>@endif
  @endif
  <a class="btn" href="{{ route('cm.connections.edit', $c) }}" id="editConn"><x-icon name="edit"/> Edit</a>
  @if(! $c->reservations()->exists())<form method="post" action="{{ route('cm.connections.destroy', $c) }}" class="inline" data-confirm="Delete connection {{ $c->name }} with its mappings and logs?">@csrf @method('DELETE')<button class="btn btn-ghost btn-icon" title="Delete"><x-icon name="trash"/></button></form>@endif
@endif
@endsection
@section('content')
@if(! $c->is_active)<div class="alert alert-warn"><x-icon name="clock"/><div><b>Paused.</b> No pushes; inbound bookings are refused with HTTP 423 so the channel retries later.</div></div>
@elseif($c->last_push_ok === false)<div class="alert alert-error" id="failNote"><x-icon name="alert"/><div><b>Sync failing</b> ({{ $c->fail_count }} attempt(s)): {{ $c->last_push_status }}.@if($c->next_retry_at) Next automatic retry {{ $c->next_retry_at->diffForHumans() }}.@endif</div></div>@endif
@if($c->roomMaps->isEmpty())<div class="alert alert-warn" id="mapNote"><x-icon name="alert"/><div><b>Nothing mapped yet.</b> Map at least one room type below – unmapped rooms are not offered on the channel and bookings for them land in the inbox.</div></div>@endif
<div class="stats req5">
  <x-stat label="Status" :value="['ok' => 'Connected', 'failing' => 'Failing', 'paused' => 'Paused', 'pending' => 'Awaiting sync'][$c->health()]" icon="globe" :tone="['ok' => 'green', 'failing' => 'red', 'paused' => 'slate', 'pending' => 'blue'][$c->health()]" :sub="$c->last_push_at ? 'last push '.$c->last_push_at->diffForHumans() : 'never pushed'"/>
  <x-stat label="Pending changes" :value="$c->full_sync_due ? 'Full' : $pending" icon="refresh" tone="blue" :sub="$c->full_sync_due ? 'full refresh queued' : 'room-nights to push'"/>
  <x-stat label="Bookings (30 d)" :value="$stats['bookings']" icon="calendar" tone="purple" :sub="number_format($stats['nights']).' RN · '.$stats['cancelled'].' cancelled'"/>
  <x-stat label="Revenue (30 d)" :value="money($stats['revenue'])" icon="money" tone="green" sub="gross booking value"/>
  <x-stat label="Commission (30 d)" :value="money($stats['commission'])" icon="wallet" tone="slate" :sub="rtrim(rtrim((string) $c->commission_pct, '0'), '.').'% contract rate'"/>
</div>
<div class="grid g-2-1">
  <div>
    <div class="card"><div class="card-head"><h3>What the channel gets – next 7 nights</h3><a class="small" href="{{ route('cm.parity', ['connection' => $c->id]) }}">Parity view →</a></div><div class="card-body table-wrap">
      @if(empty($preview))<x-empty text="Map room types to see the ARI preview." icon="bed"/>@else
      <table class="table" id="ariPreview" style="font-size:.78rem"><thead><tr><th>Room</th>@foreach(reset($preview) as $date => $v)<th class="center">{{ \Illuminate\Support\Carbon::parse($date)->format('D d') }}</th>@endforeach</tr></thead><tbody>
      @foreach($preview as $typeId => $byDate) @php $first = reset($byDate); @endphp
        <tr><td class="nowrap"><code>{{ $first['code'] }}</code> <span class="tiny muted">{{ $types->firstWhere('id', $typeId)?->name }}</span></td>@foreach($byDate as $v)<td class="center" style="{{ $v['stop_sell'] ? 'color:#b91c1c' : '' }}"><b>{{ $v['stop_sell'] ? 'closed' : $v['available'].' left' }}</b>@if($v['min_stay'] > 1)<div class="tiny muted">min {{ $v['min_stay'] }}</div>@endif @if($v['cta'])<div class="tiny" style="color:#b45309">CTA</div>@endif</td>@endforeach</tr>
        @foreach($first['rates'] as $code => $x)<tr><td class="small" style="padding-left:1.2rem"><code>{{ $code }}</code></td>@foreach($byDate as $v)<td class="center small">{{ number_format($v['rates'][$code], $v['rates'][$code] == (int) $v['rates'][$code] ? 0 : 2) }}</td>@endforeach</tr>@endforeach
      @endforeach
      </tbody></table>@endif
    </div></div>

    <div class="card" id="mapping"><div class="card-head"><h3>Room &amp; rate mapping</h3><span class="tiny muted">codes exactly as set up in the channel's extranet</span></div><div class="card-body table-wrap">
      @if($manage)<form method="post" action="{{ route('cm.connections.mapping', $c) }}" id="mappingForm">@csrf @endif
      <table class="table" id="mapTable"><thead><tr><th>PMS room type</th><th>Channel room code</th><th class="num">Max to sell</th><th>Active</th><th>Rate plans → channel rate codes</th></tr></thead><tbody>
      @foreach($types as $t) @php $rm = $c->roomMaps->firstWhere('room_type_id', $t->id); $rates = $c->rateMaps->where('room_type_id', $t->id); @endphp
        <tr><td><b>{{ $t->name }}</b><div class="tiny muted">{{ $t->code }} · BAR {{ money($t->base_rate) }}</div></td>
          <td><input name="rooms[{{ $t->id }}][code]" value="{{ old("rooms.$t->id.code", $rm?->channel_room_code) }}" placeholder="not mapped" style="width:120px;text-transform:uppercase" @disabled(! $manage)></td>
          <td class="num"><input type="number" min="0" name="rooms[{{ $t->id }}][max_sell]" value="{{ old("rooms.$t->id.max_sell", $rm?->max_sell) }}" placeholder="all" style="width:70px" @disabled(! $manage)></td>
          <td><input type="hidden" name="rooms[{{ $t->id }}][active]" value="0"><input type="checkbox" name="rooms[{{ $t->id }}][active]" value="1" @checked($rm ? $rm->is_active : true) @disabled(! $manage)></td>
          <td><div class="flex gap-1 wrap">
            @php $bar = $rates->first(fn ($r) => $r->rate_plan_id === null); @endphp
            <label class="tiny">Room only (BAR)<br><input name="rates[{{ $t->id }}][bar]" value="{{ old("rates.$t->id.bar", $bar?->channel_rate_code) }}" style="width:95px;text-transform:uppercase" @disabled(! $manage)></label>
            @foreach($plans->get($t->id, collect()) as $pl) @php $pm = $rates->firstWhere('rate_plan_id', $pl->id); @endphp
              <label class="tiny">{{ $pl->meal_plan }} <span class="muted">(+{{ number_format(max(0, $pl->rate - $t->base_rate)) }})</span><br><input name="rates[{{ $t->id }}][{{ $pl->id }}]" value="{{ old("rates.$t->id.$pl->id", $pm?->channel_rate_code) }}" style="width:95px;text-transform:uppercase" @disabled(! $manage)></label>
            @endforeach
          </div></td></tr>
      @endforeach
      </tbody></table>
      @error('rooms')<div class="err">{{ $message }}</div>@enderror @error('rates')<div class="err">{{ $message }}</div>@enderror
      @if($manage)<div class="flex between mt-1"><span class="tiny muted">Blank room code = not sold on this channel. Rate price = nightly PMS rate + meal-plan supplement, {{ (float) $c->rate_markup_pct ? 'then '.($c->rate_markup_pct > 0 ? '+' : '').rtrim(rtrim((string) $c->rate_markup_pct, '0'), '.').'% markup' : 'no markup' }}.</span><button class="btn btn-primary" id="saveMapping"><x-icon name="check"/> Save mapping</button></div></form>@endif
    </div></div>

    <div class="card" id="restrictions"><div class="card-head"><h3>Channel restrictions</h3><span class="tiny muted">apply to this channel only – on top of the rate calendar</span></div><div class="card-body">
      <table class="table" id="restrTable"><thead><tr><th>Dates</th><th>Room</th><th>Restriction</th><th>Reason</th><th>By</th><th></th></tr></thead><tbody>
      @forelse($restrictions as $r)
        <tr><td class="small nowrap">{{ $r->date_from->format('d M') }} – {{ $r->date_to->format('d M Y') }}</td><td class="small">{{ $r->roomType?->name ?? 'All mapped rooms' }}</td><td><b class="small">{{ $r->summary() }}</b></td>
          <td class="small muted">{{ $r->reason }}</td><td class="small">{{ $r->creator?->name ?? '—' }}</td>
          <td class="right">@if($manage)<form method="post" action="{{ route('cm.connections.unrestrict', [$c, $r]) }}" class="inline" data-confirm="Remove this restriction?">@csrf @method('DELETE')<button class="btn btn-ghost btn-icon" title="Remove"><x-icon name="trash"/></button></form>@endif</td></tr>
      @empty<tr><td colspan="6" class="muted small">No channel-specific restrictions.</td></tr>@endforelse
      </tbody></table>
      @if($manage)
      <form method="post" action="{{ route('cm.connections.restrict', $c) }}" class="form-grid mt-1" id="restrForm" style="background:#f8fafc;padding:.7rem;border-radius:8px">@csrf
        <x-field name="room_type_id" label="Room type" type="select" :options="$types->whereIn('id', $c->roomMaps->pluck('room_type_id'))->pluck('name', 'id')" placeholder="All mapped rooms"/>
        <x-field name="date_from" label="From" type="date" :value="bdate()->toDateString()" required/>
        <x-field name="date_to" label="To (inclusive)" type="date" :value="bdate()->copy()->addDays(2)->toDateString()" required/>
        <x-field name="min_stay" label="Min stay" type="number" min="1" max="30" placeholder="—"/>
        <div class="field"><label>&nbsp;</label><label class="check"><input type="checkbox" name="stop_sell" value="1" id="f_stop_sell"> Stop-sell</label></div>
        <div class="field"><label>&nbsp;</label><label class="check"><input type="checkbox" name="closed_to_arrival" value="1"> Closed to arrival</label></div>
        <x-field name="reason" label="Reason" placeholder="e.g. High direct demand – festival" class="span-2"/>
        @error('stop_sell')<div class="err span-all">{{ $message }}</div>@enderror
        <div class="span-all right"><button class="btn btn-primary" id="addRestr"><x-icon name="plus"/> Add restriction</button></div>
      </form>@endif
    </div></div>

    <div class="card flush"><div class="card-head"><h3>Recent messages</h3><a class="small" href="{{ route('cm.inbox', ['connection' => $c->id, 'tab' => 'all']) }}">All →</a></div><div class="card-body table-wrap"><table class="table" id="connMsgs">
      <thead><tr><th>Received</th><th>Ref</th><th>Event</th><th>Guest</th><th class="num">Amount</th><th>Result</th><th>Reservation</th></tr></thead><tbody>
      @forelse($messages as $m)<tr><td class="small nowrap"><a href="{{ route('cm.message', $m) }}">{{ $m->created_at->format('d M H:i') }}</a></td><td class="small">{{ $m->channel_ref }}</td><td><span class="badge badge-slate">{{ $m->event }}</span></td><td>{{ $m->guestName() }}</td>
        <td class="num">{{ $m->amount !== null ? money($m->amount) : '—' }}</td><td>{!! $m->statusBadge() !!}</td><td>@if($m->reservation)<a href="{{ route('reservations.show', $m->reservation) }}">{{ $m->reservation->reservation_no }}</a>@endif</td></tr>
      @empty<tr><td colspan="7"><x-empty text="No messages from this channel yet." icon="mail"/></td></tr>@endforelse
      </tbody></table></div></div>
  </div>

  <div>
    <div class="card"><div class="card-head"><h3>{{ $c->name }}</h3>@include('channel-manager._health', ['conn' => $c])</div><div class="card-body"><dl class="kv">
      <dt>Code</dt><dd><code>{{ $c->code }}</code></dd>
      <dt>Type</dt><dd>{{ \App\Models\ChannelConnection::providerOptions()[$c->provider] ?? $c->provider }}</dd>
      <dt>Hotel ID</dt><dd>{{ $c->property_code ?: '—' }}</dd>
      @if(! $c->isSimulator())<dt>Channel endpoint</dt><dd class="small" style="word-break:break-all">{{ $c->endpoint_url ?: '—' }}</dd><dt>API key</dt><dd>{{ $c->api_key ? '•••• stored (encrypted)' : '—' }}</dd>
      <dt>API secret</dt><dd>{{ $c->api_secret ? '•••• stored (encrypted)' : '—' }}@if(! $c->api_secret && $c->provider === 'expedia') <span class="badge badge-amber">needed to sign requests</span>@endif</dd>@endif
      <dt>Commission</dt><dd>{{ rtrim(rtrim((string) $c->commission_pct, '0'), '.') }}%</dd>
      <dt>Rate markup</dt><dd>{{ (float) $c->rate_markup_pct ? ($c->rate_markup_pct > 0 ? '+' : '').rtrim(rtrim((string) $c->rate_markup_pct, '0'), '.').'%' : 'none (parity)' }} · {{ \App\Models\ChannelConnection::ROUNDING[$c->rate_rounding] ?? '' }}</dd>
      <dt>Collect</dt><dd>{{ $c->payment_model === 'channel_collect' ? 'Channel collect (prepaid)' : 'Hotel collect' }}</dd>
      <dt>Source</dt><dd>{{ $c->source?->name ?? '—' }}</dd>
      <dt>OTA account</dt><dd>@if($c->company && can_do('credit.view'))<a href="{{ route('credit.show', $c->company) }}">{{ $c->company->name }}</a>@else{{ $c->company?->name ?? '—' }}@endif</dd>
      <dt>Horizon</dt><dd>{{ $c->push_days }} days</dd>
      <dt>Last booking</dt><dd>{{ $c->last_booking_at?->diffForHumans() ?? '—' }}</dd>
    </dl></div></div>

    @if($manage)
    <div class="card" id="integration"><div class="card-head"><h3>Integration</h3><span class="tiny muted">Nowills Channel API v1</span></div><div class="card-body small">
      <p class="muted">Give these to the channel / hub. Every message is signed: <code>X-Nowills-Timestamp</code> (unix seconds) and <code>X-Nowills-Signature: sha256=HMAC_SHA256(secret, timestamp + "." + body)</code>.</p>
      <label class="tiny bold">Reservations webhook (POST)</label><input readonly value="{{ $c->webhookUrl() }}" id="webhookUrl" onclick="this.select()" style="width:100%;font-size:.72rem">
      <label class="tiny bold mt-1">Availability pull (GET ?from=&amp;days=)</label><input readonly value="{{ $c->ariUrl() }}" onclick="this.select()" style="width:100%;font-size:.72rem">
      <label class="tiny bold mt-1">Shared secret</label>
      <div class="flex gap-1"><input readonly type="password" value="{{ $c->secret }}" id="secretBox" style="flex:1;font-size:.72rem"><button type="button" class="btn btn-sm" onclick="const s=document.getElementById('secretBox'); s.type = s.type === 'password' ? 'text' : 'password'"><x-icon name="eye"/></button></div>
      <form method="post" action="{{ route('cm.connections.rotate', $c) }}" class="mt-1" data-confirm="Generate a new secret? The channel must be updated immediately – messages signed with the old secret will be refused.">@csrf<button class="btn btn-sm" id="rotateBtn"><x-icon name="key"/> Rotate secret</button></form>
    </div></div>
    @endif

    @if($c->isSimulator() && $manage)
    <div class="card" id="simulator"><div class="card-head"><h3>Test channel – simulate</h3><span class="badge badge-purple">sandbox</span></div><div class="card-body">
      <p class="small muted">Sends a message exactly as an OTA would – through validation, idempotency, mapping and inventory checks – and pushes the new availability back.</p>
      <form method="post" action="{{ route('cm.connections.simulate', $c) }}" class="form-grid" id="simForm">@csrf
        <x-field name="event" label="Event" type="select" :options="['new' => 'New booking', 'modify' => 'Modify booking', 'cancel' => 'Cancel booking']" value="new" :placeholder="false" required/>
        <x-field name="booking_id" label="Booking ref" placeholder="auto for new"/>
        <x-field name="room_map_id" label="Room" type="select" :options="$c->roomMaps->mapWithKeys(fn ($m) => [$m->id => $m->channel_room_code.' – '.$types->firstWhere('id', $m->room_type_id)?->name])" :placeholder="false"/>
        <x-field name="rooms" label="Rooms" type="number" min="1" max="5" value="1"/>
        <x-field name="arrival" label="Arrival" type="date" :value="bdate()->copy()->addDays(3)->toDateString()"/>
        <x-field name="departure" label="Departure" type="date" :value="bdate()->copy()->addDays(5)->toDateString()"/>
        <x-field name="adults" label="Adults" type="number" min="1" max="6" value="2"/>
        <x-field name="currency" label="Currency the OTA quotes in" type="select" :placeholder="false" value=""
                 :options="['' => (hotel()->currency ?: 'GHS').' (hotel)'] + \App\Models\Currency::where('is_active', true)->orderBy('code')->pluck('code', 'code')->all()"
                 hint="A foreign currency is converted at the forex board — Foreign Guests › Settings › Forex desk."/>
        <x-field name="first_name" label="Guest first name" value="Test"/>
        <x-field name="last_name" label="Last name" value="Guest"/>
        <x-field name="email" label="Email" type="email" placeholder="optional"/>
        <div class="span-all right"><button class="btn btn-primary" id="simSend"><x-icon name="globe"/> Send from {{ $c->code }}</button></div>
      </form>
      @if($simBookings->isNotEmpty())
      <h4 class="mt-2">Open test bookings</h4>
      <ul class="list" id="simList">@foreach($simBookings as $b)<li><span class="small"><a href="{{ route('reservations.show', $b) }}">{{ $b->reservation_no }}</a> · <code>{{ $b->ota_reference }}</code> · {{ $b->guest?->full_name }}</span>
        <form method="post" action="{{ route('cm.connections.simulate', $c) }}" class="inline" data-confirm="Send a cancellation for {{ $b->ota_reference }} from {{ $c->name }}?">@csrf<input type="hidden" name="event" value="cancel"><input type="hidden" name="booking_id" value="{{ $b->ota_reference }}"><button class="btn btn-sm">Cancel via OTA</button></form></li>@endforeach</ul>
      @endif
    </div></div>
    @endif

    <div class="card"><div class="card-head"><h3>Recent pushes</h3><a class="small" href="{{ route('cm.pushes', ['connection' => $c->id]) }}">Log →</a></div><div class="card-body">
      <ul class="list" id="connPushes">@forelse($pushes as $p)<li><span class="small">{{ $p->created_at->format('d M H:i') }} <span class="muted">{{ $p->kind }} · {{ $p->room_days }} RN</span></span>{!! $p->status === 'ok' ? '<span class="badge badge-green">ok</span>' : '<span class="badge badge-red" title="'.e($p->error).'">failed</span>' !!}</li>@empty<li class="muted small">No pushes yet.</li>@endforelse</ul>
    </div></div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const ev = document.getElementById('f_event'); if (!ev) return;
  const toggle = () => ['room_map_id', 'rooms', 'arrival', 'departure', 'adults', 'first_name', 'last_name', 'email'].forEach(n => {
    const el = document.getElementById('f_' + n); if (el) el.closest('.field').style.display = ev.value === 'cancel' ? 'none' : '';
  });
  ev.addEventListener('change', toggle); toggle();
});
</script>
@endsection
