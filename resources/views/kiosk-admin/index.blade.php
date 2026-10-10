@extends('layouts.app')
@section('title', 'Self-Service Kiosk')
@section('crumbs') Self-Service Kiosk / Dashboard @endsection
@php $KS = \App\Services\KioskService::class; $u = auth()->user(); $canMg = $KS::canManage($u); $canAs = $KS::canAssist($u); $S = \App\Models\KioskSession::class; @endphp
@section('actions')
  <a class="btn" href="{{ route('kiosks.sessions') }}"><x-icon name="clock"/> Guest visits</a>
  @if($canMg)<a class="btn btn-primary" href="{{ route('kiosks.devices') }}#pair" id="kskPairBtn"><x-icon name="plus"/> Pair a kiosk</a>@endif
@endsection
@section('content')
@if(! $cfg['enabled'])<div class="alert alert-warn"><x-icon name="alert"/><div>Self-service kiosks are switched off – kiosks show “please see the front desk”. @if($canMg)<a href="{{ route('kiosks.settings') }}">Turn them on in kiosk settings.</a>@endif</div></div>@endif
<div class="stats ksk6" id="kskStats">
  <x-stat label="Kiosks online" :value="$d['kiosks']->filter->isOnline()->count().' / '.$d['kiosks']->where('status', 'active')->count()" icon="wifi" :tone="$d['kiosks']->where('status', 'active')->reject->isOnline()->count() ? 'amber' : 'green'" :sub="$d['live']->count().' guest(s) using a kiosk now'" :href="route('kiosks.devices')"/>
  <x-stat label="Self check-ins today" :value="$d['kiosk_checkins'].' / '.$d['checkins_today']" icon="key" tone="blue" :sub="$d['share'].'% of today\'s check-ins · '.$d['arrivals_left'].' arrival(s) still due'"/>
  <x-stat label="Self check-outs today" :value="$d['kiosk_checkouts'].' / '.$d['checkouts_today']" icon="check" tone="purple" :sub="$d['walkins_today'].' walk-in booking(s) at the kiosk'"/>
  <x-stat label="Collected today" :value="money($d['collected_today'])" icon="money" tone="teal" sub="deposits & bills paid by QR at the kiosk"/>
  <x-stat label="Average check-in" :value="$d['avg_seconds'] ? floor($d['avg_seconds'] / 60).'m '.str_pad($d['avg_seconds'] % 60, 2, '0', STR_PAD_LEFT).'s' : '—'" icon="clock" tone="slate" sub="finished kiosk check-ins · 30 days"/>
  <x-stat label="Need the desk" :value="$d['queue']->count()" icon="bell" :tone="$d['queue']->count() ? 'red' : 'green'" sub="guests waiting for help"/>
</div>
<div class="g-2-1">
  <div>
    <div class="card {{ $d['queue']->count() ? 'ksk-queue-live' : '' }}" id="kskQueue"><div class="card-head"><h3><x-icon name="bell"/> Assistance queue</h3><span class="tiny muted">guests the kiosk handed to the desk</span></div><div class="card-body table-wrap">
      @if($d['queue']->isEmpty())<p class="muted m-0">Nobody is waiting – every kiosk visit finished on its own.</p>@else
      <table class="table table-compact"><thead><tr><th>Waiting</th><th>Guest</th><th>Why</th><th>Kiosk</th><th></th></tr></thead><tbody>
      @foreach($d['queue'] as $s)
        <tr class="{{ $s->assist_at->lt(now()->subMinutes(5)) ? 'row-red' : '' }}"><td class="nowrap"><b>{{ $s->assist_at->diffForHumans(null, true) }}</b><div class="tiny muted">{{ $s->assist_at->format('H:i') }}</div></td>
          <td><a href="{{ route('kiosks.session', $s) }}">@if($s->guest)<b>{{ $s->guest->full_name }}</b>@else<span class="ksk-anon">Guest not identified</span>@endif</a><div class="tiny muted">{{ $s->kindLabel() }}{{ $s->reservation ? ' · '.$s->reservation->reservation_no : '' }}{{ $s->room ? ' · room '.$s->room->room_no : '' }}</div></td>
          <td class="small"><span class="badge badge-red">{{ $s->assistLabel() }}</span>@if($s->assist_note)<div class="tiny muted">{{ \Illuminate\Support\Str::limit($s->assist_note, 90) }}</div>@endif</td>
          <td class="small">{{ $s->kiosk?->name }}</td>
          <td class="nowrap">@if($canAs)<form method="post" action="{{ route('kiosks.resolve', $s) }}" class="inline ksk-resolve">@csrf<input type="hidden" name="resolution" value="Guest helped at the desk"><button class="btn btn-sm btn-primary">Dealt with</button></form>@endif</td></tr>
      @endforeach
      </tbody></table>@endif
    </div></div>
    <div class="card"><div class="card-head"><h3>Check-in funnel – last 30 days</h3><span class="tiny muted">where kiosk check-ins stop</span></div><div class="card-body" id="kskFunnel">
      @php $top = max(1, reset($d['funnel'])); @endphp
      @foreach($d['funnel'] as $lbl => $n)
        <div class="ksk-fun"><span class="small">{{ $lbl }}</span><div class="ksk-fun-bar"><i style="width:{{ round($n / $top * 100) }}%"></i></div><b class="small">{{ $n }}</b><span class="tiny muted">{{ round($n / $top * 100) }}%</span></div>
      @endforeach
    </div></div>
    <div class="card"><div class="card-head"><h3>Visits by hour – last 14 days</h3></div><div class="card-body">
      @php $mx = max(1, max($d['hours'])); @endphp
      <div class="bar-chart" id="kskHours" style="height:130px">@foreach($d['hours'] as $h => $n)<div class="col"><span class="tiny muted">{{ $n ?: '' }}</span><div class="b" style="height:{{ round($n / $mx * 72) }}%" data-v="{{ $n }} visits {{ sprintf('%02d', $h) }}:00"></div><small>{{ sprintf('%02d', $h) }}</small></div>@endforeach</div>
    </div></div>
  </div>
  <div>
    <div class="card" id="kskKiosks"><div class="card-head"><h3>Kiosks</h3><a class="small" href="{{ route('kiosks.devices') }}">manage</a></div><div class="card-body">
      @forelse($d['kiosks'] as $k)
        <div class="ksk-dev"><span class="badge badge-{{ $k->stateTone() }}">{{ $k->stateLabel() }}</span><div><b>{{ $k->name }}</b><div class="tiny muted">{{ $k->location ?: '—' }} · {{ implode(', ', $k->services()) ?: 'no services' }}{{ $k->last_seen_at ? ' · seen '.$k->last_seen_at->diffForHumans() : '' }}</div></div></div>
      @empty<p class="muted m-0">No kiosks yet. @if($canMg)<a href="{{ route('kiosks.devices') }}#pair">Pair the first one.</a>@endif</p>@endforelse
    </div></div>
    <div class="card"><div class="card-head"><h3>On the kiosks now</h3></div><div class="card-body">
      @forelse($d['live'] as $s)
        <div class="ksk-dev"><span class="badge badge-blue">{{ $s->stepLabel() }}</span><div><a href="{{ route('kiosks.session', $s) }}"><b>{{ $s->guest?->full_name ?? $s->kindLabel() }}</b></a><div class="tiny muted">{{ $s->kiosk?->name }} · {{ $s->kindLabel() }} · {{ $s->durationLabel() }}</div></div></div>
      @empty<p class="muted m-0 small">No guest is using a kiosk right now.</p>@endforelse
    </div></div>
    <div class="card"><div class="card-head"><h3>Recent visits</h3><a class="small" href="{{ route('kiosks.sessions') }}">all</a></div><div class="card-body">
      @forelse($d['recent'] as $s)
        <div class="ksk-dev"><span class="badge badge-{{ $S::STATUS_TONES[$s->status] ?? 'slate' }}">{{ $s->statusLabel() }}</span><div><a href="{{ route('kiosks.session', $s) }}">@if($s->guest)<b>{{ $s->guest->full_name }}</b>@else<span class="ksk-anon">Guest not identified</span>@endif</a><div class="tiny muted">{{ $s->kindLabel() }}{{ $s->room ? ' · room '.$s->room->room_no : '' }} · {{ $s->started_at->format('d M H:i') }} · {{ $s->durationLabel() }}</div></div></div>
      @empty<p class="muted m-0 small">No visits yet.</p>@endforelse
    </div></div>
    <div class="card"><div class="card-head"><h3>Set up a kiosk</h3></div><div class="card-body small">
      <p class="m-0">Open <code class="tv-url">{{ $setupUrl }}</code> full-screen on the kiosk (touch PC / tablet in kiosk mode), then enter the code it shows under <a href="{{ route('kiosks.devices') }}#pair">Kiosks › Pair</a>. Guests pay by scanning a QR code with mobile money or a card – no card terminal needed; the key-card encoder uses the door-lock interface.</p>
    </div></div>
  </div>
</div>
@endsection
