<?php

namespace App\Services;

use App\Models\ChannelMessage;
use App\Models\DataBreach;
use App\Models\EmailLog;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\GuestDocument;
use App\Models\LoyaltyEntry;
use App\Models\MessageLog;
use App\Models\PiiAccessLog;
use App\Models\PrivacyRequest;
use App\Models\PrivacyRetentionRun;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Support\Numbering;
use App\Support\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * v2.9 guest data privacy (Ghana Data Protection Act 2012 (Act 843) / GDPR; originally India DPDP): data-subject requests, erasure by anonymisation with legal
 * holds, retention policies, PII access logging, access exports and breach deadlines. All methods are tenant-scoped.
 */
class PrivacyService
{
    public const DEFAULTS = [
        'privacy_response_days' => 30, 'privacy_extension_days' => 60, 'privacy_tax_years' => 8, 'privacy_police_years' => 3,
        'retention_enabled' => 1, 'retention_id_days' => 180, 'retention_message_days' => 365, 'retention_inactive_years' => 8,
        'retention_unverified_days' => 7, 'breach_notify_hours' => 72,
        'dpo_name' => '', 'dpo_email' => '', 'dpo_phone' => '', 'privacy_notice' => '',
    ];

    /** Profile columns holding personal data (what erasure clears). */
    public const PII_FIELDS = ['title', 'first_name', 'last_name', 'gender', 'dob', 'anniversary', 'phone', 'email', 'nationality', 'id_type', 'id_number',
        'passport_no', 'visa_expiry', 'address', 'city', 'state', 'country', 'pincode', 'tax_number', 'company_id', 'preferences', 'notes', 'tags'];

    /** Kept while tax invoices must be retained (name & billing address / TIN printed on the invoice). */
    public const TAX_FIELDS = ['title', 'first_name', 'last_name', 'address', 'city', 'state', 'country', 'pincode', 'tax_number', 'company_id'];

    /** Kept for the police / Form-C register of foreign nationals. */
    public const POLICE_FIELDS = ['title', 'first_name', 'last_name', 'gender', 'dob', 'nationality', 'passport_no', 'visa_expiry', 'address', 'city', 'country'];

    public const FIELD_LABELS = ['title' => 'Title', 'first_name' => 'First name', 'last_name' => 'Last name', 'gender' => 'Gender', 'dob' => 'Date of birth',
        'anniversary' => 'Anniversary', 'phone' => 'Mobile', 'email' => 'E-mail', 'nationality' => 'Nationality', 'id_type' => 'ID type', 'id_number' => 'ID number',
        'passport_no' => 'Passport no.', 'visa_expiry' => 'Visa expiry', 'address' => 'Address', 'city' => 'City', 'state' => 'State', 'country' => 'Country',
        'pincode' => 'PIN code', 'tax_number' => 'TIN', 'company_id' => 'Company', 'preferences' => 'Preferences', 'notes' => 'Notes', 'tags' => 'Tags'];

    public const ERASED_NAME = 'Erased guest';

    public const DEFAULT_NOTICE = "We collect the personal data you give us when you book, check in and stay (name, contact details, identity document and nationality, stay and billing history, preferences and feedback) to provide your stay, to meet legal obligations (the police / Form-C register for foreign nationals, tax invoices) and, only with your consent, to send you offers.\n\nWe keep identity-document scans for a limited period after check-out, tax records for as long as the law requires, and marketing data until you withdraw consent. Card data is never stored.\n\nYou can ask for a copy of your data, have it corrected or erased, withdraw marketing consent at any time or raise a grievance using the form below. We reply within the period set by law.";

    // ------------------------------------------------------------------ settings & helpers

    public static function settings(): array
    {
        $out = [];
        foreach (self::DEFAULTS as $k => $d) {
            $v = setting($k, $d);
            $out[$k] = is_int($d) ? (int) $v : (string) ($v ?? '');
        }
        $out['privacy_notice'] = trim($out['privacy_notice']) !== '' ? $out['privacy_notice'] : self::DEFAULT_NOTICE;

        return $out;
    }

    public static function isForeign(Guest $g): bool
    {
        return FormCService::isForeign($g); // v3.4: 'Indian' / 'IN' count as home too
    }

    /** Reservations where the guest is the booker or a room guest. */
    public static function reservationIds(Guest $g): Collection
    {
        return Reservation::where('guest_id', $g->id)->pluck('id')
            ->merge(ReservationRoom::where('guest_id', $g->id)->pluck('reservation_id'))->unique()->values();
    }

    /** Stays (room lines) that belong to the guest: named on the line, or the booker's line with no one else named. */
    public static function stayIds(Guest $g): Collection
    {
        return ReservationRoom::where(fn ($q) => $q->where('guest_id', $g->id)
            ->orWhere(fn ($w) => $w->whereNull('guest_id')->whereIn('reservation_id', Reservation::where('guest_id', $g->id)->select('id'))))->pluck('id');
    }

    public static function logAccess(?Guest $g, string $action, ?string $detail = null, ?string $reason = null): void
    {
        PiiAccessLog::create(['guest_id' => $g?->id, 'user_id' => auth()->id(), 'action' => $action, 'detail' => $detail ? mb_substr($detail, 0, 190) : null,
            'reason' => $reason ? mb_substr($reason, 0, 190) : null, 'ip' => request()?->ip(), 'created_at' => now()]);
    }

    // ------------------------------------------------------------------ erasure

    /** Reasons the profile cannot be erased right now. */
    public static function blockers(Guest $g): array
    {
        if ($g->isErased()) {
            return ['This profile has already been erased.'];
        }
        $out = [];
        $ids = self::reservationIds($g);
        foreach (Reservation::whereIn('id', $ids)->whereIn('status', ['tentative', 'confirmed', 'checked_in'])->orderBy('arrival_date')->get() as $r) {
            $out[] = "Booking {$r->reservation_no} is ".str_replace('_', ' ', $r->status).' ('.$r->arrival_date->format('d M Y').') – cancel it or wait until the stay is completed.';
        }
        foreach (Folio::where('guest_id', $g->id)->where('status', 'open')->get() as $f) {
            $bal = $f->totals()['balance'];
            $out[] = "Folio {$f->folio_no} is still open".(abs($bal) >= 0.01 ? ' with a balance of '.money($bal) : '').' – settle and close it first.';
        }

        return $out;
    }

    /** Legal holds: what must be kept, why and until when (only holds that have not expired yet). */
    public static function holds(Guest $g): array
    {
        $s = self::settings();
        $today = bdate()->copy()->startOfDay();
        $holds = [];
        $inv = Folio::where('guest_id', $g->id)->whereNotNull('invoice_no')->orderByDesc('closed_at')->first(['id', 'invoice_no', 'closed_at', 'updated_at']);
        if ($inv && $s['privacy_tax_years'] > 0) {
            $until = ($inv->closed_at ?? $inv->updated_at)->copy()->addYears($s['privacy_tax_years'])->startOfDay();
            if ($until->gt($today)) {
                $holds[] = ['key' => 'tax', 'label' => "Tax invoices (last {$inv->invoice_no})", 'until' => $until, 'fields' => self::TAX_FIELDS];
            }
        }
        if (self::isForeign($g) && $s['privacy_police_years'] > 0) {
            $last = Reservation::whereIn('id', self::reservationIds($g))->where('status', 'checked_out')->max('departure_date');
            if ($last) {
                $until = Carbon::parse($last)->addYears($s['privacy_police_years'])->startOfDay();
                if ($until->gt($today)) {
                    $holds[] = ['key' => 'police', 'label' => 'Police / Form-C register (foreign national)', 'until' => $until, 'fields' => self::POLICE_FIELDS];
                }
            }
        }

        return $holds;
    }

    /** What erasure would do – shown before it is executed. */
    public static function plan(Guest $g): array
    {
        $holds = self::holds($g);
        $retain = collect($holds)->flatMap(fn ($h) => $h['fields'])->unique()->values()->all();
        $until = collect($holds)->max(fn ($h) => $h['until']);
        $resIds = self::reservationIds($g);
        $stayIds = self::stayIds($g);
        $phone = MessageService::normalise($g->phone);
        $filled = collect(self::PII_FIELDS)->filter(fn ($f) => filled($g->{$f}));
        $retainPolice = in_array('passport_no', $retain);
        $items = [
            'profile' => ['Profile fields cleared', $filled->reject(fn ($f) => in_array($f, $retain))->count()],
            'documents' => ['ID document scans deleted', GuestDocument::where('guest_id', $g->id)->count()],
            'signatures' => ['Registration-card signatures deleted', ReservationRoom::whereIn('id', $stayIds)->whereNotNull('grc_signature_path')->count()],
            'messages' => ['SMS / WhatsApp log entries blanked', self::messageQuery($resIds, $g, $phone)->count()],
            'emails' => ['E-mail log addresses removed', self::emailQuery($resIds, $g)->count()],
            'links' => ['Guest-portal & online check-in links revoked', ReservationRoom::whereIn('id', $stayIds)->whereNotNull('portal_token')->count()
                + Reservation::whereIn('id', $resIds)->where('guest_id', $g->id)->whereNotNull('checkin_token')->count()],
            'notes' => ['Booking special requests cleared', Reservation::whereIn('id', $resIds)->where('guest_id', $g->id)->whereNotNull('special_request')->count()],
            'feedback' => ['Feedback name / contact removed', self::feedbackQuery($g, $resIds)->count()],
            'surveys' => ['Survey links revoked & survey contact details removed', DB::table('survey_invitations')->where('hotel_id', $g->hotel_id)->where('guest_id', $g->id)->count()],
            'dining' => ['Restaurant bookings anonymised (upcoming ones cancelled)', self::diningQuery($g, $phone)->count()],
            'transport' => ['Airport / city transfers anonymised (upcoming ones cancelled)', self::transportQuery($g, $phone)->count()],
            // v3.4: Form C & currency-exchange records are statutory – kept intact while the police / Form-C hold runs, anonymised afterwards
            'formc' => [$retainPolice ? 'Form C (FRRO) records kept under the police-register hold' : 'Form C (FRRO) records anonymised', \App\Models\FormCRecord::where('guest_id', $g->id)->count()],
            'forex' => [$retainPolice ? 'Currency-exchange records kept under the police-register hold' : 'Currency-exchange records anonymised (amounts kept)', \App\Models\ForexExchange::where('guest_id', $g->id)->count()],
            // v3.5: front desk operations
            'frontdesk' => ['Desk messages, wake-up calls, lost-property records, log-book entries & traces anonymised',
                DB::table('guest_messages')->where('hotel_id', $g->hotel_id)->where(fn ($q) => $q->where('guest_id', $g->id)->orWhereIn('reservation_room_id', $stayIds))->count()
                + DB::table('wakeup_calls')->where('hotel_id', $g->hotel_id)->whereIn('reservation_room_id', $stayIds)->count()
                + DB::table('lost_reports')->where('hotel_id', $g->hotel_id)->where(fn ($q) => $q->where('guest_id', $g->id)->orWhereIn('reservation_room_id', $stayIds))->count()
                + DB::table('lost_found_items')->where('hotel_id', $g->hotel_id)->where('guest_id', $g->id)->count()
                + DB::table('log_entries')->where('hotel_id', $g->hotel_id)->where('guest_id', $g->id)->count()
                + DB::table('traces')->where('hotel_id', $g->hotel_id)->where('guest_id', $g->id)->count()],
            // v4.2: the guest's visitors lose the host name; incident reports stay as the hotel's security record but are unlinked and the name cut out
            'security' => ['Visitor records (as host) anonymised, security incidents unlinked from the guest',
                DB::table('visitors')->where('hotel_id', $g->hotel_id)->where(fn ($q) => $q->where('guest_id', $g->id)->orWhereIn('reservation_room_id', $stayIds))->count()
                + DB::table('security_incidents')->where('hotel_id', $g->hotel_id)->where('guest_id', $g->id)->count()],
            'reviews' => ['Online reviews unlinked from the guest', DB::table('online_reviews')->where('hotel_id', $g->hotel_id)->where('guest_id', $g->id)->count()],
            'contacts' => ['Payment-link, laundry, spa & POS contact details removed', self::contactCount($g, $stayIds)],
            'ota' => ['OTA message payloads scrubbed', ChannelMessage::whereIn('reservation_id', $resIds)->count()],
            'loyalty' => ['Loyalty points forfeited', (int) $g->loyalty_points],
        ];

        return ['blockers' => self::blockers($g), 'holds' => $holds, 'hold_until' => $until, 'retain' => $retain, 'items' => $items,
            'result' => $until ? 'restricted' : 'erased'];
    }

    /** v3.2: restaurant table bookings made under the guest profile or the guest's phone number. */
    protected static function diningQuery(Guest $g, ?string $phone)
    {
        return DB::table('table_reservations')->where('hotel_id', $g->hotel_id)
            ->where(fn ($q) => $q->where('guest_id', $g->id)->when($phone, fn ($w) => $w->orWhere('phone', $phone)));
    }

    /** v3.3: guest transfers booked under the guest profile or the guest's phone number. */
    protected static function transportQuery(Guest $g, ?string $phone)
    {
        return DB::table('transport_trips')->where('hotel_id', $g->hotel_id)
            ->where(fn ($q) => $q->where('guest_id', $g->id)->when($phone, fn ($w) => $w->orWhere('phone', $phone)));
    }

    /** v3.1: feedback given for one of the guest's stays or linked to the guest profile (surveys). */
    protected static function feedbackQuery(Guest $g, Collection $resIds)
    {
        return DB::table('guest_feedback')->where('hotel_id', $g->hotel_id)->where(fn ($q) => $q->whereIn('reservation_id', $resIds)->orWhere('guest_id', $g->id));
    }

    protected static function messageQuery(Collection $resIds, Guest $g, ?string $phone)
    {
        return MessageLog::where(fn ($q) => $q->where(fn ($w) => $w->where('related_type', 'Reservation')->whereIn('related_id', $resIds))
            ->orWhere(fn ($w) => $w->where('related_type', 'Guest')->where('related_id', $g->id))
            ->when($phone, fn ($w) => $w->orWhere('to', $phone)))->where('body', '!=', '[erased]');
    }

    protected static function emailQuery(Collection $resIds, Guest $g)
    {
        $folios = Folio::where('guest_id', $g->id)->pluck('id');

        return EmailLog::where(fn ($q) => $q->where(fn ($w) => $w->where('related_type', 'Reservation')->whereIn('related_id', $resIds))
            ->orWhere(fn ($w) => $w->where('related_type', 'Guest')->where('related_id', $g->id))
            ->orWhere(fn ($w) => $w->where('related_type', 'Folio')->whereIn('related_id', $folios))
            ->when($g->email, fn ($w) => $w->orWhere('to_email', $g->email)))->where('to_email', '!=', '[erased]');
    }

    protected static function contactCount(Guest $g, Collection $stayIds): int
    {
        $h = $g->hotel_id;

        return DB::table('online_payments')->where('hotel_id', $h)->where('guest_id', $g->id)->where(fn ($q) => $q->whereNotNull('payer_email')->orWhereNotNull('payer_phone'))->count()
            + DB::table('laundry_orders')->where('hotel_id', $h)->whereIn('reservation_room_id', $stayIds)->whereNotNull('phone')->count()
            + DB::table('spa_appointments')->where('hotel_id', $h)->where('guest_id', $g->id)->whereNotNull('customer_phone')->count()
            + DB::table('pos_orders')->where('hotel_id', $h)->whereIn('reservation_room_id', $stayIds)->whereNotNull('customer_phone')->count();
    }

    /**
     * Erase (anonymise) a guest. With an active legal hold the legally required fields are kept and the profile is
     * "restricted" until the hold ends (the retention job then completes the erasure); otherwise it is fully erased.
     * Financial records (folios, invoices, payments) stay – they no longer point at an identifiable person.
     */
    public static function erase(Guest $g, ?PrivacyRequest $req = null, ?int $userId = null, string $source = 'privacy_request'): array
    {
        $plan = self::plan($g);
        if ($plan['blockers']) {
            throw ValidationException::withMessages(['erase' => implode(' ', $plan['blockers'])]);
        }
        $files = [];
        $counts = collect($plan['items'])->map(fn ($i) => $i[1])->all();
        DB::transaction(function () use ($g, $plan, $req, $userId, $source, &$files) {
            $full = ! $plan['hold_until'];
            self::scrub($g, $plan['retain'], $full, $files, $source, $userId);
            $g->forceFill(['privacy_status' => $full ? 'erased' : 'restricted', 'legal_hold_until' => $plan['hold_until']?->toDateString(), 'erased_at' => now(),
                'privacy_note' => $full ? 'Erased'.($req ? " on request {$req->request_no}" : ' by retention policy')
                    : 'Restricted – '.collect($plan['holds'])->map(fn ($h) => $h['label'].' until '.$h['until']->format('d M Y'))->implode('; ')])->save();
            ActivityLogger::log('privacy', 'erase', "Guest #{$g->id} ".($full ? 'erased' : 'restricted until '.$plan['hold_until']->format('d M Y')).($req ? " ({$req->request_no})" : ' (retention policy)'), $g);
            if ($req) {
                $outcome = $full ? 'Personal data erased (anonymised). Financial records kept without personal identifiers.'
                    : 'Personal data erased except what the law requires us to keep ('.collect($plan['holds'])->map(fn ($h) => $h['label'].' until '.$h['until']->format('d M Y'))->implode('; ')
                        .'). The profile is restricted – no further use – and will be fully erased automatically when the hold ends.';
                $req->forceFill(['status' => 'completed', 'completed_at' => now(), 'completed_by' => $userId, 'outcome' => $outcome])->save();
                $req->log('erased', $outcome, $userId);
            }
        });
        foreach ($files as $f) {
            Storage::disk('local')->delete($f);
        }

        return ['status' => $plan['hold_until'] ? 'restricted' : 'erased', 'hold_until' => $plan['hold_until'], 'counts' => $counts];
    }

    /** A restricted profile whose legal hold has ended: clear what was kept. */
    public static function completeHold(Guest $g): void
    {
        $files = [];
        DB::transaction(function () use ($g, &$files) {
            self::scrub($g, [], true, $files, 'retention_policy', null);
            $g->forceFill(['privacy_status' => 'erased', 'legal_hold_until' => null, 'privacy_note' => 'Erased – legal hold ended '.bdate()->format('d M Y')])->save();
            ActivityLogger::log('privacy', 'erase', "Guest #{$g->id} erased – legal hold ended", $g);
        });
        foreach ($files as $f) {
            Storage::disk('local')->delete($f);
        }
    }

    protected static function scrub(Guest $g, array $retain, bool $full, array &$files, string $source, ?int $userId): void
    {
        $h = $g->hotel_id;
        $resIds = self::reservationIds($g);
        $stayIds = self::stayIds($g);
        $phone = MessageService::normalise($g->phone);
        $names = collect([$g->full_name, trim($g->first_name.' '.$g->last_name)])->map(fn ($n) => trim($n))
            ->filter(fn ($n) => mb_strlen($n) >= 5 && $n !== self::ERASED_NAME)->unique()->values();

        // marketing consent: withdraw on every channel (logged as proof), then drop the IPs from the consent log
        CrmService::setConsent($g, collect(CrmService::CHANNELS)->map(fn () => false)->all(), $source);
        DB::table('marketing_consent_logs')->where('hotel_id', $h)->where('guest_id', $g->id)->update(['ip' => null]);

        foreach (GuestDocument::where('guest_id', $g->id)->get() as $d) {
            $files[] = $d->path;
            $d->delete();
        }
        foreach (ReservationRoom::whereIn('id', $stayIds)->whereNotNull('grc_signature_path')->get() as $s) {
            $files[] = $s->grc_signature_path;
            $s->forceFill(['grc_signature_path' => null])->save();
        }
        ReservationRoom::whereIn('id', $stayIds)->whereNotNull('portal_token')->update(['portal_token' => null]);
        Reservation::whereIn('id', $resIds)->where('guest_id', $g->id)->update(['checkin_token' => null, 'special_request' => null]);
        self::messageQuery($resIds, $g, $phone)->update(['to' => '[erased]', 'body' => '[erased]']);
        self::emailQuery($resIds, $g)->update(['to_email' => '[erased]']);
        self::feedbackQuery($g, $resIds)->update(['guest_name' => null, 'contact' => null]);
        // v3.2: upcoming restaurant bookings are cancelled; past ones stay for covers statistics but lose every identifier
        self::diningQuery($g, $phone)->whereIn('status', ['booked', 'confirmed', 'waiting', 'notified'])
            ->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => 'Guest data erased']);
        self::diningQuery($g, $phone)->update(['name' => self::ERASED_NAME, 'phone' => null, 'email' => null, 'notes' => null, 'token_hash' => null, 'guest_id' => null]);
        // v3.3: planned transfers are cancelled; driven trips keep km, fares & timings for fleet statistics but lose every identifier
        self::transportQuery($g, $phone)->whereIn('status', ['requested', 'confirmed', 'assigned'])
            ->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => 'Guest data erased']);
        self::transportQuery($g, $phone)->update(['passenger_name' => self::ERASED_NAME, 'phone' => null, 'flight_no' => null, 'notes' => null, 'extra_note' => null, 'guest_id' => null]);
        // v3.4: Form C / forex records keep their particulars while the police-register hold runs (passport retained), else lose every identifier
        if (! in_array('passport_no', $retain)) {
            DB::table('form_c_records')->where('hotel_id', $h)->where('guest_id', $g->id)->update(['surname' => null, 'given_name' => self::ERASED_NAME, 'dob' => null,
                'passport_no' => null, 'passport_place' => null, 'visa_no' => null, 'visa_place' => null, 'address_abroad' => null, 'phone' => null, 'arrived_from' => null,
                'next_destination' => null, 'notes' => null, 'guest_id' => null]);
            DB::table('forex_exchanges')->where('hotel_id', $h)->where('guest_id', $g->id)->update(['guest_name' => self::ERASED_NAME, 'passport_no' => null, 'notes' => null, 'guest_id' => null]);
        }
        // v3.5: desk messages lose their content, wake-ups their notes, lost property its claimant data; names are cut out of log-book entries & traces
        $gm = fn () => DB::table('guest_messages')->where('hotel_id', $h)->where(fn ($q) => $q->where('guest_id', $g->id)->orWhereIn('reservation_room_id', $stayIds));
        $gm()->whereIn('status', ['pending', 'notified'])->update(['status' => 'cancelled', 'close_reason' => 'Guest data erased']);
        $gm()->update(['body' => null, 'from_phone' => null, 'tracking_no' => null, 'collected_by' => null, 'guest_id' => null]);
        DB::table('wakeup_calls')->where('hotel_id', $h)->whereIn('reservation_room_id', $stayIds)->where('status', 'scheduled')->update(['status' => 'cancelled', 'outcome_note' => 'Guest data erased']);
        DB::table('wakeup_calls')->where('hotel_id', $h)->whereIn('reservation_room_id', $stayIds)->update(['note' => null]);
        DB::table('lost_reports')->where('hotel_id', $h)->where(fn ($q) => $q->where('guest_id', $g->id)->orWhereIn('reservation_room_id', $stayIds))
            ->update(['name' => self::ERASED_NAME, 'phone' => null, 'email' => null, 'description' => null, 'guest_id' => null]);
        DB::table('lost_found_items')->where('hotel_id', $h)->where('guest_id', $g->id)->update(['returned_to' => DB::raw("CASE WHEN returned_to IS NULL THEN NULL ELSE '".self::ERASED_NAME."' END"),
            'claimant_phone' => null, 'claimant_id_type' => null, 'claimant_id_no' => null, 'guest_id' => null]);
        foreach (['log_entries' => ['title', 'body'], 'traces' => ['text']] as $tbl => $cols) {
            foreach (DB::table($tbl)->where('hotel_id', $h)->where('guest_id', $g->id)->get() as $row) {
                $upd = ['guest_id' => null];
                foreach ($cols as $col) {
                    $upd[$col] = $row->{$col} === null ? null : $names->reduce(fn ($t, $n) => str_ireplace($n, self::ERASED_NAME, $t), $row->{$col});
                }
                DB::table($tbl)->where('id', $row->id)->update($upd);
            }
        }
        // v4.2: visitors the guest received / pre-registered, security incidents
        $vq = fn () => DB::table('visitors')->where('hotel_id', $h)->where(fn ($q) => $q->where('guest_id', $g->id)->orWhereIn('reservation_room_id', $stayIds));
        $vq()->whereIn('status', ['expected', 'awaiting'])->update(['status' => 'cancelled', 'deny_reason' => 'Guest data erased']);
        $vq()->update(['host_name' => self::ERASED_NAME, 'guest_id' => null, 'pre_token' => null]);
        foreach (DB::table('security_incidents')->where('hotel_id', $h)->where('guest_id', $g->id)->get() as $row) {
            $upd = ['guest_id' => null];
            foreach (['title', 'description', 'persons_involved'] as $col) {
                $upd[$col] = $row->{$col} === null ? null : $names->reduce(fn ($t, $n) => str_ireplace($n, self::ERASED_NAME, $t), $row->{$col});
            }
            DB::table('security_incidents')->where('id', $row->id)->update($upd);
        }
        // v3.1: survey links stop working, nothing more is sent; public reviews stay (they are the reviewer's own post) but lose the link to the guest
        DB::table('survey_invitations')->where('hotel_id', $h)->where('guest_id', $g->id)->update(['to_email' => null, 'to_phone' => null, 'token_hash' => null, 'send_at' => null]);
        DB::table('survey_invitations')->where('hotel_id', $h)->where('guest_id', $g->id)->whereIn('status', ['scheduled', 'sent', 'opened', 'failed'])
            ->update(['status' => 'skipped', 'skip_reason' => 'Guest data erased']);
        DB::table('online_reviews')->where('hotel_id', $h)->where('guest_id', $g->id)->update(['guest_id' => null, 'reservation_id' => null]);
        DB::table('online_payments')->where('hotel_id', $h)->where('guest_id', $g->id)->update(['payer_email' => null, 'payer_phone' => null] + ($full ? ['payer_name' => self::ERASED_NAME] : []));
        DB::table('laundry_orders')->where('hotel_id', $h)->whereIn('reservation_room_id', $stayIds)->update(['phone' => null] + ($full ? ['guest_name' => null] : []));
        DB::table('spa_appointments')->where('hotel_id', $h)->where('guest_id', $g->id)->update(['customer_phone' => null, 'notes' => null] + ($full ? ['customer_name' => null] : []));
        DB::table('pos_orders')->where('hotel_id', $h)->whereIn('reservation_room_id', $stayIds)->update(['customer_phone' => null] + ($full ? ['customer_name' => null] : []));
        foreach (ChannelMessage::whereIn('reservation_id', $resIds)->get() as $m) {
            $p = $m->payload ?? [];
            if (isset($p['guest']) || isset($p['remarks'])) {
                $p['guest'] = ['first_name' => '[erased]'];
                unset($p['remarks']);
                $m->forceFill(['payload' => $p])->save();
            }
        }
        if ($g->loyalty_points) {
            LoyaltyEntry::create(['type' => 'adjust', 'guest_id' => $g->id, 'points' => -$g->loyalty_points, 'user_id' => $userId, 'description' => 'Forfeited – personal data erased']);
        }
        if ($full) {
            foreach ($names as $n) {
                DB::table('activity_logs')->where('hotel_id', $h)->where('description', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $n).'%')
                    ->update(['description' => DB::raw('REPLACE(description, '.DB::getPdo()->quote($n).", '[erased guest]')")]);
            }
        }

        $clear = [];
        foreach (self::PII_FIELDS as $f) {
            if (! in_array($f, $retain)) {
                $clear[$f] = null;
            }
        }
        if (! in_array('first_name', $retain)) {
            $clear['first_name'] = self::ERASED_NAME;
        }
        $g->forceFill($clear + ['is_vip' => false, 'is_blacklisted' => false, 'loyalty_points' => 0, 'email_opt_in' => false, 'sms_opt_in' => false,
            'whatsapp_opt_in' => false, 'consent_at' => null])->save();
    }

    // ------------------------------------------------------------------ access export

    public static function export(Guest $g): array
    {
        $hotel = hotel();
        $s = self::settings();
        $resIds = self::reservationIds($g);
        $stayIds = self::stayIds($g);
        $phone = MessageService::normalise($g->phone);
        $d = fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v;
        $profile = collect(self::PII_FIELDS)->mapWithKeys(fn ($f) => [self::FIELD_LABELS[$f] => $f === 'company_id' ? $g->company?->name : $d($g->{$f})])
            ->filter(fn ($v) => filled($v))->all();

        return [
            'about' => ['hotel' => $hotel->legal_name ?: $hotel->name, 'address' => trim($hotel->address.', '.$hotel->city.', '.$hotel->country, ', '),
                'data_protection_contact' => trim($s['dpo_name'].' '.$s['dpo_email'].' '.$s['dpo_phone']) ?: $hotel->email,
                'generated_at' => now()->toIso8601String(), 'guest_reference' => 'G-'.$g->id],
            'profile' => $profile + ['VIP' => $g->is_vip ? 'yes' : 'no', 'Loyalty points' => (int) $g->loyalty_points, 'Profile created' => $d($g->created_at)],
            'marketing_consent' => ['email' => (bool) $g->email_opt_in, 'sms' => (bool) $g->sms_opt_in, 'whatsapp' => (bool) $g->whatsapp_opt_in,
                'history' => $g->consentLogs()->orderBy('id')->get()->map(fn ($l) => ['date' => $l->created_at?->format('Y-m-d H:i'), 'channel' => $l->channel,
                    'opted_in' => (bool) $l->opted_in, 'source' => $l->source])->all()],
            'stays' => Reservation::with('rooms.roomType', 'rooms.room', 'source')->whereIn('id', $resIds)->orderBy('arrival_date')->get()->map(fn ($r) => [
                'reservation' => $r->reservation_no, 'status' => $r->status, 'arrival' => $d($r->arrival_date), 'departure' => $d($r->departure_date),
                'adults' => $r->adults, 'children' => $r->children, 'booked_via' => $r->source?->name, 'booked_on' => $d($r->created_at),
                'special_request' => $r->guest_id === $g->id ? $r->special_request : null,
                'rooms' => $r->rooms->map(fn ($s) => trim(($s->roomType?->name ?? '').' '.($s->room ? '#'.$s->room->room_no : '')).' @ '.$s->rate)->all(),
            ])->all(),
            'bills' => Folio::where('guest_id', $g->id)->orderBy('id')->get()->map(function ($f) use ($d) {
                $t = $f->totals();

                return ['folio' => $f->folio_no, 'invoice' => $f->invoice_no, 'status' => $f->status, 'closed' => $d($f->closed_at),
                    'charges' => round($t['charges'], 2), 'payments' => round($t['payments'], 2), 'balance' => $t['balance']];
            })->all(),
            'loyalty' => LoyaltyEntry::where('guest_id', $g->id)->orderBy('id')->get()->map(fn ($l) => ['date' => $d($l->created_at), 'type' => $l->type, 'points' => $l->points, 'description' => $l->description])->all(),
            'feedback' => self::feedbackQuery($g, $resIds)->orderBy('id')->get()->map(fn ($f) => ['date' => substr((string) $f->created_at, 0, 10), 'source' => $f->source,
                'overall' => $f->rating_overall, 'recommend_score' => $f->nps, 'comments' => $f->comments, 'publish_consent' => (bool) $f->consent_publish,
                'answers' => DB::table('feedback_answers')->join('survey_questions', 'survey_questions.id', '=', 'feedback_answers.question_id')->where('feedback_answers.feedback_id', $f->id)
                    ->orderBy('survey_questions.sort')->get(['survey_questions.label', 'feedback_answers.value_int', 'feedback_answers.value_text'])
                    ->map(fn ($a) => ['question' => $a->label, 'answer' => $a->value_text ?? $a->value_int])->all()])->all(),
            'survey_invitations' => DB::table('survey_invitations')->where('hotel_id', $g->hotel_id)->where('guest_id', $g->id)->orderBy('id')->get()
                ->map(fn ($i) => ['sent' => substr((string) $i->sent_at, 0, 16) ?: null, 'channel' => $i->channel, 'to' => $i->to_email ?: $i->to_phone, 'status' => $i->status])->all(),
            'online_reviews_linked_to_you' => DB::table('online_reviews')->where('hotel_id', $g->hotel_id)->where('guest_id', $g->id)->orderBy('id')->get()
                ->map(fn ($r) => ['site' => $r->source, 'date' => $r->review_date, 'reviewer_name' => $r->reviewer_name, 'rating' => (float) $r->rating.' / '.$r->rating_scale, 'title' => $r->title])->all(),
            'restaurant_bookings' => self::diningQuery($g, $phone)->orderBy('start_at')->get()->map(fn ($r) => ['ref' => $r->booking_no, 'date_time' => substr((string) $r->start_at, 0, 16),
                'guests' => (int) $r->party_size, 'name' => $r->name, 'phone' => $r->phone, 'email' => $r->email, 'occasion' => $r->occasion, 'notes' => $r->notes, 'status' => $r->status,
                'booked_via' => $r->source])->all(),
            'transfers' => self::transportQuery($g, $phone)->orderBy('scheduled_at')->get()->map(fn ($t) => ['ref' => $t->trip_no, 'type' => $t->type, 'date_time' => substr((string) $t->scheduled_at, 0, 16),
                'from' => $t->pickup_point, 'to' => $t->dropoff_point, 'passenger' => $t->passenger_name, 'phone' => $t->phone, 'flight_or_train' => $t->flight_no, 'passengers' => (int) $t->pax,
                'notes' => $t->notes, 'fare' => (float) $t->total, 'billing' => $t->billing, 'status' => $t->status])->all(),
            'form_c_reports' => \App\Models\FormCRecord::where('guest_id', $g->id)->orderBy('id')->get()->map(fn ($f) => ['form' => $f->form_no, 'checked_in' => $f->checked_in_at?->format('Y-m-d H:i'),
                'nationality' => $f->nationality, 'passport' => $f->passport_no, 'visa' => trim(($f->visa_no ?? '').' '.(\App\Models\FormCRecord::VISA_TYPES[$f->visa_type] ?? '')) ?: null,
                'visa_valid_until' => $d($f->visa_expires_on), 'arrived_in_india' => $d($f->arrived_india_on), 'next_destination' => $f->next_destination,
                'reported_to_frro' => $f->submitted_at?->format('Y-m-d H:i'), 'frro_reference' => $f->frro_ref, 'departure_reported' => $f->departure_submitted_at?->format('Y-m-d H:i')])->all(),
            'currency_exchanges' => \App\Models\ForexExchange::with('currency')->where('guest_id', $g->id)->orderBy('id')->get()->map(fn ($x) => ['ref' => $x->exchange_no, 'date' => $d($x->business_date),
                'currency' => $x->currency?->code, 'amount' => (float) $x->fc_amount, 'rate' => (float) $x->rate, 'rupees' => (float) $x->inr_amount, 'gst' => (float) $x->gst,
                'paid' => (float) $x->net_amount, 'how' => $x->payout === 'cash' ? 'cash' : 'credited to bill', 'passport' => $x->passport_no, 'void' => (bool) $x->voided_at])->all(),
            // v3.5
            'desk_messages' => DB::table('guest_messages')->where('hotel_id', $g->hotel_id)->where(fn ($q) => $q->where('guest_id', $g->id)->orWhereIn('reservation_room_id', $stayIds))->orderBy('id')->get()
                ->map(fn ($m) => ['ref' => $m->message_no, 'type' => $m->kind, 'received' => substr((string) $m->created_at, 0, 16), 'from' => $m->from_name, 'message' => $m->body,
                    'courier' => $m->courier, 'tracking' => $m->tracking_no, 'status' => $m->status, 'delivered' => $m->delivered_at ? substr((string) $m->delivered_at, 0, 16) : null])->all(),
            'wake_up_calls' => DB::table('wakeup_calls')->where('hotel_id', $g->hotel_id)->whereIn('reservation_room_id', $stayIds)->orderBy('call_at')->get()
                ->map(fn ($w) => ['time' => substr((string) $w->call_at, 0, 16), 'daily' => (bool) $w->daily, 'status' => $w->status, 'attempts' => (int) $w->attempts, 'note' => $w->note])->all(),
            'lost_property' => DB::table('lost_reports')->where('hotel_id', $g->hotel_id)->where(fn ($q) => $q->where('guest_id', $g->id)->orWhereIn('reservation_room_id', $stayIds))->orderBy('id')->get()
                ->map(fn ($r) => ['report' => $r->report_no, 'item' => $r->item, 'lost_on' => $r->lost_date, 'where' => $r->where_lost, 'status' => $r->status, 'outcome' => $r->close_note])
                ->merge(DB::table('lost_found_items')->where('hotel_id', $g->hotel_id)->where('guest_id', $g->id)->orderBy('id')->get()
                    ->map(fn ($i) => ['item_no' => $i->item_no, 'item' => $i->item_name, 'found_on' => $i->found_date, 'status' => $i->status, 'returned_to' => $i->returned_to, 'returned_on' => $i->returned_date]))->values()->all(),
            // v4.2
            'visitors' => DB::table('visitors')->where('hotel_id', $g->hotel_id)->where(fn ($q) => $q->where('guest_id', $g->id)->orWhereIn('reservation_room_id', $stayIds))->orderBy('id')->get()
                ->map(fn ($v) => ['pass' => $v->pass_no, 'visitor' => $v->name, 'expected' => $v->expected_at ? substr((string) $v->expected_at, 0, 16) : null,
                    'in' => $v->checked_in_at ? substr((string) $v->checked_in_at, 0, 16) : null, 'out' => $v->checked_out_at ? substr((string) $v->checked_out_at, 0, 16) : null, 'status' => $v->status])->all(),
            'security_incidents' => DB::table('security_incidents')->where('hotel_id', $g->hotel_id)->where('guest_id', $g->id)->orderBy('id')->get()
                ->map(fn ($i) => ['incident' => $i->incident_no, 'when' => substr((string) $i->occurred_at, 0, 16), 'type' => $i->type, 'summary' => $i->title, 'status' => $i->status])->all(),
            'service_cases' => DB::table('feedback_cases')->where('hotel_id', $g->hotel_id)->where('guest_id', $g->id)->orderBy('id')->get()
                ->map(fn ($c) => ['case' => $c->case_no, 'opened' => substr((string) $c->created_at, 0, 10), 'subject' => $c->title, 'status' => $c->status,
                    'resolution' => $c->resolution, 'goodwill_points' => (int) $c->goodwill_points])->all(),
            'requests_during_stay' => DB::table('guest_requests')->where('hotel_id', $g->hotel_id)->whereIn('reservation_room_id', $stayIds)->orderBy('id')->get()
                ->map(fn ($r) => ['date' => substr((string) $r->created_at, 0, 10), 'item' => $r->item, 'category' => $r->category, 'status' => $r->status, 'notes' => $r->notes])->all(),
            'spa_appointments' => DB::table('spa_appointments')->where('hotel_id', $g->hotel_id)->where('guest_id', $g->id)->orderBy('date')->get()
                ->map(fn ($a) => ['date' => $a->date, 'time' => substr((string) $a->start_time, 0, 5), 'status' => $a->status, 'price' => (float) $a->price])->all(),
            'messages_sent_to_you' => self::messageQuery($resIds, $g, $phone)->orderBy('id')->get()->map(fn ($m) => ['date' => $m->created_at?->format('Y-m-d H:i'),
                'channel' => $m->channel, 'to' => $m->to, 'text' => $m->body, 'status' => $m->status])->all(),
            'emails_sent_to_you' => self::emailQuery($resIds, $g)->orderBy('id')->get()->map(fn ($m) => ['date' => $m->created_at?->format('Y-m-d H:i'),
                'to' => $m->to_email, 'subject' => $m->subject, 'status' => $m->status])->all(),
            'marketing_messages' => $g->marketingMessages()->with('campaign:id,name', 'journey:id,name')->orderBy('id')->get()->map(fn ($m) => [
                'date' => $m->created_at?->format('Y-m-d'), 'message' => $m->campaign?->name ?? $m->journey?->name, 'channel' => $m->channel, 'status' => $m->status,
                'clicked' => (bool) $m->clicked_at])->all(),
            'online_payments' => DB::table('online_payments')->where('hotel_id', $g->hotel_id)->where('guest_id', $g->id)->orderBy('id')->get()
                ->map(fn ($p) => ['reference' => $p->pay_no, 'amount' => (float) $p->amount, 'currency' => $p->currency, 'status' => $p->status, 'paid_at' => $p->paid_at])->all(),
            'identity_documents_held' => GuestDocument::where('guest_id', $g->id)->orderBy('id')->get()->map(fn ($x) => ['type' => GuestDocument::TYPES[$x->type] ?? $x->type,
                'uploaded' => $d($x->created_at)])->all(),
            'who_accessed_your_identity_data' => PiiAccessLog::with('user:id,name')->where('guest_id', $g->id)->orderBy('id')->get()->map(fn ($l) => [
                'date' => $l->created_at?->format('Y-m-d H:i'), 'staff' => $l->user?->name, 'action' => PiiAccessLog::ACTIONS[$l->action] ?? $l->action, 'reason' => $l->reason])->all(),
            'how_long_we_keep_it' => [
                'identity_document_scans_and_signatures' => $s['retention_id_days'].' days after check-out',
                'sms_whatsapp_email_contents' => $s['retention_message_days'].' days',
                'restaurant_booking_contact_details' => $s['retention_message_days'].' days after the booking (party size and date kept anonymously)',
                'tax_invoices' => $s['privacy_tax_years'].' years',
                'police_register_foreign_nationals' => $s['privacy_police_years'].' years after check-out',
                'guest_profile' => $s['retention_inactive_years'] ? 'anonymised after '.$s['retention_inactive_years'].' years without a stay' : 'until you ask us to erase it',
            ],
        ];
    }

    // ------------------------------------------------------------------ requests

    public static function dueOn(Carbon $received): Carbon
    {
        return $received->copy()->startOfDay()->addDays(self::settings()['privacy_response_days']);
    }

    public static function createRequest(array $data, ?int $userId = null): PrivacyRequest
    {
        $received = isset($data['received_at']) ? Carbon::parse($data['received_at']) : now();
        $req = PrivacyRequest::create($data + ['request_no' => Numbering::next('privacy_request'), 'received_at' => $received,
            'due_on' => self::dueOn($received)->toDateString(), 'created_by' => $userId]);
        $req->log('received', 'Received via '.(PrivacyRequest::CHANNELS[$req->channel] ?? $req->channel).'.', $userId);
        if (! $req->guest_id && $req->status === 'open') {
            self::autoLink($req);
        }
        ActivityLogger::log('privacy', 'request', "Privacy request {$req->request_no} (".PrivacyRequest::SHORT[$req->type].') logged', $req);

        return $req;
    }

    /** Link the request to a guest when exactly one live profile matches the requester's e-mail (or mobile). */
    public static function autoLink(PrivacyRequest $req): ?Guest
    {
        foreach (['email' => $req->requester_email, 'phone' => $req->requester_phone] as $col => $v) {
            if (! $v) {
                continue;
            }
            $m = Guest::whereNull('privacy_status')->where($col, $v)->limit(2)->get();
            if ($m->count() === 1) {
                $req->forceFill(['guest_id' => $m->first()->id])->save();
                $req->log('linked', 'Matched to guest profile G-'.$m->first()->id.' by '.($col === 'email' ? 'e-mail' : 'mobile').'.', null);

                return $m->first();
            }
        }

        return null;
    }

    /** Public form: stored as "unverified" until the e-mailed link is clicked. Returns [request, plain token]. */
    public static function publicSubmit(array $data): array
    {
        $token = Str::random(40);
        $req = PrivacyRequest::create($data + ['request_no' => Numbering::next('privacy_request'), 'status' => 'unverified', 'channel' => 'web',
            'received_at' => now(), 'due_on' => self::dueOn(now())->toDateString(), 'verify_token' => hash('sha256', $token), 'verify_expires_at' => now()->addHours(48)]);
        $req->log('received', 'Submitted on the privacy page – verification e-mail sent.', null);

        return [$req, $token];
    }

    public static function verifyEmail(string $token): ?PrivacyRequest
    {
        $req = PrivacyRequest::where('verify_token', hash('sha256', $token))->first();
        if (! $req) {
            return null;
        }
        if ($req->status === 'unverified') {
            if ($req->verify_expires_at && $req->verify_expires_at->isPast()) {
                return null;
            }
            // the statutory clock starts once we know the request is genuine
            $req->forceFill(['status' => 'open', 'email_verified_at' => now(), 'received_at' => now(), 'due_on' => self::dueOn(now())->toDateString()])->save();
            $req->log('email_verified', 'Requester confirmed the e-mail address.', null);
            if (! $req->guest_id) {
                self::autoLink($req);
            }
        }

        return $req;
    }

    public static function assertOpen(PrivacyRequest $req): void
    {
        if (! $req->isOpen()) {
            throw ValidationException::withMessages(['request' => "Request {$req->request_no} is ".($req->status === 'unverified' ? 'waiting for the requester to confirm the e-mail address.' : 'already closed.')]);
        }
    }

    public static function assertReady(PrivacyRequest $req, bool $identity = true): Guest
    {
        self::assertOpen($req);
        if (! $req->guest) {
            throw ValidationException::withMessages(['guest_id' => 'Link the request to a guest profile first.']);
        }
        if ($identity && $req->needsIdentity() && ! $req->verified_at) {
            throw ValidationException::withMessages(['verification_method' => 'Verify the requester\'s identity before disclosing, changing or erasing data.']);
        }

        return $req->guest;
    }

    public static function withdrawConsent(PrivacyRequest $req, ?int $userId): int
    {
        $g = self::assertReady($req, false);
        $n = CrmService::setConsent($g, collect(CrmService::CHANNELS)->map(fn () => false)->all(), 'privacy_request');
        $outcome = $n ? "Marketing consent withdrawn on {$n} channel(s). No further marketing will be sent." : 'The guest had no active marketing consent – nothing to withdraw; confirmed to the requester.';
        $req->forceFill(['status' => 'completed', 'completed_at' => now(), 'completed_by' => $userId, 'outcome' => $outcome])->save();
        $req->log('completed', $outcome, $userId);

        return $n;
    }

    public static function complete(PrivacyRequest $req, string $outcome, ?int $userId): void
    {
        self::assertOpen($req);
        if ($req->type === 'access' && ! $req->exports) {
            throw ValidationException::withMessages(['outcome' => 'Generate the data export for the requester before completing an access request.']);
        }
        if ($req->needsIdentity() && ! $req->verified_at) {
            throw ValidationException::withMessages(['outcome' => 'Verify the requester\'s identity first.']);
        }
        if ($req->type === 'erasure') {
            throw ValidationException::withMessages(['outcome' => 'Erasure requests are completed by executing the erasure (or rejected with a reason).']);
        }
        $req->forceFill(['status' => 'completed', 'completed_at' => now(), 'completed_by' => $userId, 'outcome' => $outcome])->save();
        $req->log('completed', $outcome, $userId);
    }

    public static function reject(PrivacyRequest $req, string $reason, ?int $userId): void
    {
        if (! in_array($req->status, ['open', 'unverified'])) {
            throw ValidationException::withMessages(['request' => 'Request is already closed.']);
        }
        $req->forceFill(['status' => 'rejected', 'completed_at' => now(), 'completed_by' => $userId, 'rejection_reason' => $reason])->save();
        $req->log('rejected', $reason, $userId);
    }

    public static function extend(PrivacyRequest $req, string $reason, ?int $userId): void
    {
        self::assertOpen($req);
        if ($req->extended) {
            throw ValidationException::withMessages(['extension_reason' => 'The deadline has already been extended once.']);
        }
        $req->forceFill(['extended' => true, 'extension_reason' => $reason, 'due_on' => $req->due_on->copy()->addDays(self::settings()['privacy_extension_days'])->toDateString()])->save();
        $req->log('extended', "Deadline extended to {$req->due_on->format('d M Y')}: {$reason}", $userId);
    }

    // ------------------------------------------------------------------ retention

    protected static function cutoffs(): array
    {
        $s = self::settings();

        return ['id' => $s['retention_id_days'] > 0 ? now()->subDays($s['retention_id_days']) : null,
            'msg' => $s['retention_message_days'] > 0 ? now()->subDays($s['retention_message_days']) : null,
            'inactive' => $s['retention_inactive_years'] > 0 ? now()->subYears($s['retention_inactive_years']) : null,
            'unverified' => now()->subDays(max(1, $s['retention_unverified_days']))];
    }

    protected static function retentionQueries(): array
    {
        $c = self::cutoffs();
        $none = fn ($q) => $q->whereRaw('1 = 0');
        $busy = fn ($q, Carbon $cut) => $q->whereIn('status', ['tentative', 'confirmed', 'checked_in'])->orWhere('departure_date', '>=', $cut->toDateString());

        return [
            'documents' => GuestDocument::query()->when($c['id'], fn ($q) => $q->where('created_at', '<', $c['id'])
                ->whereNotIn('guest_id', Reservation::whereNotNull('guest_id')->where(fn ($w) => $busy($w, $c['id']))->select('guest_id'))
                ->whereNotIn('guest_id', ReservationRoom::whereNotNull('guest_id')->where(fn ($w) => $busy($w, $c['id']))->select('guest_id')), $none),
            'signatures' => ReservationRoom::whereNotNull('grc_signature_path')->when($c['id'], fn ($q) => $q->where('status', 'checked_out')
                ->where('departure_date', '<', $c['id']->toDateString()), $none),
            'messages' => MessageLog::when($c['msg'], fn ($q) => $q->where('created_at', '<', $c['msg'])->whereNotIn('body', ['[purged]', '[erased]']), $none),
            'emails' => EmailLog::when($c['msg'], fn ($q) => $q->where('created_at', '<', $c['msg'])->whereNotIn('to_email', ['[purged]', '[erased]']), $none),
            'dining' => \App\Models\TableReservation::when($c['msg'], fn ($q) => $q->where('start_at', '<', $c['msg'])->whereNotIn('status', ['booked', 'confirmed', 'waiting', 'notified', 'seated'])
                ->where(fn ($w) => $w->whereNotNull('phone')->orWhereNotNull('email')->orWhereNotNull('notes')), $none),
            'inactive' => Guest::whereNull('privacy_status')->when($c['inactive'], fn ($q) => $q->where('created_at', '<', $c['inactive'])
                ->where(fn ($w) => $w->whereNull('last_stay_on')->orWhere('last_stay_on', '<', $c['inactive']->toDateString()))
                ->whereNotIn('id', Reservation::whereNotNull('guest_id')->where(fn ($w) => $w->where('created_at', '>=', $c['inactive'])->orWhere(fn ($x) => $busy($x, $c['inactive'])))->select('guest_id'))
                ->whereNotIn('id', ReservationRoom::whereNotNull('guest_id')->where(fn ($w) => $busy($w, $c['inactive']))->select('guest_id'))
                ->whereNotIn('id', Folio::where('status', 'open')->whereNotNull('guest_id')->select('guest_id')), $none),
            'holds_ended' => Guest::where('privacy_status', 'restricted')->whereNotNull('legal_hold_until')->where('legal_hold_until', '<=', bdate()->toDateString()),
            'unverified' => PrivacyRequest::where('status', 'unverified')->where('created_at', '<', $c['unverified']),
        ];
    }

    public const RETENTION_LABELS = ['documents' => 'ID document scans', 'signatures' => 'Registration-card signatures', 'messages' => 'SMS / WhatsApp contents',
        'emails' => 'E-mail log addresses', 'dining' => 'Restaurant booking contact details', 'inactive' => 'Inactive guest profiles anonymised', 'holds_ended' => 'Legal holds ended – erasure completed',
        'unverified' => 'Unverified web requests closed'];

    public static function retentionPreview(): array
    {
        $out = [];
        foreach (self::retentionQueries() as $k => $q) {
            $n = (clone $q)->count();
            $sample = match ($k) {
                'inactive', 'holds_ended' => (clone $q)->orderBy('id')->limit(5)->get()->map(fn ($g) => $g->full_name.' (G-'.$g->id.')')->all(),
                'documents' => (clone $q)->with('guest:id,first_name,last_name,title')->orderBy('id')->limit(5)->get()->map(fn ($d) => ($d->guest?->full_name ?? '?').' – '.(GuestDocument::TYPES[$d->type] ?? $d->type))->all(),
                'unverified' => (clone $q)->limit(5)->pluck('request_no')->all(),
                default => [],
            };
            $out[$k] = ['label' => self::RETENTION_LABELS[$k], 'count' => $n, 'sample' => $sample];
        }

        return $out;
    }

    public static function runRetention(string $trigger = 'schedule', ?int $userId = null): PrivacyRetentionRun
    {
        $t0 = microtime(true);
        $q = self::retentionQueries();
        $counts = array_fill_keys(array_keys($q), 0);
        foreach ($q['documents']->get() as $d) {
            Storage::disk('local')->delete($d->path);
            $d->delete();
            $counts['documents']++;
        }
        foreach ($q['signatures']->get() as $s) {
            Storage::disk('local')->delete($s->grc_signature_path);
            $s->forceFill(['grc_signature_path' => null])->save();
            $counts['signatures']++;
        }
        $counts['messages'] = $q['messages']->update(['body' => '[purged]', 'to' => '[purged]']);
        $counts['emails'] = $q['emails']->update(['to_email' => '[purged]']);
        $counts['dining'] = $q['dining']->update(['phone' => null, 'email' => null, 'notes' => null, 'token_hash' => null]);
        foreach ($q['inactive']->limit(500)->get() as $g) {
            if (! self::blockers($g)) {
                self::erase($g, null, $userId, 'retention_policy');
                $counts['inactive']++;
            }
        }
        foreach ($q['holds_ended']->limit(500)->get() as $g) {
            self::completeHold($g);
            $counts['holds_ended']++;
        }
        foreach ($q['unverified']->get() as $r) {
            self::reject($r, 'E-mail address never confirmed – request closed automatically.', null);
            $counts['unverified']++;
        }
        $run = PrivacyRetentionRun::create(['trigger' => $trigger, 'counts' => $counts, 'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
            'user_id' => $userId, 'created_at' => now()]);
        if (array_sum($counts)) {
            ActivityLogger::log('privacy', 'retention', 'Retention run: '.collect($counts)->filter()->map(fn ($n, $k) => $n.' '.strtolower(self::RETENTION_LABELS[$k]))->implode(', '), $run);
        }

        return $run;
    }

    // ------------------------------------------------------------------ dashboard

    public static function alerts(): array
    {
        $today = bdate()->toDateString();
        $pending = DataBreach::where('notifiable', true)->whereNull('authority_notified_at')->where('status', '!=', 'closed')->get();

        return ['overdue' => PrivacyRequest::where('status', 'open')->where('due_on', '<', $today)->count(),
            'due_soon' => PrivacyRequest::where('status', 'open')->whereBetween('due_on', [$today, bdate()->copy()->addDays(7)->toDateString()])->count(),
            'breach' => $pending->count(), 'breach_overdue' => $pending->filter(fn ($b) => $b->deadline()->isPast())->count(),
            'breach_next' => $pending->sortBy(fn ($b) => $b->deadline())->first()];
    }

    public static function attentionCount(): int
    {
        $a = self::alerts();

        return $a['overdue'] + $a['due_soon'] + $a['breach'];
    }

    public static function nextBreachRef(): string
    {
        $y = now()->year;
        $n = DataBreach::where('ref', 'like', "BR-{$y}-%")->count() + 1;

        return sprintf('BR-%d-%03d', $y, $n);
    }

    /** Runs retention for every active hotel (scheduler). */
    public static function runAllHotels(?string $code = null): array
    {
        $out = [];
        foreach (\App\Models\Hotel::where('is_active', true)->when($code, fn ($q) => $q->where('code', $code))->get() as $hotel) {
            Tenant::set($hotel);
            if (! self::settings()['retention_enabled']) {
                $out[$hotel->code] = null;

                continue;
            }
            $out[$hotel->code] = self::runRetention('schedule');
        }

        return $out;
    }
}
