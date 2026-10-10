<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Employee;
use App\Models\TalentApplicant;
use App\Models\TalentAppraisal;
use App\Models\TalentCase;
use App\Models\TalentCycle;
use App\Models\TalentExit;
use App\Models\TalentInterview;
use App\Models\TalentOffer;
use App\Models\TalentProbation;
use App\Models\TalentTask;
use App\Models\TalentVacancy;
use App\Models\User;
use App\Services\TalentService as TS;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** v6.9 Talent management: recruitment, new starters, appraisals, discipline & grievances, exits, establishment. */
class TalentController extends Controller
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

    protected function users()
    {
        return User::where('hotel_id', Tenant::id())->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    protected function employees()
    {
        return Employee::with('department')->where('is_active', true)->orderBy('name')->get();
    }

    // ------------------------------------------------------------------ dashboard & establishment

    public function index(Request $r)
    {
        $mine = TalentInterview::with('applicant.vacancy')->where('status', 'scheduled')->whereJsonContains('panel', $r->user()->id)->orderBy('scheduled_at')->get();

        return view('talent.index', TS::dashboard() + ['mine' => $mine, 'canDisc' => TS::can($r->user(), 'discipline')]);
    }

    public function establishment()
    {
        return view('talent.establishment', ['rows' => TS::establishment(), 'employees' => $this->employees(), 'users' => $this->users()]);
    }

    public function establishmentSave(Request $r)
    {
        return $this->attempt(fn () => TS::saveEstablishment((array) $r->input('establishment', [])), 'Establishment saved.');
    }

    public function managersSave(Request $r)
    {
        return $this->attempt(fn () => TS::saveLineManagers((array) $r->input('manager', [])), 'Line managers saved.');
    }

    // ------------------------------------------------------------------ vacancies

    public function vacancies(Request $r)
    {
        $status = $r->query('status', 'active');
        $q = TalentVacancy::with(['department', 'requester'])->withCount(['applicants', 'applicants as pipeline_count' => fn ($q) => $q->whereIn('stage', TalentApplicant::OPEN)]);
        if ($status === 'active') {
            $q->whereIn('status', ['draft', 'pending', 'open', 'on_hold']);
        } elseif (isset(TalentVacancy::STATUSES[$status])) {
            $q->where('status', $status);
        }

        return view('talent.vacancies', ['rows' => $q->orderByRaw("FIELD(status, 'pending', 'open', 'on_hold', 'draft', 'filled', 'cancelled')")->orderByDesc('id')->get(),
            'status' => $status, 'departments' => Department::orderBy('name')->get(), 'employees' => $this->employees(), 'mw' => TS::minWage()]);
    }

    public function vacancy(Request $r, TalentVacancy $tvacancy)
    {
        $tvacancy->load(['department', 'requester', 'approver', 'replaces']);
        $apps = $tvacancy->applicants()->withCount('interviews')->with(['interviews.scorecards'])->orderByDesc('id')->get();

        return view('talent.vacancy', ['v' => $tvacancy, 'apps' => $apps, 'chk' => TS::establishmentCheck($tvacancy), 'departments' => Department::orderBy('name')->get(),
            'employees' => $this->employees(), 'mw' => TS::minWage(), 'u' => $r->user(), 'careers' => route('careers.index', hotel()->code)]);
    }

    public function vacancyStore(Request $r)
    {
        return $this->attempt(fn () => redirect()->route('talent.vacancy', TS::saveVacancy($r->all(), null, $r->user()))->with('success', 'Vacancy drafted – send it for approval when ready.'), '');
    }

    public function vacancyUpdate(Request $r, TalentVacancy $tvacancy)
    {
        return $this->attempt(fn () => TS::saveVacancy($r->all(), $tvacancy, $r->user()), 'Vacancy saved.');
    }

    public function vacancySubmit(Request $r, TalentVacancy $tvacancy)
    {
        return $this->attempt(fn () => TS::submitVacancy($tvacancy, $r->user()), 'Sent for approval.');
    }

    public function vacancyApprove(Request $r, TalentVacancy $tvacancy)
    {
        return $this->attempt(fn () => TS::approveVacancy($tvacancy, $r->user(), $r->input('note')), 'Vacancy approved – it is now open.');
    }

    public function vacancyReturn(Request $r, TalentVacancy $tvacancy)
    {
        return $this->attempt(fn () => TS::returnVacancy($tvacancy, $r->user(), $r->input('note')), 'Vacancy returned to the requester.');
    }

    public function vacancyStatus(Request $r, TalentVacancy $tvacancy)
    {
        return $this->attempt(fn () => TS::setVacancyStatus($tvacancy, (string) $r->input('to'), $r->user(), $r->input('reason')), 'Vacancy updated.');
    }

    // ------------------------------------------------------------------ applicants, interviews, offers

    public function applicants(Request $r)
    {
        $vac = $r->integer('vacancy') ?: null;
        $q = TalentApplicant::with(['vacancy.department'])->withCount('interviews')->whereNull('anonymised_at');
        if ($vac) {
            $q->where('vacancy_id', $vac);
        } else {
            $q->whereHas('vacancy', fn ($v) => $v->whereIn('status', ['open', 'on_hold']));
        }
        if (filled($r->query('q'))) {
            $s = '%'.$r->query('q').'%';
            $q->where(fn ($w) => $w->where('name', 'like', $s)->orWhere('phone', 'like', $s)->orWhere('email', 'like', $s)->orWhere('number', 'like', $s));
        }
        $rows = $q->orderByDesc('stage_changed_at')->get();

        return view('talent.applicants', ['cols' => $rows->groupBy('stage'), 'rows' => $rows, 'vac' => $vac,
            'vacancies' => TalentVacancy::whereIn('status', ['open', 'on_hold', 'filled'])->orderBy('title')->get(), 'employees' => $this->employees()]);
    }

    public function applicant(Request $r, TalentApplicant $tapplicant)
    {
        $tapplicant->load(['vacancy.department', 'referrer', 'employee', 'interviews.scorecards.user', 'offers.approver', 'offers.creator']);

        return view('talent.applicant', ['a' => $tapplicant, 'users' => $this->users(), 'u' => $r->user(), 'mw' => TS::minWage(), 'cfg' => TS::cfg()]);
    }

    public function applicantStore(Request $r, TalentVacancy $tvacancy)
    {
        $r->validate(['cv' => 'nullable|file|max:5120|mimes:pdf,doc,docx,jpg,jpeg,png|mimetypes:application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,image/jpeg,image/png,application/zip,application/octet-stream']);

        return $this->attempt(fn () => redirect()->route('talent.applicant', TS::addApplicant($tvacancy, $r->all(), $r->file('cv'), $r->user()))->with('success', 'Applicant added.'), '');
    }

    public function applicantMove(Request $r, TalentApplicant $tapplicant)
    {
        return $this->attempt(fn () => TS::moveApplicant($tapplicant, (string) $r->input('to'), $r->user(), $r->input('reason'), $r->filled('rating') ? (int) $r->input('rating') : null),
            'Applicant moved.');
    }

    public function cv(Request $r, TalentApplicant $tapplicant)
    {
        $panel = $tapplicant->interviews()->get()->contains(fn ($i) => in_array($r->user()->id, array_map('intval', $i->panel ?? []), true));
        abort_unless(TS::can($r->user(), 'recruit') || $panel, 403);
        abort_unless($tapplicant->cv_path && Storage::disk('local')->exists($tapplicant->cv_path), 404);

        return Storage::disk('local')->download($tapplicant->cv_path, $tapplicant->cv_name ?: 'cv');
    }

    public function interviewStore(Request $r, TalentApplicant $tapplicant)
    {
        return $this->attempt(fn () => TS::scheduleInterview($tapplicant, $r->all(), $r->user()), 'Interview scheduled.');
    }

    public function interview(Request $r, TalentInterview $tinterview)
    {
        $tinterview->load(['applicant.vacancy.department', 'scorecards.user']);
        $onPanel = in_array($r->user()->id, array_map('intval', $tinterview->panel ?? []), true);
        abort_unless($onPanel || TS::can($r->user(), 'recruit'), 403);

        return view('talent.interview', ['i' => $tinterview, 'a' => $tinterview->applicant, 'onPanel' => $onPanel,
            'mine' => $tinterview->scorecards->firstWhere('user_id', $r->user()->id)]);
    }

    public function interviewStatus(Request $r, TalentInterview $tinterview)
    {
        return $this->attempt(fn () => TS::setInterviewStatus($tinterview, (string) $r->input('to'), $r->user()), 'Interview updated.');
    }

    public function score(Request $r, TalentInterview $tinterview)
    {
        return $this->attempt(fn () => TS::score($tinterview, $r->all(), $r->user()), 'Scorecard saved.');
    }

    public function offerStore(Request $r, TalentApplicant $tapplicant)
    {
        return $this->attempt(fn () => TS::makeOffer($tapplicant, $r->all(), $r->user())->status === 'pending' ? 'The salary is above the range – the offer waits for approval.' : 'Offer issued.', '');
    }

    public function offerApprove(Request $r, TalentOffer $toffer)
    {
        return $this->attempt(fn () => TS::approveOffer($toffer, $r->user()), 'Offer approved and issued.');
    }

    public function offerRespond(Request $r, TalentOffer $toffer)
    {
        return $this->attempt(function () use ($r, $toffer) {
            return match ($r->input('action')) {
                'accept' => redirect()->route('talent.starter', TS::acceptOffer($toffer, $r->user()))->with('success', 'Offer accepted – the employee record and onboarding checklist are ready.'),
                'decline' => TS::declineOffer($toffer, $r->user(), $r->input('reason')),
                'withdraw' => TS::withdrawOffer($toffer, $r->user(), $r->input('reason')),
                default => throw new RuntimeException('Choose accept, decline or withdraw.'),
            };
        }, 'Offer updated.');
    }

    public function offerLetter(TalentOffer $toffer)
    {
        abort_if($toffer->status === 'pending', 403, 'The offer is awaiting approval.');

        return view('talent.offer-letter', ['o' => $toffer->load(['applicant', 'vacancy.department', 'creator']), 'cfg' => TS::cfg()]);
    }

    // ------------------------------------------------------------------ new starters

    public function starters()
    {
        $since = bdate()->subMonths(6)->toDateString();
        $emps = Employee::with(['department', 'onboardingTasks'])->where('is_active', true)
            ->where(fn ($q) => $q->where('joining_date', '>=', $since)->orWhere(fn ($w) => $w->whereNotNull('probation_end')->whereNull('confirmed_on'))->orWhereNull('statement_issued_on'))
            ->orderByDesc('joining_date')->get();

        return view('talent.starters', ['emps' => $emps, 'overdue' => TS::statementsOverdue()->pluck('id')->all()]);
    }

    public function starter(Request $r, Employee $temployee)
    {
        $temployee->load(['department', 'manager', 'user']);

        return view('talent.starter', ['e' => $temployee, 'tasks' => TS::onboarding($temployee), 'reviews' => TalentProbation::with('reviewer')->where('employee_id', $temployee->id)->orderByDesc('id')->get(),
            'hire' => TalentApplicant::with('vacancy')->where('hired_employee_id', $temployee->id)->first(), 'u' => $r->user(),
            'extended' => TalentProbation::where('employee_id', $temployee->id)->where('outcome', 'extend')->exists()]);
    }

    public function taskToggle(Request $r, TalentTask $ttask)
    {
        return $this->attempt(fn () => TS::completeTask($ttask, $r->user(), $r->boolean('done'), $r->input('note')), 'Checklist updated.');
    }

    public function statementIssue(Request $r, Employee $temployee)
    {
        return $this->attempt(fn () => TS::issueStatement($temployee, $r->user(), $r->input('issued_on')), 'Written statement recorded as issued.');
    }

    public function statement(Employee $temployee)
    {
        return view('talent.statement', ['e' => $temployee->load(['department', 'manager']), 'cfg' => TS::cfg(), 'leave' => \App\Models\LeaveType::where('is_active', true)->orderBy('id')->get(),
            'notice' => TS::notice($temployee, 'resignation', bdate())]);
    }

    public function probation(Request $r, Employee $temployee)
    {
        return $this->attempt(fn () => TS::reviewProbation($temployee, $r->all(), $r->user()), 'Probation review recorded.');
    }

    // ------------------------------------------------------------------ appraisals

    public function appraisals()
    {
        $cycles = TalentCycle::withCount(['appraisals', 'appraisals as signed_count' => fn ($q) => $q->where('status', 'signed')])->orderByDesc('period_to')->get();

        return view('talent.appraisals', ['cycles' => $cycles, 'cfg' => TS::cfg()]);
    }

    public function cycle(TalentCycle $tcycle)
    {
        $rows = $tcycle->appraisals()->with(['employee.department', 'reviewer', 'cycle'])->get()->sortBy(fn ($a) => $a->employee->name)->values();

        return view('talent.cycle', ['c' => $tcycle, 'rows' => $rows, 'users' => $this->users(),
            'dist' => $rows->whereNotNull('band')->groupBy('band')->map->count()]);
    }

    public function cycleStore(Request $r)
    {
        return $this->attempt(fn () => redirect()->route('talent.cycle', TS::createCycle($r->all(), $r->user()))->with('success', 'Cycle opened – employees can start their self-assessment.'), '');
    }

    public function cycleClose(Request $r, TalentCycle $tcycle)
    {
        return $this->attempt(fn () => TS::closeCycle($tcycle, $r->user()), 'Cycle closed.');
    }

    public function reviewer(Request $r, TalentAppraisal $tappraisal)
    {
        return $this->attempt(fn () => TS::assignReviewer($tappraisal, $r->integer('reviewer_id'), $r->user()), 'Reviewer assigned.');
    }

    public function signoff(Request $r, TalentAppraisal $tappraisal)
    {
        return $this->attempt(fn () => TS::signOff($tappraisal, $r->user(), $r->input('action') !== 'return', $r->input('note')), 'Appraisal updated.');
    }

    public function me(Request $r)
    {
        $u = $r->user();
        $emp = Employee::where('user_id', $u->id)->first();
        $mine = $emp ? TalentAppraisal::with(['cycle', 'reviewer'])->where('employee_id', $emp->id)->orderByDesc('id')->get() : collect();
        $team = TalentAppraisal::with(['cycle', 'employee.department'])->where('reviewer_id', $u->id)->whereHas('cycle', fn ($q) => $q->where('status', 'active'))->get();

        return view('talent.me', ['emp' => $emp, 'mine' => $mine, 'team' => $team]);
    }

    public function appraisal(Request $r, TalentAppraisal $tappraisal)
    {
        $u = $r->user();
        $tappraisal->load(['cycle', 'employee.department', 'reviewer', 'signer']);
        abort_unless(TS::isOwn($tappraisal, $u) || TS::canReview($tappraisal, $u), 403);

        return view('talent.appraisal', ['a' => $tappraisal, 'own' => TS::isOwn($tappraisal, $u), 'canReview' => TS::canReview($tappraisal, $u), 'canSign' => TS::can($u, 'appraise'),
            'cfg' => TS::cfg(), 'u' => $u]);
    }

    public function selfSave(Request $r, TalentAppraisal $tappraisal)
    {
        return $this->attempt(fn () => TS::saveSelf($tappraisal, $r->all(), $r->user(), $r->input('action') === 'submit'), $r->input('action') === 'submit' ? 'Self-assessment submitted to your manager.' : 'Saved.');
    }

    public function reviewSave(Request $r, TalentAppraisal $tappraisal)
    {
        return $this->attempt(fn () => TS::saveReview($tappraisal, $r->all(), $r->user(), $r->input('action') === 'submit'), $r->input('action') === 'submit' ? 'Review submitted to HR for sign-off.' : 'Saved.');
    }

    public function acknowledge(Request $r, TalentAppraisal $tappraisal)
    {
        return $this->attempt(fn () => TS::acknowledge($tappraisal, $r->user(), $r->input('comment')), 'Thank you – acknowledged.');
    }

    // ------------------------------------------------------------------ discipline & grievances

    public function cases(Request $r)
    {
        $kind = in_array($r->query('kind'), ['discipline', 'grievance'], true) ? $r->query('kind') : null;
        $open = $r->query('show', 'open') === 'open';
        $u = $r->user();
        $rows = TalentCase::with(['employee.department', 'raiser'])->when($kind, fn ($q) => $q->where('kind', $kind))
            ->when($open, fn ($q) => $q->whereNotIn('status', ['closed', 'withdrawn']))->orderByDesc('id')->get()->filter(fn ($c) => TS::canSeeCase($c, $u))->values();
        $warnings = TalentCase::with('employee')->whereIn('outcome', ['verbal', 'written', 'final', 'suspension'])->where('expires_on', '>=', bdate()->toDateString())->orderBy('expires_on')->get();

        return view('talent.cases', ['rows' => $rows, 'kind' => $kind, 'open' => $open, 'employees' => $this->employees(), 'warnings' => $warnings, 'today' => bdate()->toDateString()]);
    }

    public function caseShow(Request $r, TalentCase $tcase)
    {
        abort_unless(TS::canSeeCase($tcase, $r->user()), 403);
        $tcase->load(['employee.department', 'raiser', 'decider', 'exitRecord']);

        return view('talent.case', ['c' => $tcase, 'u' => $r->user(), 'warnings' => TS::activeWarnings($tcase->employee, $tcase->id), 'cfg' => TS::cfg(),
            'history' => TalentCase::where('employee_id', $tcase->employee_id)->where('kind', 'discipline')->where('id', '!=', $tcase->id)->orderByDesc('incident_on')->get()]);
    }

    public function caseLetter(Request $r, TalentCase $tcase, string $kind)
    {
        abort_unless(TS::canSeeCase($tcase, $r->user()) && in_array($kind, ['query', 'outcome'], true), 404);
        abort_if($kind === 'query' && ! $tcase->query_issued_on, 404);
        abort_if($kind === 'outcome' && ! $tcase->outcome, 404);

        return view('talent.case-letter', ['c' => $tcase->load(['employee.department', 'decider']), 'kind' => $kind]);
    }

    public function caseStore(Request $r)
    {
        return $this->attempt(fn () => redirect()->route('talent.case', TS::raiseCase($r->all(), $r->user()))->with('success', 'Case opened.'), '');
    }

    public function caseAction(Request $r, TalentCase $tcase, string $step)
    {
        abort_unless(TS::canSeeCase($tcase, $r->user()), 403);

        return $this->attempt(function () use ($r, $tcase, $step) {
            $u = $r->user();

            return match ($step) {
                'query' => TS::issueQuery($tcase, $r->all(), $u),
                'reply' => TS::recordReply($tcase, $r->all(), $u),
                'hearing' => TS::scheduleHearing($tcase, $r->all(), $u),
                'held' => TS::recordHearing($tcase, $r->all(), $u),
                'decide' => ($x = TS::decide($tcase, $r->all(), $u)) ? redirect()->route('talent.exit', $x)->with('success', 'Dismissal decided – the exit is open for clearance and final pay.') : 'Decision recorded.',
                'investigating', 'resolved', 'withdrawn' => TS::progressGrievance($tcase, $step, $r->all(), $u),
                'close' => TS::closeCase($tcase, $u, $r->input('reason')),
                default => abort(404),
            };
        }, 'Case updated.');
    }

    // ------------------------------------------------------------------ exits

    public function exits(Request $r)
    {
        $rows = TalentExit::with(['employee.department'])->orderByRaw("FIELD(status, 'notice', 'completed', 'withdrawn')")->orderByDesc('last_day')->get();
        $reasons = $rows->where('status', 'completed')->filter(fn ($x) => $x->interview)->groupBy(fn ($x) => $x->interview['primary'] ?? 'other')->map->count()->sortDesc();

        return view('talent.exits', ['rows' => $rows, 'employees' => $this->employees(), 'turnover' => TS::turnover(), 'reasons' => $reasons]);
    }

    public function exitShow(TalentExit $texit)
    {
        $texit->load(['employee.department', 'disciplinaryCase', 'creator', 'completer']);

        return view('talent.exit', ['x' => $texit, 'facts' => TS::clearanceFacts($texit), 'today' => bdate()->toDateString()]);
    }

    public function exitLetter(TalentExit $texit)
    {
        return view('talent.exit-letter', ['x' => $texit->load(['employee.department', 'disciplinaryCase', 'creator']), 'facts' => TS::clearanceFacts($texit)]);
    }

    public function exitStore(Request $r)
    {
        return $this->attempt(function () use ($r) {
            $e = Employee::findOrFail($r->integer('employee_id'));

            return redirect()->route('talent.exit', TS::startExit($e, $r->all(), $r->user()))->with('success', 'Exit recorded – work through the clearance before the last day.');
        }, '');
    }

    public function exitAction(Request $r, TalentExit $texit, string $step)
    {
        return $this->attempt(fn () => match ($step) {
            'clearance' => TS::updateClearance($texit, (string) $r->input('item'), $r->boolean('done'), $r->input('note'), $r->user()),
            'interview' => TS::saveInterview($texit, $r->all(), $r->user()),
            'complete' => TS::completeExit($texit, $r->user()),
            'withdraw' => TS::withdrawExit($texit, $r->user(), $r->input('reason')),
            default => abort(404),
        }, $step === 'complete' ? 'Exit completed – the employee is marked as left and the login is deactivated.' : 'Exit updated.');
    }

    // ------------------------------------------------------------------ settings

    public function settings()
    {
        return view('talent.settings', ['cfg' => TS::cfg()]);
    }

    public function settingsSave(Request $r)
    {
        return $this->attempt(fn () => TS::saveSettings($r->all()), 'Settings saved.');
    }
}
