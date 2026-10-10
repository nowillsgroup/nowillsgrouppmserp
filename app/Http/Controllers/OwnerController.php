<?php

namespace App\Http\Controllers;

use App\Models\AccountHead;
use App\Models\Asset;
use App\Models\CapexProject;
use App\Models\Department;
use App\Models\FfeEntry;
use App\Models\ManagementAgreement;
use App\Models\OwnerFee;
use App\Models\OwnerQuery;
use App\Models\OwnerStatement;
use App\Models\TreasuryAccount;
use App\Services\ActivityLogger;
use App\Services\BudgetService;
use App\Services\LedgerService;
use App\Services\OwnerService as OS;
use App\Services\UsaliService as US;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use RuntimeException;

/** v5.8 Owner reporting: USALI statement, KPIs, management fees, FF&E reserve & capex, monthly owner packs. */
class OwnerController extends Controller
{
    protected function attempt(callable $fn, string $ok)
    {
        try {
            $r = $fn();
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return $r instanceof \Symfony\Component\HttpFoundation\Response ? $r : back()->with('success', is_string($r) ? $r : $ok);
    }

    /** run $fn and flash $msg (the result of $fn is not needed) */
    protected function done(callable $fn, string $msg)
    {
        return $this->attempt(function () use ($fn, $msg) {
            $fn();

            return $msg;
        }, $msg);
    }

    /** fiscal year & month from the request, defaulting to the month of the ledger cut-off */
    protected function period(Request $r): array
    {
        [$fy, $m] = US::defaultPeriod();
        if ($r->filled('period') && preg_match('/^\d{4}-\d{2}$/', (string) $r->period)) {
            $d = Carbon::parse($r->period.'-01');
            [$fy, $m] = [BudgetService::fyFor($d), BudgetService::monthIndex($d)];
        }

        return [$fy, $m];
    }

    /** months that can be picked: the fiscal year before the current one up to the cut-off month */
    protected function periodOptions(): array
    {
        $cut = Carbon::parse(LedgerService::cutoff())->startOfMonth();
        $o = [];
        for ($c = $cut->copy(), $i = 0; $i < 24; $i++, $c->subMonthNoOverflow()) {
            $o[$c->format('Y-m')] = $c->format('F Y');
        }

        return $o;
    }

    public function index()
    {
        OS::ensureDefaults();
        [$fy, $m] = US::defaultPeriod();
        $st = US::statement($fy, $m);
        $months = BudgetService::months($fy);
        $yms = array_map(fn ($c) => $c->format('Y-m'), array_slice($months, 0, $m, true));
        $a = OS::agreement();

        return view('owner.index', [
            'st' => $st, 'fy' => $fy, 'm' => $m, 'month' => $months[$m], 'fyLabel' => BudgetService::fyLabel($fy),
            'kpiM' => US::kpis($st['cols']['m_act'], US::roomStats([$months[$m]->format('Y-m')])),
            'kpiMb' => US::kpis($st['cols']['m_bud'], US::budgetStats($fy, $m, $m)),
            'kpiY' => US::kpis($st['cols']['y_act'], US::roomStats($yms)),
            'trend' => US::trend($fy), 'agreement' => $a,
            'feesYtd' => $a ? (float) OwnerFee::where('status', '!=', 'reversed')->where('period', '>=', OS::feeYearStart($a, $months[$m])->format('Y-m'))->sum('total_fee') : 0,
            'unpaid' => (float) OwnerFee::where('status', 'accrued')->sum('total_fee'),
            'feesDue' => OS::feesDue(), 'packsDue' => OS::packsDue(),
            'reserve' => OS::reserveBalance(), 'funded' => OS::reserveFunded($a),
            'statements' => OwnerStatement::withCount(['queries as open_queries' => fn ($q) => $q->where('status', '!=', 'closed')])->orderByDesc('period')->limit(6)->get(),
            'capexPending' => CapexProject::with('requester')->where('status', 'submitted')->orderBy('submitted_at')->get(),
            'openQueries' => OwnerQuery::with('statement', 'raiser')->whereIn('status', ['open', 'answered'])->orderBy('id')->get(),
        ]);
    }

    public function usali(Request $r)
    {
        [$fy, $m] = $this->period($r);
        $format = $r->query('format') === 'owner' ? 'owner' : 'operator';
        $st = US::statement($fy, $m);
        $months = BudgetService::months($fy);
        $lyMonths = BudgetService::months($fy - 1);
        $yms = array_map(fn ($c) => $c->format('Y-m'), array_slice($months, 0, $m, true));
        $kpis = [
            'm_act' => US::kpis($st['cols']['m_act'], US::roomStats([$months[$m]->format('Y-m')])),
            'm_bud' => US::kpis($st['cols']['m_bud'], US::budgetStats($fy, $m, $m)),
            'm_ly' => US::kpis($st['cols']['m_ly'], US::roomStats([$lyMonths[$m]->format('Y-m')])),
            'y_act' => US::kpis($st['cols']['y_act'], US::roomStats($yms)),
            'y_bud' => US::kpis($st['cols']['y_bud'], US::budgetStats($fy, 1, $m)),
            'y_ly' => US::kpis($st['cols']['y_ly'], US::roomStats(array_map(fn ($c) => $c->format('Y-m'), array_slice($lyMonths, 0, $m, true)))),
        ];

        return view('owner.usali', ['st' => $st, 'layout' => US::layout($format), 'format' => $format, 'kpis' => $kpis, 'period' => $months[$m]->format('Y-m'),
            'periods' => $this->periodOptions(), 'fyLabel' => BudgetService::fyLabel($fy),
            'flow' => ['bud' => US::flowThrough($st['cols']['m_act'], $st['cols']['m_bud']), 'ly' => US::flowThrough($st['cols']['m_act'], $st['cols']['m_ly']),
                'ybud' => US::flowThrough($st['cols']['y_act'], $st['cols']['y_bud'])]]);
    }

    public function dept(Request $r, string $dept)
    {
        $code = $dept;
        abort_unless(in_array($code, array_merge(array_keys(US::OPERATED), array_keys(US::UNDISTRIBUTED), ['ood', 'misc', 'mgmt', 'nonop', 'below']), true), 404);
        [$fy, $m] = $this->period($r);
        $st = US::statement($fy, $m);
        $months = BudgetService::months($fy);
        $sch = [];
        foreach (['m_act', 'm_bud', 'y_act', 'y_bud'] as $k) {
            $sch[$k] = US::schedule($code, $st['acc'][$k]);
        }
        $shares = US::payrollShares($months[$m]->format('Y-m'));

        return view('owner.dept', ['code' => $code, 'title' => US::scheduleTitle($code), 'sch' => $sch, 'st' => $st, 'period' => $months[$m]->format('Y-m'),
            'periods' => $this->periodOptions(), 'shares' => $shares, 'fyLabel' => BudgetService::fyLabel($fy)]);
    }

    // ───────────── fees ─────────────

    public function fees()
    {
        $a = OS::agreement();
        $due = OS::feesDue();
        $preview = null;
        if ($due) {
            try {
                $preview = OS::computeFees($due[0]);
            } catch (RuntimeException $e) {
                $preview = null;
            }
        }

        return view('owner.fees', ['agreement' => $a, 'fees' => OwnerFee::with('journal', 'paidJournal', 'paidAccount', 'accruer')->orderByDesc('period')->get(),
            'due' => $due, 'preview' => $preview, 'payAccounts' => OS::payAccounts(), 'lock' => LedgerService::lockDate()]);
    }

    public function accrue(Request $r)
    {
        $r->validate(['period' => 'required|date_format:Y-m']);

        return $this->attempt(function () use ($r) {
            $f = OS::accrue($r->period, $r->user());

            return 'Management fees for '.$f->label().' accrued: '.money((float) $f->total_fee).($f->gl_journal_id ? ' (journal posted on '.dmy($f->date).').' : '.');
        }, 'Accrued.');
    }

    public function reverseFee(Request $r, OwnerFee $ofee)
    {
        return $this->done(fn () => OS::reverseFee($ofee, $r->user(), $r->input('reason')), 'Fees for '.$ofee->label().' reversed.');
    }

    public function pay(Request $r)
    {
        $r->validate(['fee_ids' => 'required|array', 'fee_ids.*' => 'integer', 'account_id' => 'required|integer', 'date' => 'required|date', 'reference' => 'nullable|string|max:60']);

        return $this->attempt(fn () => OS::payFees($r->fee_ids, (int) $r->account_id, Carbon::parse($r->date)->toDateString(), $r->reference, $r->user()).' month(s) of management fees paid.', 'Paid.');
    }

    // ───────────── FF&E reserve & capex ─────────────

    public function reserve(Request $r)
    {
        $a = OS::agreement();
        $ledger = OS::ledger();
        $projects = CapexProject::with('assets', 'entries', 'requester')->orderByRaw("FIELD(status, 'submitted', 'approved', 'draft', 'rejected', 'completed', 'cancelled')")->orderByDesc('id')->get();
        $funded = OS::reserveFunded($a);
        $fundUrl = null;
        if ($a?->reserve_account_id && $funded !== null && $ledger['closing'] - $funded > 0.005) {
            $from = TreasuryAccount::where('kind', 'bank')->where('is_active', true)->where('id', '!=', $a->reserve_account_id)->orderByDesc('min_balance')->value('id');
            $fundUrl = route('treasury.transfers', ['new' => 1, 'kind' => 'transfer', 'from' => $from, 'to' => $a->reserve_account_id, 'amount' => round($ledger['closing'] - $funded, 2), 'notes' => 'FF&E reserve funding']);
        }

        return view('owner.reserve', ['agreement' => $a, 'ledger' => $ledger, 'funded' => $funded, 'fundUrl' => $fundUrl, 'projects' => $projects,
            'spendable' => $projects->where('status', 'approved')->values()]);
    }

    public function entry(Request $r)
    {
        $d = $r->validate(['type' => 'required|in:opening,interest,adjustment,drawdown', 'amount' => 'required|numeric', 'date' => 'required|date',
            'memo' => 'nullable|string|max:255', 'capex_project_id' => 'nullable|integer|owned:capex_projects']);

        return $this->done(fn () => OS::addEntry($d, $r->user()), FfeEntry::TYPES[$d['type']].' recorded on the FF&E reserve.');
    }

    public function deleteEntry(Request $r, FfeEntry $ffe)
    {
        return $this->attempt(function () use ($r, $ffe) {
            OS::deleteEntry($ffe, $r->user());

            return 'Entry removed.';
        }, 'Removed.');
    }

    protected function projectRules(): array
    {
        return ['title' => 'required|string|max:150', 'category' => ['required', Rule::in(array_keys(CapexProject::CATEGORIES))], 'funding' => ['required', Rule::in(array_keys(CapexProject::FUNDING))],
            'usali_dept' => ['nullable', Rule::in(array_keys(US::payrollDepts()))], 'budget' => 'required|numeric|min:1', 'justification' => 'nullable|string|max:4000', 'needed_by' => 'nullable|date'];
    }

    public function capexStore(Request $r)
    {
        $d = $r->validate($this->projectRules());

        return $this->attempt(function () use ($d, $r) {
            $p = OS::saveProject($d, $r->user());
            if ($r->boolean('submit')) {
                OS::submitProject($p, $r->user());
            }

            return redirect()->route('owner.capex.show', $p)->with('success', "{$p->number} saved".($r->boolean('submit') ? ' and sent to the owner for approval.' : ' as a draft.'));
        }, 'Saved.');
    }

    public function capexShow(CapexProject $capex)
    {
        $capex->load('assets', 'entries.asset', 'requester', 'decider');
        $linkable = Asset::whereNull('capex_project_id')->where('status', '!=', 'disposed')->orderByDesc('purchase_date')->limit(200)->get(['id', 'asset_no', 'name', 'cost', 'purchase_date']);

        return view('owner.capex', ['p' => $capex, 'linkable' => $linkable, 'reserve' => OS::reserveBalance()]);
    }

    public function capexUpdate(Request $r, CapexProject $capex)
    {
        $d = $r->validate($this->projectRules());

        return $this->done(fn () => OS::saveProject($d, $r->user(), $capex), "{$capex->number} updated.");
    }

    public function capexSubmit(Request $r, CapexProject $capex)
    {
        return $this->done(fn () => OS::submitProject($capex, $r->user()), "{$capex->number} sent to the owner for approval.");
    }

    public function capexApprove(Request $r, CapexProject $capex)
    {
        return $this->done(fn () => OS::decideProject($capex, $r->user(), true, $r->input('note')), "{$capex->number} approved.");
    }

    public function capexReject(Request $r, CapexProject $capex)
    {
        return $this->done(fn () => OS::decideProject($capex, $r->user(), false, $r->input('note')), "{$capex->number} rejected.");
    }

    public function capexClose(Request $r, CapexProject $capex, string $to)
    {
        abort_unless(in_array($to, ['completed', 'cancelled'], true), 404);

        return $this->done(fn () => OS::closeProject($capex, $r->user(), $to), "{$capex->number} {$to}.");
    }

    public function capexLink(Request $r, CapexProject $capex)
    {
        $r->validate(['asset_id' => 'required|integer|owned:assets']);

        return $this->attempt(fn () => OS::linkAsset($capex, Asset::findOrFail($r->asset_id), $r->user()), 'Linked.');
    }

    public function capexUnlink(Request $r, CapexProject $capex, Asset $asset)
    {
        return $this->attempt(function () use ($r, $capex, $asset) {
            OS::unlinkAsset($capex, $asset, $r->user());

            return "{$asset->asset_no} removed from {$capex->number}.";
        }, 'Removed.');
    }

    // ───────────── owner packs ─────────────

    public function statements()
    {
        return view('owner.statements', ['statements' => OwnerStatement::with('issuer', 'acknowledger')->withCount(['queries as open_queries' => fn ($q) => $q->where('status', '!=', 'closed')])->orderByDesc('period')->get(),
            'packsDue' => OS::packsDue(), 'periods' => $this->periodOptions()]);
    }

    public function statementPrepare(Request $r)
    {
        $r->validate(['period' => 'required|date_format:Y-m']);

        return $this->attempt(fn () => redirect()->route('owner.statements.show', OS::prepare($r->period, $r->user())->period)->with('success', 'Pack prepared – add the commentary and issue it.'), 'Prepared.');
    }

    public function statementShow(Request $r, string $period)
    {
        $s = OwnerStatement::with('queries.raiser', 'queries.replier', 'issuer', 'acknowledger')->where('period', $period)->firstOrFail();
        $snap = $s->status === 'draft' ? OS::snapshot($s->period) : $s->snap();

        return view($r->boolean('print') ? 'owner.statement_print' : 'owner.statement', ['s' => $s, 'snap' => $snap, 'blockers' => $s->status === 'draft' ? OS::issueBlockers($s) : [],
            'layout' => US::layout('operator'), 'ownerLayout' => US::layout('owner')]);
    }

    public function statementUpdate(Request $r, OwnerStatement $ostmt)
    {
        $r->validate(['commentary' => 'nullable|string|max:10000']);

        return $this->attempt(function () use ($r, $ostmt) {
            OS::saveCommentary($ostmt, $r->commentary);

            return 'Commentary saved.';
        }, 'Saved.');
    }

    public function statementIssue(Request $r, OwnerStatement $ostmt)
    {
        return $this->done(fn () => OS::issue($ostmt, $r->user()), 'The '.$ostmt->label().' pack was issued to the owner.');
    }

    public function statementAck(Request $r, OwnerStatement $ostmt)
    {
        return $this->done(fn () => OS::acknowledge($ostmt, $r->user()), 'The '.$ostmt->label().' pack is acknowledged.');
    }

    public function queryStore(Request $r, OwnerStatement $ostmt)
    {
        $r->validate(['body' => 'required|string|max:4000']);

        return $this->done(fn () => OS::raiseQuery($ostmt, $r->user(), $r->body), 'Query sent to the operator.');
    }

    public function queryReply(Request $r, OwnerQuery $oquery)
    {
        $r->validate(['reply' => 'required|string|max:4000']);

        return $this->done(fn () => OS::reply($oquery, $r->user(), $r->reply), 'Answer sent to the owner.');
    }

    public function queryClose(Request $r, OwnerQuery $oquery)
    {
        return $this->done(fn () => OS::closeQuery($oquery, $r->user()), 'Query closed.');
    }

    // ───────────── setup ─────────────

    public function setup(Request $r)
    {
        OS::ensureDefaults();
        $heads = AccountHead::whereIn('type', ['income', 'expense'])->orderBy('code')->get()->each(function ($h) {
            [$h->u_dept, $h->u_cat, $h->u_auto] = US::resolve($h);
            $h->u_autodef = US::autoMap($h);
        });

        return view('owner.setup', ['agreement' => OS::agreement() ?? new ManagementAgreement(['effective_from' => bdate()->copy()->startOfMonth(), 'base_fee_pct' => 3, 'incentive_pct' => 8, 'incentive_basis' => 'agop', 'ffe_schedule' => [2, 3, 4], 'pay_days' => 15]),
            'heads' => $heads, 'departments' => Department::orderBy('name')->get(), 'banks' => TreasuryAccount::where('kind', 'bank')->where('is_active', true)->orderBy('name')->get(),
            'accrued' => OwnerFee::where('status', '!=', 'reversed')->exists()]);
    }

    public function agreementSave(Request $r)
    {
        $d = $r->validate(['operator_name' => 'required|string|max:150', 'owner_name' => 'required|string|max:150', 'contract_ref' => 'nullable|string|max:60', 'effective_from' => 'required|date',
            'ends_on' => 'nullable|date|after:effective_from', 'base_fee_pct' => 'required|numeric|min:0', 'incentive_pct' => 'required|numeric|min:0', 'incentive_basis' => 'required|in:agop,gop',
            'owner_priority' => 'nullable|numeric|min:0', 'ffe_schedule' => 'nullable|array|max:10', 'ffe_schedule.*' => 'nullable|numeric', 'reserve_account_id' => 'nullable|integer|owned:treasury_accounts',
            'pay_days' => 'nullable|integer|min:0|max:90', 'notes' => 'nullable|string|max:255']);

        return $this->done(fn () => OS::saveAgreement($d, $r->user()), 'Management agreement saved.');
    }

    public function mappingSave(Request $r)
    {
        $r->validate(['map' => 'required|array']);

        return $this->attempt(fn () => US::saveMap($r->input('map')).' account mapping(s) changed.', 'Saved.');
    }

    public function deptMappingSave(Request $r)
    {
        $r->validate(['dept' => 'required|array', 'dept.*' => ['nullable', Rule::in(array_keys(US::payrollDepts()))]]);
        $n = 0;
        foreach (Department::whereIn('id', array_map('intval', array_keys($r->dept)))->get() as $d) {
            $v = $r->dept[$d->id] ?: US::deptDefault($d);
            if ($d->usali_dept !== $v) {
                $d->forceFill(['usali_dept' => $v])->save();
                $n++;
            }
        }
        US::flush();
        if ($n) {
            ActivityLogger::log('owner', 'usali_depts', "Payroll department mapping changed on {$n} department(s)");
        }

        return back()->with('success', $n.' department(s) updated.');
    }
}
