@extends('layouts.print')
@php
  $ghana = \App\Services\GstService::isGhana();
  $isCn = $folio->isCreditNote();
  $invoiceReservation = $folio->reservation ?? $folio->stay?->reservation;
  $invoiceGuest = $folio->guest ?? $folio->stay?->guest ?? $invoiceReservation?->guest;
  $invoiceCompany = $folio->company ?? $invoiceReservation?->company;
  $invoiceHasStayContext = (bool) ($folio->stay || $invoiceReservation);
  $invoiceStays = $folio->stay ? collect([$folio->stay]) : ($invoiceReservation?->rooms ?? collect());
  $invoiceStays = $invoiceStays->where('status', '!=', 'cancelled')->values();
  $invoiceRoomLabel = $invoiceStays->map(fn ($stay) => trim(($stay->room?->room_no ? 'Room '.$stay->room->room_no : 'Room unassigned').' · '.($stay->roomType?->name ?? 'Room stay')))->countBy()->map(fn ($count, $label) => ($count > 1 ? $count.' × ' : '').$label)->implode(', ');
  $invoicePlanLabel = $invoiceStays->map(fn ($stay) => $stay->ratePlan?->name ?? (\App\Models\RatePlan::MEAL_PLANS[$stay->ratePlan?->meal_plan ?? 'EP'] ?? null))->filter()->unique()->implode(', ');
  $invoiceAdults = (int) ($invoiceStays->isNotEmpty() ? $invoiceStays->sum('adults') : ($invoiceReservation?->adults ?? 0));
  $invoiceChildren = (int) ($invoiceStays->isNotEmpty() ? $invoiceStays->sum('children') : ($invoiceReservation?->children ?? 0));
  $firstInvoiceStay = $invoiceStays->sortBy(fn ($stay) => $stay->arrival_date?->timestamp ?? 0)->first();
  $lastInvoiceStay = $invoiceStays->sortBy(fn ($stay) => $stay->departure_date?->timestamp ?? 0)->last();
  $invoiceArrivalAt = $firstInvoiceStay?->checked_in_at;
  $invoiceArrivalDate = $invoiceArrivalAt ?? $firstInvoiceStay?->arrival_date ?? $invoiceReservation?->arrival_date;
  $invoiceDepartureAt = $lastInvoiceStay?->checked_out_at;
  $invoiceDepartureDate = $invoiceDepartureAt ?? $lastInvoiceStay?->departure_date ?? $invoiceReservation?->departure_date;
  $issued = (bool) $folio->invoice_no;
  $openingTxs = collect($tx)->filter(fn ($t) => $t->isOpening() && ! $t->is_void);
  $openingOnly = $openingTxs->isNotEmpty() && ! $folio->activeTransactions()->where('type', 'charge')->exists();
  $openingBalance = (float) $openingTxs->sum('amount');
  $snap = $issued && $folio->supply_type;   // buyer frozen at issue (v3.0)
  $docDate = $folio->invoice_date ?? $folio->closed_at ?? ($openingOnly ? $openingTxs->first()->opening_date : now());
  $lbl = \App\Services\TaxService::labels();
  $folioBalance = round((float) ($totals['balance'] ?? 0), 2);
  $balanceLabel = $folio->status === 'cancelled' ? 'Balance at cancellation' : ($folioBalance > 0.009 ? 'Balance due' : ($folioBalance < -0.009 ? 'Credit balance' : 'Folio balance'));
  $settlementLabel = $folio->status === 'cancelled' ? 'Cancelled' : (abs($folioBalance) <= 0.009 ? ((float) ($totals['payments'] ?? 0) > 0 ? 'Settled' : 'Zero balance') : ($folioBalance > 0 ? 'Balance due' : 'Credit balance'));
  $settlementTone = match ($settlementLabel) { 'Settled' => 'green', 'Balance due' => 'amber', 'Cancelled' => 'slate', default => 'blue' };
@endphp
@push('head')
<style>
  .print-page.folio-invoice { position:relative; max-width: 1020px; padding: 2.15rem 2.35rem; }
  .folio-invoice .invoice-watermark { position:absolute; inset:0; z-index:0; display:flex; align-items:center; justify-content:center; overflow:hidden; pointer-events:none; user-select:none; color:rgba(20,83,45,.085); -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  .folio-invoice .invoice-watermark span { display:block; transform:rotate(-32deg); color:inherit; font-size:clamp(3.25rem,11vw,7.5rem); font-weight:900; letter-spacing:.12em; line-height:1; white-space:nowrap; }
  .folio-invoice .print-head { align-items: flex-start; border-bottom: 3px solid #166534; padding-bottom: 1rem; margin-bottom: 1.15rem; }
  .folio-invoice .print-head h2 { color: #14532d; letter-spacing: -.025em; }
  .folio-invoice .print-head .right h2 { font-size: 1.25rem; line-height: 1.2; }
  .folio-invoice .invoice-docmeta { margin-top: .45rem; color: #526358; line-height: 1.7; }
  .folio-invoice #docNo { color: #14532d; font-size: 1.08rem; letter-spacing: .025em; }
  .folio-invoice .invoice-copy-label { display:inline-block; margin:.18rem 0 0; padding:.1rem .45rem; border:1px solid #d5e4d7; border-radius:999px; background:#f5faf5; color:#355a3d; font-size:.64rem; font-weight:800; letter-spacing:.045em; text-transform:uppercase; }
  .folio-invoice .invoice-amount-words { margin:.4rem 0 1.1rem; padding:.65rem .8rem; border-left:3px solid #9dbda2; background:#f7faf7; color:#334b38; font-size:.76rem; }
  .folio-invoice .invoice-amount-words span { display:block; margin-bottom:.15rem; color:#647669; font-size:.65rem; font-weight:800; letter-spacing:.06em; text-transform:uppercase; }
  .folio-invoice .invoice-prepared-by { margin:-.55rem 0 1rem; }
  .folio-invoice .invoice-header-balance { display:flex; align-items:center; justify-content:space-between; gap:.9rem; margin-top:.55rem; padding:.48rem .65rem; border:1px solid #cfe1d1; border-left:4px solid #166534; border-radius:8px; background:#eef7ef; color:#14532d; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  .folio-invoice .invoice-header-balance span { color:#526358; font-size:.66rem; font-weight:800; letter-spacing:.06em; text-transform:uppercase; }
  .folio-invoice .invoice-header-balance strong { font-size:1rem; font-weight:850; font-variant-numeric:tabular-nums; white-space:nowrap; }
  .folio-invoice .invoice-header-balance--amber { border-color:#f3d18a; border-left-color:#b45309; background:#fffbeb; color:#92400e; }
  .folio-invoice .invoice-header-balance--blue { border-color:#bfdbfe; border-left-color:#1d4ed8; background:#eff6ff; color:#1e40af; }
  .folio-invoice .invoice-header-balance--slate { border-color:#d8dee7; border-left-color:#64748b; background:#f8fafc; color:#334155; }
  #settlementStatus { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .folio-invoice .invoice-parties { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .85rem; margin: 1.15rem 0; }
  .folio-invoice .invoice-panel { min-width: 0; margin: 0; padding: .85rem 1rem; border: 1px solid #dce6dd; border-radius: 10px; background: #fbfdfb; }
  .folio-invoice .invoice-panel-title { margin:0 0 .6rem; color:#14532d; font-size:.7rem; font-weight:800; letter-spacing:.06em; text-transform:uppercase; }
  .folio-invoice .invoice-panel--full { grid-column: 1 / -1; }
  .folio-invoice .invoice-panel dt { color: #657669; font-size: .69rem; font-weight: 750; letter-spacing: .055em; text-transform: uppercase; }
  .folio-invoice .invoice-panel dd { min-width: 0; overflow-wrap: anywhere; color: #203728; font-weight: 600; }
  .folio-invoice .invoice-section-heading { display: flex; align-items: center; gap: .6rem; margin: 1.25rem 0 .5rem; color: #14532d; font-size: .88rem; font-weight: 800; letter-spacing: .015em; }
  .folio-invoice .invoice-table-wrap { width: 100%; margin: .45rem 0 1rem; overflow-x: auto; border: 1px solid #dce5dd; border-radius: 9px; }
  .folio-invoice #invLines, .folio-invoice #gstSummary { margin: 0; border-collapse: collapse; }
  .folio-invoice #invLines thead th { padding: .65rem .55rem; background: #174a2a; border-color: #174a2a; color: #fff; font-size: .65rem; letter-spacing: .045em; white-space: normal; }
  .folio-invoice #invLines tbody td { padding: .55rem .5rem; border-color: #e8eee9; font-size: .76rem; }
  .folio-invoice #invLines tbody td:nth-child(2), .folio-invoice #gstSummary tbody td:first-child { overflow-wrap:anywhere; }
  .folio-invoice .invoice-tax-cell { display:block; white-space:nowrap; }
  .folio-invoice #invLines tbody tr:nth-child(even):not(.void) { background: #f8fbf8; }
  .folio-invoice #invLines tbody tr.void { background: #fff8f8; }
  .folio-invoice #invLines .day-total th, .folio-invoice #invLines .day-total td { padding:.38rem .5rem; background:#f1f7f2; border-top:1px solid #cbdccc; color:#294b33; font-size:.69rem; font-weight:750; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  .folio-invoice #invLines .day-net-movement { color:#647669; font-size:.62rem; font-weight:600; white-space:nowrap; }
  .folio-invoice #invLines tfoot td { padding: .55rem .5rem; background: #f1f7f2; border-top: 1px solid #cbdccc; color: #294b33; font-size: .73rem; }
  .folio-invoice #invLines tfoot tr:last-child td { border-top: 2px solid #166534; background: #eaf4eb; color: #14532d; font-size: .82rem; font-weight: 800; }
  .folio-invoice #gstSummary { font-size: .72rem; }
  .folio-invoice #gstSummary thead th { padding: .55rem .4rem; background: #edf5ee; border-color: #d4e2d6; color: #244b31; font-size: .62rem; line-height: 1.25; white-space: normal; }
  .folio-invoice #gstSummary tbody td, .folio-invoice #gstSummary tfoot td { padding: .48rem .4rem; font-size: .7rem; }
  .folio-invoice #gstSummary tfoot tr:last-child td { border-top: 2px solid #166534; background: #edf6ee; color: #14532d; font-weight: 800; }
  #paymentSummary { width: 100%; max-width: 540px; margin: 1rem 0 1.25rem auto; border: 1px solid #d7e3d9; border-radius: 10px; border-collapse: separate; border-spacing: 0; overflow: hidden; }
  #paymentSummary th, #paymentSummary td { padding: .55rem .75rem; }
  #paymentSummary th { background: #fff; color: #526358; font-size: .77rem; font-weight: 600; letter-spacing: 0; text-align: left; text-transform: none; white-space: normal; }
  #paymentSummary tbody td { color: #203728; font-weight: 700; }
  #paymentSummary tbody tr + tr th, #paymentSummary tbody tr + tr td { border-top: 1px solid #edf2ee; }
  #paymentSummary tfoot th, #paymentSummary tfoot td { border-top: 2px solid #166534; background: #eef7ef; color: #14532d; font-weight: 800; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  #paymentSummary .settlement-state { margin-left: .45rem; vertical-align: middle; }
  .folio-invoice .invoice-document-footer { margin-top: 1.25rem; padding-top: .85rem; border-top: 1px solid #dce6dd; color: #526358; }
  .folio-invoice .invoice-signatures { margin-top: 2rem; padding-top: 1.2rem !important; color: #334155; }
  @media (max-width: 700px) {
    .print-page.folio-invoice { padding: 1.1rem; }
    .folio-invoice .print-head { flex-direction: column; align-items: flex-start; }
    .folio-invoice .print-head .right { text-align: left; }
    .folio-invoice .invoice-parties { grid-template-columns: 1fr; }
    #paymentSummary { max-width: none; }
    #paymentSummary .settlement-state { display: table; margin: .35rem 0 0 auto; }
    .folio-invoice #invLines { min-width: 760px; }
    .folio-invoice #gstSummary { min-width: 740px; }
  }
  @page { size: A4; margin: 11mm; }
  @media print {
    .folio-invoice .invoice-watermark { position:fixed; }
    .print-page.folio-invoice { width: 100%; max-width: none; padding: 0; margin: 0; border: 0; border-radius: 0; }
    .folio-invoice .print-head { flex-direction: row; margin-bottom: .9rem; }
    .folio-invoice .invoice-parties, .folio-invoice .invoice-panel, .folio-invoice .invoice-document-footer, .folio-invoice .invoice-amount-words, .folio-invoice .invoice-signatures, #paymentSummary { break-inside: avoid; page-break-inside: avoid; }
    .folio-invoice .invoice-section-heading { break-after: avoid; page-break-after: avoid; }
    .folio-invoice .invoice-table-wrap { overflow: visible; border: 0; border-radius: 0; }
    .folio-invoice #invLines, .folio-invoice #gstSummary { min-width: 0; width: 100%; }
    .folio-invoice #invLines thead, .folio-invoice #gstSummary thead { display: table-header-group; }
    .folio-invoice #invLines tr, .folio-invoice #gstSummary tr { break-inside: avoid; page-break-inside: avoid; }
    .folio-invoice #invLines thead th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .folio-invoice #invLines tfoot td, .folio-invoice #gstSummary thead th, .folio-invoice #gstSummary tfoot td { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  }
</style>
@endpush
@section('title', $openingOnly ? 'Opening receivable statement '.$folio->folio_no : (($isCn ? 'Credit note ' : 'Invoice ').($folio->invoice_no ?? $folio->folio_no)))
@section('backurl', route('folios.show', $folio))
@section('pageclass', 'folio-invoice')
@section('doctitle', $openingOnly ? 'OPENING RECEIVABLE STATEMENT – NOT A TAX INVOICE' : ($isCn ? ($folio->status === 'cancelled' ? 'CREDIT NOTE – CANCELLED' : 'CREDIT NOTE') : ($issued ? ($ghana ? 'VAT INVOICE' : 'INVOICE') : 'PRO-FORMA BILL')))
@section('docmeta')<div class="small invoice-docmeta">
  @if($openingOnly)Opening receivable as at <time datetime="{{ $openingTxs->first()->opening_date?->toDateString() }}">{{ $openingTxs->first()->opening_date?->format('d M Y') }}</time><br>Source document: <b>{{ $openingTxs->first()->reference }}</b><br>Not a tax invoice · Folio {{ $folio->folio_no }}
  @else{{ $isCn ? 'Credit note' : 'Invoice' }} no.: <b id="docNo">{{ $folio->invoice_no ?? 'Draft' }}</b><br>Date: <time datetime="{{ $docDate->toDateString() }}">{{ $docDate->format('d M Y') }}</time>@if(! $isCn)<br>Folio: {{ $folio->folio_no }}@endif
    @if($isCn && $folio->original)<br>Against invoice: <b>{{ $folio->original->invoice_no }}</b>@if($folio->original->invoice_date) dt. <time datetime="{{ $folio->original->invoice_date->toDateString() }}">{{ $folio->original->invoice_date->format('d M Y') }}</time>@endif @endif
    @if($ghana && $issued)<br>Currency: {{ $currentHotel->currency ?: 'GHS' }}@endif
  @endif<br>Settlement: <span id="settlementStatus" class="badge badge-{{ $settlementTone }}">{{ $settlementLabel }}</span>
  @if($issued && ! $openingOnly)<br><span class="invoice-copy-label">Original for recipient</span>@endif
  <div class="invoice-header-balance invoice-header-balance--{{ $settlementTone }}"><span>{{ $balanceLabel }}</span><strong>{{ money(abs($folioBalance)) }}</strong></div>
</div>@endsection
@section('content')
@if($issued && ! $openingOnly)<div class="invoice-watermark" aria-hidden="true"><span>ORIGINAL</span></div>@endif
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
@if($openingTxs->isNotEmpty())
<div class="alert alert-info"><b>Opening receivable as at {{ $openingTxs->first()->opening_date?->format('d M Y') }}.</b> Source reference {{ $openingTxs->first()->reference }} is carried forward separately from new charges and is excluded from revenue, tax and this tax-invoice total. {{ $openingOnly ? 'This is an opening-balance statement, not a newly issued invoice.' : 'Only current charge lines below are invoiced.' }}</div>
@endif
<div class="grid g-2 mb-2 invoice-parties">
  <dl class="kv invoice-panel {{ $invoiceHasStayContext ? '' : 'invoice-panel--full' }}" id="buyerBox">
  @if($snap)
    <dt>{{ $isCn ? 'Issued to' : 'Billed to' }}</dt><dd><b>{{ $folio->buyer_name ?? $invoiceCompany?->name ?? $invoiceGuest?->full_name }}</b></dd>
    @if($folio->buyer_gstin)<dt>Buyer TIN</dt><dd>{{ $folio->buyer_gstin }}</dd>@endif
    <dt>Address</dt><dd>{{ $folio->buyer_address ?: '—' }}</dd>
    @if($folio->company_id || $folio->buyer_gstin)<dt>Guest</dt><dd>{{ $invoiceGuest?->full_name }}</dd>@endif
  @else
    <dt>Billed to</dt><dd>{{ $invoiceCompany?->name ?? $invoiceGuest?->full_name }}</dd>
    @if($invoiceCompany)<dt>Guest</dt><dd>{{ $invoiceGuest?->full_name }}</dd>@if($invoiceCompany->tax_number)<dt>Company TIN</dt><dd>{{ $invoiceCompany->tax_number }}</dd>@endif @endif
    <dt>Address</dt><dd>{{ $invoiceCompany?->address ?? trim(($invoiceGuest?->address).' '.($invoiceGuest?->city)) }}</dd>
    @if(! $invoiceCompany && $invoiceGuest?->tax_number)<dt>TIN</dt><dd>{{ $invoiceGuest->tax_number }}</dd>@endif
  @endif
    <dt>Phone</dt><dd>{{ $invoiceGuest?->phone }}</dd></dl>
  @if($invoiceHasStayContext)
  <section class="invoice-panel" aria-labelledby="reservationDetailsTitle">
    <h4 class="invoice-panel-title" id="reservationDetailsTitle">Reservation details</h4>
    <dl class="kv m-0">
    <dt>Reservation</dt><dd>{{ $invoiceReservation?->reservation_no ?? '—' }}@if($invoiceReservation && can_do('reservations.view'))<br><a class="no-print tiny" href="{{ route('reservations.show', $invoiceReservation) }}" target="_blank" rel="noopener" aria-label="Open reservation {{ $invoiceReservation->reservation_no }} from invoice {{ $folio->invoice_no ?? $folio->folio_no }}">View reservation</a>@endif</dd>
    <dt>Room{{ $invoiceStays->count() === 1 ? '' : 's' }}</dt><dd>{{ $invoiceRoomLabel ?: 'No active room allocation' }}</dd>
    @if($invoiceArrivalDate)<dt>Arrival</dt><dd><time datetime="{{ $invoiceArrivalAt?->toIso8601String() ?? $invoiceArrivalDate->toDateString() }}">{{ $invoiceArrivalAt?->format('d M Y, H:i') ?? $invoiceArrivalDate->format('d M Y') }}</time></dd>@endif
    @if($invoiceDepartureDate)<dt>Departure</dt><dd><time datetime="{{ $invoiceDepartureAt?->toIso8601String() ?? $invoiceDepartureDate->toDateString() }}">{{ $invoiceDepartureAt?->format('d M Y, H:i') ?? $invoiceDepartureDate->format('d M Y') }}</time></dd>@endif
    <dt>Guests</dt><dd>{{ $invoiceAdults || $invoiceChildren ? $invoiceAdults.' adults'.($invoiceChildren ? ', '.$invoiceChildren.' children' : '') : 'Not recorded' }}</dd>
    @if($invoicePlanLabel !== '')<dt>Plan type</dt><dd>{{ $invoicePlanLabel }}</dd>@endif
    </dl>
  </section>
  @endif
</div>
@if($isCn && ($folio->note_reason || $folio->note_remarks))<p class="small mb-2"><b>Reason:</b> {{ \App\Models\Folio::NOTE_REASONS[$folio->note_reason] ?? $folio->note_reason }}{{ $folio->note_remarks ? ' – '.$folio->note_remarks : '' }}</p>@endif
<h4 class="invoice-section-heading">Folio activity</h4>
<div class="invoice-table-wrap"><table class="table table-compact" id="invLines">
  <caption class="sr-only">Folio ledger for document {{ $folio->invoice_no ?? $folio->folio_no }}</caption>
  <thead><tr><th scope="col">Business date / recorded</th><th scope="col">Particulars</th><th scope="col" class="num">Charge / tax</th><th scope="col" class="num">Debit</th><th scope="col" class="num">Credit</th><th scope="col" class="num">Running balance</th></tr></thead>
  @php $txByBusinessDate = collect($tx)->groupBy(fn ($t) => $t->business_date->toDateString()); $runningFolioBalance = 0.0; @endphp
  @foreach($txByBusinessDate as $businessDate => $dayTransactions)
  <tbody aria-label="Folio activity for {{ $businessDate }}">
    @foreach($dayTransactions as $t)
      @php
        $runningDelta = $t->is_void ? 0 : match (true) {
            $t->type === 'charge' || $t->type === 'refund' || $t->isOpening() => (float) $t->total,
            in_array($t->type, ['payment', 'discount', 'allowance'], true) => -(float) $t->amount,
            default => 0,
        };
        $runningFolioBalance = round($runningFolioBalance + $runningDelta, 2);
      @endphp
      <tr class="{{ $t->is_void ? 'void' : '' }}"><td class="nowrap"><time datetime="{{ $t->business_date->toDateString() }}">{{ $t->business_date->format('d/m/Y') }}</time>@if(in_array($t->type, ['payment', 'refund'], true))<br><span class="tiny text-2">Recorded <time datetime="{{ $t->created_at?->toIso8601String() }}">{{ $t->created_at?->format('d M Y, H:i') }}</time></span>@endif</td><td>{{ $t->description }}{{ $t->paymentMode ? ' – '.$t->paymentMode->name : '' }} @if($t->isOpening())<span class="badge badge-amber">opening · {{ $t->opening_date?->format('d M Y') }}</span>@endif @if($t->is_void)<span class="badge badge-red" title="Excluded from invoice totals">VOIDED</span>@endif @if(in_array($t->type, ['payment', 'refund'], true))@if($t->reference)<br><span class="tiny text-2">Reference: {{ $t->reference }}</span>@endif<br><a class="no-print tiny" href="{{ route('folios.receipt', $t) }}" target="_blank" rel="noopener" aria-label="Open {{ $t->type }} receipt for transaction {{ $t->id }}">View {{ $t->type }} receipt</a>@endif</td>
        <td class="num">{{ $t->type === 'charge' || $t->isOpening() ? money($t->amount, false) : '' }}@if($t->type === 'charge' && (float) $t->tax_amount != 0)<span class="tiny text-2 invoice-tax-cell">Tax {{ money($t->tax_amount, false) }} {{ '@'.(float) $t->tax_rate }}%</span>@endif</td>
        <td class="num">{{ in_array($t->type, ['charge','refund']) || $t->isOpening() ? money($t->total, false) : '' }}</td><td class="num">{{ in_array($t->type, ['payment','discount','allowance']) ? money($t->amount, false) : '' }}</td><td class="num">{{ money($runningFolioBalance, false) }}</td></tr>
    @endforeach
    @php
      $activeDayTransactions = $dayTransactions->where('is_void', false);
      $dayDebits = $activeDayTransactions->sum(fn ($t) => in_array($t->type, ['charge', 'refund']) || $t->isOpening() ? (float) $t->total : 0);
      $dayCredits = $activeDayTransactions->sum(fn ($t) => in_array($t->type, ['payment', 'discount', 'allowance']) ? (float) $t->amount : 0);
      $dayNetMovement = round($dayDebits - $dayCredits, 2);
    @endphp
    <tr class="day-total"><th scope="row" colspan="3">Day total for {{ $dayTransactions->first()->business_date->format('d M Y') }} <span class="day-net-movement">· Net movement {{ money($dayNetMovement, false) }}</span></th><td class="num">{{ money($dayDebits, false) }}</td><td class="num">{{ money($dayCredits, false) }}</td><td class="num">{{ money($runningFolioBalance, false) }}</td></tr>
  </tbody>
  @endforeach
  <tfoot>
    <tr><td colspan="3" class="right">Current charges / transactions (opening balance excluded)</td><td class="num">{{ money($totals['charges'] + $totals['refunds'], false) }}</td><td class="num">{{ money($totals['payments'] + $totals['discount'], false) }}</td><td></td></tr>
    @if($openingTxs->isNotEmpty())<tr><td colspan="3" class="right">Opening receivable carried forward (not a new charge or tax)</td><td class="num">{{ money($openingBalance, false) }}</td><td></td><td></td></tr>@endif
    <tr><td colspan="3" class="right">Overall folio balance</td><td colspan="3" class="num">{{ money($totals['balance']) }}</td></tr>
  </tfoot>
</table></div>
@if($lines->count())
<h4 class="invoice-section-heading">Tax breakdown</h4>
<div class="invoice-table-wrap"><table class="table table-compact" id="gstSummary"><caption class="sr-only">Tax breakdown for document {{ $folio->invoice_no ?? $folio->folio_no }}</caption><thead><tr><th scope="col">Description</th><th scope="col" class="num">Taxable value</th><th scope="col" class="num">{{ $lbl['VAT'] }}</th><th scope="col" class="num">{{ $lbl['NHIL'] }}</th><th scope="col" class="num">{{ $lbl['GETFUND'] }}</th><th scope="col" class="num">{{ $lbl['TL'] }}</th><th scope="col" class="num">Total tax</th></tr></thead><tbody>
@foreach($lines as $l)<tr><td class="small">{{ $l['desc'] }} <span class="tiny text-2">@ {{ $l['rate'] }}%</span></td><td class="num">{{ money($l['taxable'], false) }}</td><td class="num">{{ money($l['vat'], false) }}</td><td class="num">{{ money($l['nhil'], false) }}</td><td class="num">{{ money($l['getfund'], false) }}</td><td class="num">{{ money($l['tl'], false) }}</td><td class="num">{{ money($l['tax'], false) }}</td></tr>@endforeach
</tbody><tfoot><tr><td class="right">Total</td><td class="num">{{ money($lines->sum('taxable'), false) }}</td><td class="num">{{ money($lines->sum('vat'), false) }}</td><td class="num">{{ money($lines->sum('nhil'), false) }}</td><td class="num">{{ money($lines->sum('getfund'), false) }}</td><td class="num">{{ money($lines->sum('tl'), false) }}</td><td class="num">{{ money($lines->sum('tax'), false) }}</td></tr>
  @if($gst['discount'] != 0)<tr><td colspan="6" class="right">Less: discount / allowance</td><td class="num">{{ money($gst['discount'], false) }}</td></tr>@endif
  <tr><td colspan="6" class="right bold">{{ $isCn ? 'Credit note value' : 'Invoice value' }}</td><td class="num bold">{{ money(abs($gst['value'])) }}</td></tr></tfoot></table></div>
<p class="tiny text-2">VAT, NHIL and GETFund are charged under the VAT Act, 2025 (Act 1151); the Tourism Levy under the Tourism Act, 2011 (Act 817).</p>
@endif
<section aria-labelledby="paymentSummaryTitle">
  <h4 class="invoice-section-heading" id="paymentSummaryTitle">Payment and balance summary</h4>
  <table class="table table-compact" id="paymentSummary">
    <caption class="sr-only">Payments, refunds and outstanding balance on folio {{ $folio->folio_no }}</caption>
    <tbody>
      @unless($openingOnly)
        <tr><th scope="row">{{ $isCn ? 'Credit note value' : 'Invoice value' }}</th><td class="num">{{ money(abs((float) $gst['value'])) }}</td></tr>
      @endunless
      @if(abs($openingBalance) > 0.009)<tr><th scope="row">{{ $openingBalance > 0 ? 'Opening receivable brought forward' : 'Opening credit brought forward' }}</th><td class="num">{{ money(abs($openingBalance)) }}</td></tr>@endif
      <tr><th scope="row">Payments received on this folio</th><td class="num">{{ money($totals['payments']) }}</td></tr>
      <tr><th scope="row">Refunds issued on this folio</th><td class="num">{{ money($totals['refunds']) }}</td></tr>
    </tbody>
    <tfoot><tr>
      <th scope="row">{{ $balanceLabel }}@if($openingTxs->isNotEmpty()) <span class="tiny text-2">(includes opening balance)</span>@endif</th>
      <td class="num">{{ money(abs($folioBalance)) }} <span class="badge badge-{{ $settlementTone }} settlement-state">{{ $settlementLabel }}</span></td>
    </tr></tfoot>
  </table>
  <p class="tiny text-2">Payments and refunds shown are those recorded on this folio; the balance may include an opening balance.</p>
</section>
@unless($openingOnly)
<p class="invoice-amount-words"><span>{{ $isCn ? 'Credit note amount in words' : 'Invoice amount in words' }}</span><strong>{{ $currentHotel->currency ?: 'GHS' }} {{ amount_words(abs((float) $gst['value'])) }}</strong></p>
@endunless
@if($folio->closedBy)<p class="small text-2 invoice-prepared-by">Prepared by <b>{{ $folio->closedBy->name }}</b></p>@endif
@if($isCn)<p class="small text-2 mt-2">Credit note issued against the invoice referred above. Output VAT, NHIL, GETFund and Tourism Levy are reduced by the amounts shown.</p>@endif
<div class="invoice-document-footer">
  @if($currentHotel->invoice_footer)<p class="small text-2 mb-1">{{ $currentHotel->invoice_footer }}</p>@endif
  @if(setting('invoice_terms'))<p class="small text-2 m-0">{{ setting('invoice_terms') }}</p>@endif
</div>
<div class="flex justify-between invoice-signatures"><div>@unless($isCn)______________________<br>Guest signature @endunless</div><div class="right">For {{ $currentHotel->legal_name ?: $currentHotel->name }}<br><br>______________________<br>Authorised signatory</div></div>
@endsection
