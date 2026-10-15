<?php

namespace App\Services\Migration\Importers;

use App\Models\Company;
use App\Services\Migration\BaseImporter;
use App\Services\Migration\ImportContext;

/**
 * Companies / corporate accounts (v7.35 cutover).
 */
class CompanyImporter extends BaseImporter
{
    public function label(): string
    {
        return 'Companies / corporate accounts';
    }

    public function columns(): array
    {
        return [
            'external_id' => ['required' => true, 'desc' => 'Legacy system id, stable across exports', 'example' => 'C-1001', 'aliases' => ['id', 'company_id', 'code']],
            'name' => ['required' => true, 'desc' => 'Company name', 'example' => 'Sahara Events Ltd', 'aliases' => ['company', 'company_name']],
            'type' => ['desc' => 'corporate, travel_agent or ota (default corporate)', 'example' => 'corporate'],
            'contact_person' => ['desc' => 'Main contact', 'example' => 'Efua Adu', 'aliases' => ['contact']],
            'phone' => ['desc' => 'Phone number', 'example' => '+233 30 123 4567'],
            'email' => ['desc' => 'E-mail address', 'example' => 'accounts@sahara.example'],
            'address' => ['desc' => 'Postal address', 'example' => '12 Ridge Rd, Accra'],
            'tax_number' => ['desc' => 'TIN / VAT number', 'example' => 'C0001234567', 'aliases' => ['tin', 'vat_no']],
            'credit_limit' => ['desc' => 'Credit limit (numbers only)', 'example' => '50000'],
            'discount_pct' => ['desc' => 'Default discount %', 'example' => '10'],
            'is_active' => ['desc' => '1/0 (default 1)', 'example' => '1', 'aliases' => ['active']],
        ];
    }

    public function validateRow(array $row, ImportContext $ctx, int $rowNo): ?string
    {
        if (! $this->externalId($row['external_id'] ?? null)) {
            return 'external_id is required.';
        }
        if (! $this->str($row['name'] ?? null, 255)) {
            return 'name is required.';
        }
        $type = strtolower($this->str($row['type'] ?? null, 20) ?? 'corporate');
        if (! in_array($type, ['corporate', 'travel_agent', 'ota'], true)) {
            return "type \"{$type}\" must be corporate, travel_agent or ota.";
        }

        return null;
    }

    public function importRow(array $row, ImportContext $ctx, bool $dryRun): array
    {
        $ext = $this->externalId($row['external_id']);
        $fields = [
            'name' => $this->str($row['name'], 255),
            'type' => strtolower($this->str($row['type'] ?? null, 20) ?? 'corporate'),
            'contact_person' => $this->str($row['contact_person'] ?? null, 255),
            'phone' => $this->str($row['phone'] ?? null, 30),
            'email' => $this->str($row['email'] ?? null, 255),
            'address' => $this->str($row['address'] ?? null, 255),
            'tax_number' => $this->str($row['tax_number'] ?? null, 40),
            'credit_limit' => $this->num($row['credit_limit'] ?? null),
            'discount_pct' => $this->num($row['discount_pct'] ?? null),
            'is_active' => $this->bool($row['is_active'] ?? null, true),
        ];

        $id = $ctx->mappedId('companies', $ext);
        $model = $id ? Company::find($id) : null;
        if (! $model) {
            $model = Company::where('name', $fields['name'])->first(); // legacy match by name
        }

        if (! $model) {
            if ($dryRun) {
                return ['status' => 'created', 'message' => null, 'model_type' => Company::class, 'model_id' => null];
            }
            $model = Company::create($fields);
            $ctx->map('companies', $ext, Company::class, $model->id);

            return ['status' => 'created', 'message' => null, 'model_type' => Company::class, 'model_id' => $model->id];
        }

        $dirty = $this->dirty($model, $fields);
        if (! $dirty) {
            if (! $id && ! $dryRun) {
                $ctx->map('companies', $ext, Company::class, $model->id);
            }

            return ['status' => 'unchanged', 'message' => null, 'model_type' => Company::class, 'model_id' => $model->id];
        }
        if ($dryRun) {
            return ['status' => 'updated', 'message' => 'Would update: '.implode(', ', array_keys($dirty)), 'model_type' => Company::class, 'model_id' => $model->id];
        }
        $model->fill($dirty)->save();
        $ctx->map('companies', $ext, Company::class, $model->id);

        return ['status' => 'updated', 'message' => 'Updated: '.implode(', ', array_keys($dirty)), 'model_type' => Company::class, 'model_id' => $model->id];
    }

    public function reconcile(ImportContext $ctx): array
    {
        $mapped = \App\Models\DataMigrationIdMap::withoutGlobalScopes()
            ->where('hotel_id', $ctx->hotel->id)->where('dataset', 'companies');

        return [
            'Companies in this property now' => Company::count(),
            'External ids mapped (all batches)' => (clone $mapped)->count(),
            'Active companies' => Company::where('is_active', true)->count(),
        ];
    }
}
