@extends('layouts.app')
@section('title', 'Unsettled POS Invoices')
@section('crumbs') Point of Sale / Unsettled Invoices @endsection
@section('actions')
  @if(can_do('pos.view'))<a class="btn" href="{{ route('pos.index', [], false) }}"><x-icon name="cup"/> POS Terminal</a>
  <a class="btn" href="{{ route('pos.orders', [], false) }}"><x-icon name="receipt"/> Orders &amp; Bills</a>@endif
@endsection
@push('head')
<style>
.pos-unsettled-hero{display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;margin:0 0 1rem}.pos-unsettled-hero h2{margin:0;font-size:1.15rem}.pos-unsettled-hero p{margin:.25rem 0 0;max-width:72ch}
.pos-unsettled-tools{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap}.pos-unsettled-tools .btn{white-space:nowrap}.pos-unsettled-autorefresh{display:inline-flex;align-items:center;gap:.3rem;color:var(--c-muted);font-size:.75rem;white-space:nowrap}.pos-unsettled-autorefresh input{accent-color:var(--c-primary)}.pos-unsettled-refresh-state{font-size:.7rem;color:var(--c-muted)}
.pos-unsettled-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.75rem;margin-bottom:1rem}.pos-unsettled-stat{display:flex;justify-content:space-between;align-items:center;gap:.75rem;padding:.85rem 1rem;background:var(--c-surface);border:1px solid var(--c-border);border-radius:12px;box-shadow:var(--shadow-sm)}.pos-unsettled-stat span{font-size:.78rem;color:var(--c-muted)}.pos-unsettled-stat b{font-size:1.3rem;font-variant-numeric:tabular-nums}.pos-unsettled-stat--due{border-color:#f1c27d;background:linear-gradient(135deg,#fff8ea,#fff)}.pos-unsettled-stat--due b{color:#935300}
.pos-unsettled-by-waiter{margin:0 0 1rem;padding:.8rem;border:1px solid var(--c-border);border-radius:12px;background:var(--c-surface)}.pos-unsettled-by-waiter h3{margin:0 0 .55rem;font-size:.86rem}.pos-unsettled-waiter-chips{display:flex;gap:.5rem;overflow-x:auto;padding:.1rem .05rem .25rem}.pos-unsettled-waiter-chip{flex:0 0 auto;display:flex;flex-direction:column;gap:.15rem;min-width:150px;padding:.55rem .7rem;border:1px solid var(--c-border);border-radius:10px;background:var(--c-bg);color:var(--c-text);text-decoration:none}.pos-unsettled-waiter-chip:hover{border-color:var(--c-primary);background:var(--c-primary-50);text-decoration:none}.pos-unsettled-waiter-chip b{font-size:.8rem}.pos-unsettled-waiter-chip span{font-size:.7rem;color:var(--c-muted)}.pos-unsettled-filter{display:grid;grid-template-columns:minmax(180px,1fr) minmax(170px,1fr) minmax(150px,.8fr) minmax(130px,.65fr) auto;align-items:end;gap:.7rem;padding:1rem;margin-bottom:1rem;border:1px solid var(--c-border);border-radius:12px;background:var(--c-surface)}.pos-unsettled-filter .field{margin:0}.pos-unsettled-filter-actions{display:flex;gap:.4rem;align-items:center}.pos-unsettled-filter input,.pos-unsettled-filter select{width:100%}
.pos-unsettled-register .card-head{align-items:flex-start}.pos-unsettled-summary{margin:.2rem 0 0}.pos-unsettled-table{min-width:1050px}.pos-unsettled-table td{vertical-align:top}.pos-unsettled-invoice a{font-weight:800}.pos-unsettled-subline{display:block;margin-top:.18rem;color:var(--c-muted);font-size:.75rem}.pos-unsettled-waiter{font-weight:700}.pos-unsettled-guest{font-weight:650}.pos-unsettled-age{white-space:nowrap}.pos-unsettled-money{font-variant-numeric:tabular-nums;white-space:nowrap}.pos-unsettled-due{font-size:1rem;font-weight:850;color:#8a4b00}.pos-unsettled-due small{display:block;font-size:.72rem;font-weight:500;color:var(--c-muted)}
.pos-unsettled-settle{min-width:170px}.pos-unsettled-settle>summary{list-style:none;cursor:pointer;display:inline-flex}.pos-unsettled-settle>summary::-webkit-details-marker{display:none}.pos-unsettled-settle[open]>summary{margin-bottom:.55rem}.pos-unsettled-panel{width:min(390px,calc(100vw - 3rem));padding:.75rem;border:1px solid var(--c-border);border-radius:12px;background:var(--c-surface);box-shadow:var(--shadow-md)}.pos-unsettled-panel-head{display:flex;justify-content:space-between;gap:.6rem;margin-bottom:.6rem}.pos-unsettled-panel-head b{font-size:.9rem}.pos-unsettled-panel .field{margin-bottom:.55rem}.pos-unsettled-panel .field:last-child{margin-bottom:0}.pos-unsettled-panel input,.pos-unsettled-panel select{width:100%}.pos-unsettled-panel .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:.55rem}.pos-unsettled-panel .wide{grid-column:1/-1}.pos-unsettled-actions{display:flex;flex-wrap:nowrap;gap:.35rem;margin-top:.7rem}.pos-unsettled-actions .pos-unsettled-bill-form{flex:0 0 auto;margin:0}.pos-unsettled-actions .pos-unsettled-bill-form .btn{width:auto}.pos-unsettled-actions .btn{flex:0 0 auto;justify-content:center;white-space:nowrap}.pos-unsettled-note{margin:.55rem 0 0;color:var(--c-muted);font-size:.74rem}.pos-payment-tabs{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.35rem;margin:.35rem 0 .6rem}.pos-payment-tab{display:flex;min-height:38px;align-items:center;justify-content:center;border:1px solid var(--tab-border);border-radius:9px;background:var(--tab-bg);color:var(--tab-color);font:inherit;font-size:.8rem;font-weight:750;cursor:pointer}.pos-payment-tab[data-mode-tab="cash"]{--tab-bg:#ecfdf3;--tab-border:#86efac;--tab-color:#166534;--tab-active:#15803d}.pos-payment-tab[data-mode-tab="momo"]{--tab-bg:#fffbeb;--tab-border:#fcd34d;--tab-color:#92400e;--tab-active:#d97706}.pos-payment-tab[data-mode-tab="card"]{--tab-bg:#eef2ff;--tab-border:#a5b4fc;--tab-color:#3730a3;--tab-active:#4f46e5}.pos-payment-tab[aria-pressed="true"]{background:var(--tab-active);border-color:var(--tab-active);color:#fff}.pos-payment-tab:disabled{opacity:.45;cursor:not-allowed}.pos-payment-tab:focus-visible{outline:3px solid var(--c-accent,#2563eb);outline-offset:2px}.pos-unsettled-modal .modal-box{width:min(680px,calc(100vw - 2rem));max-height:calc(100vh - 2rem);overflow:auto}[data-unsettled-momo][hidden],[data-unsettled-cheque][hidden]{display:none!important}
.pos-unsettled-flag{display:inline-block;padding:.23rem .48rem;border-radius:999px;background:#edf1f2;color:#495b52;font-size:.7rem;font-weight:750;white-space:nowrap}.pos-unsettled-flag--open{background:#fff1d6;color:#805000}.pos-unsettled-flag--paid{background:#e7f6ec;color:#12633c}.pos-unsettled-reason{font-size:.75rem;color:var(--c-muted)}.pos-unsettled-pagination{display:flex;justify-content:center;gap:.4rem;align-items:center}
@media(max-width:980px){.pos-unsettled-filter{grid-template-columns:repeat(2,minmax(0,1fr))}.pos-unsettled-filter-actions{grid-column:1/-1}.pos-unsettled-hero{display:block}.pos-unsettled-tools{margin-top:.7rem}}
@media(max-width:560px){.pos-unsettled-stats{grid-template-columns:1fr}.pos-unsettled-filter{grid-template-columns:1fr}.pos-unsettled-filter-actions{grid-column:auto}.pos-unsettled-stat{padding:.7rem .85rem}.pos-unsettled-actions{flex-direction:column}}
</style>
@endpush
@section('content')
@php $reopenSettleFor = $errors->any() && old('return_to') === 'unsettled' ? (int) old('return_order') : 0; @endphp
@include('pos._workspace-nav')
<div class="pos-unsettled-hero">
  <div><h2>Cashier unsettled invoice monitor</h2><p class="small muted">Review every open check and billed invoice across waiters and business dates. Record direct payments here; room posting and Complimentary / NC remain available from the order.</p></div>
  <div class="pos-unsettled-tools"><span class="tiny muted">Updated {{ now()->format('H:i:s') }}</span><label class="pos-unsettled-autorefresh"><input id="posUnsettledAutoRefresh" type="checkbox" checked> Auto-refresh when idle</label><span class="pos-unsettled-refresh-state" id="posUnsettledRefreshState">Every 60 seconds</span><a class="btn btn-sm" href="{{ route('pos.unsettled', request()->only(['outlet','waiter','q','stage']), false) }}"><x-icon name="refresh" size="sm"/> Refresh</a></div>
</div>
<div class="pos-unsettled-stats" aria-label="Unsettled invoice summary">
  <div class="pos-unsettled-stat"><span>Unsettled checks</span><b>{{ number_format((int) $summary->invoice_count) }}</b></div>
  <div class="pos-unsettled-stat pos-unsettled-stat--due"><span>Total balance due</span><b>{{ money($summary->balance_due) }}</b></div>
  <div class="pos-unsettled-stat"><span>Waiters represented</span><b>{{ number_format((int) $summary->waiter_count) }}</b></div>
</div>
@if(empty($filters['waiter'] ?? null) && $waiterSummary->isNotEmpty())
  <section class="pos-unsettled-by-waiter" aria-label="Unsettled balances by waiter">
    <h3>Outstanding by waiter <span class="tiny muted">· choose a name to filter the invoice list</span></h3>
    <div class="pos-unsettled-waiter-chips">
      @foreach($waiterSummary as $group)
        @php $waiterFilter = $group->waiter === 'Unassigned' ? '__unassigned' : $group->waiter; $waiterQuery = array_merge(request()->only(['outlet','q','stage']), ['waiter' => $waiterFilter]); @endphp
        <a class="pos-unsettled-waiter-chip" href="{{ route('pos.unsettled', $waiterQuery, false) }}"><b>{{ $group->waiter }}</b><span>{{ number_format((int) $group->invoice_count) }} check{{ (int) $group->invoice_count === 1 ? '' : 's' }} · {{ money($group->balance_due) }} due</span></a>
      @endforeach
    </div>
  </section>
@endif
<form class="pos-unsettled-filter" method="get" action="{{ route('pos.unsettled', [], false) }}" aria-label="Filter unsettled invoices">
  <div class="field"><label for="unsettledSearch">Search orders</label><input id="unsettledSearch" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Order, bill, guest, phone" autocomplete="off"></div>
  <div class="field"><label for="unsettledWaiter">Waiter</label><select id="unsettledWaiter" name="waiter"><option value="">All waiters</option><option value="__unassigned" @selected(($filters['waiter'] ?? '') === '__unassigned')>Unassigned</option>@foreach($waiters as $waiter)<option value="{{ $waiter }}" @selected(($filters['waiter'] ?? '') === $waiter)>{{ $waiter }}</option>@endforeach</select></div>
  <div class="field"><label for="unsettledOutlet">Outlet</label><select id="unsettledOutlet" name="outlet"><option value="">All outlets</option>@foreach($outlets as $id => $name)<option value="{{ $id }}" @selected((string) ($filters['outlet'] ?? '') === (string) $id)>{{ $name }}</option>@endforeach</select></div>
  <div class="field"><label for="unsettledStage">Invoice stage</label><select id="unsettledStage" name="stage"><option value="">Open + billed</option><option value="open" @selected(($filters['stage'] ?? '') === 'open')>Open checks</option><option value="billed" @selected(($filters['stage'] ?? '') === 'billed')>Billed invoices</option></select></div>
  <div class="pos-unsettled-filter-actions"><button class="btn btn-primary"><x-icon name="search" size="sm"/> Filter</button><a class="btn btn-ghost" href="{{ route('pos.unsettled', [], false) }}">Reset</a></div>
</form>
<div class="card flush pos-unsettled-register">
  <div class="card-head"><div><h2>Unsettled invoices</h2><p class="small muted pos-unsettled-summary">Showing {{ $orders->firstItem() ?? 0 }}–{{ $orders->lastItem() ?? 0 }} of {{ number_format($orders->total()) }} open checks and bills, oldest first · {{ number_format((int) ($summary->open_count ?? 0)) }} open · {{ number_format((int) ($summary->billed_count ?? 0)) }} billed · {{ number_format((int) ($summary->waiter_count ?? 0)) }} waiters represented.</p></div></div>
  <div class="card-body table-wrap"><table class="table pos-unsettled-table">
    <caption class="sr-only">All open checks and billed invoices with their waiter, balance due and cashier settlement controls.</caption>
    <thead><tr><th scope="col">Order / bill</th><th scope="col">Waiter</th><th scope="col">Guest / location</th><th scope="col">Outlet</th><th scope="col">Opened</th><th scope="col" class="num">Total</th><th scope="col" class="num">Paid</th><th scope="col" class="num">Balance due</th><th scope="col">Stage</th><th scope="col">Cashier actions</th></tr></thead>
    <tbody>
    @forelse($orders as $o)
      @php
        $received = max(0, (float) ($o->paid_amount ?? 0) - (float) ($o->refunded_amount ?? 0));
        $due = round(max(0, (float) $o->total - $received), 2);
        $location = $o->table?->table_no ? 'Table '.$o->table->table_no : ($o->stay?->room?->room_no ? 'Room '.$o->stay->room->room_no : ucfirst(str_replace('_', ' ', $o->order_type)));
        $canGenerateBill = $o->status === 'open' && $o->accepted_items_count > 0 && $o->pending_items_count === 0;
        $canSettleHere = $due > 0.005 && $o->accepted_items_count > 0 && $o->pending_items_count === 0 && $paymentModes->isNotEmpty();
      @endphp
      <tr>
        <td class="pos-unsettled-invoice"><a href="{{ route('pos.terminal', $o, false) }}">{{ $o->bill_no ?: $o->order_no }}</a><span class="pos-unsettled-subline">{{ $o->bill_no ? $o->order_no : 'Bill not generated' }}@if($o->source === 'qr') · QR @elseif($o->source === 'portal') · Guest portal @elseif($o->source === 'tv') · Room TV @endif</span></td>
        <td><span class="pos-unsettled-waiter">{{ $o->steward ?: 'Unassigned' }}</span><span class="pos-unsettled-subline">{{ $o->order_type === 'dine_in' ? 'Dine in' : ucwords(str_replace('_', ' ', $o->order_type)) }}</span></td>
        <td><span class="pos-unsettled-guest">{{ $o->customer_name ?: 'Walk-in guest' }}</span><span class="pos-unsettled-subline">{{ $location }} · {{ (int) ($o->covers ?: 1) }} {{ (int) ($o->covers ?: 1) === 1 ? 'cover' : 'covers' }}</span></td>
        <td>{{ $o->outlet?->name ?? '—' }}</td>
        <td class="pos-unsettled-age"><time datetime="{{ $o->created_at?->toIso8601String() }}" title="{{ $o->created_at?->format('d M Y H:i') }}">{{ $o->created_at?->diffForHumans() ?? '—' }}</time><span class="pos-unsettled-subline">Business date {{ $o->business_date?->format('d M Y') ?? '—' }}</span></td>
        <td class="num pos-unsettled-money">{{ money($o->total, false) }}</td>
        <td class="num pos-unsettled-money">{{ money($received, false) }}</td>
        <td class="num pos-unsettled-due">{{ money($due, false) }}@if($o->pending_items_count)<small>{{ $o->pending_items_count }} guest item{{ $o->pending_items_count === 1 ? '' : 's' }} awaiting acceptance</small>@elseif($o->accepted_items_count === 0)<small>No accepted items</small>@endif</td>
        <td><span class="pos-unsettled-flag {{ $o->status === 'open' ? 'pos-unsettled-flag--open' : '' }}">{{ $o->status === 'open' ? 'Open check' : 'Billed' }}</span>@if($received > 0 && $due > 0.005)<span class="pos-unsettled-subline"><span class="pos-unsettled-flag pos-unsettled-flag--paid">Part-paid</span></span>@endif</td>
        <td>
          <div class="pos-unsettled-actions">
            @if($canGenerateBill)
              <form method="post" action="{{ route('pos.bill', $o, false) }}" class="pos-unsettled-bill-form" aria-label="Generate bill for {{ $o->order_no }}">
                @csrf
                <input type="hidden" name="return_to" value="unsettled">
                <input type="hidden" name="return_outlet" value="{{ $filters['outlet'] ?? '' }}">
                <input type="hidden" name="return_waiter" value="{{ $filters['waiter'] ?? '' }}">
                <input type="hidden" name="return_q" value="{{ $filters['q'] ?? '' }}">
                <input type="hidden" name="return_stage" value="{{ $filters['stage'] ?? '' }}">
                <button type="submit" class="btn btn-sm btn-ghost" aria-label="Generate bill for {{ $o->order_no }}" title="Generate bill"><x-icon name="receipt" size="sm"/> Bill</button>
              </form>
            @endif
            @if($canSettleHere)
              <button type="button" class="btn btn-sm btn-primary" data-unsettled-open-payment
                data-order-id="{{ $o->id }}" data-settle-url="{{ route('pos.settle', $o, false) }}"
                data-order-no="{{ $o->bill_no ?: $o->order_no }}" data-due="{{ number_format($due, 2, '.', '') }}">
                <x-icon name="money" size="sm"/> Settle
              </button>
            @endif
          </div>
          @if($o->pending_items_count > 0)
            <span class="pos-unsettled-reason">Accept or remove pending items before settling.</span>@if(can_do('pos.view'))<a class="btn btn-sm btn-ghost" href="{{ route('pos.terminal', $o, false) }}">Review order</a>@endif
          @elseif($o->accepted_items_count === 0)
            <span class="pos-unsettled-reason">No accepted items to charge.</span>@if(can_do('pos.view'))<a class="btn btn-sm btn-ghost" href="{{ route('pos.terminal', $o, false) }}">Review order</a>@endif
          @elseif($due <= 0.005 && $o->status === 'billed')
            <span class="pos-unsettled-reason">No balance due.</span>
          @elseif($due > 0.005 && $paymentModes->isEmpty())
            <span class="pos-unsettled-reason">No active direct payment modes.</span>
          @endif
        </td>
      </tr>
    @empty
      <tr><td colspan="10"><div class="pos-unsettled-empty"><h3>No unsettled checks or invoices</h3><p class="small muted">All waiters’ open checks and billed balances will appear here, across business dates.</p></div></td></tr>
    @endforelse
    </tbody>
  </table></div>
  @if($orders->hasPages())<div class="card-foot pos-unsettled-pagination">
    @if($orders->onFirstPage())<button class="btn btn-sm" disabled>Previous</button>@else<a class="btn btn-sm" href="{{ route('pos.unsettled', array_merge(request()->only(['outlet','waiter','q','stage']), ['page' => $orders->currentPage() - 1]), false) }}">Previous</a>@endif
    <span class="small muted">Page {{ $orders->currentPage() }} of {{ $orders->lastPage() }}</span>
    @if($orders->hasMorePages())<a class="btn btn-sm" href="{{ route('pos.unsettled', array_merge(request()->only(['outlet','waiter','q','stage']), ['page' => $orders->currentPage() + 1]), false) }}">Next</a>@else<button class="btn btn-sm" disabled>Next</button>@endif
  </div>@endif
</div>

<div class="modal pos-unsettled-modal{{ $reopenSettleFor ? ' show' : '' }}" id="unsettledSettleModal" data-currency="{{ hotel()?->currency_symbol ?? 'GH₵' }}" role="dialog" aria-modal="true" aria-labelledby="unsettledSettleTitle" tabindex="-1">
  <div class="modal-box"><form method="post" action="#" id="unsettledSettleForm" data-unsettled-settle-form data-order-no="">@csrf
    <input type="hidden" name="settlement" value="payment">
    <input type="hidden" name="return_to" value="unsettled">
    <input type="hidden" name="return_order" value="{{ $reopenSettleFor ?: '' }}">
    <input type="hidden" name="return_outlet" value="{{ $filters['outlet'] ?? '' }}">
    <input type="hidden" name="return_waiter" value="{{ $filters['waiter'] ?? '' }}">
    <input type="hidden" name="return_q" value="{{ $filters['q'] ?? '' }}">
    <input type="hidden" name="return_stage" value="{{ $filters['stage'] ?? '' }}">
    <div class="modal-head"><div><h3 id="unsettledSettleTitle">Take payment</h3><p class="tiny muted" id="unsettledSettleDue">Select a bill to view its balance.</p></div><button type="button" class="btn btn-ghost btn-icon" data-close aria-label="Close payment"><x-icon name="x"/></button></div>
    <div class="modal-body">
      <div class="field mb-1"><label for="unsettledPaymentMode">Payment mode</label><select id="unsettledPaymentMode" name="payment_mode_id" required data-payment-mode><option value="">Choose a payment mode</option>@foreach($paymentModes as $modeId => $modeName)<option value="{{ $modeId }}" @selected($reopenSettleFor && (string) old('payment_mode_id') === (string) $modeId)>{{ $modeName }}</option>@endforeach</select></div>
      <div class="field"><span class="small bold">Payment type</span><div class="pos-payment-tabs" role="group" aria-label="Quick select payment type">
        <button type="button" class="pos-payment-tab" data-mode-tab="cash" aria-pressed="false">Cash</button><button type="button" class="pos-payment-tab" data-mode-tab="momo" aria-pressed="false">MoMo</button><button type="button" class="pos-payment-tab" data-mode-tab="card" aria-pressed="false">Card</button>
      </div></div>
      <div class="form-grid">
        <div class="field"><label for="unsettledAmount">Amount applied</label><input id="unsettledAmount" name="amount" type="number" min="0.01" step="0.01" value="{{ $reopenSettleFor ? old('amount') : '' }}" required></div>
        <div class="field"><label for="unsettledTender">Amount tendered <span class="tiny muted">cash only for change</span></label><input id="unsettledTender" name="tendered_amount" type="number" min="0" step="0.01" value="{{ $reopenSettleFor ? old('tendered_amount') : '' }}" placeholder="Same as amount"></div>
        <div class="field wide"><label for="unsettledReference">Reference / transaction ID</label><input id="unsettledReference" name="reference" maxlength="80" value="{{ $reopenSettleFor ? old('reference') : '' }}" placeholder="Receipt, MoMo, card, cheque…"></div>
      </div>
      <x-payment-momo-fields mode-name="payment_mode_id" network-name="mobile_network" phone-name="mobile_phone" class="form-grid span-all mt-1"/>
      <x-cheque-fields class="mt-1"/>
      <details class="mt-1" @if($reopenSettleFor) open @endif><summary class="small bold">Card, voucher and invoice details</summary><div class="form-grid mt-1">
        <div class="field"><label for="unsettledCardAuth">Card authorisation</label><input id="unsettledCardAuth" name="card_authorisation" maxlength="80" value="{{ $reopenSettleFor ? old('card_authorisation') : '' }}"></div>
        <div class="field wide"><label for="unsettledPaymentNotes">Payment notes</label><input id="unsettledPaymentNotes" name="payment_notes" maxlength="190" value="{{ $reopenSettleFor ? old('payment_notes') : '' }}"></div>
        <div class="field wide"><label for="unsettledVoucherCode">Gift voucher code</label><input id="unsettledVoucherCode" name="voucher_code" maxlength="20" value="{{ $reopenSettleFor ? old('voucher_code') : '' }}" placeholder="GV-XXXX-XXXX"></div>
        @if($evatEnabled)<div class="field"><label for="unsettledBuyerTin">Buyer TIN</label><input id="unsettledBuyerTin" name="buyer_tin" maxlength="20" value="{{ $reopenSettleFor ? old('buyer_tin') : '' }}" placeholder="C0012345678"></div><div class="field"><label for="unsettledBuyerName">Buyer name</label><input id="unsettledBuyerName" name="buyer_name" maxlength="120" value="{{ $reopenSettleFor ? old('buyer_name') : '' }}"></div>@endif
      </div></details>
      <p class="pos-unsettled-note">Part-payments remain outstanding. The invoice closes once the full balance is paid. Room posting, split tenders and Complimentary / NC remain available from order details.</p>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" type="submit">Record payment</button>@if(can_do('pos.view'))<button class="btn btn-success" type="submit" name="print_bill" value="1"><x-icon name="print"/> Settle &amp; Print</button>@endif</div>
  </form></div>
</div>
@endsection
@push('scripts')
<script>
(() => {
  const refreshToggle = document.getElementById('posUnsettledAutoRefresh');
  const refreshState = document.getElementById('posUnsettledRefreshState');
  const refreshKey = 'pos.unsettled.auto-refresh';
  const scrollKey = 'pos.unsettled.scroll-y';
  try {
    const saved = localStorage.getItem(refreshKey);
    if (saved !== null) refreshToggle.checked = saved === '1';
    const savedScroll = Number(sessionStorage.getItem(scrollKey) || 0);
    sessionStorage.removeItem(scrollKey);
    if (savedScroll > 0) requestAnimationFrame(() => window.scrollTo(0, savedScroll));
  } catch (e) {}
  let lastActivity = Date.now(), filterDirty = false;
  const markActivity = () => { lastActivity = Date.now(); };
  ['pointerdown','keydown','input','change','scroll','touchstart'].forEach(type => document.addEventListener(type, markActivity, {passive:true}));
  document.querySelector('.pos-unsettled-filter')?.addEventListener('input', () => { filterDirty = true; });
  document.querySelector('.pos-unsettled-filter')?.addEventListener('change', () => { filterDirty = true; });
  refreshToggle.addEventListener('change', () => {
    try { localStorage.setItem(refreshKey, refreshToggle.checked ? '1' : '0'); } catch (e) {}
    refreshState.textContent = refreshToggle.checked ? 'Every 60 seconds when idle' : 'Auto-refresh off';
  });
  refreshState.textContent = refreshToggle.checked ? 'Every 60 seconds when idle' : 'Auto-refresh off';
  setInterval(() => {
    if (!refreshToggle.checked || document.visibilityState !== 'visible') return;
    const active = document.activeElement;
    const editing = !!active?.closest('.pos-unsettled-filter, #unsettledSettleModal');
    const paymentOpen = !!document.getElementById('unsettledSettleModal')?.classList.contains('show');
    if (filterDirty || editing || paymentOpen || Date.now() - lastActivity < 60000) return;
    try { sessionStorage.setItem(scrollKey, String(window.scrollY)); } catch (e) {}
    window.location.reload();
  }, 30000);

  const paymentCategory = label => {
    const text = String(label || '').trim().toLowerCase();
    if (text.includes('momo') || text.includes('mobile money')) return 'momo';
    if (text.includes('card')) return 'card';
    if (text.includes('cash')) return 'cash';
    return '';
  };
  const modal = document.getElementById('unsettledSettleModal');
  const form = modal?.querySelector('[data-unsettled-settle-form]');
  const mode = form?.querySelector('[data-payment-mode]');
  const amountInput = form?.querySelector('[name="amount"]');
  const tabs = [...(form?.querySelectorAll('[data-mode-tab]') || [])];
  const updateTabs = () => {
    const category = paymentCategory(mode?.selectedOptions?.[0]?.textContent || '');
    tabs.forEach(tab => {
      tab.setAttribute('aria-pressed', tab.dataset.modeTab === category ? 'true' : 'false');
      tab.disabled = ![...(mode?.options || [])].some(option => paymentCategory(option.textContent) === tab.dataset.modeTab);
    });
  };
  const fillPayment = (button, preserve = false) => {
    if (!button || !form || !modal) return;
    if (!preserve) {
      form.reset();
      form.querySelectorAll('input:not([type="hidden"]),select,textarea').forEach(control => {
        if (control.type === 'checkbox' || control.type === 'radio') control.checked = false;
        else control.value = '';
      });
    }
    const due = Number(button.dataset.due || 0);
    const orderNo = button.dataset.orderNo || 'POS order';
    form.action = button.dataset.settleUrl;
    form.dataset.orderNo = orderNo;
    form.querySelector('[name="return_order"]').value = button.dataset.orderId;
    if (amountInput) {
      amountInput.max = due.toFixed(2);
      if (!preserve || !amountInput.value) amountInput.value = due.toFixed(2);
    }
    document.getElementById('unsettledSettleTitle').textContent = `Take payment · ${orderNo}`;
    document.getElementById('unsettledSettleDue').textContent = `Balance due: ${modal.dataset.currency} ${due.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}`;
    mode?.dispatchEvent(new Event('change', {bubbles:true}));
    updateTabs();
    window.openModal('unsettledSettleModal');
  };
  mode?.addEventListener('change', updateTabs);
  tabs.forEach(tab => tab.addEventListener('click', () => {
    const option = [...(mode?.options || [])].find(candidate => paymentCategory(candidate.textContent) === tab.dataset.modeTab);
    if (!option) return;
    mode.value = option.value;
    mode.dispatchEvent(new Event('change', {bubbles:true}));
  }));
  document.querySelectorAll('[data-unsettled-open-payment]').forEach(button => button.addEventListener('click', () => fillPayment(button)));
  form?.addEventListener('submit', event => {
    const button = event.submitter;
    const amount = Number(amountInput?.value || 0);
    const message = `Record ${amount.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})} payment for ${form.dataset.orderNo}?`;
    if (!window.confirm(message)) { event.preventDefault(); return; }
    if (button) { button.disabled = true; button.textContent = 'Recording…'; }
  });
  const reopenId = Number(form?.querySelector('[name="return_order"]')?.value || 0);
  if (reopenId) {
    const button = [...document.querySelectorAll('[data-unsettled-open-payment]')].find(candidate => Number(candidate.dataset.orderId) === reopenId);
    if (button) fillPayment(button, true);
    else modal?.classList.remove('show');
  }

})();
</script>
@endpush
