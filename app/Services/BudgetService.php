<?php

namespace App\Services;

use App\Models\AccountHead;
use App\Models\CapexProject;
use App\Models\Budget;
use App\Models\BudgetComment;
use App\Models\BudgetEvent;
use App\Models\BudgetLine;
use App\Models\BudgetSignoff;
use App\Models\BudgetStat;
use App\Models\Department;
use App\Models\GlLine;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\Setting;
use App\Support\Numbering;
use App\Support\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v3.6 – budgeting, re-forecasting, budget vs actual and spend control.
 *
 *  A version (BUD-) holds twelve fiscal-month amounts for every income / expense account plus rooms statistics.
 *  draft → submitted → approved (four eyes); one approved budget and one approved re-forecast per fiscal year are
 *  "active". Actuals always come from the general ledger up to the last closed day (LedgerService::cutoff()), so the
 *  comparison reconciles with the GL P&L. Months before the go-live date are shown but never compared.
 */
class BudgetService
{
    /** P&L sections in report order: revenue − direct − opex = GOP; + other income − non-operating = net profit */
    public const SECTIONS = [
        'revenue' => 'Operating revenue',
        'direct' => 'Cost of sales',
        'opex' => 'Operating expenses',
        'other_income' => 'Other income',
        'nonop' => 'Non-operating expenses',
        // v8.4 – a capital plan's one section. Capital is not an expense: it buys an asset, it does not reduce the year's
        // profit, so it is kept out of every P&L total rather than mixed in.
        'capital' => 'Capital expenditure',
    ];

    public const INCOME_SECTIONS = ['revenue', 'other_income'];

    public const DEFAULTS = [
        'budget_fy_start' => '4',          // fiscal year starts in April
        'budget_live_from' => '',          // ledger go-live: earlier months are not compared
        'budget_var_pct' => '10',          // variance needs an explanation at ≥ this % …
        'budget_var_amount' => '10000',    // … and ≥ this amount
        'budget_control' => 'warn',        // spend control on supplier expense bills: off | warn | block
        'budget_control_basis' => 'ytd',   // month | ytd | annual
        'budget_cost_centre_required' => 'off', // manual journals: expense lines must name a cost centre (v8.1)
        'budget_explain_due_days' => '10', // a flagged variance is explained within this many days of month end (v8.2)
        'budget_dual_approval_above' => '0', // a version this size needs two approvers; 0 = one is enough (v8.3)
        'budget_capex_control' => 'warn',   // a purchase coded to a capital asset head: off | warn | block (v8.4)
        'budget_capex_prefixes' => '15',    // asset heads whose code starts with one of these are capital, comma separated
    ];

    /** warnings raised by the last spend check (read by the AP controller to flash them) */
    public static array $lastWarnings = [];

    // ───────────────────────── settings & fiscal calendar ─────────────────────────

    public static function ensureDefaults(): void
    {
        foreach (self::DEFAULTS as $k => $v) {
            Setting::firstOrCreate(['key' => $k], ['value' => $v]);
        }
        Setting::$version++;
    }

    public static function cfg(): array
    {
        $g = fn ($k) => (string) setting($k, self::DEFAULTS[$k]);
        $start = (int) $g('budget_fy_start');
        $live = $g('budget_live_from');

        return [
            'fy_start' => $start >= 1 && $start <= 12 ? $start : 4,
            'live_from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $live) ? $live : '',
            'var_pct' => max(0, (float) $g('budget_var_pct')),
            'var_amount' => max(0, (float) $g('budget_var_amount')),
            'control' => in_array($g('budget_control'), ['off', 'warn', 'block'], true) ? $g('budget_control') : 'warn',
            'control_basis' => in_array($g('budget_control_basis'), ['month', 'ytd', 'annual'], true) ? $g('budget_control_basis') : 'ytd',
            'cost_centre_required' => $g('budget_cost_centre_required') === 'on',
            'explain_due_days' => max(0, min(90, (int) $g('budget_explain_due_days'))),
            'dual_approval_above' => max(0, (float) $g('budget_dual_approval_above')),
            'capex_control' => in_array($g('budget_capex_control'), ['off', 'warn', 'block'], true) ? $g('budget_capex_control') : 'warn',
            'capex_prefixes' => self::prefixList($g('budget_capex_prefixes')),
        ];
    }

    public static function saveSettings(array $d): void
    {
        $map = [
            'budget_fy_start' => (string) max(1, min(12, (int) ($d['fy_start'] ?? 4))),
            'budget_live_from' => ! empty($d['live_from']) ? Carbon::parse($d['live_from'])->toDateString() : '',
            'budget_var_pct' => (string) max(0, round((float) ($d['var_pct'] ?? 10), 2)),
            'budget_var_amount' => (string) max(0, round((float) ($d['var_amount'] ?? 10000), 2)),
            'budget_control' => in_array($d['control'] ?? '', ['off', 'warn', 'block'], true) ? $d['control'] : 'warn',
            'budget_control_basis' => in_array($d['control_basis'] ?? '', ['month', 'ytd', 'annual'], true) ? $d['control_basis'] : 'ytd',
            'budget_cost_centre_required' => ($d['cost_centre_required'] ?? 'off') === 'on' ? 'on' : 'off',
            'budget_explain_due_days' => (string) max(0, min(90, (int) ($d['explain_due_days'] ?? 10))),
            'budget_dual_approval_above' => (string) max(0, round((float) ($d['dual_approval_above'] ?? 0), 2)),
            'budget_capex_control' => in_array($d['capex_control'] ?? '', ['off', 'warn', 'block'], true) ? $d['capex_control'] : 'warn',
            'budget_capex_prefixes' => implode(',', self::prefixList((string) ($d['capex_prefixes'] ?? '15'))),
        ];
        foreach ($map as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        Setting::$version++;
        ActivityLogger::log('budget', 'settings', 'Budget settings updated: FY starts month '.$map['budget_fy_start'].', go-live '.($map['budget_live_from'] ?: '—')
            .', spend control '.$map['budget_control'].' ('.$map['budget_control_basis'].')'
            .', cost centre on manual journals '.$map['budget_cost_centre_required'].', explanations due '.$map['budget_explain_due_days'].' day(s) after month end'
            .', two approvals from '.($map['budget_dual_approval_above'] > 0 ? money($map['budget_dual_approval_above']) : 'never')
            .', capital purchases controlled '.$map['budget_capex_control'].' against capital heads '.$map['budget_capex_prefixes']);
    }

    /** the fiscal year (= the calendar year it starts in) a date falls in */
    public static function fyFor(Carbon|string $date): int
    {
        $d = $date instanceof Carbon ? $date : Carbon::parse($date);
        $s = self::cfg()['fy_start'];

        return $d->month >= $s ? $d->year : $d->year - 1;
    }

    public static function currentFy(): int
    {
        return self::fyFor(bdate());
    }

    public static function fyLabel(int $fy): string
    {
        return self::cfg()['fy_start'] === 1 ? 'FY '.$fy : 'FY '.$fy.'-'.substr((string) ($fy + 1), -2);
    }

    /** @return array<int, Carbon> fiscal month (1..12) => first day of that month */
    public static function months(int $fy): array
    {
        $start = Carbon::create($fy, self::cfg()['fy_start'], 1)->startOfDay();
        $o = [];
        for ($m = 1; $m <= 12; $m++) {
            $o[$m] = $start->copy()->addMonthsNoOverflow($m - 1);
        }

        return $o;
    }

    /** @return array<int, string> */
    public static function monthLabels(int $fy): array
    {
        return array_map(fn (Carbon $c) => $c->format('M y'), self::months($fy));
    }

    /** fiscal month index (1..12) of a date */
    public static function monthIndex(Carbon|string $date): int
    {
        $d = $date instanceof Carbon ? $date : Carbon::parse($date);

        return (($d->month - self::cfg()['fy_start'] + 12) % 12) + 1;
    }

    public static function period(int $fy, int $month): string
    {
        return self::months($fy)[$month]->format('Y-m');
    }

    protected static function liveFrom(): ?Carbon
    {
        $l = self::cfg()['live_from'];

        return $l ? Carbon::parse($l)->startOfDay() : null;
    }

    /** month before the go-live date (the ledger does not hold complete figures for it) */
    protected static function preLive(Carbon $monthStart): bool
    {
        $live = self::liveFrom();

        return $live !== null && $monthStart->copy()->startOfMonth()->lt($live->copy()->startOfMonth());
    }

    /** share of a month that is closed at the cut-off (0 = not started, 1 = fully closed) */
    protected static function elapsed(Carbon $monthStart, Carbon $cutoff): float
    {
        $end = $monthStart->copy()->endOfMonth()->startOfDay();
        if ($cutoff->lt($monthStart)) {
            return 0.0;
        }
        if ($cutoff->gte($end)) {
            return 1.0;
        }

        return round($cutoff->day / $monthStart->daysInMonth, 6);
    }

    // ───────────────────────── accounts & actuals ─────────────────────────

    public static function sectionOf(AccountHead $a): string
    {
        $g = strtolower((string) $a->group_name);
        if ($a->type === 'income') {
            return str_contains($g, 'other') || str_contains($g, 'non-op') ? 'other_income' : 'revenue';
        }
        if (str_contains($g, 'direct') || str_contains($g, 'cost of')) {
            return 'direct';
        }
        if (str_contains($g, 'non-op') || str_contains($g, 'non op') || str_contains($g, 'finance')) {
            return 'nonop';
        }

        return 'opex';
    }

    /** income & expense accounts, each with ->section, in P&L order */
    public static function accounts(): Collection
    {
        $order = array_flip(array_keys(self::SECTIONS));

        return AccountHead::whereIn('type', ['income', 'expense'])->where(fn ($q) => $q->where('is_active', true)->orWhereNull('is_active'))->orderBy('code')->get()
            ->each(fn ($a) => $a->section = self::sectionOf($a))
            ->sortBy(fn ($a) => $order[$a->section].'-'.str_pad((string) $a->code, 10, '0', STR_PAD_LEFT))->values();
    }

    // ───────────────────────── capital vs operating (v8.4) ─────────────────────────

    /** the code prefixes that make an asset account a capital one, from settings ('' → none) */
    public static function prefixList(?string $raw = null): array
    {
        $raw ??= (string) setting('budget_capex_prefixes', self::DEFAULTS['budget_capex_prefixes']);
        $out = [];
        foreach (preg_split('/[,;\s]+/', $raw) ?: [] as $p) {
            $p = preg_replace('/[^0-9A-Za-z]/', '', (string) $p);
            if ($p !== '') {
                $out[] = $p;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Is this account a capital asset rather than an operating cost? The chart of accounts decides: an asset-type head
     * whose code begins with one of the capital prefixes. An expense head never is — which is the whole point of the
     * rule: a capital purchase cannot become an operating cost by being coded to a repairs account, and an operating
     * cost cannot hide in the capital plan to escape the P&L.
     */
    public static function isCapitalHead(AccountHead $a): bool
    {
        if ($a->type !== 'asset') {
            return false;
        }
        foreach (self::prefixList() as $p) {
            if (str_starts_with((string) $a->code, $p)) {
                return true;
            }
        }

        return false;
    }

    /** capital (asset) heads, each with ->section = capital, in code order */
    public static function capitalAccounts(): Collection
    {
        return AccountHead::where('type', 'asset')->where(fn ($q) => $q->where('is_active', true)->orWhereNull('is_active'))->orderBy('code')->get()
            ->filter(fn ($a) => self::isCapitalHead($a))
            ->each(fn ($a) => $a->section = 'capital')
            ->values();
    }

    /** the accounts a version of this kind may hold — a capital plan holds capital heads and nothing else */
    public static function accountsFor(string $kind): Collection
    {
        return $kind === 'capital' ? self::capitalAccounts() : self::accounts();
    }

    /**
     * Posted actuals of a fiscal year in natural sign (income Cr+, expense and asset Dr+) up to the cut-off.
     *
     * @param  array  $types  income/expense for a P&L version, asset for a capital plan
     * @return array<int, array<int, float>> account id => [fiscal month => amount]
     */
    public static function actuals(int $fy, ?string $cutoff = null, ?int $departmentId = null, array $types = ['income', 'expense']): array
    {
        $months = self::months($fy);
        $from = $months[1]->toDateString();
        $to = min($months[12]->copy()->endOfMonth()->toDateString(), $cutoff ?? LedgerService::cutoff());
        if ($to < $from) {
            return [];
        }
        // v8.4 – a capital plan compares against asset heads, so the type filter is a parameter rather than a constant.
        // Signing stays by nature: income is credit-positive, everything else (expense, asset) debit-positive.
        $types = AccountHead::whereIn('type', $types)->pluck('type', 'id');
        $rows = GlLine::whereBetween('date', [$from, $to])->where('closing', false)->whereIn('account_head_id', $types->keys())
            ->when($departmentId !== null, fn ($q) => $q->where('department_id', $departmentId))   // v8.1 – one cost centre
            ->selectRaw('account_head_id, YEAR(date) y, MONTH(date) mo, SUM(debit) dr, SUM(credit) cr')->groupBy('account_head_id', DB::raw('YEAR(date)'), DB::raw('MONTH(date)'))->get();
        $out = [];
        foreach ($rows as $r) {
            $m = self::monthIndex(Carbon::create((int) $r->y, (int) $r->mo, 1));
            $v = $types[$r->account_head_id] === 'income' ? (float) $r->cr - (float) $r->dr : (float) $r->dr - (float) $r->cr;
            $out[$r->account_head_id][$m] = round(($out[$r->account_head_id][$m] ?? 0) + $v, 2);
        }

        return $out;
    }

    /**
     * Actual rooms statistics per fiscal month on closed days: available = sellable room-nights through the ledger cutoff,
     * sold = nights of checked-in / checked-out stays, revenue = room revenue in the ledger.
     *
     * @return array<int, array{available:int, sold:int, occupancy:float, adr:float, revpar:float, revenue:float}>
     */
    public static function actualStats(int $fy): array
    {
        $cut = Carbon::parse(LedgerService::cutoff());
        $months = self::months($fy);
        $fyStart = $months[1]->copy()->startOfDay();
        $fyEnd = $months[12]->copy()->endOfMonth()->startOfDay();
        $closedThrough = $cut->lt($fyEnd) ? $cut->copy() : $fyEnd->copy();
        $capacityDays = $closedThrough->lt($fyStart) ? 0 : (int) $fyStart->diffInDays($closedThrough) + 1;
        $roomRows = Room::where('is_active', true)->get(['id', 'status']);
        $sellableByDay = $capacityDays ? AvailabilityService::sellableRoomCounts($fyStart, $capacityDays, $roomRows) : [];
        $revAcc = AccountHead::sys('rev_room');
        $act = self::actuals($fy);
        $stays = ReservationRoom::whereIn('status', ['checked_in', 'checked_out'])
            ->whereDate('arrival_date', '<=', min($fyEnd, $cut)->toDateString())->whereDate('departure_date', '>', $months[1]->toDateString())
            ->get(['arrival_date', 'departure_date']);
        $out = [];
        foreach ($months as $m => $start) {
            $end = $start->copy()->endOfMonth()->startOfDay();
            $last = $cut->lt($end) ? $cut->copy() : $end;
            $days = $last->lt($start) ? 0 : (int) $start->diffInDays($last) + 1;
            $sold = 0;
            if ($days > 0) {
                $stop = $last->copy()->addDay();   // nights d with start ≤ d ≤ last
                foreach ($stays as $s) {
                    $a = $s->arrival_date->gt($start) ? $s->arrival_date->copy()->startOfDay() : $start->copy();
                    $b = $s->departure_date->lt($stop) ? $s->departure_date->copy()->startOfDay() : $stop->copy();
                    if ($b->gt($a)) {
                        $sold += (int) $a->diffInDays($b);
                    }
                }
            }
            $available = 0;
            for ($night = $start->copy(); $days > 0 && $night->lte($last); $night->addDay()) {
                $available += $sellableByDay[$night->toDateString()] ?? 0;
            }
            $revenue = round((float) ($act[$revAcc][$m] ?? 0), 2);
            $out[$m] = ['available' => $available, 'sold' => $sold, 'occupancy' => $available ? round($sold * 100 / $available, 2) : 0.0,
                'adr' => $sold ? round($revenue / $sold, 2) : 0.0, 'revpar' => $available ? round($revenue / $available, 2) : 0.0, 'revenue' => $revenue];
        }

        return $out;
    }

    /**
     * A capital plan against what the ledger has actually spent on those capital asset heads.
     *
     * "Plan" is the full year, "to date" is the plan through the month the ledger has closed, "actual" is what those
     * asset accounts have been debited with since the fiscal year began — the same figures a supplier bill is checked
     * against, so the screen and the control cannot disagree — and "committed" (v8.5) is what approved capital projects
     * have promised but not yet spent, linked by capital asset account.
     *
     * @return array{rows: array<int, array>, plan: float, to_date: float, actual: float, committed: float, remaining: float, pct: float, through: int}
     */
    public static function capitalSpend(Budget $b): array
    {
        if (! $b->isCapital()) {
            throw new RuntimeException('Only a capital plan has capital spend.');
        }
        $cut = Carbon::parse(LedgerService::cutoff());
        $months = self::months($b->fiscal_year);
        $through = 0;
        foreach ($months as $m => $start) {
            if ($start->copy()->endOfMonth()->startOfDay()->lte($cut) && ! self::preLive($start)) {
                $through = $m;
            }
        }
        $act = self::actuals($b->fiscal_year, null, $b->department_id, ['asset']);
        $lines = $b->lines()->get()->keyBy('account_head_id');
        $heads = self::capitalAccounts()->keyBy('id');
        $committed = self::commitments($b->fiscal_year);
        $rows = [];
        $tot = ['plan' => 0.0, 'to_date' => 0.0, 'actual' => 0.0, 'committed' => 0.0];
        foreach ($heads as $id => $head) {
            $l = $lines[$id] ?? null;
            $plan = $l ? $l->total() : 0.0;
            $toDate = 0.0;
            for ($m = 1; $m <= $through; $m++) {
                $toDate += $l ? (float) $l->month($m) : 0.0;
            }
            $actual = 0.0;
            for ($m = 1; $m <= $through; $m++) {
                $actual += (float) ($act[$id][$m] ?? 0);
            }
            $open = $committed[$id]['open'] ?? 0.0;
            if ($plan == 0 && $actual == 0 && $open == 0) {
                continue;
            }
            // what is left is the plan less what has been spent AND less what approved projects have already promised;
            // showing only plan − spent would let a hotel approve the same money twice
            $rows[] = ['account' => $head, 'plan' => round($plan, 2), 'to_date' => round($toDate, 2), 'actual' => round($actual, 2),
                'committed' => round($open, 2), 'projects' => $committed[$id]['projects'] ?? [],
                'remaining' => round($plan - $actual - $open, 2), 'pct' => $plan > 0 ? round($actual * 100 / $plan, 1) : null];
            $tot['plan'] += $plan;
            $tot['to_date'] += $toDate;
            $tot['actual'] += $actual;
            $tot['committed'] += $open;
        }

        return ['rows' => $rows, 'plan' => round($tot['plan'], 2), 'to_date' => round($tot['to_date'], 2), 'actual' => round($tot['actual'], 2),
            'committed' => round($tot['committed'], 2), 'remaining' => round($tot['plan'] - $tot['actual'] - $tot['committed'], 2),
            'pct' => $tot['plan'] > 0 ? round($tot['actual'] * 100 / $tot['plan'], 1) : null, 'through' => $through];
    }

    /**
     * The projects a plan counts: approved (or completed, which counts nothing) with a capital asset account to be
     * capitalised to, needed in this fiscal year — or, in the year being run, with no date yet. A project with no head
     * is not counted anywhere: it belongs to no part of the plan, and silently dropping it into a total would overstate
     * the plan. A project draws on the year it is needed in and in one year only, so an undated project is never
     * counted in two plans at once.
     */
    protected static function promisingProjects(int $fy)
    {
        $months = self::months($fy);
        $current = $fy === self::currentFy();

        return CapexProject::with(['assets', 'capitalHead'])->whereIn('status', ['approved', 'completed'])
            ->whereNotNull('capital_head_id')
            ->where(function ($q) use ($months, $current) {
                $q->whereBetween('needed_by', [$months[1]->toDateString(), $months[12]->copy()->endOfMonth()->toDateString()]);
                if ($current) {
                    $q->orWhereNull('needed_by');
                }
            })
            ->orderBy('number')->get();
    }

    /**
     * Money promised but not yet spent: approved capital projects, grouped by the capital head they will be capitalised
     * to, each at its open commitment (budget less what has been spent against it).
     *
     * @return array<int, array{open: float, budget: float, projects: array<int, array{number: string, title: string, budget: float, spent: float, open: float}>}>
     */
    public static function commitments(int $fy): array
    {
        $out = [];
        foreach (self::promisingProjects($fy) as $p) {
            $open = $p->status === 'completed' ? 0.0 : $p->openCommitment();   // a completed project has nothing left to spend
            $id = (int) $p->capital_head_id;
            $out[$id] ??= ['open' => 0.0, 'budget' => 0.0, 'projects' => []];
            $out[$id]['open'] += $open;
            $out[$id]['budget'] += (float) $p->budget;
            $out[$id]['projects'][] = ['number' => $p->number, 'title' => $p->title, 'budget' => round((float) $p->budget, 2),
                'spent' => round($p->spent(), 2), 'open' => round($open, 2)];
        }

        return $out;
    }

    /**
     * When the money promised falls due: the same projects commitments() counts, placed in the fiscal month each one
     * says it is needed in. A project is in exactly one month — its date — so moving the date moves the money; one with
     * no date is held apart rather than guessed into the run-rate, and a project past its date while still approved is
     * called out as overdue, because that is a commitment the year's cash plan has already got wrong.
     *
     * @return array{months: array<int, array{label: string, open: float, height: int, past: bool, late: bool, projects: array<int, array{number: string, title: string, open: float, head: ?string}>}>, undated: array{open: float, projects: array}, overdue: array{open: float, projects: array}, total: float, peak: float}
     */
    public static function commitmentTimeline(int $fy): array
    {
        $today = bdate()->copy()->startOfDay();
        $out = ['months' => [], 'undated' => ['open' => 0.0, 'projects' => []],
            'overdue' => ['open' => 0.0, 'projects' => []], 'total' => 0.0, 'peak' => 0.0];
        foreach (self::months($fy) as $m => $start) {
            $out['months'][$m] = ['label' => $start->format('M y'), 'open' => 0.0, 'height' => 0,
                'past' => $start->copy()->endOfMonth()->startOfDay()->lt($today), 'late' => false, 'projects' => []];
        }
        foreach (self::promisingProjects($fy) as $p) {
            $open = $p->status === 'completed' ? 0.0 : $p->openCommitment();   // completing a project releases what is left
            if ($open <= 0) {
                continue;
            }
            $ref = ['number' => $p->number, 'title' => $p->title, 'open' => round($open, 2), 'head' => $p->capitalHead?->code];
            if ($p->needed_by === null) {
                $out['undated']['open'] += $open;
                $out['undated']['projects'][] = $ref;
            } else {
                $m = self::monthIndex($p->needed_by);
                $out['months'][$m]['open'] += $open;
                $out['months'][$m]['projects'][] = $ref;
                if ($p->needed_by->copy()->startOfDay()->lt($today)) {   // approved, unspent, and its date has gone
                    $out['overdue']['open'] += $open;
                    $out['overdue']['projects'][] = $ref;
                }
            }
            $out['total'] += $open;
        }
        foreach ($out['months'] as $m => $r) {
            $out['peak'] = max($out['peak'], $r['open']);
        }
        $out['peak'] = round($out['peak'], 2);
        foreach ($out['months'] as $m => $r) {
            // the bar is drawn against the busiest month, so the strip reads as a shape rather than a table of numbers
            $out['months'][$m]['open'] = round($r['open'], 2);
            $out['months'][$m]['height'] = $out['peak'] > 0 && $r['open'] > 0 ? max(4, (int) round($r['open'] * 100 / $out['peak'])) : 0;
            $out['months'][$m]['late'] = $r['past'] && $r['open'] > 0;
        }
        $out['undated']['open'] = round($out['undated']['open'], 2);
        $out['overdue']['open'] = round($out['overdue']['open'], 2);
        $out['total'] = round($out['total'], 2);

        return $out;
    }

    /**
     * Can the plan be paid? Every open commitment is promised from one source — the FF&E reserve, an owner capital call,
     * or operating cash — and a commitment is only as good as the money behind it. The reserve is the one source the PMS
     * can see: its accrued balance is what the hotel has set aside for replacement, and its funded balance is the cash
     * actually sitting in the designated account. The two differ, which is the whole point of reporting both. Owner
     * capital calls and operating cash are not balances this system holds, so they are reported as "raised when the
     * project is paid" rather than dressed up as covered.
     *
     * @return array{rows: array<int, array{key: string, label: string, open: float, projects: array, cover: ?float, cash: ?float, short: ?float, cash_short: ?float, note: string}>, open: float, short: float, untracked: float, accrued: ?float, cash: ?float}
     */
    public static function capitalFunding(int $fy): array
    {
        $rows = [];
        foreach (CapexProject::FUNDING as $key => $label) {
            $rows[$key] = ['key' => $key, 'label' => $label, 'open' => 0.0, 'projects' => [], 'cover' => null,
                'cash' => null, 'short' => null, 'cash_short' => null, 'note' => ''];
        }
        foreach (self::promisingProjects($fy) as $p) {
            $open = $p->status === 'completed' ? 0.0 : $p->openCommitment();
            if ($open <= 0) {
                continue;
            }
            $key = (string) $p->funding;
            $rows[$key] ??= ['key' => $key, 'label' => CapexProject::FUNDING[$key] ?? ucfirst($key), 'open' => 0.0,
                'projects' => [], 'cover' => null, 'cash' => null, 'short' => null, 'cash_short' => null, 'note' => ''];
            $rows[$key]['open'] += $open;
            $rows[$key]['projects'][] = ['number' => $p->number, 'title' => $p->title, 'open' => round($open, 2)];
        }

        $accrued = null;
        $cash = null;
        $out = ['open' => 0.0, 'short' => 0.0, 'untracked' => 0.0, 'accrued' => null, 'cash' => null];
        foreach ($rows as $key => $r) {
            if ($r['open'] <= 0) {
                $rows[$key]['note'] = 'nothing promised from here';
                continue;
            }
            if ($key === 'reserve') {
                // what the reserve holds, accrued and in cash; the funded side is null when no account is designated
                $accrued = OwnerService::reserveBalance(bdate()->toDateString());
                $cash = OwnerService::reserveFunded(null, bdate()->toDateString());
                $rows[$key]['cover'] = $accrued;
                $rows[$key]['cash'] = $cash;
                $rows[$key]['short'] = round(max(0, $r['open'] - $accrued), 2);
                $rows[$key]['cash_short'] = $cash === null ? null : round(max(0, $r['open'] - $cash), 2);
                $rows[$key]['note'] = 'drawn from the reserve balance';
                $out['short'] += $rows[$key]['short'];
            } else {
                // no balance is held here, so there is nothing to measure the promise against
                $rows[$key]['note'] = $key === 'owner' ? 'raised as a capital call when it is paid' : 'paid out of the month\'s cash';
                $rows[$key]['cover'] = null;
                $out['untracked'] += $r['open'];
            }
            $rows[$key]['open'] = round($r['open'], 2);
            $out['open'] += $r['open'];
        }
        $out['rows'] = array_values($rows);
        $out['open'] = round($out['open'], 2);
        $out['short'] = round($out['short'], 2);
        $out['untracked'] = round($out['untracked'], 2);
        $out['accrued'] = $accrued;
        $out['cash'] = $cash;

        return $out;
    }

    /**
     * One capital asset account in the year's plan: the plan, what is committed to approved projects, what has been
     * spent, and what is left. Used by the capital project screen, so a project can be seen against the plan it draws on.
     *
     * @return array{plan: float, committed: float, spent: float, remaining: float, plan_no: ?string, fiscal_year: int}
     */
    public static function headPosition(int $headId): array
    {
        $fy = self::currentFy();
        $plan = self::active($fy, 'capital') ?? Budget::where('kind', 'capital')->where('status', 'approved')->orderByDesc('fiscal_year')->orderByDesc('id')->first();
        if ($plan) {
            $fy = $plan->fiscal_year;
        }
        $spend = $plan ? self::capitalSpend($plan) : null;
        $row = null;
        foreach ($spend['rows'] ?? [] as $r) {
            if ((int) $r['account']->id === $headId) {
                $row = $r;
            }
        }
        $committed = self::commitments($fy)[$headId]['open'] ?? 0.0;

        return ['plan' => $row['plan'] ?? 0.0, 'committed' => $row['committed'] ?? $committed, 'spent' => $row['actual'] ?? 0.0,
            'remaining' => $row['remaining'] ?? round($committed * -1, 2), 'plan_no' => $plan?->budget_no, 'fiscal_year' => $fy];
    }

    // ───────────────────────── versions ─────────────────────────

    /**
     * The month-by-month figures the budget grid prints under each section, and the two derived rows.
     *
     * They used to be added up inside the view with a closure, which meant the one place the accounting identity
     * could be checked was a template the test suite cannot run. Computed here, the identity is testable: gross
     * operating profit is operating revenue less cost of sales and operating expenses; net profit adds other income
     * and takes the non-operating expenses off. Both are returned per month (1–12) and for the year under 't'.
     *
     * @param  iterable  $lines  the version's lines, already grouped by section
     * @return array{cols: array<string, array<int|string, float>>, gop: array<int|string, float>, net: array<int|string, float>}
     */
    public static function gridTotals(iterable $lines): array
    {
        $cols = [];
        foreach ($lines as $sec => $ls) {
            for ($m = 1; $m <= 12; $m++) {
                $cols[$sec][$m] = 0.0;
                foreach ($ls as $l) {
                    $cols[$sec][$m] += (float) $l->month($m);
                }
            }
            $cols[$sec]['t'] = array_sum($cols[$sec]);
        }
        $gop = [];
        $net = [];
        for ($m = 1; $m <= 12; $m++) {
            $gop[$m] = round(self::col($cols, ['revenue'], $m) - self::col($cols, ['direct', 'opex'], $m), 2);
            $net[$m] = round(self::col($cols, self::INCOME_SECTIONS, $m) - self::col($cols, ['direct', 'opex', 'nonop'], $m), 2);
        }
        $gop['t'] = round(array_sum($gop), 2);   // 't' is set after the sum, so it cannot count itself
        $net['t'] = round(array_sum($net), 2);

        return ['cols' => $cols, 'gop' => $gop, 'net' => $net];
    }

    /** one column of the grid, added across the sections named */
    protected static function col(array $cols, array $sections, int $m): float
    {
        $sum = 0.0;
        foreach ($sections as $sec) {
            $sum += (float) ($cols[$sec][$m] ?? 0);
        }

        return $sum;
    }

    public static function totals(Budget $b): array
    {
        $types = AccountHead::whereIn('type', ['income', 'expense', 'asset'])->pluck('type', 'id');
        $t = ['income' => 0.0, 'expense' => 0.0, 'capital' => 0.0];
        foreach ($b->relationLoaded('lines') ? $b->lines : $b->lines()->get() as $l) {
            if (! isset($types[$l->account_head_id])) {
                continue;
            }
            if ($types[$l->account_head_id] === 'asset') {
                $t['capital'] += $l->total();   // counted, but never as an expense: an asset purchase is not a cost
            } else {
                $t[$types[$l->account_head_id]] += $l->total();
            }
        }

        return ['income' => round($t['income'], 2), 'expense' => round($t['expense'], 2), 'net' => round($t['income'] - $t['expense'], 2),
            'capital' => round($t['capital'], 2)];
    }

    /**
     * The active approved version of a fiscal year for one scope: the whole hotel ($departmentId = null) or one
     * department's cost centre. v8.1
     */
    public static function active(int $fy, string $kind = 'budget', ?int $departmentId = null): ?Budget
    {
        return Budget::where('fiscal_year', $fy)->where('kind', $kind)->where('status', 'approved')->where('is_active', true)
            ->when($departmentId === null, fn ($q) => $q->whereNull('department_id'), fn ($q) => $q->where('department_id', $departmentId))
            ->latest('id')->first();
    }

    // ───────────────────────── cost centres (v8.1) ─────────────────────────

    /** @return array<int, int> the account heads that are expense (P&L cost) accounts */
    protected static function expenseIds(): array
    {
        return array_map('intval', AccountHead::where('type', 'expense')->pluck('id')->all());
    }

    /**
     * Full-year expense plan of a version, and the same plan pro-rated to the days actually elapsed (the figure a
     * comparison must use, exactly as the budget-vs-actual screen does).
     *
     * @return array{0: float, 1: float} [full year, year to date]
     */
    protected static function expensePlan(Budget $b, int $fy, Carbon $cut): array
    {
        $ids = self::expenseIds();
        $months = self::months($fy);
        $fac = [];
        foreach ($months as $m => $start) {
            $fac[$m] = self::elapsed($start, $cut);
        }
        $year = 0.0;
        $ytd = 0.0;
        foreach ($b->lines()->whereIn('account_head_id', $ids)->get() as $l) {
            $year += $l->total();
            foreach ($months as $m => $start) {
                if ($fac[$m] > 0) {
                    $ytd += $l->month($m) * $fac[$m];
                }
            }
        }

        return [round($year, 2), round($ytd, 2)];
    }

    /**
     * The cost-centre picture of a fiscal year: what each department was planned to spend, what the ledger actually
     * charged to it, and the two figures that never balance on their own – plan not yet given to any department, and
     * expense that carries no cost centre at all. Everything comes from the ledger, so it reconciles with the P&L.
     */
    public static function costCentres(int $fy): array
    {
        $cut = Carbon::parse(LedgerService::cutoff());
        $months = self::months($fy);
        $whole = self::active($fy);
        [$wholePlan, $wholePlanYtd] = $whole ? self::expensePlan($whole, $fy, $cut) : [0.0, 0.0];
        $ids = self::expenseIds();
        $from = $months[1]->toDateString();
        $to = min($months[12]->copy()->endOfMonth()->toDateString(), $cut->toDateString());

        $spent = $to < $from ? collect() : GlLine::whereBetween('date', [$from, $to])->where('closing', false)->whereIn('account_head_id', $ids)
            ->selectRaw('department_id, SUM(debit - credit) v')->groupBy('department_id')->get();
        $actualOf = [];
        foreach ($spent as $r) {
            $actualOf[$r->department_id === null ? 0 : (int) $r->department_id] = round((float) $r->v, 2);
        }

        $budgets = Budget::with('lines')->where('fiscal_year', $fy)->where('kind', 'budget')->where('status', 'approved')
            ->where('is_active', true)->whereNotNull('department_id')->get()->keyBy('department_id');
        // a version that is not approved (or not active) caps nothing, so it is never counted in the plan — but it is
        // reported, because "no budget" and "a budget nobody has approved yet" are different facts.
        $pending = Budget::where('fiscal_year', $fy)->where('kind', 'budget')->whereNotNull('department_id')
            ->whereIn('status', ['draft', 'submitted'])->orderByDesc('id')->get()->unique('department_id')->keyBy('department_id');

        $rows = collect();
        foreach (Department::orderBy('name')->get() as $d) {
            $b = $budgets[$d->id] ?? null;
            [$plan, $planYtd] = $b ? self::expensePlan($b, $fy, $cut) : [null, null];
            $actual = $actualOf[$d->id] ?? 0.0;
            $var = $planYtd === null ? null : round($actual - $planYtd, 2);
            $rows->push((object) [
                'department' => $d, 'budget' => $b, 'pending' => $b ? null : ($pending[$d->id] ?? null),
                'plan' => $plan, 'plan_ytd' => $planYtd, 'actual' => $actual,
                'variance' => $var, 'variance_pct' => $planYtd ? round($var * 100 / $planYtd, 1) : null,
                'per_cent' => $plan !== null && $wholePlan > 0 ? round($plan * 100 / $wholePlan, 1) : null,
            ]);
        }
        $allocated = round($rows->sum(fn ($r) => (float) ($r->plan ?? 0)), 2);
        $allocatedYtd = round($rows->sum(fn ($r) => (float) ($r->plan_ytd ?? 0)), 2);
        $tagged = round($rows->sum('actual'), 2);
        $unallocated = $actualOf[0] ?? 0.0;   // expense with no cost centre on the line
        $actual = round($tagged + $unallocated, 2);

        return [
            'rows' => $rows,
            'totals' => ['plan' => $allocated, 'plan_ytd' => $allocatedYtd, 'actual' => $tagged],
            'whole' => ['plan' => $wholePlan, 'plan_ytd' => $wholePlanYtd, 'actual' => $actual],
            'not_allocated' => round($wholePlan - $allocated, 2),   // negative = departments exceed the whole-hotel plan
            'not_allocated_ytd' => round($wholePlanYtd - $allocatedYtd, 2),
            'unallocated' => $unallocated,
            'tagged_pct' => abs($actual) > 0.005 ? round($tagged * 100 / $actual, 1) : null,
            'meta' => ['fy' => $fy, 'cutoff' => $cut, 'whole' => $whole, 'budgeted' => $rows->filter(fn ($r) => $r->budget !== null)->count(),
                'pending' => $rows->filter(fn ($r) => $r->pending !== null)->count(),
                'departments' => $rows->count(), 'from' => $from, 'to' => $to],
        ];
    }

    /**
     * v8.1 – the monthly allowance a department's own budget gives it, for one account head (the stores cost account)
     * or, with no head given, for all of its expense lines. NULL means the department has no approved budget, and the
     * caller must fall back to whatever single figure it used before – never to zero.
     */
    public static function departmentAllowance(Department $d, ?int $headId = null, Carbon|string|null $date = null): ?array
    {
        $date = $date ? ($date instanceof Carbon ? $date->copy() : Carbon::parse($date)) : bdate()->copy();
        $fy = self::fyFor($date);
        $m = self::monthIndex($date);
        $b = self::active($fy, 'budget', $d->id);
        if (! $b) {
            return null;
        }
        $ids = $headId ? [$headId] : self::expenseIds();
        $sum = 0.0;
        $lines = 0;
        foreach ($b->lines()->whereIn('account_head_id', $ids)->get() as $l) {
            $sum += $l->month($m);
            $lines++;
        }

        return round($sum, 2) > 0 ? ['amount' => round($sum, 2), 'budget' => $b, 'month' => $m, 'fy' => $fy, 'lines' => $lines, 'head' => $headId] : null;
    }

    protected static function event(Budget $b, string $action, ?string $note = null): void
    {
        BudgetEvent::create(['budget_id' => $b->id, 'action' => $action, 'note' => $note ? mb_substr(trim($note), 0, 255) : null, 'user_id' => auth()->id(), 'created_at' => now()]);
    }

    /** rooms statistics belong to the whole-hotel version; a cost centre has none, and the server says so */
    protected static function mustBeWholeHotel(Budget $b, string $what): void
    {
        if ($b->department_id) {
            throw new RuntimeException($what.' belongs to the whole-hotel budget – the cost centre version '
                .($b->department?->label() ?? '#'.$b->department_id).' plans money only.');
        }
    }

    protected static function mustBeDraft(Budget $b): void
    {
        if ($b->status !== 'draft') {
            throw new RuntimeException("{$b->budget_no} is {$b->statusLabel()} – only drafts can be changed (re-open it first).");
        }
    }

    /** Default monthly sellable room-night capacity, before any budgeted occupancy or revenue. */
    protected static function blankStats(int $fy): array
    {
        $months = self::months($fy);
        $start = $months[1]->copy()->startOfDay();
        $end = $months[12]->copy()->endOfMonth()->startOfDay();
        $days = (int) $start->diffInDays($end) + 1;
        $sellableByDay = AvailabilityService::sellableRoomCounts($start, $days);
        $o = [];
        foreach ($months as $m => $s) {
            $available = 0;
            for ($night = $s->copy()->startOfDay(); $night->lte($s->copy()->endOfMonth()); $night->addDay()) {
                $available += $sellableByDay[$night->toDateString()] ?? 0;
            }
            $o[$m] = ['rooms_available' => $available, 'occupancy' => 0, 'adr' => 0];
        }

        return $o;
    }

    /**
     * New version. method: blank | last_year (previous FY actuals, same months) | run_rate (average of the last three
     * closed months since go-live) | copy (another version). Growth % is applied to income / expense figures.
     */
    public static function create(array $d): Budget
    {
        $fy = (int) ($d['fiscal_year'] ?? 0);
        $cur = self::currentFy();
        if ($fy < $cur - 5 || $fy > $cur + 5) {
            throw new RuntimeException('Choose a fiscal year close to the current one.');
        }
        $method = array_key_exists($d['method'] ?? '', Budget::METHODS) ? $d['method'] : 'blank';
        $kind = ($d['kind'] ?? 'budget') === 'capital' ? 'capital' : 'budget';   // v8.4 – a capital plan, not a P&L version
        $gi = max(-100, min(1000, (float) ($d['growth_income'] ?? 0)));
        $ge = max(-100, min(1000, (float) ($d['growth_expense'] ?? 0)));
        $accounts = self::accountsFor($kind);
        // v8.1 – a version for one cost centre. Rooms statistics belong to the whole hotel, so a departmental
        // version holds no statistics at all rather than a second, silently different set of them.
        $dept = ! empty($d['department_id']) ? Department::find((int) $d['department_id']) : null;
        if (! empty($d['department_id']) && ! $dept) {
            throw new RuntimeException('That cost centre does not belong to this hotel.');
        }
        $deptId = $dept?->id;
        if ($deptId && $method === 'copy' && ! empty($d['source_id']) && (int) Budget::whereKey((int) $d['source_id'])->value('department_id') !== (int) $deptId) {
            throw new RuntimeException('Copy a version of the same cost centre – the whole hotel plan is not a department plan.');
        }
        $src = null;
        $base = [];   // account id => [m => amount]
        // rooms statistics belong to the P&L: a capital plan and a departmental version hold none
        $stats = ($deptId || $kind === 'capital') ? [] : self::blankStats($fy);

        if ($method === 'copy') {
            $src = ! empty($d['source_id']) ? Budget::with('lines', 'stats')->find((int) $d['source_id']) : null;
            if (! $src) {
                throw new RuntimeException('Choose the version to copy.');
            }
            if ($src->kind !== $kind) {
                throw new RuntimeException('Copy a version of the same kind – '.'a capital plan holds asset accounts and a budget holds income and expense, so one cannot be built from the other.');
            }
            foreach ($src->lines as $l) {
                $base[$l->account_head_id] = $l->months();
            }
            foreach ($src->stats as $s) {
                $stats[$s->month] = ['rooms_available' => $stats[$s->month]['rooms_available'] ?: $s->rooms_available, 'occupancy' => $s->occupancy, 'adr' => round($s->adr * (1 + $gi / 100), 2)];
            }
        } elseif ($method === 'last_year') {
            $base = self::actuals($fy - 1, null, $deptId, $kind === 'capital' ? ['asset'] : ['income', 'expense']);
            if (! $base) {
                throw new RuntimeException('The ledger holds no actuals for '.self::fyLabel($fy - 1).($deptId ? ' for this cost centre' : '').' – start blank or use the run-rate.');
            }
            if (! $deptId) {
                foreach (self::actualStats($fy - 1) as $m => $a) {
                    if ($a['available']) {
                        $stats[$m]['occupancy'] = round(min(100, $a['occupancy']), 2);
                        $stats[$m]['adr'] = round($a['adr'] * (1 + $gi / 100), 2);
                    }
                }
            }
        } elseif ($method === 'run_rate') {
            $closed = self::closedMonths(3);
            if (! $closed) {
                throw new RuntimeException('No closed month since go-live yet to base a run-rate on.');
            }
            $sum = [];
            foreach ($closed as [$cfy, $cm]) {
                foreach (self::actuals($cfy, null, $deptId, $kind === 'capital' ? ['asset'] : ['income', 'expense']) as $acc => $ms) {
                    $sum[$acc] = ($sum[$acc] ?? 0) + ($ms[$cm] ?? 0);
                }
            }
            foreach ($sum as $acc => $v) {
                $base[$acc] = array_fill(1, 12, round($v / count($closed), 2));
            }
        }

        return DB::transaction(function () use ($d, $fy, $method, $kind, $gi, $ge, $accounts, $src, $base, $stats, $dept) {
            $b = Budget::create([
                'budget_no' => Numbering::next($kind === 'capital' ? 'capital' : 'budget'), 'fiscal_year' => $fy, 'kind' => $kind, 'status' => 'draft', 'method' => $method,
                'department_id' => $dept?->id,
                'name' => mb_substr(trim((string) ($d['name'] ?? '')), 0, 80)
                    ?: ($dept ? $dept->name.' – '.self::fyLabel($fy) : ($kind === 'capital' ? 'Capital plan ' : 'Budget ').self::fyLabel($fy)),
                'parent_id' => $src?->id, 'growth_income' => $gi, 'growth_expense' => $ge,
                'notes' => ! empty($d['notes']) ? mb_substr(trim($d['notes']), 0, 1000) : null, 'created_by' => auth()->id(),
            ]);
            foreach ($accounts as $a) {
                $g = $a->type === 'income' ? $gi : $ge;
                $row = ['budget_id' => $b->id, 'account_head_id' => $a->id];
                foreach (range(1, 12) as $m) {
                    $row['m'.$m] = round((float) ($base[$a->id][$m] ?? 0) * (1 + $g / 100), 2);
                }
                BudgetLine::create($row);
            }
            foreach ($stats as $m => $s) {
                BudgetStat::create(['budget_id' => $b->id, 'month' => $m] + $s);
            }
            self::event($b, 'created', ($dept ? 'Cost centre '.$dept->label().' · ' : '').Budget::METHODS[$method].($src ? ' ('.$src->budget_no.')' : '').($gi || $ge ? sprintf(' · growth income %+.1f %%, expense %+.1f %%', $gi, $ge) : ''));
            ActivityLogger::log('budget', 'create', "Budget {$b->budget_no} ({$b->name}) created – ".Budget::METHODS[$method].($dept ? ' for cost centre '.$dept->label() : ''), $b);

            return $b;
        });
    }

    /** the last $n fully closed months on or after go-live, newest first, as [fy, fiscal month] */
    protected static function closedMonths(int $n): array
    {
        $cut = Carbon::parse(LedgerService::cutoff());
        $m = $cut->copy()->endOfMonth()->startOfDay()->equalTo($cut) ? $cut->copy()->startOfMonth() : $cut->copy()->startOfMonth()->subMonthNoOverflow();
        $out = [];
        for ($i = 0; $i < 24 && count($out) < $n; $i++, $m = $m->copy()->subMonthNoOverflow()) {
            if (self::preLive($m)) {
                break;
            }
            $out[] = [self::fyFor($m), self::monthIndex($m)];
        }

        return $out;
    }

    protected static function num($v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        $s = str_replace([',', '₹', 'GH₵', 'GH¢', 'GHS', ' ', "\u{00A0}"], '', trim((string) $v));
        if ($s === '' || $s === '-') {
            return 0.0;
        }
        if (preg_match('/^\((\d+(\.\d+)?)\)$/', $s, $m)) {   // (1234) = negative
            return -(float) $m[1];
        }

        return is_numeric($s) ? (float) $s : null;
    }

    protected static function line(Budget $b, int $accountId): BudgetLine
    {
        // v8.4 – a version may only hold accounts of its own kind: a capital plan holds capital asset heads, a P&L
        // version holds income and expense heads. Coding capital into the P&L (or an operating cost into the capital
        // plan) is refused here rather than being silently accepted and hidden from the other plan.
        if (! self::accountsFor($b->kind)->firstWhere('id', $accountId)) {
            throw new RuntimeException($b->isCapital()
                ? 'That account is not a capital asset account – the capital plan holds asset purchases, not income or operating expense.'
                : 'That account is not an income or expense account – capital purchases belong in the capital plan.');
        }

        return BudgetLine::firstOrCreate(['budget_id' => $b->id, 'account_head_id' => $accountId]);
    }

    /** save grid edits: $cells[account id][month] = amount, $notes[account id] = assumption */
    public static function saveGrid(Budget $b, array $cells, array $notes = []): int
    {
        self::mustBeDraft($b);
        $locked = $b->lockedMonths();
        $valid = self::accountsFor($b->kind)->pluck('code', 'id');
        $changed = 0;
        $errors = [];
        $plan = [];
        foreach ($cells as $acc => $months) {
            if (! isset($valid[(int) $acc]) || ! is_array($months)) {
                continue;
            }
            foreach ($months as $m => $v) {
                $m = (int) $m;
                if ($m < 1 || $m > 12 || in_array($m, $locked, true)) {
                    continue;
                }
                $n = self::num($v);
                if ($n === null || abs($n) > 99999999999) {
                    $errors[] = $valid[(int) $acc].' month '.$m.': “'.mb_substr((string) $v, 0, 20).'” is not an amount';

                    continue;
                }
                $plan[(int) $acc]['m'.$m] = round($n, 2);
            }
        }
        if ($errors) {
            throw new RuntimeException('Not saved – '.implode('; ', array_slice($errors, 0, 5)).'.');
        }
        foreach ($notes as $acc => $note) {
            if (isset($valid[(int) $acc])) {
                $plan[(int) $acc]['note'] = mb_substr(trim((string) $note), 0, 190) ?: null;
            }
        }
        DB::transaction(function () use ($b, $plan, &$changed) {
            foreach ($plan as $acc => $vals) {
                $l = BudgetLine::firstOrNew(['budget_id' => $b->id, 'account_head_id' => $acc]);
                $l->fill($vals);
                if (! $l->exists || $l->isDirty()) {
                    $l->save();
                    $changed++;
                }
            }
        });
        if ($changed) {
            self::event($b, 'edited', "{$changed} line(s) changed");
        }

        return $changed;
    }

    /** spread an annual total over the open months: even | days (days in month) | seasonal (budgeted room-nights) */
    public static function spread(Budget $b, int $accountId, float $total, string $how): BudgetLine
    {
        self::mustBeDraft($b);
        if ($how === 'seasonal') {
            self::mustBeWholeHotel($b, 'Seasonal spreading');
        }
        $l = self::line($b, $accountId);
        $locked = $b->lockedMonths();
        $open = array_values(array_diff(range(1, 12), $locked));
        if (! $open) {
            throw new RuntimeException('Every month of this version is locked.');
        }
        $remaining = $total - array_sum(array_map(fn ($m) => $l->month($m), $locked));
        $months = self::months($b->fiscal_year);
        $w = [];
        foreach ($open as $m) {
            $w[$m] = match ($how) {
                'days' => $months[$m]->daysInMonth,
                'seasonal' => (float) ($b->stats()->where('month', $m)->first()?->roomsSold() ?? 0),
                default => 1,
            };
        }
        if (array_sum($w) <= 0) {
            throw new RuntimeException('Seasonal spreading follows the budgeted room-nights – enter the rooms statistics first.');
        }
        $sumW = array_sum($w);
        $done = 0.0;
        $lastM = end($open);
        foreach ($open as $m) {
            $v = $m === $lastM ? round($remaining - $done, 2) : round($remaining * $w[$m] / $sumW, 2);
            $l->{'m'.$m} = $v;
            $done += $v;
        }
        $l->save();
        self::event($b, 'spread', ($l->account?->code ?? '').' '.money($total).' – '.$how);

        return $l->fresh('account');
    }

    /** change open months by a percentage: scope account | income | expense | all */
    public static function adjust(Budget $b, string $scope, float $pct, ?int $accountId = null): int
    {
        self::mustBeDraft($b);
        if ($pct <= -100 || $pct > 1000) {
            throw new RuntimeException('The change must be between −100 % and +1000 %.');
        }
        $accounts = self::accountsFor($b->kind)->keyBy('id');
        if ($scope === 'account' && ! $accounts->has((int) $accountId)) {
            throw new RuntimeException('Choose the account to adjust.');
        }
        $locked = $b->lockedMonths();
        $n = 0;
        foreach ($b->lines()->get() as $l) {
            $a = $accounts[$l->account_head_id] ?? null;
            if (! $a || ($scope === 'account' && $a->id !== (int) $accountId) || ($scope === 'income' && $a->type !== 'income') || ($scope === 'expense' && $a->type !== 'expense')) {
                continue;
            }
            foreach (range(1, 12) as $m) {
                if (! in_array($m, $locked, true)) {
                    $l->{'m'.$m} = round($l->month($m) * (1 + $pct / 100), 2);
                }
            }
            if ($l->isDirty()) {
                $l->save();
                $n++;
            } elseif ($scope === 'account') {
                $n++;
            }
        }
        self::event($b, 'adjusted', sprintf('%+.1f %% on %s', $pct, $scope === 'account' ? ($accounts[(int) $accountId]->code ?? 'account') : $scope));

        return $n;
    }

    /** rooms statistics; $apply recalculates the room revenue line = rooms × occupancy × ADR */
    public static function saveStats(Budget $b, array $s, bool $apply = false): void
    {
        self::mustBeDraft($b);
        if ($b->isCapital()) {
            throw new RuntimeException('A capital plan has no rooms statistics – those belong to the P&L budget they drive.');
        }
        self::mustBeWholeHotel($b, 'Rooms statistics');
        $locked = $b->lockedMonths();
        DB::transaction(function () use ($b, $s, $apply, $locked) {
            foreach ($s as $m => $v) {
                $m = (int) $m;
                if ($m < 1 || $m > 12 || in_array($m, $locked, true) || ! is_array($v)) {
                    continue;
                }
                BudgetStat::updateOrCreate(['budget_id' => $b->id, 'month' => $m], [
                    'rooms_available' => max(0, (int) ($v['rooms_available'] ?? 0)),
                    'occupancy' => max(0, min(100, round((float) ($v['occupancy'] ?? 0), 2))),
                    'adr' => max(0, round((float) ($v['adr'] ?? 0), 2)),
                ]);
            }
            if ($apply) {
                $l = BudgetLine::firstOrCreate(['budget_id' => $b->id, 'account_head_id' => AccountHead::sys('rev_room')]);
                foreach ($b->stats()->get() as $st) {
                    if (! in_array($st->month, $locked, true)) {
                        $l->{'m'.$st->month} = $st->roomRevenue();
                    }
                }
                $l->save();
            }
        });
        self::event($b, 'stats', $apply ? 'Rooms statistics saved – room revenue recalculated' : 'Rooms statistics saved');
    }

    /** @return array<int, array> header + one row per account (code, name, 12 months, total) */
    public static function exportRows(Budget $b): array
    {
        $accounts = self::accountsFor($b->kind);
        $lines = $b->lines()->get()->keyBy('account_head_id');
        $rows = [array_merge(['Code', 'Account'], array_values(self::monthLabels($b->fiscal_year)), ['Total'])];
        foreach ($accounts as $a) {
            $l = $lines[$a->id] ?? null;
            $ms = $l ? $l->months() : array_fill(1, 12, 0.0);
            $rows[] = array_merge([(string) $a->code, $a->name], array_values($ms), [round(array_sum($ms), 2)]);
        }
        $t = self::totals($b);
        // v8.4 – a capital plan has no profit to show; its total is money committed to assets
        $rows[] = $b->isCapital()
            ? array_merge(['', 'Total capital'], array_fill(0, 12, ''), [$t['capital']])
            : array_merge(['', 'Net profit'], array_fill(0, 12, ''), [$t['net']]);

        return $rows;
    }

    /**
     * Import a CSV (code, [name], 12 months). All or nothing. Header / total rows (no account code) are skipped.
     *
     * @return array{0:int, 1:array<int,string>} accounts imported, errors
     */
    public static function importCsv(Budget $b, string $text): array
    {
        self::mustBeDraft($b);
        $byCode = self::accountsFor($b->kind)->keyBy(fn ($a) => (string) $a->code);
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        $locked = $b->lockedMonths();
        $errors = [];
        $plan = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            $c = array_map('trim', str_getcsv($line, str_contains($line, ';') && ! str_contains($line, ',') ? ';' : ',', '"', ''));
            $code = ltrim((string) ($c[0] ?? ''), "'");
            $row = $i + 1;
            if ($code === '' || ! preg_match('/\d/', $code)) {
                continue;   // header, section or total row
            }
            $a = $byCode[$code] ?? null;
            if (! $a) {
                $errors[] = "Row {$row}: unknown account code {$code}.";

                continue;
            }
            $vals = array_slice($c, 1);
            if ($vals && self::num($vals[0]) === null) {
                array_shift($vals);   // the name column
            }
            if (count($vals) < 12) {
                $errors[] = "Row {$row} ({$code}): expected 12 monthly amounts, found ".count($vals).'.';

                continue;
            }
            foreach (array_slice($vals, 0, 12) as $k => $v) {
                $n = self::num($v);
                if ($n === null) {
                    $errors[] = "Row {$row} ({$code}): “".mb_substr($v, 0, 20).'” is not a number.';

                    continue 2;
                }
                if (! in_array($k + 1, $locked, true)) {
                    $plan[$a->id]['m'.($k + 1)] = round($n, 2);
                }
            }
        }
        if (! $errors && ! $plan) {
            $errors[] = 'No account rows found – the first column must hold the account code.';
        }
        if ($errors) {
            return [0, $errors];
        }
        DB::transaction(function () use ($b, $plan) {
            foreach ($plan as $acc => $vals) {
                BudgetLine::updateOrCreate(['budget_id' => $b->id, 'account_head_id' => $acc], $vals);
            }
        });
        self::event($b, 'imported', count($plan).' account(s) from CSV');
        ActivityLogger::log('budget', 'import', "Budget {$b->budget_no}: ".count($plan).' account(s) imported from CSV', $b);

        return [count($plan), []];
    }

    // ───────────────────────── approval ─────────────────────────

    public static function submit(Budget $b, ?string $note = null): Budget
    {
        self::mustBeDraft($b);
        $t = self::totals($b);
        // v8.4 – a capital plan has no income and no expense at all, so "empty" is its capital figure, not a P&L the
        // plan does not have; without this a capital plan could never be submitted
        $empty = $b->isCapital()
            ? abs($t['capital']) < 0.005
            : (abs($t['income']) < 0.005 && abs($t['expense']) < 0.005);
        if ($empty) {
            throw new RuntimeException($b->isCapital()
                ? 'Every amount is zero – enter the capital plan before submitting it.'
                : 'Every amount is zero – enter the budget before submitting it.');
        }
        $b->update(['status' => 'submitted', 'submitted_by' => auth()->id(), 'submitted_at' => now(), 'return_note' => null]);
        self::event($b, 'submitted', $note);
        ActivityLogger::log('budget', 'submit', "Budget {$b->budget_no} submitted for approval", $b);

        return $b;
    }

    /**
     * The size of a version for the approval threshold: the larger of its budgeted income and its budgeted expenditure,
     * or — for a capital plan — the capital it commits. It is what a signature is given for, so it is the figure the
     * screen shows next to the rule.
     */
    public static function sizeOf(Budget $b): float
    {
        $t = self::totals($b);

        // a capital plan commits capital, so that is the figure a signature is given for
        return $b->isCapital() ? round(abs($t['capital']), 2) : round(max(abs($t['income']), abs($t['expense'])), 2);
    }

    /**
     * Who still has to sign this version. At or below the threshold in settings one approver is enough (the four-eyes
     * rule always applies); above it the version needs two, one after the other, from two different people — and it
     * stays `submitted` until both have signed, so nothing that acts on an approved version can see half an approval.
     *
     * @return array{size: float, threshold: float, needed: int, signed: int, first: ?User, first_at: ?Carbon,
     *               second: ?User, second_at: ?Carbon, awaiting: bool, mine: bool}
     */
    public static function approvalState(Budget $b): array
    {
        $b->loadMissing('approver', 'secondApprover');
        $threshold = (float) self::cfg()['dual_approval_above'];
        $size = self::sizeOf($b);
        $needed = $threshold > 0 && $size >= $threshold ? 2 : 1;
        $first = $b->approved_by ? $b->approver : null;
        $second = $b->second_approved_by ? $b->secondApprover : null;

        return ['size' => $size, 'threshold' => $threshold, 'needed' => $needed, 'signed' => ($first ? 1 : 0) + ($second ? 1 : 0),
            'first' => $first, 'first_at' => $b->approved_at, 'second' => $second, 'second_at' => $b->second_approved_at,
            'awaiting' => $needed === 2 && $first !== null && $second === null && $b->status === 'submitted',
            'mine' => $first !== null && (int) $b->approved_by === (int) auth()->id()];
    }

    public static function approve(Budget $b, ?string $note = null, bool $activate = true): Budget
    {
        if ($b->status !== 'submitted') {
            throw new RuntimeException('Only a submitted version can be approved.');
        }
        if ($b->submitted_by && $b->submitted_by === auth()->id()) {
            throw new RuntimeException('Four-eyes rule: someone other than the person who submitted it must approve it.');
        }
        $state = self::approvalState($b);
        // a signature already on the version decides what this click is, not the threshold as it stands today: if the
        // value was lowered between the two signatures, the second one is still the second one.
        $already = $b->approved_by !== null;

        // above the threshold, with nobody signed yet: record the first signature and leave the version submitted
        if (! $already && $state['needed'] === 2) {
            $b->update(['approved_by' => auth()->id(), 'approved_at' => now()]);
            self::event($b, 'approved_first', 'First approval for '.money($state['size']).' – over the '.money($state['threshold'])
                .' threshold, a second approver is required'.($note ? ' · '.$note : ''));
            ActivityLogger::log('budget', 'approve_first', "Budget {$b->budget_no} – first approval (over ".money($state['threshold']).'), needs a second', $b);

            return $b->fresh(['approver']);
        }
        if ($already && $state['mine']) {
            throw new RuntimeException('The second approval must come from someone else – '.($b->approver?->name ?? 'the first approver')
                .' has already approved this version.');
        }

        DB::transaction(function () use ($b, $note, $activate, $state, $already) {
            $data = ['status' => 'approved'];
            if ($already) {
                // somebody signed before this: this click is the second signature
                $data['second_approved_by'] = auth()->id();
                $data['second_approved_at'] = now();
            } else {
                $data['approved_by'] = auth()->id();
                $data['approved_at'] = now();
            }
            $b->update($data);
            self::event($b, 'approved', $note.($already ? ' · second approval – '.money($state['size']).' over the '.money($state['threshold']).' threshold' : ''));
            if ($activate) {
                self::activate($b);
            }
        });
        ActivityLogger::log('budget', 'approve', "Budget {$b->budget_no} approved".($already ? ' (second approval)' : '').($activate ? ' and activated' : ''), $b);

        return $b;
    }

    public static function returnToDraft(Budget $b, ?string $note): Budget
    {
        if ($b->status !== 'submitted') {
            throw new RuntimeException('Only a submitted version can be returned.');
        }
        $note = trim((string) $note);
        if ($note === '') {
            throw new RuntimeException('Say what needs to change.');
        }
        // v8.3 – the first signature (if one was given) goes with the return: the version is going to be edited, and a
        // signature must be for the figures that were signed.
        $b->update(['status' => 'draft', 'return_note' => mb_substr($note, 0, 255),
            'approved_by' => null, 'approved_at' => null, 'second_approved_by' => null, 'second_approved_at' => null]);
        self::event($b, 'returned', $note);
        ActivityLogger::log('budget', 'return', "Budget {$b->budget_no} returned: {$note}", $b);

        return $b;
    }

    public static function reopen(Budget $b, ?string $reason): Budget
    {
        if ($b->status !== 'approved') {
            throw new RuntimeException('Only an approved version can be re-opened.');
        }
        $reason = trim((string) $reason);
        if ($reason === '') {
            throw new RuntimeException('Give a reason for re-opening.');
        }
        $b->update(['status' => 'draft', 'is_active' => false, 'approved_by' => null, 'approved_at' => null, 'second_approved_by' => null, 'second_approved_at' => null,
            'submitted_by' => null, 'submitted_at' => null]);
        self::event($b, 'reopened', $reason);
        ActivityLogger::log('budget', 'reopen', "Budget {$b->budget_no} re-opened: {$reason}", $b);

        return $b;
    }

    public static function activate(Budget $b): Budget
    {
        if ($b->status !== 'approved') {
            throw new RuntimeException('Only an approved version can be made active.');
        }
        DB::transaction(function () use ($b) {
            // v8.1 – only the other versions of the SAME scope give way. Activating the whole-hotel budget must not
            // switch off a department's budget (or the requisition cap that reads it); each cost centre has its own
            // active version for the year.
            Budget::where('fiscal_year', $b->fiscal_year)->where('kind', $b->kind)->whereKeyNot($b->id)->where('is_active', true)
                ->when($b->department_id === null, fn ($q) => $q->whereNull('department_id'), fn ($q) => $q->where('department_id', $b->department_id))
                ->update(['is_active' => false]);
            $b->update(['is_active' => true]);
        });
        self::event($b, 'activated', 'Active '.strtolower($b->kindLabel()).' for '.$b->fyLabel()
            .($b->department_id ? ' · cost centre '.($b->department?->label() ?? '#'.$b->department_id) : ' · whole hotel'));

        return $b;
    }

    public static function delete(Budget $b): void
    {
        self::mustBeDraft($b);
        ActivityLogger::log('budget', 'delete', "Draft budget {$b->budget_no} ({$b->name}) deleted");
        $b->delete();
    }

    /** re-forecast: months up to $through take the posted actuals (locked), later months start from the budget */
    public static function forecast(Budget $b, string $through, ?string $name = null): Budget
    {
        if ($b->status !== 'approved' || $b->kind !== 'budget') {
            throw new RuntimeException('Re-forecast an approved budget.');
        }
        $t = Carbon::parse($through)->endOfMonth()->startOfDay();
        $months = self::months($b->fiscal_year);
        if ($t->lt($months[1]) || $t->gte($months[12])) {
            throw new RuntimeException('Pick a month-end inside the fiscal year, before its last month.');
        }
        if ($t->gt(Carbon::parse(LedgerService::cutoff()))) {
            throw new RuntimeException('That month is not closed in the ledger yet.');
        }
        $upTo = self::monthIndex($t);
        // v8.1 – a department's version re-forecasts that department: its own actuals, its own scope, no statistics
        $act = self::actuals($b->fiscal_year, null, $b->department_id);
        $actStats = $b->department_id ? [] : self::actualStats($b->fiscal_year);
        $b->load('lines', 'stats');

        return DB::transaction(function () use ($b, $t, $name, $upTo, $act, $actStats, $months) {
            $f = Budget::create([
                'budget_no' => Numbering::next('budget'), 'fiscal_year' => $b->fiscal_year, 'kind' => 'forecast', 'status' => 'draft', 'method' => 'copy',
                'name' => mb_substr(trim((string) $name), 0, 80) ?: 'Re-forecast after '.$t->format('M Y'), 'parent_id' => $b->id, 'actuals_through' => $t->toDateString(),
                'department_id' => $b->department_id,   // a departmental re-forecast stays in its cost centre

                'notes' => $b->notes, 'created_by' => auth()->id(),
            ]);
            $lines = $b->lines->keyBy('account_head_id');
            foreach (self::accounts() as $a) {
                $row = ['budget_id' => $f->id, 'account_head_id' => $a->id];
                foreach (range(1, 12) as $m) {
                    $bud = isset($lines[$a->id]) ? $lines[$a->id]->month($m) : 0.0;
                    $row['m'.$m] = $m <= $upTo && ! self::preLive($months[$m]) ? round((float) ($act[$a->id][$m] ?? 0), 2) : $bud;
                }
                BudgetLine::create($row);
            }
            $stats = $b->stats->keyBy('month');
            foreach ($b->department_id ? [] : range(1, 12) as $m) {
                $s = $stats[$m] ?? null;
                $a = $actStats[$m];
                $useActual = $m <= $upTo && ! self::preLive($months[$m]) && $a['available'];
                BudgetStat::create(['budget_id' => $f->id, 'month' => $m, 'rooms_available' => $useActual ? $a['available'] : ($s?->rooms_available ?? 0),
                    'occupancy' => $useActual ? min(100, $a['occupancy']) : ($s?->occupancy ?? 0), 'adr' => $useActual ? $a['adr'] : ($s?->adr ?? 0)]);
            }
            self::event($f, 'created', "Re-forecast of {$b->budget_no} – actuals to ".$t->format('M Y').($b->department_id ? ' · cost centre '.($b->department?->label() ?? '#'.$b->department_id) : ''));
            ActivityLogger::log('budget', 'forecast', "Re-forecast {$f->budget_no} of {$b->budget_no} created (actuals to ".$t->format('M Y').')', $f);

            return $f;
        });
    }

    // ───────────────────────── budget vs actual ─────────────────────────

    protected static function cmp(float $actual, float $budget, bool $income): array
    {
        $var = round($actual - $budget, 2);

        return [$var, abs($budget) >= 0.005 ? round($var * 100 / abs($budget), 1) : null, $income ? $var >= -0.004 : $var <= 0.004];
    }

    protected static function row(array $base, bool $income, array $v, array $cfg, bool $canFlag): array
    {
        [$var, $pct, $fav] = self::cmp($v['actual'], $v['budget'], $income);
        [$yv, $ypct, $yfav] = self::cmp($v['ytd_actual'], $v['ytd_budget'], $income);
        $flag = $canFlag && abs($var) >= max(0.01, $cfg['var_amount']) && ($pct === null || abs($pct) >= $cfg['var_pct']);

        return $base + [
            'type' => $income ? 'income' : 'expense',
            'actual' => round($v['actual'], 2), 'budget' => round($v['budget'], 2), 'var' => $var, 'var_pct' => $pct, 'fav' => $fav,
            'ytd_actual' => round($v['ytd_actual'], 2), 'ytd_budget' => round($v['ytd_budget'], 2), 'ytd_var' => $yv, 'ytd_var_pct' => $ypct, 'ytd_fav' => $yfav,
            'year_budget' => round($v['year_budget'], 2), 'forecast' => $v['forecast'] === null ? null : round($v['forecast'], 2),
            'fc_var' => $v['forecast'] === null ? null : round($v['forecast'] - $v['year_budget'], 2), 'flag' => $flag,
        ];
    }

    /**
     * Month + year-to-date comparison of an approved budget with the ledger. The month in progress is compared with the
     * budget pro-rated to the closed days; months before go-live are shown but not compared nor counted in YTD.
     */
    public static function bva(Budget $b, int $month, ?Budget $forecast = null, bool $withRoomStats = true): array
    {
        if ($b->isCapital()) {
            throw new RuntimeException('A capital plan is not compared with the profit and loss – its spend to date is on the plan itself.');
        }
        $cfg = self::cfg();
        $month = max(1, min(12, $month));
        $months = self::months($b->fiscal_year);
        $cut = Carbon::parse(LedgerService::cutoff());
        $start = $months[$month];
        $factor = self::elapsed($start, $cut);
        $preLive = self::preLive($start);
        $future = $factor <= 0;
        $partial = $factor > 0 && $factor < 1;
        $fac = [];
        $ytdMonths = [];
        foreach (range(1, $month) as $m) {
            $fac[$m] = self::elapsed($months[$m], $cut);
            if (! self::preLive($months[$m])) {
                $ytdMonths[] = $m;
            }
        }
        $ytdFrom = $ytdMonths && $ytdMonths[0] > 1 ? $months[$ytdMonths[0]] : null;
        // v8.1 – a departmental version is compared with the lines the ledger charged to that cost centre, never with
        // the hotel's whole P&L; its rooms statistics do not exist, so the KPI block is omitted rather than invented.
        $act = self::actuals($b->fiscal_year, null, $b->department_id);
        $lines = $b->lines()->get()->keyBy('account_head_id');
        $fLines = $forecast ? $forecast->lines()->get()->keyBy('account_head_id') : collect();
        $rows = collect();
        $secV = [];
        foreach (self::accounts() as $a) {
            $l = $lines[$a->id] ?? null;
            $bm = $l ? $l->months() : array_fill(1, 12, 0.0);
            $am = $act[$a->id] ?? [];
            $v = [
                'actual' => (float) ($am[$month] ?? 0),
                'budget' => $bm[$month] * $factor,
                'ytd_actual' => array_sum(array_map(fn ($m) => (float) ($am[$m] ?? 0), $ytdMonths)),
                'ytd_budget' => array_sum(array_map(fn ($m) => $bm[$m] * $fac[$m], $ytdMonths)),
                'year_budget' => array_sum($bm),
                'forecast' => $forecast ? (isset($fLines[$a->id]) ? $fLines[$a->id]->total() : 0.0) : null,
            ];
            if (abs($v['actual']) + abs($v['ytd_actual']) + abs($v['year_budget']) + abs((float) $v['forecast']) + abs(array_sum($am)) < 0.005) {
                continue;
            }
            foreach ($v as $k => $x) {
                $secV[$a->section][$k] = ($secV[$a->section][$k] ?? 0) + (float) $x;
            }
            $rows->push(self::row(['id' => $a->id, 'code' => (string) $a->code, 'name' => $a->name, 'section' => $a->section], $a->type === 'income', $v, $cfg, ! $preLive && ! $future));
        }
        $zero = ['actual' => 0, 'budget' => 0, 'ytd_actual' => 0, 'ytd_budget' => 0, 'year_budget' => 0, 'forecast' => $forecast ? 0 : null];
        $sections = [];
        foreach (self::SECTIONS as $k => $label) {
            $v = ($secV[$k] ?? []) + $zero;
            if (! $forecast) {
                $v['forecast'] = null;
            }
            $sections[$k] = self::row(['id' => null, 'code' => '', 'name' => 'Total '.strtolower($label), 'section' => $k], in_array($k, self::INCOME_SECTIONS, true), $v, $cfg, false);
        }
        $combine = function (array $plus, array $minus, string $name) use ($sections, $cfg, $forecast) {
            $v = [];
            foreach (['actual', 'budget', 'ytd_actual', 'ytd_budget', 'year_budget', 'forecast'] as $k) {
                $v[$k] = array_sum(array_map(fn ($s) => (float) $sections[$s][$k], $plus)) - array_sum(array_map(fn ($s) => (float) $sections[$s][$k], $minus));
            }
            if (! $forecast) {
                $v['forecast'] = null;
            }

            return self::row(['id' => null, 'code' => '', 'name' => $name, 'section' => 'result'], true, $v, $cfg, false);
        };
        $totals = [
            'revenue' => $sections['revenue'],
            'gop' => $combine(['revenue'], ['direct', 'opex'], 'Gross operating profit (GOP)'),
            'net' => $combine(['revenue', 'other_income'], ['direct', 'opex', 'nonop'], 'Net profit'),
        ];

        // rooms KPIs – budget pro-rated like the P&L
        if ($b->department_id) {
            return [
                'rows' => $rows, 'sections' => $sections, 'totals' => $totals, 'kpis' => null,
                'meta' => ['month' => $month, 'start' => $start, 'cutoff' => $cut, 'factor' => $factor, 'partial' => $partial, 'future' => $future, 'pre_live' => $preLive,
                    'ytd_from' => $ytdFrom, 'flagged' => $rows->where('flag', true)->count(), 'cost_centre' => $b->department?->label() ?? '#'.$b->department_id],
            ];
        }
        // The room KPI block reads stay rows and the sellable-room calendar; the dashboard snapshot
        // card shows revenue/GOP/flagged only, so callers that do not display the KPI grid can skip it.
        $as = $withRoomStats ? self::actualStats($b->fiscal_year) : null;
        $st = $withRoomStats ? $b->stats()->get()->keyBy('month') : null;
        $kpi = function (array $ms) use ($as, $st, $fac) {
            $A = ['available' => 0, 'sold' => 0, 'revenue' => 0.0];
            $B = ['available' => 0.0, 'sold' => 0.0, 'revenue' => 0.0];
            foreach ($ms as $m) {
                $A['available'] += $as[$m]['available'];
                $A['sold'] += $as[$m]['sold'];
                $A['revenue'] += $as[$m]['revenue'];
                if ($s = $st[$m] ?? null) {
                    $B['available'] += $s->rooms_available * $fac[$m];
                    $B['sold'] += $s->roomsSold() * $fac[$m];
                    $B['revenue'] += $s->roomRevenue() * $fac[$m];
                }
            }
            $fmt = fn ($x) => ['available' => (int) round($x['available']), 'sold' => (int) round($x['sold']), 'revenue' => round($x['revenue'], 2),
                'occupancy' => $x['available'] ? round($x['sold'] * 100 / $x['available'], 1) : 0.0, 'adr' => $x['sold'] ? round($x['revenue'] / $x['sold'], 2) : 0.0,
                'revpar' => $x['available'] ? round($x['revenue'] / $x['available'], 2) : 0.0];

            return ['actual' => $fmt($A), 'budget' => $fmt($B)];
        };
        $kpis = $withRoomStats ? ['month' => $kpi([$month]), 'ytd' => $kpi($ytdMonths)] : null;
        // an un-started month: show the full-month budget KPIs
        if ($withRoomStats && $future && ($s = $st[$month] ?? null)) {
            $kpis['month']['budget'] = ['available' => $s->rooms_available, 'sold' => $s->roomsSold(), 'revenue' => $s->roomRevenue(), 'occupancy' => round($s->occupancy, 1), 'adr' => $s->adr, 'revpar' => $s->revpar()];
        }

        return [
            'rows' => $rows, 'sections' => $sections, 'totals' => $totals, 'kpis' => $kpis,
            'meta' => ['month' => $month, 'start' => $start, 'cutoff' => $cut, 'factor' => $factor, 'partial' => $partial, 'future' => $future, 'pre_live' => $preLive,
                'ytd_from' => $ytdFrom, 'flagged' => $rows->where('flag', true)->count()],
        ];
    }

    /** comments of a period grouped by account id ('' = general notes on the month) */
    /**
     * Explanations of one month of ONE version, grouped by account ('' = the note on the month itself).
     *
     * v8.2 – scoped to the version. It used to filter on the period alone, so the comparison screen showed the
     * comments written against last year's version, a department's version and an old re-forecast side by side with
     * figures they were never written about.
     */
    public static function commentsFor(Budget $b, string $period): Collection
    {
        return BudgetComment::with('user', 'assignee', 'resolver')->where('budget_id', $b->id)->where('period', $period)
            ->orderBy('id')->get()->groupBy(fn ($c) => (string) ($c->account_head_id ?? ''));
    }

    public static function comment(Budget $b, ?int $accountId, string $period, string $body, ?float $actual = null, ?float $budget = null): BudgetComment
    {
        $body = trim($body);
        if (mb_strlen($body) < 3) {
            throw new RuntimeException('Write the explanation.');
        }
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw new RuntimeException('Invalid month.');
        }
        $fyPeriods = array_map(fn ($c) => $c->format('Y-m'), self::months($b->fiscal_year));
        if (! in_array($period, $fyPeriods, true)) {
            throw new RuntimeException('That month is outside '.$b->fyLabel().'.');
        }
        if ($accountId && ! self::accountsFor($b->kind)->firstWhere('id', $accountId)) {
            throw new RuntimeException('Unknown account.');
        }
        $c = BudgetComment::create(['budget_id' => $b->id, 'account_head_id' => $accountId ?: null, 'period' => $period, 'body' => mb_substr($body, 0, 2000),
            'actual' => $actual, 'budget' => $budget, 'user_id' => auth()->id()]);
        ActivityLogger::log('budget', 'comment', "Variance comment on {$period}".($accountId ? ' ('.AccountHead::find($accountId)?->code.')' : '').': '.mb_substr($body, 0, 120), $c);

        return $c;
    }

    /** dashboard card: revenue and GOP month-to-date against the pro-rated active budget */
    public static function snapshot(): array
    {
        $cut = Carbon::parse(LedgerService::cutoff());
        $b = self::active(self::fyFor($cut));
        $pending = Budget::where('status', 'submitted')->count();
        if (! $b) {
            return ['budget' => null, 'pending' => $pending];
        }
        $m = self::monthIndex($cut);
        $d = self::bva($b, $m, null, false);

        return ['budget' => $b, 'month' => $m, 'cutoff' => $cut, 'revenue' => $d['totals']['revenue'], 'gop' => $d['totals']['gop'], 'flagged' => $d['meta']['flagged'], 'pending' => $pending];
    }

    // ───────────────────────── variance review & month sign-off (v8.2) ─────────────────────────

    /** the day a month's explanations fall due: the end of the month plus the days in the setting */
    public static function explainDue(Budget $b, int $month): Carbon
    {
        return self::months($b->fiscal_year)[max(1, min(12, $month))]->copy()->endOfMonth()->startOfDay()
            ->addDays(self::cfg()['explain_due_days']);
    }

    /**
     * The variances one month of one version must account for: exactly the rows the comparison flags (the rule lives
     * in one place — bva(), not here), each with the explanation that covers it, if there is one, and who owes it.
     *
     * @return array{rows: Collection, period: string, month: int, due: Carbon, flagged: int, explained: int, outstanding: int}
     */
    public static function worklist(Budget $b, int $month): array
    {
        $month = max(1, min(12, $month));
        $d = self::bva($b, $month);
        $period = self::period($b->fiscal_year, $month);
        $comments = self::commentsFor($b, $period);
        $due = self::explainDue($b, $month);
        $today = bdate()->copy()->startOfDay();

        $rows = collect($d['rows'])->filter(fn ($r) => ! empty($r['flag']))->map(function ($r) use ($comments, $due, $today) {
            $c = $comments->get((string) $r['id'])?->last();
            $explained = $c ? $c->isExplained() : false;

            return [
                'row' => $r,
                'comment' => $c,
                'explained' => $explained,
                'owner' => $c?->assignee,
                'due_on' => $c?->due_on ?: ($explained ? null : $due),
                'overdue' => ! $explained && $today->gt($due),
                'status' => $c ? $c->statusLabel() : 'Needs explaining',
                'tone' => $c ? $c->statusTone() : 'amber',
            ];
        })->values();

        return ['rows' => $rows, 'period' => $period, 'month' => $month, 'due' => $due,
            'flagged' => $rows->count(), 'explained' => $rows->where('explained', true)->count(),
            'outstanding' => $rows->where('explained', false)->count(), 'meta' => $d['meta']];
    }

    /**
     * Record (or replace) the explanation of one flagged variance, with the person who owes it and when it is due.
     * The version, the month and the account are fixed by the row it was written on.
     */
    public static function explain(Budget $b, int $month, ?int $accountId, string $body, ?int $assignedTo = null, ?string $dueOn = null): BudgetComment
    {
        $month = max(1, min(12, $month));
        if ($assignedTo !== null && ! \App\Models\User::whereKey($assignedTo)->exists()) {
            throw new RuntimeException('That person is not a user of this hotel.');
        }
        $defaultDue = $dueOn === null || $dueOn === '';
        $due = $defaultDue ? self::explainDue($b, $month) : Carbon::parse($dueOn)->startOfDay();
        $c = self::comment($b, $accountId, self::period($b->fiscal_year, $month), $body);
        $c->forceFill(['assigned_to' => $assignedTo, 'due_on' => $due->toDateString(), 'status' => 'explained',
            'resolved_at' => null, 'resolved_by' => null])->save();
        if ($defaultDue) {
            self::event($b, 'explain', 'Variance explained for '.$c->period.($c->account?->code ? ' · '.$c->account->code : '').' – due '.$due->format('d M Y'));
        }

        return $c->fresh('assignee');
    }

    /** the day's objections to signing a month off – every one of them a sentence somebody can act on */
    public static function signOffBlockers(Budget $b, int $month): array
    {
        $month = max(1, min(12, $month));
        $period = self::period($b->fiscal_year, $month);
        $out = [];
        if ($b->status !== 'approved') {
            $out[] = 'Only an approved version can be signed off – this one is '.strtolower($b->statusLabel()).'.';
        }
        if ($signed = self::signoffFor($b, $period)) {
            $out[] = 'The month was already signed off by '.($signed->signer?->name ?? 'a user').' on '
                .($signed->signed_at?->format('d M Y') ?? '—').'.';
        }
        $wl = self::worklist($b, $month);
        if ($wl['meta']['pre_live']) {
            $out[] = 'The month is before the go-live date – the ledger does not hold complete figures, so there is nothing to review.';
        }
        if ($wl['meta']['future']) {
            $out[] = 'The month has not started yet – there is nothing to sign off.';
        }
        foreach ($wl['rows']->where('explained', false) as $r) {
            $acc = trim(($r['row']['code'] ? $r['row']['code'].' ' : '').$r['row']['name']);
            // the amount is printed unsigned and the word carries the direction: "GH₵ 13,100.00 under", never "GH₵ -13,100.00 under"
            $out[] = $acc.' ('.money(abs($r['row']['var'])).' '.($r['row']['var'] > 0 ? 'over' : 'under').') has no explanation.';
        }

        return $out;
    }

    /** sign a month of this version off; refused, naming every reason, while one variance is unexplained */
    public static function signOff(Budget $b, int $month, ?string $note = null): BudgetSignoff
    {
        $month = max(1, min(12, $month));
        $period = self::period($b->fiscal_year, $month);
        if ($why = self::signOffBlockers($b, $month)) {
            throw new RuntimeException('Not signed off: '.implode(' ', array_slice($why, 0, 6)).(count($why) > 6 ? ' …' : ''));
        }
        $wl = self::worklist($b, $month);
        $s = BudgetSignoff::updateOrCreate(['budget_id' => $b->id, 'period' => $period], [
            'flagged' => $wl['flagged'], 'explained' => $wl['explained'], 'signed_by' => auth()->id(), 'signed_at' => now(),
            'note' => $note ? mb_substr(trim($note), 0, 255) : null,
        ]);
        self::event($b, 'signoff', $period.' signed off – '.$wl['explained'].' of '.$wl['flagged'].' flagged variance(s) explained'.($note ? ': '.$note : ''));
        ActivityLogger::log('budget', 'signoff', "Budget {$b->budget_no} – {$period} signed off ({$wl['explained']}/{$wl['flagged']} explained)", $s);

        return $s;
    }

    public static function signoffFor(Budget $b, string $period): ?BudgetSignoff
    {
        return BudgetSignoff::with('signer')->where('budget_id', $b->id)->where('period', $period)->first();
    }

    /** accept an explanation, which closes it for the sign-off count without changing the words */
    public static function acceptExplanation(BudgetComment $c, bool $accepted = true): BudgetComment
    {
        $c->forceFill(['status' => $accepted ? 'accepted' : 'explained', 'resolved_at' => $accepted ? now() : null, 'resolved_by' => $accepted ? auth()->id() : null])->save();

        return $c;
    }

    // ───────────────────────── spend control (supplier expense bills) ─────────────────────────

    /**
     * Would these bill lines take an expense account over the active budget? Returns (and remembers in $lastWarnings)
     * one warning per account. In block mode a warning throws unless the user holds budget.override and gave a reason.
     *
     * @param  array<int, array{account_head_id:int, amount:float}>  $lines
     */
    /**
     * Spend control on a supplier bill (v3.6, capital-aware from v8.4).
     *
     * Two plans, two answers. An operating cost is measured against the active P&L budget, as it always was. A purchase
     * coded to a capital asset head is measured against the active *capital plan* — before v8.4 every asset line was
     * simply skipped, so capital spending was invisible to the budget and the only way to get it past an approver was to
     * code it to repairs. Each plan is controlled by its own setting (off / warn / block).
     *
     * @return array<int, array> the warnings raised, each naming the plan it came from
     */
    public static function checkSpend(array $lines, string $date, ?string $reason = null): array
    {
        self::$lastWarnings = [];
        $cfg = self::cfg();
        if ($cfg['control'] === 'off' && $cfg['capex_control'] === 'off') {
            return [];
        }
        $heads = AccountHead::whereIn('type', ['expense', 'asset'])->get()->keyBy('id');
        $operating = [];
        $capital = [];
        foreach ($lines as $l) {
            $id = (int) ($l['account_head_id'] ?? 0);
            $amt = (float) ($l['amount'] ?? 0);
            if ($amt <= 0 || ! isset($heads[$id])) {
                continue;
            }
            if ($heads[$id]->type === 'asset') {
                if (self::isCapitalHead($heads[$id])) {
                    $capital[$id] = ($capital[$id] ?? 0) + $amt;
                }
                // cash, receivables and the like are neither spend nor capital
                continue;
            }
            $operating[$id] = ($operating[$id] ?? 0) + $amt;
        }
        if (! $operating && ! $capital) {
            return [];
        }
        $fy = self::fyFor($date);
        $months = self::months($fy);
        $warn = [];
        if ($operating && $cfg['control'] !== 'off') {
            $warn = self::overPlan(self::active($fy), $operating, $heads, $months, $date, $cfg['control_basis'], 'operating');
        }
        if ($capital && $cfg['capex_control'] !== 'off') {
            // a capital plan is an annual commitment, so it is read to date: what the plan allows by this month
            $warn = array_merge($warn, self::overPlan(self::active($fy, 'capital'), $capital, $heads, $months, $date, 'ytd', 'capital'));
        }
        $blocking = array_values(array_filter($warn, fn ($w) => ($w['plan'] === 'capital' ? $cfg['capex_control'] : $cfg['control']) === 'block'));
        if ($blocking) {
            $allCapital = count(array_filter($blocking, fn ($w) => $w['plan'] === 'capital')) === count($blocking);
            $what = $allCapital ? 'Over the capital plan' : 'Over budget';
            if (! can_do('budget.override')) {
                throw new RuntimeException($what.' – '.self::describe($blocking).'. Someone with the budget override permission must enter this bill.');
            }
            if (mb_strlen(trim((string) $reason)) < 3) {
                throw new RuntimeException($what.' – '.self::describe($blocking).'. Give a reason to exceed the budget.');
            }
        }
        self::$lastWarnings = $warn;

        return $warn;
    }

    /**
     * What one plan allows by a date against what the ledger has already taken, per account.
     *
     * @param  Budget|null  $plan  the active version of that plan; null means the hotel has not approved one
     * @return array<int, array>
     */
    protected static function overPlan(?Budget $plan, array $new, Collection $heads, array $months, string $date, string $basis, string $kind): array
    {
        // no approved operating budget means no operating control (as before); no approved capital plan is itself the
        // finding — capital is being bought against a plan nobody has approved
        if (! $plan && $kind === 'operating') {
            return [];
        }
        $m = self::monthIndex($date);
        $range = match ($basis) {
            'month' => [$m, $m],
            'annual' => [1, 12],
            default => [1, $m],
        };
        $live = self::liveFrom();
        $ms = array_values(array_filter(range($range[0], $range[1]), fn ($x) => ! self::preLive($months[$x])));
        if (! $ms) {
            return [];
        }
        $from = $months[$ms[0]]->toDateString();
        $to = $months[end($ms)]->copy()->endOfMonth()->toDateString();
        $lines = $plan ? $plan->lines()->whereIn('account_head_id', array_keys($new))->get()->keyBy('account_head_id') : collect();
        $warn = [];
        foreach ($new as $id => $amt) {
            $budget = isset($lines[$id]) ? array_sum(array_map(fn ($x) => $lines[$id]->month($x), $ms)) : 0.0;
            $spent = (float) GlLine::where('account_head_id', $id)->where('closing', false)->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->sum(DB::raw('debit - credit'));
            if ($spent + $amt > $budget + 0.005) {
                $warn[] = ['account' => $heads[$id], 'budget' => round($budget, 2), 'spent' => round($spent, 2), 'new' => round($amt, 2),
                    'over' => round($spent + $amt - $budget, 2), 'basis' => $basis, 'plan' => $kind];
            }
        }

        return $warn;
    }

    public static function describe(array $warnings): string
    {
        $basis = ['month' => "the month's budget", 'ytd' => 'the year-to-date budget', 'annual' => 'the full-year budget'];
        $capBasis = ['month' => 'the capital plan for this month', 'ytd' => 'the capital plan to date', 'annual' => 'the capital plan for the year'];

        return implode('; ', array_map(function ($w) use ($basis, $capBasis) {
            $capital = ($w['plan'] ?? 'operating') === 'capital';
            $words = $capital ? ($capBasis[$w['basis']] ?? 'the capital plan') : ($basis[$w['basis']] ?? 'the budget');

            return ($w['account']->code ? $w['account']->code.' ' : '').$w['account']->name.': '.money($w['spent'] + $w['new']).' against '
                .($w['budget'] > 0 ? $words.' of '.money($w['budget']) : ($capital ? 'no approved capital plan' : 'no budget')).' ('.money($w['over']).' over)';
        }, $warnings));
    }
}
