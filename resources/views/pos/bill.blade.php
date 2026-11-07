@extends('layouts.print')
@section('title', 'Bill '.$order->bill_no)
@section('pageclass', 'thermal pos-bill')
@section('nohead', true)
@section('content')
<div class="center pos-bill-brand"><b style="font-size:14px">{{ $currentHotel->name }}</b><br>{{ $order->outlet->name }}@if($currentHotel->address)<br>{{ $currentHotel->address }}{{ $currentHotel->city ? ', '.$currentHotel->city : '' }}{{ $currentHotel->state ? ', '.$currentHotel->state : '' }}{{ $currentHotel->pincode ? ' '.$currentHotel->pincode : '' }}@endif<br>{{ $currentHotel->phone }}@if($currentHotel->email)<br>{{ $currentHotel->email }}@endif @if($currentHotel->tax_number)<br>TIN: {{ $currentHotel->tax_number }}@endif</div>
<hr>
@if($order->evat_status === 'generated')<div class="center"><b>VAT RECEIPT</b></div>@endif
<div class="pos-bill-meta">Bill: {{ $order->bill_no ?? 'PROVISIONAL' }}<br>Order: {{ $order->order_no }}<br>Date: {{ $order->created_at->format('d/m/Y H:i') }}<br>
{{ $order->table ? 'Table: '.$order->table->table_no : ($order->stay ? 'Room: '.$order->stay->room?->room_no : ucfirst($order->order_type)) }} · Covers: {{ $order->covers }}<br>Waiter Name: {{ $order->steward ?: '—' }}</div>
<hr>
<table class="pos-bill-items" aria-label="Bill items, quantities, rates and amounts" style="width:100%;font-size:12px">
<caption class="sr-only">Bill items, quantities, rates and amounts</caption>
<colgroup><col style="width:45%"><col style="width:10%"><col style="width:20%"><col style="width:25%"></colgroup>
<thead><tr><th scope="col">ITEMS</th><th scope="col">QTY</th><th scope="col">RATE</th><th scope="col">AMOUNT</th></tr></thead>
<tbody>@foreach($order->items->where('is_cancelled', false)->where('is_pending', false) as $l)
<tr><td>{{ $l->item_name }}@if($l->is_open) <span class="pos-bill-tag">SPECIAL</span>@endif @if($l->is_complimentary) <span class="pos-bill-tag">COMP</span>@endif @if($l->modifiers)@php $lineOptions = collect($l->modifiers); $sideDishes = $lineOptions->where('type', 'side_dish'); $addOns = $lineOptions->reject(fn ($m) => ($m['type'] ?? null) === 'side_dish'); @endphp @if($sideDishes->isNotEmpty())<br><small>Side dishes: {{ \App\Services\PosMenuVariantService::describe($sideDishes) }}</small>@endif @if($addOns->isNotEmpty())<br><small>{{ $addOns->pluck('name')->implode(', ') }}</small>@endif @endif</td><td class="pos-bill-number">{{ (float)$l->qty }}</td><td class="pos-bill-number">{{ number_format($l->rate,2) }}</td><td class="pos-bill-number">{{ $l->is_complimentary ? 'COMP' : number_format($l->amount,2) }}</td></tr>
@endforeach</tbody></table><hr>
<table class="pos-bill-summary" style="width:100%;font-size:12px">
<tr><td>Subtotal</td><td style="text-align:right">{{ number_format($order->subtotal,2) }}</td></tr>
@if($order->compValue() > 0)<tr><td>Complimentary (not charged)</td><td style="text-align:right">{{ number_format($order->compValue(),2) }}</td></tr>@endif
@if($order->discount>0)<tr><td>Discount</td><td style="text-align:right">-{{ number_format($order->discount,2) }}</td></tr>@endif
@if($order->service_charge>0)<tr><td>Service charge</td><td style="text-align:right">{{ number_format($order->service_charge,2) }}</td></tr>@endif
@if($order->delivery_fee>0)<tr><td>Delivery fee</td><td style="text-align:right">{{ number_format($order->delivery_fee,2) }}</td></tr>@endif
@php
  $tl = \App\Services\TaxService::labels('fnb');
  // v4.4: when the bill is (to be) stamped, print the levies exactly as reported to GRA E-VAT (item by item)
  $evLines = \App\Services\PosEvatService::stampable($order) && ($order->evat_status || \App\Services\PosEvatService::enabled()) ? \App\Services\PosEvatService::lines($order) : collect();
  $tb = $evLines->isNotEmpty() ? ['VAT' => $evLines->sum('vat'), 'NHIL' => $evLines->sum('nhil'), 'GETFUND' => $evLines->sum('getfund'), 'TL' => $evLines->sum('tl')] : \App\Services\TaxService::breakdown((float) $order->tax, 'fnb');
  $hasTaxSummary = collect($tb)->contains(fn ($amount) => round((float) $amount, 2) != 0);
@endphp
@if($hasTaxSummary)<tr class="pos-bill-tax-heading"><td colspan="2">TAX SUMMARY</td></tr>@endif
@foreach(['VAT', 'NHIL', 'GETFUND', 'TL'] as $k)@if(round((float) $tb[$k], 2) != 0)<tr><td>{{ $tl[$k] }}</td><td style="text-align:right">{{ number_format($tb[$k], 2) }}</td></tr>@endif @endforeach
@if($order->round_off!=0)<tr><td>Round off</td><td style="text-align:right">{{ number_format($order->round_off,2) }}</td></tr>@endif
<tr class="pos-bill-total"><td><b>TOTAL</b></td><td style="text-align:right"><b>{{ $currentHotel->currency_symbol }} {{ number_format($order->total,2) }}</b></td></tr>
</table><hr>
@if($order->settlement === 'complimentary')<div class="pos-bill-settlement"><b>COMPLIMENTARY / NC</b><div class="pos-bill-comp-details">
  @if($order->comp_reason)<div><b>Reason:</b> {{ $order->comp_reason }}</div>@endif
  @if($order->comp_department)<div><b>Department:</b> {{ $order->comp_department }}</div>@endif
  @if($order->comp_manager_name)<div><b>Name of Manager:</b> {{ $order->comp_manager_name }}</div>@endif
  @if($order->comp_receiver_name)<div><b>Name of Receiver:</b> {{ $order->comp_receiver_name }}</div>@endif
  @if($order->comp_post_to === 'city_ledger')<div><b>Post to:</b> City Ledger / {{ $order->compPostCompany?->name ?? 'Company' }}</div>@elseif($order->comp_post_to === 'guest_folio')<div><b>Post to:</b> Guest Folio — Room {{ $order->compPostStay?->room?->room_no ?? '—' }}{{ $order->compPostStay?->reservation?->guest?->full_name ? ' · '.$order->compPostStay->reservation->guest->full_name : '' }}</div>@endif
</div></div>
@elseif($order->settlement === 'room_post')<div class="pos-bill-settlement">Paid: Posted to Room {{ $order->stay?->room?->room_no }}</div>
@elseif($order->payments->where('kind', 'payment')->isNotEmpty() || $order->settlement === 'payment')
@php $receiptPayments = $order->payments->where('kind', 'payment'); $posPaid = $order->paidAmount(); $posBalance = $order->balanceDue(); if ($receiptPayments->isEmpty() && $order->settlement === 'payment') { if ($posPaid <= 0) { $posPaid = (float) $order->total; } $posBalance = 0; } @endphp
<section id="billReceipts" class="pos-bill-receipts" aria-label="Payment receipts">
  <b class="pos-bill-section-title">RECEIPTS</b>
  <table class="pos-bill-receipt-table">
    <thead><tr><th scope="col">PAY MODE</th><th scope="col">AMOUNT</th></tr></thead>
    <tbody>
      @if($receiptPayments->isNotEmpty())
        @foreach($receiptPayments as $p)
          @php $receiptTender = $p->tendered_amount !== null ? (float) $p->tendered_amount : (float) $p->amount; @endphp
          <tr><td>{{ $p->paymentMode?->name ?? 'Payment' }}@if($p->reference)<br><small>Ref: {{ $p->reference }}</small>@endif</td><td>{{ number_format($receiptTender, 2) }}</td></tr>
          @if($p->change_amount > 0)<tr class="pos-bill-change"><td>Change given</td><td>-{{ number_format($p->change_amount, 2) }}</td></tr>@endif
        @endforeach
      @else
        <tr><td>{{ $order->paymentMode?->name ?? 'Payment' }}</td><td>{{ number_format($posPaid, 2) }}</td></tr>
      @endif
      @foreach($order->payments->where('kind', 'refund') as $p)
        <tr class="pos-bill-refund"><td>Refund · {{ $p->paymentMode?->name ?? 'Payment' }}@if($p->reference)<br><small>Ref: {{ $p->reference }}</small>@endif</td><td>-{{ number_format($p->amount, 2) }}</td></tr>
      @endforeach
      <tr class="pos-bill-receipt-total"><td>RECEIPTS TOTAL</td><td>{{ $currentHotel->currency_symbol }} {{ number_format($posPaid, 2) }}</td></tr>
      @if($posBalance > 0)<tr class="pos-bill-balance"><td>Balance due</td><td>{{ $currentHotel->currency_symbol }} {{ number_format($posBalance, 2) }}</td></tr>@endif
    </tbody>
  </table>
</section>
@elseif($order->settlement)<div class="pos-bill-settlement">Paid: {{ $order->paymentMode?->name ?? ucfirst($order->settlement) }}</div>@endif
<div class="pos-bill-cashier"><b>Cashier:</b> {{ $order->user?->name ?: '—' }}</div>
<section id="billSignoff" class="pos-bill-signoff" aria-label="Guest name, room number, and signature">
  <b class="pos-bill-signoff-heading">GUEST DETAILS &amp; SIGN-OFF</b>
  <div class="pos-bill-signoff-fields">
    <div class="pos-bill-signoff-field">
      <span class="pos-bill-signoff-label">Guest Name:</span>
      <span class="pos-bill-signoff-value {{ $order->customer_name ? 'has-content' : 'is-blank' }}">{{ $order->customer_name ?: '____________________________' }}</span>
    </div>
    <div class="pos-bill-signoff-field">
      <span class="pos-bill-signoff-label">Room Number:</span>
      <span class="pos-bill-signoff-value {{ $order->stay?->room?->room_no ? 'has-content' : 'is-blank' }}">{{ $order->stay?->room?->room_no ?: '____________________________' }}</span>
    </div>
    <div class="pos-bill-signoff-field pos-bill-signature">
      <span class="pos-bill-signoff-label">Signature:</span>
      <span class="pos-bill-signoff-value is-blank">_____________________________</span>
    </div>
  </div>
</section>
@if($order->buyer_tin)<div id="billBuyer">Customer: {{ $order->buyer_name }}<br>Customer TIN: {{ $order->buyer_tin }}</div>@endif
@if($order->evat_status === 'generated')
<hr><div class="center" id="billEvat"><b>GRA E-VAT</b><br>
  @if($order->evat_qr)<div style="width:120px;margin:4px auto">{!! \App\Services\EInvoiceService::qrSvg($order->evat_qr) !!}</div>@endif
  <div style="font-size:10px;text-align:left">SDC ID: {{ $order->evat_sdc_id }}<br>Receipt no.: {{ $order->evat_rec_num }}<br>SDC time: {{ $order->evat_at?->format('d-m-Y H:i:s') }}<br>Internal data: {{ $order->evat_intdata }}<br>Signature: {{ $order->evat_signature }}</div>
  @if(\App\Services\EInvoiceService::driver() === 'sandbox')<div style="font-size:9px">Sandbox stamp – not reported to GRA</div>@endif
</div>
@elseif(\App\Services\PosEvatService::stampable($order) && \App\Services\PosEvatService::enabled())
<hr><div class="center" id="billEvatPending" style="font-size:10px">GRA E-VAT stamp pending – not a final VAT receipt</div>
@endif
<div class="center mt-1">Thank you! Visit again.</div>
@endsection
