@extends('layouts.app')
@section('title', $reconciliation->reconciliation_no.' · Billing Reconciliation')
@section('crumbs') Billing / <a href="{{ route('billingrec.index') }}">Billing reconciliation</a> / {{ $reconciliation->reconciliation_no }} @endsection
@section('actions')
<a class="btn" href="{{ route('billingrec.print', $reconciliation) }}" target="_blank">Print workpaper</a>
@if(! $reconciliation->isFinalised() && can_do('billingrec.manage'))
<form method="post" action="{{ route('billingrec.refresh', $reconciliation) }}" class="inline">@csrf<button class="btn" type="submit">Refresh from folios</button></form>
@endif
@endsection
@section('content')
@php
  $statusLabels = ['pending' => ['badge-amber', 'Pending'], 'matched' => ['badge-green', 'Matched'], 'exception' => ['badge-red', 'Exception'], 'not_applicable' => ['badge-slate', 'No cash settlement']];
@endphp

<div class="flex justify-between align-center mb-1">
  <div><h2 style="margin:0">{{ $reconciliation->reconciliation_no }}</h2><div class="small muted">Invoice dates {{ $reconciliation->label() }} · {{ $reconciliation->items_count ?? $items->count() }} document(s)</div></div>
  <div>{!! status_badge($reconciliation->status) !!}</div>
</div>
<div class="alert alert-info"><b>Folio-only, non-posting workpaper.</b> Document totals and folio receipts/refunds are snapshots; company-account transfers are shown separately and are not cash collection. Enter settlement amounts from source evidence. A “Matched” row must have the full receipt/refund amounts and a reference or private evidence file. Partial or disputed settlements belong in “Exception noted” with an explanation. No GL entries are created.</div>
@if($reconciliation->refreshed_at)<p class="tiny muted">Last source refresh: {{ $reconciliation->refreshed_at->format('d M Y H:i') }}. Refresh before finalising if you posted or voided folio transactions after the snapshot.</p>@endif
@if($reconciliation->reopened_at)<div class="alert alert-warning">Reopened {{ $reconciliation->reopened_at->format('d M Y H:i') }} by {{ $reconciliation->reopener?->name ?? 'a previous user' }}: {{ $reconciliation->reopen_reason }}</div>@endif

<div class="stats" id="billingReconSummary">
  <x-stat label="Tax invoices" :value="money($totals['invoices'])" icon="receipt" tone="blue"/>
  <x-stat label="Credit notes" :value="money($totals['credit_notes'])" icon="receipt" tone="purple"/>
  <x-stat label="Net billed" :value="money($totals['net_billed'])" icon="book" tone="teal"/>
  <x-stat label="Folio cash movement (net)" :value="money($totals['net_cash'])" icon="wallet" tone="green"/>
  <x-stat label="Company-ledger transfer (net)" :value="money($totals['city_ledger_net'])" icon="building" tone="slate"/>
  <x-stat label="Settlement evidence (net)" :value="money($totals['net_evidenced'])" icon="check" tone="blue"/>
  <x-stat label="Cash variance (net)" :value="money($totals['net_cash_variance'])" icon="alert" :tone="abs($totals['net_cash_variance']) > 0.01 ? 'amber' : 'green'"/>
  <x-stat label="Review status" :value="$totals['matched'].' matched · '.$totals['exceptions'].' exceptions · '.$totals['pending'].' pending'" icon="shield" :tone="$totals['pending'] ? 'amber' : 'green'"/>
</div>
<p class="tiny muted mt-1">Net cash movement = folio payments less refunds. City-ledger transfers are not treated as cash. Cash variance is a portfolio net; review each document row to identify offsetting differences.</p>

<div class="card flush mt-2">
  <div class="card-head"><h3>Issued folio documents</h3><span class="tiny muted">Credit-note values display as positive document amounts; period netting subtracts credits.</span></div>
  <div class="card-body table-wrap">
    <table class="table" id="billingReconItems">
      <thead><tr><th>Document</th><th>Guest / company</th><th class="num">Document value</th><th class="num">Folio payments</th><th class="num">Folio refunds</th><th class="num">Company account</th><th>Review</th></tr></thead>
      <tbody>
      @forelse($items as $item)
        @php [$badgeClass, $badgeLabel] = $statusLabels[$item->review_status] ?? ['badge-slate', ucfirst($item->review_status)]; @endphp
        <tr id="billingDoc{{ $item->id }}" class="{{ $item->review_status === 'exception' ? 'row-alert' : '' }}">
          <td class="nowrap"><a class="bold" href="{{ route('folios.invoice', $item->folio_id) }}" target="_blank">{{ $item->document_no }}</a>
            <div class="tiny muted">{{ $item->document_type === 'credit_note' ? 'Credit note' : 'Tax invoice' }} · folio {{ $item->folio_no }}</div>
            @if($item->original_invoice_no)<div class="tiny muted">Against {{ $item->original_invoice_no }}</div>@endif
            <div class="tiny muted">{{ $item->document_date?->format('d M Y') }}</div>
            @if($item->source_status !== 'issued')<div class="tiny text-red">Source: {{ str_replace('_',' ',$item->source_status) }}; refresh and record an exception.</div>@endif
            @if($item->source_changed_at)<div class="tiny text-amber">Source changed on {{ $item->source_changed_at->format('d M Y H:i') }}; re-review required.</div>@endif
          </td>
          <td>{{ $item->customer_name ?: '—' }}</td>
          <td class="num bold">{{ money($item->document_amount, false) }}</td>
          <td class="num">{{ money($item->cash_payments, false) }}</td>
          <td class="num">{{ money($item->cash_refunds, false) }}</td>
          <td class="num">{{ money($item->city_ledger_amount, false) }}</td>
          <td><span class="badge {{ $badgeClass }}">{{ $badgeLabel }}</span>
            @if($item->reviewed_at)<div class="tiny muted">{{ $item->reviewed_by === auth()->id() ? 'Reviewed by you' : ($item->reviewer?->name ?? 'Reviewed') }} · {{ $item->reviewed_at->format('d M H:i') }}</div>@endif
          </td>
        </tr>
        <tr class="billing-review-row"><td colspan="7">
          @if(! $reconciliation->isFinalised() && can_do('billingrec.manage'))
          <details @if($item->review_status === 'pending' || $item->review_status === 'exception') open @endif>
            <summary>Review settlement evidence for {{ $item->document_no }}</summary>
            <form method="post" action="{{ route('billingrec.items.update', [$reconciliation, $item]) }}" enctype="multipart/form-data" class="form-grid mt-1" id="billingReview{{ $item->id }}">
              @csrf @method('PUT')
              <div class="field"><label for="settledPayments{{ $item->id }}">Receipts evidenced</label><input type="number" min="0" max="9999999999.99" step="0.01" id="settledPayments{{ $item->id }}" name="settled_payments" value="{{ old('settled_payments', $item->settled_payments) }}" placeholder="Folio payments: {{ number_format((float)$item->cash_payments,2,'.','') }}"></div>
              <div class="field"><label for="settledRefunds{{ $item->id }}">Refunds evidenced</label><input type="number" min="0" max="9999999999.99" step="0.01" id="settledRefunds{{ $item->id }}" name="settled_refunds" value="{{ old('settled_refunds', $item->settled_refunds) }}" placeholder="Folio refunds: {{ number_format((float)$item->cash_refunds,2,'.','') }}"></div>
              <div class="field"><label for="settlementReference{{ $item->id }}">Settlement reference</label><input type="text" maxlength="160" id="settlementReference{{ $item->id }}" name="settlement_reference" value="{{ old('settlement_reference', $item->settlement_reference) }}" placeholder="Bank, cashier shift, receipt or voucher ref"></div>
              <div class="field"><label for="reviewStatus{{ $item->id }}">Review outcome</label><select id="reviewStatus{{ $item->id }}" name="review_status" required>
                <option value="" disabled @selected($item->review_status === 'pending')>Choose outcome</option>
                <option value="matched" @selected(old('review_status', $item->review_status) === 'matched')>Matched to evidence</option>
                <option value="exception" @selected(old('review_status', $item->review_status) === 'exception')>Exception noted</option>
                <option value="not_applicable" @selected(old('review_status', $item->review_status) === 'not_applicable')>No cash settlement (e.g. company account)</option>
              </select></div>
              <div class="field span-2"><label for="reviewNote{{ $item->id }}">Reviewer note / exception reason</label><textarea id="reviewNote{{ $item->id }}" name="review_note" maxlength="2000" rows="2" placeholder="Required for an exception or no-cash settlement">{{ old('review_note', $item->review_note) }}</textarea></div>
              <div class="field"><label for="evidence{{ $item->id }}">Private evidence file</label><input type="file" id="evidence{{ $item->id }}" name="evidence" accept=".pdf,.jpg,.jpeg,.png,.csv,.xlsx">
                <span class="tiny muted">PDF, image, CSV or XLSX; max 10 MB.</span>
                @if($item->evidence_path)<div class="tiny"><a href="{{ route('billingrec.items.evidence', [$reconciliation, $item]) }}">Current: {{ $item->evidence_name ?: 'Download evidence' }}</a></div>@endif
              </div>
              <div class="field" style="align-self:end"><button class="btn btn-primary" type="submit">Save review</button></div>
            </form>
          </details>
          @else
            <div class="small">Evidence: {{ $item->settlement_reference ?: '—' }}
              @if($item->settled_payments !== null) · Receipts evidenced {{ money($item->settled_payments, false) }}@endif
              @if($item->settled_refunds !== null) · Refunds evidenced {{ money($item->settled_refunds, false) }}@endif
              @if($item->evidence_path) · <a href="{{ route('billingrec.items.evidence', [$reconciliation, $item]) }}">{{ $item->evidence_name ?: 'Download file' }}</a>@endif
            </div>
            @if($item->review_note)<div class="tiny muted">{{ $item->review_note }}</div>@endif
          @endif
        </td></tr>
      @empty
        <tr><td colspan="7"><x-empty text="No issued folio invoices or credit notes in this period. Refresh the snapshot after posting documents." icon="receipt"/></td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="grid g-2 mt-2">
  @if(! $reconciliation->isFinalised() && can_do('billingrec.approve'))
  <div class="card"><div class="card-head"><h3>Four-eyes final review</h3></div><div class="card-body">
    <p class="small">A different user must finalise this prepared workpaper. All rows must be matched, an explained exception, or an explained no-cash settlement. Finalising locks the evidence record but does not post to the general ledger.</p>
    <form method="post" action="{{ route('billingrec.finalise', $reconciliation) }}">@csrf<button class="btn btn-primary" @disabled($totals['pending'] > 0)>Finalise and lock</button></form>
  </div></div>
  @elseif($reconciliation->isFinalised())
  <div class="card"><div class="card-head"><h3>Final review</h3></div><div class="card-body">
    <p class="small">Finalised by <b>{{ $reconciliation->reviewer?->name ?? '—' }}</b> on {{ $reconciliation->finalised_at?->format('d M Y H:i') }}. This workpaper is read-only.</p>
    @if(can_do('billingrec.approve'))<form method="post" action="{{ route('billingrec.reopen', $reconciliation) }}" class="form-grid">@csrf
      <div class="field span-2"><label for="reopenReason">Reason to reopen</label><input id="reopenReason" name="reason" maxlength="2000" required placeholder="Explain the correction needed"></div>
      <div class="field" style="align-self:end"><button class="btn btn-danger">Reopen workpaper</button></div>
    </form>@endif
  </div></div>
  @endif
  <div class="card"><div class="card-head"><h3>Scope and controls</h3></div><div class="card-body small">
    <ul style="margin:.25rem 0 0;padding-left:1.2rem">
      <li>Only issued folio invoices and credit notes by invoice date are included. POS direct bills, company-ledger cash receipts and gateway/bank batch attribution are not in this first release.</li>
      <li>Folio payment/refund totals are captured from active transactions. City-ledger transfers are displayed separately and are not called cash collected.</li>
      <li>Settlement amounts and references are entered from evidence by an operator; finalisation requires a separate approver.</li>
      <li>Evidence uploads are private and served only through this permission-checked workpaper.</li>
      <li>This feature is an audit workpaper only: it does not create, edit, reverse or reconcile GL journals.</li>
    </ul>
  </div></div>
</div>
@endsection
