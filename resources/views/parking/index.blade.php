@extends('layouts.app')
@section('title', 'Parking Desk')
@section('crumbs') Parking &amp; Valet / Desk @endsection
@php $canOp = \App\Services\ParkingService::canOperate(auth()->user()); $canMg = \App\Services\ParkingService::canManage(auth()->user()); @endphp
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'parking_revenue']) }}"><x-icon name="download"/> Revenue</a>
  @if($canMg)<form method="post" action="{{ route('parking.nightly') }}" class="inline" data-confirm="Charge tonight's guest parking to the room folios now? (The night audit does this automatically.)">@csrf<button class="btn"><x-icon name="moon"/> Post tonight</button></form>@endif
  @if($canOp)<button class="btn btn-primary" data-modal="pkCheckinModal" id="pkParkBtn"><x-icon name="plus"/> Park a vehicle</button>@endif
@endsection
@section('content')
@if(session('ticket'))<div class="alert alert-info" id="pkTicketFlash">Ticket ready – <a href="{{ route('parking.ticket', session('ticket')) }}?autoprint=1" target="_blank"><b>print the ticket</b></a> (valet tickets carry a QR the guest scans to call for the car).</div>@endif
<div class="stats" id="pkStats">
  <x-stat label="Parked now" :value="$k['parked'].($k['capacity'] ? ' / '.$k['capacity'] : '')" icon="key" :tone="($k['pct'] ?? 0) >= $cfg['full_pct'] ? 'red' : 'blue'" :sub="($k['pct'] !== null ? $k['pct'].'% full · ' : '').$k['guests'].' guests · '.$k['visitors'].' visitors'"/>
  <x-stat label="With the valet" :value="$k['valet']" icon="car" tone="purple" :sub="$k['requested'].' requested · '.$k['ready'].' at the door'" :href="route('parking.valet')"/>
  <x-stat label="In / out today" :value="$k['in_today'].' / '.$k['out_today']" icon="swap" tone="slate" :sub="'arrivals / exits since midnight'"/>
  <x-stat label="Parking revenue" :value="money_short($k['revenue_today'])" icon="money" tone="green" :sub="'today · MTD '.money_short($k['revenue_mtd'])"/>
  <x-stat label="Valet retrieval" :value="$k['avg_wait'] !== null ? $k['avg_wait'].' min' : '—'" icon="clock" :tone="$k['within_sla'] !== null && $k['within_sla'] < 80 ? 'amber' : 'green'" :sub="$k['within_sla'] !== null ? $k['within_sla'].'% within '.$cfg['sla_minutes'].' min · 30 days' : 'no retrievals yet'"/>
</div>
@if($alerts['overdue'] || $alerts['overstay'] || $alerts['departed'] || $alerts['full'] || $alerts['permits_expiring'])
<div class="alert alert-warn" id="pkAlerts"><x-icon name="alert"/><div>
  @if($alerts['overdue'])<a href="{{ route('parking.valet') }}"><b>{{ $alerts['overdue'] }}</b> car(s) requested more than {{ $cfg['sla_minutes'] }} min ago.</a> @endif
  @if($alerts['departed'])<b>{{ $alerts['departed'] }}</b> guest vehicle(s) still parked after check-out. @endif
  @if($alerts['overstay'])<b>{{ $alerts['overstay'] }}</b> visitor vehicle(s) parked over {{ $cfg['overstay_hours'] }} h. @endif
  @if($alerts['full'])Nearly full: {{ implode(', ', $alerts['full']) }}. @endif
  @if($alerts['permits_expiring'])<a href="{{ route('parking.permits', ['view' => 'expiring']) }}"><b>{{ $alerts['permits_expiring'] }}</b> permit(s) expire within 7 days.</a>@endif
</div></div>
@endif
<div class="g-2-1">
  <div>
    <div class="card"><div class="card-body"><form method="get" class="flex gap-1 wrap" style="align-items:center" id="pkLookupForm">
      <input name="plate" value="{{ request('plate') }}" placeholder="Gate check: number plate" style="text-transform:uppercase;flex:1 1 14rem;min-width:12rem;max-width:22rem;font-size:1rem;padding:.5rem .7rem" aria-label="Plate lookup">
      <button class="btn btn-sm"><x-icon name="search"/> Look up</button>
      <span class="tiny muted">Checks the car park, permits, bans and the visit history.</span>
    </form>
    @if($look)
      <div class="mt-1" id="pkLookup">
      @if($look['vehicle'])
        @php $v = $look['vehicle']; @endphp
        <div class="kv-grid">
          <div><span>Vehicle</span><b>{{ $v->plate_display }}</b> {{ $v->description() }}@if($v->is_banned) <span class="badge badge-red">BANNED</span>@endif</div>
          <div><span>Owner</span>{{ $v->guest?->full_name ?? $v->owner_name ?? '—' }}</div>
          <div><span>In the car park</span>@if($look['active'])<a href="{{ route('parking.session', $look['active']) }}">{{ $look['active']->number }}</a> · {{ $look['active']->zone->name }} · {{ $look['active']->statusLabel() }}@else<span class="muted">no</span>@endif</div>
          <div><span>Permit</span>@if($look['permit'])<span class="badge badge-green">{{ $look['permit']->number }}</span> to {{ $look['permit']->valid_to->format('d M Y') }}@else<span class="muted">none valid</span>@endif</div>
          <div><span>Visits</span>{{ $look['visits'] }}{{ $look['last'] ? ' · last '.$look['last']->exited_at?->format('d M Y') : '' }}</div>
        </div>
        @if($v->is_banned)<div class="alert alert-error mt-1">Do not admit: {{ $v->ban_reason }}</div>@endif
      @else
        <div class="muted small">No vehicle {{ strtoupper($look['query']) }} on record – a first visit.</div>
      @endif
      </div>
    @endif
    </div></div>
    @if($departed->count())
    <div class="card mt-1"><div class="card-head"><h3>Checked out – car still here</h3></div><div class="card-body"><ul class="small" id="pkDeparted">
      @foreach($departed as $d)<li><a href="{{ route('parking.session', $d) }}">{{ $d->number }}</a> {{ $d->plate }} · Rm {{ $d->stay?->room?->room_no }} · {{ $d->guest_name }}</li>@endforeach
    </ul><p class="tiny muted">Unposted nights are charged at the exit and paid there.</p></div></div>
    @endif
  </div>
  <div>
    <div class="card"><div class="card-head"><h3>Occupancy</h3><a class="small" href="{{ route('parking.zones') }}">zones</a></div><div class="card-body" id="pkOccupancy">
      @foreach($occ as $o)
        <div class="mb-1"><div class="flex" style="justify-content:space-between"><span class="small"><b>{{ $o['zone']->code }}</b> {{ $o['zone']->name }}</span><span class="small {{ $o['state'] === 'full' ? 'text-red' : '' }}">{{ $o['parked'] }}{{ $o['capacity'] ? ' / '.$o['capacity'] : '' }}</span></div>
          @if($o['capacity'])<div class="dm-bar {{ in_array($o['state'], ['full', 'nearly']) ? 'over' : '' }}"><span style="width:{{ min(100, $o['pct']) }}%"></span></div>@endif
          <div class="tiny muted">{{ $o['zone']->typeLabel() }}{{ $o['valet'] ? ' · '.$o['valet'].' valet' : '' }}{{ $o['capacity'] ? ' · '.$o['free'].' free' : '' }}</div></div>
      @endforeach
    </div></div>
  </div>
</div>
    <div class="card flush mt-1"><div class="card-head"><h3>In the car park</h3>
      <form method="get" class="flex gap-1" style="align-items:center"><input name="q" value="{{ request('q') }}" placeholder="ticket, plate, name, key tag" style="max-width:12rem" aria-label="Search">
        <select name="zone" aria-label="Zone"><option value="">All zones</option>@foreach($zones as $z)<option value="{{ $z->id }}" @selected((int) request('zone') === $z->id)>{{ $z->code }}</option>@endforeach</select>
        <select name="kind" aria-label="Kind"><option value="">Everyone</option>@foreach(\App\Models\ParkingSession::KINDS as $kk => $kl)<option value="{{ $kk }}" @selected(request('kind') === $kk)>{{ $kl }}</option>@endforeach</select><button class="btn btn-sm">Filter</button></form></div>
    <div class="card-body table-wrap"><table class="table table-compact" id="pkActiveTable"><thead><tr><th>Ticket</th><th>Vehicle</th><th>Who</th><th>Zone / bay</th><th>In</th><th class="num">Due now</th><th>Status</th><th></th></tr></thead><tbody>
      @forelse($active as $s)
        @php $q = $quotes[$s->id]; $isGone = $s->kind === 'guest' && $s->stay && $s->stay->status !== 'checked_in' && $s->stay->status !== 'reserved'; @endphp
        <tr class="{{ $s->status === 'requested' ? 'row-amber' : ($isGone ? 'row-red' : '') }}" data-ticket="{{ $s->number }}">
          <td><a href="{{ route('parking.session', $s) }}"><b>{{ $s->number }}</b></a>@if($s->key_tag)<div class="tiny muted">key {{ $s->key_tag }}</div>@endif</td>
          <td><b class="mono">{{ $s->plate }}</b><div class="tiny muted">{{ $s->vehicle?->description() }}</div></td>
          <td class="small">{{ $s->kindLabel() }}<div class="tiny muted">{{ $s->stay?->room ? 'Rm '.$s->stay->room->room_no.' · ' : '' }}{{ $s->guest_name }}</div>@if($isGone)<span class="badge badge-red">checked out</span>@endif</td>
          <td class="small">{{ $s->zone->code }}{{ $s->bay ? ' · '.$s->bay : '' }}</td>
          <td class="small nowrap">{{ $s->entered_at->format('d M H:i') }}<div class="tiny muted">{{ $s->durationLabel() }}</div></td>
          <td class="num">{{ $q['total'] > 0 ? money($q['total']) : '—' }}@if($s->validation_hours)<div class="tiny muted">{{ $s->validation_hours }} h validated</div>@endif</td>
          <td><span class="badge badge-{{ $s->statusTone() }}">{{ $s->statusLabel() }}</span></td>
          <td class="nowrap">@if($canOp)
            @if($s->is_valet && $s->status === 'parked')<form method="post" action="{{ route('parking.request', $s) }}" class="inline">@csrf<button class="btn btn-sm">Request</button></form>@endif
            @if($s->is_valet && $s->status === 'requested')<form method="post" action="{{ route('parking.ready', $s) }}" class="inline">@csrf<button class="btn btn-sm">At door</button></form>@endif
            <a class="btn btn-sm btn-primary" href="{{ route('parking.session', $s) }}#exit">Exit</a>
          @endif</td>
        </tr>
      @empty<tr><td colspan="8" class="muted">No vehicles in the car park{{ request()->hasAny(['q', 'zone', 'kind']) ? ' matching the filter' : '' }}.</td></tr>@endforelse
    </tbody></table></div></div>
    <div class="card mt-1"><div class="card-head"><h3>Revenue – 14 days</h3></div><div class="card-body"><div class="bar-chart pk-trend" id="pkTrend">
      @php $mx = max(1, collect($trend)->max('total')); @endphp
      @foreach($trend as $t)<div class="col"><small>{{ $t['total'] > 0 ? number_format($t['total']) : '' }}</small><div class="b" style="height:{{ $t['total'] > 0 ? max(2, $t['total'] / $mx * 100) : 0 }}%" data-v="nights {{ money($t['night']) }} · exit {{ money($t['exit']) }}"></div><small>{{ $t['date']->format('D d') }}</small></div>@endforeach
    </div><p class="tiny muted mt-1">Guest nights posted to rooms plus exit fees (visitor tariff, valet, lost tickets), net of tax.</p></div></div>
@if($canOp)@include('parking._checkin_modal')@endif
@endsection
