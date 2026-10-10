<?php

namespace App\Http\Controllers;

use App\Models\AccountHead;
use App\Models\Budget;
use App\Models\BudgetComment;
use App\Services\BudgetService;
use App\Services\LedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

/** v3.6 – budgets, re-forecasts, budget-vs-actual & variance commentary. */
class BudgetController extends Controller
{
    protected function fail(RuntimeException $e)
    {
        return back()->withInput()->with('error', $e->getMessage());
    }

    public function index(Request $request)
    {
        $cur = BudgetService::currentFy();
        $fy = (int) $request->query('fy', 0) ?: null;
        $versions = Budget::with('creator', 'submitter', 'approver', 'parent')
            ->when($fy, fn ($q) => $q->where('fiscal_year', $fy))
            ->when(in_array($request->query('status'), array_keys(Budget::STATUSES), true), fn ($q) => $q->where('status', $request->query('status')))
            ->orderByDesc('fiscal_year')->orderByRaw("FIELD(kind,'budget','forecast')")->orderByDesc('is_active')->orderByDesc('id')->get();
        $totals = $versions->mapWithKeys(fn ($b) => [$b->id => BudgetService::totals($b)]);
        $years = [];
        for ($y = $cur - 1; $y <= $cur + 2; $y++) {
            $years[$y] = BudgetService::fyLabel($y);
        }

        return view('budgets.index', ['versions' => $versions, 'totals' => $totals, 'years' => $years, 'fy' => $fy, 'cur' => $cur,
            'cfg' => BudgetService::cfg(), 'active' => BudgetService::active($cur), 'forecast' => BudgetService::active($cur, 'forecast'),
            'pending' => Budget::where('status', 'submitted')->count(), 'copyable' => Budget::orderByDesc('fiscal_year')->orderByDesc('id')->get()]);
    }

    public function store(Request $request)
    {
        $d = $request->validate(['fiscal_year' => 'required|integer', 'name' => 'nullable|string|max:80', 'method' => 'required|in:'.implode(',', array_keys(Budget::METHODS)),
            'source_id' => 'nullable|integer', 'growth_income' => 'nullable|numeric', 'growth_expense' => 'nullable|numeric', 'notes' => 'nullable|string|max:1000']);
        try {
            $b = BudgetService::create($d);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return redirect()->route('budgets.show', $b)->with('success', "{$b->budget_no} created – enter or adjust the figures, then submit it for approval.");
    }

    public function saveSettings(Request $request)
    {
        $d = $request->validate(['fy_start' => 'required|integer|between:1,12', 'live_from' => 'nullable|date', 'var_pct' => 'required|numeric|between:0,1000',
            'var_amount' => 'required|numeric|min:0', 'control' => 'required|in:off,warn,block', 'control_basis' => 'required|in:month,ytd,annual']);
        $d['live_from'] = ! empty($d['live_from']) ? Carbon::parse($d['live_from'])->toDateString() : '';
        BudgetService::saveSettings($d);

        return back()->with('success', 'Budget settings saved.');
    }

    public function show(Budget $budget)
    {
        $budget->load('lines.account', 'stats', 'events.user', 'creator', 'submitter', 'approver', 'parent');
        $accounts = BudgetService::accounts()->keyBy('id');
        $lines = $budget->lines->filter(fn ($l) => $accounts->has($l->account_head_id))
            ->sortBy(fn ($l) => array_search($accounts[$l->account_head_id]->section, array_keys(BudgetService::SECTIONS)).'-'.$accounts[$l->account_head_id]->code)
            ->groupBy(fn ($l) => $accounts[$l->account_head_id]->section);
        $act = BudgetService::actuals($budget->fiscal_year);
        $lastYear = BudgetService::actuals($budget->fiscal_year - 1);

        return view('budgets.show', ['b' => $budget, 'lines' => $lines, 'accounts' => $accounts, 'labels' => BudgetService::monthLabels($budget->fiscal_year),
            'locked' => $budget->lockedMonths(), 'totals' => BudgetService::totals($budget), 'actuals' => $act, 'lastYear' => $lastYear,
            'stats' => $budget->stats->keyBy('month'), 'actualStats' => BudgetService::actualStats($budget->fiscal_year),
            'canEdit' => $budget->isDraft() && can_do('budget.manage'), 'roomAccount' => AccountHead::sys('rev_room'),
            'forecasts' => Budget::where('parent_id', $budget->id)->where('kind', 'forecast')->orderByDesc('id')->get(),
            'closedMonths' => $this->closedMonthOptions($budget->fiscal_year)]);
    }

    /** month-ends that are closed in the GL, for the re-forecast picker */
    protected function closedMonthOptions(int $fy): array
    {
        $cut = Carbon::parse(LedgerService::cutoff());
        $o = [];
        foreach (BudgetService::months($fy) as $m => $s) {
            $e = $s->copy()->endOfMonth()->startOfDay();
            if ($m < 12 && $e->lte($cut)) {
                $o[$e->toDateString()] = $s->format('M Y');
            }
        }

        return $o;
    }

    public function saveGrid(Request $request, Budget $budget)
    {
        $request->validate(['l' => 'nullable|array', 'note' => 'nullable|array']);
        try {
            $n = BudgetService::saveGrid($budget, (array) $request->input('l', []), (array) $request->input('note', []));
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return back()->with('success', $n ? "{$n} line(s) saved." : 'Nothing changed.');
    }

    public function spread(Request $request, Budget $budget)
    {
        $d = $request->validate(['account_head_id' => 'required|integer', 'total' => 'required|numeric|between:-99999999999,99999999999', 'how' => 'required|in:even,days,seasonal']);
        try {
            $l = BudgetService::spread($budget, (int) $d['account_head_id'], (float) $d['total'], $d['how']);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return back()->with('success', ($l->account?->name ?? 'Line').' spread – annual total '.money($l->total()).'.');
    }

    public function adjust(Request $request, Budget $budget)
    {
        $d = $request->validate(['scope' => 'required|in:account,income,expense,all', 'pct' => 'required|numeric', 'account_head_id' => 'nullable|integer|required_if:scope,account']);
        try {
            $n = BudgetService::adjust($budget, $d['scope'], (float) $d['pct'], isset($d['account_head_id']) ? (int) $d['account_head_id'] : null);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return back()->with('success', sprintf('%+.1f %% applied to %d line(s).', $d['pct'], $n));
    }

    public function saveStats(Request $request, Budget $budget)
    {
        $request->validate(['s' => 'required|array', 's.*.rooms_available' => 'nullable|integer|min:0', 's.*.occupancy' => 'nullable|numeric|between:0,100', 's.*.adr' => 'nullable|numeric|min:0']);
        try {
            BudgetService::saveStats($budget, (array) $request->input('s'), $request->boolean('apply'));
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return back()->with('success', $request->boolean('apply') ? 'Statistics saved and room revenue recalculated (rooms × occupancy × ADR).' : 'Statistics saved.');
    }

    public function import(Request $request, Budget $budget)
    {
        $request->validate(['file' => 'required|file|max:1024|mimes:csv,txt']);
        try {
            [$n, $errors] = BudgetService::importCsv($budget, (string) file_get_contents($request->file('file')->getRealPath()));
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }
        if ($errors) {
            return back()->with('error', 'Nothing imported – fix these rows: '.implode(' ', array_slice($errors, 0, 8)).(count($errors) > 8 ? ' …' : ''));
        }

        return back()->with('success', "{$n} account(s) imported.");
    }

    public function export(Budget $budget)
    {
        $rows = BudgetService::exportRows($budget);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            foreach ($rows as $r) {
                fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v, $r));
            }
            fclose($out);
        }, strtolower($budget->budget_no).'.csv', ['Content-Type' => 'text/csv']);
    }

    public function submit(Request $request, Budget $budget)
    {
        try {
            BudgetService::submit($budget, $request->input('note'));
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return back()->with('success', "{$budget->budget_no} submitted for approval.");
    }

    public function approve(Request $request, Budget $budget)
    {
        try {
            BudgetService::approve($budget, $request->input('note'), $request->boolean('activate', true));
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return back()->with('success', "{$budget->budget_no} approved".($budget->fresh()->is_active ? ' and is now the active '.strtolower($budget->kindLabel()).'.' : '.'));
    }

    public function returnDraft(Request $request, Budget $budget)
    {
        $request->validate(['note' => 'required|string|max:255']);
        try {
            BudgetService::returnToDraft($budget, $request->input('note'));
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return back()->with('success', "{$budget->budget_no} returned to the preparer.");
    }

    public function reopen(Request $request, Budget $budget)
    {
        $request->validate(['reason' => 'required|string|max:255']);
        try {
            BudgetService::reopen($budget, $request->input('reason'));
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return back()->with('success', "{$budget->budget_no} is a draft again – it must be re-approved before it counts.");
    }

    public function activate(Budget $budget)
    {
        try {
            BudgetService::activate($budget);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return back()->with('success', "{$budget->budget_no} is now the active ".strtolower($budget->kindLabel()).'.');
    }

    public function forecast(Request $request, Budget $budget)
    {
        $d = $request->validate(['through' => 'required|date', 'name' => 'nullable|string|max:80']);
        try {
            $f = BudgetService::forecast($budget, $d['through'], $d['name'] ?? null);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return redirect()->route('budgets.show', $f)->with('success', "Re-forecast {$f->budget_no} created – closed months hold actuals; adjust the remaining months.");
    }

    public function destroy(Budget $budget)
    {
        try {
            BudgetService::delete($budget);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return redirect()->route('budgets.index')->with('success', 'Draft deleted.');
    }

    // ───────────────────────── budget vs actual ─────────────────────────

    public function bva(Request $request)
    {
        $cut = Carbon::parse(LedgerService::cutoff());
        $cur = BudgetService::fyFor($cut);
        $approved = Budget::where('kind', 'budget')->where('status', 'approved')->orderByDesc('fiscal_year')->orderByDesc('is_active')->orderByDesc('id')->get();
        $budget = $request->query('budget') ? $approved->firstWhere('id', (int) $request->query('budget')) : null;
        $budget ??= BudgetService::active($cur) ?? $approved->first();
        if (! $budget) {
            return view('budgets.bva', ['budget' => null, 'approved' => $approved]);
        }
        $defMonth = BudgetService::fyFor($cut) === $budget->fiscal_year ? BudgetService::monthIndex($cut) : 12;
        $month = min(12, max(1, (int) $request->query('month', $defMonth)));
        $forecast = BudgetService::active($budget->fiscal_year, 'forecast');
        $data = BudgetService::bva($budget, $month, $forecast);
        $period = BudgetService::period($budget->fiscal_year, $month);

        return view('budgets.bva', ['budget' => $budget, 'approved' => $approved, 'forecast' => $forecast, 'month' => $month, 'data' => $data,
            'labels' => BudgetService::monthLabels($budget->fiscal_year), 'period' => $period, 'comments' => BudgetService::commentsFor($period), 'cfg' => BudgetService::cfg()]);
    }

    public function comment(Request $request)
    {
        $d = $request->validate(['budget_id' => 'required|integer', 'account_head_id' => 'nullable|integer', 'period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'], 'body' => 'required|string|max:2000',
            'actual' => 'nullable|numeric', 'budget' => 'nullable|numeric']);
        $b = Budget::findOrFail($d['budget_id']);
        try {
            BudgetService::comment($b, $d['account_head_id'] ?? null, $d['period'], $d['body'], isset($d['actual']) ? (float) $d['actual'] : null, isset($d['budget']) ? (float) $d['budget'] : null);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return back()->with('success', 'Variance comment saved.');
    }

    public function deleteComment(BudgetComment $bcomment)
    {
        abort_unless($bcomment->user_id === auth()->id() || can_do('budget.approve'), 403);
        $bcomment->delete();

        return back()->with('success', 'Comment removed.');
    }
}
