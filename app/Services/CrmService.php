<?php

namespace App\Services;

use App\Mail\MarketingMail;
use App\Models\Guest;
use App\Models\MarketingCampaign;
use App\Models\MarketingConsentLog;
use App\Models\MarketingJourney;
use App\Models\MarketingMessage;
use App\Models\PromoCode;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * v2.6 guest marketing & CRM.
 *
 * Consent is per channel (e-mail, SMS, WhatsApp) and every change is written to marketing_consent_logs. Nothing
 * marketing-related is ever sent to a guest who has not opted in on that channel, who is blacklisted or who has no
 * valid address; campaigns additionally respect a weekly frequency cap. Every recipient – including the ones that
 * were skipped, with the reason – gets a marketing_messages row carrying a random token used for the tracked
 * booking link and the one-click unsubscribe link. Bookings made within the attribution window after a message (or
 * with the message's promo code, or through its link) are credited to the campaign / automation.
 */
class CrmService
{
    public const CHANNELS = ['email' => 'E-mail', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp'];

    public const DEFAULTS = [
        'crm_frequency_cap' => '2',        // campaign messages per guest per rolling 7 days (0 = no cap)
        'crm_attribution_days' => '30',    // bookings within n days of a message are credited to it
        'crm_sms_optout' => 'Opt out: {unsubscribe_link}',
        'crm_consent_text' => 'Send me special offers and news by e-mail and SMS. I can unsubscribe at any time.',
    ];

    public const TOKENS = [
        'first_name' => 'First name', 'last_name' => 'Last name', 'guest' => 'Full name', 'hotel' => 'Hotel name',
        'points' => 'Loyalty points', 'stays' => 'Number of stays', 'last_stay' => 'Last stay date',
        'promo_code' => 'Promo code', 'promo_offer' => 'Offer (e.g. 15% off)', 'promo_expiry' => 'Promo booking deadline',
        'book_link' => 'Tracked booking link', 'arrival' => 'Arrival date*', 'res_no' => 'Reservation no.*', 'room_type' => 'Room type*',
        'checkin_link' => 'Online check-in link*', 'feedback_link' => 'Feedback form link', 'unsubscribe_link' => 'Unsubscribe link',
    ];

    /** field => [label, type] */
    public const FIELDS = [
        'stays_count' => ['Completed stays', 'number'], 'nights_count' => ['Room nights', 'number'], 'total_revenue' => ['Lifetime revenue', 'number'],
        'days_since_stay' => ['Days since last stay', 'number'], 'loyalty_points' => ['Loyalty points', 'number'], 'guest_since_days' => ['Profile age (days)', 'number'],
        'is_vip' => ['VIP', 'bool'], 'corporate' => ['Linked to a company', 'bool'], 'upcoming' => ['Has an upcoming booking', 'bool'],
        'country' => ['Country', 'text'], 'nationality' => ['Nationality', 'text'], 'city' => ['City', 'text'], 'gender' => ['Gender', 'text'],
        'tag' => ['Tag', 'tag'], 'birthday_month' => ['Birthday month', 'month'], 'anniversary_month' => ['Anniversary month', 'month'],
    ];

    public const OPS = [
        'number' => ['gte' => 'at least', 'lte' => 'at most', 'eq' => 'exactly'],
        'bool' => ['yes' => 'is yes', 'no' => 'is no'],
        'text' => ['eq' => 'is', 'neq' => 'is not', 'in' => 'is one of', 'contains' => 'contains'],
        'tag' => ['has' => 'has', 'not' => 'does not have'],
        'month' => ['eq' => 'is', 'this' => 'is this month', 'next' => 'is next month'],
    ];

    public static function ensureDefaults(): void
    {
        foreach (self::DEFAULTS as $k => $v) {
            Setting::firstOrCreate(['key' => $k], ['value' => $v]);
        }
        Setting::$version++;
    }

    // ------------------------------------------------------------------ segments

    /** Keep only well-formed rules. */
    public static function cleanRules(?array $rules): array
    {
        return collect($rules ?? [])->map(function ($r) {
            $f = $r['field'] ?? null;
            $type = self::FIELDS[$f][1] ?? null;
            $op = $r['op'] ?? null;
            if (! $type || ! isset(self::OPS[$type][$op])) {
                return null;
            }
            $v = trim((string) ($r['value'] ?? ''));
            if (in_array($type, ['number', 'text', 'tag']) && $v === '' || ($type === 'month' && $op === 'eq' && ((int) $v < 1 || (int) $v > 12))) {
                return null;
            }
            if ($type === 'number' && ! is_numeric($v)) {
                return null;
            }
            if ($type === 'tag') {
                $v = self::normaliseTags($v);
            }

            return ['field' => $f, 'op' => $op, 'value' => in_array($type, ['bool']) || ($type === 'month' && $op !== 'eq') ? null : $v];
        })->filter()->values()->all();
    }

    public static function segmentQuery(array $rules, string $match = 'all'): Builder
    {
        $q = Guest::query()->whereNull('privacy_status');
        $rules = self::cleanRules($rules);
        if ($rules) {
            $q->where(function ($w) use ($rules, $match) {
                foreach ($rules as $r) {
                    $w->{$match === 'any' ? 'orWhere' : 'where'}(fn ($c) => self::applyRule($c, $r));
                }
            });
        }

        return $q;
    }

    protected static function applyRule(Builder $c, array $r): void
    {
        ['field' => $f, 'op' => $op, 'value' => $v] = $r;
        $bd = bdate()->copy();
        $cmp = ['gte' => '>=', 'lte' => '<=', 'eq' => '='];
        $flip = ['gte' => '<=', 'lte' => '>=', 'eq' => '=']; // "at least n days ago" = on or before the date
        $upcoming = fn ($q) => $q->whereIn('status', ['confirmed', 'tentative'])->where('arrival_date', '>=', $bd->toDateString());
        match ($f) {
            'stays_count', 'nights_count', 'total_revenue', 'loyalty_points' => $c->where($f, $cmp[$op], (float) $v),
            'days_since_stay' => $c->whereNotNull('last_stay_on')->whereDate('last_stay_on', $flip[$op], $bd->subDays((int) $v)->toDateString()),
            'guest_since_days' => $c->whereDate('created_at', $flip[$op], now()->subDays((int) $v)->toDateString()),
            'is_vip' => $c->where('is_vip', $op === 'yes'),
            'corporate' => $op === 'yes' ? $c->whereNotNull('company_id') : $c->whereNull('company_id'),
            'upcoming' => $op === 'yes' ? $c->whereHas('reservations', $upcoming) : $c->whereDoesntHave('reservations', $upcoming),
            'country', 'nationality', 'city', 'gender' => match ($op) {
                'eq' => $c->where($f, $v),
                'neq' => $c->where(fn ($w) => $w->whereNull($f)->orWhere($f, '!=', $v)),
                'in' => $c->whereIn($f, array_map('trim', explode(',', $v))),
                default => $c->where($f, 'like', '%'.addcslashes($v, '%_').'%'),
            },
            'tag' => $op === 'has'
                ? $c->whereRaw('FIND_IN_SET(?, guests.tags) > 0', [$v])
                : $c->where(fn ($w) => $w->whereNull('guests.tags')->orWhereRaw('FIND_IN_SET(?, guests.tags) = 0', [$v])),
            'birthday_month', 'anniversary_month' => $c->whereMonth($f === 'birthday_month' ? 'dob' : 'anniversary',
                $op === 'this' ? $bd->month : ($op === 'next' ? $bd->copy()->startOfMonth()->addMonth()->month : (int) $v)),
        };
    }

    public static function describeRules(?array $rules, string $match = 'all'): string
    {
        $rules = self::cleanRules($rules);
        if (! $rules) {
            return 'All guests';
        }

        return collect($rules)->map(function ($r) {
            [$label, $type] = self::FIELDS[$r['field']];
            $v = $r['value'];
            if ($type === 'month' && $r['op'] === 'eq') {
                $v = Carbon::create(2000, (int) $v, 1)->format('F');
            }

            return trim($label.' '.self::OPS[$type][$r['op']].' '.($type === 'number' && $r['field'] === 'total_revenue' ? money($v) : $v));
        })->join($match === 'any' ? ' OR ' : ' AND ');
    }

    // ------------------------------------------------------------------ consent & tags

    public static function normaliseTags(?string $tags): string
    {
        return collect(preg_split('/[,;]+/', mb_strtolower((string) $tags)))
            ->map(fn ($t) => trim(preg_replace('/[^a-z0-9]+/', '-', trim($t)), '-'))->filter()->unique()->take(15)->implode(',');
    }

    /**
     * Change marketing consent; logs every channel that actually changes.
     *
     * @param  array<string, bool>  $channels
     */
    public static function setConsent(Guest $g, array $channels, string $source, ?string $ip = null): int
    {
        $changed = 0;
        foreach (self::CHANNELS as $ch => $label) {
            if (! array_key_exists($ch, $channels) || (bool) $g->{$ch.'_opt_in'} === (bool) $channels[$ch]) {
                continue;
            }
            $g->{$ch.'_opt_in'} = (bool) $channels[$ch];
            MarketingConsentLog::create(['guest_id' => $g->id, 'channel' => $ch, 'opted_in' => (bool) $channels[$ch], 'source' => $source,
                'ip' => $ip, 'user_id' => auth()->id()]);
            $changed++;
        }
        if ($changed) {
            if (collect(self::CHANNELS)->keys()->contains(fn ($ch) => $g->{$ch.'_opt_in'})) {
                $g->consent_at = now();
            }
            $g->consent_source = $source;
            $g->save();
        }

        return $changed;
    }

    public static function address(Guest $g, string $channel): ?string
    {
        return $channel === 'email'
            ? (filter_var((string) $g->email, FILTER_VALIDATE_EMAIL) ? $g->email : null)
            : MessageService::normalise($g->phone);
    }

    /** Recent campaign messages per guest (for the frequency cap). */
    protected static function recentCounts(?array $guestIds = null): Collection
    {
        return MarketingMessage::whereNotNull('campaign_id')->where('status', 'sent')->where('created_at', '>=', now()->subDays(7))
            ->when($guestIds !== null, fn ($q) => $q->whereIn('guest_id', $guestIds))
            ->groupBy('guest_id')->selectRaw('guest_id, count(*) n')->pluck('n', 'guest_id');
    }

    /** Why a guest cannot receive a marketing message on a channel, or null when they can. */
    public static function eligibility(Guest $g, string $channel, bool $cap = true, ?Collection $recent = null): ?string
    {
        if ($g->is_blacklisted) {
            return 'Blacklisted';
        }
        if (! $g->optedIn($channel)) {
            return 'No consent';
        }
        if (! self::address($g, $channel)) {
            return $channel === 'email' ? 'No valid e-mail' : 'No valid mobile';
        }
        $limit = (int) setting('crm_frequency_cap', '2');
        if ($cap && $limit > 0) {
            $n = $recent ? (int) $recent->get($g->id, 0) : (int) (self::recentCounts([$g->id])->first() ?? 0);
            if ($n >= $limit) {
                return 'Frequency cap';
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ personalisation & delivery

    public static function bookUrl(?PromoCode $promo = null, ?string $token = null): string
    {
        $hotel = hotel();
        if (setting('booking_engine_enabled', '0') !== '1') {
            return $hotel->website ?: route('feedback.form', $hotel->code);
        }
        $q = array_filter(['promo' => $promo?->code, 'm' => $token]);

        return route('booking.show', $hotel->code).($q ? '?'.http_build_query($q) : '');
    }

    public static function vars(Guest $g, array $ctx = []): array
    {
        $hotel = hotel();
        $promo = $ctx['promo'] ?? null;
        $r = $ctx['reservation'] ?? null;
        $token = $ctx['token'] ?? null;

        return [
            'first_name' => $g->first_name, 'last_name' => $g->last_name, 'guest' => trim($g->first_name.' '.$g->last_name), 'hotel' => $hotel->name,
            'points' => number_format((int) $g->loyalty_points), 'stays' => (int) $g->stays_count, 'last_stay' => $g->last_stay_on?->format('d M Y'),
            'promo_code' => $promo?->code, 'promo_offer' => $promo?->offerLabel(), 'promo_expiry' => $promo?->book_to?->format('d M Y'),
            'book_link' => $token ? route('crm.click', $token) : self::bookUrl($promo),
            'unsubscribe_link' => $token ? route('crm.unsubscribe', $token) : route('crm.unsubscribe', 'preview'),
            'arrival' => $r?->arrival_date?->format('d M Y'), 'res_no' => $r?->reservation_no, 'room_type' => $r?->rooms?->first()?->roomType?->name,
            'checkin_link' => $r && $r->onlineCheckinOpen() ? $r->checkinUrl() : '', 'feedback_link' => route('feedback.form', $hotel->code),
        ];
    }

    /** Render subject + body for a guest (used by previews, test sends and real sends). */
    public static function compose(Guest $g, string $channel, ?string $subject, string $body, ?string $cta, array $ctx = []): array
    {
        $vars = self::vars($g, $ctx);
        $text = MessageService::render($body, $vars);
        if ($channel !== 'email') {
            if ($cta && ! str_contains($body, '{book_link}')) {
                $text .= "\n{$cta}: {$vars['book_link']}";
            }
            if (! str_contains($body, '{unsubscribe_link}')) {
                $text .= "\n".MessageService::render((string) setting('crm_sms_optout', self::DEFAULTS['crm_sms_optout']), $vars);
            }
        }

        return ['subject' => MessageService::render($subject ?: hotel()->name, $vars), 'body' => trim($text), 'vars' => $vars, 'cta' => $cta];
    }

    /**
     * Send (or skip, with the reason) one marketing message and record it.
     *
     * @param  array  $attrs  campaign_id / journey_id / occurrence
     */
    public static function deliver(Guest $g, string $channel, ?string $subject, string $body, ?string $cta, array $ctx, array $attrs, bool $cap = true, ?Collection $recent = null): MarketingMessage
    {
        $msg = new MarketingMessage;
        $msg->forceFill($attrs + ['guest_id' => $g->id, 'channel' => $channel, 'token' => Str::random(32), 'to' => self::address($g, $channel)]);
        if ($reason = self::eligibility($g, $channel, $cap, $recent)) {
            $msg->forceFill(['status' => 'skipped', 'skip_reason' => $reason])->save();

            return $msg;
        }
        $m = self::compose($g, $channel, $subject, $body, $cta, $ctx + ['token' => $msg->token]);
        if ($channel === 'email') {
            $log = MailService::send($msg->to, new MarketingMail($m['subject'], $m['body'], $cta, $m['vars']['book_link'], $m['vars']['unsubscribe_link'], hotel()), 'marketing', $g);
            $msg->email_log_id = $log->id;
        } else {
            $log = MessageService::send($msg->to, $m['body'], $channel, 'marketing', $g);
            $msg->message_log_id = $log->id;
        }
        $msg->status = $log->status === 'sent' ? 'sent' : 'failed';
        $msg->skip_reason = $log->status === 'sent' ? null : mb_substr((string) $log->error, 0, 60);
        $msg->save();

        return $msg;
    }

    /** Send a test to an arbitrary address using a sample guest; never counted in the stats. */
    public static function sendTest(string $channel, ?string $subject, string $body, ?string $cta, ?PromoCode $promo, string $to, ?Guest $sample = null): bool
    {
        $g = $sample ?? new Guest(['first_name' => 'Test', 'last_name' => 'Guest', 'loyalty_points' => 1200]);
        $m = self::compose($g, $channel, $subject, $body, $cta, ['promo' => $promo]);
        $log = $channel === 'email'
            ? MailService::send($to, new MarketingMail('[TEST] '.$m['subject'], $m['body'], $cta, $m['vars']['book_link'], $m['vars']['unsubscribe_link'], hotel()), 'marketing_test')
            : MessageService::send($to, '[TEST] '.$m['body'], $channel, 'marketing_test');

        return $log->status === 'sent';
    }

    // ------------------------------------------------------------------ campaigns

    public static function audience(MarketingCampaign $c): Builder
    {
        return $c->segment ? $c->segment->guests() : Guest::query()->whereNull('privacy_status');
    }

    /** Eligible / skipped breakdown for the campaign's audience right now. */
    public static function audienceBreakdown(MarketingCampaign $c): array
    {
        $guests = self::audience($c)->get();
        $recent = self::recentCounts($guests->pluck('id')->all());
        $reasons = $guests->map(fn ($g) => self::eligibility($g, $c->channel, true, $recent));

        return ['total' => $guests->count(), 'eligible' => $reasons->filter(fn ($r) => $r === null)->count(),
            'skipped' => $reasons->filter()->countBy()->sortDesc()->all(), 'sample' => $guests->first(fn ($g, $i) => $reasons[$i] === null) ?? $guests->first()];
    }

    public static function schedule(MarketingCampaign $c, Carbon $at): void
    {
        self::assertSendable($c);
        if ($at->lt(now()->subMinute())) {
            throw ValidationException::withMessages(['scheduled_at' => 'The send time must be in the future.']);
        }
        $c->update(['status' => 'scheduled', 'scheduled_at' => $at]);
        ActivityLogger::log('crm', 'schedule', "Scheduled campaign \"{$c->name}\" for {$at->format('d M Y H:i')}", $c);
    }

    protected static function assertSendable(MarketingCampaign $c): void
    {
        if (! in_array($c->status, ['draft', 'scheduled'])) {
            throw ValidationException::withMessages(['campaign' => 'This campaign is '.$c->status.'.']);
        }
        if ($c->channel === 'email' && trim((string) $c->subject) === '') {
            throw ValidationException::withMessages(['subject' => 'An e-mail campaign needs a subject.']);
        }
        if ($c->segment && ! $c->segment->is_active) {
            throw ValidationException::withMessages(['segment_id' => 'The segment "'.$c->segment->name.'" is inactive.']);
        }
        if ($c->promo && ! $c->promo->is_active) {
            throw ValidationException::withMessages(['promo_code_id' => 'The promo code '.$c->promo->code.' is inactive.']);
        }
    }

    /** Deliver a campaign to its whole audience. Safe to re-run: guests already processed are skipped. */
    public static function sendCampaign(MarketingCampaign $c, ?int $userId = null): MarketingCampaign
    {
        if ($c->status !== 'sending') {
            self::assertSendable($c);
            // atomic claim so two schedulers / double clicks cannot both start it
            $claimed = MarketingCampaign::whereKey($c->id)->whereIn('status', ['draft', 'scheduled'])
                ->update(['status' => 'sending', 'started_at' => now(), 'sent_by' => $userId ?? auth()->id()]);
            if (! $claimed) {
                throw ValidationException::withMessages(['campaign' => 'This campaign is already being sent.']);
            }
        }
        $c->refresh()->load('segment', 'promo');
        $done = MarketingMessage::where('campaign_id', $c->id)->pluck('guest_id')->flip();
        self::audience($c)->chunkById(200, function ($guests) use ($c, $done) {
            $recent = self::recentCounts($guests->pluck('id')->all());
            foreach ($guests as $g) {
                if (isset($done[$g->id])) {
                    continue;
                }
                try {
                    $m = self::deliver($g, $c->channel, $c->subject, $c->body, $c->cta_label, ['promo' => $c->promo], ['campaign_id' => $c->id], true, $recent);
                    if ($m->status === 'sent') {
                        $recent->put($g->id, $recent->get($g->id, 0) + 1);
                    }
                } catch (UniqueConstraintViolationException) {
                    // another worker handled this guest
                }
            }
        }, 'guests.id', 'id');
        self::refreshCounts($c);
        $c->forceFill(['status' => 'sent', 'sent_at' => now()])->save();
        ActivityLogger::log('crm', 'send', "Sent campaign \"{$c->name}\" – {$c->sent_count} delivered, {$c->skipped_count} skipped, {$c->failed_count} failed", $c);

        return $c;
    }

    public static function refreshCounts(MarketingCampaign $c): void
    {
        $by = MarketingMessage::where('campaign_id', $c->id)->groupBy('status')->selectRaw('status, count(*) n')->pluck('n', 'status');
        $c->forceFill(['recipients_count' => $by->sum(), 'sent_count' => (int) ($by['sent'] ?? 0), 'failed_count' => (int) ($by['failed'] ?? 0),
            'skipped_count' => (int) ($by['skipped'] ?? 0)])->save();
    }

    /** Scheduled campaigns that are due, plus sends interrupted more than 15 minutes ago. */
    public static function runDue(): int
    {
        $n = 0;
        $due = MarketingCampaign::where(fn ($q) => $q->where('status', 'scheduled')->where('scheduled_at', '<=', now()))
            ->orWhere(fn ($q) => $q->where('status', 'sending')->where('started_at', '<=', now()->subMinutes(15)))->get();
        foreach ($due as $c) {
            try {
                self::sendCampaign($c, $c->sent_by ?? $c->created_by);
                $n++;
            } catch (ValidationException $e) {
                $c->forceFill(['status' => 'draft'])->save(); // e.g. segment switched off since scheduling – back to draft for review
                ActivityLogger::log('crm', 'error', "Scheduled campaign \"{$c->name}\" returned to draft: ".collect($e->errors())->flatten()->first(), $c);
            }
        }

        return $n;
    }

    // ------------------------------------------------------------------ automated journeys

    /** @return Collection<int, array{0: Guest, 1: string, 2: ?Reservation}> */
    public static function journeyCandidates(MarketingJourney $j, Carbon $date): Collection
    {
        $off = (int) $j->offset_days;
        $items = match ($j->trigger) {
            'birthday', 'anniversary' => (function () use ($j, $date, $off) {
                $col = $j->trigger === 'birthday' ? 'dob' : 'anniversary';
                $t = $date->copy()->addDays($off);
                $leapCatchUp = $t->month === 2 && $t->day === 28 && ! $t->isLeapYear(); // 29 Feb birthdays are greeted on the 28th

                return Guest::whereNull('privacy_status')->whereMonth($col, $t->month)->when($leapCatchUp, fn ($q) => $q->whereIn(DB::raw("DAY($col)"), [28, 29]), fn ($q) => $q->whereDay($col, $t->day))
                    ->get()->map(fn ($g) => [$g, (string) $t->year, null]);
            })(),
            'pre_arrival' => Reservation::with('guest', 'rooms.roomType')->where('status', 'confirmed')->whereDate('arrival_date', $date->copy()->addDays($off))->get()
                ->filter(fn ($r) => $r->guest)->map(fn ($r) => [$r->guest, 'R'.$r->id, $r]),
            'post_stay' => Reservation::with('guest', 'rooms.roomType')->where('status', 'checked_out')->whereDate('departure_date', $date->copy()->subDays($off))->get()
                ->filter(fn ($r) => $r->guest)->map(fn ($r) => [$r->guest, 'R'.$r->id, $r]),
            'win_back' => Guest::whereDate('last_stay_on', $date->copy()->subDays($off))
                ->whereDoesntHave('reservations', fn ($q) => $q->whereIn('status', ['confirmed', 'tentative', 'checked_in']))->get()
                ->map(fn ($g) => [$g, 'W'.$g->last_stay_on->toDateString(), null]),
            default => collect(),
        };
        if ($j->segment) {
            $ids = $j->segment->guests()->pluck('guests.id')->flip();
            $items = $items->filter(fn ($i) => isset($ids[$i[0]->id]));
        }

        return $items->values();
    }

    public static function runJourney(MarketingJourney $j, ?Carbon $date = null): int
    {
        $date ??= bdate()->copy();
        $j->loadMissing('segment', 'promo');
        $seen = MarketingMessage::where('journey_id', $j->id)->get(['guest_id', 'occurrence'])->mapWithKeys(fn ($m) => [$m->guest_id.'|'.$m->occurrence => true]);
        $sent = 0;
        foreach (self::journeyCandidates($j, $date) as [$g, $occ, $r]) {
            if (isset($seen[$g->id.'|'.$occ])) {
                continue;
            }
            try {
                $m = self::deliver($g, $j->channel, $j->subject, $j->body, $j->cta_label, ['promo' => $j->promo, 'reservation' => $r],
                    ['journey_id' => $j->id, 'occurrence' => $occ], false);
                $sent += $m->status === 'sent' ? 1 : 0;
            } catch (UniqueConstraintViolationException) {
            }
        }
        $j->forceFill(['last_run_at' => now()])->save();

        return $sent;
    }

    public static function runJourneys(?Carbon $date = null): int
    {
        $n = 0;
        foreach (MarketingJourney::where('is_active', true)->get() as $j) {
            $n += self::runJourney($j, $date);
        }

        return $n;
    }

    // ------------------------------------------------------------------ promo codes

    /** Validate a promo code for a booking; returns the code or throws with a guest-friendly message. */
    public static function validatePromo(?string $code, int $roomTypeId, string $arrival, string $departure, ?Guest $guest = null): ?PromoCode
    {
        $code = strtoupper(trim((string) $code));
        if ($code === '') {
            return null;
        }
        $p = PromoCode::where('code', $code)->first();
        $fail = fn (string $m) => throw ValidationException::withMessages(['promo' => $m]);
        if (! $p || ! $p->is_active) {
            $fail('That promo code is not valid.');
        }
        $today = bdate()->toDateString();
        if (($p->book_from && $today < $p->book_from->toDateString()) || ($p->book_to && $today > $p->book_to->toDateString())) {
            $fail('That promo code has expired or is not active yet.');
        }
        if (($p->stay_from && $arrival < $p->stay_from->toDateString()) || ($p->stay_to && $arrival > $p->stay_to->toDateString())) {
            $fail('Promo '.$p->code.' is valid for arrivals '.($p->stay_from?->format('d M Y') ?? 'now').' – '.($p->stay_to?->format('d M Y') ?? 'onwards').'.');
        }
        if (Carbon::parse($arrival)->diffInDays(Carbon::parse($departure)) < $p->min_nights) {
            $fail("Promo {$p->code} needs a stay of at least {$p->min_nights} nights.");
        }
        if ($p->room_type_ids && ! in_array($roomTypeId, array_map('intval', $p->room_type_ids))) {
            $fail("Promo {$p->code} does not apply to this room type.");
        }
        if ($p->max_uses && $p->usesCount() >= $p->max_uses) {
            $fail("Promo {$p->code} has been fully redeemed.");
        }
        if ($guest?->exists && $p->per_guest_limit && $p->reservations()->where('guest_id', $guest->id)->where('status', '!=', 'cancelled')->count() >= $p->per_guest_limit) {
            $fail("You have already used promo {$p->code}.");
        }

        return $p;
    }

    /** Apply a promo code to a RateService quote (each night discounted, never below zero). */
    public static function applyPromo(array $quote, PromoCode $p): array
    {
        $before = (float) $quote['total'];
        $quote['nights'] = array_map(fn ($n) => ['rate' => round((float) $n['rate'] - $p->discountOn((float) $n['rate']), 2), 'full_rate' => $n['rate']] + $n, $quote['nights']);
        $quote['total'] = round(array_sum(array_column($quote['nights'], 'rate')), 2);
        $quote['original_average'] = $quote['average'];
        $quote['average'] = round($quote['total'] / max(1, count($quote['nights'])), 2);
        $quote['promo_discount'] = round($before - $quote['total'], 2); // per room

        return $quote;
    }

    // ------------------------------------------------------------------ attribution

    /**
     * Credit a new booking to the marketing message that led to it: the message whose link was used ($via), else
     * one that carried the booking's promo code, else the latest message the guest received in the window.
     */
    public static function attribute(Reservation $r, ?MarketingMessage $via = null): void
    {
        if (! $r->guest_id || ($r->marketing_message_id && ! $via)) {
            return;
        }
        $window = now()->subDays(max(0, (int) setting('crm_attribution_days', '30')));
        $base = fn () => MarketingMessage::where('guest_id', $r->guest_id)->where('status', 'sent')->latest('id');
        $m = $via && $via->guest_id === $r->guest_id ? $via : null;
        if (! $m && $r->promo_code_id) {
            $m = $base()->where(fn ($q) => $q->whereIn('campaign_id', MarketingCampaign::where('promo_code_id', $r->promo_code_id)->select('id'))
                ->orWhereIn('journey_id', MarketingJourney::where('promo_code_id', $r->promo_code_id)->select('id')))->where('created_at', '>=', now()->subDays(365))->first();
        }
        $m ??= $via ? null : $base()->where('created_at', '>=', $window)->first();
        if (! $m && $via) {
            $m = $via; // clicked a link that was forwarded – still credit the campaign, not a guest-level message
        }
        if (! $m) {
            if ($r->promo_code_id && ! $r->attribution) {
                $campaign = MarketingCampaign::where('promo_code_id', $r->promo_code_id)->where('status', 'sent')->latest('sent_at')->value('id');
                self::stamp($r, ['campaign_id' => $campaign, 'attribution' => 'promo']);
            }

            return;
        }
        self::stamp($r, ['marketing_message_id' => $m->id, 'campaign_id' => $m->campaign_id, 'journey_id' => $m->journey_id,
            'attribution' => $via ? 'click' : ($r->promo_code_id ? 'promo' : ($m->clicked_at ? 'click' : 'influenced'))]);
        MarketingMessage::whereKey($m->id)->whereNull('reservation_id')->update(['reservation_id' => $r->id]);
    }

    protected static function stamp(Reservation $r, array $cols): void
    {
        Reservation::whereKey($r->id)->update($cols); // no model events
        $r->forceFill($cols)->syncOriginalAttributes(array_keys($cols));
    }

    /** Bookings, room nights and room revenue of a set of reservations (cancelled ones excluded). */
    public static function bookingValue(Builder $reservations): array
    {
        $ids = (clone $reservations)->where('status', '!=', 'cancelled')->pluck('id');
        $v = ReservationRoom::whereIn('reservation_id', $ids)->selectRaw('COALESCE(SUM(rate * DATEDIFF(departure_date, arrival_date)),0) v, COALESCE(SUM(DATEDIFF(departure_date, arrival_date)),0) n')->first();

        return ['bookings' => $ids->count(), 'nights' => (int) $v->n, 'revenue' => round((float) $v->v, 2),
            'discount' => round((float) Reservation::whereIn('id', $ids)->sum('promo_discount'), 2)];
    }

    /** Delivery, engagement and booking stats for a campaign or a journey. */
    public static function stats(MarketingCampaign|MarketingJourney $c, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $col = $c instanceof MarketingCampaign ? 'campaign_id' : 'journey_id';
        $msgs = MarketingMessage::where($col, $c->id)->when($from, fn ($q) => $q->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]));
        $by = (clone $msgs)->groupBy('status')->selectRaw('status, count(*) n')->pluck('n', 'status');
        $sent = (int) ($by['sent'] ?? 0);
        $clicks = (clone $msgs)->whereNotNull('clicked_at')->count();
        $value = self::bookingValue(Reservation::where($col, $c->id)->when($from, fn ($q) => $q->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()->addDays((int) setting('crm_attribution_days', '30'))])));

        return ['recipients' => (int) $by->sum(), 'sent' => $sent, 'failed' => (int) ($by['failed'] ?? 0), 'skipped' => (int) ($by['skipped'] ?? 0),
            'clicks' => $clicks, 'ctr' => $sent ? round($clicks / $sent * 100, 1) : 0.0, 'unsubscribes' => (clone $msgs)->whereNotNull('unsubscribed_at')->count(),
            'conversion' => $sent ? round($value['bookings'] / $sent * 100, 1) : 0.0] + $value;
    }

    // ------------------------------------------------------------------ guest value

    /** Recalculate stays, room nights, revenue and first / last stay for all (or some) guests. */
    public static function refreshStats(?array $guestIds = null): int
    {
        $hid = hotel()->id;
        $stays = DB::table('reservations')->where('hotel_id', $hid)->where('status', 'checked_out')->whereNotNull('guest_id')
            ->when($guestIds !== null, fn ($q) => $q->whereIn('guest_id', $guestIds))
            ->groupBy('guest_id')->selectRaw('guest_id, COUNT(*) n, MIN(arrival_date) f, MAX(departure_date) l')->get()->keyBy('guest_id');
        $nights = DB::table('reservation_rooms as rr')->join('reservations as r', 'r.id', '=', 'rr.reservation_id')
            ->where('r.hotel_id', $hid)->where('r.status', 'checked_out')->when($guestIds !== null, fn ($q) => $q->whereIn('r.guest_id', $guestIds))
            ->groupBy('r.guest_id')->selectRaw('r.guest_id, SUM(DATEDIFF(rr.departure_date, rr.arrival_date)) n')->pluck('n', 'guest_id');
        $revenue = DB::table('folio_transactions as t')->join('folios as f', 'f.id', '=', 't.folio_id')
            ->where('t.hotel_id', $hid)->where('t.is_void', false)->whereNotNull('f.guest_id')->when($guestIds !== null, fn ($q) => $q->whereIn('f.guest_id', $guestIds))
            ->groupBy('f.guest_id')->selectRaw("f.guest_id, SUM(CASE WHEN t.type = 'charge' THEN t.amount WHEN t.type IN ('discount','allowance') THEN -t.amount ELSE 0 END) v")->pluck('v', 'guest_id');
        // stays without any folio (history imported from a previous system) count at their room rate
        $imported = DB::table('reservation_rooms as rr')->join('reservations as r', 'r.id', '=', 'rr.reservation_id')
            ->where('r.hotel_id', $hid)->where('r.status', 'checked_out')->when($guestIds !== null, fn ($q) => $q->whereIn('r.guest_id', $guestIds))
            ->whereNotExists(fn ($q) => $q->from('folios')->whereColumn('folios.reservation_id', 'r.id'))
            ->groupBy('r.guest_id')->selectRaw('r.guest_id, SUM(rr.rate * DATEDIFF(rr.departure_date, rr.arrival_date)) v')->pluck('v', 'guest_id');
        $n = 0;
        Guest::when($guestIds !== null, fn ($q) => $q->whereIn('id', $guestIds))->select('id', 'stays_count', 'nights_count', 'total_revenue', 'first_stay_on', 'last_stay_on')
            ->chunkById(500, function ($guests) use ($stays, $nights, $revenue, $imported, &$n) {
                foreach ($guests as $g) {
                    $s = $stays->get($g->id);
                    $cols = ['stays_count' => (int) ($s->n ?? 0), 'nights_count' => (int) ($nights[$g->id] ?? 0), 'total_revenue' => round((float) ($revenue[$g->id] ?? 0) + (float) ($imported[$g->id] ?? 0), 2),
                        'first_stay_on' => $s->f ?? null, 'last_stay_on' => $s->l ?? null];
                    DB::table('guests')->where('id', $g->id)->update($cols + ['stats_at' => now()]);
                    $n++;
                }
            });

        return $n;
    }

    /** Recency / frequency / monetary scores (1–5) and a plain-language label. */
    public static function rfm(Guest $g, ?array $mBreaks = null): array
    {
        if (! $g->stays_count) {
            return ['r' => 0, 'f' => 0, 'm' => 0, 'label' => 'Prospect', 'tone' => 'slate'];
        }
        $days = $g->last_stay_on ? (int) $g->last_stay_on->diffInDays(bdate()) : 9999;
        $r = $days <= 90 ? 5 : ($days <= 180 ? 4 : ($days <= 365 ? 3 : ($days <= 730 ? 2 : 1)));
        $f = $g->stays_count >= 10 ? 5 : ($g->stays_count >= 5 ? 4 : ($g->stays_count >= 3 ? 3 : ($g->stays_count >= 2 ? 2 : 1)));
        $mBreaks ??= [25000, 75000, 150000, 400000];
        $m = 1 + collect($mBreaks)->filter(fn ($b) => (float) $g->total_revenue >= $b)->count();
        [$label, $tone] = match (true) {
            $r >= 4 && $f >= 4 => ['Champion', 'green'],
            $r >= 3 && $f >= 3 => ['Loyal', 'green'],
            $r >= 4 && $f === 1 => ['New', 'blue'],
            $r >= 3 && $m >= 4 => ['Big spender', 'blue'],
            $r <= 2 && $f >= 2 => ['At risk', 'amber'],
            $r === 1 => ['Lost', 'red'],
            default => ['Promising', 'slate'],
        };

        return compact('r', 'f', 'm', 'label', 'tone');
    }
}
