<?php

namespace App\Services\Migration\Importers;

use App\Models\DataMigrationIdMap;
use App\Models\SupplierPaidHistoryArchive;
use App\Models\Supplier;
use App\Services\LedgerService;
use App\Services\Migration\BaseImporter;
use App\Services\Migration\ImportContext;

/**
 * Immutable archive-only retention for paid supplier documents and settlements.
 *
 * It does not create SupplierInvoice, SupplierPayment, ApAllocation, ID-map or
 * GL records, and it does not approve or change the provisional opening-AP policy.
 */
class PaidSupplierHistoryArchiveImporter extends BaseImporter
{
    private const MAX_AMOUNT = 999999999999.99; // archive amount is DECIMAL(14, 2)

    private const RECORD_TYPES = ['paid_bill', 'payment', 'allocation', 'supplier_credit', 'credit_application'];

    public function label(): string
    {
        return 'Paid supplier history archive (no AP or GL writes)';
    }

    public function columns(): array
    {
        return [
            'external_id' => ['required' => true, 'desc' => 'Stable legacy id for this bill, payment, credit or allocation row', 'example' => 'AP-HIST-000123', 'aliases' => ['id', 'row_id', 'legacy_id']],
            'supplier_external_id' => ['required' => true, 'desc' => 'Mapped external_id from the suppliers dataset', 'example' => 'SUP-1001', 'aliases' => ['supplier_id', 'legacy_supplier_id']],
            'cutover_date' => ['required' => true, 'desc' => 'One common cutover date; every historical AP record must be earlier', 'example' => '2026-10-22', 'aliases' => ['as_of_date', 'opening_date']],
            'record_type' => ['required' => true, 'desc' => 'paid_bill, payment, allocation, supplier_credit or credit_application', 'example' => 'paid_bill', 'aliases' => ['type']],
            'document_ref' => ['required' => true, 'desc' => 'Original supplier bill, payment, credit or allocation reference', 'example' => 'INV-7842', 'aliases' => ['reference', 'document_reference']],
            'document_date' => ['required' => true, 'desc' => 'Original bill/payment/credit/allocation date, before cutover_date', 'example' => '2026-10-10', 'aliases' => ['date', 'source_date']],
            'currency' => ['required' => true, 'desc' => 'Three-letter source currency code; amounts are never converted in this archive', 'example' => 'GHS', 'aliases' => ['currency_code']],
            'amount' => ['required' => true, 'desc' => 'Positive source-document or allocation amount in currency, up to two decimals', 'example' => '1250.00', 'aliases' => ['total', 'value']],
            'bill_external_id' => ['required' => false, 'desc' => 'For allocation/credit_application, external_id of the paid_bill record', 'example' => 'AP-BILL-001'],
            'payment_external_id' => ['required' => false, 'desc' => 'For allocation, external_id of the payment record', 'example' => 'AP-PAY-004'],
            'credit_external_id' => ['required' => false, 'desc' => 'For credit_application, external_id of the supplier_credit record', 'example' => 'AP-CREDIT-009'],
            'remarks' => ['required' => false, 'desc' => 'Optional source note retained verbatim after trimming', 'example' => 'Settled before cutover'],
        ];
    }

    public function templateRows(): array
    {
        $cutoverDate = LedgerService::cutoff();
        $billDate = date('Y-m-d', strtotime($cutoverDate.' -30 days'));
        $paymentDate = date('Y-m-d', strtotime($cutoverDate.' -10 days'));

        return [
            [
                'external_id' => 'AP-HIST-BILL-001',
                'supplier_external_id' => 'SUP-1001',
                'cutover_date' => $cutoverDate,
                'record_type' => 'paid_bill',
                'document_ref' => 'INV-7842',
                'document_date' => $billDate,
                'currency' => 'GHS',
                'amount' => '1250.00',
                'bill_external_id' => '',
                'payment_external_id' => '',
                'credit_external_id' => '',
                'remarks' => 'Example only — replace with a source bill confirmed paid before cutover',
            ],
            [
                'external_id' => 'AP-HIST-PAYMENT-001',
                'supplier_external_id' => 'SUP-1001',
                'cutover_date' => $cutoverDate,
                'record_type' => 'payment',
                'document_ref' => 'PAY-4471',
                'document_date' => $paymentDate,
                'currency' => 'GHS',
                'amount' => '1250.00',
                'bill_external_id' => '',
                'payment_external_id' => '',
                'credit_external_id' => '',
                'remarks' => 'Example only — no payment is posted to Nowills AP or GL',
            ],
            [
                'external_id' => 'AP-HIST-ALLOC-001',
                'supplier_external_id' => 'SUP-1001',
                'cutover_date' => $cutoverDate,
                'record_type' => 'allocation',
                'document_ref' => 'PAY-4471 → INV-7842',
                'document_date' => $paymentDate,
                'currency' => 'GHS',
                'amount' => '1250.00',
                'bill_external_id' => 'AP-HIST-BILL-001',
                'payment_external_id' => 'AP-HIST-PAYMENT-001',
                'credit_external_id' => '',
                'remarks' => 'Example link only; replace with source allocation data',
            ],
        ];
    }

    public function supportsApply(): bool
    {
        return true;
    }

    public function validateBatch(array $rows, ImportContext $ctx): ?string
    {
        if ($rows === []) {
            return 'The paid supplier history file has no rows.';
        }

        $ids = [];
        $records = [];
        $cutoverDates = [];
        foreach ($rows as $index => $row) {
            $externalId = $this->sourceId($row['external_id'] ?? null);
            if ($externalId !== null) {
                $key = mb_strtolower($externalId);
                if (isset($ids[$key])) {
                    return "external_id {$externalId} appears more than once; each supplier history record needs a unique stable id.";
                }
                $ids[$key] = true;
            }

            $cutover = $this->date($row['cutover_date'] ?? null);
            if ($cutover !== null) {
                $cutoverDates[$cutover] = true;
            }

            $error = $this->validateRow($row, $ctx, $index + 2);
            if ($error !== null) {
                return 'Supplier history row '.($index + 2).': '.$error;
            }

            $fields = $this->archiveSourceFields($row);
            $records[mb_strtolower($fields['external_id'])] = $fields;
            $existing = $this->findArchive($ctx, $fields['external_id']);
            if ($existing && ! $this->archiveMatches($existing, $row)) {
                return 'Supplier history external_id '.$fields['external_id'].' is already archived with different source values; immutable archive rows cannot be overwritten.';
            }
        }

        if (count($cutoverDates) !== 1) {
            return 'Use exactly one cutover_date for the complete paid supplier history file.';
        }

        foreach ($rows as $index => $row) {
            $type = strtolower(trim((string) ($row['record_type'] ?? '')));
            $relations = match ($type) {
                'allocation' => ['bill_external_id' => 'paid_bill', 'payment_external_id' => 'payment'],
                'credit_application' => ['bill_external_id' => 'paid_bill', 'credit_external_id' => 'supplier_credit'],
                default => [],
            };
            foreach ($relations as $field => $expectedType) {
                $linkedId = $this->sourceId($row[$field] ?? null);
                $linked = $linkedId !== null ? ($records[mb_strtolower($linkedId)] ?? null) : null;
                if ($linked === null) {
                    $linked = $linkedId !== null ? $this->findArchive($ctx, $linkedId) : null;
                }
                if (! $linked) {
                    return 'Supplier history row '.($index + 2).": {$field} {$linkedId} does not identify an imported {$expectedType} record in this or an earlier archive batch.";
                }
                $linkedType = $linked instanceof SupplierPaidHistoryArchive ? $linked->record_type : $linked['record_type'];
                $linkedSupplier = $linked instanceof SupplierPaidHistoryArchive ? $linked->supplier_external_id : $linked['supplier_external_id'];
                if ($linkedType !== $expectedType) {
                    return 'Supplier history row '.($index + 2).": {$field} {$linkedId} must identify a {$expectedType} record.";
                }
                if (mb_strtolower((string) $linkedSupplier) !== mb_strtolower((string) $row['supplier_external_id'])) {
                    return 'Supplier history row '.($index + 2).": linked {$expectedType} must belong to the same supplier.";
                }
            }
        }

        return null;
    }

    public function validateRow(array $row, ImportContext $ctx, int $rowNo): ?string
    {
        $externalId = trim((string) ($row['external_id'] ?? ''));
        if ($externalId === '') {
            return 'external_id is required and must be a stable source history id.';
        }
        if (mb_strlen($externalId) > 100) {
            return 'external_id must be 100 characters or fewer.';
        }

        $supplierExternalId = trim((string) ($row['supplier_external_id'] ?? ''));
        if ($supplierExternalId === '') {
            return 'supplier_external_id is required; import and apply suppliers before archiving paid history.';
        }
        if (mb_strlen($supplierExternalId) > 100) {
            return 'supplier_external_id must be 100 characters or fewer.';
        }
        $supplierMap = DataMigrationIdMap::withoutGlobalScopes()
            ->where('hotel_id', $ctx->hotel->id)
            ->where('dataset', 'suppliers')
            ->where('external_id', $supplierExternalId)
            ->first();
        if (! $supplierMap || $supplierMap->model_type !== Supplier::class || ! Supplier::find($supplierMap->model_id)) {
            return "Supplier external id {$supplierExternalId} is not mapped for this property; import and apply suppliers first.";
        }

        $cutoverDate = $this->date($row['cutover_date'] ?? null);
        if ($cutoverDate === null || $cutoverDate < '2000-01-01') {
            return 'cutover_date must be a valid date on or after 2000-01-01.';
        }
        $cutoff = LedgerService::cutoff();
        if ($cutoverDate > $cutoff) {
            return "cutover_date must be on or before the closed-ledger cutoff ({$cutoff}).";
        }
        $lockDate = LedgerService::lockDate();
        if ($lockDate && $cutoverDate <= $lockDate) {
            return "cutover_date must be after the GL lock date ({$lockDate}).";
        }

        $documentDate = $this->date($row['document_date'] ?? null);
        if ($documentDate === null || $documentDate < '2000-01-01') {
            return 'document_date must be a valid date on or after 2000-01-01.';
        }
        if ($documentDate >= $cutoverDate) {
            return 'document_date must be earlier than cutover_date; records on or after cutover belong to the live system.';
        }

        $type = strtolower(trim((string) ($row['record_type'] ?? '')));
        if (! in_array($type, self::RECORD_TYPES, true)) {
            return 'record_type must be one of: '.implode(', ', self::RECORD_TYPES).'.';
        }

        $reference = trim((string) ($row['document_ref'] ?? ''));
        if ($reference === '') {
            return 'document_ref is required.';
        }
        if (mb_strlen($reference) > 120) {
            return 'document_ref must be 120 characters or fewer.';
        }

        $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            return 'currency must be a three-letter source currency code, such as GHS or USD.';
        }

        if ($this->amountValue($row['amount'] ?? null) === null) {
            return 'amount must be a positive source-currency value with no more than two decimal places.';
        }

        $billId = trim((string) ($row['bill_external_id'] ?? ''));
        $paymentId = trim((string) ($row['payment_external_id'] ?? ''));
        $creditId = trim((string) ($row['credit_external_id'] ?? ''));
        foreach (['bill_external_id' => $billId, 'payment_external_id' => $paymentId, 'credit_external_id' => $creditId] as $field => $value) {
            if (mb_strlen($value) > 100) {
                return "{$field} must be 100 characters or fewer.";
            }
        }
        if ($type === 'allocation' && ($billId === '' || $paymentId === '' || $creditId !== '')) {
            return 'allocation requires bill_external_id and payment_external_id only.';
        }
        if ($type === 'credit_application' && ($billId === '' || $creditId === '' || $paymentId !== '')) {
            return 'credit_application requires bill_external_id and credit_external_id only.';
        }
        if (in_array($type, ['paid_bill', 'payment', 'supplier_credit'], true)
            && ($billId !== '' || $paymentId !== '' || $creditId !== '')) {
            return "{$type} records cannot carry allocation link ids.";
        }

        return null;
    }

    public function importRow(array $row, ImportContext $ctx, bool $dryRun): array
    {
        $externalId = $this->sourceId($row['external_id'] ?? null);
        $existing = $this->findArchive($ctx, $externalId);
        if ($existing) {
            if (! $this->archiveMatches($existing, $row)) {
                return ['status' => 'failed', 'message' => 'This source id is already archived with different values; immutable archive rows cannot be overwritten.',
                    'model_type' => SupplierPaidHistoryArchive::class, 'model_id' => $existing->id];
            }

            return ['status' => 'unchanged', 'message' => 'This supplier history record is already retained in the immutable archive.',
                'model_type' => SupplierPaidHistoryArchive::class, 'model_id' => $existing->id];
        }

        if ($dryRun) {
            return ['status' => 'created', 'message' => 'Would archive this supplier history row only; no AP subledger or GL record would be written.',
                'model_type' => SupplierPaidHistoryArchive::class, 'model_id' => null];
        }

        $supplierId = DataMigrationIdMap::withoutGlobalScopes()
            ->where('hotel_id', $ctx->hotel->id)
            ->where('dataset', 'suppliers')
            ->where('external_id', $row['supplier_external_id'])
            ->value('model_id');
        $supplier = Supplier::findOrFail($supplierId);
        $archive = SupplierPaidHistoryArchive::create($this->archiveSourceFields($row) + [
            'hotel_id' => $ctx->hotel->id,
            'batch_id' => $ctx->batch->id,
            'supplier_name_snapshot' => $supplier->name,
            'source_payload' => $row,
            'archived_by' => auth()->id(),
        ]);

        return ['status' => 'created', 'message' => 'Archived in supplier_paid_history_archives only; no supplier invoice, payment, AP allocation, GL journal or ID map was created.',
            'model_type' => SupplierPaidHistoryArchive::class, 'model_id' => $archive->id];
    }

    public function reconcile(ImportContext $ctx): array
    {
        $rows = $ctx->batch->rows()->orderBy('row_no')->get()
            ->map(fn ($row) => (array) $row->payload)->all();
        $typeCounts = [];
        $supplierIds = [];
        $currencyTotals = [];
        foreach ($rows as $row) {
            $type = strtolower(trim((string) ($row['record_type'] ?? '')));
            if ($type !== '') {
                $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
            }
            $supplier = $this->sourceId($row['supplier_external_id'] ?? null);
            if ($supplier !== null) {
                $supplierIds[mb_strtolower($supplier)] = true;
            }
            $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
            $amount = $this->amountValue($row['amount'] ?? null);
            if ($type !== '' && preg_match('/^[A-Z]{3}$/', $currency) && $amount !== null) {
                $key = $type.' '.$currency;
                $currencyTotals[$key] = ($currencyTotals[$key] ?? 0) + (int) round($amount * 100);
            }
        }
        ksort($typeCounts);
        ksort($currencyTotals);
        $typeSummary = $typeCounts
            ? implode('; ', array_map(fn ($type, $count) => "{$type}: {$count}", array_keys($typeCounts), array_values($typeCounts)))
            : 'None';
        $amountSummary = $currencyTotals
            ? implode('; ', array_map(fn ($key, $cents) => $key.': '.$this->formatCents($cents), array_keys($currencyTotals), array_values($currencyTotals)))
            : 'None';
        $archiveRows = SupplierPaidHistoryArchive::where('hotel_id', $ctx->hotel->id)
            ->where('batch_id', $ctx->batch->id)->count();
        $status = $ctx->batch->rows_failed > 0
            ? 'REVIEW — correct failed supplier source rows and rerun dry-run.'
            : ($ctx->batch->status === 'imported'
                ? ($archiveRows > 0
                    ? 'ARCHIVED — paid supplier history is retained outside operational AP and GL.'
                    : 'ARCHIVE MATCH — source ids already exist unchanged; no duplicate archive rows were created.')
                : 'PASS — supplier mappings, source links and fields validated; apply stores only a separate archive copy.');

        return [
            'Preflight and archive status' => $status,
            'Historical supplier rows reviewed' => count($rows),
            'Unique mapped suppliers referenced' => count($supplierIds),
            'Record type row counts' => $typeSummary,
            'Source amount totals by type and currency (not converted)' => $amountSummary,
            'Archive rows created by this batch' => $archiveRows,
            'Total supplier paid-history archive rows for this property' => SupplierPaidHistoryArchive::where('hotel_id', $ctx->hotel->id)->count(),
            'Operational AP impact' => 'None — no supplier invoices, payments or allocations were created or changed.',
            'GL impact' => 'None — this importer creates no journal and does not call ledger posting.',
            'Supplier-history ID maps' => 'None — source ids remain on immutable archive rows only.',
            'AP policy status' => 'Still provisional and unapproved; this archive does not operationalize or approve AP treatment.',
        ];
    }

    private function archiveSourceFields(array $row): array
    {
        $amount = $this->amountValue($row['amount'] ?? null);

        return [
            'external_id' => $this->sourceId($row['external_id'] ?? null),
            'supplier_external_id' => $this->sourceId($row['supplier_external_id'] ?? null),
            'record_type' => strtolower(trim((string) ($row['record_type'] ?? ''))),
            'document_ref' => trim((string) ($row['document_ref'] ?? '')),
            'cutover_date' => $this->date($row['cutover_date'] ?? null),
            'document_date' => $this->date($row['document_date'] ?? null),
            'currency' => strtoupper(trim((string) ($row['currency'] ?? ''))),
            'amount' => number_format((float) $amount, 2, '.', ''),
            'bill_external_id' => $this->nullableSourceId($row['bill_external_id'] ?? null),
            'payment_external_id' => $this->nullableSourceId($row['payment_external_id'] ?? null),
            'credit_external_id' => $this->nullableSourceId($row['credit_external_id'] ?? null),
            'remarks' => $this->nullableText($row['remarks'] ?? null),
        ];
    }

    private function archiveMatches(SupplierPaidHistoryArchive $archive, array $row): bool
    {
        $source = $this->archiveSourceFields($row);

        return mb_strtolower((string) $archive->external_id) === mb_strtolower((string) $source['external_id'])
            && mb_strtolower((string) $archive->supplier_external_id) === mb_strtolower((string) $source['supplier_external_id'])
            && $archive->record_type === $source['record_type']
            && $archive->document_ref === $source['document_ref']
            && $archive->cutover_date?->toDateString() === $source['cutover_date']
            && $archive->document_date?->toDateString() === $source['document_date']
            && $archive->currency === $source['currency']
            && (string) $archive->amount === $source['amount']
            && $this->sameNullableId($archive->bill_external_id, $source['bill_external_id'])
            && $this->sameNullableId($archive->payment_external_id, $source['payment_external_id'])
            && $this->sameNullableId($archive->credit_external_id, $source['credit_external_id'])
            && $archive->remarks === $source['remarks'];
    }

    private function findArchive(ImportContext $ctx, ?string $externalId): ?SupplierPaidHistoryArchive
    {
        if ($externalId === null || $externalId === '') {
            return null;
        }

        return SupplierPaidHistoryArchive::where('hotel_id', $ctx->hotel->id)
            ->whereRaw('lower(external_id) = ?', [mb_strtolower($externalId)])
            ->first();
    }

    private function sourceId(?string $raw): ?string
    {
        $value = trim((string) $raw);

        return $value === '' || mb_strlen($value) > 100 ? null : $value;
    }

    private function nullableSourceId(?string $raw): ?string
    {
        return $this->sourceId($raw);
    }

    private function nullableText(?string $raw): ?string
    {
        $value = trim((string) $raw);

        return $value === '' ? null : $value;
    }

    private function sameNullableId(?string $left, ?string $right): bool
    {
        return ($left === null && $right === null)
            || ($left !== null && $right !== null && mb_strtolower($left) === mb_strtolower($right));
    }

    private function amountValue(?string $raw): ?float
    {
        $value = trim((string) $raw);
        if (! preg_match('/^(?:\\d+|\\d{1,3}(?:,\\d{3})+)(?:\\.(\\d{1,2}))?$/', $value)) {
            return null;
        }
        $amount = (float) str_replace(',', '', $value);
        if (! is_finite($amount) || $amount <= 0 || $amount > self::MAX_AMOUNT) {
            return null;
        }

        return $amount;
    }

    private function formatCents(int $cents): string
    {
        $whole = intdiv($cents, 100);
        $fraction = str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);

        return number_format($whole, 0, '.', ',').'.'.$fraction;
    }
}
