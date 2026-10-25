<?php

namespace App\Services\Migration\Importers;

use App\Models\Company;
use App\Models\DataMigrationIdMap;
use App\Models\Folio;
use App\Models\FolioTransaction;
use App\Models\GlJournal;
use App\Models\Guest;
use App\Models\Voucher;
use App\Services\CreditService;
use App\Services\LedgerService;
use App\Services\Migration\BaseImporter;
use App\Services\Migration\ImportContext;
use App\Support\Numbering;

/**
 * Opening guest-folio and company city-ledger receivable items. This is a
 * non-posting snapshot: each positive open document balance is preserved as a
 * special folio transaction, and the full opening TB establishes the controls.
 */
class OpeningArImporter extends BaseImporter
{
    private const MAX_ROW_CENTS = 99999999999999; // folio_transactions.amount is DECIMAL(14, 2)

    private const MAX_BATCH_CENTS = 9999999999999999;

    public function label(): string
    {
        return 'Opening guest / city-ledger receivables (non-posting)';
    }

    public function columns(): array
    {
        return [
            'external_id' => ['required' => true, 'desc' => 'Stable legacy id for this open guest-folio or company invoice item', 'example' => 'AR-OPEN-INV-1042', 'aliases' => ['id', 'row_id', 'legacy_id']],
            'item_type' => ['required' => true, 'desc' => 'guest_folio or company_invoice', 'example' => 'guest_folio', 'aliases' => ['type', 'record_type']],
            'opening_date' => ['required' => true, 'desc' => 'As-of/cutover date for this receivable snapshot; use the same date as opening_gl', 'example' => '2026-10-22', 'aliases' => ['as_of_date', 'cutover_date']],
            'document_date' => ['required' => true, 'desc' => 'Original guest folio or company invoice date', 'example' => '2026-10-10', 'aliases' => ['doc_date', 'invoice_date', 'folio_date']],
            'due_date' => ['required' => false, 'desc' => 'Original due date; required for company invoices and retained when supplied for guest folios', 'example' => '2026-11-09', 'aliases' => ['payment_due_date']],
            'source_ref' => ['required' => true, 'desc' => 'Legacy folio or invoice reference; use a stable source identifier if none exists', 'example' => 'INV-1042', 'aliases' => ['invoice_no', 'folio_no', 'document_ref', 'reference']],
            'open_amount' => ['required' => true, 'desc' => 'Positive unpaid balance remaining at cutover, in property currency', 'example' => '1280.50', 'aliases' => ['balance', 'outstanding', 'amount']],
            'guest_external_id' => ['required' => false, 'desc' => 'Mapped guest id; required for guest_folio and optional for a company invoice', 'example' => 'G-2001', 'aliases' => ['guest_id', 'legacy_guest_id']],
            'company_external_id' => ['required' => false, 'desc' => 'Mapped company id; required for company_invoice and blank for guest_folio', 'example' => 'C-1001', 'aliases' => ['company_id', 'legacy_company_id']],
            'notes' => ['required' => false, 'desc' => 'Optional source note; historical charges, tax and settlement history are not replayed', 'example' => 'Balance per final guest statement'],
        ];
    }

    public function templateRows(): array
    {
        $cutoff = LedgerService::cutoff();

        return [
            [
                'external_id' => 'AR-OPEN-GUEST-1042',
                'item_type' => 'guest_folio',
                'opening_date' => $cutoff,
                'document_date' => date('Y-m-d', strtotime($cutoff.' -12 days')),
                'due_date' => '',
                'source_ref' => 'FOL-1042',
                'open_amount' => '1280.50',
                'guest_external_id' => 'G-2001',
                'company_external_id' => '',
                'notes' => 'Outstanding guest folio balance',
            ],
            [
                'external_id' => 'AR-OPEN-COMPANY-2055',
                'item_type' => 'company_invoice',
                'opening_date' => $cutoff,
                'document_date' => date('Y-m-d', strtotime($cutoff.' -20 days')),
                'due_date' => date('Y-m-d', strtotime($cutoff.' +10 days')),
                'source_ref' => 'INV-2055',
                'open_amount' => '2450.00',
                'guest_external_id' => '',
                'company_external_id' => 'C-1001',
                'notes' => 'Outstanding company city-ledger invoice',
            ],
        ];
    }

    public function validateBatch(array $rows, ImportContext $ctx): ?string
    {
        if ($rows === []) {
            return 'The opening AR file has no receivable items.';
        }
        if ($ctx->batch->status !== 'imported' && $this->openingGlJournalExists()) {
            return 'Opening receivable items are frozen because an opening-GL journal already exists; use an approved live settlement or adjustment instead.';
        }

        $ids = [];
        $dates = [];
        $references = [];
        $totalCents = 0;
        foreach ($rows as $row) {
            $externalId = $this->externalId($row['external_id'] ?? null);
            if ($externalId !== null) {
                if (isset($ids[$externalId])) {
                    return "external_id {$externalId} appears more than once; each open receivable item needs a unique stable id.";
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
                    return 'The opening AR snapshot exceeds the supported aggregate amount; investigate the source totals before splitting the cutover.';
                }
                $totalCents += $amount;
            }

            $itemType = $this->itemType($row['item_type'] ?? null);
            $sourceRef = $this->str($row['source_ref'] ?? null, 80);
            $guestExt = $this->externalId($row['guest_external_id'] ?? null);
            $companyExt = $this->externalId($row['company_external_id'] ?? null);
            $partyId = $itemType === 'guest_folio'
                ? $this->guestForExternalId($ctx, $guestExt)?->id
                : $this->companyForExternalId($ctx, $companyExt)?->id;
            if ($itemType && $sourceRef && $partyId) {
                $key = $itemType.'|'.$partyId.'|'.mb_strtolower($sourceRef);
                if (isset($references[$key])) {
                    return "Source reference {$sourceRef} appears more than once for the same receivable owner and item type.";
                }
                $references[$key] = true;
            }
        }

        if (count($dates) > 1) {
            return 'Use one opening_date per AR snapshot so guest and company balances are compared at the same cutover point.';
        }

        foreach ($rows as $index => $row) {
            $error = $this->validateRow($row, $ctx, $index + 2);
            if ($error !== null) {
                return 'Opening AR row '.($index + 2).': '.$error;
            }
        }

        return null;
    }

    public function validateRow(array $row, ImportContext $ctx, int $rowNo): ?string
    {
        $externalId = $this->externalId($row['external_id'] ?? null);
        if ($externalId === null) {
            return 'external_id is required and must be a stable legacy folio/invoice id.';
        }
        $itemType = $this->itemType($row['item_type'] ?? null);
        if ($itemType === null) {
            return 'item_type must be guest_folio or company_invoice.';
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
        $lockDate = LedgerService::lockDate();
        if ($lockDate && $openingDate <= $lockDate) {
            return "opening_date must be after the GL lock date ({$lockDate}); the historical period cannot be edited.";
        }

        $documentDate = $this->date($row['document_date'] ?? null);
        if ($documentDate === null) {
            return 'document_date must be the original guest folio or company invoice date.';
        }
        if ($documentDate > $openingDate) {
            return 'document_date cannot be after opening_date; only balances outstanding at cutover can be imported.';
        }

        $dueDateRaw = trim((string) ($row['due_date'] ?? ''));
        $dueDate = $this->date($dueDateRaw);
        if ($dueDateRaw !== '' && $dueDate === null) {
            return 'due_date must be a valid date when supplied.';
        }
        if ($itemType === 'company_invoice' && $dueDate === null) {
            return 'due_date is required for a company_invoice so the original city-ledger ageing is retained.';
        }
        if ($dueDate !== null && $dueDate < $documentDate) {
            return 'due_date cannot be before document_date.';
        }

        $sourceRef = $this->str($row['source_ref'] ?? null, 80);
        if ($sourceRef === null) {
            return 'source_ref is required; use the source folio/invoice number or a stable legacy identifier.';
        }
        if (mb_strlen(trim((string) ($row['source_ref'] ?? ''))) > 80) {
            return 'source_ref must not exceed 80 characters.';
        }
        $amountCents = $this->moneyCents($row['open_amount'] ?? null);
        if ($amountCents === null || $amountCents <= 0 || $amountCents > self::MAX_ROW_CENTS) {
            return 'open_amount must be a positive property-currency balance, fit the folio ledger, and have no more than two decimal places.';
        }
        if (mb_strlen(trim((string) ($row['notes'] ?? ''))) > 500) {
            return 'notes must not exceed 500 characters.';
        }

        $guestExternalId = $this->externalId($row['guest_external_id'] ?? null);
        $companyExternalId = $this->externalId($row['company_external_id'] ?? null);
        $guest = null;
        $company = null;
        if ($itemType === 'guest_folio') {
            if ($guestExternalId === null) {
                return 'guest_external_id is required for guest_folio items.';
            }
            if ($companyExternalId !== null) {
                return 'company_external_id must be blank for guest_folio items; use company_invoice for city-ledger balances.';
            }
            $guest = $this->guestForExternalId($ctx, $guestExternalId);
            if (! $guest) {
                return "Guest external id {$guestExternalId} is not mapped for this property; apply the guests import first.";
            }
        } else {
            if ($companyExternalId === null) {
                return 'company_external_id is required for company_invoice items.';
            }
            $company = $this->companyForExternalId($ctx, $companyExternalId);
            if (! $company) {
                return "Company external id {$companyExternalId} is not mapped for this property; apply the companies import first.";
            }
            if ($guestExternalId !== null) {
                $guest = $this->guestForExternalId($ctx, $guestExternalId);
                if (! $guest) {
                    return "Guest external id {$guestExternalId} is not mapped for this property; apply the guests import first.";
                }
            }
        }

        $mapped = $this->openingMap($ctx, $externalId);
        $category = $itemType === 'guest_folio' ? 'opening_ar' : 'city_ledger';
        if ($mapped) {
            if ($mapped->model_type !== Folio::class) {
                return "Opening AR id {$externalId} is already mapped to a different model type; resolve it manually.";
            }
            $folio = Folio::find($mapped->model_id);
            if (! $folio) {
                return "Opening AR id {$externalId} no longer points to a folio; resolve it manually.";
            }
            $expectedFolioType = $itemType === 'guest_folio' ? 'walk_in' : 'master';
            if ($folio->type !== $expectedFolioType || $folio->guest_id !== $guest?->id || $folio->company_id !== $company?->id) {
                return "Opening AR id {$externalId} is already attached to a different guest, company or item type.";
            }
            $openingRows = $folio->transactions()->where('source_type', 'opening_ar')->where('type', 'opening_balance')->get();
            if ($openingRows->count() !== 1 || $openingRows->first()->is_void || ! $openingRows->first()->isOpening()
                || $openingRows->first()->category !== $category) {
                return "Opening AR id {$externalId} no longer has one valid opening-balance transaction; resolve it manually.";
            }
            $openingTx = $openingRows->first();
            $duplicate = $this->duplicateSourceReference($itemType, $guest?->id, $company?->id, $sourceRef, $openingTx->id);
            if ($duplicate) {
                return "Source reference {$sourceRef} already exists for this guest or company; map or resolve the existing receivable before importing it again.";
            }

            $folioFields = $this->folioFields($itemType, $guest, $company);
            $txFields = $this->transactionFields($row, $itemType, $openingDate, $documentDate, $dueDate, $sourceRef, $amountCents, $company);
            $dirty = $this->dirty($folio, $folioFields) || $this->dirty($openingTx, $txFields);
            if ($dirty && $this->hasSubsequentActivity($itemType, $folio, $openingTx, $company, $openingDate)) {
                return "Opening AR id {$externalId} has subsequent charges, settlements or other activity and cannot be rewritten; record an approved live adjustment instead.";
            }
            if ($dirty && (($itemType === 'guest_folio' && $folio->status !== 'open') || ($itemType === 'company_invoice' && $folio->status !== 'closed'))) {
                return "Opening AR id {$externalId} is no longer in its original open-item state and cannot be rewritten.";
            }
        } else {
            $duplicate = $this->duplicateSourceReference($itemType, $guest?->id, $company?->id, $sourceRef);
            if ($duplicate) {
                return "Source reference {$sourceRef} already exists for this guest or company; map or resolve the existing receivable before importing it again.";
            }
        }

        return null;
    }

    public function importRow(array $row, ImportContext $ctx, bool $dryRun): array
    {
        $externalId = $this->externalId($row['external_id']);
        $itemType = $this->itemType($row['item_type'] ?? null);
        $openingDate = $this->date($row['opening_date'] ?? null);
        $documentDate = $this->date($row['document_date'] ?? null);
        $dueDate = $this->date($row['due_date'] ?? null);
        $sourceRef = $this->str($row['source_ref'] ?? null, 80);
        $amountCents = $this->moneyCents($row['open_amount'] ?? null);
        $guestExternalId = $this->externalId($row['guest_external_id'] ?? null);
        $companyExternalId = $this->externalId($row['company_external_id'] ?? null);
        $guest = $guestExternalId ? $this->guestForExternalId($ctx, $guestExternalId) : null;
        $company = $companyExternalId ? $this->companyForExternalId($ctx, $companyExternalId) : null;

        if (! $itemType || ! $openingDate || ! $documentDate || ! $sourceRef || $amountCents === null
            || ($itemType === 'guest_folio' && ! $guest) || ($itemType === 'company_invoice' && ! $company)) {
            return ['status' => 'failed', 'message' => 'A required guest, company, date, item type or amount could not be resolved after validation.', 'model_type' => null, 'model_id' => null];
        }

        $folioFields = $this->folioFields($itemType, $guest, $company);
        $txFields = $this->transactionFields($row, $itemType, $openingDate, $documentDate, $dueDate, $sourceRef, $amountCents, $company);
        $mapped = $this->openingMap($ctx, $externalId);
        $folio = $mapped && $mapped->model_type === Folio::class ? Folio::find($mapped->model_id) : null;
        $openingTx = $folio?->transactions()->where('source_type', 'opening_ar')->where('type', 'opening_balance')->first();

        if (! $folio || ! $openingTx) {
            if ($dryRun) {
                return ['status' => 'created', 'message' => 'Would create a non-posting opening guest/city-ledger receivable item; no historical GL journal will be created.', 'model_type' => Folio::class, 'model_id' => null];
            }
            $folio = Folio::create($folioFields + [
                'folio_no' => Numbering::next('folio'),
                'status' => $itemType === 'guest_folio' ? 'open' : 'closed',
                'invoice_no' => null,
            ]);
            $openingTx = $folio->transactions()->create($txFields + [
                'source_type' => 'opening_ar',
                'source_id' => null,
                'posted_by' => auth()->id(),
                'is_void' => false,
            ]);
            $ctx->map('opening_ar', $externalId, Folio::class, $folio->id);

            return ['status' => 'created', 'message' => 'Opening receivable item recorded in the guest/city-ledger subledger; no historical revenue, tax or GL journal was posted.', 'model_type' => Folio::class, 'model_id' => $folio->id];
        }

        $folioDirty = $this->dirty($folio, $folioFields);
        $txDirty = $this->dirty($openingTx, $txFields);
        if (! $folioDirty && ! $txDirty) {
            if (! $dryRun) {
                $ctx->map('opening_ar', $externalId, Folio::class, $folio->id);
            }

            return ['status' => 'unchanged', 'message' => 'Opening AR item is unchanged; no duplicate folio, receivable or GL journal was created.', 'model_type' => Folio::class, 'model_id' => $folio->id];
        }
        if ($dryRun) {
            return ['status' => 'updated', 'message' => 'Would update the opening AR item: '.implode(', ', array_keys($folioDirty + $txDirty)), 'model_type' => Folio::class, 'model_id' => $folio->id];
        }

        $folio->fill($folioDirty)->save();
        $openingTx->fill($txDirty)->save();
        $ctx->map('opening_ar', $externalId, Folio::class, $folio->id);

        return ['status' => 'updated', 'message' => 'Opening AR item updated before subsequent activity; no GL journal was posted.', 'model_type' => Folio::class, 'model_id' => $folio->id];
    }

    public function reconcile(ImportContext $ctx): array
    {
        $rows = $ctx->batch->rows()->orderBy('row_no')->get()->map(fn ($row) => (array) $row->payload)->all();
        if ($rows === []) {
            return ['Opening AR status' => 'BLOCKED — no receivable items were found in this batch.'];
        }
        $openingDate = $this->date($rows[0]['opening_date'] ?? null);
        if ($openingDate === null) {
            return ['Opening AR status' => 'BLOCKED — the receivable as-of date is invalid.'];
        }

        $guestCents = 0;
        $cityCents = 0;
        $mappedGuestCents = 0;
        $mappedCityCents = 0;
        $mappedCount = 0;
        foreach ($rows as $row) {
            $amount = $this->moneyCents($row['open_amount'] ?? null) ?? 0;
            if ($this->itemType($row['item_type'] ?? null) === 'guest_folio') {
                $guestCents += $amount;
            } else {
                $cityCents += $amount;
            }
            $externalId = $this->externalId($row['external_id'] ?? null);
            $mapped = $externalId ? $this->openingMap($ctx, $externalId) : null;
            if ($mapped && $mapped->model_type === Folio::class) {
                $folio = Folio::find($mapped->model_id);
                $tx = $folio?->transactions()->where('source_type', 'opening_ar')->where('type', 'opening_balance')->first();
                if ($tx && ! $tx->is_void) {
                    $mappedCount++;
                    if ($tx->category === 'opening_ar') {
                        $mappedGuestCents += (int) round((float) $tx->amount * 100);
                    } elseif ($tx->category === 'city_ledger') {
                        $mappedCityCents += (int) round((float) $tx->amount * 100);
                    }
                }
            }
        }

        $controls = collect(LedgerService::controls($openingDate))->keyBy('key');
        $currentGuestCents = (int) round((float) ($controls['guest_ledger']['sub'] ?? 0) * 100);
        $currentCityCents = (int) round((float) ($controls['city_ledger']['sub'] ?? 0) * 100);
        $guestGlCents = (int) round(LedgerService::balance('guest_ledger', $openingDate) * 100);
        $cityGlCents = (int) round(LedgerService::balance('city_ledger', $openingDate) * 100);
        $projectedGuestCents = $currentGuestCents + $guestCents - $mappedGuestCents;
        $projectedCityCents = $currentCityCents + $cityCents - $mappedCityCents;

        return [
            'Opening AR as-of date' => $openingDate,
            'Open receivable documents in this file' => count($rows),
            'Guest folio balances in property currency' => $this->money($guestCents),
            'Company invoice balances in property currency' => $this->money($cityCents),
            'Rows already mapped from this dataset' => $mappedCount,
            'Current guest-ledger subledger control' => $this->money($currentGuestCents),
            'Projected guest-ledger control after apply' => $this->money($projectedGuestCents),
            'Existing guest-ledger GL debit balance' => $this->money($guestGlCents),
            'Projected guest-ledger control less existing GL' => $this->money($projectedGuestCents - $guestGlCents),
            'Current city-ledger subledger control' => $this->money($currentCityCents),
            'Projected city-ledger control after apply' => $this->money($projectedCityCents),
            'Existing city-ledger GL debit balance' => $this->money($cityGlCents),
            'Projected city-ledger control less existing GL' => $this->money($projectedCityCents - $cityGlCents),
            'Historical guest/city-ledger GL journals posted by this dataset' => '0 — opening receivables are non-posting; the opening trial balance establishes both controls once',
        ];
    }

    private function folioFields(string $itemType, ?Guest $guest, ?Company $company): array
    {
        return [
            'type' => $itemType === 'guest_folio' ? 'walk_in' : 'master',
            'guest_id' => $guest?->id,
            'company_id' => $company?->id,
            'reservation_id' => null,
            'reservation_room_id' => null,
        ];
    }

    private function transactionFields(array $row, string $itemType, string $openingDate, string $documentDate, ?string $dueDate, string $sourceRef, int $amountCents, ?Company $company): array
    {
        return [
            'business_date' => $documentDate,
            'opening_date' => $openingDate,
            'due_date' => $dueDate,
            'type' => 'opening_balance',
            'category' => $itemType === 'guest_folio' ? 'opening_ar' : 'city_ledger',
            'description' => $this->description($itemType, $sourceRef, $openingDate, $row['notes'] ?? null),
            'amount' => $amountCents / 100,
            'tax_amount' => 0,
            'tax_rate' => 0,
            'payment_mode_id' => null,
            'company_id' => $company?->id,
            'reference' => $sourceRef,
        ];
    }

    private function description(string $itemType, string $sourceRef, string $openingDate, ?string $notes): string
    {
        $prefix = $itemType === 'guest_folio' ? 'Opening guest folio receivable' : 'Opening company invoice receivable';
        $description = $prefix.' as at '.$openingDate.' · '.$sourceRef;
        $sourceNote = $this->str($notes, 500);
        if ($sourceNote) {
            $description .= ' · '.$sourceNote;
        }

        return mb_substr($description, 0, 255);
    }

    private function guestForExternalId(ImportContext $ctx, ?string $externalId): ?Guest
    {
        if ($externalId === null) {
            return null;
        }
        $map = $this->sourceMap($ctx, 'guests', $externalId);
        if (! $map || $map->model_type !== Guest::class) {
            return null;
        }

        return Guest::find($map->model_id);
    }

    private function companyForExternalId(ImportContext $ctx, ?string $externalId): ?Company
    {
        if ($externalId === null) {
            return null;
        }
        $map = $this->sourceMap($ctx, 'companies', $externalId);
        if (! $map || $map->model_type !== Company::class) {
            return null;
        }

        return Company::find($map->model_id);
    }

    private function sourceMap(ImportContext $ctx, string $dataset, string $externalId): ?DataMigrationIdMap
    {
        return DataMigrationIdMap::withoutGlobalScopes()->where('hotel_id', $ctx->hotel->id)
            ->where('dataset', $dataset)->where('external_id', $externalId)->first();
    }

    private function openingMap(ImportContext $ctx, string $externalId): ?DataMigrationIdMap
    {
        return $this->sourceMap($ctx, 'opening_ar', $externalId);
    }

    private function itemType(?string $value): ?string
    {
        return match (strtolower(trim((string) $value))) {
            'guest_folio', 'guest', 'folio' => 'guest_folio',
            'company_invoice', 'city_invoice', 'company' => 'company_invoice',
            default => null,
        };
    }

    private function duplicateSourceReference(string $itemType, ?int $guestId, ?int $companyId, string $sourceRef, ?int $exceptTransactionId = null): bool
    {
        $category = $itemType === 'guest_folio' ? 'opening_ar' : 'city_ledger';

        return FolioTransaction::where('category', $category)->where('reference', $sourceRef)->where('is_void', false)
            ->when($exceptTransactionId, fn ($query) => $query->where('id', '!=', $exceptTransactionId))
            ->when($itemType === 'guest_folio', fn ($query) => $query->whereHas('folio', fn ($folio) => $folio->where('guest_id', $guestId)))
            ->when($itemType === 'company_invoice', fn ($query) => $query->where('company_id', $companyId))
            ->exists();
    }

    private function hasSubsequentActivity(string $itemType, Folio $folio, FolioTransaction $openingTx, ?Company $company, string $openingDate): bool
    {
        if ($itemType === 'guest_folio') {
            return $folio->transactions()->where('id', '!=', $openingTx->id)->exists();
        }
        if (! $company) {
            return true;
        }

        // The opening balance represents the end-of-day position: activity on
        // the cutover date is ambiguous for a later rewrite, so freeze it too.
        $laterCityLedgerActivity = FolioTransaction::where('company_id', $company->id)->where('category', 'city_ledger')
            ->where('is_void', false)->where(function ($query) {
                $query->whereNull('source_type')->orWhere('source_type', '!=', 'opening_ar');
            })->whereDate('business_date', '>=', $openingDate)->exists();
        $laterReceipt = Voucher::where('company_id', $company->id)->where('type', 'receipt')->whereDate('date', '>=', $openingDate)->exists();

        return $laterCityLedgerActivity || $laterReceipt;
    }

    private function openingGlJournalExists(): bool
    {
        return GlJournal::where('source_type', 'manual')->where('source_key', OpeningGlImporter::SOURCE_KEY)->exists();
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
        if (strlen($whole) > 12) {
            return null;
        }
        $cents = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');

        return $cents <= self::MAX_ROW_CENTS ? $cents : null;
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', ',');
    }
}
