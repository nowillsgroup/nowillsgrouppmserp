@extends('layouts.app')
@section('title', 'Service Shift Report #'.$session->id)
@section('crumbs') Operations / My Shift / Food &amp; Beverage / Report #{{ $session->id }} @endsection
@section('actions')<a class="btn" href="{{ route('department-shifts.print', $session) }}" target="_blank" rel="noopener"><x-icon name="print"/> Print shift totals</a><a class="btn" href="{{ route('department-shifts.index', ['date' => $session->business_date->toDateString()]) }}"><x-icon name="chev"/> Back to shift reports</a>@endsection
@section('content')
<div class="alert {{ $session->status === 'closed' ? 'alert-success' : 'alert-info' }}"><x-icon name="check"/><div><b>{{ $session->status === 'closed' ? 'Close-out snapshot' : 'Provisional live report' }}</b> · This report belongs to {{ $session->user?->name ?? 'a former staff member' }}. POS order value belongs to the service shift; payment reconciliation remains on the cashier shift.</div></div>
<div class="grid g-4">
  <div class="card"><div class="card-head"><h3>Shift details</h3><span class="badge badge-{{ $session->status === 'open' ? 'green' : 'slate' }}">{{ $session->statusLabel() }}</span></div><div class="card-body"><dl class="detail-grid">
    <dt>Staff member</dt><dd>{{ $session->user?->name ?? 'Former staff member' }}</dd><dt>Department</dt><dd>Food &amp; Beverage Service</dd><dt>Shift</dt><dd>{{ ucfirst($session->shift) }}</dd><dt>Business date</dt><dd>{{ $session->business_date->format('D, d M Y') }}</dd><dt>Opened</dt><dd>{{ $session->opened_at->format('d M Y H:i') }}</dd><dt>Closed</dt><dd>{{ $session->closed_at?->format('d M Y H:i') ?? 'Still open' }}</dd>
  </dl>@if($session->opening_note)<p class="small mt-1"><b>Opening note:</b> {{ $session->opening_note }}</p>@endif @if($session->closing_note)<p class="small mt-1"><b>Closing note:</b> {{ $session->closing_note }}</p>@endif</div></div>
  @php
    $payments = $summary['payments'] ?? [];
    $paidIn = (float) ($summary['payments_in'] ?? 0);
    $paidOut = (float) ($summary['payments_out'] ?? 0);
    $paidNet = (float) ($summary['payments_total'] ?? array_sum(array_map(fn ($r) => (float) ($r['net'] ?? 0), $payments)));
    $roomPosts = (float) ($summary['room_posts'] ?? 0);
  @endphp
  <div class="card"><div class="card-head"><h3>{{ $session->status === 'closed' ? 'Saved shift close-out' : 'Live shift totals' }}</h3>@if($session->status === 'open')<span class="badge badge-green">Live</span>@endif</div><div class="card-body"><ul class="list">
    <li><span>Orders handled</span><b>{{ $summary['orders'] }}</b></li><li><span>Covers</span><b>{{ $summary['covers'] }}</b></li><li><span>Order value, excluding voids</span><b>{{ money($summary['order_value']) }}</b></li><li><span>Settled POS sales</span><b>{{ money($summary['settled_sales']) }}</b></li><li><span>Room-posted orders</span><b>{{ money($summary['room_posts']) }}</b></li><li><span>Voided orders</span><b>{{ $summary['void_orders'] }}</b></li><li><span>Open / billed orders at close</span><b>{{ $summary['open_orders'] }}</b></li>
  </ul>

  <div class="flex justify-between items-center mt-2" style="gap:.5rem;flex-wrap:wrap">
    <div><b class="small">Payments by method</b><div class="tiny muted">What this shift collected on its own orders. Room posts settle on the guest folio, not here.</div></div>
    <a class="btn btn-sm" href="{{ route('department-shifts.print', $session) }}" target="_blank" rel="noopener"><x-icon name="print" size="sm"/> Print totals sheet</a>
  </div>
  <div class="table-wrap mt-1"><table class="table table-compact" id="serviceShiftPayments">
    <thead><tr><th>Payment method</th><th class="num">Collected</th><th class="num">Refunds</th><th class="num">Net</th></tr></thead>
    <tbody>
      @forelse($payments as $row)
        <tr><td><b>{{ $row['name'] }}</b>@if(! empty($row['is_cash']))<span class="tiny muted"> · drawer cash</span>@endif<div class="tiny muted">{{ $row['count'] }} tender{{ $row['count'] === 1 ? '' : 's' }}</div></td><td class="num">{{ money($row['collected']) }}</td><td class="num">{{ money($row['refunded']) }}</td><td class="num">{{ money($row['net']) }}</td></tr>
      @empty
        <tr><td colspan="4" class="muted">No payment has been collected on this shift yet. Room-posted orders are billed to the guest folio.</td></tr>
      @endforelse
    </tbody>
    <tfoot><tr><td><b>Total, all methods</b></td><td class="num">{{ money($paidIn) }}</td><td class="num">{{ money($paidOut) }}</td><td class="num">{{ money($paidNet) }}</td></tr></tfoot>
  </table></div>
  <p class="tiny muted mt-1" id="serviceShiftPaymentNote">Total collected on this {{ $session->status === 'open' ? 'open' : 'closed' }} shift: <b>{{ money($paidNet) }}</b>@if($roomPosts > 0) · {{ money($roomPosts) }} of orders were posted to guest rooms instead of being paid here @endif. Payment reconciliation against the drawer stays on the cashier shift.</p>
  </div></div>
</div>
<section class="card flush mt-2"><div class="card-head"><div><h2>Orders linked to this shift</h2><div class="small muted">Orders were linked when this staff member opened them during the service session.</div></div></div><div class="card-body table-wrap"><table class="table"><thead><tr><th>Order</th><th>Outlet / table</th><th>Guest / service</th><th>Opened</th><th>Covers</th><th>Status</th><th class="num">Value</th><th></th></tr></thead><tbody>
  @forelse($session->posOrders as $order)
    <tr><td>{{ $order->order_no }}</td><td>{{ $order->outlet?->name ?? '—' }}<div class="small muted">{{ $order->table ? 'Table '.$order->table->table_no : ucfirst(str_replace('_', ' ', $order->order_type)) }}</div></td><td>{{ $order->customer_name ?: '—' }}</td><td>{{ $order->created_at?->format('H:i') }}</td><td>{{ $order->covers }}</td><td>{{ ucfirst(str_replace('_', ' ', $order->status)) }}</td><td class="num">{{ money($order->total) }}</td><td>@if(can_do('pos.view'))<a class="btn btn-sm" href="{{ route('pos.terminal', $order) }}">View</a>@else<span class="muted">—</span>@endif</td></tr>
  @empty<tr><td colspan="8" class="muted">No POS orders were opened during this service shift.</td></tr>@endforelse
</tbody></table></div></section>
@endsection
