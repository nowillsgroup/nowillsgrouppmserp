<?php

namespace App\Services;

use App\Models\ClubBooking;
use App\Models\ClubCharge;
use App\Models\ClubDayPass;
use App\Models\ClubMember;
use App\Models\ClubMembership;
use App\Models\ClubPlan;
use App\Models\ClubVisit;
use App\Models\ReservationRoom;
use App\Models\User;
use App\Support\Numbering;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v5.1 Health club: enrolment, renewals, freezes, cancellations, access control at the desk (members, in-house
 * residents, day passes, members' guests), capacity per facility and the nightly housekeeping of memberships.
 */
class ClubService
{
    public const SETTINGS = [
        'club_grace_days' => ['Grace days before an unpaid charge blocks entry', 7],
        'club_renew_days' => ['Raise auto-renewal invoices this many days before expiry', 7],
        'club_checkout_hours' => ['Auto check-out visits left open after (hours)', 4],
        'club_daypass_adult' => ['Day pass – adult (GH₵, excl. tax)', 150],
        'club_daypass_child' => ['Day pass – child under 12 (GH₵, excl. tax)', 80],
        'club_guestpass' => ["Member's guest pass beyond the free allowance (GH₵, excl. tax)", 100],
        'club_resident_free' => ['In-house guests use the gym, pool and sauna free (1 = yes)', 1],
        'club_class_cancel_hours' => ['Late class cancellation counts as a no-show within (hours)', 2],
        'club_noshow_limit' => ['Class booking blocked after this many no-shows in 30 days', 3],
        'club_cap_gym' => ['Capacity – gym', 25],
        'club_cap_pool' => ['Capacity – swimming pool', 40],
        'club_cap_sauna' => ['Capacity – sauna & steam', 6],
        'club_cap_tennis' => ['Capacity – tennis court', 4],
        'club_cap_squash' => ['Capacity – squash court', 2],
        'club_cap_kids' => ['Capacity – kids club', 15],
        'club_cap_classes' => ['Capacity – studio (group classes)', 30],
    ];

    /** Facilities open to in-house residents when club_resident_free is on. */
    public const RESIDENT_FACILITIES = ['gym', 'pool', 'sauna'];

    /** Starter plans: [code, name, category, term, price, joining, persons, facilities, from, to, guest passes, F&B %, spa %]. */
    public const DEFAULT_PLANS = [
        ['GYM-M', 'Gym Monthly', 'individual', 1, 450, 200, 1, ['gym', 'classes'], null, null, 0, 0, 0],
        ['FULL-M', 'Full Club Monthly', 'individual', 1, 650, 300, 1, ['gym', 'pool', 'sauna', 'tennis', 'squash', 'classes'], null, null, 2, 10, 10],
        ['FULL-Q', 'Full Club Quarterly', 'individual', 3, 1800, 300, 1, ['gym', 'pool', 'sauna', 'tennis', 'squash', 'classes'], null, null, 2, 10, 10],
        ['FULL-Y', 'Full Club Annual', 'individual', 12, 6500, 0, 1, ['gym', 'pool', 'sauna', 'tennis', 'squash', 'classes'], null, null, 4, 15, 15],
        ['CPL-Y', 'Couple Annual', 'couple', 12, 11500, 0, 2, ['gym', 'pool', 'sauna', 'tennis', 'squash', 'classes'], null, null, 4, 15, 15],
        ['FAM-Y', 'Family Annual', 'family', 12, 11000, 500, 4, ['gym', 'pool', 'sauna', 'tennis', 'kids', 'classes'], null, null, 4, 15, 10],
        ['OFF-M', 'Off-Peak Monthly', 'individual', 1, 380, 150, 1, ['gym', 'pool'], '06:00:00', '16:00:00', 0, 0, 0],
        ['STU-M', 'Student Monthly', 'student', 1, 300, 100, 1, ['gym', 'pool'], null, null, 0, 0, 0],
        ['CORP-Y', 'Corporate Annual (per person)', 'corporate', 12, 5200, 0, 1, ['gym', 'pool', 'sauna', 'classes'], null, null, 2, 10, 10],
    ];

    /** Starter timetable: [name, facility, instructor, weekday, start, minutes, capacity]. */
    public const DEFAULT_CLASSES = [
        ['Aqua Aerobics', 'pool', 'Akosua Mensah', 1, '07:00', 45, 15],
        ['Spin Express', 'classes', 'Kwame Boateng', 2, '18:00', 45, 12],
        ['Vinyasa Yoga', 'classes', 'Efua Owusu', 3, '07:00', 60, 16],
        ['HIIT Circuit', 'gym', 'Kwame Boateng', 4, '18:00', 45, 14],
        ['Pilates Core', 'classes', 'Efua Owusu', 5, '09:00', 60, 12],
        ['Kids Swim School', 'pool', 'Akosua Mensah', 6, '10:00', 45, 10],
        ['Zumba Party', 'classes', 'Adjoa Asante', 6, '16:00', 60, 25],
    ];

    public static function ensureDefaults(): int
    {
        foreach (self::SETTINGS as $k => [$label, $v]) {
            \App\Models\Setting::firstOrCreate(['key' => $k], ['value' => (string) $v]);
        }
        \App\Models\Setting::$version++;
        $n = 0;
        if (! ClubPlan::exists()) {
            foreach (self::DEFAULT_PLANS as [$code, $name, $cat, $term, $price, $join, $max, $fac, $from, $to, $gp, $fnb, $spa]) {
                ClubPlan::create(['code' => $code, 'name' => $name, 'category' => $cat, 'term_months' => $term, 'price' => $price, 'joining_fee' => $join,
                    'max_persons' => $max, 'facilities' => $fac, 'access_from' => $from, 'access_to' => $to, 'guest_passes' => $gp,
                    'fnb_discount_pct' => $fnb, 'spa_discount_pct' => $spa, 'freeze_days_max' => $term >= 12 ? 60 : 30, 'is_active' => true]);
                $n++;
            }
        }
        if (! \App\Models\ClubClass::exists()) {
            foreach (self::DEFAULT_CLASSES as [$name, $fac, $who, $wd, $at, $min, $cap]) {
                \App\Models\ClubClass::create(['name' => $name, 'facility' => $fac, 'instructor' => $who, 'weekday' => $wd, 'start_time' => $at, 'duration_min' => $min, 'capacity' => $cap]);
                $n++;
            }
        }

        return $n;
    }

    public static function cfg(string $key): mixed
    {
        return setting($key, self::SETTINGS[$key][1] ?? null);
    }

    public static function capacity(string $facility): int
    {
        return (int) self::cfg('club_cap_'.$facility);
    }

    public static function today(): string
    {
        return bdate()->toDateString();
    }

    public static function newCardNo(): string
    {
        do {
            $no = '8'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
        } while (ClubMember::where('card_no', $no)->exists());

        return $no;
    }

    public static function periodEnd(string $start, int $months): string
    {
        return Carbon::parse($start)->addMonthsNoOverflow($months)->subDay()->toDateString();
    }

    // ───────────────────────── enrolment ─────────────────────────

    /**
     * Enrol a new membership. $d: club_plan_id, holder_name, phone, email, company_id, guest_id, start_date, auto_renew,
     * source, notes, waive_joining, persons[] (name, relation, gender, dob, phone, emergency_contact, health),
     * payment (amount, payment_mode_id, reference). $asOf back-dates the documents (demo data / migration).
     */
    public static function enrol(array $d, ?User $u = null, ?string $asOf = null): ClubMembership
    {
        $plan = ClubPlan::find($d['club_plan_id'] ?? 0);
        if (! $plan || ! $plan->is_active) {
            throw new RuntimeException('Choose an active membership plan.');
        }
        $holder = trim($d['holder_name'] ?? '');
        if ($holder === '') {
            throw new RuntimeException('The membership holder\'s name is required.');
        }
        $today = $asOf ?? self::today();
        $start = Carbon::parse($d['start_date'] ?? $today)->toDateString();
        if (! $asOf && $start < Carbon::parse($today)->subDays(7)->toDateString()) {
            throw new RuntimeException('A membership cannot start more than 7 days back.');
        }
        if ($start > Carbon::parse($today)->addDays(60)->toDateString()) {
            throw new RuntimeException('A membership can start at most 60 days ahead.');
        }
        if ($plan->category === 'corporate' && empty($d['company_id'])) {
            throw new RuntimeException('A corporate plan needs the paying company.');
        }
        $persons = collect($d['persons'] ?? [])->filter(fn ($p) => trim($p['name'] ?? '') !== '')->values();
        if ($persons->isEmpty()) {
            $persons = collect([['name' => $holder, 'relation' => 'primary', 'phone' => $d['phone'] ?? null]]);
        }
        if ($persons->count() > $plan->max_persons) {
            throw new RuntimeException("The {$plan->name} plan covers at most {$plan->max_persons} person(s).");
        }

        return DB::transaction(function () use ($plan, $d, $u, $today, $start, $persons, $holder) {
            $m = ClubMembership::create([
                'membership_no' => Numbering::next('club_membership'),
                'club_plan_id' => $plan->id,
                'company_id' => $d['company_id'] ?? null,
                'guest_id' => $d['guest_id'] ?? null,
                'holder_name' => mb_substr($holder, 0, 120),
                'phone' => $d['phone'] ?? null,
                'email' => $d['email'] ?? null,
                'status' => 'active',
                'start_date' => $start,
                'end_date' => self::periodEnd($start, $plan->term_months),
                'auto_renew' => (bool) ($d['auto_renew'] ?? true),
                'source' => array_key_exists($d['source'] ?? '', ClubMembership::SOURCES) ? $d['source'] : ($plan->category === 'corporate' ? 'corporate' : 'walk_in'),
                'notes' => $d['notes'] ?? null,
                'created_by' => $u?->id,
            ]);
            foreach ($persons as $i => $p) {
                self::addPerson($m, array_merge($p, ['relation' => $i === 0 ? 'primary' : ($p['relation'] ?? 'dependant')]), $today);
            }
            if ((float) $plan->joining_fee > 0 && empty($d['waive_joining'])) {
                ClubBillingService::raise($m, 'joining', "Joining fee – {$plan->name}", (float) $plan->joining_fee, ['date' => $today, 'due_date' => $today], $u);
            }
            ClubBillingService::raise($m, 'subscription', "{$plan->name} – ".dmy($start).' to '.dmy($m->end_date), (float) $plan->price,
                ['date' => $today, 'period_start' => $start, 'period_end' => $m->end_date->toDateString(), 'due_date' => max($today, $start)], $u);
            if (! empty($d['payment']['amount']) && (float) $d['payment']['amount'] > 0) {
                ClubBillingService::pay($m, (float) $d['payment']['amount'], (int) $d['payment']['payment_mode_id'], $d['payment']['reference'] ?? null, $u, $today);
            }
            ActivityLogger::log('club', 'enrolled', "Membership {$m->membership_no} – {$m->holder_name} ({$plan->name})", $m);

            return $m->refresh();
        });
    }

    public static function addPerson(ClubMembership $m, array $p, ?string $asOf = null): ClubMember
    {
        $name = trim($p['name'] ?? '');
        if ($name === '') {
            throw new RuntimeException('Name is required.');
        }
        $count = $m->members()->where('is_active', true)->count();
        if ($count >= $m->plan->max_persons) {
            throw new RuntimeException("The plan covers at most {$m->plan->max_persons} person(s).");
        }
        $rel = array_key_exists($p['relation'] ?? '', ClubMember::RELATIONS) ? $p['relation'] : 'dependant';
        if ($rel === 'primary' && $m->members()->where('relation', 'primary')->exists()) {
            $rel = 'dependant';
        }

        return ClubMember::create([
            'club_membership_id' => $m->id,
            'name' => mb_substr($name, 0, 120),
            'relation' => $rel,
            'gender' => in_array($p['gender'] ?? null, ['M', 'F'], true) ? $p['gender'] : null,
            'dob' => ! empty($p['dob']) ? $p['dob'] : null,
            'phone' => $p['phone'] ?? null,
            'card_no' => self::newCardNo(),
            'emergency_contact' => $p['emergency_contact'] ?? null,
            'health_declared_at' => ! empty($p['health']) ? Carbon::parse($asOf ?? now())->setTimeFrom(now()) : null,
        ]);
    }

    // ───────────────────────── renewals & expiry ─────────────────────────

    /** Bill the next term. An expired membership restarts today; otherwise the next period follows on. */
    public static function renew(ClubMembership $m, ?User $u = null, ?string $asOf = null, ?int $planId = null): ClubCharge
    {
        $today = $asOf ?? self::today();
        if ($m->status === 'cancelled') {
            throw new RuntimeException('The membership is cancelled – enrol again.');
        }
        if ($m->cancel_mode === 'term_end') {
            throw new RuntimeException('The membership is set to end at the end of the term – withdraw the notice first.');
        }
        if ($planId && $planId !== $m->club_plan_id) {
            $plan = ClubPlan::where('is_active', true)->find($planId) ?? throw new RuntimeException('Choose an active plan.');
            if ($m->members()->where('is_active', true)->count() > $plan->max_persons) {
                throw new RuntimeException("{$plan->name} covers at most {$plan->max_persons} person(s) – deactivate cards first.");
            }
            $m->update(['club_plan_id' => $plan->id]);
            $m->load('plan');
        }
        $plan = $m->plan;
        $start = max($m->end_date->copy()->addDay()->toDateString(), $m->status === 'expired' ? $today : '0000-00-00');
        if ($start > Carbon::parse($today)->addDays(max(31, (int) self::cfg('club_renew_days')))->toDateString()) {
            throw new RuntimeException('The membership is already paid through '.dmy($m->end_date).'.');
        }
        $end = self::periodEnd($start, $plan->term_months);

        return DB::transaction(function () use ($m, $u, $today, $start, $end, $plan) {
            $c = ClubBillingService::raise($m, 'subscription', "{$plan->name} renewal – ".dmy($start).' to '.dmy($end), (float) $plan->price,
                ['date' => $today, 'period_start' => $start, 'period_end' => $end, 'due_date' => max($today, $start)], $u);
            $upd = ['end_date' => $end];
            if ($m->status === 'expired') {
                $upd += ['status' => 'active', 'frozen_days' => 0];
            }
            if ($start > $m->end_date->toDateString() && $m->status !== 'expired') {
                $upd['frozen_days'] = 0; // freeze allowance is per term
            }
            $m->update($upd);
            ActivityLogger::log('club', 'renewed', "Membership {$m->membership_no} renewed to ".dmy($end)." ({$c->charge_no})", $m);

            return $c;
        });
    }

    /** Auto-renewal invoices for memberships ending within club_renew_days. */
    public static function renewalRun(?User $u = null, ?string $asOf = null): array
    {
        $today = $asOf ?? self::today();
        $limit = Carbon::parse($today)->addDays((int) self::cfg('club_renew_days'))->toDateString();
        $done = [];
        $errors = [];
        foreach (ClubMembership::with('plan')->whereIn('status', ['active', 'frozen'])->where('auto_renew', true)->whereNull('cancel_mode')
            ->whereDate('end_date', '<=', $limit)->whereDate('end_date', '>=', Carbon::parse($today)->subDay()->toDateString())->get() as $m) {
            if (! $m->plan->is_active) {
                $errors[] = "{$m->membership_no}: the {$m->plan->name} plan is withdrawn";

                continue;
            }
            try {
                $done[] = self::renew($m, $u, $today)->charge_no;
            } catch (\Throwable $e) {
                $errors[] = "{$m->membership_no}: ".$e->getMessage();
            }
        }

        return ['renewed' => $done, 'errors' => $errors];
    }

    /** Nightly: freezes start/end, terms lapse, open visits close, class no-shows are marked. */
    public static function expiryRun(?string $asOf = null): array
    {
        $today = $asOf ?? self::today();
        $out = ['frozen' => 0, 'unfrozen' => 0, 'expired' => 0, 'cancelled' => 0, 'checked_out' => 0, 'no_shows' => 0];
        foreach (ClubMembership::where('status', 'active')->whereNotNull('frozen_from')->where('frozen_from', '<=', $today)->where('frozen_to', '>=', $today)->get() as $m) {
            $m->update(['status' => 'frozen']);
            $out['frozen']++;
        }
        foreach (ClubMembership::where('status', 'frozen')->where('frozen_to', '<', $today)->get() as $m) {
            $m->update(['status' => 'active']);
            $out['unfrozen']++;
        }
        foreach (ClubMembership::whereIn('status', ['active', 'frozen'])->where('end_date', '<', $today)->get() as $m) {
            if ($m->cancel_mode === 'term_end') {
                $m->update(['status' => 'cancelled', 'cancelled_on' => $m->end_date->copy()->addDay()->toDateString()]);
                $out['cancelled']++;
            } else {
                $m->update(['status' => 'expired']);
                $out['expired']++;
            }
        }
        $hours = max(1, (int) self::cfg('club_checkout_hours'));
        foreach (ClubVisit::where('denied', false)->whereNull('checked_out_at')->where('checked_in_at', '<', now()->subHours($hours))->get() as $v) {
            $v->update(['checked_out_at' => $v->checked_in_at->copy()->addHours($hours)]);
            $out['checked_out']++;
        }
        $out['no_shows'] = ClubBooking::where('status', 'booked')->where('date', '<', $today)->update(['status' => 'no_show']);

        return $out;
    }

    /** Night audit hook: roll the memberships for the new business date, then raise the renewal invoices. */
    public static function nightly(): array
    {
        $r = self::expiryRun();
        $r['renewals'] = self::renewalRun(null);

        return $r;
    }

    // ───────────────────────── freeze / cancel ─────────────────────────

    public static function freeze(ClubMembership $m, string $from, string $to, string $reason, ?User $u = null): void
    {
        if ($m->status !== 'active') {
            throw new RuntimeException('Only an active membership can be frozen.');
        }
        if ($m->frozen_to && $m->frozen_to->toDateString() >= self::today()) {
            throw new RuntimeException('A freeze is already booked ('.dmy($m->frozen_from).' – '.dmy($m->frozen_to).').');
        }
        $f = Carbon::parse($from);
        $t = Carbon::parse($to);
        if ($f->toDateString() < self::today()) {
            throw new RuntimeException('A freeze cannot start in the past.');
        }
        if ($f->toDateString() > $m->end_date->toDateString()) {
            throw new RuntimeException('The freeze must start before the membership ends ('.dmy($m->end_date).').');
        }
        $days = (int) $f->diffInDays($t) + 1;
        if ($t->lt($f) || $days < 7) {
            throw new RuntimeException('A freeze runs for at least 7 days.');
        }
        $left = (int) $m->plan->freeze_days_max - (int) $m->frozen_days;
        if ($days > $left) {
            throw new RuntimeException("Only {$left} freeze day(s) left this term (the plan allows {$m->plan->freeze_days_max}).");
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give a reason (travel, medical …).');
        }
        $m->update(['frozen_from' => $f->toDateString(), 'frozen_to' => $t->toDateString(), 'frozen_days' => $m->frozen_days + $days,
            'freeze_reason' => mb_substr($reason, 0, 160), 'end_date' => $m->end_date->copy()->addDays($days)->toDateString(),
            'status' => $f->toDateString() === self::today() ? 'frozen' : 'active']);
        ActivityLogger::log('club', 'frozen', "Membership {$m->membership_no} frozen ".dmy($f).' – '.dmy($t)." ({$days} days; now ends ".dmy($m->end_date).')', $m);
    }

    /** End a freeze early (or withdraw a future one): the unused days come off the extension. */
    public static function unfreeze(ClubMembership $m, ?User $u = null): void
    {
        $today = self::today();
        if (! $m->frozen_to || $m->frozen_to->toDateString() < $today) {
            throw new RuntimeException('There is no current or booked freeze.');
        }
        $from = $m->frozen_from->toDateString() > $today ? $m->frozen_from : Carbon::parse($today);
        $unused = (int) $from->diffInDays($m->frozen_to) + 1;
        $whole = $m->frozen_from->toDateString() >= $today;
        $m->update(['end_date' => $m->end_date->copy()->subDays($unused)->toDateString(), 'frozen_days' => max(0, $m->frozen_days - $unused), 'status' => 'active',
            'frozen_from' => $whole ? null : $m->frozen_from, 'frozen_to' => $whole ? null : Carbon::parse($today)->subDay()->toDateString(), 'freeze_reason' => $whole ? null : $m->freeze_reason]);
        ActivityLogger::log('club', 'unfrozen', "Membership {$m->membership_no} freeze ended ({$unused} day(s) returned)", $m);
    }

    /** Suggested credit for an immediate cancellation: the unused days of the current subscription, net. */
    public static function unusedValue(ClubMembership $m, ?string $asOf = null): array
    {
        $today = $asOf ?? self::today();
        $c = ClubCharge::where('club_membership_id', $m->id)->where('type', 'subscription')->whereNull('cancelled_at')
            ->where('period_start', '<=', $today)->where('period_end', '>=', $today)->latest('period_start')->first();
        if (! $c) {
            return ['charge' => null, 'days' => 0, 'amount' => 0.0];
        }
        $total = (int) $c->period_start->diffInDays($c->period_end) + 1;
        $unused = (int) Carbon::parse($today)->diffInDays($c->period_end); // from tomorrow
        $amt = min(ClubBillingService::creditable($c), round((float) $c->amount * $unused / $total, 2));

        return ['charge' => $c, 'days' => $unused, 'amount' => max(0, $amt)];
    }

    /**
     * Cancel: term_end = no renewal, access until the paid term ends; immediate = access stops today, future renewals
     * are cancelled and an optional credit note (default: the unused part) goes on the account.
     */
    public static function cancel(ClubMembership $m, string $mode, string $reason, ?float $credit = null, ?User $u = null, ?string $asOf = null): void
    {
        $today = $asOf ?? self::today();
        if (in_array($m->status, ['cancelled'], true)) {
            throw new RuntimeException('Already cancelled.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give a reason for the cancellation.');
        }
        if (! in_array($mode, ['term_end', 'immediate'], true)) {
            throw new RuntimeException('Choose how the membership ends.');
        }
        DB::transaction(function () use ($m, $mode, $reason, $credit, $u, $today) {
            // renewals for periods that have not started are withdrawn
            foreach (ClubCharge::where('club_membership_id', $m->id)->where('type', 'subscription')->whereNull('cancelled_at')->where('period_start', '>', $today)->get() as $c) {
                ClubBillingService::cancelCharge($c, 'Membership cancelled – '.$reason, $u, true);
                $m->refresh();
            }
            if ($mode === 'term_end') {
                $m->update(['cancel_mode' => 'term_end', 'auto_renew' => false, 'cancel_reason' => mb_substr($reason, 0, 255)]);
                if ($m->status === 'expired') {
                    $m->update(['status' => 'cancelled', 'cancelled_on' => $today]);
                }
            } else {
                $unused = self::unusedValue($m, $today);
                $m->update(['cancel_mode' => 'immediate', 'auto_renew' => false, 'status' => 'cancelled', 'cancelled_on' => $today,
                    'end_date' => min($m->end_date->toDateString(), $today), 'cancel_reason' => mb_substr($reason, 0, 255), 'frozen_from' => null, 'frozen_to' => null]);
                $amt = $credit ?? $unused['amount'];
                if ($unused['charge'] && $amt > 0) {
                    ClubBillingService::creditNote($unused['charge'], $amt, 'cancellation – unused '.$unused['days'].' day(s)', $u, $today);
                }
                ClubVisit::whereIn('club_member_id', $m->members()->pluck('id'))->whereNull('checked_out_at')->where('denied', false)->update(['checked_out_at' => now()]);
                ClubBooking::whereIn('club_member_id', $m->members()->pluck('id'))->whereIn('status', ['booked', 'waitlist'])->where('date', '>=', $today)->update(['status' => 'cancelled']);
            }
        });
        ActivityLogger::log('club', 'cancelled', "Membership {$m->membership_no} cancelled (".($mode === 'term_end' ? 'at term end '.dmy($m->end_date) : 'immediately').") – {$reason}", $m);
    }

    /** Withdraw an end-of-term notice. */
    public static function withdrawNotice(ClubMembership $m): void
    {
        if ($m->cancel_mode !== 'term_end' || $m->status === 'cancelled') {
            throw new RuntimeException('There is no notice to withdraw.');
        }
        $m->update(['cancel_mode' => null, 'cancel_reason' => null, 'auto_renew' => true]);
        ActivityLogger::log('club', 'notice_withdrawn', "Membership {$m->membership_no} – cancellation notice withdrawn", $m);
    }

    // ───────────────────────── access control ─────────────────────────

    /** Find a card holder by card number, membership number or name. */
    public static function lookup(string $q): Collection
    {
        $q = trim($q);
        if ($q === '') {
            return collect();
        }
        $exact = ClubMember::with('membership.plan')->where('card_no', $q)->get();
        if ($exact->isNotEmpty()) {
            return $exact;
        }
        $m = ClubMembership::where('membership_no', $q)->first();
        if ($m) {
            return ClubMember::with('membership.plan')->where('club_membership_id', $m->id)->get();
        }

        return ClubMember::with('membership.plan')->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%"))->limit(12)->get();
    }

    public static function openVisits(): \Illuminate\Database\Eloquent\Builder
    {
        return ClubVisit::where('denied', false)->whereNull('checked_out_at')->where('checked_in_at', '>=', now()->subHours(max(1, (int) self::cfg('club_checkout_hours'))));
    }

    /** People in each facility right now. */
    public static function occupancy(): array
    {
        $now = self::openVisits()->selectRaw('facility, SUM(pax) n')->groupBy('facility')->pluck('n', 'facility');
        $out = [];
        foreach (ClubPlan::FACILITIES as $k => $label) {
            $out[$k] = ['label' => $label, 'in' => (int) ($now[$k] ?? 0), 'cap' => self::capacity($k)];
        }

        return $out;
    }

    /** Can this card holder use the facility now? ['ok', 'reasons', 'warnings']. */
    public static function access(ClubMember $p, string $facility, ?Carbon $at = null, int $pax = 1): array
    {
        $at ??= now();
        $today = self::today();
        $m = $p->membership;
        $plan = $m->plan;
        $no = [];
        $warn = [];
        if (! array_key_exists($facility, ClubPlan::FACILITIES)) {
            throw new RuntimeException('Choose a facility.');
        }
        if (! $p->is_active) {
            $no[] = 'This card has been deactivated.';
        }
        if ($m->status === 'cancelled') {
            $no[] = 'Membership cancelled'.($m->cancelled_on ? ' on '.dmy($m->cancelled_on) : '').'.';
        } elseif ($m->status === 'expired' || $m->end_date->toDateString() < $today) {
            $no[] = 'Membership expired on '.dmy($m->end_date).' – renew at the desk.';
        } elseif ($m->status === 'frozen' || ($m->frozen_from && $m->frozen_from->toDateString() <= $today && $m->frozen_to->toDateString() >= $today)) {
            $no[] = 'Membership frozen until '.dmy($m->frozen_to).'.';
        } elseif ($m->start_date->toDateString() > $today) {
            $no[] = 'Membership starts on '.dmy($m->start_date).'.';
        }
        [$od, $since] = ClubBillingService::overdue($m);
        if ($od > 0) {
            $no[] = 'Overdue balance '.money($od).' (due since '.dmy($since).').';
        }
        if (! in_array($facility, $plan->facilities ?? [], true)) {
            $no[] = "The {$plan->name} plan does not include the ".strtolower(ClubPlan::FACILITIES[$facility]).'.';
        }
        if ($plan->access_from && $plan->access_to) {
            $t = $at->format('H:i:s');
            if ($t < $plan->access_from || $t > $plan->access_to) {
                $no[] = 'Off-peak plan – access '.$plan->hoursLabel().' only.';
            }
        }
        if ($facility === 'gym' && $p->dob && $p->dob->age < 16) {
            $no[] = 'Under-16s may not use the gym.';
        }
        $open = self::openVisits()->where('club_member_id', $p->id)->where('facility', $facility)->first();
        if ($open) {
            $no[] = 'Already checked in at the '.strtolower(ClubPlan::FACILITIES[$facility]).' since '.$open->checked_in_at->format('H:i').'.';
        }
        $occ = self::occupancy()[$facility];
        if ($occ['cap'] > 0 && $occ['in'] + $pax > $occ['cap']) {
            $no[] = ClubPlan::FACILITIES[$facility]." is full ({$occ['in']}/{$occ['cap']}).";
        }
        if (! $p->health_declared_at) {
            $warn[] = 'No health declaration (PAR-Q) on file – ask the member to sign one.';
        }
        $bal = ClubBillingService::balance($m);
        if ($bal > 0.004 && $od <= 0) {
            $warn[] = 'Balance due '.money($bal).'.';
        }
        if ($m->status === 'active' && $m->end_date->toDateString() >= $today && $m->end_date->toDateString() <= Carbon::parse($today)->addDays(7)->toDateString() && ! $m->auto_renew) {
            $warn[] = 'Membership ends on '.dmy($m->end_date).' and does not auto-renew.';
        }

        return ['ok' => ! $no, 'reasons' => $no, 'warnings' => $warn];
    }

    private static function denied(array $attrs, array $reasons, ?User $u): never
    {
        ClubVisit::create(array_merge($attrs, ['checked_in_at' => now(), 'checked_out_at' => now(), 'denied' => true,
            'deny_reason' => mb_substr(implode(' ', $reasons), 0, 200), 'user_id' => $u?->id]));

        throw new RuntimeException('Entry refused: '.implode(' ', $reasons));
    }

    /**
     * Check a card holder in. A refusal is logged; a manager may override with a reason. Checking in to another
     * facility closes the previous visit (the member moved on).
     */
    public static function checkIn(ClubMember $p, string $facility, ?User $u = null, ?string $override = null, ?Carbon $at = null): ClubVisit
    {
        $chk = self::access($p, $facility, $at);
        $attrs = ['visitor_type' => 'member', 'club_member_id' => $p->id, 'name' => $p->name, 'facility' => $facility];
        if (! $chk['ok']) {
            if (! $override || trim($override) === '') {
                self::denied($attrs, $chk['reasons'], $u);
            }
            if (! $u || ! $u->hasPermission('club.manage')) {
                throw new RuntimeException('Only a club manager can override a refusal.');
            }
            if (collect($chk['reasons'])->contains(fn ($r) => str_starts_with($r, 'Already checked in'))) {
                throw new RuntimeException($chk['reasons'][0]);
            }
        }
        self::openVisits()->where('club_member_id', $p->id)->update(['checked_out_at' => $at ?? now()]);
        $v = ClubVisit::create(array_merge($attrs, ['checked_in_at' => $at ?? now(), 'user_id' => $u?->id,
            'override_reason' => $chk['ok'] ? null : mb_substr(trim($override), 0, 200), 'override_by' => $chk['ok'] ? null : $u?->id]));
        if (! $chk['ok']) {
            ActivityLogger::log('club', 'override', "Entry override for {$p->name} ({$p->card_no}) – ".implode(' ', $chk['reasons'])." Reason: {$override}", $v);
        }

        return $v;
    }

    /** Free guest visits left this month for the membership. */
    public static function guestPassesLeft(ClubMembership $m, ?string $asOf = null): int
    {
        $d = Carbon::parse($asOf ?? self::today());
        $used = ClubVisit::where('visitor_type', 'guest')->where('denied', false)->whereNull('club_day_pass_id')->whereNull('club_charge_id')
            ->whereIn('host_member_id', $m->members()->pluck('id'))
            ->whereBetween('checked_in_at', [$d->copy()->startOfMonth(), $d->copy()->endOfMonth()->endOfDay()])->count();

        return max(0, (int) $m->plan->guest_passes - $used);
    }

    /**
     * A member brings a guest. The free monthly allowance is used first; beyond it a guest pass is charged to the
     * member's account (or paid now by $modeId).
     */
    public static function checkInGuest(ClubMember $host, string $name, string $facility, ?User $u = null, ?int $modeId = null, ?Carbon $at = null): ClubVisit
    {
        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException("The guest's name is required.");
        }
        $chk = self::access($host, $facility, $at);
        $chk['reasons'] = array_values(array_filter($chk['reasons'], fn ($r) => ! str_starts_with($r, 'Already checked in')));
        if ($chk['reasons']) {
            self::denied(['visitor_type' => 'guest', 'host_member_id' => $host->id, 'name' => $name, 'facility' => $facility], $chk['reasons'], $u);
        }
        $m = $host->membership;

        return DB::transaction(function () use ($host, $name, $facility, $u, $modeId, $at, $m) {
            $passId = null;
            $chargeId = null;
            if (self::guestPassesLeft($m) <= 0) {
                $price = (float) self::cfg('club_guestpass');
                if ($modeId) {
                    $passId = self::sellDayPass(['kind' => 'guest', 'name' => $name, 'adults' => 1, 'host_member_id' => $host->id, 'payment_mode_id' => $modeId, 'amount' => $price], $u, null, false)->id;
                } else {
                    $chargeId = ClubBillingService::raise($m, 'guest_pass', "Guest pass – {$name} (guest of {$host->name})", $price, ['due_date' => self::today()], $u)->id;
                }
            }

            return ClubVisit::create(['visitor_type' => 'guest', 'host_member_id' => $host->id, 'club_day_pass_id' => $passId, 'club_charge_id' => $chargeId,
                'name' => mb_substr($name, 0, 120), 'facility' => $facility, 'checked_in_at' => $at ?? now(), 'user_id' => $u?->id]);
        });
    }

    /** In-house stay for a room number (checked in today). */
    public static function residentStay(string $roomNo): ?ReservationRoom
    {
        return ReservationRoom::with('room', 'guest', 'reservation.guest')->where('status', 'checked_in')
            ->whereHas('room', fn ($q) => $q->where('room_no', trim($roomNo)))->first();
    }

    public static function checkInResident(string $roomNo, string $facility, int $pax = 1, ?User $u = null, ?string $override = null, ?Carbon $at = null): ClubVisit
    {
        $stay = self::residentStay($roomNo);
        if (! $stay) {
            throw new RuntimeException("Room {$roomNo} has no in-house guest.");
        }
        $name = $stay->guest?->full_name ?? $stay->reservation?->guest?->full_name ?? 'Room '.$roomNo;
        $pax = max(1, min($pax, (int) ($stay->adults ?? 1) + (int) ($stay->children ?? 0)));
        $no = [];
        if (! array_key_exists($facility, ClubPlan::FACILITIES)) {
            throw new RuntimeException('Choose a facility.');
        }
        if (! (int) self::cfg('club_resident_free') || ! in_array($facility, self::RESIDENT_FACILITIES, true)) {
            $no[] = 'Hotel guests need a day pass for the '.strtolower(ClubPlan::FACILITIES[$facility]).' (charge it to the room).';
        }
        $occ = self::occupancy()[$facility];
        if ($occ['cap'] > 0 && $occ['in'] + $pax > $occ['cap']) {
            $no[] = ClubPlan::FACILITIES[$facility]." is full ({$occ['in']}/{$occ['cap']}).";
        }
        $attrs = ['visitor_type' => 'resident', 'reservation_room_id' => $stay->id, 'name' => "{$name} (Rm {$roomNo})", 'facility' => $facility, 'pax' => $pax];
        if ($no) {
            if (! $override || ! $u?->hasPermission('club.manage')) {
                self::denied($attrs, $no, $u);
            }
        }

        return ClubVisit::create(array_merge($attrs, ['checked_in_at' => $at ?? now(), 'user_id' => $u?->id,
            'override_reason' => $no ? mb_substr($override, 0, 200) : null, 'override_by' => $no ? $u?->id : null]));
    }

    public static function checkOut(ClubVisit $v, ?Carbon $at = null): void
    {
        if ($v->denied || $v->checked_out_at) {
            throw new RuntimeException('This visit is already closed.');
        }
        $v->update(['checked_out_at' => $at ?? now()]);
    }

    // ───────────────────────── ticket redemption ─────────────────────────

    /**
     * v7.38 Check in a walk-in on a ticket / day pass sold at the recreation outlet or the desk.
     * Members use their card as before; walk-ins scan the ticket code from the receipt.
     */
    public static function checkInPass(ClubDayPass $pass, string $facility, ?User $u = null, ?string $override = null, ?Carbon $at = null): ClubVisit
    {
        if ($pass->cancelled_at) {
            throw new RuntimeException("Ticket {$pass->pass_no} is cancelled.");
        }
        if ($pass->date->toDateString() !== self::today()) {
            throw new RuntimeException("Ticket {$pass->pass_no} is for {$pass->date->format('d M Y')} — not today.");
        }
        if (self::openVisits()->where('club_day_pass_id', $pass->id)->exists()) {
            throw new RuntimeException("Ticket {$pass->pass_no} is already checked in — look under “In now”.");
        }
        if (! array_key_exists($facility, ClubPlan::FACILITIES)) {
            throw new RuntimeException('Choose a facility.');
        }
        $pax = (int) $pass->adults + (int) $pass->children;
        $no = [];
        $occ = self::occupancy()[$facility];
        if ($occ['cap'] > 0 && $occ['in'] + $pax > $occ['cap']) {
            $no[] = ClubPlan::FACILITIES[$facility]." is full ({$occ['in']}/{$occ['cap']}).";
        }
        $attrs = ['visitor_type' => 'day_pass', 'club_day_pass_id' => $pass->id, 'host_member_id' => $pass->host_member_id,
            'name' => $pass->name, 'facility' => $facility, 'pax' => $pax];
        if ($no && (! $override || ! $u?->hasPermission('club.manage'))) {
            self::denied($attrs, $no, $u);
        }
        $v = ClubVisit::create(array_merge($attrs, ['checked_in_at' => $at ?? now(), 'user_id' => $u?->id,
            'override_reason' => $no ? mb_substr(trim($override), 0, 200) : null, 'override_by' => $no ? $u?->id : null]));
        ActivityLogger::log('club', 'ticket_checkin', "Ticket {$pass->pass_no} – {$pass->name} ({$pax} pax) checked in at the ".strtolower(ClubPlan::FACILITIES[$facility]), $v);

        return $v;
    }

    // ───────────────────────── day passes ─────────────────────────

    /**
     * Sell a day pass: paid now ($d['payment_mode_id']) or charged to an in-house room ($d['room_no']). With $facility
     * the visitors are checked in straight away.
     */
    public static function sellDayPass(array $d, ?User $u = null, ?string $facility = null, bool $log = true, ?string $asOf = null): ClubDayPass
    {
        $name = trim($d['name'] ?? '');
        if ($name === '') {
            throw new RuntimeException('Name is required.');
        }
        $adults = max(0, (int) ($d['adults'] ?? 1));
        $children = max(0, (int) ($d['children'] ?? 0));
        if ($adults + $children < 1 || $adults + $children > 10) {
            throw new RuntimeException('A pass covers 1 to 10 people.');
        }
        $kind = ($d['kind'] ?? 'day') === 'guest' ? 'guest' : 'day';
        $net = isset($d['amount']) ? round((float) $d['amount'], 2)
            : round($adults * (float) self::cfg('club_daypass_adult') + $children * (float) self::cfg('club_daypass_child'), 2);
        $stay = ! empty($d['room_no']) ? self::residentStay($d['room_no']) : null;
        if (! empty($d['room_no']) && ! $stay) {
            throw new RuntimeException("Room {$d['room_no']} has no in-house guest to charge.");
        }
        if (! $stay && empty($d['payment_mode_id'])) {
            throw new RuntimeException('Take payment or charge the pass to a room.');
        }
        if ($facility) {
            $occ = self::occupancy()[$facility] ?? throw new RuntimeException('Choose a facility.');
            if ($occ['cap'] > 0 && $occ['in'] + $adults + $children > $occ['cap']) {
                throw new RuntimeException(ClubPlan::FACILITIES[$facility]." is full ({$occ['in']}/{$occ['cap']}).");
            }
        }
        $t = ClubBillingService::tax($net);
        $net = $t['net']; // v7.39 tax-inclusive prices are split into net + tax here
        $date = $asOf ?? self::today();

        return DB::transaction(function () use ($d, $u, $facility, $log, $name, $adults, $children, $kind, $net, $stay, $t, $date) {
            $p = ClubDayPass::create([
                'pass_no' => Numbering::next('club_daypass'),
                'date' => $date,
                'kind' => $kind,
                'name' => mb_substr($name, 0, 120),
                'phone' => $d['phone'] ?? null,
                'adults' => $adults,
                'children' => $children,
                'host_member_id' => $d['host_member_id'] ?? null,
                'amount' => $net,
                'tax_rate' => $t['rate'],
                'tax' => $t['tax'],
                'total' => $t['total'],
                'payment_mode_id' => $stay ? null : (int) $d['payment_mode_id'],
                'reservation_room_id' => $stay?->id,
                'reference' => $d['reference'] ?? null,
                'user_id' => $u?->id,
            ]);
            if ($stay) {
                $folio = FolioService::openForStay($stay);
                $tx = FolioService::charge($folio, 'club', "Health club day pass {$p->pass_no} – {$name}", $net, null, ['tax_rate' => $t['rate'], 'source_type' => 'club', 'source_id' => $p->id]);
                $p->update(['folio_transaction_id' => $tx->id]);
            }
            if ($facility) {
                ClubVisit::create(['visitor_type' => $kind === 'guest' ? 'guest' : 'day_pass', 'club_day_pass_id' => $p->id, 'host_member_id' => $p->host_member_id,
                    'name' => $p->name, 'facility' => $facility, 'pax' => $adults + $children, 'checked_in_at' => now(), 'user_id' => $u?->id]);
            }
            if ($log) {
                ActivityLogger::log('club', 'day_pass', "Day pass {$p->pass_no} – {$name} ".money($p->total).($stay ? ' to room '.$stay->room?->room_no : ''), $p);
            }

            return $p;
        });
    }

    public static function cancelDayPass(ClubDayPass $p, string $reason, ?User $u = null): void
    {
        if ($p->cancelled_at) {
            throw new RuntimeException('Already cancelled.');
        }
        if ($p->date->toDateString() !== self::today()) {
            throw new RuntimeException('A day pass can only be cancelled on the day it was sold.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give a reason.');
        }
        DB::transaction(function () use ($p, $reason, $u) {
            if ($p->folio_transaction_id && ($tx = \App\Models\FolioTransaction::find($p->folio_transaction_id)) && ! $tx->is_void) {
                FolioService::void($tx, 'Day pass cancelled – '.$reason);
            }
            $p->update(['cancelled_at' => now(), 'cancelled_by' => $u?->id, 'cancel_reason' => mb_substr($reason, 0, 255)]);
            ClubVisit::where('club_day_pass_id', $p->id)->whereNull('checked_out_at')->update(['checked_out_at' => now()]);
        });
        ActivityLogger::log('club', 'day_pass_cancelled', "Day pass {$p->pass_no} cancelled – {$reason}", $p);
    }

    // ───────────────────────── dashboard ─────────────────────────

    public static function stats(): array
    {
        $today = self::today();
        $active = ClubMembership::with('plan')->whereIn('status', ['active', 'frozen'])->get();
        $mrr = $active->where('status', 'active')->sum(fn ($m) => (float) $m->plan->price / max(1, $m->plan->term_months));
        $arrears = ClubBillingService::arrears();
        $visits = ClubVisit::where('denied', false)->whereDate('checked_in_at', now()->toDateString());

        return [
            'active' => $active->where('status', 'active')->count(),
            'frozen' => $active->where('status', 'frozen')->count(),
            'cards' => ClubMember::where('is_active', true)->whereIn('club_membership_id', $active->pluck('id'))->count(),
            'mrr' => round($mrr, 2),
            'expiring' => ClubMembership::whereIn('status', ['active', 'frozen'])->whereDate('end_date', '>=', $today)->whereDate('end_date', '<=', Carbon::parse($today)->addDays(30)->toDateString())
                ->where(fn ($q) => $q->where('auto_renew', false)->orWhereNotNull('cancel_mode'))->count(),
            'arrears_count' => $arrears->count(),
            'arrears_amount' => round($arrears->sum('amount'), 2),
            'visits_today' => (clone $visits)->sum('pax'),
            'denied_today' => ClubVisit::where('denied', true)->whereDate('checked_in_at', now()->toDateString())->count(),
            'in_now' => (int) self::openVisits()->sum('pax'),
            'day_passes_today' => ClubDayPass::whereNull('cancelled_at')->where('date', $today)->count(),
            'unearned' => ClubBillingService::unearned($today),
        ];
    }

    /** POS: the member behind a card / membership number and their plan's F&B discount (active, not in arrears). */
    public static function memberDiscount(string $code): array
    {
        $found = ClubMember::with('membership.plan')->where('card_no', $code)->get();
        if ($found->isEmpty() && ($m = ClubMembership::where('membership_no', $code)->first())) {
            $found = ClubMember::with('membership.plan')->where('club_membership_id', $m->id)->where('relation', 'primary')->get();
        }
        $p = $found->first();
        if (! $p) {
            return ['member' => null, 'pct' => 0, 'reason' => "No club member with card {$code}."];
        }
        $m = $p->membership;
        $today = self::today();
        if (! $p->is_active || $m->status !== 'active' || $m->end_date->toDateString() < $today || $m->start_date->toDateString() > $today) {
            return ['member' => null, 'pct' => 0, 'reason' => "{$p->name}'s membership is not active – no member discount."];
        }
        if (ClubBillingService::overdue($m)[0] > 0) {
            return ['member' => null, 'pct' => 0, 'reason' => "{$p->name}'s membership is in arrears – no member discount."];
        }

        return ['member' => $p, 'pct' => (float) $m->plan->fnb_discount_pct, 'reason' => null];
    }

    /** Sidebar badge: memberships in arrears + non-renewing memberships ending within 7 days. */
    public static function attention(): int
    {
        $today = self::today();

        return ClubBillingService::arrears()->count()
            + ClubMembership::where('status', 'active')->whereDate('end_date', '>=', $today)->whereDate('end_date', '<=', Carbon::parse($today)->addDays(7)->toDateString())
                ->where(fn ($q) => $q->where('auto_renew', false)->orWhereNotNull('cancel_mode'))->count();
    }
}
