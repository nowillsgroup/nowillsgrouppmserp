@extends('layouts.app')
@section('title', 'Meter Readings')
@section('crumbs') <a href="{{ route('leases.index') }}">Apartments &amp; Leases</a> / Meter readings @endsection
@section('content')
<div class="card" id="bulkMeters"><div class="card-head"><h3><x-icon name="thermo"/> Monthly readings</h3><span class="small muted">metered tenants are recharged for the consumption since the previous reading (plus tax at their lease rate)</span></div><div class="card-body">
<form method="post" action="{{ route('leases.meters.store') }}">@csrf
  <div class="field" style="max-width:220px"><label for="m_date">Reading date</label><input type="date" name="date" id="m_date" value="{{ $date }}" max="{{ bdate()->toDateString() }}" required></div>
  <div class="table-wrap"><table class="table" id="metersTable">
    <thead><tr><th>Unit</th><th>Tenant</th><th>Electricity (kWh)</th><th>Water (m³)</th></tr></thead>
    <tbody>@foreach($units as $u)@php $cl = $u->currentLease; @endphp
      <tr data-unit="{{ $u->code }}"><td class="bold">{{ $u->code }}<div class="tiny muted">{{ $u->elec_meter }} {{ $u->water_meter }}</div></td><td class="small">{{ $cl?->tenant_name ?? '— '.strtolower(\App\Models\LeaseUnit::STATUSES[$u->status]).' —' }}</td>
      @foreach(['electricity' => 'elec_mode', 'water' => 'water_mode'] as $m => $mode)@php $lr = $last[$u->id.'-'.$m] ?? null; @endphp
        <td><input type="number" step="0.01" min="{{ $lr?->reading ?? 0 }}" name="r[{{ $u->id }}][{{ $m }}]" style="max-width:140px" data-meter="{{ $m }}">
          <div class="tiny muted">last {{ $lr ? number_format($lr->reading, 2).' · '.dmy($lr->date) : '—' }}{{ $cl ? ' · '.($cl->{$mode} === 'metered' ? 'recharged @ '.\App\Services\LeaseService::tariff($cl, $m) : strtolower(\App\Models\Lease::UTILITY_MODES[$cl->{$mode}])) : '' }}</div></td>
      @endforeach</tr>
    @endforeach</tbody></table></div>
  @if(can_do('leases.manage'))<button class="btn btn-primary mt-1" id="metersSave">Save readings</button>@endif
</form></div></div>

<div class="card flush mt-1"><div class="card-head"><h3>Recent readings</h3></div><div class="card-body table-wrap"><table class="table" id="recentReadings">
  <thead><tr><th>Date</th><th>Unit</th><th>Meter</th><th class="num">Previous</th><th class="num">Reading</th><th class="num">Used</th><th>Recharge</th><th>By</th></tr></thead>
  <tbody>@forelse($recent as $r)
    <tr><td class="small">{{ dmy($r->date) }}</td><td class="bold">{{ $r->unit->code }}</td><td class="small">{{ \App\Models\LeaseMeterReading::METERS[$r->meter][0] }} · {{ $r->kind }}</td><td class="num">{{ $r->previous !== null ? number_format($r->previous, 2) : '—' }}</td><td class="num">{{ number_format($r->reading, 2) }}</td><td class="num">{{ number_format($r->consumption, 2) }}</td>
      <td class="small">@if($r->charge)<a href="{{ route('leases.charges.invoice', $r->charge) }}" target="_blank">{{ $r->charge->charge_no }}</a> {{ money($r->charge->total, false) }}@endif</td><td class="small">{{ $r->user?->name }}</td></tr>
  @empty <tr><td colspan="8"><x-empty text="No readings yet." icon="thermo"/></td></tr> @endforelse</tbody></table></div></div>
@endsection
