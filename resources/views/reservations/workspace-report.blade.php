@extends('layouts.print')
@section('title', 'Day Report')
@section('doctitle', 'Reservation Workspace · Day Report')
@section('docmeta')
<div class="small text-2">{{ $date->format('l, j F Y') }}</div>
@endsection

@php use App\Services\ReservationWorkspaceService as WS; @endphp

@section('content')
<div class="kpis" style="display:grid;grid-template-columns:repeat(5,1fr);gap:8px;margin:12px 0">
  @foreach ([
    ['Occupancy', $kpis['occupancy'].'%'],
    ['Revenue (on books)', 'GH₵ '.number_format($kpis['revenue'])],
    ['Available', $kpis['available']],
    ['Occupied', $kpis['occupied']],
    ['Reserved', $kpis['reserved']],
    ['Arrivals', $kpis['arrivals']],
    ['Departures', $kpis['departures']],
    ['In-house guests', $kpis['inhouse']],
    ['VIP guests', $kpis['vips']],
    ['Rooms (active)', $cards->count()],
  ] as [$label, $value])
  <div style="border:1px solid #d7dce5;border-radius:8px;padding:8px 10px">
    <div class="small text-2" style="text-transform:uppercase;letter-spacing:.4px">{{ $label }}</div>
    <div style="font-size:16px;font-weight:700">{{ $value }}</div>
  </div>
  @endforeach
</div>

<div class="two" style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
  <div>
    <h3 style="margin:10px 0 6px">Arrivals today · {{ $arrivals->count() }}</h3>
    <table class="tbl">
      <thead><tr><th>Room</th><th>Guest</th><th>Source</th><th>Nights</th><th>Rate</th></tr></thead>
      <tbody>
        @forelse ($arrivals as $s)
        <tr>
          <td>{{ $s->room?->room_no ?? '—' }}</td>
          <td>{{ $s->guest?->first_name ?? $s->reservation->guest?->first_name }} {{ $s->guest?->last_name ?? $s->reservation->guest?->last_name }}</td>
          <td>{{ $s->reservation->businessSource->name ?? 'Direct' }}</td>
          <td>{{ max(1, \Illuminate\Support\Carbon::parse($s->arrival_date)->diffInDays(\Illuminate\Support\Carbon::parse($s->departure_date))) }}</td>
          <td>GH₵{{ number_format((float) $s->rate, 2) }}</td>
        </tr>
        @empty
        <tr><td colspan="5" class="text-2">No arrivals today.</td></tr>
        @endforelse
      </tbody>
    </table>

    <h3 style="margin:14px 0 6px">Departures today · {{ $departures->count() }}</h3>
    <table class="tbl">
      <thead><tr><th>Room</th><th>Guest</th><th>Source</th><th>Balance</th></tr></thead>
      <tbody>
        @forelse ($departures as $s)
        @php
          // `due` for a stay still in house (nights and fixed charges post at
          // checkout), the final folio balance for one already checked out
          $df = $departureFigures[$s->id] ?? null;
          $bal = $df['amount'] ?? null;
        @endphp
        <tr>
          <td>{{ $s->room?->room_no ?? '—' }}</td>
          <td>{{ $s->guest?->first_name ?? $s->reservation->guest?->first_name }} {{ $s->guest?->last_name ?? $s->reservation->guest?->last_name }}</td>
          <td>{{ $s->reservation->businessSource->name ?? 'Direct' }}</td>
          <td class="{{ ($bal ?? 0) > 0 ? 'neg' : 'text-2' }}">{{ $bal === null ? '—' : (($bal > 0 ? 'GH₵ '.number_format($bal, 2) : 'Settled').($bal > 0 && ($df['unposted'] ?? 0) > 0 ? ' *' : '')) }}</td>
        </tr>
        @empty
        <tr><td colspan="4" class="text-2">No departures today.</td></tr>
        @endforelse
      </tbody>
    </table>
    @if (collect($departureFigures ?? [])->filter(fn ($f) => ($f['unposted'] ?? 0) > 0)->isNotEmpty())
    <div class="text-2 small">* still in house: the figure is what checkout will collect, including nights and fixed charges not yet posted.</div>
    @endif
  </div>

  <div>
    <h3 style="margin:10px 0 6px">Room status</h3>
    <table class="tbl">
      <thead><tr><th>Status</th><th>Rooms</th></tr></thead>
      <tbody>
        @foreach ($statusSummary as $label => [$n, $color])
        <tr><td>{{ $label }}</td><td>{{ $n }}</td></tr>
        @endforeach
        <tr><td><b>Housekeeping — dirty</b></td><td>{{ $hk['Dirty'] }}</td></tr>
        <tr><td><b>Housekeeping — touch-up</b></td><td>{{ $hk['Touch-up'] }}</td></tr>
      </tbody>
    </table>

    <h3 style="margin:14px 0 6px">VIP in-house · {{ $vip->count() }}</h3>
    <table class="tbl">
      <tbody>
        @forelse ($vip as $s)
        <tr><td>{{ $s->room?->room_no ?? '—' }}</td><td>{{ $s->guest?->first_name ?? $s->reservation->guest?->first_name }} {{ $s->guest?->last_name ?? $s->reservation->guest?->last_name }}</td></tr>
        @empty
        <tr><td class="text-2">No VIPs in-house.</td></tr>
        @endforelse
      </tbody>
    </table>

    <h3 style="margin:14px 0 6px">Waiting list · {{ $waitlist->count() }}</h3>
    <table class="tbl">
      <tbody>
        @forelse ($waitlist as $w)
        <tr><td>{{ $w->guest?->first_name ?? $w->contact_name }}</td><td>{{ \Illuminate\Support\Carbon::parse($w->arrival_date)->format('Y-m-d') }}</td><td>{{ $w->roomType->name ?? 'Any' }}</td></tr>
        @empty
        <tr><td class="text-2">Waiting list is empty.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<h3 style="margin:16px 0 6px">Room board</h3>
<table class="tbl">
  <thead><tr><th>Room</th><th>Type</th><th>Status</th><th>Guest / state</th><th>Arrive</th><th>Depart</th><th>Rate</th><th>Balance</th></tr></thead>
  <tbody>
    @foreach ($cards as $c)
    <tr>
      <td><b>{{ $c['room']->room_no }}</b></td>
      <td>{{ $c['room']->roomType->name ?? '—' }}</td>
      <td>{{ ucwords(str_replace('_', ' ', $c['status'])) }}</td>
      <td>
        @if ($c['stay'])
          {{ $c['stay']->guest?->first_name ?? $c['stay']->reservation->guest?->first_name }} {{ $c['stay']->guest?->last_name ?? $c['stay']->reservation->guest?->last_name }}
          {{ $c['arriving_today'] ? '(arriving today)' : '' }}
        @else
          <span class="text-2">Vacant</span>
        @endif
      </td>
      <td>{{ $c['stay'] ? \Illuminate\Support\Carbon::parse($c['stay']->arrival_date)->format('d M') : '—' }}</td>
      <td>{{ $c['stay'] ? \Illuminate\Support\Carbon::parse($c['stay']->departure_date)->format('d M') : '—' }}</td>
      <td>GH₵{{ number_format($c['rate'], 2) }}</td>
      <td class="{{ ($c['balance'] ?? 0) > 0 ? 'neg' : 'text-2' }}">{{ $c['balance'] !== null && $c['balance'] > 0 ? 'GH₵ '.number_format($c['balance'], 2) : '—' }}</td>
    </tr>
    @endforeach
  </tbody>
</table>

<p class="small text-2" style="margin-top:14px">Generated {{ now()->format('d M Y H:i') }} · Reservation Workspace Day Report · on-the-books figures</p>
@endsection

@push('styles')
<style>
.tbl{width:100%;border-collapse:collapse;font-size:12px}
.tbl th,.tbl td{border:1px solid #d7dce5;padding:5px 7px;text-align:left}
.tbl th{background:#f1f5f9;font-size:11px;text-transform:uppercase;letter-spacing:.3px}
.neg{color:#dc2626;font-weight:700}
h3{font-size:13px;text-transform:uppercase;letter-spacing:.5px;color:#475569}
</style>
@endpush
