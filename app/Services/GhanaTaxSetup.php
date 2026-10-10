<?php

namespace App\Services;

use App\Models\AccountHead;
use App\Models\PayComponent;
use App\Models\Setting;
use App\Models\Tax;

/**
 * v4.3 Ghana localisation of an existing property (idempotent; runs for the current tenant).
 *
 *  - tax rows: legacy GST rows are replaced by VAT 15% / NHIL 2.5% / GETFund 2.5% (GRA) + Tourism Levy 1% (GTA)
 *  - chart of accounts: statutory liabilities renamed (GRA output tax, GTA tourism levy, SSNIT tiers, PAYE)
 *  - payroll: PF / ESI / professional tax / TDS → SSNIT Tier 1 & Tier 2 and GRA PAYE (monthly bands)
 *  - settings that only made sense in India (GST on money changing, India tax slabs, …) are reset
 *
 * Called from HotelProvisioner::provision() and ::upgrade() (php artisan pms:upgrade) BEFORE the module defaults,
 * so PayrollService::ensureDefaults() only adds what is still missing.
 */
class GhanaTaxSetup
{
    /** Account renames: system key => [new name, [legacy default names that may be replaced]]. User-renamed heads are left alone. */
    public const ACCOUNT_NAMES = [
        'tax_payable' => ['Output VAT, NHIL & GETFund Payable (GRA)', ['Output Tax Payable', 'GST Payable', 'Output GST Payable']],
        'input_tax' => ['Input VAT, NHIL & GETFund Recoverable', ['Input Tax Credit', 'GST Input Tax Credit']],
        'wallet_clearing' => ['Mobile Money Clearing', ['UPI / Mobile Money Clearing']],
        'pf_payable' => ['SSNIT Tier 1 Payable (13.5%)', ['Provident Fund Payable']],
        'esi_payable' => ['Tier 2 Occupational Pension Payable (5%)', ['ESI / Health Insurance Payable']],
        'paye_payable' => ['PAYE Payable (GRA)', ['Income Tax (TDS / PAYE) Payable']],
        'pt_payable' => ['Other Statutory Deductions Payable', ['Professional Tax Payable']],
        'employer_contrib' => ['Employer SSNIT & Pension Contributions', ['Employer Contributions (PF / ESI)']],
    ];

    /** India-only pay components retired on conversion (kept for payslip history, switched off). */
    public const RETIRED_COMPONENTS = ['PF', 'ESI', 'PT', 'EPF_ER', 'ESI_ER'];

    public static function apply(): array
    {
        $done = ['taxes' => 0, 'accounts' => 0, 'components' => 0];

        // 1. tax rows
        if (! Tax::whereNotNull('code')->exists()) {
            Tax::where(fn ($q) => $q->where('name', 'like', '%GST%')->orWhere('name', 'like', 'Cess%'))->delete();
            foreach (TaxService::defaultRows() as $row) {
                Tax::create($row + ['slab_from' => 0, 'slab_to' => null, 'is_active' => true]);
                $done['taxes']++;
            }
        }
        TaxService::flush();

        // 2. chart of accounts
        ChartOfAccounts::ensure();
        foreach (self::ACCOUNT_NAMES as $key => [$name, $legacy]) {
            $h = AccountHead::where('system_key', $key)->first();
            if ($h && $h->name !== $name && in_array($h->name, $legacy, true)) {
                $h->forceFill(['name' => $name])->save();
                $done['accounts']++;
            }
        }
        AccountHead::flushSys();

        // 3. payroll structure
        $retired = PayComponent::whereIn('code', self::RETIRED_COMPONENTS)->where('is_active', true)->get();
        foreach ($retired as $c) {
            $c->forceFill(['is_active' => false, 'is_default' => false])->save();
            $done['components']++;
        }
        if (($tds = PayComponent::where('code', 'TDS')->first()) && ! PayComponent::where('code', 'PAYE')->exists()) {
            $tds->forceFill(['code' => 'PAYE', 'name' => 'PAYE income tax', 'calc' => 'tax', 'liability_head_id' => AccountHead::sys('paye_payable')])->save();
            $done['components']++;
        }
        if (($hra = PayComponent::where('code', 'HRA')->where('name', 'House rent allowance')->first()) && ! PayComponent::where('code', 'HOUSING')->exists()) {
            $hra->forceFill(['code' => 'HOUSING', 'name' => 'Housing allowance', 'value' => 15])->save();
            $done['components']++;
        }
        if (($conv = PayComponent::where('code', 'CONV')->where('name', 'Conveyance allowance')->first()) && ! PayComponent::where('code', 'TRANSPORT')->exists()) {
            $conv->forceFill(['code' => 'TRANSPORT', 'name' => 'Transport allowance', 'value' => 300])->save();
            $done['components']++;
        }

        // 4. item-level sales tax rates (menu, banquet packages / services, venue rent) → the Ghana combined rate, once
        if (Setting::where('key', 'tax_regime')->value('value') !== 'GH-2026') {
            $fnb = TaxService::rateFor('fnb', 0);
            $done['items'] = \App\Models\MenuItem::query()->update(['tax_rate' => $fnb])
                + \App\Models\BanquetPackage::query()->update(['tax_rate' => $fnb])
                + \App\Models\BanquetService::query()->update(['tax_rate' => $fnb]);
            Setting::updateOrCreate(['key' => 'banquet_hall_tax_rate'], ['value' => (string) TaxService::rateFor('service', 0)]);
            Setting::updateOrCreate(['key' => 'tax_regime'], ['value' => 'GH-2026']);
        }

        // 4b. v4.4 payment modes: India's UPI is replaced by Mobile Money (kept, inactive, for history)
        if (\App\Models\PaymentMode::where('type', 'wallet')->exists()) {
            $done['modes'] = \App\Models\PaymentMode::where('type', 'upi')->where('is_active', true)->update(['is_active' => false]);
        }
        foreach (WhtService::DEFAULTS as $k => $v) { // v4.4 withholding tax defaults
            Setting::firstOrCreate(['key' => $k], ['value' => $v]);
        }

        // 4c. v4.5 licence checklist: India items → Ghana authorities; statutory return defaults
        $done['licences'] = ComplianceService::ghanaRename();
        foreach (StatutoryService::DEFAULTS as $k => $v) {
            Setting::firstOrCreate(['key' => $k], ['value' => $v]);
        }

        // 5. settings
        foreach (['payroll_tax_slabs', 'payroll_tax_std_deduction', 'payroll_tax_rebate_limit', 'payroll_tax_cess_pct'] as $k) {
            Setting::where('key', $k)->delete();
        }
        Setting::where('key', 'forex_gst')->update(['value' => '0']);
        foreach (['gst_sac_%', 'gst_gsp_%', 'gst_einvoice_30day'] as $k) {
            Setting::where('key', 'like', $k)->delete();
        }
        Setting::$version++;

        return $done;
    }
}
