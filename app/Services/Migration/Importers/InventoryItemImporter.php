<?php

namespace App\Services\Migration\Importers;

use App\Models\DataMigrationIdMap;
use App\Models\InventoryItem;
use App\Models\ItemCategory;
use App\Services\Migration\BaseImporter;
use App\Services\Migration\ImportContext;

/** Inventory item master-data importer. Stock quantities and movements are deliberately excluded. */
class InventoryItemImporter extends BaseImporter
{
    public function label(): string
    {
        return 'Inventory item catalogue';
    }

    public function columns(): array
    {
        return [
            'external_id' => ['required' => true, 'desc' => 'Stable legacy item id', 'example' => 'ITEM-2001', 'aliases' => ['id', 'item_id', 'legacy_code']],
            'name' => ['required' => true, 'desc' => 'Item name', 'example' => 'Bottled water 500 ml', 'aliases' => ['item', 'description']],
            'code' => ['desc' => 'Stock or SKU code (optional)', 'example' => 'BEV-WATER-500', 'aliases' => ['sku', 'item_code']],
            'category' => ['desc' => 'Category name (created if it does not exist)', 'example' => 'Beverages', 'aliases' => ['category_name']],
            'unit' => ['desc' => 'Stock unit (default pcs)', 'example' => 'bottle', 'aliases' => ['uom', 'unit_of_measure']],
            'reorder_level' => ['desc' => 'Reorder point in stock units (default 0)', 'example' => '24'],
            'last_cost' => ['desc' => 'Last unit cost in the property currency (default 0)', 'example' => '12.50', 'aliases' => ['cost', 'unit_cost']],
            'tax_rate' => ['desc' => 'Tax rate percent, 0–100 (default 0)', 'example' => '15'],
            'yield_pct' => ['desc' => 'Usable yield percent, greater than 0 and up to 100 (default 100)', 'example' => '95'],
            'is_active' => ['desc' => '1/0 (default 1)', 'example' => '1', 'aliases' => ['active']],
        ];
    }

    public function validateRow(array $row, ImportContext $ctx, int $rowNo): ?string
    {
        if (! $this->externalId($row['external_id'] ?? null)) {
            return 'external_id is required.';
        }
        if (! $this->str($row['name'] ?? null, 120)) {
            return 'name is required.';
        }
        if (mb_strlen(trim((string) ($row['code'] ?? ''))) > 30) {
            return 'code must not exceed 30 characters.';
        }
        if (mb_strlen(trim((string) ($row['unit'] ?? ''))) > 20) {
            return 'unit must not exceed 20 characters.';
        }
        if (mb_strlen(trim((string) ($row['category'] ?? ''))) > 80) {
            return 'category must not exceed 80 characters.';
        }
        $active = strtolower(trim((string) ($row['is_active'] ?? '')));
        if ($active !== '' && ! in_array($active, ['1', '0', 'true', 'false', 'yes', 'no', 'y', 'n', 'on', 'off'], true)) {
            return 'is_active must be a boolean value (1/0, yes/no, true/false).';
        }

        $limits = [
            'reorder_level' => [0.0, 9999999999.99],
            'last_cost' => [0.0, 9999999999.99],
            'tax_rate' => [0.0, 100.0],
            'yield_pct' => [0.01, 100.0],
        ];
        foreach ($limits as $field => [$min, $max]) {
            $raw = trim((string) ($row[$field] ?? ''));
            if ($raw === '') {
                continue;
            }
            $value = $this->numberValue($raw);
            if ($value === null || $value < $min || $value > $max) {
                return "{$field} must be a valid number between {$min} and {$max}.";
            }
        }

        return null;
    }

    public function importRow(array $row, ImportContext $ctx, bool $dryRun): array
    {
        $externalId = $this->externalId($row['external_id']);
        $code = $this->str($row['code'] ?? null, 30);
        $name = $this->str($row['name'], 120);
        $categoryName = $this->str($row['category'] ?? null, 80);
        $category = $categoryName
            ? ItemCategory::whereRaw('lower(name) = ?', [mb_strtolower($categoryName)])->first()
            : null;

        $mappedId = $ctx->mappedId('inventory_items', $externalId);
        $item = $mappedId ? InventoryItem::find($mappedId) : null;
        if (! $item && $code) {
            $matches = InventoryItem::where('code', $code)->get();
            if ($matches->count() > 1) {
                return $this->failed('More than one inventory item has this code; resolve the match before importing.', null);
            }
            $item = $matches->first();
        }
        if (! $item) {
            $matches = InventoryItem::whereRaw('lower(name) = ?', [mb_strtolower($name)])->get();
            if ($matches->count() > 1) {
                return $this->failed('More than one inventory item has this name; resolve the match before importing.', null);
            }
            $candidate = $matches->first();
            if ($candidate && $code && $candidate->code && $candidate->code !== $code) {
                return $this->failed('The exact-name item has a different stock code; resolve the match before importing.', $candidate->id);
            }
            $item = $candidate;
        }

        if (! $categoryName) {
            $categoryId = null;
        } elseif ($category) {
            $categoryId = $category->id;
        } elseif ($dryRun) {
            $categoryId = null;
        } else {
            $categoryId = ItemCategory::create(['name' => $categoryName])->id;
        }

        $fields = [
            'item_category_id' => $categoryId,
            'code' => $code,
            'name' => $name,
            'unit' => $this->str($row['unit'] ?? null, 20) ?? 'pcs',
            'reorder_level' => $this->numberValue($row['reorder_level'] ?? null) ?? 0.0,
            'last_cost' => $this->numberValue($row['last_cost'] ?? null) ?? 0.0,
            'tax_rate' => $this->numberValue($row['tax_rate'] ?? null) ?? 0.0,
            'yield_pct' => $this->numberValue($row['yield_pct'] ?? null) ?? 100.0,
            'is_active' => $this->bool($row['is_active'] ?? null, true),
        ];

        if (! $item) {
            if ($dryRun) {
                return ['status' => 'created', 'message' => $categoryName && ! $category ? "New category {$categoryName} will be created" : null, 'model_type' => InventoryItem::class, 'model_id' => null];
            }
            $item = InventoryItem::create($fields);
            $ctx->map('inventory_items', $externalId, InventoryItem::class, $item->id);

            return ['status' => 'created', 'message' => $categoryName && ! $category ? "Created category {$categoryName}" : null, 'model_type' => InventoryItem::class, 'model_id' => $item->id];
        }

        // On an existing item, blank optional columns mean "leave as-is" rather than erase a catalogue value.
        $sourceColumns = [
            'item_category_id' => 'category', 'code' => 'code', 'unit' => 'unit',
            'reorder_level' => 'reorder_level', 'last_cost' => 'last_cost', 'tax_rate' => 'tax_rate',
            'yield_pct' => 'yield_pct', 'is_active' => 'is_active',
        ];
        $updates = ['name' => $fields['name']];
        foreach ($sourceColumns as $field => $source) {
            if (array_key_exists($source, $row) && trim((string) $row[$source]) !== '') {
                $updates[$field] = $fields[$field];
            }
        }
        $dirty = $this->dirty($item, $updates);
        if (! $dirty) {
            if (! $mappedId && ! $dryRun) {
                $ctx->map('inventory_items', $externalId, InventoryItem::class, $item->id);
            }

            return ['status' => 'unchanged', 'message' => null, 'model_type' => InventoryItem::class, 'model_id' => $item->id];
        }
        if ($dryRun) {
            return ['status' => 'updated', 'message' => 'Would update: '.implode(', ', array_keys($dirty)), 'model_type' => InventoryItem::class, 'model_id' => $item->id];
        }
        $item->fill($dirty)->save();
        $ctx->map('inventory_items', $externalId, InventoryItem::class, $item->id);

        return ['status' => 'updated', 'message' => 'Updated: '.implode(', ', array_keys($dirty)), 'model_type' => InventoryItem::class, 'model_id' => $item->id];
    }

    public function reconcile(ImportContext $ctx): array
    {
        $mapped = DataMigrationIdMap::withoutGlobalScopes()
            ->where('hotel_id', $ctx->hotel->id)->where('dataset', 'inventory_items');

        return [
            'Inventory items in this property now' => InventoryItem::count(),
            'Active inventory items' => InventoryItem::where('is_active', true)->count(),
            'Item categories' => ItemCategory::count(),
            'External ids mapped (all batches)' => (clone $mapped)->count(),
        ];
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

    private function failed(string $message, ?int $modelId): array
    {
        return ['status' => 'failed', 'message' => $message, 'model_type' => InventoryItem::class, 'model_id' => $modelId];
    }
}
