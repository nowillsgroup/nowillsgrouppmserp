@extends('layouts.print')
@section('title', 'Bill '.$order->bill_no)
@section('pageclass', 'thermal')
@section('nohead', true)
@section('content')
<div class="center"><b style="font-size:14px">{{ $currentHotel->name }}</b><br>{{ $order->outlet->name }}<br>{{ $currentHotel->phone }}@if($currentHotel->tax_number)<br>TIN: {{ $currentHotel->tax_number }}@endif</div>
<hr>
@if($order->evat_status === 'generated')<div class="center"><b>VAT RECEIPT</b></div>@endif
<div>Bill: {{ $order->bill_no ?? 'PROVISIONAL' }}<br>Order: {{ $order->order_no }}<br>Date: {{ $order->created_at->format('d/m/Y H:i') }}<br>
{{ $order->table ? 'Table: '.$order->table->table_no : ($order->stay ? 'Room: '.$order->stay->room?->room_no : ucfirst($order->order_type)) }} · Covers: {{ $order->covers }}<br>Cashier: {{ $order->user?->name }}</div>
<hr>
<table style="width:100%;font-size:12px">
@foreach($order->items->where('is_cancelled', false)->where('is_pending', false) as $l)<tr><td>{{ $l->item_name }}@if($l->is_open) SPECIAL @endif @if($l->is_complimentary) COMP @endif @if($l->modifiers)<br><small>{{ collect($l->modifiers)->pluck('name')->implode(', ') }}</small>@endif<br>{{ (float)$l->qty }} x {{ number_format($l->rate,2) }}</td><td style="text-align:right;vertical-align:bottom">{{ $l->is_complimentary ? 'COMP' : number_format($l->amount,2) }}</td></tr>@endforeach
</table><hr>
<table style="width:100%;font-size:12px">
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
@endphp
@foreach(['VAT', 'NHIL', 'GETFUND', 'TL'] as $k)@if($tb[$k] != 0)<tr><td>{{ $tl[$k] }}</td><td style="text-align:right">{{ number_format($tb[$k], 2) }}</td></tr>@endif @endforeach
@if($order->round_off!=0)<tr><td>Round off</td><td style="text-align:right">{{ number_format($order->round_off,2) }}</td></tr>@endif
<tr><td><b>TOTAL</b></td><td style="text-align:right"><b>{{ $currentHotel->currency_symbol }} {{ number_format($order->total,2) }}</b></td></tr>
</table><hr>
@if($order->settlement === 'complimentary')<div><b>COMPLIMENTARY / NC</b>@if($order->comp_reason)<br>{{ $order->comp_reason }}@endif</div>@elseif($order->settlement === 'room_post')<div>Paid: Posted to Room {{ $order->stay?->room?->room_no }}</div>@elseif($order->payments->where('kind', 'payment')->isNotEmpty())
@php $posPaid = $order->paidAmount(); $posBalance = $order->balanceDue(); @endphp
<div><b>PAYMENTS</b><br>@foreach($order->payments->where('kind', 'payment') as $p){{ $p->paymentMode?->name ?? 'Payment' }}: {{ $currentHotel->currency_symbol }} {{ number_format($p->amount, 2) }}@if($p->reference) ({{ $p->reference }})@endif<br>@if($p->change_amount > 0)Change: {{ $currentHotel->currency_symbol }} {{ number_format($p->change_amount, 2) }}<br>@endif @endforeach @foreach($order->payments->where('kind', 'refund') as $p)<span>Refund: {{ $p->paymentMode?->name ?? 'Payment' }} -{{ $currentHotel->currency_symbol }} {{ number_format($p->amount, 2) }}@if($p->reference) ({{ $p->reference }})@endif</span><br>@endforeach @if($posBalance > 0)Balance due: {{ $currentHotel->currency_symbol }} {{ number_format($posBalance, 2) }}@endif</div>
@elseif($order->settlement)<div>Paid: {{ $order->paymentMode?->name ?? ucfirst($order->settlement) }}</div>@endif
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
