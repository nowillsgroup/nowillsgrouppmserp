<?php

namespace App\Services;

use App\Models\AccountHead;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\PayComponent;
use App\Models\PaymentMode;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PayslipLine;
use App\Models\StaffAdvance;
use App\Support\Numbering;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Monthly payroll.
 *
 *  • Attendance → paid days: P, WO and PH are paid, A is loss of pay (LOP), L is paid unless the approved leave type is unpaid,
 *    H is half present (the other half is paid leave when covered by an approved half-day leave, otherwise LOP).
 *    Unmarked days count as present by default (setting payroll_unmarked); an unmarked gazetted public holiday counts as PH.
 *    Days before joining / after leaving are not employed.
 *  • Overtime = hours beyond the shift (payroll_shift_hours) on present days, paid at basic ÷ days ÷ shift × payroll_ot_multiplier.
 *    Hours worked on a public holiday are paid separately at payroll_holiday_multiplier (Act 651 s. 77: the holiday is
 *    already a paid day, so the hours earn the premium part of the rate – 2× by default).
 *  • Earnings: BASIC (pro-rata), components (fixed / % of basic), OT, OTH (holiday work), manual lines. Deductions & employer
 *    contributions: fixed, % of basic (with a base cap), % of gross (while gross ≤ ceiling), slab on gross, income tax, the
 *    concessionary overtime tax, and salary-advance instalments.
 *  • Ghana (v4.3, tax rules v7.43): SSNIT 5.5% employee / 8% employer (Tier 1) + 5% employer (Tier 2) on basic up to the
 *    insurable cap; PAYE on the GRA monthly bands in force in the payroll month (Act 1178 from 1 Sep 2026) over chargeable
 *    income = taxable earnings − SSNIT 5.5% − the employee's personal reliefs, with the three special GRA treatments:
 *    a non-resident at a flat 25%, a casual worker at a final 5% of gross, and a junior employee's overtime (qualifying
 *    income for the year ≤ GH¢ 18,000) at a final 5% up to half the month's basic and 10% on the excess.
 *  • Draft → approved (accrued in the ledger at the period end) → paid (salaries payable cleared by payment mode).
 *    Attendance and leave inside an approved period are locked until the run is reopened.
 */
class PayrollService
{
    /** SSNIT maximum insurable earnings per month (GH¢ 69,000 from 1 Jan 2026 – SSNIT / NPRA notice of 14 Jan 2026). */
    public const SSNIT_CAP = 69000;

    /**
     * GRA PAYE for resident individuals – monthly cumulative upper limits in GH¢ and the rate %, newest schedule first.
     * The bands are effective-dated on purpose: a payroll month is taxed with the schedule that was in force in that
     * month, so reopening an old run recomputes it as it should have been and the current month always follows the law
     * as it now stands.
     *   2026-09-01  Income Tax (Amendment) Act, 2026 (Act 1178) – first 588 @ 0 · next 80 @ 5 · next 100 @ 10 ·
     *               next 2,900 @ 17.5 · next 16,000 @ 25 · next 30,332 @ 30 · above 50,000 @ 35.
     *   2024-01-01  the previous GRA table – first 490 @ 0 · next 110 @ 5 · next 130 @ 10 · next 3,166.67 @ 17.5 ·
     *               next 16,000 @ 25 · next 30,520 @ 30 · above 50,416.67 @ 35.
     */
    public const PAYE_SCHEDULES = [
        ['from' => '2026-09-01', 'name' => 'Act 1178, from 1 Sep 2026',
            'bands' => [[588, 0], [668, 5], [768, 10], [3668, 17.5], [19668, 25], [50000, 30], [null, 35]]],
        ['from' => '2024-01-01', 'name' => 'GRA bands from 1 Jan 2024',
            'bands' => [[490, 0], [600, 5], [730, 10], [3896.67, 17.5], [19896.67, 25], [50416.67, 30], [null, 35]]],
    ];

    /** The statutory bands in force today – what "reset to the GRA table" restores. */
    public const PAYE_BANDS = [[588, 0], [668, 5], [768, 10], [3668, 17.5], [19668, 25], [50000, 30], [null, 35]];

    /** The other PAYE rules GRA publishes (settings can move them; these are the statutory figures). */
    public const OT_JUNIOR_CAP = 18000;        // qualifying employment income for the year that keeps the overtime concession
    public const OT_JUNIOR_RATIO = 0.5;        // overtime up to 50% of the month's basic …
    public const OT_JUNIOR_RATE = 5;           // … is taxed at a final 5% …
    public const OT_JUNIOR_EXCESS_RATE = 10;   // … and the excess over 50% at 10%
    public const CASUAL_RATE = 5;              // a casual worker's gross pay: 5% final tax (Act 651 s. 78 / GRA practice note 4.12)
    public const NON_RESIDENT_RATE = 25;       // a non-resident's employment income: a flat 25% (Act 896)
    public const BONUS_CAP_PCT = 15;           // a bonus up to 15% of the year's basic salary …
    public const BONUS_RATE = 5;               // … carries a final 5% tax for a resident (Act 896 s. 114, GRA practice note 4.8) …
    public const BONUS_NON_RESIDENT_RATE = 20; // … while a non-resident's bonus and overtime carry a flat 20%; the excess over the cap is taxed on the bands

    // ───────────────────────── defaults ─────────────────────────

    /** Default pay structure & leave types for a property (idempotent; existing codes are never touched). */
    public static function ensureDefaults(): void
    {
        ChartOfAccounts::ensureLight();
        $sys = fn ($k) => AccountHead::sys($k);
        $defs = [
            ['BASIC', 'Basic salary', 'earning', 'system', 0, ['sort_order' => 0, 'expense_head_id' => $sys('salaries')]],
            ['HOUSING', 'Housing allowance', 'earning', 'percent_basic', 15, ['sort_order' => 10]],
            ['TRANSPORT', 'Transport allowance', 'earning', 'fixed', 300, ['sort_order' => 20]],
            ['OT', 'Overtime', 'earning', 'system', 0, ['sort_order' => 90, 'expense_head_id' => $sys('salaries')]],
            ['OTH', 'Public holiday work', 'earning', 'system', 0, ['sort_order' => 91, 'expense_head_id' => $sys('salaries')]],
            ['BONUS', 'Bonus / ex-gratia payment', 'earning', 'system', 0, ['sort_order' => 92, 'expense_head_id' => $sys('salaries')]],
            // Ghana (National Pensions Act 2008, Act 766): 18.5% of basic up to the maximum insurable earnings –
            // Tier 1 (SSNIT) 13.5% = employee 5.5% + employer 8%; Tier 2 (occupational trustee) 5% employer.
            ['SSNIT', 'SSNIT (employee 5.5%)', 'deduction', 'percent_basic', 5.5, ['sort_order' => 10, 'cap_base' => self::SSNIT_CAP, 'tax_relief' => true, 'liability_head_id' => $sys('pf_payable')]],
            ['PAYE', 'PAYE income tax', 'deduction', 'tax', 0, ['sort_order' => 40, 'liability_head_id' => $sys('paye_payable')]],
            ['OT_TAX', 'Overtime tax (junior staff – final 5% / 10%)', 'deduction', 'system', 0, ['sort_order' => 45, 'liability_head_id' => $sys('paye_payable')]],
            ['BONUS_TAX', 'Tax on bonus (final 5% / non-resident 20%)', 'deduction', 'system', 0, ['sort_order' => 46, 'liability_head_id' => $sys('paye_payable')]],
            ['ADV', 'Salary advance recovery', 'deduction', 'system', 0, ['sort_order' => 90, 'liability_head_id' => $sys('staff_advances')]],
            ['SSNIT_ER', 'SSNIT Tier 1 (employer 8%)', 'employer', 'percent_basic', 8, ['sort_order' => 10, 'cap_base' => self::SSNIT_CAP, 'expense_head_id' => $sys('employer_contrib'), 'liability_head_id' => $sys('pf_payable')]],
            ['TIER2_ER', 'Tier 2 pension (employer 5%)', 'employer', 'percent_basic', 5, ['sort_order' => 20, 'cap_base' => self::SSNIT_CAP, 'expense_head_id' => $sys('employer_contrib'), 'liability_head_id' => $sys('esi_payable')]],
        ];
        foreach ($defs as [$code, $name, $kind, $calc, $value, $x]) {
            if (! PayComponent::where('code', $code)->exists()) {
                PayComponent::create(['code' => $code, 'name' => $name, 'kind' => $kind, 'calc' => $calc, 'value' => $value, 'is_default' => true, 'taxable' => true] + $x);
            }
        }
        // Leave types. Act 651 s. 20 sets the floor at 15 working days of annual leave with full pay and s. 57 gives a
        // mother 12 weeks (84 days) of maternity leave on full remuneration, plus two more weeks for an abnormal
        // confinement or multiple births – so ML is created even on an existing installation that has none.
        // PL is the counterpart the Act does not provide: Ghana gives a father no statutory paternity leave, so what a
        // hotel offers is contractual (its own policy or a collective agreement). The type is seeded with a common
        // 5 working days on full pay and the hotel sets the real number in Masters › Leave Types; the engine needs no
        // special case for it, because paid leave of any type is paid leave in payroll.
        $fresh = ! \App\Models\LeaveType::exists();
        foreach ([['CL', 'Casual leave', 12, true, 0], ['SL', 'Sick leave', 12, true, 0], ['EL', 'Earned / annual leave', 18, true, 30],
            ['ML', 'Maternity leave (12 weeks, s. 57)', 84, true, 0],
            ['PL', 'Paternity leave (contractual – Act 651 provides none)', 5, true, 0], ['LWP', 'Leave without pay', 0, false, 0]] as [$c, $n, $d, $paid, $cf]) {
            if (($fresh || in_array($c, ['ML', 'PL'], true)) && ! \App\Models\LeaveType::where('code', $c)->exists()) {
                \App\Models\LeaveType::create(['code' => $c, 'name' => $n, 'days_per_year' => $d, 'is_paid' => $paid, 'carry_forward_max' => $cf]);
            }
        }
    }

    // ───────────────────────── attendance & tax ─────────────────────────

    public static function attendanceSummary(Employee $e, Carbon $start, Carbon $end): array
    {
        $from = $start->copy()->startOfDay();
        $to = $end->copy()->startOfDay();
        if ($e->joining_date && $e->joining_date->gt($from)) {
            $from = $e->joining_date->copy()->startOfDay();
        }
        if ($e->leaving_date && $e->leaving_date->lt($to)) {
            $to = $e->leaving_date->copy()->startOfDay();
        }
        $out = ['employed' => 0.0, 'present' => 0.0, 'week_off' => 0.0, 'paid_leave' => 0.0, 'lop' => 0.0, 'holiday' => 0.0, 'ot_hours' => 0.0, 'holiday_hours' => 0.0, 'paid' => 0.0];
        if ($from->gt($to)) {
            return $out;
        }
        $att = Attendance::with('leaveRequest.leaveType')->where('employee_id', $e->id)
            ->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())->get()->keyBy(fn ($a) => $a->date->toDateString());
        $holidays = HolidayService::inRange($from, $to);          // Act 651 ss. 22, 72: a public holiday is paid and is not leave
        $unmarkedPresent = setting('payroll_unmarked', 'present') !== 'absent';
        $shift = max(1.0, (float) setting('payroll_shift_hours', 9));
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $out['employed']++;
            $a = $att[$d->toDateString()] ?? null;
            $isHoliday = isset($holidays[$d->toDateString()]);
            $status = $a?->status ?? ($isHoliday ? 'PH' : ($unmarkedPresent ? 'P' : 'A'));
            $leavePaid = $a?->leave_request_id ? (bool) ($a->leaveRequest?->leaveType?->is_paid ?? true) : true; // a manually marked L is paid
            switch ($status) {
                case 'P': $out['present']++;
                    break;
                case 'WO': $out['week_off']++;
                    break;
                case 'PH': $out['holiday']++;
                    break;
                case 'L': $leavePaid ? $out['paid_leave']++ : $out['lop']++;
                    break;
                case 'H':
                    $out['present'] += .5;
                    $a?->leave_request_id && $leavePaid ? $out['paid_leave'] += .5 : $out['lop'] += .5;
                    break;
                default: $out['lop']++; // A
            }
            if ($a && $a->in_time && $a->out_time && in_array($status, ['P', 'H', 'PH'], true)) {
                $in = Carbon::parse($a->in_time);
                $outT = Carbon::parse($a->out_time);
                if ($outT->lte($in)) {
                    $outT->addDay(); // night shift
                }
                $worked = $in->diffInMinutes($outT) / 60;
                if ($status === 'PH') {
                    // s. 77: the holiday itself is already paid in the salary, so every hour worked on it is holiday work
                    $out['holiday_hours'] += floor($worked * 4) / 4;
                } else {
                    $extra = $worked - ($status === 'H' ? $shift / 2 : $shift);
                    if ($extra >= .5) {
                        $out['ot_hours'] += floor($extra * 4) / 4; // quarter hours
                    }
                }
            }
        }
        $out['paid'] = $out['employed'] - $out['lop'];

        return $out;
    }

    /**
     * The statutory band schedule in force on a date (defaults to today). An explicit override in
     * Payroll settings (payroll_paye_bands) replaces every schedule – clear it to go back to the
     * effective-dated GRA tables.
     */
    public static function scheduleFor(Carbon|string|null $period = null): array
    {
        $on = $period instanceof Carbon ? $period->copy() : ($period ? Carbon::parse($period) : bdate());
        foreach (self::PAYE_SCHEDULES as $s) {
            if ($on->toDateString() >= $s['from']) {
                return $s;
            }
        }

        return self::PAYE_SCHEDULES[array_key_last(self::PAYE_SCHEDULES)];
    }

    /** Monthly PAYE bands for a payroll month (setting payroll_paye_bands overrides the statutory schedule). */
    public static function taxSlabs(Carbon|string|null $period = null): array
    {
        $slabs = json_decode((string) setting('payroll_paye_bands', ''), true);

        return is_array($slabs) && $slabs ? $slabs : self::scheduleFor($period)['bands'];
    }

    /**
     * GRA PAYE on one month's chargeable income (taxable pay less SSNIT 5.5% and the employee's personal reliefs):
     * graduated monthly bands, no standard deduction / rebate / cess. Rounded to the pesewa.
     * $period is the payroll month, so the bands in force that month are the ones applied.
     */
    public static function monthlyPaye(float $chargeable, Carbon|string|null $period = null): float
    {
        $income = max(0, $chargeable);
        $tax = 0.0;
        $lower = 0.0;
        foreach (self::taxSlabs($period) as [$upto, $rate]) {
            $top = $upto === null ? $income : min($income, (float) $upto);
            if ($top > $lower) {
                $tax += ($top - $lower) * (float) $rate / 100;
            }
            if ($upto === null || $income <= (float) $upto) {
                break;
            }
            $lower = (float) $upto;
        }

        return round($tax, 2);
    }

    /** Annual PAYE for an annual chargeable income (12 × the monthly bands) – used by projections and the annual return. */
    public static function annualTax(float $annualChargeable, Carbon|string|null $period = null): float
    {
        return round(self::monthlyPaye($annualChargeable / 12, $period) * 12, 2);
    }

    /** The employee's monthly personal reliefs (GRA reliefs are annual; the employee record carries the monthly figure). */
    public static function personalRelief(Employee $e): float
    {
        return max(0, round((float) ($e->tax_relief ?? 0), 2));
    }

    public static function isCasual(Employee $e): bool
    {
        return ($e->employment_type ?? 'permanent') === 'casual';
    }

    public static function isResident(Employee $e): bool
    {
        return (bool) ($e->tax_resident ?? true);
    }

    /**
     * The income tax of one payslip, following GRA's PAYE rules rather than one formula for everybody:
     *   • a non-resident's employment income is taxed at a flat 25%;
     *   • a casual worker's gross pay carries a final 5% (no bands, no reliefs);
     *   • a resident junior employee – qualifying employment income for the year not over GH¢ 18,000 – is taxed on the
     *     bands without the overtime, and the overtime itself at a final 5% up to half the month's basic and 10% above;
     *   • anyone else is taxed on the bands over chargeable income = taxable pay − SSNIT 5.5% − personal reliefs,
     *     overtime included.
     *
     *   • a bonus (ex-gratia payment) is taxed on its own: a resident pays a final 5% on the part up to 15% of the
     *     year's basic salary and the excess goes on the bands, a non-resident pays a flat 20% on the whole of it, and a
     *     casual worker's bonus is simply part of the gross that carries the final 5%;
     *
     * @return array{paye:float, ot_tax:float, chargeable:float, basis:string, schedule:string, junior:bool,
     *                bonus_tax:float, bonus_final:float, bonus_excess:float, bonus_cap:float}
     */
    public static function incomeTax(Employee $e, Carbon $period, float $taxable, float $ssnitRelief, float $ot, float $basic, float $bonus = 0.0): array
    {
        $sched = self::scheduleFor($period);
        $personal = self::personalRelief($e);
        $ot = max(0, round($ot, 2));
        $bonus = max(0, round($bonus, 2));
        $pct = fn (float $v) => rtrim(rtrim(number_format($v, 2), '0'), '.');

        if (! self::isResident($e)) {
            $chargeable = max(0, round($taxable - $ssnitRelief, 2));
            // A non-resident's bonus is taxed at a flat 20% – not on the resident bands, and not at the 25% that applies
            // to the rest of a non-resident's employment income.
            $rate = (float) setting('payroll_bonus_nonresident_rate', self::BONUS_NON_RESIDENT_RATE);

            return self::taxResult($chargeable * self::NON_RESIDENT_RATE / 100, 0.0, $chargeable,
                'Non-resident – flat '.self::NON_RESIDENT_RATE.'%', $sched['name'], false,
                $bonus, 0.0, $bonus * $rate / 100, $bonus, $bonus > 0.004 ? ' · bonus '.$pct($rate).'% final (non-resident)' : '');
        }
        if (self::isCasual($e)) {
            // A casual worker's whole gross pay carries the final 5% (Act 651 s. 78) and a bonus is part of that gross,
            // so there is no separate bonus treatment to apply – it is simply taxed inside the 5%.
            $gross = max(0, round($taxable + $bonus, 2));

            return self::taxResult($gross * self::CASUAL_RATE / 100, 0.0, $gross,
                'Casual worker – '.self::CASUAL_RATE.'% final tax on gross pay', $sched['name'], false,
                $bonus, 0.0, 0.0, 0.0, $bonus > 0.004 ? ' · bonus taxed inside the '.$pct(self::CASUAL_RATE).'% final' : '');
        }
        // A resident's bonus: a final 5% on the part up to 15% of the year's basic salary, and the excess over that cap
        // taxed on the bands with the rest of the month's income (Act 896 s. 114, GRA practice note on ex-gratia payments).
        $bonusPct = (float) setting('payroll_bonus_cap_pct', self::BONUS_CAP_PCT);
        $bonusCap = round($basic * 12 * $bonusPct / 100, 2);
        $bonusFinal = $bonusCap > 0 ? round(min($bonus, $bonusCap), 2) : 0.0;
        $bonusExcess = round(max(0, $bonus - $bonusFinal), 2);
        $bonusRate = (float) setting('payroll_bonus_rate', self::BONUS_RATE);
        $bonusTax = round($bonusFinal * $bonusRate / 100, 2);
        $bonusNote = $bonus > 0.004
            ? ' · bonus '.money($bonusFinal, false).' at a final '.$pct($bonusRate).'%'
                .($bonusExcess > 0.004 ? ', '.money($bonusExcess, false).' over the '.$pct($bonusPct).'% of annual basic cap taxed on the bands' : ' (within the '.$pct($bonusPct).'% of annual basic cap)')
            : '';

        $cap = (float) setting('payroll_ot_junior_cap', self::OT_JUNIOR_CAP);
        // GRA tests the concession on the year's qualifying employment income, overtime and bonus included: the regular
        // emolument annualised, plus the overtime and the bonus of the month in question.
        $qualifying = round(($taxable - $ot) * 12 + $ot + $bonus, 2);
        if ($ot > 0.004 && $cap > 0 && $qualifying <= $cap) {
            $ratio = (float) setting('payroll_ot_junior_ratio', self::OT_JUNIOR_RATIO);
            $free = round($basic * $ratio, 2);                       // the part of the month's basic the concession covers
            $at5 = round(min($ot, $free), 2);
            $at10 = round(max(0, $ot - $free), 2);
            $otTax = round($at5 * (float) setting('payroll_ot_junior_rate', self::OT_JUNIOR_RATE) / 100
                + $at10 * (float) setting('payroll_ot_junior_excess_rate', self::OT_JUNIOR_EXCESS_RATE) / 100, 2);
            $chargeable = max(0, round($taxable - $ot + $bonusExcess - $ssnitRelief - $personal, 2));

            return self::taxResult(self::monthlyPaye($chargeable, $period), $otTax, $chargeable,
                'Junior employee – overtime taxed at a final 5% / 10%, bands on the rest', $sched['name'], true,
                $bonusFinal, $bonusExcess, $bonusTax, $bonusCap, $bonusNote);
        }
        $chargeable = max(0, round($taxable + $bonusExcess - $ssnitRelief - $personal, 2));

        return self::taxResult(self::monthlyPaye($chargeable, $period), 0.0, $chargeable,
            'Resident – '.$sched['name'].($personal > 0 ? ' less personal reliefs '.money($personal) : ''), $sched['name'], false,
            $bonusFinal, $bonusExcess, $bonusTax, $bonusCap, $bonusNote);
    }

    /** One shape for every incomeTax() branch, so the bonus fields are never missing from the payslip context. */
    protected static function taxResult(float $paye, float $otTax, float $chargeable, string $basis, string $schedule, bool $junior,
        float $bonusFinal = 0.0, float $bonusExcess = 0.0, float $bonusTax = 0.0, float $bonusCap = 0.0, string $bonusNote = ''): array
    {
        return ['paye' => round($paye, 2), 'ot_tax' => round($otTax, 2), 'chargeable' => round($chargeable, 2),
            'basis' => $basis.$bonusNote, 'schedule' => $schedule, 'junior' => $junior,
            'bonus_tax' => round($bonusTax, 2), 'bonus_final' => round($bonusFinal, 2),
            'bonus_excess' => round($bonusExcess, 2), 'bonus_cap' => round($bonusCap, 2)];
    }

    // ───────────────────────── payslip computation ─────────────────────────

    /** Components that apply to the employee, with the effective value. [[PayComponent, value], …] */
    public static function componentsFor(Employee $e): Collection
    {
        $assigned = $e->payComponents()->get()->keyBy('pay_component_id');

        return PayComponent::where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()
            ->filter(function ($c) use ($assigned) {
                $a = $assigned[$c->id] ?? null;

                return ! $c->isSystem() && ! ($a?->is_excluded) && ($c->is_default || $a);
            })
            ->map(fn ($c) => [$c, ($assigned[$c->id] ?? null)?->value !== null ? (float) $assigned[$c->id]->value : (float) $c->value])->values();
    }

    /**
     * Compute one payslip. $manual = the slip's manual lines (kept on recalculation).
     * Returns ['stats' => attendance summary, 'lines' => [line arrays], 'gross', 'deductions', 'employer', 'net'].
     */
    public static function compute(Employee $e, PayrollRun $run, Collection $manual): array
    {
        $dim = $run->period_start->daysInMonth;
        $s = self::attendanceSummary($e, $run->period_start, $run->period_end);
        $factor = $dim ? $s['paid'] / $dim : 0;
        $basicFull = (float) $e->salary;
        $sys = PayComponent::whereIn('code', PayComponent::SYSTEM)->get()->keyBy('code');
        $salaries = AccountHead::sys('salaries');
        $lines = [];
        $line = fn (string $code, string $name, string $kind, float $amount, array $x = []) => $x + [
            'code' => $code, 'name' => $name, 'kind' => $kind, 'amount' => round($amount, 2), 'taxable' => true, 'is_manual' => false,
            'pay_component_id' => null, 'staff_advance_id' => null, 'expense_head_id' => null, 'liability_head_id' => null, 'sort_order' => 100,
        ];

        // earnings
        $basic = round($basicFull * $factor, 2);
        $b = $sys['BASIC'] ?? null;
        $lines[] = $line('BASIC', $b?->name ?? 'Basic salary', 'earning', $basic, ['pay_component_id' => $b?->id, 'expense_head_id' => $b?->expense_head_id ?? $salaries, 'sort_order' => 0]);
        $comps = self::componentsFor($e);
        foreach ($comps as [$c, $v]) {
            if ($c->kind !== 'earning') {
                continue;
            }
            $amt = match ($c->calc) {
                'fixed' => $v * ($c->prorate ? $factor : 1),
                'percent_basic' => min($basic, $c->cap_base !== null ? (float) $c->cap_base : INF) * $v / 100,
                default => 0,
            };
            if ($amt > 0.004) {
                $lines[] = $line($c->code, $c->name, 'earning', $amt, ['pay_component_id' => $c->id, 'taxable' => $c->taxable, 'expense_head_id' => $c->expense_head_id ?? $salaries, 'sort_order' => $c->sort_order]);
            }
        }
        $hourly = $basicFull > 0 ? $basicFull / $dim / max(1, (float) setting('payroll_shift_hours', 9)) : 0.0;
        if ($e->ot_eligible && $s['ot_hours'] > 0 && $basicFull > 0) {
            $rate = $hourly * (float) setting('payroll_ot_multiplier', 1.5);
            $o = $sys['OT'] ?? null;
            $lines[] = $line('OT', ($o?->name ?? 'Overtime').' ('.rtrim(rtrim(number_format($s['ot_hours'], 2), '0'), '.').' h)', 'earning', $s['ot_hours'] * $rate,
                ['pay_component_id' => $o?->id, 'expense_head_id' => $o?->expense_head_id ?? $salaries, 'sort_order' => 90]);
        }
        // Work on a gazetted public holiday (Act 651 s. 77): the day itself is already paid in the salary, so the hours
        // worked earn the premium part of the holiday rate – at the default 2× that is a further day's pay for the hours.
        // Not gated on ot_eligible: a salaried manager who is asked to work on Kwame Nkrumah Memorial Day has still worked
        // it, and an unpaid worked hour is the one thing a Labour Inspector would go straight to. A hotel that gives time
        // off in lieu instead sets payroll_holiday_multiplier to 1, which pays the day but no premium.
        if ($s['holiday_hours'] > 0 && $basicFull > 0) {
            $mult = max(1, (float) setting('payroll_holiday_multiplier', HolidayService::DEFAULTS['payroll_holiday_multiplier']));
            $o = $sys['OTH'] ?? $sys['OT'] ?? null;
            $lines[] = $line('OTH', 'Public holiday work ('.rtrim(rtrim(number_format($s['holiday_hours'], 2), '0'), '.').' h at '.rtrim(rtrim(number_format($mult, 2), '0'), '.').'×)',
                'earning', $s['holiday_hours'] * $hourly * ($mult - 1),
                ['pay_component_id' => $o?->id, 'expense_head_id' => $o?->expense_head_id ?? $salaries, 'sort_order' => 91]);
        }
        foreach ($manual->where('kind', 'earning') as $m) {
            $lines[] = self::manualArray($m);
        }
        $gross = round(array_sum(array_map(fn ($l) => $l['amount'], array_filter($lines, fn ($l) => $l['kind'] === 'earning'))), 2);
        // A bonus is an earning for the payslip but not part of the banded income: GRA taxes it on its own terms (a final
        // 5% up to 15% of the year's basic for a resident, a flat 20% for a non-resident), so incomeTax() takes it apart.
        $bonusPaid = round(array_sum(array_map(fn ($l) => $l['amount'], array_filter($lines, fn ($l) => $l['kind'] === 'earning' && $l['code'] === 'BONUS'))), 2);
        $taxable = array_sum(array_map(fn ($l) => $l['amount'], array_filter($lines, fn ($l) => $l['kind'] === 'earning' && $l['taxable'] && $l['code'] !== 'BONUS')));
        $otPaid = ($s['ot_hours'] > 0 || $s['holiday_hours'] > 0)
            ? round(array_sum(array_map(fn ($l) => $l['amount'], array_filter($lines, fn ($l) => in_array($l['code'], ['OT', 'OTH'], true)))), 2)
            : 0.0;

        // deductions & employer contributions (sorted: SSNIT before PAYE, so the relief is known when PAYE is computed)
        $relief = 0.0;
        $taxCtx = null;
        $comps = $comps->sortBy(fn ($cv) => [$cv[0]->calc === 'tax' ? 1 : 0, $cv[0]->sort_order, $cv[0]->id])->values();
        foreach ($comps as [$c, $v]) {
            if ($c->kind === 'earning') {
                continue;
            }
            $amt = match ($c->calc) {
                'fixed' => $v * ($c->prorate ? $factor : 1),
                'percent_basic' => min($basic, $c->cap_base !== null ? (float) $c->cap_base * $factor : INF) * $v / 100,
                'percent_gross' => ($c->gross_ceiling !== null && $gross > (float) $c->gross_ceiling) ? 0 : $gross * $v / 100,
                'slab' => self::slabAmount($c->slabs ?? [], $gross),
                'tax' => ($taxCtx = self::incomeTax($e, $run->period_start, $taxable, $relief, $otPaid, $basic, $bonusPaid))['paye'],
                default => 0,
            };
            if ($c->calc === 'percent_gross' && $amt > 0) {
                $amt = ceil($amt); // statutory contributions are rounded up to the next unit
            }
            if ($amt > 0.004 && $gross > 0) {
                if ($c->kind === 'deduction' && $c->tax_relief) {
                    $relief += round($amt, 2); // SSNIT employee contribution is deductible before PAYE
                }
                $lines[] = $line($c->code, $c->name, $c->kind, $amt, ['pay_component_id' => $c->id, 'sort_order' => 200 + $c->sort_order,
                    'expense_head_id' => $c->kind === 'employer' ? ($c->expense_head_id ?? AccountHead::sys('employer_contrib')) : null,
                    'liability_head_id' => $c->liability_head_id ?? AccountHead::sys('suspense')]);
            }
        }
        foreach ($manual->where('kind', 'deduction') as $m) {
            $lines[] = self::manualArray($m);
        }
        $taxCtx ??= self::incomeTax($e, $run->period_start, $taxable, $relief, $otPaid, $basic, $bonusPaid);
        // A junior employee's overtime carries its own final tax (GRA PAYE: 5% up to half the month's basic, 10% above),
        // credited to PAYE payable like the bands tax so the monthly DT 107 return picks both up.
        if (($taxCtx['ot_tax'] ?? 0) > 0.004) {
            $otc = $sys['OT_TAX'] ?? null;
            $lines[] = $line('OT_TAX', $otc?->name ?? 'Overtime tax (junior staff – final 5% / 10%)', 'deduction', $taxCtx['ot_tax'],
                ['pay_component_id' => $otc?->id, 'liability_head_id' => $otc?->liability_head_id ?? AccountHead::sys('paye_payable'), 'sort_order' => 245]);
        }
        if (($taxCtx['bonus_tax'] ?? 0) > 0.004) {
            $btc = $sys['BONUS_TAX'] ?? null;
            $lines[] = $line('BONUS_TAX', $btc?->name ?? 'Tax on bonus (final 5% / non-resident 20%)', 'deduction', $taxCtx['bonus_tax'],
                ['pay_component_id' => $btc?->id, 'liability_head_id' => $btc?->liability_head_id ?? AccountHead::sys('paye_payable'), 'sort_order' => 246]);
        }

        // salary advances: one instalment per open advance, never more than the net pay left
        $room = $gross - array_sum(array_map(fn ($l) => $l['amount'], array_filter($lines, fn ($l) => $l['kind'] === 'deduction')));
        $a = $sys['ADV'] ?? null;
        foreach (StaffAdvance::where('employee_id', $e->id)->whereNull('cancelled_at')->whereDate('date', '<=', $run->period_end->toDateString())->orderBy('date')->get() as $adv) {
            $amt = min((float) $adv->installment, $adv->outstanding($run->id), max(0, $room));
            if ($amt > 0.004) {
                $lines[] = $line('ADV', ($a?->name ?? 'Salary advance recovery').' '.$adv->advance_no, 'deduction', $amt,
                    ['pay_component_id' => $a?->id, 'staff_advance_id' => $adv->id, 'liability_head_id' => AccountHead::sys('staff_advances'), 'sort_order' => 290]);
                $room -= $amt;
            }
        }
        $ded = round(array_sum(array_map(fn ($l) => $l['amount'], array_filter($lines, fn ($l) => $l['kind'] === 'deduction'))), 2);
        $emp = round(array_sum(array_map(fn ($l) => $l['amount'], array_filter($lines, fn ($l) => $l['kind'] === 'employer'))), 2);

        return ['stats' => $s, 'days' => $dim, 'basic' => $basicFull, 'lines' => $lines, 'gross' => $gross, 'deductions' => $ded, 'employer' => $emp, 'net' => round($gross - $ded, 2),
            'relief' => round($relief + self::personalRelief($e), 2), 'chargeable' => round((float) ($taxCtx['chargeable'] ?? 0), 2),
            'bonus' => $bonusPaid, 'bonus_tax' => round((float) ($taxCtx['bonus_tax'] ?? 0), 2),
            'bonus_final' => round((float) ($taxCtx['bonus_final'] ?? 0), 2), 'bonus_excess' => round((float) ($taxCtx['bonus_excess'] ?? 0), 2),
            'bonus_cap' => round((float) ($taxCtx['bonus_cap'] ?? 0), 2),
            'paye_basis' => $taxCtx['basis'] ?? null, 'tax_schedule' => $taxCtx['schedule'] ?? null];
    }

    public static function slabAmount(array $slabs, float $gross): float
    {
        foreach ($slabs as [$upto, $amount]) {
            if ($upto === null || $upto === '' || $gross <= (float) $upto) {
                return (float) $amount;
            }
        }

        return 0.0;
    }

    protected static function manualArray(PayslipLine $m): array
    {
        return $m->only(['code', 'name', 'kind', 'taxable', 'pay_component_id', 'staff_advance_id', 'expense_head_id', 'liability_head_id', 'sort_order'])
            + ['amount' => (float) $m->amount, 'is_manual' => true, 'id' => $m->id];
    }

    // ───────────────────────── runs ─────────────────────────

    public static function eligible(PayrollRun $run): Collection
    {
        return Employee::where('salary', '>', 0)
            ->where(fn ($q) => $q->whereNull('joining_date')->orWhereDate('joining_date', '<=', $run->period_end->toDateString()))
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('is_active', true)->whereNull('leaving_date'))->orWhereDate('leaving_date', '>=', $run->period_start->toDateString()))
            ->orderBy('emp_code')->get();
    }

    public static function createRun(Carbon $month, ?string $notes = null): PayrollRun
    {
        $start = $month->copy()->startOfMonth();
        if (PayrollRun::whereDate('period_start', $start->toDateString())->exists()) {
            throw new RuntimeException('A payroll run for '.$start->format('F Y').' already exists.');
        }
        if ($start->gt(bdate()->copy()->startOfMonth())) {
            throw new RuntimeException('Payroll cannot be run for a future month.');
        }
        $run = PayrollRun::create(['run_no' => 'PAY-'.$start->format('Y-m'), 'period_start' => $start, 'period_end' => $start->copy()->endOfMonth()->startOfDay(), 'status' => 'draft', 'notes' => $notes]);
        self::generate($run);
        ActivityLogger::log('payroll', 'create', "Payroll run {$run->run_no} generated for {$run->employees} employee(s)", $run);

        return $run;
    }

    /** (Re)calculate every payslip of a draft run; manual lines are kept. */
    public static function generate(PayrollRun $run, bool $stamp = true): PayrollRun
    {
        if ($run->isLocked()) {
            throw new RuntimeException('Only a draft run can be recalculated – reopen it first.');
        }
        DB::transaction(function () use ($run, $stamp) {
            $emps = self::eligible($run)->keyBy('id');
            Payslip::where('payroll_run_id', $run->id)->whereNotIn('employee_id', $emps->keys())->get()->each->delete();
            foreach ($emps as $e) {
                $slip = Payslip::firstOrNew(['payroll_run_id' => $run->id, 'employee_id' => $e->id]);
                self::fill($slip, $e, $run);
            }
            self::totals($run);
            $stamp && $run->update(['generated_by' => auth()->id(), 'generated_at' => now()]);
        });

        return $run->fresh();
    }

    public static function recalcSlip(Payslip $slip): Payslip
    {
        $run = $slip->run;
        if ($run->isLocked()) {
            throw new RuntimeException('The run is '.$run->status.' – reopen it to change payslips.');
        }
        DB::transaction(function () use ($slip, $run) {
            self::fill($slip, $slip->employee, $run);
            self::totals($run);
        });

        return $slip->fresh('lines');
    }

    protected static function fill(Payslip $slip, Employee $e, PayrollRun $run): void
    {
        $manual = $slip->exists ? $slip->lines()->where('is_manual', true)->get() : collect();
        $r = self::compute($e, $run, $manual);
        $s = $r['stats'];
        $slip->fill([
            'days_in_period' => $r['days'], 'employed_days' => $s['employed'], 'present_days' => $s['present'] + $s['week_off'] + $s['holiday'], 'paid_leave_days' => $s['paid_leave'],
            'lop_days' => $s['lop'], 'paid_days' => $s['paid'], 'ot_hours' => $s['ot_hours'], 'holiday_days' => $s['holiday'], 'holiday_hours' => $s['holiday_hours'],
            'basic' => $r['basic'], 'gross' => $r['gross'], 'chargeable_income' => $r['chargeable'], 'paye_basis' => $r['paye_basis'],
            'deductions' => $r['deductions'], 'net' => $r['net'], 'employer_cost' => $r['employer'],
            'payment_mode_id' => $e->payment_mode_id ?: self::defaultModeId(), 'bank_account' => $e->bank_account,
        ])->save();
        $slip->lines()->where('is_manual', false)->delete();
        foreach ($r['lines'] as $l) {
            if (empty($l['is_manual'])) {
                unset($l['id']);
                $slip->lines()->create($l);
            }
        }
    }

    protected static function totals(PayrollRun $run): void
    {
        $t = Payslip::where('payroll_run_id', $run->id)->selectRaw('COUNT(*) n, SUM(gross) g, SUM(deductions) d, SUM(net) nt, SUM(employer_cost) e')->first();
        $run->update(['employees' => (int) $t->n, 'gross' => $t->g ?? 0, 'deductions' => $t->d ?? 0, 'net' => $t->nt ?? 0, 'employer_cost' => $t->e ?? 0]);
    }

    public static function defaultModeId(): ?int
    {
        return (int) setting('payroll_default_mode', 0) ?: PaymentMode::where('type', 'bank')->value('id') ?: PaymentMode::where('type', 'cash')->value('id');
    }

    public static function addAdjustment(Payslip $slip, array $d): PayslipLine
    {
        if ($slip->run->isLocked()) {
            throw new RuntimeException('The run is '.$slip->run->status.' – reopen it to change payslips.');
        }
        // Flagging an earning as a bonus moves it out of the banded income: the tax engine then applies the GRA
        // ex-gratia treatment (final 5% up to 15% of the year's basic, 20% for a non-resident) and raises BONUS_TAX itself,
        // which is why the line is stored non-taxable – its own tax is computed, not guessed by a checkbox.
        $bonus = $d['kind'] === 'earning' && ($d['component'] ?? '') === 'BONUS';
        $bc = $bonus ? PayComponent::where('code', 'BONUS')->first() : null;
        $line = $slip->lines()->create([
            'code' => $bonus ? 'BONUS' : ($d['kind'] === 'earning' ? 'ADJ+' : 'ADJ-'), 'name' => $d['name'], 'kind' => $d['kind'], 'amount' => round((float) $d['amount'], 2),
            'taxable' => $bonus ? false : ($d['kind'] === 'earning' && ! empty($d['taxable'])), 'is_manual' => true,
            'pay_component_id' => $bc?->id, 'sort_order' => $bonus ? 92 : ($d['kind'] === 'earning' ? 95 : 295),
            'expense_head_id' => $d['kind'] === 'earning' ? ($d['account_head_id'] ?? null ?: AccountHead::sys('salaries')) : null,
            'liability_head_id' => $d['kind'] === 'deduction' ? ($d['account_head_id'] ?? null ?: AccountHead::sys('salaries')) : null, // e.g. a fine/recovery reduces salary cost
        ]);
        self::recalcSlip($slip->fresh());
        ActivityLogger::log('payroll', 'adjust', "Payslip adjustment '{$line->name}' ".money($line->amount)." for {$slip->employee->name} ({$slip->run->run_no})", $slip);

        return $line;
    }

    public static function removeAdjustment(PayslipLine $line): void
    {
        $slip = $line->payslip;
        if (! $line->is_manual || $slip->run->isLocked()) {
            throw new RuntimeException('Only manual lines of a draft run can be removed.');
        }
        if ($line->code === 'SVC') { // v4.9
            throw new RuntimeException('This is a service charge share – cancel the service charge distribution instead.');
        }
        $line->delete();
        self::recalcSlip($slip->fresh());
    }

    public static function approve(PayrollRun $run): PayrollRun
    {
        if ($run->status !== 'draft') {
            throw new RuntimeException('Only a draft run can be approved.');
        }
        if (setting('payroll_segregation', '1') === '1' && $run->generated_by && (int) $run->generated_by === (int) auth()->id()) {
            throw new RuntimeException('Segregation of duties: the run must be approved by someone other than the person who generated it.');
        }
        self::generate($run, false); // latest attendance, leave and advances (the preparer stays on record)
        $run->refresh();
        if (! $run->employees) {
            throw new RuntimeException('The run has no payslips.');
        }
        if ($neg = Payslip::with('employee')->where('payroll_run_id', $run->id)->where('net', '<', 0)->first()) {
            throw new RuntimeException("Net pay for {$neg->employee->name} is negative – reduce the deductions first.");
        }
        $run->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
        self::postNow('payroll', $run, $run->period_end);
        ActivityLogger::log('payroll', 'approve', "Approved payroll {$run->run_no}: net ".money($run->net), $run);
        self::notifyRun($run, 'approved');

        return $run;
    }

    /**
     * Best-effort SMS / WhatsApp to every paid worker when their run is approved or paid (v7.43).
     * Off unless payroll_notify = 1, and deliberately amount-free: Ghana's Data Protection Act 2012
     * (Act 843) treats pay as sensitive personal data, and a text message is not a secure channel.
     */
    public static function notifyRun(PayrollRun $run, string $event): int
    {
        if (setting('payroll_notify', '0') !== '1' || ! class_exists(MessageService::class)) {
            return 0;
        }
        $label = $run->period_start->format('F Y');
        $sent = 0;
        foreach (Payslip::with('employee')->where('payroll_run_id', $run->id)->get() as $slip) {
            $to = $slip->employee?->phone;
            if (! $to) {
                continue;
            }
            $body = $event === 'paid'
                ? "Your salary for {$label} has been paid. Your payslip is on the staff portal under My HR. – ".hotel()->name
                : "Your payslip for {$label} has been approved and is on the staff portal under My HR. Query anything you disagree with before payday. – ".hotel()->name;
            try {
                MessageService::send($to, $body, MessageService::defaultChannel(), 'payroll_'.$event, $run);
                $sent++;
            } catch (\Throwable) {
                // a missing or failing gateway must never block payroll approval
            }
        }

        return $sent;
    }

    public static function reopen(PayrollRun $run, string $reason): PayrollRun
    {
        if ($run->status !== 'approved') {
            throw new RuntimeException($run->status === 'paid' ? 'A paid run cannot be reopened.' : 'The run is already a draft.');
        }
        StatutoryService::assertRunReopenable($run); // v4.5
        $run->update(['status' => 'draft', 'approved_by' => null, 'approved_at' => null]);
        self::postNow('payroll', $run, $run->period_end); // reverses the accrual when that day is already closed
        ActivityLogger::log('payroll', 'reopen', "Reopened payroll {$run->run_no}: {$reason}", $run);

        return $run;
    }

    public static function pay(PayrollRun $run, string $date): PayrollRun
    {
        if ($run->status !== 'approved') {
            throw new RuntimeException('Only an approved run can be paid.');
        }
        if ($date < $run->period_end->toDateString() || $date > bdate()->toDateString()) {
            throw new RuntimeException('The payment date must be between the period end ('.dmy($run->period_end).') and today.');
        }
        $run->update(['status' => 'paid', 'paid_date' => $date, 'paid_by' => auth()->id()]);
        self::postNow('payroll_pay', $run, Carbon::parse($date));
        ActivityLogger::log('payroll', 'pay', "Paid payroll {$run->run_no}: ".money($run->net)." on {$date}", $run);
        self::notifyRun($run, 'paid');

        return $run;
    }

    public static function deleteRun(PayrollRun $run): void
    {
        if ($run->isLocked()) {
            throw new RuntimeException('Only a draft run can be deleted.');
        }
        ActivityLogger::log('payroll', 'delete', "Deleted draft payroll {$run->run_no}", $run);
        $run->delete();
    }

    /** The approved / paid run covering a date (attendance and leave are locked there). */
    public static function lockedRunFor(string $date): ?PayrollRun
    {
        return PayrollRun::whereIn('status', ['approved', 'paid'])->whereDate('period_start', '<=', $date)->whereDate('period_end', '>=', $date)->first();
    }

    public static function assertOpen(string $from, ?string $to = null): void
    {
        $to ??= $from;
        $run = PayrollRun::whereIn('status', ['approved', 'paid'])->whereDate('period_start', '<=', $to)->whereDate('period_end', '>=', $from)->first();
        if ($run) {
            throw new RuntimeException("Payroll for {$run->label()} is {$run->status} – attendance and leave in that month are locked".($run->status === 'approved' ? ' (reopen the run to change them).' : '.'));
        }
    }

    // ───────────────────────── advances ─────────────────────────

    public static function giveAdvance(array $d): StaffAdvance
    {
        $adv = StaffAdvance::create($d + ['advance_no' => Numbering::next('staff_advance'), 'created_by' => auth()->id()]);
        self::postNow('advance', $adv, $adv->date);
        ActivityLogger::log('payroll', 'advance', "Salary advance {$adv->advance_no} ".money($adv->amount)." to {$adv->employee->name}", $adv);

        return $adv;
    }

    public static function cancelAdvance(StaffAdvance $adv): void
    {
        if ($adv->cancelled_at) {
            throw new RuntimeException('Already cancelled.');
        }
        if (PayslipLine::where('staff_advance_id', $adv->id)->exists()) {
            throw new RuntimeException('Instalments are already on a payslip – the advance cannot be cancelled.');
        }
        $adv->update(['cancelled_at' => now()]);
        self::postNow('advance', $adv, $adv->date);
        ActivityLogger::log('payroll', 'advance_cancel', "Cancelled salary advance {$adv->advance_no}", $adv);
    }

    // ───────────────────────── ledger ─────────────────────────

    /** Post / reverse straight away when the document date is in a closed day; otherwise the night-audit sync picks it up. */
    public static function postNow(string $type, Model $doc, Carbon $date): void
    {
        $cutoff = setting('gl_cutoff');
        if (! $cutoff || $date->toDateString() > $cutoff) {
            return;
        }
        try {
            ChartOfAccounts::ensureLight();
            LedgerService::reconcile($type, (string) $doc->id, LedgerService::build($type, (string) $doc->id), $cutoff);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Sub-ledger balances for the GL control reconciliation. */
    public static function payableBalance(string $asOf): float
    {
        $accrued = PayrollRun::whereIn('status', ['approved', 'paid'])->whereDate('period_end', '<=', $asOf)->sum('net');
        $paid = PayrollRun::where('status', 'paid')->whereDate('paid_date', '<=', $asOf)->sum('net');

        return round((float) $accrued - (float) $paid, 2);
    }

    public static function advancesBalance(string $asOf): float
    {
        $given = StaffAdvance::whereNull('cancelled_at')->whereDate('date', '<=', $asOf)->sum('amount');
        $recovered = PayslipLine::whereNotNull('staff_advance_id')
            ->whereHas('payslip.run', fn ($q) => $q->whereIn('status', ['approved', 'paid'])->whereDate('period_end', '<=', $asOf))->sum('amount');

        return round((float) $given - (float) $recovered, 2);
    }
}
