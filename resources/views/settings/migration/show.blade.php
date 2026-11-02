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
        @elseif($batch->dataset === 'banquet_enquiries')
          @if($batch->status === 'validated' && $batch->rows_failed === 0)
            <form method="post" action="{{ route('migration.apply', $batch) }}" class="flex gap-1 wrap items-end" data-confirm="Import only these reviewed banquet enquiries? No tentative or definite venue holds, deposits, folios, bills or ledger entries will be created.">
              @csrf<input type="hidden" name="confirm" value="yes">
              <label class="small">Type <b>IMPORT BANQUET ENQUIRIES</b><input name="confirm_sensitive" required autocomplete="off" placeholder="IMPORT BANQUET ENQUIRIES"></label>
              <button class="btn btn-primary">Import enquiry records</button>
            </form>
          @else
            <span class="badge badge-amber">Clean dry-run required before import</span>
          @endif
        @elseif(in_array($batch->dataset, ['masters__rate-plans', 'masters__taxes', 'masters__account-heads', 'menu_items'], true))
          @if($batch->status === 'validated' && $batch->rows_failed === 0)
            @php
              $phrase = match ($batch->dataset) { 'masters__taxes' => 'APPLY TAX SETTINGS', 'masters__account-heads' => 'APPLY CHART OF ACCOUNTS', 'menu_items' => 'APPLY MENU ITEMS', default => 'APPLY RATE PLANS' };
              $sensitiveLabel = match ($batch->dataset) { 'masters__taxes' => 'tax settings', 'masters__account-heads' => 'chart-of-accounts changes', 'menu_items' => 'menu catalogue changes', default => 'rate plans' };
            @endphp
            <form method="post" action="{{ route('migration.apply', $batch) }}" class="flex gap-1 wrap items-end" data-confirm="Apply these reviewed {{ $sensitiveLabel }} to this property? Check every displayed value first.">
              @csrf<input type="hidden" name="confirm" value="yes">
              <label class="small">Type <b>{{ $phrase }}</b><input name="confirm_sensitive" required autocomplete="off" placeholder="{{ $phrase }}"></label>
              <button class="btn btn-primary">Apply reviewed settings</button>
            </form>
          @else
            <span class="badge badge-amber">Clean dry-run required before apply</span>
          @endif
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
@elseif($batch->dataset === 'masters__taxes')
  <div class="alert alert-warning mt-2">
    <b>Tax settings affect new billable charges.</b> This adapter requires both master-data and GL permissions, checks statutory component/authority pairing and slab bounds, and only allows apply after a clean dry-run plus typed confirmation. Review the code, authority, category, rate and slab for every row; existing posted documents are not rewritten.
  </div>
@elseif($batch->dataset === 'masters__rate-plans')
  <div class="alert alert-warning mt-2">
    <b>Rate plans affect future room pricing and booking availability.</b> Review the room type, code, meal plan and every rate below. Apply is enabled only after a clean dry-run plus typed confirmation; existing reservations are not rewritten.
  </div>
@elseif($batch->dataset === 'masters__account-heads')
  <div class="alert alert-warning mt-2">
    <b>Chart-of-accounts entries can affect future postings.</b> Only non-system accounts are exported or imported; the <code>system_key</code> field is never accepted and matching system accounts are blocked from CSV updates. This requires GL permission, a clean dry-run and typed confirmation. Review each code, name and account type below.
  </div>
@elseif($batch->dataset === 'menu_items')
  <div class="alert alert-warning mt-2">
    <b>Menu catalogue import only; no POS orders or settlements are touched.</b> This imports item descriptions, category/outlet references, prices and side-size variants. Photos, recipes, modifier groups and allergen declarations remain untouched. New items must remain unavailable until staff complete module setup; CSV cannot activate an unavailable item.
  </div>
@elseif($batch->dataset === 'banquet_enquiries')
  <div class="alert alert-warning mt-2">
    <b>Enquiry-stage Banquet records only.</b> Imported events are forced to <code>enquiry</code>; the upload cannot set event status or open venue holds. Deposit amounts/payments, folios, billing, and GL entries are not imported or changed. Existing rows can only update an unbilled enquiry without a folio, linked organiser/BEO, visitor, online-payment or sales activity, actual attendance or a locked guarantee. Later tentative/definite booking and payment actions stay in Banquet.
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
  @elseif($batch->dataset === 'banquet_enquiries')
    <x-stat label="Enquiries to create / update" value="{{ number_format($batch->rows_created + $batch->rows_updated) }}" icon="calendar" tone="green"/>
    <x-stat label="Rows blocked" value="{{ number_format($batch->rows_failed) }}" icon="alert" tone="{{ $batch->rows_failed ? 'red' : 'slate' }}"/>
    <x-stat label="Venue holds created by import" value="0" icon="calendar" tone="slate"/>
    <x-stat label="Folios / deposits / bills created by import" value="0" icon="receipt" tone="slate"/>
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

@php
  $hasSensitiveMasterPreview = in_array($batch->dataset, ['masters__rate-plans', 'masters__taxes', 'masters__account-heads', 'menu_items'], true);
  $hasBanquetPreview = $batch->dataset === 'banquet_enquiries';
@endphp
<div class="card flush mt-2"><div class="card-head"><h3>Row outcomes</h3>
  <div class="flex gap-1 small">
    @foreach(['pending'=>'slate','would_create'=>'blue','would_update'=>'blue','unchanged'=>'slate','created'=>'green','updated'=>'green','skipped'=>'slate','failed'=>'red'] as $s => $c)
      @if(($counts[$s] ?? 0) > 0)<span class="badge badge-{{ $c }}">{{ $s }}: {{ number_format($counts[$s]) }}</span>@endif
    @endforeach
  </div></div>
  <div class="card-body table-wrap"><table class="table">
    <thead><tr><th class="num">Row</th><th>External id</th>@if($hasSensitiveMasterPreview || $hasBanquetPreview)<th>Values to review</th>@endif<th>Status</th><th>Message</th></tr></thead>
    <tbody>
    @forelse($rows as $r)
      @php $payload = (array) $r->payload; @endphp
      <tr>
        <td class="num">{{ $r->row_no }}</td>
        <td class="small">{{ $r->external_id }}</td>
        @if($hasSensitiveMasterPreview || $hasBanquetPreview)
          <td class="small">
            @if($batch->dataset === 'banquet_enquiries')
              @php
                $banquetRowPreview = $banquetPreview[$r->id] ?? [];
                $functionPreview = $banquetRowPreview['functions'] ?? json_decode((string) ($payload['functions_json'] ?? ''), true);
                $eventTypeLabel = \App\Models\BanquetEvent::TYPES[$payload['event_type'] ?? ''] ?? ($payload['event_type'] ?? 'Enquiry');
              @endphp
              <b>{{ $payload['contact_name'] ?? '—' }}</b> · {{ $payload['title'] ?? 'Untitled enquiry' }} · {{ $eventTypeLabel }} · contact {{ $payload['contact_phone'] ?? $payload['contact_email'] ?? '—' }}@if(!empty($banquetRowPreview['company_label'])) · {{ $banquetRowPreview['company_label'] }}@endif
              @if(is_array($functionPreview))
                @if(count($functionPreview))
                  <details><summary>{{ count($functionPreview) }} proposed function(s)</summary>
                    @foreach($functionPreview as $index => $function)
                      @if(is_array($function))
                        <div><b>{{ $function['name'] ?? 'Function '.($index + 1) }}</b> · {{ $function['hall_label'] ?? ('Venue #'.($function['banquet_hall_id'] ?? '—')) }} · {{ $function['date'] ?? '—' }} · {{ $function['start_time'] ?? '—' }}–{{ $function['end_time'] ?? '—' }} · {{ $function['setup_style'] ?? '—' }} · expected {{ $function['expected_pax'] ?? '—' }} / guaranteed {{ $function['guaranteed_pax'] ?? '—' }}</div>
                        @if(!empty($function['package_label']))<div class="tiny muted">Package: {{ $function['package_label'] }}</div>@endif
                        <div class="tiny muted">Estimate only · property currency: rate per person {{ $function['rate_per_pax'] ?? 'package default' }} · venue rate {{ $function['hall_rate'] ?? 'venue default' }}</div>
                        @if(!empty($function['items']) && is_array($function['items']))
                          @foreach($function['items'] as $item)
                            @if(is_array($item))<div class="tiny">Extra: @if(!empty($item['description'])){{ $item['description'] }}@if(!empty($item['service_label'])) ({{ $item['service_label'] }})@endif @else{{ $item['service_label'] ?? 'service' }}@endif × {{ $item['qty'] ?? '—' }} · unit {{ $item['unit_price'] ?? 'service default' }} · tax {{ $item['tax_rate'] ?? 'service default' }}%</div>@endif
                          @endforeach
                        @endif
                        @if(!empty($function['notes']))<div class="tiny">Function note: {{ $function['notes'] }}</div>@endif
                      @else<div class="bad">Invalid function at position {{ $index + 1 }}.</div>@endif
                    @endforeach
                  </details>
                @else<div class="bad">No proposed functions submitted; row is blocked.</div>@endif
              @else<div class="bad">Invalid functions JSON; row is blocked.</div>@endif
            @elseif($batch->dataset === 'masters__taxes')
              <b>{{ $payload['name'] ?? '—' }} ({{ $payload['code'] ?? '—' }} / {{ $payload['authority'] ?? '—' }})</b> · {{ $payload['applies_to'] ?? '—' }} · rate {{ $payload['rate'] ?? '—' }}% · slab {{ $payload['slab_from'] ?? '0' }}–{{ ($payload['slab_to'] ?? '') !== '' ? $payload['slab_to'] : 'no upper limit' }} · active {{ $payload['is_active'] ?? '—' }}
            @elseif($batch->dataset === 'masters__account-heads')
              <b>{{ $payload['code'] ?? '—' }} — {{ $payload['name'] ?? '—' }}</b> · {{ $payload['type'] ?? '—' }} · group {{ $payload['group_name'] ?? '—' }} · active {{ $payload['is_active'] ?? '—' }}
            @elseif($batch->dataset === 'menu_items')
              @php $variantPreview = json_decode((string) ($payload['variants_json'] ?? ''), true); @endphp
              <b>{{ $payload['code'] ?? '—' }} — {{ $payload['name'] ?? '—' }}</b> · category {{ $payload['menu_category_id'] ?? '—' }} · outlet {{ $payload['outlet_id'] ?? 'all' }} · price {{ $payload['price'] ?? '—' }} · tax {{ $payload['tax_rate'] ?? '—' }}% · {{ $payload['food_type'] ?? '—' }} · available {{ $payload['is_available'] ?? 'preserve/unavailable on create' }}
              @if(is_array($variantPreview))
                @if(count($variantPreview))<details><summary>{{ count($variantPreview) }} side variant(s)</summary>@foreach($variantPreview as $variant)<div>{{ $variant['name'] ?? '—' }} · rate {{ $variant['rate'] ?? '—' }} · cost {{ $variant['cost'] ?? '—' }}</div>@endforeach</details>@else<div>No side variants submitted; apply will clear any existing variants.</div>@endif
              @elseif(trim((string) ($payload['variants_json'] ?? '')) === '')
                <div>Variants are preserved on existing items (blank field).</div>
              @else
                <div class="bad">Invalid variants JSON; row is blocked.</div>
              @endif
            @else
              Room type {{ $payload['room_type_id'] ?? '—' }} / <b>{{ $payload['code'] ?? '—' }} — {{ $payload['name'] ?? '—' }}</b> · {{ $payload['meal_plan'] ?? '—' }} · rate {{ $payload['rate'] ?? '—' }} · extra adult {{ $payload['extra_adult_rate'] ?? '—' }} · extra child {{ $payload['extra_child_rate'] ?? '—' }} · tax-inclusive {{ $payload['tax_inclusive'] ?? '—' }} · active {{ $payload['is_active'] ?? '—' }}
            @endif
          </td>
        @endif
        <td><span class="badge badge-{{ in_array($r->status, ['created','updated']) ? 'green' : ($r->status === 'failed' ? 'red' : (in_array($r->status, ['would_create','would_update']) ? 'blue' : 'slate')) }}">{{ $r->status }}</span></td>
        <td class="small">{{ $r->message }}</td>
      </tr>
    @empty
      <tr><td colspan="{{ ($hasSensitiveMasterPreview || $hasBanquetPreview) ? 5 : 4 }}"><x-empty text="No rows recorded." icon="upload"/></td></tr>
    @endforelse
    </tbody>
  </table>
  {{ $rows->links() }}
  </div></div>
@endsection
