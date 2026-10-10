@extends('layouts.app')
@section('title', 'IPTV & Guest Welcome')
@section('crumbs') IPTV &amp; Guest Welcome / Dashboard @endsection
@php $u = auth()->user(); $IS = \App\Services\IptvService::class; $canMg = $IS::canManage($u); $canMsg = $IS::canMessage($u); $canEm = $IS::canEmergency($u); $E = \App\Models\TvEvent::EVENTS; @endphp
@section('actions')
  @if($canMsg)<a class="btn" href="{{ route('iptv.messages') }}#new" id="tvNewMessage"><x-icon name="message"/> Message TVs</a>@endif
  @if($canMg)<a class="btn btn-primary" href="{{ route('iptv.devices') }}#pair" id="tvPairBtn"><x-icon name="plus"/> Pair a TV</a>@endif
@endsection
@section('content')
@include('iptv._emergency', ['emergency' => $emergency, 'canEm' => $canEm])
@if(! $cfg['enabled'])<div class="alert alert-warn"><x-icon name="alert"/><div>The TV portal is switched off – guest TVs show a plain “welcome” screen only. @if($canMg)<a href="{{ route('iptv.settings') }}">Turn it on in IPTV settings.</a>@endif</div></div>@endif
<div class="stats" id="tvStats">
  <x-stat label="TVs online" :value="$stats['online'].' / '.$stats['devices']" icon="wifi" :tone="$stats['devices'] && $stats['online'] < $stats['devices'] ? 'amber' : 'green'" :sub="$stats['signage'].' lobby / signage screens'" :href="route('iptv.devices')"/>
  <x-stat label="Rooms with a TV" :value="$stats['rooms_tv'].' / '.$stats['rooms']" icon="bed" tone="blue" :sub="$alerts['no_tv'] ? $alerts['no_tv'].' in-house room(s) without a TV' : 'every in-house room is covered'"/>
  <x-stat label="In-house guests welcomed" :value="$stats['welcomed'].' / '.$stats['inhouse']" icon="star" tone="purple" :sub="$stats['welcomes_today'].' welcome screen(s) seen today'" :href="route('iptv.welcome')"/>
  <x-stat label="Movie rentals · month" :value="$stats['rentals']" icon="eye" tone="teal" :sub="money($stats['rental_rev']).' before tax'" :href="route('iptv.movies')"/>
  <x-stat label="TV orders & requests · 7 days" :value="$stats['orders7'] + $stats['requests7']" icon="coffee" tone="amber" :sub="$stats['orders7'].' dining orders · '.$stats['requests7'].' service requests'" :href="route('iptv.analytics')"/>
  <x-stat label="Messages on screen" :value="$stats['live_msgs']" icon="message" tone="slate" sub="room, floor, group and broadcast messages" :href="route('iptv.messages')"/>
</div>
@if($alerts['offline'] || $alerts['pending'] || $alerts['no_tv'] || $alerts['epg_stale'])
<div class="alert alert-warn" id="tvAlerts"><x-icon name="alert"/><div>
  @if($alerts['offline'])<a href="{{ route('iptv.devices', ['state' => 'offline']) }}"><b>{{ $alerts['offline'] }}</b> TV(s) offline – no heartbeat for {{ $cfg['offline_min'] }}+ minutes.</a> @endif
  @if($alerts['pending'])<a href="{{ route('iptv.devices', ['state' => 'pending']) }}"><b>{{ $alerts['pending'] }}</b> TV(s) showing a pairing code.</a> @endif
  @if($alerts['no_tv'])<span><b>{{ $alerts['no_tv'] }}</b> in-house room(s) have no TV registered.</span> @endif
  @if($alerts['epg_stale'])<a href="{{ route('iptv.channels') }}"><b>{{ $alerts['epg_stale'] }}</b> channel(s) have less than 6 hours of programme guide left – import a fresh XMLTV file.</a> @endif
</div></div>
@endif
<div class="g-2-1">
  <div>
    <div class="card"><div class="card-head"><h3>Room TVs</h3><span class="tiny muted"><i class="tv-key tv-on"></i>online · guest <i class="tv-key tv-idle"></i>online · vacant <i class="tv-key tv-off"></i>offline <i class="tv-key tv-none"></i>no TV · ★ welcomed</span></div><div class="card-body" id="tvFloorGrid">
      @foreach($rooms as $floor => $list)
        <div class="tv-floor"><div class="tiny muted bold">{{ $floor }}</div><div class="tv-grid">
        @foreach($list as $r)@php $devs = $byRoom[$r->id] ?? collect(); $d = $devs->first(); $st = $r->currentStay; $in = $st && $st->status === 'checked_in';
          $cls = ! $d ? 'tv-none' : ($d->status !== 'active' ? 'tv-none' : (! $d->isOnline() ? 'tv-off' : ($in ? 'tv-on' : 'tv-idle'))); $g = $in ? ($st->guest ?? $st->reservation?->guest) : null; @endphp
          @if($d)<a class="tv-tile {{ $cls }}" href="{{ route('iptv.device', $d) }}" title="{{ $d->name }} · {{ $d->stateLabel() }}{{ $d->last_seen_at ? ' · seen '.$d->last_seen_at->diffForHumans() : '' }}">
          @else<span class="tv-tile {{ $cls }}" title="No TV registered for this room">@endif
            <b>{{ $r->room_no }}</b>@if($in && in_array($st->id, $welcomed, true))<i class="tv-star">★</i>@endif
            <small>{{ $g ? \Illuminate\Support\Str::limit(trim($g->first_name.' '.$g->last_name), 14) : ($d ? ($d->isOnline() ? 'vacant' : 'offline') : 'no TV') }}</small>
          @if($d)</a>@else</span>@endif
        @endforeach
        </div></div>
      @endforeach
    </div></div>
    <div class="card"><div class="card-head"><h3>TV usage – last 7 days</h3><a class="small" href="{{ route('iptv.analytics') }}">analytics</a></div><div class="card-body">
      @php $mx = max(1, collect($usage['trend'])->max('n')); @endphp
      <div class="bar-chart" id="tvUsage" style="height:140px">@foreach($usage['trend'] as $t)<div class="col"><span class="tiny muted">{{ $t['n'] ?: '' }}</span><div class="b" style="height:{{ round($t['n'] / $mx * 72) }}%" data-v="{{ $t['n'] }} actions on {{ $t['tvs'] }} TVs"></div><small>{{ $t['label'] }}</small></div>@endforeach</div>
      <p class="tiny muted m-0">{{ number_format($usage['events']) }} screen actions · {{ $usage['adoption'] !== null ? $usage['adoption'].'% of stays used the TV portal' : 'no stays' }} · {{ $usage['rentals'] }} rentals · {{ $usage['orders'] }} dining orders</p>
    </div></div>
  </div>
  <div>
    @if($canEm && ! $emergency)
    <div class="card tv-em-card" id="tvEmergencyCard"><div class="card-head"><h3><x-icon name="alert"/> Emergency alert</h3></div><div class="card-body">
      <p class="small muted m-0">Covers every guest TV and lobby screen with a full-screen alert until you send the all clear. Use for fire, evacuation, severe weather or a security lockdown.</p>
      <details class="mt-1"><summary class="btn btn-danger btn-sm" id="tvEmergencyOpen">Send emergency alert…</summary>
      <form method="post" action="{{ route('iptv.emergency') }}" class="form-grid mt-1" id="tvEmergencyForm">@csrf
        <div class="field span-all"><label>Title</label><input name="title" maxlength="80" value="{{ old('title', 'Fire alarm – please evacuate') }}"></div>
        <div class="field span-all"><label>What guests must do</label><textarea name="body" rows="3" maxlength="500" required>{{ old('body', 'Leave your room now by the nearest stairway. Do not use the lifts. Go to the assembly point in the front car park and wait for staff.') }}</textarea></div>
        <div class="field span-all"><label>Type EMERGENCY to confirm</label><input name="confirm" class="input" autocomplete="off" required pattern="[Ee][Mm][Ee][Rr][Gg][Ee][Nn][Cc][Yy]"></div>
        <div class="span-all"><button class="btn btn-danger">Put the alert on every screen</button></div>
      </form></details>
    </div></div>
    @endif
    <div class="card"><div class="card-head"><h3>Lobby & signage screens</h3></div><div class="card-body">
      <ul class="plain small" id="tvSignage">@forelse($signage as $d)<li class="esg-sec"><a href="{{ route('iptv.device', $d) }}">{{ $d->name }}</a><span class="badge badge-{{ $d->stateTone() }}">{{ $d->stateLabel() }}</span></li>
      @empty<li class="muted">No public screens registered.</li>@endforelse</ul>
    </div></div>
    <div class="card"><div class="card-head"><h3>On screen now</h3><a class="small" href="{{ route('iptv.messages') }}">all messages</a></div><div class="card-body">
      <ul class="plain small" id="tvLiveMsgs">@forelse($messages as $m)<li><b>{{ $m->title }}</b> <span class="tiny muted">{{ $m->number }} · {{ $m->targetLabel() }}{{ $m->ends_at ? ' · until '.$m->ends_at->format('d M H:i') : '' }}</span></li>
      @empty<li class="muted">No messages on the TVs.</li>@endforelse</ul>
    </div></div>
    <div class="card"><div class="card-head"><h3>Recent guest activity</h3></div><div class="card-body">
      <ul class="plain small" id="tvRecent">@forelse($recent as $e)<li class="esg-sec"><span>{{ $E[$e->event] ?? $e->event }}@if($e->target) <span class="muted">· {{ \Illuminate\Support\Str::limit($e->target, 30) }}</span>@endif</span><span class="tiny muted nowrap">{{ $e->stay?->room ? 'Rm '.$e->stay->room->room_no.' · ' : '' }}{{ $e->created_at->format('d M H:i') }}</span></li>
      @empty<li class="muted">No guest activity yet.</li>@endforelse</ul>
    </div></div>
  </div>
</div>
@endsection
