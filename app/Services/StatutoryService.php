<?php

namespace App\Services;

use App\Models\AccountHead;
use App\Models\Employee;
use App\Models\GlJournal;
use App\Models\GlLine;
use App\Models\PaymentMode;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PayslipLine;
use App\Models\StatutoryRemittance;
use App\Support\Numbering;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v4.5 Ghana payroll statutory returns, built from the approved payroll of each month:
 *   SSNIT Tier 1 – 13.5 % of basic salary (employee 5.5 % + employer 8 %), to SSNIT by the 14th of the following month;
 *   Tier 2      – 5 % of basic (employer), to the scheme's corporate trustee by the 14th;
 *   PAYE        – income tax deducted, to GRA by the 15th, on the bands in force in that payroll month (Act 1178 from 1 Sep 2026).
 * Amounts come from the payslip lines that credit each liability account (2210 / 2220 / 2230), so custom components are
 * included. Recording a remittance posts Dr the liability / Cr the bank and locks the month's payroll run from reopening.
 * Also: the national daily minimum wage check (GH₵ 21.77 × 27 days from 1 Jan 2026) and the insurable-earnings cap.
 */
class StatutoryService
{
    /** kind => [label, default payee, due day of the following month, liability account system key, codes (fallback when a line has no account)] */
    public const KINDS = [
        'ssnit' => ['SSNIT Tier 1 (13.5%)', 'Social Security and National Insurance Trust (SSNIT)', 14, 'pf_payable', ['SSNIT', 'SSNIT_ER']],
        'tier2' => ['Tier 2 occupational pension (5%)', 'Tier 2 corporate trustee', 14, 'esi_payable', ['TIER2_ER', 'TIER2']],
        'paye' => ['PAYE income tax (GRA)', 'Ghana Revenue Authority (GRA)', 15, 'paye_payable', ['PAYE']],
    ];

    public const DEFAULTS = [
        'stat_ssnit_employer_no' => '', 'stat_tier2_trustee' => '', 'stat_tier2_scheme' => '',
        'payroll_min_wage_daily' => '21.77', 'payroll_min_wage_days' => '27', 'stat_insurable_cap' => '69000',
    ];

    public static function cfg(string $k): string
    {
        return (string) setting($k, self::DEFAULTS[$k]);
    }

    public static function label(string $kind): string
    {
        return self::KINDS[$kind][0] ?? $kind;
    }

    public static function payee(string $kind): string
    {
        if ($kind === 'tier2' && ($t = self::cfg('stat_tier2_trustee')) !== '') {
            return $t;
        }

        return self::KINDS[$kind][1];
    }

    public static function due(string $kind, string $period): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfDay()->addMonthNoOverflow()->day(self::KINDS[$kind][2]);
    }

    public static function validPeriod(?string $p): bool
    {
        return (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $p);
    }

    /** SSNIT number: the Ghana Card PIN (SSNIT has used it since 2021) or a legacy number – a letter and 11–12 digits. */
    public static function validSsnit(?string $n): bool
    {
        $n = strtoupper(trim((string) $n));

        return GstService::validTin($n) || (bool) preg_match('/^[A-Z]\d{11,12}$/', $n);
    }

    public static function isGhanaCard(?string $n): bool
    {
        return (bool) preg_match('/^GHA-\d{9}-\d$/', strtoupper(trim((string) $n)));
    }

    public static function minWageMonthly(): float
    {
        return round((float) self::cfg('payroll_min_wage_daily') * (float) self::cfg('payroll_min_wage_days'), 2);
    }

    /** Active employees whose monthly basic salary is below the national minimum wage. */
    public static function belowMinimumWage(): Collection
    {
        $min = self::minWageMonthly();

        return $min > 0 ? Employee::where('is_active', true)->where('salary', '>', 0)->where('salary', '<', $min)->orderBy('name')->get() : collect();
    }

    // ─────────────────────────── the returns ───────────────────────────

    public static function runFor(string $period): ?PayrollRun
    {
        return PayrollRun::whereDate('period_start', $period.'-01')->first(); // a date column stores 'Y-m-d 00:00:00' – equality never matched
    }

    protected static function heads(): array
    {
        return collect(self::KINDS)->map(fn ($k) => AccountHead::sys($k[3]))->all();
    }

    /** Which return a payslip line belongs to (by its liability account, else by its code), or null. */
    public static function kindOf($line, ?array $heads = null): ?string
    {
        if (! in_array($line->kind, ['deduction', 'employer'], true)) {
            return null;
        }
        $heads ??= self::heads();
        if ($line->liability_head_id && ($k = array_search((int) $line->liability_head_id, array_map('intval', $heads), true)) !== false) {
            return $k;
        }
        if ($line->liability_head_id) {
            return null; // credited to another account (advances, loans …)
        }
        foreach (self::KINDS as $k => $def) {
            if (in_array($line->code, $def[4], true)) {
                return $k;
            }
        }

        return null;
    }

    /**
     * One return: rows per employee, totals, due date, status, the remittance, the GL balance posted by the payroll and data issues.
     * Rows are built only from an approved / paid run.
     */
    public static function build(string $kind, string $period): array
    {
        abort_unless(isset(self::KINDS[$kind]), 404);
        $run = self::runFor($period);
        $heads = self::heads();
        $cap = (float) self::cfg('stat_insurable_cap');
        $rows = collect();
        if ($run && $run->isLocked()) {
            $slips = Payslip::with('employee', 'lines')->where('payroll_run_id', $run->id)->get()->sortBy(fn ($s) => $s->employee?->name)->values();
            foreach ($slips as $s) {
                $mine = $s->lines->filter(fn ($l) => self::kindOf($l, $heads) === $kind);
                $ee = round((float) $mine->where('kind', 'deduction')->sum('amount'), 2);
                $er = round((float) $mine->where('kind', 'employer')->sum('amount'), 2);
                if ($ee + $er <= 0) {
                    continue;
                }
                $e = $s->employee;
                $basic = round((float) $s->lines->where('code', 'BASIC')->sum('amount'), 2);
                $row = ['employee_id' => $e->id, 'emp_code' => $e->emp_code, 'name' => $e->name, 'position' => $e->designation, 'ghana_card' => $e->tax_id, 'ssnit_no' => $e->social_security_no,
                    'basic' => $basic, 'ee' => $ee, 'er' => $er, 'total' => round($ee + $er, 2)];
                if ($kind === 'paye') {
                    $earn = $s->lines->where('kind', 'earning');
                    $ot = round((float) $earn->whereIn('code', ['OT', 'OTH'])->sum('amount'), 2);
                    $relief = round((float) $s->lines->filter(fn ($l) => $l->kind === 'deduction' && self::kindOf($l, $heads) === 'ssnit')->sum('amount'), 2);
                    $row += ['allowances' => round((float) $earn->whereNotIn('code', ['BASIC', 'OT', 'OTH'])->sum('amount'), 2), 'overtime' => $ot, 'gross' => (float) $s->gross,
                        'relief' => $relief, 'personal_relief' => PayrollService::personalRelief($e),
                        'ot_tax' => round((float) $s->lines->where('code', 'OT_TAX')->sum('amount'), 2),
                        'chargeable' => round((float) $s->chargeable_income, 2), 'resident' => PayrollService::isResident($e) ? 'Y' : 'N',
                        'basis' => (string) ($s->paye_basis ?? ''), 'holiday_hours' => round((float) $s->holiday_hours, 2)];
                } else {
                    $row['insurable'] = $cap > 0 ? min($basic, $cap) : $basic;
                }
                $rows->push($row);
            }
        }
        $rem = StatutoryRemittance::active()->with('paymentMode', 'creator')->where('period', $period)->where('kind', $kind)->latest('id')->first();
        $due = self::due($kind, $period);
        $total = round($rows->sum('total'), 2);
        $issues = [];
        foreach ($rows as $r) {
            if ($kind === 'paye' && ! GstService::validTin($r['ghana_card'])) {
                $issues[] = "{$r['name']}: no valid Ghana Card PIN / TIN";
            } elseif ($kind !== 'paye' && ! self::validSsnit($r['ssnit_no']) && ! self::isGhanaCard($r['ghana_card'])) {
                $issues[] = "{$r['name']}: no SSNIT number or Ghana Card PIN";
            }
        }
        // what the payroll credited to the liability account for this run (the remittance should clear it)
        $gl = null;
        if ($run && $heads[$kind]) {
            $jid = GlJournal::where('source_type', 'payroll')->where('source_key', (string) $run->id)->whereNull('reversed_by_id')->whereNull('reversal_of_id')->value('id');
            $gl = $jid ? round((float) GlLine::where('gl_journal_id', $jid)->where('account_head_id', $heads[$kind])->sum(DB::raw('credit - debit')), 2) : null;
        }

        return ['kind' => $kind, 'label' => self::label($kind), 'payee' => self::payee($kind), 'period' => $period, 'run' => $run, 'rows' => $rows,
            'ee' => round($rows->sum('ee'), 2), 'er' => round($rows->sum('er'), 2), 'total' => $total, 'employees' => $rows->count(),
            'due' => $due, 'remittance' => $rem, 'status' => self::statusOf($run, $rem, $due, $total), 'issues' => $issues, 'gl' => $gl,
            'schedule' => $kind === 'paye' ? PayrollService::scheduleFor($period.'-01') : null,
            'ot_tax' => $kind === 'paye' ? round($rows->sum('ot_tax'), 2) : 0.0,
            'personal_relief' => $kind === 'paye' ? round($rows->sum('personal_relief'), 2) : 0.0,
            'non_residents' => $kind === 'paye' ? $rows->where('resident', 'N')->count() : 0];
    }

    /** no_payroll | draft | nil | due | overdue | remitted | late */
    protected static function statusOf(?PayrollRun $run, ?StatutoryRemittance $rem, Carbon $due, float $total): string
    {
        if ($rem) {
            return $rem->paid_on->gt($due) ? 'late' : 'remitted';
        }
        if (! $run) {
            return 'no_payroll';
        }
        if (! $run->isLocked()) {
            return 'draft';
        }
        if ($total <= 0) {
            return 'nil';
        }

        return bdate()->gt($due) ? 'overdue' : 'due';
    }

    public static function status(string $kind, string $period): string
    {
        return self::build($kind, $period)['status'];
    }

    public static function overview(string $period): array
    {
        return collect(array_keys(self::KINDS))->mapWithKeys(fn ($k) => [$k => self::build($k, $period)])->all();
    }

    /** Returns overdue or due within 5 days over the last three payroll months (dashboard, nav badge). */
    public static function alerts(): Collection
    {
        $out = collect();
        foreach ([3, 2, 1] as $back) {
            $p = bdate()->copy()->startOfMonth()->subMonthsNoOverflow($back)->format('Y-m');
            if (! ($run = self::runFor($p)) || ! $run->isLocked()) {
                continue;
            }
            foreach (array_keys(self::KINDS) as $k) {
                $r = self::build($k, $p);
                if ($r['status'] === 'overdue' || ($r['status'] === 'due' && bdate()->diffInDays($r['due'], false) <= 5)) {
                    $out->push(['kind' => $k, 'period' => $p, 'label' => $r['label'], 'total' => $r['total'], 'due' => $r['due'], 'overdue' => $r['status'] === 'overdue']);
                }
            }
        }

        return $out;
    }

    /** Overdue returns over the last three payroll months – light query for the nav badge (no per-employee build). */
    public static function overdueCount(): int
    {
        $n = 0;
        $heads = null;
        foreach ([3, 2, 1] as $back) {
            $p = bdate()->copy()->startOfMonth()->subMonthsNoOverflow($back)->format('Y-m');
            $run = self::runFor($p);
            if (! $run || ! $run->isLocked()) {
                continue;
            }
            $open = collect(array_keys(self::KINDS))->filter(fn ($k) => bdate()->gt(self::due($k, $p)));
            if ($open->isEmpty()) {
                continue;
            }
            $paid = StatutoryRemittance::active()->where('period', $p)->pluck('kind')->all();
            $open = $open->reject(fn ($k) => in_array($k, $paid, true));
            if ($open->isEmpty()) {
                continue;
            }
            $heads ??= self::heads();
            $sums = [];
            PayslipLine::whereIn('payslip_id', Payslip::where('payroll_run_id', $run->id)->select('id'))->whereIn('kind', ['deduction', 'employer'])
                ->get(['kind', 'code', 'amount', 'liability_head_id'])
                ->each(function ($l) use (&$sums, $heads) {
                    if ($k = self::kindOf($l, $heads)) {
                        $sums[$k] = ($sums[$k] ?? 0) + (float) $l->amount;
                    }
                });
            $n += $open->filter(fn ($k) => ($sums[$k] ?? 0) > 0)->count();
        }

        return $n;
    }

    // ─────────────────────────── remittances ───────────────────────────

    public static function remit(string $kind, string $period, array $d): StatutoryRemittance
    {
        $r = self::build($kind, $period);
        if (! $r['run'] || ! $r['run']->isLocked()) {
            throw new RuntimeException('Approve the payroll for '.Carbon::parse($period.'-01')->format('F Y').' first – the return is built from the approved payslips.');
        }
        if ($r['remittance']) {
            throw new RuntimeException("{$r['label']} for ".Carbon::parse($period.'-01')->format('M Y')." is already recorded ({$r['remittance']->reference}).");
        }
        if ($r['total'] <= 0) {
            throw new RuntimeException('Nothing is due on this return.');
        }
        $paid = Carbon::parse($d['paid_on'])->startOfDay();
        if ($paid->lt($r['run']->period_end) || $paid->gt(bdate())) {
            throw new RuntimeException('The payment date must be between the end of the payroll month ('.dmy($r['run']->period_end).') and today.');
        }
        if (! empty($d['payment_mode_id']) && ! PaymentMode::whereKey($d['payment_mode_id'])->whereNotIn('type', ['city_ledger', 'gift_voucher', 'forex'])->exists()) {
            throw new RuntimeException('Choose a cash, bank or Mobile Money account to pay from.');
        }
        if ($kind === 'tier2' && self::cfg('stat_tier2_trustee') === '') {
            throw new RuntimeException('Enter the Tier 2 corporate trustee under Statutory returns › Settings first.');
        }
        $rem = DB::transaction(fn () => StatutoryRemittance::create([
            'remit_no' => Numbering::next('stat_remit'), 'period' => $period, 'kind' => $kind, 'payroll_run_id' => $r['run']->id,
            'amount' => $r['total'], 'employee_share' => $r['ee'], 'employer_share' => $r['er'], 'employees' => $r['employees'],
            'paid_on' => $paid->toDateString(), 'payment_mode_id' => $d['payment_mode_id'] ?? null, 'reference' => trim($d['reference']),
            'payee' => self::payee($kind), 'notes' => $d['notes'] ?? null, 'created_by' => auth()->id(),
        ]));
        PayrollService::postNow('stat_remit', $rem, $paid);
        ActivityLogger::log('payroll', 'stat_remit', "{$r['label']} ".Carbon::parse($period.'-01')->format('M Y').' remitted: '.money($rem->amount)." ({$rem->reference})".($paid->gt($r['due']) ? ' – late' : ''), $rem);

        return $rem;
    }

    public static function cancel(StatutoryRemittance $rem, string $reason): StatutoryRemittance
    {
        if ($rem->isCancelled()) {
            throw new RuntimeException('Already cancelled.');
        }
        $rem->update(['cancelled_at' => now(), 'cancelled_by' => auth()->id(), 'cancel_reason' => $reason]);
        PayrollService::postNow('stat_remit', $rem, $rem->paid_on);
        ActivityLogger::log('payroll', 'stat_remit_cancel', "Cancelled remittance {$rem->remit_no} ({$rem->reference}): {$reason}", $rem);

        return $rem;
    }

    /** A remitted month cannot be reopened: the liability has been paid on the approved figures. */
    public static function assertRunReopenable(PayrollRun $run): void
    {
        $p = $run->period_start->format('Y-m');
        if ($n = StatutoryRemittance::active()->where('period', $p)->pluck('kind')->map(fn ($k) => self::label($k))->implode(', ')) {
            throw new RuntimeException("Statutory payments for {$run->label()} have been recorded ({$n}) – cancel them before reopening the payroll.");
        }
    }

    /** GL: Dr the liability, Cr the account paid from. Cancelled → nothing (the posted journal is reversed). */
    public static function forRemittance(StatutoryRemittance $r): ?array
    {
        if ($r->isCancelled() || (float) $r->amount <= 0) {
            return null;
        }
        $head = AccountHead::sys(self::KINDS[$r->kind][3]);
        $label = self::label($r->kind).' '.Carbon::parse($r->period.'-01')->format('M Y');

        return ['date' => $r->paid_on->toDateString(), 'reference' => $r->reference, 'narration' => "{$label} paid to {$r->payee}",
            'lines' => [[$head, (float) $r->amount, $label], [LedgerService::modeAccount($r->payment_mode_id, 'bank'), -(float) $r->amount, $r->remit_no]]];
    }

    // ─────────────────────────── exports ───────────────────────────

    public static function csv(string $kind, string $period): array
    {
        $r = self::build($kind, $period);
        if ($kind === 'paye') {
            $head = ['Employee TIN / Ghana Card PIN', 'Employee name', 'Position', 'Resident', 'Basic salary', 'Cash allowances', 'Overtime', 'Total cash emolument',
                'SSNIT relief (5.5%)', 'Personal reliefs', 'Chargeable income', 'Overtime tax (junior staff)', 'Tax deducted (PAYE)', 'Rule applied'];
            $rows = $r['rows']->map(fn ($x) => [$x['ghana_card'], $x['name'], $x['position'], $x['resident'], $x['basic'], $x['allowances'], $x['overtime'], $x['gross'],
                $x['relief'], $x['personal_relief'], $x['chargeable'], $x['ot_tax'], $x['total'], $x['basis']]);
        } else {
            $head = ['SSNIT number', 'Ghana Card PIN', 'Employee name', 'Staff no.', 'Basic salary', 'Insurable earnings', 'Employee share', 'Employer share', 'Total contribution'];
            $rows = $r['rows']->map(fn ($x) => [$x['ssnit_no'], $x['ghana_card'], $x['name'], $x['emp_code'], $x['basic'], $x['insurable'], $x['ee'], $x['er'], $x['total']]);
        }
        $id = match ($kind) {
            'ssnit' => self::cfg('stat_ssnit_employer_no') ?: 'EMPLOYER',
            'tier2' => self::cfg('stat_tier2_scheme') ?: 'SCHEME',
            default => GstService::hotelTin() ?: 'TIN',
        };
        $name = strtoupper($kind).'_'.preg_replace('/[^A-Za-z0-9-]/', '', $id).'_'.str_replace('-', '', $period).'.csv';

        return [$name, $head, $rows->values()->all()];
    }
}
