<?php

namespace App\Services\Migration\Importers;

use App\Models\Concerns\BelongsToHotel;
use App\Models\DataMigrationIdMap;
use App\Services\Migration\BaseImporter;
use App\Services\Migration\ImportContext;
use App\Services\TaxService;
use App\Support\Tenant;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Validated transfer adapter for ordinary config-driven master records only.
 * It deliberately accepts only editable fields declared in config/masters.php;
 * ids, tenant keys, system flags, media uploads and account/login details never
 * enter through this adapter. Posted transactions have dedicated workflows.
 */
class ConfiguredMasterImporter extends BaseImporter
{
    private const EXCLUDED_FIELDS = ['image', 'image_path', 'bank_details', 'user_id', 'account_manager_id'];
    private const EXCLUDED_MASTERS = ['companies', 'employees', 'rooms', 'suppliers', 'inventory-items', 'menu-items'];
    private const COMPOSITE_NATURAL_KEYS = [
        'rate-plans' => [['room_type_id', 'code']],
        'menu-categories' => [['name', 'outlet_id']],
        'pos-tables' => [['outlet_id', 'table_no']],
        'taxes' => [
            ['code', 'authority', 'applies_to', 'slab_from', 'slab_to'],
            ['name', 'applies_to', 'slab_from', 'slab_to'],
        ],
    ];

    public function __construct(private readonly string $dataset)
    {
        if (! str_starts_with($dataset, 'masters__') || ! $this->definition() || ! in_array($dataset, self::datasetKeys(), true)) {
            throw ValidationException::withMessages(['dataset' => 'Unknown configured master dataset.']);
        }
    }

    public static function datasetKeys(): array
    {
        $keys = [];
        foreach (config('masters', []) as $slug => $definition) {
            $model = $definition['model'] ?? null;
            if (in_array($slug, self::EXCLUDED_MASTERS, true) || ! self::naturalKeyGroupsFor($slug, $definition)
                || ! is_string($model) || ! class_exists($model)
                || ! in_array(BelongsToHotel::class, class_uses_recursive($model), true)) {
                continue;
            }
            $keys[] = 'masters__'.$slug;
        }

        return $keys;
    }

    public function masterSlug(): string
    {
        return substr($this->dataset, strlen('masters__'));
    }

    public function definition(): ?array
    {
        $def = config('masters.'.$this->masterSlug());

        return is_array($def) && isset($def['model'], $def['fields']) ? $def : null;
    }

    public function canAccess(): bool
    {
        $def = $this->definition();

        if ($def === null) {
            return false;
        }
        if (auth()->user() === null) {
            return app()->runningInConsole();
        }

        if ($this->masterSlug() === 'taxes') {
            return can_do('masters.manage') && can_do('gl.manage');
        }

        return can_do($def['perm'] ?? 'masters.manage');
    }

    public function authorizeAccess(): void
    {
        abort_unless($this->canAccess(), 403, 'You do not have permission to transfer this module\'s master data.');
    }

    public function label(): string
    {
        return (string) ($this->definition()['title'] ?? $this->masterSlug());
    }

    /** @return array<string, array<string, mixed>> */
    public function columns(): array
    {
        $columns = [
            'external_id' => [
                'required' => true,
                'desc' => 'Stable source id; kept in a property-scoped import map.',
                'example' => '',
                'aliases' => ['source_id'],
            ],
        ];
        foreach ($this->safeFields() as $name => $field) {
            $type = (string) ($field[1] ?? 'text');
            $desc = (string) ($field[0] ?? $name);
            if (str_ends_with($name, '_id') && str_contains((string) ($field[3] ?? ''), 'model:')) {
                $desc .= ' — enter an existing record id from this property.';
            } elseif ($type === 'select') {
                $desc .= ' — enter the configured value/key, not its display label.';
            }
            $columns[$name] = [
                'required' => str_contains((string) ($field[2] ?? ''), 'required'),
                'desc' => $desc,
                'example' => '',
            ];
        }

        return $columns;
    }

    public function templateRows(): array
    {
        return [array_fill_keys(array_keys($this->columns()), '')];
    }

    public function validateRow(array $row, ImportContext $ctx, int $rowNo): ?string
    {
        if (! $this->canAccess()) {
            return 'You do not have permission to import this module.';
        }
        $externalId = $this->externalId($row['external_id'] ?? null);
        if (! $externalId) {
            return 'external_id is required and must be no longer than 100 characters.';
        }
        foreach ($this->safeFields() as $name => $field) {
            if (($field[1] ?? null) === 'bool') {
                $value = strtolower(trim((string) ($row[$name] ?? '')));
                if ($value !== '' && ! in_array($value, ['0', '1', 'true', 'false', 'yes', 'no', 'y', 'n', 'on', 'off'], true)) {
                    return "{$name} must be a boolean value (1/0, true/false, yes/no).";
                }
            }
        }
        $fields = $this->normalizedFields($row);
        $rules = [];
        foreach ($this->safeFields() as $name => $field) {
            $rules[$name] = $field[2] ?? 'nullable|string';
        }
        $validator = Validator::make($fields, $rules);
        if ($validator->fails()) {
            return implode(' ', $validator->errors()->all());
        }
        if ($this->masterSlug() === 'taxes') {
            $expectedAuthority = TaxService::COMPONENTS[$fields['code'] ?? ''][1] ?? null;
            if ($expectedAuthority === null || $fields['authority'] !== $expectedAuthority) {
                return 'The tax authority must match the selected statutory component (GRA for VAT/NHIL/GETFund; GTA for Tourism Levy).';
            }
        }

        $existing = $this->findExisting($externalId, $fields, $ctx, $error);
        if ($error !== null) {
            return $error;
        }
        if ($existing && $this->masterSlug() === 'account-heads' && $existing->system_key) {
            return 'System chart-of-accounts entries are provisioned and cannot be imported or changed by CSV. Use the secured account screen for permitted system-account edits.';
        }
        if ($existing) {
            foreach ($this->uniqueGroups() as $group) {
                if (collect($group)->contains(fn ($field) => ! array_key_exists($field, $fields) || $fields[$field] === '')) {
                    continue;
                }
                $query = $this->query();
                foreach ($group as $field) {
                    $query->where($field, $fields[$field]);
                }
                $conflicts = $query->limit(2)->get();
                if ($conflicts->count() > 1) {
                    return 'The configured natural key '.implode('+', $group).' matches multiple records in this property; resolve the duplicates first.';
                }
                if ($conflicts->isNotEmpty() && (int) $conflicts->first()->id !== (int) $existing->id) {
                    return implode('+', $group).' is already used by another record in this property.';
                }
            }
        }

        return null;
    }

    public function validateBatch(array $rows, ImportContext $ctx): ?string
    {
        $seenExternal = [];
        $seenUnique = [];
        foreach ($rows as $index => $row) {
            $line = $index + 1;
            $id = $this->externalId($row['external_id'] ?? null);
            $idKey = $id === null ? null : mb_strtolower($id);
            if ($idKey !== null && isset($seenExternal[$idKey])) {
                return 'Duplicate external_id "'.$id.'" on rows '.$seenExternal[$idKey].' and '.$line.'.';
            }
            if ($idKey !== null) {
                $seenExternal[$idKey] = $line;
            }
            $fields = $this->normalizedFields($row);
            foreach ($this->uniqueGroups() as $group) {
                if (collect($group)->contains(fn ($field) => ! array_key_exists($field, $fields) || $fields[$field] === '')) {
                    continue;
                }
                $keyName = implode('+', $group);
                $token = mb_strtolower(json_encode(array_map(fn ($field) => (string) $fields[$field], $group), JSON_UNESCAPED_UNICODE) ?: '');
                if (isset($seenUnique[$keyName][$token])) {
                    return "Duplicate {$keyName} value on rows {$seenUnique[$keyName][$token]} and {$line}.";
                }
                $seenUnique[$keyName][$token] = $line;
            }
        }

        return null;
    }

    public function importRow(array $row, ImportContext $ctx, bool $dryRun): array
    {
        $externalId = $this->externalId($row['external_id'] ?? null);
        $fields = $this->normalizedFields($row);
        $modelClass = $this->definition()['model'];
        $existing = $this->findExisting($externalId, $fields, $ctx, $error);
        if ($error !== null) {
            return ['status' => 'failed', 'message' => $error, 'model_type' => $modelClass, 'model_id' => null];
        }

        if (! $existing) {
            if ($dryRun) {
                return ['status' => 'created', 'message' => null, 'model_type' => $modelClass, 'model_id' => null];
            }
            $model = new $modelClass();
            $model->fill($fields);
            $model->hotel_id = $ctx->hotel->id;
            $model->save();
            $ctx->map($this->dataset, $externalId, $modelClass, (int) $model->id);

            return ['status' => 'created', 'message' => null, 'model_type' => $modelClass, 'model_id' => (int) $model->id];
        }

        $dirty = $this->dirty($existing, $fields);
        if (! $dirty) {
            if (! $dryRun) {
                $ctx->map($this->dataset, $externalId, $modelClass, (int) $existing->id);
            }

            return ['status' => 'unchanged', 'message' => null, 'model_type' => $modelClass, 'model_id' => (int) $existing->id];
        }
        $changeSummary = $this->changeSummary($existing, $dirty);
        if ($dryRun) {
            return ['status' => 'updated', 'message' => 'Would update: '.$changeSummary, 'model_type' => $modelClass, 'model_id' => (int) $existing->id];
        }
        $existing->fill($dirty)->save();
        $ctx->map($this->dataset, $externalId, $modelClass, (int) $existing->id);

        return ['status' => 'updated', 'message' => 'Updated: '.$changeSummary, 'model_type' => $modelClass, 'model_id' => (int) $existing->id];
    }

    private function changeSummary($model, array $dirty): string
    {
        if (! in_array($this->masterSlug(), ['rate-plans', 'taxes', 'account-heads'], true)) {
            return implode(', ', array_keys($dirty));
        }
        $parts = [];
        foreach ($dirty as $field => $newValue) {
            $oldValue = $model->getAttribute($field);
            if (is_bool($oldValue) || is_bool($newValue)) {
                $oldValue = $oldValue ? 'true' : 'false';
                $newValue = $newValue ? 'true' : 'false';
            }
            $oldValue = $oldValue === null ? 'blank' : (string) $oldValue;
            $newValue = $newValue === null ? 'blank' : (string) $newValue;
            $parts[] = $field.': '.$oldValue.' → '.$newValue;
        }

        return implode('; ', $parts);
    }

    public function reconcile(ImportContext $ctx): array
    {
        $mapped = DataMigrationIdMap::withoutGlobalScopes()
            ->where('hotel_id', $ctx->hotel->id)->where('dataset', $this->dataset)->count();
        $records = $this->query();
        if ($this->masterSlug() === 'account-heads') {
            $records->whereNull('system_key');
        }

        return [
            $this->label().' eligible in this property' => $records->count(),
            'External ids mapped (all batches)' => $mapped,
        ];
    }

    /** Exported field order mirrors the import template. */
    public function exportRows(): iterable
    {
        $this->authorizeAccess();
        $fields = array_keys($this->safeFields());
        $query = $this->query();
        if ($this->masterSlug() === 'account-heads') {
            $query->whereNull('system_key');
        }
        foreach ($query->orderBy('id')->cursor() as $model) {
            $row = ['external_id' => 'LOCAL-'.$model->id];
            foreach ($fields as $field) {
                $value = $model->getAttribute($field);
                if ($value instanceof \DateTimeInterface) {
                    $value = $value->format('Y-m-d');
                } elseif (is_object($value) && method_exists($value, '__toString')) {
                    $value = (string) $value;
                }
                $row[$field] = $value;
            }
            yield $row;
        }
    }

    private function safeFields(): array
    {
        return array_filter(
            $this->definition()['fields'],
            fn ($field, $name) => ! in_array($name, self::EXCLUDED_FIELDS, true)
                && ($field[1] ?? null) !== 'image'
                && ! str_ends_with($name, '_user_id'),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    private function normalizedFields(array $row): array
    {
        $fields = [];
        foreach ($this->safeFields() as $name => $field) {
            $value = $row[$name] ?? null;
            if (is_string($value)) {
                $value = trim($value);
                if ($value === '') {
                    $value = null;
                }
            }
            if (($field[1] ?? null) === 'bool') {
                $value = $value === null ? false : $this->bool((string) $value, false);
            } elseif (($field[1] ?? null) === 'number' && $value === null && ! str_contains((string) ($field[2] ?? ''), 'required')) {
                $value = in_array($name, ['slab_to'], true) ? null : ($name === 'credit_days' ? 30 : 0);
            }
            $fields[$name] = $value;
        }

        return $fields;
    }

    private function uniqueGroups(): array
    {
        return self::naturalKeyGroupsFor($this->masterSlug(), $this->definition() ?? []);
    }

    private static function naturalKeyGroupsFor(string $slug, array $definition): array
    {
        $safeNames = [];
        foreach (($definition['fields'] ?? []) as $name => $field) {
            if (! in_array($name, self::EXCLUDED_FIELDS, true)
                && ($field[1] ?? null) !== 'image'
                && ! str_ends_with($name, '_user_id')) {
                $safeNames[] = $name;
            }
        }
        $groups = self::COMPOSITE_NATURAL_KEYS[$slug]
            ?? array_map(fn ($field) => [$field], (array) ($definition['unique'] ?? []));

        return array_values(array_filter($groups, fn ($group) => count(array_intersect($group, $safeNames)) === count($group)));
    }

    private function query()
    {
        $modelClass = $this->definition()['model'];

        return $modelClass::query()->where('hotel_id', Tenant::id());
    }

    private function findExisting(string $externalId, array $fields, ImportContext $ctx, ?string &$error)
    {
        $error = null;
        $modelClass = $this->definition()['model'];
        $map = DataMigrationIdMap::withoutGlobalScopes()
            ->where('hotel_id', $ctx->hotel->id)
            ->where('dataset', $this->dataset)
            ->where('external_id', $externalId)
            ->first();
        if ($map) {
            if ($map->model_type !== $modelClass) {
                $error = 'The saved external-id map points to an unexpected record type; no changes were made.';

                return null;
            }
            $mapped = $this->query()->whereKey($map->model_id)->first();
            if (! $mapped) {
                $error = 'The saved external-id map does not point to a record in this property; no changes were made.';

                return null;
            }

            return $mapped;
        }

        $matches = collect();
        foreach ($this->uniqueGroups() as $group) {
            if (collect($group)->contains(fn ($field) => ! array_key_exists($field, $fields) || $fields[$field] === '')) {
                continue;
            }
            $query = $this->query();
            foreach ($group as $field) {
                $query->where($field, $fields[$field]);
            }
            $groupMatches = $query->limit(2)->get();
            if ($groupMatches->count() > 1) {
                $error = 'The configured natural key '.implode('+', $group).' matches multiple records in this property; resolve the duplicates before importing.';

                return null;
            }
            if ($groupMatches->isNotEmpty()) {
                $matches->push($groupMatches->first());
            }
        }
        $ids = $matches->pluck('id')->unique();
        if ($ids->count() > 1) {
            $error = 'Configured natural keys point to different existing records; resolve the conflict before importing.';

            return null;
        }

        return $matches->first();
    }
}
