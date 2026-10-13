<?php

namespace App\Services;

use App\Models\AccountHead;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\GlLine;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PayslipLine;
use App\Models\ScDistribution;
use App\Models\ScDistributionLine;
use App\Models\TipCollection;
use App\Models\User;
use App\Support\Numbering;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v4.9 Service charge & tips pool (tronc). POS / folio service charge and recorded tips are credited to
 * 2110 Service Charge Payable. For a closed period the pool (collected − house retention + rounding carried in)
 * is shared among eligible staff by points × days worked (or points / equal), approved by someone other than
 * the preparer, and paid as a taxable earning through the next draft payroll run – the payroll journal then
 * clears 2110. The house retention is posted to 4095 on approval.
 */
class ServiceChargeService
{
    public const EXCLUDED_SOURCES = ['payroll', 'sc_dist'];

    public static function retentionPct(): float
    {
        return max(0.0, min(100.0, (float) setting('sc_retention_pct', '10')));
    }

    /** Net credits to 2110 in a period, from anything but the payout itself, by GL source. */
    public static function collectedBySource(string $from, string $to): array
    {
        $acc = AccountHead::sys('service_charge');

        return GlLine::join('gl_journals', 'gl_journals.id', '=', 'gl_lines.gl_journal_id')->where('gl_lines.account_head_id', $acc)
            ->whereBetween('gl_lines.date', [$from, $to])->where('gl_lines.closing', false)->whereNotIn('gl_journals.source_type', self::EXCLUDED_SOURCES)
            ->groupBy('gl_journals.source_type')->selectRaw('gl_journals.source_type s, SUM(gl_lines.credit) - SUM(gl_lines.debit) amt')
            ->pluck('amt', 's')->map(fn ($v) => round((float) $v, 2))->all();
    }

    public static function collected(string $from, string $to): float
    {
        return round(array_sum(self::collectedBySource($from, $to)), 2);
    }

    /** Collected to date less everything already retained or distributed by live distributions (other than $except). */
    public static function available(string $asOf, ?ScDistribution $except = null): float
    {
        $in = self::collected('2000-01-01', $asOf);
        $used = ScDistribution::whereIn('status', ['approved', 'posted'])->when($except, fn ($q) => $q->whereKeyNot($except->id))
            ->selectRaw('COALESCE(SUM(retained + distributed), 0) s')->value('s');

        return round($in - (float) $used, 2);
    }

    // ───────────────────────── tips ─────────────────────────

    public static function addTip(array $d, User $u): TipCollection
    {
        if (! array_key_exists($d['source'] ?? '', TipCollection::SOURCES)) {
            throw new RuntimeException('Choose where the tips came from.');
        }
        if ((float) $d['amount'] <= 0) {
            throw new RuntimeException('Enter the amount.');
        }
        if ($d['date'] > bdate()->toDateString()) {
            throw new RuntimeException('The date cannot be after the business date.');
        }
        if (($lock = LedgerService::lockDate()) && $d['date'] <= $lock) {
            throw new RuntimeException('The books are locked up to '.dmy($lock).'.');
        }
        if (self::consumed($d['date'])) {
            throw new RuntimeException('That date is inside a period whose service charge has already been distributed.');
        }
        $t = TipCollection::create(['tip_no' => Numbering::next('tip'), 'date' => $d['date'], 'source' => $d['source'], 'payment_mode_id' => $d['payment_mode_id'],
            'amount' => round((float) $d['amount'], 2), 'reference' => $d['reference'] ?? null, 'notes' => $d['notes'] ?? null, 'created_by' => $u->id]);
        ActivityLogger::log('servicecharge', 'tip', "Tips {$t->tip_no} ".money($t->amount).' ('.TipCollection::SOURCES[$t->source].')', $t);

        return $t;
    }

    public static function cancelTip(TipCollection $t, string $reason): TipCollection
    {
        if ($t->cancelled_at) {
            throw new RuntimeException('Already cancelled.');
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw new RuntimeException('Give a reason for cancelling.');
        }
        if (self::consumed($t->date->toDateString())) {
            throw new RuntimeException('These tips were part of a distribution that has been approved – cancel that first.');
        }
        $t->update(['cancelled_at' => now(), 'cancel_reason' => trim($reason)]);
        ActivityLogger::log('servicecharge', 'tip_cancel', "Cancelled tips {$t->tip_no} ({$reason})", $t);

        return $t;
    }

    protected static function consumed(string $date): bool
    {
        return ScDistribution::whereIn('status', ['approved', 'posted'])->where('period_start', '<=', $date)->where('period_end', '>=', $date)->exists();
    }

    /** GL: Dr cash / card clearing / MoMo, Cr 2110. */
    public static function forTip(TipCollection $t): ?array
    {
        if ($t->cancelled_at || (float) $t->amount <= 0) {
            return null;
        }

        return ['date' => $t->date->toDateString(), 'reference' => $t->tip_no, 'narration' => 'Staff tips – '.TipCollection::SOURCES[$t->source].($t->reference ? " – {$t->reference}" : ''),
            'lines' => [[LedgerService::modeAccount($t->payment_mode_id, 'cash'), (float) $t->amount, $t->paymentMode?->name], [AccountHead::sys('service_charge'), -(float) $t->amount, 'Tips']]];
    }

    // ───────────────────────── distributions ─────────────────────────

    public static function create(array $d, User $u): ScDistribution
    {
        $from = Carbon::parse($d['period_start'])->toDateString();
        $to = Carbon::parse($d['period_end'])->toDateString();
        if ($to < $from) {
            throw new RuntimeException('The period ends before it starts.');
        }
        if ($to > LedgerService::cutoff()) {
            throw new RuntimeException('The period must be closed – the ledger is posted up to '.dmy(LedgerService::cutoff()).'.');
        }
        if ($o = ScDistribution::where('status', '!=', 'cancelled')->where('period_start', '<=', $to)->where('period_end', '>=', $from)->first()) {
            throw new RuntimeException("The period overlaps {$o->dist_no} ({$o->label()}).");
        }
        if (! array_key_exists($d['basis'] ?? 'points_days', ScDistribution::BASES)) {
            throw new RuntimeException('Unknown sharing basis.');
        }
        $prev = ScDistribution::whereIn('status', ['approved', 'posted'])->where('period_end', '<', $from)->orderByDesc('period_end')->first();

        return DB::transaction(function () use ($d, $u, $from, $to, $prev) {
            $dist = ScDistribution::create(['dist_no' => Numbering::next('sc_dist'), 'period_start' => $from, 'period_end' => $to, 'basis' => $d['basis'] ?? 'points_days',
                'retention_pct' => self::retentionPct(), 'carried_in' => (float) ($prev?->carry_out ?? 0), 'notes' => $d['notes'] ?? null, 'created_by' => $u->id, 'status' => 'draft']);
            self::compute($dist);
            ActivityLogger::log('servicecharge', 'create', "Service charge distribution {$dist->dist_no} for {$dist->label()}: pool ".money($dist->pool), $dist);

            return $dist->fresh('lines');
        });
    }

    /** (Re)build a draft: collected, retention, pool and the per-employee shares (manual exclusions are kept). */
    public static function compute(ScDistribution $dist): ScDistribution
    {
        if ($dist->status !== 'draft') {
            throw new RuntimeException('Only a draft distribution can be recalculated.');
        }
        $from = $dist->period_start->toDateString();
        $to = $dist->period_end->toDateString();
        $collected = self::collected($from, $to);
        $retained = round(max(0, $collected) * (float) $dist->retention_pct / 100, 2);
        $pool = round(max(0, $collected - $retained + (float) $dist->carried_in), 2);
        $periodDays = $dist->period_start->diffInDays($dist->period_end) + 1;
        $keep = $dist->lines()->where('excluded', true)->pluck('exclude_reason', 'employee_id');
        $dist->lines()->delete();

        $emps = Employee::where('sc_eligible', true)
            ->where(fn ($q) => $q->whereNull('joining_date')->whereDate('joining_date', '<=', $to))
            ->where(fn ($q) => $q->whereNull('leaving_date')->whereDate('leaving_date', '>=', $from))
            ->where(fn ($q) => $q->where('is_active', true)->orWhereNotNull('leaving_date'))->orderBy('name')->get();
        $att = Attendance::whereIn('employee_id', $emps->pluck('id'))->whereBetween('date', [$from, $to])->whereIn('status', ['P', 'H'])
            ->get()->groupBy('employee_id')->map(fn ($g) => $g->sum(fn ($a) => $a->status === 'H' ? 0.5 : 1));
        $rows = [];
        foreach ($emps as $e) {
            $start = max($from, $e->joining_date?->toDateString() ?? $from);
            $end = min($to, $e->leaving_date?->toDateString() ?? $to);
            $employed = $start <= $end ? Carbon::parse($start)->diffInDays(Carbon::parse($end)) + 1 : 0;
            $days = (float) ($att[$e->id] ?? 0);
            $points = (float) $e->sc_points;
            $excluded = $keep->has($e->id);
            $weight = $excluded ? 0 : match ($dist->basis) {
                'points' => $points * $employed / max(1, $periodDays),
                'equal' => $employed > 0 ? 1 : 0,
                default => $points * $days,
            };
            $rows[] = ['employee_id' => $e->id, 'department_id' => $e->department_id, 'points' => $points, 'days' => $days, 'weight' => round($weight, 2),
                'excluded' => $excluded, 'exclude_reason' => $excluded ? $keep[$e->id] : null];
        }
        $total = array_sum(array_column($rows, 'weight'));
        $distributed = 0.0;
        foreach ($rows as &$r) {
            $r['amount'] = $total > 0 ? floor($pool * $r['weight'] / $total * 100) / 100 : 0;
            $distributed += $r['amount'];
        }
        unset($r);
        foreach ($rows as $r) {
            $dist->lines()->create($r);
        }
        $distributed = round($distributed, 2);
        $dist->update(['collected' => $collected, 'retained' => $retained, 'pool' => $pool, 'distributed' => $distributed, 'carry_out' => round($pool - $distributed, 2)]);

        return $dist;
    }

    public static function exclude(ScDistributionLine $line, bool $excluded, ?string $reason): ScDistribution
    {
        $dist = $line->distribution;
        if ($dist->status !== 'draft') {
            throw new RuntimeException('Only a draft distribution can be changed.');
        }
        if ($excluded && mb_strlen(trim((string) $reason)) < 5) {
            throw new RuntimeException('Give the reason for excluding '.$line->employee?->name.'.');
        }
        $line->update(['excluded' => $excluded, 'exclude_reason' => $excluded ? trim($reason) : null]);
        self::compute($dist);
        ActivityLogger::log('servicecharge', 'exclude', ($excluded ? 'Excluded ' : 'Re-included ').$line->employee?->name." in {$dist->dist_no}".($excluded ? " ({$reason})" : ''), $dist);

        return $dist->fresh('lines');
    }

    public static function approve(ScDistribution $dist, User $u): ScDistribution
    {
        if ($dist->status !== 'draft') {
            throw new RuntimeException('Only a draft distribution can be approved.');
        }
        if ((int) $dist->created_by === (int) $u->id) {
            throw new RuntimeException('The person who prepared the distribution cannot approve it.');
        }
        self::compute($dist);
        $dist->refresh();
        if ((float) $dist->distributed <= 0) {
            throw new RuntimeException('Nothing to distribute for this period.');
        }
        if ((float) $dist->retained + (float) $dist->distributed > self::available($dist->period_end->toDateString(), $dist) + 0.005) {
            throw new RuntimeException('The pool is larger than the undistributed service charge on 2110 ('.money(self::available($dist->period_end->toDateString(), $dist)).').');
        }
        $dist->update(['status' => 'approved', 'approved_by' => $u->id, 'approved_on' => bdate()->toDateString()]);
        ActivityLogger::log('servicecharge', 'approve', "Approved {$dist->dist_no}: ".money($dist->distributed).' to '.$dist->lines()->where('amount', '>', 0)->count().' staff, house '.money($dist->retained), $dist);

        return $dist;
    }

    /** Add each share as a taxable earning (charged to 2110) to the employee's payslip in a draft run. */
    public static function postToPayroll(ScDistribution $dist, PayrollRun $run, User $u): ScDistribution
    {
        if ($dist->status !== 'approved') {
            throw new RuntimeException('Approve the distribution first.');
        }
        if ($run->isLocked() || $run->status !== 'draft') {
            throw new RuntimeException("Payroll run {$run->run_no} is {$run->status} – choose a draft run.");
        }
        if ($run->period_end->toDateString() < $dist->period_end->toDateString()) {
            throw new RuntimeException('Choose a payroll run that ends after the distribution period.');
        }
        $lines = $dist->lines()->with('employee')->where('amount', '>', 0)->get();
        $slips = Payslip::where('payroll_run_id', $run->id)->whereIn('employee_id', $lines->pluck('employee_id'))->get()->keyBy('employee_id');
        $missing = $lines->filter(fn ($l) => ! isset($slips[$l->employee_id]))->map(fn ($l) => $l->employee?->name);
        if ($missing->isNotEmpty()) {
            throw new RuntimeException("No payslip in {$run->run_no} for: ".$missing->implode(', ').' – regenerate the run or pay them separately.');
        }
        $name = 'Service charge – '.$dist->label();
        DB::transaction(function () use ($dist, $run, $lines, $slips, $name) {
            foreach ($lines as $l) {
                $slip = $slips[$l->employee_id];
                $pl = PayslipLine::create(['payslip_id' => $slip->id, 'code' => 'SVC', 'name' => mb_substr($name, 0, 80), 'kind' => 'earning',
                    'amount' => $l->amount, 'taxable' => true, 'is_manual' => true, 'expense_head_id' => AccountHead::sys('service_charge'), 'sort_order' => 96]);
                $l->update(['payslip_line_id' => $pl->id]);
                PayrollService::recalcSlip($slip->fresh());
            }
            $dist->update(['status' => 'posted', 'payroll_run_id' => $run->id, 'posted_at' => now()]);
        });
        ActivityLogger::log('servicecharge', 'post', "Posted {$dist->dist_no} (".money($dist->distributed).") to payroll {$run->run_no}", $dist);

        return $dist;
    }

    public static function cancel(ScDistribution $dist, string $reason, User $u): ScDistribution
    {
        if ($dist->status === 'cancelled') {
            throw new RuntimeException('Already cancelled.');
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw new RuntimeException('Give a reason for cancelling.');
        }
        DB::transaction(function () use ($dist, $reason) {
            if ($dist->status === 'posted') {
                $run = $dist->run;
                if (! $run || $run->isLocked() || $run->status !== 'draft') {
                    throw new RuntimeException("Payroll run {$run?->run_no} is {$run?->status} – the shares have been paid. Correct it in a later distribution.");
                }
                foreach ($dist->lines()->whereNotNull('payslip_line_id')->with('payslipLine.payslip')->get() as $l) {
                    $slip = $l->payslipLine?->payslip;
                    $l->payslipLine?->delete();
                    $l->update(['payslip_line_id' => null]);
                    if ($slip) {
                        PayrollService::recalcSlip($slip->fresh());
                    }
                }
            }
            $dist->update(['status' => 'cancelled', 'cancel_reason' => trim($reason), 'payroll_run_id' => null]);
        });
        ActivityLogger::log('servicecharge', 'cancel', "Cancelled {$dist->dist_no} ({$reason})", $dist);

        return $dist;
    }

    /** GL on approval: Dr 2110 / Cr 4095 for the house retention (the staff shares clear 2110 through payroll). */
    public static function forRetention(ScDistribution $dist): ?array
    {
        if (! in_array($dist->status, ['approved', 'posted'], true) || (float) $dist->retained <= 0 || ! $dist->approved_on) {
            return null;
        }
        $r = (float) $dist->retained;

        return ['date' => $dist->approved_on->toDateString(), 'reference' => $dist->dist_no, 'narration' => "Service charge house retention {$dist->retention_pct}% – {$dist->label()}",
            'lines' => [[AccountHead::sys('service_charge'), $r, $dist->dist_no], [AccountHead::sys('sc_retained'), -$r, $dist->label()]]];
    }

    public static function attentionCount(): int
    {
        return ScDistribution::where('status', 'draft')->count() + ScDistribution::where('status', 'approved')->count();
    }
}
