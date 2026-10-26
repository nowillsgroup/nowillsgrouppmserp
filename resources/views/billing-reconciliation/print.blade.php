@extends('layouts.print')
@section('title', $reconciliation->reconciliation_no.' · Billing Reconciliation')
@section('doctitle', 'Billing Reconciliation')
@section('docmeta')<div class="small"><b>Workpaper:</b> {{ $reconciliation->reconciliation_no }}<br><b>Invoice-date period:</b> {{ $reconciliation->label() }}<br><b>Status:</b> {{ ucfirst($reconciliation->status) }}<br><b>Prepared by:</b> {{ $reconciliation->preparer?->name ?? '—' }}<br><b>Finalised by:</b> {{ $reconciliation->reviewer?->name ?? '—' }} @if($reconciliation->finalised_at)<br>{{ $reconciliation->finalised_at->format('d M Y H:i') }}@endif</div>@endsection
@section('content')
<p class="small"><b>Non-posting folio workpaper.</b> It covers issued folio invoices and credit notes only. Company-account transfers are not cash. Settlement evidence is manually recorded; no GL journals are created or changed.</p>
<table class="table table-compact" id="billingReconSummaryPrint"><thead><tr><th>Tax invoices</th><th>Credit notes</th><th>Net billed</th><th>Net cash movement</th><th>Net company-ledger transfer</th><th>Net evidence</th><th>Net cash variance</th></tr></thead>
<tbody><tr><td class="num">{{ money($totals['invoices'], false) }}</td><td class="num">{{ money($totals['credit_notes'], false) }}</td><td class="num bold">{{ money($totals['net_billed'], false) }}</td><td class="num">{{ money($totals['net_cash'], false) }}</td><td class="num">{{ money($totals['city_ledger_net'], false) }}</td><td class="num">{{ money($totals['net_evidenced'], false) }}</td><td class="num">{{ money($totals['net_cash_variance'], false) }}</td></tr></tbody></table>
<table class="table table-compact mt-2" id="billingReconItemsPrint">
<thead><tr><th>Document</th><th>Date</th><th>Guest / company</th><th>Type</th><th class="num">Value</th><th class="num">Payments</th><th class="num">Refunds</th><th class="num">Company ledger</th><th class="num">Receipts evidenced</th><th class="num">Refunds evidenced</th><th>Status / settlement reference / notes</th></tr></thead>
<tbody>
@forelse($items as $item)
<tr>
  <td><b>{{ $item->document_no }}</b><div class="tiny">Folio {{ $item->folio_no }}@if($item->original_invoice_no)<br>Against {{ $item->original_invoice_no }}@endif</div></td>
  <td>{{ $item->document_date?->format('d M Y') }}</td><td>{{ $item->customer_name ?: '—' }}</td>
  <td>{{ $item->document_type === 'credit_note' ? 'Credit note' : 'Invoice' }}</td>
  <td class="num">{{ money($item->document_amount, false) }}</td><td class="num">{{ money($item->cash_payments, false) }}</td>
  <td class="num">{{ money($item->cash_refunds, false) }}</td><td class="num">{{ money($item->city_ledger_amount, false) }}</td>
  <td class="num">{{ $item->settled_payments === null ? '—' : money($item->settled_payments, false) }}</td>
  <td class="num">{{ $item->settled_refunds === null ? '—' : money($item->settled_refunds, false) }}</td>
  <td>{{ str_replace('_', ' ', ucfirst($item->review_status)) }}@if($item->source_status !== 'issued') · source {{ str_replace('_', ' ', $item->source_status) }}@endif
    @if($item->settlement_reference)<div class="tiny">Ref: {{ $item->settlement_reference }}</div>@endif
    @if($item->review_note)<div class="tiny">{{ $item->review_note }}</div>@endif
  </td>
</tr>
@empty<tr><td colspan="11">No issued folio invoices or credit notes in this period.</td></tr>@endforelse
</tbody></table>
<p class="tiny text-2 mt-2">Net cash movement and variance are shown as portfolio totals; offsetting document differences may cancel. Use the document rows for exceptions. Private evidence files remain available through the application workpaper.</p>
@endsection
