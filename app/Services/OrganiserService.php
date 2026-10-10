<?php

namespace App\Services;

use App\Mail\GuestMessage;
use App\Models\BanquetEvent;
use App\Models\BanquetFunction;
use App\Models\Company;
use App\Models\OnlinePayment;
use App\Models\OrganiserAttendee;
use App\Models\OrganiserAudit;
use App\Models\OrganiserRequest;
use App\Models\OrganiserUser;
use App\Models\Setting;
use App\Models\User;
use App\Support\Numbering;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * v7.5 Event organiser portal (B2B extranet for MICE & banquet clients).
 *
 * Organiser users belong to a company, are linked to one event, or simply match an event's contact e-mail (private
 * clients). They follow their events, sign the published banquet event order (BEO) online, set guest-number guarantees
 * while the window is open, keep the guest list, ask for changes / cancellation / a new-event quote, and pay deposits
 * and balances online straight onto the event folio. The hotel publishes BEOs, answers the request queue and manages
 * the logins.
 */
class OrganiserService
{
    public const DEFAULTS = [
        'organiser_enabled' => '1', 'organiser_pay' => '1', 'organiser_changes' => '1', 'organiser_cancel' => '1',
        'organiser_quotes' => '1', 'organiser_attendees' => '1', 'organiser_idle' => '30', 'organiser_invite_days' => '7',
        'organiser_beo_days' => '7', 'organiser_notify_email' => '',
        'organiser_welcome' => 'Follow your events, sign the banquet event order, keep the guest list and pay online – 24/7.',
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
        return setting('organiser_enabled', '1') === '1';
    }

    public static function saveSettings(array $d): void
    {
        foreach (['organiser_idle' => [5, 240, 'Idle sign-out is 5 to 240 minutes.'], 'organiser_invite_days' => [1, 30, 'An invitation is valid 1 to 30 days.'],
            'organiser_beo_days' => [1, 60, 'The BEO signature deadline is 1 to 60 days before the event.']] as $k => [$lo, $hi, $msg]) {
            self::need(is_numeric($d[$k] ?? null) && $d[$k] >= $lo && $d[$k] <= $hi, $msg);
        }
        $mail = trim((string) ($d['organiser_notify_email'] ?? ''));
        self::need($mail === '' || filter_var($mail, FILTER_VALIDATE_EMAIL), 'Enter a valid notification e-mail (or leave it empty).');
        $vals = ['organiser_notify_email' => $mail, 'organiser_welcome' => mb_substr(trim((string) ($d['organiser_welcome'] ?? '')), 0, 250) ?: self::DEFAULTS['organiser_welcome']];
        foreach (['organiser_idle', 'organiser_invite_days', 'organiser_beo_days'] as $k) {
            $vals[$k] = (string) (int) $d[$k];
        }
        foreach (['organiser_enabled', 'organiser_pay', 'organiser_changes', 'organiser_cancel', 'organiser_quotes', 'organiser_attendees'] as $k) {
            $vals[$k] = ! empty($d[$k]) ? '1' : '0';
        }
        foreach ($vals as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        Setting::$version++;
        ActivityLogger::log('organiser', 'settings', 'Organiser portal settings updated');
    }

    /** Switch the portal on or off for one event (staff: Organiser portal › Events). */
    public static function saveEventPortal(BanquetEvent $e, array $d): void
    {
        $e->forceFill(['portal_enabled' => ! empty($d['portal_enabled'])])->save();
        ActivityLogger::log('organiser', 'event', "Organiser portal ".($e->portal_enabled ? 'switched on' : 'switched off')." for {$e->event_no}", $e);
    }

    // ------------------------------------------------------------------ audit

    public static function audit(?OrganiserUser $u, string $action, ?string $detail = null, ?int $companyId = null, ?int $eventId = null): void
    {
        $req = app()->bound('request') ? request() : null;
        OrganiserAudit::create(['company_id' => $companyId ?? $u?->company_id, 'organiser_user_id' => $u?->id, 'banquet_event_id' => $eventId,
            'action' => $action, 'detail' => $detail ? mb_substr($detail, 0, 300) : null, 'ip' => $req?->ip(),
            'user_agent' => $req ? mb_substr((string) $req->userAgent(), 0, 190) : null, 'created_at' => now()]);
    }

    public static function eligibleUser(?OrganiserUser $u): bool
    {
        return (bool) $u && $u->is_active && self::enabled() && (! $u->company_id || (bool) $u->company?->is_active);
    }

    // ------------------------------------------------------------------ accounts

    /**
     * Invite an organiser login (by staff, or by the account's own administrator who passes their scope on).
     * Staff give a company and/or an event; a login with neither still sees events whose contact e-mail is theirs.
     * Returns [user, plain token].
     */
    public static function invite(?Company $c, array $d, ?User $staff = null, ?OrganiserUser $by = null, ?BanquetEvent $event = null): array
    {
        if ($by) {
            self::need($by->can('users'), 'Only your account administrator can add users.');
            $c = $by->company_id ? Company::find($by->company_id) : null;
            $event = $by->banquet_event_id ? BanquetEvent::find($by->banquet_event_id) : null;
        }
        self::need(! $c || $c->is_active, 'That company is not active.');
        $email = Str::lower(trim((string) ($d['email'] ?? '')));
        $name = trim((string) ($d['name'] ?? ''));
        self::need(mb_strlen($name) >= 2, 'Enter the person\'s name.');
        self::need((bool) filter_var($email, FILTER_VALIDATE_EMAIL), 'Enter a valid e-mail address.');
        self::need(isset(OrganiserUser::ROLES[$d['role'] ?? '']), 'Choose a role.');
        self::need(! OrganiserUser::where('email', $email)->exists(), "{$email} already has an organiser login at this hotel.");
        $u = new OrganiserUser(['company_id' => $c?->id, 'banquet_event_id' => $event?->id, 'name' => mb_substr($name, 0, 120), 'email' => $email,
            'phone' => mb_substr(trim((string) ($d['phone'] ?? '')), 0, 30) ?: null, 'role' => $d['role'], 'is_active' => true,
            'created_by' => $staff?->id, 'invited_by' => $by?->id]);
        $u->save();
        $token = self::issueInvite($u);
        $scope = $c?->name ?? $event?->event_no ?? 'private events';
        self::audit($by, 'user', "Invited {$u->name} <{$u->email}> as ".OrganiserUser::ROLES[$u->role]." ({$scope})".($staff ? " (by hotel: {$staff->name})" : ''), $c?->id, $event?->id);
        ActivityLogger::log('organiser', 'invite', "Organiser portal invitation for {$u->name} ({$scope})", $event ?? $c);

        return [$u, $token];
    }

    public static function issueInvite(OrganiserUser $u): string
    {
        $token = Str::random(48);
        $u->forceFill(['invite_token' => hash('sha256', $token), 'invite_expires_at' => now()->addDays((int) setting('organiser_invite_days', 7))])->save();
        $link = route('organiser.invite', $token);
        $h = hotel();
        rescue(fn () => MailService::send($u->email, new GuestMessage("Your {$h->name} organiser portal invitation",
            "Dear {$u->name},\n\n{$h->name} has opened an organiser portal account for you".($u->company ? " ({$u->company->name})" : '').". Use the link below to choose your password – it is valid until "
            .$u->invite_expires_at->format('d M Y H:i').".\n\n{$link}\n\nHotel ID: {$h->code}\nLogin: {$u->email}\n\nIn the portal you can follow your events, sign the banquet event order, keep the guest list and pay online.", $h), 'organiser_invite', $u), null, true);

        return $token;
    }

    /** Organiser administrators manage their company's logins, their event's logins, and logins they invited. */
    public static function manages(OrganiserUser $by, OrganiserUser $u): bool
    {
        return ($by->company_id && $by->company_id === $u->company_id)
            || ($by->banquet_event_id && $by->banquet_event_id === $u->banquet_event_id)
            || $u->invited_by === $by->id;
    }

    public static function managedUsers(OrganiserUser $by): Collection
    {
        return OrganiserUser::with('company')->where(fn ($q) => $q->when($by->company_id, fn ($w) => $w->orWhere('company_id', $by->company_id))
            ->when($by->banquet_event_id, fn ($w) => $w->orWhere('banquet_event_id', $by->banquet_event_id))
            ->orWhere('invited_by', $by->id)->orWhere('id', $by->id))->orderBy('name')->get();
    }

    public static function setActive(OrganiserUser $u, bool $on, ?OrganiserUser $by = null): void
    {
        self::need(! $by || ($by->can('users') && self::manages($by, $u)), 'Only your account administrator can change users.');
        self::need(! ($by && $by->id === $u->id && ! $on), 'You cannot disable your own login.');
        $u->forceFill(['is_active' => $on] + ($on ? [] : ['reset_token' => null, 'invite_token' => null]))->save();
        self::audit($by, 'user', ($on ? 'Enabled ' : 'Disabled ')."{$u->name} <{$u->email}>".($by ? '' : ' (by hotel)'), $u->company_id);
    }

    public static function setRole(OrganiserUser $u, string $role, OrganiserUser $by): void
    {
        self::need($by->can('users') && self::manages($by, $u), 'Only your account administrator can change users.');
        self::need(isset(OrganiserUser::ROLES[$role]), 'Choose a role.');
        self::need($by->id !== $u->id, 'Ask another administrator to change your own role.');
        $u->forceFill(['role' => $role])->save();
        self::audit($by, 'user', "{$u->name} is now ".OrganiserUser::ROLES[$role], $u->company_id);
    }

    public static function unlock(OrganiserUser $u): void
    {
        $u->forceFill(['locked_until' => null, 'failed_logins' => 0])->save();
        self::audit(null, 'unlocked', "{$u->name} <{$u->email}> unlocked by hotel", $u->company_id);
    }

    // ------------------------------------------------------------------ authentication

    public static function passwordProblem(string $pw, ?OrganiserUser $u = null): ?string
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

    public static function byToken(string $column, string $token): ?OrganiserUser
    {
        if (strlen($token) < 40) {
            return null;
        }
        $u = OrganiserUser::where($column, hash('sha256', $token))->where('is_active', true)->first();
        $exp = $column === 'invite_token' ? $u?->invite_expires_at : $u?->reset_expires_at;

        return $u && $exp && $exp->isFuture() ? $u : null;
    }

    /** Invitation accepted or password reset: set the password, burn the token. */
    public static function setPassword(OrganiserUser $u, string $pw, string $confirm, string $via): void
    {
        self::need($pw === $confirm, 'The two passwords do not match.');
        if ($p = self::passwordProblem($pw, $u)) {
            throw new RuntimeException($p);
        }
        $u->forceFill(['password' => Hash::make($pw), 'password_changed_at' => now(), 'invite_token' => null, 'invite_expires_at' => null,
            'reset_token' => null, 'reset_expires_at' => null, 'failed_logins' => 0, 'locked_until' => null])->save();
        self::audit($u, 'password', $via === 'invite' ? 'Invitation accepted – password set' : ($via === 'reset' ? 'Password reset by e-mail link' : 'Password changed'));
    }

    public static function changePassword(OrganiserUser $u, string $current, string $pw, string $confirm): void
    {
        self::need(Hash::check($current, (string) $u->password), 'Your current password is not correct.');
        self::need(! Hash::check($pw, (string) $u->password), 'Choose a password different from the current one.');
        self::setPassword($u, $pw, $confirm, 'change');
    }

    /** Sign-in check (tenant already set from the hotel code). Generic error so e-mail addresses cannot be probed. */
    public static function attempt(string $email, string $pw): OrganiserUser
    {
        $email = Str::lower(trim($email));
        $generic = 'Invalid hotel ID, e-mail or password.';
        $u = OrganiserUser::with('company')->where('email', $email)->first();
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
        if (! self::eligibleUser($u)) {
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
        $u = OrganiserUser::with('company')->where('email', Str::lower(trim($email)))->where('is_active', true)->first();
        if (! $u || $u->password === null || ! self::eligibleUser($u)) {
            return;
        }
        $token = Str::random(48);
        $u->forceFill(['reset_token' => hash('sha256', $token), 'reset_expires_at' => now()->addMinutes(60)])->save();
        $h = hotel();
        rescue(fn () => MailService::send($u->email, new GuestMessage("{$h->name} organiser portal – reset your password",
            "Dear {$u->name},\n\nSomeone (hopefully you) asked to reset your organiser portal password. The link is valid for 60 minutes:\n\n".route('organiser.reset', $token)
            ."\n\nIf you did not ask for this, ignore this e-mail – your password is unchanged.", $h), 'organiser_reset', $u), null, true);
        self::audit($u, 'reset_requested', 'Password reset link e-mailed');
    }

    // ------------------------------------------------------------------ events & money

    /** Events the login follows: its company's, its linked event, or events booked under its e-mail. Portal on only. */
    public static function eventsFor(OrganiserUser $u): Collection
    {
        return BanquetEvent::with('functions.hall', 'functions.package', 'company', 'salesUser')
            ->where('portal_enabled', true)
            ->where(fn ($q) => $q->when($u->company_id, fn ($w) => $w->orWhere('company_id', $u->company_id))
                ->when($u->banquet_event_id, fn ($w) => $w->orWhere('id', $u->banquet_event_id))
                ->orWhereRaw('LOWER(contact_email) = ?', [Str::lower($u->email)]))
            ->orderBy('event_date')->orderBy('id')->get();
    }

    public static function ownEvent(OrganiserUser $u, BanquetEvent $e): void
    {
        $mine = ((int) $u->company_id && (int) $e->company_id === (int) $u->company_id)
            || ((int) $u->banquet_event_id && (int) $e->id === (int) $u->banquet_event_id)
            || Str::lower((string) $e->contact_email) === Str::lower($u->email);
        self::need($mine && $e->portal_enabled, 'Event not found.');
    }

    /** Logins that follow an event (staff page, BEO notifications). */
    public static function loginsFor(BanquetEvent $e): Collection
    {
        return OrganiserUser::with('company')->where('is_active', true)
            ->where(fn ($q) => $q->when($e->company_id, fn ($w) => $w->orWhere('company_id', $e->company_id))
                ->orWhere('banquet_event_id', $e->id)
                ->when($e->contact_email, fn ($w) => $w->orWhereRaw('LOWER(email) = ?', [Str::lower((string) $e->contact_email)])))
            ->orderBy('name')->get();
    }

    public static function money(BanquetEvent $e): array
    {
        $deposits = $e->depositsReceived();
        $total = $e->total();

        return ['net' => $e->net(), 'tax' => $e->tax(), 'total' => $total, 'deposits' => $deposits,
            'balance' => round($total - $deposits, 2), 'deposit_due' => (float) $e->deposit_due,
            'deposit_due_date' => $e->deposit_due_date, 'billed' => (bool) $e->billed_at, 'folio_no' => $e->folio?->folio_no];
    }

    // ------------------------------------------------------------------ BEO

    /** The banquet event order as it stands right now (header, functions, money, terms) – the staff publish a copy of this. */
    public static function beo(BanquetEvent $e): array
    {
        $e->loadMissing('functions.items', 'functions.hall', 'functions.package', 'company', 'hall');
        $m = self::money($e);

        return [
            'event_no' => $e->event_no, 'title' => $e->title, 'type' => BanquetEvent::TYPES[$e->event_type] ?? $e->event_type,
            'status' => BanquetEvent::STATUSES[$e->status] ?? $e->status, 'event_date' => $e->event_date?->toDateString(), 'end_date' => $e->end_date?->toDateString(),
            'venue' => $e->hall?->name, 'contact' => ['name' => $e->contact_name, 'phone' => $e->contact_phone, 'email' => $e->contact_email],
            'company' => $e->company?->name, 'expected_pax' => (int) $e->expected_pax, 'guaranteed_pax' => $e->guaranteed_pax,
            'requirements' => $e->requirements,
            'functions' => $e->functions->map(fn ($f) => ['name' => $f->name, 'date' => $f->date->toDateString(), 'time' => $f->timeRange(),
                'hall' => $f->hall?->name, 'setup' => BanquetFunction::SETUPS[$f->setup_style] ?? $f->setup_style,
                'expected_pax' => (int) $f->expected_pax, 'guaranteed_pax' => $f->guaranteed_pax, 'package' => $f->package?->name,
                'rate_per_pax' => (float) $f->rate_per_pax, 'hall_rate' => (float) $f->hall_rate, 'net' => $f->net(), 'tax' => $f->tax(),
                'items' => $f->items->map(fn ($i) => ['desc' => $i->description, 'qty' => (float) $i->qty, 'price' => (float) $i->unit_price, 'amount' => $i->amount()])->values()->all(),
                'notes' => $f->notes])->values()->all(),
            'money' => $m,
            'terms' => ['guarantee_days' => (int) setting('banquet_guarantee_days', '3'),
                'cancel_policy' => (string) setting('banquet_cancel_policy', ''), 'sign_by' => $e->event_date?->copy()->subDays((int) setting('organiser_beo_days', 7))->toDateString()],
        ];
    }

    public static function beoHash(array $snap): string
    {
        $snap = collect($snap)->except(['published_at', 'published_by'])->all();
        // MySQL normalises key order inside JSON columns; hash the semantic document, not its storage order.
        $canonicalise = function ($value) use (&$canonicalise) {
            if (! is_array($value)) {
                return $value;
            }
            foreach ($value as $key => $child) {
                $value[$key] = $canonicalise($child);
            }
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return $value;
        };

        return hash('sha256', json_encode($canonicalise($snap)));
    }

    /** True when the event changed after the BEO was published (the organiser signs the published copy, not the live one). */
    public static function beoChanged(BanquetEvent $e): bool
    {
        return (bool) $e->beo_snapshot && self::beoHash($e->beo_snapshot) !== self::beoHash(self::beo($e));
    }

    /** Staff publish (or re-publish) the BEO: the organiser's old signature, if any, falls away and they sign again. */
    public static function publishBeo(BanquetEvent $e, User $by): void
    {
        self::need($e->portal_enabled, 'Switch the portal on for this event first.');
        self::need($e->isOpen() && ! $e->billed_at, 'A BEO is published on an open, unbilled event.');
        $snap = self::beo($e) + ['published_at' => now()->toDateTimeString(), 'published_by' => $by->name];
        $re = (bool) $e->beo_published_at;
        $e->forceFill(['beo_snapshot' => $snap, 'beo_published_at' => now(), 'beo_published_by' => $by->id, 'beo_approved_at' => null, 'beo_sign_name' => null])->save();
        self::audit(null, 'beo', 'Hotel '.($re ? 're-published' : 'published')." the BEO for {$e->event_no} ({$e->title})", $e->company_id, $e->id);
        ActivityLogger::log('organiser', 'beo', "BEO ".($re ? 're-published' : 'published')." for {$e->event_no}", $e);
        foreach (self::loginsFor($e)->whereNotNull('password') as $login) {
            rescue(fn () => MailService::send($login->email, new GuestMessage("{$e->event_no} – your banquet event order is ready to sign",
                "Dear {$login->name},\n\nThe banquet event order for {$e->title} ({$e->event_date?->format('d M Y')}) is ready in the organiser portal – please check it and sign it online.\n\n".route('organiser.beo', $e->id), hotel()), 'organiser_beo', $e), null, true);
        }
    }

    /** The organiser signs the published BEO. */
    public static function approveBeo(OrganiserUser $u, BanquetEvent $e, string $signName): void
    {
        self::ownEvent($u, $e);
        self::need($u->can('events'), 'Your role cannot sign the banquet event order.');
        self::need($e->beo_published_at && $e->beo_snapshot, 'The hotel has not published the banquet event order yet.');
        self::need(! $e->beo_approved_at, 'This banquet event order is already signed.');
        self::need($e->isOpen() && ! $e->billed_at, 'This event can no longer be signed.');
        $signName = trim($signName);
        self::need(mb_strlen($signName) >= 2, 'Type your name as your signature.');
        $e->forceFill(['beo_approved_at' => now(), 'beo_sign_name' => mb_substr($signName, 0, 120)])->save();
        self::audit($u, 'beo', "Signed the BEO for {$e->event_no} as {$e->beo_sign_name}", null, $e->id);
        ActivityLogger::log('organiser', 'beo', "{$e->event_no} BEO signed by {$e->beo_sign_name} ({$u->name})", $e);
        self::notifyStaff("BEO signed: {$e->event_no}", "{$u->name} signed the BEO for {$e->title} as {$e->beo_sign_name}.\n\n".route('organiser-admin.event', $e->id));
    }

    // ------------------------------------------------------------------ guarantees

    /** Set the guest-number guarantee on a function: before the cut-off freely, afterwards only upwards. */
    public static function guarantee(OrganiserUser $u, BanquetFunction $f, int $pax): BanquetFunction
    {
        $f->loadMissing('event', 'hall');
        self::ownEvent($u, $f->event);
        self::need($u->can('events'), 'Your role cannot set guarantees.');
        self::need($f->event->isEditable(), 'This event can no longer be changed.');
        $cap = $f->hall ? $f->hall->capacityFor($f->setup_style) : 100000;
        self::need($pax >= 1 && $pax <= $cap, "The guarantee is 1 to {$cap} guests for {$f->name}.");
        if ($f->guaranteeLocked() && $f->guaranteed_pax !== null && $pax < $f->guaranteed_pax) {
            throw new RuntimeException("The guarantee of {$f->guaranteed_pax} is final since ".$f->guaranteeCutoff()->format('d M').' – send a change request instead.');
        }
        $f->update(['guaranteed_pax' => $pax]);
        self::audit($u, 'guarantee', "Guarantee for {$f->event->event_no} / {$f->name} set to {$pax} guests", null, $f->event->id);
        ActivityLogger::log('organiser', 'guarantee', "{$f->event->event_no} / {$f->name}: organiser set the guarantee to {$pax}", $f->event);

        return $f;
    }

    // ------------------------------------------------------------------ requests

    /** Ask for a change to an event, or to give the event up (the quoted fee travels with a cancellation). */
    public static function change(OrganiserUser $u, BanquetEvent $e, array $d): OrganiserRequest
    {
        self::ownEvent($u, $e);
        $kind = ($d['kind'] ?? 'change') === 'cancel' ? 'cancel' : 'change';
        self::need($u->can('events'), 'Your role cannot send requests.');
        self::need(setting($kind === 'cancel' ? 'organiser_cancel' : 'organiser_changes', '1') === '1', 'Requests are switched off – please contact the hotel.');
        self::need($e->isOpen() && ! $e->billed_at, "This event ({$e->status}) can no longer be changed – please call your coordinator.");
        self::need(! OrganiserRequest::where('banquet_event_id', $e->id)->where('type', $kind)->where('status', 'pending')->exists(),
            'There is already an open '.($kind === 'cancel' ? 'cancellation' : 'change').' request for this event.');
        $details = trim((string) ($d['details'] ?? ''));
        self::need(mb_strlen($details) >= ($kind === 'cancel' ? 3 : 5), $kind === 'cancel' ? 'Tell us briefly why you are cancelling.' : 'Describe the change you need.');
        $payload = null;
        if ($kind === 'change') {
            $payload = array_filter(['date' => $d['date'] ?? null, 'pax' => isset($d['pax']) && $d['pax'] !== '' ? max(1, (int) $d['pax']) : null,
                'package' => mb_substr(trim((string) ($d['package'] ?? '')), 0, 120) ?: null]);
            if (! empty($payload['date'])) {
                try {
                    $dt = Carbon::parse($payload['date'])->startOfDay();
                } catch (\Throwable) {
                    throw new RuntimeException('Use a valid date (YYYY-MM-DD).');
                }
                self::need($dt->gte(bdate()->copy()->startOfDay()), 'The requested date cannot be in the past.');
                $payload['date'] = $dt->toDateString();
            }
            $payload = $payload ?: null;
        } else {
            $q = BanquetSalesService::cancellationQuote($e);
            $payload = ['fee' => $q['fee'], 'pct' => $q['pct'], 'days' => $q['days'], 'base' => $q['base']];
        }
        $req = OrganiserRequest::create(['number' => Numbering::next('organiser_request'), 'company_id' => $u->company_id ?? $e->company_id,
            'organiser_user_id' => $u->id, 'banquet_event_id' => $e->id, 'type' => $kind, 'status' => 'pending',
            'details' => mb_substr($details, 0, 2000), 'payload' => $payload]);
        self::audit($u, 'request', ucfirst($kind)." request {$req->number} for {$e->event_no}", null, $e->id);
        self::notifyStaff(ucfirst($kind)." request {$req->number} ({$e->event_no})", "{$u->name} asks:\n{$details}\n\n".route('organiser-admin.requests'));

        return $req;
    }

    /** Price a brand-new event. */
    public static function quoteRequest(OrganiserUser $u, array $d): OrganiserRequest
    {
        self::need($u->can('events'), 'Your role cannot request quotes.');
        self::need(setting('organiser_quotes', '1') === '1', 'Quote requests are switched off – please contact the hotel.');
        $title = mb_substr(trim((string) ($d['title'] ?? '')), 0, 120);
        self::need(mb_strlen($title) >= 3, 'Give the event a title.');
        self::need(isset(BanquetEvent::TYPES[$d['event_type'] ?? '']), 'Choose the kind of event.');
        try {
            $dt = Carbon::parse((string) ($d['event_date'] ?? ''))->startOfDay();
        } catch (\Throwable) {
            throw new RuntimeException('Use a valid date (YYYY-MM-DD).');
        }
        self::need($dt->gte(bdate()->copy()->startOfDay()), 'The event date cannot be in the past.');
        self::need($dt->lte(bdate()->copy()->addYears(2)), 'We quote events up to two years ahead – call us for later dates.');
        $pax = (int) ($d['pax'] ?? 0);
        self::need($pax >= 1 && $pax <= 10000, 'Expect 1 to 10,000 guests.');
        $details = mb_substr(trim((string) ($d['details'] ?? '')), 0, 2000) ?: null;
        $req = OrganiserRequest::create(['number' => Numbering::next('organiser_request'), 'company_id' => $u->company_id,
            'organiser_user_id' => $u->id, 'type' => 'quote', 'status' => 'pending', 'details' => $details,
            'payload' => ['title' => $title, 'event_type' => $d['event_type'], 'event_date' => $dt->toDateString(), 'pax' => $pax]]);
        self::audit($u, 'request', "Quote request {$req->number}: {$title}, ".$dt->format('d M Y').", {$pax} guests");
        self::notifyStaff("New-event quote {$req->number}", "{$u->name} asks for a quote: {$title}, ".BanquetEvent::TYPES[$d['event_type']].', '.$dt->format('d M Y').", {$pax} guests.\n{$details}\n\n".route('organiser-admin.requests'));

        return $req;
    }

    public static function ownRequest(OrganiserUser $u, OrganiserRequest $q): void
    {
        self::need($q->organiser_user_id === $u->id || ($u->company_id && $q->company_id && (int) $q->company_id === (int) $u->company_id)
            || ($u->banquet_event_id && $q->banquet_event_id && (int) $q->banquet_event_id === (int) $u->banquet_event_id), 'Request not found.');
    }

    public static function requestsFor(OrganiserUser $u): Collection
    {
        return OrganiserRequest::with('event', 'decider')->where(fn ($q) => $q->where('organiser_user_id', $u->id)
            ->when($u->company_id, fn ($w) => $w->orWhere('company_id', $u->company_id))
            ->when($u->banquet_event_id, fn ($w) => $w->orWhere('banquet_event_id', $u->banquet_event_id)))
            ->orderByDesc('id')->get();
    }

    public static function withdraw(OrganiserUser $u, OrganiserRequest $q): void
    {
        self::ownRequest($u, $q);
        self::need($q->status === 'pending', 'Only a pending request can be withdrawn.');
        $q->update(['status' => 'withdrawn', 'decided_at' => now(), 'decision_note' => 'Withdrawn by '.$u->name]);
        self::audit($u, 'request', "Withdrew {$q->number}", null, $q->banquet_event_id);
    }

    // ------------------------------------------------------------------ hotel decisions

    /** Approve a cancellation: the event is cancelled (definite) or released (enquiry / tentative), fee as per policy. */
    public static function approve(OrganiserRequest $q, User $by, ?string $note = null): void
    {
        self::need($q->type === 'cancel' && $q->status === 'pending', 'Only a pending cancellation can be approved.');
        $e = $q->event;
        self::need($e && $e->isOpen() && ! $e->billed_at, 'The event is no longer open – decline the request instead.');
        try {
            DB::transaction(function () use ($q, $e, $by, $note) {
                if ($e->status === 'definite') {
                    BanquetSalesService::cancel($e, 'Organiser portal: '.mb_substr((string) $q->details, 0, 200));
                } else {
                    BanquetSalesService::markLost($e, 'Organiser portal: '.mb_substr((string) $q->details, 0, 200));
                }
                $q->update(['status' => 'approved', 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => $note ? mb_substr($note, 0, 500) : null]);
                ActivityLogger::log('organiser', 'approve', "Approved cancellation {$q->number} ({$e->event_no})", $e);
                self::audit(null, 'approved', "Hotel approved the cancellation of {$e->event_no} ({$q->number})".($note ? ": {$note}" : ''), $q->company_id, $e->id);
            });
        } catch (ValidationException $x) {
            throw new RuntimeException(collect($x->errors())->flatten()->first() ?: 'The event cannot be cancelled.');
        }
        $e->refresh();
        self::notifyOrganiser($q, "Cancellation of {$e->event_no} confirmed",
            "Your cancellation of {$e->title} is confirmed".($e->cancellation_fee > 0 ? ' – a fee of '.money($e->cancellation_fee).' applies as quoted' : ' – no fee applies').'.'.($note ? "\n\nNote from the hotel: {$note}" : ''));
    }

    public static function decline(OrganiserRequest $q, User $by, string $note): void
    {
        self::need($q->status === 'pending', 'This request has already been answered.');
        $note = trim($note);
        self::need(mb_strlen($note) >= 3, 'Tell the organiser why (a short note is e-mailed to them).');
        $q->update(['status' => 'declined', 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => mb_substr($note, 0, 500)]);
        ActivityLogger::log('organiser', 'decline', "Declined organiser request {$q->number}: {$note}", $q->event);
        self::audit(null, 'declined', "Hotel declined {$q->number}: {$note}", $q->company_id, $q->banquet_event_id);
        self::notifyOrganiser($q, OrganiserRequest::TYPES[$q->type].' '.$q->number.' declined', 'The hotel could not accept your '.Str::lower(OrganiserRequest::TYPES[$q->type])
            .($q->event ? ' for '.$q->event->event_no : '').".\n\nReason: {$note}");
    }

    /** Change / quote requests: staff did what was asked and close it with a note for the organiser. */
    public static function complete(OrganiserRequest $q, User $by, string $note): void
    {
        self::need(in_array($q->type, ['change', 'quote']) && $q->status === 'pending', 'Only a pending change or quote request can be completed.');
        $note = trim($note);
        self::need(mb_strlen($note) >= 3, 'Add a short note for the organiser (what was changed / quoted).');
        $q->update(['status' => 'done', 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => mb_substr($note, 0, 500)]);
        ActivityLogger::log('organiser', 'done', "Completed organiser request {$q->number}: {$note}", $q->event);
        self::audit(null, 'done', "Hotel completed {$q->number}: {$note}", $q->company_id, $q->banquet_event_id);
        self::notifyOrganiser($q, OrganiserRequest::TYPES[$q->type].' '.$q->number.' – done', "The hotel has answered your request {$q->number}:\n\n{$note}");
    }

    protected static function notifyOrganiser(OrganiserRequest $q, string $subject, string $text): void
    {
        $to = $q->organiserUser?->email ?: $q->event?->contact_email;
        if ($to) {
            rescue(fn () => MailService::send($to, new GuestMessage($subject, 'Dear '.($q->organiserUser?->name ?: 'organiser').",\n\n{$text}\n\n".route('organiser.home'), hotel()), 'organiser_update', $q), null, true);
        }
    }

    protected static function notifyStaff(string $subject, string $text): void
    {
        $to = setting('organiser_notify_email', '');
        if ($to) {
            rescue(fn () => MailService::send($to, new GuestMessage($subject, $text, hotel()), 'organiser_staff'), null, true);
        }
    }

    // ------------------------------------------------------------------ payments

    /** Pay part or all of an event's balance online – the money lands on the event folio as an advance. */
    public static function pay(OrganiserUser $u, BanquetEvent $e, float $amount): OnlinePayment
    {
        self::ownEvent($u, $e);
        self::need($u->can('finance'), 'Your role cannot make payments.');
        self::need(setting('organiser_pay', '1') === '1', 'Online payment is not available – please pay by bank transfer.');
        self::need($e->isOpen() && ! $e->billed_at, 'This event can no longer be paid online.');
        $m = self::money($e);
        self::need($m['balance'] > 0, 'This event has nothing outstanding.');
        self::need($amount >= 1 && $amount <= $m['balance'] + 0.009, 'Pay between '.money(1).' and the outstanding '.money($m['balance']).'.');
        $p = OnlinePaymentService::createBanquet($e, $amount, ['name' => $u->name.($u->company ? ' ('.$u->company->name.')' : ''), 'email' => $u->email,
            'phone' => $u->phone, 'description' => 'Event payment – '.$e->event_no.' '.$e->title, 'minutes' => 60, 'source' => 'portal']);
        self::audit($u, 'payment', "Started online payment {$p->pay_no} for ".money($amount)." on {$e->event_no}", null, $e->id);

        return $p;
    }

    // ------------------------------------------------------------------ guest lists

    public static function attendees(BanquetEvent $e): Collection
    {
        return OrganiserAttendee::where('banquet_event_id', $e->id)->orderBy('name')->get();
    }

    public static function attendeeSummary(BanquetEvent $e): array
    {
        $all = self::attendees($e);

        return ['total' => $all->count(),
            'tags' => $all->groupBy('tag')->map->count()->all(),
            'dietary' => $all->whereNotNull('dietary')->groupBy(fn ($a) => mb_strtolower(trim((string) $a->dietary)))->map->count()->sortDesc()->all()];
    }

    public static function attendeeAdd(OrganiserUser $u, BanquetEvent $e, array $d): OrganiserAttendee
    {
        self::ownEvent($u, $e);
        self::need($u->can('events'), 'Your role cannot keep the guest list.');
        self::need(setting('organiser_attendees', '1') === '1', 'Guest lists are switched off – please e-mail the list to the hotel.');
        self::need($e->isEditable(), 'This event can no longer be changed.');
        self::need(OrganiserAttendee::where('banquet_event_id', $e->id)->count() < 2000, 'The guest list is full (2,000 guests) – call your coordinator.');
        $name = trim((string) ($d['name'] ?? ''));
        self::need(mb_strlen($name) >= 2, 'Enter the guest\'s name.');
        $email = Str::lower(trim((string) ($d['email'] ?? '')));
        self::need($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL), 'Enter a valid e-mail (or leave it empty).');
        self::need(isset(OrganiserAttendee::TAGS[$d['tag'] ?? '']), 'Choose delegate, VIP, speaker, companion or crew.');
        $a = OrganiserAttendee::create(['banquet_event_id' => $e->id, 'name' => mb_substr($name, 0, 120), 'email' => $email ?: null,
            'phone' => mb_substr(trim((string) ($d['phone'] ?? '')), 0, 30) ?: null, 'dietary' => mb_substr(trim((string) ($d['dietary'] ?? '')), 0, 120) ?: null,
            'tag' => $d['tag'], 'notes' => mb_substr(trim((string) ($d['notes'] ?? '')), 0, 200) ?: null, 'added_by' => $u->id]);
        self::audit($u, 'guest_list', "Added {$a->name} to the guest list of {$e->event_no}", null, $e->id);

        return $a;
    }

    public static function attendeeDelete(OrganiserUser $u, OrganiserAttendee $a): void
    {
        $a->loadMissing('event');
        self::ownEvent($u, $a->event);
        self::need($u->can('events'), 'Your role cannot keep the guest list.');
        self::need($a->event->isEditable(), 'This event can no longer be changed.');
        $a->delete();
        self::audit($u, 'guest_list', "Removed {$a->name} from the guest list of {$a->event->event_no}", null, $a->event->id);
    }

    // ------------------------------------------------------------------ dashboards

    public static function organiserDashboard(OrganiserUser $u): array
    {
        $today = bdate()->copy()->startOfDay()->toDateString();
        $events = self::eventsFor($u);
        $upcoming = $events->filter(fn ($e) => $e->isOpen() && ($e->end_date?->toDateString() ?? $e->event_date?->toDateString()) >= $today)->take(10);
        $open = $events->filter(fn ($e) => $e->isOpen() && ! $e->billed_at);
        $balance = round($open->sum(fn ($e) => max(0, self::money($e)['balance'])), 2);

        return [
            'upcoming' => $upcoming,
            'beoAwaiting' => $open->filter(fn ($e) => $e->beo_published_at && ! $e->beo_approved_at)->values(),
            'balance' => $balance,
            'pending' => self::requestsFor($u)->where('status', 'pending')->count(),
            'requests' => self::requestsFor($u)->take(5),
            'coordinator' => $upcoming->first()?->salesUser,
        ];
    }

    public static function staffDashboard(): array
    {
        $since = now()->subDays(30);
        $events = BanquetEvent::where('portal_enabled', true)->with('functions')->get();
        $open = $events->filter(fn ($e) => $e->isOpen() && ! $e->billed_at);

        return [
            'kpi' => [
                'events' => $open->count(),
                'users' => OrganiserUser::where('is_active', true)->whereNotNull('password')->count(),
                'invited' => OrganiserUser::where('is_active', true)->whereNull('password')->count(),
                'logins' => OrganiserAudit::where('action', 'login')->where('created_at', '>=', $since)->count(),
                'beoAwaiting' => $open->filter(fn ($e) => $e->beo_published_at && ! $e->beo_approved_at)->count(),
                'beoSigned' => OrganiserAudit::where('action', 'beo')->where('detail', 'like', 'Signed%')->where('created_at', '>=', $since)->count(),
                'online' => round((float) OnlinePayment::where('purpose', 'banquet')->where('status', 'paid')->where('paid_at', '>=', $since)->sum('amount_received'), 2),
                'requests' => OrganiserRequest::where('status', 'pending')->count(),
                'guests' => OrganiserAttendee::count(),
                'failed' => OrganiserAudit::whereIn('action', ['login_failed', 'locked', 'login_blocked'])->where('created_at', '>=', now()->subDays(7))->count(),
            ],
            'upcoming' => $open->filter(fn ($e) => ($e->end_date?->toDateString() ?? '9999') >= bdate()->toDateString())->sortBy('event_date')->take(8)->values(),
            'pending' => OrganiserRequest::with('company', 'organiserUser', 'event')->where('status', 'pending')->orderBy('created_at')->limit(8)->get(),
            'recent' => OrganiserAudit::with('company', 'organiserUser', 'event')->orderByDesc('id')->limit(12)->get(),
        ];
    }

    public static function pendingCount(): int
    {
        return OrganiserRequest::where('status', 'pending')->count();
    }

    protected static function need(bool $ok, string $msg = 'You do not have permission to do that.'): void
    {
        if (! $ok) {
            throw new RuntimeException($msg);
        }
    }
}
