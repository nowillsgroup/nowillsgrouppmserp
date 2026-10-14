<?php

namespace App\Http\Controllers;

use App\Models\AccountHead;
use App\Models\Accrual;
use App\Models\ClosePeriod;
use App\Models\CloseTask;
use App\Models\FiscalYearClose;
use App\Models\PeriodPosting;
use App\Models\RecurringJournal;
use App\Models\Supplier;
use App\Services\ActivityLogger;
use App\Services\BudgetService;
use App\Services\CloseService as CS;
use App\Services\LedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use RuntimeException;

/** v5.6 Financial close: period list, month-end checklist & four-eyes close, schedules, recurring journals, year end. */
class CloseController extends Controller
{
    protected function periodOr404(string $month): ClosePeriod
    {
        abort_unless(CS::valid($month), 404, 'That month is outside the periods managed by the close.');

        return CS::period($month);
    }

    protected function attempt(callable $fn, string $ok)
    {
        try {
            $fn();
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return back()->with('success', $ok);
    }

    public function index()
    {
        $periods = CS::periods();
        $cur = CS::currentPeriod();
        // the period to work on: the oldest one not closed
        $focus = $periods->reverse()->first(fn ($p) => $p->status !== 'closed');
        if ($focus && $focus->status === 'open' && ! $focus->checked_at && $focus->end()->toDateString() < bdate()->toDateString()) {
            CS::runChecks($focus, auth()->user(), false);
            $focus->loadCount(['tasks', 'tasks as cleared_count' => fn ($q) => $q->whereIn('status', CloseTask::CLEARED)]);
        }
        $fy = BudgetService::currentFy();
        $pv = CS::yearEndPreview($fy);
        $mtdFrom = bdate()->copy()->startOfMonth()->toDateString();

        return view('close.index', [
            'periods' => $periods, 'focus' => $focus, 'cur' => $cur, 'approvals' => CS::approvals(), 'overdue' => CS::overdue(), 'cfg' => CS::cfg(),
            'lock' => LedgerService::lockDate(), 'lastClosed' => CS::lastClosed(), 'fy' => $fy, 'preview' => $pv,
            'due' => $focus ? CS::due($focus->period) : collect(),
            'activeSchedules' => Accrual::where('status', 'active')->count(), 'activeRecurring' => RecurringJournal::where('status', 'active')->count(),
            'prepaid' => LedgerService::balance('prepayments', bdate()->toDateString()), 'accrued' => -LedgerService::balance('accrued_expenses', bdate()->toDateString()),
            'deferred' => -LedgerService::balance('deferred_income', bdate()->toDateString()), 'mtdFrom' => $mtdFrom,
            'canClose' => ClosePeriod::where('status', 'closed')->exists(),
        ]);
    }

    public function show(string $month)
    {
        $p = $this->periodOr404($month);
        if ($p->status !== 'closed' && ! $p->checked_at) {
            CS::runChecks($p, auth()->user(), false);
        }
        $p->load('tasks.doer', 'events.user', 'preparer', 'closer', 'reopenRequester');
        $E = $p->end()->toDateString();
        $prevRow = ClosePeriod::where('period', CS::prev($month))->first();

        return view('close.show', [
            'p' => $p, 'tasks' => $p->tasks, 'due' => $p->status === 'open' ? CS::due($month) : collect(),
            'postings' => PeriodPosting::with('journal', 'accrual', 'recurring')->where(fn ($q) => $q->where('period', $month)->orWhere(fn ($b) => $b->whereDate('date', '>=', $p->start()->toDateString())->whereDate('date', '<=', $E)))->orderBy('date')->orderBy('id')->get(),
            'blockers' => $p->status === 'open' ? CS::blockers($p) : [], 'lock' => LedgerService::lockDate(), 'lastClosed' => CS::lastClosed(),
            'prev' => $month > CS::startPeriod() ? CS::prev($month) : null, 'next' => $month < CS::currentPeriod() ? CS::next($month) : null, 'prevRow' => $prevRow,
            'pnl' => \App\Services\GlReportService::profitLoss($p->start()->toDateString(), $E), 'yearClose' => $p->isYearEnd() ? CS::yearClose((int) $p->fiscal_year) : null,
        ]);
    }

    public function check(string $month)
    {
        $p = $this->periodOr404($month);
        if ($p->status === 'closed') {
            return back()->with('error', $p->label().' is closed – its checklist is frozen.');
        }
        $r = CS::runChecks($p, auth()->user());
        $fails = collect($r)->filter(fn ($s) => $s === 'fail')->count();

        return back()->with($fails ? 'error' : 'success', count($r).' checks run – '.($fails ? $fails.' failed.' : 'nothing failed.'));
    }

    public function post(string $month)
    {
        $p = $this->periodOr404($month);
        try {
            $r = CS::runPostings($p, auth()->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        CS::runChecks($p, auth()->user(), false);

        return back()->with('success', $r['made'] ? $r['made'].' period-end journal(s) posted – '.money($r['total']).'.' : 'Nothing was due.');
    }

    public function addTask(Request $request, string $month)
    {
        $p = $this->periodOr404($month);
        $d = $request->validate(['area' => 'nullable|string|max:30', 'title' => 'required|string|max:150']);

        return $this->attempt(fn () => CS::addTask($p, $d['area'] ?? 'General', $d['title'], $request->user()), 'Task added.');
    }

    public function taskDone(Request $request, CloseTask $closetask)
    {
        $d = $request->validate(['note' => 'nullable|string|max:255']);

        return $this->attempt(fn () => CS::completeTask($closetask, $request->user(), $d['note'] ?? null), 'Marked done: '.$closetask->title);
    }

    public function taskWaive(Request $request, CloseTask $closetask)
    {
        $d = $request->validate(['note' => 'required|string|min:5|max:255']);

        return $this->attempt(fn () => CS::waiveTask($closetask, $request->user(), $d['note']), 'Waived: '.$closetask->title);
    }

    public function taskReset(Request $request, CloseTask $closetask)
    {
        return $this->attempt(fn () => CS::resetTask($closetask, $request->user()), 'Task reset.');
    }

    public function submit(Request $request, string $month)
    {
        $p = $this->periodOr404($month);
        $d = $request->validate(['note' => 'nullable|string|max:255']);

        return $this->attempt(fn () => CS::submit($p, $request->user(), $d['note'] ?? null), $p->label().' submitted – a second person with close approval must now approve it.');
    }

    public function approve(Request $request, string $month)
    {
        $p = $this->periodOr404($month);
        $d = $request->validate(['note' => 'nullable|string|max:255']);

        return $this->attempt(fn () => CS::approve($p, $request->user(), $d['note'] ?? null), $p->label().' closed. The ledger is locked up to '.dmy($p->end()).'.'.($p->isYearEnd() ? ' Year-end closing entry posted to retained earnings.' : ''));
    }

    public function reject(Request $request, string $month)
    {
        $p = $this->periodOr404($month);
        $d = $request->validate(['reason' => 'required|string|min:5|max:255']);

        return $this->attempt(fn () => CS::reject($p, $request->user(), $d['reason']), $p->label().' sent back to the preparer.');
    }

    public function reopenRequest(Request $request, string $month)
    {
        $p = $this->periodOr404($month);
        $d = $request->validate(['reason' => 'required|string|min:10|max:255']);

        return $this->attempt(fn () => CS::requestReopen($p, $request->user(), $d['reason']), 'Reopen request for '.$p->label().' sent for approval.');
    }

    public function reopenApprove(Request $request, string $month)
    {
        $p = $this->periodOr404($month);

        return $this->attempt(fn () => CS::approveReopen($p, $request->user()), $p->label().' reopened – postings dated in it are allowed again until it is closed.');
    }

    public function reopenDeny(Request $request, string $month)
    {
        $p = $this->periodOr404($month);
        $d = $request->validate(['reason' => 'required|string|min:5|max:255']);

        return $this->attempt(fn () => CS::denyReopen($p, $request->user(), $d['reason']), 'Reopen request declined.');
    }

    public function settings(Request $request)
    {
        $d = $request->validate(['due_days' => 'required|integer|min:1|max:60', 'auto_post' => 'nullable|boolean', 'start' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'], 'manual' => 'nullable|string|max:3000']);
        CS::saveSettings($d, $request->user());

        return back()->with('success', 'Period close settings saved.');
    }

    // ───────────────────────── schedules ─────────────────────────

    protected function accountLists(): array
    {
        $a = AccountHead::where('is_active', true)->orderBy('code')->get();

        return ['expense' => $a->where('type', 'expense')->values(), 'income' => $a->where('type', 'income')->values(), 'balance' => $a->whereIn('type', ['asset', 'liability'])->values(), 'all' => $a];
    }

    public function accruals(Request $request)
    {
        $q = Accrual::with('plAccount', 'balanceAccount', 'supplier', 'creator', 'approver')
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status), fn ($q) => $q->whereNotIn('status', ['rejected']));
        $rows = $q->orderByRaw("FIELD(status, 'pending', 'active', 'completed', 'cancelled', 'rejected')")->orderByDesc('id')->get();
        $all = Accrual::whereIn('status', ['active', 'completed'])->get();
        $asOf = bdate()->toDateString();

        return view('close.accruals', [
            'rows' => $rows, 'accounts' => $this->accountLists(), 'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
            'sys' => collect(Accrual::BALANCE_ACCOUNT)->map(fn ($k) => AccountHead::sys($k))->all(),
            'stats' => ['pending' => Accrual::where('status', 'pending')->count(), 'active' => Accrual::where('status', 'active')->count(),
                'prepaid' => LedgerService::balance('prepayments', $asOf), 'accrued' => -LedgerService::balance('accrued_expenses', $asOf), 'deferred' => -LedgerService::balance('deferred_income', $asOf),
                'remaining' => $all->where('status', 'active')->sum(fn ($a) => $a->remaining())],
            'minStart' => CS::startPeriod(), 'curPeriod' => CS::currentPeriod(),
        ]);
    }

    public function accrual(Accrual $accrual)
    {
        $accrual->load('plAccount', 'balanceAccount', 'supplier', 'creator', 'approver', 'postings.journal');
        $posted = $accrual->postings->where('kind', 'post')->keyBy('period');

        return view('close.accrual', ['a' => $accrual, 'schedule' => $accrual->schedule(), 'posted' => $posted, 'reversals' => $accrual->postings->where('kind', 'reverse')->keyBy('period'), 'lock' => LedgerService::lockDate()]);
    }

    public function storeAccrual(Request $request)
    {
        $d = $request->validate([
            'type' => ['required', Rule::in(array_keys(Accrual::TYPES))], 'description' => 'required|string|max:150',
            'pl_account_id' => 'required|integer|owned:account_heads', 'balance_account_id' => 'nullable|integer|owned:account_heads',
            'supplier_id' => 'nullable|integer|owned:suppliers', 'amount' => 'required|numeric|min:1|max:999999999',
            'start_period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'], 'months' => 'nullable|integer|min:1|max:60',
            'reference' => 'nullable|string|max:80', 'notes' => 'nullable|string|max:255',
        ]);
        if ($d['start_period'] < CS::startPeriod()) {
            return back()->withInput()->with('error', 'The first month cannot be before the first managed period ('.Carbon::parse(CS::startPeriod().'-01')->format('M Y').').');
        }
        try {
            $a = CS::createAccrual($d, $request->user());
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('close.accruals.show', $a)->with('success', "{$a->number} saved – it posts once a second person approves it.");
    }

    public function decideAccrual(Request $request, Accrual $accrual)
    {
        $d = $request->validate(['decision' => 'required|in:approve,reject', 'note' => 'nullable|string|max:255|required_if:decision,reject']);

        return $this->attempt(fn () => CS::decideAccrual($accrual, $request->user(), $d['decision'] === 'approve', $d['note'] ?? null), "{$accrual->number} ".($d['decision'] === 'approve' ? 'approved – it posts with the period-end postings.' : 'rejected.'));
    }

    public function cancelAccrual(Request $request, Accrual $accrual)
    {
        $d = $request->validate(['reason' => 'required|string|min:5|max:255']);
        $u = $request->user();
        abort_unless($u->hasPermission('close.approve') || ($accrual->status === 'pending' && (int) $accrual->created_by === (int) $u->id), 403, 'Only an approver can cancel an active schedule.');

        return $this->attempt(fn () => CS::cancelAccrual($accrual, $u, $d['reason']), "{$accrual->number} cancelled.");
    }

    // ───────────────────────── recurring journals ─────────────────────────

    public function recurring(Request $request)
    {
        $rows = RecurringJournal::with('creator', 'approver', 'postings.journal')->orderByRaw("FIELD(status, 'pending', 'active', 'paused', 'ended', 'rejected')")->orderBy('code')->get();

        return view('close.recurring', ['rows' => $rows, 'accounts' => $this->accountLists()['all'], 'names' => AccountHead::pluck('name', 'id'), 'codes' => AccountHead::pluck('code', 'id'),
            'minStart' => CS::startPeriod(), 'curPeriod' => CS::currentPeriod(), 'open' => $request->integer('open')]);
    }

    public function storeRecurring(Request $request)
    {
        $d = $request->validate([
            'name' => 'required|string|max:120', 'frequency' => ['required', Rule::in(array_keys(RecurringJournal::FREQUENCIES))],
            'start_period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'], 'end_period' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'], 'auto_reverse' => 'nullable|boolean',
            'lines' => 'required|array|min:2|max:10', 'lines.*.account_head_id' => 'nullable|integer|owned:account_heads',
            'lines.*.debit' => 'nullable|numeric|min:0|max:999999999', 'lines.*.credit' => 'nullable|numeric|min:0|max:999999999', 'lines.*.memo' => 'nullable|string|max:150',
        ]);
        if ($d['start_period'] < CS::startPeriod()) {
            return back()->withInput()->with('error', 'The first period cannot be before the first managed period.');
        }
        try {
            $r = CS::createRecurring($d, $request->user());
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('close.recurring', ['open' => $r->id])->with('success', "{$r->code} saved – it runs once a second person approves it.");
    }

    public function decideRecurring(Request $request, RecurringJournal $rjournal)
    {
        $d = $request->validate(['decision' => 'required|in:approve,reject', 'note' => 'nullable|string|max:255|required_if:decision,reject']);

        return $this->attempt(fn () => CS::decideRecurring($rjournal, $request->user(), $d['decision'] === 'approve', $d['note'] ?? null), "{$rjournal->code} ".($d['decision'] === 'approve' ? 'approved.' : 'rejected.'));
    }

    public function recurringStatus(Request $request, RecurringJournal $rjournal)
    {
        $d = $request->validate(['status' => 'required|in:active,paused,ended']);

        return $this->attempt(fn () => CS::setRecurringStatus($rjournal, $d['status']), "{$rjournal->code} ".['active' => 'resumed', 'paused' => 'paused', 'ended' => 'ended'][$d['status']].'.');
    }

    // ───────────────────────── year end ─────────────────────────

    public function yearEnd(Request $request)
    {
        $first = CS::fiscal(CS::startPeriod())[0];
        $cur = BudgetService::currentFy();
        $fy = max($first, min($cur, $request->integer('fy') ?: $cur));
        $months = collect(BudgetService::months($fy))->map(fn ($d) => $d->format('Y-m'));
        $rows = ClosePeriod::whereIn('period', $months->all())->get()->keyBy('period');

        return view('close.yearend', [
            'fy' => $fy, 'years' => range($cur, $first), 'preview' => CS::yearEndPreview($fy), 'close' => CS::yearClose($fy),
            'history' => FiscalYearClose::with('journal', 'closer')->orderByDesc('fiscal_year')->orderByDesc('id')->get(),
            'months' => $months, 'rows' => $rows, 'start' => CS::startPeriod(), 'label' => BudgetService::fyLabel($fy), 'retained' => AccountHead::find(AccountHead::sys('retained')),
        ]);
    }
}
