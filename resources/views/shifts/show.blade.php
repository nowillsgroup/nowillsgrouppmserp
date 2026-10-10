@extends('layouts.print')
@section('title', 'Cashiering report #'.$shift->id)
@section('doctitle', 'CASHIER SHIFT REPORT')
@section('docmeta')<div>Shift #{{ $shift->id }} · {{ $shift->status === 'open' ? 'OPEN (provisional)' : 'CLOSED' }}</div>@endsection
@section('content')
@if($shift->status === 'open' && can_do('folio.shift_all') && $shift->user_id !== auth()->id())
<div class="no-print" style="border:1px solid #f59e0b;padding:10px;margin-bottom:12px"><b>Manager close-out</b>
  <form method="post" action="{{ route('shifts.close', $shift) }}" style="display:flex;gap:8px;align-items:end;margin-top:8px">@csrf
    <label>Counted cash <input name="counted_cash" type="number" step="0.01" min="0" required></label>
    <label>Notes <input name="notes" maxlength="250"></label>
    <button class="btn btn-warning">Close this shift</button>
  </form>
</div>
@endif
<table style="width:100%;margin-bottom:12px"><tr>
  <td><b>Cashier:</b> {{ $shift->user->name }} ({{ $shift->user->username }})<br><b>Business date:</b> {{ dmy($shift->business_date) }}</td>
  <td class="num"><b>Opened:</b> {{ $shift->opened_at->format('d M Y H:i') }}<br><b>Closed:</b> {{ $shift->closed_at?->format('d M Y H:i') ?? '—' }}@if($shift->closedBy)<br><b>Closed by:</b> {{ $shift->closedBy->name }}@endif</td></tr></table>
@if($shift->movements->isNotEmpty())
<h3>Drawer movements</h3>
<table class="table table-compact"><thead><tr><th>When</th><th>Type</th><th>Reason</th><th>Reference</th><th>By</th><th class="num">Amount</th></tr></thead><tbody>
@foreach($shift->movements->sortBy('id') as $movement)<tr><td>{{ $movement->created_at->format('d M H:i') }}</td><td>{{ $movement->kindLabel() }}</td><td>{{ $movement->reason }}</td><td>{{ $movement->reference ?: '—' }}</td><td>{{ $movement->user?->name }}</td><td class="num">{{ $movement->isIn() ? '+' : '−' }}{{ money($movement->amount, false) }}</td></tr>@endforeach
</tbody></table>
@endif
<table class="table table-compact"><thead><tr><th style="text-align:left">Payment mode</th><th class="num">Transactions</th><th class="num">Received</th><th class="num">Refunded</th><th class="num">Net</th></tr></thead><tbody>
@forelse($summary['modes'] as $name => $m)<tr><td>{{ $name }}</td><td class="num">{{ $m['count'] }}</td><td class="num">{{ number_format($m['in'], 2) }}</td><td class="num">{{ number_format($m['out'], 2) }}</td><td class="num"><b>{{ number_format($m['net'], 2) }}</b></td></tr>
@empty<tr><td colspan="5">No collections.</td></tr>@endforelse
</tbody><tfoot><tr><td><b>Total</b></td><td class="num">{{ $summary['count'] }}</td><td class="num"><b>{{ number_format($summary['total_in'], 2) }}</b></td><td class="num">{{ number_format($summary['total_out'], 2) }}</td><td class="num"><b>{{ number_format($summary['total_in'] - $summary['total_out'], 2) }}</b></td></tr></tfoot></table>
<h3>Collection detail</h3>
<table class="table table-compact"><thead><tr><th>Time</th><th>Source</th><th>Description</th><th>Payment mode</th><th>Reference</th><th class="num">Amount</th></tr></thead><tbody>
@foreach($folioTransactions as $t)<tr><td class="small">{{ $t->created_at->format('d M H:i') }}</td><td>Folio <a href="{{ route('folios.show', $t->folio) }}">{{ $t->folio?->folio_no }}</a></td><td>{{ $t->description }}</td><td>{{ $t->paymentMode?->name ?: '—' }}</td><td class="small">{{ $t->reference ?: '—' }}</td><td class="num {{ $t->type === 'refund' ? 'text-red' : 'text-green' }}">{{ $t->type === 'refund' ? '−' : '+' }}{{ money($t->amount, false) }}</td></tr>@endforeach
@foreach($posSettlements as $order)<tr><td class="small">{{ $order->settled_at->format('d M H:i') }}</td><td>POS <a href="{{ route('pos.terminal', $order) }}">{{ $order->order_no }}</a></td><td>Restaurant bill</td><td>{{ $order->paymentMode?->name ?: '—' }}</td><td class="small">{{ $order->order_no }}</td><td class="num text-green">+{{ money($order->total, false) }}</td></tr>@endforeach
@if($folioTransactions->isEmpty() && $posSettlements->isEmpty())<tr><td colspan="6" class="muted">No payment detail is recorded.</td></tr>@endif
</tbody></table>
<table style="width:320px;margin:14px 0 0 auto" class="table table-compact">
  <tr><td>Opening float</td><td class="num">{{ money($shift->opening_float) }}</td></tr>
  <tr><td>+ Cash received</td><td class="num">{{ money($summary['cash_in']) }}</td></tr>
  <tr><td>− Cash refunded</td><td class="num">{{ money($summary['cash_out']) }}</td></tr>
  <tr><td><b>Expected cash</b></td><td class="num"><b>{{ money($summary['expected_cash']) }}</b></td></tr>
  @if(! empty($summary['forex']))<tr><td>Foreign currency handed over</td><td class="num">{{ collect($summary['forex'])->map(fn ($v, $k) => $k.' '.number_format($v, 2))->implode(' · ') }}</td></tr>@endif
  @if($shift->status === 'closed')
  <tr><td><b>Counted cash</b></td><td class="num"><b>{{ money($shift->counted_cash) }}</b></td></tr>
  <tr><td><b>Variance</b></td><td class="right" style="color:{{ abs($shift->variance) < 0.01 ? '#15803d' : '#b91c1c' }}"><b>{{ money($shift->variance) }}</b></td></tr>
  @if($shift->counted_denominations)<tr><td>Denomination count</td><td class="num small">{{ collect($shift->counted_denominations)->map(fn ($qty, $denom) => number_format((float) $denom, (float) $denom < 1 ? 1 : 0).' × '.$qty)->implode(' · ') }}</td></tr>@endif
  @endif
</table>
@if($shift->notes)<p style="margin-top:12px"><b>Notes:</b> {!! nl2br(e($shift->notes)) !!}</p>@endif
<table style="width:100%;margin-top:50px"><tr><td>Cashier signature: ____________________</td><td class="num">Received by: ____________________</td></tr></table>
<p class="no-print" style="margin-top:20px"><a href="{{ route('shifts.index') }}">← Back to shifts</a></p>
@endsection
