<?php

namespace App\Services\Migration\Importers;

use App\Models\DataMigrationIdMap;
use App\Models\GlJournal;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Services\ApService;
use App\Services\LedgerService;
use App\Services\Migration\BaseImporter;
use App\Services\Migration\ImportContext;
use App\Support\Numbering;

/**
 * Opening accounts-payable items at cutover. Only unpaid supplier bills and
 * unapplied supplier credits are carried forward; historical payments and
 * their GL journals are not replayed. The full opening TB establishes AP once.
 */
class OpeningApImporter extends BaseImporter
{
    private const MAX_ROW_CENTS = 999999999999; // supplier_invoices uses DECIMAL(12, 2)

    private const MAX_BATCH_CENTS = 99999999999999; // safe aggregate bound for a GL control balance

    public function label(): string
    {
        return 'Opening supplier AP items (non-posting)';
    }

    public function columns(): array
    {
        return [
            'external_id' => ['required' => true, 'desc' => 'Stable legacy id for this open bill or supplier credit', 'example' => 'AP-OPEN-INV-1042', 'aliases' => ['id', 'row_id', 'legacy_id']],
            'supplier_external_id' => ['required' => true, 'desc' => 'external_id from the applied suppliers import', 'example' => 'SUP-1001', 'aliases' => ['supplier_id', 'legacy_supplier_id']],
            'item_type' => ['required' => true, 'desc' => 'bill for an unpaid supplier invoice; credit for an unapplied supplier credit', 'example' => 'bill', 'aliases' => ['type', 'record_type']],
            'opening_date' => ['required' => true, 'desc' => 'As-of/cutover date for this AP snapshot; use the same date as opening_gl', 'example' => '2026-10-22', 'aliases' => ['as_of_date', 'cutover_date']],
            'document_date' => ['required' => true, 'desc' => 'Original supplier document date (must be on or before opening_date)', 'example' => '2026-10-10', 'aliases' => ['doc_date', 'invoice_date', 'date']],
            'due_date' => ['required' => false, 'desc' => 'Original due date; required for bills and ignored for credits', 'example' => '2026-11-09', 'aliases' => ['payment_due_date']],
            'supplier_ref' => ['required' => true, 'desc' => 'Legacy supplier invoice/credit reference; use a stable reference if the source has none', 'example' => 'INV-1042', 'aliases' => ['invoice_no', 'document_ref', 'reference']],
            'open_amount' => ['required' => true, 'desc' => 'Outstanding bill or credit balance in property currency (not the original gross invoice)', 'example' => '1280.50', 'aliases' => ['balance', 'outstanding', 'amount']],
            'currency' => ['required' => false, 'desc' => 'Supplier document currency; blank means the property currency', 'example' => 'GHS'],
            'foreign_open_amount' => ['required' => false, 'desc' => 'Outstanding amount in the supplier currency; required for a foreign-currency item', 'example' => '100.00', 'aliases' => ['fx_open_amount']],
            'fx_rate' => ['required' => false, 'desc' => 'Source carrying rate (up to 4 decimals); never use today’s rate to reconstruct the opening AP balance', 'example' => '12.3456', 'aliases' => ['book_rate']],
            'fx_date' => ['required' => false, 'desc' => 'Date of the source carrying-rate evidence; required for a foreign-currency item', 'example' => '2026-10-10'],
            'fx_basis' => ['required' => false, 'desc' => 'reference, buy, deal, or legacy when the source basis is not known', 'example' => 'legacy'],
            'notes' => ['required' => false, 'desc' => 'Optional source note; historical bill/payment detail is not recreated', 'example' => 'Opening balance from vendor statement'],
        ];
    }

    public function templateRows(): array
    {
        return [[
            'external_id' => 'AP-OPEN-INV-1042',
            'supplier_external_id' => 'SUP-1001',
            'item_type' => 'bill',
            'opening_date' => LedgerService::cutoff(),
            'document_date' => date('Y-m-d', strtotime(LedgerService::cutoff().' -12 days')),
            'due_date' => date('Y-m-d', strtotime(LedgerService::cutoff().' +18 days')),
            'supplier_ref' => 'INV-1042',
            'open_amount' => '1280.50',
            'currency' => strtoupper(trim((string) (hotel()?->currency ?: 'GHS'))),
            'foreign_open_amount' => '',
            'fx_rate' => '',
            'fx_date' => '',
            'fx_basis' => '',
            'notes' => '',
        ]];
    }

    public function validateBatch(array $rows, ImportContext $ctx): ?string
    {
        if ($rows === []) {
            return 'The opening AP file has no supplier items.';
        }

        // Once a full opening-GL journal exists, changing this subledger snapshot
        // would make the control account drift from the posted opening position.
        if ($ctx->batch->status !== 'imported' && $this->openingGlJournalExists()) {
            return 'Opening AP items are frozen because an opening-GL journal already exists; correct the live AP balance with an approved transaction instead.';
        }

        $ids = [];
        $dates = [];
        $references = [];
        $totalCents = 0;
        foreach ($rows as $row) {
            $externalId = $this->externalId($row['external_id'] ?? null);
            if ($externalId !== null) {
                if (isset($ids[$externalId])) {
                    return "external_id {$externalId} appears more than once; each open supplier item needs a unique stable id.";
                }
                $ids[$externalId] = true;
            }

            $openingDate = $this->date($row['opening_date'] ?? null);
            if ($openingDate !== null) {
                $dates[$openingDate] = true;
            }

            $amount = $this->moneyCents($row['open_amount'] ?? null);
            if ($amount !== null && $amount > 0) {
                if ($totalCents > self::MAX_BATCH_CENTS - $amount) {
                    return 'The opening AP snapshot exceeds the supported aggregate amount; split it only if each file remains a separately reconciled cutover.';
                }
                $totalCents += $amount;
            }

            $supplierExternalId = $this->externalId($row['supplier_external_id'] ?? null);
            $supplier = $supplierExternalId ? $this->supplierForExternalId($ctx, $supplierExternalId) : null;
            $itemType = $this->itemType($row['item_type'] ?? null);
            $supplierRef = $this->str($row['supplier_ref'] ?? null, 60);
            if ($supplier && $itemType && $supplierRef) {
                $key = $supplier->id.'|'.$itemType.'|'.mb_strtolower($supplierRef);
                if (isset($references[$key])) {
                    return "Supplier document reference {$supplierRef} appears more than once for this supplier and item type.";
                }
                $references[$key] = true;
            }
        }

        if (count($dates) > 1) {
            return 'Use one opening_date per AP snapshot so supplier balances are compared at a single cutover point.';
        }

        // Like the trial balance, an opening AP snapshot is atomic: one invalid
        // row blocks the whole file instead of importing a misleading partial balance.
        foreach ($rows as $index => $row) {
            $error = $this->validateRow($row, $ctx, $index + 2);
            if ($error !== null) {
                return 'Opening AP row '.($index + 2).': '.$error;
            }
        }

        return null;
    }

    public function validateRow(array $row, ImportContext $ctx, int $rowNo): ?string
    {
        $externalId = $this->externalId($row['external_id'] ?? null);
        if ($externalId === null) {
            return 'external_id is required and must be a stable legacy bill/credit id.';
        }

        $supplierExternalId = $this->externalId($row['supplier_external_id'] ?? null);
        if ($supplierExternalId === null) {
            return 'supplier_external_id is required; apply the suppliers import before opening AP.';
        }
        $supplier = $this->supplierForExternalId($ctx, $supplierExternalId);
        if (! $supplier) {
            return "Supplier external id {$supplierExternalId} is not mapped for this property; apply the suppliers import first.";
        }

        $itemType = $this->itemType($row['item_type'] ?? null);
        if ($itemType === null) {
            return 'item_type must be bill or credit.';
        }

        $openingDate = $this->date($row['opening_date'] ?? null);
        if ($openingDate === null) {
            return 'opening_date must be a valid as-of date.';
        }
        if ($openingDate > bdate()->toDateString()) {
            return 'opening_date cannot be after the property business date.';
        }
        $cutoff = LedgerService::cutoff();
        if ($openingDate > $cutoff) {
            return "opening_date must be on or before the closed-ledger cutoff ({$cutoff}).";
        }

        $documentDate = $this->date($row['document_date'] ?? null);
        if ($documentDate === null) {
            return 'document_date must be the original supplier document date.';
        }
        if ($documentDate > $openingDate) {
            return 'document_date cannot be after opening_date; this file carries forward balances already outstanding at cutover.';
        }

        $dueDateRaw = trim((string) ($row['due_date'] ?? ''));
        $dueDate = $this->date($dueDateRaw);
        if ($dueDateRaw !== '' && $dueDate === null) {
            return 'due_date must be a valid date when supplied.';
        }
        if ($itemType === 'bill') {
            if ($dueDate === null) {
                return 'due_date is required for a bill so opening AP ageing uses the source due date.';
            }
            if ($dueDate < $documentDate) {
                return 'due_date cannot be before document_date.';
            }
        } elseif ($dueDate !== null && $dueDate < $documentDate) {
            return 'A credit due_date, when supplied, cannot be before document_date.';
        }

        $supplierRef = $this->str($row['supplier_ref'] ?? null, 60);
        if ($supplierRef === null) {
            return 'supplier_ref is required; use the source document reference or a stable legacy identifier.';
        }
        if (mb_strlen(trim((string) ($row['supplier_ref'] ?? ''))) > 60) {
            return 'supplier_ref must not exceed 60 characters.';
        }

        $homeAmount = $this->moneyCents($row['open_amount'] ?? null);
        if ($homeAmount === null || $homeAmount <= 0 || $homeAmount > self::MAX_ROW_CENTS) {
            return 'open_amount must be positive, fit the AP ledger, and have no more than two decimal places.';
        }
        if (mb_strlen(trim((string) ($row['notes'] ?? ''))) > 500) {
            return 'notes must not exceed 500 characters.';
        }

        $fxError = $this->validateCurrency($row, $openingDate, $documentDate, $homeAmount);
        if ($fxError !== null) {
            return $fxError;
        }

        $mapped = $this->openingMap($ctx, $externalId);
        $expectedType = $itemType === 'bill' ? 'invoice' : 'debit_note';
        if ($mapped) {
            if ($mapped->model_type !== SupplierInvoice::class) {
                return "Opening AP id {$externalId} is already mapped to a different model type; resolve it manually.";
            }
            $doc = SupplierInvoice::find($mapped->model_id);
            if (! $doc || ! $doc->isOpening()) {
                return "Opening AP id {$externalId} no longer points to an opening supplier document; resolve it manually.";
            }
            if ($doc->supplier_id !== $supplier->id || $doc->type !== $expectedType) {
                return "Opening AP id {$externalId} is already attached to a different supplier or item type.";
            }
            if ($doc->status === 'cancelled') {
                return "Opening AP id {$externalId} was cancelled; use an approved new source id and document reference for a corrected balance.";
            }
            $duplicate = SupplierInvoice::where('supplier_id', $supplier->id)
                ->where('type', $expectedType)
                ->where('status', '!=', 'cancelled')
                ->where('supplier_ref', $supplierRef)
                ->where('id', '!=', $doc->id)
                ->exists();
            if ($duplicate) {
                return "Supplier document {$supplierRef} already exists for {$supplier->name}; choose or map the correct source AP document.";
            }

            $fields = $this->fields($row, $supplier, $itemType, $openingDate, $documentDate, $dueDate, $homeAmount);
            $dirty = $this->dirty($doc, $fields);
            $lineChanged = ! $this->openingLineMatches($doc, $itemType, $supplierRef, $openingDate, $homeAmount);
            if (($dirty || $lineChanged) && ($doc->allocations()->exists() || $doc->applications()->exists() || $doc->status !== 'open')) {
                return "Opening AP id {$externalId} already has allocations or subsequent activity and cannot be rewritten; enter a separate approved adjustment.";
            }
        } else {
            $expectedType = $itemType === 'bill' ? 'invoice' : 'debit_note';
            $duplicate = SupplierInvoice::where('supplier_id', $supplier->id)
                ->where('type', $expectedType)
                ->where('status', '!=', 'cancelled')
                ->where('supplier_ref', $supplierRef)
                ->exists();
            if ($duplicate) {
                return "Supplier document {$supplierRef} already exists for {$supplier->name}; map or resolve the existing AP document before importing it again.";
            }
        }

        return null;
    }

    public function importRow(array $row, ImportContext $ctx, bool $dryRun): array
    {
        $externalId = $this->externalId($row['external_id']);
        $supplierExternalId = $this->externalId($row['supplier_external_id']);
        $supplier = $supplierExternalId ? $this->supplierForExternalId($ctx, $supplierExternalId) : null;
        $itemType = $this->itemType($row['item_type'] ?? null);
        $openingDate = $this->date($row['opening_date'] ?? null);
        $documentDate = $this->date($row['document_date'] ?? null);
        $dueDate = $this->date($row['due_date'] ?? null);
        $homeAmount = $this->moneyCents($row['open_amount'] ?? null);

        if (! $supplier || ! $itemType || ! $openingDate || ! $documentDate || $homeAmount === null) {
            return $this->failed('A required supplier, date, item type or amount could not be resolved after validation.', null);
        }

        $fields = $this->fields($row, $supplier, $itemType, $openingDate, $documentDate, $dueDate, $homeAmount);
        $supplierRef = $fields['supplier_ref'];
        $lineDescription = $this->lineDescription($itemType, $supplierRef, $openingDate);
        $mapped = $this->openingMap($ctx, $externalId);
        $doc = $mapped && $mapped->model_type === SupplierInvoice::class
            ? SupplierInvoice::find($mapped->model_id)
            : null;

        if (! $doc) {
            if ($dryRun) {
                return ['status' => 'created', 'message' => 'Would create a non-posting opening AP item; no GL journal will be created.', 'model_type' => SupplierInvoice::class, 'model_id' => null];
            }
            $key = $itemType === 'bill' ? 'supplier_bill' : 'debit_note';
            $doc = SupplierInvoice::create($fields + [
                'doc_no' => Numbering::next($key),
                'allocated' => 0,
                'status' => 'open',
                'created_by' => auth()->id(),
            ]);
            $doc->lines()->create([
                'account_head_id' => null,
                'description' => $lineDescription,
                'amount' => $homeAmount / 100,
                'tax_rate' => 0,
                'tax_amount' => 0,
            ]);
            $ctx->map('opening_ap', $externalId, SupplierInvoice::class, $doc->id);

            return ['status' => 'created', 'message' => 'Opening AP item recorded in the supplier subledger; no GL journal was posted.', 'model_type' => SupplierInvoice::class, 'model_id' => $doc->id];
        }

        $dirty = $this->dirty($doc, $fields);
        $lineMatches = $this->openingLineMatches($doc, $itemType, $supplierRef, $openingDate, $homeAmount);
        if (! $dirty && $lineMatches) {
            if (! $dryRun) {
                $ctx->map('opening_ap', $externalId, SupplierInvoice::class, $doc->id);
            }

            return ['status' => 'unchanged', 'message' => 'Opening AP item is unchanged; no duplicate bill, credit or GL journal was created.', 'model_type' => SupplierInvoice::class, 'model_id' => $doc->id];
        }
        if ($dryRun) {
            return ['status' => 'updated', 'message' => 'Would update the opening AP item: '.implode(', ', array_keys($dirty) ?: ['opening item line']), 'model_type' => SupplierInvoice::class, 'model_id' => $doc->id];
        }

        $doc->fill($dirty)->save();
        $doc->lines()->delete();
        $doc->lines()->create([
            'account_head_id' => null,
            'description' => $lineDescription,
            'amount' => $homeAmount / 100,
            'tax_rate' => 0,
            'tax_amount' => 0,
        ]);
        $ctx->map('opening_ap', $externalId, SupplierInvoice::class, $doc->id);

        return ['status' => 'updated', 'message' => 'Opening AP item updated; no GL journal was posted.', 'model_type' => SupplierInvoice::class, 'model_id' => $doc->id];
    }

    public function reconcile(ImportContext $ctx): array
    {
        $rows = $ctx->batch->rows()->orderBy('row_no')->get()
            ->map(fn ($row) => (array) $row->payload)->all();
        if ($rows === []) {
            return ['Opening AP status' => 'BLOCKED — no supplier items were found in this batch.'];
        }
        $openingDate = $this->date($rows[0]['opening_date'] ?? null);
        if ($openingDate === null) {
            return ['Opening AP status' => 'BLOCKED — the AP as-of date is invalid.'];
        }

        $billCents = 0;
        $creditCents = 0;
        $mappedContributionCents = 0;
        $mappedCount = 0;
        foreach ($rows as $row) {
            $amount = $this->moneyCents($row['open_amount'] ?? null) ?? 0;
            if ($this->itemType($row['item_type'] ?? null) === 'bill') {
                $billCents += $amount;
            } else {
                $creditCents += $amount;
            }
            $externalId = $this->externalId($row['external_id'] ?? null);
            $mapped = $externalId ? $this->openingMap($ctx, $externalId) : null;
            if ($mapped && $mapped->model_type === SupplierInvoice::class) {
                $doc = SupplierInvoice::find($mapped->model_id);
                if ($doc) {
                    $mappedCount++;
                    $mappedContributionCents += $this->controlContributionCents($doc, $openingDate);
                }
            }
        }

        $currentControlCents = (int) round(ApService::controlBalance($openingDate) * 100);
        $batchNetCents = $billCents - $creditCents;
        $projectedControlCents = $currentControlCents + $batchNetCents - $mappedContributionCents;
        $account = \App\Models\AccountHead::where('system_key', 'accounts_payable')->first();
        $currentGlCents = $account ? (int) round(-LedgerService::balance('accounts_payable', $openingDate) * 100) : null;

        return [
            'Opening AP as-of date' => $openingDate,
            'Open supplier items in this file' => count($rows),
            'Opening bills in property currency' => $this->money($billCents),
            'Unapplied supplier credits in property currency' => $this->money($creditCents),
            'Net AP items in this file' => $this->money($batchNetCents),
            'Current AP subledger control at as-of date' => $this->money($currentControlCents),
            'Projected AP subledger control after apply' => $this->money($projectedControlCents),
            'Existing AP GL credit balance at as-of date' => $currentGlCents === null ? 'AP control account missing' : $this->money($currentGlCents),
            'Rows already mapped from this dataset' => $mappedCount,
            'Historical AP GL journals posted by this dataset' => '0 — opening items are non-posting; the opening trial balance establishes AP once',
        ];
    }

    private function fields(array $row, Supplier $supplier, string $itemType, string $openingDate, string $documentDate, ?string $dueDate, int $homeAmountCents): array
    {
        $supplierRef = $this->str($row['supplier_ref'] ?? null, 60) ?? $this->externalId($row['external_id'] ?? null);
        $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
        $homeCurrency = strtoupper(trim((string) (hotel()?->currency ?: 'GHS')));
        $currency = $currency === '' || $currency === $homeCurrency ? null : $currency;
        $fx = $currency ? $this->fxFields($row) : [
            'fx_subtotal' => null,
            'fx_tax' => null,
            'fx_total' => null,
            'fx_rate' => null,
            'fx_date' => null,
            'fx_basis' => null,
        ];
        $sourceNotes = $this->str($row['notes'] ?? null, 500);
        $notes = 'Opening AP item as at '.$openingDate.'; only the outstanding balance is carried forward. Historical bills, payments and GL activity were not replayed.'
            .($sourceNotes ? "\nSource note: {$sourceNotes}" : '');

        return array_merge([
            'type' => $itemType === 'bill' ? 'invoice' : 'debit_note',
            'kind' => 'expense',
            'supplier_id' => $supplier->id,
            'purchase_order_id' => null,
            'against_invoice_id' => null,
            'supplier_ref' => $supplierRef,
            'doc_date' => $documentDate,
            'due_date' => $itemType === 'bill' ? $dueDate : $documentDate,
            'subtotal' => $homeAmountCents / 100,
            'tax' => 0,
            'total' => $homeAmountCents / 100,
            'grn_total' => null,
            'opening_date' => $openingDate,
            'notes' => mb_substr($notes, 0, 500),
            'currency' => $currency,
        ], $fx);
    }

    private function validateCurrency(array $row, string $openingDate, string $documentDate, int $homeAmountCents): ?string
    {
        $rawCurrency = strtoupper(trim((string) ($row['currency'] ?? '')));
        $homeCurrency = strtoupper(trim((string) (hotel()?->currency ?: 'GHS')));
        if ($rawCurrency !== '' && ! preg_match('/^[A-Z]{3}$/', $rawCurrency)) {
            return 'currency must be a three-letter ISO-style code (or blank for the property currency).';
        }
        $currency = $rawCurrency === '' ? $homeCurrency : $rawCurrency;
        $foreignRaw = trim((string) ($row['foreign_open_amount'] ?? ''));
        $rateRaw = trim((string) ($row['fx_rate'] ?? ''));
        $foreignCents = $this->moneyCents($foreignRaw);
        $rate = $this->rateValue($rateRaw);
        $fxDateRaw = trim((string) ($row['fx_date'] ?? ''));
        $basisRaw = strtolower(trim((string) ($row['fx_basis'] ?? '')));
        $hasFxData = $foreignRaw !== '' || $rateRaw !== '' || $fxDateRaw !== '' || $basisRaw !== ''; 

        if ($currency === $homeCurrency) {
            return $hasFxData ? 'Foreign-currency fields must be blank when currency is the property currency.' : null;
        }
        if ($foreignCents === null || $foreignCents <= 0 || $foreignCents > self::MAX_ROW_CENTS) {
            return 'foreign_open_amount must be positive with no more than two decimal places for a foreign-currency item.';
        }
        if ($rate === null || $rate <= 0 || $rate > 999999999.9999 || ! $this->hasFourDecimals($rate)) {
            return 'fx_rate must be a positive source carrying rate with no more than four decimal places; current market rates are not substituted.';
        }
        $fxDate = $this->date($fxDateRaw);
        if ($fxDate === null || $fxDate > $openingDate) {
            return 'fx_date must be a valid source rate date on or before opening_date.';
        }
        $basis = $basisRaw === '' ? 'legacy' : $basisRaw;
        if (! in_array($basis, ['reference', 'buy', 'deal', 'legacy'], true)) {
            return 'fx_basis must be reference, buy, deal, or legacy.';
        }
        $convertedValue = $foreignCents * $rate;
        if (! is_finite($convertedValue) || $convertedValue > self::MAX_ROW_CENTS + 1) {
            return 'foreign_open_amount × fx_rate exceeds the AP ledger precision.';
        }
        $convertedCents = (int) round($convertedValue);
        if ($convertedCents !== $homeAmountCents) {
            return 'open_amount must equal foreign_open_amount × fx_rate to the cent; resolve any source revaluation separately before import.';
        }

        return null;
    }

    private function fxFields(array $row): array
    {
        $foreignCents = $this->moneyCents($row['foreign_open_amount'] ?? null) ?? 0;
        $fxRate = $this->rateValue($row['fx_rate'] ?? null) ?? 0.0;
        $fxDate = $this->date($row['fx_date'] ?? null);
        $basis = strtolower(trim((string) ($row['fx_basis'] ?? ''))) ?: 'legacy';

        return [
            'fx_subtotal' => $foreignCents / 100,
            'fx_tax' => 0,
            'fx_total' => $foreignCents / 100,
            'fx_rate' => $fxRate,
            'fx_date' => $fxDate,
            'fx_basis' => $basis,
        ];
    }

    private function supplierForExternalId(ImportContext $ctx, string $externalId): ?Supplier
    {
        $map = DataMigrationIdMap::withoutGlobalScopes()
            ->where('hotel_id', $ctx->hotel->id)
            ->where('dataset', 'suppliers')
            ->where('external_id', $externalId)
            ->first();
        if (! $map || $map->model_type !== Supplier::class) {
            return null;
        }

        return Supplier::find($map->model_id);
    }

    private function openingMap(ImportContext $ctx, string $externalId): ?DataMigrationIdMap
    {
        return DataMigrationIdMap::withoutGlobalScopes()
            ->where('hotel_id', $ctx->hotel->id)
            ->where('dataset', 'opening_ap')
            ->where('external_id', $externalId)
            ->first();
    }

    private function itemType(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));
        return match ($value) {
            'bill', 'invoice' => 'bill',
            'credit', 'supplier_credit', 'debit_note' => 'credit',
            default => null,
        };
    }

    private function openingLineMatches(SupplierInvoice $doc, string $itemType, string $supplierRef, string $openingDate, int $amountCents): bool
    {
        $lines = $doc->lines()->get();
        if ($lines->count() !== 1) {
            return false;
        }
        $line = $lines->first();
        $expectedDescription = $this->lineDescription($itemType, $supplierRef, $openingDate);

        return $line->account_head_id === null
            && $line->description === $expectedDescription
            && abs((int) round((float) $line->amount * 100) - $amountCents) === 0
            && abs((float) $line->tax_rate) < 0.000001
            && abs((float) $line->tax_amount) < 0.005;
    }

    private function lineDescription(string $itemType, string $supplierRef, string $openingDate): string
    {
        $label = $itemType === 'bill' ? 'Opening supplier payable' : 'Opening supplier credit';

        return mb_substr($label.' as at '.$openingDate.' · '.$supplierRef, 0, 200);
    }

    private function controlContributionCents(SupplierInvoice $doc, string $asOf): int
    {
        if ($doc->doc_date?->toDateString() > $asOf || $doc->status === 'cancelled') {
            return 0;
        }
        if ($doc->isDebitNote()) {
            return -(int) round((float) $doc->total * 100);
        }
        if (in_array($doc->status, ['open', 'partial', 'paid'], true)) {
            return (int) round((float) $doc->total * 100);
        }

        return 0;
    }

    private function openingGlJournalExists(): bool
    {
        return GlJournal::where('source_type', 'manual')
            ->where('source_key', OpeningGlImporter::SOURCE_KEY)
            ->exists();
    }

    private function moneyCents(?string $raw): ?int
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return null;
        }
        $value = str_ireplace(['GH₵', 'GHS', '₵', '¢', '$', '£', '€'], '', $value);
        $value = preg_replace('/\s+/', '', $value) ?? '';
        if (! preg_match('/^(?:\d+|\d{1,3}(?:,\d{3})+)(?:\.\d{1,2})?$/', $value)) {
            return null;
        }
        $value = str_replace(',', '', $value);
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';
        if (strlen($whole) > 10) {
            return null;
        }
        $cents = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');

        return $cents <= self::MAX_ROW_CENTS ? $cents : null;
    }

    private function rateValue(?string $raw): ?float
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return null;
        }
        if (! preg_match('/^(?:\d+|\d{1,3}(?:,\d{3})+)(?:\.\d{1,4})?$/', $value)) {
            return null;
        }
        $rate = (float) str_replace(',', '', $value);

        return is_finite($rate) ? $rate : null;
    }

    private function hasFourDecimals(float $value): bool
    {
        return abs($value - round($value, 4)) < 0.00000001;
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', ',');
    }

    private function failed(string $message, ?int $modelId): array
    {
        return ['status' => 'failed', 'message' => $message, 'model_type' => SupplierInvoice::class, 'model_id' => $modelId];
    }
}
