<?php

namespace App\Services\Migration\Importers;

use App\Models\DataMigrationIdMap;
use App\Models\Supplier;
use App\Services\Migration\BaseImporter;
use App\Services\Migration\ImportContext;
use App\Services\WhtService;

/** Supplier master-data importer. It intentionally does not import bills or payments. */
class SupplierImporter extends BaseImporter
{
    public function label(): string
    {
        return 'Supplier master records';
    }

    public function columns(): array
    {
        return [
            'external_id' => ['required' => true, 'desc' => 'Stable legacy supplier id', 'example' => 'SUP-1001', 'aliases' => ['id', 'supplier_id', 'code']],
            'name' => ['required' => true, 'desc' => 'Supplier legal or trading name', 'example' => 'Accra Food Traders Ltd', 'aliases' => ['supplier', 'supplier_name']],
            'contact_person' => ['desc' => 'Primary contact', 'example' => 'Ama Mensah', 'aliases' => ['contact']],
            'phone' => ['desc' => 'Telephone number', 'example' => '+233 30 123 4567'],
            'email' => ['desc' => 'Accounts/contact e-mail', 'example' => 'accounts@example.com'],
            'address' => ['desc' => 'Postal address', 'example' => '12 Ridge Road, Accra'],
            'tax_number' => ['desc' => 'Supplier TIN', 'example' => 'C0001234567', 'aliases' => ['tin', 'vat_no']],
            'payment_terms_days' => ['desc' => 'Default payment terms in days (default 30)', 'example' => '30', 'aliases' => ['terms_days']],
            'wht_category' => ['desc' => 'none, goods, works, services, rent_res, rent_com or nonresident (default services)', 'example' => 'services'],
            'wht_exempt_ref' => ['desc' => 'GRA withholding-tax exemption certificate reference', 'example' => 'WHT-EX-2026-001'],
            'wht_exempt_until' => ['desc' => 'Certificate expiry date', 'example' => '2027-12-31'],
            'is_local' => ['desc' => '1/0 — Ghana-based/local supplier (default 0)', 'example' => '1'],
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

        $category = strtolower($this->str($row['wht_category'] ?? null, 12) ?? 'services');
        if (! isset(WhtService::CATEGORIES[$category])) {
            return 'wht_category must be one of: '.implode(', ', array_keys(WhtService::CATEGORIES)).'.';
        }

        $terms = str_replace([',', ' '], '', trim((string) ($row['payment_terms_days'] ?? '')));
        if ($terms !== '' && (! preg_match('/^\d+$/', $terms) || (int) $terms > 65535)) {
            return 'payment_terms_days must be a whole number between 0 and 65535.';
        }
        if (! empty($row['email']) && ! filter_var(trim((string) $row['email']), FILTER_VALIDATE_EMAIL)) {
            return 'email is not a valid e-mail address.';
        }
        foreach (['is_local', 'is_active'] as $field) {
            $value = strtolower(trim((string) ($row[$field] ?? '')));
            if ($value !== '' && ! in_array($value, ['1', '0', 'true', 'false', 'yes', 'no', 'y', 'n', 'on', 'off'], true)) {
                return "{$field} must be a boolean value (1/0, yes/no, true/false).";
            }
        }
        if (! empty($row['wht_exempt_until']) && ! $this->date($row['wht_exempt_until'])) {
            return 'wht_exempt_until is not a recognised date.';
        }

        return null;
    }

    public function importRow(array $row, ImportContext $ctx, bool $dryRun): array
    {
        $externalId = $this->externalId($row['external_id']);
        $taxNumber = $this->str($row['tax_number'] ?? null, 40);
        $fields = [
            'name' => $this->str($row['name'], 255),
            'contact_person' => $this->str($row['contact_person'] ?? null, 255),
            'phone' => $this->str($row['phone'] ?? null, 30),
            'email' => $this->str($row['email'] ?? null, 255),
            'address' => $this->str($row['address'] ?? null, 255),
            'tax_number' => $taxNumber,
            'payment_terms_days' => (int) $this->num($row['payment_terms_days'] ?? null, 30),
            'wht_category' => strtolower($this->str($row['wht_category'] ?? null, 12) ?? 'services'),
            'wht_exempt_ref' => $this->str($row['wht_exempt_ref'] ?? null, 60),
            'wht_exempt_until' => $this->date($row['wht_exempt_until'] ?? null),
            'is_local' => $this->bool($row['is_local'] ?? null, false),
            'is_active' => $this->bool($row['is_active'] ?? null, true),
        ];

        $mappedId = $ctx->mappedId('suppliers', $externalId);
        $supplier = $mappedId ? Supplier::find($mappedId) : null;
        if (! $supplier && $taxNumber) {
            $matches = Supplier::where('tax_number', $taxNumber)->get();
            if ($matches->count() > 1) {
                return $this->failed('More than one existing supplier has this TIN; resolve the match before importing.', Supplier::class);
            }
            $supplier = $matches->first();
        }
        if (! $supplier) {
            $matches = Supplier::whereRaw('lower(name) = ?', [mb_strtolower($fields['name'])])->get();
            if ($matches->count() > 1) {
                return $this->failed('More than one existing supplier has this name; resolve the match before importing.', Supplier::class);
            }
            $candidate = $matches->first();
            if ($candidate && $taxNumber && $candidate->tax_number && $candidate->tax_number !== $taxNumber) {
                return $this->failed('The exact-name supplier has a different TIN; resolve the match before importing.', Supplier::class, $candidate->id);
            }
            $supplier = $candidate;
        }

        if (! $supplier) {
            if ($dryRun) {
                return ['status' => 'created', 'message' => null, 'model_type' => Supplier::class, 'model_id' => null];
            }
            $supplier = Supplier::create($fields);
            $ctx->map('suppliers', $externalId, Supplier::class, $supplier->id);

            return ['status' => 'created', 'message' => null, 'model_type' => Supplier::class, 'model_id' => $supplier->id];
        }

        // Blank optional cells preserve existing financial/contact settings; clear a value in the PMS after cutover.
        $updates = ['name' => $fields['name']];
        foreach ($fields as $key => $value) {
            if ($key !== 'name' && array_key_exists($key, $row) && trim((string) $row[$key]) !== '') {
                $updates[$key] = $value;
            }
        }
        $dirty = $this->dirty($supplier, $updates);
        if (! $dirty) {
            if (! $mappedId && ! $dryRun) {
                $ctx->map('suppliers', $externalId, Supplier::class, $supplier->id);
            }

            return ['status' => 'unchanged', 'message' => null, 'model_type' => Supplier::class, 'model_id' => $supplier->id];
        }
        if ($dryRun) {
            return ['status' => 'updated', 'message' => 'Would update: '.implode(', ', array_keys($dirty)), 'model_type' => Supplier::class, 'model_id' => $supplier->id];
        }
        $supplier->fill($dirty)->save();
        $ctx->map('suppliers', $externalId, Supplier::class, $supplier->id);

        return ['status' => 'updated', 'message' => 'Updated: '.implode(', ', array_keys($dirty)), 'model_type' => Supplier::class, 'model_id' => $supplier->id];
    }

    public function reconcile(ImportContext $ctx): array
    {
        $mapped = DataMigrationIdMap::withoutGlobalScopes()
            ->where('hotel_id', $ctx->hotel->id)->where('dataset', 'suppliers');

        return [
            'Suppliers in this property now' => Supplier::count(),
            'Active suppliers' => Supplier::where('is_active', true)->count(),
            'External ids mapped (all batches)' => (clone $mapped)->count(),
        ];
    }

    private function failed(string $message, string $modelType, ?int $modelId = null): array
    {
        return ['status' => 'failed', 'message' => $message, 'model_type' => $modelType, 'model_id' => $modelId];
    }
}
