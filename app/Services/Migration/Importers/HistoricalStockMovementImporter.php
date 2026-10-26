<?php

namespace App\Services\Migration\Importers;

use App\Models\DataMigrationIdMap;
use App\Models\DataMigrationRow;
use App\Models\HistoricalStockMovementArchive;
use App\Models\InventoryItem;
use App\Models\Store;
use App\Services\LedgerService;
use App\Services\Migration\BaseImporter;
use App\Services\Migration\ImportContext;

/**
 * Archive-only importer for legacy stock movement history.
 *
 * Rows are written only to historical_stock_movement_archives, an immutable,
 * non-operational archive. They never enter stock_movements, affect on-hand
 * balances, create stock GL journals, or create DataMigrationIdMap records.
 */
class HistoricalStockMovementImporter extends BaseImporter
{
    private const MAX_QUANTITY = 999999999.999; // stock_movements.qty is DECIMAL(12, 3)

    private const MAX_UNIT_COST = 9999999999.99; // stock_movements.rate is DECIMAL(12, 2)

    private const MAX_ROW_VALUE_CENTS = 99999999999999;

    private const MAX_BATCH_VALUE_CENTS = 9999999999999999;

    private const MOVEMENT_TYPES = [
        'purchase', 'issue', 'transfer_in', 'transfer_out', 'adjustment', 'wastage', 'consumption', 'opening',
    ];

    public function label(): string
    {
        return 'Historical stock archive (no operational stock or GL writes)';
    }

    public function columns(): array
    {
        return [
            'external_id' => ['required' => true, 'desc' => 'Stable legacy id for this source movement row', 'example' => 'HIST-MOVE-000123', 'aliases' => ['id', 'row_id', 'legacy_id']],
            'item_external_id' => ['required' => true, 'desc' => 'Mapped external_id from the inventory_items dataset', 'example' => 'ITEM-2001', 'aliases' => ['item_id', 'legacy_item_id']],
            'store_name' => ['required' => true, 'desc' => 'Exact existing Nowills store name', 'example' => 'Main Store', 'aliases' => ['store']],
            'cutover_date' => ['required' => true, 'desc' => 'One common cutover date; every historical movement must be earlier', 'example' => '2026-10-22', 'aliases' => ['as_of_date', 'opening_date']],
            'movement_date' => ['required' => true, 'desc' => 'Original source stock movement date, strictly before cutover_date', 'example' => '2026-10-10', 'aliases' => ['date', 'source_date']],
            'movement_type' => ['required' => true, 'desc' => 'purchase, issue, transfer_in, transfer_out, adjustment, wastage, consumption or opening', 'example' => 'purchase', 'aliases' => ['type']],
            'quantity' => ['required' => true, 'desc' => 'Signed source quantity in the inventory item unit, up to 3 decimals; positive in, negative out', 'example' => '24.000', 'aliases' => ['qty']],
            'unit_cost' => ['required' => false, 'desc' => 'Optional historical unit cost in property currency, up to 2 decimals', 'example' => '12.50', 'aliases' => ['rate', 'cost']],
            'reference' => ['required' => false, 'desc' => 'Original receipt, issue, transfer or adjustment reference', 'example' => 'GRN-148', 'aliases' => ['source_ref', 'document_ref']],
            'department' => ['required' => false, 'desc' => 'Original department label (up to 80 characters), retained for source review', 'example' => 'Kitchen'],
            'remarks' => ['required' => false, 'desc' => 'Optional source note; this is not posted to live inventory', 'example' => 'Legacy goods receipt'],
        ];
    }

    public function templateRows(): array
    {
        $cutoverDate = LedgerService::cutoff();

        return [
            [
                'external_id' => 'HIST-MOVE-RECEIPT-001',
                'item_external_id' => 'ITEM-2001',
                'store_name' => 'Main Store',
                'cutover_date' => $cutoverDate,
                'movement_date' => date('Y-m-d', strtotime($cutoverDate.' -12 days')),
                'movement_type' => 'purchase',
                'quantity' => '24.000',
                'unit_cost' => '12.50',
                'reference' => 'GRN-148',
                'department' => '',
                'remarks' => 'Legacy receipt example; replace with source data',
            ],
            [
                'external_id' => 'HIST-MOVE-ISSUE-002',
                'item_external_id' => 'ITEM-2001',
                'store_name' => 'Main Store',
                'cutover_date' => $cutoverDate,
                'movement_date' => date('Y-m-d', strtotime($cutoverDate.' -5 days')),
                'movement_type' => 'issue',
                'quantity' => '-3.250',
                'unit_cost' => '12.50',
                'reference' => 'ISS-083',
                'department' => 'Kitchen',
                'remarks' => 'Legacy issue example; replace with source data',
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
            return 'The historical stock movement file has no rows.';
        }

        $ids = [];
        $cutoverDates = [];
        $grossValueCents = 0;
        foreach ($rows as $row) {
            $externalId = $this->sourceExternalId($row['external_id'] ?? null);
            if ($externalId !== null) {
                $sourceKey = mb_strtolower($externalId);
                if (isset($ids[$sourceKey])) {
                    return "external_id {$externalId} appears more than once; each historical movement needs a unique stable id.";
                }
                $ids[$sourceKey] = true;
            }

            $cutoverDate = $this->date($row['cutover_date'] ?? null);
            if ($cutoverDate !== null) {
                $cutoverDates[$cutoverDate] = true;
            }

            $quantity = $this->numberValue($row['quantity'] ?? null, 3, true, self::MAX_QUANTITY);
            $costRaw = trim((string) ($row['unit_cost'] ?? ''));
            $unitCost = $costRaw === '' ? null : $this->unitCostValue($costRaw);
            if ($quantity !== null && $unitCost !== null) {
                $rowValue = round(abs($quantity) * $unitCost, 2);
                if ($rowValue > self::MAX_ROW_VALUE_CENTS / 100) {
                    return 'A historical movement value exceeds the supported reconciliation limit; verify the source quantity and unit cost.';
                }
                $rowValueCents = (int) round($rowValue * 100);
                if ($grossValueCents > self::MAX_BATCH_VALUE_CENTS - $rowValueCents) {
                    return 'The historical movement batch value exceeds the supported reconciliation limit; split the source file.';
                }
                $grossValueCents += $rowValueCents;
            }
        }

        if (count($cutoverDates) !== 1) {
            return 'Use exactly one cutover_date for the complete historical movement file.';
        }

        foreach ($rows as $index => $row) {
            $error = $this->validateRow($row, $ctx, $index + 2);
            if ($error !== null) {
                return 'Historical movement row '.($index + 2).': '.$error;
            }
            $externalId = $this->sourceExternalId($row['external_id'] ?? null);
            $existing = HistoricalStockMovementArchive::where('hotel_id', $ctx->hotel->id)
                ->whereRaw('lower(external_id) = ?', [mb_strtolower($externalId)])
                ->first();
            if ($existing && ! $this->archiveMatches($existing, $row)) {
                return 'Historical movement external_id '.$externalId.' is already archived with different source values; immutable archive rows cannot be overwritten.';
            }
        }

        return null;
    }

    public function validateRow(array $row, ImportContext $ctx, int $rowNo): ?string
    {
        $externalId = trim((string) ($row['external_id'] ?? ''));
        if ($externalId === '') {
            return 'external_id is required and must be a stable source movement id.';
        }
        if (mb_strlen($externalId) > 100) {
            return 'external_id must be 100 characters or fewer.';
        }

        $itemExternalId = trim((string) ($row['item_external_id'] ?? ''));
        if ($itemExternalId === '') {
            return 'item_external_id is required; import and apply inventory_items before preflighting stock history.';
        }
        if (mb_strlen($itemExternalId) > 100) {
            return 'item_external_id must be 100 characters or fewer.';
        }
        $itemMap = DataMigrationIdMap::withoutGlobalScopes()
            ->where('hotel_id', $ctx->hotel->id)
            ->where('dataset', 'inventory_items')
            ->where('external_id', $itemExternalId)
            ->first();
        if (! $itemMap || $itemMap->model_type !== InventoryItem::class || ! InventoryItem::find($itemMap->model_id)) {
            return "Inventory item external id {$itemExternalId} is not mapped for this property; import and apply inventory_items first.";
        }

        $storeName = trim((string) ($row['store_name'] ?? ''));
        if ($storeName === '') {
            return 'store_name is required.';
        }
        if (mb_strlen($storeName) > 80) {
            return 'store_name must be 80 characters or fewer.';
        }
        $stores = Store::whereRaw('lower(name) = ?', [mb_strtolower($storeName)])->get();
        if ($stores->count() === 0) {
            return "Store {$storeName} was not found in this property; create the store before preflighting stock history.";
        }
        if ($stores->count() > 1) {
            return "More than one store is named {$storeName}; resolve the store mapping before preflighting.";
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

        $movementDate = $this->date($row['movement_date'] ?? null);
        if ($movementDate === null || $movementDate < '2000-01-01') {
            return 'movement_date must be a valid date on or after 2000-01-01.';
        }
        if ($movementDate >= $cutoverDate) {
            return 'movement_date must be earlier than cutover_date; movements on or after cutover belong to the live system.';
        }

        $type = strtolower(trim((string) ($row['movement_type'] ?? '')));
        if (! in_array($type, self::MOVEMENT_TYPES, true)) {
            return 'movement_type must be one of: '.implode(', ', self::MOVEMENT_TYPES).'.';
        }

        $quantity = $this->numberValue($row['quantity'] ?? null, 3, true, self::MAX_QUANTITY);
        if ($quantity === null || abs($quantity) < 0.0005) {
            return 'quantity must be a non-zero signed amount with no more than three decimal places.';
        }
        $expectedSign = match ($type) {
            'purchase', 'transfer_in', 'opening' => 1,
            'issue', 'transfer_out', 'wastage', 'consumption' => -1,
            default => 0, // adjustment may be an increase or decrease
        };
        if (($expectedSign > 0 && $quantity < 0) || ($expectedSign < 0 && $quantity > 0)) {
            return "quantity sign does not match {$type}; inbound movements must be positive and outbound movements negative.";
        }

        $costRaw = trim((string) ($row['unit_cost'] ?? ''));
        if ($costRaw !== '' && $this->unitCostValue($costRaw) === null) {
            return 'unit_cost must be blank or a non-negative property-currency amount with no more than two decimal places.';
        }
        if (mb_strlen(trim((string) ($row['department'] ?? ''))) > 80) {
            return 'department must be 80 characters or fewer.';
        }

        return null;
    }

    public function importRow(array $row, ImportContext $ctx, bool $dryRun): array
    {
        $externalId = $this->sourceExternalId($row['external_id'] ?? null);
        $existing = HistoricalStockMovementArchive::where('hotel_id', $ctx->hotel->id)
            ->whereRaw('lower(external_id) = ?', [mb_strtolower((string) $externalId)])
            ->first();
        if ($existing) {
            if (! $this->archiveMatches($existing, $row)) {
                return ['status' => 'failed', 'message' => 'This source id is already archived with different values; immutable archive rows cannot be overwritten.',
                    'model_type' => HistoricalStockMovementArchive::class, 'model_id' => $existing->id];
            }

            return ['status' => 'unchanged', 'message' => 'This source movement is already present in the immutable historical archive.',
                'model_type' => HistoricalStockMovementArchive::class, 'model_id' => $existing->id];
        }

        if ($dryRun) {
            return ['status' => 'created', 'message' => 'Would archive this source row only; no operational stock movement or GL journal would be written.',
                'model_type' => HistoricalStockMovementArchive::class, 'model_id' => null];
        }

        $itemId = DataMigrationIdMap::withoutGlobalScopes()
            ->where('hotel_id', $ctx->hotel->id)
            ->where('dataset', 'inventory_items')
            ->where('external_id', $row['item_external_id'])
            ->value('model_id');
        $item = InventoryItem::findOrFail($itemId);
        $archive = HistoricalStockMovementArchive::create($this->archiveSourceFields($row) + [
            'hotel_id' => $ctx->hotel->id,
            'batch_id' => $ctx->batch->id,
            'item_code_snapshot' => $item->code,
            'item_name_snapshot' => $item->name,
            'item_unit_snapshot' => $item->unit,
            'source_payload' => $row,
            'archived_by' => auth()->id(),
        ]);

        return [
            'status' => 'created',
            'message' => 'Archived in historical_stock_movement_archives only; no stock_movements, on-hand quantity, GL journal or ID map was created.',
            'model_type' => HistoricalStockMovementArchive::class,
            'model_id' => $archive->id,
        ];
    }

    public function reconcile(ImportContext $ctx): array
    {
        $rows = $ctx->batch->rows()->orderBy('row_no')->get()
            ->map(fn ($row) => (array) $row->payload)->all();
        $typeCounts = [];
        $items = [];
        $stores = [];
        $grossValueCents = 0;
        $costedRows = 0;
        $uncostedRows = 0;
        $cutoverDates = [];

        foreach ($rows as $row) {
            $type = strtolower(trim((string) ($row['movement_type'] ?? '')));
            if ($type !== '') {
                $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
            }
            $item = $this->sourceExternalId($row['item_external_id'] ?? null);
            if ($item !== null) {
                $items[$item] = true;
            }
            $store = $this->sourceStoreName($row['store_name'] ?? null);
            if ($store !== null) {
                $stores[mb_strtolower($store)] = true;
            }
            $cutover = $this->date($row['cutover_date'] ?? null);
            if ($cutover !== null) {
                $cutoverDates[$cutover] = true;
            }
            $quantity = $this->numberValue($row['quantity'] ?? null, 3, true, self::MAX_QUANTITY);
            $costRaw = trim((string) ($row['unit_cost'] ?? ''));
            if ($costRaw === '') {
                $uncostedRows++;
                continue;
            }
            $unitCost = $this->unitCostValue($costRaw);
            if ($quantity !== null && $unitCost !== null) {
                $grossValueCents += (int) round(round(abs($quantity) * $unitCost, 2) * 100);
                $costedRows++;
            }
        }
        ksort($typeCounts);

        $archiveRows = HistoricalStockMovementArchive::where('hotel_id', $ctx->hotel->id)
            ->where('batch_id', $ctx->batch->id)->count();
        $preflightStatus = $ctx->batch->rows_failed > 0
            ? 'REVIEW — correct the failed source rows and rerun preflight.'
            : ($ctx->batch->status === 'imported'
                ? ($archiveRows > 0
                    ? 'ARCHIVED — immutable source rows are stored outside operational stock and GL.'
                    : 'ARCHIVE MATCH — source ids already exist unchanged; no duplicate archive rows were created.')
                : 'PASS — mappings and source fields validated; apply stores only a separate archive copy.');

        return [
            'Preflight and archive status' => $preflightStatus,
            'Common cutover date' => count($cutoverDates) === 1 ? array_key_first($cutoverDates) : 'Not resolved',
            'Historical source rows reviewed' => count($rows),
            'Unique inventory item IDs referenced' => count($items),
            'Unique source stores referenced' => count($stores),
            'Movement type row counts' => $typeCounts ? implode('; ', array_map(fn ($type, $count) => "{$type}: {$count}", array_keys($typeCounts), array_values($typeCounts))) : 'None',
            'Rows with unit cost' => $costedRows,
            'Rows without unit cost' => $uncostedRows,
            'Gross value for costed rows (property currency)' => $this->formatCents($grossValueCents),
            'Archive rows created by this batch' => $archiveRows,
            'Total historical archive rows for this property' => HistoricalStockMovementArchive::where('hotel_id', $ctx->hotel->id)->count(),
            'Operational stock impact' => 'None — this archive is not queried by stock_movements, on-hand totals or stocktakes.',
            'GL impact' => 'None — this importer creates no journal and does not call ledger posting.',
            'Historical-stock ID maps' => 'None — external ids remain on immutable archive rows only.',
        ];
    }

    /** Canonical archive values; source_payload separately retains the submitted row. */
    private function archiveSourceFields(array $row): array
    {
        $quantity = $this->numberValue($row['quantity'] ?? null, 3, true, self::MAX_QUANTITY);
        $costRaw = trim((string) ($row['unit_cost'] ?? ''));
        $unitCost = $costRaw === '' ? null : $this->unitCostValue($costRaw);

        return [
            'external_id' => $this->sourceExternalId($row['external_id'] ?? null),
            'item_external_id' => $this->sourceExternalId($row['item_external_id'] ?? null),
            'store_name' => $this->sourceStoreName($row['store_name'] ?? null),
            'cutover_date' => $this->date($row['cutover_date'] ?? null),
            'movement_date' => $this->date($row['movement_date'] ?? null),
            'movement_type' => strtolower(trim((string) ($row['movement_type'] ?? ''))),
            'quantity' => number_format((float) $quantity, 3, '.', ''),
            'unit_cost' => $unitCost === null ? null : number_format($unitCost, 2, '.', ''),
            'reference' => $this->nullableText($row['reference'] ?? null),
            'department' => $this->nullableText($row['department'] ?? null),
            'remarks' => $this->nullableText($row['remarks'] ?? null),
        ];
    }

    private function archiveMatches(HistoricalStockMovementArchive $archive, array $row): bool
    {
        $source = $this->archiveSourceFields($row);

        return mb_strtolower((string) $archive->external_id) === mb_strtolower((string) $source['external_id'])
            && mb_strtolower((string) $archive->item_external_id) === mb_strtolower((string) $source['item_external_id'])
            && $archive->store_name === $source['store_name']
            && $archive->cutover_date?->toDateString() === $source['cutover_date']
            && $archive->movement_date?->toDateString() === $source['movement_date']
            && $archive->movement_type === $source['movement_type']
            && (string) $archive->quantity === $source['quantity']
            && ($archive->unit_cost === null ? null : (string) $archive->unit_cost) === $source['unit_cost']
            && $archive->reference === $source['reference']
            && $archive->department === $source['department']
            && $archive->remarks === $source['remarks'];
    }

    private function nullableText(?string $raw): ?string
    {
        $value = trim((string) $raw);

        return $value === '' ? null : $value;
    }

    private function sourceExternalId(?string $raw): ?string
    {
        $value = trim((string) $raw);

        return $value === '' || mb_strlen($value) > 100 ? null : $value;
    }

    private function sourceStoreName(?string $raw): ?string
    {
        $value = trim((string) $raw);

        return $value === '' || mb_strlen($value) > 80 ? null : $value;
    }

    private function formatCents(int $cents): string
    {
        $whole = intdiv($cents, 100);
        $fraction = str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);

        return number_format($whole, 0, '.', ',').'.'.$fraction;
    }

    /** Accept legacy currency markers for costs while keeping quantity parsing currency-free. */
    private function unitCostValue(?string $raw): ?float
    {
        $value = str_ireplace(['GH₵', 'GHS', '₵', '¢', '$', '£', '€'], '', trim((string) $raw));
        $value = preg_replace('/\\s+/', '', $value) ?? '';

        return $this->numberValue($value, 2, false, self::MAX_UNIT_COST);
    }

    /** Parse a strictly grouped numeric value with the scale of the destination ledger column. */
    private function numberValue(?string $raw, int $scale, bool $allowNegative, float $max): ?float
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return null;
        }
        $integer = '(?:\\d+|\\d{1,3}(?:,\\d{3})+)';
        $sign = $allowNegative ? '-?' : '';
        $pattern = '/^'.$sign.$integer.'(?:\\.(\\d{1,'.$scale.'}))?$/';
        if (! preg_match($pattern, $value)) {
            return null;
        }
        $number = (float) str_replace(',', '', $value);
        if (! is_finite($number) || abs($number) > $max) {
            return null;
        }

        return $number;
    }
}
