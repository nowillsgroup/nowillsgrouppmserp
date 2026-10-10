<?php

namespace App\Services;

use App\Mail\GuestMessage;
use App\Models\FormCRecord;
use App\Models\Guest;
use App\Models\GuestDocument;
use App\Models\ReservationRoom;
use App\Models\Setting;
use App\Support\Numbering;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v3.4 Form C – the register of foreign nationals (Foreigners Act 1946 s.14, Registration of Foreigners Rules 1992 r.14).
 *
 * Every foreign national who stays for consideration must be reported to the FRRO (indianfrro.gov.in) within 24 hours
 * of arrival, with passport, visa and travel particulars and scans of the passport photo page and visa; the departure
 * is reported after check-out. A record is opened automatically when a foreign guest checks in (prefilled from the
 * guest profile and anything the guest typed during online check-in); companions in the same room get their own
 * record. The desk completes the particulars, files on the FRRO portal and records the application / reference number
 * here – the system tracks the 24-hour deadline, late filings (with a reason), departures still to report and visas
 * that expire during the stay.
 */
class FormCService
{
    public const DEFAULTS = [
        'formc_enabled' => '1',
        'formc_deadline_hours' => '24',      // statutory window after check-in
        'formc_alert_hours' => '6',          // warn when a Form C is due within N hours
        'formc_departure_hours' => '24',     // departures should be reported within N hours of check-out
        'formc_require_scans' => '1',        // passport (and visa) scans on file before submitting
        'formc_visa_exempt' => 'Nepal,Bhutan',
        'formc_block_checkout' => '0',       // refuse check-out while the arrival report is outstanding
        'formc_alert_email' => '',           // blank = the hotel's e-mail
    ];

    public const HOME_ALIASES = ['india', 'indian', 'in', 'ind', 'bharat', 'republic of india']; // Form C (FRRO) is an Indian requirement

    public const GHANA_ALIASES = ['ghana', 'ghanaian', 'gh', 'gha', 'republic of ghana'];

    /** Nationality spellings that count as "home" for the property's country (Ghana by default). */
    public static function homeAliases(): array
    {
        $c = mb_strtolower(trim((string) hotel()?->country));

        return match (true) {
            $c === '' || in_array($c, self::GHANA_ALIASES, true) => self::GHANA_ALIASES,
            in_array($c, self::HOME_ALIASES, true) => self::HOME_ALIASES,
            default => [$c],
        };
    }

    /** Form C applies only to properties in India. */
    public static function propertyInIndia(): bool
    {
        return in_array(mb_strtolower(trim((string) hotel()?->country)), self::HOME_ALIASES, true);
    }

    /** column => label – what the FRRO portal asks for */
    public const FIELDS = [
        'surname' => 'Surname', 'given_name' => 'Given name(s)', 'gender' => 'Gender', 'dob' => 'Date of birth', 'nationality' => 'Nationality',
        'passport_no' => 'Passport no.', 'passport_place' => 'Passport place of issue', 'passport_issued_on' => 'Passport date of issue', 'passport_expires_on' => 'Passport valid until',
        'visa_no' => 'Visa no.', 'visa_type' => 'Visa type', 'visa_place' => 'Visa place of issue', 'visa_issued_on' => 'Visa date of issue', 'visa_expires_on' => 'Visa valid until',
        'arrived_india_on' => 'Date of arrival in India', 'arrived_from' => 'Arrived from (city, country)', 'port_of_arrival' => 'Port of arrival in India',
        'next_destination' => 'Next destination', 'purpose' => 'Purpose of visit', 'address_abroad' => 'Permanent address abroad',
    ];

    public const DATE_FIELDS = ['dob', 'passport_issued_on', 'passport_expires_on', 'visa_issued_on', 'visa_expires_on', 'arrived_india_on'];

    /** fields the guest may pre-fill during online check-in */
    public const PREFILL = ['passport_place', 'passport_issued_on', 'passport_expires_on', 'visa_no', 'visa_type', 'visa_place', 'visa_issued_on', 'visa_expires_on',
        'arrived_india_on', 'arrived_from', 'port_of_arrival', 'next_destination', 'purpose'];

    public static function ensureDefaults(): void
    {
        foreach (self::DEFAULTS as $k => $v) {
            if ($k === 'formc_enabled') {
                $v = self::propertyInIndia() ? '1' : '0'; // Form C is an Indian requirement – off for Ghana properties
            }
            Setting::firstOrCreate(['key' => $k], ['value' => $v]);
        }
        Setting::$version++;
    }

    public static function cfg(): array
    {
        $s = [];
        foreach (self::DEFAULTS as $k => $v) {
            $s[substr($k, 6)] = (string) setting($k, $v);
        }
        foreach (['deadline_hours', 'alert_hours', 'departure_hours'] as $k) {
            $s[$k] = max(0, (int) $s[$k]);
        }
        $s['deadline_hours'] = max(1, $s['deadline_hours']);

        return $s;
    }

    public static function enabled(): bool
    {
        return setting('formc_enabled', '1') === '1';
    }

    public static function saveSettings(array $d): void
    {
        foreach (self::DEFAULTS as $k => $v) {
            $short = substr($k, 6);
            if (array_key_exists($short, $d)) {
                Setting::updateOrCreate(['key' => $k], ['value' => is_bool($d[$short]) ? ($d[$short] ? '1' : '0') : trim((string) $d[$short])]);
            }
        }
        Setting::$version++;
        ActivityLogger::log('formc', 'settings', 'Form C settings updated');
    }

    // ───────────────────────── who is foreign ─────────────────────────

    /** true = foreign, false = national of the property's country, null = unknown (blank). */
    public static function isForeignNationality(?string $nationality): ?bool
    {
        $n = mb_strtolower(trim((string) $nationality));

        return $n === '' ? null : ! in_array($n, self::homeAliases(), true);
    }

    public static function isForeign(?Guest $g): bool
    {
        return $g && self::isForeignNationality($g->nationality) === true;
    }

    public static function visaExempt(?string $nationality): bool
    {
        $n = mb_strtolower(trim((string) $nationality));
        $list = collect(explode(',', self::cfg()['visa_exempt']))->map(fn ($x) => mb_strtolower(trim($x)))->filter();

        return $n !== '' && $list->contains($n);
    }

    public static function stayGuest(ReservationRoom $stay): ?Guest
    {
        return $stay->guest ?? $stay->reservation?->guest;
    }

    // ───────────────────────── lifecycle ─────────────────────────

    /** Check-in hook: open the primary guest's Form C (idempotent, never throws for the caller). */
    public static function onCheckIn(ReservationRoom $stay): ?FormCRecord
    {
        if (! self::enabled()) {
            return null;
        }
        $stay->loadMissing('reservation.guest', 'guest');
        $g = self::stayGuest($stay);
        if (! self::isForeign($g)) {
            return null;
        }
        $existing = FormCRecord::where('reservation_room_id', $stay->id)->where('is_companion', false)->first();

        return $existing ?? self::createForStay($stay, $g);
    }

    /** Check-out hook: the departure now has to be reported. */
    public static function onCheckOut(ReservationRoom $stay): int
    {
        $at = $stay->checked_out_at ?? now();

        return FormCRecord::where('reservation_room_id', $stay->id)->whereIn('status', ['pending', 'submitted'])->whereNull('departed_at')
            ->update(['departed_at' => $at, 'updated_at' => now()]);
    }

    /** A guest's nationality changed (profile edit / desk fix): open Form Cs for the guest's in-house stays. */
    public static function syncGuest(Guest $g): int
    {
        if (! self::enabled() || ! self::isForeign($g)) {
            return 0;
        }
        $n = 0;
        foreach (ReservationRoom::with('reservation.guest', 'guest')->where('status', 'checked_in')->whereIn('id', PrivacyService::stayIds($g))->get() as $stay) {
            if (self::stayGuest($stay)?->id === $g->id && ! FormCRecord::where('reservation_room_id', $stay->id)->where('is_companion', false)->exists()) {
                self::createForStay($stay, $g);
                $n++;
            }
        }

        return $n;
    }

    public static function createForStay(ReservationRoom $stay, ?Guest $g = null, bool $companion = false, array $data = []): FormCRecord
    {
        $stay->loadMissing('reservation');
        if (! in_array($stay->status, ['checked_in', 'checked_out'])) {
            throw new RuntimeException('Form C is opened at check-in.');
        }
        $cfg = self::cfg();
        $in = $stay->checked_in_at ?? now();
        $attrs = array_merge(
            $g ? self::fromProfile($g, $stay) : [],
            $companion ? [] : self::prefill($stay),
            array_intersect_key($data, self::FIELDS + ['phone' => 1]),
        );
        $f = FormCRecord::create($attrs + [
            'form_no' => Numbering::next('form_c'), 'reservation_room_id' => $stay->id, 'reservation_id' => $stay->reservation_id,
            'guest_id' => $g?->id, 'is_companion' => $companion, 'status' => 'pending', 'checked_in_at' => $in,
            'expected_checkout' => $stay->departure_date, 'deadline_at' => $in->copy()->addHours($cfg['deadline_hours']),
            'departed_at' => $stay->status === 'checked_out' ? ($stay->checked_out_at ?? now()) : null,
        ]);
        ActivityLogger::log('formc', 'open', "Form C {$f->form_no} opened for {$f->name()} (".($f->nationality ?: 'nationality?').') – due '.$f->deadline_at->format('d M H:i'), $f);

        return $f;
    }

    public static function fromProfile(Guest $g, ReservationRoom $stay): array
    {
        $gender = strtoupper(substr((string) $g->gender, 0, 1));
        $passport = $g->passport_no ?: ($g->id_type === 'passport' ? $g->id_number : null);
        $purpose = match (mb_strtolower((string) $stay->reservation?->purpose)) {
            'business' => 'business', 'conference' => 'conference', 'medical' => 'medical', 'leisure', 'wedding' => 'tourism', default => null,
        };

        return array_filter([
            'surname' => $g->last_name ?: null, 'given_name' => $g->first_name ?: null, 'gender' => in_array($gender, ['M', 'F']) ? $gender : ($gender === 'O' ? 'X' : null),
            'dob' => $g->dob?->toDateString(), 'nationality' => $g->nationality, 'passport_no' => $passport ? strtoupper(preg_replace('/\s+/', '', $passport)) : null,
            'visa_expires_on' => $g->visa_expiry?->toDateString(), 'phone' => $g->phone, 'purpose' => $purpose,
            'address_abroad' => collect([$g->address, $g->city, $g->state, $g->pincode, $g->country])->filter()->implode(', ') ?: null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    protected static function prefill(ReservationRoom $stay): array
    {
        $p = $stay->reservation?->formc_prefill ?? [];

        return collect($p)->only(self::PREFILL)->filter(fn ($v) => $v !== null && $v !== '')->all();
    }

    /** Fields still missing before the form can be filed: column => label (incl. scans). */
    public static function missing(FormCRecord $f): array
    {
        $noVisa = $f->visa_type === 'none' || self::visaExempt($f->nationality);
        $out = [];
        foreach (self::FIELDS as $k => $label) {
            if ($noVisa && str_starts_with($k, 'visa_') && $k !== 'visa_type') {
                continue;
            }
            if ($k === 'visa_type' && $noVisa && ! $f->visa_type) {
                continue;
            }
            if ($k === 'visa_expires_on' && in_array($f->visa_type, ['oci', 'diplomatic'])) {
                continue; // OCI cards are for life; diplomatic visas carry no fixed expiry on the form
            }
            if (blank($f->{$k})) {
                $out[$k] = $label;
            }
        }
        if (self::cfg()['require_scans'] === '1') {
            $docs = self::scans($f);
            if (! $docs['passport']) {
                $out['scan_passport'] = 'Passport scan (photo page)';
            }
            if (! $noVisa && ! $docs['visa']) {
                $out['scan_visa'] = 'Visa scan';
            }
        }

        return $out;
    }

    /** Passport / visa scans on file for the guest (companions without a profile are checked on paper). */
    public static function scans(FormCRecord $f): array
    {
        if (! $f->guest_id) {
            return ['passport' => true, 'visa' => true, 'docs' => collect()];
        }
        $docs = GuestDocument::where('guest_id', $f->guest_id)->orderByDesc('id')->get();
        $idType = $f->guest?->id_type;

        return ['passport' => $docs->contains(fn ($d) => $d->type === 'passport' || ($d->type === 'id_front' && $idType === 'passport')),
            'visa' => $docs->contains(fn ($d) => $d->type === 'visa'), 'docs' => $docs];
    }

    /** Things the desk should know (never block filing – FRRO must be told regardless). */
    public static function warnings(FormCRecord $f): array
    {
        $w = [];
        $in = ($f->checked_in_at ?? now())->copy()->startOfDay();
        if ($f->passport_expires_on && $f->passport_expires_on->lt($in)) {
            $w['passport'] = 'The passport had expired on the day of check-in ('.$f->passport_expires_on->format('d M Y').').';
        }
        if ($f->visa_expires_on && $f->visa_expires_on->lt($in)) {
            $w['visa'] = 'The visa had expired on the day of check-in ('.$f->visa_expires_on->format('d M Y').') – inform the FRRO.';
        } elseif ($f->visa_expires_on && $f->expected_checkout && $f->visa_expires_on->lt($f->expected_checkout)) {
            $w['visa'] = 'The visa expires on '.$f->visa_expires_on->format('d M Y').', before the planned check-out ('.$f->expected_checkout->format('d M Y').').';
        }

        return $w;
    }

    /** Save particulars (pending records only). Optionally write passport / visa / nationality back to the guest profile. */
    public static function save(FormCRecord $f, array $d, bool $syncProfile = true): FormCRecord
    {
        if ($f->status !== 'pending') {
            throw new RuntimeException('This Form C has been '.($f->status === 'submitted' ? 'submitted – re-open it to correct it.' : 'marked exempt.'));
        }
        $d = array_intersect_key($d, self::FIELDS + ['phone' => 1, 'notes' => 1]);
        foreach ($d as $k => $v) {
            $d[$k] = is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v;
        }
        if (! empty($d['passport_no'])) {
            $d['passport_no'] = strtoupper(preg_replace('/\s+/', '', $d['passport_no']));
        }
        if (! empty($d['visa_no'])) {
            $d['visa_no'] = strtoupper(preg_replace('/\s+/', '', $d['visa_no']));
        }
        $today = now()->startOfDay();
        $in = ($f->checked_in_at ?? now())->copy()->startOfDay();
        $dt = fn ($k) => ! empty($d[$k]) ? Carbon::parse($d[$k])->startOfDay() : null;
        $errors = [];
        if (($x = $dt('dob')) && $x->gte($today)) {
            $errors['dob'] = 'The date of birth must be in the past.';
        }
        foreach (['passport_issued_on' => 'passport', 'visa_issued_on' => 'visa'] as $k => $what) {
            if (($x = $dt($k)) && $x->gt($today)) {
                $errors[$k] = "The {$what} issue date can't be in the future.";
            }
        }
        if (($a = $dt('passport_issued_on')) && ($b = $dt('passport_expires_on')) && $b->lte($a)) {
            $errors['passport_expires_on'] = 'The passport expiry must be after its issue date.';
        }
        if (($a = $dt('visa_issued_on')) && ($b = $dt('visa_expires_on')) && $b->lt($a)) {
            $errors['visa_expires_on'] = 'The visa expiry must be on or after its issue date.';
        }
        if (($x = $dt('arrived_india_on')) && $x->gt($in)) {
            $errors['arrived_india_on'] = 'The arrival in India can’t be after the check-in date ('.$in->format('d M Y').').';
        }
        if (($a = $dt('dob')) && ($x = $dt('arrived_india_on')) && $x->lt($a)) {
            $errors['arrived_india_on'] = 'The arrival in India can’t be before the date of birth.';
        }
        if (array_key_exists('nationality', $d) && self::isForeignNationality($d['nationality']) === false) {
            $errors['nationality'] = 'Form C is only for foreign nationals – mark this record exempt if the guest is Indian.';
        }
        if ($errors) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($f, $d, $syncProfile) {
            $f->fill($d)->save();
            if ($syncProfile && $f->guest && ! $f->guest->isPrivacyLocked()) {
                $g = $f->guest;
                $g->fill(array_filter([
                    'nationality' => $f->nationality, 'passport_no' => $f->passport_no, 'visa_expiry' => $f->visa_expires_on?->toDateString(),
                    'dob' => $g->dob ? null : $f->dob?->toDateString(),
                    'gender' => $g->gender ? null : (['M' => 'Male', 'F' => 'Female', 'X' => 'Other'][$f->gender] ?? null),
                ], fn ($v) => $v !== null))->save();
            }
            ActivityLogger::log('formc', 'update', "Form C {$f->form_no} particulars saved (".count(self::missing($f)).' item(s) still missing)', $f);
        });

        return $f->fresh();
    }

    public static function submit(FormCRecord $f, string $ref, ?string $at = null, ?string $lateReason = null): FormCRecord
    {
        if ($f->status !== 'pending') {
            throw new RuntimeException('Only a Form C still to submit can be marked submitted.');
        }
        if ($m = self::missing($f)) {
            throw new RuntimeException('Complete the Form C first – missing: '.implode(', ', $m).'.');
        }
        $ref = strtoupper(trim($ref));
        if (! preg_match('/^[A-Z0-9][A-Z0-9\-\/]{4,39}$/', $ref)) {
            throw new RuntimeException('Enter the FRRO application / reference number exactly as shown on the portal (5–40 letters, digits, - or /).');
        }
        if (FormCRecord::where('frro_ref', $ref)->where('id', '!=', $f->id)->exists()) {
            throw new RuntimeException("Reference {$ref} is already recorded on another Form C.");
        }
        $when = $at ? Carbon::parse($at) : now();
        if ($when->gt(now()->addMinutes(2))) {
            throw new RuntimeException('The submission time can’t be in the future.');
        }
        if ($f->checked_in_at && $when->lt($f->checked_in_at)) {
            throw new RuntimeException('The submission time can’t be before the check-in ('.$f->checked_in_at->format('d M H:i').').');
        }
        $late = $f->deadline_at && $when->gt($f->deadline_at);
        if ($late && mb_strlen(trim((string) $lateReason)) < 5) {
            throw new RuntimeException('This Form C was filed after the '.self::cfg()['deadline_hours'].'-hour deadline ('.$f->deadline_at->format('d M H:i').') – record the reason.');
        }
        $f->update(['status' => 'submitted', 'frro_ref' => $ref, 'submitted_at' => $when, 'submitted_by' => auth()->id(), 'late_reason' => $late ? trim($lateReason) : null]);
        ActivityLogger::log('formc', 'submit', "Form C {$f->form_no} submitted to FRRO – ref {$ref}".($late ? ' (late)' : ''), $f);

        return $f->fresh();
    }

    public static function reportDeparture(FormCRecord $f, ?string $ref = null, ?string $at = null): FormCRecord
    {
        if ($f->status !== 'submitted') {
            throw new RuntimeException('Submit the arrival report first.');
        }
        if (! $f->departed_at) {
            throw new RuntimeException('The guest has not checked out yet.');
        }
        if ($f->departure_submitted_at) {
            throw new RuntimeException('The departure has already been reported.');
        }
        $when = $at ? Carbon::parse($at) : now();
        if ($when->gt(now()->addMinutes(2)) || $when->lt($f->departed_at->copy()->subMinutes(1))) {
            throw new RuntimeException('The departure report time must be between the check-out and now.');
        }
        $ref = $ref !== null && trim($ref) !== '' ? strtoupper(trim($ref)) : null;
        if ($ref && ! preg_match('/^[A-Z0-9][A-Z0-9\-\/]{4,39}$/', $ref)) {
            throw new RuntimeException('The departure reference may only contain letters, digits, - or / (5–40 characters).');
        }
        $f->update(['departure_submitted_at' => $when, 'departure_by' => auth()->id(), 'departure_ref' => $ref]);
        ActivityLogger::log('formc', 'departure', "Form C {$f->form_no}: departure reported to FRRO".($ref ? " – ref {$ref}" : ''), $f);

        return $f->fresh();
    }

    public static function exempt(FormCRecord $f, string $reason): FormCRecord
    {
        if ($f->status !== 'pending') {
            throw new RuntimeException('Only a Form C still to submit can be marked exempt.');
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw new RuntimeException('Give the reason this guest needs no Form C (e.g. Indian national – nationality corrected).');
        }
        $f->update(['status' => 'exempt', 'exempt_reason' => trim($reason)]);
        ActivityLogger::log('formc', 'exempt', "Form C {$f->form_no} marked exempt: ".trim($reason), $f);

        return $f->fresh();
    }

    public static function reopen(FormCRecord $f, string $reason): FormCRecord
    {
        if (! in_array($f->status, ['submitted', 'exempt'])) {
            throw new RuntimeException('This Form C is already open.');
        }
        if ($f->departure_submitted_at) {
            throw new RuntimeException('The departure has been reported – correct the record on the FRRO portal.');
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw new RuntimeException('Give the reason for re-opening.');
        }
        $was = $f->status === 'submitted' ? "submitted {$f->submitted_at?->format('d M H:i')} ref {$f->frro_ref}" : "exempt ({$f->exempt_reason})";
        $f->update(['status' => 'pending', 'frro_ref' => null, 'submitted_at' => null, 'submitted_by' => null, 'late_reason' => null, 'exempt_reason' => null,
            'notes' => trim(($f->notes ? $f->notes."\n" : '').now()->format('d M Y H:i').' re-opened by '.(auth()->user()?->name ?? 'system').": {$reason} (was {$was})")]);
        ActivityLogger::log('formc', 'reopen', "Form C {$f->form_no} re-opened: ".trim($reason), $f);

        return $f->fresh();
    }

    // ───────────────────────── desk lists & alerts ─────────────────────────

    /** In-house guests whose nationality is blank but look foreign (passport number / foreign address). */
    public static function nationalityCheck(): Collection
    {
        return ReservationRoom::with('reservation.guest', 'guest', 'room')->where('status', 'checked_in')->get()
            ->filter(function ($s) {
                $g = self::stayGuest($s);

                return $g && self::isForeignNationality($g->nationality) === null
                    && (filled($g->passport_no) || $g->id_type === 'passport' || self::isForeignNationality($g->country) === true);
            })->values();
    }

    public static function setNationality(Guest $g, string $nationality): int
    {
        $g->update(['nationality' => trim($nationality)]);
        ActivityLogger::log('formc', 'nationality', "Nationality of {$g->full_name} set to ".trim($nationality), $g);

        return self::syncGuest($g->fresh());
    }

    public static function alerts(): array
    {
        if (! self::enabled()) {
            return ['pending' => 0, 'overdue' => 0, 'due_soon' => 0, 'incomplete' => 0, 'departures' => 0, 'departures_late' => 0, 'visa' => 0, 'nationality' => 0];
        }
        $cfg = self::cfg();
        $pending = FormCRecord::with('guest')->where('status', 'pending')->get();
        $dep = FormCRecord::where('status', 'submitted')->whereNotNull('departed_at')->whereNull('departure_submitted_at')->get();
        $inHouse = FormCRecord::whereIn('status', ['pending', 'submitted'])->whereNull('departed_at')->whereNotNull('visa_expires_on')->get();

        return [
            'pending' => $pending->count(),
            'overdue' => $pending->filter(fn ($f) => $f->isOverdue())->count(),
            'due_soon' => $pending->filter(fn ($f) => ! $f->isOverdue() && $f->deadline_at && $f->deadline_at->lte(now()->addHours($cfg['alert_hours'])))->count(),
            'incomplete' => $pending->filter(fn ($f) => self::missing($f))->count(),
            'departures' => $dep->count(),
            'departures_late' => $dep->filter(fn ($f) => $f->departed_at->lt(now()->subHours($cfg['departure_hours'])))->count(),
            'visa' => $inHouse->filter(fn ($f) => $f->expected_checkout && $f->visa_expires_on->lt($f->expected_checkout))->count(),
            'nationality' => self::nationalityCheck()->count(),
        ];
    }

    public static function attentionCount(): int
    {
        $a = self::alerts();

        return $a['overdue'] + $a['due_soon'] + $a['departures_late'] + $a['nationality'];
    }

    /** Hourly job: e-mail the desk once when a Form C is about to fall due and once when it is overdue. */
    public static function runDue(): array
    {
        $stats = ['due' => 0, 'overdue' => 0, 'mails' => 0];
        if (! self::enabled()) {
            return $stats;
        }
        $cfg = self::cfg();
        $due = FormCRecord::where('status', 'pending')->whereNull('alert_due_at')->where('deadline_at', '>', now())
            ->where('deadline_at', '<=', now()->addHours($cfg['alert_hours']))->get();
        $over = FormCRecord::where('status', 'pending')->whereNull('alert_overdue_at')->where('deadline_at', '<=', now())->get();
        if ($due->isEmpty() && $over->isEmpty()) {
            return $stats;
        }
        $h = hotel();
        $line = fn ($f) => "• {$f->form_no} {$f->name()} ({$f->nationality}), room ".($f->stay?->room?->room_no ?? '—').' – due '.$f->deadline_at->format('d M H:i')
            .(($m = self::missing($f)) ? ' – missing: '.implode(', ', array_slice($m, 0, 4)).(count($m) > 4 ? '…' : '') : ' – ready to file');
        $body = "Form C (FRRO) reports for {$h->name}:\n\n"
            .($over->isNotEmpty() ? "OVERDUE – file now:\n".$over->map($line)->implode("\n")."\n\n" : '')
            .($due->isNotEmpty() ? "Due within {$cfg['alert_hours']} h:\n".$due->map($line)->implode("\n")."\n\n" : '')
            .'File on indianfrro.gov.in and record the reference number on the Form C desk.';
        $to = $cfg['alert_email'] ?: $h->email;
        $subject = ($over->isNotEmpty() ? $over->count().' Form C overdue' : $due->count().' Form C due soon').' – '.$h->name;
        MailService::send($to, new GuestMessage($subject, $body, $h), 'formc_alert');
        $stats['mails']++;
        FormCRecord::whereIn('id', $due->pluck('id'))->update(['alert_due_at' => now()]);
        FormCRecord::whereIn('id', $over->pluck('id'))->update(['alert_overdue_at' => now(), 'alert_due_at' => DB::raw('COALESCE(alert_due_at, NOW())')]);
        $stats['due'] = $due->count();
        $stats['overdue'] = $over->count();

        return $stats;
    }

    /** FRRO-style register rows for CSV export. */
    public static function exportRows(Collection $records): array
    {
        $rows = [['Form no', 'Status', 'Surname', 'Given name', 'Gender', 'Date of birth', 'Nationality', 'Passport no', 'Passport place of issue', 'Passport issued',
            'Passport valid until', 'Visa no', 'Visa type', 'Visa place of issue', 'Visa issued', 'Visa valid until', 'Arrived in India', 'Arrived from', 'Port of arrival',
            'Next destination', 'Purpose', 'Address abroad', 'Room', 'Checked in', 'Expected check-out', 'Deadline', 'Submitted at', 'FRRO reference', 'Late reason',
            'Checked out', 'Departure reported', 'Departure reference']];
        $d = fn ($v) => $v ? $v->format('Y-m-d') : '';
        $t = fn ($v) => $v ? $v->format('Y-m-d H:i') : '';
        foreach ($records as $f) {
            $rows[] = [$f->form_no, FormCRecord::STATUSES[$f->status] ?? $f->status, $f->surname, $f->given_name, $f->gender, $d($f->dob), $f->nationality, $f->passport_no,
                $f->passport_place, $d($f->passport_issued_on), $d($f->passport_expires_on), $f->visa_no, FormCRecord::VISA_TYPES[$f->visa_type] ?? $f->visa_type, $f->visa_place,
                $d($f->visa_issued_on), $d($f->visa_expires_on), $d($f->arrived_india_on), $f->arrived_from, $f->port_of_arrival, $f->next_destination,
                FormCRecord::PURPOSES[$f->purpose] ?? $f->purpose, $f->address_abroad, $f->stay?->room?->room_no, $t($f->checked_in_at), $d($f->expected_checkout),
                $t($f->deadline_at), $t($f->submitted_at), $f->frro_ref, $f->late_reason, $t($f->departed_at), $t($f->departure_submitted_at), $f->departure_ref];
        }

        return $rows;
    }
}
