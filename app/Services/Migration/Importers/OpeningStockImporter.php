<?php

namespace App\Services\Migration\Importers;

use App\Models\AccountHead;
use App\Models\DataMigrationIdMap;
use App\Models\GlJournal;
use App\Models\GlLine;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\Store;
use App\Services\LedgerService;
use App\Services\Migration\BaseImporter;
use App\Services\Migration\ImportContext;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Opening-stock cutover importer. Rows are idempotent by source id; locked-period
 * rows may be rechecked unchanged but can only be corrected by a current-period
 * stocktake. The daily stock journal debits Inventory and credits Opening Balance Equity.
 */
class OpeningStockImporter extends BaseImporter
{
    /** @var array<string, true> */
    private array $affectedDates = [];

    /** @var array<string, true> */
    private array $validatedLockedDates = [];

    public function label(): string
    {
        return 'Opening stock balances (with GL)';
    }

    public function columns(): array
    {
        return [
            'external_id' => ['required' => true, 'desc' => 'Stable legacy opening-stock row id', 'example' => 'OPEN-STORE1-ITEM2001', 'aliases' => ['id', 'row_id', 'legacy_id']],
            'item_external_id' => ['required' => true, 'desc' => 'external_id from the inventory_items import', 'example' => 'ITEM-2001', 'aliases' => ['item_id', 'legacy_item_id']],
            'store_name' => ['required' => true, 'desc' => 'Exact existing Nowills store name', 'example' => 'Main Store', 'aliases' => ['store']],
            'opening_date' => ['required' => true, 'desc' => 'One cutover date shared by every row in this CSV', 'example' => '2026-10-21', 'aliases' => ['effective_date', 'as_of_date', 'date']],
            'quantity' => ['required' => true, 'desc' => 'Positive on-hand quantity in the item stock unit (up to 2 decimal places)', 'example' => '24'],
            'unit_cost' => ['required' => true, 'desc' => 'Positive unit cost in the property currency (up to 2 decimal places)', 'example' => '12.50', 'aliases' => ['rate', 'cost']],
        ];
    }

    public function validateBatch(array $rows, ImportContext $ctx): ?string
    {
        $ids = [];
        $dates = [];
        $totalValueCents = 0;

        foreach ($rows as $row) {
            $externalId = $this->externalId($row['external_id'] ?? null);
            if ($externalId !== null) {
                if (isset($ids[$externalId])) {
                    return "external_id {$externalId} appears more than once in this CSV; each opening-stock source row must have a unique id.";
                }
                $ids[$externalId] = true;
            }

            $date = $this->date($row['opening_date'] ?? null);
            if ($date !== null) {
                $dates[$date] = true;
            }

            $quantity = $this->numberValue($row['quantity'] ?? null);
            $unitCost = $this->numberValue($row['unit_cost'] ?? null);
            $validQuantity = $quantity !== null && $quantity > 0 && $quantity <= 9999999999.99 && $this->hasTwoDecimals($quantity);
            $validUnitCost = $unitCost !== null && $unitCost > 0 && $unitCost <= 9999999999.99 && $this->hasTwoDecimals($unitCost);
            if ($validQuantity && $validUnitCost) {
                $lineValue = round($quantity * $unitCost, 2);
                if ($lineValue >= 0.01 && $lineValue <= 999999999999.99) {
                    $lineValueCents = (int) round($lineValue * 100);
                    if ($totalValueCents > 99999999999999 - $lineValueCents) {
                        return 'The opening-stock batch value exceeds the general-ledger limit; split the cutover or verify the source amounts.';
                    }
                    $totalValueCents += $lineValueCents;
                }
            }
        }

        if (count($dates) > 1) {
            return 'Use one opening_date per opening-stock CSV so its stock baseline is posted as one dated cutover; split different dates into separate files.';
        }

        return null;
    }

    public function validateRow(array $row, ImportContext $ctx, int $rowNo): ?string
    {
        $externalId = $this->externalId($row['external_id'] ?? null);
        if ($externalId === null) {
            return 'external_id is required.';
        }

        $itemExternalId = $this->externalId($row['item_external_id'] ?? null);
        if ($itemExternalId === null) {
            return 'item_external_id is required; import inventory_items before opening stock.';
        }
        $itemMap = DataMigrationIdMap::withoutGlobalScopes()
            ->where('hotel_id', $ctx->hotel->id)
            ->where('dataset', 'inventory_items')
            ->where('external_id', $itemExternalId)
            ->first();
        if (! $itemMap || $itemMap->model_type !== InventoryItem::class || ! InventoryItem::find($itemMap->model_id)) {
            return "Inventory item external id {$itemExternalId} is not mapped for this property; import and apply inventory_items first.";
        }

        $storeName = $this->str($row['store_name'] ?? null, 80);
        if ($storeName === null) {
            return 'store_name is required.';
        }
        $stores = Store::whereRaw('lower(name) = ?', [mb_strtolower($storeName)])->get();
        if ($stores->count() === 0) {
            return "Store {$storeName} was not found in this property; create the store before importing opening stock.";
        }
        if ($stores->count() > 1) {
            return "More than one store is named {$storeName}; rename or resolve the stores before importing.";
        }

        $date = $this->date($row['opening_date'] ?? null);
        if ($date === null) {
            return 'opening_date must be a valid date.';
        }
        if ($date > bdate()->toDateString()) {
            return 'opening_date cannot be after the property business date.';
        }
        $cutoff = LedgerService::cutoff();
        if ($date > $cutoff) {
            return "opening_date must be on or before the closed-ledger cutoff ({$cutoff}); this prevents posting today's unfinished stock journal early.";
        }
        $lockDate = LedgerService::lockDate();

        $quantity = $this->numberValue($row['quantity'] ?? null);
        if ($quantity === null || $quantity <= 0 || $quantity > 9999999999.99 || ! $this->hasTwoDecimals($quantity)) {
            return 'quantity must be greater than zero, fit the stock ledger, and have no more than two decimal places.';
        }
        $unitCost = $this->numberValue($row['unit_cost'] ?? null);
        if ($unitCost === null || $unitCost <= 0 || $unitCost > 9999999999.99 || ! $this->hasTwoDecimals($unitCost)) {
            return 'unit_cost must be greater than zero, fit the stock ledger, and have no more than two decimal places.';
        }
        $value = round($quantity * $unitCost, 2);
        if ($value < 0.01 || $value > 999999999999.99) {
            return 'quantity × unit_cost must produce a positive value within the general-ledger limit.';
        }

        $movementMap = $this->movementMap($ctx, $externalId);
        if ($lockDate && $date <= $lockDate && ! $movementMap) {
            return "opening_date must be after the GL lock date ({$lockDate}); use a stocktake adjustment in the first open period instead.";
        }
        if ($movementMap) {
            if ($movementMap->model_type !== StockMovement::class) {
                return "Opening-stock id {$externalId} is already mapped to a different model type; resolve it manually.";
            }
            $movement = StockMovement::find($movementMap->model_id);
            if (! $movement || $movement->type !== 'opening') {
                return "Opening-stock id {$externalId} no longer points to an opening stock movement; resolve it manually.";
            }
            $oldDate = $movement->date?->toDateString();
            if ($lockDate && (($oldDate && $oldDate <= $lockDate) || $date <= $lockDate)) {
                $unchangedLockedRow = $oldDate === $date
                    && (int) $movement->inventory_item_id === (int) $itemMap->model_id
                    && (int) $movement->store_id === (int) $stores->first()->id
                    && abs((float) $movement->qty - round($quantity, 2)) < 0.005
                    && abs((float) $movement->rate - round($unitCost, 2)) < 0.005
                    && $movement->reference === 'MIG-OPENING'
                    && $movement->remarks === 'Opening stock cutover import';
                if (! $unchangedLockedRow) {
                    return "The existing opening-stock row is dated {$oldDate}, which is GL-locked; use a current-period stocktake adjustment instead.";
                }
                if (! isset($this->validatedLockedDates[$oldDate])) {
                    try {
                        $this->assertOpeningEquityMatches($oldDate);
                        $this->validatedLockedDates[$oldDate] = true;
                    } catch (Throwable $e) {
                        return 'The locked opening-stock movement no longer reconciles to its GL journal: '.$e->getMessage();
                    }
                }
            }
        }

        return null;
    }

    public function importRow(array $row, ImportContext $ctx, bool $dryRun): array
    {
        $externalId = $this->externalId($row['external_id']);
        $itemExternalId = $this->externalId($row['item_external_id']);
        $itemId = $ctx->mappedId('inventory_items', $itemExternalId);
        $item = $itemId ? InventoryItem::find($itemId) : null;
        $storeName = $this->str($row['store_name'], 80);
        $store = Store::whereRaw('lower(name) = ?', [mb_strtolower((string) $storeName)])->first();
        $date = $this->date($row['opening_date']);
        $quantity = $this->numberValue($row['quantity']);
        $unitCost = $this->numberValue($row['unit_cost']);

        if (! $item || ! $store || $date === null || $quantity === null || $unitCost === null) {
            return $this->failed('A required item, store, date or numeric value could not be resolved after validation.', null);
        }

        $movementMap = $this->movementMap($ctx, $externalId);
        $movement = $movementMap ? StockMovement::find($movementMap->model_id) : null;
        $fields = [
            'inventory_item_id' => $item->id,
            'store_id' => $store->id,
            'date' => $date,
            'type' => 'opening',
            'qty' => round($quantity, 2),
            'rate' => round($unitCost, 2),
            'reference' => 'MIG-OPENING',
            'remarks' => 'Opening stock cutover import',
        ];

        if (! $movement) {
            if ($dryRun) {
                return ['status' => 'created', 'message' => null, 'model_type' => StockMovement::class, 'model_id' => null];
            }
            $movement = StockMovement::create($fields + ['user_id' => auth()->id()]);
            $ctx->map('opening_stock', $externalId, StockMovement::class, $movement->id);
            $this->affectedDates[$date] = true;

            return ['status' => 'created', 'message' => 'Opening stock movement recorded; GL posting is reconciled after the batch.', 'model_type' => StockMovement::class, 'model_id' => $movement->id];
        }

        $dirty = $this->dirty($movement, $fields);
        if (! $dirty) {
            if (! $dryRun) {
                $ctx->map('opening_stock', $externalId, StockMovement::class, $movement->id);
                $lockDate = LedgerService::lockDate();
                if (! $lockDate || $date > $lockDate) {
                    $this->affectedDates[$date] = true; // an open-period idempotent re-run also rechecks its journal
                }
            }

            return ['status' => 'unchanged', 'message' => null, 'model_type' => StockMovement::class, 'model_id' => $movement->id];
        }
        if ($dryRun) {
            return ['status' => 'updated', 'message' => 'Would update: '.implode(', ', array_keys($dirty)), 'model_type' => StockMovement::class, 'model_id' => $movement->id];
        }

        $oldDate = $movement->date->toDateString();
        $movement->fill($dirty)->save();
        $ctx->map('opening_stock', $externalId, StockMovement::class, $movement->id);
        $this->affectedDates[$oldDate] = true;
        $this->affectedDates[$date] = true;

        return ['status' => 'updated', 'message' => 'Updated opening stock movement; affected GL dates are reconciled after the batch.', 'model_type' => StockMovement::class, 'model_id' => $movement->id];
    }

    public function afterRun(ImportContext $ctx, bool $dryRun): void
    {
        if ($dryRun || $this->affectedDates === []) {
            return;
        }

        $changeDate = LedgerService::cutoff();
        foreach (array_keys($this->affectedDates) as $date) {
            try {
                LedgerService::reconcile('stock', $date, LedgerService::forStockDay($date), $changeDate);
            } catch (Throwable $e) {
                throw ValidationException::withMessages([
                    'batch' => "Opening stock was not applied because the stock GL journal for {$date} could not be reconciled: {$e->getMessage()}",
                ]);
            }
            $this->assertOpeningEquityMatches($date);
        }
    }

    public function reconcile(ImportContext $ctx): array
    {
        $maps = DataMigrationIdMap::withoutGlobalScopes()
            ->where('hotel_id', $ctx->hotel->id)
            ->where('dataset', 'opening_stock')
            ->where('batch_id', $ctx->batch->id)
            ->get();
        $ids = $maps->where('model_type', StockMovement::class)->pluck('model_id')->all();
        $movements = $ids ? StockMovement::whereIn('id', $ids)->get() : collect();
        $dates = $movements->pluck('date')->filter()->map(fn ($d) => $d->toDateString())->unique()->sort()->values();
        $value = $movements->sum(fn ($m) => round((float) $m->qty * (float) $m->rate, 2));
        $openingCredit = 0.0;
        $journalNumbers = [];
        foreach ($dates as $date) {
            $journal = $this->activeJournal($date);
            if ($journal) {
                $journalNumbers[] = $journal->journal_no;
                $openingCredit += $this->openingEquityCredit($journal);
            }
        }

        return [
            'Opening stock rows in this batch' => $movements->count(),
            'Opening stock quantity' => round($movements->sum(fn ($m) => (float) $m->qty), 2),
            'Opening stock value in property currency' => round($value, 2),
            'Opening-stock GL journals' => $journalNumbers ? implode(', ', $journalNumbers) : 'Missing',
            'Opening-equity credit on affected stock journals' => round($openingCredit, 2),
        ];
    }

    private function assertOpeningEquityMatches(string $date): void
    {
        $expected = StockMovement::with('item')->whereDate('date', $date)->where('type', 'opening')->get()
            ->sum(fn ($movement) => round((float) $movement->qty * ((float) $movement->rate ?: (float) ($movement->item?->last_cost ?? 0)), 2));
        $journal = $this->activeJournal($date);
        if ($expected < 0.01) {
            return;
        }
        if (! $journal) {
            throw ValidationException::withMessages(['batch' => "Opening stock movements for {$date} have no active stock GL journal."]);
        }

        $actual = $this->openingEquityCredit($journal);
        if (abs(round($actual - $expected, 2)) >= 0.01) {
            throw ValidationException::withMessages([
                'batch' => "Opening-equity credit for {$date} does not reconcile: expected ".number_format($expected, 2).', found '.number_format($actual, 2).'.',
            ]);
        }
        $balance = (float) $journal->lines()->sum('debit') - (float) $journal->lines()->sum('credit');
        if (abs(round($balance, 2)) >= 0.01) {
            throw ValidationException::withMessages(['batch' => "Stock GL journal {$journal->journal_no} is not balanced."]);
        }
    }

    private function openingEquityCredit(GlJournal $journal): float
    {
        $accountId = AccountHead::sys('opening_equity');
        $lines = GlLine::where('gl_journal_id', $journal->id)->where('account_head_id', $accountId);

        return (float) $lines->sum('credit') - (float) $lines->sum('debit');
    }

    private function activeJournal(string $date): ?GlJournal
    {
        return GlJournal::where('source_type', 'stock')->where('source_key', $date)
            ->whereNull('reversed_by_id')->whereNull('reversal_of_id')->latest('id')->first();
    }

    private function movementMap(ImportContext $ctx, string $externalId): ?DataMigrationIdMap
    {
        return DataMigrationIdMap::withoutGlobalScopes()
            ->where('hotel_id', $ctx->hotel->id)
            ->where('dataset', 'opening_stock')
            ->where('external_id', $externalId)
            ->first();
    }

    private function numberValue(?string $raw): ?float
    {
        $value = str_replace([',', ' ', 'GH₵', 'GHS', '₵', '¢', '$', '£', '€'], '', trim((string) $raw));
        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) ? $number : null;
    }

    private function hasTwoDecimals(float $value): bool
    {
        return abs($value - round($value, 2)) < 0.00000001;
    }

    private function failed(string $message, ?int $modelId): array
    {
        return ['status' => 'failed', 'message' => $message, 'model_type' => StockMovement::class, 'model_id' => $modelId];
    }
}
