<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Employee;
use App\Models\KeyLoan;
use App\Models\LeaveRequest;
use App\Models\PayslipLine;
use App\Models\Setting;
use App\Models\StaffAdvance;
use App\Models\TalentApplicant;
use App\Models\TalentAppraisal;
use App\Models\TalentCase;
use App\Models\TalentCycle;
use App\Models\TalentExit;
use App\Models\TalentInterview;
use App\Models\TalentOffer;
use App\Models\TalentProbation;
use App\Models\TalentScorecard;
use App\Models\TalentTask;
use App\Models\TalentVacancy;
use App\Models\User;
use App\Support\Numbering;
use App\Support\Tenant;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * v6.9 Talent management – the employee life cycle around the HR & payroll register, under the Labour Act 2003 (Act 651).
 *  - Recruitment: a vacancy is approved by someone other than the requester; going over the department's establishment
 *    needs a note. Applicants come from the public careers page (with data-protection consent), referrals, agencies and
 *    walk-ins; one application per phone / e-mail per vacancy; rejection needs a reason. Interview panels score five
 *    criteria 1–5 (only panel members, only once the interview has started). An offer above the salary range waits for
 *    approval by someone other than the recruiter; accepting it creates the employee record with probation, contract end
 *    and the onboarding checklist.
 *  - New starters: the written statement of particulars is due within two months of starting (s. 13); probation ends in
 *    confirmation, one extension, or termination.
 *  - Appraisals: goals (weighted) and competencies rated 1–5 by the employee and the line manager; overall = goals ×
 *    weight + competencies × (100 − weight); five bands; below 2.6 triggers a performance improvement plan. HR signs off
 *    (never the reviewer) and the employee acknowledges.
 *  - Discipline: a written query with a reply window, then a hearing for serious sanctions; a warning expires after its
 *    period; dismissal only for proven misconduct – gross misconduct, or major misconduct with a live final warning –
 *    decided by an approver who did not raise the case, confirming none of the s. 63 unfair reasons apply.
 *  - Exits: notice under s. 17 (one month after three years' service, otherwise two weeks, a week for weekly pay, none at
 *    will; a longer contractual period prevails; at least a month for a worker with a disability, s. 51), pay in lieu,
 *    final pay by the end of notice or the next working day (s. 18), redundancy notified to the Chief Labour Officer three
 *    months ahead (s. 65), no employer termination during maternity leave (s. 57(8)); clearance and exit interview.
 */
class TalentService
{
    public const DEFAULTS = [
        'talent_probation_months' => '6', 'talent_probation_notice' => '7', 'talent_query_days' => '3', 'talent_offer_days' => '7',
        'talent_warn_verbal' => '6', 'talent_warn_written' => '12', 'talent_warn_final' => '12', 'talent_retention_months' => '6',
        'talent_goal_weight' => '60', 'talent_careers' => '1', 'talent_careers_intro' => 'Join our team. We hire for attitude and train for skill.',
    ];

    public const CRITERIA = ['communication' => 'Communication', 'attitude' => 'Guest attitude', 'skills' => 'Technical skills', 'experience' => 'Relevant experience', 'fit' => 'Team fit'];

    public const COMPETENCIES = ['guest' => 'Guest focus', 'teamwork' => 'Teamwork', 'knowledge' => 'Job knowledge', 'reliability' => 'Reliability & punctuality',
        'grooming' => 'Grooming & hygiene', 'initiative' => 'Initiative'];

    /** Onboarding items: [label, due days after joining, auto-done field on the employee (or null)]. */
    public const ONBOARDING = [
        'contract' => ['Signed contract of employment (s. 12)', 0, null],
        'uniform' => ['Uniform, name badge & locker issued', 1, null],
        'induction' => ['Hotel induction & fire-safety briefing', 7, null],
        'bank' => ['Bank / mobile-money account for salary', 14, 'bank_account'],
        'ssnit' => ['SSNIT number recorded', 30, 'social_security_no'],
        'tin' => ['Ghana Card / TIN recorded', 30, 'tax_id'],
        'medical' => ['Food-handler medical certificate', 30, null],
        'buddy' => ['Departmental on-the-job training with a buddy', 30, null],
        'statement' => ['Written statement of particulars (s. 13)', 60, 'statement_issued_on'],
    ];

    public const CLEARANCE = ['handover' => 'Work handed over', 'uniform' => 'Uniform & locker returned', 'keys' => 'Keys & key cards returned', 'assets' => 'Tools, phone & equipment returned',
        'advance' => 'Salary advance settled', 'leave' => 'Leave balance settled', 'access' => 'System & building access removed', 'final_pay' => 'Final pay computed (by the notice end, s. 18)'];

    public const EXIT_REASONS = ['pay' => 'Pay & benefits', 'career' => 'Career growth', 'manager' => 'Relationship with manager', 'hours' => 'Hours / shifts', 'workload' => 'Workload',
        'relocation' => 'Relocation', 'studies' => 'Further studies', 'personal' => 'Personal / family', 'other' => 'Other'];

    public const BAND_CUTS = [1.8, 2.6, 3.4, 4.2];

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
        // s. 13 statements are tracked for staff who join from the day the module is set up – on an existing property the
        // current staff are not all flagged overnight; HR can move the date back to catch up on earlier starters
        Setting::firstOrCreate(['key' => 'talent_statement_from'], ['value' => bdate()->toDateString()]);
        Setting::$version++;
        self::flush();
    }

    public static function cfg(): array
    {
        return self::$memo['cfg'.Tenant::id()] ??= [
            'probation_months' => (int) setting('talent_probation_months', '6'),
            'probation_notice' => (int) setting('talent_probation_notice', '7'),
            'query_days' => (int) setting('talent_query_days', '3'),
            'offer_days' => (int) setting('talent_offer_days', '7'),
            'warn_verbal' => (int) setting('talent_warn_verbal', '6'),
            'warn_written' => (int) setting('talent_warn_written', '12'),
            'warn_final' => (int) setting('talent_warn_final', '12'),
            'retention_months' => (int) setting('talent_retention_months', '6'),
            'goal_weight' => (int) setting('talent_goal_weight', '60'),
            'careers' => setting('talent_careers', '1') === '1',
            'careers_intro' => (string) setting('talent_careers_intro', self::DEFAULTS['talent_careers_intro']),
            'statement_from' => (string) setting('talent_statement_from', '2000-01-01'),
        ];
    }

    public static function saveSettings(array $d): void
    {
        $ints = ['probation_months' => [0, 12, 'Probation is 0 to 12 months.'], 'probation_notice' => [0, 30, 'Notice during probation is 0 to 30 days.'],
            'query_days' => [1, 14, 'The query reply window is 1 to 14 days.'], 'offer_days' => [1, 30, 'An offer stays open 1 to 30 days.'],
            'warn_verbal' => [1, 24, 'A verbal warning stays on file 1 to 24 months.'], 'warn_written' => [1, 36, 'A written warning stays on file 1 to 36 months.'],
            'warn_final' => [1, 36, 'A final warning stays on file 1 to 36 months.'], 'retention_months' => [1, 60, 'Unsuccessful applicants are kept 1 to 60 months.'],
            'goal_weight' => [0, 100, 'The goals weight is 0 to 100 %.']];
        $out = [];
        foreach ($ints as $k => [$lo, $hi, $msg]) {
            $v = $d[$k] ?? null;
            self::need(is_numeric($v) && (int) $v == $v && $v >= $lo && $v <= $hi, $msg);
            $out['talent_'.$k] = (string) (int) $v;
        }
        self::need((int) $out['talent_warn_verbal'] <= (int) $out['talent_warn_written'] && (int) $out['talent_warn_written'] <= (int) $out['talent_warn_final'],
            'Warnings must last longer as they get more serious (verbal ≤ written ≤ final).');
        $out['talent_careers'] = ! empty($d['careers']) ? '1' : '0';
        $out['talent_careers_intro'] = self::text($d['careers_intro'] ?? '', 300) ?? '';
        if (filled($d['statement_from'] ?? null)) {
            $sf = self::date($d['statement_from'], 'date statements are tracked from');
            self::need($sf->toDateString() <= self::today(), 'The statement tracking date cannot be in the future.');
            $out['talent_statement_from'] = $sf->toDateString();
        }
        foreach ($out as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        Setting::$version++;
        self::flush();
        ActivityLogger::log('talent', 'settings', 'Talent management settings updated');
    }

    // ───────────────────────── helpers & permissions ─────────────────────────

    protected static function need(bool $ok, string $msg = 'You do not have permission to do that.'): void
    {
        if (! $ok) {
            throw new RuntimeException($msg);
        }
    }

    protected static function today(): string
    {
        return bdate()->toDateString();
    }

    protected static function text($v, int $max = 255): ?string
    {
        return filled($v) ? mb_substr(trim((string) $v), 0, $max) : null;
    }

    protected static function date($v, string $label, bool $required = true): ?Carbon
    {
        if (! filled($v)) {
            self::need(! $required, "Enter the {$label}.");

            return null;
        }
        try {
            return Carbon::parse((string) $v)->startOfDay();
        } catch (\Throwable) {
            throw new RuntimeException(ucfirst($label).' is not a valid date.');
        }
    }

    public static function can(?User $u, string $perm): bool
    {
        return (bool) $u?->hasPermission('talent.'.$perm);
    }

    public static function minWage(): float
    {
        return StatutoryService::minWageMonthly();
    }

    protected static function phoneKey(?string $p): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $p);
        if ($d === '') {
            return null;
        }
        if (str_starts_with($d, '233')) {
            $d = substr($d, 3);
        }

        return ltrim($d, '0');
    }

    public static function isFoodHandler(Employee $e): bool
    {
        $n = strtolower((string) $e->department?->name);

        return str_contains($n, 'kitchen') || str_contains($n, 'f&b') || str_contains($n, 'food');
    }

    // ───────────────────────── establishment & vacancies ─────────────────────────

    /** Per department: approved establishment, staff in post, open posts being recruited, and the gap. */
    public static function establishment(): Collection
    {
        $staff = Employee::where('is_active', true)->selectRaw('department_id, count(*) n')->groupBy('department_id')->pluck('n', 'department_id');
        $open = TalentVacancy::whereIn('status', ['pending', 'open', 'on_hold'])->get()->groupBy('department_id');

        return Department::orderBy('name')->get()->map(function ($d) use ($staff, $open) {
            $vac = $open->get($d->id, collect());
            $newPosts = $vac->where('reason', '!=', 'replacement')->sum(fn ($v) => $v->remaining());
            $inPost = (int) ($staff[$d->id] ?? 0);

            return ['department' => $d, 'establishment' => $d->establishment, 'staff' => $inPost, 'recruiting' => $vac->sum(fn ($v) => $v->remaining()),
                'new_posts' => $newPosts, 'gap' => $d->establishment === null ? null : $d->establishment - $inPost - $newPosts];
        });
    }

    public static function saveEstablishment(array $rows): void
    {
        foreach (Department::all() as $d) {
            $v = $rows[$d->id] ?? null;
            if ($v === null || $v === '') {
                $d->update(['establishment' => null]);

                continue;
            }
            self::need(is_numeric($v) && (int) $v == $v && $v >= 0 && $v <= 999, "The establishment for {$d->name} must be 0 to 999.");
            $d->update(['establishment' => (int) $v]);
        }
        ActivityLogger::log('talent', 'establishment', 'Departmental establishment updated');
    }

    public static function saveLineManagers(array $rows): void
    {
        $users = User::visibleToHotel()->where('is_active', true)->pluck('id')->all();
        foreach (Employee::where('is_active', true)->get() as $e) {
            $v = $rows[$e->id] ?? null;
            $v = filled($v) ? (int) $v : null;
            self::need($v === null || in_array($v, $users, true), "Choose an active user as {$e->name}'s line manager.");
            self::need($v === null || $v !== $e->user_id, "{$e->name} cannot be their own line manager.");
            $e->update(['manager_user_id' => $v]);
        }
        ActivityLogger::log('talent', 'managers', 'Line managers updated');
    }

    public static function saveVacancy(array $d, ?TalentVacancy $v, User $u): TalentVacancy
    {
        self::need(! $v || in_array($v->status, ['draft', 'pending'], true), 'Only draft or pending vacancies can be edited – put an open post on hold and raise a new one.');
        $dept = Department::find((int) ($d['department_id'] ?? 0));
        self::need((bool) $dept, 'Choose the department.');
        $title = self::text($d['title'] ?? null, 80);
        self::need((bool) $title, 'Enter the job title.');
        $hc = (int) ($d['headcount'] ?? 1);
        self::need($hc >= 1 && $hc <= 20, 'The headcount is 1 to 20.');
        $type = $d['employment_type'] ?? 'permanent';
        self::need(isset(TalentVacancy::TYPES[$type]), 'Choose the type of employment.');
        $months = filled($d['contract_months'] ?? null) ? (int) $d['contract_months'] : null;
        self::need($type !== 'fixed_term' || ($months >= 1 && $months <= 60), 'A fixed-term post needs a contract length of 1 to 60 months.');
        $min = round((float) ($d['salary_min'] ?? 0), 2);
        $max = round((float) ($d['salary_max'] ?? 0), 2);
        $mw = self::minWage();
        self::need($min >= $mw, 'The salary range cannot start below the national minimum wage ('.money($mw).' a month).');
        self::need($max >= $min, 'The top of the salary range is below the bottom.');
        $reason = $d['reason'] ?? 'new';
        self::need(isset(TalentVacancy::REASONS[$reason]), 'Choose why the post is needed.');
        $rep = null;
        if ($reason === 'replacement') {
            $rep = Employee::find((int) ($d['replaces_employee_id'] ?? 0));
            self::need((bool) $rep, 'Choose the employee being replaced.');
        }
        $closing = self::date($d['closing_on'] ?? null, 'closing date', false);
        self::need(! $closing || $closing->toDateString() >= self::today(), 'The closing date is in the past.');
        $data = ['department_id' => $dept->id, 'title' => $title, 'headcount' => $hc, 'employment_type' => $type, 'contract_months' => $type === 'fixed_term' ? $months : null,
            'salary_min' => $min, 'salary_max' => $max, 'reason' => $reason, 'replaces_employee_id' => $rep?->id, 'justification' => self::text($d['justification'] ?? null, 2000),
            'description' => self::text($d['description'] ?? null, 4000), 'requirements' => self::text($d['requirements'] ?? null, 4000), 'closing_on' => $closing?->toDateString(),
            'is_public' => ! empty($d['is_public'])];
        if ($v) {
            $v->update($data);
            ActivityLogger::log('talent', 'vacancy_edit', "Vacancy {$v->number} {$title} edited", $v);

            return $v;
        }
        $v = TalentVacancy::create($data + ['number' => Numbering::next('talent_vacancy'), 'status' => 'draft', 'requested_by' => $u->id]);
        ActivityLogger::log('talent', 'vacancy', "Vacancy {$v->number} {$title} ({$dept->name}) drafted", $v);

        return $v;
    }

    public static function submitVacancy(TalentVacancy $v, User $u): void
    {
        self::need($v->status === 'draft', 'Only a draft can be sent for approval.');
        self::need(filled($v->justification), 'Explain why the post is needed before sending it for approval.');
        $v->update(['status' => 'pending', 'requested_by' => $u->id, 'approval_note' => null]);
        ActivityLogger::log('talent', 'vacancy_submit', "Vacancy {$v->number} sent for approval", $v);
    }

    /** Headcount after this vacancy is filled, against the establishment (null when no establishment is set). */
    public static function establishmentCheck(TalentVacancy $v): ?array
    {
        $row = self::establishment()->first(fn ($r) => $r['department']->id === $v->department_id);
        if (! $row || $row['establishment'] === null) {
            return null;
        }
        $after = $row['staff'] + $row['new_posts'] + ($v->status === 'draft' && $v->reason !== 'replacement' ? $v->remaining() : 0);

        return ['establishment' => $row['establishment'], 'after' => $after, 'over' => max(0, $after - $row['establishment'])];
    }

    public static function approveVacancy(TalentVacancy $v, User $u, ?string $note = null): void
    {
        self::need(self::can($u, 'approve'), 'Only a vacancy approver can approve posts.');
        self::need($v->status === 'pending', 'This vacancy is not awaiting approval.');
        self::need($v->requested_by !== $u->id, 'You requested this post – another approver must approve it.');
        $note = self::text($note, 255);
        $chk = self::establishmentCheck($v);
        self::need(! $chk || $chk['over'] === 0 || $v->reason === 'replacement' || filled($note),
            "This takes {$v->department->name} to {$chk['after']} against an establishment of {$chk['establishment']} – record why the extra post is approved.");
        $v->update(['status' => 'open', 'approved_by' => $u->id, 'approved_at' => now(), 'approval_note' => $note]);
        ActivityLogger::log('talent', 'vacancy_approve', "Vacancy {$v->number} {$v->title} approved and open", $v);
    }

    public static function returnVacancy(TalentVacancy $v, User $u, ?string $note): void
    {
        self::need(self::can($u, 'approve'), 'Only a vacancy approver can return posts.');
        self::need($v->status === 'pending', 'This vacancy is not awaiting approval.');
        $note = self::text($note, 255);
        self::need((bool) $note, 'Say why the request is being returned.');
        $v->update(['status' => 'draft', 'approval_note' => 'Returned: '.$note]);
        ActivityLogger::log('talent', 'vacancy_return', "Vacancy {$v->number} returned: {$note}", $v);
    }

    public static function setVacancyStatus(TalentVacancy $v, string $to, User $u, ?string $reason = null): void
    {
        $reason = self::text($reason, 255);
        $ok = ['on_hold' => ['open'], 'open' => ['on_hold'], 'cancelled' => ['draft', 'pending', 'open', 'on_hold'], 'filled' => ['open', 'on_hold']];
        self::need(isset($ok[$to]) && in_array($v->status, $ok[$to], true), 'That change is not possible for a '.strtolower($v->statusLabel()).' vacancy.');
        if ($to === 'cancelled' || $to === 'filled') {
            self::need($to === 'filled' || (bool) $reason, 'Say why the vacancy is cancelled.');
            self::need(! TalentOffer::where('vacancy_id', $v->id)->whereIn('status', ['pending', 'issued'])->exists(), 'Withdraw the outstanding offer first.');
        }
        DB::transaction(function () use ($v, $to, $reason) {
            $v->update(['status' => $to, 'closed_reason' => $to === 'cancelled' ? $reason : $v->closed_reason, 'filled_on' => $to === 'filled' ? self::today() : $v->filled_on]);
            if (in_array($to, ['cancelled', 'filled'], true)) {
                self::closeOpenApplicants($v, $to === 'filled' ? 'Position filled' : 'Vacancy cancelled');
            }
        });
        ActivityLogger::log('talent', 'vacancy_'.$to, "Vacancy {$v->number} {$v->title}: ".TalentVacancy::STATUSES[$to].($reason ? " – {$reason}" : ''), $v);
    }

    protected static function closeOpenApplicants(TalentVacancy $v, string $reason): void
    {
        TalentApplicant::where('vacancy_id', $v->id)->whereIn('stage', ['applied', 'screening', 'interview'])
            ->update(['stage' => 'rejected', 'reject_reason' => $reason, 'stage_changed_at' => now()]);
    }

    /** Vacancies that the public careers page lists. */
    public static function publicVacancies(): Collection
    {
        return TalentVacancy::with('department')->where('status', 'open')->where('is_public', true)
            ->where(fn ($q) => $q->whereNull('closing_on')->orWhereDate('closing_on', '>=', self::today()))->orderBy('title')->get();
    }

    // ───────────────────────── applicants ─────────────────────────

    public static function addApplicant(TalentVacancy $v, array $d, ?UploadedFile $cv, ?User $by, bool $public = false): TalentApplicant
    {
        self::need($v->status === 'open', 'Applications are only taken for open vacancies.');
        if ($public) {
            self::need($v->is_public && (! $v->closing_on || $v->closing_on->toDateString() >= self::today()), 'This vacancy is closed.');
            self::need(! empty($d['consent']), 'Please agree to the hotel keeping your details for this application.');
        }
        $name = self::text($d['name'] ?? null, 120);
        self::need((bool) $name, 'Enter the applicant\'s name.');
        $email = self::text(strtolower((string) ($d['email'] ?? '')), 150);
        $phone = self::text($d['phone'] ?? null, 30);
        self::need($email || $phone, 'Enter a phone number or an e-mail address.');
        self::need(! $email || filter_var($email, FILTER_VALIDATE_EMAIL), 'The e-mail address is not valid.');
        self::need(! $phone || strlen((string) self::phoneKey($phone)) >= 7, 'The phone number is not valid.');
        $pk = self::phoneKey($phone);
        $dup = TalentApplicant::where('vacancy_id', $v->id)->whereNull('anonymised_at')->get()
            ->first(fn ($a) => ($email && strtolower((string) $a->email) === $email) || ($pk && self::phoneKey($a->phone) === $pk));
        self::need(! $dup, $public ? 'We already have your application for this job.' : "{$dup?->name} ({$dup?->number}) has already applied for this vacancy.");
        $source = $public ? 'careers' : ($d['source'] ?? 'walk_in');
        self::need(isset(TalentApplicant::SOURCES[$source]), 'Choose where the applicant came from.');
        $ref = null;
        if ($source === 'referral') {
            $ref = Employee::where('is_active', true)->find((int) ($d['referred_by_employee_id'] ?? 0));
            self::need((bool) $ref, 'Choose the member of staff who referred the applicant.');
        }
        $exp = filled($d['experience_years'] ?? null) ? round((float) $d['experience_years'], 1) : null;
        self::need($exp === null || ($exp >= 0 && $exp <= 60), 'Experience is 0 to 60 years.');
        $sal = filled($d['expected_salary'] ?? null) ? round((float) $d['expected_salary'], 2) : null;
        self::need($sal === null || ($sal >= 0 && $sal <= 1000000), 'The expected salary is not valid.');
        $file = [];
        if ($cv) {
            $path = $cv->storeAs('talent/cv', Str::random(32).'.'.($cv->guessExtension() ?: 'bin'), 'local');
            $file = ['cv_path' => $path, 'cv_name' => mb_substr($cv->getClientOriginalName(), 0, 150), 'cv_mime' => $cv->getMimeType()];
        }
        $a = TalentApplicant::create(['number' => Numbering::next('talent_applicant'), 'vacancy_id' => $v->id, 'name' => $name, 'email' => $email, 'phone' => $phone,
            'source' => $source, 'referred_by_employee_id' => $ref?->id, 'current_employer' => self::text($d['current_employer'] ?? null, 120), 'experience_years' => $exp,
            'expected_salary' => $sal, 'cover_note' => self::text($d['cover_note'] ?? null, 3000), 'stage' => 'applied', 'stage_changed_at' => now(),
            'consent_at' => $public || ! empty($d['consent']) ? now() : null, 'created_by' => $by?->id] + $file);
        ActivityLogger::log('talent', 'applicant', "{$a->number} {$name} applied for {$v->title}".($public ? ' (careers page)' : ''), $a);

        return $a;
    }

    public static function moveApplicant(TalentApplicant $a, string $to, User $u, ?string $reason = null, ?int $rating = null): void
    {
        $flow = ['screening' => ['applied'], 'interview' => ['screening'], 'rejected' => ['applied', 'screening', 'interview'], 'withdrawn' => ['applied', 'screening', 'interview'],
            'applied' => ['screening']];
        self::need(isset($flow[$to]) && in_array($a->stage, $flow[$to], true), "{$a->name} cannot move from ".strtolower($a->stageLabel()).' to '.strtolower(TalentApplicant::STAGES[$to] ?? $to).'.');
        $reason = self::text($reason, 255);
        self::need(! in_array($to, ['rejected', 'withdrawn'], true) || (bool) $reason, 'Record the reason – unsuccessful applicants are told why, and the hiring record must show it (s. 14).');
        self::need($rating === null || ($rating >= 1 && $rating <= 5), 'The screening rating is 1 to 5.');
        $a->update(['stage' => $to, 'reject_reason' => in_array($to, ['rejected', 'withdrawn'], true) ? $reason : null, 'stage_changed_at' => now(),
            'screen_rating' => $rating ?? $a->screen_rating]);
        ActivityLogger::log('talent', 'applicant_'.$to, "{$a->number} {$a->name} → ".TalentApplicant::STAGES[$to].($reason ? ": {$reason}" : ''), $a);
    }

    // ───────────────────────── interviews ─────────────────────────

    public static function scheduleInterview(TalentApplicant $a, array $d, User $u): TalentInterview
    {
        self::need(in_array($a->stage, ['screening', 'interview'], true), 'Screen the applicant before scheduling an interview.');
        $kind = $d['kind'] ?? 'in_person';
        self::need(isset(TalentInterview::KINDS[$kind]), 'Choose the type of interview.');
        try {
            $at = Carbon::parse((string) ($d['scheduled_at'] ?? ''));
        } catch (\Throwable) {
            $at = null;
        }
        self::need(filled($d['scheduled_at'] ?? null) && $at, 'Enter the interview date and time.');
        $panel = collect((array) ($d['panel'] ?? []))->map(fn ($x) => (int) $x)->filter()->unique()->values();
        self::need($panel->isNotEmpty(), 'Choose at least one panel member.');
        self::need(User::visibleToHotel()->where('is_active', true)->whereIn('id', $panel)->count() === $panel->count(), 'Every panel member must be an active user.');
        $i = TalentInterview::create(['applicant_id' => $a->id, 'round' => $a->interviews()->count() + 1, 'kind' => $kind, 'scheduled_at' => $at,
            'location' => self::text($d['location'] ?? null, 120), 'panel' => $panel->all(), 'status' => 'scheduled', 'created_by' => $u->id]);
        if ($a->stage === 'screening') {
            $a->update(['stage' => 'interview', 'stage_changed_at' => now()]);
        }
        ActivityLogger::log('talent', 'interview', "{$a->number} {$a->name}: round {$i->round} ".strtolower($i->kindLabel()).' on '.$at->format('d M H:i'), $a);

        return $i;
    }

    public static function setInterviewStatus(TalentInterview $i, string $to, User $u): void
    {
        self::need(in_array($to, ['done', 'no_show', 'cancelled'], true) && $i->status === 'scheduled', 'Only a scheduled interview can be updated.');
        self::need($to === 'cancelled' || ! $i->scheduled_at->isFuture(), 'The interview has not happened yet.');
        $i->update(['status' => $to]);
        ActivityLogger::log('talent', 'interview_'.$to, "{$i->applicant->number} round {$i->round}: ".TalentInterview::STATUSES[$to], $i->applicant);
    }

    public static function score(TalentInterview $i, array $d, User $u): TalentScorecard
    {
        self::need(in_array($u->id, array_map('intval', $i->panel ?? []), true), 'Only members of the interview panel can score it.');
        self::need(in_array($i->status, ['scheduled', 'done'], true), 'This interview was not held.');
        self::need(! $i->scheduled_at->isFuture(), 'Scorecards open when the interview starts ('.$i->scheduled_at->format('d M H:i').').');
        self::need(in_array($i->applicant->stage, ['interview', 'offer'], true), 'The applicant is no longer being considered.');
        $scores = [];
        foreach (self::CRITERIA as $k => $label) {
            $v = $d['scores'][$k] ?? null;
            self::need(is_numeric($v) && (int) $v == $v && $v >= 1 && $v <= 5, "Score {$label} from 1 to 5.");
            $scores[$k] = (int) $v;
        }
        $rec = $d['recommendation'] ?? null;
        self::need(isset(TalentScorecard::RECOMMEND[$rec]), 'Give your recommendation.');
        $avg = round(array_sum($scores) / count($scores), 2);
        $s = TalentScorecard::updateOrCreate(['interview_id' => $i->id, 'user_id' => $u->id],
            ['scores' => $scores, 'average' => $avg, 'recommendation' => $rec, 'comments' => self::text($d['comments'] ?? null, 2000)]);
        if ($i->status === 'scheduled') {
            $i->update(['status' => 'done']);
        }
        ActivityLogger::log('talent', 'scorecard', "{$i->applicant->number} round {$i->round} scored {$avg} ({$rec}) by {$u->name}", $i->applicant);

        return $s;
    }

    // ───────────────────────── offers & hiring ─────────────────────────

    public static function makeOffer(TalentApplicant $a, array $d, User $u): TalentOffer
    {
        $v = $a->vacancy;
        self::need($a->stage === 'interview', 'Only an interviewed applicant can be offered the job.');
        self::need(TalentScorecard::whereIn('interview_id', $a->interviews()->pluck('id'))->exists(), 'At least one interview scorecard is needed before an offer.');
        self::need(! $a->offers()->whereIn('status', ['pending', 'issued'])->exists(), 'This applicant already has an offer outstanding.');
        self::need($v->status === 'open' && $v->remaining() > 0, 'The vacancy is not open.');
        $salary = round((float) ($d['salary'] ?? 0), 2);
        $mw = self::minWage();
        self::need($salary >= $mw, 'The salary cannot be below the national minimum wage ('.money($mw).' a month).');
        $start = self::date($d['start_date'] ?? null, 'start date');
        self::need($start->toDateString() >= self::today(), 'The start date is in the past.');
        $cfg = self::cfg();
        $prob = (int) ($d['probation_months'] ?? $cfg['probation_months']);
        self::need($prob >= 0 && $prob <= $cfg['probation_months'], "Probation is 0 to {$cfg['probation_months']} months.");
        $type = $d['employment_type'] ?? $v->employment_type;
        self::need(isset(TalentVacancy::TYPES[$type]), 'Choose the type of employment.');
        $months = $type === 'fixed_term' ? (int) ($d['contract_months'] ?? $v->contract_months) : null;
        self::need($type !== 'fixed_term' || ($months >= 1 && $months <= 60), 'A fixed-term offer needs a contract length of 1 to 60 months.');
        $above = $salary > (float) $v->salary_max;

        return DB::transaction(function () use ($a, $v, $u, $d, $salary, $start, $prob, $type, $months, $above, $cfg) {
            $o = TalentOffer::create(['number' => Numbering::next('talent_offer'), 'applicant_id' => $a->id, 'vacancy_id' => $v->id, 'salary' => $salary,
                'start_date' => $start->toDateString(), 'probation_months' => $prob, 'employment_type' => $type, 'contract_months' => $months,
                'status' => $above ? 'pending' : 'issued', 'above_range' => $above, 'respond_by' => $above ? null : bdate()->addDays($cfg['offer_days'])->toDateString(),
                'notes' => self::text($d['notes'] ?? null, 1000), 'created_by' => $u->id]);
            $a->update(['stage' => 'offer', 'stage_changed_at' => now()]);
            ActivityLogger::log('talent', 'offer', "{$o->number} to {$a->name}: ".money($salary).($above ? ' – above the range, awaiting approval' : ' issued'), $a);

            return $o;
        });
    }

    public static function approveOffer(TalentOffer $o, User $u): void
    {
        self::need(self::can($u, 'approve'), 'Only an approver can approve an above-range offer.');
        self::need($o->status === 'pending', 'This offer is not awaiting approval.');
        self::need($o->created_by !== $u->id, 'You made this offer – another approver must approve it.');
        $o->update(['status' => 'issued', 'approved_by' => $u->id, 'approved_at' => now(), 'respond_by' => bdate()->addDays(self::cfg()['offer_days'])->toDateString()]);
        ActivityLogger::log('talent', 'offer_approve', "{$o->number} approved above the range (".money($o->salary).')', $o->applicant);
    }

    public static function withdrawOffer(TalentOffer $o, User $u, ?string $reason): void
    {
        self::need(in_array($o->status, ['pending', 'issued'], true), 'Only an outstanding offer can be withdrawn.');
        $reason = self::text($reason, 255);
        self::need((bool) $reason, 'Say why the offer is withdrawn.');
        DB::transaction(function () use ($o, $reason) {
            $o->update(['status' => 'withdrawn', 'decline_reason' => $reason, 'responded_at' => now()]);
            $o->applicant->update(['stage' => 'interview', 'stage_changed_at' => now()]);
        });
        ActivityLogger::log('talent', 'offer_withdraw', "{$o->number} withdrawn: {$reason}", $o->applicant);
    }

    public static function declineOffer(TalentOffer $o, User $u, ?string $reason): void
    {
        self::need($o->status === 'issued', 'Only an issued offer can be declined.');
        $reason = self::text($reason, 255);
        self::need((bool) $reason, 'Record why the applicant declined.');
        DB::transaction(function () use ($o, $reason) {
            $o->update(['status' => 'declined', 'decline_reason' => $reason, 'responded_at' => now()]);
            $o->applicant->update(['stage' => 'interview', 'stage_changed_at' => now()]);
        });
        ActivityLogger::log('talent', 'offer_decline', "{$o->number} declined by {$o->applicant->name}: {$reason}", $o->applicant);
    }

    /** The applicant accepted: create the employee, the onboarding checklist, and fill the vacancy. */
    public static function acceptOffer(TalentOffer $o, User $u): Employee
    {
        self::need($o->status === 'issued', 'Only an issued offer can be accepted.');
        $a = $o->applicant;
        $v = $o->vacancy;
        self::need($v->remaining() > 0, 'The vacancy is already filled.');

        return DB::transaction(function () use ($o, $a, $v, $u) {
            $start = $o->start_date->copy();
            $n = (int) Employee::withoutGlobalScopes()->where('hotel_id', Tenant::id())->where('emp_code', 'like', 'EMP%')->get(['emp_code'])
                ->map(fn ($e) => (int) preg_replace('/\D+/', '', $e->emp_code))->max();
            $manager = $v->replaces?->manager_user_id ?? Employee::where('department_id', $v->department_id)->where('is_active', true)->whereNotNull('manager_user_id')
                ->groupBy('manager_user_id')->selectRaw('manager_user_id, count(*) c')->orderByDesc('c')->value('manager_user_id');
            $e = Employee::create(['department_id' => $v->department_id, 'emp_code' => 'EMP'.str_pad((string) ($n + 1), 3, '0', STR_PAD_LEFT), 'name' => $a->name,
                'designation' => $v->title, 'employment_type' => $o->employment_type, 'phone' => $a->phone, 'email' => $a->email, 'joining_date' => $start->toDateString(),
                'salary' => $o->salary, 'probation_end' => $o->probation_months > 0 ? $start->copy()->addMonthsNoOverflow($o->probation_months)->subDay()->toDateString() : null,
                'confirmed_on' => $o->probation_months > 0 ? null : $start->toDateString(),
                'contract_end' => $o->employment_type === 'fixed_term' ? $start->copy()->addMonthsNoOverflow((int) $o->contract_months)->subDay()->toDateString() : null,
                'manager_user_id' => $manager, 'is_active' => true]);
            self::createOnboarding($e);
            $o->update(['status' => 'accepted', 'responded_at' => now()]);
            $a->update(['stage' => 'hired', 'hired_employee_id' => $e->id, 'stage_changed_at' => now()]);
            $v->increment('filled');
            if ($v->fresh()->remaining() === 0) {
                $v->update(['status' => 'filled', 'filled_on' => self::today()]);
                self::closeOpenApplicants($v, 'Position filled');
            }
            ActivityLogger::log('talent', 'hire', "{$a->name} hired as {$v->title} ({$e->emp_code}) from {$start->format('d M Y')}", $e);

            return $e;
        });
    }

    // ───────────────────────── onboarding, statement, probation ─────────────────────────

    public static function createOnboarding(Employee $e): void
    {
        $join = ($e->joining_date ?? bdate())->copy();
        foreach (self::ONBOARDING as $k => [$label, $days]) {
            if ($k === 'medical' && ! self::isFoodHandler($e)) {
                continue;
            }
            $due = $k === 'statement' ? $join->copy()->addMonthsNoOverflow(2) : $join->copy()->addDays($days);   // s. 13: two calendar months
            TalentTask::firstOrCreate(['employee_id' => $e->id, 'item' => $k], ['due_on' => $due->toDateString()]);
        }
    }

    /** Checklist rows: item, label, due, done (manually or because the employee record already has the data), overdue. */
    public static function onboarding(Employee $e): Collection
    {
        $today = self::today();

        return $e->onboardingTasks()->with('doer')->get()->sortBy(fn ($t) => array_search($t->item, array_keys(self::ONBOARDING)))->values()->map(function ($t) use ($e, $today) {
            $field = self::ONBOARDING[$t->item][2] ?? null;
            $auto = $field && filled($e->{$field});
            $done = $t->done_at !== null || $auto;

            return ['task' => $t, 'label' => $t->label(), 'auto' => $auto, 'field' => $field, 'done' => $done, 'overdue' => ! $done && $t->due_on->toDateString() < $today];
        });
    }

    public static function completeTask(TalentTask $t, User $u, bool $done, ?string $note = null): void
    {
        self::need($t->item !== 'statement', 'Issue the written statement from the statement screen.');
        $t->update(['done_at' => $done ? now() : null, 'done_by' => $done ? $u->id : null, 'note' => self::text($note, 190)]);
        ActivityLogger::log('talent', 'onboarding', "{$t->employee->name}: ".$t->label().($done ? ' done' : ' re-opened'), $t->employee);
    }

    public static function statementDue(Employee $e): ?Carbon
    {
        return $e->joining_date?->copy()->addMonthsNoOverflow(2);
    }

    public static function issueStatement(Employee $e, User $u, $on = null): void
    {
        $d = self::date($on ?: self::today(), 'date issued');
        self::need($d->toDateString() <= self::today(), 'The statement cannot be dated in the future.');
        self::need(! $e->joining_date || $d->gte($e->joining_date), 'The statement cannot pre-date the start of employment.');
        $e->update(['statement_issued_on' => $d->toDateString()]);
        TalentTask::where('employee_id', $e->id)->where('item', 'statement')->update(['done_at' => now(), 'done_by' => $u->id]);
        ActivityLogger::log('talent', 'statement', "Written statement of particulars issued to {$e->name} on {$d->format('d M Y')}", $e);
    }

    /** Employees whose s. 13 statement is outstanding after two months. */
    public static function statementsOverdue(): Collection
    {
        $today = self::today();

        return Employee::with('department')->where('is_active', true)->whereNull('statement_issued_on')->whereNotNull('joining_date')
            ->whereDate('joining_date', '>=', self::cfg()['statement_from'])->get()
            ->filter(fn ($e) => self::statementDue($e)->toDateString() < $today)->values();
    }

    public static function onProbation(): Collection
    {
        return Employee::with('department')->where('is_active', true)->whereNotNull('probation_end')->whereNull('confirmed_on')->orderBy('probation_end')->get();
    }

    public static function reviewProbation(Employee $e, array $d, User $u): TalentProbation
    {
        self::need(self::can($u, 'appraise'), 'Only HR can record a probation review.');
        self::need($e->is_active && $e->probation_end && ! $e->confirmed_on, "{$e->name} is not on probation.");
        self::need($e->user_id !== $u->id, 'You cannot review your own probation.');
        $out = $d['outcome'] ?? null;
        self::need(isset(TalentProbation::OUTCOMES[$out]), 'Choose the outcome.');
        $rating = filled($d['rating'] ?? null) ? (int) $d['rating'] : null;
        self::need($rating === null || ($rating >= 1 && $rating <= 5), 'The rating is 1 to 5.');
        $comments = self::text($d['comments'] ?? null, 2000);
        self::need($out === 'confirm' || (bool) $comments, 'Record the reasons – an extension or termination must be explained to the employee.');
        $ext = null;
        if ($out === 'extend') {
            self::need(! TalentProbation::where('employee_id', $e->id)->where('outcome', 'extend')->exists(), 'Probation has already been extended once – confirm or end the employment.');
            $ext = (int) ($d['extend_months'] ?? 0);
            self::need($ext >= 1 && $ext <= 6, 'Extend probation by 1 to 6 months.');
        }

        return DB::transaction(function () use ($e, $u, $out, $rating, $comments, $ext) {
            $p = TalentProbation::create(['employee_id' => $e->id, 'due_on' => $e->probation_end->toDateString(), 'reviewed_on' => self::today(), 'outcome' => $out,
                'extend_months' => $ext, 'rating' => $rating, 'comments' => $comments, 'reviewed_by' => $u->id]);
            if ($out === 'confirm') {
                $e->update(['confirmed_on' => self::today()]);
            } elseif ($out === 'extend') {
                $e->update(['probation_end' => $e->probation_end->copy()->addMonthsNoOverflow($ext)->toDateString()]);
            } else {
                self::startExit($e, ['type' => 'probation', 'notice_on' => self::today(), 'reason' => $comments, 'fair_confirmed' => 1], $u);
            }
            ActivityLogger::log('talent', 'probation_'.$out, "{$e->name}: ".TalentProbation::OUTCOMES[$out], $e);

            return $p;
        });
    }

    // ───────────────────────── appraisals ─────────────────────────

    public static function createCycle(array $d, User $u): TalentCycle
    {
        self::need(self::can($u, 'appraise'), 'Only HR can open an appraisal cycle.');
        $name = self::text($d['name'] ?? null, 80);
        self::need((bool) $name, 'Name the cycle.');
        $from = self::date($d['period_from'] ?? null, 'period start');
        $to = self::date($d['period_to'] ?? null, 'period end');
        self::need($to->gt($from), 'The period ends before it starts.');
        $self = self::date($d['self_due'] ?? null, 'self-assessment deadline');
        $rev = self::date($d['review_due'] ?? null, 'review deadline');
        self::need($rev->gte($self), 'The manager review is due after the self-assessment.');
        $goals = collect(preg_split('/\r\n|\r|\n/', (string) ($d['goals'] ?? '')))->map(fn ($g) => self::text($g, 150))->filter()->values();
        self::need($goals->count() <= 8, 'At most eight common goals.');

        return DB::transaction(function () use ($name, $from, $to, $self, $rev, $goals, $u) {
            $c = TalentCycle::create(['name' => $name, 'period_from' => $from, 'period_to' => $to, 'self_due' => $self, 'review_due' => $rev, 'status' => 'active', 'created_by' => $u->id]);
            $weights = self::splitWeights($goals->count());
            $g = $goals->map(fn ($t, $i) => ['title' => $t, 'weight' => $weights[$i], 'self' => null, 'mgr' => null])->all();
            $comp = collect(self::COMPETENCIES)->map(fn () => ['self' => null, 'mgr' => null])->all();
            $n = 0;
            foreach (Employee::where('is_active', true)->where(fn ($q) => $q->whereNull('joining_date')->orWhereDate('joining_date', '<=', $to->toDateString()))->get() as $e) {
                TalentAppraisal::create(['cycle_id' => $c->id, 'employee_id' => $e->id, 'reviewer_id' => $e->manager_user_id, 'goals' => $g, 'competencies' => $comp,
                    'status' => $e->user_id ? 'self' : 'review']);
                $n++;
            }
            ActivityLogger::log('talent', 'cycle', "Appraisal cycle {$name} opened for {$n} employees", $c);

            return $c;
        });
    }

    protected static function splitWeights(int $n): array
    {
        if ($n === 0) {
            return [];
        }
        $w = array_fill(0, $n, intdiv(100, $n));
        $w[0] += 100 - array_sum($w);

        return $w;
    }

    public static function closeCycle(TalentCycle $c, User $u): void
    {
        self::need(self::can($u, 'appraise'), 'Only HR can close a cycle.');
        self::need($c->status === 'active', 'The cycle is already closed.');
        $open = $c->appraisals()->where('status', '!=', 'signed')->count();
        self::need($open === 0, "{$open} appraisal(s) are not signed off yet.");
        $c->update(['status' => 'closed', 'closed_at' => now()]);
        ActivityLogger::log('talent', 'cycle_close', "Appraisal cycle {$c->name} closed", $c);
    }

    public static function assignReviewer(TalentAppraisal $a, ?int $userId, User $u): void
    {
        self::need(self::can($u, 'appraise'), 'Only HR can change the reviewer.');
        self::need($a->status !== 'signed', 'The appraisal is signed off.');
        $r = User::visibleToHotel()->where('is_active', true)->find((int) $userId);
        self::need((bool) $r, 'Choose an active user as reviewer.');
        self::need($r->id !== $a->employee->user_id, 'An employee cannot review their own appraisal.');
        $a->update(['reviewer_id' => $r->id]);
        ActivityLogger::log('talent', 'reviewer', "{$a->employee->name}'s appraisal reviewer: {$r->name}", $a->employee);
    }

    public static function isOwn(TalentAppraisal $a, ?User $u): bool
    {
        return $u && $a->employee->user_id === $u->id;
    }

    public static function canReview(TalentAppraisal $a, ?User $u): bool
    {
        return $u && ! self::isOwn($a, $u) && ($a->reviewer_id === $u->id || self::can($u, 'appraise'));
    }

    protected static function rating($v, string $label, bool $required): ?int
    {
        if (! filled($v)) {
            self::need(! $required, "Rate {$label} from 1 to 5.");

            return null;
        }
        self::need(is_numeric($v) && (int) $v == $v && $v >= 1 && $v <= 5, "Rate {$label} from 1 to 5.");

        return (int) $v;
    }

    public static function saveSelf(TalentAppraisal $a, array $d, User $u, bool $submit): void
    {
        self::need(self::isOwn($a, $u), 'This is not your appraisal.');
        self::need($a->status === 'self' && $a->cycle->status === 'active', 'Your self-assessment has already been submitted.');
        $goals = $a->goals ?? [];
        foreach ($goals as $i => $g) {
            $goals[$i]['self'] = self::rating($d['goal_self'][$i] ?? null, '"'.$g['title'].'"', $submit);
        }
        $comp = $a->competencies ?? [];
        foreach (self::COMPETENCIES as $k => $label) {
            $comp[$k]['self'] = self::rating($d['comp_self'][$k] ?? null, $label, $submit);
        }
        $a->update(['goals' => $goals, 'competencies' => $comp, 'self_comments' => self::text($d['self_comments'] ?? null, 3000)]
            + ($submit ? ['status' => 'review', 'self_at' => now()] : []));
        ActivityLogger::log('talent', $submit ? 'self_submit' : 'self_save', "{$a->employee->name}: self-assessment ".($submit ? 'submitted' : 'saved'), $a->employee);
    }

    public static function saveReview(TalentAppraisal $a, array $d, User $u, bool $submit): void
    {
        self::need(self::canReview($a, $u), 'Only the appointed reviewer (or HR) can review this appraisal.');
        self::need($a->cycle->status === 'active', 'The cycle is closed.');
        $selfLate = $a->status === 'self' && self::today() > $a->cycle->self_due->toDateString();
        self::need($a->status === 'review' || $selfLate, $a->status === 'self' ? 'Wait for the self-assessment (due '.$a->cycle->self_due->format('d M').').' : 'This appraisal is past the review stage.');
        $titles = (array) ($d['goal_title'] ?? []);
        $goals = [];
        foreach ($titles as $i => $t) {
            $t = self::text($t, 150);
            if (! $t) {
                continue;
            }
            $w = (int) ($d['goal_weight'][$i] ?? 0);
            self::need($w >= 1 && $w <= 100, "Give \"{$t}\" a weight of 1 to 100 %.");
            $goals[] = ['title' => $t, 'weight' => $w, 'self' => $a->goals[$i]['self'] ?? null, 'mgr' => self::rating($d['goal_mgr'][$i] ?? null, '"'.$t.'"', $submit)];
        }
        self::need(count($goals) >= 1 && count($goals) <= 8, 'Set one to eight goals.');
        self::need(array_sum(array_column($goals, 'weight')) === 100, 'Goal weights must add up to 100 % (now '.array_sum(array_column($goals, 'weight')).' %).');
        $comp = $a->competencies ?? [];
        foreach (self::COMPETENCIES as $k => $label) {
            $comp[$k] = ['self' => $comp[$k]['self'] ?? null, 'mgr' => self::rating($d['comp_mgr'][$k] ?? null, $label, $submit)];
        }
        $data = ['goals' => $goals, 'competencies' => $comp, 'mgr_comments' => self::text($d['mgr_comments'] ?? null, 3000), 'development' => self::text($d['development'] ?? null, 3000)];
        if ($submit) {
            self::need(filled($data['mgr_comments']), 'Write your overall comments before submitting.');
            $data += self::scores($goals, $comp) + ['status' => 'hr', 'reviewed_at' => now(), 'reviewer_id' => $a->reviewer_id ?? $u->id];
        }
        $a->update($data);
        ActivityLogger::log('talent', $submit ? 'review_submit' : 'review_save', "{$a->employee->name}: manager review ".($submit ? 'submitted – overall '.$data['overall'] : 'saved'), $a->employee);
    }

    public static function scores(array $goals, array $comp): array
    {
        $w = array_sum(array_column($goals, 'weight'));
        $gs = $w > 0 ? array_sum(array_map(fn ($g) => (float) $g['mgr'] * $g['weight'], $goals)) / $w : 0;
        $cm = array_filter(array_map(fn ($c) => $c['mgr'] ?? null, $comp), fn ($x) => $x !== null);
        $cs = $cm ? array_sum($cm) / count($cm) : 0;
        $gw = self::cfg()['goal_weight'];
        $overall = round($gs * $gw / 100 + $cs * (100 - $gw) / 100, 2);

        return ['goals_score' => round($gs, 2), 'comp_score' => round($cs, 2), 'overall' => $overall, 'band' => self::band($overall), 'pip' => $overall < self::BAND_CUTS[1]];
    }

    public static function band(float $overall): int
    {
        $b = 1;
        foreach (self::BAND_CUTS as $cut) {
            if ($overall >= $cut) {
                $b++;
            }
        }

        return $b;
    }

    public static function signOff(TalentAppraisal $a, User $u, bool $approve, ?string $note = null): void
    {
        self::need(self::can($u, 'appraise'), 'Only HR can sign off appraisals.');
        self::need($a->status === 'hr', 'This appraisal is not awaiting sign-off.');
        self::need($a->reviewer_id !== $u->id, 'You reviewed this appraisal – another HR user must sign it off.');
        self::need(! self::isOwn($a, $u), 'You cannot sign off your own appraisal.');
        if ($approve) {
            $a->update(['status' => 'signed', 'signed_by' => $u->id, 'signed_at' => now()]);
        } else {
            $note = self::text($note, 255);
            self::need((bool) $note, 'Say what the reviewer needs to change.');
            $a->update(['status' => 'review', 'mgr_comments' => trim(($a->mgr_comments ?? '')."\n[HR returned: {$note}]")]);
        }
        ActivityLogger::log('talent', $approve ? 'signoff' : 'signoff_return', "{$a->employee->name}: appraisal ".($approve ? 'signed off' : 'returned to the reviewer'), $a->employee);
    }

    public static function acknowledge(TalentAppraisal $a, User $u, ?string $comment): void
    {
        self::need(self::isOwn($a, $u), 'This is not your appraisal.');
        self::need($a->status === 'signed' && ! $a->acknowledged_at, 'There is nothing to acknowledge.');
        $a->update(['acknowledged_at' => now(), 'employee_comment' => self::text($comment, 255)]);
        ActivityLogger::log('talent', 'acknowledge', "{$a->employee->name} acknowledged the appraisal", $a->employee);
    }

    public static function appraisalOverdue(TalentAppraisal $a): bool
    {
        $t = self::today();

        return ($a->status === 'self' && $a->cycle->self_due->toDateString() < $t) || ($a->status === 'review' && $a->cycle->review_due->toDateString() < $t);
    }

    // ───────────────────────── discipline & grievances ─────────────────────────

    public static function warningMonths(string $outcome): int
    {
        $c = self::cfg();

        return match ($outcome) { 'verbal' => $c['warn_verbal'], 'written' => $c['warn_written'], 'final', 'suspension' => $c['warn_final'], default => 0 };
    }

    public static function activeWarnings(Employee $e, ?int $except = null): Collection
    {
        $t = self::today();

        return TalentCase::where('employee_id', $e->id)->where('kind', 'discipline')->whereIn('outcome', ['verbal', 'written', 'final', 'suspension'])
            ->whereDate('expires_on', '>=', $t)->when($except, fn ($q) => $q->where('id', '!=', $except))->orderByDesc('outcome_on')->get();
    }

    public static function raiseCase(array $d, User $u): TalentCase
    {
        $kind = $d['kind'] ?? 'discipline';
        self::need(in_array($kind, ['discipline', 'grievance'], true), 'Choose discipline or grievance.');
        $e = Employee::where('is_active', true)->find((int) ($d['employee_id'] ?? 0));
        self::need((bool) $e, 'Choose the employee.');
        $cats = $kind === 'grievance' ? TalentCase::GRIEVANCE : TalentCase::DISCIPLINE;
        $cat = $d['category'] ?? null;
        self::need(isset($cats[$cat]), 'Choose the category.');
        $sev = $kind === 'discipline' ? ($d['severity'] ?? null) : null;
        self::need($kind === 'grievance' || isset(TalentCase::SEVERITY[$sev]), 'Choose how serious the alleged misconduct is.');
        $on = self::date($d['incident_on'] ?? null, 'date of the incident');
        self::need($on->toDateString() <= self::today(), 'The incident date is in the future.');
        $desc = self::text($d['description'] ?? null, 4000);
        self::need((bool) $desc, 'Describe what happened.');
        self::need($kind === 'grievance' || $e->user_id !== $u->id, 'You cannot raise a disciplinary case against yourself.');
        $c = TalentCase::create(['number' => Numbering::next($kind === 'grievance' ? 'talent_grievance' : 'talent_case'), 'kind' => $kind, 'employee_id' => $e->id,
            'category' => $cat, 'severity' => $sev, 'incident_on' => $on->toDateString(), 'description' => $desc,
            'confidential' => $kind === 'grievance' && (! empty($d['confidential']) || in_array($cat, ['sexual_harassment', 'discrimination', 'bullying'], true)),
            'status' => 'open', 'raised_by' => $u->id]);
        ActivityLogger::log('talent', $kind, "{$c->number} {$c->categoryLabel()} – {$e->name}", $c);

        return $c;
    }

    public static function issueQuery(TalentCase $c, array $d, User $u): void
    {
        self::need($c->kind === 'discipline' && $c->status === 'open', 'A query is issued once, on an open disciplinary case.');
        $due = self::date($d['reply_due'] ?? null, 'reply deadline', false) ?? bdate()->addDays(self::cfg()['query_days']);
        self::need($due->toDateString() > self::today(), 'Give the employee at least a day to reply.');
        $c->update(['status' => 'query', 'query_issued_on' => self::today(), 'reply_due' => $due->toDateString()]);
        ActivityLogger::log('talent', 'query', "{$c->number}: query issued to {$c->employee->name}, reply by {$due->format('d M Y')}", $c);
    }

    public static function recordReply(TalentCase $c, array $d, User $u): void
    {
        self::need($c->status === 'query' && ! $c->replied_on, 'There is no open query to reply to.');
        $text = self::text($d['reply'] ?? null, 4000);
        self::need((bool) $text, 'Enter the employee\'s written reply.');
        $on = self::date($d['replied_on'] ?? self::today(), 'reply date');
        self::need($on->gte($c->query_issued_on) && $on->toDateString() <= self::today(), 'The reply date must fall between the query and today.');
        $c->update(['reply' => $text, 'replied_on' => $on->toDateString()]);
        ActivityLogger::log('talent', 'reply', "{$c->number}: reply received".($on->gt($c->reply_due) ? ' (late)' : ''), $c);
    }

    public static function scheduleHearing(TalentCase $c, array $d, User $u): void
    {
        self::need($c->kind === 'discipline' && in_array($c->status, ['query', 'hearing'], true) && ! $c->hearing_held, 'A hearing follows the query.');
        try {
            $at = Carbon::parse((string) ($d['hearing_at'] ?? ''));
        } catch (\Throwable) {
            $at = null;
        }
        self::need(filled($d['hearing_at'] ?? null) && $at, 'Enter the hearing date and time.');
        $panel = self::text($d['panel'] ?? null, 255);
        self::need((bool) $panel, 'Name the hearing panel.');
        $c->update(['status' => 'hearing', 'hearing_at' => $at, 'panel' => $panel, 'representative' => self::text($d['representative'] ?? null, 120)]);
        ActivityLogger::log('talent', 'hearing', "{$c->number}: hearing on {$at->format('d M Y H:i')}", $c);
    }

    public static function recordHearing(TalentCase $c, array $d, User $u): void
    {
        self::need($c->status === 'hearing' && ! $c->hearing_held, 'No hearing is scheduled.');
        self::need(! $c->hearing_at->isFuture(), 'The hearing has not taken place yet.');
        $notes = self::text($d['hearing_notes'] ?? null, 6000);
        self::need((bool) $notes, 'Record what was said at the hearing – the employee\'s side must be heard.');
        $c->update(['hearing_held' => true, 'hearing_notes' => $notes]);
        ActivityLogger::log('talent', 'hearing_held', "{$c->number}: hearing held", $c);
    }

    public static function decide(TalentCase $c, array $d, User $u): ?TalentExit
    {
        self::need($c->kind === 'discipline' && in_array($c->status, ['query', 'hearing'], true), 'This case is not ready for a decision.');
        self::need($c->replied_on || ($c->reply_due && $c->reply_due->toDateString() < self::today()),
            'Wait for the employee\'s reply or for the reply deadline ('.$c->reply_due?->format('d M').') to pass.');
        self::need($c->status !== 'hearing' || $c->hearing_held, 'Record the hearing before deciding.');
        $finding = $d['finding'] ?? null;
        self::need(in_array($finding, ['proven', 'not_proven'], true), 'Record whether the misconduct was proven.');
        $out = $finding === 'not_proven' ? 'none' : ($d['outcome'] ?? null);
        self::need(isset(TalentCase::OUTCOMES[$out]), 'Choose the sanction.');
        self::need($finding === 'not_proven' || $out !== 'none' || filled($d['resolution'] ?? null), 'Explain why no action is taken on proven misconduct.');
        if (in_array($out, ['final', 'suspension', 'dismissal'], true)) {
            self::need($c->hearing_held, 'A final warning, suspension or dismissal needs a disciplinary hearing first.');
        }
        $days = null;
        if ($out === 'suspension') {
            $days = (int) ($d['suspension_days'] ?? 0);
            self::need($days >= 1 && $days <= 30, 'A suspension is 1 to 30 days.');
        }
        if ($out === 'dismissal') {
            self::need(self::can($u, 'approve'), 'Only an approver can decide a dismissal.');
            self::need($c->raised_by !== $u->id, 'You raised this case – another approver must decide the dismissal.');
            $finalOnFile = self::activeWarnings($c->employee, $c->id)->contains(fn ($w) => in_array($w->outcome, ['final', 'suspension'], true));
            self::need($c->severity === 'gross' || ($c->severity === 'major' && $finalOnFile),
                'Dismissal needs gross misconduct, or major misconduct while a final warning is still on file – otherwise choose a lesser sanction.');
            self::need(! empty($d['fair_confirmed']), 'Confirm that none of the unfair reasons in s. 63 (union activity, a complaint, gender, race, religion, pregnancy, disability…) plays any part.');
        }
        $months = self::warningMonths($out);

        return DB::transaction(function () use ($c, $d, $u, $finding, $out, $days, $months) {
            $c->update(['status' => 'decided', 'finding' => $finding, 'outcome' => $out, 'suspension_days' => $days, 'suspension_paid' => $out === 'suspension' && ! empty($d['suspension_paid']),
                'outcome_on' => self::today(), 'expires_on' => $months ? bdate()->addMonthsNoOverflow($months)->toDateString() : null,
                'resolution' => self::text($d['resolution'] ?? null, 2000), 'decided_by' => $u->id]);
            ActivityLogger::log('talent', 'decision', "{$c->number}: ".($finding === 'proven' ? 'proven – ' : 'not proven – ').TalentCase::OUTCOMES[$out], $c);
            if ($out === 'dismissal') {
                return self::startExit($c->employee, ['type' => 'dismissal', 'notice_on' => self::today(), 'reason' => $c->categoryLabel().': '.mb_substr($c->description, 0, 200),
                    'fair_confirmed' => 1, 'case_id' => $c->id], $u);
            }

            return null;
        });
    }

    public static function progressGrievance(TalentCase $c, string $to, array $d, User $u): void
    {
        self::need($c->kind === 'grievance', 'Not a grievance.');
        $flow = ['investigating' => ['open'], 'resolved' => ['open', 'investigating'], 'withdrawn' => ['open', 'investigating']];
        self::need(isset($flow[$to]) && in_array($c->status, $flow[$to], true), 'That step is not possible now.');
        $res = self::text($d['resolution'] ?? null, 3000);
        self::need($to === 'investigating' || (bool) $res, 'Record the resolution / reason.');
        $c->update(['status' => $to, 'resolution' => $res ?? $c->resolution, 'outcome_on' => $to === 'investigating' ? null : self::today(), 'decided_by' => $to === 'investigating' ? null : $u->id]);
        ActivityLogger::log('talent', 'grievance_'.$to, "{$c->number}: ".TalentCase::STATUSES[$to], $c);
    }

    public static function closeCase(TalentCase $c, User $u, ?string $reason = null): void
    {
        if (in_array($c->status, ['open', 'query'], true) && $c->kind === 'discipline') {
            $reason = self::text($reason, 255);
            self::need((bool) $reason, 'Say why the case is withdrawn.');
            $c->update(['status' => 'withdrawn', 'resolution' => $reason, 'closed_at' => now()]);
        } else {
            self::need(in_array($c->status, ['decided', 'resolved'], true), 'Only a decided or resolved case can be closed.');
            $c->update(['status' => 'closed', 'closed_at' => now()]);
        }
        ActivityLogger::log('talent', 'case_close', "{$c->number}: ".TalentCase::STATUSES[$c->status], $c);
    }

    public static function canSeeCase(TalentCase $c, ?User $u): bool
    {
        return $u && self::can($u, 'discipline') && (! $c->confidential || $c->raised_by === $u->id || self::can($u, 'manage') || self::can($u, 'approve'));
    }

    // ───────────────────────── exits ─────────────────────────

    public static function serviceYears(Employee $e, Carbon $on): float
    {
        return $e->joining_date ? $e->joining_date->floatDiffInYears($on) : 0.0;
    }

    /** Statutory notice: [days, basis, last day]. The day notice is given counts as the first day (s. 17). */
    public static function notice(Employee $e, string $type, Carbon $on, ?TalentCase $case = null, ?int $contractDays = null): array
    {
        $on = $on->copy()->startOfDay();
        $employer = in_array($type, TalentExit::EMPLOYER, true);
        if ($type === 'death') {
            return [0, 'Death in service – no notice; dues are paid to the estate', $on];
        }
        if ($type === 'dismissal' && $case?->severity === 'gross') {
            return [0, 'Summary dismissal for gross misconduct', $on];
        }
        if ($type === 'contract_end') {
            $end = $e->contract_end && $e->contract_end->gte($on) ? $e->contract_end->copy() : $on;

            return [(int) $on->diffInDays($end) + 1, 'Fixed-term contract expires', $end];
        }
        if ($type === 'probation') {
            $d = self::cfg()['probation_notice'];

            return [$d, "Contractual probation notice ({$d} days)", $d ? $on->copy()->addDays($d - 1) : $on];
        }
        if (($e->employment_type ?? 'permanent') === 'casual') {
            [$days, $basis, $last] = [0, 'Casual / daily-rated – terminable at the close of any day (s. 17(1)(c))', $on];
        } elseif ($e->employment_type === 'weekly') {
            [$days, $basis, $last] = [7, 'Weekly-paid – 7 days (s. 17(1)(b))', $on->copy()->addDays(6)];
        } elseif (self::serviceYears($e, $on) >= 3) {
            $last = $on->copy()->addMonthsNoOverflow(1)->subDay();
            [$days, $basis] = [(int) $on->diffInDays($last) + 1, 'Three years\' service or more – one month (s. 17(1)(a))'];
        } else {
            [$days, $basis, $last] = [14, 'Under three years\' service – two weeks (s. 17(1)(a))', $on->copy()->addDays(13)];
        }
        if ($e->has_disability && $employer) {
            $month = $on->copy()->addMonthsNoOverflow(1)->subDay();
            if ($month->gt($last)) {
                [$days, $basis, $last] = [(int) $on->diffInDays($month) + 1, 'Worker with a disability – at least one month (s. 51)', $month];
            }
        }
        if ($contractDays && $contractDays > $days) {
            [$days, $basis, $last] = [$contractDays, "Contract / collective agreement – {$contractDays} days (longer than the statutory minimum)", $on->copy()->addDays($contractDays - 1)];
        }

        return [(int) $days, $basis, $last];
    }

    public static function pilonAmount(Employee $e, int $days, string $basis): float
    {
        $salary = (float) $e->salary;
        if (str_contains($basis, 'one month')) {
            return round($salary, 2);
        }

        return round($salary * 12 / 52 * $days / 7, 2);
    }

    public static function nextWorkingDay(Carbon $d): Carbon
    {
        $n = $d->copy()->addDay();
        while ($n->isWeekend()) {
            $n->addDay();
        }

        return $n;
    }

    public static function onMaternityLeave(Employee $e, Carbon $on): bool
    {
        return LeaveRequest::where('employee_id', $e->id)->where('status', 'approved')
            ->whereDate('date_from', '<=', $on->toDateString())->whereDate('date_to', '>=', $on->copy()->subWeeks(2)->toDateString())
            ->whereHas('leaveType', fn ($q) => $q->where('code', 'ML')->orWhere('name', 'like', '%matern%'))->exists();
    }

    public static function startExit(Employee $e, array $d, User $u): TalentExit
    {
        $type = $d['type'] ?? null;
        self::need(isset(TalentExit::TYPES[$type]), 'Choose why the employment ends.');
        self::need($e->is_active, "{$e->name} has already left.");
        self::need(! TalentExit::where('employee_id', $e->id)->where('status', 'notice')->exists(), "{$e->name} already has an exit in progress.");
        $on = self::date($d['notice_on'] ?? self::today(), 'date notice is given');
        self::need($on->toDateString() <= self::today(), 'Notice cannot be dated in the future.');
        $case = filled($d['case_id'] ?? null) ? TalentCase::find((int) $d['case_id']) : null;
        self::need($type !== 'dismissal' || ($case && $case->outcome === 'dismissal' && $case->employee_id === $e->id), 'A dismissal is decided through a disciplinary case.');
        $employer = in_array($type, TalentExit::EMPLOYER, true);
        if ($employer) {
            self::need(! empty($d['fair_confirmed']), 'Confirm that none of the unfair reasons in s. 63 applies.');
            self::need(! self::onMaternityLeave($e, $on), "{$e->name} is on maternity leave – the employer cannot end the employment during it (s. 57(8)).");
            self::need(in_array($type, ['probation', 'dismissal'], true) || self::can($u, 'approve'), 'Only an approver can end employment for this reason.');
        }
        $contractDays = filled($d['contract_notice_days'] ?? null) ? (int) $d['contract_notice_days'] : null;
        self::need($contractDays === null || ($contractDays >= 0 && $contractDays <= 180), 'Contractual notice is 0 to 180 days.');
        [$days, $basis, $last] = self::notice($e, $type, $on, $case, $contractDays);
        if ($type === 'resignation' && filled($d['last_day'] ?? null)) {
            $want = self::date($d['last_day'], 'last day');
            self::need($want->gte($on), 'The last day is before the notice date.');
            $last = $want;   // a worker may give more notice than the minimum; less is by agreement (waiver) – recorded as such
            $basis .= $want->lt($on->copy()->addDays(max(0, $days - 1))) ? ' – shorter notice agreed by the employer' : '';
        }
        $pilon = ! empty($d['pilon']) && $days > 0 && $type !== 'resignation';
        $amount = $pilon ? self::pilonAmount($e, $days, $basis) : null;
        if ($pilon) {
            $last = $on->copy();
        }
        if ($type === 'redundancy') {
            $clo = self::date($d['clo_notified_on'] ?? null, 'date the Chief Labour Officer was notified');
            self::need($clo->lte($last->copy()->subMonthsNoOverflow(3)), 'Redundancy needs three months\' notice to the Chief Labour Officer and the union before it takes effect (s. 65).');
        }
        $finalPay = ($days === 0 || $pilon) ? self::nextWorkingDay($last) : $last->copy();
        $x = TalentExit::create(['number' => Numbering::next('talent_exit'), 'employee_id' => $e->id, 'type' => $type, 'notice_on' => $on->toDateString(), 'notice_days' => $days,
            'notice_basis' => mb_substr($basis, 0, 120), 'last_day' => $last->toDateString(), 'pilon' => $pilon, 'pilon_amount' => $amount, 'final_pay_due' => $finalPay->toDateString(),
            'case_id' => $case?->id, 'reason' => self::text($d['reason'] ?? null, 2000), 'clo_notified_on' => $type === 'redundancy' ? $clo->toDateString() : null,
            'fair_confirmed' => $employer, 'status' => 'notice', 'created_by' => $u->id,
            'clearance' => collect(self::CLEARANCE)->map(fn () => ['done' => false, 'by' => null, 'at' => null, 'note' => null])->all()]);
        ActivityLogger::log('talent', 'exit', "{$x->number} {$e->name}: ".TalentExit::TYPES[$type].", last day {$last->format('d M Y')}", $x);

        return $x;
    }

    /** Facts the clearance checklist needs: keys out, advance balance, leave balance and its value. */
    public static function clearanceFacts(TalentExit $x): array
    {
        $e = $x->employee;
        $keys = $e->user_id ? KeyLoan::where('user_id', $e->user_id)->where('status', 'out')->count() : 0;
        $given = (float) StaffAdvance::where('employee_id', $e->id)->whereNull('cancelled_at')->sum('amount');
        $rec = (float) PayslipLine::whereNotNull('staff_advance_id')->whereIn('staff_advance_id', StaffAdvance::where('employee_id', $e->id)->pluck('id'))
            ->whereHas('payslip.run', fn ($q) => $q->whereIn('status', ['approved', 'paid']))->sum('amount');
        $el = LeaveService::balances($e, $x->last_day->year)->first(fn ($b) => $b['type']->code === 'EL');
        $days = $el ? max(0, (float) $el['balance']) : 0.0;

        return ['keys' => $keys, 'advance' => round($given - $rec, 2), 'leave_days' => $days, 'leave_value' => round($days * (float) $e->salary * 12 / 260, 2)];
    }

    public static function updateClearance(TalentExit $x, string $item, bool $done, ?string $note, User $u): void
    {
        self::need($x->status === 'notice', 'This exit is closed.');
        self::need(isset(self::CLEARANCE[$item]), 'Unknown clearance item.');
        if ($done) {
            $f = self::clearanceFacts($x);
            self::need($item !== 'keys' || $f['keys'] === 0, "{$f['keys']} key(s) are still signed out to {$x->employee->name}.");
            self::need($item !== 'advance' || $f['advance'] <= 0 || filled($note), 'An advance of '.money($f['advance']).' is outstanding – note how it is recovered from the final pay.');
        }
        $c = $x->clearance ?? [];
        $c[$item] = ['done' => $done, 'by' => $done ? $u->name : null, 'at' => $done ? now()->toDateTimeString() : null, 'note' => self::text($note, 190)];
        $x->update(['clearance' => $c]);
        ActivityLogger::log('talent', 'clearance', "{$x->number}: ".self::CLEARANCE[$item].($done ? ' ✓' : ' re-opened'), $x);
    }

    public static function saveInterview(TalentExit $x, array $d, User $u): void
    {
        self::need(in_array($x->status, ['notice', 'completed'], true), 'This exit was withdrawn.');
        $reason = $d['primary'] ?? null;
        self::need(isset(self::EXIT_REASONS[$reason]), 'Choose the main reason for leaving.');
        $sat = self::rating($d['satisfaction'] ?? null, 'overall satisfaction', true);
        $x->update(['interview' => ['primary' => $reason, 'satisfaction' => $sat, 'recommend' => ! empty($d['recommend']), 'liked' => self::text($d['liked'] ?? null, 1000),
            'improve' => self::text($d['improve'] ?? null, 1000), 'by' => $u->name, 'on' => self::today()],
            'regretted' => ! empty($d['regretted']), 'rehire' => ! empty($d['rehire'])]);
        ActivityLogger::log('talent', 'exit_interview', "{$x->number}: exit interview recorded", $x);
    }

    public static function completeExit(TalentExit $x, User $u): void
    {
        self::need($x->status === 'notice', 'This exit is not in progress.');
        self::need($x->last_day->toDateString() <= self::today(), 'The employee is still working their notice (last day '.$x->last_day->format('d M Y').').');
        $missing = collect(self::CLEARANCE)->except('access')->filter(fn ($l, $k) => empty($x->clearance[$k]['done']));
        self::need($missing->isEmpty(), 'Clearance is incomplete: '.$missing->implode(', ').'.');
        DB::transaction(function () use ($x, $u) {
            $e = $x->employee;
            $e->update(['leaving_date' => $x->last_day->toDateString(), 'is_active' => false]);
            if ($e->user_id) {
                User::where('id', $e->user_id)->update(['is_active' => false]);
            }
            $c = $x->clearance ?? [];
            $c['access'] = ['done' => true, 'by' => $u->name, 'at' => now()->toDateTimeString(), 'note' => $e->user_id ? 'Login deactivated automatically' : ($c['access']['note'] ?? null)];
            $x->update(['status' => 'completed', 'completed_by' => $u->id, 'completed_at' => now(), 'clearance' => $c]);
        });
        ActivityLogger::log('talent', 'exit_complete', "{$x->number} {$x->employee->name} left on {$x->last_day->format('d M Y')}", $x);
    }

    public static function withdrawExit(TalentExit $x, User $u, ?string $reason): void
    {
        self::need($x->status === 'notice', 'Only an exit in progress can be withdrawn.');
        self::need(! in_array($x->type, ['death', 'contract_end'], true), 'This exit cannot be withdrawn.');
        $reason = self::text($reason, 255);
        self::need((bool) $reason, 'Say why the exit is withdrawn (e.g. resignation withdrawn with the employer\'s agreement).');
        $x->update(['status' => 'withdrawn', 'reason' => trim(($x->reason ?? '')."\nWithdrawn: {$reason}")]);
        ActivityLogger::log('talent', 'exit_withdraw', "{$x->number}: withdrawn – {$reason}", $x);
    }

    /** Twelve-month turnover to the business date: leavers ÷ average headcount. */
    public static function turnover(?Carbon $to = null): array
    {
        $to ??= bdate();
        $from = $to->copy()->subYear()->addDay();
        $count = fn (string $d) => Employee::where(fn ($q) => $q->whereNull('joining_date')->orWhereDate('joining_date', '<=', $d))
            ->where(fn ($q) => $q->where(fn ($w) => $w->whereNull('leaving_date')->where('is_active', true))->orWhereDate('leaving_date', '>=', $d))->count();
        $avg = ($count($from->toDateString()) + $count($to->toDateString())) / 2;
        $leavers = TalentExit::with('employee')->where('status', 'completed')->whereDate('last_day', '>=', $from->toDateString())->whereDate('last_day', '<=', $to->toDateString())->get();
        $vol = $leavers->filter->isVoluntary()->count();

        return ['from' => $from, 'to' => $to, 'average' => $avg, 'leavers' => $leavers->count(), 'voluntary' => $vol, 'regretted' => $leavers->where('regretted', true)->count(),
            'rate' => $avg > 0 ? round($leavers->count() / $avg * 100, 1) : 0.0, 'voluntary_rate' => $avg > 0 ? round($vol / $avg * 100, 1) : 0.0];
    }

    // ───────────────────────── dashboard, alerts, nightly ─────────────────────────

    public static function alerts(): array
    {
        return self::$memo['alerts'.Tenant::id().'-'.self::today()] ??= (function () {
            $t = self::today();
            $cycles = TalentCycle::where('status', 'active')->pluck('id');
            $apps = TalentAppraisal::with('cycle')->whereIn('cycle_id', $cycles)->whereIn('status', ['self', 'review'])->get();

            return [
                'vacancies' => TalentVacancy::where('status', 'pending')->count(),
                'offers' => TalentOffer::where('status', 'pending')->count(),
                'probation' => Employee::where('is_active', true)->whereNotNull('probation_end')->whereNull('confirmed_on')->whereDate('probation_end', '<', $t)->count(),
                'statements' => self::statementsOverdue()->count(),
                'replies' => TalentCase::where('status', 'query')->whereNull('replied_on')->whereDate('reply_due', '<', $t)->count(),
                'exits' => TalentExit::where('status', 'notice')->whereDate('last_day', '<', $t)->count(),
                'appraisals' => $apps->filter(fn ($a) => self::appraisalOverdue($a))->count(),
            ];
        })();
    }

    public static function attention(): int
    {
        return array_sum(self::alerts());
    }

    public static function dashboard(): array
    {
        $t = self::today();

        return [
            'alerts' => self::alerts(),
            'open' => TalentVacancy::with('department')->withCount(['applicants as pipeline_count' => fn ($q) => $q->whereIn('stage', TalentApplicant::OPEN)])
                ->whereIn('status', ['pending', 'open', 'on_hold'])->orderBy('status')->orderBy('closing_on')->get(),
            'pipeline' => TalentApplicant::whereHas('vacancy', fn ($q) => $q->where('status', 'open'))->selectRaw('stage, count(*) n')->groupBy('stage')->pluck('n', 'stage'),
            'interviews' => TalentInterview::with('applicant.vacancy')->where('status', 'scheduled')->where('scheduled_at', '>=', bdate()->startOfDay())->orderBy('scheduled_at')->limit(8)->get(),
            'probation' => self::onProbation()->filter(fn ($e) => $e->probation_end->toDateString() <= bdate()->addDays(30)->toDateString()),
            'exits' => TalentExit::with('employee.department')->where('status', 'notice')->orderBy('last_day')->get(),
            'turnover' => self::turnover(),
            'headcount' => Employee::where('is_active', true)->count(),
            'cases' => TalentCase::whereNotIn('status', ['closed', 'withdrawn', 'decided', 'resolved'])->count(),
            'cycle' => TalentCycle::where('status', 'active')->withCount(['appraisals', 'appraisals as signed_count' => fn ($q) => $q->where('status', 'signed')])->latest('id')->first(),
            'today' => $t,
        ];
    }

    /** Nightly: anonymise unsuccessful applicants after the retention period and delete their CVs (Data Protection Act 2012). */
    public static function nightly(?string $date = null): int
    {
        $cut = Carbon::parse($date ?? self::today())->subMonthsNoOverflow(self::cfg()['retention_months']);
        $n = 0;
        foreach (TalentApplicant::whereIn('stage', ['rejected', 'withdrawn'])->whereNull('anonymised_at')->where('stage_changed_at', '<', $cut)->get() as $a) {
            if ($a->cv_path) {
                Storage::disk('local')->delete($a->cv_path);
            }
            $a->update(['name' => 'Anonymised applicant', 'email' => null, 'phone' => null, 'current_employer' => null, 'cover_note' => null, 'cv_path' => null, 'cv_name' => null,
                'cv_mime' => null, 'anonymised_at' => now()]);
            $n++;
        }
        if ($n) {
            ActivityLogger::log('talent', 'anonymise', "{$n} unsuccessful applicant(s) anonymised after the retention period");
        }

        return $n;
    }
}
