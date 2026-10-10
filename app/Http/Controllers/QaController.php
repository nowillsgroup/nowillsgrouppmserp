<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\QaAction;
use App\Models\QaAudit;
use App\Models\QaStandard;
use App\Models\QaTemplate;
use App\Models\SopDocument;
use App\Services\QaService as QA;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** v6.5 Quality assurance & brand standards: dashboard, audits (conduct / score / print), corrective actions, library, checklists. */
class QaController extends Controller
{
    protected function attempt(callable $fn, string $ok)
    {
        try {
            $r = $fn();
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first())->withInput();
        }

        return $r instanceof \Symfony\Component\HttpFoundation\Response ? $r : back()->with('success', is_string($r) ? $r : $ok);
    }

    protected function range(Request $r, int $back = 89): array
    {
        $from = $r->filled('from') ? rescue(fn () => Carbon::parse((string) $r->query('from'))->startOfDay(), null, false) : null;
        $from ??= bdate()->copy()->subDays($back);
        $to = $r->filled('to') ? rescue(fn () => Carbon::parse((string) $r->query('to'))->startOfDay(), null, false) : null;
        $to ??= bdate()->copy();
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) > 731) {
            $from = $to->copy()->subDays(731);
        }

        return [$from, $to];
    }

    protected function lookups(): array
    {
        return ['departments' => Department::orderBy('name')->get(['id', 'name']), 'people' => QA::people(), 'auditors' => QA::auditors(),
            'templates' => QaTemplate::where('is_active', true)->withCount('standards')->orderBy('name')->get(),
            'standardList' => QaStandard::where('is_active', true)->orderBy('area')->orderBy('sort')->orderBy('code')->get()];
    }

    // ------------------------------------------------------------------ dashboard

    public function index(Request $r)
    {
        [$from, $to] = $this->range($r);

        return view('qa.index', ['from' => $from, 'to' => $to, 'k' => QA::kpis($from, $to), 'trend' => QA::trend(6), 'depts' => QA::departmentScores($from, $to),
            'failures' => QA::topFailures($from, $to, 8), 'due' => QA::due(), 'alerts' => QA::alerts(), 'cfg' => QA::cfg(),
            'recent' => QaAudit::with(['template', 'department', 'auditor'])->where('status', 'completed')->orderByDesc('completed_at')->limit(6)->get(),
            'upcoming' => QaAudit::with(['template', 'auditor'])->whereIn('status', ['scheduled', 'in_progress'])->orderBy('scheduled_for')->limit(6)->get(),
            'overdue' => QaAction::with(['department', 'owner'])->whereIn('status', QaAction::OPEN)->where('due_date', '<', bdate()->toDateString())->orderBy('due_date')->limit(8)->get(),
        ] + $this->lookups());
    }

    // ------------------------------------------------------------------ audits

    public function audits(Request $r)
    {
        $q = QaAudit::with(['template', 'department', 'auditor'])
            ->when($r->filled('status'), fn ($q) => $q->where('status', $r->query('status')))
            ->when($r->filled('type'), fn ($q) => $q->where('type', $r->query('type')))
            ->when($r->filled('dept'), fn ($q) => $q->where('department_id', (int) $r->query('dept')))
            ->when($r->filled('template'), fn ($q) => $q->where('qa_template_id', (int) $r->query('template')))
            ->when($r->query('result') === 'failed', fn ($q) => $q->where('passed', false))
            ->when($r->query('result') === 'passed', fn ($q) => $q->where('passed', true))
            ->when($r->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('number', 'like', '%'.$r->query('q').'%')->orWhere('title', 'like', '%'.$r->query('q').'%')))
            ->orderByRaw("FIELD(status, 'in_progress', 'scheduled', 'completed', 'cancelled')")->orderByRaw("CASE WHEN status IN ('scheduled', 'in_progress') THEN scheduled_for END ASC")
            ->orderByDesc('completed_at')->orderByDesc('id');

        return view('qa.audits', ['audits' => $q->paginate(25)->withQueryString(), 'due' => QA::due(), 'counts' => QaAudit::selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status')]
            + $this->lookups());
    }

    public function audit(QaAudit $qaaudit)
    {
        $a = $qaaudit->load(['items.standard.sop', 'template', 'department', 'auditor', 'acknowledger', 'actions.owner', 'actions.ticket']);
        $prev = QaAudit::where('qa_template_id', $a->qa_template_id)->where('status', 'completed')->whereKeyNot($a->id)
            ->when($a->completed_at, fn ($q) => $q->where('completed_at', '<=', $a->completed_at)->where('id', '<', $a->id))->orderByDesc('completed_at')->orderByDesc('id')->first();
        $u = auth()->user();

        return view('qa.audit', ['a' => $a, 'prev' => $prev, 'canConduct' => QA::canConduct($a, $u), 'canManage' => QA::canManage($u),
            'areas' => $a->items->groupBy('area'), 'history' => QaAudit::where('qa_template_id', $a->qa_template_id)->where('status', 'completed')->orderByDesc('completed_at')->limit(8)->get()->reverse()->values()]
            + $this->lookups());
    }

    public function auditPrint(QaAudit $qaaudit)
    {
        $a = $qaaudit->load(['items', 'template', 'department', 'auditor', 'acknowledger', 'actions.owner']);

        return view('qa.print', ['a' => $a, 'areas' => $a->items->groupBy('area')]);
    }

    public function schedule(Request $r)
    {
        return $this->attempt(function () use ($r) {
            $t = QaTemplate::findOrFail((int) $r->input('qa_template_id'));
            $a = QA::schedule($t, $r->only('scheduled_for', 'auditor_id', 'external_auditor', 'title'), $r->user());

            return redirect()->route('qa.audit', $a)->with('success', "Audit {$a->number} scheduled – {$a->items()->count()} standards to check.");
        }, '');
    }

    public function record(Request $r, QaAudit $qaaudit)
    {
        $complete = $r->input('do') === 'complete';

        return $this->attempt(function () use ($r, $qaaudit, $complete) {
            $a = QA::record($qaaudit, (array) $r->input('result', []), (array) $r->input('note', []), $r->user(), $complete, $r->has('summary') ? (string) $r->input('summary') : null);

            return $complete ? "Audit {$a->number} completed: ".rtrim(rtrim(number_format((float) $a->score_pct, 1), '0'), '.').'% – '.($a->passed ? 'PASSED' : 'FAILED')
                .($a->fails ? " · {$a->fails} corrective action(s) raised" : '') : 'Results saved – the audit is in progress.';
        }, '');
    }

    public function cancel(Request $r, QaAudit $qaaudit)
    {
        return $this->attempt(fn () => QA::cancel($qaaudit, (string) $r->input('reason'), $r->user()), "Audit {$qaaudit->number} cancelled.");
    }

    public function acknowledge(Request $r, QaAudit $qaaudit)
    {
        return $this->attempt(fn () => QA::acknowledge($qaaudit, $r->user()), 'Result acknowledged.');
    }

    // ------------------------------------------------------------------ corrective actions

    public function actions(Request $r)
    {
        $u = $r->user();
        $view = $r->query('view', 'open');
        $q = QaAction::with(['department', 'owner', 'audit', 'standard', 'ticket'])
            ->when($view === 'open', fn ($q) => $q->whereIn('status', QaAction::OPEN))
            ->when($view === 'overdue', fn ($q) => $q->whereIn('status', QaAction::OPEN)->where('due_date', '<', bdate()->toDateString()))
            ->when($view === 'verify', fn ($q) => $q->where('status', 'done'))
            ->when($view === 'mine', fn ($q) => $q->where('owner_id', $u->id)->whereIn('status', ['open', 'in_progress', 'done']))
            ->when($view === 'unassigned', fn ($q) => $q->whereIn('status', QaAction::OPEN)->whereNull('owner_id'))
            ->when($view === 'closed', fn ($q) => $q->whereIn('status', ['verified', 'cancelled']))
            ->when($r->filled('dept'), fn ($q) => $q->where('department_id', (int) $r->query('dept')))
            ->when($r->filled('priority'), fn ($q) => $q->where('priority', $r->query('priority')))
            ->when($r->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('number', 'like', '%'.$r->query('q').'%')->orWhere('title', 'like', '%'.$r->query('q').'%')))
            ->orderByRaw("FIELD(priority, 'critical', 'high', 'normal', 'low')")->orderBy('due_date')->orderBy('id');
        if (in_array($view, ['closed'], true)) {
            $q->reorder()->orderByDesc('updated_at');
        }
        $tabs = ['open' => QaAction::whereIn('status', QaAction::OPEN)->count(), 'overdue' => QA::alerts()['overdue_actions'], 'verify' => QA::alerts()['to_verify'],
            'mine' => QaAction::where('owner_id', $u->id)->whereIn('status', ['open', 'in_progress', 'done'])->count(), 'unassigned' => QA::alerts()['unassigned'],
            'closed' => QaAction::whereIn('status', ['verified', 'cancelled'])->count(), 'all' => QaAction::count()];

        return view('qa.actions', ['actions' => $q->paginate(30)->withQueryString(), 'view' => $view, 'tabs' => $tabs, 'canManage' => QA::canManage($u), 'canAudit' => QA::canAudit($u)]
            + $this->lookups());
    }

    public function actionStore(Request $r)
    {
        return $this->attempt(fn () => 'Corrective action '.QA::createAction($r->all(), $r->user())->number.' raised.', '');
    }

    public function actionUpdate(Request $r, QaAction $qaaction)
    {
        return $this->attempt(fn () => QA::updateAction($qaaction, $r->only('root_cause', 'action_plan', 'owner_id', 'due_date', 'priority', 'start'), $r->user()),
            "Corrective action {$qaaction->number} updated.");
    }

    public function actionDone(Request $r, QaAction $qaaction)
    {
        return $this->attempt(fn () => QA::completeAction($qaaction, (string) $r->input('completion_note'), $r->user()), "{$qaaction->number} marked done – quality will verify it.");
    }

    public function actionVerify(Request $r, QaAction $qaaction)
    {
        $ok = $r->input('decision') !== 'reject';

        return $this->attempt(fn () => QA::verifyAction($qaaction, $ok, $r->input('verify_note'), $r->user()), $ok ? "{$qaaction->number} verified and closed." : "{$qaaction->number} reopened.");
    }

    public function actionCancel(Request $r, QaAction $qaaction)
    {
        return $this->attempt(fn () => QA::cancelAction($qaaction, (string) $r->input('reason'), $r->user()), "{$qaaction->number} cancelled.");
    }

    public function actionTicket(Request $r, QaAction $qaaction)
    {
        return $this->attempt(fn () => 'Maintenance ticket '.QA::raiseTicket($qaaction, $r->only('category', 'location'), $r->user())->ticket_no.' raised for engineering.', '');
    }

    // ------------------------------------------------------------------ library & checklists

    public function standards(Request $r)
    {
        $q = QaStandard::with(['department', 'sop'])->withCount('templates')
            ->when($r->filled('dept'), fn ($q) => $q->where('department_id', (int) $r->query('dept')))
            ->when($r->query('critical') === '1', fn ($q) => $q->where('is_critical', true))
            ->when($r->query('show') !== 'all', fn ($q) => $q->where('is_active', true))
            ->when($r->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('code', 'like', '%'.$r->query('q').'%')->orWhere('title', 'like', '%'.$r->query('q').'%')->orWhere('area', 'like', '%'.$r->query('q').'%')))
            ->orderBy('area')->orderBy('sort')->orderBy('code');
        [$from, $to] = [bdate()->copy()->subDays(179), bdate()->copy()];
        $fail = collect(QA::topFailures($from, $to, 500))->keyBy(fn ($f) => $f['standard']->id);

        return view('qa.standards', ['standards' => $q->get(), 'fail' => $fail, 'sops' => SopDocument::orderBy('code')->get(['id', 'code', 'title']), 'canManage' => QA::canManage($r->user())]
            + $this->lookups());
    }

    public function standardSave(Request $r)
    {
        $s = $r->filled('id') ? QaStandard::findOrFail((int) $r->input('id')) : null;

        return $this->attempt(fn () => 'Standard '.QA::saveStandard($r->all(), $s, $r->user())->code.' saved.', '');
    }

    public function templates(Request $r)
    {
        $templates = QaTemplate::with(['department', 'standards'])->withCount('audits')->orderByDesc('is_active')->orderBy('name')->get();
        $due = QA::due()->keyBy(fn ($d) => $d['template']->id);
        $scores = QaAudit::where('status', 'completed')->selectRaw('qa_template_id, AVG(score_pct) avg, SUM(passed = 1) passed, COUNT(*) n')->groupBy('qa_template_id')->get()->keyBy('qa_template_id');

        return view('qa.templates', ['list' => $templates, 'due' => $due, 'scores' => $scores, 'canManage' => QA::canManage($r->user()), 'canAudit' => QA::canAudit($r->user()), 'cfg' => QA::cfg()]
            + $this->lookups());
    }

    public function templateSave(Request $r)
    {
        $t = $r->filled('id') ? QaTemplate::findOrFail((int) $r->input('id')) : null;

        return $this->attempt(fn () => 'Checklist "'.QA::saveTemplate($r->all(), $t, $r->user())->name.'" saved.', '');
    }

    // ------------------------------------------------------------------ settings

    public function settings()
    {
        return view('qa.settings', ['cfg' => QA::cfg()]);
    }

    public function settingsSave(Request $r)
    {
        return $this->attempt(fn () => QA::saveSettings($r->all()), 'Quality assurance settings saved.');
    }
}
