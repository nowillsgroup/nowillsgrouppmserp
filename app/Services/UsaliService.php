<?php

namespace App\Services;

use App\Models\AccountHead;
use App\Models\Budget;
use App\Models\Department;
use App\Models\Employee;
use App\Models\GlLine;
use App\Models\OwnerFee;
use App\Models\PayrollRun;
use App\Models\Payslip;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v5.8 USALI (Uniform System of Accounts for the Lodging Industry, 11th ed.) management accounts built from the ledger.
 *
 *  - every income / expense account maps to a USALI department and category (automatic defaults by system account,
 *    overridable per account); salaries, employer contributions and staff welfare are split across departments by the
 *    payroll of each HR department (payslips of the month, else the salary roll);
 *  - Summary Operating Statement: operating revenue (rooms, F&B, other operated departments, miscellaneous income) →
 *    departmental expenses → total departmental profit → undistributed operating expenses → GOP → management fees →
 *    non-operating income & expenses → EBITDA, then for operators the replacement reserve, for owners interest,
 *    depreciation, amortisation and income taxes down to net income (which agrees to the GL profit);
 *  - departmental schedules, month / YTD against budget and last year, hotel KPIs.
 * Amounts are carried internally as profit contribution (income +, expense −).
 */
class UsaliService
{
    public const OPERATED = ['rooms' => 'Rooms', 'fnb' => 'Food & Beverage'];

    public const OOD = ['spa' => 'Spa & Wellness', 'club' => 'Health Club', 'laundry' => 'Guest Laundry', 'transport' => 'Transportation', 'parking' => 'Parking', 'residential' => 'Apartments & Leases'];

    public const UNDISTRIBUTED = ['ag' => 'Administrative & General', 'it' => 'Information & Telecommunications Systems', 'sm' => 'Sales & Marketing', 'pom' => 'Property Operation & Maintenance', 'util' => 'Utilities'];

    public const NONOP = ['nonop_income' => 'Income', 'rent' => 'Rent', 'prop_tax' => 'Property & Other Taxes', 'insurance' => 'Insurance', 'nonop_other' => 'Other'];

    public const BELOW = ['interest' => 'Interest', 'depreciation' => 'Depreciation', 'amortization' => 'Amortization', 'income_tax' => 'Income Taxes'];

    public const CATS = ['revenue' => 'Revenue', 'cos' => 'Cost of Sales', 'labor' => 'Labour Costs & Related Expenses', 'other' => 'Other Expenses'];

    /** automatic mapping of system accounts: key => [department, category] */
    public const DEFAULTS = [
        'rev_room' => ['rooms', 'revenue'], 'discounts' => ['rooms', 'revenue'], 'rev_fnb' => ['fnb', 'revenue'], 'rev_banquet' => ['fnb', 'revenue'], 'rev_minibar' => ['fnb', 'revenue'],
        'rev_spa' => ['spa', 'revenue'], 'rev_club' => ['club', 'revenue'], 'rev_club_other' => ['club', 'revenue'], 'rev_laundry' => ['laundry', 'revenue'], 'rev_transport' => ['transport', 'revenue'], 'rev_parking' => ['parking', 'revenue'],
        'rev_lease_rent' => ['residential', 'revenue'], 'rev_lease_other' => ['residential', 'revenue'],
        // Miscellaneous Income – net revenue with no direct costs: cancellation fees, package / voucher breakage, commissions, space rental, interest
        'rev_telephone' => ['misc', 'revenue'], 'rev_pay_tv' => ['misc', 'revenue'], 'rev_cancellation' => ['misc', 'revenue'], 'rev_pkg_breakage' => ['misc', 'revenue'], 'voucher_breakage' => ['misc', 'revenue'], 'forex_income' => ['misc', 'revenue'], 'rev_concierge' => ['misc', 'revenue'],
        'rev_space_rental' => ['misc', 'revenue'], 'interest_income' => ['misc', 'revenue'], 'sc_retained' => ['misc', 'revenue'], 'rev_other' => ['misc', 'revenue'], 'rounding' => ['misc', 'revenue'],
        'asset_disposal' => ['nonop_other', 'revenue'], 'forex_gain' => ['nonop_other', 'revenue'],
        'cost_fnb' => ['fnb', 'cos'], 'stock_variance' => ['fnb', 'cos'], 'food_waste' => ['fnb', 'cos'], 'price_variance' => ['fnb', 'cos'],
        'cost_hk' => ['rooms', 'other'], 'commission' => ['rooms', 'other'], 'laundry_linen' => ['rooms', 'other'], 'cost_general' => ['pom', 'other'],
        'salaries' => ['payroll', 'labor'], 'employer_contrib' => ['payroll', 'labor'], 'staff_welfare' => ['payroll', 'labor'],
        'utility_power' => ['util', 'other'], 'utility_fuel' => ['util', 'other'], 'utility_water' => ['util', 'other'], 'repairs' => ['pom', 'other'], 'telephone_exp' => ['it', 'other'],
        'misc_expense' => ['ag', 'other'], 'gateway_fees' => ['ag', 'other'], 'bank_charges' => ['ag', 'other'], 'momo_fees' => ['ag', 'other'], 'stationery' => ['ag', 'other'], 'travel' => ['ag', 'other'],
        'professional_fees' => ['ag', 'other'], 'cash_over_short' => ['ag', 'other'], 'loyalty' => ['sm', 'other'], 'voucher_promo' => ['sm', 'other'], 'entertainment' => ['sm', 'other'],
        'insurance' => ['insurance', 'other'], 'depreciation' => ['depreciation', 'other'], 'mgmt_base_fee' => ['mgmt', 'other'], 'mgmt_incentive_fee' => ['mgmt', 'other'], 'interest_expense' => ['interest', 'other'],
    ];

    protected static array $shares = [];

    protected static ?array $heads = null;

    protected static ?array $deptMap = null;

    public static function flush(): void
    {
        self::$shares = [];
        self::$heads = null;
        self::$deptMap = null;
    }

    // ───────────────────────── mapping ─────────────────────────

    /** every department code => label (for the mapping screen) */
    public static function depts(): array
    {
        $n = [];
        foreach (self::NONOP as $k => $l) {
            $n[$k] = 'Non-operating – '.$l;
        }

        return self::OPERATED + self::OOD + ['misc' => 'Miscellaneous Income'] + self::UNDISTRIBUTED + ['mgmt' => 'Management Fees'] + $n + self::BELOW
            + ['payroll' => 'Split by payroll department', 'excluded' => 'Not reported'];
    }

    /** departments an HR department's payroll may be reported in */
    public static function payrollDepts(): array
    {
        return self::OPERATED + self::OOD + self::UNDISTRIBUTED;
    }

    public static function label(string $dept): string
    {
        return self::depts()[$dept] ?? ucfirst($dept);
    }

    /** where an account is reported when nobody has chosen: system key, then name, then its group */
    public static function autoMap(AccountHead $h): array
    {
        if ($h->system_key && isset(self::DEFAULTS[$h->system_key])) {
            return self::DEFAULTS[$h->system_key];
        }
        $n = strtolower((string) $h->name);
        $g = strtolower((string) $h->group_name);
        if ($h->type === 'income') {
            return match (true) {
                str_contains($n, 'room') => ['rooms', 'revenue'],
                str_contains($n, 'food') || str_contains($n, 'beverage') || str_contains($n, 'banquet') => ['fnb', 'revenue'],
                str_contains($g, 'non-op') => ['nonop_income', 'revenue'],
                default => ['misc', 'revenue'],
            };
        }

        return match (true) {
            str_contains($n, 'food') || str_contains($n, 'beverage') => ['fnb', 'cos'],
            str_contains($n, 'housekeeping') || str_contains($n, 'guest suppl') || str_contains($n, 'linen') => ['rooms', 'other'],
            str_contains($n, 'marketing') || str_contains($n, 'advertis') || str_contains($n, 'promotion') => ['sm', 'other'],
            str_contains($n, 'salar') || str_contains($n, 'wage') => ['payroll', 'labor'],
            str_contains($n, 'repair') || str_contains($n, 'maintenance') => ['pom', 'other'],
            str_contains($n, 'electric') || str_contains($n, 'water') || str_contains($n, 'fuel') || str_contains($n, 'gas') => ['util', 'other'],
            str_contains($n, 'internet') || str_contains($n, 'software') || str_contains($n, 'telephone') => ['it', 'other'],
            str_contains($n, 'rent') || str_contains($n, 'lease') => ['rent', 'other'],
            str_contains($n, 'property tax') || str_contains($n, 'rates') => ['prop_tax', 'other'],
            str_contains($n, 'insurance') => ['insurance', 'other'],
            str_contains($n, 'interest') => ['interest', 'other'],
            str_contains($n, 'depreciation') => ['depreciation', 'other'],
            str_contains($n, 'amorti') => ['amortization', 'other'],
            str_contains($n, 'income tax') || str_contains($n, 'corporate tax') => ['income_tax', 'other'],
            str_contains($g, 'direct') => ['fnb', 'cos'],
            str_contains($g, 'non-op') => ['nonop_other', 'other'],
            default => ['ag', 'other'],
        };
    }

    public static function defaultCat(string $dept, string $type): string
    {
        if ($type === 'income') {
            return 'revenue';
        }

        return $dept === 'payroll' ? 'labor' : 'other';
    }

    /** @return array{0:string,1:string,2:bool} [department, category, automatic?] */
    public static function resolve(AccountHead $h): array
    {
        if ($h->usali_dept) {
            return [$h->usali_dept, $h->usali_cat ?: self::defaultCat($h->usali_dept, $h->type), false];
        }
        [$d, $c] = self::autoMap($h);

        return [$d, $c, true];
    }

    /** head id => [type, dept, cat, code, name] for every income / expense account */
    protected static function heads(): array
    {
        if (self::$heads !== null) {
            return self::$heads;
        }
        $o = [];
        foreach (AccountHead::whereIn('type', ['income', 'expense'])->orderBy('code')->get() as $h) {
            [$d, $c] = self::resolve($h);
            $o[$h->id] = ['type' => $h->type, 'dept' => $d, 'cat' => $c, 'code' => $h->code, 'name' => $h->name];
        }

        return self::$heads = $o;
    }

    /** the categories that make sense for an account of $type in $dept */
    public static function allowedCats(string $dept, string $type): array
    {
        if ($type === 'income') {
            return in_array($dept, ['mgmt', 'payroll', 'rent', 'prop_tax', 'insurance', 'interest', 'depreciation', 'amortization', 'income_tax'], true)
                || isset(self::UNDISTRIBUTED[$dept]) ? [] : ['revenue'];
        }
        if (isset(self::OPERATED[$dept]) || isset(self::OOD[$dept])) {
            return ['cos', 'labor', 'other'];
        }
        if (isset(self::UNDISTRIBUTED[$dept])) {
            return ['labor', 'other'];
        }
        if (in_array($dept, ['misc', 'nonop_income'], true)) {
            return [];
        }

        return $dept === 'payroll' ? ['labor'] : ['other'];
    }

    /** save account mappings: [head id => "dept:cat" | "" (automatic)] */
    public static function saveMap(array $map): int
    {
        $n = 0;
        $heads = AccountHead::whereIn('type', ['income', 'expense'])->whereIn('id', array_map('intval', array_keys($map)))->get()->keyBy('id');
        foreach ($map as $id => $v) {
            $h = $heads[(int) $id] ?? null;
            if (! $h) {
                continue;
            }
            $v = trim((string) $v);
            [$d, $c] = $v === '' ? [null, null] : array_pad(explode(':', $v, 2), 2, null);
            if ($d !== null) {
                if (! isset(self::depts()[$d])) {
                    throw new RuntimeException("Unknown USALI department for {$h->code} {$h->name}.");
                }
                $allowed = self::allowedCats($d, $h->type);
                if ($d !== 'excluded' && ! $allowed) {
                    throw new RuntimeException("{$h->code} {$h->name} is ".($h->type === 'income' ? 'an income' : 'an expense').' account – it cannot be reported under '.self::label($d).'.');
                }
                $c = $c ?: ($d === 'excluded' ? 'other' : self::defaultCat($d, $h->type));
                if ($d !== 'excluded' && ! in_array($c, $allowed, true)) {
                    throw new RuntimeException("{$h->code} {$h->name}: ".(self::CATS[$c] ?? $c).' is not a category of '.self::label($d).'.');
                }
                if ($d !== 'excluded' && [$d, $c] === self::autoMap($h)) {
                    [$d, $c] = [null, null];   // same as automatic – keep it automatic
                }
            }
            if ($h->usali_dept !== $d || $h->usali_cat !== $c) {
                $h->forceFill(['usali_dept' => $d, 'usali_cat' => $c])->save();
                $n++;
            }
        }
        self::flush();
        if ($n) {
            ActivityLogger::log('owner', 'usali_map', "USALI mapping changed on {$n} account(s)");
        }

        return $n;
    }

    public static function deptDefault(Department $d): string
    {
        $n = strtolower($d->name);

        return match (true) {
            str_contains($n, 'front') || str_contains($n, 'reception') || str_contains($n, 'housekeep') || str_contains($n, 'reservation') || str_contains($n, 'laundry') => 'rooms',
            str_contains($n, 'f&b') || str_contains($n, 'food') || str_contains($n, 'kitchen') || str_contains($n, 'restaurant') || str_contains($n, 'bar') || str_contains($n, 'banquet') => 'fnb',
            str_contains($n, 'spa') || str_contains($n, 'wellness') => 'spa',
            str_contains($n, 'club') || str_contains($n, 'gym') => 'club',
            str_contains($n, 'transport') || str_contains($n, 'driver') => 'transport',
            str_contains($n, 'maint') || str_contains($n, 'engineer') => 'pom',
            str_contains($n, 'sales') || str_contains($n, 'marketing') => 'sm',
            str_contains($n, ' it') || $n === 'it' || str_contains($n, 'information') => 'it',
            default => 'ag',
        };
    }

    public static function deptOf(?int $departmentId): string
    {
        if (self::$deptMap === null) {
            self::$deptMap = Department::all()->mapWithKeys(fn ($d) => [$d->id => $d->usali_dept ?: self::deptDefault($d)])->all();
        }

        return $departmentId ? (self::$deptMap[$departmentId] ?? 'ag') : 'ag';
    }

    /**
     * How a month's payroll splits across USALI departments: payslips of the month's approved / paid runs (gross +
     * employer cost), else any run of the month, else the active salary roll.
     *
     * @return array<string, float> dept => share (sums to 1)
     */
    public static function payrollShares(string $ym): array
    {
        if (isset(self::$shares[$ym])) {
            return self::$shares[$ym];
        }
        $from = $ym.'-01';
        $to = Carbon::parse($from)->endOfMonth()->toDateString();
        $w = [];
        $runs = PayrollRun::whereBetween('period_start', [$from, $to])->whereIn('status', ['approved', 'paid'])->pluck('id');
        if ($runs->isEmpty()) {
            $runs = PayrollRun::whereBetween('period_start', [$from, $to])->pluck('id');
        }
        if ($runs->isNotEmpty()) {
            foreach (Payslip::with('employee:id,department_id')->whereIn('payroll_run_id', $runs)->get() as $p) {
                $d = self::deptOf($p->employee?->department_id);
                $w[$d] = ($w[$d] ?? 0) + (float) $p->gross + (float) $p->employer_cost;
            }
        }
        if (array_sum($w) <= 0) {
            $w = [];
            foreach (Employee::where('is_active', true)->get(['department_id', 'salary']) as $e) {
                $d = self::deptOf($e->department_id);
                $w[$d] = ($w[$d] ?? 0) + (float) $e->salary;
            }
        }
        $t = array_sum($w);
        if ($t <= 0) {
            return self::$shares[$ym] = ['ag' => 1.0];
        }
        arsort($w);

        return self::$shares[$ym] = array_map(fn ($v) => $v / $t, $w);
    }

    // ───────────────────────── amounts ─────────────────────────

    /** posted amounts per month in natural sign (income Cr+, expense Dr+), closing entries excluded: [Y-m => [head => amt]] */
    public static function actualMonths(string $from, string $to): array
    {
        $heads = self::heads();
        $rows = GlLine::whereBetween('date', [$from, $to])->where('closing', false)->whereIn('account_head_id', array_keys($heads))
            ->selectRaw('account_head_id, YEAR(date) y, MONTH(date) mo, SUM(debit) dr, SUM(credit) cr')
            ->groupBy('account_head_id', DB::raw('YEAR(date)'), DB::raw('MONTH(date)'))->get();
        $o = [];
        foreach ($rows as $r) {
            $ym = sprintf('%04d-%02d', $r->y, $r->mo);
            $v = $heads[$r->account_head_id]['type'] === 'income' ? (float) $r->cr - (float) $r->dr : (float) $r->dr - (float) $r->cr;
            $o[$ym][$r->account_head_id] = round(($o[$ym][$r->account_head_id] ?? 0) + $v, 2);
        }

        return $o;
    }

    /** the active budget of a fiscal year per month: [Y-m => [head => amt]] for fiscal months $m1..$m2 */
    public static function budgetMonths(int $fy, int $m1, int $m2): array
    {
        $b = BudgetService::active($fy);
        if (! $b) {
            return [];
        }
        $months = BudgetService::months($fy);
        $o = [];
        foreach ($b->lines()->get() as $l) {
            for ($m = $m1; $m <= $m2; $m++) {
                $v = (float) $l->{'m'.$m};
                if ($v != 0.0) {
                    $o[$months[$m]->format('Y-m')][$l->account_head_id] = $v;
                }
            }
        }

        return $o;
    }

    /**
     * Allocate monthly amounts into USALI buckets: [dept => [cat => [head => profit contribution]]].
     * Payroll-split accounts go to the departments in proportion to that month's payroll.
     */
    public static function allocate(array $months): array
    {
        $heads = self::heads();
        $acc = [];
        foreach ($months as $ym => $amounts) {
            foreach ($amounts as $hid => $v) {
                if (! isset($heads[$hid]) || $v == 0.0) {
                    continue;
                }
                $h = $heads[$hid];
                $p = $h['type'] === 'income' ? $v : -$v;
                if ($h['dept'] === 'payroll') {
                    $shares = self::payrollShares($ym);
                    $left = $p;
                    $keys = array_keys($shares);
                    foreach ($keys as $i => $d) {
                        $part = $i === count($keys) - 1 ? $left : round($p * $shares[$d], 2);
                        $left = round($left - $part, 2);
                        $acc[$d]['labor'][$hid] = round(($acc[$d]['labor'][$hid] ?? 0) + $part, 2);
                    }

                    continue;
                }
                $acc[$h['dept']][$h['cat']][$hid] = round(($acc[$h['dept']][$h['cat']][$hid] ?? 0) + $p, 2);
            }
        }

        return $acc;
    }

    protected static function sumDept(array $acc, string $dept, ?array $cats = null): float
    {
        $t = 0.0;
        foreach ($acc[$dept] ?? [] as $cat => $heads) {
            if ($cats === null || in_array($cat, $cats, true)) {
                $t += array_sum($heads);
            }
        }

        return round($t, 2);
    }

    /**
     * Summary Operating Statement figures from an allocation. Revenue lines positive, expense lines positive (cost),
     * profit lines signed. $reserve = the replacement reserve for the operator format.
     */
    public static function summarise(array $acc, float $reserve = 0.0): array
    {
        $s = [];
        $s['rev_rooms'] = self::sumDept($acc, 'rooms', ['revenue']);
        $s['rev_fnb'] = self::sumDept($acc, 'fnb', ['revenue']);
        $s['rev_ood'] = 0.0;
        foreach (array_keys(self::OOD) as $d) {
            $s['rev_'.$d] = self::sumDept($acc, $d, ['revenue']);
            $s['rev_ood'] += $s['rev_'.$d];
        }
        $s['rev_misc'] = self::sumDept($acc, 'misc');
        $s['tor'] = round($s['rev_rooms'] + $s['rev_fnb'] + $s['rev_ood'] + $s['rev_misc'], 2);

        $s['exp_rooms'] = -self::sumDept($acc, 'rooms', ['cos', 'labor', 'other']);
        $s['exp_fnb'] = -self::sumDept($acc, 'fnb', ['cos', 'labor', 'other']);
        $s['exp_ood'] = 0.0;
        foreach (array_keys(self::OOD) as $d) {
            $s['exp_'.$d] = -self::sumDept($acc, $d, ['cos', 'labor', 'other']);
            $s['exp_ood'] += $s['exp_'.$d];
        }
        $s['tde'] = round($s['exp_rooms'] + $s['exp_fnb'] + $s['exp_ood'], 2);
        $s['tdp'] = round($s['tor'] - $s['tde'], 2);

        $s['tue'] = 0.0;
        foreach (array_keys(self::UNDISTRIBUTED) as $d) {
            $s['u_'.$d] = -self::sumDept($acc, $d);
            $s['tue'] += $s['u_'.$d];
        }
        $s['tue'] = round($s['tue'], 2);
        $s['gop'] = round($s['tdp'] - $s['tue'], 2);
        $s['mgmt'] = -self::sumDept($acc, 'mgmt');
        $s['ibnoie'] = round($s['gop'] - $s['mgmt'], 2);

        $s['n_income'] = self::sumDept($acc, 'nonop_income');
        $s['tnoie'] = -$s['n_income'];
        foreach (['rent', 'prop_tax', 'insurance', 'nonop_other'] as $d) {
            $s['n_'.$d] = -self::sumDept($acc, $d);
            $s['tnoie'] += $s['n_'.$d];
        }
        $s['tnoie'] = round($s['tnoie'], 2);
        $s['ebitda'] = round($s['ibnoie'] - $s['tnoie'], 2);
        $s['rr'] = round($reserve, 2);
        $s['ebitda_rr'] = round($s['ebitda'] - $s['rr'], 2);

        $s['tida'] = 0.0;
        foreach (['interest', 'depreciation', 'amortization'] as $d) {
            $s['b_'.$d] = -self::sumDept($acc, $d);
            $s['tida'] += $s['b_'.$d];
        }
        $s['tida'] = round($s['tida'], 2);
        $s['ibit'] = round($s['ebitda'] - $s['tida'], 2);
        $s['b_income_tax'] = -self::sumDept($acc, 'income_tax');
        $s['net'] = round($s['ibit'] - $s['b_income_tax'], 2);
        $s['excluded'] = self::sumDept($acc, 'excluded');
        $s['labor'] = 0.0;
        foreach ($acc as $d => $cats) {
            $s['labor'] += -array_sum($cats['labor'] ?? []);
        }
        $s['labor'] = round($s['labor'], 2);
        $s['fnb_cos'] = -self::sumDept($acc, 'fnb', ['cos']);
        $s['gl_net'] = round($s['net'] + $s['excluded'], 2);

        return $s;
    }

    /** the statement layout: [key, label, kind (head|line|sub|total|strong), % base, drill-down] */
    public static function layout(string $format = 'operator'): array
    {
        $rows = [
            ['h_rev', 'Operating Revenue', 'head'],
            ['rev_rooms', 'Rooms', 'line', 'tor', 'rooms'],
            ['rev_fnb', 'Food and Beverage', 'line', 'tor', 'fnb'],
            ['rev_ood', 'Other Operated Departments', 'line', 'tor', 'ood'],
            ['rev_misc', 'Miscellaneous Income', 'line', 'tor', 'misc'],
            ['tor', 'Total Operating Revenue', 'total', 'tor'],
            ['h_dep', 'Departmental Expenses', 'head'],
            ['exp_rooms', 'Rooms', 'line', 'rev_rooms', 'rooms'],
            ['exp_fnb', 'Food and Beverage', 'line', 'rev_fnb', 'fnb'],
            ['exp_ood', 'Other Operated Departments', 'line', 'rev_ood', 'ood'],
            ['tde', 'Total Departmental Expenses', 'total', 'tor'],
            ['tdp', 'Total Departmental Profit', 'strong', 'tor'],
            ['h_und', 'Undistributed Operating Expenses', 'head'],
        ];
        foreach (self::UNDISTRIBUTED as $d => $l) {
            $rows[] = ['u_'.$d, $l, 'line', 'tor', $d];
        }
        array_push($rows, ['tue', 'Total Undistributed Expenses', 'total', 'tor'], ['gop', 'Gross Operating Profit', 'strong', 'tor'],
            ['mgmt', 'Management Fees', 'line', 'tor', 'mgmt'], ['ibnoie', 'Income Before Non-Operating Income and Expenses', 'strong', 'tor'],
            ['h_non', 'Non-Operating Income and Expenses', 'head'], ['n_income', 'Income', 'line', 'tor', 'nonop'], ['n_rent', 'Rent', 'line', 'tor', 'nonop'],
            ['n_prop_tax', 'Property and Other Taxes', 'line', 'tor', 'nonop'], ['n_insurance', 'Insurance', 'line', 'tor', 'nonop'], ['n_nonop_other', 'Other', 'line', 'tor', 'nonop'],
            ['tnoie', 'Total Non-Operating Income and Expenses', 'total', 'tor'],
            ['ebitda', 'Earnings Before Interest, Taxes, Depreciation and Amortization', 'strong', 'tor']);
        if ($format === 'operator') {
            array_push($rows, ['rr', 'Replacement Reserve', 'line', 'tor'], ['ebitda_rr', 'EBITDA less Replacement Reserve', 'strong', 'tor']);
        } else {
            array_push($rows, ['h_ida', 'Interest, Depreciation and Amortization', 'head'], ['b_interest', 'Interest', 'line', 'tor', 'below'], ['b_depreciation', 'Depreciation', 'line', 'tor', 'below'],
                ['b_amortization', 'Amortization', 'line', 'tor', 'below'], ['tida', 'Total Interest, Depreciation and Amortization', 'total', 'tor'],
                ['ibit', 'Income Before Income Taxes', 'strong', 'tor'], ['b_income_tax', 'Income Taxes', 'line', 'tor', 'below'], ['net', 'Net Income', 'strong', 'tor']);
        }

        return $rows;
    }

    /** replacement reserve for months: accrued FF&E contributions (actual) */
    public static function reserveFor(array $yms): float
    {
        return round((float) OwnerFee::whereIn('period', $yms)->where('status', '!=', 'reversed')->sum('ffe_amount'), 2);
    }

    /** replacement reserve a budget implies: budgeted revenue × the agreement's FF&E % for each month */
    protected static function budgetReserve(array $budgetMonths): float
    {
        $t = 0.0;
        foreach ($budgetMonths as $ym => $amounts) {
            $ag = OwnerService::agreementFor(Carbon::parse($ym.'-01'));
            if (! $ag) {
                continue;
            }
            $rev = self::summarise(self::allocate([$ym => $amounts]))['tor'];
            $t += $rev * $ag->ffePct(Carbon::parse($ym.'-01')) / 100;
        }

        return round($t, 2);
    }

    /** a month's actual figures (the fee calculation uses this) */
    public static function month(string $ym): array
    {
        $from = $ym.'-01';
        $to = Carbon::parse($from)->endOfMonth()->toDateString();

        return self::summarise(self::allocate(self::actualMonths($from, $to)), self::reserveFor([$ym]));
    }

    /** figures for any date range (reports) */
    public static function range(string $from, string $to): array
    {
        $months = self::actualMonths($from, $to);
        $yms = [];
        for ($c = Carbon::parse($from)->startOfMonth(); $c->lte(Carbon::parse($to)); $c->addMonthNoOverflow()) {
            $yms[] = $c->format('Y-m');
        }
        $acc = self::allocate($months);

        return ['summary' => self::summarise($acc, self::reserveFor($yms)), 'acc' => $acc];
    }

    /**
     * The statement page: month and year-to-date, each actual / budget / last year, for fiscal month $m of $fy.
     *
     * @return array{cols: array<string, array>, acc: array<string, array>, months: array, fy:int, m:int}
     */
    public static function statement(int $fy, int $m): array
    {
        $months = BudgetService::months($fy);
        $lyMonths = BudgetService::months($fy - 1);
        $start = $months[$m]->format('Y-m');
        $ytdYms = array_map(fn ($c) => $c->format('Y-m'), array_slice($months, 0, $m, true));
        $lyYtdYms = array_map(fn ($c) => $c->format('Y-m'), array_slice($lyMonths, 0, $m, true));
        $cut = LedgerService::cutoff();

        $act = self::actualMonths($months[1]->toDateString(), min($months[$m]->copy()->endOfMonth()->toDateString(), $cut));
        $ly = self::actualMonths($lyMonths[1]->toDateString(), $lyMonths[$m]->copy()->endOfMonth()->toDateString());
        $bud = self::budgetMonths($fy, 1, $m);
        $pick = fn (array $src, array $yms) => array_intersect_key($src, array_flip($yms));

        $sets = [
            'm_act' => [$pick($act, [$start]), self::reserveFor([$start])],
            'm_bud' => [$pick($bud, [$start]), null],
            'm_ly' => [$pick($ly, [$lyMonths[$m]->format('Y-m')]), self::reserveFor([$lyMonths[$m]->format('Y-m')])],
            'y_act' => [$pick($act, $ytdYms), self::reserveFor($ytdYms)],
            'y_bud' => [$pick($bud, $ytdYms), null],
            'y_ly' => [$pick($ly, $lyYtdYms), self::reserveFor($lyYtdYms)],
        ];
        $cols = [];
        $accs = [];
        foreach ($sets as $k => [$src, $rr]) {
            $accs[$k] = self::allocate($src);
            $cols[$k] = self::summarise($accs[$k], $rr ?? self::budgetReserve($src));
        }

        return ['cols' => $cols, 'acc' => $accs, 'fy' => $fy, 'm' => $m, 'month' => $months[$m], 'mtd' => $months[$m]->copy()->endOfMonth()->toDateString() > $cut,
            'has_budget' => (bool) $bud, 'has_ly' => (bool) $ly];
    }

    /** departmental schedule rows for a drill-down code (a department, 'ood', 'nonop' or 'below') */
    public static function schedule(string $code, array $acc): array
    {
        $heads = self::heads();
        $depts = match ($code) {
            'ood' => array_keys(self::OOD),
            'nonop' => array_keys(self::NONOP),
            'below' => array_keys(self::BELOW),
            default => [$code],
        };
        $out = [];
        foreach ($depts as $d) {
            $sec = ['dept' => $d, 'label' => self::label($d), 'cats' => [], 'revenue' => 0.0, 'expense' => 0.0];
            foreach (self::CATS as $cat => $cl) {
                $lines = [];
                foreach ($acc[$d][$cat] ?? [] as $hid => $p) {
                    if ($p == 0.0) {
                        continue;
                    }
                    $h = $heads[$hid] ?? ['code' => '', 'name' => '#'.$hid, 'dept' => $d];
                    $lines[] = ['head_id' => $hid, 'code' => $h['code'], 'name' => $h['name'], 'split' => ($h['dept'] ?? '') === 'payroll', 'amount' => $cat === 'revenue' || $d === 'nonop_income' || $d === 'misc' ? $p : -$p];
                }
                if ($lines) {
                    usort($lines, fn ($a, $b) => strcmp((string) $a['code'], (string) $b['code']));
                    $t = round(array_sum(array_column($lines, 'amount')), 2);
                    $sec['cats'][$cat] = ['label' => $cl, 'lines' => $lines, 'total' => $t];
                    if ($cat === 'revenue' || $d === 'nonop_income' || $d === 'misc') {
                        $sec['revenue'] += $t;
                    } else {
                        $sec['expense'] += $t;
                    }
                }
            }
            $sec['revenue'] = round($sec['revenue'], 2);
            $sec['expense'] = round($sec['expense'], 2);
            $sec['profit'] = round($sec['revenue'] - $sec['expense'], 2);
            $out[] = $sec;
        }

        return $out;
    }

    public static function scheduleTitle(string $code): string
    {
        return match ($code) {
            'ood' => 'Other Operated Departments',
            'nonop' => 'Non-Operating Income and Expenses',
            'below' => 'Interest, Depreciation, Amortization and Income Taxes',
            default => self::label($code),
        };
    }

    // ───────────────────────── KPIs ─────────────────────────

    /** rooms statistics of whole calendar months [ym, …] from the booking data: available, sold */
    public static function roomStats(array $yms): array
    {
        $by = [];
        foreach ($yms as $ym) {
            $fy = BudgetService::fyFor($ym.'-01');
            $by[$fy][] = BudgetService::monthIndex($ym.'-01');
        }
        $available = 0;
        $sold = 0;
        foreach ($by as $fy => $ms) {
            $st = BudgetService::actualStats($fy);
            foreach ($ms as $m) {
                $available += $st[$m]['available'] ?? 0;
                $sold += $st[$m]['sold'] ?? 0;
            }
        }

        return ['available' => $available, 'sold' => $sold];
    }

    /** budgeted rooms statistics for fiscal months $m1..$m2 */
    public static function budgetStats(int $fy, int $m1, int $m2): array
    {
        $b = BudgetService::active($fy);
        $available = 0;
        $sold = 0.0;
        if ($b) {
            foreach ($b->stats()->whereBetween('month', [$m1, $m2])->get() as $s) { // budget_stats.month is an INTEGER fiscal-month number
                $available += (int) $s->rooms_available;
                $sold += (int) $s->rooms_available * (float) $s->occupancy / 100;
            }
        }

        return ['available' => $available, 'sold' => (int) round($sold)];
    }

    public static function kpis(array $s, array $st): array
    {
        $a = max(0, (int) $st['available']);
        $n = max(0, (int) $st['sold']);
        $div = fn ($x, $y) => $y ? round($x / $y, 2) : null;
        $pct = fn ($x, $y) => $y ? round($x * 100 / $y, 1) : null;

        return [
            'available' => $a, 'sold' => $n, 'occupancy' => $pct($n, $a), 'adr' => $div($s['rev_rooms'], $n), 'revpar' => $div($s['rev_rooms'], $a),
            'trevpar' => $div($s['tor'], $a), 'goppar' => $div($s['gop'], $a), 'cpor' => $div($s['exp_rooms'], $n),
            'gop_margin' => $pct($s['gop'], $s['tor']), 'ebitda_margin' => $pct($s['ebitda'], $s['tor']), 'labor_pct' => $pct($s['labor'], $s['tor']),
            'fnb_cost_pct' => $pct($s['fnb_cos'], $s['rev_fnb']), 'rooms_margin' => $pct($s['rev_rooms'] - $s['exp_rooms'], $s['rev_rooms']),
        ];
    }

    public const KPI_LABELS = [
        'occupancy' => ['Occupancy', '%'], 'adr' => ['ADR', 'money'], 'revpar' => ['RevPAR', 'money'], 'trevpar' => ['TRevPAR', 'money'], 'goppar' => ['GOPPAR', 'money'],
        'cpor' => ['Cost per occupied room (CPOR)', 'money'], 'gop_margin' => ['GOP margin', '%'], 'ebitda_margin' => ['EBITDA margin', '%'], 'labor_pct' => ['Labour cost % of revenue', '%'],
        'fnb_cost_pct' => ['F&B cost of sales %', '%'], 'rooms_margin' => ['Rooms departmental margin', '%'], 'sold' => ['Rooms sold', 'int'], 'available' => ['Rooms available', 'int'],
    ];

    /** flow-through: share of the revenue change that reached GOP (null when revenue did not move) */
    public static function flowThrough(array $a, array $b): ?float
    {
        $dr = $a['tor'] - $b['tor'];

        return abs($dr) < 0.01 ? null : round(($a['gop'] - $b['gop']) * 100 / $dr, 1);
    }

    /** 12 fiscal months of revenue, GOP and EBITDA (the trend chart) */
    public static function trend(int $fy): array
    {
        $months = BudgetService::months($fy);
        $act = self::actualMonths($months[1]->toDateString(), min($months[12]->copy()->endOfMonth()->toDateString(), LedgerService::cutoff()));
        $bud = self::budgetMonths($fy, 1, 12);
        $o = [];
        foreach ($months as $m => $c) {
            $ym = $c->format('Y-m');
            $s = isset($act[$ym]) ? self::summarise(self::allocate([$ym => $act[$ym]])) : null;
            $b = isset($bud[$ym]) ? self::summarise(self::allocate([$ym => $bud[$ym]])) : null;
            $o[$m] = ['label' => $c->format('M'), 'ym' => $ym, 'tor' => $s['tor'] ?? null, 'gop' => $s['gop'] ?? null, 'ebitda' => $s['ebitda'] ?? null, 'bud_gop' => $b['gop'] ?? null, 'bud_tor' => $b['tor'] ?? null];
        }

        return $o;
    }

    /** the latest month with ledger data up to the cut-off → [fy, fiscal month] */
    public static function defaultPeriod(): array
    {
        $cut = Carbon::parse(LedgerService::cutoff());

        return [BudgetService::fyFor($cut), BudgetService::monthIndex($cut)];
    }
}
