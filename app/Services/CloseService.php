<?php

namespace App\Services;

use App\Models\AccountHead;
use App\Models\Accrual;
use App\Models\CloseEvent;
use App\Models\ClosePeriod;
use App\Models\CloseTask;
use App\Models\FiscalYearClose;
use App\Models\GlJournal;
use App\Models\GlLine;
use App\Models\PeriodPosting;
use App\Models\RecurringJournal;
use App\Models\Setting;
use App\Models\User;
use App\Support\Numbering;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * v5.6 Financial close.
 *
 * Each calendar month is an accounting period. Its checklist combines automatic checks against every sub-ledger
 * (night audit, income audit, GL posting, control accounts, bank reconciliation, payroll, depreciation …) with
 * manual sign-offs. The preparer submits a clean checklist; a different user approves, which closes the period
 * and moves the GL lock date to month end. Only the latest closed period can be reopened, again four-eyes.
 * Closing the last month of a fiscal year posts the closing entry: every income and expense account is cleared
 * to retained earnings (the entry is flagged "closing" so P&L-type totals leave it out).
 *
 * Period-end postings come from approved schedules (accrued expenses reversed on the 1st, prepaid expenses and
 * deferred income spread over months) and recurring journal templates. They are posted by the "Run period-end
 * postings" button, or automatically when the night audit closes the last day of the month.
 */
class CloseService
{
    /** automatic checks: key => [title, area] */
    public const CHECKS = [
        'night_audit' => ['Every business day of the month closed by the night audit', 'Front office'],
        'income_audit' => ['Income audit closed for every day of the month', 'Front office'],
        'gl_posted' => ['General ledger posted to month end with no posting errors', 'Ledger'],
        'period_end' => ['Accruals, prepayments, deferred income and recurring journals posted', 'Ledger'],
        'controls' => ['Sub-ledgers agree to their GL control accounts', 'Ledger'],
        'trial_balance' => ['Trial balance and balance sheet balance', 'Ledger'],
        'suspense' => ['Suspense account cleared', 'Ledger'],
        'bank_rec' => ['Bank accounts reconciled to month end', 'Cash & bank'],
        'treasury' => ['Cash in transit, cheques in hand and post-dated cheques followed up', 'Cash & bank'], // v5.7
        'owner_fees' => ['Management fees under the hotel management agreement accrued', 'Owner reporting'], // v5.8
        'concierge' => ['Activity bookings of the month completed, cancelled or marked no-show', 'Concierge'], // v5.9
        'claims' => ['No expense claims awaiting approval', 'Payables'],
        'ap_hold' => ['No supplier bills on hold', 'Payables'],
        'depreciation' => ['Depreciation run posted for the month', 'Fixed assets'],
        'payroll' => ['Payroll for the month approved', 'Payroll'],
        'statutory' => ['PAYE and SSNIT for the previous month remitted (GRA / SSNIT)', 'Payroll'],
        'stock_take' => ['Stores counted – stock take posted', 'Stores'],
    ];

    public const DEFAULT_MANUAL = [
        ['Receivables', 'Review the city-ledger ageing and provide for doubtful debts'],
        ['Tax', 'Agree VAT / NHIL / GETFund and tourism-levy control accounts to the GRA and GTA returns'],
        ['Payables', 'Accrue goods received not yet billed and bills received after month end'],
        ['Reporting', 'Management accounts pack reviewed by the general manager'],
    ];

    public const DEFAULTS = ['close_due_days' => '10', 'close_auto_post' => '1'];

    // ───────────────────────── settings & calendar ─────────────────────────

    public static function cfg(): array
    {
        $manual = json_decode((string) setting('close_manual_tasks', ''), true);

        return [
            'start' => self::startPeriod(),
            'due_days' => max(1, (int) setting('close_due_days', self::DEFAULTS['close_due_days'])),
            'auto_post' => setting('close_auto_post', self::DEFAULTS['close_auto_post']) !== '0',
            'manual' => is_array($manual) && $manual ? $manual : self::DEFAULT_MANUAL,
        ];
    }

    public static function saveSettings(array $d, User $by): void
    {
        $map = ['close_due_days' => (string) max(1, min(60, (int) ($d['due_days'] ?? 10))), 'close_auto_post' => ! empty($d['auto_post']) ? '1' : '0'];
        if (! empty($d['start']) && ! ClosePeriod::where('status', 'closed')->exists()) {
            $map['close_start'] = $d['start'];
        }
        if (isset($d['manual'])) {
            $rows = collect(preg_split('/\r?\n/', (string) $d['manual']))->map(fn ($l) => trim($l))->filter()->take(15)
                ->map(fn ($l) => str_contains($l, '|') ? array_map('trim', explode('|', $l, 2)) : ['General', $l])
                ->map(fn ($r) => [mb_substr($r[0] ?: 'General', 0, 30), mb_substr($r[1], 0, 150)])->values()->all();
            $map['close_manual_tasks'] = json_encode($rows ?: self::DEFAULT_MANUAL);
        }
        foreach ($map as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        Setting::$version++;
        ActivityLogger::log('close', 'settings', 'Period close settings: close due '.$map['close_due_days'].' days after month end, auto-post '.($map['close_auto_post'] === '1' ? 'on' : 'off'));
    }

    /** First period managed by the close (YYYY-MM): the setting, else the month of the first journal, else this month. */
    public static function startPeriod(): string
    {
        $s = (string) setting('close_start', '');
        if (preg_match('/^\d{4}-\d{2}$/', $s)) {
            return $s;
        }
        $first = GlJournal::min('date');

        return Carbon::parse($first ?: bdate())->format('Y-m');
    }

    public static function currentPeriod(): string
    {
        return bdate()->format('Y-m');
    }

    public static function fiscal(string $period): array
    {
        $d = Carbon::parse($period.'-01');
        $fy = BudgetService::fyFor($d);
        $start = BudgetService::cfg()['fy_start'];

        return [$fy, (($d->month - $start + 12) % 12) + 1];
    }

    public static function fyEnd(int $fy): string
    {
        return Carbon::create($fy, BudgetService::cfg()['fy_start'], 1)->addMonthsNoOverflow(11)->endOfMonth()->toDateString();
    }

    public static function fyStart(int $fy): string
    {
        return Carbon::create($fy, BudgetService::cfg()['fy_start'], 1)->toDateString();
    }

    public static function prev(string $period): string
    {
        return Carbon::parse($period.'-01')->subMonthNoOverflow()->format('Y-m');
    }

    public static function next(string $period): string
    {
        return Carbon::parse($period.'-01')->addMonthNoOverflow()->format('Y-m');
    }

    public static function valid(string $period): bool
    {
        return (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) && $period >= self::startPeriod() && $period <= self::currentPeriod();
    }

    /** The period row, created on first use (with its checklist while it is open). */
    public static function period(string $period, bool $withTasks = true): ClosePeriod
    {
        [$fy, $fm] = self::fiscal($period);
        $p = ClosePeriod::firstOrCreate(['period' => $period], ['fiscal_year' => $fy, 'fiscal_month' => $fm, 'status' => 'open']);
        if ($withTasks && $p->status !== 'closed') {
            self::ensureTasks($p);
        }

        return $p;
    }

    /** @return Collection<int, ClosePeriod> every managed period, newest first */
    public static function periods(): Collection
    {
        $rows = ClosePeriod::withCount(['tasks', 'tasks as cleared_count' => fn ($q) => $q->whereIn('status', CloseTask::CLEARED)])->get()->keyBy('period');
        $out = collect();
        for ($p = self::startPeriod(), $cur = self::currentPeriod(), $i = 0; $p <= $cur && $i < 240; $p = self::next($p), $i++) {
            $out->push($rows[$p] ?? self::period($p, false));
        }

        return $out->reverse()->values();
    }

    public static function ensureTasks(ClosePeriod $p): void
    {
        $have = $p->tasks()->pluck('key')->all();
        $sort = 0;
        foreach (self::CHECKS as $key => [$title, $area]) {
            $sort += 10;
            if (! in_array($key, $have, true)) {
                CloseTask::create(['close_period_id' => $p->id, 'key' => $key, 'title' => $title, 'area' => $area, 'kind' => 'auto', 'sort' => $sort]);
            }
        }
        foreach (self::cfg()['manual'] as $i => [$area, $title]) {
            $key = 'manual_'.($i + 1);
            if (! in_array($key, $have, true)) {
                CloseTask::create(['close_period_id' => $p->id, 'key' => $key, 'title' => $title, 'area' => $area, 'kind' => 'manual', 'sort' => 500 + $i]);
            }
        }
    }

    public static function addTask(ClosePeriod $p, string $area, string $title, User $by): CloseTask
    {
        self::assertOpen($p);
        $n = $p->tasks()->where('key', 'like', 'custom_%')->count() + 1;
        $t = CloseTask::create(['close_period_id' => $p->id, 'key' => 'custom_'.$n, 'title' => $title, 'area' => $area ?: 'General', 'kind' => 'manual', 'sort' => 800 + $n]);
        self::log($p, 'task', 'Added task: '.$title, $by);

        return $t;
    }

    public static function log(ClosePeriod $p, string $action, ?string $note = null, ?User $by = null): void
    {
        CloseEvent::create(['close_period_id' => $p->id, 'action' => $action, 'note' => $note ? mb_substr($note, 0, 255) : null, 'user_id' => $by?->id ?? auth()->id(), 'created_at' => now()]);
    }

    // ───────────────────────── automatic checks ─────────────────────────

    public static function runChecks(ClosePeriod $p, ?User $by = null, bool $log = true): array
    {
        self::ensureTasks($p);
        $out = [];
        foreach ($p->tasks()->where('kind', 'auto')->get() as $t) {
            try {
                [$st, $msg] = self::check($t->key, $p);
            } catch (\Throwable $e) {
                report($e);
                [$st, $msg] = ['fail', 'Check could not run: '.mb_substr($e->getMessage(), 0, 200)];
            }
            // a waiver stands while the check still fails; a check that now passes replaces it
            $status = ($t->status === 'waived' && in_array($st, ['fail', 'warn'], true)) ? 'waived' : $st;
            $t->forceFill(['status' => $status, 'result' => mb_substr($msg, 0, 500), 'checked_at' => now()])->save();
            $out[$t->key] = $st;
        }
        $p->forceFill(['checked_at' => now()])->save();
        if ($log) {
            $fails = collect($out)->filter(fn ($s) => $s === 'fail')->count();
            self::log($p, 'checked', count($out).' checks: '.($fails ? $fails.' failed' : 'all passed or not applicable'), $by);
        }

        return $out;
    }

    /** @return array{0: string, 1: string} [status, message] */
    public static function check(string $key, ClosePeriod $p): array
    {
        $S = $p->start()->toDateString();
        $E = $p->end()->toDateString();
        $mon = $p->start()->format('M Y');

        switch ($key) {
            case 'night_audit':
                $bd = bdate()->toDateString();
                if ($bd > $E) {
                    return ['pass', 'All '.$p->end()->day.' days closed – business date is '.dmy($bd).'.'];
                }
                $left = Carbon::parse(max($bd, $S))->diffInDays(Carbon::parse($E)) + 1;

                return ['fail', 'Business date is '.dmy($bd).' – '.(int) $left.' day(s) of '.$mon.' still to be closed by the night audit.'];

            case 'income_audit':
                $first = \App\Models\AuditDay::min('business_date');
                if (! $first || Carbon::parse($first)->toDateString() > $E) {
                    return ['na', 'Income audit was not in use in '.$mon.'.'];
                }
                $from = max($S, Carbon::parse($first)->toDateString());
                $to = min($E, bdate()->copy()->subDay()->toDateString());
                if ($to < $from) {
                    return ['fail', 'No business day of '.$mon.' has been audited yet.'];
                }
                $closed = \App\Models\AuditDay::where('status', 'closed')->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)->pluck('business_date')->map(fn ($d) => Carbon::parse($d)->toDateString())->all();
                $missing = [];
                for ($d = Carbon::parse($from); $d->toDateString() <= $to; $d->addDay()) {
                    if (! in_array($d->toDateString(), $closed, true)) {
                        $missing[] = $d->format('d M');
                    }
                }
                if ($to < $E) {
                    $missing[] = 'days after '.Carbon::parse($to)->format('d M');
                }

                return $missing ? ['fail', count($missing).' day(s) not closed by the income auditor: '.implode(', ', array_slice($missing, 0, 6)).(count($missing) > 6 ? ' …' : '').'.']
                    : ['pass', 'Every day from '.Carbon::parse($from)->format('d M').' to '.Carbon::parse($to)->format('d M').' signed and closed.'];

            case 'gl_posted':
                $cut = setting('gl_cutoff');
                $errs = json_decode((string) setting('gl_last_errors', '[]'), true) ?: [];
                if (! $cut || $cut < $E) {
                    return ['fail', 'Ledger posted only up to '.($cut ? dmy($cut) : '—').' – it posts each day the night audit closes.'];
                }

                return $errs ? ['fail', count($errs).' source document(s) could not be posted on the last sync, e.g. '.mb_substr((string) $errs[0], 0, 160)]
                    : ['pass', 'Posted up to '.dmy($cut).' with no errors.'];

            case 'period_end':
                $due = self::due($p->period);
                $pending = Accrual::where('status', 'pending')->where('start_period', '<=', $p->period)->count()
                    + RecurringJournal::where('status', 'pending')->where('start_period', '<=', $p->period)->count();
                if ($due->count()) {
                    return ['fail', $due->count().' posting(s) due ('.money($due->sum('amount')).') – run the period-end postings.'];
                }
                $made = PeriodPosting::where('period', $p->period)->where('kind', 'post')->count();

                return $pending ? ['warn', ($made ? $made.' posted. ' : '').$pending.' schedule(s) / template(s) still awaiting approval.']
                    : ($made ? ['pass', $made.' period-end posting(s) made.'] : ['na', 'No schedules or recurring journals fall in '.$mon.'.']);

            case 'controls':
                $bad = [];
                $warn = [];
                foreach (LedgerService::controls($E) as $c) {
                    $diff = round((float) $c['sub'] - (float) $c['gl'], 2);
                    if (abs($diff) >= 0.01) {
                        if ($c['strict']) {
                            $bad[] = $c['label'].' (difference '.money($diff).')';
                        } else {
                            $warn[] = $c['label'].' (difference '.money($diff).')';
                        }
                    }
                }
                if ($bad) {
                    return ['fail', 'Out of balance: '.implode('; ', array_slice($bad, 0, 3)).(count($bad) > 3 ? ' …' : '')];
                }

                return $warn ? ['warn', 'Strict controls agree. Timing differences: '.implode('; ', array_slice($warn, 0, 3))] : ['pass', 'Every sub-ledger agrees to its control account.'];

            case 'trial_balance':
                $t = LedgerService::totals(null, $E);
                $dr = round($t->sum('dr'), 2);
                $cr = round($t->sum('cr'), 2);
                $bs = GlReportService::balanceSheet($E);
                if (abs($dr - $cr) >= 0.01 || abs($bs['difference']) >= 0.01) {
                    return ['fail', 'Debits '.money($dr).' vs credits '.money($cr).'; balance sheet difference '.money($bs['difference']).'.'];
                }

                return ['pass', 'Debits = credits = '.money($dr).'; assets '.money($bs['assets']).' = liabilities + equity.'];

            case 'suspense':
                $bal = LedgerService::balance('suspense', $E);

                return abs($bal) < 0.01 ? ['pass', 'Nothing left in suspense.'] : ['fail', money($bal).' in the suspense account at '.dmy($E).' – clear it to the right accounts.'];

            case 'bank_rec':
                $accts = \App\Models\BankStatement::where('period_from', '<=', $E)->distinct()->pluck('account_head_id');
                if ($accts->isEmpty()) {
                    return ['na', 'Bank reconciliation not in use for '.$mon.'.'];
                }
                $missing = $accts->reject(fn ($a) => \App\Models\BankStatement::where('account_head_id', $a)->where('status', 'reconciled')->whereDate('period_to', '>=', $E)->exists());
                if ($missing->isEmpty()) {
                    return ['pass', $accts->count().' account(s) reconciled to '.dmy($E).'.'];
                }
                $names = AccountHead::whereIn('id', $missing)->pluck('name')->implode(', ');

                return ['fail', 'Not reconciled to '.dmy($E).': '.$names.'.'];

            case 'treasury':
                if (! \App\Models\TreasuryTransfer::where('date', '<=', $E)->exists() && ! \App\Models\ChequeReceived::where('received_on', '<=', $E)->exists()) {
                    return ['na', 'No bankings, transfers or cheques recorded in Treasury by '.dmy($E).'.'];
                }
                $notes = [];
                if (($tr = TreasuryService::inTransit($E)) > 0) {
                    $notes[] = money($tr).' in transit at month end – confirm it was credited in the first days of the next month';
                }
                $pdc = \App\Models\ChequeReceived::where('status', 'pdc')->whereDate('cheque_date', '<=', $E)->count();
                if ($pdc) {
                    $notes[] = $pdc.' post-dated cheque(s) due by '.dmy($E).' not yet banked';
                }
                $old = \App\Models\ChequeReceived::whereNotNull('recognised_on')->whereDate('recognised_on', '<=', Carbon::parse($E)->subDays(7)->toDateString())
                    ->where(fn ($q) => $q->whereNull('left_on')->orWhereDate('left_on', '>', $E))->count();
                if ($old) {
                    $notes[] = $old.' cheque(s) held more than a week at month end';
                }

                return $notes ? ['warn', ucfirst(implode('; ', $notes)).'.'] : ['pass', 'Nothing in transit, no cheques waiting to be banked at '.dmy($E).'.'];

            case 'owner_fees': // v5.8 – warn only: the owner pack needs the month's fees
                $ag = OwnerService::agreementFor(Carbon::parse($p->period.'-01'));
                if (! $ag) {
                    return ['na', 'No management agreement covers this month.'];
                }
                $fee = \App\Models\OwnerFee::where('period', $p->period)->where('status', '!=', 'reversed')->first();

                if ($fee && abs(($now = \App\Services\UsaliService::month($p->period))['gop'] - (float) $fee->gop) >= 1) {
                    return ['warn', 'Fees were accrued on GOP of '.money((float) $fee->gop).'; the books now show '.money($now['gop']).' after later entries. The difference is trued up in the next month\'s incentive fee.'];
                }

                return $fee ? ['pass', 'Base fee '.money((float) $fee->base_fee).', incentive fee '.money((float) $fee->incentive_fee).' and FF&E reserve '.money((float) $fee->ffe_amount).' accrued.']
                    : ['warn', 'Management fees for '.Carbon::parse($p->period.'-01')->format('F Y').' are not accrued – accrue them under Owner Reporting › Management Fees before closing the month.'];

            case 'concierge': // v5.9 – warn only: open activity bookings dated in the month keep commission out of the books
                $S = Carbon::parse($p->period.'-01')->toDateString();
                $open = \App\Models\ActivityBooking::whereIn('status', \App\Models\ActivityBooking::OPEN)->whereDate('date', '>=', $S)->whereDate('date', '<=', $E)->count();
                if (! \App\Models\ActivityBooking::whereBetween('date', [$S, $E])->exists()) {
                    return ['na', 'No activity bookings this month.'];
                }

                return $open ? ['warn', $open.' activity booking(s) dated in the month are still awaiting the vendor, waitlisted or confirmed – complete, cancel or mark them no-show so the commission is in the right month.']
                    : ['pass', 'Every activity booking of the month is closed; commission '.money((float) \App\Models\ActivityBooking::whereBetween('recognised_on', [$S, $E])->sum('commission')).' recognised.'];

            case 'claims':
                $n = \App\Models\ExpenseClaim::where('status', 'submitted')->where('submitted_at', '<=', $E.' 23:59:59')->count();

                return $n ? ['fail', $n.' expense claim(s) submitted by '.dmy($E).' still awaiting approval.'] : ['pass', 'No claims waiting.'];

            case 'ap_hold':
                $q = \App\Models\SupplierInvoice::where('status', 'on_hold')->whereDate('doc_date', '<=', $E);

                return ($n = $q->count()) ? ['warn', $n.' supplier bill(s) on hold for price / quantity variances ('.money($q->sum('total')).') – accrue if the goods were received.'] : ['pass', 'No bills on hold.'];

            case 'depreciation':
                if (! \App\Models\Asset::where('status', '!=', 'disposed')->exists()) {
                    return ['na', 'No fixed assets in the register.'];
                }
                $run = \App\Models\AssetDepRun::where('period_end', $E)->first();
                if ($run && $run->status === 'posted') {
                    return ['pass', 'Run '.$run->run_no.' posted – '.money($run->total).'.'];
                }

                return ['fail', $run ? 'Run '.$run->run_no.' is '.$run->status.'.' : 'No depreciation run for '.$mon.' (Fixed Assets › Depreciation).'];

            case 'payroll':
                if (! \App\Models\PayrollRun::exists()) {
                    return ['na', 'Payroll is not run in the system.'];
                }
                $run = \App\Models\PayrollRun::whereDate('period_start', '<=', $E)->whereDate('period_end', '>=', $S)->orderByDesc('id')->first();
                if (! $run) {
                    return ['fail', 'No payroll run for '.$mon.'.'];
                }

                return in_array($run->status, ['approved', 'paid'], true) ? ['pass', 'Payroll '.$run->run_no.' '.$run->status.' – net '.money($run->net).'.'] : ['fail', 'Payroll '.$run->run_no.' is still '.$run->status.'.'];

            case 'statutory':
                $prev = self::prev($p->period);
                $pr = \App\Models\PayrollRun::whereIn('status', ['approved', 'paid'])->whereDate('period_end', '>=', $prev.'-01')->whereDate('period_end', '<=', Carbon::parse($prev.'-01')->endOfMonth()->toDateString())->first();
                if (! $pr) {
                    return ['na', 'No approved payroll for '.Carbon::parse($prev.'-01')->format('M Y').'.'];
                }
                $kinds = \App\Models\StatutoryRemittance::where('period', $prev)->whereNull('cancelled_at')->pluck('kind')->all();
                $miss = array_values(array_diff(['paye', 'ssnit'], $kinds));

                return $miss ? ['fail', strtoupper(implode(' and ', $miss)).' for '.Carbon::parse($prev.'-01')->format('M Y').' not remitted (due by the 15th / 14th of '.$mon.').']
                    : ['pass', 'PAYE and SSNIT for '.Carbon::parse($prev.'-01')->format('M Y').' remitted.'];

            case 'stock_take':
                if (! \App\Models\InventoryItem::exists()) {
                    return ['na', 'Stores not in use.'];
                }
                $st = \App\Models\StockTake::where('status', 'posted')->whereDate('count_date', '>=', $S)->whereDate('count_date', '<=', Carbon::parse($E)->addDays(5)->toDateString())->orderByDesc('count_date')->first();

                return $st ? ['pass', 'Stock take '.$st->take_no.' posted ('.dmy($st->count_date).').'] : ['warn', 'No stock take posted for '.$mon.' – stores are valued at the book balance.'];
        }

        return ['na', 'Unknown check.'];
    }

    // ───────────────────────── manual tasks ─────────────────────────

    public static function completeTask(CloseTask $t, User $by, ?string $note): void
    {
        self::assertOpen($t->period);
        if ($t->kind !== 'manual') {
            throw new \RuntimeException('Automatic checks are completed by running the checks.');
        }
        $t->forceFill(['status' => 'done', 'note' => $note ? mb_substr($note, 0, 255) : null, 'done_by' => $by->id, 'done_at' => now()])->save();
        self::log($t->period, 'task', 'Done: '.$t->title.($note ? ' – '.$note : ''), $by);
    }

    public static function waiveTask(CloseTask $t, User $by, string $reason): void
    {
        self::assertOpen($t->period);
        if ($t->cleared() && $t->status !== 'waived') {
            throw new \RuntimeException('This task has already passed – nothing to waive.');
        }
        $t->forceFill(['status' => 'waived', 'note' => mb_substr($reason, 0, 255), 'done_by' => $by->id, 'done_at' => now()])->save();
        self::log($t->period, 'task', 'Waived: '.$t->title.' – '.$reason, $by);
    }

    public static function resetTask(CloseTask $t, User $by): void
    {
        self::assertOpen($t->period);
        $t->forceFill(['status' => 'pending', 'note' => null, 'done_by' => null, 'done_at' => null])->save();
        self::log($t->period, 'task', 'Reset: '.$t->title, $by);
    }

    protected static function assertOpen(ClosePeriod $p): void
    {
        if ($p->status !== 'open') {
            throw new \RuntimeException($p->label().' is '.strtolower(ClosePeriod::STATUSES[$p->status]).' – '.($p->status === 'review' ? 'send it back to change the checklist.' : 'reopen it first.'));
        }
    }

    // ───────────────────────── close workflow (four eyes) ─────────────────────────

    public static function lastClosed(): ?ClosePeriod
    {
        return ClosePeriod::where('status', 'closed')->orderByDesc('period')->first();
    }

    public static function lastClosedEnd(): ?string
    {
        return ($p = self::lastClosed()) ? $p->end()->toDateString() : null;
    }

    /** reasons the period cannot be submitted / closed yet */
    public static function blockers(ClosePeriod $p): array
    {
        $b = [];
        if (bdate()->toDateString() <= $p->end()->toDateString()) {
            $b[] = 'The month has not ended – the night audit must close '.dmy($p->end()).' first.';
        }
        if ($p->period > self::startPeriod()) {
            $prev = ClosePeriod::where('period', self::prev($p->period))->first();
            if (! $prev || $prev->status !== 'closed') {
                $b[] = 'Close '.Carbon::parse(self::prev($p->period).'-01')->format('F Y').' first – periods close in order.';
            }
        }
        $open = $p->tasks()->whereNotIn('status', CloseTask::CLEARED)->get();
        if ($open->count()) {
            $b[] = $open->count().' checklist item(s) not cleared: '.$open->take(3)->pluck('title')->implode('; ').($open->count() > 3 ? ' …' : '');
        }

        return $b;
    }

    public static function submit(ClosePeriod $p, User $by, ?string $note = null): void
    {
        self::assertOpen($p);
        self::runChecks($p, $by);
        if ($b = self::blockers($p)) {
            throw new \RuntimeException(implode(' ', $b));
        }
        $p->forceFill(['status' => 'review', 'prepared_by' => $by->id, 'prepared_at' => now(), 'prepare_note' => $note ? mb_substr($note, 0, 255) : null, 'reject_reason' => null])->save();
        self::log($p, 'submitted', $note, $by);
        ActivityLogger::log('close', 'submit', $p->label().' submitted for close', $p);
    }

    public static function reject(ClosePeriod $p, User $by, string $reason): void
    {
        if ($p->status !== 'review') {
            throw new \RuntimeException($p->label().' is not awaiting approval.');
        }
        $p->forceFill(['status' => 'open', 'reject_reason' => mb_substr($reason, 0, 255)])->save();
        self::log($p, 'rejected', $reason, $by);
        ActivityLogger::log('close', 'reject', $p->label().' sent back: '.$reason, $p);
    }

    public static function approve(ClosePeriod $p, User $by, ?string $note = null): void
    {
        if ($p->status !== 'review') {
            throw new \RuntimeException($p->label().' has not been submitted for approval.');
        }
        if ((int) $p->prepared_by === (int) $by->id) {
            throw new \RuntimeException('Four-eyes rule: the person who prepared the close cannot approve it.');
        }
        self::runChecks($p, $by, false);
        if ($b = self::blockers($p)) {
            throw new \RuntimeException('The checklist changed since it was submitted: '.implode(' ', $b));
        }
        DB::transaction(function () use ($p, $by, $note) {
            $lockBefore = LedgerService::lockDate();
            $end = $p->end()->toDateString();
            if ($p->isYearEnd()) {
                self::postYearEnd($p, $by);
            }
            if (! $lockBefore || $lockBefore < $end) {
                Setting::updateOrCreate(['key' => 'gl_lock_date'], ['value' => $end]);
                Setting::$version++;
            }
            $p->forceFill(['status' => 'closed', 'closed_by' => $by->id, 'closed_at' => now(), 'close_note' => $note ? mb_substr($note, 0, 255) : null, 'lock_before' => $lockBefore])->save();
            self::log($p, 'closed', 'Books locked up to '.dmy($end).($note ? ' – '.$note : ''), $by);
        });
        ActivityLogger::log('close', 'close', $p->label().' closed; GL locked up to '.dmy($p->end()), $p);
    }

    public static function requestReopen(ClosePeriod $p, User $by, string $reason): void
    {
        if ($p->status !== 'closed') {
            throw new \RuntimeException($p->label().' is not closed.');
        }
        if (self::lastClosed()?->id !== $p->id) {
            throw new \RuntimeException('Only the most recently closed period ('.self::lastClosed()?->label().') can be reopened.');
        }
        if ($p->reopen_requested_at) {
            throw new \RuntimeException('A reopen request is already waiting for approval.');
        }
        $p->forceFill(['reopen_requested_by' => $by->id, 'reopen_requested_at' => now(), 'reopen_reason' => mb_substr($reason, 0, 255)])->save();
        self::log($p, 'reopen_req', $reason, $by);
        ActivityLogger::log('close', 'reopen_request', 'Reopen of '.$p->label().' requested: '.$reason, $p);
    }

    public static function approveReopen(ClosePeriod $p, User $by): void
    {
        if ($p->status !== 'closed' || ! $p->reopen_requested_at) {
            throw new \RuntimeException('There is no reopen request for '.$p->label().'.');
        }
        if ((int) $p->reopen_requested_by === (int) $by->id) {
            throw new \RuntimeException('Four-eyes rule: a reopen request must be approved by someone else.');
        }
        DB::transaction(function () use ($p, $by) {
            $prevEnd = ClosePeriod::where('status', 'closed')->where('period', '<', $p->period)->exists() ? Carbon::parse(self::prev($p->period).'-01')->endOfMonth()->toDateString() : null;
            $to = $p->lock_before ? $p->lock_before->toDateString() : $prevEnd;
            Setting::updateOrCreate(['key' => 'gl_lock_date'], ['value' => $to ?? '']);
            Setting::$version++;
            if ($p->isYearEnd()) {
                self::reverseYearEnd($p);
            }
            $reason = $p->reopen_reason;
            $p->forceFill(['status' => 'open', 'closed_by' => null, 'closed_at' => null, 'close_note' => null, 'prepared_by' => null, 'prepared_at' => null,
                'reopen_requested_by' => null, 'reopen_requested_at' => null, 'reopen_reason' => null, 'reopen_count' => $p->reopen_count + 1])->save();
            self::ensureTasks($p);
            self::log($p, 'reopened', 'Approved – '.$reason.'. GL lock moved back to '.($to ? dmy($to) : 'none'), $by);
        });
        ActivityLogger::log('close', 'reopen', $p->label().' reopened', $p);
    }

    public static function denyReopen(ClosePeriod $p, User $by, string $reason): void
    {
        if (! $p->reopen_requested_at) {
            throw new \RuntimeException('There is no reopen request for '.$p->label().'.');
        }
        $p->forceFill(['reopen_requested_by' => null, 'reopen_requested_at' => null, 'reopen_reason' => null])->save();
        self::log($p, 'reopen_denied', $reason, $by);
    }

    // ───────────────────────── period-end postings ─────────────────────────

    /**
     * What is still to post up to this period: every schedule month / recurring due period ≤ $period not posted yet.
     *
     * @return Collection<int, array{source: string, model: Accrual|RecurringJournal, period: string, amount: float}>
     */
    public static function due(string $period): Collection
    {
        $floor = self::startPeriod();
        $out = collect();
        foreach (Accrual::where('status', 'active')->where('start_period', '<=', $period)->get() as $a) {
            $done = PeriodPosting::where('source', 'accrual')->where('source_id', $a->id)->where('kind', 'post')->pluck('period')->all();
            foreach ($a->schedule() as $m => $amt) {
                if ($m <= $period && ! in_array($m, $done, true)) {
                    $out->push(['source' => 'accrual', 'model' => $a, 'period' => $m, 'amount' => $amt]);
                }
            }
        }
        foreach (RecurringJournal::where('status', 'active')->where('start_period', '<=', $period)->get() as $r) {
            $done = PeriodPosting::where('source', 'recurring')->where('source_id', $r->id)->where('kind', 'post')->pluck('period')->all();
            for ($m = max($r->start_period, $floor), $i = 0; $m <= $period && $i < 120; $m = self::next($m), $i++) {
                if ($r->dueIn($m) && ! in_array($m, $done, true)) {
                    $out->push(['source' => 'recurring', 'model' => $r, 'period' => $m, 'amount' => (float) $r->total]);
                }
            }
        }

        return $out->sortBy('period')->values();
    }

    /** Post everything due up to the period. Months whose own date is locked are caught up on this period's month end. */
    public static function runPostings(ClosePeriod $p, ?User $by = null, bool $auto = false): array
    {
        self::assertOpen($p);
        $end = $p->end()->toDateString();
        $lock = LedgerService::lockDate();
        if ($lock && $end <= $lock) {
            throw new \RuntimeException('The ledger is locked up to '.dmy($lock).'.');
        }
        $made = 0;
        $total = 0.0;
        foreach (self::due($p->period) as $d) {
            $date = Carbon::parse($d['period'].'-01')->endOfMonth()->toDateString();
            if ($lock && $date <= $lock) {
                $date = $end; // catch-up on the open month
            }
            $d['source'] === 'accrual' ? self::postAccrual($d['model'], $d['period'], $d['amount'], $date, $by) : self::postRecurring($d['model'], $d['period'], $date, $by);
            $made++;
            $total += $d['amount'];
        }
        if ($made) {
            self::log($p, 'posted', $made.' journal(s), '.money($total).($auto ? ' – posted automatically by the night audit' : ''), $by);
        }

        return ['made' => $made, 'total' => round($total, 2)];
    }

    /** Night audit hook: when the last day of a month is closed, post that month's schedules. Never blocks the audit. */
    public static function autoPost(string $closedDate): ?array
    {
        try {
            $d = Carbon::parse($closedDate);
            if (! $d->isLastOfMonth() || ! self::cfg()['auto_post']) {
                return null;
            }
            $period = $d->format('Y-m');
            if ($period < self::startPeriod()) {
                return null;
            }
            $p = self::period($period);

            return $p->status === 'open' && self::due($period)->count() ? self::runPostings($p, null, true) : null;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    public static function postAccrual(Accrual $a, string $period, float $amt, string $date, ?User $by = null): PeriodPosting
    {
        return DB::transaction(function () use ($a, $period, $amt, $date, $by) {
            $label = Carbon::parse($period.'-01')->format('M Y');
            $memo = $a->number.' · '.$label.($date !== Carbon::parse($period.'-01')->endOfMonth()->toDateString() ? ' (catch-up)' : '');
            // accrual & prepayment: Dr expense; deferral: Dr deferred income / Cr income
            $lines = $a->type === 'deferral'
                ? [[$a->balance_account_id, $amt, $memo], [$a->pl_account_id, -$amt, $memo]]
                : [[$a->pl_account_id, $amt, $memo], [$a->balance_account_id, -$amt, $memo]];
            $narr = Accrual::TYPES[$a->type].': '.$a->description.' – '.$label;
            $j = LedgerService::post(['date' => $date, 'reference' => $a->number, 'narration' => $narr, 'lines' => $lines], 'accrual', $a->id.':'.$period);
            $pp = PeriodPosting::create(['source' => 'accrual', 'source_id' => $a->id, 'period' => $period, 'kind' => 'post', 'date' => $date, 'amount' => $amt, 'gl_journal_id' => $j->id, 'created_by' => $by?->id]);
            if ($a->type === 'accrual') {
                $rd = Carbon::parse($date)->addDay()->toDateString();
                $rj = LedgerService::post(['date' => $rd, 'reference' => $a->number, 'narration' => 'Reversal – '.$narr, 'lines' => array_map(fn ($l) => [$l[0], -$l[1], $l[2]], $lines)], 'accrual', $a->id.':'.$period.':rev');
                PeriodPosting::create(['source' => 'accrual', 'source_id' => $a->id, 'period' => $period, 'kind' => 'reverse', 'date' => $rd, 'amount' => $amt, 'gl_journal_id' => $rj->id, 'created_by' => $by?->id]);
            }
            $posted = round((float) PeriodPosting::where('source', 'accrual')->where('source_id', $a->id)->where('kind', 'post')->sum('amount'), 2);
            $last = max((string) $a->last_period, $period);
            $a->forceFill(['posted' => $posted, 'last_period' => $last, 'status' => $posted >= (float) $a->amount - 0.005 ? 'completed' : $a->status])->save();

            return $pp;
        });
    }

    public static function postRecurring(RecurringJournal $r, string $period, string $date, ?User $by = null): PeriodPosting
    {
        return DB::transaction(function () use ($r, $period, $date, $by) {
            $label = Carbon::parse($period.'-01')->format('M Y');
            $lines = collect($r->lines)->map(fn ($l) => [(int) $l[0], round((float) $l[1] - (float) $l[2], 2), trim(($l[3] ?? '') ?: $r->code.' · '.$label)])->all();
            $j = LedgerService::post(['date' => $date, 'reference' => $r->code, 'narration' => $r->name.' – '.$label, 'lines' => $lines], 'recurring', $r->id.':'.$period);
            $pp = PeriodPosting::create(['source' => 'recurring', 'source_id' => $r->id, 'period' => $period, 'kind' => 'post', 'date' => $date, 'amount' => $r->total, 'gl_journal_id' => $j->id, 'created_by' => $by?->id]);
            if ($r->auto_reverse) {
                $rd = Carbon::parse($date)->addDay()->toDateString();
                $rj = LedgerService::post(['date' => $rd, 'reference' => $r->code, 'narration' => 'Reversal – '.$r->name.' – '.$label, 'lines' => array_map(fn ($l) => [$l[0], -$l[1], $l[2]], $lines)], 'recurring', $r->id.':'.$period.':rev');
                PeriodPosting::create(['source' => 'recurring', 'source_id' => $r->id, 'period' => $period, 'kind' => 'reverse', 'date' => $rd, 'amount' => $r->total, 'gl_journal_id' => $rj->id, 'created_by' => $by?->id]);
            }
            $r->forceFill(['last_period' => max((string) $r->last_period, $period), 'status' => ($r->end_period && ! $r->nextDue(self::next($period))) ? 'ended' : $r->status])->save();

            return $pp;
        });
    }

    // ───────────────────────── schedules & templates (four eyes) ─────────────────────────

    public static function createAccrual(array $d, User $by): Accrual
    {
        $type = $d['type'];
        $months = $type === 'accrual' ? 1 : max(1, min(60, (int) ($d['months'] ?? 1)));
        $pl = AccountHead::findOrFail($d['pl_account_id']);
        $want = $type === 'deferral' ? 'income' : 'expense';
        if ($pl->type !== $want) {
            throw new \RuntimeException('Choose '.($want === 'income' ? 'an income' : 'an expense').' account for a '.strtolower(Accrual::TYPES[$type]).'.');
        }
        $bal = ! empty($d['balance_account_id']) ? AccountHead::findOrFail($d['balance_account_id']) : AccountHead::find(AccountHead::sys(Accrual::BALANCE_ACCOUNT[$type]));
        if (! in_array($bal->type, ['asset', 'liability'], true)) {
            throw new \RuntimeException('The balance-sheet account must be an asset or liability.');
        }
        $a = Accrual::create([
            'number' => Numbering::next('accrual'), 'type' => $type, 'description' => $d['description'], 'supplier_id' => $d['supplier_id'] ?? null,
            'pl_account_id' => $pl->id, 'balance_account_id' => $bal->id, 'amount' => round((float) $d['amount'], 2), 'start_period' => $d['start_period'], 'months' => $months,
            'reference' => $d['reference'] ?? null, 'notes' => $d['notes'] ?? null, 'status' => 'pending', 'created_by' => $by->id,
        ]);
        ActivityLogger::log('close', 'accrual', "{$a->number} ".Accrual::TYPES[$type].": {$a->description} ".money($a->amount).($months > 1 ? " over {$months} months" : '').' – awaiting approval', $a);

        return $a;
    }

    public static function decideAccrual(Accrual $a, User $by, bool $approve, ?string $note = null): void
    {
        if ($a->status !== 'pending') {
            throw new \RuntimeException("{$a->number} is not awaiting approval.");
        }
        if ((int) $a->created_by === (int) $by->id) {
            throw new \RuntimeException('Four-eyes rule: the schedule must be approved by someone other than the person who entered it.');
        }
        $a->forceFill(['status' => $approve ? 'active' : 'rejected', 'approved_by' => $by->id, 'approved_at' => now(), 'decision_note' => $note ? mb_substr($note, 0, 255) : null])->save();
        ActivityLogger::log('close', $approve ? 'accrual_approve' : 'accrual_reject', "{$a->number} ".($approve ? 'approved' : 'rejected').($note ? ": {$note}" : ''), $a);
    }

    public static function cancelAccrual(Accrual $a, User $by, string $reason): void
    {
        if (! in_array($a->status, ['pending', 'active'], true)) {
            throw new \RuntimeException("{$a->number} is {$a->status}.");
        }
        $a->forceFill(['status' => 'cancelled', 'cancelled_by' => $by->id, 'cancelled_at' => now(), 'decision_note' => mb_substr($reason, 0, 255)])->save();
        ActivityLogger::log('close', 'accrual_cancel', "{$a->number} cancelled with ".money($a->remaining())." not posted: {$reason}", $a);
    }

    /** @param array<int, array{account_head_id?: mixed, debit?: mixed, credit?: mixed, memo?: mixed}> $rows */
    public static function normaliseLines(array $rows): array
    {
        $lines = [];
        foreach ($rows as $i => $l) {
            $dr = round((float) ($l['debit'] ?? 0), 2);
            $cr = round((float) ($l['credit'] ?? 0), 2);
            if (! $dr && ! $cr && empty($l['account_head_id'])) {
                continue;
            }
            if (empty($l['account_head_id']) || ! AccountHead::whereKey($l['account_head_id'])->exists()) {
                throw new \RuntimeException('Line '.($i + 1).': choose an account.');
            }
            if (($dr > 0) === ($cr > 0)) {
                throw new \RuntimeException('Line '.($i + 1).': enter either a debit or a credit.');
            }
            $lines[] = [(int) $l['account_head_id'], $dr, $cr, mb_substr(trim((string) ($l['memo'] ?? '')), 0, 150)];
        }
        if (count($lines) < 2) {
            throw new \RuntimeException('A journal needs at least two lines.');
        }
        $diff = round(array_sum(array_column($lines, 1)) - array_sum(array_column($lines, 2)), 2);
        if (abs($diff) >= 0.005) {
            throw new \RuntimeException('Debits and credits must be equal (difference '.number_format($diff, 2).').');
        }

        return $lines;
    }

    public static function createRecurring(array $d, User $by): RecurringJournal
    {
        $lines = self::normaliseLines($d['lines'] ?? []);
        if (! empty($d['end_period']) && $d['end_period'] < $d['start_period']) {
            throw new \RuntimeException('The last period is before the first.');
        }
        $r = RecurringJournal::create([
            'code' => Numbering::next('recurring_journal'), 'name' => $d['name'], 'frequency' => $d['frequency'], 'start_period' => $d['start_period'], 'end_period' => $d['end_period'] ?? null,
            'auto_reverse' => ! empty($d['auto_reverse']), 'lines' => $lines, 'total' => round(array_sum(array_column($lines, 1)), 2), 'status' => 'pending', 'created_by' => $by->id,
        ]);
        ActivityLogger::log('close', 'recurring', "{$r->code} {$r->name} ".money($r->total).' '.strtolower(RecurringJournal::FREQUENCIES[$r->frequency]).' – awaiting approval', $r);

        return $r;
    }

    public static function decideRecurring(RecurringJournal $r, User $by, bool $approve, ?string $note = null): void
    {
        if ($r->status !== 'pending') {
            throw new \RuntimeException("{$r->code} is not awaiting approval.");
        }
        if ((int) $r->created_by === (int) $by->id) {
            throw new \RuntimeException('Four-eyes rule: the template must be approved by someone other than the person who entered it.');
        }
        $r->forceFill(['status' => $approve ? 'active' : 'rejected', 'approved_by' => $by->id, 'approved_at' => now(), 'decision_note' => $note ? mb_substr($note, 0, 255) : null])->save();
        ActivityLogger::log('close', $approve ? 'recurring_approve' : 'recurring_reject', "{$r->code} ".($approve ? 'approved' : 'rejected'), $r);
    }

    public static function setRecurringStatus(RecurringJournal $r, string $status): void
    {
        $ok = ['paused' => ['active'], 'active' => ['paused'], 'ended' => ['active', 'paused']];
        if (! in_array($r->status, $ok[$status] ?? [], true)) {
            throw new \RuntimeException("{$r->code} is {$r->status}.");
        }
        $r->forceFill(['status' => $status])->save();
        ActivityLogger::log('close', 'recurring_'.$status, "{$r->code} {$status}", $r);
    }

    // ───────────────────────── year end ─────────────────────────

    /**
     * The closing entry as at a fiscal year end: every income / expense account's balance to that date (including
     * earlier years left unclosed) reversed to retained earnings.
     */
    public static function yearEndPreview(int $fy): array
    {
        $end = self::fyEnd($fy);
        $start = self::fyStart($fy);
        $bal = GlLine::where('date', '<=', $end)->groupBy('account_head_id')->selectRaw('account_head_id, SUM(debit - credit) b')->pluck('b', 'account_head_id');
        $fyMove = GlLine::whereBetween('date', [$start, $end])->where('closing', false)->groupBy('account_head_id')->selectRaw('account_head_id, SUM(debit - credit) b')->pluck('b', 'account_head_id');
        $rows = collect();
        $lines = [];
        foreach (AccountHead::whereIn('type', ['income', 'expense'])->orderBy('code')->get() as $a) {
            $b = round((float) ($bal[$a->id] ?? 0), 2);
            $m = round((float) ($fyMove[$a->id] ?? 0), 2);
            if (abs($b) < 0.005 && abs($m) < 0.005) {
                continue;
            }
            $rows->push(['id' => $a->id, 'code' => $a->code, 'account' => $a->name, 'type' => $a->type, 'fy' => $a->type === 'income' ? -$m : $m, 'balance' => $a->type === 'income' ? -$b : $b,
                'dr' => max(-$b, 0), 'cr' => max($b, 0)]);
            if (abs($b) >= 0.005) {
                $lines[] = [$a->id, -$b, 'Year-end close '.BudgetService::fyLabel($fy)];
            }
        }
        $income = round($rows->where('type', 'income')->sum('balance'), 2);
        $expense = round($rows->where('type', 'expense')->sum('balance'), 2);
        $net = round($income - $expense, 2);
        if ($lines) {
            $lines[] = [AccountHead::sys('retained'), -$net, 'Net '.($net >= 0 ? 'profit' : 'loss').' '.BudgetService::fyLabel($fy)];
        }

        return ['fy' => $fy, 'start' => $start, 'end' => $end, 'rows' => $rows, 'lines' => $lines, 'income' => $income, 'expense' => $expense, 'net' => $net,
            'fy_income' => round($rows->where('type', 'income')->sum('fy'), 2), 'fy_expense' => round($rows->where('type', 'expense')->sum('fy'), 2)];
    }

    public static function postYearEnd(ClosePeriod $p, ?User $by = null): ?FiscalYearClose
    {
        $fy = (int) $p->fiscal_year;
        $pv = self::yearEndPreview($fy);
        $j = null;
        if ($pv['lines']) {
            $j = LedgerService::post(['date' => $pv['end'], 'reference' => 'YE-'.$fy, 'narration' => 'Year-end closing entry '.BudgetService::fyLabel($fy).' – income and expenses to retained earnings', 'lines' => $pv['lines']], 'ye_close', (string) $fy);
        }
        $y = FiscalYearClose::create(['fiscal_year' => $fy, 'closing_date' => $pv['end'], 'income' => $pv['income'], 'expense' => $pv['expense'], 'net' => $pv['net'],
            'gl_journal_id' => $j?->id, 'status' => 'closed', 'closed_by' => $by?->id, 'closed_at' => now()]);
        self::log($p, 'year_end', BudgetService::fyLabel($fy).': net '.($pv['net'] >= 0 ? 'profit ' : 'loss ').money(abs($pv['net'])).' to retained earnings'.($j ? " ({$j->journal_no})" : ' – nothing to close'), $by);

        return $y;
    }

    public static function reverseYearEnd(ClosePeriod $p): void
    {
        $y = FiscalYearClose::where('fiscal_year', $p->fiscal_year)->where('status', 'closed')->latest('id')->first();
        if (! $y) {
            return;
        }
        if ($y->journal && ! $y->journal->reversed_by_id) {
            LedgerService::reverse($y->journal, $y->closing_date->toDateString(), 'year reopened');
        }
        $y->forceFill(['status' => 'reopened', 'reopened_at' => now()])->save();
    }

    public static function yearClose(int $fy): ?FiscalYearClose
    {
        return FiscalYearClose::with('journal', 'closer')->where('fiscal_year', $fy)->where('status', 'closed')->latest('id')->first();
    }

    // ───────────────────────── dashboard ─────────────────────────

    /** Periods whose close is overdue (month ended more than N days ago, not closed). */
    public static function overdue(): Collection
    {
        $due = self::cfg()['due_days'];
        $limit = bdate()->copy()->subDays($due)->toDateString();

        return self::periods()->filter(fn ($p) => $p->status !== 'closed' && $p->end()->toDateString() < $limit)->values();
    }

    /** things waiting for an approver */
    public static function approvals(): array
    {
        return [
            'periods' => ClosePeriod::where('status', 'review')->orderBy('period')->get(),
            'reopens' => ClosePeriod::whereNotNull('reopen_requested_at')->get(),
            'accruals' => Accrual::with('creator')->where('status', 'pending')->orderBy('id')->get(),
            'recurring' => RecurringJournal::with('creator')->where('status', 'pending')->orderBy('id')->get(),
        ];
    }

    /** nav badge: approvals for approvers + overdue periods for preparers */
    public static function attention(): int
    {
        static $memo = [];
        $u = auth()->user();
        $k = \App\Support\Tenant::id().':'.($u?->id ?? 0);
        if (isset($memo[$k])) {
            return $memo[$k];
        }
        $n = 0;
        if ($u?->hasPermission('close.approve')) {
            $n += ClosePeriod::where('status', 'review')->count() + ClosePeriod::whereNotNull('reopen_requested_at')->count()
                + Accrual::where('status', 'pending')->where('created_by', '!=', $u->id)->count() + RecurringJournal::where('status', 'pending')->where('created_by', '!=', $u->id)->count();
        }
        if ($u?->hasPermission('close.prepare')) {
            $limit = bdate()->copy()->subDays(self::cfg()['due_days'])->format('Y-m-d');
            $start = self::startPeriod();
            $n += ClosePeriod::where('status', 'open')->where('period', '>=', $start)->get()->filter(fn ($p) => $p->end()->toDateString() < $limit)->count();
        }

        return $memo[$k] = $n;
    }
}
