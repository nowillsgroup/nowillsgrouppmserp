<?php

namespace App\Services;

use App\Models\AccountHead;
use App\Models\PaymentMode;
use App\Models\Voucher;

/**
 * Standard hotel chart of accounts (USALI-inspired numbering).
 * ensure() is idempotent: it adopts existing heads by name, creates missing system accounts,
 * numbers un-coded heads and maps payment modes. Safe to call from provisioning and upgrades.
 */
class ChartOfAccounts
{
    /** key => [code, name, type, group, [legacy names adopted]] */
    public const SYSTEM = [
        'cash' => ['1010', 'Cash in Hand', 'asset', 'Cash & Bank', ['Cash in Hand']],
        'bank' => ['1020', 'Bank Account', 'asset', 'Cash & Bank', ['Bank Account']],
        'card_clearing' => ['1030', 'Card Settlements Receivable', 'asset', 'Cash & Bank'],
        'wallet_clearing' => ['1040', 'Mobile Money Clearing', 'asset', 'Cash & Bank'],
        'guest_ledger' => ['1100', 'Guest Ledger (In-house Receivable)', 'asset', 'Receivables'],
        'city_ledger' => ['1110', 'City Ledger Receivable', 'asset', 'Receivables'],
        'club_receivable' => ['1120', 'Club Members Receivable', 'asset', 'Receivables'], // v5.1 health club members' accounts
        'lease_receivable' => ['1130', 'Tenant Receivables', 'asset', 'Receivables'], // v5.2 apartment & lease accounts
        'inventory' => ['1200', 'Inventory – Stores', 'asset', 'Current Assets'],
        'input_tax' => ['1300', 'Input VAT, NHIL & GETFund Recoverable', 'asset', 'Current Assets'],
        'wht_recoverable' => ['1315', 'Withholding Tax Deducted by Customers', 'asset', 'Current Assets'], // v5.2 WHT credits on rent receipts
        'suspense' => ['1900', 'Suspense Account', 'asset', 'Current Assets'],
        'prepayments' => ['1320', 'Prepaid Expenses', 'asset', 'Current Assets', ['Prepaid Expenses', 'Prepayments']], // v5.6 period close
        'deferred_income' => ['2145', 'Deferred Income', 'liability', 'Current Liabilities', ['Deferred Income', 'Income Received in Advance']],
        'accrued_expenses' => ['2150', 'Accrued Expenses', 'liability', 'Current Liabilities', ['Accrued Expenses', 'Accruals']],
        'accounts_payable' => ['2010', 'Accounts Payable – Suppliers', 'liability', 'Current Liabilities'],
        'tax_payable' => ['2100', 'Output VAT, NHIL & GETFund Payable (GRA)', 'liability', 'Duties & Taxes'],
        'tourism_levy' => ['2105', 'Tourism Levy Payable (GTA)', 'liability', 'Duties & Taxes'], // v4.3 Ghana: 1% levy remitted to the Tourism Development Fund
        'service_charge' => ['2110', 'Service Charge Payable', 'liability', 'Current Liabilities'],
        'club_unearned' => ['2130', 'Membership Fees Received in Advance', 'liability', 'Current Liabilities'], // v5.1 deferred subscriptions
        'package_liability' => ['2135', 'Package Allowances – Unconsumed', 'liability', 'Current Liabilities'], // v5.5 meals / credits included in rates
        'lease_deposits' => ['2140', 'Tenant Security Deposits Held', 'liability', 'Current Liabilities'], // v5.2
        'capital' => ['3010', "Owner's Capital", 'equity', 'Capital'],
        'retained' => ['3100', 'Retained Earnings', 'equity', 'Capital'],
        'opening_equity' => ['3200', 'Opening Balance Equity', 'equity', 'Capital'],
        'rev_room' => ['4010', 'Room Revenue', 'income', 'Operating Revenue', ['Room Revenue']],
        'rev_fnb' => ['4020', 'F&B Revenue', 'income', 'Operating Revenue', ['F&B Revenue']],
        'rev_banquet' => ['4030', 'Banquet Revenue', 'income', 'Operating Revenue', ['Banquet Revenue']],
        'rev_spa' => ['4040', 'Spa & Activities Revenue', 'income', 'Operating Revenue'],
        'rev_club' => ['4042', 'Health Club Membership Revenue', 'income', 'Operating Revenue'], // v5.1 recognised monthly
        'rev_club_other' => ['4043', 'Health Club Day Passes & Joining Fees', 'income', 'Operating Revenue'],
        'rev_telephone' => ['4045', 'Telephone Revenue', 'income', 'Operating Revenue'], // v3.7 call accounting
        'rev_pay_tv' => ['4047', 'In-room Entertainment (Pay TV) Revenue', 'income', 'Operating Revenue'], // v7.1 IPTV movie rentals
        'rev_minibar' => ['4050', 'Minibar Revenue', 'income', 'Operating Revenue'],
        'rev_lease_rent' => ['4052', 'Apartment & Lease Rent', 'income', 'Operating Revenue'], // v5.2
        'rev_lease_other' => ['4053', 'Lease Service Charges & Utility Recharges', 'income', 'Operating Revenue'],
        'rev_laundry' => ['4060', 'Laundry Revenue', 'income', 'Operating Revenue'],
        'rev_cancellation' => ['4070', 'Cancellation & No-show Fees', 'income', 'Operating Revenue'],
        'rev_transport' => ['4070', 'Transport Revenue', 'income', 'Operating Revenue'], // v3.3 guest transfers
        'rev_parking' => ['4075', 'Parking & Valet Revenue', 'income', 'Operating Revenue'], // v6.6 car park & valet
        'rev_pkg_breakage' => ['4092', 'Package Breakage Income', 'income', 'Operating Revenue'], // v5.5 unused inclusions
        'rev_other' => ['4090', 'Other Operating Income', 'income', 'Operating Revenue', ['Other Income']],
        'discounts' => ['4900', 'Discounts & Allowances', 'income', 'Operating Revenue'],
        'rounding' => ['4990', 'Round-off', 'income', 'Other Income'],
        'cost_fnb' => ['5010', 'Cost of Food & Beverage Consumed', 'expense', 'Direct Costs'],
        'cost_hk' => ['5020', 'Guest Supplies Consumed', 'expense', 'Direct Costs'],
        'cost_general' => ['5030', 'General Stores Consumed', 'expense', 'Direct Costs'],
        'stock_variance' => ['5040', 'Stock Variance & Wastage', 'expense', 'Direct Costs'],
        'food_waste' => ['5042', 'Food Waste', 'expense', 'Direct Costs'], // v5.0 food-safety waste log
        'price_variance' => ['5045', 'Purchase Price Variance', 'expense', 'Direct Costs'],
        'loyalty' => ['5050', 'Loyalty Redemptions', 'expense', 'Direct Costs'],
        'commission' => ['5060', 'Agent Commission', 'expense', 'Direct Costs', ['Agent Commission']],
        // v2.0 payroll
        'staff_advances' => ['1400', 'Staff Advances & Loans', 'asset', 'Current Assets'],
        'salary_payable' => ['2200', 'Salaries & Wages Payable', 'liability', 'Current Liabilities'],
        'pf_payable' => ['2210', 'SSNIT Tier 1 Payable (13.5%)', 'liability', 'Duties & Taxes'],
        'esi_payable' => ['2220', 'Tier 2 Occupational Pension Payable (5%)', 'liability', 'Duties & Taxes'],
        'paye_payable' => ['2230', 'PAYE Payable (GRA)', 'liability', 'Duties & Taxes'],
        'pt_payable' => ['2240', 'Other Statutory Deductions Payable', 'liability', 'Duties & Taxes'],
        // v4.4 Ghana: tax withheld from supplier payments (Income Tax Act 2015, Act 896) and withholding VAT (appointed agents)
        'wht_payable' => ['2250', 'Withholding Tax Payable (GRA)', 'liability', 'Duties & Taxes'],
        'wvat_payable' => ['2255', 'Withholding VAT Payable (GRA)', 'liability', 'Duties & Taxes'],
        'salaries' => ['6010', 'Salaries & Wages', 'expense', 'Operating Expenses', ['Salaries & Wages']],
        'employer_contrib' => ['6015', 'Employer SSNIT & Pension Contributions', 'expense', 'Operating Expenses'],
        // v2.1 online payments
        'gateway_clearing' => ['1050', 'Payment Gateway Clearing', 'asset', 'Cash & Bank'],
        'gateway_fees' => ['6120', 'Payment Gateway & Bank Charges', 'expense', 'Operating Expenses'],
        'bank_charges' => ['6125', 'Bank Charges & Commissions', 'expense', 'Operating Expenses'], // v4.6 bank reconciliation
        'momo_fees' => ['6126', 'Mobile Money Charges', 'expense', 'Operating Expenses'],
        'interest_income' => ['4088', 'Bank Interest Income', 'income', 'Other Income'],
        // v4.7 petty cash & staff expense claims (adopts the standard expense heads the claim categories book to)
        'petty_iou' => ['1420', 'Petty Cash IOUs (Staff)', 'asset', 'Current Assets'],
        'claims_payable' => ['2260', 'Staff Expense Claims Payable', 'liability', 'Current Liabilities'],
        'travel' => ['6160', 'Travel & Transport', 'expense', 'Operating Expenses', ['Travel & Transport', 'Travelling Expenses', 'Conveyance']],
        'staff_welfare' => ['6165', 'Staff Meals & Welfare', 'expense', 'Operating Expenses', ['Staff Welfare', 'Staff Meals & Welfare']],
        'entertainment' => ['6170', 'Business Entertainment', 'expense', 'Operating Expenses', ['Business Entertainment', 'Entertainment']],
        'cash_over_short' => ['6190', 'Cash Shortages & Overages', 'expense', 'Operating Expenses', ['Cash Short / Over']],
        'repairs' => ['6040', 'Repairs & Maintenance', 'expense', 'Operating Expenses', ['Repairs & Maintenance']],
        'telephone_exp' => ['6100', 'Internet & Telephone', 'expense', 'Operating Expenses', ['Internet & Telephone']],
        'stationery' => ['6140', 'Printing & Stationery', 'expense', 'Operating Expenses', ['Printing & Stationery']],
        'misc_expense' => ['6110', 'Miscellaneous Expense', 'expense', 'Operating Expenses', ['Miscellaneous Expense']],
        // v2.2 laundry & linen
        'laundry_linen' => ['6130', 'Laundry & Linen', 'expense', 'Operating Expenses'],
        // v2.3 fixed assets (adopts the heads older installs created by hand)
        'fixed_assets' => ['1510', 'Furniture, Fixtures & Equipment', 'asset', 'Fixed Assets', ['Furniture, Fixtures & Equipment', 'Fixed Assets']],
        'acc_depreciation' => ['1590', 'Accumulated Depreciation', 'asset', 'Fixed Assets', ['Accumulated Depreciation']],
        'depreciation' => ['6500', 'Depreciation', 'expense', 'Non-operating Expenses', ['Depreciation']],
        'asset_disposal' => ['4980', 'Gain / Loss on Disposal of Assets', 'income', 'Other Income'],
        // v3.4 forex desk
        'forex_on_hand' => ['1060', 'Foreign Currency on Hand', 'asset', 'Cash & Bank'],
        'cheques_in_hand' => ['1035', 'Cheques in Hand (undeposited)', 'asset', 'Cash & Bank', ['Cheques in Hand']], // v5.7 treasury
        'cash_in_transit' => ['1090', 'Cash in Transit', 'asset', 'Cash & Bank', ['Cash in Transit']],
        'bank_loans' => ['2700', 'Bank Loans & Borrowings', 'liability', 'Long-term Liabilities', ['Bank Loans', 'Term Loan']],
        'forex_income' => ['4085', 'Forex Commission Income', 'income', 'Other Income'],
        'sc_retained' => ['4095', 'Service Charge Retained (House Share)', 'income', 'Other Income'], // v4.9 tronc
        'forex_gain' => ['4986', 'Exchange Gain / Loss', 'income', 'Other Income'],
        // v3.8 gift vouchers & stored-value cards
        'voucher_liability' => ['2120', 'Gift Vouchers Outstanding', 'liability', 'Current Liabilities'],
        'voucher_breakage' => ['4087', 'Voucher Breakage Income', 'income', 'Other Income'],
        'voucher_promo' => ['6150', 'Promotional Vouchers', 'expense', 'Operating Expenses'],
        // v3.9 utilities & energy (adopts the Electricity / Water heads older installs created by hand)
        'utility_power' => ['6020', 'Electricity', 'expense', 'Operating Expenses', ['Electricity', 'Power & Electricity']],
        'utility_water' => ['6030', 'Water', 'expense', 'Operating Expenses', ['Water', 'Water Charges']],
        'utility_fuel' => ['6025', 'Fuel – LPG, Diesel & Gas', 'expense', 'Operating Expenses'],
        // v5.6 typical accrual / prepayment heads
        'insurance' => ['6180', 'Insurance', 'expense', 'Operating Expenses', ['Insurance', 'Insurance Premium']],
        'professional_fees' => ['6185', 'Audit & Professional Fees', 'expense', 'Operating Expenses', ['Professional Fees', 'Audit Fees', 'Legal & Professional']],
        'rev_space_rental' => ['4086', 'Space Rental & Concession Income', 'income', 'Operating Revenue', ['Rental Income', 'Concession Income']],
        // v5.8 hotel management agreement
        'mgmt_base_fee' => ['6600', 'Management Fees – Base', 'expense', 'Management Fees', ['Management Fees', 'Base Management Fee']],
        'mgmt_incentive_fee' => ['6605', 'Management Fees – Incentive', 'expense', 'Management Fees', ['Incentive Management Fee']],
        'mgmt_fees_payable' => ['2160', 'Management Fees Payable', 'liability', 'Current Liabilities'],
        'interest_expense' => ['6700', 'Interest on Loans & Borrowings', 'expense', 'Non-operating Expenses', ['Interest Expense', 'Loan Interest', 'Bank Interest']],
        // v5.9 concierge: activities sold as agent – the gross is owed to the vendor, only the commission is revenue
        'concierge_payable' => ['2170', 'Activity & Excursion Vendors Payable', 'liability', 'Current Liabilities'],
        'concierge_receivable' => ['1140', 'Commission Receivable – Activity Vendors', 'asset', 'Receivables'],
        'rev_concierge' => ['4089', 'Concierge & Excursion Commission', 'income', 'Other Income', ['Tour Commission', 'Excursion Commission']],
    ];

    /** Default revenue account per folio charge category. */
    public const CATEGORY_DEFAULTS = [
        'room' => 'rev_room', 'extra_bed' => 'rev_room', 'fnb' => 'rev_fnb', 'banquet' => 'rev_banquet', 'spa' => 'rev_spa',
        'minibar' => 'rev_minibar', 'laundry' => 'rev_laundry', 'cancellation' => 'rev_cancellation', 'transport' => 'rev_transport', 'parking' => 'rev_parking', 'pay_tv' => 'rev_pay_tv',
        'telephone' => 'rev_telephone', 'club' => 'rev_club_other',
        'excursion' => 'concierge_payable', // v5.9: agency sales – the room charge is owed to the activity vendor
    ];

    public const MODE_DEFAULTS = ['cash' => 'cash', 'card' => 'card_clearing', 'upi' => 'wallet_clearing', 'wallet' => 'wallet_clearing', 'bank' => 'bank', 'city_ledger' => 'city_ledger', 'online' => 'gateway_clearing', 'forex' => 'forex_on_hand', 'gift_voucher' => 'voucher_liability', 'cheque' => 'cheques_in_hand'];

    /** Code series for heads created by users/older versions without a code. */
    protected const SERIES = ['asset' => [1500, 'Current Assets'], 'liability' => [2500, 'Current Liabilities'], 'equity' => [3500, 'Capital'], 'income' => [4500, 'Other Income'], 'expense' => [6010, 'Operating Expenses']];

    /** Cheap guard: only runs the full ensure() when a system account is missing. */
    public static function ensureLight(): void
    {
        if (AccountHead::whereNotNull('system_key')->count() < count(self::SYSTEM)) {
            self::ensure();
        }
    }

    public static function ensure(): void
    {
        AccountHead::flushSys();
        $heads = AccountHead::all();
        foreach (self::SYSTEM as $key => $def) {
            [$code, $name, $type, $group] = $def;
            $head = $heads->firstWhere('system_key', $key)
                ?? $heads->first(fn ($h) => ! $h->system_key && in_array($h->name, $def[4] ?? []))
                ?? $heads->first(fn ($h) => ! $h->system_key && $h->name === $name);
            if (! $head) {
                $head = new AccountHead(['name' => $name]);
                $heads->push($head);
            }
            $taken = $heads->first(fn ($h) => $h !== $head && $h->code === $code);
            $head->forceFill([
                'system_key' => $key, 'type' => $type,
                'code' => $head->code ?: ($taken ? null : $code),
                'group_name' => $head->group_name ?: $group,
            ])->save();
        }

        // Legacy "City Ledger Receipts" income head: receipts against companies settle a receivable, not income.
        $legacy = AccountHead::whereNull('system_key')->where('name', 'City Ledger Receipts')->first();
        if ($legacy) {
            $cl = $heads->firstWhere('system_key', 'city_ledger');
            Voucher::where('account_head_id', $legacy->id)->update(['account_head_id' => $cl->id]);
            if (! $legacy->lines()->exists() && ! PaymentMode::where('account_head_id', $legacy->id)->exists()) {
                $legacy->delete();
            }
        }

        // Number the remaining heads per type series.
        $used = AccountHead::whereNotNull('code')->pluck('code')->all();
        foreach (AccountHead::whereNull('code')->orderBy('type')->orderBy('id')->get() as $h) {
            [$start, $group] = self::SERIES[$h->type] ?? self::SERIES['expense'];
            $n = $start;
            while (in_array((string) $n, $used, true)) {
                $n += 10;
            }
            $used[] = (string) $n;
            $h->forceFill(['code' => (string) $n, 'group_name' => $h->group_name ?: $group])->save();
        }

        AccountHead::flushSys();
        foreach (PaymentMode::whereNull('account_head_id')->get() as $m) {
            $m->forceFill(['account_head_id' => AccountHead::sys(self::MODE_DEFAULTS[$m->type] ?? 'suspense')])->save();
        }
    }
}
