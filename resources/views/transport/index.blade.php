@extends('layouts.app')
@section('title', 'Dispatch Board')
@section('crumbs') Transport / Dispatch board @endsection
@section('actions')
  <a class="btn" href="{{ route('tr.trips') }}"><x-icon name="menu"/> Trips</a>
  <a class="btn" href="{{ route('tr.fleet') }}"><x-icon name="car"/> Fleet</a>
  @if(can_do('transport.book'))<a class="btn btn-primary" href="{{ route('tr.create', ['date' => $date->toDateString()]) }}" id="newTripBtn"><x-icon name="plus"/> New trip</a>@endif
@endsection
@section('content')
@php
  $isToday = $date->isSameDay(now());
  $hours = $to - $from;
  $base = $date->copy()->setTime($from, 0);
  $pct = fn ($min) => round(max(0, min(100, $min / ($hours * 60) * 100)), 3);
@endphp
@include('transport._clash')
<form class="filters" id="dayNav">
  <a class="btn btn-sm" href="{{ route('tr.index', ['date' => $date->copy()->subDay()->toDateString()]) }}" title="Previous day">‹</a>
  <input type="date" name="date" value="{{ $date->toDateString() }}" onchange="this.form.submit()" id="dayPick">
  <a class="btn btn-sm" href="{{ route('tr.index', ['date' => $date->copy()->addDay()->toDateString()]) }}" title="Next day">›</a>
  @unless($isToday)<a class="btn btn-sm" href="{{ route('tr.index') }}">Today</a>@endunless
  <span class="bold ml-1">{{ $date->format('l, d M Y') }}</span>
</form>

@php $al = $alerts; @endphp
@if($al['requested'] || $al['unassigned'] || $al['overdue'] || $al['guest_requests'] || $al['papers_expired'] || $al['papers_due'] || $al['licences'])
<div class="alert alert-warn" id="trAlerts"><x-icon name="alert"/><div class="flex" style="gap:.9rem;flex-wrap:wrap">
  @if($al['requested'])<a href="{{ route('tr.trips', ['tab' => 'requested']) }}" id="alRequested"><b>{{ $al['requested'] }}</b> guest request(s) to confirm</a>@endif
  @if($al['unassigned'])<span id="alUnassigned" class="text-red"><b>{{ $al['unassigned'] }}</b> trip(s) leaving within {{ $cfg['alert_hours'] }} h without a car</span>@endif
  @if($al['overdue'])<a href="{{ route('tr.trips', ['tab' => 'open']) }}" class="text-red" id="alOverdue"><b>{{ $al['overdue'] }}</b> trip(s) overdue – not started / not closed</a>@endif
  @if($al['guest_requests'])<a href="#guestReq"><b>{{ $al['guest_requests'] }}</b> transport request(s) from guests</a>@endif
  @if($al['papers_expired'])<a href="{{ route('tr.fleet') }}" class="text-red" id="alPapers"><b>{{ $al['papers_expired'] }}</b> vehicle(s) with expired papers</a>@endif
  @if($al['papers_due'])<a href="{{ route('tr.fleet') }}"><b>{{ $al['papers_due'] }}</b> vehicle(s) with papers due in {{ $cfg['paper_days'] }} days</a>@endif
  @if($al['licences'])<a href="{{ route('tr.fleet') }}#drivers"><b>{{ $al['licences'] }}</b> driving licence(s) expiring / expired</a>@endif
</div></div>
@endif

<div class="stats">
  <x-stat label="Trips" :value="$stats['trips']" icon="car" tone="blue" :sub="$stats['pax'].' passengers · '.$stats['vehicles_used'].' vehicles'"/>
  <x-stat label="Pick-ups / drops / local" :value="$stats['pickups'].' / '.$stats['drops'].' / '.$stats['transfers']" icon="plane" tone="purple"/>
  <x-stat label="Without a car" :value="$stats['unassigned']" icon="alert" :tone="$stats['unassigned'] ? 'red' : 'green'" :sub="$stats['on_road'].' on the road now'"/>
  <x-stat label="Completed" :value="$stats['done']" icon="check" tone="green" :sub="money($stats['revenue']).' chargeable'.($stats['lost'] ? ' · '.$stats['lost'].' cancelled / no-show' : '')"/>
</div>

<div class="grid g-2-1">
<div>
<div class="card"><div class="card-head"><h3>Vehicles · {{ $date->format('d M') }}</h3>
  <div class="legend"><span><i style="background:#fef3c7;border:1px solid #f59e0b"></i>No car yet</span><span><i style="background:#ede9fe;border:1px solid #7c3aed"></i>Assigned</span><span><i style="background:#dcfce7;border:1px solid #16a34a"></i>On the road</span><span><i style="background:#f1f5f9;border:1px solid #94a3b8"></i>Done</span>@if($isToday)<span><i style="background:#dc2626;width:3px"></i>Now</span>@endif</div></div>
  <div class="card-body">
  @if($vehicles->isEmpty())
    <x-empty text="No vehicles yet – add the fleet under Transport › Vehicles." icon="car"/>
  @else
  <div class="tb-tl" id="trBoard" style="--hours: {{ $hours }}"><div class="tb-tl-inner">
    <div class="tb-tl-row tb-tl-head"><div class="tb-tl-label">Vehicle</div><div class="tb-tl-track">@for($h = $from; $h < $to; $h++)<span>{{ sprintf('%02d:00', $h % 24) }}</span>@endfor</div></div>
    @foreach($vehicles as $v)
      @php $off = $v->status === 'maintenance' || count($v->expiredPapers($date)); @endphp
      <div class="tb-tl-row {{ $off ? 'tr-row-off' : '' }}" data-vehicle="{{ $v->code }}"><div class="tb-tl-label" title="{{ $v->name }} · {{ $v->reg_no }}">{{ $v->code }}<small>{{ $v->seats }} seats</small>
          @if($v->status === 'maintenance')<span class="badge badge-slate">workshop</span>@elseif(count($v->expiredPapers($date)))<span class="badge badge-red" title="{{ implode(', ', array_keys($v->expiredPapers($date))) }} expired">papers</span>@endif</div>
        <div class="tb-tl-track">
          @if($isToday)<div class="tb-now" style="left:{{ $pct($base->diffInMinutes(now(), false)) }}%"></div>@endif
          @foreach($trips->where('vehicle_id', $v->id) as $t)
            @php $s = $base->diffInMinutes($t->busy_from, false); $e = $base->diffInMinutes($t->status === 'completed' && $t->completed_at ? $t->completed_at->max($t->busy_from->copy()->addMinutes(20)) : $t->busy_to, false); $k = $base->diffInMinutes($t->scheduled_at, false); @endphp
            <a class="tr-blk {{ $t->status }}" href="{{ route('tr.show', $t) }}" data-trip="{{ $t->trip_no }}" style="left:{{ $pct($s) }}%;width:calc({{ max(1.2, $pct($e) - $pct($s)) }}% - 2px)"
               title="{{ $t->trip_no }} · {{ $t->typeLabel() }} {{ $t->scheduled_at->format('H:i') }}{{ $t->flight_no ? ' · '.$t->flight_no : '' }} · {{ $t->passenger_name }} ({{ $t->pax }}) · {{ $t->route() }} · {{ $t->driver?->name }} · {{ $t->statusLabel() }}">
              @if($t->type === 'pickup' && $e > $s)<span class="tick" style="left:{{ round(($k - $s) / max(1, $e - $s) * 100, 2) }}%"></span>@endif
              {{ $t->scheduled_at->format('H:i') }} <b>{{ $t->type === 'pickup' ? '↓' : ($t->type === 'drop' ? '↑' : '↔') }} {{ $t->passenger_name }}</b> · {{ $t->pax }}@if($t->flight_no) · {{ $t->flight_no }}@endif · {{ $t->driver?->name }}</a>
          @endforeach
        </div></div>
    @endforeach
  </div></div>
  <p class="tiny muted mt-1">Bars show when the car is busy (pick-ups leave {{ $cfg['pickup_lead'] }} min before the flight lands – the thin line marks the landing time). ↓ pick-up · ↑ drop · ↔ local.</p>
  @endif
  </div></div>

<div class="card flush"><div class="card-head"><h3>All trips on {{ $date->format('d M') }}</h3></div>
  <div class="card-body table-wrap"><table class="table" id="dayTrips"><thead><tr><th>Time</th><th>Trip</th><th>Guest</th><th>Route</th><th>Car · driver</th><th>Status</th></tr></thead><tbody>
  @forelse($trips->sortBy('scheduled_at') as $t)
    <tr class="{{ in_array($t->status, ['no_show']) ? 'muted-row' : '' }}"><td class="nowrap"><b>{{ $t->scheduled_at->format('H:i') }}</b><div class="tiny muted">{{ $t->typeLabel() }}</div></td>
      <td class="nowrap"><a href="{{ route('tr.show', $t) }}">{{ $t->trip_no }}</a>@if($t->flight_no)<div class="tiny muted"><x-icon name="plane" size="sm"/> {{ $t->flight_no }}</div>@endif</td>
      <td>{{ $t->passenger_name }} <span class="muted">({{ $t->pax }})</span>@if($t->stay?->room) <span class="badge badge-purple">Rm {{ $t->stay->room->room_no }}</span>@endif</td>
      <td class="small">{{ $t->route() }}</td>
      <td class="small">{{ $t->vehicle?->code ?? '—' }} · {{ $t->driver?->name ?? '—' }}</td>
      <td>{!! tr_badge($t->status) !!}</td></tr>
  @empty<tr><td colspan="6"><x-empty text="No trips on this day." icon="car"/></td></tr>@endforelse
  </tbody></table></div></div>
</div>

<div>
  <div class="card"><div class="card-head"><h3>Needs a car</h3><span class="badge {{ $unassigned->count() ? 'badge-red' : 'badge-green' }}">{{ $unassigned->count() }}</span></div><div class="card-body">
    <div class="tr-queue" id="trQueue">
    @forelse($unassigned as $t)
      <div class="tr-q {{ $t->status }}" data-trip="{{ $t->trip_no }}">
        <div class="t">{{ $t->scheduled_at->format('H:i') }} · {{ $t->typeLabel() }} · {{ $t->passenger_name }} <span class="muted">({{ $t->pax }}{{ $t->bags ? ', '.$t->bags.' bags' : '' }})</span></div>
        <div class="m">{{ $t->route() }}@if($t->flight_no) · <x-icon name="plane" size="sm"/> {{ $t->flight_no }}@endif @if($t->vehicle_type)· wants {{ \App\Models\Vehicle::TYPES[$t->vehicle_type] ?? $t->vehicle_type }}@endif</div>
        <div class="m">{!! tr_badge($t->status) !!} <a href="{{ route('tr.show', $t) }}">{{ $t->trip_no }}</a> · leaves {{ $t->busy_from->format('H:i') }}</div>
        @if(can_do('transport.book'))<div class="acts">
          @if($t->status === 'requested')<form method="post" action="{{ route('tr.confirm', $t) }}" class="inline">@csrf<button class="btn btn-sm">Confirm</button></form>@endif
          <button class="btn btn-sm btn-primary assign-btn" data-assign="{{ route('tr.options', $t) }}">Assign car</button>
        </div>@endif
      </div>
    @empty
      <x-empty text="Every trip on this day has a car." icon="check"/>
    @endforelse
    </div>
  </div></div>

  <div class="card" id="guestReq"><div class="card-head"><h3>Guest transport requests</h3></div><div class="card-body">
    @forelse($requests as $r)
      <div class="tr-q" data-request="{{ $r->request_no }}">
        <div class="t">{{ $r->item }} · Room {{ $r->room?->room_no }}</div>
        <div class="m">{{ $r->stay?->reservation?->guest?->full_name }} · {{ $r->preferred_at ? 'for '.$r->preferred_at->format('D d M H:i') : 'no time given' }}@if($r->notes) · {{ \Illuminate\Support\Str::limit($r->notes, 60) }}@endif</div>
        @if(can_do('transport.book'))<div class="acts"><a class="btn btn-sm btn-primary" href="{{ route('tr.create', ['request' => $r->id]) }}">Book a car</a></div>@endif
      </div>
    @empty<p class="small muted m-0">No open requests from the guest portal or the front desk.</p>@endforelse
  </div></div>

  <div class="card"><div class="card-head"><h3>Drivers today</h3></div><div class="card-body"><ul class="list" id="driverLoad">
    @foreach($drivers as $d)
      @php $mine = $trips->where('driver_id', $d->id)->whereNotIn('status', ['no_show']); $live = $mine->first(fn ($t) => in_array($t->status, ['en_route', 'onboard'])); @endphp
      <li><span>{{ $d->name }} @if($d->licenceExpired($date))<span class="badge badge-red">licence</span>@endif</span>
        <span class="small">@if($live)<span class="text-green bold">on the road · {{ $live->trip_no }}</span>@else{{ $mine->count() }} trip(s)@endif</span></li>
    @endforeach
  </ul></div></div>
</div>
</div>
@include('transport._assign')
@endsection
