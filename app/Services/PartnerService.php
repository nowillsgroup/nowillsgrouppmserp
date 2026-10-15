<?php

namespace App\Services;

use App\Mail\GuestMessage;
use App\Models\AgentCommission;
use App\Models\BusinessSource;
use App\Models\Company;
use App\Models\Folio;
use App\Models\FolioTransaction;
use App\Models\Guest;
use App\Models\OnlinePayment;
use App\Models\PartnerAudit;
use App\Models\PartnerRequest;
use App\Models\PartnerUser;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Numbering;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * v7.4 Corporate & travel-agent portal (B2B extranet).
 *
 * Partner users belong to a company (corporate client or travel agent) and sign in with the hotel code, their e-mail and a
 * password they chose when accepting an invitation. They see their negotiated rate (active rate contract → net / discount
 * pricing and allotment; otherwise the best available rate less the company discount), book against live availability,
 * bill the stay to the company account (credit hold / limit checked – bookings that fail the check or exceed the company's
 * approval threshold wait as tentative for the hotel to approve), cancel within the contract terms, ask for changes or a
 * group quote, read invoices / statement, pay the outstanding balance online and (agents) follow their commission.
 */
class PartnerService
{
    public const DEFAULTS = [
        'partner_enabled' => '1', 'partner_cancel' => '1', 'partner_pay' => '1', 'partner_amend' => '1',
        'partner_window_days' => '365', 'partner_max_nights' => '30', 'partner_idle' => '30', 'partner_invite_days' => '7',
        'partner_notify_email' => '',
        'partner_welcome' => 'Book your negotiated rates, manage your travellers and settle your account online – 24/7.',
    ];

    public const MAX_FAILS = 5;

    public const LOCK_MINUTES = 15;

    // ------------------------------------------------------------------ settings

    public static function settings(): array
    {
        $out = [];
        foreach (self::DEFAULTS as $k => $v) {
            $out[$k] = (string) setting($k, $v);
        }

        return $out;
    }

    public static function enabled(): bool
    {
        return setting('partner_enabled', '1') === '1';
    }

    public static function saveSettings(array $d): void
    {
        foreach (['partner_window_days' => [30, 730, 'Partners can book 30 to 730 days ahead.'], 'partner_max_nights' => [1, 90, 'A portal booking is 1 to 90 nights.'],
            'partner_idle' => [5, 240, 'Idle sign-out is 5 to 240 minutes.'], 'partner_invite_days' => [1, 30, 'An invitation is valid 1 to 30 days.']] as $k => [$lo, $hi, $msg]) {
            self::need(is_numeric($d[$k] ?? null) && $d[$k] >= $lo && $d[$k] <= $hi, $msg);
        }
        $mail = trim((string) ($d['partner_notify_email'] ?? ''));
        self::need($mail === '' || filter_var($mail, FILTER_VALIDATE_EMAIL), 'Enter a valid notification e-mail (or leave it empty).');
        $vals = ['partner_notify_email' => $mail, 'partner_welcome' => mb_substr(trim((string) ($d['partner_welcome'] ?? '')), 0, 250) ?: self::DEFAULTS['partner_welcome']];
        foreach (['partner_window_days', 'partner_max_nights', 'partner_idle', 'partner_invite_days'] as $k) {
            $vals[$k] = (string) (int) $d[$k];
        }
        foreach (['partner_enabled', 'partner_cancel', 'partner_pay', 'partner_amend'] as $k) {
            $vals[$k] = ! empty($d[$k]) ? '1' : '0';
        }
        foreach ($vals as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        Setting::$version++;
        ActivityLogger::log('partner', 'settings', 'Partner portal settings updated');
    }

    /** Portal terms of a company (staff: Partner accounts › company). */
    public static function saveTerms(Company $c, array $d): void
    {
        self::need(in_array($c->type, ['corporate', 'travel_agent']), 'Only corporate clients and travel agents can use the partner portal.');
        $over = ($d['portal_approval_over'] ?? '') === '' ? null : (float) $d['portal_approval_over'];
        self::need($over === null || $over >= 0, 'The approval threshold cannot be negative.');
        $max = (int) ($d['portal_max_rooms'] ?? 5);
        self::need($max >= 1 && $max <= 50, 'Rooms per booking must be 1 to 50.');
        $c->forceFill(['portal_enabled' => ! empty($d['portal_enabled']), 'portal_guest_pay' => ! empty($d['portal_guest_pay']),
            'portal_approval_over' => $over, 'portal_max_rooms' => $max])->save();
        ActivityLogger::log('partner', 'terms', "Partner portal terms updated for {$c->name}".($c->portal_enabled ? '' : ' (portal off)'), $c);
    }

    // ------------------------------------------------------------------ audit

    public static function audit(?PartnerUser $u, string $action, ?string $detail = null, ?int $companyId = null): void
    {
        $req = app()->bound('request') ? request() : null;
        PartnerAudit::create(['company_id' => $companyId ?? $u?->company_id, 'partner_user_id' => $u?->id, 'action' => $action,
            'detail' => $detail ? mb_substr($detail, 0, 300) : null, 'ip' => $req?->ip(), 'user_agent' => $req ? mb_substr((string) $req->userAgent(), 0, 190) : null,
            'created_at' => now()]);
    }

    public static function eligible(?Company $c): bool
    {
        return $c && $c->is_active && $c->portal_enabled && in_array($c->type, ['corporate', 'travel_agent']);
    }

    // ------------------------------------------------------------------ accounts

    /** Invite a partner user (by staff or by the company's own portal administrator). Returns [user, plain token]. */
    public static function invite(Company $c, array $d, ?User $staff = null, ?PartnerUser $by = null): array
    {
        self::need(in_array($c->type, ['corporate', 'travel_agent']), 'Only corporate clients and travel agents can use the partner portal.');
        self::need(! $by || ($by->company_id === $c->id && $by->can('users')), 'Only your company administrator can add users.');
        $email = Str::lower(trim((string) ($d['email'] ?? '')));
        $name = trim((string) ($d['name'] ?? ''));
        self::need(mb_strlen($name) >= 2, 'Enter the person\'s name.');
        self::need((bool) filter_var($email, FILTER_VALIDATE_EMAIL), 'Enter a valid e-mail address.');
        self::need(isset(PartnerUser::ROLES[$d['role'] ?? '']), 'Choose a role.');
        self::need(! PartnerUser::where('email', $email)->exists(), "{$email} already has a partner login at this hotel.");
        $u = new PartnerUser(['company_id' => $c->id, 'name' => mb_substr($name, 0, 120), 'email' => $email, 'phone' => mb_substr(trim((string) ($d['phone'] ?? '')), 0, 30) ?: null,
            'role' => $d['role'], 'is_active' => true, 'created_by' => $staff?->id]);
        $u->save();
        $token = self::issueInvite($u);
        self::audit($by, 'user', "Invited {$u->name} <{$u->email}> as ".PartnerUser::ROLES[$u->role].($staff ? " (by hotel: {$staff->name})" : ''), $c->id);
        ActivityLogger::log('partner', 'invite', "Partner portal invitation for {$u->name} ({$c->name})", $c);

        return [$u, $token];
    }

    public static function issueInvite(PartnerUser $u): string
    {
        $token = Str::random(48);
        $u->forceFill(['invite_token' => hash('sha256', $token), 'invite_expires_at' => now()->addDays((int) setting('partner_invite_days', 7))])->save();
        $link = route('partner.invite', $token);
        $h = hotel();
        rescue(fn () => MailService::send($u->email, new GuestMessage("Your {$h->name} partner portal invitation",
            "Dear {$u->name},\n\n{$h->name} has opened a partner portal account for {$u->company->name}. Use the link below to choose your password – it is valid until "
            .$u->invite_expires_at->format('d M Y H:i').".\n\n{$link}\n\nHotel ID: {$h->code}\nLogin: {$u->email}\n\nIn the portal you can book your negotiated rates, manage bookings, view invoices and statements and pay online.", $h), 'partner_invite', $u), null, true);

        return $token;
    }

    public static function setActive(PartnerUser $u, bool $on, ?PartnerUser $by = null): void
    {
        self::need(! $by || ($by->company_id === $u->company_id && $by->can('users')), 'Only your company administrator can change users.');
        self::need(! ($by && $by->id === $u->id && ! $on), 'You cannot disable your own login.');
        $u->forceFill(['is_active' => $on] + ($on ? [] : ['reset_token' => null, 'invite_token' => null]))->save();
        self::audit($by, 'user', ($on ? 'Enabled ' : 'Disabled ')."{$u->name} <{$u->email}>".($by ? '' : ' (by hotel)'), $u->company_id);
    }

    public static function setRole(PartnerUser $u, string $role, PartnerUser $by): void
    {
        self::need($by->company_id === $u->company_id && $by->can('users'), 'Only your company administrator can change users.');
        self::need(isset(PartnerUser::ROLES[$role]), 'Choose a role.');
        self::need($by->id !== $u->id, 'Ask another administrator to change your own role.');
        $u->forceFill(['role' => $role])->save();
        self::audit($by, 'user', "{$u->name} is now ".PartnerUser::ROLES[$role], $u->company_id);
    }

    public static function unlock(PartnerUser $u): void
    {
        $u->forceFill(['locked_until' => null, 'failed_logins' => 0])->save();
        self::audit(null, 'unlocked', "{$u->name} <{$u->email}> unlocked by hotel", $u->company_id);
    }

    // ------------------------------------------------------------------ authentication

    public static function passwordProblem(string $pw, ?PartnerUser $u = null): ?string
    {
        if (mb_strlen($pw) < 10) {
            return 'Use at least 10 characters.';
        }
        if (! preg_match('/[A-Za-z]/', $pw) || ! preg_match('/\d/', $pw)) {
            return 'Use letters and numbers.';
        }
        if ($u && ($local = Str::before($u->email, '@')) && mb_strlen($local) >= 4 && str_contains(Str::lower($pw), Str::lower($local))) {
            return 'The password must not contain your e-mail name.';
        }

        return null;
    }

    public static function byToken(string $column, string $token): ?PartnerUser
    {
        if (strlen($token) < 40) {
            return null;
        }
        $u = PartnerUser::where($column, hash('sha256', $token))->where('is_active', true)->first();
        $exp = $column === 'invite_token' ? $u?->invite_expires_at : $u?->reset_expires_at;

        return $u && $exp && $exp->isFuture() ? $u : null;
    }

    /** Invitation accepted or password reset: set the password, burn the token. */
    public static function setPassword(PartnerUser $u, string $pw, string $confirm, string $via): void
    {
        self::need($pw === $confirm, 'The two passwords do not match.');
        if ($p = self::passwordProblem($pw, $u)) {
            throw new RuntimeException($p);
        }
        $u->forceFill(['password' => Hash::make($pw), 'password_changed_at' => now(), 'invite_token' => null, 'invite_expires_at' => null,
            'reset_token' => null, 'reset_expires_at' => null, 'failed_logins' => 0, 'locked_until' => null])->save();
        self::audit($u, 'password', $via === 'invite' ? 'Invitation accepted – password set' : ($via === 'reset' ? 'Password reset by e-mail link' : 'Password changed'));
    }

    public static function changePassword(PartnerUser $u, string $current, string $pw, string $confirm): void
    {
        self::need(Hash::check($current, (string) $u->password), 'Your current password is not correct.');
        self::need(! Hash::check($pw, (string) $u->password), 'Choose a password different from the current one.');
        self::setPassword($u, $pw, $confirm, 'change');
    }

    /** Sign-in check (tenant already set from the hotel code). Generic error so e-mail addresses cannot be probed. */
    public static function attempt(string $email, string $pw): PartnerUser
    {
        $email = Str::lower(trim($email));
        $generic = 'Invalid hotel ID, e-mail or password.';
        $u = PartnerUser::with('company')->where('email', $email)->first();
        if (! $u || $u->password === null) {
            Hash::check($pw, '$2y$12$abcdefghijklmnopqrstuuJ0x5Qe2L7d1b8Zb1Wq9aYw7S0cQ5K2e'); // timing equaliser
            self::audit($u, 'login_failed', $u ? 'Invitation not accepted yet' : 'Unknown e-mail '.mb_substr($email, 0, 120));
            throw new RuntimeException($u ? 'Please accept your invitation e-mail and choose a password first.' : $generic);
        }
        if ($u->isLocked()) {
            self::audit($u, 'login_blocked', 'Sign-in attempt while locked');
            throw new RuntimeException('Too many failed attempts – this login is locked for '.max(1, (int) ceil(now()->diffInSeconds($u->locked_until) / 60)).' more minute(s).');
        }
        if (! Hash::check($pw, $u->password)) {
            $fails = $u->failed_logins + 1;
            if ($fails >= self::MAX_FAILS) {
                $u->forceFill(['failed_logins' => 0, 'locked_until' => now()->addMinutes(self::LOCK_MINUTES)])->save();
                self::audit($u, 'locked', self::MAX_FAILS.' failed passwords – locked for '.self::LOCK_MINUTES.' minutes');
            } else {
                $u->forceFill(['failed_logins' => $fails])->save();
                self::audit($u, 'login_failed', "Wrong password ({$fails}/".self::MAX_FAILS.')');
            }
            throw new RuntimeException($generic);
        }
        if (! $u->is_active || ! self::eligible($u->company) || ! self::enabled()) {
            self::audit($u, 'login_failed', 'Access disabled');
            throw new RuntimeException('Portal access for this login is disabled – please contact the hotel.');
        }
        $u->forceFill(['failed_logins' => 0, 'locked_until' => null, 'last_login_at' => now(), 'last_login_ip' => request()->ip()])->save();
        self::audit($u, 'login', 'Signed in');

        return $u;
    }

    /** "Forgot password": always answers the same; only a real, active, accepted login gets a 60-minute link. */
    public static function requestReset(string $email): void
    {
        $u = PartnerUser::with('company')->where('email', Str::lower(trim($email)))->where('is_active', true)->first();
        if (! $u || $u->password === null || ! self::eligible($u->company)) {
            return;
        }
        $token = Str::random(48);
        $u->forceFill(['reset_token' => hash('sha256', $token), 'reset_expires_at' => now()->addMinutes(60)])->save();
        $h = hotel();
        rescue(fn () => MailService::send($u->email, new GuestMessage("{$h->name} partner portal – reset your password",
            "Dear {$u->name},\n\nSomeone (hopefully you) asked to reset your partner portal password. The link is valid for 60 minutes:\n\n".route('partner.reset', $token)
            ."\n\nIf you did not ask for this, ignore this e-mail – your password is unchanged.", $h), 'partner_reset', $u), null, true);
        self::audit($u, 'reset_requested', 'Password reset link e-mailed');
    }

    // ------------------------------------------------------------------ rates & availability

    /**
     * Rate for one room of a type: the company's active contract (net / discount, its allotment) when one covers the arrival,
     * otherwise the best available rate less the company discount. Keys as RateService::quote plus public_total, saving,
     * contract_id, label, meal_plan.
     */
    public static function quote(Company $c, RoomType $t, $from, $to, int $adults = 1): array
    {
        $rc = ContractService::forCompany($c->id, $from);
        if ($rc) {
            return ContractService::quote($rc, $t, $from, $to, $adults) + ['label' => 'Contract '.$rc->code, 'meal_plan' => $rc->meal_plan, 'cancel_days' => (int) $rc->cancel_days];
        }
        $bar = RateService::quote($t, $from, $to);
        $disc = max(0, min(90, (float) $c->discount_pct));
        $nights = array_map(fn ($n) => ['date' => $n['date'], 'rate' => round((float) $n['rate'] * (1 - $disc / 100), 2), 'public_rate' => (float) $n['rate'],
            'season' => $n['season'], 'basis' => $disc > 0 ? 'discount' : 'public', 'why' => null], $bar['nights']);
        $total = round(array_sum(array_column($nights, 'rate')), 2);

        return ['nights' => $nights, 'count' => count($nights), 'total' => $total, 'average' => round($total / max(1, count($nights)), 2),
            'public_total' => $bar['total'], 'saving' => round($bar['total'] - $total, 2), 'contract' => null, 'contract_id' => null, 'lra' => false,
            'min_stay' => $bar['min_stay'], 'stop_sell' => $bar['stop_sell'], 'min_stay_ok' => $bar['min_stay_ok'],
            'label' => $disc > 0 ? 'Corporate discount '.rtrim(rtrim(number_format($disc, 2), '0'), '.').'%' : 'Best available rate', 'meal_plan' => null, 'cancel_days' => null];
    }

    public static function checkDates($from, $to): array
    {
        try {
            $a = Carbon::parse((string) $from)->startOfDay();
            $b = Carbon::parse((string) $to)->startOfDay();
        } catch (\Throwable) {
            throw new RuntimeException('Choose valid arrival and departure dates.');
        }
        self::need((string) $from !== '' && (string) $to !== '', 'Choose arrival and departure dates.');
        self::need($a->gte(bdate()->copy()->startOfDay()), 'Arrival cannot be in the past.');
        self::need($b->gt($a), 'Departure must be after arrival.');
        self::need($a->diffInDays($b) <= (int) setting('partner_max_nights', 30), 'A portal booking is at most '.setting('partner_max_nights', 30).' nights – contact the hotel for longer stays.');
        self::need($a->lte(bdate()->copy()->addDays((int) setting('partner_window_days', 365))), 'Bookings open '.setting('partner_window_days', 365).' days ahead.');

        return [$a, $b];
    }

    /** Room types with rate + availability for the partner search. */
    public static function search(Company $c, $from, $to, int $adults, int $rooms): array
    {
        [$a, $b] = self::checkDates($from, $to);
        self::need($rooms >= 1 && $rooms <= max(1, (int) $c->portal_max_rooms), 'You can book 1 to '.$c->portal_max_rooms.' rooms at a time – send a group request for more.');
        self::need($adults >= $rooms && $adults <= $rooms * 4, 'At least one and at most four adults per room.');
        $perRoom = (int) ceil($adults / $rooms);
        $rc = ContractService::forCompany($c->id, $a);
        $out = [];
        foreach (RoomType::where('is_active', true)->orderBy('base_rate')->get() as $t) {
            $q = self::quote($c, $t, $a, $b, $perRoom);
            $avail = AvailabilityService::typeAvailable($t->id, $a, $b, null, $rc?->id);
            $why = match (true) {
                $t->max_adults && $perRoom > $t->max_adults => "Sleeps {$t->max_adults} adult(s) per room",
                (bool) $q['stop_sell'] => 'Closed for sale on these dates',
                ! $q['min_stay_ok'] => "Minimum stay {$q['min_stay']} nights",
                $avail < 1 => 'Sold out',
                $avail < $rooms => "Only {$avail} room(s) left",
                default => null,
            };
            $out[] = ['type' => $t, 'available' => $avail, 'quote' => $q, 'per_room' => $perRoom, 'total' => round($q['total'] * $rooms, 2), 'why' => $why];
        }

        return ['rows' => $out, 'from' => $a, 'to' => $b, 'nights' => $a->diffInDays($b), 'rooms' => $rooms, 'adults' => $adults, 'contract' => $rc];
    }

    // ------------------------------------------------------------------ booking

    /** Reasons a company-billed amount cannot go straight through (credit hold / over the limit). */
    public static function creditProblems(Company $c, float $amount): array
    {
        $p = CreditService::position($c);
        $out = [];
        if ($c->onHold()) {
            $out[] = 'account on credit hold'.($c->credit_hold_reason ? " ({$c->credit_hold_reason})" : '');
        }
        if ($p['limit'] > 0 && max(0, $p['exposure'] ?? $p['outstanding']) + $amount > $p['limit'] + 0.004) {
            $out[] = 'credit limit '.money($p['limit']).' would be exceeded ('.money(max(0, $p['exposure'] ?? $p['outstanding']) + $amount).' incl. this booking)';
        }

        return $out;
    }

    /** Book from the portal. Returns [reservation, approval request|null]. */
    public static function book(PartnerUser $u, array $d): array
    {
        $c = $u->company;
        self::need(self::eligible($c) && $u->is_active, 'Portal access for your company is disabled.');
        self::need($u->can('book'), 'Your role cannot make bookings.');
        $rooms = (int) ($d['rooms'] ?? 1);
        $adults = (int) ($d['adults'] ?? 1);
        [$a, $b] = self::checkDates($d['arrival'] ?? '', $d['departure'] ?? '');
        self::need($rooms >= 1 && $rooms <= max(1, (int) $c->portal_max_rooms), 'You can book 1 to '.$c->portal_max_rooms.' rooms at a time – send a group request for more.');
        self::need($adults >= $rooms && $adults <= $rooms * 4, 'At least one and at most four adults per room.');
        $t = RoomType::where('is_active', true)->find((int) ($d['room_type_id'] ?? 0));
        self::need((bool) $t, 'Choose a room type.');
        $perRoom = (int) ceil($adults / $rooms);
        self::need(! $t->max_adults || $perRoom <= $t->max_adults, "{$t->name} sleeps at most {$t->max_adults} adult(s) per room – book more rooms.");
        $first = trim((string) ($d['first_name'] ?? ''));
        $last = trim((string) ($d['last_name'] ?? ''));
        $email = Str::lower(trim((string) ($d['email'] ?? '')));
        self::need($first !== '' && $last !== '', 'Enter the traveller\'s first and last name.');
        self::need($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL), 'Enter a valid traveller e-mail (or leave it empty).');
        $q = self::quote($c, $t, $a, $b, $perRoom);
        self::need(! $q['stop_sell'], "{$t->name} is closed for sale on these dates.");
        self::need((bool) $q['min_stay_ok'], "A minimum stay of {$q['min_stay']} nights applies to these dates.");
        $total = round($q['total'] * $rooms, 2);
        $billTo = ($d['bill_to'] ?? 'company') === 'guest' ? 'guest' : 'company';
        if ($billTo === 'company') {
            self::need((bool) $c->direct_billing, 'Your account is not set up for direct billing – choose "Traveller pays at the hotel".');
        } else {
            self::need((bool) $c->portal_guest_pay, 'Your agreement requires bookings to be billed to the company account.');
        }
        $reasons = $billTo === 'company' ? self::creditProblems($c, $total) : [];
        if ($c->portal_approval_over !== null && $total > (float) $c->portal_approval_over) {
            $reasons[] = 'booking value '.money($total).' is over the '.money($c->portal_approval_over).' approval threshold';
        }
        $ref = mb_substr(trim((string) ($d['partner_ref'] ?? '')), 0, 60) ?: null;
        $note = mb_substr(trim((string) ($d['special_request'] ?? '')), 0, 500) ?: null;

        [$r, $req] = DB::transaction(function () use ($u, $c, $t, $a, $b, $rooms, $adults, $perRoom, $first, $last, $email, $q, $billTo, $reasons, $ref, $note, $d) {
            AvailabilityService::lockRoomInventory([$t->id]);
            if (AvailabilityService::typeAvailable($t->id, $a, $b, null, $q['contract_id']) < $rooms) {
                throw new RuntimeException("Sorry – not enough {$t->name} rooms are left for those dates.");
            }
            if ($email !== '' && Guest::where('email', $email)->where('first_name', $first)->where('is_blacklisted', true)->exists()) {
                throw new RuntimeException('This guest is blacklisted. Portal booking blocked.');
            }
            $guest = $email !== ''
                ? Guest::whereNull('privacy_status')->where('email', $email)->where('first_name', $first)->first()
                : null;
            $guest ??= Guest::create(['first_name' => $first, 'last_name' => $last, 'phone' => mb_substr(trim((string) ($d['phone'] ?? '')), 0, 30) ?: null, 'company_id' => $c->id]);
            if ($guest->is_blacklisted) {
                throw new RuntimeException('This guest is blacklisted. Portal booking blocked.');
            }
            $r = Reservation::create([
                'reservation_no' => Numbering::next('reservation'), 'guest_id' => $guest->id, 'company_id' => $c->id, 'rate_contract_id' => $q['contract_id'],
                'business_source_id' => BusinessSource::where('type', $c->type === 'travel_agent' ? 'travel_agent' : 'corporate')->value('id'),
                'arrival_date' => $a->toDateString(), 'departure_date' => $b->toDateString(), 'adults' => $adults, 'children' => max(0, min(10, (int) ($d['children'] ?? 0))),
                'status' => $reasons ? 'tentative' : 'confirmed', 'purpose' => $c->type === 'corporate' ? 'Business' : 'Leisure',
                'special_request' => $note, 'billing_instruction' => $billTo, 'partner_user_id' => $u->id, 'partner_ref' => $ref,
            ]);
            for ($i = 0; $i < $rooms; $i++) {
                $r->rooms()->create(['room_type_id' => $t->id, 'guest_id' => $guest->id, 'arrival_date' => $a->toDateString(), 'departure_date' => $b->toDateString(),
                    'rate' => $q['average'], 'adults' => $i === $rooms - 1 ? max(1, $adults - $perRoom * ($rooms - 1)) : $perRoom, 'status' => 'reserved']);
            }
            $req = null;
            if ($reasons) {
                $req = PartnerRequest::create(['number' => Numbering::next('partner_request'), 'company_id' => $c->id, 'partner_user_id' => $u->id, 'reservation_id' => $r->id,
                    'type' => 'approval', 'status' => 'pending', 'reason' => mb_substr(ucfirst(implode('; ', $reasons)), 0, 300),
                    'payload' => ['total' => round($q['total'] * $rooms, 2), 'bill_to' => $billTo]]);
            }
            ActivityLogger::log('partner', 'booking', "Partner booking {$r->reservation_no} by {$u->name} ({$c->name}) – {$rooms} × {$t->name}".($req ? ' – awaiting approval' : ''), $r);
            self::audit($u, 'booking', "Booked {$r->reservation_no}: {$rooms} × {$t->name}, ".$a->format('d M').'–'.$b->format('d M').', '.money(round($q['total'] * $rooms, 2)).($req ? " – held for approval ({$req->number})" : ''));

            return [$r, $req];
        });
        if ($req) {
            self::notifyStaff("Partner booking awaiting approval: {$r->reservation_no}", "{$c->name} ({$u->name}) booked {$r->reservation_no} for ".money($total).".\nWhy it needs approval: {$req->reason}\n\n".route('partner-admin.requests'));
        } else {
            rescue(fn () => MailService::autoConfirm($r), null, true);
            rescue(fn () => MessageService::autoConfirm($r), null, true);
        }

        return [$r, $req];
    }

    public static function ownBooking(PartnerUser $u, Reservation $r): void
    {
        self::need((int) $r->company_id === (int) $u->company_id, 'Booking not found.');
    }

    public static function cancellable(Reservation $r): bool
    {
        return in_array($r->status, ['confirmed', 'tentative']) && $r->rooms->where('status', 'reserved')->isNotEmpty() && $r->rooms->where('status', 'checked_in')->isEmpty();
    }

    public static function cancelQuote(Reservation $r): array
    {
        return CancellationService::quote($r, $r->rooms()->where('status', 'reserved')->get());
    }

    /** Partner cancels: the house / contract policy applies (fee posted to the reservation's advance folio). Returns the fee. */
    public static function cancel(PartnerUser $u, Reservation $r, string $reason): float
    {
        self::ownBooking($u, $r);
        self::need($u->can('book'), 'Your role cannot cancel bookings.');
        self::need(setting('partner_cancel', '1') === '1', 'Online cancellation is switched off – please contact the hotel.');
        $r->loadMissing('rooms');
        self::need(self::cancellable($r), "Booking {$r->reservation_no} can no longer be cancelled online.");
        $reason = trim($reason);
        self::need(mb_strlen($reason) >= 3, 'Tell us briefly why you are cancelling.');
        [, $fee] = CancellationService::cancel($r, null, 'Partner portal: '.mb_substr($reason, 0, 200), false, null, " via partner portal ({$u->name})");
        PartnerRequest::where('reservation_id', $r->id)->where('status', 'pending')->update(['status' => 'withdrawn', 'decision_note' => 'Booking cancelled by the partner', 'decided_at' => now()]);
        self::audit($u, 'cancel', "Cancelled {$r->reservation_no}".($fee > 0 ? ' – fee '.money($fee) : ' – no fee').": {$reason}");

        return $fee;
    }

    /** Ask the hotel to change a booking (dates / rooms / anything else); reservations staff apply it and mark it done. */
    public static function amend(PartnerUser $u, Reservation $r, array $d): PartnerRequest
    {
        self::ownBooking($u, $r);
        self::need($u->can('book'), 'Your role cannot change bookings.');
        self::need(setting('partner_amend', '1') === '1', 'Change requests are switched off – please contact the hotel.');
        self::need(in_array($r->status, ['confirmed', 'tentative', 'checked_in']), "Booking {$r->reservation_no} cannot be changed.");
        $details = trim((string) ($d['details'] ?? ''));
        self::need(mb_strlen($details) >= 5, 'Describe the change you need.');
        $payload = array_filter(['arrival' => $d['arrival'] ?? null, 'departure' => $d['departure'] ?? null, 'rooms' => isset($d['rooms']) && $d['rooms'] !== '' ? (int) $d['rooms'] : null]);
        if (! empty($payload['arrival']) || ! empty($payload['departure'])) {
            self::checkDates($payload['arrival'] ?? $r->arrival_date->toDateString(), $payload['departure'] ?? $r->departure_date->toDateString());
        }
        $q = DB::transaction(function () use ($u, $r, $details, $payload) {
            // The pending-request check and insert share the reservation lock;
            // two portal clicks cannot open duplicate amendment requests.
            $locked = Reservation::whereKey($r->id)->lockForUpdate()->firstOrFail();
            self::need(in_array($locked->status, ['confirmed', 'tentative', 'checked_in']), "Booking {$locked->reservation_no} cannot be changed.");
            self::need(! PartnerRequest::where('reservation_id', $locked->id)->where('type', 'amendment')->where('status', 'pending')->exists(), 'There is already an open change request for this booking.');

            return PartnerRequest::create(['number' => Numbering::next('partner_request'), 'company_id' => $u->company_id, 'partner_user_id' => $u->id, 'reservation_id' => $locked->id,
                'type' => 'amendment', 'status' => 'pending', 'details' => mb_substr($details, 0, 2000), 'payload' => $payload ?: null]);
        });
        self::audit($u, 'request', "Change request {$q->number} for {$r->reservation_no}");
        self::notifyStaff("Partner change request {$q->number} ({$r->reservation_no})", "{$u->company->name} ({$u->name}) asks:\n{$details}\n\n".route('partner-admin.requests'));

        return $q;
    }

    public static function groupRequest(PartnerUser $u, array $d): PartnerRequest
    {
        self::need($u->can('book'), 'Your role cannot request group quotes.');
        [$a, $b] = self::checkDates($d['arrival'] ?? '', $d['departure'] ?? '');
        $rooms = (int) ($d['rooms'] ?? 0);
        self::need($rooms >= 2 && $rooms <= 200, 'A group request is for 2 to 200 rooms.');
        $details = trim((string) ($d['details'] ?? ''));
        $q = PartnerRequest::create(['number' => Numbering::next('partner_request'), 'company_id' => $u->company_id, 'partner_user_id' => $u->id,
            'type' => 'group', 'status' => 'pending', 'details' => mb_substr($details, 0, 2000) ?: null,
            'payload' => ['arrival' => $a->toDateString(), 'departure' => $b->toDateString(), 'rooms' => $rooms, 'adults' => max($rooms, (int) ($d['adults'] ?? $rooms)), 'name' => mb_substr(trim((string) ($d['name'] ?? '')), 0, 100) ?: null]]);
        self::audit($u, 'request', "Group quote request {$q->number}: {$rooms} rooms ".$a->format('d M').'–'.$b->format('d M'));
        self::notifyStaff("Partner group request {$q->number}", "{$u->company->name} ({$u->name}) asks for {$rooms} rooms, ".$a->format('d M').' – '.$b->format('d M Y').".\n{$details}\n\n".route('partner-admin.requests'));

        return $q;
    }

    public static function withdraw(PartnerUser $u, PartnerRequest $q): void
    {
        self::need($q->company_id === $u->company_id, 'Request not found.');
        DB::transaction(function () use ($u, $q) {
            $locked = PartnerRequest::whereKey($q->id)->lockForUpdate()->firstOrFail();
            self::need($locked->company_id === $u->company_id, 'Request not found.');
            self::need($locked->status === 'pending', 'Only a pending request can be withdrawn.');
            $r = $locked->reservation_id ? Reservation::whereKey($locked->reservation_id)->lockForUpdate()->first() : null;
            if ($locked->type === 'approval' && $r && $r->status === 'tentative') {
                CancellationService::cancel($r, null, 'Withdrawn by the partner before approval', true, 'Not approved yet', " via partner portal ({$u->name})");
            }
            $locked->update(['status' => 'withdrawn', 'decided_at' => now(), 'decision_note' => 'Withdrawn by '.$u->name]);
            self::audit($u, 'request', "Withdrew {$locked->number}");
        });
    }

    // ------------------------------------------------------------------ hotel decisions

    public static function approve(PartnerRequest $q, User $by, ?string $note = null): void
    {
        [$q, $r] = DB::transaction(function () use ($q, $by, $note) {
            $lockedQ = PartnerRequest::whereKey($q->id)->lockForUpdate()->firstOrFail();
            self::need($lockedQ->type === 'approval' && $lockedQ->status === 'pending', 'Only a pending booking approval can be approved.');
            $lockedR = $lockedQ->reservation_id ? Reservation::whereKey($lockedQ->reservation_id)->lockForUpdate()->first() : null;
            self::need($lockedR && $lockedR->status === 'tentative' && $lockedR->rooms()->where('status', 'reserved')->exists(), 'The booking is no longer waiting – decline the request instead.');
            $lockedR->update(['status' => 'confirmed']);
            $lockedQ->update(['status' => 'approved', 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => $note ? mb_substr($note, 0, 500) : null]);
            if ($lockedR->billing_instruction === 'company' && $lockedR->company) {
                CreditService::log($lockedR->company, 'override', "Partner booking {$lockedR->reservation_no} approved by {$by->name}: {$lockedQ->reason}".($note ? " – {$note}" : ''), (float) ($lockedQ->payload['total'] ?? 0));
            }
            ActivityLogger::log('partner', 'approve', "Approved partner booking {$lockedR->reservation_no} ({$lockedQ->number})", $lockedR);
            self::audit(null, 'approved', "Hotel approved {$lockedR->reservation_no} ({$lockedQ->number})".($note ? ": {$note}" : ''), $lockedQ->company_id);

            return [$lockedQ, $lockedR];
        });
        rescue(fn () => MailService::autoConfirm($r), null, true);
        rescue(fn () => MessageService::autoConfirm($r), null, true);
        self::notifyPartner($q, "Booking {$r->reservation_no} confirmed", "Good news – the hotel approved your booking {$r->reservation_no} (".$r->arrival_date->format('d M').' – '.$r->departure_date->format('d M Y').').'.($note ? "\n\nNote from the hotel: {$note}" : ''));
    }

    public static function decline(PartnerRequest $q, User $by, string $note): void
    {
        $note = trim($note);
        self::need(mb_strlen($note) >= 3, 'Tell the partner why (a short note is e-mailed to them).');
        [$q, $r] = DB::transaction(function () use ($q, $by, $note) {
            $lockedQ = PartnerRequest::whereKey($q->id)->lockForUpdate()->firstOrFail();
            self::need($lockedQ->status === 'pending', 'This request has already been answered.');
            $lockedR = $lockedQ->reservation_id ? Reservation::whereKey($lockedQ->reservation_id)->lockForUpdate()->first() : null;
            if ($lockedQ->type === 'approval' && $lockedR && in_array($lockedR->status, ['tentative', 'confirmed']) && $lockedR->rooms()->where('status', 'reserved')->exists()) {
                CancellationService::cancel($lockedR, null, 'Partner booking not approved: '.mb_substr($note, 0, 200), true, 'Declined by hotel', ' (partner approval declined)');
            }
            $lockedQ->update(['status' => 'declined', 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => mb_substr($note, 0, 500)]);
            ActivityLogger::log('partner', 'decline', "Declined partner request {$lockedQ->number}: {$note}", $lockedR);
            self::audit(null, 'declined', "Hotel declined {$lockedQ->number}: {$note}", $lockedQ->company_id);

            return [$lockedQ, $lockedR];
        });
        self::notifyPartner($q, PartnerRequest::TYPES[$q->type].' '.$q->number.' declined', 'The hotel could not accept your '.Str::lower(PartnerRequest::TYPES[$q->type])
            .($r ? ' for booking '.$r->reservation_no : '').".\n\nReason: {$note}");
    }

    /** Change / group requests: staff did what was asked (or could not) and close it with a note for the partner. */
    public static function complete(PartnerRequest $q, User $by, string $note): void
    {
        $note = trim($note);
        self::need(mb_strlen($note) >= 3, 'Add a short note for the partner (what was changed / quoted).');
        $q = DB::transaction(function () use ($q, $by, $note) {
            $locked = PartnerRequest::whereKey($q->id)->lockForUpdate()->firstOrFail();
            self::need(in_array($locked->type, ['amendment', 'group']) && $locked->status === 'pending', 'Only a pending change or group request can be completed.');
            $locked->update(['status' => 'done', 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => mb_substr($note, 0, 500)]);
            ActivityLogger::log('partner', 'done', "Completed partner request {$locked->number}: {$note}", $locked->reservation);
            self::audit(null, 'done', "Hotel completed {$locked->number}: {$note}", $locked->company_id);

            return $locked;
        });
        self::notifyPartner($q, PartnerRequest::TYPES[$q->type].' '.$q->number.' – done', "The hotel has answered your request {$q->number}:\n\n{$note}");
    }

    protected static function notifyPartner(PartnerRequest $q, string $subject, string $text): void
    {
        $to = $q->partnerUser?->email;
        if ($to) {
            rescue(fn () => MailService::send($to, new GuestMessage($subject, "Dear {$q->partnerUser->name},\n\n{$text}\n\n".route('partner.home'), hotel()), 'partner_update', $q), null, true);
        }
    }

    protected static function notifyStaff(string $subject, string $text): void
    {
        $to = setting('partner_notify_email', '');
        if ($to) {
            rescue(fn () => MailService::send($to, new GuestMessage($subject, $text, hotel()), 'partner_staff'), null, true);
        }
    }

    // ------------------------------------------------------------------ finance

    /** City-ledger lines (bills and receipts) with a running balance, as Accounts › City Ledger shows them. */
    public static function statement(Company $c): array
    {
        $debits = FolioTransaction::with('folio.guest')->where('category', 'city_ledger')->where('company_id', $c->id)->where('is_void', false)->get()
            ->map(fn ($t) => ['date' => $t->business_date, 'ref' => $t->folio->invoice_no ?? $t->folio->folio_no, 'desc' => 'Invoice – '.($t->folio->guest?->full_name ?? 'guest'), 'debit' => (float) $t->amount, 'credit' => 0.0, 'folio_id' => $t->folio_id]);
        $credits = Voucher::where('type', 'receipt')->where('company_id', $c->id)->get()
            ->map(fn ($v) => ['date' => $v->date, 'ref' => $v->voucher_no, 'desc' => 'Payment received'.($v->reference ? ' – '.$v->reference : ''), 'debit' => 0.0, 'credit' => (float) $v->amount, 'folio_id' => null]);
        $bal = 0.0;
        $rows = $debits->concat($credits)->sortBy(fn ($r) => $r['date']->format('Y-m-d').($r['debit'] > 0 ? '0' : '1'))->values()
            ->map(function ($r) use (&$bal) {
                $bal = round($bal + $r['debit'] - $r['credit'], 2);

                return $r + ['balance' => $bal];
            });

        return ['rows' => $rows, 'position' => CreditService::position($c), 'billed' => round($debits->sum('debit'), 2), 'paid' => round($credits->sum('credit'), 2)];
    }

    /** A folio the company was billed for (portal invoice view) – null when it is not theirs. */
    public static function invoice(Company $c, int $folioId): ?Folio
    {
        if (! FolioTransaction::where('folio_id', $folioId)->where('category', 'city_ledger')->where('company_id', $c->id)->where('is_void', false)->exists()) {
            return null;
        }

        return Folio::with(['guest', 'transactions' => fn ($q) => $q->where('is_void', false)->orderBy('business_date')->orderBy('id')])->find($folioId);
    }

    public static function pay(PartnerUser $u, float $amount): OnlinePayment
    {
        self::need($u->can('finance'), 'Your role cannot make payments.');
        self::need(setting('partner_pay', '1') === '1', 'Online payment is not available – please pay by bank transfer.');
        $c = $u->company;
        $out = round((float) CreditService::position($c)['outstanding'], 2);
        self::need($out > 0, 'Your account has nothing outstanding.');
        self::need($amount >= 1 && $amount <= $out + 0.009, 'Pay between '.money(1).' and the outstanding '.money($out).'.');
        $p = OnlinePaymentService::createCityLedger($c, $amount, ['name' => $u->name.' ('.$c->name.')', 'email' => $u->email, 'phone' => $u->phone,
            'description' => 'Account settlement – '.$c->name, 'minutes' => 60, 'source' => 'portal']);
        self::audit($u, 'payment', "Started online payment {$p->pay_no} for ".money($amount));

        return $p;
    }

    public static function commissions(Company $c): Collection
    {
        return AgentCommission::with('reservation.guest')->where('company_id', $c->id)->orderByDesc('business_date')->get();
    }

    // ------------------------------------------------------------------ dashboards

    /** Value of a stay line = nightly rate × nights. */
    public static function stayValue(ReservationRoom $s): float
    {
        return round((float) $s->rate * max(1, $s->nights), 2);
    }

    public static function partnerDashboard(PartnerUser $u): array
    {
        $c = $u->company;
        $today = bdate()->copy()->startOfDay();
        $arrivals = Reservation::with('guest', 'rooms.roomType')->where('company_id', $c->id)->whereIn('status', ['confirmed', 'tentative'])
            ->whereDate('arrival_date', '>=', $today->toDateString())->whereDate('arrival_date', '<=', $today->copy()->addDays(60)->toDateString())->orderBy('arrival_date')->limit(10)->get();
        $inHouse = ReservationRoom::with('guest', 'room', 'reservation')->where('status', 'checked_in')->whereHas('reservation', fn ($q) => $q->where('company_id', $c->id))->get();
        $ytd = ReservationRoom::whereHas('reservation', fn ($q) => $q->where('company_id', $c->id))->whereIn('status', ['reserved', 'checked_in', 'checked_out'])
            ->whereDate('arrival_date', '>=', $today->copy()->startOfYear()->toDateString())->whereDate('arrival_date', '<=', $today->copy()->endOfYear()->toDateString())->get();

        return [
            'arrivals' => $arrivals, 'inHouse' => $inHouse,
            'ytd' => ['stays' => $ytd->count(), 'nights' => $ytd->sum(fn ($s) => $s->nights), 'value' => round($ytd->sum(fn ($s) => self::stayValue($s)), 2)],
            'pending' => PartnerRequest::where('company_id', $c->id)->where('status', 'pending')->count(),
            'credit' => $c->direct_billing ? CreditService::position($c) : null,
            'commission_due' => $c->type === 'travel_agent' ? round((float) AgentCommission::where('company_id', $c->id)->where('status', 'due')->sum('amount'), 2) : null,
            'contract' => ContractService::forCompany($c->id, $today),
        ];
    }

    public static function staffDashboard(): array
    {
        $since = now()->subDays(30);
        $portal = Reservation::whereNotNull('partner_user_id')->where('created_at', '>=', $since)->with('rooms')->get();
        $live = $portal->where('status', '!=', 'cancelled');

        return [
            'kpi' => [
                'companies' => Company::where('portal_enabled', true)->count(),
                'users' => PartnerUser::where('is_active', true)->whereNotNull('password')->count(),
                'invited' => PartnerUser::where('is_active', true)->whereNull('password')->count(),
                'logins' => PartnerAudit::where('action', 'login')->where('created_at', '>=', $since)->count(),
                'bookings' => $live->count(),
                'nights' => $live->sum(fn ($r) => $r->rooms->where('status', '!=', 'cancelled')->sum(fn ($s) => $s->nights)),
                'value' => round($live->sum(fn ($r) => $r->rooms->where('status', '!=', 'cancelled')->sum(fn ($s) => self::stayValue($s))), 2),
                'cancelled' => $portal->where('status', 'cancelled')->count(),
                'approvals' => PartnerRequest::where('status', 'pending')->where('type', 'approval')->count(),
                'changes' => PartnerRequest::where('status', 'pending')->whereIn('type', ['amendment', 'group'])->count(),
                'failed' => PartnerAudit::whereIn('action', ['login_failed', 'locked', 'login_blocked'])->where('created_at', '>=', now()->subDays(7))->count(),
            ],
            'top' => $live->groupBy('company_id')->map(fn ($g) => ['company' => Company::find($g->first()->company_id), 'bookings' => $g->count(),
                'value' => round($g->sum(fn ($r) => $r->rooms->where('status', '!=', 'cancelled')->sum(fn ($s) => self::stayValue($s))), 2)])->sortByDesc('value')->take(6)->values(),
            'pending' => PartnerRequest::with('company', 'partnerUser', 'reservation')->where('status', 'pending')->orderBy('created_at')->limit(8)->get(),
            'recent' => PartnerAudit::with('company', 'partnerUser')->orderByDesc('id')->limit(12)->get(),
        ];
    }

    public static function pendingCount(): int
    {
        return PartnerRequest::where('status', 'pending')->count();
    }

    protected static function need(bool $ok, string $msg = 'You do not have permission to do that.'): void
    {
        if (! $ok) {
            throw new RuntimeException($msg);
        }
    }
}
