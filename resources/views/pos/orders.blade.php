@extends('layouts.app')
@section('title', 'POS Orders & Bills')
@section('content')
@include('pos._workspace-nav')
<div class="card pos-orders-filter-card">
  <div class="card-body">
    <form class="filters pos-orders-filters" method="get" action="{{ route('pos.orders') }}" aria-label="Filter POS orders and bills">
      <div class="field"><label for="posBillsFrom">From date</label><input id="posBillsFrom" type="date" name="from" value="{{ $from }}"></div>
      <div class="field"><label for="posBillsTo">To date</label><input id="posBillsTo" type="date" name="to" value="{{ $to }}"></div>
      <div class="field pos-orders-search"><label for="posBillsSearch">Search orders and bills</label><input id="posBillsSearch" type="search" name="q" value="{{ $q }}" placeholder="Order, bill, customer, phone or waiter" aria-describedby="posBillsSearchHint"><span id="posBillsSearchHint" class="tiny muted">Searches order and bill numbers, customer details, and waiter.</span></div>
      <div class="field"><label for="posBillsItem">Menu item</label><input id="posBillsItem" type="search" name="item_name" value="{{ request('item_name') }}" placeholder="Exact item name"></div>
      <div class="field"><label for="posBillsOutlet">Outlet</label><select id="posBillsOutlet" name="outlet"><option value="">All outlets</option>@foreach($outlets as $id => $name)<option value="{{ $id }}" @selected(request('outlet') == $id)>{{ $name }}</option>@endforeach</select></div>
      <div class="field"><label for="posBillsPaymentMode">Payment mode</label><select id="posBillsPaymentMode" name="payment_mode_id"><option value="">All modes</option>@foreach($paymentModes as $id => $name)<option value="{{ $id }}" @selected(request('payment_mode_id') == $id)>{{ $name }}</option>@endforeach</select></div>
      <div class="field"><label for="posBillsOrderType">Order type</label><select id="posBillsOrderType" name="order_type"><option value="">All types</option>@foreach(['dine_in' => 'Dine-in', 'room_service' => 'Room service', 'delivery' => 'Delivery', 'takeaway' => 'Take Away', 'other' => 'Other'] as $type => $label)<option value="{{ $type }}" @selected(request('order_type') === $type)>{{ $label }}</option>@endforeach</select></div>
      <div class="field"><label for="posBillsComps">Complimentary</label><select id="posBillsComps" name="with_comps"><option value="">All checks</option><option value="1" @selected(request('with_comps') === '1')>Checks with comps</option></select></div>
      <div class="field"><label for="posBillsServed">Service time</label><select id="posBillsServed" name="with_served"><option value="">All checks</option><option value="1" @selected(request('with_served') === '1')>With served items</option></select></div>
      <div class="field"><label for="posBillsStatus">Status</label><select id="posBillsStatus" name="status"><option value="">All statuses</option><option value="unsettled" @selected(request('status') === 'unsettled')>Unsettled (open / billed)</option>@foreach(['open', 'billed', 'settled', 'void'] as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ $status === 'void' ? 'Voided' : ucfirst($status) }}</option>@endforeach</select></div>
      <div class="pos-orders-filter-actions"><button class="btn btn-primary"><x-icon name="search" size="sm"/> Apply filters</button><a class="btn btn-ghost" href="{{ route('pos.orders') }}">Reset</a></div>
    </form>
  </div>
</div>
<div class="stats">
  <x-stat label="Bills settled" :value="$summary->settled_count" icon="receipt" tone="blue"/>
  <x-stat label="Sales excl. tax" :value="money($summary->sales_ex_tax)" icon="money" tone="green"/>
  <x-stat label="Tax" :value="money($summary->tax_total)" icon="chart" tone="purple"/>
  <x-stat label="Posted to rooms" :value="money($summary->room_post_total)" icon="bed" tone="teal"/>
</div>
<div class="card flush pos-orders-register">
  <div class="card-head pos-orders-register-head"><div><h2>Orders and bills</h2><p id="posOrdersCount" class="small muted">Showing {{ $orders->firstItem() ?? 0 }}–{{ $orders->lastItem() ?? 0 }} of {{ $orders->total() }} result{{ $orders->total() === 1 ? '' : 's' }} for {{ $from }} to {{ $to }}.</p></div></div>
  <div class="card-body table-wrap"><table class="table pos-orders-table" aria-describedby="posOrdersCount">
    <caption class="sr-only">POS orders and bills matching the selected date, search, outlet, payment mode, order type, menu item, comp, service time and status filters.</caption>
    <thead><tr><th scope="col">Order</th><th scope="col">Bill</th><th scope="col">Guest</th><th scope="col">Business date / time</th><th scope="col">Outlet</th><th scope="col">Table / room</th><th scope="col">Settlement</th><th scope="col" class="num">Discount</th><th scope="col" class="num">Tax</th><th scope="col" class="num">Total / due</th><th scope="col">Status</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
      @forelse($orders as $o)
        <tr>
          <td><a class="pos-order-number" href="{{ route('pos.terminal', $o) }}">{{ $o->order_no }}</a></td>
          <td>{{ $o->bill_no ?: '—' }}</td>
          <td class="pos-order-guest">{{ $o->customer_name ?: '—' }}@if($o->source === 'qr')<small>QR order</small>@elseif($o->source === 'portal')<small>Guest portal</small>@elseif($o->source === 'tv')<small>Room TV</small>@endif</td>
          <td class="nowrap"><time datetime="{{ $o->business_date->format('Y-m-d') }}">{{ $o->business_date->format('d M Y') }}</time><span class="tiny muted"> · <time datetime="{{ $o->created_at->toIso8601String() }}">{{ $o->created_at->format('H:i') }}</time></span></td>
          <td>{{ $o->outlet->name }}</td>
          <td>{{ $o->table?->table_no ? 'Table '.$o->table->table_no : ($o->stay ? 'Room '.$o->stay->room?->room_no : ucfirst(str_replace('_', ' ', $o->order_type))) }}</td>
          <td class="pos-order-settlement">{{ $o->settlement ? ucwords(str_replace('_', ' ', $o->settlement)) : '—' }}@if($o->settlement === 'payment')<span class="tiny muted"> · {{ $o->payments->where('kind', 'payment')->map(fn ($p) => $p->paymentMode?->name)->filter()->unique()->join(', ') ?: ($o->paymentMode?->name ?? 'Payment') }}</span>@endif</td>
          <td class="num">{{ money($o->discount, false) }}</td><td class="num">{{ money($o->tax, false) }}</td>
          @php $received = (float) $o->payments->where('kind', 'payment')->sum('amount') - (float) $o->payments->where('kind', 'refund')->sum('amount'); $billBalance = round(max(0, (float) $o->total - $received), 2); $showBalance = ! in_array($o->status, ['settled', 'void'], true) && ! in_array($o->settlement, ['room_post', 'complimentary'], true) && $billBalance > 0.005; @endphp
          <td class="num bold pos-order-total">{{ money($o->total, false) }}@if($showBalance)<small class="pos-order-balance-due">Due {{ money($billBalance, false) }}</small>@endif</td>
          <td>{!! status_badge($o->status) !!}</td>
          <td><a class="btn btn-sm btn-ghost" target="_blank" rel="noopener noreferrer" href="{{ route('pos.print', $o) }}" aria-label="Print bill {{ $o->bill_no ?: $o->order_no }}" title="Print bill"><x-icon name="print" size="sm"/><span class="sr-only">Print bill {{ $o->bill_no ?: $o->order_no }}</span></a></td>
        </tr>
      @empty
        <tr><td colspan="12"><x-empty text="No orders match these filters." icon="cup"/><div class="pos-orders-empty-action"><a class="btn btn-sm btn-ghost" href="{{ route('pos.orders') }}">Reset filters</a></div></td></tr>
      @endforelse
    </tbody>
  </table></div>
  @if($orders->hasPages())<div class="card-foot pos-orders-pagination">{{ $orders->links('partials.pagination') }}</div>@endif
</div>
@endsection
