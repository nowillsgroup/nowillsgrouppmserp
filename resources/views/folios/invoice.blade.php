@extends('layouts.print')
@php
  $ghana = \App\Services\GstService::isGhana();
  $isCn = $folio->isCreditNote();
  $issued = (bool) $folio->invoice_no;
  $snap = $issued && $folio->supply_type;   // buyer frozen at issue (v3.0)
  $docDate = $folio->invoice_date ?? $folio->closed_at ?? now();
  $lbl = \App\Services\TaxService::labels();
@endphp
@section('title', ($isCn ? 'Credit note ' : 'Invoice ').($folio->invoice_no ?? $folio->folio_no))
@section('doctitle', $isCn ? ($folio->status === 'cancelled' ? 'CREDIT NOTE – CANCELLED' : 'CREDIT NOTE') : ($issued ? ($ghana ? 'VAT INVOICE' : 'INVOICE') : 'PRO-FORMA BILL'))
@section('docmeta')<div class="small">{{ $isCn ? 'Credit note' : 'Invoice' }} no.: <b id="docNo">{{ $folio->invoice_no ?? 'Draft' }}</b><br>Date: {{ $docDate->format('d M Y') }}@if(! $isCn)<br>Folio: {{ $folio->folio_no }}@endif
  @if($isCn && $folio->original)<br>Against invoice: <b>{{ $folio->original->invoice_no }}</b> dt. {{ $folio->original->invoice_date?->format('d M Y') }}@endif
  @if($ghana && $issued)<br>Currency: {{ $currentHotel->currency ?: 'GHS' }}@endif</div>@endsection
@section('content')
@if($folio->irn && $folio->irn_status === 'generated')
<div id="irnBlock" style="display:flex;gap:1rem;align-items:center;border:1px solid #cbd5e1;border-radius:6px;padding:.5rem .75rem;margin-bottom:1rem">
  @if($folio->irn_qr)<div style="width:110px;flex:none">{!! \App\Services\EInvoiceService::qrSvg($folio->irn_qr) !!}</div>@endif
  <div class="small" style="min-width:0"><div><b>GRA E-VAT</b> &nbsp; <b>SDC ID:</b> <span style="font-family:monospace">{{ $folio->irn }}</span> &nbsp; <b>Receipt no.:</b> {{ $folio->irn_ack_no }}</div>
    <div><b>SDC time:</b> {{ $folio->irn_ack_at?->format('d-m-Y H:i:s') }}</div>
    @if($folio->evat_intdata)<div><b>Internal data:</b> <span style="font-family:monospace">{{ $folio->evat_intdata }}</span></div>@endif
    @if($folio->evat_signature)<div><b>Signature:</b> <span style="font-family:monospace">{{ $folio->evat_signature }}</span></div>@endif
    @if(\App\Services\EInvoiceService::driver() === 'sandbox')<div class="tiny text-2">Sandbox stamp – for testing only, not reported to the Ghana Revenue Authority.</div>@endif</div>
</div>
@endif
<div class="grid g-2 mb-2">
  <dl class="kv" id="buyerBox">
  @if($snap)
    <dt>{{ $isCn ? 'Issued to' : 'Billed to' }}</dt><dd><b>{{ $folio->buyer_name ?? $folio->guest?->full_name }}</b></dd>
    @if($folio->buyer_gstin)<dt>Buyer TIN</dt><dd>{{ $folio->buyer_gstin }}</dd>@endif
    <dt>Address</dt><dd>{{ $folio->buyer_address ?: '—' }}</dd>
    @if($folio->company_id || $folio->buyer_gstin)<dt>Guest</dt><dd>{{ $folio->guest?->full_name }}</dd>@endif
  @else
    <dt>Billed to</dt><dd>{{ $folio->company?->name ?? $folio->guest?->full_name }}</dd>
    @if($folio->company)<dt>Guest</dt><dd>{{ $folio->guest?->full_name }}</dd>@if($folio->company->tax_number)<dt>Company TIN</dt><dd>{{ $folio->company->tax_number }}</dd>@endif @endif
    <dt>Address</dt><dd>{{ $folio->company?->address ?? trim(($folio->guest?->address).' '.($folio->guest?->city)) }}</dd>
    @if(! $folio->company && $folio->guest?->tax_number)<dt>TIN</dt><dd>{{ $folio->guest->tax_number }}</dd>@endif
  @endif
    <dt>Phone</dt><dd>{{ $folio->guest?->phone }}</dd></dl>
  @if($folio->stay)<dl class="kv"><dt>Room</dt><dd>{{ $folio->stay->room?->room_no }} – {{ $folio->stay->roomType->name }}</dd><dt>Arrival</dt><dd>{{ ($folio->stay->checked_in_at ?? $folio->stay->arrival_date)->format('d M Y H:i') }}</dd><dt>Departure</dt><dd>{{ ($folio->stay->checked_out_at ?? $folio->stay->departure_date)->format('d M Y H:i') }}</dd><dt>Reservation</dt><dd>{{ $folio->reservation?->reservation_no }}</dd><dt>Pax</dt><dd>{{ $folio->stay->adults }}/{{ $folio->stay->children }}</dd></dl>@endif
</div>
@if($isCn && ($folio->note_reason || $folio->note_remarks))<p class="small mb-2"><b>Reason:</b> {{ \App\Models\Folio::NOTE_REASONS[$folio->note_reason] ?? $folio->note_reason }}{{ $folio->note_remarks ? ' – '.$folio->note_remarks : '' }}</p>@endif
<table class="table table-compact" id="invLines">
  <thead><tr><th>Date</th><th>Particulars</th><th class="num">Amount</th><th class="num">Tax</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead><tbody>
  @foreach($tx as $t)<tr><td class="nowrap">{{ $t->business_date->format('d/m/y') }}</td><td>{{ $t->description }}{{ $t->paymentMode ? ' – '.$t->paymentMode->name : '' }}</td>
    <td class="num">{{ $t->type === 'charge' ? money($t->amount, false) : '' }}</td><td class="num">{{ $t->type === 'charge' && (float) $t->tax_amount != 0 ? money($t->tax_amount, false).' @'.(float) $t->tax_rate.'%' : '' }}</td>
    <td class="num">{{ in_array($t->type, ['charge','refund']) ? money($t->total, false) : '' }}</td><td class="num">{{ in_array($t->type, ['payment','discount','allowance']) ? money($t->amount, false) : '' }}</td></tr>@endforeach
  </tbody>
  <tfoot>
    <tr><td colspan="4" class="right">Total</td><td class="num">{{ money($totals['charges'] + $totals['refunds'], false) }}</td><td class="num">{{ money($totals['payments'] + $totals['discount'], false) }}</td></tr>
    <tr><td colspan="4" class="right">Balance</td><td colspan="2" class="num">{{ money($totals['balance']) }}</td></tr>
  </tfoot>
</table>
@if($lines->count())
<h4 class="mt-2">Tax summary</h4>
<style>#gstSummary{width:100%;max-width:100%;font-size:12px}#gstSummary th{white-space:normal;line-height:1.25}#gstSummary td:first-child{min-width:110px}</style>
<table class="table table-compact" id="gstSummary"><thead><tr><th>Description</th><th class="num">Taxable value</th><th class="num">{{ $lbl['VAT'] }}</th><th class="num">{{ $lbl['NHIL'] }}</th><th class="num">{{ $lbl['GETFUND'] }}</th><th class="num">{{ $lbl['TL'] }}</th><th class="num">Total tax</th></tr></thead><tbody>
@foreach($lines as $l)<tr><td class="small">{{ $l['desc'] }} <span class="tiny text-2">@ {{ $l['rate'] }}%</span></td><td class="num">{{ money($l['taxable'], false) }}</td><td class="num">{{ money($l['vat'], false) }}</td><td class="num">{{ money($l['nhil'], false) }}</td><td class="num">{{ money($l['getfund'], false) }}</td><td class="num">{{ money($l['tl'], false) }}</td><td class="num">{{ money($l['tax'], false) }}</td></tr>@endforeach
</tbody><tfoot><tr><td class="right">Total</td><td class="num">{{ money($lines->sum('taxable'), false) }}</td><td class="num">{{ money($lines->sum('vat'), false) }}</td><td class="num">{{ money($lines->sum('nhil'), false) }}</td><td class="num">{{ money($lines->sum('getfund'), false) }}</td><td class="num">{{ money($lines->sum('tl'), false) }}</td><td class="num">{{ money($lines->sum('tax'), false) }}</td></tr>
  @if($gst['discount'] != 0)<tr><td colspan="6" class="right">Less: discount / allowance</td><td class="num">{{ money($gst['discount'], false) }}</td></tr>@endif
  <tr><td colspan="6" class="right bold">{{ $isCn ? 'Credit note value' : 'Invoice value' }}</td><td class="num bold">{{ money(abs($gst['value'])) }}</td></tr></tfoot></table>
<p class="tiny text-2">VAT, NHIL and GETFund are charged under the VAT Act, 2025 (Act 1151); the Tourism Levy under the Tourism Act, 2011 (Act 817).</p>
@endif
@if($isCn)<p class="small text-2 mt-2">Credit note issued against the invoice referred above. Output VAT, NHIL, GETFund and Tourism Levy are reduced by the amounts shown.</p>@endif
<p class="small text-2 mt-2">{{ $currentHotel->invoice_footer }}</p>
<p class="small text-2">{{ setting('invoice_terms') }}</p>
<div class="flex justify-between mt-3" style="padding-top:2rem"><div>@unless($isCn)______________________<br>Guest signature @endunless</div><div class="right">For {{ $currentHotel->legal_name ?: $currentHotel->name }}<br><br>______________________<br>Authorised signatory</div></div>
@endsection
