@extends('layouts.app')
@section('title', 'POS Dashboard')
@section('content')
@include('pos._workspace-nav')
@php
  $billFilters = ['from' => $from, 'to' => $to];
  if ($selectedOutlet) $billFilters['outlet'] = $selectedOutlet->id;
  $allOrdersUrl = route('pos.orders', $billFilters);
  $settledBillsUrl = route('pos.orders', $billFilters + ['status' => 'settled']);
  $voidedBillsUrl = route('pos.orders', $billFilters + ['status' => 'void']);
  $compBillsUrl = route('pos.orders', $billFilters + ['status' => 'settled', 'with_comps' => '1']);
  $servedBillsUrl = route('pos.orders', $billFilters + ['with_served' => '1']);
  $previousPeriodLabel = \Illuminate\Support\Carbon::parse($previousFrom)->format('d M Y').' – '.\Illuminate\Support\Carbon::parse($previousTo)->format('d M Y');
@endphp
<div class="pos-dash">
  <header class="pos-dash-header">
    <div class="pos-dash-title"><span class="pos-dash-eyebrow"><x-icon name="chart" size="sm"/> FOOD &amp; BEVERAGE</span><h1>POS Dashboard</h1><p>Sales, collections and control activity across your outlets.</p></div>
    <form class="pos-dash-filters" method="get" action="{{ route('pos.dashboard') }}" aria-label="Filter POS dashboard">
      <label><span>Outlet</span><select name="outlet"><option value="">All outlets</option>@foreach($outlets as $option)<option value="{{ $option->id }}" @selected($selectedOutlet?->id === $option->id)>{{ $option->name }}</option>@endforeach</select></label>
      <label><span>From</span><input type="date" name="from" value="{{ $from }}" required></label>
      <label><span>To</span><input type="date" name="to" value="{{ $to }}" required></label>
      <button class="btn btn-primary" type="submit"><x-icon name="search" size="sm"/> Apply</button>
      <small class="pos-dash-range-hint">Up to 31 days</small>
      <div class="pos-dash-quick-ranges" role="group" aria-label="Quick date ranges ending on the selected To date">
        <span>Quick range</span>
        <button class="btn btn-sm{{ $rangeDays === 1 ? ' btn-soft' : '' }}" type="submit" name="range_days" value="1" formnovalidate aria-pressed="{{ $rangeDays === 1 ? 'true' : 'false' }}">1 day</button>
        <button class="btn btn-sm{{ $rangeDays === 7 ? ' btn-soft' : '' }}" type="submit" name="range_days" value="7" formnovalidate aria-pressed="{{ $rangeDays === 7 ? 'true' : 'false' }}">7 days</button>
        <button class="btn btn-sm{{ $rangeDays === 30 ? ' btn-soft' : '' }}" type="submit" name="range_days" value="30" formnovalidate aria-pressed="{{ $rangeDays === 30 ? 'true' : 'false' }}">30 days</button>
      </div>
    </form>
  </header>

  <div class="pos-dash-period-label"><span>{{ $selectedOutlet?->name ?? 'All outlets' }}</span><span>{{ \Illuminate\Support\Carbon::parse($from)->format('d M Y') }} – {{ \Illuminate\Support\Carbon::parse($to)->format('d M Y') }}</span><span>{{ $rangeDays }} {{ $rangeDays === 1 ? 'day' : 'days' }}</span><span class="pos-dash-compare-period">Compared with {{ $previousPeriodLabel }}</span></div>

  <section class="pos-dash-stats" aria-label="POS summary for selected period">
    <article class="pos-dash-stat stat-sales"><span class="pos-dash-stat-icon"><x-icon name="chart"/></span><div><span class="pos-dash-stat-label" title="Sum of settled POS bill totals after bill-level discounts">Total sales<span class="sr-only">: sum of settled POS bill totals after bill-level discounts.</span></span><strong>{{ money_short($summary->total_sales ?? 0) }}</strong>@include('pos._kpi-change', ['change' => $kpiChanges['sales'], 'previousPeriodLabel' => $previousPeriodLabel])<small><a class="pos-dash-kpi-link" href="{{ $settledBillsUrl }}">Settled bills in selected period</a></small></div></article>
    <article class="pos-dash-stat stat-collections"><span class="pos-dash-stat-icon"><x-icon name="money"/></span><div><span class="pos-dash-stat-label" title="POS payments less refunds; city-ledger postings are excluded">Collections<span class="sr-only">: POS payments less refunds; city-ledger postings are excluded.</span></span><strong>{{ money_short($collections) }}</strong>@include('pos._kpi-change', ['change' => $kpiChanges['collections'], 'previousPeriodLabel' => $previousPeriodLabel])<small>Payments less refunds</small></div></article>
    <article class="pos-dash-stat stat-orders"><span class="pos-dash-stat-icon"><x-icon name="receipt"/></span><div><span class="pos-dash-stat-label" title="Open, billed and settled checks; voided orders are shown separately">Orders<span class="sr-only">: open, billed and settled checks; voided orders are shown separately.</span></span><strong>{{ number_format((int) ($summary->order_count ?? 0)) }}</strong>@include('pos._kpi-change', ['change' => $kpiChanges['orders'], 'previousPeriodLabel' => $previousPeriodLabel])<small><a class="pos-dash-kpi-link" href="{{ $voidedBillsUrl }}">{{ number_format((int) ($summary->cancelled_count ?? 0)) }} voided</a> · <a class="pos-dash-kpi-link" href="{{ $allOrdersUrl }}">View orders</a></small></div></article>
    <article class="pos-dash-stat stat-comps"><span class="pos-dash-stat-icon"><x-icon name="cup"/></span><div><span class="pos-dash-stat-label" title="Unique settled checks with a complimentary bill or at least one complimentary item">Complimentary<span class="sr-only">: unique settled checks with a complimentary bill or at least one complimentary item.</span></span><strong>{{ number_format($complimentaryChecks) }}</strong>@include('pos._kpi-change', ['change' => $kpiChanges['complimentary'], 'previousPeriodLabel' => $previousPeriodLabel])<small>Checks with bill or item comps · <a class="pos-dash-kpi-link" href="{{ $compBillsUrl }}">View checks</a></small></div></article>
    <article class="pos-dash-stat stat-turnaround"><span class="pos-dash-stat-icon"><x-icon name="clock"/></span><div><span class="pos-dash-stat-label" title="Average time from item entry to serving for completed, non-cancelled items">Turnaround time<span class="sr-only">: average time from item entry to serving for completed, non-cancelled items.</span></span><strong>@if($turnaroundItemsCount > 0){{ number_format($turnaroundMinutes, 2) }} <small>min</small>@else—@endif</strong>@include('pos._kpi-change', ['change' => $kpiChanges['turnaround'], 'previousPeriodLabel' => $previousPeriodLabel, 'noCurrentData' => $turnaroundItemsCount === 0, 'nullChangeLabel' => $previousTurnaroundItemsCount > 0 ? 'N/A' : 'New'])<small>@if($turnaroundItemsCount > 0)Average time · {{ number_format($turnaroundItemsCount) }} served items @else No served items in selected period @endif · <a class="pos-dash-kpi-link" href="{{ $servedBillsUrl }}">View orders</a></small></div></article>
    <article class="pos-dash-stat stat-discounts"><span class="pos-dash-stat-icon"><x-icon name="receipt"/></span><div><span class="pos-dash-stat-label" title="Sum of bill-level discounts on settled POS checks">Discounts<span class="sr-only">: sum of bill-level discounts on settled POS checks.</span></span><strong>{{ money_short($summary->discounts ?? 0) }}</strong>@include('pos._kpi-change', ['change' => $kpiChanges['discounts'], 'previousPeriodLabel' => $previousPeriodLabel])<small><a class="pos-dash-kpi-link" href="{{ $settledBillsUrl }}">{{ number_format((int) ($summary->discounted_invoices ?? 0)) }} discounted invoices · View bills</a></small></div></article>
  </section>

  <section class="pos-dash-leakage" aria-labelledby="posDashLeakageTitle">
    <div class="pos-dash-leakage-heading"><h2 id="posDashLeakageTitle">Leakage watch</h2><p>Things to check for lost revenue</p></div>
    @foreach($leakageWatch as $metric)
      @php
        $leakageFilters = ['from' => $from, 'to' => $to, 'module' => 'pos'];
        if (filled($metric['search'] ?? null)) $leakageFilters['q'] = $metric['search'];
        $leakageUrl = can_do('logs.view') ? route('logs.index', $leakageFilters) : null;
        $leakageTitle = $metric['title'] ?? 'Counted from recorded POS audit events in the selected period.';
      @endphp
      @if($leakageUrl)<a class="pos-dash-leakage-card{{ $metric['increased'] ? ' increased' : '' }}" href="{{ $leakageUrl }}" title="{{ $leakageTitle }}" aria-label="{{ $metric['label'] }}: {{ $metric['count'] }}, was {{ $metric['previous'] }}">@else<div class="pos-dash-leakage-card{{ $metric['increased'] ? ' increased' : '' }}" title="{{ $leakageTitle }}" aria-label="{{ $metric['label'] }}: {{ $metric['count'] }}, was {{ $metric['previous'] }}">@endif
        <span class="pos-dash-leakage-icon"><x-icon name="{{ $metric['icon'] }}" size="sm"/></span>
        <span class="pos-dash-leakage-copy"><span class="pos-dash-leakage-value"><strong>{{ number_format($metric['count']) }}</strong><small>was {{ number_format($metric['previous']) }}</small></span><span class="pos-dash-leakage-label">{{ $metric['label'] }}</span></span>
        @if($leakageUrl)<span class="pos-dash-leakage-chevron" aria-hidden="true">›</span>@endif
      @if($leakageUrl)</a>@else</div>@endif
    @endforeach
  </section>

  <section class="card pos-dash-control" aria-labelledby="posDashControlTitle">
    <div class="pos-dash-control-head"><div><h2 id="posDashControlTitle">Revenue control</h2><p>Review activity that can affect revenue integrity.</p></div><div class="pos-dash-control-tools"><span class="pos-dash-control-range">Selected period</span>@if(can_do('logs.view'))<a class="pos-dash-control-link" href="{{ route('logs.index', ['from' => $from, 'to' => $to, 'module' => 'pos']) }}">View POS audit log <x-icon name="chev" size="sm"/></a>@endif</div></div>
    <div class="pos-dash-control-items">
      @foreach($revenueControls as $control)
        @if(can_do('logs.view'))<a class="pos-dash-control-item" href="{{ route('logs.index', ['from' => $from, 'to' => $to, 'module' => 'pos', 'q' => $control['search']]) }}" title="View {{ strtolower($control['label']) }} in the POS audit log">@else<div class="pos-dash-control-item">@endif
          <span class="pos-dash-control-icon"><x-icon name="{{ $control['icon'] }}" size="sm"/></span><span>{{ $control['label'] }}</span><b>{{ number_format($control['count']) }}</b>
        @if(can_do('logs.view'))</a>@else</div>@endif
      @endforeach
    </div>
  </section>

  <div class="pos-dash-main-grid">
    <section class="card pos-dash-card pos-dash-trend" aria-labelledby="posDashTrendTitle">
      <div class="card-head pos-dash-card-head"><div><h2 id="posDashTrendTitle">Outlet sales · daily trend</h2><p>Settled POS sales by business date and outlet · select a bar to view bills</p></div><span class="pos-dash-chart-range">{{ $rangeDays }} day{{ $rangeDays === 1 ? '' : 's' }}</span></div>
      <div class="pos-dash-chart-shell">
        <div class="pos-dash-y-axis" aria-hidden="true">@foreach($trendAxis as $tick)<span>{{ $tick }}</span>@endforeach</div>
        <div class="pos-dash-plot-scroll"><div class="pos-dash-daily-plot" role="group" aria-label="Daily settled sales stacked by outlet. Select a colored bar to view that outlet's settled bills for the day.">
          <div class="pos-dash-grid-lines" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i></div>
          @unless($hasDailySales)<div class="pos-dash-chart-empty" role="status">No settled sales in this period</div>@endunless
          <div class="pos-dash-day-columns" style="grid-template-columns:repeat({{ max(1, $dailyTrend->count()) }}, minmax(44px, 1fr))">
            @foreach($dailyTrend as $day)
              <div class="pos-dash-day-column" title="{{ \Illuminate\Support\Carbon::parse($day['date'])->format('d M Y') }} · {{ money($day['total']) }}">
                <div class="pos-dash-stacked-bar">
                  @foreach($day['segments'] as $segment)
                    @if($segment['height'] > 0)<a href="{{ route('pos.orders', ['from' => $day['date'], 'to' => $day['date'], 'outlet' => $segment['id'], 'status' => 'settled']) }}" style="height:{{ $segment['height'] }}%;background:{{ $segment['color'] }}" title="{{ $segment['name'] }} · {{ money($segment['sales']) }} · View bills" aria-label="{{ $day['label'] }}, {{ $segment['name'] }}: {{ money($segment['sales']) }}. View settled bills."></a>@endif
                  @endforeach
                </div>
                <b>{{ $day['label'] }}</b>
              </div>
            @endforeach
          </div>
        </div></div>
      </div>
      @if($hasDailySales)
        <div class="pos-dash-legend" aria-label="Outlet legend">
          @foreach($chartOutlets as $outletLegend)<span><i style="background:{{ $outletLegend['color'] }}"></i>{{ $outletLegend['name'] }}</span>@endforeach
        </div>
      @endif
    </section>

    <section class="card pos-dash-card pos-dash-order-type" aria-labelledby="posDashTypeTitle">
      <div class="card-head pos-dash-card-head"><div><h2 id="posDashTypeTitle">Sales by order type</h2><p>Share of settled sales</p></div></div>
      @if($hasOrderTypeSales)
        <div class="pos-dash-donut-wrap">
          <div class="pos-dash-donut" style="background:{{ $donutStyle }}" role="img" aria-label="Sales share by order type"><div class="pos-dash-donut-hole"><strong>{{ money_short($summary->total_sales ?? 0) }}</strong><span>Total sales</span></div></div>
        </div>
        <div class="pos-dash-type-legend">
          @foreach($orderTypes as $type)
            <div class="pos-dash-type-row"><span><i style="background:{{ $type['color'] }}"></i><a class="pos-dash-type-link" href="{{ route('pos.orders', $billFilters + ['order_type' => $type['type'], 'status' => 'settled']) }}">{{ $type['label'] }}</a></span><b>{{ number_format($type['percent'], 1) }}%</b><small>{{ money($type['sales']) }} · {{ number_format($type['checks']) }} checks</small></div>
          @endforeach
        </div>
      @else
        <div class="pos-dash-empty pos-dash-order-type-empty" role="status"><b>No settled sales in this period</b><span>Order type shares appear after bills are settled.</span></div>
      @endif
    </section>
  </div>

  <div class="pos-dash-lower-grid">
    <section class="card pos-dash-card" aria-labelledby="posDashCollectionsTitle">
      <div class="card-head pos-dash-card-head"><div><h2 id="posDashCollectionsTitle">Collections by payment mode</h2><p>Net receipts after refunds</p></div></div>
      @if($paymentMix->isEmpty())
        <div class="pos-dash-empty"><x-icon name="money"/><b>No collections in this period</b><span>Payments will appear here when recorded.</span></div>
      @else
        <div class="pos-dash-payment-list">
          @foreach($paymentMix as $payment)
            <div class="pos-dash-payment-row"><span class="pos-dash-payment-name" title="{{ $payment['name'] }} · {{ number_format($payment['checks']) }} checks">@if($payment['id'])<a href="{{ route('pos.orders', $billFilters + ['payment_mode_id' => $payment['id']]) }}">{{ $payment['name'] }}</a>@else<span>{{ $payment['name'] }}</span>@endif<small>{{ number_format($payment['checks']) }} checks</small></span><span class="pos-dash-payment-track"><i style="width:{{ $payment['width'] }}%"></i></span><b>{{ money($payment['amount']) }}</b></div>
          @endforeach
        </div>
      @endif
    </section>

    <section class="card pos-dash-card" aria-labelledby="posDashTopTitle">
      <div class="card-head pos-dash-card-head"><div><h2 id="posDashTopTitle">Top selling items</h2><p>Highest sales value</p></div></div>
      @if($topItems->isEmpty())<div class="pos-dash-empty"><x-icon name="cup"/><b>No item sales in this period</b><span>Settled item sales will be ranked here.</span></div>
      @else<div class="pos-dash-table-wrap"><table class="pos-dash-items-table"><thead><tr><th>#</th><th>Item</th><th class="num">Amount</th></tr></thead><tbody>@foreach($topItems as $item)<tr><td>{{ $loop->iteration }}</td><td><a class="pos-dash-item-link" href="{{ route('pos.orders', $billFilters + ['item_name' => $item->item_name, 'status' => 'settled']) }}">{{ $item->item_name }}</a><small>{{ number_format((float) $item->qty, 2) }} sold</small></td><td class="num">{{ money($item->sales, false) }}</td></tr>@endforeach</tbody></table></div>@endif
    </section>

    <section class="card pos-dash-card" aria-labelledby="posDashLowTitle">
      <div class="card-head pos-dash-card-head"><div><h2 id="posDashLowTitle">Low selling items</h2><p>Lowest sales value among sold items</p></div></div>
      @if($lowItems->isEmpty())<div class="pos-dash-empty"><x-icon name="cup"/><b>@if($topItems->isEmpty())No item sales in this period @else All sold items are already in the top list @endif</b><span>@if($topItems->isEmpty())Items with sales will be ranked here.@else Not enough unique items for a separate low-selling list.@endif</span></div>
      @else<div class="pos-dash-table-wrap"><table class="pos-dash-items-table"><thead><tr><th>#</th><th>Item</th><th class="num">Amount</th></tr></thead><tbody>@foreach($lowItems as $item)<tr><td>{{ $loop->iteration }}</td><td><a class="pos-dash-item-link" href="{{ route('pos.orders', $billFilters + ['item_name' => $item->item_name, 'status' => 'settled']) }}">{{ $item->item_name }}</a><small>{{ number_format((float) $item->qty, 2) }} sold</small></td><td class="num">{{ money($item->sales, false) }}</td></tr>@endforeach</tbody></table></div>@endif
    </section>
  </div>
</div>
@endsection
