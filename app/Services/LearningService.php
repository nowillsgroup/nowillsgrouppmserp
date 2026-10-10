<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Employee;
use App\Models\FsCertificate;
use App\Models\Setting;
use App\Models\SopAcknowledgement;
use App\Models\SopDocument;
use App\Models\SopVersion;
use App\Models\TrainingCourse;
use App\Models\TrainingRecord;
use App\Models\TrainingRequirement;
use App\Models\TrainingSession;
use App\Models\User;
use App\Support\Numbering;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v6.0 Learning & development.
 *
 * Training matrix: a course is *required* for an employee when a requirement rule matches their department and / or job
 * title (a rule with neither applies to everyone). For each required course the employee is
 *  - current   – a passed (or exempt) record that has not expired;
 *  - expiring  – current, but it expires within the warning window;
 *  - expired   – they held it, but the last certificate has lapsed;
 *  - due       – never held, still inside the new-starter grace period (joining date + the rule's due days);
 *  - overdue   – never held and past that date.
 * Compliance % = (current + expiring) ÷ (required − due).
 *
 * SOPs are edited as a draft and published as frozen, numbered versions. Staff in scope must sign ("read and
 * understood") the latest version published with *re-acknowledge*; minor edits keep earlier signatures valid.
 */
class LearningService
{
    public const DEFAULTS = [
        'learning_expiry_warn_days' => '60',   // certificates expiring within this many days are flagged
        'learning_review_warn_days' => '30',   // SOPs due for review within this many days are flagged
        'learning_self_ack' => '1',            // staff can read and sign SOPs themselves on the self-service page
        'learning_notify' => '1',              // SMS the employee when they are enrolled on / removed from a session
        'learning_venue' => 'Training Room, 1st floor',
    ];

    public const STATES = [
        'current' => ['Current', 'green'], 'expiring' => ['Expiring', 'amber'], 'expired' => ['Expired', 'red'],
        'overdue' => ['Overdue', 'red'], 'due' => ['Due', 'blue'],
    ];

    protected static array $memo = [];

    public static function flush(): void
    {
        self::$memo = [];
    }

    public static function ensureDefaults(): void
    {
        foreach (self::DEFAULTS as $k => $v) {
            Setting::firstOrCreate(['key' => $k], ['value' => $v]);
        }
        Setting::$version++;
    }

    public static function cfg(): array
    {
        $s = [];
        foreach (self::DEFAULTS as $k => $v) {
            $s[substr($k, 9)] = (string) setting($k, $v);
        }
        $s['expiry_warn_days'] = max(0, min(365, (int) $s['expiry_warn_days']));
        $s['review_warn_days'] = max(0, min(365, (int) $s['review_warn_days']));

        return $s;
    }

    public static function saveSettings(array $d): void
    {
        foreach (self::DEFAULTS as $k => $v) {
            $short = substr($k, 9);
            if (! array_key_exists($short, $d)) {
                continue;
            }
            $val = is_bool($d[$short]) ? ($d[$short] ? '1' : '0') : trim((string) $d[$short]);
            Setting::updateOrCreate(['key' => $k], ['value' => $val]);
        }
        Setting::$version++;
        self::flush();
        ActivityLogger::log('learning', 'settings', 'Learning & development settings updated');
    }

    protected static function today(): string
    {
        return bdate()->toDateString();
    }

    // ───────────────────────── training matrix ─────────────────────────

    /** active staff: not deactivated and not left before the business date */
    public static function employees(?int $departmentId = null): Collection
    {
        return Employee::with('department')->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('leaving_date')->orWhere('leaving_date', '>=', self::today()))
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->orderBy('department_id')->orderBy('name')->get();
    }

    protected static function rules(): Collection
    {
        return self::$memo['rules'] ??= TrainingRequirement::with('course', 'department')->whereHas('course', fn ($q) => $q->where('is_active', true))->get();
    }

    /** course id => due days (the most demanding matching rule) for one employee */
    public static function required(Employee $e): array
    {
        $out = [];
        foreach (self::rules() as $r) {
            if ($r->applies($e)) {
                $out[$r->training_course_id] = min($out[$r->training_course_id] ?? PHP_INT_MAX, (int) $r->due_days);
            }
        }

        return $out;
    }

    /** the matrix cell for one employee and course, given that employee's records (any order) */
    public static function cell(Employee $e, int $courseId, int $dueDays, Collection $records): array
    {
        $today = self::today();
        $warn = Carbon::parse($today)->addDays(self::cfg()['expiry_warn_days'])->toDateString();
        $mine = $records->where('training_course_id', $courseId);
        $held = $mine->filter(fn ($r) => in_array($r->status, ['passed', 'exempt'], true));
        // the best certificate: one that never expires, else the latest expiry
        $best = $held->sortByDesc(fn ($r) => $r->expires_on ? $r->expires_on->toDateString() : '9999-12-31')->first();
        $booked = $mine->first(fn ($r) => $r->status === 'enrolled' && $r->session && $r->session->status === 'scheduled' && $r->session->date->toDateString() >= $today);
        $cell = ['record' => $best, 'expires' => $best?->expires_on, 'booked' => $booked?->session?->date, 'session' => $booked?->session, 'due' => null];
        if ($best && (! $best->expires_on || $best->expires_on->toDateString() >= $today)) {
            $cell['state'] = $best->expires_on && $best->expires_on->toDateString() <= $warn ? 'expiring' : 'current';
        } elseif ($best) {
            $cell['state'] = 'expired';
        } else {
            $due = $e->joining_date ? $e->joining_date->copy()->addDays($dueDays) : null;
            $cell['due'] = $due;
            $cell['state'] = $due && $due->toDateString() >= $today ? 'due' : 'overdue';
        }

        return $cell;
    }

    /** ['employees', 'courses', 'cells' => [empId][courseId] => cell, 'rows' => [empId => stats], 'totals'] */
    public static function matrix(?int $departmentId = null): array
    {
        $key = 'matrix_'.($departmentId ?? 0);
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }
        $emps = self::employees($departmentId);
        $records = TrainingRecord::with('session')->whereIn('employee_id', $emps->pluck('id'))->get()->groupBy('employee_id');
        $cells = [];
        $rows = [];
        $courseIds = [];
        $tot = array_fill_keys(array_keys(self::STATES), 0);
        foreach ($emps as $e) {
            $row = array_fill_keys(array_keys(self::STATES), 0);
            foreach (self::required($e) as $cid => $days) {
                $c = self::cell($e, $cid, $days, $records->get($e->id, collect()));
                $cells[$e->id][$cid] = $c;
                $row[$c['state']]++;
                $tot[$c['state']]++;
                $courseIds[$cid] = true;
            }
            $row['required'] = array_sum($row);
            $row['pct'] = self::pct($row);
            $rows[$e->id] = $row;
        }
        $tot['required'] = array_sum($tot);
        $tot['pct'] = self::pct($tot);
        $courses = TrainingCourse::whereIn('id', array_keys($courseIds))->orderBy('category')->orderBy('name')->get();

        return self::$memo[$key] = ['employees' => $emps, 'courses' => $courses, 'cells' => $cells, 'rows' => $rows, 'totals' => $tot];
    }

    public static function pct(array $c): ?float
    {
        $base = ($c['current'] ?? 0) + ($c['expiring'] ?? 0) + ($c['expired'] ?? 0) + ($c['overdue'] ?? 0);

        return $base ? round((($c['current'] ?? 0) + ($c['expiring'] ?? 0)) * 100 / $base, 1) : null;
    }

    /** compliance by department */
    public static function byDepartment(): Collection
    {
        $m = self::matrix();
        $out = [];
        foreach ($m['employees'] as $e) {
            $k = $e->department_id ?? 0;
            $out[$k] ??= ['department' => $e->department?->name ?? 'No department', 'staff' => 0] + array_fill_keys(array_keys(self::STATES), 0);
            $out[$k]['staff']++;
            foreach (array_keys(self::STATES) as $s) {
                $out[$k][$s] += $m['rows'][$e->id][$s];
            }
        }

        return collect($out)->map(fn ($r) => $r + ['required' => array_sum(array_intersect_key($r, self::STATES)), 'pct' => self::pct($r)])->sortBy('department')->values();
    }

    /** every non-compliant or expiring cell, most urgent first: [employee, course, cell] */
    public static function gaps(array $states = ['overdue', 'expired', 'expiring']): Collection
    {
        $m = self::matrix();
        $courses = $m['courses']->keyBy('id');
        $out = [];
        foreach ($m['employees'] as $e) {
            foreach ($m['cells'][$e->id] ?? [] as $cid => $c) {
                if (in_array($c['state'], $states, true)) {
                    $out[] = ['employee' => $e, 'course' => $courses[$cid], 'cell' => $c,
                        'sort' => [array_search($c['state'], ['expired', 'overdue', 'expiring', 'due', 'current'], true), ($c['expires'] ?? $c['due'])?->toDateString() ?? '0000']];
                }
            }
        }

        return collect($out)->sortBy('sort')->values();
    }

    /** staff who need this course now (not current, or expiring), with no scheduled seat yet – enrolment suggestions */
    public static function needing(TrainingCourse $c): Collection
    {
        return self::gaps(['overdue', 'expired', 'expiring', 'due'])->filter(fn ($g) => $g['course']->id === $c->id && ! $g['cell']['booked'])->values();
    }

    public static function alerts(): array
    {
        return self::$memo['alerts'] ??= (function () {
            $t = self::matrix()['totals'];
            $warn = bdate()->copy()->addDays(self::cfg()['review_warn_days'])->toDateString();

            return [
                'overdue' => $t['overdue'], 'expired' => $t['expired'], 'expiring' => $t['expiring'],
                'review_overdue' => SopDocument::where('status', 'published')->where('review_due', '<', self::today())->count(),
                'review_soon' => SopDocument::where('status', 'published')->whereBetween('review_due', [self::today(), $warn])->count(),
                'to_complete' => TrainingSession::where('status', 'scheduled')->where('date', '<', self::today())->count(),
            ];
        })();
    }

    public static function attention(): int
    {
        $a = self::alerts();

        return $a['overdue'] + $a['expired'] + $a['review_overdue'] + $a['to_complete'];
    }

    // ───────────────────────── courses & requirements ─────────────────────────

    public static function saveCourse(array $d, ?TrainingCourse $c = null): TrainingCourse
    {
        $code = strtoupper(trim((string) ($d['code'] ?? '')));
        if (! preg_match('/^[A-Z0-9][A-Z0-9\-]{0,19}$/', $code)) {
            throw new RuntimeException('Give the course a short code (letters, digits, dashes).');
        }
        if (TrainingCourse::where('code', $code)->when($c, fn ($q) => $q->where('id', '!=', $c->id))->exists()) {
            throw new RuntimeException("Course code {$code} is already used.");
        }
        if (! isset(TrainingCourse::CATEGORIES[$d['category'] ?? '']) || ! isset(TrainingCourse::DELIVERY[$d['delivery'] ?? ''])) {
            throw new RuntimeException('Choose a category and a delivery method.');
        }
        $validity = ($d['validity_months'] ?? '') === '' || $d['validity_months'] === null ? null : (int) $d['validity_months'];
        $fs = ($d['fs_type'] ?? '') === 'hygiene' ? 'hygiene' : null;
        if ($fs && ! $validity) {
            throw new RuntimeException('A food-hygiene course renews the food-safety certificate, so it needs a validity period.');
        }
        if ($validity !== null && ($validity < 1 || $validity > 120)) {
            throw new RuntimeException('Validity is between 1 and 120 months (leave it empty if the course never expires).');
        }
        $pass = ($d['pass_mark'] ?? '') === '' || $d['pass_mark'] === null ? null : (int) $d['pass_mark'];
        if ($pass !== null && ($pass < 1 || $pass > 100)) {
            throw new RuntimeException('The pass mark is a percentage between 1 and 100 (leave it empty for attendance only).');
        }
        $data = ['code' => $code, 'name' => trim((string) $d['name']), 'category' => $d['category'], 'delivery' => $d['delivery'],
            'description' => $d['description'] ?? null, 'duration_hours' => max(0.5, min(200, (float) ($d['duration_hours'] ?? 1))),
            'validity_months' => $validity, 'pass_mark' => $pass, 'provider' => $d['provider'] ?? null, 'cost_per_head' => max(0, (float) ($d['cost_per_head'] ?? 0)),
            'sop_document_id' => ! empty($d['sop_document_id']) ? SopDocument::findOrFail((int) $d['sop_document_id'])->id : null,
            'fs_type' => $fs, 'is_active' => (bool) ($d['is_active'] ?? true)];
        if ($data['name'] === '') {
            throw new RuntimeException('Give the course a name.');
        }
        $c ? $c->update($data) : $c = TrainingCourse::create($data);
        self::flush();
        ActivityLogger::log('learning', 'course', "Training course {$c->code} {$c->name} saved", $c);

        return $c;
    }

    public static function addRequirement(TrainingCourse $c, ?int $departmentId, ?string $designation, int $dueDays): TrainingRequirement
    {
        $designation = trim((string) $designation) ?: null;
        $dept = $departmentId ? Department::findOrFail($departmentId) : null;
        if ($dueDays < 0 || $dueDays > 730) {
            throw new RuntimeException('New starters must be trained within 0 – 730 days of joining.');
        }
        $dup = TrainingRequirement::where('training_course_id', $c->id)->where('department_id', $dept?->id)
            ->when($designation, fn ($q) => $q->where('designation', $designation), fn ($q) => $q->whereNull('designation'))->exists();
        if ($dup) {
            throw new RuntimeException('That requirement already exists for this course.');
        }
        $r = TrainingRequirement::create(['training_course_id' => $c->id, 'department_id' => $dept?->id, 'designation' => $designation, 'due_days' => $dueDays]);
        self::flush();
        ActivityLogger::log('learning', 'requirement', "{$c->name} required for ".$r->fresh('department')->label()." (within {$dueDays} days of joining)", $c);

        return $r;
    }

    public static function removeRequirement(TrainingRequirement $r): void
    {
        $label = $r->label();
        $c = $r->course;
        $r->delete();
        self::flush();
        ActivityLogger::log('learning', 'requirement', "{$c->name} no longer required for {$label}", $c);
    }

    // ───────────────────────── sessions ─────────────────────────

    public static function schedule(array $d, User $u): TrainingSession
    {
        $c = TrainingCourse::where('is_active', true)->findOrFail((int) ($d['training_course_id'] ?? 0));
        $date = (string) ($d['date'] ?? '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new RuntimeException('Choose the session date.');
        }
        if ($date < bdate()->copy()->subDays(30)->toDateString()) {
            throw new RuntimeException('Sessions more than 30 days ago are recorded as individual training records instead.');
        }
        $start = (string) ($d['start_time'] ?? '09:00');
        $end = ($d['end_time'] ?? '') ?: null;
        if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start) || ($end && (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $end) || $end <= $start))) {
            throw new RuntimeException('Check the start and end times.');
        }
        $cap = ($d['capacity'] ?? '') === '' || $d['capacity'] === null ? null : (int) $d['capacity'];
        if ($cap !== null && ($cap < 1 || $cap > 500)) {
            throw new RuntimeException('Capacity is between 1 and 500 (leave it empty for no limit).');
        }
        $trainer = ! empty($d['trainer_id']) ? User::findOrFail((int) $d['trainer_id']) : null;
        $s = TrainingSession::create(['number' => Numbering::next('training_session'), 'training_course_id' => $c->id, 'date' => $date, 'start_time' => $start, 'end_time' => $end,
            'venue' => trim((string) ($d['venue'] ?? '')) ?: self::cfg()['venue'], 'trainer_id' => $trainer?->id, 'trainer_name' => trim((string) ($d['trainer_name'] ?? '')) ?: ($trainer ? null : $c->provider),
            'capacity' => $cap, 'cost' => max(0, (float) ($d['cost'] ?? 0)), 'notes' => $d['notes'] ?? null, 'status' => 'scheduled', 'created_by' => $u->id]);
        ActivityLogger::log('learning', 'session', "Scheduled {$s->number} {$c->name} on ".dmy($date)." {$start}", $s);
        if (! empty($d['employees'])) {
            self::enrol($s, (array) $d['employees'], $u);
        }

        return $s;
    }

    public static function enrol(TrainingSession $s, array $employeeIds, User $u): int
    {
        if ($s->status !== 'scheduled') {
            throw new RuntimeException("{$s->number} is {$s->statusLabel()} – enrolment is closed.");
        }
        $ids = array_values(array_unique(array_map('intval', $employeeIds)));
        if (! $ids) {
            throw new RuntimeException('Tick at least one employee.');
        }
        $active = self::employees()->keyBy('id');
        $already = $s->records()->pluck('employee_id')->all();
        $new = array_values(array_diff($ids, $already));
        foreach ($new as $id) {
            if (! $active->has($id)) {
                throw new RuntimeException('Only current employees can be enrolled.');
            }
        }
        if (! $new) {
            throw new RuntimeException('Those employees are already enrolled.');
        }
        if ($s->capacity && count($already) + count($new) > $s->capacity) {
            throw new RuntimeException("{$s->number} has room for {$s->capacity} – ".max(0, $s->capacity - count($already)).' place(s) left.');
        }
        DB::transaction(function () use ($s, $new, $u, $active) {
            foreach ($new as $id) {
                $r = TrainingRecord::create(['training_course_id' => $s->training_course_id, 'training_session_id' => $s->id, 'employee_id' => $id, 'status' => 'enrolled',
                    'source' => 'session', 'recorded_by' => $u->id]);
                self::notify($r, $active[$id], 'enrolled');
            }
        });
        self::flush();
        ActivityLogger::log('learning', 'enrol', count($new)." employee(s) enrolled on {$s->number} {$s->course->name}", $s);

        return count($new);
    }

    public static function unenrol(TrainingRecord $r): void
    {
        if ($r->status !== 'enrolled' || ! $r->session || $r->session->status !== 'scheduled') {
            throw new RuntimeException('Only enrolments on a scheduled session can be removed.');
        }
        self::notify($r, $r->employee, 'removed');
        $r->delete();
        self::flush();
        ActivityLogger::log('learning', 'unenrol', "{$r->employee->name} removed from {$r->session->number}", $r->session);
    }

    /**
     * Record the outcome. $results: employee id => ['status' => attended|passed|failed|no_show, 'score' => ?]. With a pass mark,
     * an attended employee needs a score and passes at or above it; without one, attending is passing.
     */
    public static function complete(TrainingSession $s, array $results, User $u): array
    {
        if ($s->status !== 'scheduled') {
            throw new RuntimeException("{$s->number} is {$s->statusLabel()}.");
        }
        if ($s->date->toDateString() > self::today()) {
            throw new RuntimeException("{$s->number} is on ".dmy($s->date).' – record the results after it has taken place.');
        }
        $recs = $s->records()->with('employee')->where('status', 'enrolled')->get();
        if ($recs->isEmpty()) {
            throw new RuntimeException('Nobody is enrolled – enrol the attendees first, or cancel the session.');
        }
        $c = $s->course;
        $plan = [];
        foreach ($recs as $r) {
            $in = $results[$r->employee_id] ?? [];
            $st = $in['status'] ?? 'no_show';
            $score = isset($in['score']) && $in['score'] !== '' ? (float) $in['score'] : null;
            if ($score !== null && ($score < 0 || $score > 100)) {
                throw new RuntimeException("Scores are percentages – check {$r->employee->name}.");
            }
            if ($st === 'no_show') {
                $plan[$r->id] = ['no_show', null];

                continue;
            }
            if (! in_array($st, ['attended', 'passed', 'failed'], true)) {
                throw new RuntimeException("Unknown result for {$r->employee->name}.");
            }
            if ($c->pass_mark) {
                if ($score === null) {
                    throw new RuntimeException("{$c->name} has a pass mark of {$c->pass_mark}% – enter {$r->employee->name}'s score.");
                }
                $st = $score >= $c->pass_mark ? 'passed' : 'failed';
            } elseif ($st === 'attended') {
                $st = 'passed';
            }
            $plan[$r->id] = [$st, $score];
        }
        $attended = collect($plan)->filter(fn ($p) => $p[0] !== 'no_show')->count();
        $perHead = $attended ? round(((float) $s->cost ?: (float) $c->cost_per_head * $attended) / $attended, 2) : 0;
        $out = ['passed' => 0, 'failed' => 0, 'no_show' => 0];
        DB::transaction(function () use ($s, $recs, $plan, $perHead, $u, $c, &$out) {
            foreach ($recs as $r) {
                [$st, $score] = $plan[$r->id];
                $upd = ['status' => $st, 'score' => $score, 'completed_on' => $s->date->toDateString(), 'cost' => $st === 'no_show' ? 0 : $perHead, 'recorded_by' => $u->id];
                if ($st === 'passed') {
                    $upd['expires_on'] = $c->validity_months ? $s->date->copy()->addMonthsNoOverflow($c->validity_months)->subDay()->toDateString() : null;
                    $upd['certificate_no'] = Numbering::next('training_cert');
                }
                $r->forceFill($upd)->save();
                $out[$st]++;
                if ($st === 'passed') {
                    self::afterPass($r->fresh(['course', 'employee']), $u);
                }
            }
            $s->forceFill(['status' => 'completed', 'completed_at' => now(), 'completed_by' => $u->id])->save();
        });
        self::flush();
        ActivityLogger::log('learning', 'complete', "{$s->number} {$c->name} completed: {$out['passed']} passed, {$out['failed']} failed, {$out['no_show']} no-show", $s);

        return $out;
    }

    public static function cancelSession(TrainingSession $s, string $reason): int
    {
        if ($s->status !== 'scheduled') {
            throw new RuntimeException("{$s->number} is {$s->statusLabel()}.");
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give a reason – enrolled staff will be told.');
        }
        $n = 0;
        DB::transaction(function () use ($s, $reason, &$n) {
            foreach ($s->records()->with('employee')->where('status', 'enrolled')->get() as $r) {
                self::notify($r, $r->employee, 'cancelled');
                $r->delete();
                $n++;
            }
            $s->forceFill(['status' => 'cancelled', 'cancel_reason' => mb_substr(trim($reason), 0, 190)])->save();
        });
        self::flush();
        ActivityLogger::log('learning', 'cancel', "{$s->number} {$s->course->name} cancelled ({$reason}) – {$n} enrolment(s) released", $s);

        return $n;
    }

    // ───────────────────────── individual records ─────────────────────────

    /** training completed elsewhere (previous employer, external body) – or a failed attempt */
    public static function recordExternal(array $d, User $u): TrainingRecord
    {
        $e = Employee::findOrFail((int) ($d['employee_id'] ?? 0));
        $c = TrainingCourse::findOrFail((int) ($d['training_course_id'] ?? 0));
        $on = (string) ($d['completed_on'] ?? '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $on) || $on > self::today()) {
            throw new RuntimeException('Enter the completion date (not after the business date).');
        }
        $st = ($d['status'] ?? 'passed') === 'failed' ? 'failed' : 'passed';
        $score = isset($d['score']) && $d['score'] !== '' ? (float) $d['score'] : null;
        if ($score !== null && ($score < 0 || $score > 100)) {
            throw new RuntimeException('The score is a percentage.');
        }
        if ($st === 'passed' && $c->pass_mark && $score !== null && $score < $c->pass_mark) {
            throw new RuntimeException("That score is below the {$c->pass_mark}% pass mark – record it as failed.");
        }
        $exp = null;
        if ($st === 'passed') {
            $exp = ! empty($d['expires_on']) ? (string) $d['expires_on'] : ($c->validity_months ? Carbon::parse($on)->addMonthsNoOverflow($c->validity_months)->subDay()->toDateString() : null);
            if ($exp && $exp < $on) {
                throw new RuntimeException('The certificate cannot expire before it was issued.');
            }
            if ($c->fs_type && ! $exp) {
                throw new RuntimeException('Enter the certificate expiry date.');
            }
        }
        $r = DB::transaction(function () use ($e, $c, $on, $st, $score, $exp, $d, $u) {
            $r = TrainingRecord::create(['training_course_id' => $c->id, 'employee_id' => $e->id, 'status' => $st, 'source' => 'external', 'score' => $score,
                'completed_on' => $on, 'expires_on' => $exp, 'certificate_no' => trim((string) ($d['certificate_no'] ?? '')) ?: null,
                'provider' => trim((string) ($d['provider'] ?? '')) ?: $c->provider, 'cost' => max(0, (float) ($d['cost'] ?? 0)), 'notes' => $d['notes'] ?? null, 'recorded_by' => $u->id]);
            if ($st === 'passed') {
                self::afterPass($r->fresh(['course', 'employee']), $u);
            }

            return $r;
        });
        self::flush();
        ActivityLogger::log('learning', 'record', "{$c->name} recorded for {$e->name}: ".strtolower($r->statusLabel()).' '.dmy($on).($exp ? ', valid to '.dmy($exp) : ''), $r);

        return $r;
    }

    /** a manager decides the course does not apply to this person (e.g. qualified first-aider nurse), optionally until a date */
    public static function exempt(Employee $e, TrainingCourse $c, string $reason, ?string $until, User $u): TrainingRecord
    {
        if (trim($reason) === '') {
            throw new RuntimeException('Give the reason for the exemption.');
        }
        if ($until && $until < self::today()) {
            throw new RuntimeException('The exemption must run to today or later.');
        }
        $r = TrainingRecord::create(['training_course_id' => $c->id, 'employee_id' => $e->id, 'status' => 'exempt', 'source' => 'exempt', 'completed_on' => self::today(),
            'expires_on' => $until ?: null, 'notes' => mb_substr(trim($reason), 0, 255), 'recorded_by' => $u->id]);
        self::flush();
        ActivityLogger::log('learning', 'exempt', "{$e->name} exempted from {$c->name}".($until ? ' until '.dmy($until) : '').": {$reason}", $r);

        return $r;
    }

    /** remove a mistaken external record or exemption (session results are corrected by the trainer, not deleted) */
    public static function deleteRecord(TrainingRecord $r, string $reason): void
    {
        if ($r->source === 'session') {
            throw new RuntimeException('Session results cannot be deleted.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give a reason.');
        }
        $desc = "{$r->course->name} record for {$r->employee->name} (".strtolower($r->statusLabel()).') deleted: '.$reason;
        $r->delete();
        self::flush();
        ActivityLogger::log('learning', 'record_deleted', $desc, $r->employee);
    }

    /** passing: counts as signing the course's SOP and renews the food-safety hygiene certificate */
    protected static function afterPass(TrainingRecord $r, User $u): void
    {
        $c = $r->course;
        if ($c->sop_document_id && ($sop = SopDocument::find($c->sop_document_id)) && $sop->status === 'published' && $sop->requires_ack) {
            SopAcknowledgement::firstOrCreate(['sop_document_id' => $sop->id, 'version' => $sop->version, 'employee_id' => $r->employee_id],
                ['via' => 'training', 'acknowledged_at' => now(), 'recorded_by' => $u->id]);
        }
        if ($c->fs_type === 'hygiene' && $r->expires_on) {
            FsCertificate::create(['employee_id' => $r->employee_id, 'type' => 'hygiene', 'cert_no' => $r->certificate_no, 'issuer' => $r->provider ?: ($c->provider ?: hotel()->name),
                'issued_on' => $r->completed_on->toDateString(), 'expires_on' => $r->expires_on->toDateString(), 'notes' => 'From training '.$c->code.($r->session ? ' '.$r->session->number : ''), 'user_id' => $u->id]);
        }
    }

    protected static function notify(TrainingRecord $r, ?Employee $e, string $kind): void
    {
        if (! $e || ! $e->phone || self::cfg()['notify'] !== '1' || ! $r->session) {
            return;
        }
        $s = $r->session;
        $when = $s->date->format('D d M').' '.$s->start_time;
        $text = match ($kind) {
            'enrolled' => "Hi {$e->name}, you are booked on {$s->course->name} – {$when}, {$s->venue}. Please be on time.",
            'removed' => "Hi {$e->name}, you are no longer booked on {$s->course->name} on {$when}.",
            default => "Hi {$e->name}, {$s->course->name} on {$when} has been cancelled. We will book you on a new date.",
        };
        try {
            MessageService::send($e->phone, $text.' – '.hotel()->name, MessageService::defaultChannel(), 'training_'.$kind, $s);
        } catch (\Throwable) {
            // messaging problems never block training administration
        }
    }

    // ───────────────────────── SOP library ─────────────────────────

    public static function saveSop(array $d, ?SopDocument $doc, User $u): SopDocument
    {
        $code = strtoupper(trim((string) ($d['code'] ?? '')));
        if (! preg_match('/^[A-Z0-9][A-Z0-9\-\.]{1,29}$/', $code)) {
            throw new RuntimeException('Give the SOP a reference code such as SOP-FO-001.');
        }
        if (SopDocument::where('code', $code)->when($doc, fn ($q) => $q->where('id', '!=', $doc->id))->exists()) {
            throw new RuntimeException("SOP {$code} already exists.");
        }
        if ($doc && $doc->status === 'retired') {
            throw new RuntimeException("{$doc->code} is retired.");
        }
        if (! isset(SopDocument::CATEGORIES[$d['category'] ?? ''])) {
            throw new RuntimeException('Choose a category.');
        }
        $title = trim((string) ($d['title'] ?? ''));
        if ($title === '') {
            throw new RuntimeException('Give the SOP a title.');
        }
        $depts = array_values(array_filter(array_map('intval', (array) ($d['ack_departments'] ?? []))));
        $data = ['code' => $code, 'title' => mb_substr($title, 0, 150), 'category' => $d['category'], 'summary' => $d['summary'] ?? null, 'body' => (string) ($d['body'] ?? ''),
            'department_id' => ! empty($d['department_id']) ? Department::findOrFail((int) $d['department_id'])->id : null,
            'requires_ack' => (bool) ($d['requires_ack'] ?? false), 'ack_departments' => $depts ?: null,
            'review_months' => max(1, min(60, (int) ($d['review_months'] ?? 12))), 'owner_id' => ! empty($d['owner_id']) ? User::findOrFail((int) $d['owner_id'])->id : $u->id];
        if ($doc) {
            $cur = $doc->current;
            $data['has_draft'] = ! $cur || $cur->body !== $data['body'] || $cur->title !== $data['title'];
            if ($doc->review_months !== $data['review_months'] && $doc->last_reviewed) {
                $data['review_due'] = $doc->last_reviewed->copy()->addMonthsNoOverflow($data['review_months'])->toDateString();
            }
            $doc->update($data);
        } else {
            $doc = SopDocument::create($data + ['status' => 'draft', 'version' => 0, 'has_draft' => true, 'created_by' => $u->id]);
        }
        self::flush();
        ActivityLogger::log('learning', 'sop_saved', "SOP {$doc->code} {$doc->title} saved".($doc->version ? ' (draft of v'.($doc->version + 1).')' : ''), $doc);

        return $doc;
    }

    public static function publish(SopDocument $doc, ?string $note, bool $reack, User $u): SopVersion
    {
        if ($doc->status === 'retired') {
            throw new RuntimeException("{$doc->code} is retired.");
        }
        if (trim((string) $doc->body) === '') {
            throw new RuntimeException('Write the procedure before publishing it.');
        }
        $cur = $doc->current;
        if ($cur && $cur->body === $doc->body && $cur->title === $doc->title) {
            throw new RuntimeException('Nothing has changed since v'.$cur->version.' – use "Mark reviewed" to confirm it is still correct.');
        }
        if ($cur && trim((string) $note) === '') {
            throw new RuntimeException('Say what changed in this version.');
        }

        return DB::transaction(function () use ($doc, $note, $reack, $u, $cur) {
            $n = $doc->version + 1;
            $v = SopVersion::create(['sop_document_id' => $doc->id, 'version' => $n, 'title' => $doc->title, 'body' => $doc->body, 'change_note' => trim((string) $note) ?: 'First issue',
                'reack' => $cur ? $reack : true, 'effective_date' => self::today(), 'published_by' => $u->id, 'published_at' => now()]);
            $doc->forceFill(['version' => $n, 'status' => 'published', 'has_draft' => false, 'effective_date' => self::today(), 'last_reviewed' => self::today(),
                'review_due' => bdate()->copy()->addMonthsNoOverflow($doc->review_months)->toDateString()])->save();
            self::flush();
            ActivityLogger::log('learning', 'sop_published', "SOP {$doc->code} v{$n} published".($cur ? ($v->reack ? ' – staff must sign again' : ' – minor change, signatures kept') : ''), $doc);

            return $v;
        });
    }

    public static function markReviewed(SopDocument $doc, User $u): void
    {
        if ($doc->status !== 'published') {
            throw new RuntimeException('Only published SOPs are reviewed.');
        }
        if ($doc->has_draft) {
            throw new RuntimeException('There are unpublished edits – publish them or discard them first.');
        }
        $doc->forceFill(['last_reviewed' => self::today(), 'review_due' => bdate()->copy()->addMonthsNoOverflow($doc->review_months)->toDateString()])->save();
        self::flush();
        ActivityLogger::log('learning', 'sop_reviewed', "SOP {$doc->code} v{$doc->version} reviewed – still correct; next review ".dmy($doc->review_due), $doc);
    }

    public static function discardDraft(SopDocument $doc): void
    {
        $cur = $doc->current ?? throw new RuntimeException('This SOP has never been published – there is nothing to go back to.');
        $doc->forceFill(['body' => $cur->body, 'title' => $cur->title, 'has_draft' => false])->save();
        self::flush();
        ActivityLogger::log('learning', 'sop_discard', "SOP {$doc->code}: unpublished edits discarded (back to v{$cur->version})", $doc);
    }

    public static function retire(SopDocument $doc, string $reason): void
    {
        if ($doc->status === 'retired') {
            throw new RuntimeException("{$doc->code} is already retired.");
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give the reason (e.g. replaced by another SOP).');
        }
        if (TrainingCourse::where('sop_document_id', $doc->id)->where('is_active', true)->exists()) {
            throw new RuntimeException('An active training course is based on this SOP – unlink it first.');
        }
        $doc->forceFill(['status' => 'retired', 'has_draft' => false])->save();
        self::flush();
        ActivityLogger::log('learning', 'sop_retired', "SOP {$doc->code} retired: {$reason}", $doc);
    }

    /** staff who must sign this SOP */
    public static function inScope(SopDocument $doc): Collection
    {
        if ($doc->status !== 'published' || ! $doc->requires_ack) {
            return collect();
        }
        $scope = $doc->ackScope();

        return self::employees()->filter(fn ($e) => $scope === null || in_array((int) $e->department_id, $scope, true))->values();
    }

    /** ['required' => n, 'signed' => n, 'pct', 'version' => the version that must be signed, 'rows' => [employee, ack|null]] */
    public static function ackStatus(SopDocument $doc): array
    {
        $need = self::inScope($doc);
        $v = $doc->ackVersion();
        $acks = SopAcknowledgement::where('sop_document_id', $doc->id)->where('version', '>=', max(1, $v))->orderByDesc('version')->get()->unique('employee_id')->keyBy('employee_id');
        $rows = $need->map(fn ($e) => ['employee' => $e, 'ack' => $acks->get($e->id)]);
        $signed = $rows->filter(fn ($r) => $r['ack'])->count();

        return ['required' => $need->count(), 'signed' => $signed, 'pct' => $need->count() ? round($signed * 100 / $need->count(), 1) : null, 'version' => $v, 'rows' => $rows];
    }

    public static function acknowledge(SopDocument $doc, Employee $e, string $via, ?User $u): SopAcknowledgement
    {
        if ($doc->status !== 'published') {
            throw new RuntimeException("{$doc->code} is not published.");
        }
        if (! $doc->requires_ack) {
            throw new RuntimeException("{$doc->code} does not need signing.");
        }
        if (! isset(SopAcknowledgement::VIA[$via])) {
            throw new RuntimeException('Unknown sign-off method.');
        }
        if (SopAcknowledgement::where('sop_document_id', $doc->id)->where('employee_id', $e->id)->where('version', $doc->version)->exists()) {
            throw new RuntimeException("{$e->name} has already signed v{$doc->version} of {$doc->code}.");
        }
        $a = SopAcknowledgement::create(['sop_document_id' => $doc->id, 'version' => $doc->version, 'employee_id' => $e->id, 'via' => $via, 'acknowledged_at' => now(), 'recorded_by' => $u?->id]);
        self::flush();
        ActivityLogger::log('learning', 'sop_ack', "{$e->name} signed SOP {$doc->code} v{$doc->version} (".strtolower(SopAcknowledgement::VIA[$via]).')', $doc);

        return $a;
    }

    /** a paper sign-off sheet: record every ticked employee who has not signed this version yet */
    public static function recordSheet(SopDocument $doc, array $employeeIds, User $u): int
    {
        $ids = array_map('intval', $employeeIds);
        if (! $ids) {
            throw new RuntimeException('Tick the staff who signed the sheet.');
        }
        $n = 0;
        foreach (Employee::whereIn('id', $ids)->get() as $e) {
            if (! SopAcknowledgement::where('sop_document_id', $doc->id)->where('employee_id', $e->id)->where('version', $doc->version)->exists()) {
                self::acknowledge($doc, $e, 'sheet', $u);
                $n++;
            }
        }
        if (! $n) {
            throw new RuntimeException('Everyone ticked has already signed this version.');
        }

        return $n;
    }

    /** SOPs an employee still has to sign */
    public static function pendingSops(Employee $e): Collection
    {
        return SopDocument::where('status', 'published')->where('requires_ack', true)->orderBy('code')->get()
            ->filter(function ($d) use ($e) {
                $scope = $d->ackScope();
                if ($scope !== null && ! in_array((int) $e->department_id, $scope, true)) {
                    return false;
                }

                return ! SopAcknowledgement::where('sop_document_id', $d->id)->where('employee_id', $e->id)->where('version', '>=', max(1, $d->ackVersion()))->exists();
            })->values();
    }

    /** SOP sign-off overview: every published SOP needing signatures with required / signed counts */
    public static function sopOverview(): Collection
    {
        return SopDocument::with('department')->where('status', 'published')->where('requires_ack', true)->orderBy('code')->get()
            ->map(fn ($d) => ['doc' => $d] + self::ackStatus($d));
    }

    // ───────────────────────── self-service ─────────────────────────

    public static function mine(Employee $e): array
    {
        $recs = TrainingRecord::with('course', 'session')->where('employee_id', $e->id)->orderByDesc('completed_on')->orderByDesc('id')->get();
        $cells = [];
        foreach (self::required($e) as $cid => $days) {
            $cells[$cid] = self::cell($e, $cid, $days, $recs);
        }
        $courses = TrainingCourse::whereIn('id', array_keys($cells))->get()->keyBy('id');
        $req = collect($cells)->map(fn ($c, $cid) => ['course' => $courses[$cid], 'cell' => $c])->sortBy(fn ($r) => array_search($r['cell']['state'], ['expired', 'overdue', 'expiring', 'due', 'current'], true))->values();
        $upcoming = $recs->filter(fn ($r) => $r->status === 'enrolled' && $r->session && $r->session->status === 'scheduled')->sortBy(fn ($r) => $r->session->date)->values();
        $count = array_count_values(array_map(fn ($c) => $c['state'], $cells));

        return ['required' => $req, 'records' => $recs->where('status', '!=', 'enrolled')->values(), 'upcoming' => $upcoming, 'pending' => self::pendingSops($e),
            'signed' => SopAcknowledgement::with('document')->where('employee_id', $e->id)->orderByDesc('acknowledged_at')->limit(30)->get(), 'pct' => self::pct($count)];
    }
}
