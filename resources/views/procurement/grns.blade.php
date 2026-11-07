@extends('layouts.app')
@section('title', 'Goods Receipts (GRN)')
@section('crumbs') Inventory / Goods Receipts @endsection
@section('actions')
<a class="btn" href="{{ route('purchases.index') }}"><x-icon name="cart"/> Purchase orders</a>
<a class="btn" href="{{ route('ap.bills') }}"><x-icon name="receipt"/> Supplier bills</a>
@endsection
@section('content')

{{-- v9.16 a receipt is a claim on the payables ledger from the day the goods are accepted, so the header carries what is still unclaimed --}}
<div class="stats">
  <x-stat label="Receipts" :value="(int) $tot->n" icon="box" tone="blue" sub="arrivals in this window, part deliveries included"/>
  <x-stat label="Accepted value" :value="money($tot->v ?? 0)" icon="check" tone="green" sub="what the shelf took in, any tax on the delivery included"/>
  <x-stat label="Rejected & returned" :value="money($tot->r ?? 0)" icon="alert" tone="red" href="?rejections=1&from={{ $from }}&to={{ $to }}" sub="turned away at the door and not billed"/>
  <x-stat label="Waiting for a bill" :value="money($billing['waiting_value'])" icon="receipt" :tone="$billing['waiting'] ? 'amber' : 'green'" href="?waiting=1&from={{ $from }}&to={{ $to }}"
    :sub="$billing['waiting'] ? $billing['waiting'].' receipt'.($billing['waiting'] === 1 ? '' : 's').' on record with no bill behind '.($billing['waiting'] === 1 ? 'it' : 'them').($billing['oldest'] ? ' · oldest '.$billing['oldest'].' day'.($billing['oldest'] === 1 ? '' : 's') : '') : 'every receipt on record has reached its bill'"/>
</div>

{{-- v9.16 the bill: the receipt's value has to reach Accounts Payable, or the goods are on the shelf and nowhere on the ledger --}}
<div class="card" id="grnBilling">
  <div class="card-head"><h3>Has every receipt reached a bill?</h3>
    <span class="muted small">{{ $billing['billed'] }} billed in the last ninety days · {{ $billing['waiting'] ? $billing['waiting'].' waiting' : 'nothing waiting' }}</span></div>
  <div class="card-body">
    <div class="stats" style="margin:0">
      <x-stat label="Received and billed" :value="money($billing['billed_value'])" icon="check" tone="slate"
        :sub="$billing['billed'].' receipt'.($billing['billed'] === 1 ? '' : 's').' in the last ninety days, each with its purchase order’s bill'"/>
      <x-stat label="Received, no bill" :value="money($billing['waiting_value'])" icon="receipt" :tone="$billing['waiting'] ? 'red' : 'green'"
        :sub="$billing['waiting'].' receipt'.($billing['waiting'] === 1 ? '' : 's').' on record — the goods are on the shelf and nothing is on the payables ledger'"/>
      <x-stat label="Oldest wait" :value="$billing['oldest'] ? $billing['oldest'].' day'.($billing['oldest'] === 1 ? '' : 's') : '—'" icon="clock" :tone="$billing['oldest'] >= 30 ? 'red' : 'slate'"
        sub="age of the oldest receipt still without a bill"/>
    </div>
    @if($billing['waiting'] > 0)
      <p class="small text-red" id="grnBillingState" style="margin:.6rem 0 0"><x-icon name="alert"/>{{ $billing['waiting'] }} receipt{{ $billing['waiting'] === 1 ? '' : 's' }} worth
        {{ money($billing['waiting_value']) }} {{ $billing['waiting'] === 1 ? 'is' : 'are' }} in stock with no bill behind {{ $billing['waiting'] === 1 ? 'it' : 'them' }}.
        The oldest is {{ $billing['oldest_row']['grn_no'] }} from {{ $billing['oldest_row']['supplier'] }} — {{ money($billing['oldest_row']['value']) }} received on
        {{ dmy($billing['oldest_row']['date']) }} and unbilled for {{ $billing['oldest_row']['days'] }} day{{ $billing['oldest_row']['days'] === 1 ? '' : 's' }}.
        The supplier will invoice {{ $billing['waiting'] === 1 ? 'it' : 'them' }}; until then the payables ledger does not know the goods exist, and the supplier
        statement will disagree with their own books.</p>
      <div class="table-wrap" style="margin-top:.6rem"><table class="table table-compact" id="billingGap">
        <thead><tr><th>GRN</th><th>Supplier</th><th>Store</th><th>Received</th><th class="num">Waiting</th><th class="num">Value</th></tr></thead><tbody>
        @foreach($billing['rows'] as $r)<tr data-gap="billing">
          <td class="bold">{{ $r['grn_no'] }}</td><td class="small">{{ $r['supplier'] }}</td><td class="small">{{ $r['store'] }}</td>
          <td class="small">{{ dmy($r['date']) }}</td><td class="num">{{ $r['days'] }} d</td><td class="num text-red">{{ money($r['value'], false) }}</td></tr>@endforeach
        </tbody></table></div>
    @else
      <p class="small" id="grnBillingState" style="margin:.6rem 0 0"><x-icon name="check"/>Every receipt on record has reached a bill: {{ $billing['billed'] }} receipts,
        {{ money($billing['billed_value']) }} of goods that are both on the shelf and on the payables ledger.
        @if($billing['slowest'])
          The longest wait in the last ninety days was {{ $billing['slowest']['grn_no'] }} from {{ $billing['slowest']['supplier'] }} —
          {{ money($billing['slowest']['value']) }} received on {{ dmy($billing['slowest']['date']) }} and billed {{ $billing['slowest']['wait'] }} day{{ $billing['slowest']['wait'] === 1 ? '' : 's' }} later
          on {{ $billing['slowest']['bill'] }}. Nothing is sitting unbilled.
        @else
          No receipt has been billed yet in the last ninety days, so there is no wait to report.
        @endif
      </p>
    @endif
  </div>
</div>

{{-- v9.16 the delivery: lateness is the PO's expected date against the day the goods arrived, valued on the goods, not the tax --}}
<div class="card" id="grnDelivery">
  <div class="card-head"><h3>Did the goods come when the purchase order said?</h3>
    <span class="muted small">{{ $late['late'] }} of {{ $late['receipts'] }} late · {{ $late['share'] }} % of the value</span></div>
  <div class="card-body">
    <div class="stats" style="margin:0">
      <x-stat label="On time" :value="money($late['value'] - $late['late_value'])" icon="check" tone="slate"
        :sub="($late['receipts'] - $late['late']).' receipt'.(($late['receipts'] - $late['late']) === 1 ? '' : 's').' on or before the expected date'"/>
      <x-stat label="Late" :value="money($late['late_value'])" icon="clock" :tone="$late['late'] ? 'amber' : 'green'"
        :sub="$late['late'].' receipt'.($late['late'] === 1 ? '' : 's').' after the date the purchase order named'"/>
      <x-stat label="Still to deliver" :value="$late['overdue']" icon="cart" :tone="$late['overdue'] ? 'red' : 'green'"
        sub="purchase orders past their expected date with nothing received"/>
    </div>
    @if($late['late'] > 0)
      <p class="small" id="grnDeliveryState" style="margin:.6rem 0 0"><x-icon name="clock"/>{{ $late['late'] }} of the {{ $late['receipts'] }} receipts in this window came after the date
        their purchase order named, {{ money($late['late_value']) }} of the {{ money($late['value']) }} accepted. The worst is {{ $late['worst']['grn_no'] }} from
        {{ $late['worst']['supplier'] }} — {{ money($late['worst']['value']) }} arrived {{ $late['worst']['days'] }} day{{ $late['worst']['days'] === 1 ? '' : 's' }} after
        {{ dmy($late['worst']['expected']) }}. Late goods are not a ledger problem, but they are the supplier’s side of the scorecard, and they are the reason a
        service ran short.</p>
      <div class="table-wrap" style="margin-top:.6rem"><table class="table table-compact" id="lateGap">
        <thead><tr><th>GRN</th><th>Supplier</th><th>Expected</th><th>Received</th><th class="num">Late</th><th class="num">Goods value</th></tr></thead><tbody>
        @foreach($late['rows'] as $r)<tr data-gap="late">
          <td class="bold">{{ $r['grn_no'] }}</td><td class="small">{{ $r['supplier'] }}</td>
          <td class="small">{{ dmy($r['expected']) }}</td><td class="small">{{ dmy($r['date']) }}</td>
          <td class="num text-red">{{ $r['days'] }} d</td><td class="num">{{ money($r['value'], false) }}</td></tr>@endforeach
        </tbody></table></div>
    @else
      <p class="small" id="grnDeliveryState" style="margin:.6rem 0 0"><x-icon name="check"/>Every one of the {{ $late['receipts'] }} receipts in this window arrived on or before
        the date its purchase order named, {{ money($late['value']) }} of goods. Nothing here is late.</p>
    @endif
  </div>
</div>

{{-- v9.16 the shelf: the accepted lines must be the stock movements the receipt wrote, found again by GRN number --}}
<div class="card" id="grnStock">
  <div class="card-head"><h3>Is the accepted goods actually in stock?</h3>
    <span class="muted small">{{ $stock['breaks'] ? $stock['breaks'].' of '.$stock['receipts'].' receipts unmatched' : 'all '.$stock['receipts'].' receipts in stock' }} · {{ money($stock['goods']) }} accepted</span></div>
  <div class="card-body">
    <div class="stats" style="margin:0">
      <x-stat label="Accepted, before tax" :value="money($stock['goods'])" icon="box" tone="slate" sub="the goods value on the receipts in this window"/>
      <x-stat label="In stock" :value="money($stock['moved'])" icon="check" tone="slate" sub="the purchase movements those receipts wrote"/>
      <x-stat label="Difference" :value="money($stock['diff'])" icon="alert" :tone="$stock['breaks'] ? 'red' : 'green'"
        :sub="$stock['breaks'] ? 'goods accepted and never stocked' : 'every accepted line is on the shelf'"/>
    </div>
    @if($stock['breaks'] === 0)
      <p class="small" id="grnStockState" style="margin:.6rem 0 0"><x-icon name="check"/>All {{ $stock['receipts'] }} receipts in this window are in stock, {{ money($stock['goods']) }} of goods
        matched to the movements they wrote. The largest, {{ $stock['largest']['grn_no'] }} from {{ $stock['largest']['supplier'] }} — {{ money($stock['largest']['value']) }} into the
        {{ $stock['largest']['store'] }} — is there with the rest.</p>
    @else
      <p class="small text-red" id="grnStockState" style="margin:.6rem 0 0"><x-icon name="alert"/>{{ $stock['breaks'] }} receipt{{ $stock['breaks'] === 1 ? '' : 's' }} accepted
        {{ money(abs($stock['diff'])) }} of goods that no stock movement carries, so the shelf is short of what the paperwork says it took in.
        The supplier will invoice {{ $stock['breaks'] === 1 ? 'the receipt' : 'those receipts' }}, the bill{{ $stock['breaks'] === 1 ? '' : 's' }} will be posted, and the stock value
        behind {{ $stock['breaks'] === 1 ? 'it' : 'them' }} will not exist.</p>
      <div class="table-wrap" style="margin-top:.6rem"><table class="table table-compact" id="stockGap">
        <thead><tr><th>GRN</th><th>Supplier</th><th>Store</th><th>Received</th><th class="num">Accepted</th><th class="num">In stock</th><th class="num">Difference</th></tr></thead><tbody>
        @foreach($stock['rows'] as $r)<tr data-gap="stock">
          <td class="bold">{{ $r['grn_no'] }}</td><td class="small">{{ $r['supplier'] }}</td><td class="small">{{ $r['store'] }}</td>
          <td class="small">{{ dmy($r['date']) }}</td><td class="num">{{ money($r['value'], false) }}</td>
          <td class="num">{{ money($r['moved'], false) }}</td><td class="num text-red">{{ money($r['diff'], false) }}</td></tr>@endforeach
        </tbody></table></div>
    @endif
  </div>
</div>

@if($overdue->count())
<div class="card" id="grnOverdue"><div class="card-head"><h3>Deliveries overdue</h3><span class="badge badge-red">{{ $overdue->count() }}</span></div><div class="card-body table-wrap"><table class="table table-compact"><tbody>
@foreach($overdue as $po)<tr><td><a class="bold" href="{{ route('purchases.show', $po) }}">{{ $po->po_no }}</a></td><td>{{ $po->supplier->name }}</td><td>{!! status_badge($po->status) !!}</td><td class="small">expected {{ $po->expected_date->format('d M Y') }}</td><td class="text-red small">{{ $po->expected_date->diffInDays(bdate()) }} day(s) late</td></tr>@endforeach
</tbody></table></div></div>
@endif

<form class="card" method="get"><div class="card-body flex gap-1 wrap items-end">
  <div class="field"><label>From</label><input type="date" name="from" value="{{ $from }}" class="input"></div><div class="field"><label>To</label><input type="date" name="to" value="{{ $to }}" class="input"></div>
  <div class="field"><label>Supplier</label><select name="supplier" class="input"><option value="">All</option>@foreach($suppliers as $id => $n)<option value="{{ $id }}" @selected(request('supplier') == $id)>{{ $n }}</option>@endforeach</select></div>
  <label class="check"><input type="checkbox" name="rejections" value="1" @checked(request()->boolean('rejections'))> <span>With rejections only</span></label>
  <label class="check"><input type="checkbox" name="waiting" value="1" @checked(request()->boolean('waiting'))> <span>Waiting for a bill only</span></label>
  <button class="btn">Show</button></div></form>

<div class="card flush"><div class="card-body table-wrap"><table class="table" id="grnTable">
<thead><tr><th>GRN</th><th>Date</th><th>PO</th><th>Supplier</th><th>Store</th><th>Delivery note</th><th class="num">Accepted</th><th class="num">Rejected</th><th>Bill</th><th>Stock</th><th>By</th><th></th></tr></thead><tbody>
@forelse($grns as $g)<tr data-billed="{{ $g->bill ? 1 : 0 }}" data-stocked="{{ $g->stocked ? 1 : 0 }}"><td class="bold">{{ $g->grn_no }}</td><td>{{ $g->date->format('d M Y') }}@if(! $g->onTime()) <span class="badge badge-amber">late</span>@endif</td><td><a href="{{ route('purchases.show', $g->purchase_order_id) }}">{{ $g->order->po_no }}</a></td><td>{{ $g->supplier->name }}</td><td>{{ $g->store->name }}</td><td class="small">{{ $g->delivery_note ?: '—' }}</td>
  <td class="num">{{ money($g->total, false) }}</td><td class="num {{ (float) $g->rejected_value ? 'text-red' : 'muted' }}">{{ (float) $g->rejected_value ? money($g->rejected_value, false) : '—' }}</td>
  <td>@if($g->bill)<a class="small" href="{{ route('ap.bills.show', $g->bill) }}">{{ $g->bill->doc_no }}</a>@else<span class="badge badge-amber">{{ $g->waiting_days ? 'waiting '.$g->waiting_days.' d' : 'no bill yet' }}</span>@endif</td>
  <td>@if($g->stocked)<span class="badge badge-green">in stock</span>@else<span class="badge badge-red">not in stock</span>@endif</td>
  <td class="small">{{ $g->receiver?->name }}</td>
  <td><a class="btn btn-sm" href="{{ route('grn.print', $g) }}?autoprint=1" target="_blank"><x-icon name="print" size="sm"/></a></td></tr>
@empty<tr><td colspan="12"><x-empty text="No goods receipts in this period." icon="box"/></td></tr>@endforelse
</tbody></table>{{ $grns->links() }}</div></div>

<p class="small muted" id="grnChain" style="margin:.8rem 0 0">The chain these receipts start: a bill is raised against the purchase order (not the receipt), it is
  matched to what was received, and only then can it be paid — so the bill screen’s “goods received, not yet invoiced” list and this card ask the same question at two
  levels, one per purchase order and one per delivery. A receipt that never reaches a bill keeps the supplier’s money off the ledger; one that reaches a bill without
  a stock movement puts money on the ledger for goods that are not on the shelf.</p>
@endsection
