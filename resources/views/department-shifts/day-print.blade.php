@extends('layouts.print')
@section('title', 'Service shift day report · '.$serviceShiftDate)
@section('backurl', route('department-shifts.index', ['date' => $serviceShiftDate]))
@section('doctitle', 'Service shift day report')
@section('docmeta')
<div class="small">
  <b>Business date:</b> {{ \Illuminate\Support\Carbon::parse($serviceShiftDate)->format('D, d M Y') }}<br>
  <b>Department:</b> Food &amp; Beverage Service<br>
  <b>Scope:</b> {{ $canViewServiceTeam ? 'All staff sessions on this date' : 'My own sessions' }}<br>
  <b>Sessions:</b> {{ $teamServiceSessions->count() }}<br>
  <b>Printed:</b> {{ now()->format('d M Y H:i') }}<br>
  <b>Printed by:</b> {{ auth()->user()?->name }}
</div>
@endsection
@section('content')
<p class="small">
  A service shift is not a cashier drawer: it shows the POS orders each member of staff handled and what was collected on them.
  Payment reconciliation against the drawer stays on the cashier shift.
</p>

<h3 class="mt-2" style="font-size:.95rem">Totals by shift</h3>
<table class="table table-compact" id="serviceShiftDayShiftsPrint">
  <thead><tr><th>Shift</th><th class="num">Staff sessions</th><th class="num">Active</th><th class="num">Orders</th><th class="num">Covers</th><th class="num">Order value</th><th class="num">Settled POS sales</th><th class="num">Collected by method</th><th class="num">Open orders</th></tr></thead>
  <tbody>
    @forelse($serviceDaySummary as $shift => $summary)
      <tr>
        <td><b>{{ ucfirst($shift) }}</b></td><td class="num">{{ $summary['people'] }}</td><td class="num">{{ $summary['active'] }}</td>
        <td class="num">{{ $summary['orders'] }}</td><td class="num">{{ $summary['covers'] }}</td>
        <td class="num">{{ money($summary['order_value'], false) }}</td><td class="num">{{ money($summary['settled_sales'], false) }}</td>
        <td class="num">{{ money($summary['payments_total'] ?? 0, false) }}</td><td class="num">{{ $summary['open_orders'] }}</td>
      </tr>
    @empty
      <tr><td colspan="9" class="muted">No Food &amp; Beverage service shifts were recorded for this business date.</td></tr>
    @endforelse
  </tbody>
  <tfoot><tr>
    <td><b>All shifts</b></td>
    <td class="num">{{ $serviceDaySummary->sum('people') }}</td><td class="num">{{ $serviceDaySummary->sum('active') }}</td>
    <td class="num">{{ $serviceDaySummary->sum('orders') }}</td><td class="num">{{ $serviceDaySummary->sum('covers') }}</td>
    <td class="num">{{ money($serviceDaySummary->sum('order_value'), false) }}</td><td class="num">{{ money($serviceDaySummary->sum('settled_sales'), false) }}</td>
    <td class="num">{{ money($serviceDaySummary->sum('payments_total'), false) }}</td><td class="num">{{ $serviceDaySummary->sum('open_orders') }}</td>
  </tr></tfoot>
</table>

<p class="small mt-2" id="serviceShiftDayCollected">Total collected on this date, all shifts and all payment methods: <b>{{ money($serviceDaySummary->sum('payments_total')) }}</b>.</p>

<h3 class="mt-2" style="font-size:.95rem">Individual staff sessions</h3>
<table class="table table-compact" id="serviceShiftDaySessionsPrint">
  <thead><tr><th>Staff member</th><th>Shift</th><th>Opened</th><th>Closed</th><th>Status</th><th class="num">Orders</th><th class="num">Covers</th><th class="num">Order value</th><th class="num">Settled</th><th class="num">Collected by method</th></tr></thead>
  <tbody>
    @forelse($teamServiceSessions as $session)
      <tr>
        <td>{{ $session->user?->name ?? 'Former staff member' }}</td>
        <td>{{ ucfirst($session->shift) }}</td>
        <td>{{ $session->opened_at->format('H:i') }}</td>
        <td>{{ $session->closed_at?->format('H:i') ?? '—' }}</td>
        <td>{{ $session->statusLabel() }}</td>
        <td class="num">{{ $session->report_summary['orders'] }}</td>
        <td class="num">{{ $session->report_summary['covers'] }}</td>
        <td class="num">{{ money($session->report_summary['order_value'], false) }}</td>
        <td class="num">{{ money($session->report_summary['settled_sales'], false) }}</td>
        <td class="num">{{ money($session->report_summary['payments_total'] ?? 0, false) }}</td>
      </tr>
    @empty
      <tr><td colspan="10" class="muted">No individual shift sessions found for this date.</td></tr>
    @endforelse
  </tbody>
  <tfoot><tr>
    <td colspan="5"><b>All sessions</b></td>
    <td class="num">{{ $teamServiceSessions->sum(fn ($s) => $s->report_summary['orders']) }}</td>
    <td class="num">{{ $teamServiceSessions->sum(fn ($s) => $s->report_summary['covers']) }}</td>
    <td class="num">{{ money($teamServiceSessions->sum(fn ($s) => $s->report_summary['order_value']), false) }}</td>
    <td class="num">{{ money($teamServiceSessions->sum(fn ($s) => $s->report_summary['settled_sales']), false) }}</td>
    <td class="num">{{ money($teamServiceSessions->sum(fn ($s) => (float) ($s->report_summary['payments_total'] ?? 0)), false) }}</td>
  </tr></tfoot>
</table>

<div class="mt-2" style="display:flex;gap:2.5rem;flex-wrap:wrap">
  <div class="small" style="min-width:220px"><b>Prepared by</b><div style="border-bottom:1px solid #94a3b8;height:1.6rem"></div><span class="tiny">Name / signature · {{ auth()->user()?->name }}</span></div>
  <div class="small" style="min-width:220px"><b>Checked by (manager)</b><div style="border-bottom:1px solid #94a3b8;height:1.6rem"></div><span class="tiny">Name / signature</span></div>
</div>
<p class="tiny muted mt-2">Printed from Nowills PMS · service shift day report · {{ \Illuminate\Support\Carbon::parse($serviceShiftDate)->format('d M Y') }}.</p>
@endsection
