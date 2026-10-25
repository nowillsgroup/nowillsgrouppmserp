<?php

namespace App\Services\Migration\Importers;

use App\Models\DataMigrationIdMap;
use App\Models\InventoryItem;
use App\Services\AllergenService;
use App\Services\Migration\BaseImporter;
use App\Services\Migration\ImportContext;

/**
 * Read-only validation of legacy ingredient-allergen declarations.
 *
 * Allergen declarations can affect menu warnings and guest safety. This importer
 * deliberately does not write InventoryItem.allergens or mark any declaration
 * as verified; staff must review the supplier evidence in Food Safety first.
 */
class InventoryAllergenPreflightImporter extends BaseImporter
{
    private const MAX_DECLARATION_LENGTH = 300;

    public function label(): string
    {
        return 'Inventory allergens (preflight only; manual verification required)';
    }

    public function columns(): array
    {
        return [
            'external_id' => ['required' => true, 'desc' => 'Stable source declaration id; may equal the source item id', 'example' => 'ITEM-2001', 'aliases' => ['id', 'declaration_id', 'legacy_id']],
            'item_external_id' => ['required' => true, 'desc' => 'External id from an applied inventory_items batch', 'example' => 'ITEM-2001', 'aliases' => ['item_id', 'legacy_item_id']],
            'allergens' => ['required' => true, 'desc' => 'Pipe-separated canonical allergen keys or two-letter codes; use none only when the source explicitly records no listed allergens', 'example' => 'gluten|milk', 'aliases' => ['allergen_keys', 'allergen_codes']],
        ];
    }

    public function templateRows(): array
    {
        return [
            ['external_id' => 'ITEM-2001', 'item_external_id' => 'ITEM-2001', 'allergens' => 'gluten|milk'],
            ['external_id' => 'ITEM-2002', 'item_external_id' => 'ITEM-2002', 'allergens' => 'none'],
        ];
    }

    public function supportsApply(): bool
    {
        return false;
    }

    public function validateBatch(array $rows, ImportContext $ctx): ?string
    {
        if ($rows === []) {
            return 'The inventory-allergen declaration file has no rows.';
        }

        $sourceIds = [];
        $itemIds = [];
        foreach ($rows as $index => $row) {
            $sourceId = $this->sourceId($row['external_id'] ?? null);
            if ($sourceId !== null) {
                $sourceKey = mb_strtolower($sourceId);
                if (isset($sourceIds[$sourceKey])) {
                    return "external_id {$sourceId} appears more than once; each source declaration needs a unique stable id.";
                }
                $sourceIds[$sourceKey] = true;
            }

            $itemExternalId = $this->sourceId($row['item_external_id'] ?? null);
            if ($itemExternalId !== null) {
                $itemKey = mb_strtolower($itemExternalId);
                if (isset($itemIds[$itemKey])) {
                    return "item_external_id {$itemExternalId} appears more than once; supply one declaration per inventory item.";
                }
                $itemIds[$itemKey] = true;
            }

            $error = $this->validateRow($row, $ctx, $index + 2);
            if ($error !== null) {
                return 'Inventory-allergen row '.($index + 2).': '.$error;
            }
        }

        return null;
    }

    public function validateRow(array $row, ImportContext $ctx, int $rowNo): ?string
    {
        $sourceId = trim((string) ($row['external_id'] ?? ''));
        if ($sourceId === '') {
            return 'external_id is required and must be a stable source declaration id.';
        }
        if (mb_strlen($sourceId) > 100) {
            return 'external_id must be 100 characters or fewer.';
        }

        $itemExternalId = trim((string) ($row['item_external_id'] ?? ''));
        if ($itemExternalId === '') {
            return 'item_external_id is required; import and apply inventory_items before preflighting allergens.';
        }
        if (mb_strlen($itemExternalId) > 100) {
            return 'item_external_id must be 100 characters or fewer.';
        }

        $map = DataMigrationIdMap::withoutGlobalScopes()
            ->where('hotel_id', $ctx->hotel->id)
            ->where('dataset', 'inventory_items')
            ->where('external_id', $itemExternalId)
            ->first();
        if (! $map || $map->model_type !== InventoryItem::class || ! InventoryItem::find($map->model_id)) {
            return "Inventory item external id {$itemExternalId} is not mapped for this property; import and apply inventory_items first.";
        }

        $declaration = $this->parseDeclaration($row['allergens'] ?? null);
        if ($declaration['error'] !== null) {
            return $declaration['error'];
        }

        return null;
    }

    public function importRow(array $row, ImportContext $ctx, bool $dryRun): array
    {
        if (! $dryRun) {
            throw new \LogicException('Inventory-allergen declarations are preflight-only; Food Safety staff must verify source evidence before any live change.');
        }

        return [
            'status' => 'created',
            'message' => 'Validated only — no inventory allergen declaration or verification stamp was written; review supplier evidence in Food Safety.',
            'model_type' => null,
            'model_id' => null,
        ];
    }

    public function reconcile(ImportContext $ctx): array
    {
        $rows = $ctx->batch->rows()->orderBy('row_no')->get()
            ->map(fn ($row) => (array) $row->payload)->all();
        $itemIds = [];
        $allergenCounts = [];
        $explicitNone = 0;

        foreach ($rows as $row) {
            $itemId = $this->sourceId($row['item_external_id'] ?? null);
            if ($itemId !== null) {
                $itemIds[$itemId] = true;
            }

            $declaration = $this->parseDeclaration($row['allergens'] ?? null);
            if ($declaration['error'] !== null) {
                continue;
            }
            if ($declaration['explicit_none']) {
                $explicitNone++;
            }
            foreach ($declaration['keys'] as $key) {
                $allergenCounts[$key] = ($allergenCounts[$key] ?? 0) + 1;
            }
        }
        ksort($allergenCounts);

        return [
            'Preflight status' => $ctx->batch->rows_failed > 0
                ? 'REVIEW — correct the failed source rows and rerun preflight.'
                : 'PASS — mapped inventory items and allergen codes validated; nothing was imported or verified.',
            'Unique inventory item IDs referenced' => count($itemIds),
            'Source rows with an explicit none declaration' => $explicitNone,
            'Declaration row counts by allergen' => $allergenCounts
                ? implode('; ', array_map(fn ($key, $count) => AllergenService::short($key)." ({$key}): {$count}", array_keys($allergenCounts), array_values($allergenCounts)))
                : 'None listed',
            'Inventory allergen declarations written' => 'None — no InventoryItem.allergens field was changed.',
            'Food-safety verification' => 'Not performed — a responsible staff member must review supplier labels/specifications in Food Safety.',
            'GL impact' => 'None — this importer is preflight-only and creates no journal.',
        ];
    }

    /**
     * Parse one explicit source declaration. Accept canonical keys and the
     * two-letter codes shown in the Food Safety allergen matrix; do not infer
     * allergens from free text or silently discard unknown labels.
     *
     * @return array{keys:array<int,string>,explicit_none:bool,error:?string}
     */
    private function parseDeclaration(?string $raw): array
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return ['keys' => [], 'explicit_none' => false, 'error' => 'allergens is required; use none only when the source explicitly records no listed allergens.'];
        }
        if (mb_strlen($value) > self::MAX_DECLARATION_LENGTH) {
            return ['keys' => [], 'explicit_none' => false, 'error' => 'allergens must not exceed '.self::MAX_DECLARATION_LENGTH.' characters.'];
        }

        $parts = explode('|', $value);
        if (count($parts) === 1 && strtolower(trim($parts[0])) === 'none') {
            return ['keys' => [], 'explicit_none' => true, 'error' => null];
        }

        $aliases = [];
        foreach (AllergenService::ALLERGENS as $key => [, $code]) {
            $aliases[strtolower($key)] = $key;
            $aliases[strtolower($code)] = $key;
        }

        $keys = [];
        foreach ($parts as $part) {
            $token = trim($part);
            if ($token === '') {
                return ['keys' => [], 'explicit_none' => false, 'error' => 'allergens must be pipe-separated canonical keys or two-letter codes with no empty entries.'];
            }
            if (strtolower($token) === 'none') {
                return ['keys' => [], 'explicit_none' => false, 'error' => 'none must be the only allergen value in the row.'];
            }
            $key = $aliases[strtolower($token)] ?? null;
            if ($key === null) {
                return ['keys' => [], 'explicit_none' => false, 'error' => "Unknown allergen value {$token}; use a canonical key or Food Safety matrix code." ];
            }
            if (in_array($key, $keys, true)) {
                return ['keys' => [], 'explicit_none' => false, 'error' => "Allergen {$key} appears more than once in the declaration."];
            }
            $keys[] = $key;
        }

        return ['keys' => AllergenService::clean($keys), 'explicit_none' => false, 'error' => null];
    }

    private function sourceId(?string $raw): ?string
    {
        $value = trim((string) $raw);

        return $value === '' || mb_strlen($value) > 100 ? null : $value;
    }
}
