<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Employee;
use App\Models\SopAcknowledgement;
use App\Models\SopDocument;
use App\Models\TrainingCourse;
use App\Models\TrainingRecord;
use App\Models\TrainingRequirement;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\LearningService as LS;
use Illuminate\Http\Request;
use RuntimeException;

/** v6.0 Learning & development: training matrix, courses, sessions, records & certificates, SOP library, self-service. */
class LearningController extends Controller
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

    protected function me(): ?Employee
    {
        return Employee::with('department')->where('user_id', auth()->id())->where('is_active', true)->first();
    }

    protected function depts()
    {
        return Department::orderBy('name')->get();
    }

    // ───────────────────────── dashboard & matrix ─────────────────────────

    public function index()
    {
        LS::ensureDefaults();
        $warn = bdate()->copy()->addDays(LS::cfg()['review_warn_days'])->toDateString();

        return view('learning.index', [
            'm' => LS::matrix(), 'alerts' => LS::alerts(), 'depts' => LS::byDepartment(), 'gaps' => LS::gaps()->take(12), 'cfg' => LS::cfg(),
            'upcoming' => TrainingSession::with('course')->withCount('records')->where('status', 'scheduled')->orderBy('date')->orderBy('start_time')->limit(8)->get(),
            'reviews' => SopDocument::where('status', 'published')->where('review_due', '<=', $warn)->orderBy('review_due')->limit(8)->get(),
            'sops' => LS::sopOverview()->filter(fn ($r) => $r['required'] > $r['signed'])->sortBy('pct')->take(6)->values(),
            'yearHours' => (float) TrainingRecord::join('training_courses', 'training_courses.id', '=', 'training_records.training_course_id')
                ->where('training_records.status', 'passed')->whereYear('training_records.completed_on', bdate()->year)->sum('training_courses.duration_hours'),
            'yearCost' => (float) TrainingRecord::whereIn('status', ['passed', 'failed'])->whereYear('completed_on', bdate()->year)->sum('cost'),
        ]);
    }

    public function matrix(Request $r)
    {
        $dept = $r->filled('department') ? (int) $r->department : null;

        return view('learning.matrix', ['m' => LS::matrix($dept), 'depts' => $this->depts(), 'dept' => $dept, 'print' => false]);
    }

    public function matrixPrint(Request $r)
    {
        $dept = $r->filled('department') ? (int) $r->department : null;

        return view('learning.matrix_print', ['m' => LS::matrix($dept), 'dept' => $dept ? Department::find($dept) : null]);
    }

    public function employee(Employee $employee)
    {
        return view('learning.employee', ['e' => $employee->load('department'), 'mine' => LS::mine($employee), 'courses' => TrainingCourse::where('is_active', true)->orderBy('name')->get()]);
    }

    // ───────────────────────── courses ─────────────────────────

    public function courses()
    {
        return view('learning.courses', [
            'courses' => TrainingCourse::with('requirements.department', 'sop')->withCount(['sessions' => fn ($q) => $q->where('status', 'scheduled')])->orderBy('category')->orderBy('name')->get(),
            'depts' => $this->depts(), 'sops' => SopDocument::where('status', '!=', 'retired')->orderBy('code')->get(), 'm' => LS::matrix(),
            'designations' => Employee::whereNotNull('designation')->distinct()->orderBy('designation')->pluck('designation'),
        ]);
    }

    public function course(TrainingCourse $tcourse)
    {
        $m = LS::matrix();
        $holders = collect($m['employees'])->filter(fn ($e) => isset($m['cells'][$e->id][$tcourse->id]))->map(fn ($e) => ['employee' => $e, 'cell' => $m['cells'][$e->id][$tcourse->id]])
            ->sortBy(fn ($r) => array_search($r['cell']['state'], ['expired', 'overdue', 'expiring', 'due', 'current'], true))->values();

        return view('learning.course', ['c' => $tcourse->load('requirements.department', 'sop'), 'holders' => $holders, 'depts' => $this->depts(),
            'sessions' => $tcourse->sessions()->withCount('records')->orderByDesc('date')->limit(20)->get(), 'needing' => LS::needing($tcourse),
            'designations' => Employee::whereNotNull('designation')->distinct()->orderBy('designation')->pluck('designation'),
            'sops' => SopDocument::where('status', '!=', 'retired')->orderBy('code')->get()]);
    }

    protected function courseData(Request $r): array
    {
        $d = $r->validate(['code' => 'required|string|max:20', 'name' => 'required|string|max:150', 'category' => 'required|string', 'delivery' => 'required|string',
            'description' => 'nullable|string|max:2000', 'duration_hours' => 'required|numeric|min:0.5|max:200', 'validity_months' => 'nullable|integer', 'pass_mark' => 'nullable|integer',
            'provider' => 'nullable|string|max:150', 'cost_per_head' => 'nullable|numeric|min:0', 'sop_document_id' => 'nullable|integer', 'fs_type' => 'nullable|string']);

        return $d + ['is_active' => $r->boolean('is_active')];
    }

    public function courseStore(Request $r)
    {
        $d = $this->courseData($r);

        return $this->attempt(fn () => redirect()->route('learning.course', LS::saveCourse($d))->with('success', 'Course saved – now add who must take it.'), '');
    }

    public function courseUpdate(Request $r, TrainingCourse $tcourse)
    {
        $d = $this->courseData($r);

        return $this->attempt(fn () => 'Course '.LS::saveCourse($d, $tcourse)->code.' saved.', '');
    }

    public function requirementStore(Request $r, TrainingCourse $tcourse)
    {
        $d = $r->validate(['department_id' => 'nullable|integer', 'designation' => 'nullable|string|max:80', 'due_days' => 'required|integer|min:0|max:730']);

        return $this->attempt(fn () => 'Now required for '.LS::addRequirement($tcourse, $d['department_id'] ?? null, $d['designation'] ?? null, (int) $d['due_days'])->fresh('department')->label().'.', '');
    }

    public function requirementDestroy(TrainingRequirement $treq)
    {
        return $this->attempt(fn () => LS::removeRequirement($treq), 'Requirement removed.');
    }

    // ───────────────────────── sessions ─────────────────────────

    public function sessions(Request $r)
    {
        $q = TrainingSession::with('course', 'trainer')->withCount(['records', 'records as passed_count' => fn ($w) => $w->where('status', 'passed')])->orderByDesc('date')->orderByDesc('start_time');
        if ($r->filled('status')) {
            $q->where('status', $r->status);
        }
        if ($r->filled('course')) {
            $q->where('training_course_id', (int) $r->course);
        }
        $upcoming = TrainingSession::with('course')->withCount('records')->where('status', 'scheduled')->orderBy('date')->orderBy('start_time')->get();

        return view('learning.sessions', ['sessions' => $q->paginate(30)->withQueryString(), 'upcoming' => $upcoming, 'courses' => TrainingCourse::where('is_active', true)->orderBy('name')->get(),
            'users' => User::where('is_active', true)->orderBy('name')->get(), 'cfg' => LS::cfg(), 'preset' => $r->integer('course') ?: null]);
    }

    public function session(TrainingSession $tsession)
    {
        $tsession->load('course', 'trainer', 'records.employee.department');
        $enrolled = $tsession->records->pluck('employee_id')->all();

        return view('learning.session', ['s' => $tsession, 'needing' => LS::needing($tsession->course)->reject(fn ($g) => in_array($g['employee']->id, $enrolled, true))->values(),
            'others' => LS::employees()->reject(fn ($e) => in_array($e->id, $enrolled, true))->values()]);
    }

    public function sessionSheet(TrainingSession $tsession)
    {
        return view('learning.session_sheet', ['s' => $tsession->load('course', 'trainer', 'records.employee.department')]);
    }

    public function sessionStore(Request $r)
    {
        $d = $r->validate(['training_course_id' => 'required|integer', 'date' => 'required|date_format:Y-m-d', 'start_time' => 'required|string|max:5', 'end_time' => 'nullable|string|max:5',
            'venue' => 'nullable|string|max:120', 'trainer_id' => 'nullable|integer', 'trainer_name' => 'nullable|string|max:120', 'capacity' => 'nullable|integer', 'cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:255', 'employees' => 'nullable|array', 'employees.*' => 'integer']);

        return $this->attempt(fn () => redirect()->route('learning.session', $s = LS::schedule($d, auth()->user()))->with('success', "Session {$s->number} scheduled".($s->records()->count() ? ' with '.$s->records()->count().' enrolled.' : ' – now enrol the staff.')), '');
    }

    public function enrol(Request $r, TrainingSession $tsession)
    {
        $d = $r->validate(['employees' => 'required|array|min:1', 'employees.*' => 'integer']);

        return $this->attempt(fn () => LS::enrol($tsession, $d['employees'], auth()->user()).' employee(s) enrolled.', '');
    }

    public function unenrol(TrainingRecord $trecord)
    {
        return $this->attempt(fn () => LS::unenrol($trecord), 'Removed from the session.');
    }

    public function complete(Request $r, TrainingSession $tsession)
    {
        $d = $r->validate(['results' => 'required|array', 'results.*.status' => 'required|string', 'results.*.score' => 'nullable|numeric']);

        return $this->attempt(function () use ($tsession, $d) {
            $o = LS::complete($tsession, $d['results'], auth()->user());

            return "{$tsession->number} completed – {$o['passed']} passed, {$o['failed']} failed, {$o['no_show']} no-show.";
        }, '');
    }

    public function cancelSession(Request $r, TrainingSession $tsession)
    {
        $d = $r->validate(['reason' => 'required|string|max:190']);

        return $this->attempt(fn () => "{$tsession->number} cancelled – ".LS::cancelSession($tsession, $d['reason']).' enrolment(s) released.', '');
    }

    // ───────────────────────── records & certificates ─────────────────────────

    public function records(Request $r)
    {
        $q = TrainingRecord::with('course', 'employee.department', 'session')->where('status', '!=', 'enrolled')->orderByDesc('completed_on')->orderByDesc('id');
        foreach (['status' => 'status', 'course' => 'training_course_id', 'employee' => 'employee_id', 'source' => 'source'] as $k => $col) {
            if ($r->filled($k)) {
                $q->where($col, $r->$k);
            }
        }
        if ($r->filled('department')) {
            $q->whereHas('employee', fn ($w) => $w->where('department_id', (int) $r->department));
        }
        if ($r->filled('expiring')) {
            $q->where('status', 'passed')->whereBetween('expires_on', [bdate()->toDateString(), bdate()->copy()->addDays((int) $r->expiring)->toDateString()]);
        }
        if ($r->filled('q')) {
            $s = '%'.trim((string) $r->q).'%';
            $q->where(fn ($w) => $w->where('certificate_no', 'like', $s)->orWhereHas('employee', fn ($e) => $e->where('name', 'like', $s)));
        }

        return view('learning.records', ['records' => $q->paginate(40)->withQueryString(), 'courses' => TrainingCourse::orderBy('name')->get(), 'employees' => LS::employees(), 'depts' => $this->depts()]);
    }

    public function certificate(TrainingRecord $trecord)
    {
        abort_unless($trecord->status === 'passed', 404);

        return view('learning.certificate', ['r' => $trecord->load('course', 'employee.department', 'session')]);
    }

    public function recordStore(Request $r)
    {
        $d = $r->validate(['employee_id' => 'required|integer', 'training_course_id' => 'required|integer', 'completed_on' => 'required|date_format:Y-m-d', 'status' => 'required|in:passed,failed',
            'score' => 'nullable|numeric', 'expires_on' => 'nullable|date_format:Y-m-d', 'certificate_no' => 'nullable|string|max:40', 'provider' => 'nullable|string|max:150',
            'cost' => 'nullable|numeric|min:0', 'notes' => 'nullable|string|max:255']);

        return $this->attempt(fn () => 'Training record added for '.LS::recordExternal($d, auth()->user())->employee->name.'.', '');
    }

    public function exempt(Request $r)
    {
        $d = $r->validate(['employee_id' => 'required|integer', 'training_course_id' => 'required|integer', 'reason' => 'required|string|max:255', 'until' => 'nullable|date_format:Y-m-d']);

        return $this->attempt(fn () => LS::exempt(Employee::findOrFail($d['employee_id']), TrainingCourse::findOrFail($d['training_course_id']), $d['reason'], $d['until'] ?? null, auth()->user()) ? 'Exemption recorded.' : '', '');
    }

    public function recordDelete(Request $r, TrainingRecord $trecord)
    {
        $d = $r->validate(['reason' => 'required|string|max:190']);

        return $this->attempt(fn () => LS::deleteRecord($trecord, $d['reason']), 'Record deleted.');
    }

    // ───────────────────────── SOP library ─────────────────────────

    public function sops(Request $r)
    {
        $q = SopDocument::with('department', 'owner')->orderBy('code');
        if ($r->filled('status')) {
            $r->status === 'review' ? $q->where('status', 'published')->where('review_due', '<', bdate()->toDateString()) : $q->where('status', $r->status);
        } else {
            $q->where('status', '!=', 'retired');
        }
        if ($r->filled('department')) {
            $r->department === 'all' ? $q->whereNull('department_id') : $q->where('department_id', (int) $r->department);
        }
        if ($r->filled('category')) {
            $q->where('category', $r->category);
        }
        if ($r->filled('q')) {
            $s = '%'.trim((string) $r->q).'%';
            $q->where(fn ($w) => $w->where('code', 'like', $s)->orWhere('title', 'like', $s)->orWhere('summary', 'like', $s)->orWhere('body', 'like', $s));
        }
        $docs = $q->get();
        $acks = LS::sopOverview()->keyBy(fn ($r) => $r['doc']->id);

        return view('learning.sops', ['docs' => $docs, 'acks' => $acks, 'depts' => $this->depts()]);
    }

    public function sop(Request $r, SopDocument $sop)
    {
        $sop->load('department', 'owner', 'versions.publisher');
        $v = $r->filled('v') ? $sop->versions->firstWhere('version', (int) $r->v) : null;

        return view('learning.sop', ['d' => $sop, 'v' => $v, 'ack' => LS::ackStatus($sop), 'courses' => TrainingCourse::where('sop_document_id', $sop->id)->get()]);
    }

    public function sopPrint(Request $r, SopDocument $sop)
    {
        $v = $r->filled('v') ? $sop->versions()->where('version', (int) $r->v)->firstOrFail() : $sop->current;
        abort_unless($v || can_do('learning.sop'), 404);

        return view('learning.sop_print', ['d' => $sop->load('department', 'owner'), 'v' => $v]);
    }

    public function sopSheet(SopDocument $sop)
    {
        abort_unless($sop->status === 'published' && $sop->requires_ack, 404);

        return view('learning.sop_sheet', ['d' => $sop->load('department'), 'ack' => LS::ackStatus($sop)]);
    }

    public function sopRecordSheet(Request $r, SopDocument $sop)
    {
        $d = $r->validate(['employees' => 'required|array|min:1', 'employees.*' => 'integer']);

        return $this->attempt(fn () => LS::recordSheet($sop, $d['employees'], auth()->user()).' signature(s) recorded from the sign-off sheet.', '');
    }

    public function sopCreate()
    {
        return view('learning.sop_form', ['d' => new SopDocument(['category' => 'operations', 'requires_ack' => true, 'review_months' => 12, 'owner_id' => auth()->id()]),
            'depts' => $this->depts(), 'users' => User::where('is_active', true)->orderBy('name')->get()]);
    }

    public function sopEdit(SopDocument $sop)
    {
        abort_if($sop->status === 'retired', 404);

        return view('learning.sop_form', ['d' => $sop, 'depts' => $this->depts(), 'users' => User::where('is_active', true)->orderBy('name')->get()]);
    }

    protected function sopData(Request $r): array
    {
        $d = $r->validate(['code' => 'required|string|max:30', 'title' => 'required|string|max:150', 'category' => 'required|string', 'department_id' => 'nullable|integer',
            'summary' => 'nullable|string|max:255', 'body' => 'nullable|string|max:60000', 'review_months' => 'required|integer|min:1|max:60', 'owner_id' => 'nullable|integer',
            'ack_departments' => 'nullable|array', 'ack_departments.*' => 'integer']);

        return $d + ['requires_ack' => $r->boolean('requires_ack')];
    }

    public function sopStore(Request $r)
    {
        $d = $this->sopData($r);

        return $this->attempt(fn () => redirect()->route('learning.sop', LS::saveSop($d, null, auth()->user()))->with('success', 'Draft saved – publish it when it is ready.'), '');
    }

    public function sopUpdate(Request $r, SopDocument $sop)
    {
        $d = $this->sopData($r);

        return $this->attempt(fn () => redirect()->route('learning.sop', LS::saveSop($d, $sop, auth()->user()))->with('success', $sop->fresh()->has_draft && $sop->version ? 'Saved as a draft of v'.($sop->version + 1).' – staff still see v'.$sop->version.' until you publish.' : 'Saved.'), '');
    }

    public function sopPublish(Request $r, SopDocument $sop)
    {
        $d = $r->validate(['change_note' => 'nullable|string|max:255']);

        return $this->attempt(fn () => 'Published v'.LS::publish($sop, $d['change_note'] ?? null, $r->boolean('reack'), auth()->user())->version.' of '.$sop->code.'.', '');
    }

    public function sopReview(SopDocument $sop)
    {
        return $this->attempt(fn () => LS::markReviewed($sop, auth()->user()), 'Marked as reviewed – next review '.dmy(bdate()->copy()->addMonthsNoOverflow($sop->review_months)).'.');
    }

    public function sopDiscard(SopDocument $sop)
    {
        return $this->attempt(fn () => LS::discardDraft($sop), 'Unpublished edits discarded.');
    }

    public function sopRetire(Request $r, SopDocument $sop)
    {
        $d = $r->validate(['reason' => 'required|string|max:190']);

        return $this->attempt(fn () => LS::retire($sop, $d['reason']), "{$sop->code} retired.");
    }

    // ───────────────────────── settings ─────────────────────────

    public function settings()
    {
        LS::ensureDefaults();

        return view('learning.settings', ['cfg' => LS::cfg()]);
    }

    public function saveSettings(Request $r)
    {
        $d = $r->validate(['expiry_warn_days' => 'required|integer|min:0|max:365', 'review_warn_days' => 'required|integer|min:0|max:365', 'venue' => 'nullable|string|max:120']);
        LS::saveSettings($d + ['self_ack' => $r->boolean('self_ack'), 'notify' => $r->boolean('notify')]);

        return back()->with('success', 'Learning & development settings saved.');
    }

    // ───────────────────────── self-service ─────────────────────────

    public function mine()
    {
        $e = $this->me();
        if (! $e) {
            return view('me.unlinked');
        }

        return view('me.learning', ['e' => $e, 'mine' => LS::mine($e), 'cfg' => LS::cfg()]);
    }

    /** read an SOP that applies to me (or any published SOP) */
    public function mySop(SopDocument $sop)
    {
        $e = $this->me();
        abort_unless($e && $sop->status === 'published', 404);
        $signed = SopAcknowledgement::where('sop_document_id', $sop->id)->where('employee_id', $e->id)->orderByDesc('version')->first();

        return view('me.sop', ['e' => $e, 'd' => $sop, 'v' => $sop->current, 'signed' => $signed, 'needs' => LS::pendingSops($e)->contains('id', $sop->id), 'cfg' => LS::cfg()]);
    }

    public function mySign(Request $r, SopDocument $sop)
    {
        $e = $this->me();
        abort_unless($e, 404);
        if (LS::cfg()['self_ack'] !== '1') {
            return back()->with('error', 'Online signing is switched off – sign the paper sheet with your supervisor.');
        }
        $r->validate(['confirm' => 'accepted'], ['confirm.accepted' => 'Tick the box to confirm you have read and understood the procedure.']);

        return $this->attempt(fn () => LS::acknowledge($sop, $e, 'self', auth()->user()) ? redirect()->route('me.learning')->with('success', "Thank you – you have signed {$sop->code} v{$sop->version}.") : null, '');
    }

    public function myCertificate(TrainingRecord $trecord)
    {
        $e = $this->me();
        abort_unless($e && $trecord->employee_id === $e->id && $trecord->status === 'passed', 404);

        return view('learning.certificate', ['r' => $trecord->load('course', 'employee.department', 'session')]);
    }
}
