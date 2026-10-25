@extends('layouts.app')
@section('title', 'Migration batch '.$batch->code)
@section('content')
<div class="flex gap-1 wrap between">
  <div>
    <h2>{{ $batch->code }} — {{ $importer->label() }}</h2>
    <p class="muted small">Source: {{ $batch->source_name ?: '—' }} · file <code>{{ $batch->file_path }}</code> · uploaded {{ $batch->created_at->format('d M Y H:i') }} by {{ $batch->creator?->name ?? 'system' }}</p>
  </div>
  <div class="flex gap-1 wrap">
    <a class="btn" href="{{ route('migration.index') }}">← All batches</a>
    @if($batch->status !== 'imported')
      <form method="post" action="{{ route('migration.validate', $batch) }}">@csrf<button class="btn">Dry-run again</button></form>
      @if($importer->supportsApply())
        @if($batch->dataset === 'historical_stock')
          <form method="post" action="{{ route('migration.apply', $batch) }}" data-confirm="Archive {{ $batch->rows_total }} historical movement rows in the non-operational archive only? No stock balance, stock movement, GL journal or ID map will be created.">@csrf<input type="hidden" name="confirm" value="yes"><button class="btn btn-primary">Archive source history</button></form>
        @elseif($batch->dataset === 'paid_supplier_history')
          <form method="post" action="{{ route('migration.apply', $batch) }}" data-confirm="Archive {{ $batch->rows_total }} historical supplier bills, payments and allocations only? No AP subledger or GL record will be created.">@csrf<input type="hidden" name="confirm" value="yes"><button class="btn btn-primary">Archive supplier history</button></form>
        @else
          <form method="post" action="{{ route('migration.apply', $batch) }}" data-confirm="Import {{ $batch->rows_total }} rows of {{ $batch->dataset }} into the live database?">@csrf<input type="hidden" name="confirm" value="yes"><button class="btn btn-primary">Import (apply)</button></form>
        @endif
      @endif
    @endif
    <a class="btn" href="{{ route('migration.template', $batch->dataset) }}"><x-icon name="download" size="sm"/> Template</a>
  </div>
</div>

@if($batch->dataset === 'opening_ap')
  <div class="alert alert-info mt-2">
    <b>Opening AP subledger only — no historical GL posting.</b>
    <div>Provisional — policy not yet approved; confirm cutover method before production.</div>
    <div>The open-items-only policy remains provisional and not yet user/finance-approved. This file carries forward unpaid bill balances and unapplied supplier credits, not paid-bill/payment history. Applying it creates operational supplier subledger items but no journals; the full opening trial balance will establish the AP control once. Check the as-of date and AP totals before apply.</div>
  </div>
@elseif($batch->dataset === 'opening_ar')
  <div class="alert alert-info mt-2">
    <b>Opening AR subledger only — no historical GL posting.</b> This file carries open guest folio and company city-ledger balances by source document, with original references and dates. Paid items and historical payment/refund activity are not replayed. Opening entries are non-posting; the opening trial balance establishes guest and city-ledger controls once. Opening-only guest folios do not issue a new invoice or tax record when settled.
  </div>
@elseif($batch->dataset === 'historical_stock')
  <div class="alert alert-info mt-2">
    <b>Immutable historical archive only — no operational stock or GL writes.</b> Dry-run checks mapped items, stores, signed quantities, movement types, dates and optional property-currency costs. Apply stores source rows in <code>historical_stock_movement_archives</code> for audit; it never writes <code>stock_movements</code>, changes on-hand balances, posts GL, or creates historical-stock ID maps. Existing archive rows cannot be overwritten.
  </div>
@elseif($batch->dataset === 'paid_supplier_history')
  <div class="alert alert-info mt-2">
    <b>Paid supplier history archive only — no operational AP or GL writes.</b>
    <div>Provisional — policy not yet approved; confirm cutover method before production.</div>
    <div>This stores historical bill/payment/credit/allocation source rows in <code>supplier_paid_history_archives</code> only. It does not create or change supplier invoices, payments, AP allocations, opening AP balances, ID maps or journals, and it does not approve or operationalize the provisional open-items-only AP policy. Keep unpaid balances in the separate opening-AP review once that cutover policy is approved.</div>
  </div>
@elseif($batch->dataset === 'bank_account_mappings')
  <div class="alert alert-info mt-2">
    <b>Read-only bank-account mapping preflight — no Treasury, bank-statement, ID-map or GL writes.</b>
    <div>Only a stable, non-sensitive legacy ID, neutral account label, source kind and exact existing Nowills account code are accepted. The target must be active, reconcilable and have a matching Treasury-account kind. Do not include bank/branch metadata, account numbers, credentials, supplier bank details, balances or statement lines. Apply is intentionally disabled; this report is a mapping workpaper only.</div>
  </div>
@elseif($batch->dataset === 'inventory_allergens')
  <div class="alert alert-info mt-2">
    <b>Inventory allergen source preflight only — no declarations are imported.</b> This validates mapped stock ingredients and the canonical allergen keys/codes. It does not change ingredient or menu declarations or mark them verified. Food Safety staff must review supplier labels/specifications before entering or verifying allergen data in the live matrix.
  </div>
@elseif(!$importer->supportsApply())
  <div class="alert alert-info mt-2">
    <b>Preflight only — no GL posting.</b> This review checks the mapped trial balance, inventory and tracked subledger controls. It reports control variances and posting blockers; Apply remains intentionally disabled pending AP-policy approval, all required subledger sign-offs and duplicate-posting controls. A structural preflight pass is not posting approval.
  </div>
@endif

<div class="grid g-4 mt-2">
  <x-stat label="Rows in file" value="{{ number_format($batch->rows_total) }}" icon="upload"/>
  @if($batch->dataset === 'historical_stock')
    @php $archiveLabel = in_array($batch->status, ['registered', 'validated'], true) ? 'Archive rows that would be created' : 'Archive rows created'; @endphp
    <x-stat label="{{ $archiveLabel }}" value="{{ number_format($batch->rows_created) }}" icon="box" tone="green"/>
    <x-stat label="Already archived / skipped" value="{{ number_format($batch->rows_skipped) }}" icon="check" tone="slate"/>
    <x-stat label="Rows blocked" value="{{ number_format($batch->rows_failed) }}" icon="alert" tone="{{ $batch->rows_failed ? 'red' : 'slate' }}"/>
    <x-stat label="Operational stock movements written" value="0" icon="box" tone="slate"/>
    <x-stat label="GL journals posted" value="0" icon="book" tone="slate"/>
  @elseif($batch->dataset === 'paid_supplier_history')
    @php $archiveLabel = in_array($batch->status, ['registered', 'validated'], true) ? 'Archive rows that would be created' : 'Archive rows created'; @endphp
    <x-stat label="{{ $archiveLabel }}" value="{{ number_format($batch->rows_created) }}" icon="box" tone="green"/>
    <x-stat label="Already archived / skipped" value="{{ number_format($batch->rows_skipped) }}" icon="check" tone="slate"/>
    <x-stat label="Rows blocked" value="{{ number_format($batch->rows_failed) }}" icon="alert" tone="{{ $batch->rows_failed ? 'red' : 'slate' }}"/>
    <x-stat label="Operational AP invoices/payments written" value="0" icon="receipt" tone="slate"/>
    <x-stat label="GL journals posted" value="0" icon="book" tone="slate"/>
  @elseif(!$importer->supportsApply())
    <x-stat label="Rows passing preflight" value="{{ number_format($batch->rows_created) }}" icon="check" tone="green"/>
    <x-stat label="Rows blocked" value="{{ number_format($batch->rows_failed) }}" icon="alert" tone="{{ $batch->rows_failed ? 'red' : 'slate' }}"/>
    @if($batch->dataset === 'inventory_allergens')
      <x-stat label="Allergen declarations written" value="0" icon="check" tone="slate"/>
      <x-stat label="GL journals posted" value="0" icon="book" tone="slate"/>
    @elseif($batch->dataset === 'bank_account_mappings')
      <x-stat label="Treasury mappings written" value="0" icon="check" tone="slate"/>
      <x-stat label="Bank statements / balances written" value="0" icon="receipt" tone="slate"/>
      <x-stat label="GL journals posted" value="0" icon="book" tone="slate"/>
    @else
      <x-stat label="GL journals posted" value="0" icon="book" tone="slate"/>
    @endif
  @else
    <x-stat label="Created / would create" value="{{ number_format($batch->rows_created) }}" icon="plus" tone="green"/>
    <x-stat label="Updated / would update" value="{{ number_format($batch->rows_updated) }}" icon="edit" tone="blue"/>
    <x-stat label="Failed" value="{{ number_format($batch->rows_failed) }}" icon="alert" tone="{{ $batch->rows_failed ? 'red' : 'slate' }}"/>
  @endif
</div>

@if($report)
<div class="card mt-2"><div class="card-head"><h3>Reconciliation</h3></div><div class="card-body">
  <table class="table"><tbody>
  @foreach($report as $label => $n)
    @php
      $numericReportValue = is_numeric($n);
      $reportDecimals = $batch->dataset === 'historical_stock' && $label === 'Gross value for costed rows (property currency)' ? 2 : 0;
    @endphp
    <tr><td>{{ $label }}</td><td class="{{ $numericReportValue ? 'num' : 'small' }}">{{ $numericReportValue ? number_format((float) $n, $reportDecimals) : $n }}</td></tr>
  @endforeach
  </tbody></table>
</div></div>
@endif

<div class="card flush mt-2"><div class="card-head"><h3>Row outcomes</h3>
  <div class="flex gap-1 small">
    @foreach(['pending'=>'slate','would_create'=>'blue','would_update'=>'blue','unchanged'=>'slate','created'=>'green','updated'=>'green','skipped'=>'slate','failed'=>'red'] as $s => $c)
      @if(($counts[$s] ?? 0) > 0)<span class="badge badge-{{ $c }}">{{ $s }}: {{ number_format($counts[$s]) }}</span>@endif
    @endforeach
  </div></div>
  <div class="card-body table-wrap"><table class="table">
    <thead><tr><th class="num">Row</th><th>External id</th><th>Status</th><th>Message</th></tr></thead>
    <tbody>
    @forelse($rows as $r)
      <tr>
        <td class="num">{{ $r->row_no }}</td>
        <td class="small">{{ $r->external_id }}</td>
        <td><span class="badge badge-{{ in_array($r->status, ['created','updated']) ? 'green' : ($r->status === 'failed' ? 'red' : (in_array($r->status, ['would_create','would_update']) ? 'blue' : 'slate')) }}">{{ $r->status }}</span></td>
        <td class="small">{{ $r->message }}</td>
      </tr>
    @empty
      <tr><td colspan="4"><x-empty text="No rows recorded." icon="upload"/></td></tr>
    @endforelse
    </tbody>
  </table>
  {{ $rows->links() }}
  </div></div>
@endsection
