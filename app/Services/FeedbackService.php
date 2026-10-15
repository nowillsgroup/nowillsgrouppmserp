<?php

namespace App\Services;

use App\Mail\SurveyInvite;
use App\Models\FeedbackAnswer;
use App\Models\FeedbackCase;
use App\Models\GuestFeedback;
use App\Models\OnlineReview;
use App\Models\Reservation;
use App\Models\ReviewTemplate;
use App\Models\Setting;
use App\Models\Survey;
use App\Models\SurveyInvitation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * v3.1 guest feedback & online reputation: surveys and invitations, response analysis (NPS, score, sentiment,
 * department mentions), alert rules that open service-recovery cases, and the reputation statistics.
 */
class FeedbackService
{
    public const DEFAULTS = [
        'fb_survey_auto' => '1',            // schedule the default survey at check-out
        'fb_cooldown_days' => '30',         // don't survey the same guest again within N days
        'fb_alert_nps' => '6',              // NPS at or below → case
        'fb_alert_rating' => '2',           // any rating at or below (1-5) → case
        'fb_alert_review' => '2.5',         // online review at or below (out of 5) → case
        'fb_sla_high' => '4',               // hours to resolve
        'fb_sla_medium' => '24',
        'fb_sla_low' => '72',
        'fb_sla_inhouse' => '2',            // guest still in the hotel – fix it before they leave
        'fb_goodwill_max' => '2000',        // goodwill points above this need feedback.manage
        'fb_review_approval' => '1',        // responses to reviews at or below 3★ need a second person's approval
        'fb_review_link_google' => '',      // promoters are invited to share their experience here
        'fb_review_link_tripadvisor' => '',
        'fb_signature' => 'Guest Relations Manager',
        'fb_owners' => '{}',                // department => user id (case owner)
        'fb_severe_words' => 'bed bugs, bedbugs, cockroach, rat, food poisoning, theft, stolen, unsafe, harassment, discrimination, injury, injured, fire, police',
    ];

    public const DEPTS = ['overall' => 'Overall', 'front_office' => 'Front office', 'housekeeping' => 'Housekeeping', 'room' => 'Room & comfort', 'fnb' => 'Food & beverage',
        'maintenance' => 'Maintenance', 'spa' => 'Spa & leisure', 'value' => 'Value for money', 'location' => 'Location'];

    /** Legacy rating columns (public form / portal) → department. */
    public const LEGACY = ['rating_overall' => 'overall', 'rating_room' => 'room', 'rating_service' => 'front_office', 'rating_food' => 'fnb', 'rating_cleanliness' => 'housekeeping'];

    /** Department keywords (after normalise()). */
    public const ASPECTS = [
        'front_office' => ['checkin', 'checkout', 'reception', 'receptionist', 'frontdesk', 'staff', 'concierge', 'welcome', 'welcomed', 'queue', 'waiting', 'waited', 'manager', 'bellboy', 'porter', 'luggage', 'service'],
        'housekeeping' => ['clean', 'cleaned', 'cleaning', 'cleanliness', 'dirty', 'dust', 'dusty', 'towel', 'towels', 'linen', 'sheets', 'housekeeping', 'smell', 'smelled', 'smelly', 'stain', 'stains', 'stained', 'hair', 'bathroom', 'toilet', 'bedbugs', 'cockroach', 'cockroaches', 'tidy'],
        'room' => ['room', 'rooms', 'bed', 'beds', 'pillow', 'pillows', 'view', 'noise', 'noisy', 'quiet', 'spacious', 'cramped', 'comfortable', 'uncomfortable', 'balcony', 'suite', 'mattress', 'shower'],
        'maintenance' => ['ac', 'hotwater', 'leak', 'leaking', 'broken', 'repair', 'lift', 'elevator', 'wifi', 'internet', 'tv', 'notworking', 'power'],
        'fnb' => ['breakfast', 'food', 'restaurant', 'buffet', 'dinner', 'lunch', 'bar', 'coffee', 'tea', 'menu', 'chef', 'roomservice', 'meal', 'meals', 'drinks', 'cocktail', 'cocktails', 'delicious', 'tasty'],
        'spa' => ['spa', 'pool', 'gym', 'massage', 'therapist', 'sauna', 'jacuzzi'],
        'value' => ['price', 'value', 'expensive', 'overpriced', 'worth', 'cheap', 'pricey', 'money'],
        'location' => ['location', 'airport', 'metro', 'nearby', 'located', 'neighbourhood', 'neighborhood', 'traffic'],
    ];

    public const POSITIVE = ['excellent', 'great', 'good', 'amazing', 'lovely', 'wonderful', 'friendly', 'helpful', 'clean', 'comfortable', 'spacious', 'delicious', 'tasty', 'perfect', 'courteous',
        'recommend', 'recommended', 'loved', 'love', 'fantastic', 'quick', 'prompt', 'beautiful', 'best', 'easy', 'nice', 'pleasant', 'superb', 'awesome', 'warm', 'quiet', 'fresh', 'efficient', 'attentive',
        'outstanding', 'impressed', 'enjoyed', 'smooth', 'polite', 'professional', 'value', 'worth', 'tidy', 'spotless', 'thank', 'thanks'];

    public const NEGATIVE = ['dirty', 'rude', 'slow', 'noisy', 'bad', 'poor', 'terrible', 'awful', 'broken', 'smelly', 'smelled', 'stain', 'stains', 'stained', 'worst', 'disappointing', 'disappointed',
        'overpriced', 'expensive', 'unhelpful', 'delay', 'delayed', 'waited', 'leak', 'leaking', 'notworking', 'bedbugs', 'cockroach', 'cockroaches', 'stale', 'uncomfortable', 'horrible', 'cold', 'cramped',
        'dusty', 'mediocre', 'unprofessional', 'ignored', 'unfriendly', 'problem', 'problems', 'complaint', 'hair', 'lukewarm', 'never', 'charged', 'overcharged', 'refused'];

    public const NEGATORS = ['not', 'no', 'never', 'hardly', 'barely', 'without'];

    public static function ensureDefaults(): void
    {
        foreach (self::DEFAULTS as $k => $v) {
            Setting::firstOrCreate(['key' => $k], ['value' => $v]);
        }
        Setting::$version++;
        if (! Survey::exists()) {
            $s = Survey::create(['name' => 'Post-stay survey', 'is_default' => true, 'channel' => 'email', 'send_delay_hours' => 4, 'reminder_hours' => 48, 'expiry_days' => 14,
                'intro' => 'Thank you for staying with us. Tell us about your stay – it takes about one minute and every answer is read by our managers.',
                'thank_you' => 'Thank you for your feedback! Your answers go straight to our management team.']);
            foreach ([
                ['nps', 'How likely are you to recommend us to a friend or colleague?', null, true],
                ['rating', 'Your stay overall', 'overall', true],
                ['rating', 'Check-in and front desk', 'front_office', false],
                ['rating', 'Cleanliness of your room', 'housekeeping', false],
                ['rating', 'Room comfort', 'room', false],
                ['rating', 'Food & beverage', 'fnb', false],
                ['rating', 'Value for money', 'value', false],
                ['yesno', 'Would you stay with us again?', null, false],
                ['choice', 'What was the main purpose of your stay?', null, false, ['Business', 'Leisure', 'Wedding or event', 'Other']],
                ['text', 'Is there anything we could have done better?', null, false],
            ] as $i => $q) {
                $s->questions()->create(['sort' => ($i + 1) * 10, 'type' => $q[0], 'label' => $q[1], 'department' => $q[2], 'required' => $q[3], 'options' => $q[4] ?? null]);
            }
        }
        if (! ReviewTemplate::exists()) {
            foreach ([
                ['Thank you – positive', 'positive', "Dear {reviewer},\n\nThank you for your wonderful review and for choosing {hotel}. We are delighted that you enjoyed {liked}, and we have shared your kind words with the team.\n\nWe look forward to welcoming you back soon.\n\nWarm regards,\n{signature}, {hotel}"],
                ['Mixed – thank & improve', 'neutral', "Dear {reviewer},\n\nThank you for taking the time to share your feedback. We are glad you liked {liked}, and we are sorry that {disliked} did not meet your expectations – we have passed your comments to the department concerned.\n\nWe hope to see you again and show you a better experience.\n\nKind regards,\n{signature}, {hotel}"],
                ['Apology – negative', 'negative', "Dear {reviewer},\n\nThank you for your feedback, and please accept our sincere apologies. We are sorry that {disliked} fell short of the standard we set ourselves – our team is already looking into what went wrong.\n\nWe would value the chance to discuss your stay personally – please write to us at {email}.\n\nSincerely,\n{signature}, {hotel}"],
            ] as [$n, $s, $b]) {
                ReviewTemplate::create(['name' => $n, 'sentiment' => $s, 'body' => $b]);
            }
        }
    }

    // ------------------------------------------------------------------ text analysis

    /** Lower-case, fold contractions and multi-word terms into single tokens. */
    public static function normalise(string $text): string
    {
        $t = mb_strtolower($text);
        $t = str_replace(['’', '`'], "'", $t);
        $t = preg_replace("/n't\b/", ' not', $t);
        $map = ['wi-fi' => 'wifi', 'wi fi' => 'wifi', 'check-in' => 'checkin', 'check in' => 'checkin', 'check-out' => 'checkout', 'check out' => 'checkout', 'front desk' => 'frontdesk',
            'front office' => 'frontdesk', 'room service' => 'roomservice', 'air conditioning' => 'ac', 'air-conditioning' => 'ac', 'a/c' => 'ac', 'hot water' => 'hotwater',
            'bed bugs' => 'bedbugs', 'bed-bugs' => 'bedbugs', 'food poisoning' => 'foodpoisoning', 'not working' => 'notworking', "didn't work" => 'notworking', 'did not work' => 'notworking',
            'value for money' => 'value', 'good value' => 'value'];

        return strtr($t, $map);
    }

    /** @return array{score:int, aspects:array<string,string>, mentions:array<string,array{pos:int,neg:int}>, severe:array<int,string>} */
    public static function analyseText(?string $text): array
    {
        $text = trim((string) $text);
        $out = ['score' => 0, 'aspects' => [], 'mentions' => [], 'severe' => []];
        if ($text === '') {
            return $out;
        }
        $norm = self::normalise($text);
        $lower = mb_strtolower($text);
        foreach (array_filter(array_map('trim', explode(',', (string) setting('fb_severe_words', self::DEFAULTS['fb_severe_words'])))) as $w) {
            $w = mb_strtolower($w);
            $wn = self::normalise($w);
            if (preg_match('/\b'.preg_quote($w, '/').'\b/u', $lower) || preg_match('/\b'.preg_quote($wn, '/').'\b/u', $norm)) {
                $out['severe'][] = $w;
            }
        }
        $out['severe'] = array_values(array_unique($out['severe']));
        $pos = array_flip(self::POSITIVE);
        $neg = array_flip(self::NEGATIVE);
        foreach (preg_split('/[.!?;\n]+|\bbut\b|\bhowever\b|\bthough\b|\balthough\b|\bexcept\b/u', $norm) as $clause) {
            $words = preg_split('/[^a-z0-9]+/u', $clause, -1, PREG_SPLIT_NO_EMPTY);
            $s = 0;
            $negateUntil = -1;
            foreach ($words as $i => $w) {
                if (in_array($w, self::NEGATORS, true)) {
                    $negateUntil = $i + 3;

                    continue;
                }
                $v = isset($pos[$w]) ? 1 : (isset($neg[$w]) ? -1 : 0);
                if ($v && $i <= $negateUntil) {
                    $v = -$v;
                }
                $s += $v;
            }
            $out['score'] += $s;
            $hit = [];
            foreach (self::ASPECTS as $dept => $kw) {
                if (array_intersect($words, $kw)) {
                    $hit[] = $dept;
                }
            }
            foreach ($hit as $dept) {
                $out['mentions'][$dept] ??= ['pos' => 0, 'neg' => 0];
                if ($s > 0) {
                    $out['mentions'][$dept]['pos']++;
                } elseif ($s < 0) {
                    $out['mentions'][$dept]['neg']++;
                }
            }
        }
        foreach ($out['mentions'] as $dept => $m) {
            if ($m['pos'] || $m['neg']) {
                $out['aspects'][$dept] = $m['pos'] && $m['neg'] ? 'mixed' : ($m['pos'] ? 'positive' : 'negative');
            }
        }

        return $out;
    }

    public static function npsGroup(?int $nps): ?string
    {
        return $nps === null ? null : ($nps >= 9 ? 'promoter' : ($nps >= 7 ? 'passive' : 'detractor'));
    }

    /** Sentiment from a 0-100 score and the text score. */
    public static function sentiment(?int $score, int $textScore, bool $severe = false): string
    {
        if ($severe) {
            return 'negative';
        }
        if ($score === null) {
            return $textScore > 0 ? 'positive' : ($textScore < 0 ? 'negative' : 'neutral');
        }
        if ($score >= 75) {
            return $textScore <= -2 ? 'neutral' : 'positive';
        }
        if ($score <= 45) {
            return $textScore >= 2 ? 'neutral' : 'negative';
        }

        return $textScore >= 1 ? 'positive' : ($textScore <= -1 ? 'negative' : 'neutral');
    }

    /** Department ratings of a response (1-5): survey answers first, then the legacy columns. */
    public static function ratings(GuestFeedback $f): array
    {
        $r = [];
        foreach ($f->answers()->with('question')->get() as $a) {
            if ($a->question?->type === 'rating' && $a->question->department && $a->value_int) {
                $r[$a->question->department][] = $a->value_int;
            }
        }
        $r = array_map(fn ($v) => (int) round(array_sum($v) / count($v)), $r);
        foreach (self::LEGACY as $col => $dept) {
            if (! isset($r[$dept]) && $f->{$col}) {
                $r[$dept] = (int) $f->{$col};
            }
        }

        return $r;
    }

    public static function feedbackText(GuestFeedback $f): string
    {
        $parts = [(string) $f->comments];
        foreach ($f->answers()->whereHas('question', fn ($q) => $q->where('type', 'text'))->get() as $a) {
            if ($a->value_text && ! str_contains((string) $f->comments, $a->value_text)) {
                $parts[] = $a->value_text;
            }
        }

        return trim(implode("\n", array_filter($parts)));
    }

    /** Analyse a response (idempotent) and open a service-recovery case when it breaches the alert rules. */
    public static function analyse(GuestFeedback $f, bool $withCase = true): GuestFeedback
    {
        $ratings = self::ratings($f);
        $npsAnswer = $f->answers()->whereHas('question', fn ($q) => $q->where('type', 'nps'))->value('value_int');
        $nps = $npsAnswer ?? $f->nps;
        $norm = array_map(fn ($v) => ($v - 1) / 4 * 100, array_values($ratings));
        if ($nps !== null) {
            $norm[] = $nps * 10;
        }
        $score = $norm ? (int) round(array_sum($norm) / count($norm)) : null;
        $txt = self::analyseText(self::feedbackText($f));
        $aspects = $txt['aspects'];
        foreach ($ratings as $dept => $v) {
            if ($dept === 'overall') {
                continue;
            }
            $rs = $v <= 2 ? 'negative' : ($v >= 4 ? 'positive' : null);
            if ($rs) {
                $aspects[$dept] = isset($aspects[$dept]) && $aspects[$dept] !== $rs ? 'mixed' : $rs;
            }
        }
        $stay = $f->stay;
        $f->forceFill([
            'nps' => $nps, 'nps_group' => self::npsGroup($nps), 'score' => $score, 'aspects' => $aspects ?: null,
            'sentiment' => self::sentiment($score, $txt['score'], (bool) $txt['severe']),
            'guest_id' => $f->guest_id ?? $f->reservation?->guest_id,
            'in_house' => $f->in_house || ($stay && $stay->status === 'checked_in'),
        ])->save();
        if ($withCase) {
            self::maybeOpenCase($f, $ratings, $txt);
        }

        return $f;
    }

    /** Alert rules → service-recovery case (one per response). */
    public static function maybeOpenCase(GuestFeedback $f, array $ratings, array $txt): ?FeedbackCase
    {
        $alertNps = (int) setting('fb_alert_nps', self::DEFAULTS['fb_alert_nps']);
        $alertRating = (int) setting('fb_alert_rating', self::DEFAULTS['fb_alert_rating']);
        $reasons = [];
        $low = array_filter($ratings, fn ($v) => $v <= $alertRating);
        asort($low);
        if ($f->nps !== null && $f->nps <= $alertNps) {
            $reasons[] = "Detractor (NPS {$f->nps})";
        }
        foreach ($low as $dept => $v) {
            $reasons[] = self::DEPTS[$dept]." rated {$v}★";
        }
        if ($txt['severe']) {
            $reasons[] = 'Mentions '.implode(', ', $txt['severe']);
        }
        if (! $reasons && $f->sentiment === 'negative' && trim((string) self::feedbackText($f)) !== '') {
            $reasons[] = 'Negative comments';
        }
        $existing = $f->recoveryCase;
        if ($existing) {
            if ($existing->isActive() && $f->wasChanged(['rating_overall', 'comments', 'nps', 'score'])) {
                CaseService::event($existing, 'updated', ($reasons ? implode(' · ', $reasons).' – ' : '').Str::limit((string) $f->comments, 300));
            }

            return $existing;
        }
        if (! $reasons) {
            return null;
        }
        $minRating = $ratings ? min($ratings) : 5;
        $severity = ($txt['severe'] || ($f->nps !== null && $f->nps <= 3) || $minRating === 1) ? 'high'
            : (($f->nps !== null && $f->nps <= $alertNps) || $minRating <= $alertRating ? 'medium' : 'low');
        $lowDepts = array_keys(array_filter($low, fn ($v, $d) => $d !== 'overall', ARRAY_FILTER_USE_BOTH));
        $negAspects = array_keys(array_filter((array) $f->aspects, fn ($s) => $s === 'negative'));
        $dept = $lowDepts[0] ?? $negAspects[0] ?? 'front_office';
        $who = $f->guest_name ?: $f->guest?->full_name ?: 'Guest';
        $room = $f->stay?->room?->room_no;

        return CaseService::open([
            'source' => 'feedback', 'feedback_id' => $f->id, 'guest_id' => $f->guest_id, 'reservation_id' => $f->reservation_id,
            'department' => $dept, 'severity' => $severity, 'in_house' => $f->in_house,
            'title' => Str::limit(($f->in_house ? 'In-house: ' : '').$who.($room ? " (room {$room})" : '').' – '.implode(' · ', $reasons), 160, '…'),
            'details' => (string) self::feedbackText($f) ?: null,
        ]);
    }

    // ------------------------------------------------------------------ surveys

    public static function defaultSurvey(): ?Survey
    {
        return Survey::where('is_default', true)->where('is_active', true)->first();
    }

    /**
     * Schedule the post-stay survey for a checked-out reservation (idempotent). A skipped invitation records why.
     * $manual = staff "send survey" (sends straight away, ignores the auto switch and the cooldown).
     */
    public static function scheduleFor(Reservation $r, bool $manual = false, ?Survey $survey = null): ?SurveyInvitation
    {
        if (! $manual && setting('fb_survey_auto', '1') !== '1') {
            return null;
        }
        $survey ??= self::defaultSurvey();
        if (! $survey || ! $survey->is_active) {
            return null;
        }
        $existing = SurveyInvitation::where('survey_id', $survey->id)->where('reservation_id', $r->id)->first();
        if ($existing) {
            return $existing;
        }
        $r->loadMissing('guest');
        $g = $r->guest;
        $stay = $r->rooms()->orderByDesc('checked_out_at')->orderByDesc('id')->first();
        $email = $g && filter_var($g->email, FILTER_VALIDATE_EMAIL) ? $g->email : null;
        $phone = $g ? MessageService::normalise($g->phone) : null;
        $channel = match ($survey->channel) {
            'sms' => $phone ? 'sms' : null,
            'both' => $email && $phone ? 'both' : ($email ? 'email' : ($phone ? 'sms' : null)),
            default => $email ? 'email' : null,
        };
        $days = (int) setting('fb_cooldown_days', '30');
        $skip = match (true) {
            ! $g => 'No guest profile',
            (bool) $g->privacy_status => 'Guest data is restricted (privacy)',
            ! $channel => $survey->channel === 'sms' ? 'No valid mobile number' : 'No valid e-mail address',
            GuestFeedback::where('reservation_id', $r->id)->whereIn('source', ['portal', 'survey', 'tv'])->exists() => 'Feedback already given during the stay',
            ! $manual && $days > 0 && SurveyInvitation::where('guest_id', $g->id)->whereIn('status', ['sent', 'opened', 'completed', 'expired'])
                ->where('sent_at', '>=', now()->subDays($days))->exists() => "Surveyed in the last {$days} days",
            default => null,
        };
        $inv = SurveyInvitation::create([
            'survey_id' => $survey->id, 'reservation_id' => $r->id, 'reservation_room_id' => $stay?->id, 'guest_id' => $g?->id,
            'channel' => $channel ?? $survey->channel, 'to_email' => $email, 'to_phone' => $phone, 'nonce' => Str::random(32),
            'status' => $skip ? 'skipped' : 'scheduled', 'skip_reason' => $skip,
            'send_at' => $skip ? null : ($manual ? now() : now()->addHours($survey->send_delay_hours)), 'created_by' => $manual ? auth()->id() : null,
        ]);
        if ($manual && ! $skip) {
            self::send($inv);
        }

        return $inv->fresh();
    }

    /** Send (or remind). Returns true when at least one channel went out. */
    public static function send(SurveyInvitation $inv, bool $reminder = false): bool
    {
        $inv->loadMissing('survey', 'guest', 'reservation');
        if (! $inv->survey?->is_active) {
            $inv->forceFill(['status' => 'skipped', 'skip_reason' => 'Survey switched off', 'send_at' => null])->save();

            return false;
        }
        $first = ! $inv->sent_at;
        $token = $inv->token();
        $url = route('survey.show', $token);
        $hotel = hotel();
        $name = $inv->guest?->first_name ?: 'Guest';
        $ok = false;
        $errors = [];
        if (in_array($inv->channel, ['email', 'both'])) {
            $log = MailService::send($inv->to_email, new SurveyInvite($inv, $hotel, $url, $reminder), $reminder ? 'survey_reminder' : 'survey', $inv);
            $ok = $ok || $log->status === 'sent';
            $log->status === 'sent' || $errors[] = 'E-mail: '.$log->error;
        }
        if (in_array($inv->channel, ['sms', 'both'])) {
            $body = ($reminder ? "Reminder: " : '')."Dear {$name}, thank you for staying at {$hotel->name}. How did we do? One minute: {$url}";
            $log = MessageService::send($inv->to_phone, $body, MessageService::defaultChannel(), $reminder ? 'survey_reminder' : 'survey', $inv);
            $ok = $ok || $log->status === 'sent';
            $log->status === 'sent' || $errors[] = 'SMS: '.$log->error;
        }
        if ($reminder) {
            $inv->forceFill(['reminded_at' => now()])->save();

            return $ok;
        }
        $inv->forceFill($ok
            ? ['status' => 'sent', 'sent_at' => $inv->sent_at ?? now(), 'token_hash' => hash('sha256', $token), 'error' => null,
                'expires_at' => $first ? now()->addDays(max(1, $inv->survey->expiry_days)) : $inv->expires_at]
            : ['status' => 'failed', 'error' => Str::limit(implode(' · ', $errors), 250)])->save();

        return $ok;
    }

    /** Scheduler: due invitations, one reminder, expiry. Returns [sent, reminded, expired]. */
    public static function runDue(): array
    {
        $sent = $rem = 0;
        foreach (SurveyInvitation::where('status', 'scheduled')->where('send_at', '<=', now())->orderBy('send_at')->limit(500)->get() as $inv) {
            $sent += self::send($inv) ? 1 : 0;
        }
        foreach (SurveyInvitation::with('survey')->whereIn('status', ['sent', 'opened'])->whereNull('reminded_at')->where('expires_at', '>', now())->limit(500)->get() as $inv) {
            $h = $inv->survey?->reminder_hours;
            if ($h && $inv->sent_at->copy()->addHours($h)->lte(now())) {
                $rem += self::send($inv, true) ? 1 : 0;
            }
        }
        $exp = SurveyInvitation::whereIn('status', ['sent', 'opened'])->where('expires_at', '<=', now())->update(['status' => 'expired', 'updated_at' => now()]);

        return [$sent, $rem, $exp];
    }

    public static function findByToken(string $token): ?SurveyInvitation
    {
        return preg_match('/^[a-f0-9]{40}$/', $token)
            ? SurveyInvitation::withoutGlobalScopes()->where('token_hash', hash('sha256', $token))->first()
            : null;
    }

    /** Store a survey response for an invitation. */
    public static function submit(SurveyInvitation $inv, array $input): GuestFeedback
    {
        if ($inv->status === 'completed') {
            throw ValidationException::withMessages(['survey' => 'This survey has already been completed – thank you!']);
        }
        if (! $inv->isOpen()) {
            throw ValidationException::withMessages(['survey' => 'This survey has closed.']);
        }
        $qs = $inv->survey->activeQuestions()->get();
        $rules = [];
        foreach ($qs as $q) {
            $rules['q'.$q->id] = $q->rule();
        }
        $rules['consent_publish'] = 'nullable|boolean';
        $attr = $qs->mapWithKeys(fn ($q) => ['q'.$q->id => '"'.Str::limit($q->label, 40).'"'])->all();
        $data = validator($input, $rules, [], $attr)->validate();

        return DB::transaction(function () use ($inv, $qs, $data) {
            $inv = SurveyInvitation::lockForUpdate()->find($inv->id);
            if ($inv->status === 'completed') {
                throw ValidationException::withMessages(['survey' => 'This survey has already been completed – thank you!']);
            }
            $byDept = [];
            $nps = null;
            $text = [];
            foreach ($qs as $q) {
                $v = $data['q'.$q->id] ?? null;
                if ($v === null || $v === '') {
                    continue;
                }
                if ($q->type === 'rating' && $q->department) {
                    $byDept[$q->department][] = (int) $v;
                }
                if ($q->type === 'nps') {
                    $nps = (int) $v;
                }
                if ($q->type === 'text') {
                    $text[] = trim((string) $v);
                }
            }
            $avg = fn ($d) => isset($byDept[$d]) ? (int) round(array_sum($byDept[$d]) / count($byDept[$d])) : null;
            $all = array_merge(...array_values($byDept ?: [[]]));
            $overall = $avg('overall') ?? ($all ? (int) round(array_sum($all) / count($all)) : ($nps !== null ? max(1, (int) round($nps / 2)) : 3));
            $g = $inv->guest;
            $f = GuestFeedback::create([
                'reservation_id' => $inv->reservation_id, 'reservation_room_id' => $inv->reservation_room_id, 'survey_id' => $inv->survey_id, 'invitation_id' => $inv->id,
                'guest_id' => $inv->guest_id, 'guest_name' => $g?->full_name, 'contact' => $inv->to_email ?: $inv->to_phone,
                'rating_overall' => $overall, 'rating_room' => $avg('room'), 'rating_service' => $avg('front_office'), 'rating_food' => $avg('fnb'), 'rating_cleanliness' => $avg('housekeeping'),
                'nps' => $nps, 'comments' => $text ? Str::limit(implode("\n", $text), 2000, '') : null, 'source' => 'survey', 'consent_publish' => (bool) ($data['consent_publish'] ?? false),
            ]);
            foreach ($qs as $q) {
                $v = $data['q'.$q->id] ?? null;
                if ($v === null || $v === '') {
                    continue;
                }
                FeedbackAnswer::create(['feedback_id' => $f->id, 'question_id' => $q->id,
                    'value_int' => in_array($q->type, ['nps', 'rating', 'yesno']) ? (int) $v : null, 'value_text' => in_array($q->type, ['text', 'choice']) ? (string) $v : null]);
            }
            $inv->forceFill(['status' => 'completed', 'completed_at' => now(), 'opened_at' => $inv->opened_at ?? now()])->save();

            return self::analyse($f->fresh());
        });
    }

    /** Is this respondent a promoter we may invite to post a public review? */
    public static function reviewLinks(GuestFeedback $f): array
    {
        $happy = $f->nps !== null ? $f->nps >= 9 : $f->rating_overall >= 5;
        if (! $happy) {
            return [];
        }

        return array_filter(['google' => (string) setting('fb_review_link_google', ''), 'tripadvisor' => (string) setting('fb_review_link_tripadvisor', '')]);
    }

    // ------------------------------------------------------------------ statistics

    /** @return array{n:int, promoters:int, passives:int, detractors:int, nps:?int} */
    public static function npsOf($rows): array
    {
        $rows = collect($rows)->filter(fn ($r) => $r->nps !== null);
        $n = $rows->count();
        $p = $rows->where('nps', '>=', 9)->count();
        $d = $rows->where('nps', '<=', 6)->count();

        return ['n' => $n, 'promoters' => $p, 'passives' => $n - $p - $d, 'detractors' => $d, 'nps' => $n ? (int) round(($p - $d) / $n * 100) : null];
    }

    public static function stats(Carbon $from, Carbon $to): array
    {
        $fb = GuestFeedback::whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->get(['id', 'nps', 'rating_overall', 'rating_room', 'rating_service', 'rating_food', 'rating_cleanliness', 'sentiment', 'aspects', 'source', 'created_at']);
        $inv = SurveyInvitation::whereBetween('sent_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->selectRaw("count(*) n, sum(status = 'completed') c")->first();
        $rev = OnlineReview::whereDate('review_date', '>=', $from->toDateString())->whereDate('review_date', '<=', $to->toDateString())->get(['id', 'source', 'rating5', 'status', 'sentiment', 'aspects', 'created_at', 'review_date', 'posted_at']);
        $answerable = $rev->where('status', '!=', 'ignored');

        return [
            'responses' => $fb->count(),
            'nps' => self::npsOf($fb),
            'csat' => $fb->count() ? round($fb->avg('rating_overall'), 2) : null,
            'invited' => (int) $inv->n, 'completed' => (int) $inv->c, 'response_rate' => $inv->n ? round($inv->c / $inv->n * 100) : null,
            'sentiment' => ['positive' => $fb->where('sentiment', 'positive')->count(), 'neutral' => $fb->where('sentiment', 'neutral')->count(), 'negative' => $fb->where('sentiment', 'negative')->count()],
            'reviews' => $rev->count(), 'review_avg' => $rev->count() ? round($rev->avg('rating5'), 2) : null,
            'review_response_rate' => $answerable->count() ? round($answerable->where('status', 'posted')->count() / $answerable->count() * 100) : null,
            'review_response_hours' => ($h = $answerable->where('status', 'posted')->map(fn ($r) => $r->responseHours())->filter(fn ($x) => $x !== null))->count() ? round($h->avg(), 1) : null,
            'by_source' => $rev->groupBy('source')->map(fn ($g) => ['n' => $g->count(), 'avg' => round($g->avg('rating5'), 2)])->sortByDesc('n')->all(),
            'departments' => self::departmentScores($fb, $rev),
        ];
    }

    /** Average rating (1-5) and positive / negative mentions per department across responses and reviews. */
    public static function departmentScores($fb, $rev): array
    {
        $out = [];
        foreach (self::DEPTS as $k => $label) {
            $out[$k] = ['label' => $label, 'avg' => null, 'n' => 0, 'pos' => 0, 'neg' => 0];
        }
        $ids = collect($fb)->pluck('id');
        $sum = [];
        foreach (collect($fb) as $f) {
            foreach (self::LEGACY as $col => $dept) {
                if ($f->{$col}) {
                    $sum[$dept][] = (int) $f->{$col};
                }
            }
        }
        if ($ids->count()) {
            $ans = FeedbackAnswer::join('survey_questions as q', 'q.id', '=', 'feedback_answers.question_id')->whereIn('feedback_answers.feedback_id', $ids)
                ->where('q.type', 'rating')->whereNotIn('q.department', array_values(self::LEGACY))->whereNotNull('q.department')
                ->get(['q.department', 'feedback_answers.value_int']);
            foreach ($ans as $a) {
                $sum[$a->department][] = (int) $a->value_int;
            }
        }
        foreach ($sum as $d => $v) {
            if (isset($out[$d]) && $v) {
                $out[$d]['avg'] = round(array_sum($v) / count($v), 2);
                $out[$d]['n'] = count($v);
            }
        }
        foreach (collect($fb)->concat(collect($rev)) as $x) {
            foreach ((array) $x->aspects as $d => $s) {
                if (isset($out[$d])) {
                    $out[$d]['pos'] += in_array($s, ['positive', 'mixed']) ? 1 : 0;
                    $out[$d]['neg'] += in_array($s, ['negative', 'mixed']) ? 1 : 0;
                }
            }
        }

        return $out;
    }

    /** Month-by-month NPS / CSAT / online rating for the trend chart. */
    public static function trend(int $months = 6): array
    {
        $out = [];
        $m = bdate()->copy()->startOfMonth()->subMonths($months - 1);
        for ($i = 0; $i < $months; $i++, $m->addMonth()) {
            $fb = GuestFeedback::whereBetween('created_at', [$m->copy()->startOfMonth(), $m->copy()->endOfMonth()])->get(['nps', 'rating_overall']);
            $rv = OnlineReview::whereDate('review_date', '>=', $m->copy()->startOfMonth()->toDateString())->whereDate('review_date', '<=', $m->copy()->endOfMonth()->toDateString())->avg('rating5');
            $out[$m->format('Y-m')] = ['label' => $m->format('M'), 'responses' => $fb->count(), 'nps' => self::npsOf($fb)['nps'],
                'csat' => $fb->count() ? round($fb->avg('rating_overall'), 2) : null, 'reviews' => $rv !== null ? round((float) $rv, 2) : null];
        }

        return $out;
    }

    /** Dashboard / sidebar alerts. */
    public static function alerts(): array
    {
        return [
            'overdue' => FeedbackCase::overdue()->count(),
            'high' => FeedbackCase::active()->where('severity', 'high')->count(),
            'open' => FeedbackCase::active()->count(),
            'reviews_waiting' => OnlineReview::whereIn('status', ['new', 'draft'])->where('created_at', '<', now()->subHours(48))->count(),
            'reviews_pending' => OnlineReview::where('status', 'pending')->count(),
        ];
    }

    public static function attentionCount(): int
    {
        $a = self::alerts();

        return $a['overdue'] + $a['reviews_waiting'] + $a['reviews_pending'];
    }

    /** Cases badge: my open cases + unassigned ones. */
    public static function myCaseCount(): int
    {
        return FeedbackCase::active()->where(fn ($q) => $q->where('assigned_to', auth()->id())->orWhereNull('assigned_to'))->count();
    }

    /** Case owners by department (validated against active users of this hotel). */
    public static function owners(): array
    {
        $o = json_decode((string) setting('fb_owners', '{}'), true);

        return is_array($o) ? array_filter($o, fn ($v, $k) => isset(self::DEPTS[$k]) && (int) $v > 0, ARRAY_FILTER_USE_BOTH) : [];
    }

    public static function ownerFor(string $dept): ?int
    {
        $id = (int) (self::owners()[$dept] ?? 0);

        return $id && self::caseStaff()->has($id) ? $id : null;
    }

    /** Active users of this hotel who can handle service-recovery cases (id => name). */
    public static function caseStaff(): \Illuminate\Support\Collection
    {
        return User::where('hotel_id', \App\Support\Tenant::id())->where('is_active', true)->orderBy('name')->get()
            ->filter(fn ($u) => $u->hasPermission('feedback.cases'))->pluck('name', 'id');
    }

    /** Upgrade: analyse feedback received before v3.1 (no cases are opened for history). */
    public static function backfill(): int
    {
        $n = 0;
        foreach (GuestFeedback::whereNull('score')->get() as $f) {
            self::analyse($f, false);
            $n++;
        }

        return $n;
    }
}
