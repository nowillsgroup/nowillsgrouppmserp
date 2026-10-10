@extends('layouts.app')
@section('title', 'Messaging Dashboard')
@section('crumbs') Guest Messaging / Dashboard @endsection
@php $IS = \App\Services\InboxService::class; $C = \App\Models\Conversation::class; $sla = $d['sla']; @endphp
@section('actions')
  <a class="btn btn-primary" href="{{ route('inbox.index', ['f' => 'waiting']) }}" id="inbDashOpen"><x-icon name="message"/> Open inbox</a>
@endsection
@section('content')
@if(! $cfg['enabled'])<div class="alert alert-warn"><x-icon name="alert"/><div>Guest messaging is switched off. @if($IS::canManage(auth()->user()))<a href="{{ route('inbox.settings') }}">Messaging settings</a>@endif</div></div>@endif
<div class="stats ksk6" id="inbStats">
  <x-stat label="Need a reply" :value="$d['waiting']->count()" icon="message" :tone="$d['overdue']->count() ? 'red' : 'blue'" :sub="$d['overdue']->count().' past the '.$sla.'-minute target'" :href="route('inbox.index', ['f' => 'waiting'])"/>
  <x-stat label="Open conversations" :value="$d['active']->count()" icon="users" tone="teal" :sub="$d['unassigned'].' unassigned · '.$d['mine'].' assigned to you'" :href="route('inbox.index')"/>
  <x-stat label="Average first response" :value="$d['avg_response'] !== null ? $IS::fmtSecs($d['avg_response']) : '—'" icon="clock" tone="purple" sub="staff answers · last 7 days"/>
  <x-stat label="Answered within target" :value="$d['within_sla'] !== null ? $d['within_sla'].'%' : '—'" icon="check" :tone="($d['within_sla'] ?? 100) >= 80 ? 'green' : 'amber'" :sub="'target '.$sla.' minutes · last 7 days'"/>
  <x-stat label="Guest messages today" :value="$d['today_in']" icon="bell" tone="slate" :sub="$d['automated'].' of '.$d['out7'].' replies automated · 7 days'"/>
  <x-stat label="Guest satisfaction" :value="$d['csat'] !== null ? $d['csat'].' / 5' : '—'" icon="star" tone="amber" :sub="$d['csat_n'].' chat rating(s) · 30 days'"/>
</div>
<div class="g-2-1">
  <div>
    <div class="card" id="inbOverdue"><div class="card-head"><h3><x-icon name="alert"/> Waiting longest</h3><span class="tiny muted">guests whose last message has no answer yet</span></div><div class="card-body table-wrap">
      @if($d['waiting']->isEmpty())<p class="muted m-0">Every guest has an answer.</p>@else
      <table class="table table-compact"><thead><tr><th>Waiting</th><th>Guest</th><th>Channel</th><th>Team</th><th>Assigned</th></tr></thead><tbody>
      @foreach($d['waiting']->sortBy('awaiting_since')->take(10) as $x)
        <tr class="{{ $x->isOverdue($sla) ? 'row-red' : '' }}"><td class="nowrap"><b>{{ $x->waitingMinutes() }} min</b></td>
          <td><a href="{{ route('inbox.index', ['c' => $x->id]) }}"><b>{{ $x->displayName() }}</b></a><div class="tiny muted">{{ \Illuminate\Support\Str::limit($x->lastMessage?->body, 70) }}</div></td>
          <td class="small">{{ $x->channelLabel() }}</td><td class="small">{{ $x->departmentLabel() }}</td><td class="small">{{ $x->assignee?->name ?? '—' }}</td></tr>
      @endforeach
      </tbody></table>@endif
    </div></div>
    <div class="card"><div class="card-head"><h3>Guest messages by hour – last 7 days</h3></div><div class="card-body">
      @php $mx = max(1, max($d['hours'])); @endphp
      <div class="bar-chart" id="inbHours" style="height:130px">@foreach($d['hours'] as $h => $n)<div class="col"><span class="tiny muted">{{ $n ?: '' }}</span><div class="b" style="height:{{ round($n / $mx * 72) }}%" data-v="{{ $n }} messages {{ sprintf('%02d', $h) }}:00"></div><small>{{ sprintf('%02d', $h) }}</small></div>@endforeach</div>
    </div></div>
    <div class="card" id="inbAgents"><div class="card-head"><h3>Team response times – last 7 days</h3></div><div class="card-body table-wrap">
      @if($d['agents']->isEmpty())<p class="muted m-0">No staff replies yet.</p>@else
      <table class="table table-compact"><thead><tr><th>Staff member</th><th class="num">Replies</th><th class="num">Average response</th><th class="num">Within target</th></tr></thead><tbody>
      @foreach($d['agents'] as $a)<tr><td>{{ $a['user']?->name ?? 'Automatic' }}</td><td class="num">{{ $a['replies'] }}</td><td class="num">{{ $IS::fmtSecs($a['avg']) }}</td><td class="num"><span class="badge badge-{{ $a['within'] >= 80 ? 'green' : ($a['within'] >= 50 ? 'amber' : 'red') }}">{{ $a['within'] }}%</span></td></tr>@endforeach
      </tbody></table>@endif
    </div></div>
  </div>
  <div>
    <div class="card"><div class="card-head"><h3>Guest messages by channel</h3><span class="tiny muted">7 days</span></div><div class="card-body" id="inbChannels">
      @php $top = max(1, $d['channels']->max()); @endphp
      @foreach($d['channels'] as $k => $n)<div class="ksk-fun inb-fun"><span class="small">{{ $C::CHANNELS[$k] }}</span><div class="ksk-fun-bar"><i style="width:{{ round($n / $top * 100) }}%"></i></div><b class="small">{{ $n }}</b></div>@endforeach
    </div></div>
    <div class="card"><div class="card-head"><h3>Open by team</h3></div><div class="card-body">
      @forelse($d['departments'] as $k => $n)<div class="inb-kv"><a href="{{ route('inbox.index', ['department' => $k]) }}">{{ $C::DEPARTMENTS[$k] ?? $k }}</a><b>{{ $n }}</b></div>@empty<p class="muted small m-0">No open conversations.</p>@endforelse
    </div></div>
    <div class="card"><div class="card-head"><h3>Busiest routing rules</h3>@if($IS::canManage(auth()->user()))<a class="small" href="{{ route('inbox.rules') }}">rules</a>@endif</div><div class="card-body small">
      @forelse($d['rules'] as $r)<div class="inb-kv"><span>{{ $r->name }}</span><b>{{ $r->hits }}</b></div>@empty<p class="muted m-0">No rule has matched yet.</p>@endforelse
    </div></div>
    <div class="card"><div class="card-head"><h3>Recent broadcasts</h3><a class="small" href="{{ route('inbox.broadcasts') }}">all</a></div><div class="card-body small">
      @forelse($d['broadcasts'] as $b)<div class="inb-kv"><a href="{{ route('inbox.broadcast', $b) }}">{{ $b->number }} · {{ $b->title }}</a><span class="muted">{{ $b->sent }} sent</span></div>@empty<p class="muted m-0">No broadcasts yet.</p>@endforelse
    </div></div>
  </div>
</div>
@endsection
