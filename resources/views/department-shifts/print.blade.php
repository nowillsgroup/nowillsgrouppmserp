@extends('layouts.print')
@section('title', 'Service shift totals #'.$session->id)
@section('backurl', route('department-shifts.show', $session))
@section('doctitle', 'Service shift totals')
@section('docmeta')
<div class="small">
  <b>Report:</b> #{{ $session->id }} · {{ $session->status === 'open' ? 'LIVE (provisional)' : 'Close-out snapshot' }}<br>
  <b>Staff member:</b> {{ $session->user?->name ?? 'Former staff member' }}<br>
  <b>Department:</b> Food &amp; Beverage Service<br>
  <b>Shift:</b> {{ ucfirst($session->shift) }}<br>
  <b>Business date:</b> {{ $session->business_date->format('D, d M Y') }}<br>
  <b>Opened:</b> {{ $session->opened_at->format('d M Y H:i') }}<br>
  <b>Printed:</b> {{ now()->format('d M Y H:i') }}
</div>
@endsection
@section('content')
@php
  $payments = $summary['payments'] ?? [];
  $paidIn = (float) ($summary['payments_in'] ?? 0);
  $paidOut = (float) ($summary['payments_out'] ?? 0);
  $paidNet = (float) ($summary['payments_total'] ?? array_sum(array_map(fn ($r) => (float) ($r['net'] ?? 0), $payments)));
@endphp

<p class="small">
  @if($session->status === 'open')
    <b>Provisional live sheet.</b> This shift is still open, so the figures below can change until it is closed.
  @else
    <b>Saved close-out.</b> These figures were frozen when the shift was closed on {{ $session->closed_at?->format('d M Y H:i') ?? '—' }}.
  @endif
  POS order value belongs to the service shift; payment reconciliation against the drawer stays on the cashier shift.
</p>

<table class="table table-compact" id="serviceShiftTotalsPrint">
  <thead><tr><th>Orders handled</th><th>Covers</th><th class="num">Order value (excl. voids)</th><th class="num">Settled POS sales</th><th class="num">Room-posted orders</th><th>Voided orders</th><th>Open / billed</th></tr></thead>
  <tbody><tr>
    <td>{{ $summary['orders'] }}</td><td>{{ $summary['covers'] }}</td><td class="num">{{ money($summary['order_value'], false) }}</td>
    <td class="num">{{ money($summary['settled_sales'], false) }}</td><td class="num">{{ money($summary['room_posts'], false) }}</td>
    <td>{{ $summary['void_orders'] }}</td><td>{{ $summary['open_orders'] }}</td>
  </tr></tbody>
</table>

<h3 class="mt-2" style="font-size:.95rem">Payments by method</h3>
<table class="table table-compact" id="serviceShiftPaymentsPrint">
  <thead><tr><th>Payment method</th><th class="num">Tenders</th><th class="num">Collected</th><th class="num">Refunds</th><th class="num">Net</th></tr></thead>
  <tbody>
    @forelse($payments as $row)
      <tr>
        <td><b>{{ $row['name'] }}</b>@if(! empty($row['is_cash'])) <span class="tiny">(drawer cash)</span>@endif</td>
        <td class="num">{{ $row['count'] }}</td><td class="num">{{ money($row['collected'], false) }}</td>
        <td class="num">{{ money($row['refunded'], false) }}</td><td class="num">{{ money($row['net'], false) }}</td>
      </tr>
    @empty
      <tr><td colspan="5" class="muted">No payment was collected on this shift. Room-posted orders are billed to the guest folio.</td></tr>
    @endforelse
  </tbody>
  <tfoot><tr>
    <td><b>Total, all methods</b></td><td class="num">{{ collect($payments)->sum('count') }}</td>
    <td class="num">{{ money($paidIn, false) }}</td><td class="num">{{ money($paidOut, false) }}</td><td class="num">{{ money($paidNet, false) }}</td>
  </tr></tfoot>
</table>

<p class="small mt-2" id="serviceShiftPaymentTotal">Total collected on this shift, all payment methods: <b>{{ money($paidNet) }}</b>@if((float) $summary['room_posts'] > 0) · a further {{ money($summary['room_posts']) }} was posted to guest rooms instead of being paid at the table @endif.</p>

<h3 class="mt-2" style="font-size:.95rem">Orders linked to this shift</h3>
<table class="table table-compact" id="serviceShiftOrdersPrint">
  <thead><tr><th>Order</th><th>Outlet / table</th><th>Guest</th><th>Opened</th><th class="num">Covers</th><th>Status</th><th class="num">Value</th></tr></thead>
  <tbody>
    @forelse($session->posOrders as $order)
      <tr>
        <td>{{ $order->order_no }}</td>
        <td>{{ $order->outlet?->name ?? '—' }}<div class="tiny">{{ $order->table ? 'Table '.$order->table->table_no : ucfirst(str_replace('_', ' ', $order->order_type)) }}</div></td>
        <td>{{ $order->customer_name ?: '—' }}</td><td>{{ $order->created_at?->format('H:i') }}</td>
        <td class="num">{{ $order->covers }}</td><td>{{ ucfirst(str_replace('_', ' ', $order->status)) }}</td>
        <td class="num">{{ money($order->total, false) }}</td>
      </tr>
    @empty
      <tr><td colspan="7" class="muted">No POS orders were opened during this service shift.</td></tr>
    @endforelse
  </tbody>
</table>

<div class="mt-2" style="display:flex;gap:2.5rem;flex-wrap:wrap">
  <div class="small" style="min-width:220px"><b>Handed over by</b><div style="border-bottom:1px solid #94a3b8;height:1.6rem"></div><span class="tiny">Name / signature · {{ $session->user?->name }}</span></div>
  <div class="small" style="min-width:220px"><b>Received by (supervisor)</b><div style="border-bottom:1px solid #94a3b8;height:1.6rem"></div><span class="tiny">Name / signature</span></div>
</div>
<p class="tiny muted mt-2">Printed from Nowills PMS · report #{{ $session->id }} · {{ $session->status === 'open' ? 'provisional live figures' : 'saved close-out figures' }}.</p>
@endsection
