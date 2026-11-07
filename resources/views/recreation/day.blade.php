@extends('layouts.app')
@section('title', 'Recreation Day Report')
@section('crumbs') <a href="{{ route('recreation.outlet') }}">Recreation outlet</a> / Day report @endsection
@section('actions')
<a class="btn" href="{{ route('recreation.shift') }}"><x-icon name="wallet"/> Shift</a>
<a class="btn btn-primary" href="{{ route('recreation.day.print', ['date' => $report['date']]) }}" target="_blank"><x-icon name="print"/> Print the Z</a>
@endsection
@section('content')
<form method="get" class="filters mb-1 flex gap-1 items-center">
  <label for="date" class="small">Business date</label>
  <input id="date" type="date" name="date" class="input input-sm" value="{{ $report['date'] }}" max="{{ bdate()->toDateString() }}">
  <button class="btn btn-sm"><x-icon name="search"/> Show</button>
  <span class="small muted">Sales are counted on the business day they were rung up, not the wall clock.</span>
</form>

<div class="stats">
  <x-stat label="Takings" :value="money($report['total'])" icon="money" tone="green" :sub="$report['sales'].' sale(s)'"/>
  <x-stat label="Net revenue" :value="money($report['net'])" icon="chart" tone="blue" :sub="'tax '.money($report['tax'])"/>
  <x-stat label="Tickets issued" :value="$report['tickets']" icon="users" tone="purple" :sub="$report['items'].' item(s) sold'"/>
  <x-stat label="Cash taken" :value="money($report['cash_in'])" icon="wallet" tone="amber" :sub="'change '.money($report['change'])"/>
</div>

<div class="grid g-2-1">
  <div>
    <div class="card flush"><div class="card-head"><h3>Taken by tender</h3><span class="small muted">Cash lands in the drawer; the rest are collections</span></div>
      <div class="card-body table-wrap"><table class="table table-compact" id="dayTenders">
        <thead><tr><th>Tender</th><th class="num">Rows</th><th class="num">Amount</th></tr></thead>
        <tbody>
        @forelse($report['tenders'] as $name => $row)
          <tr><td>{{ $name }}@if($row['is_cash']) <span class="badge badge-green">drawer</span>@endif</td><td class="num">{{ $row['count'] }}</td><td class="num">{{ money($row['amount'], false) }}</td></tr>
        @empty<tr><td colspan="3" class="muted center">Nothing taken on this date.</td></tr>@endforelse
        </tbody>
        <tfoot>
          <tr><td class="right" colspan="2">Collections</td><td class="num bold">{{ money($report['total']) }}</td></tr>
          @if($report['room_total'] > 0)<tr><td class="right" colspan="2">Charged to rooms (not collected here)</td><td class="num">{{ money($report['room_total']) }}</td></tr>@endif
        </tfoot>
      </table></div>
    </div>

    <div class="card flush mt-2"><div class="card-head"><h3>Revenue by category</h3></div>
      <div class="card-body table-wrap"><table class="table table-compact" id="dayCategories">
        <thead><tr><th>Category</th><th class="num">Qty</th><th class="num">Net</th><th class="num">Tax</th><th class="num">Total</th></tr></thead>
        <tbody>
        @forelse($report['by_category'] as $key => $row)
          <tr><td>{{ \App\Models\ClubTicketType::CATEGORIES[$key] ?? ucfirst($key) }}</td><td class="num">{{ $row['qty'] }}</td>
            <td class="num">{{ money($row['net'], false) }}</td><td class="num">{{ money($row['tax'], false) }}</td><td class="num bold">{{ money($row['net'] + $row['tax'], false) }}</td></tr>
        @empty<tr><td colspan="5" class="muted center">No sales.</td></tr>@endforelse
        </tbody>
      </table></div>
    </div>

    <div class="card flush mt-2"><div class="card-head"><h3>What sold</h3></div>
      <div class="card-body table-wrap"><table class="table table-compact" id="dayTypes">
        <thead><tr><th>Ticket / item</th><th class="num">Qty</th><th class="num">Net</th><th class="num">Tax</th><th class="num">Total</th></tr></thead>
        <tbody>
        @forelse($report['by_type'] as $name => $row)
          <tr><td>{{ $name }}</td><td class="num">{{ $row['qty'] }}</td><td class="num">{{ money($row['net'], false) }}</td><td class="num">{{ money($row['tax'], false) }}</td><td class="num bold">{{ money($row['total'], false) }}</td></tr>
        @empty<tr><td colspan="5" class="muted center">Nothing sold.</td></tr>@endforelse
        </tbody>
      </table></div>
    </div>

    <div class="card flush mt-2"><div class="card-head"><h3>Shifts and drawers</h3></div>
      <div class="card-body table-wrap"><table class="table table-compact" id="dayShifts">
        <thead><tr><th>Cashier</th><th>Shift</th><th class="num">Sales</th><th class="num">Takings</th><th class="num">Expected</th><th class="num">Counted</th><th class="num">Variance</th></tr></thead>
        <tbody>
        @forelse($report['shifts'] as $row)
          @php $s = $row['session']; @endphp
          <tr class="{{ $s->status === 'open' ? 'row-amber' : '' }}">
            <td>{{ $s->user?->name ?? '—' }}@if($s->status === 'open') <span class="badge badge-amber">open</span>@endif</td>
            <td class="small">{{ $s->shift }}<div class="tiny muted">{{ $s->opened_at->format('H:i') }}{{ $s->closed_at ? ' – '.$s->closed_at->format('H:i') : '' }}</div></td>
            <td class="num">{{ $row['summary']['sales'] ?? '—' }}</td>
            <td class="num">{{ isset($row['summary']['total']) ? money($row['summary']['total'], false) : '—' }}</td>
            <td class="num">{{ $s->expected_cash !== null ? money($s->expected_cash, false) : ($row['summary'] ? money($row['summary']['expected_cash'], false) : '—') }}</td>
            <td class="num">{{ $s->counted_cash !== null ? money($s->counted_cash, false) : '—' }}</td>
            <td class="num {{ $s->variance !== null && abs((float) $s->variance) > 0.009 ? 'text-red' : '' }}">{{ $s->variance !== null ? money($s->variance, false) : '—' }}</td>
          </tr>
        @empty<tr><td colspan="7" class="muted center">No shifts were opened on this date.</td></tr>@endforelse
        </tbody>
      </table></div>
    </div>
  </div>

  <div>
    <div class="card"><div class="card-head"><h3>Day close</h3></div><div class="card-body">
      <div class="kv-grid">
        <div><span>Sales</span><b>{{ $report['sales'] }}</b></div>
        <div><span>Tickets</span><b>{{ $report['tickets'] }}</b></div>
        <div><span>Items</span><b>{{ $report['items'] }}</b></div>
        <div><span>Net</span><b>{{ money($report['net']) }}</b></div>
        <div><span>Tax</span><b>{{ money($report['tax']) }}</b></div>
        <div><span>Total</span><b>{{ money($report['total']) }}</b></div>
        <div><span>Cash</span><b>{{ money($report['cash_in']) }}</b></div>
        <div><span>Change</span><b>{{ money($report['change']) }}</b></div>
        <div><span>Room charges</span><b>{{ money($report['room_total']) }}</b></div>
        <div><span>Voids</span><b>{{ $report['void_sales'] }} · {{ money($report['void_total']) }}</b></div>
        <div><span>Admissions</span><b>{{ $report['admissions']['admitted'] }} of {{ $report['admissions']['issued'] }} ticket(s)</b></div>
        <div><span>Guests through the gate</span><b>{{ $report['admissions']['pax_admitted'] }} of {{ $report['admissions']['pax_issued'] }}</b></div>
      </div>
      <p class="tiny muted m-0 mt-1">
        Tax components on outlet charges: @forelse($report['components'] as $code => $rate){{ (\App\Services\TaxService::COMPONENTS[$code][0] ?? $code).' '.rtrim(rtrim(number_format($rate, 2), '0'), '.').'%' }}@if(! $loop->last) + @endif @empty none configured @endforelse
      </p>
    </div></div>

    <div class="card mt-2"><div class="card-head"><h3>By cashier</h3></div><div class="card-body table-wrap"><table class="table table-compact" id="dayCashiers">
      <thead><tr><th>Cashier</th><th class="num">Sales</th><th class="num">Takings</th><th class="num">Change</th></tr></thead>
      <tbody>
      @forelse($report['cashiers'] as $row)
        <tr><td>{{ $row['name'] }}</td><td class="num">{{ $row['sales'] }}</td><td class="num">{{ money($row['total'], false) }}</td><td class="num">{{ money($row['change'], false) }}</td></tr>
      @empty<tr><td colspan="4" class="muted center">Nothing sold.</td></tr>@endforelse
      </tbody>
    </table></div></div>
  </div>
</div>

<div class="card flush mt-2"><div class="card-head"><h3>Sales on {{ dmy($report['date']) }}</h3><span class="small muted">{{ $report['sales'] }} paid · {{ $report['void_sales'] }} voided</span></div>
  <div class="card-body table-wrap"><table class="table table-compact" id="daySales">
    <thead><tr><th>Sale</th><th>Time</th><th>Guest</th><th>Cashier</th><th>Settled by</th><th class="num">Total</th><th>Status</th></tr></thead>
    <tbody>
    @forelse($report['sales_list'] as $sale)
      <tr class="{{ $sale->isVoid() ? 'muted' : '' }}">
        <td><a href="{{ route('recreation.receipt', $sale) }}" target="_blank">{{ $sale->sale_no }}</a></td>
        <td class="nowrap small">{{ $sale->sold_at->format('H:i') }}</td>
        <td>{{ $sale->guest_name ?: 'Walk-in' }}</td>
        <td class="small">{{ $sale->user?->name ?? '—' }}</td>
        <td class="small">@if($sale->stay)Room {{ $sale->stay->room?->room_no }} @else {{ $sale->payments->pluck('paymentMode.name')->filter()->unique()->implode(' + ') ?: '—' }} @endif</td>
        <td class="num">{{ money($sale->total, false) }}</td>
        <td><span class="badge badge-{{ $sale->isVoid() ? 'red' : 'green' }}">{{ $sale->isVoid() ? 'Void' : 'Paid' }}</span></td>
      </tr>
    @empty
      <tr><td colspan="7"><x-empty text="No outlet sales on this date." icon="receipt"/></td></tr>
    @endforelse
    </tbody>
  </table></div>
</div>
@endsection
