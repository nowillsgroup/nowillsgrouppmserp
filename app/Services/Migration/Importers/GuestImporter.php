<?php

namespace App\Services\Migration\Importers;

use App\Models\DataMigrationIdMap;
use App\Models\Guest;
use App\Services\Migration\BaseImporter;
use App\Services\Migration\ImportContext;

/**
 * Guest profiles (v7.35 cutover).
 *
 * Match order: legacy external id map → exact e-mail match (single) → create.
 */
class GuestImporter extends BaseImporter
{
    public function label(): string
    {
        return 'Guest profiles';
    }

    public function columns(): array
    {
        return [
            'external_id' => ['required' => true, 'desc' => 'Legacy system id, stable across exports', 'example' => 'G-2001', 'aliases' => ['id', 'guest_id', 'code']],
            'first_name' => ['required' => true, 'desc' => 'Given name(s)', 'example' => 'Ama', 'aliases' => ['name', 'first']],
            'last_name' => ['desc' => 'Family name', 'example' => 'Mensah', 'aliases' => ['surname']],
            'title' => ['desc' => 'Mr / Mrs / Ms / Dr …', 'example' => 'Ms'],
            'gender' => ['desc' => 'male / female / other', 'example' => 'female'],
            'dob' => ['desc' => 'Date of birth (YYYY-MM-DD or DD/MM/YYYY)', 'example' => '1988-04-12'],
            'phone' => ['desc' => 'Phone number', 'example' => '+233 24 555 0102'],
            'email' => ['desc' => 'E-mail (used to match existing profiles)', 'example' => 'ama@example.com'],
            'nationality' => ['desc' => 'Nationality', 'example' => 'Ghanaian'],
            'id_type' => ['desc' => 'passport, national_id, driving_licence, voter_id', 'example' => 'passport'],
            'id_number' => ['desc' => 'ID document number', 'example' => 'G1234567'],
            'passport_no' => ['desc' => 'Passport number', 'example' => 'G1234567'],
            'address' => ['desc' => 'Street address', 'example' => '12 Ridge Rd'],
            'city' => ['desc' => 'City', 'example' => 'Accra'],
            'state' => ['desc' => 'Region / state', 'example' => 'Greater Accra'],
            'country' => ['desc' => 'Country', 'example' => 'Ghana'],
            'pincode' => ['desc' => 'Postal code', 'example' => 'GA-107'],
            'tax_number' => ['desc' => 'TIN (Ghana Card PIN etc.)', 'example' => 'GHA-123456789-0', 'aliases' => ['tin']],
            'company_external_id' => ['desc' => 'Company external_id (import companies first)', 'example' => 'C-1001', 'aliases' => ['company_id', 'company']],
            'is_vip' => ['desc' => '1/0', 'example' => '0'],
            'preferences' => ['desc' => 'Free-text preferences', 'example' => 'High floor, quiet room'],
            'notes' => ['desc' => 'Free-text notes', 'example' => 'Repeat corporate guest'],
            'loyalty_points' => ['desc' => 'Opening loyalty balance', 'example' => '250'],
        ];
    }

    public function validateRow(array $row, ImportContext $ctx, int $rowNo): ?string
    {
        if (! $this->externalId($row['external_id'] ?? null)) {
            return 'external_id is required.';
        }
        if (! $this->str($row['first_name'] ?? null, 80)) {
            return 'first_name is required.';
        }
        foreach (['dob'] as $d) {
            if (! empty($row[$d]) && ! $this->date($row[$d])) {
                return "{$d} \"{$row[$d]}\" is not a recognised date.";
            }
        }
        if (! empty($row['email']) && ! filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
            return "email \"{$row['email']}\" is not valid.";
        }
        if (! empty($row['company_external_id']) && ! $ctx->mappedId('companies', $row['company_external_id'])) {
            return "company_external_id \"{$row['company_external_id']}\" is not in the id map – import the companies file first.";
        }
        $gender = strtolower($this->str($row['gender'] ?? null, 10) ?? '');
        if ($gender !== '' && ! in_array($gender, ['male', 'female', 'other', 'm', 'f', 'o'], true)) {
            return "gender \"{$gender}\" must be male, female or other.";
        }

        return null;
    }

    public function importRow(array $row, ImportContext $ctx, bool $dryRun): array
    {
        $ext = $this->externalId($row['external_id']);
        $companyId = $ctx->mappedId('companies', $this->externalId($row['company_external_id'] ?? null));
        $gender = strtolower($this->str($row['gender'] ?? null, 10) ?? '');
        $fields = [
            'company_id' => $companyId,
            'title' => $this->str($row['title'] ?? null, 10),
            'first_name' => $this->str($row['first_name'], 80),
            'last_name' => $this->str($row['last_name'] ?? null, 80),
            'gender' => match ($gender) {
                'm' => 'male', 'f' => 'female', 'o' => 'other', '' => null, default => $gender,
            },
            'dob' => $this->date($row['dob'] ?? null),
            'phone' => $this->str($row['phone'] ?? null, 30),
            'email' => $this->str($row['email'] ?? null, 255),
            'nationality' => $this->str($row['nationality'] ?? null, 60),
            'id_type' => $this->str($row['id_type'] ?? null, 30),
            'id_number' => $this->str($row['id_number'] ?? null, 60),
            'passport_no' => $this->str($row['passport_no'] ?? null, 40),
            'address' => $this->str($row['address'] ?? null, 255),
            'city' => $this->str($row['city'] ?? null, 80),
            'state' => $this->str($row['state'] ?? null, 80),
            'country' => $this->str($row['country'] ?? null, 80),
            'pincode' => $this->str($row['pincode'] ?? null, 20),
            'tax_number' => $this->str($row['tax_number'] ?? null, 40),
            'is_vip' => $this->bool($row['is_vip'] ?? null, false),
            'preferences' => $this->str($row['preferences'] ?? null, 500),
            'notes' => $this->str($row['notes'] ?? null, 65000),
            'loyalty_points' => (int) $this->num($row['loyalty_points'] ?? null),
        ];

        $id = $ctx->mappedId('guests', $ext);
        $model = $id ? Guest::find($id) : null;
        if (! $model && ! empty($fields['email'])) {
            $matches = Guest::where('email', $fields['email'])->get();
            if ($matches->count() === 1) {
                $model = $matches->first();
            }
        }

        if (! $model) {
            if ($dryRun) {
                return ['status' => 'created', 'message' => null, 'model_type' => Guest::class, 'model_id' => null];
            }
            $model = Guest::create($fields);
            $ctx->map('guests', $ext, Guest::class, $model->id);

            return ['status' => 'created', 'message' => null, 'model_type' => Guest::class, 'model_id' => $model->id];
        }

        $dirty = $this->dirty($model, $fields);
        if (! $dirty) {
            if (! $id && ! $dryRun) {
                $ctx->map('guests', $ext, Guest::class, $model->id);
            }

            return ['status' => 'unchanged', 'message' => null, 'model_type' => Guest::class, 'model_id' => $model->id];
        }
        if ($dryRun) {
            return ['status' => 'updated', 'message' => 'Would update: '.implode(', ', array_keys($dirty)), 'model_type' => Guest::class, 'model_id' => $model->id];
        }
        $model->fill($dirty)->save();
        $ctx->map('guests', $ext, Guest::class, $model->id);

        return ['status' => 'updated', 'message' => 'Updated: '.implode(', ', array_keys($dirty)), 'model_type' => Guest::class, 'model_id' => $model->id];
    }

    public function reconcile(ImportContext $ctx): array
    {
        return [
            'Guest profiles in this property now' => Guest::count(),
            'External ids mapped (all batches)' => DataMigrationIdMap::withoutGlobalScopes()
                ->where('hotel_id', $ctx->hotel->id)->where('dataset', 'guests')->count(),
            'Guests with e-mail' => Guest::whereNotNull('email')->count(),
            'Guests linked to a company' => Guest::whereNotNull('company_id')->count(),
        ];
    }
}
