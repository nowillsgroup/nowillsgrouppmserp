<?php

namespace App\Services;

use App\Models\AccountHead;
use App\Models\Budget;
use App\Models\BudgetComment;
use App\Models\BudgetEvent;
use App\Models\BudgetLine;
use App\Models\BudgetStat;
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
    ];

    public const INCOME_SECTIONS = ['revenue', 'other_income'];

    public const DEFAULTS = [
        'budget_fy_start' => '4',          // fiscal year starts in April
        'budget_live_from' => '',          // ledger go-live: earlier months are not compared
        'budget_var_pct' => '10',          // variance needs an explanation at ≥ this % …
        'budget_var_amount' => '10000',    // … and ≥ this amount
        'budget_control' => 'warn',        // spend control on supplier expense bills: off | warn | block
        'budget_control_basis' => 'ytd',   // month | ytd | annual
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
        ];
        foreach ($map as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        Setting::$version++;
        ActivityLogger::log('budget', 'settings', 'Budget settings updated: FY starts month '.$map['budget_fy_start'].', go-live '.($map['budget_live_from'] ?: '—')
            .', spend control '.$map['budget_control'].' ('.$map['budget_control_basis'].')');
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

    /**
     * Posted actuals of a fiscal year in natural sign (income Cr+, expense Dr+) up to the cut-off.
     *
     * @return array<int, array<int, float>> account id => [fiscal month => amount]
     */
    public static function actuals(int $fy, ?string $cutoff = null): array
    {
        $months = self::months($fy);
        $from = $months[1]->toDateString();
        $to = min($months[12]->copy()->endOfMonth()->toDateString(), $cutoff ?? LedgerService::cutoff());
        if ($to < $from) {
            return [];
        }
        $types = AccountHead::whereIn('type', ['income', 'expense'])->pluck('type', 'id');
        $rows = GlLine::whereBetween('date', [$from, $to])->where('closing', false)->whereIn('account_head_id', $types->keys())
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
     * Actual rooms statistics per fiscal month on closed days: available = active rooms × closed days, sold = nights of
     * checked-in / checked-out stays, revenue = room revenue in the ledger.
     *
     * @return array<int, array{available:int, sold:int, occupancy:float, adr:float, revpar:float, revenue:float}>
     */
    public static function actualStats(int $fy): array
    {
        $cut = Carbon::parse(LedgerService::cutoff());
        $rooms = Room::where('is_active', true)->count();
        $months = self::months($fy);
        $revAcc = AccountHead::sys('rev_room');
        $act = self::actuals($fy);
        $fyEnd = $months[12]->copy()->endOfMonth()->startOfDay();
        $stays = ReservationRoom::whereIn('status', ['checked_in', 'checked_out'])
            ->where('arrival_date', '<=', min($fyEnd, $cut)->toDateString())->whereDate('departure_date', '>', $months[1]->toDateString())
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
            $available = $rooms * $days;
            $revenue = round((float) ($act[$revAcc][$m] ?? 0), 2);
            $out[$m] = ['available' => $available, 'sold' => $sold, 'occupancy' => $available ? round($sold * 100 / $available, 2) : 0.0,
                'adr' => $sold ? round($revenue / $sold, 2) : 0.0, 'revpar' => $available ? round($revenue / $available, 2) : 0.0, 'revenue' => $revenue];
        }

        return $out;
    }

    // ───────────────────────── versions ─────────────────────────

    public static function totals(Budget $b): array
    {
        $types = AccountHead::whereIn('type', ['income', 'expense'])->pluck('type', 'id');
        $t = ['income' => 0.0, 'expense' => 0.0];
        foreach ($b->relationLoaded('lines') ? $b->lines : $b->lines()->get() as $l) {
            if (isset($types[$l->account_head_id])) {
                $t[$types[$l->account_head_id]] += $l->total();
            }
        }

        return ['income' => round($t['income'], 2), 'expense' => round($t['expense'], 2), 'net' => round($t['income'] - $t['expense'], 2)];
    }

    public static function active(int $fy, string $kind = 'budget'): ?Budget
    {
        return Budget::where('fiscal_year', $fy)->where('kind', $kind)->where('status', 'approved')->where('is_active', true)->latest('id')->first();
    }

    protected static function event(Budget $b, string $action, ?string $note = null): void
    {
        BudgetEvent::create(['budget_id' => $b->id, 'action' => $action, 'note' => $note ? mb_substr(trim($note), 0, 255) : null, 'user_id' => auth()->id(), 'created_at' => now()]);
    }

    protected static function mustBeDraft(Budget $b): void
    {
        if ($b->status !== 'draft') {
            throw new RuntimeException("{$b->budget_no} is {$b->statusLabel()} – only drafts can be changed (re-open it first).");
        }
    }

    /** default rooms statistics: every active room, every day of the month, nothing sold yet */
    protected static function blankStats(int $fy): array
    {
        $rooms = Room::where('is_active', true)->count();
        $o = [];
        foreach (self::months($fy) as $m => $s) {
            $o[$m] = ['rooms_available' => $rooms * $s->daysInMonth, 'occupancy' => 0, 'adr' => 0];
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
        $gi = max(-100, min(1000, (float) ($d['growth_income'] ?? 0)));
        $ge = max(-100, min(1000, (float) ($d['growth_expense'] ?? 0)));
        $accounts = self::accounts();
        $src = null;
        $base = [];   // account id => [m => amount]
        $stats = self::blankStats($fy);

        if ($method === 'copy') {
            $src = ! empty($d['source_id']) ? Budget::with('lines', 'stats')->find((int) $d['source_id']) : null;
            if (! $src) {
                throw new RuntimeException('Choose the version to copy.');
            }
            foreach ($src->lines as $l) {
                $base[$l->account_head_id] = $l->months();
            }
            foreach ($src->stats as $s) {
                $stats[$s->month] = ['rooms_available' => $stats[$s->month]['rooms_available'] ?: $s->rooms_available, 'occupancy' => $s->occupancy, 'adr' => round($s->adr * (1 + $gi / 100), 2)];
            }
        } elseif ($method === 'last_year') {
            $base = self::actuals($fy - 1);
            if (! $base) {
                throw new RuntimeException('The ledger holds no actuals for '.self::fyLabel($fy - 1).' – start blank or use the run-rate.');
            }
            foreach (self::actualStats($fy - 1) as $m => $a) {
                if ($a['available']) {
                    $stats[$m]['occupancy'] = round(min(100, $a['occupancy']), 2);
                    $stats[$m]['adr'] = round($a['adr'] * (1 + $gi / 100), 2);
                }
            }
        } elseif ($method === 'run_rate') {
            $closed = self::closedMonths(3);
            if (! $closed) {
                throw new RuntimeException('No closed month since go-live yet to base a run-rate on.');
            }
            $sum = [];
            foreach ($closed as [$cfy, $cm]) {
                foreach (self::actuals($cfy) as $acc => $ms) {
                    $sum[$acc] = ($sum[$acc] ?? 0) + ($ms[$cm] ?? 0);
                }
            }
            foreach ($sum as $acc => $v) {
                $base[$acc] = array_fill(1, 12, round($v / count($closed), 2));
            }
        }

        return DB::transaction(function () use ($d, $fy, $method, $gi, $ge, $accounts, $src, $base, $stats) {
            $b = Budget::create([
                'budget_no' => Numbering::next('budget'), 'fiscal_year' => $fy, 'kind' => 'budget', 'status' => 'draft', 'method' => $method,
                'name' => mb_substr(trim((string) ($d['name'] ?? '')), 0, 80) ?: 'Budget '.self::fyLabel($fy),
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
            self::event($b, 'created', Budget::METHODS[$method].($src ? ' ('.$src->budget_no.')' : '').($gi || $ge ? sprintf(' · growth income %+.1f %%, expense %+.1f %%', $gi, $ge) : ''));
            ActivityLogger::log('budget', 'create', "Budget {$b->budget_no} ({$b->name}) created – ".Budget::METHODS[$method], $b);

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
        if (! self::accounts()->firstWhere('id', $accountId)) {
            throw new RuntimeException('That account is not an income or expense account.');
        }

        return BudgetLine::firstOrCreate(['budget_id' => $b->id, 'account_head_id' => $accountId]);
    }

    /** save grid edits: $cells[account id][month] = amount, $notes[account id] = assumption */
    public static function saveGrid(Budget $b, array $cells, array $notes = []): int
    {
        self::mustBeDraft($b);
        $locked = $b->lockedMonths();
        $valid = self::accounts()->pluck('code', 'id');
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
        $accounts = self::accounts()->keyBy('id');
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
        $accounts = self::accounts();
        $lines = $b->lines()->get()->keyBy('account_head_id');
        $rows = [array_merge(['Code', 'Account'], array_values(self::monthLabels($b->fiscal_year)), ['Total'])];
        foreach ($accounts as $a) {
            $l = $lines[$a->id] ?? null;
            $ms = $l ? $l->months() : array_fill(1, 12, 0.0);
            $rows[] = array_merge([(string) $a->code, $a->name], array_values($ms), [round(array_sum($ms), 2)]);
        }
        $t = self::totals($b);
        $rows[] = array_merge(['', 'Net profit'], array_fill(0, 12, ''), [$t['net']]);

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
        $byCode = self::accounts()->keyBy(fn ($a) => (string) $a->code);
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
        if (abs($t['income']) < 0.005 && abs($t['expense']) < 0.005) {
            throw new RuntimeException('Every amount is zero – enter the budget before submitting it.');
        }
        $b->update(['status' => 'submitted', 'submitted_by' => auth()->id(), 'submitted_at' => now(), 'return_note' => null]);
        self::event($b, 'submitted', $note);
        ActivityLogger::log('budget', 'submit', "Budget {$b->budget_no} submitted for approval", $b);

        return $b;
    }

    public static function approve(Budget $b, ?string $note = null, bool $activate = true): Budget
    {
        if ($b->status !== 'submitted') {
            throw new RuntimeException('Only a submitted version can be approved.');
        }
        if ($b->submitted_by && $b->submitted_by === auth()->id()) {
            throw new RuntimeException('Four-eyes rule: someone other than the person who submitted it must approve it.');
        }
        DB::transaction(function () use ($b, $note, $activate) {
            $b->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
            self::event($b, 'approved', $note);
            if ($activate) {
                self::activate($b);
            }
        });
        ActivityLogger::log('budget', 'approve', "Budget {$b->budget_no} approved".($activate ? ' and activated' : ''), $b);

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
        $b->update(['status' => 'draft', 'return_note' => mb_substr($note, 0, 255)]);
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
        $b->update(['status' => 'draft', 'is_active' => false, 'approved_by' => null, 'approved_at' => null, 'submitted_by' => null, 'submitted_at' => null]);
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
            Budget::where('fiscal_year', $b->fiscal_year)->where('kind', $b->kind)->whereKeyNot($b->id)->where('is_active', true)->update(['is_active' => false]);
            $b->update(['is_active' => true]);
        });
        self::event($b, 'activated', 'Active '.strtolower($b->kindLabel()).' for '.$b->fyLabel());

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
        $act = self::actuals($b->fiscal_year);
        $actStats = self::actualStats($b->fiscal_year);
        $b->load('lines', 'stats');

        return DB::transaction(function () use ($b, $t, $name, $upTo, $act, $actStats, $months) {
            $f = Budget::create([
                'budget_no' => Numbering::next('budget'), 'fiscal_year' => $b->fiscal_year, 'kind' => 'forecast', 'status' => 'draft', 'method' => 'copy',
                'name' => mb_substr(trim((string) $name), 0, 80) ?: 'Re-forecast after '.$t->format('M Y'), 'parent_id' => $b->id, 'actuals_through' => $t->toDateString(),
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
            foreach (range(1, 12) as $m) {
                $s = $stats[$m] ?? null;
                $a = $actStats[$m];
                $useActual = $m <= $upTo && ! self::preLive($months[$m]) && $a['available'];
                BudgetStat::create(['budget_id' => $f->id, 'month' => $m, 'rooms_available' => $useActual ? $a['available'] : ($s?->rooms_available ?? 0),
                    'occupancy' => $useActual ? min(100, $a['occupancy']) : ($s?->occupancy ?? 0), 'adr' => $useActual ? $a['adr'] : ($s?->adr ?? 0)]);
            }
            self::event($f, 'created', "Re-forecast of {$b->budget_no} – actuals to ".$t->format('M Y'));
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
    public static function bva(Budget $b, int $month, ?Budget $forecast = null): array
    {
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
        $act = self::actuals($b->fiscal_year);
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
        $as = self::actualStats($b->fiscal_year);
        $st = $b->stats()->get()->keyBy('month');
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
        $kpis = ['month' => $kpi([$month]), 'ytd' => $kpi($ytdMonths)];
        // an un-started month: show the full-month budget KPIs
        if ($future && ($s = $st[$month] ?? null)) {
            $kpis['month']['budget'] = ['available' => $s->rooms_available, 'sold' => $s->roomsSold(), 'revenue' => $s->roomRevenue(), 'occupancy' => round($s->occupancy, 1), 'adr' => $s->adr, 'revpar' => $s->revpar()];
        }

        return [
            'rows' => $rows, 'sections' => $sections, 'totals' => $totals, 'kpis' => $kpis,
            'meta' => ['month' => $month, 'start' => $start, 'cutoff' => $cut, 'factor' => $factor, 'partial' => $partial, 'future' => $future, 'pre_live' => $preLive,
                'ytd_from' => $ytdFrom, 'flagged' => $rows->where('flag', true)->count()],
        ];
    }

    /** comments of a period grouped by account id ('' = general notes on the month) */
    public static function commentsFor(string $period): Collection
    {
        return BudgetComment::with('user')->where('period', $period)->orderBy('id')->get()->groupBy(fn ($c) => (string) ($c->account_head_id ?? ''));
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
        if ($accountId && ! self::accounts()->firstWhere('id', $accountId)) {
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
        $d = self::bva($b, $m);

        return ['budget' => $b, 'month' => $m, 'cutoff' => $cut, 'revenue' => $d['totals']['revenue'], 'gop' => $d['totals']['gop'], 'flagged' => $d['meta']['flagged'], 'pending' => $pending];
    }

    // ───────────────────────── spend control (supplier expense bills) ─────────────────────────

    /**
     * Would these bill lines take an expense account over the active budget? Returns (and remembers in $lastWarnings)
     * one warning per account. In block mode a warning throws unless the user holds budget.override and gave a reason.
     *
     * @param  array<int, array{account_head_id:int, amount:float}>  $lines
     */
    public static function checkSpend(array $lines, string $date, ?string $reason = null): array
    {
        self::$lastWarnings = [];
        $cfg = self::cfg();
        if ($cfg['control'] === 'off') {
            return [];
        }
        $fy = self::fyFor($date);
        $b = self::active($fy);
        if (! $b) {
            return [];
        }
        $expense = AccountHead::where('type', 'expense')->get()->keyBy('id');
        $new = [];
        foreach ($lines as $l) {
            $id = (int) ($l['account_head_id'] ?? 0);
            if (isset($expense[$id]) && (float) ($l['amount'] ?? 0) > 0) {
                $new[$id] = ($new[$id] ?? 0) + (float) $l['amount'];
            }
        }
        if (! $new) {
            return [];
        }
        $months = self::months($fy);
        $m = self::monthIndex($date);
        $range = match ($cfg['control_basis']) {
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
        $lines = $b->lines()->whereIn('account_head_id', array_keys($new))->get()->keyBy('account_head_id');
        $warn = [];
        foreach ($new as $id => $amt) {
            $budget = isset($lines[$id]) ? array_sum(array_map(fn ($x) => $lines[$id]->month($x), $ms)) : 0.0;
            $spent = (float) GlLine::where('account_head_id', $id)->where('closing', false)->whereBetween('date', [$from, $to])->sum(DB::raw('debit - credit'));
            if ($spent + $amt > $budget + 0.005) {
                $warn[] = ['account' => $expense[$id], 'budget' => round($budget, 2), 'spent' => round($spent, 2), 'new' => round($amt, 2),
                    'over' => round($spent + $amt - $budget, 2), 'basis' => $cfg['control_basis']];
            }
        }
        if ($warn && $cfg['control'] === 'block') {
            if (! can_do('budget.override')) {
                throw new RuntimeException('Over budget – '.self::describe($warn).'. Someone with the budget override permission must enter this bill.');
            }
            if (mb_strlen(trim((string) $reason)) < 3) {
                throw new RuntimeException('Over budget – '.self::describe($warn).'. Give a reason to exceed the budget.');
            }
        }
        self::$lastWarnings = $warn;

        return $warn;
    }

    public static function describe(array $warnings): string
    {
        $basis = ['month' => "the month's budget", 'ytd' => 'the year-to-date budget', 'annual' => 'the full-year budget'];

        return implode('; ', array_map(fn ($w) => ($w['account']->code ? $w['account']->code.' ' : '').$w['account']->name.': '.money($w['spent'] + $w['new']).' against '
            .($w['budget'] > 0 ? ($basis[$w['basis']] ?? 'the budget').' of '.money($w['budget']) : 'no budget').' ('.money($w['over']).' over)', $warnings));
    }
}
