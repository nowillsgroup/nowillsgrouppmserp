@extends('layouts.app')
@section('title', 'Reservation Workspace')

@section('actions')
<a class="btn" href="{{ route('reservations.daysummary') }}"><x-icon name="chart"/> Reports</a>
<a class="btn btn-primary" href="{{ route('frontdesk.walkin') }}" style="background:#16a34a"><x-icon name="login"/> Walk-In</a>
<a class="btn" href="{{ route('blocks.create') }}" style="background:#2563eb;color:#fff"><x-icon name="users"/> Group</a>
<a class="btn" href="{{ route('reservations.create') }}" style="background:#7c3aed;color:#fff"><x-icon name="building"/> Corporate</a>
<a class="btn" href="{{ url('/channel-manager') }}" style="background:#d97706;color:#fff"><x-icon name="globe"/> OTA</a>
@endsection

<div class="ws-head">
  <div>
    <h1 class="ws-title">Reservation Workspace</h1>
    <div class="muted">{{ $date->format('l, j F Y') }}</div>
  </div>
  <div class="flex gap">
    <span class="badge badge-green" id="wsClock"><x-icon name="refresh"/> Live-synced {{ now()->format('H:i:s') }}</span>
  </div>
</div>

{{-- ── KPI strip ── --}}
@php
  use App\Services\ReservationWorkspaceService as WS;
  $tile = [
    'occupancy' => ['Occupancy', fn ($v) => $v.'%'],
    'revenue' => ['Revenue today', fn ($v) => 'GH₵ '.number_format($v)],
    'available' => ['Available', fn ($v) => $v],
    'occupied' => ['Occupied', fn ($v) => $v],
    'reserved' => ['Reserved', fn ($v) => $v],
    'arrivals' => ['Arrivals today', fn ($v) => $v],
    'departures' => ['Departures', fn ($v) => $v],
    'inhouse' => ['In-house guests', fn ($v) => $v],
    'vips' => ['VIP guests', fn ($v) => $v],
  ];
  $kpis = [];
  foreach ($tile as $key => [$label, $fmt]) {
      $delta = $deltas[$key] ?? null;
      $tone = $delta === null || $delta == 0 ? 'green' : ($delta > 0 ? 'green' : 'red');
      $kpis[] = [
          'label' => $label,
          'value' => $fmt($trend[$key][count($trend[$key]) - 1]),
          'delta' => $delta === null ? '—' : ($delta > 0 ? '+' : '').$delta.'%',
          'tone' => $delta !== null && $delta == 0 ? 'green' : $tone,
          'series' => $trend[$key],
      ];
  }
@endphp
<div class="ws-kpis">
  @foreach ($kpis as $i => $k)
  <div class="ws-kpi">
    <div class="flex gap">
      <span class="ws-kpi-delta {{ $k['tone'] === 'red' ? 'down' : 'up' }}">{{ $k['delta'] }}</span>
    </div>
    {!! WS::spark($k['series'], $k['tone']) !!}
    <div class="ws-kpi-value">{{ $k['value'] }}</div>
    <div class="ws-kpi-label">{{ $k['label'] }}</div>
  </div>
  @endforeach
</div>

{{-- ── Filters ── --}}
<div class="ws-filters">
  <span class="muted small">FILTERS</span>
  <select id="fStatus" class="ws-select"><option value="">Room Status: All</option>
    @foreach (['available' => 'Available', 'reserved' => 'Reserved', 'occupied' => 'Occupied', 'out_of_order' => 'Out of Order', 'maintenance' => 'Maintenance', 'house_use' => 'House Use'] as $k => $l)
    <option value="{{ $k }}">Room Status: {{ $l }}</option>@endforeach
  </select>
  <select id="fType" class="ws-select"><option value="">Room Type: All</option>
    @foreach ($roomTypes as $rt)<option value="{{ $rt->id }}">Room Type: {{ $rt->name }}</option>@endforeach
  </select>
  <select id="fFloor" class="ws-select"><option value="">Floor: All</option>
    @foreach ($floors as $f)<option value="{{ $f->id }}">Floor: {{ $f->name }}</option>@endforeach
  </select>
  <select id="fSource" class="ws-select"><option value="">Source: All</option>
    @foreach ($cards->pluck('stay.reservation.businessSource.name')->filter()->unique()->sort()->values() as $src)
    <option value="{{ $src }}">Source: {{ $src }}</option>@endforeach
  </select>
</div>

<div class="ws-body">
  {{-- ── Room board ── --}}
  <div class="ws-grid" id="wsGrid">
    @foreach ($cards as $c)
      @php $room = $c['room']; $stay = $c['stay']; $src = $stay?->reservation?->businessSource?->name ?? ''; @endphp
      <div class="ws-card" data-status="{{ $c['status'] }}" data-type="{{ $room->room_type_id }}" data-floor="{{ $room->floor_id }}" data-source="{{ $src }}">
        <div class="ws-card-head">
          <div class="flex gap">
            <span class="ws-room-no">{{ $room->room_no }}</span>
            <div>
              <div class="bold small">{{ $room->roomType->name ?? '—' }}</div>
              <div class="muted small">{{ $room->floor?->name ? 'F'.$room->floor->name : '' }}{{ $room->roomType->code ? ' · '.$room->roomType->code : '' }}</div>
            </div>
          </div>
          <div class="text-right">
            <span class="badge badge-{{ ['available' => 'green', 'reserved' => 'amber', 'occupied' => 'blue', 'out_of_order' => 'red', 'maintenance' => 'amber', 'house_use' => 'slate'][$c['status']] }}">{{ strtoupper(str_replace('_', ' ', $c['status'])) }}</span>
            @if ($c['arriving_today'])<div class="ws-tag">ARRIVING TODAY</div>@endif
          </div>
        </div>

        <div class="ws-photo" style="background:linear-gradient(135deg, hsl({{ ($room->room_type_id * 47) % 360 }},32%,28%), hsl({{ ($room->room_type_id * 47 + 40) % 360 }},28%,42%)){{ $room->roomType->image ? ' ,url('.asset($room->roomType->image).') center/cover no-repeat' : '' }}">
          <span class="ws-photo-tag">{{ strtoupper($room->roomType->code ?? $room->roomType->name ?? 'ROOM') }}</span>
          <span class="ws-photo-rate">GH₵{{ number_format($c['rate']) }} <small>/ night</small></span>
        </div>
        <div class="ws-room-meta small muted">
          <span><x-icon name="home" /> {{ $room->roomType->size_sqm ? rtrim(rtrim(number_format($room->roomType->size_sqm, 1), '0'), '.').' m²' : ($room->roomType->code ?? '—') }}</span>
          <span><x-icon name="user"/> {{ $room->roomType->max_adults ?? 2 }}</span>
          <span><x-icon name="shield"/> {{ $room->is_smoking ? 'Smoking' : 'Non-smoking' }}</span>
        </div>
        <div class="ws-amen">
          @foreach (array_slice($c['amenities'], 0, 10) as $a)
            <span title="{{ $a }}"><x-icon name="{{ WS::amenityIcon($a) }}"/></span>
          @endforeach
        </div>

        @if ($stay)
        <div class="ws-guest {{ $c['status'] === 'reserved' ? 'arriving' : ($c['status'] === 'occupied' ? 'inhouse' : '') }}">
          <div class="flex gap">
            <span class="ws-avatar">{{ mb_substr($stay->guest?->first_name ?? $stay->reservation->guest?->first_name ?? 'G', 1) === '' ? 'G' : mb_substr($stay->guest?->first_name ?? $stay->reservation->guest?->first_name ?? 'G', 0, 1) }}</span>
            <div>
              <div class="bold small">{{ $stay->guest?->first_name ?? $stay->reservation->guest?->first_name }} {{ $stay->guest?->last_name ?? $stay->reservation->guest?->last_name }}</div>
              <div class="muted small">{{ $src ?: 'Direct' }}{{ ($stay->guest?->is_vip ?? $stay->reservation->guest?->is_vip) ? ' · VIP' : '' }}{{ $stay->guest?->nationality ? ' · '.$stay->guest->nationality : '' }}</div>
            </div>
          </div>
          <div class="ws-stay small">
            <span><b>{{ \Illuminate\Support\Carbon::parse($stay->arrival_date)->format('d M') }}</b><br><span class="muted">Arrive</span></span>
            <span class="ws-nights">{{ max(1, \Illuminate\Support\Carbon::parse($stay->arrival_date)->diffInDays(\Illuminate\Support\Carbon::parse($stay->departure_date))) }}n</span>
            <span><b>{{ \Illuminate\Support\Carbon::parse($stay->departure_date)->format('d M') }}</b><br><span class="muted">Depart</span></span>
          </div>
          <div class="ws-rate-row small">
            <div><span class="muted">Best available rate</span><br><span class="bold">GH₵{{ number_format($c['rate']) }}</span> <span class="muted">/n</span></div>
            @if ($c['balance'] !== null)
            <div class="text-right {{ $c['balance'] > 0 ? 'ws-due' : 'muted' }}">{{ $c['balance'] > 0 ? 'GH₵ '.number_format($c['balance'], 0).' due' : 'Settled' }}</div>
            @endif
          </div>
        </div>
        @else
        <div class="ws-guest vacant">
          <div class="muted small">VACANT · RACK RATE</div>
          <div class="ws-vacant-rate">GH₵ {{ number_format($c['rate']) }} <small class="muted">/night</small></div>
          <a class="btn btn-sm btn-primary ws-reserve" href="{{ route('reservations.create', ['room' => $room->id]) }}">Reserve Room</a>
        </div>
        @endif

        <div class="ws-actions">
          @if ($stay && $stay->status === 'reserved')
            <a class="btn btn-sm btn-primary" style="background:#16a34a" href="{{ route('frontdesk.checkin', $stay) }}"><x-icon name="login"/> Check-In</a>
            <a class="btn btn-sm" href="{{ route('frontdesk.amend', $stay) }}"><x-icon name="clock"/> Hold</a>
          @elseif ($stay && $stay->status === 'checked_in')
            <a class="btn btn-sm" style="background:#ea580c;color:#fff" href="{{ route('frontdesk.checkout', $stay) }}"><x-icon name="logout"/> Check-Out</a>
            <a class="btn btn-sm" href="{{ route('frontdesk.amend', $stay) }}"><x-icon name="calendar"/> Extend</a>
          @else
            <a class="btn btn-sm" href="{{ route('blocks.index') }}"><x-icon name="lock"/> Block</a>
          @endif
          <button class="btn btn-sm btn-ghost ws-all" data-room="{{ $room->id }}">··· All actions</button>
        </div>
      </div>
    @endforeach
  </div>

  {{-- ── Right rail ── --}}
  <div class="ws-rail">
    <div class="card ws-rail-card">
      <div class="card-body">
        @php $m = $date->copy()->startOfMonth(); $days = $m->daysInMonth; $pad = ((int) $m->format('w') + 6) % 7; @endphp
        <div class="bold small" style="text-transform:uppercase">{{ $m->format('F Y') }}</div>
        <div class="ws-cal">
          @foreach (['M','T','W','T','F','S','S'] as $d)<span class="ws-cal-d muted">{{ $d }}</span>@endforeach
          @for ($i = 0; $i < $pad; $i++)<span></span>@endfor
          @for ($day = 1; $day <= $days; $day++)
            @php $ds = $m->copy()->day($day)->toDateString();
              $isToday = $ds === $date->toDateString();
              $hasA = $arrivals->contains(fn ($s) => \Illuminate\Support\Carbon::parse($s->arrival_date)->toDateString() === $ds) || collect($cards)->pluck('stay')->filter()->contains(fn ($s) => \Illuminate\Support\Carbon::parse($s->arrival_date)->toDateString() === $ds);
              $hasD = $departures->contains(fn ($s) => \Illuminate\Support\Carbon::parse($s->departure_date)->toDateString() === $ds);
            @endphp
            <span class="ws-cal-day {{ $isToday ? 'today' : '' }}">
              {{ $day }}
              <span class="ws-cal-dots">@if($hasA)<i class="dot-a"></i>@endif @if($hasD)<i class="dot-d"></i>@endif</span>
            </span>
          @endfor
        </div>
        <div class="ws-cal-legend small muted"><span class="ws-legend"><i class="dot-a"></i> Arrivals</span> <span class="ws-legend"><i class="dot-d"></i> Departures</span></div>
      </div>
    </div>

    <div class="card ws-rail-card"><div class="card-body">
      <div class="ws-rail-title">ROOM STATUS SUMMARY</div>
      <div class="ws-stack">
        @php $sum = collect($statusSummary)->sum(fn ($v) => max(1, $v[0])); @endphp
        @foreach ($statusSummary as $label => [$n, $color])
          <span style="flex:{{ max($n, 0.35) }};background:{{ $color }}" title="{{ $label }} {{ $n }}"></span>
        @endforeach
      </div>
      <table class="ws-rail-list">
        @foreach ($statusSummary as $label => [$n, $color])
        <tr><td><span class="dot" style="background:{{ $color }}"></span> {{ $label }}</td><td class="text-right bold">{{ $n }}</td></tr>
        @endforeach
      </table>
    </div></div>

    <div class="card ws-rail-card"><div class="card-body">
      <div class="ws-rail-title"><x-icon name="login"/> TODAY'S ARRIVALS · {{ $arrivals->count() }}</div>
      @forelse ($arrivals as $s)
      <a class="ws-person" href="{{ $s->room_id ? route('frontdesk.checkin', $s) : route('reservations.index') }}">
        <span class="ws-avatar sm">{{ mb_substr($s->guest?->first_name ?? $s->reservation->guest?->first_name ?? 'G', 0, 1) }}</span>
        <span><b>{{ $s->guest?->first_name ?? $s->reservation->guest?->first_name }} {{ $s->guest?->last_name ?? $s->reservation->guest?->last_name }}</b>
          <span class="muted small">Rm {{ $s->room?->room_no ?? '—' }} · {{ max(1, \Illuminate\Support\Carbon::parse($s->arrival_date)->diffInDays(\Illuminate\Support\Carbon::parse($s->departure_date))) }}n · {{ $s->reservation->businessSource->name ?? 'Direct' }}</span></span>
      </a>
      @empty
      <div class="muted small">No arrivals today.</div>
      @endforelse
    </div></div>

    <div class="card ws-rail-card"><div class="card-body">
      <div class="ws-rail-title"><x-icon name="logout"/> TODAY'S DEPARTURES · {{ $departures->count() }}</div>
      @forelse ($departures as $s)
      <a class="ws-person" href="{{ route('frontdesk.checkout', $s) }}">
        <span class="ws-avatar sm">{{ mb_substr($s->guest?->first_name ?? $s->reservation->guest?->first_name ?? 'G', 0, 1) }}</span>
        <span><b>{{ $s->guest?->first_name ?? $s->reservation->guest?->first_name }} {{ $s->guest?->last_name ?? $s->reservation->guest?->last_name }}</b>
          <span class="muted small">Rm {{ $s->room?->room_no ?? '—' }} · {{ $s->reservation->businessSource->name ?? 'Direct' }}</span></span>
      </a>
      @empty
      <div class="muted small">No departures today.</div>
      @endforelse
    </div></div>

    <div class="card ws-rail-card"><div class="card-body">
      <div class="ws-rail-title"><x-icon name="star"/> VIP GUESTS · {{ $vip->count() }}</div>
      @forelse ($vip as $s)
      <a class="ws-person" href="{{ route('frontdesk.inhouse') }}">
        <span class="ws-avatar sm vip">{{ mb_substr($s->guest?->first_name ?? $s->reservation->guest?->first_name ?? 'G', 0, 1) }}</span>
        <span><b>{{ $s->guest?->first_name ?? $s->reservation->guest?->first_name }} {{ $s->guest?->last_name ?? $s->reservation->guest?->last_name }}</b>
          <span class="muted small">Rm {{ $s->room?->room_no ?? '—' }}</span></span>
      </a>
      @empty
      <div class="muted small">No VIPs in-house.</div>
      @endforelse
    </div></div>

    <div class="card ws-rail-card"><div class="card-body">
      <div class="ws-rail-title"><x-icon name="clock"/> WAITING LIST · {{ $waitlist->count() }}</div>
      @forelse ($waitlist as $w)
      <div class="ws-person">
        <span class="ws-avatar sm">{{ mb_substr($w->guest?->first_name ?? $w->contact_name ?? 'G', 0, 1) }}</span>
        <span><b>{{ $w->guest?->first_name ?? $w->contact_name }}</b>
          <span class="muted small">{{ \Illuminate\Support\Carbon::parse($w->arrival_date)->format('Y-m-d') }} · {{ $w->roomType->name ?? 'Any' }}</span></span>
      </div>
      @empty
      <div class="muted small">Waiting list is empty.</div>
      @endforelse
    </div></div>

    <div class="card ws-rail-card"><div class="card-body">
      <div class="ws-rail-title"><x-icon name="broom"/> HOUSEKEEPING</div>
      <table class="ws-rail-list">
        <tr><td><span class="dot" style="background:#f59e0b"></span> Dirty</td><td class="text-right bold">{{ $hk['Dirty'] }}</td></tr>
        <tr><td><span class="dot" style="background:#10b981"></span> Clean / inspected</td><td class="text-right bold">{{ $hk['Clean'] }}</td></tr>
        <tr><td><span class="dot" style="background:#3b82f6"></span> Touch-up</td><td class="text-right bold">{{ $hk['Touch-up'] }}</td></tr>
      </table>
    </div></div>
  </div>
</div>

{{-- ── Room detail modals (screenshot 2) ── --}}
@php $allActions = [
  ['Check-In', 'login', fn ($s) => $s ? route('frontdesk.checkin', $s) : route('frontdesk.walkin')],
  ['Move Room', 'swap', fn ($s) => $s ? route('frontdesk.move', $s) : route('frontdesk.rooms')],
  ['Upgrade', 'trend', fn ($s) => $s ? route('frontdesk.amend', $s) : route('reservations.create')],
  ['Room Block', 'lock', fn ($s) => route('blocks.index')],
  ['Mark Dirty', 'broom', fn ($s) => url('/housekeeping')],
  ['Maintenance', 'tool', fn ($s) => url('/housekeeping')],
  ['Open Folio', 'wallet', fn ($s) => route('folios.index', $s ? ['stay' => $s->id] : [])],
  ['Generate Invoice', 'receipt', fn ($s) => route('folios.invoices')],
  ['Record Payment', 'money', fn ($s) => route('folios.index')],
  ['Guest Profile', 'user', fn ($s) => route('guests.index')],
  ['Room History', 'clock', fn ($s) => route('frontdesk.rooms')],
  ['Print GRC Card', 'print', fn ($s) => $s ? route('frontdesk.grc', $s) : '#'],
  ['Issue Key Card', 'key', fn ($s) => url('/interfaces')],
  ['Send SMS', 'message', fn ($s) => url('/desk/messages')],
  ['Send Email', 'mail', fn ($s) => url('/desk/messages')],
  ['WhatsApp', 'phone', fn ($s) => url('/desk/messages')],
]; @endphp

@foreach ($cards as $c)
@php $room = $c['room']; $stay = $c['stay']; $facts = [
  ['Size', $room->roomType->size_sqm ? rtrim(rtrim(number_format($room->roomType->size_sqm, 1), '0'), '.').' m²' : ($room->roomType->code ?? '—')],
  ['Max Occupancy', ($room->roomType->max_adults ?? 2).' guests'],
  ['Configuration', $room->roomType->name ?? '—'],
  ['Smoking', $room->is_smoking ? 'Smoking' : 'Non-Smoking'],
]; @endphp
<div class="modal ws-modal" id="roomModal{{ $room->id }}"><div class="modal-box wide">
  <div class="ws-modal-hero" style="background:linear-gradient(135deg, hsl({{ ($room->room_type_id * 47) % 360 }},32%,24%), hsl({{ ($room->room_type_id * 47 + 40) % 360 }},26%,38%)){{ $room->roomType->image ? ' ,url('.asset($room->roomType->image).') center/cover no-repeat' : '' }}">
    <button type="button" class="btn btn-ghost btn-icon ws-modal-x" data-close><x-icon name="x"/></button>
    <div class="ws-modal-hero-row">
      <span class="ws-room-no huge">{{ $room->room_no }}</span>
      <div>
        <div class="bold">{{ $room->roomType->name ?? '—' }} · {{ $room->roomType->code ?? '' }}</div>
        <div class="small" style="opacity:.85">{{ $room->floor?->name ? 'Floor '.$room->floor->name : '' }}{{ $room->roomType->code ? ' · '.$room->roomType->name : '' }}</div>
      </div>
      <div class="text-right">
        <span class="badge badge-{{ ['available' => 'green', 'reserved' => 'amber', 'occupied' => 'blue', 'out_of_order' => 'red', 'maintenance' => 'amber', 'house_use' => 'slate'][$c['status']] }}">{{ strtoupper(str_replace('_', ' ', $c['status'])) }}</span>
        <div class="ws-photo-rate" style="position:static;margin-top:6px">GH₵{{ number_format($c['rate']) }} <small>/ night</small></div>
      </div>
    </div>
  </div>

  <div class="modal-body">
    <div class="ws-modal-grid">
      <div>
        <div class="ws-rail-title">ROOM FACTS</div>
        <div class="ws-facts">
          @foreach ($facts as [$l, $v])<div class="ws-fact"><span class="muted small">{{ $l }}</span><br><b>{{ $v }}</b></div>@endforeach
        </div>
        <div class="ws-rail-title" style="margin-top:14px">FACILITIES & AMENITIES</div>
        <div class="ws-fac">
          @foreach (array_slice($c['amenities'], 0, 12) as $a)
          <span class="ws-fac-chip"><x-icon name="{{ WS::amenityIcon($a) }}"/> {{ $a }}</span>
          @endforeach
          @if ($room->is_smoking === false)<span class="ws-fac-chip"><x-icon name="shield"/> Non-Smoking</span>@endif
        </div>
      </div>

      @if ($stay)
      <div>
        <div class="ws-rail-title">CURRENT RESERVATION</div>
        <div class="ws-resv">
          <div class="flex gap">
            <span class="ws-avatar">{{ mb_substr($stay->guest?->first_name ?? $stay->reservation->guest?->first_name ?? 'G', 0, 1) }}</span>
            <div>
              <div class="bold">{{ $stay->guest?->first_name ?? $stay->reservation->guest?->first_name }} {{ $stay->guest?->last_name ?? $stay->reservation->guest?->last_name }}</div>
              <div class="muted small">{{ $stay->reservation?->businessSource?->name ?? 'Direct' }} · {{ $stay->guest?->nationality ?? '' }}</div>
            </div>
          </div>
          <table class="ws-resv-t">
            <tr><td class="muted">Confirmation</td><td class="bold">{{ $stay->reservation?->reservation_no ?? '—' }}</td>
                <td class="muted">Source</td><td class="bold">{{ $stay->reservation?->businessSource?->name ?? 'Direct' }}</td></tr>
            <tr><td class="muted">Stay</td><td class="bold">{{ \Illuminate\Support\Carbon::parse($stay->arrival_date)->format('d M') }} → {{ \Illuminate\Support\Carbon::parse($stay->departure_date)->format('d M') }} · {{ max(1, \Illuminate\Support\Carbon::parse($stay->arrival_date)->diffInDays(\Illuminate\Support\Carbon::parse($stay->departure_date))) }}n</td>
                <td class="muted">Guests</td><td class="bold">{{ $stay->reservation?->adults ?? 1 }} Adults · {{ $stay->reservation?->children ?? 0 }} Children</td></tr>
            <tr><td class="muted">Rate</td><td class="bold">GH₵{{ number_format($c['rate']) }}/n</td>
                <td class="muted">Balance</td><td class="bold {{ ($c['balance'] ?? 0) > 0 ? 'ws-due' : '' }}">{{ ($c['balance'] ?? 0) > 0 ? 'GH₵ '.number_format($c['balance'], 0).' due' : 'Settled' }}</td></tr>
          </table>
        </div>
      </div>
      @endif
    </div>

    <div class="ws-rail-title" style="margin-top:16px">QUICK ACTIONS</div>
    <div class="ws-quick">
      @foreach ($allActions as [$label, $icon, $fn])
        <a class="ws-quick-tile" href="{{ $fn($stay) }}"><x-icon name="{{ $icon }}"/><span>{{ $label }}</span></a>
      @endforeach
    </div>
  </div>
</div></div>
@endforeach

@push('scripts')
<script>
document.getElementById('fStatus')?.addEventListener('change', applyWsFilters);
document.getElementById('fType')?.addEventListener('change', applyWsFilters);
document.getElementById('fFloor')?.addEventListener('change', applyWsFilters);
document.getElementById('fSource')?.addEventListener('change', applyWsFilters);
function applyWsFilters() {
  const st = document.getElementById('fStatus').value, ty = document.getElementById('fType').value,
        fl = document.getElementById('fFloor').value, so = document.getElementById('fSource').value;
  document.querySelectorAll('#wsGrid .ws-card').forEach(c => {
    c.style.display = (!st || c.dataset.status === st) && (!ty || c.dataset.type === ty)
      && (!fl || c.dataset.floor === fl) && (!so || c.dataset.source === so) ? '' : 'none';
  });
}
document.querySelectorAll('.ws-all').forEach(b => b.addEventListener('click', (e) => {
  e.preventDefault();
  const m = document.getElementById('roomModal' + b.dataset.room);
  m?.classList.add('open');
  m?.querySelector('[data-close]')?.addEventListener('click', () => m.classList.remove('open'));
  m?.addEventListener('click', (e2) => { if (e2.target === m) m.classList.remove('open'); });
}));
setInterval(() => {
  const el = document.getElementById('wsClock');
  if (el) el.innerHTML = '⟳ Live-synced ' + new Date().toLocaleTimeString('en-GB');
}, 1000);
</script>
@endpush

@push('styles')
<style>
.ws-head{display:flex;justify-content:space-between;align-items:flex-end;margin:2px 0 12px}
.ws-title{font-size:22px;margin:0}
.ws-kpis{display:grid;grid-template-columns:repeat(9,1fr);gap:8px;margin-bottom:12px}
.ws-kpi{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:10px 12px}
.ws-kpi-delta{font-size:11px;font-weight:600}
.ws-kpi-delta.up{color:#16a34a}.ws-kpi-delta.down{color:#ef4444}
.ws-spark{width:100%;height:24px;display:block;margin:4px 0}
.ws-kpi-value{font-size:18px;font-weight:700}
.ws-kpi-label{font-size:10px;letter-spacing:.4px;text-transform:uppercase;color:#64748b}
.ws-filters{display:flex;gap:8px;align-items:center;margin-bottom:12px;flex-wrap:wrap}
.ws-select{max-width:220px}
.ws-body{display:grid;grid-template-columns:1fr 320px;gap:12px;align-items:start}
.ws-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(255px,1fr));gap:12px}
.ws-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden}
.ws-card-head{display:flex;justify-content:space-between;padding:10px 12px 6px}
.ws-room-no{font-size:22px;font-weight:800}
.ws-room-no.huge{font-size:34px}
.ws-tag{display:inline-block;margin-top:4px;font-size:9px;font-weight:700;letter-spacing:.4px;background:#fef3c7;color:#b45309;padding:2px 6px;border-radius:5px}
.ws-photo{height:128px;position:relative;border-radius:8px;margin:0 10px}
.ws-photo-tag{position:absolute;left:10px;top:8px;background:rgba(15,23,42,.72);color:#fff;font-size:9px;font-weight:700;letter-spacing:.6px;padding:2px 6px;border-radius:4px}
.ws-photo-rate{position:absolute;right:10px;bottom:8px;background:#f59e0b;color:#1f2937;font-weight:800;padding:3px 8px;border-radius:6px;font-size:13px}
.ws-photo-rate small{font-weight:500}
.ws-room-meta{display:flex;gap:10px;padding:8px 12px 2px}
.ws-amen{display:flex;flex-wrap:wrap;gap:6px;padding:6px 12px 10px;color:#94a3b8}
.ws-amen .i{width:15px;height:15px}
.ws-guest{border-top:1px solid #f1f5f9;padding:10px 12px;margin:0 0 0;border-left:3px solid transparent}
.ws-guest.arriving{border-left-color:#f59e0b;background:#fffbeb66}
.ws-guest.inhouse{border-left-color:#3b82f6;background:#eff6ff66}
.ws-guest.vacant{text-align:center;border-top-style:dashed}
.ws-avatar{width:34px;height:34px;flex:0 0 34px;border-radius:50%;background:#4f46e5;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-weight:700}
.ws-avatar.sm{width:28px;height:28px;flex-basis:28px;font-size:12px}
.ws-avatar.vip{background:#b45309}
.ws-stay{display:flex;justify-content:space-between;align-items:center;padding:8px 0 2px;text-align:center}
.ws-nights{font-size:10px;background:#f1f5f9;border-radius:10px;padding:2px 8px;color:#64748b}
.ws-rate-row{display:flex;justify-content:space-between;padding:6px 0 2px}
.ws-due{color:#dc2626;font-weight:700}
.ws-vacant-rate{font-size:22px;font-weight:800;margin:4px 0 8px}
.ws-reserve{width:calc(100% - 8px);margin:0 4px 4px}
.ws-actions{display:flex;gap:6px;padding:8px 12px 12px;border-top:1px solid #f1f5f9}
.ws-all{margin-left:auto}
.ws-rail{display:flex;flex-direction:column;gap:10px}
.ws-rail-card .card-body{padding:12px}
.ws-rail-title{font-size:10px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:#64748b;margin-bottom:8px;display:flex;gap:6px;align-items:center}
.ws-stack{display:flex;height:8px;border-radius:6px;overflow:hidden;margin:4px 0 10px}
.ws-rail-list{width:100%;font-size:12px}
.ws-rail-list td{padding:2px 0}
.dot{display:inline-block;width:7px;height:7px;border-radius:50%;margin-right:6px}
.ws-person{display:flex;gap:8px;align-items:center;padding:5px 0;text-decoration:none;color:inherit}
.ws-person:hover{background:#f8fafc}
.ws-cal{display:grid;grid-template-columns:repeat(7,1fr);gap:2px;font-size:11px}
.ws-cal-d{text-align:center;font-size:9px}
.ws-cal-day{text-align:center;padding:3px 0;border-radius:6px;position:relative}
.ws-cal-day.today{background:#0f172a;color:#fff}
.ws-cal-dots{display:block;height:5px;line-height:5px}
.dot-a,.dot-d{display:inline-block;width:4px;height:4px;border-radius:50%;margin:0 1px}
.dot-a{background:#16a34a}.dot-d{background:#ef4444}
.ws-legend{margin-right:10px}
.ws-modal .modal-box{max-width:900px}
.ws-modal .modal-box.wide{width:min(920px,94vw)}
.ws-modal-hero{position:relative;padding:56px 22px 18px;color:#fff;border-radius:0}
.ws-modal-x{position:absolute;top:10px;right:10px;color:#fff}
.ws-modal-hero-row{display:flex;gap:16px;align-items:center;justify-content:space-between}
.ws-modal-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.ws-facts{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.ws-fact{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:8px 10px;font-size:13px}
.ws-fac{display:flex;flex-wrap:wrap;gap:6px}
.ws-fac-chip{display:inline-flex;gap:5px;align-items:center;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;padding:5px 9px;font-size:12px}
.ws-resv{border:1px solid #e2e8f0;border-radius:10px;padding:12px}
.ws-resv-t{width:100%;margin-top:10px;font-size:12px}
.ws-resv-t td{padding:3px 6px 3px 0}
.ws-quick{display:grid;grid-template-columns:repeat(auto-fill,minmax(112px,1fr));gap:8px}
.ws-quick-tile{display:flex;flex-direction:column;gap:6px;align-items:center;justify-content:center;padding:12px 6px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;color:#1e293b;text-decoration:none;font-size:11px}
.ws-quick-tile:hover{background:#f8fafc}
@media (max-width:1200px){.ws-kpis{grid-template-columns:repeat(5,1fr)}.ws-body{grid-template-columns:1fr}}
</style>
@endpush
