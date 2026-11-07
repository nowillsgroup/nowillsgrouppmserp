<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Opening a city-ledger account from the desk that is taking the booking.
 *
 * Both desks need it — the walk-in check-in and the new-reservation form — and they
 * must produce the same account: same fields, same duplicate rule, same credit terms,
 * same audit trail. The only difference is the phrase recorded in the activity log.
 */
class CityLedgerService
{
    /** The fields the desk can capture when it opens an account. */
    public static function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'type' => 'required|in:corporate,travel_agent',
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:150',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:80',
            'credit_limit' => 'required|numeric|min:0|max:999999999999.99',
            'credit_days' => 'required|integer|min:0|max:365',
        ];
    }

    /**
     * Create the account, approve direct billing on it and record where it came from.
     *
     * @param  array  $data  already validated against rules()
     * @param  string  $source  the desk that opened it, for the activity log
     */
    public static function createFromDesk(array $data, string $source): Company
    {
        $data['name'] = trim($data['name']);
        if (Company::whereRaw('LOWER(name) = ?', [mb_strtolower($data['name'])])->exists()) {
            throw ValidationException::withMessages([
                'name' => 'A company with this name already exists. Select it from Company / account instead.',
            ]);
        }

        return DB::transaction(function () use ($data, $source): Company {
            $company = Company::create([
                'name' => $data['name'],
                'type' => $data['type'],
                'contact_person' => $data['contact_person'] ?? null,
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'address' => $data['address'] ?? null,
                'city' => $data['city'] ?? null,
                'credit_limit' => 0,
                'credit_days' => 30,
                'direct_billing' => false,
                'credit_status' => 'open',
                'is_active' => true,
                'tier' => 'standard',
                'sales_status' => 'active',
            ]);
            CreditService::setTerms(
                $company,
                (float) $data['credit_limit'],
                (int) $data['credit_days'],
                true,
                "Initial City Ledger setup from {$source}"
            );
            $company->refresh();
            ActivityLogger::log('credit', 'city_ledger_create', "City-ledger company {$company->name} created from {$source}", $company);

            return $company;
        });
    }

    /** The shape both desks use to select the account they just opened. */
    public static function payload(Company $company): array
    {
        return [
            'id' => $company->id,
            'name' => $company->name,
            'direct_billing' => (bool) $company->direct_billing,
            'credit_limit' => (float) $company->credit_limit,
            'credit_days' => (int) $company->credit_days,
        ];
    }
}
