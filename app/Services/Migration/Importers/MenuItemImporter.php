<?php

namespace App\Services\Migration\Importers;

use App\Models\DataMigrationIdMap;
use App\Models\MenuItem;
use App\Services\Migration\BaseImporter;
use App\Services\Migration\ImportContext;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Menu catalogue transfer adapter. Imports the sellable item's basic catalogue
 * fields and side-size variants only. Images, recipe cards, modifier groups and
 * allergen declarations stay on their purpose-built module workflows.
 */
class MenuItemImporter extends BaseImporter
{
    private const DATASET = 'menu_items';

    public function canAccess(): bool
    {
        return auth()->user() === null
            ? app()->runningInConsole()
            : can_do('masters.manage');
    }

    public function authorizeAccess(): void
    {
        abort_unless($this->canAccess(), 403, 'You need master-data permission to transfer menu catalogue records.');
    }

    public function label(): string
    {
        return 'Menu items and side-size variants (catalogue only)';
    }

    public function columns(): array
    {
        return [
            'external_id' => ['required' => true, 'desc' => 'Stable source id; stored in a property-scoped import map.', 'example' => '', 'aliases' => ['source_id']],
            'code' => ['desc' => 'Optional menu-item code; when supplied it is used as a natural match key.', 'example' => 'BRK-01'],
            'name' => ['required' => true, 'desc' => 'Menu item name.', 'example' => 'Grilled tilapia'],
            'menu_category_id' => ['required' => true, 'desc' => 'Existing category id in this property.', 'example' => '1', 'aliases' => ['category_id']],
            'outlet_id' => ['desc' => 'Optional outlet id in this property; blank means all outlets.', 'example' => '1'],
            'kot_printer_id' => ['desc' => 'Optional KOT printer id in this property.', 'example' => ''],
            'price' => ['required' => true, 'desc' => 'Menu price in the property currency.', 'example' => '180'],
            'tax_rate' => ['required' => true, 'desc' => 'Tax percentage (0–50).', 'example' => '25'],
            'food_type' => ['required' => true, 'desc' => 'veg, non_veg, egg or beverage.', 'example' => 'non_veg'],
            'description' => ['desc' => 'Optional menu description.', 'example' => 'Grilled with local herbs.'],
            'is_available' => ['desc' => '1/0, true/false, yes/no. CSV cannot activate an item; leave blank to preserve, or use 0 to hide.', 'example' => '0'],
            'variants_json' => ['desc' => 'Optional JSON list of {name, rate, cost}; rate and cost may be left blank (saved as 0 = included in the item price). Blank preserves existing variants, [] removes them.', 'example' => '[]'],
        ];
    }

    public function templateRows(): array
    {
        return [array_fill_keys(array_keys($this->columns()), '')];
    }

    public function validateRow(array $row, ImportContext $ctx, int $rowNo): ?string
    {
        if (! $this->canAccess()) {
            return 'You do not have permission to import menu catalogue records.';
        }
        if (! $this->externalId($row['external_id'] ?? null)) {
            return 'external_id is required and must be no longer than 100 characters.';
        }
        $availability = strtolower(trim((string) ($row['is_available'] ?? '')));
        if ($availability !== '' && ! in_array($availability, ['0', '1', 'true', 'false', 'yes', 'no', 'y', 'n', 'on', 'off'], true)) {
            return 'is_available must be a boolean value (1/0, true/false, yes/no).';
        }

        $fields = $this->fields($row);
        $validator = Validator::make($fields, [
            'code' => 'nullable|string|max:20',
            'name' => 'required|string|max:120',
            'menu_category_id' => 'required|integer|owned:menu_categories',
            'outlet_id' => 'nullable|integer|owned:outlets',
            'kot_printer_id' => 'nullable|integer|owned:kot_printers',
            'price' => 'required|numeric|min:0',
            'tax_rate' => 'required|numeric|min:0|max:50',
            'food_type' => 'required|in:veg,non_veg,egg,beverage',
            'description' => 'nullable|string|max:255',
            'is_available' => 'nullable|boolean',
        ]);
        if ($validator->fails()) {
            return implode(' ', $validator->errors()->all());
        }
        if (strlen((string) ($row['variants_json'] ?? '')) > 15000) {
            return 'variants_json must be no longer than 15,000 characters.';
        }
        $variants = $this->parseVariants($row['variants_json'] ?? null, $variantError);
        if ($variantError !== null) {
            return $variantError;
        }

        $existing = $this->findExisting($this->externalId($row['external_id']), $fields, $ctx, $error);
        if ($error !== null) {
            return $error;
        }
        if (! $existing && $fields['is_available'] === true) {
            return 'New catalogue items must be imported unavailable. Complete the recipe, modifier, allergen and image setup in the menu module, then enable the item there.';
        }
        if ($existing && $fields['is_available'] === true && ! $existing->is_available) {
            return 'CSV cannot make an unavailable menu item sellable. Complete the module setup and enable it from the menu screen.';
        }
        if ($existing) {
            foreach ($this->naturalKeyGroups() as $group) {
                if ($this->groupIncomplete($fields, $group)) {
                    continue;
                }
                $matches = $this->matchingGroup($fields, $group);
                if ($matches->count() > 1) {
                    return 'The menu-item natural key '.implode('+', $group).' matches multiple records; resolve the duplicates first.';
                }
                if ($matches->isNotEmpty() && (int) $matches->first()->id !== (int) $existing->id) {
                    return implode('+', $group).' is already used by another menu item in this property.';
                }
            }
        }

        return null;
    }

    public function validateBatch(array $rows, ImportContext $ctx): ?string
    {
        $seenExternal = [];
        $seenKeys = [];
        foreach ($rows as $index => $row) {
            $line = $index + 1;
            $externalId = $this->externalId($row['external_id'] ?? null);
            $externalKey = $externalId === null ? null : mb_strtolower($externalId);
            if ($externalKey !== null && isset($seenExternal[$externalKey])) {
                return 'Duplicate external_id "'.$externalId.'" on rows '.$seenExternal[$externalKey].' and '.$line.'.';
            }
            if ($externalKey !== null) {
                $seenExternal[$externalKey] = $line;
            }

            $fields = $this->fields($row);
            foreach ($this->naturalKeyGroups() as $group) {
                if ($this->groupIncomplete($fields, $group)) {
                    continue;
                }
                $keyName = implode('+', $group);
                $key = mb_strtolower(json_encode(array_map(fn ($field) => $fields[$field], $group), JSON_UNESCAPED_UNICODE) ?: '');
                if (isset($seenKeys[$keyName][$key])) {
                    return "Duplicate menu-item {$keyName} on rows {$seenKeys[$keyName][$key]} and {$line}.";
                }
                $seenKeys[$keyName][$key] = $line;
            }
        }

        return null;
    }

    public function importRow(array $row, ImportContext $ctx, bool $dryRun): array
    {
        $externalId = $this->externalId($row['external_id'] ?? null);
        $fields = $this->fields($row);
        $modelClass = MenuItem::class;
        $variants = $this->parseVariants($row['variants_json'] ?? null, $variantError);
        if ($variantError !== null) {
            return ['status' => 'failed', 'message' => $variantError, 'model_type' => $modelClass, 'model_id' => null];
        }
        $existing = $this->findExisting($externalId, $fields, $ctx, $error);
        if ($error !== null) {
            return ['status' => 'failed', 'message' => $error, 'model_type' => $modelClass, 'model_id' => null];
        }
        if (! $existing) {
            if ($fields['is_available']) {
                return ['status' => 'failed', 'message' => 'New catalogue items must be imported unavailable until their module setup is complete.', 'model_type' => $modelClass, 'model_id' => null];
            }
            if ($dryRun) {
                return ['status' => 'created', 'message' => null, 'model_type' => $modelClass, 'model_id' => null];
            }
            $fields['is_available'] ??= false;
            $model = DB::transaction(function () use ($fields, $variants, $ctx, $externalId) {
                $model = new MenuItem();
                $model->fill($fields);
                $model->hotel_id = $ctx->hotel->id;
                $model->save();
                if ($variants !== null) {
                    $this->syncVariants($model, $variants);
                }
                $ctx->map(self::DATASET, $externalId, MenuItem::class, (int) $model->id);

                return $model;
            });

            return ['status' => 'created', 'message' => null, 'model_type' => $modelClass, 'model_id' => (int) $model->id];
        }

        $updateFields = $fields;
        if ($updateFields['is_available'] === null) {
            unset($updateFields['is_available']);
        }
        $dirty = $this->dirty($existing, $updateFields);
        $variantsChanged = $variants !== null && ! $this->sameVariants($existing, $variants);
        if (! $dirty && ! $variantsChanged) {
            if (! $dryRun) {
                $ctx->map(self::DATASET, $externalId, $modelClass, (int) $existing->id);
            }

            return ['status' => 'unchanged', 'message' => null, 'model_type' => $modelClass, 'model_id' => (int) $existing->id];
        }
        $summary = $this->changeSummary($existing, $dirty, $variants, $variantsChanged);
        if ($dryRun) {
            return ['status' => 'updated', 'message' => 'Would update: '.$summary, 'model_type' => $modelClass, 'model_id' => (int) $existing->id];
        }
        DB::transaction(function () use ($existing, $dirty, $variantsChanged, $variants, $ctx, $externalId) {
            $locked = $this->query()->whereKey($existing->id)->lockForUpdate()->first();
            if (! $locked) {
                throw ValidationException::withMessages(['batch' => 'The mapped menu item disappeared before apply; no changes were made.']);
            }
            if ($dirty) {
                $locked->fill($dirty)->save();
            }
            if ($variantsChanged && $variants !== null) {
                $this->syncVariants($locked, $variants);
            }
            $ctx->map(self::DATASET, $externalId, MenuItem::class, (int) $locked->id);
        });

        return ['status' => 'updated', 'message' => 'Updated: '.$summary, 'model_type' => $modelClass, 'model_id' => (int) $existing->id];
    }

    public function reconcile(ImportContext $ctx): array
    {
        $mapped = DataMigrationIdMap::withoutGlobalScopes()
            ->where('hotel_id', $ctx->hotel->id)->where('dataset', self::DATASET)->count();

        return [
            'Menu items in this property' => $this->query()->count(),
            'External ids mapped (all batches)' => $mapped,
        ];
    }

    /** Export approved catalogue fields and variant rows; never export photos, recipes, allergens or modifiers. */
    public function exportRows(): iterable
    {
        $this->authorizeAccess();
        foreach ($this->query()->with('variants')->orderBy('id')->lazyById(250) as $item) {
            $variants = $item->variants->map(fn ($variant) => [
                'name' => $variant->name,
                'rate' => number_format((float) $variant->rate, 2, '.', ''),
                'cost' => number_format((float) $variant->cost, 2, '.', ''),
            ])->values()->all();
            yield [
                'external_id' => 'LOCAL-'.$item->id,
                'code' => $item->code,
                'name' => $item->name,
                'menu_category_id' => $item->menu_category_id,
                'outlet_id' => $item->outlet_id,
                'kot_printer_id' => $item->kot_printer_id,
                'price' => $item->price,
                'tax_rate' => $item->tax_rate,
                'food_type' => $item->food_type,
                'description' => $item->description,
                'is_available' => $item->is_available ? '1' : '0',
                'variants_json' => json_encode($variants, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
            ];
        }
    }

    private function fields(array $row): array
    {
        $fields = [];
        foreach (['code', 'name', 'menu_category_id', 'outlet_id', 'kot_printer_id', 'price', 'tax_rate', 'food_type', 'description'] as $field) {
            $value = $row[$field] ?? null;
            $fields[$field] = is_string($value) && trim($value) === '' ? null : (is_string($value) ? trim($value) : $value);
        }
        $availability = trim((string) ($row['is_available'] ?? ''));
        $fields['is_available'] = $availability === '' ? null : $this->bool($availability, false);

        return $fields;
    }

    private function parseVariants(?string $json, ?string &$error): ?array
    {
        $error = null;
        $json = trim((string) $json);
        if ($json === '') {
            return null;
        }
        try {
            $variants = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $error = 'variants_json must be a valid JSON list of {name, rate, cost} objects.';

            return null;
        }
        if (! is_array($variants) || ! array_is_list($variants) || count($variants) > 30) {
            $error = 'variants_json must be a JSON list with no more than 30 variants.';

            return null;
        }
        // Rate and cost are optional: an empty cell arrives as '' in JSON and is normalised to null,
        // which saves as 0 — the POS reads a 0 rate as "included in the item price".
        $variants = array_map(function ($variant) {
            if (! is_array($variant)) {
                return $variant;
            }
            foreach (['rate', 'cost'] as $money) {
                if (array_key_exists($money, $variant) && ($variant[$money] === '' || $variant[$money] === null)) {
                    $variant[$money] = null;
                }
            }

            return $variant;
        }, $variants);
        try {
            $validated = Validator::make(['variants' => $variants], [
                'variants' => ['array', 'max:30'],
                'variants.*' => ['array:name,rate,cost'],
                'variants.*.name' => ['required', 'string', 'max:100', 'distinct:ignore_case'],
                'variants.*.rate' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
                'variants.*.cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            ], [
                'variants.*.name.distinct' => 'Variant names must be unique (case-insensitive).',
            ])->validate();
        } catch (ValidationException $e) {
            $error = implode(' ', $e->validator->errors()->all());

            return null;
        }

        return array_map(fn ($variant) => [
            'name' => trim($variant['name']),
            'rate' => number_format(round((float) ($variant['rate'] ?? 0), 2), 2, '.', ''),
            'cost' => number_format(round((float) ($variant['cost'] ?? 0), 2), 2, '.', ''),
        ], array_values($validated['variants'] ?? []));
    }

    private function naturalKeyGroups(): array
    {
        return [['code'], ['menu_category_id', 'outlet_id', 'name']];
    }

    private function groupIncomplete(array $fields, array $group): bool
    {
        foreach ($group as $field) {
            if (! array_key_exists($field, $fields) || $fields[$field] === '') {
                return true;
            }
            if ($fields[$field] === null && $field !== 'outlet_id') {
                return true;
            }
        }

        return false;
    }

    private function matchingGroup(array $fields, array $group)
    {
        $query = $this->query();
        foreach ($group as $field) {
            $query->where($field, $fields[$field]);
        }

        return $query->limit(2)->get();
    }

    private function findExisting(string $externalId, array $fields, ImportContext $ctx, ?string &$error): ?MenuItem
    {
        $error = null;
        $map = DataMigrationIdMap::withoutGlobalScopes()
            ->where('hotel_id', $ctx->hotel->id)->where('dataset', self::DATASET)->where('external_id', $externalId)->first();
        if ($map) {
            if ($map->model_type !== MenuItem::class) {
                $error = 'The saved external-id map points to an unexpected record type; no changes were made.';

                return null;
            }
            $mapped = $this->query()->whereKey($map->model_id)->first();
            if (! $mapped) {
                $error = 'The saved external-id map does not point to a menu item in this property; no changes were made.';

                return null;
            }

            return $mapped;
        }
        $matches = collect();
        foreach ($this->naturalKeyGroups() as $group) {
            if ($this->groupIncomplete($fields, $group)) {
                continue;
            }
            $groupMatches = $this->matchingGroup($fields, $group);
            if ($groupMatches->count() > 1) {
                $error = 'The menu-item natural key '.implode('+', $group).' matches multiple records; resolve the duplicates before importing.';

                return null;
            }
            if ($groupMatches->isNotEmpty()) {
                $matches->push($groupMatches->first());
            }
        }
        if ($matches->pluck('id')->unique()->count() > 1) {
            $error = 'The menu-item code and category/name keys point to different records; resolve the conflict before importing.';

            return null;
        }

        return $matches->first();
    }

    private function query()
    {
        return MenuItem::query()->where('hotel_id', Tenant::id());
    }

    private function syncVariants(MenuItem $item, array $variants): void
    {
        $item->variants()->delete();
        foreach ($variants as $sort => $variant) {
            $item->variants()->create([
                'name' => $variant['name'],
                'rate' => $variant['rate'],
                'cost' => $variant['cost'],
                'sort_order' => $sort,
            ]);
        }
    }

    private function sameVariants(MenuItem $item, array $variants): bool
    {
        $current = $item->variants()->orderBy('sort_order')->orderBy('id')->get()->map(fn ($variant) => [
            'name' => $variant->name,
            'rate' => number_format((float) $variant->rate, 2, '.', ''),
            'cost' => number_format((float) $variant->cost, 2, '.', ''),
        ])->values()->all();

        return $current === $variants;
    }

    private function changeSummary(MenuItem $item, array $dirty, ?array $variants, bool $variantsChanged): string
    {
        $parts = [];
        foreach ($dirty as $field => $newValue) {
            $oldValue = $item->getAttribute($field);
            $parts[] = $field.': '.($oldValue === null ? 'blank' : (string) $oldValue).' → '.($newValue === null ? 'blank' : (string) $newValue);
        }
        if ($variantsChanged) {
            $parts[] = 'variants_json: '.($item->variants()->count()).' existing → '.count($variants ?? []).' incoming variant(s)';
        }

        return implode('; ', $parts);
    }
}
