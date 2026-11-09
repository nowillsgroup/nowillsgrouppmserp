<?php

namespace Database\Seeders\Concerns;

use App\Models;
use App\Services\FolioService;
use App\Services\HotelProvisioner;
use App\Services\ApService;
use App\Services\ForexService;
use App\Services\LedgerService;
use App\Services\StayService;
use App\Services\TaxService;
use App\Support\Numbering;
use App\Support\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Demo-data seeders — split out of DatabaseSeeder (one file per domain).
 * Composed into DatabaseSeeder via `use`; every method keeps its original visibility and body.
 */
trait SeedPeopleConcern
{
    protected function seedWorkforce(bool $rich, Carbon $today): void
    {
        if (\App\Models\ShiftTemplate::exists()) {
            return;
        }
        $R = \App\Services\RosterService::class;
        $TC = \App\Services\TimeClockService::class;
        $hid = \App\Support\Tenant::id();
        $u = fn ($n) => Models\User::where('hotel_id', $hid)->where('username', $n)->first();
        $prevUser = auth()->user();
        $tpl = [];
        foreach ([['M', 'Morning', '06:00', '14:00', 30, '#f59e0b', null], ['E', 'Evening', '14:00', '22:00', 30, '#6366f1', null], ['N', 'Night', '22:00', '06:00', 30, '#334155', null],
            ['G', 'General', '09:00', '18:00', 60, '#10b981', null], ['SP', 'Spa day', '10:00', '19:00', 60, '#ec4899', 9]] as [$c, $n, $st, $en, $br, $col, $dept]) {
            $tpl[$c] = \App\Models\ShiftTemplate::create(['code' => $c, 'name' => $n, 'start_time' => $st, 'end_time' => $en, 'break_minutes' => $br, 'color' => $col,
                'department_id' => $dept && Models\Department::whereKey($dept)->exists() ? $dept : null, 'is_active' => true]);
        }
        // points & eligibility: managers / heads 3, supervisors 2, others 1; back office (accounts, HR) not in the tronc
        foreach (Models\Employee::all() as $e) {
            $pts = preg_match('/manager|head|chief/i', (string) $e->designation) ? 3 : (preg_match('/supervisor/i', (string) $e->designation) ? 2 : 1);
            $dn = (string) Models\Department::whereKey($e->department_id)->value('name');
            $e->forceFill(['sc_points' => $pts, 'sc_eligible' => ! in_array($dn, ['Accounts', 'Management'], true)])->save();
        }
        if (! $rich) {
            return;
        }
        [$admin, $hr, $hk, $acct, $fnb] = [$u('admin'), $u('hr'), $u('housekeeping'), $u('accounts'), $u('restaurant')];
        $emp = fn ($code) => Models\Employee::where('emp_code', $code)->first();
        // pattern: code per employee; line staff alternate M / E weekly; two consecutive days off staggered by employee
        $plan = ['EMP005' => 'ME', 'EMP013' => 'G', 'EMP001' => 'M', 'EMP002' => 'ME', 'EMP009' => 'EM', 'EMP007' => 'E', 'EMP006' => 'G', 'EMP008' => 'N', 'EMP010' => 'SP', 'EMP011' => 'SP', 'EMP012' => 'SP'];
        $thisWeek = $R::weekStart(now());
        $weeks = [$thisWeek->copy()->subWeek(), $thisWeek->copy(), $thisWeek->copy()->addWeek()];
        foreach ($weeks as $w) {
            $alt = (int) $w->format('W') % 2;
            foreach ($plan as $code => $p) {
                $e = $emp($code);
                if (! $e) {
                    continue;
                }
                $shiftCode = in_array($p, ['ME', 'EM'], true) ? $p[$alt] : $p;
                $off1 = ($e->id * 2) % 7;
                $leave = $R::leaveMap(collect([$e->id]), $w->toDateString(), $w->copy()->addDays(6)->toDateString())[$e->id] ?? [];
                for ($i = 0; $i < 7; $i++) {
                    $d = $w->copy()->addDays($i)->toDateString();
                    if (isset($leave[$d]) && ! $leave[$d]->half_day) {
                        continue;
                    }
                    $off = $i === $off1 || $i === ($off1 + 1) % 7;
                    $t = $tpl[$shiftCode];
                    \App\Models\RosterShift::create(['employee_id' => $e->id, 'date' => $d, 'kind' => $off ? 'off' : 'shift', 'shift_template_id' => $off ? null : $t->id,
                        'start_time' => $off ? null : $t->start_time, 'end_time' => $off ? null : $t->end_time, 'break_minutes' => $off ? 0 : $t->break_minutes, 'created_by' => $hr?->id]);
                }
            }
        }
        // the steward worked yesterday evening and is off today (his missed clock-out below is his latest shift)
        if ($s7 = $emp('EMP007')) {
            \App\Models\RosterShift::where('employee_id', $s7->id)->where('date', now()->subDay()->toDateString())->update(['kind' => 'shift', 'shift_template_id' => $tpl['E']->id, 'start_time' => '14:00', 'end_time' => '22:00', 'break_minutes' => 30]);
            \App\Models\RosterShift::where('employee_id', $s7->id)->where('date', now()->toDateString())->update(['kind' => 'off', 'shift_template_id' => null, 'start_time' => null, 'end_time' => null, 'break_minutes' => 0]);
        }
        $depts = Models\Employee::whereIn('emp_code', array_keys($plan))->distinct()->pluck('department_id');
        $spa = Models\Department::where('name', 'Spa & Wellness')->value('id');
        foreach ($depts as $dId) {
            foreach ($weeks as $k => $w) {
                $pub = $k < 2 || $dId != $spa;
                \App\Models\RosterWeek::create(['department_id' => $dId, 'week_start' => $w->toDateString(), 'published_at' => $pub ? $w->copy()->subDays(4)->setTime(16, 30) : null, 'published_by' => $pub ? $hr?->id : null]);
            }
        }

        // ---------- time punches: D-6 … now ----------
        Models\Setting::updateOrCreate(['key' => 'timeclock_self'], ['value' => '1']);
        Models\Setting::$version++;
        auth()->setUser($hr);
        $now = now();
        $shifts = \App\Models\RosterShift::with('employee')->where('kind', 'shift')->whereBetween('date', [$now->copy()->subDays(6)->toDateString(), $now->toDateString()])->orderBy('date')->orderBy('start_time')->get();
        $D = fn (int $n) => $now->copy()->subDays($n)->toDateString();
        // each exception lands on the first preferred day the employee actually worked (weekday-independent)
        $pick = function (string $code, array $prefer) use ($shifts, $D, $now) {
            foreach ($prefer as $n) {
                if ($shifts->first(fn ($s) => $s->employee->emp_code === $code && $s->date->toDateString() === $D($n) && $s->endsAt()->lt($now))) {
                    return $D($n);
                }
            }

            return null;
        };
        $X = ['noshow' => $pick('EMP002', [3, 4, 2, 5]), 'late' => $pick('EMP005', [2, 3, 4, 5, 6]), 'late2' => $pick('EMP012', [1, 2, 3, 4]),
            'early' => $pick('EMP011', [3, 4, 2, 5, 6]), 'edit' => $pick('EMP009', [4, 5, 3, 6, 2])];
        foreach ($shifts->sortBy(fn ($s) => $s->startsAt()) as $s) {
            $e = $s->employee;
            $d = $s->date->toDateString();
            $start = $s->startsAt();
            $end = $s->endsAt();
            if ($start->gt($now) || ($e->emp_code === 'EMP002' && $d === $X['noshow'])) {
                continue; // not started yet / Lata's no-show
            }
            $h = crc32($e->id.'|'.$d) % 100;
            $inAt = $start->copy()->addMinutes(($h % 14) - 10);          // −10 … +3 min
            if ($e->emp_code === 'EMP005' && $d === $X['late']) {
                $inAt = $start->copy()->addMinutes(23);
            }
            if ($e->emp_code === 'EMP012' && $d === $X['late2']) {
                $inAt = $start->copy()->addMinutes(9);
            }
            if ($inAt->gt($now)) {
                $inAt = $now->copy()->subMinute();
            }
            $src = $h % 3 === 0 ? 'self' : 'kiosk';
            try {
                $p = $TC::clockIn($e, $src, $src === 'self' ? '10.10.4.'.(20 + $e->id) : '10.10.1.5', $inAt);
                if ($e->emp_code === 'EMP007' && $d === $D(1)) {
                    continue; // forgot to clock out
                }
                $outAt = $end->copy()->addMinutes(($h % 17) - 2);             // −2 … +14 min
                if ($e->emp_code === 'EMP011' && $d === $X['early']) {
                    $outAt = $end->copy()->subMinutes(40);                       // left early (appointment)
                }
                if ($outAt->gt($now)) {
                    continue;                                                    // still on duty
                }
                if ($e->emp_code === 'EMP009' && $d === $X['edit']) {
                    auth()->setUser($hk);
                    $TC::managerSave($e, $inAt->format('Y-m-d H:i'), $end->copy()->addMinutes(5)->format('Y-m-d H:i'), 'Forgot to clock out – finished at 22:05, confirmed by the duty supervisor', $hk, $p);
                    auth()->setUser($hr);

                    continue;
                }
                $TC::clockOut($e, $src, null, $outAt);
            } catch (\RuntimeException $ex) {
                // a clash with an earlier exception (e.g. open punch) – leave that shift unpunched
            }
        }
        // unrostered: the electrician called in for a breakdown yesterday (kiosk)
        if (($j = $emp('EMP003')) && $now->copy()->subDay()->setTime(17, 30)->lt($now)) {
            try {
                $TC::clockIn($j, 'kiosk', '10.10.1.5', $now->copy()->subDay()->setTime(9, 2));
                $TC::clockOut($j, 'kiosk', '10.10.1.5', $now->copy()->subDay()->setTime(17, 34));
            } catch (\RuntimeException $ex) {
            }
        }
        foreach ([6, 5, 4, 3, 2] as $n) {
            $TC::approveDay($D($n), null, $hr);
        }
        foreach (['EMP001' => '4821', 'EMP005' => '3907'] as $code => $pin) {
            ($x = $emp($code)) && $TC::setPin($x, $pin);
        }

        // ---------- swaps (next week, published) ----------
        $next = $weeks[2];
        $findDay = function ($a, $b, array $exclude = []) use ($next) {
            for ($i = 0; $i < 7; $i++) {
                $d = $next->copy()->addDays($i)->toDateString();
                $sa = \App\Models\RosterShift::where('employee_id', $a->id)->where('date', $d)->first();
                $sb = \App\Models\RosterShift::where('employee_id', $b->id)->where('date', $d)->first();
                if (! in_array($d, $exclude, true) && $sa?->isShift() && (! $sb?->isShift() || $sb->start_time !== $sa->start_time)) {
                    return $sa;
                }
            }

            return null;
        };
        try {
            [$ram, $lata, $abena, $deepa, $ravi] = [$emp('EMP001'), $emp('EMP002'), $emp('EMP009'), $emp('EMP005'), $emp('EMP013')];
            if ($s = $findDay($ram, $abena)) {
                $sw = $R::requestSwap($ram, $s, $abena, 'Doctor\'s appointment that morning');
                $R::peerRespond($sw, $abena, true);                                     // waiting for the manager
                $used = $s->date->toDateString();
            }
            if ($s = $findDay($lata, $ram, [$used ?? ''])) {
                $R::requestSwap($lata, $s, $ram, 'Church harvest – could you take my shift?');   // waiting for Ramesh
            }
            if ($s = $findDay($deepa, $ravi)) {
                $sw = $R::requestSwap($deepa, $s, $ravi, 'Sister\'s graduation in Cape Coast');
                $R::peerRespond($sw, $ravi, true);
                $R::decide($sw, true, 'OK – cover confirmed', $admin);                     // approved
            }
        } catch (\RuntimeException $ex) {
            // swaps are demo sugar; never fail the seed on a calendar edge case
        }

        // ---------- tips & service charge ----------
        $mode = fn ($like) => Models\PaymentMode::where('name', 'like', $like.'%')->value('id');
        [$cash, $card, $momo] = [$mode('Cash'), $mode('Credit'), $mode('Mobile')];
        auth()->setUser($fnb ?? $admin);
        $cut = \App\Services\LedgerService::cutoff();
        $srcs = [['card_tips', $card, 'POS card tips batch'], ['cash_box', $cash, 'Tips box count'], ['momo_tips', $momo, 'MoMo tips (merchant wallet)']];
        $k = 0;
        for ($d = Carbon::parse('2026-08-02'); $d->toDateString() <= $cut; $d->addDays(3)) {
            [$src, $m, $ref] = $srcs[$k % 3];
            $amt = 180 + (crc32('tip'.$d->toDateString()) % 520) + ($src === 'card_tips' ? 150 : 0);
            \App\Services\ServiceChargeService::addTip(['date' => $d->toDateString(), 'source' => $src, 'payment_mode_id' => $m, 'amount' => $amt, 'reference' => $ref.' '.$d->format('d/m')], $fnb ?? $admin);
            $k++;
        }
        \App\Services\ServiceChargeService::addTip(['date' => '2026-08-22', 'source' => 'banquet', 'payment_mode_id' => $cash, 'amount' => 1500, 'reference' => 'Asante wedding – gratuity', 'notes' => 'Handed in by the banquet captain after the event'], $fnb ?? $admin);
        $bad = \App\Services\ServiceChargeService::addTip(['date' => '2026-09-10', 'source' => 'cash_box', 'payment_mode_id' => $cash, 'amount' => 420, 'reference' => 'Tips box count 10/09'], $fnb ?? $admin);
        \App\Services\ServiceChargeService::cancelTip($bad, 'Duplicate – already counted in the 09/09 box count');
        \App\Services\LedgerService::sync();
        auth()->setUser($hr);
        $dist = \App\Services\ServiceChargeService::create(['period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'basis' => 'points_days', 'notes' => 'August tronc'], $hr);
        auth()->setUser($acct);
        \App\Services\ServiceChargeService::approve($dist, $acct);

        // attendance changed for the approved days → refresh the draft payroll so payslips match
        auth()->setUser($hr);
        if ($run = Models\PayrollRun::where('status', 'draft')->orderByDesc('period_start')->first()) {
            \App\Services\PayrollService::generate($run, false);
        }
        \App\Services\LedgerService::sync();
        $prevUser ? auth()->setUser($prevUser) : Auth::forgetUser();
    }

    protected function seedTalent(bool $rich): void
    {
        $TS = \App\Services\TalentService::class;
        $TS::ensureDefaults();
        if (! $rich) {
            return;
        }
        Models\Setting::updateOrCreate(['key' => 'talent_statement_from'], ['value' => '2023-01-01']);   // HR caught up on every current employee
        \App\Models\Setting::$version++;
        $TS::flush();
        $hid = \App\Support\Tenant::id();
        $u = fn (string $n) => Models\User::where('hotel_id', $hid)->where('username', $n)->first();
        $admin = $u('admin');
        $hr = $u('hr') ?? $admin;
        $fom = $u('fom') ?? $admin;
        $hk = $u('housekeeping') ?? $admin;
        $fb = $u('restaurant') ?? $admin;
        $spa = $u('spa') ?? $admin;
        $sec = $u('security') ?? $admin;
        $eng = $u('engineer') ?? $admin;
        $B = bdate()->copy()->startOfDay();
        $d = fn (int $n) => $B->copy()->addDays($n)->toDateString();
        $E = fn (string $name) => Models\Employee::where('name', $name)->first();
        $dept = fn (string $name) => Models\Department::where('name', $name)->value('id');

        // ---- establishment, line managers, contracts, statements, probation
        foreach (['Front Office' => 4, 'Housekeeping' => 5, 'F&B Service' => 4, 'Kitchen' => 3, 'Maintenance' => 2, 'Accounts' => 2, 'Security' => 2, 'Management' => 2, 'Spa & Wellness' => 3] as $n => $x) {
            Models\Department::where('name', $n)->update(['establishment' => $x]);
        }
        $mgr = ['Ramesh Gowda' => $hk, 'Lata Bai' => $hk, 'Abena Owusu' => $hk, 'Joseph Thomas' => $admin, 'Imran Ali' => $eng, 'Deepa Nair' => $fom, 'Ravi Kumar' => $admin,
            'Mohan Lal' => $fb, 'Sunil Yadav' => $fb, 'Kiran Patil' => $sec, 'Grace Mensah' => $admin, 'Efua Asante' => $spa, 'Kofi Boateng' => $spa, 'Meera Iyer' => $admin, 'Anjali Menon' => $admin];
        foreach (Models\Employee::where('is_active', true)->get() as $e) {
            $j = $e->joining_date->copy();
            $late = in_array($e->name, ['Abena Owusu', 'Kofi Boateng'], true);
            $e->update(['manager_user_id' => $mgr[$e->name]?->id, 'statement_issued_on' => $late ? null : $j->copy()->addDays(20)->toDateString(),
                'probation_end' => $j->copy()->addMonthsNoOverflow(6)->subDay()->toDateString(),
                'confirmed_on' => $late ? null : $j->copy()->addMonthsNoOverflow(6)->subDays(3)->toDateString()]);
        }
        $E('Kiran Patil')->update(['employment_type' => 'fixed_term', 'contract_end' => '2026-12-31']);
        foreach (['Kiran Patil', 'Efua Asante'] as $n) {
            $e = $E($n);
            Models\TalentProbation::create(['employee_id' => $e->id, 'due_on' => $e->probation_end, 'reviewed_on' => $e->confirmed_on, 'outcome' => 'confirm', 'rating' => 4,
                'comments' => 'Reliable, settled in well.', 'reviewed_by' => $hr->id]);
        }

        // ---- former employees (left before the first seeded payroll month)
        $yaw = Models\Employee::create(['department_id' => $dept('F&B Service'), 'emp_code' => 'EMP016', 'name' => 'Yaw Mensah', 'designation' => 'Waiter', 'phone' => '0244000116',
            'joining_date' => '2024-02-01', 'leaving_date' => '2026-04-30', 'salary' => 2600, 'is_active' => false, 'sc_eligible' => false, 'ot_eligible' => true,
            'statement_issued_on' => '2024-02-15', 'probation_end' => '2024-07-31', 'confirmed_on' => '2024-07-29', 'manager_user_id' => $fb->id]);
        $kwesi = Models\Employee::create(['department_id' => $dept('Kitchen'), 'emp_code' => 'EMP017', 'name' => 'Kwesi Appiah', 'designation' => 'Commis Chef', 'phone' => '0244000117',
            'joining_date' => '2025-06-02', 'leaving_date' => '2026-06-12', 'salary' => 2700, 'is_active' => false, 'sc_eligible' => false, 'ot_eligible' => true,
            'statement_issued_on' => '2025-06-20', 'probation_end' => '2025-12-01', 'confirmed_on' => '2025-11-28', 'manager_user_id' => $fb->id]);
        $clr = fn (string $by, string $at) => collect($TS::CLEARANCE)->map(fn () => ['done' => true, 'by' => $by, 'at' => $at, 'note' => null])->all();
        Models\TalentExit::create(['number' => \App\Support\Numbering::next('talent_exit'), 'employee_id' => $yaw->id, 'type' => 'resignation', 'notice_on' => '2026-04-01', 'notice_days' => 14,
            'notice_basis' => 'Under three years\' service – two weeks (s. 17(1)(a))', 'last_day' => '2026-04-30', 'final_pay_due' => '2026-04-30', 'reason' => 'Joining a resort in Takoradi as head waiter.',
            'status' => 'completed', 'regretted' => true, 'rehire' => true, 'clearance' => $clr($hr->name, '2026-04-30 16:00:00'), 'created_by' => $hr->id, 'completed_by' => $hr->id,
            'completed_at' => '2026-04-30 17:00:00', 'interview' => ['primary' => 'career', 'satisfaction' => 4, 'recommend' => true, 'liked' => 'The team and the training.',
                'improve' => 'A clearer path to supervisor.', 'by' => $hr->name, 'on' => '2026-04-29']]);
        $dc = Models\TalentCase::create(['number' => \App\Support\Numbering::next('talent_case'), 'kind' => 'discipline', 'employee_id' => $kwesi->id, 'category' => 'theft', 'severity' => 'gross',
            'incident_on' => '2026-06-02', 'description' => 'Seen on CCTV leaving the kitchen store with two packs of chicken (5 kg) in a backpack at the end of the night shift; the packs were recovered at the staff gate.',
            'status' => 'closed', 'query_issued_on' => '2026-06-03', 'reply_due' => '2026-06-06', 'reply' => 'I was taking the chicken to the staff canteen fridge.', 'replied_on' => '2026-06-05',
            'hearing_at' => '2026-06-10 10:00:00', 'panel' => 'Anjali Menon (HR), Arjun Menon (F&B Manager)', 'representative' => 'Kwabena Tetteh (colleague)',
            'hearing_notes' => 'The canteen has no fridge on that route; the gate guard found the packs inside a personal bag. The explanation was not accepted.', 'hearing_held' => true,
            'finding' => 'proven', 'outcome' => 'dismissal', 'outcome_on' => '2026-06-12', 'raised_by' => $fb->id, 'decided_by' => $admin->id, 'closed_at' => '2026-06-15 12:00:00',
            'resolution' => 'Summary dismissal for gross misconduct (theft of hotel property).']);
        Models\TalentExit::create(['number' => \App\Support\Numbering::next('talent_exit'), 'employee_id' => $kwesi->id, 'type' => 'dismissal', 'notice_on' => '2026-06-12', 'notice_days' => 0,
            'notice_basis' => 'Summary dismissal for gross misconduct', 'last_day' => '2026-06-12', 'final_pay_due' => '2026-06-15', 'case_id' => $dc->id, 'fair_confirmed' => true,
            'status' => 'completed', 'regretted' => false, 'rehire' => false, 'clearance' => $clr($hr->name, '2026-06-15 11:00:00'), 'created_by' => $admin->id, 'completed_by' => $hr->id,
            'completed_at' => '2026-06-15 11:30:00', 'reason' => 'Theft of hotel property (DC case).']);

        // ---- Fitness Instructor: filled by Kofi Boateng (built directly – the offer pre-dates today)
        $kofi = $E('Kofi Boateng');
        $fit = Models\TalentVacancy::create(['number' => \App\Support\Numbering::next('talent_vacancy'), 'department_id' => $dept('Spa & Wellness'), 'title' => 'Fitness Instructor', 'headcount' => 1,
            'filled' => 1, 'employment_type' => 'permanent', 'salary_min' => 3200, 'salary_max' => 3800, 'reason' => 'new', 'justification' => 'Health-club membership has doubled; classes need a qualified instructor.',
            'description' => 'Run the gym floor, group classes and personal-training sessions for members and in-house guests.', 'requirements' => 'Recognised fitness certification; first-aid certificate; 2+ years in a gym.',
            'is_public' => true, 'status' => 'filled', 'requested_by' => $hr->id, 'approved_by' => $admin->id, 'approved_at' => $B->copy()->subDays(80)->setTime(11, 0), 'filled_on' => '2026-07-28']);
        $mk = function (array $a) use ($fit) {
            return Models\TalentApplicant::create($a + ['number' => \App\Support\Numbering::next('talent_applicant'), 'vacancy_id' => $fit->id, 'consent_at' => now()]);
        };
        $ak = $mk(['name' => 'Kofi Boateng', 'phone' => '0244100212', 'email' => 'kofi.boateng@example.com', 'source' => 'careers', 'experience_years' => 4, 'expected_salary' => 3600,
            'stage' => 'hired', 'screen_rating' => 5, 'hired_employee_id' => $kofi->id, 'stage_changed_at' => '2026-07-28 12:00:00']);
        $mk(['name' => 'Sena Agbeko', 'phone' => '0244100213', 'source' => 'job_board', 'experience_years' => 1, 'stage' => 'rejected', 'reject_reason' => 'Certification still in progress', 'stage_changed_at' => '2026-07-20 10:00:00']);
        $mk(['name' => 'Fiifi Quansah', 'phone' => '0244100214', 'source' => 'walk_in', 'experience_years' => 3, 'stage' => 'rejected', 'reject_reason' => 'Position filled', 'stage_changed_at' => '2026-07-28 12:00:00']);
        $iv = Models\TalentInterview::create(['applicant_id' => $ak->id, 'round' => 1, 'kind' => 'trade_test', 'scheduled_at' => '2026-07-22 10:00:00', 'location' => 'Health club', 'panel' => [$spa->id, $hr->id], 'status' => 'done', 'created_by' => $hr->id]);
        foreach ([[$spa, [5, 5, 5, 4, 5], 'hire'], [$hr, [4, 5, 4, 4, 4], 'hire']] as [$who, $sc, $rec]) {
            Models\TalentScorecard::create(['interview_id' => $iv->id, 'user_id' => $who->id, 'scores' => array_combine(array_keys($TS::CRITERIA), $sc), 'average' => round(array_sum($sc) / 5, 2), 'recommendation' => $rec]);
        }
        Models\TalentOffer::create(['number' => \App\Support\Numbering::next('talent_offer'), 'applicant_id' => $ak->id, 'vacancy_id' => $fit->id, 'salary' => 3600, 'start_date' => '2026-08-18', 'probation_months' => 6,
            'employment_type' => 'permanent', 'status' => 'accepted', 'respond_by' => '2026-08-01', 'responded_at' => '2026-07-28 12:00:00', 'created_by' => $hr->id]);
        $TS::createOnboarding($kofi->fresh());
        foreach (['contract', 'uniform', 'induction'] as $k) {
            Models\TalentTask::where('employee_id', $kofi->id)->where('item', $k)->update(['done_at' => '2026-08-19 10:00:00', 'done_by' => $hr->id]);
        }
        $abena = $E('Abena Owusu');
        $TS::createOnboarding($abena);
        Models\TalentTask::where('employee_id', $abena->id)->where('item', '!=', 'statement')->update(['done_at' => '2026-03-20 10:00:00', 'done_by' => $hr->id]);

        // ---- Guest Service Agent ×2: open, full pipeline (through the service)
        Auth::setUser($fom);
        $gsa = $TS::saveVacancy(['department_id' => $dept('Front Office'), 'title' => 'Guest Service Agent', 'headcount' => 2, 'employment_type' => 'permanent', 'salary_min' => 3200, 'salary_max' => 3800,
            'reason' => 'new', 'closing_on' => $d(14), 'is_public' => 1, 'justification' => 'Occupancy up 12 points year on year; the desk runs one agent short on the evening shift.',
            'description' => "Welcome guests, check them in and out, handle bills and payments and look after requests at the front desk on a rotating shift.",
            'requirements' => "SHS or HND (hospitality preferred); 1+ year in a guest-facing role; good English (French a plus); comfortable with a PMS and mobile money."], null, $fom);
        $TS::submitVacancy($gsa, $fom);
        Auth::setUser($admin);
        $TS::approveVacancy($gsa, $admin, 'Two posts approved within the front-office establishment.');
        $gsa->update(['approved_at' => $B->copy()->subDays(20)->setTime(9, 30)]);
        Auth::setUser($hr);
        $deepa = $E('Deepa Nair');
        $apps = [];
        foreach ([
            ['Ama Serwaa', '0244200301', 'ama.serwaa@example.com', 'careers', 3, 3500, 'Front desk at a 60-room hotel in Osu for three years.'],
            ['Kwame Owusu', '0244200302', 'kwame.owusu@example.com', 'referral', 5, 4200, 'Night auditor and receptionist; referred by Deepa Nair.'],
            ['Esi Mensah', '0244200303', 'esi.mensah@example.com', 'job_board', 2, 3300, null],
            ['Nana Adjei', '0244200304', null, 'careers', 1.5, 3200, null],
            ['Akua Boakye', '0244200305', 'akua.b@example.com', 'agency', 2, 3400, null],
            ['Yaw Darko', '0244200306', null, 'walk_in', 0.5, 3000, 'Dropped a CV at the front desk.'],
            ['Efo Kpodo', '0244200307', 'efo.kpodo@example.com', 'careers', 0, 3200, null],
            ['Adwoa Sarpong', '0244200308', 'adwoa.s@example.com', 'job_board', 0, 3500, null],
            ['Kojo Asamoah', '0244200309', 'kojo.asamoah@example.com', 'careers', 4, 3800, null],
        ] as $i => [$n, $ph, $em, $src, $exp, $sal, $note]) {
            $apps[$n] = $TS::addApplicant($gsa, ['name' => $n, 'phone' => $ph, 'email' => $em, 'source' => $src, 'referred_by_employee_id' => $src === 'referral' ? $deepa->id : null,
                'experience_years' => $exp, 'expected_salary' => $sal, 'cover_note' => $note, 'consent' => 1], null, $hr);
            $apps[$n]->forceFill(['created_at' => $B->copy()->subDays(18 - $i)->setTime(9 + $i % 6, 15), 'consent_at' => $B->copy()->subDays(18 - $i)->setTime(9 + $i % 6, 15), 'stage_changed_at' => $B->copy()->subDays(18 - $i)->setTime(9 + $i % 6, 15)])->saveQuietly();
        }
        foreach (['Ama Serwaa' => 5, 'Kwame Owusu' => 4, 'Esi Mensah' => 4, 'Nana Adjei' => 3, 'Efo Kpodo' => 2] as $n => $r) {
            $TS::moveApplicant($apps[$n], 'screening', $hr, null, $r);
        }
        $TS::moveApplicant($apps['Efo Kpodo'], 'rejected', $hr, 'Insufficient guest-facing experience for the role');
        $TS::moveApplicant($apps['Adwoa Sarpong'], 'rejected', $hr, 'No customer-service experience');
        $TS::moveApplicant($apps['Kojo Asamoah'], 'withdrawn', $hr, 'Accepted another offer');
        $crit = array_keys($TS::CRITERIA);
        foreach ([['Ama Serwaa', -6, [[$fom, [5, 5, 4, 4, 5], 'hire'], [$hr, [4, 5, 4, 4, 4], 'hire']]], ['Kwame Owusu', -5, [[$fom, [4, 4, 5, 5, 4], 'hire'], [$hr, [4, 3, 4, 5, 3], 'hold']]]] as [$n, $day, $cards]) {
            $i = $TS::scheduleInterview($apps[$n], ['kind' => 'in_person', 'scheduled_at' => $B->copy()->addDays($day)->setTime(10, 0)->toDateTimeString(), 'location' => 'Front office meeting room', 'panel' => [$fom->id, $hr->id]], $hr);
            foreach ($cards as [$who, $sc, $rec]) {
                $TS::score($i, ['scores' => array_combine($crit, $sc), 'recommendation' => $rec, 'comments' => $rec === 'hire' ? 'Warm, confident, knows the check-in routine.' : 'Good experience; a little flat with guests.'], $who);
            }
        }
        $TS::scheduleInterview($apps['Esi Mensah'], ['kind' => 'in_person', 'scheduled_at' => $B->copy()->addDays(2)->setTime(11, 0)->toDateTimeString(), 'location' => 'Front office meeting room', 'panel' => [$fom->id, $hr->id, $admin->id]], $hr);
        $TS::makeOffer($apps['Ama Serwaa'], ['salary' => 3500, 'start_date' => $d(10), 'probation_months' => 6, 'notes' => 'Subject to two satisfactory references.'], $hr);
        $TS::makeOffer($apps['Kwame Owusu'], ['salary' => 4000, 'start_date' => $d(14), 'probation_months' => 6, 'notes' => 'Above range – five years\' experience incl. night audit; covers the evening shift lead.'], $hr);

        // ---- Room Attendant (pending, replaces Lata), Night Auditor (cancelled), Commis Chef (draft)
        $lata = $E('Lata Bai');
        Auth::setUser($hk);
        $ra = $TS::saveVacancy(['department_id' => $dept('Housekeeping'), 'title' => 'Room Attendant', 'headcount' => 1, 'employment_type' => 'permanent', 'salary_min' => 2400, 'salary_max' => 2600,
            'reason' => 'replacement', 'replaces_employee_id' => $lata->id, 'is_public' => 1, 'justification' => 'Lata Bai resigned (last day next month); 16 rooms per attendant is already at the limit.',
            'description' => 'Clean and prepare guest rooms and public areas to the hotel standard.', 'requirements' => 'Previous hotel housekeeping experience preferred.'], null, $hk);
        $TS::submitVacancy($ra, $hk);
        Auth::setUser($hr);
        $na = $TS::saveVacancy(['department_id' => $dept('Accounts'), 'title' => 'Night Auditor', 'headcount' => 1, 'employment_type' => 'permanent', 'salary_min' => 3500, 'salary_max' => 4200,
            'reason' => 'new', 'is_public' => 0, 'justification' => 'Night audit currently done by the duty manager.'], null, $hr);
        $TS::submitVacancy($na, $hr);
        $TS::approveVacancy($na, $admin);
        $TS::setVacancyStatus($na, 'cancelled', $hr, 'Covered by training a front-office agent in night audit instead');
        Auth::setUser($fb);
        $TS::saveVacancy(['department_id' => $dept('Kitchen'), 'title' => 'Commis Chef', 'headcount' => 1, 'employment_type' => 'fixed_term', 'contract_months' => 12, 'salary_min' => 2700, 'salary_max' => 3000,
            'reason' => 'replacement', 'replaces_employee_id' => $kwesi->id, 'is_public' => 1, 'justification' => 'Replaces Kwesi Appiah (dismissed June).'], null, $fb);

        // ---- discipline & grievances
        Auth::setUser($hr);
        $mkCase = function (array $a) use ($TS) {
            return Models\TalentCase::create($a + ['number' => \App\Support\Numbering::next($a['kind'] === 'grievance' ? 'talent_grievance' : 'talent_case')]);
        };
        $imran = $E('Imran Ali');
        $mkCase(['kind' => 'discipline', 'employee_id' => $imran->id, 'category' => 'negligence', 'severity' => 'major', 'incident_on' => '2025-06-02',
            'description' => 'Left a leaking valve in room 204 unrepaired at the end of shift; the ceiling of 104 below was damaged.', 'status' => 'closed', 'query_issued_on' => '2025-06-03',
            'reply_due' => '2025-06-06', 'reply' => 'I was called to the kitchen emergency and forgot to hand over.', 'replied_on' => '2025-06-04', 'finding' => 'proven', 'outcome' => 'written',
            'outcome_on' => '2025-06-10', 'expires_on' => '2026-06-10', 'raised_by' => $eng->id, 'decided_by' => $hr->id, 'closed_at' => '2025-06-12 10:00:00']);
        $sunil = $E('Sunil Yadav');
        $mkCase(['kind' => 'discipline', 'employee_id' => $sunil->id, 'category' => 'lateness', 'severity' => 'minor', 'incident_on' => $d(-68),
            'description' => 'Late for the breakfast shift five times in two weeks (15–45 minutes) without calling in.', 'status' => 'closed', 'query_issued_on' => $d(-66), 'reply_due' => $d(-63),
            'reply' => 'Transport problems from Kasoa; I will take the earlier trotro.', 'replied_on' => $d(-65), 'finding' => 'proven', 'outcome' => 'verbal', 'outcome_on' => $d(-60),
            'expires_on' => $B->copy()->subDays(60)->addMonthsNoOverflow(6)->toDateString(), 'raised_by' => $fb->id, 'decided_by' => $hr->id, 'closed_at' => $B->copy()->subDays(59)->setTime(10, 0)]);
        $kiran = $E('Kiran Patil');
        $kc = $TS::raiseCase(['kind' => 'discipline', 'employee_id' => $kiran->id, 'category' => 'sleeping', 'severity' => 'major', 'incident_on' => $d(-5),
            'description' => 'Found asleep in the car-park guard hut at 03:10 by the night manager; the barrier was left raised and unattended for about 40 minutes.'], $sec);
        $TS::issueQuery($kc, ['reply_due' => $d(3)], $hr);
        $kc->update(['query_issued_on' => $d(-4), 'reply_due' => $d(-1)]);
        $TS::raiseCase(['kind' => 'grievance', 'employee_id' => $E('Efua Asante')->id, 'category' => 'hours', 'incident_on' => $d(-9), 'confidential' => 1,
            'description' => 'Rostered for split shifts six days running with less than 10 hours between the late and early sessions; asks for a fair rotation.'], $hr);
        Models\TalentCase::where('kind', 'grievance')->latest('id')->first()->update(['status' => 'investigating']);

        // ---- Lata resigns: working one month's notice, clearance under way
        $x = $TS::startExit($lata, ['type' => 'resignation', 'notice_on' => $d(-5), 'reason' => 'Relocating to Kumasi with her family.'], $hr);
        $TS::updateClearance($x, 'handover', true, 'Floor 2 section handed to Ramesh', $hr);
        $TS::saveInterview($x, ['primary' => 'relocation', 'satisfaction' => 4, 'recommend' => 1, 'liked' => 'Supportive supervisor.', 'improve' => 'Transport for early shifts.', 'rehire' => 1], $hr);

        // ---- 2026 mid-year appraisals at every stage
        $cyc = $TS::createCycle(['name' => '2026 Mid-year review', 'period_from' => '2026-01-01', 'period_to' => '2026-06-30', 'self_due' => $d(-20), 'review_due' => $d(10),
            'goals' => "Guest satisfaction in my area\nAttendance & punctuality\nDepartmental standards (SOP / QA audits)"], $hr);
        $plan = [ // name => [self ratings (or null), manager goal ratings, manager competency ratings, target stage]
            'Joseph Thomas' => [[4, 5, 4], [5, 5, 4], [4, 5, 5, 5, 4, 5], 'signed'], 'Deepa Nair' => [null, [4, 4, 4], [5, 4, 4, 4, 5, 3], 'signed'],
            'Mohan Lal' => [null, [5, 4, 5], [5, 4, 5, 5, 5, 4], 'signed'], 'Grace Mensah' => [[4, 4, 4], [4, 4, 5], [5, 4, 4, 4, 5, 4], 'signed'],
            'Meera Iyer' => [[4, 4, 3], [3, 4, 4], [3, 4, 4, 4, 3, 3], 'signed'], 'Efua Asante' => [null, [3, 3, 3], [4, 3, 3, 3, 4, 3], 'signed'],
            'Ravi Kumar' => [[4, 4, 4], [4, 3, 3], [4, 3, 4, 3, 4, 3], 'hr'], 'Sunil Yadav' => [null, [2, 1, 2], [3, 2, 2, 1, 3, 2], 'hr'], 'Imran Ali' => [null, [3, 2, 3], [3, 3, 3, 2, 3, 2], 'hr'],
            'Anjali Menon' => [null, null, null, 'self'], 'Ramesh Gowda' => [null, null, null, 'self'],
        ];
        $compKeys = array_keys($TS::COMPETENCIES);
        foreach ($plan as $name => [$self, $mg, $mc, $stage]) {
            $a = Models\TalentAppraisal::where('cycle_id', $cyc->id)->where('employee_id', $E($name)->id)->first();
            if ($self) {
                $TS::saveSelf($a->fresh(), ['goal_self' => $self, 'comp_self' => array_combine($compKeys, array_fill(0, 6, 4)), 'self_comments' => 'I met most of my targets this half.'], $a->employee->user, true);
            }
            if ($mg) {
                $a = $a->fresh();
                $rev = $a->reviewer;
                $TS::saveReview($a, ['goal_title' => array_column($a->goals, 'title'), 'goal_weight' => array_column($a->goals, 'weight'), 'goal_mgr' => $mg, 'comp_mgr' => array_combine($compKeys, $mc),
                    'mgr_comments' => $mg[0] >= 4 ? 'Strong half-year – a role model for the team.' : ($mg[0] <= 2 ? 'Punctuality and standards are below what the role needs; a performance improvement plan is agreed.' : 'Solid, dependable contribution.'),
                    'development' => $mg[0] <= 2 ? 'Weekly check-ins with the F&B Manager; SOP refresher on table service.' : 'Supervisory skills course in Q4.'], $rev, true);
                if ($stage === 'signed') {
                    $TS::signOff($a->fresh(), $hr, true);
                }
            }
        }
        foreach (Models\TalentAppraisal::where('cycle_id', $cyc->id)->where('status', 'signed')->get() as $i => $a) {
            $a->update(['self_at' => $a->self_at ? $B->copy()->subDays(24)->setTime(10, 0) : null, 'reviewed_at' => $B->copy()->subDays(12 - $i)->setTime(15, 0), 'signed_at' => $B->copy()->subDays(6 - min(5, $i))->setTime(11, 0),
                'acknowledged_at' => $i < 3 ? $B->copy()->subDays(3)->setTime(12, 0) : null]);
        }
        $TS::flush();
        Auth::forgetUser();
    }

    protected function seedAttendance($emps, bool $rich, Carbon $today): void
    {
        $from = $rich ? Carbon::parse('2026-07-01') : $today->copy()->subDays(6);
        $rows = [];
        foreach ($emps as $i => $e) {
            for ($d = $from->copy(); $d->lte($today); $d->addDay()) {
                if ($e->joining_date && $d->lt($e->joining_date)) {
                    continue;
                }
                $h = crc32($e->id.'|'.$d->toDateString()) % 100;
                [$status, $in, $out] = match (true) {
                    $d->dayOfWeek === ($i % 7) => ['WO', null, null],
                    $h < 3 => ['A', null, null],
                    $e->ot_eligible && $h > 90 => ['P', '09:00', $h > 96 ? '21:00' : '20:00'], // 2–3 h overtime
                    default => ['P', '09:00', '18:00'],
                };
                $rows[] = ['hotel_id' => $e->hotel_id, 'employee_id' => $e->id, 'date' => $d->toDateString(), 'status' => $status, 'in_time' => $in, 'out_time' => $out, 'created_at' => now(), 'updated_at' => now()];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            Models\Attendance::insert($chunk);
        }
    }

    protected function seedPayroll(Models\Hotel $hotel, $emps, bool $rich, Carbon $today, Models\User $user): void
    {
        \App\Services\PayrollService::ensureDefaults();
        Models\Setting::updateOrCreate(['key' => 'payroll_segregation'], ['value' => '1']);
        if (! $rich) {
            return;
        }
        $hr = Models\User::where('username', 'hr')->first() ?? $user;
        $acc = Models\User::where('username', 'accounts')->first() ?? $user;
        $e = fn ($n) => $emps->firstWhere('name', $n);
        $t = Models\LeaveType::pluck('id', 'code')->map(fn ($id) => Models\LeaveType::find($id));
        $leave = function ($name, $code, $from, $to, $half, $reason, $decision = null, $note = null) use ($e, $t, $hr) {
            Auth::setUser($hr);
            $r = \App\Services\LeaveService::request($e($name), $t[$code], $from, $to, $half, $reason, $hr);
            match ($decision) {
                'approve' => \App\Services\LeaveService::approve($r, $note),
                'reject' => \App\Services\LeaveService::reject($r, $note),
                default => null,
            };

            return $r;
        };
        $leave('Lata Bai', 'EL', '2026-07-14', '2026-07-16', false, 'Family function in the village', 'approve');
        $leave('Sunil Yadav', 'LWP', '2026-07-22', '2026-07-22', false, 'Personal work', 'approve');
        $leave('Deepa Nair', 'SL', '2026-08-05', '2026-08-06', false, 'Fever – medical certificate submitted', 'approve');
        $leave('Joseph Thomas', 'CL', '2026-08-12', '2026-08-12', true, 'Bank work (afternoon)', 'approve');
        $leave('Imran Ali', 'CL', '2026-09-08', '2026-09-08', false, 'Child\'s school meeting', 'approve');
        $leave('Efua Asante', 'CL', '2026-09-19', '2026-09-19', false, 'Friend\'s wedding', 'reject', 'Peak weekend – spa fully booked. Please pick another date.');
        $leave('Kiran Patil', 'SL', $today->copy()->addDays(3)->toDateString(), $today->copy()->addDays(4)->toDateString(), false, 'Minor surgery scheduled');
        $leave('Abena Owusu', 'EL', $today->copy()->addDays(9)->toDateString(), $today->copy()->addDays(13)->toDateString(), false, 'Travelling home to Kumasi');
        $leave('Ramesh Gowda', 'CL', $today->copy()->addDays(6)->toDateString(), $today->copy()->addDays(6)->toDateString(), false, 'Festival at home');

        Auth::setUser($hr);
        $bank = Models\PaymentMode::where('type', 'bank')->value('id');
        $cash = Models\PaymentMode::where('type', 'cash')->value('id');
        \App\Services\PayrollService::giveAdvance(['employee_id' => $e('Sunil Yadav')->id, 'date' => '2026-06-20', 'amount' => 1500, 'installment' => 500, 'payment_mode_id' => $bank, 'reason' => 'Medical expenses (mother)']);
        \App\Services\PayrollService::giveAdvance(['employee_id' => $e('Kiran Patil')->id, 'date' => $today->copy()->subDays(16)->toDateString(), 'amount' => 600, 'installment' => 300, 'payment_mode_id' => $cash, 'reason' => 'House rent deposit']);

        foreach (['2026-07-01' => 'paid', '2026-08-01' => 'approved', $today->copy()->startOfMonth()->toDateString() => 'draft'] as $month => $state) {
            Auth::setUser($hr);
            $run = \App\Services\PayrollService::createRun(Carbon::parse($month));
            if ($month === '2026-08-01') {
                \App\Services\PayrollService::addAdjustment(Models\Payslip::where('payroll_run_id', $run->id)->where('employee_id', $e('Deepa Nair')->id)->first(), ['kind' => 'earning', 'name' => 'Guest-review incentive', 'amount' => 300, 'taxable' => true]);
                \App\Services\PayrollService::addAdjustment(Models\Payslip::where('payroll_run_id', $run->id)->where('employee_id', $e('Sunil Yadav')->id)->first(), ['kind' => 'deduction', 'name' => 'Broken glassware recovery', 'amount' => 45]);
            }
            if ($state === 'draft') {
                continue;
            }
            Auth::setUser($acc);
            \App\Services\PayrollService::approve($run);
            if ($state === 'paid') {
                \App\Services\PayrollService::pay($run, Carbon::parse($month)->endOfMonth()->addDay()->toDateString());
            }
        }
        Auth::setUser($user);
    }

    protected function seedLearning(bool $rich): void
    {
        $LS = \App\Services\LearningService::class;
        $LS::ensureDefaults();
        if (! $rich) {
            return;
        }
        $prevUser = Auth::user();
        $u = fn (string $n) => Models\User::where('username', $n)->first();
        $hr = $u('hr') ?? $u('admin');
        Auth::setUser($hr);
        \App\Models\Setting::updateOrCreate(['key' => 'learning_notify'], ['value' => '0']);   // no SMS while seeding
        \App\Models\Setting::$version++;
        $B = bdate()->copy()->startOfDay();
        $d = fn (int $n) => $B->copy()->addDays($n)->toDateString();
        $E = Models\Employee::where('is_active', true)->get()->keyBy('name');
        $e = fn (string $n) => $E[$n] ?? null;
        $dept = fn (string $n) => Models\Department::where('name', $n)->value('id');

        // ── SOP library
        $mk = function (array $a, array $versions, ?string $draft = null) use ($hr, $B) {
            $last = end($versions);
            $doc = Models\SopDocument::create(array_diff_key($a, ['reviewed' => 1]) + ['body' => $draft ?? $last['body'], 'title' => $a['title'], 'owner_id' => $a['owner_id'] ?? $hr->id, 'created_by' => $hr->id, 'status' => 'draft', 'has_draft' => true]);
            foreach ($versions as $i => $v) {
                Models\SopVersion::create(['sop_document_id' => $doc->id, 'version' => $i + 1, 'title' => $a['title'], 'body' => $v['body'], 'change_note' => $v['note'] ?? ($i ? 'Updated' : 'First issue'),
                    'reack' => $v['reack'] ?? true, 'effective_date' => $B->copy()->addDays($v['on'])->toDateString(), 'published_by' => $hr->id, 'published_at' => $B->copy()->addDays($v['on'])->setTime(10, 0)]);
            }
            if ($versions) {
                $reviewed = $B->copy()->addDays($a['reviewed'] ?? $last['on']);
                $doc->forceFill(['version' => count($versions), 'status' => $a['status'] ?? 'published', 'has_draft' => $draft !== null, 'effective_date' => $B->copy()->addDays($last['on'])->toDateString(),
                    'last_reviewed' => $reviewed->toDateString(), 'review_due' => $reviewed->copy()->addMonthsNoOverflow($a['review_months'] ?? 12)->toDateString()])->save();
            }

            return $doc;
        };
        $fireV1 = "## Purpose\nKeep guests and staff safe if a fire breaks out.\n\n## On discovering a fire\n1. Break the nearest **manual call point** to raise the alarm.\n2. Call the switchboard on **0** and the Ghana National Fire Service on **192** (or 112).\n3. Only use an extinguisher if you are trained and the fire is small – never put yourself at risk.\n\n## On hearing the alarm\n1. Stop work, switch off equipment, close doors behind you.\n2. Guide guests to the nearest exit – **never use the lifts**.\n3. Go to the **assembly point: front car park, by the flag poles**.";
        $fireV2 = $fireV1."\n4. Department heads take the **roll call** with the duty roster and the in-house guest list printed from the PMS (*Front Office → In-house*).\n5. Nobody re-enters the building until the Fire Service officer in charge says it is safe.\n\n## Guests who need help\n- Front Office prints the *assisted evacuation* list (guests with mobility or hearing needs) and hands it to the fire marshals.\n- Fire marshals check their floors, then report to the duty manager.\n\n> Fire drills are held every six months. Every employee attends the annual fire-safety training (course **FIRE**).";
        $fire = $mk(['code' => 'SOP-GEN-001', 'title' => 'Fire emergency & evacuation', 'category' => 'emergency', 'summary' => 'What every employee does when the fire alarm sounds – raising the alarm, evacuation, assembly and roll call.', 'requires_ack' => true, 'review_months' => 12],
            [['body' => $fireV1, 'on' => -560], ['body' => $fireV2, 'on' => -200, 'note' => 'Roll call from the PMS in-house list, assisted-evacuation list added', 'reack' => true]]);
        $checkin = $mk(['code' => 'SOP-FO-001', 'title' => 'Guest check-in & identity verification', 'category' => 'guest_service', 'department_id' => $dept('Front Office'), 'summary' => 'Welcome, identity checks (Ghana Card / passport), registration card, payment guarantee and key issue.', 'requires_ack' => true, 'review_months' => 12],
            [['body' => "## Standard\nEvery guest is greeted within **30 seconds** with *\"Akwaaba, welcome to Nowills Grand\"*. Check-in takes no longer than **4 minutes**.\n\n## Steps\n1. Find the reservation in the PMS (name, confirmation number or phone).\n2. Ask for identification: **Ghana Card** for Ghanaian citizens, **passport with visa / entry stamp** for foreign nationals. Scan it to the guest profile.\n3. Foreign nationals: complete the **Form C / immigration** details before the key is issued.\n4. Confirm the room type, rate, meal plan and departure date. Print or e-sign the registration card.\n5. Take the payment guarantee: card pre-authorisation, Mobile Money deposit or approved company billing.\n6. Encode the key card, explain Wi-Fi, breakfast times and the guest portal QR code.\n7. Offer help with luggage and wish the guest a pleasant stay.\n\n## Never\n- Say the room number out loud – write it on the key wallet.\n- Issue a key without identification.", 'on' => -300]]);
        $cashV2 = "## Scope\nAll cashiers at Front Office, the restaurant and the spa.\n\n## During the shift\n1. Open your own shift in the PMS with the counted float.\n2. Every payment is entered at the time it is received – cash, card, Mobile Money (MTN MoMo, Telecel Cash, AirtelTigo Money).\n3. Never keep cash outside the drawer. Drops above **GH₵ 5,000** go to the safe with a drop envelope signed by a witness.\n4. Refunds and voids above **GH₵ 500** need the duty manager's approval in the PMS.\n\n## Closing the shift\n1. Count the drawer by denomination and enter the count **before** looking at the system total (blind count).\n2. Print the shift report and explain any difference over **GH₵ 20** in writing.\n3. Place the cash, the report and card / MoMo settlement slips in the sealed bag; drop it in the safe with a witness.";
        $cash = $mk(['code' => 'SOP-FO-002', 'title' => 'Cash handling & shift close', 'category' => 'finance', 'department_id' => $dept('Front Office'), 'summary' => 'Floats, payments, safe drops, refunds and the blind count at shift close.', 'requires_ack' => true,
            'ack_departments' => [$dept('Front Office'), $dept('Accounts')], 'review_months' => 12, 'reviewed' => -375],
            [['body' => str_replace(['GH₵ 5,000', 'GH₵ 500'], ['GH₵ 3,000', 'GH₵ 300'], $cashV2), 'on' => -700], ['body' => $cashV2, 'on' => -420, 'note' => 'Higher safe-drop and refund limits, blind count introduced', 'reack' => true],
             ['body' => str_replace('AirtelTigo Money', 'AT Money', $cashV2), 'on' => -375, 'note' => 'Mobile Money brand names updated', 'reack' => false]]);
        $room = $mk(['code' => 'SOP-HK-001', 'title' => 'Guest room cleaning standard', 'category' => 'operations', 'department_id' => $dept('Housekeeping'), 'summary' => 'The 12-step departure clean and the daily service clean, with the inspection standard.', 'requires_ack' => true, 'review_months' => 18],
            [['body' => "## Before you enter\nKnock three times and say *\"Housekeeping\"*. Wait 10 seconds. Never enter a room showing **Do Not Disturb** – report it to the supervisor after 14:00.\n\n## Departure clean (target 35 minutes)\n1. Open curtains and windows, switch on all lights and check them.\n2. Remove used linen, towels and rubbish; check for **lost property** and log it in the PMS.\n3. Strip and remake the bed with fresh linen – hospital corners, pillows zipped away from the door.\n4. Clean the bathroom top to bottom with the **blue** cloth and the approved sanitiser; the toilet with the **red** cloth only.\n5. Dust high to low, polish mirrors and glass, wipe the phone, remote and switches.\n6. Restock amenities, minibar (post consumption to the room in the PMS) and water.\n7. Vacuum from the far corner towards the door.\n8. Final check: AC at 24 °C, TV on the welcome channel, curtains half-drawn.\n9. Update the room status on your mobile to **Clean – to inspect**.", 'on' => -150]]);
        $lf = $mk(['code' => 'SOP-HK-002', 'title' => 'Lost & found handling', 'category' => 'operations', 'department_id' => $dept('Housekeeping'), 'summary' => 'Logging, storing, returning and disposing of items found on the premises.', 'requires_ack' => true,
            'ack_departments' => [$dept('Housekeeping'), $dept('Front Office'), $dept('Security')], 'review_months' => 24],
            [['body' => "1. Hand every found item to the housekeeping office **within one hour**; do not keep it in your trolley.\n2. The supervisor logs it in the PMS (*Lost & Found*) with a photo, where it was found and by whom. Valuables (cash, jewellery, phones, passports) go to the **safe** with a witness.\n3. If the owner is known, Front Office contacts them the same day.\n4. Items are returned only against identification; the guest signs the release in the PMS. Courier costs are charged to the guest.\n5. Unclaimed items: perishables after 24 hours, clothing after 3 months, valuables after 6 months – disposed of or donated with the GM's approval.", 'on' => -250]]);
        $allergen = $mk(['code' => 'SOP-FB-001', 'title' => 'Allergen handling & communication', 'category' => 'food_safety', 'department_id' => $dept('Kitchen'), 'summary' => 'Taking allergy orders, preparing allergen-free dishes and communicating with guests.', 'requires_ack' => true,
            'ack_departments' => [$dept('Kitchen'), $dept('F&B Service')], 'review_months' => 12],
            [['body' => "## Service staff\n1. Ask every table: *\"Does anyone have a food allergy or intolerance?\"*\n2. Record the allergen on the POS order – it prints in **red** on the KOT.\n3. Tell the guest about the dish from the allergen matrix; **never guess**. If unsure, ask the chef.\n\n## Kitchen\n1. The chef on the pass confirms every allergen ticket verbally.\n2. Prepare it with clean boards and utensils (**purple** board for allergen-free), on a cleaned surface, with fresh oil.\n3. Carry the dish separately with an allergen flag; the server confirms the allergen with the guest at the table.\n\n> Groundnuts, sesame, shellfish, fish, gluten, milk and eggs are the most common allergens in our menu (Ghanaian dishes often contain groundnut paste).", 'on' => -90]]);
        $keys = $mk(['code' => 'SOP-SEC-001', 'title' => 'Key control & master keys', 'category' => 'security', 'department_id' => $dept('Security'), 'summary' => 'Issue, return and audit of master, sub-master and emergency keys.', 'requires_ack' => true,
            'ack_departments' => [$dept('Security'), $dept('Housekeeping'), $dept('Front Office')], 'review_months' => 12, 'reviewed' => -60],
            [['body' => "1. Master and sub-master key cards are issued only from the security office and signed for in the key log at the start of each shift.\n2. Keys never leave the premises and are never lent to another employee.\n3. A lost master key is reported **immediately** to the duty manager – the key system is re-coded the same day.\n4. Security audits the key log every night during the night audit and reports missing returns.\n5. The emergency key set is sealed in the GM's safe; breaking the seal is recorded in the incident register.", 'on' => -420]]);
        $privacy = $mk(['code' => 'SOP-IT-001', 'title' => 'Guest data protection (Data Protection Act, 2012 – Act 843)', 'category' => 'it_privacy', 'summary' => 'Handling guest personal data, ID copies, payment cards and data subject requests.', 'requires_ack' => true,
            'ack_departments' => [$dept('Front Office'), $dept('Accounts'), $dept('Management')], 'review_months' => 12],
            [['body' => "## Principles\nWe collect only the personal data we need, keep it secure and use it only for the purpose the guest was told about.\n\n## Rules\n1. ID scans and registration cards are stored **only in the PMS** – never on phones, WhatsApp or e-mail.\n2. Never read out or write down full card numbers; card details are entered only on the payment terminal or the secure payment link.\n3. Do not confirm whether a person is staying at the hotel to callers or visitors – take a message instead.\n4. Lock your screen (Windows + L) whenever you leave the desk.\n5. A guest who asks to see, correct or delete their data is referred to the privacy request process (*Guests & CRM → Privacy*); we reply within the legal deadline.\n6. Report any suspected data breach to the GM **the same day** – the Data Protection Commission may need to be notified.", 'on' => -210]]);
        $mk(['code' => 'SOP-MNT-001', 'title' => 'Lock-out / tag-out for electrical work', 'category' => 'safety', 'department_id' => $dept('Maintenance'), 'summary' => 'Isolating equipment before maintenance.', 'requires_ack' => true, 'review_months' => 12], [],
            "## Draft – for review by the Chief Engineer\n1. Tell the users of the equipment that it will be switched off.\n2. Switch off at the local isolator **and** the distribution board.\n3. Fit your personal padlock and a danger tag with your name and the date.\n4. Test that the circuit is dead with an approved voltage tester.\n5. Only the person whose lock is fitted may remove it.");
        $mk(['code' => 'SOP-FO-000', 'title' => 'Manual key register (paper)', 'category' => 'security', 'department_id' => $dept('Front Office'), 'requires_ack' => false, 'review_months' => 12, 'status' => 'retired'],
            [['body' => 'Record every key issued in the paper key register at the front desk.', 'on' => -900]]);

        // ── courses & the training matrix
        $C = fn (array $a) => Models\TrainingCourse::create($a + ['is_active' => true, 'cost_per_head' => 0]);
        $R = fn (Models\TrainingCourse $c, ?string $deptName, ?string $designation, int $due) => Models\TrainingRequirement::create(['training_course_id' => $c->id, 'department_id' => $deptName ? $dept($deptName) : null, 'designation' => $designation, 'due_days' => $due]);
        $ind = $C(['code' => 'IND', 'name' => 'Hotel induction & orientation', 'category' => 'induction', 'delivery' => 'classroom', 'duration_hours' => 4, 'provider' => 'HR – in-house', 'description' => "Our history, values and service standards\nHotel tour, departments and who's who\nHR policies, pay, leave and self-service\nUniform, grooming and conduct\nHealth, safety and emergency basics"]);
        $R($ind, null, null, 7);
        $fireC = $C(['code' => 'FIRE', 'name' => 'Fire safety & evacuation', 'category' => 'fire', 'delivery' => 'classroom', 'duration_hours' => 2, 'validity_months' => 12, 'provider' => 'Ghana National Fire Service – Accra Region', 'cost_per_head' => 80, 'sop_document_id' => $fire->id,
            'description' => "Fire triangle and classes of fire\nUsing extinguishers and fire blankets (practical)\nAlarm, evacuation routes and the assembly point\nRoll call and assisted evacuation"]);
        $R($fireC, null, null, 30);
        $faid = $C(['code' => 'FAID', 'name' => 'First aid at work', 'category' => 'first_aid', 'delivery' => 'external', 'duration_hours' => 16, 'validity_months' => 36, 'pass_mark' => 70, 'provider' => 'Ghana Red Cross Society', 'cost_per_head' => 450,
            'description' => "Primary survey, CPR and the recovery position\nChoking, bleeding, burns and fractures\nSeizures, diabetic and allergic emergencies (EpiPen)\nRecording and reporting incidents"]);
        $R($faid, null, 'Front Office Manager', 60);
        $R($faid, 'Security', null, 60);
        $R($faid, null, 'Fitness Instructor', 90);
        $R($faid, null, 'Spa Manager & Therapist', 60);
        $fsh = $C(['code' => 'FSH', 'name' => 'Food hygiene & safety (HACCP level 2)', 'category' => 'food_safety', 'delivery' => 'classroom', 'duration_hours' => 6, 'validity_months' => 24, 'pass_mark' => 75, 'provider' => 'FDA-accredited food safety trainer', 'cost_per_head' => 250, 'fs_type' => 'hygiene',
            'description' => "Food safety hazards and the law (FDA Ghana)\nPersonal hygiene and fitness to work\nTemperature control, cooling and reheating\nCross-contamination and allergens\nCleaning, pest control and HACCP records"]);
        $R($fsh, 'Kitchen', null, 14);
        $R($fsh, 'F&B Service', null, 14);
        $chem = $C(['code' => 'CHEM', 'name' => 'Safe chemical handling', 'category' => 'health_safety', 'delivery' => 'on_job', 'duration_hours' => 1.5, 'validity_months' => 24, 'provider' => 'Executive Housekeeper / Chief Engineer', 'description' => "Reading safety data sheets and labels\nDilution and the colour-coded system\nPPE: gloves, goggles, masks\nSpills, first aid and storage"]);
        $R($chem, 'Housekeeping', null, 30);
        $R($chem, 'Maintenance', null, 30);
        $gse = $C(['code' => 'GSE', 'name' => 'Guest service excellence – the Akwaaba standard', 'category' => 'service', 'delivery' => 'classroom', 'duration_hours' => 3, 'provider' => 'Front Office Manager', 'description' => "First impressions and the 10-5 rule\nListening, empathy and handling complaints (LEARN)\nUpselling with care\nPersonalising the stay with guest preferences"]);
        $R($gse, 'Front Office', null, 60);
        $R($gse, 'F&B Service', null, 60);
        $R($gse, 'Spa & Wellness', null, 60);
        $dpa = $C(['code' => 'DPA', 'name' => 'Data protection & guest privacy (Act 843)', 'category' => 'compliance', 'delivery' => 'online', 'duration_hours' => 1, 'validity_months' => 12, 'pass_mark' => 80, 'provider' => 'In-house e-learning', 'sop_document_id' => $privacy->id,
            'description' => "The Data Protection Act, 2012 (Act 843) and the Data Protection Commission\nWhat counts as personal data in a hotel\nCard data and ID copies\nPrivacy requests and breach reporting"]);
        $R($dpa, 'Front Office', null, 30);
        $R($dpa, 'Accounts', null, 30);
        $R($dpa, 'Management', null, 30);
        $traf = $C(['code' => 'TRAF', 'name' => 'Human-trafficking awareness for hotel staff', 'category' => 'security', 'delivery' => 'classroom', 'duration_hours' => 1.5, 'validity_months' => 24, 'provider' => 'Ghana Police Service – Anti-Human Trafficking Unit',
            'description' => "Human Trafficking Act, 2005 (Act 694)\nWarning signs at check-in, in rooms and in public areas\nWhat to do – and what not to do\nReporting to the duty manager and the police"]);
        $R($traf, 'Front Office', null, 60);
        $R($traf, 'Security', null, 60);
        $elec = $C(['code' => 'ELEC', 'name' => 'Electrical safety & lock-out / tag-out', 'category' => 'health_safety', 'delivery' => 'on_job', 'duration_hours' => 4, 'validity_months' => 12, 'pass_mark' => 80, 'provider' => 'Chief Engineer']);
        $R($elec, null, 'Electrician', 14);
        $lead = $C(['code' => 'LEAD', 'name' => 'Supervisory skills for hospitality', 'category' => 'leadership', 'delivery' => 'external', 'duration_hours' => 12, 'provider' => 'Ghana Tourism Authority – Hotel Training School', 'cost_per_head' => 2400]);

        // ── history
        $rec = function (Models\TrainingCourse $c, ?Models\Employee $emp, int $on, array $x = []) use ($B) {
            if (! $emp) {
                return null;
            }
            $date = $B->copy()->addDays($on);
            $st = $x['status'] ?? 'passed';

            return Models\TrainingRecord::create(['training_course_id' => $c->id, 'employee_id' => $emp->id, 'status' => $st, 'source' => $x['source'] ?? 'external', 'training_session_id' => $x['session'] ?? null,
                'score' => $x['score'] ?? null, 'completed_on' => $date->toDateString(),
                'expires_on' => $st === 'passed' && $c->validity_months ? $date->copy()->addMonthsNoOverflow($c->validity_months)->subDay()->toDateString() : ($x['expires'] ?? null),
                'certificate_no' => $st === 'passed' ? ($x['cert'] ?? \App\Support\Numbering::next('training_cert')) : null, 'provider' => $x['provider'] ?? $c->provider,
                'cost' => $st === 'no_show' ? 0 : ($x['cost'] ?? (float) $c->cost_per_head), 'notes' => $x['notes'] ?? null, 'recorded_by' => Auth::id()]);
        };
        $sess = function (Models\TrainingCourse $c, int $on, array $x = []) use ($B, $hr) {
            return Models\TrainingSession::create(['number' => \App\Support\Numbering::next('training_session'), 'training_course_id' => $c->id, 'date' => $B->copy()->addDays($on)->toDateString(),
                'start_time' => $x['start'] ?? '09:00', 'end_time' => $x['end'] ?? '11:00', 'venue' => $x['venue'] ?? 'Training Room, 1st floor', 'trainer_name' => $x['trainer'] ?? $c->provider,
                'trainer_id' => $x['trainer_id'] ?? null, 'capacity' => $x['capacity'] ?? 15, 'cost' => $x['cost'] ?? 0, 'status' => $x['status'] ?? 'completed', 'cancel_reason' => $x['cancel'] ?? null,
                'completed_at' => ($x['status'] ?? 'completed') === 'completed' ? $B->copy()->addDays($on)->setTime(12, 0) : null, 'completed_by' => ($x['status'] ?? 'completed') === 'completed' ? $hr->id : null, 'created_by' => $hr->id]);
        };
        // induction for everyone (except the newest starter, who is inside the 7-day window? no – everyone did it on day 2)
        foreach ($E as $emp) {
            if ($emp->joining_date && $emp->joining_date->copy()->addDays(2)->lte($B)) {
                $rec($ind, $emp, (int) $B->diffInDays($emp->joining_date->copy()->addDays(2), false), ['provider' => 'HR – in-house', 'cost' => 0]);
            }
        }
        // fire: two sessions a year – last autumn's group expires in 3–4 weeks, the spring group is current; one lapsed certificate from a previous employer
        $s1 = $sess($fireC, -340, ['cost' => 900, 'trainer' => 'Station Officer K. Addo, GNFS']);
        foreach (['Ramesh Gowda', 'Lata Bai', 'Joseph Thomas', 'Deepa Nair', 'Mohan Lal', 'Sunil Yadav', 'Ravi Kumar', 'Meera Iyer'] as $n) {
            $rec($fireC, $e($n), -340, ['source' => 'session', 'session' => $s1->id, 'cost' => 112.5]);
        }
        $s2 = $sess($fireC, -200, ['cost' => 600, 'trainer' => 'Station Officer K. Addo, GNFS']);
        foreach (['Abena Owusu', 'Grace Mensah', 'Efua Asante', 'Anjali Menon', 'Kiran Patil'] as $n) {
            $rec($fireC, $e($n), -200, ['source' => 'session', 'session' => $s2->id, 'cost' => 120]);
        }
        $rec($fireC, $e('Imran Ali'), -400, ['provider' => 'GNFS (previous employer – Golden Tulip Kumasi)', 'cost' => 0, 'cert' => 'GNFS-KSI-2025-0917']);
        // first aid
        $rec($faid, $e('Ravi Kumar'), -700, ['score' => 84, 'cert' => 'GRCS-FA-24-3310']);
        if ($e('Grace Mensah')) {
            Models\TrainingRecord::create(['training_course_id' => $faid->id, 'employee_id' => $e('Grace Mensah')->id, 'status' => 'exempt', 'source' => 'exempt', 'completed_on' => $d(-120),
                'expires_on' => $d(300), 'notes' => 'Registered nurse (N&MC Ghana) – holds a current clinical first-aid / BLS certificate', 'recorded_by' => $hr->id]);
        }
        // food hygiene (history kept as records only – the food-safety certificates were seeded with v5.0)
        $rec($fsh, $e('Mohan Lal'), -600, ['score' => 91]);
        $rec($fsh, $e('Sunil Yadav'), -690, ['score' => 78]);
        // chemicals
        $rec($chem, $e('Ramesh Gowda'), -100, ['source' => 'external', 'provider' => 'Executive Housekeeper (on the job)', 'cost' => 0]);
        $rec($chem, $e('Lata Bai'), -800, ['provider' => 'Executive Housekeeper (on the job)', 'cost' => 0]);
        $rec($chem, $e('Abena Owusu'), -150, ['provider' => 'Executive Housekeeper (on the job)', 'cost' => 0]);
        $rec($chem, $e('Joseph Thomas'), -300, ['provider' => 'Chief Engineer (on the job)', 'cost' => 0]);
        // service
        foreach (['Deepa Nair' => -180, 'Ravi Kumar' => -500, 'Sunil Yadav' => -160, 'Grace Mensah' => -400] as $n => $on) {
            $rec($gse, $e($n), $on, ['cost' => 0]);
        }
        // data protection – a failed attempt then a pass
        $rec($dpa, $e('Deepa Nair'), -200, ['score' => 92, 'cost' => 0]);
        $rec($dpa, $e('Ravi Kumar'), -380, ['score' => 88, 'cost' => 0]);
        $rec($dpa, $e('Meera Iyer'), -45, ['status' => 'failed', 'score' => 65, 'cost' => 0]);
        $rec($dpa, $e('Meera Iyer'), -40, ['score' => 90, 'cost' => 0]);
        $rec($dpa, $e('Anjali Menon'), -330, ['score' => 95, 'cost' => 0]);
        $rec($elec, $e('Joseph Thomas'), -20, ['score' => 88, 'cost' => 0]);
        $rec($lead, $e('Ravi Kumar'), -120, ['cost' => 2400, 'cert' => 'GTA-HTS-SUP-0412']);
        // cancelled session
        $sess($dpa, -10, ['status' => 'cancelled', 'cancel' => 'Trainer unwell – e-learning module used instead', 'start' => '14:00', 'end' => '15:00']);

        // ── through the service: last week's trafficking-awareness session (one no-show), scheduled and completed
        $traS = $LS::schedule(['training_course_id' => $traf->id, 'date' => $d(-3), 'start_time' => '10:00', 'end_time' => '11:30', 'venue' => 'Conference Room B', 'capacity' => 12, 'cost' => 0,
            'trainer_name' => 'Insp. A. Mensah, AHTU', 'employees' => array_values(array_filter([$e('Deepa Nair')?->id, $e('Ravi Kumar')?->id, $e('Kiran Patil')?->id]))], $hr);
        $LS::complete($traS, [$e('Deepa Nair')?->id => ['status' => 'attended'], $e('Ravi Kumar')?->id => ['status' => 'attended'], $e('Kiran Patil')?->id => ['status' => 'no_show']], $hr);
        // yesterday's service session – not recorded yet (flagged)
        $gseS = $LS::schedule(['training_course_id' => $gse->id, 'date' => $d(-1), 'start_time' => '15:00', 'end_time' => '18:00', 'venue' => 'Training Room, 1st floor', 'capacity' => 10,
            'trainer_id' => $u('fom')?->id, 'employees' => array_values(array_filter([$e('Efua Asante')?->id, $e('Kofi Boateng')?->id]))], $hr);
        // upcoming refreshers
        $fireS = $LS::schedule(['training_course_id' => $fireC->id, 'date' => $d(6), 'start_time' => '09:00', 'end_time' => '11:00', 'venue' => 'Training Room, 1st floor', 'capacity' => 15, 'cost' => 900,
            'trainer_name' => 'Station Officer K. Addo, GNFS', 'employees' => array_values(array_filter([$e('Kofi Boateng')?->id, $e('Imran Ali')?->id, $e('Ramesh Gowda')?->id, $e('Lata Bai')?->id, $e('Deepa Nair')?->id]))], $hr);
        $LS::schedule(['training_course_id' => $fsh->id, 'date' => $d(12), 'start_time' => '08:30', 'end_time' => '15:00', 'venue' => 'Banquet Hall – Ashanti Room', 'capacity' => 10, 'cost' => 250,
            'trainer_name' => 'Mrs. E. Quaye (FDA-accredited)', 'employees' => array_values(array_filter([$e('Sunil Yadav')?->id]))], $hr);

        // ── SOP sign-offs
        $ack = function (Models\SopDocument $doc, array $names, int $version, string $via, int $on) use ($e, $B, $hr) {
            foreach ($names as $n) {
                if ($emp = $e($n)) {
                    Models\SopAcknowledgement::firstOrCreate(['sop_document_id' => $doc->id, 'version' => $version, 'employee_id' => $emp->id],
                        ['via' => $via, 'acknowledged_at' => $B->copy()->addDays($on)->setTime(11, 0), 'recorded_by' => $hr->id]);
                }
            }
        };
        $ack($fire, ['Ramesh Gowda', 'Lata Bai', 'Joseph Thomas', 'Imran Ali', 'Deepa Nair', 'Mohan Lal', 'Sunil Yadav', 'Ravi Kumar', 'Meera Iyer'], 1, 'sheet', -330);
        $ack($fire, ['Abena Owusu', 'Grace Mensah', 'Efua Asante', 'Anjali Menon', 'Kiran Patil'], 2, 'training', -200);
        $ack($fire, ['Ravi Kumar', 'Meera Iyer', 'Deepa Nair', 'Joseph Thomas'], 2, 'self', -150);
        $ack($checkin, ['Deepa Nair', 'Ravi Kumar'], 1, 'self', -290);
        $ack($cash, ['Deepa Nair', 'Ravi Kumar', 'Meera Iyer'], 2, 'self', -410);
        $ack($room, ['Ramesh Gowda', 'Abena Owusu'], 1, 'sheet', -140);
        $ack($lf, ['Ramesh Gowda', 'Lata Bai', 'Abena Owusu', 'Deepa Nair', 'Ravi Kumar', 'Kiran Patil'], 1, 'sheet', -240);
        $ack($allergen, ['Mohan Lal'], 1, 'self', -85);
        $ack($keys, ['Kiran Patil', 'Ramesh Gowda', 'Lata Bai', 'Abena Owusu', 'Deepa Nair', 'Ravi Kumar'], 1, 'sheet', -400);
        $ack($privacy, ['Deepa Nair', 'Ravi Kumar', 'Meera Iyer', 'Anjali Menon'], 1, 'training', -200);

        \App\Models\Setting::updateOrCreate(['key' => 'learning_notify'], ['value' => '1']);
        \App\Models\Setting::$version++;
        $LS::flush();
        $prevUser ? Auth::setUser($prevUser) : Auth::forgetUser();
    }

}
