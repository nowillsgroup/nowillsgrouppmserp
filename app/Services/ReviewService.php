<?php

namespace App\Services;

use App\Models\FeedbackCase;
use App\Models\Guest;
use App\Models\OnlineReview;
use App\Models\Reservation;
use App\Models\ReviewTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * v3.1 online reputation: reviews from TripAdvisor / Google / Booking.com / … are imported (CSV) or keyed in,
 * de-duplicated, normalised to a 5-point scale, analysed (sentiment + departments), matched to the guest's stay,
 * escalated to a service-recovery case when poor, and answered from templates – with manager approval for
 * responses to critical reviews (maker ≠ checker) before they are posted.
 */
class ReviewService
{
    /** Friendly phrases for response templates. */
    public const PHRASES = ['front_office' => 'our front-office team', 'housekeeping' => 'the cleanliness of your room', 'room' => 'your room', 'maintenance' => 'the room facilities',
        'fnb' => 'our food and drinks', 'spa' => 'our spa and pool', 'value' => 'the value of your stay', 'location' => 'our location'];

    public const MAX_RESPONSE = 4000;

    public static function dedupeHash(array $d): string
    {
        return ! empty($d['external_id'])
            ? hash('sha256', 'id:'.trim((string) $d['external_id']))
            : hash('sha256', mb_strtolower(trim($d['reviewer_name'])).'|'.Carbon::parse($d['review_date'])->toDateString().'|'.mb_strtolower(trim((string) ($d['title'] ?? ''))).'|'.mb_substr(trim($d['body']), 0, 120));
    }

    public static function rating5(float $rating, int $scale): float
    {
        return round($scale === 10 ? $rating / 2 : $rating, 1);
    }

    /** @return array{0: OnlineReview, 1: bool} [review, created] */
    public static function store(array $d): array
    {
        $d['source'] = isset(OnlineReview::SOURCES[$d['source'] ?? '']) ? $d['source'] : 'other';
        $hash = self::dedupeHash($d);
        if ($dup = OnlineReview::where('source', $d['source'])->where('dedupe_hash', $hash)->first()) {
            return [$dup, false];
        }
        $scale = (int) ($d['rating_scale'] ?? 5) === 10 ? 10 : 5;
        $r = OnlineReview::create([
            'source' => $d['source'], 'external_id' => $d['external_id'] ?? null, 'dedupe_hash' => $hash, 'reviewer_name' => trim($d['reviewer_name']),
            'reviewer_location' => $d['reviewer_location'] ?? null, 'review_date' => Carbon::parse($d['review_date'])->toDateString(),
            'rating' => (float) $d['rating'], 'rating_scale' => $scale, 'rating5' => self::rating5((float) $d['rating'], $scale),
            'title' => $d['title'] ?? null, 'body' => trim($d['body']), 'url' => $d['url'] ?? null, 'trip_type' => $d['trip_type'] ?? null, 'created_by' => auth()->id(),
        ]);
        self::analyse($r);
        self::matchGuest($r);
        self::maybeOpenCase($r->fresh());

        return [$r->fresh(), true];
    }

    public static function analyse(OnlineReview $r): void
    {
        $txt = FeedbackService::analyseText(trim(($r->title ? $r->title.'. ' : '').$r->body));
        $score = (int) round(((float) $r->rating5 - 1) / 4 * 100);
        $r->forceFill(['sentiment' => FeedbackService::sentiment(max(0, $score), $txt['score'], (bool) $txt['severe']), 'aspects' => $txt['aspects'] ?: null])->save();
    }

    /** Link the review to a stay when exactly one guest with that name checked out in the 60 days before the review. */
    public static function matchGuest(OnlineReview $r): bool
    {
        if ($r->guest_id) {
            return true;
        }
        $name = mb_strtolower(preg_replace('/\s+/', ' ', trim($r->reviewer_name)));
        if (! str_contains($name, ' ')) {
            return false;
        }
        $res = Reservation::with('guest')->where('status', 'checked_out')
            ->whereBetween('departure_date', [$r->review_date->copy()->subDays(60)->toDateString(), $r->review_date->copy()->addDays(3)->toDateString()])
            ->whereHas('guest', fn ($q) => $q->whereNull('privacy_status')->whereRaw("lower(concat(first_name, ' ', coalesce(last_name, ''))) = ?", [$name]))
            ->get();
        if ($res->pluck('guest_id')->unique()->count() !== 1) {
            return false;
        }
        $last = $res->sortByDesc('departure_date')->first();
        $r->forceFill(['guest_id' => $last->guest_id, 'reservation_id' => $last->id])->save();

        return true;
    }

    public static function maybeOpenCase(OnlineReview $r): ?FeedbackCase
    {
        if ($r->recoveryCase || (float) $r->rating5 > (float) setting('fb_alert_review', FeedbackService::DEFAULTS['fb_alert_review'])) {
            return $r->recoveryCase;
        }
        $txt = FeedbackService::analyseText($r->body);
        $neg = array_keys(array_filter((array) $r->aspects, fn ($s) => $s === 'negative'));

        return CaseService::open([
            'source' => 'review', 'review_id' => $r->id, 'guest_id' => $r->guest_id, 'reservation_id' => $r->reservation_id,
            'department' => $neg[0] ?? 'front_office', 'severity' => ((float) $r->rating5 <= 1.5 || $txt['severe']) ? 'high' : 'medium',
            'title' => Str::limit($r->sourceName().' review '.rtrim(rtrim(number_format((float) $r->rating5, 1), '0'), '.').'★ by '.$r->reviewer_name.($r->title ? ' – '.$r->title : ''), 160, '…'),
            'details' => $r->body,
        ]);
    }

    /**
     * CSV import. Columns (header row, any order): source, review_id, reviewer, location, date, rating, scale, title, review, url, trip_type.
     * @return array{imported:int, duplicates:int, errors:array<int,string>}
     */
    public static function importCsv(string $path, string $defaultSource): array
    {
        $fh = fopen($path, 'r');
        $head = $fh ? fgetcsv($fh, 0, ',', '"', '') : false;
        if (! $head) {
            throw ValidationException::withMessages(['file' => 'The file is empty or not a CSV.']);
        }
        $alias = ['review_id' => 'external_id', 'id' => 'external_id', 'external_id' => 'external_id', 'reviewer' => 'reviewer_name', 'name' => 'reviewer_name', 'reviewer_name' => 'reviewer_name',
            'location' => 'reviewer_location', 'date' => 'review_date', 'review_date' => 'review_date', 'rating' => 'rating', 'score' => 'rating', 'scale' => 'rating_scale', 'out_of' => 'rating_scale',
            'title' => 'title', 'review' => 'body', 'text' => 'body', 'body' => 'body', 'comment' => 'body', 'url' => 'url', 'link' => 'url', 'trip_type' => 'trip_type', 'source' => 'source', 'site' => 'source'];
        $cols = array_map(fn ($h) => $alias[strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h)))] ?? null, $head);
        foreach (['reviewer_name', 'review_date', 'rating', 'body'] as $req) {
            if (! in_array($req, $cols, true)) {
                throw ValidationException::withMessages(['file' => 'Missing column: '.['reviewer_name' => 'reviewer', 'review_date' => 'date', 'rating' => 'rating', 'body' => 'review'][$req].'. Expected: source, review_id, reviewer, date, rating, scale, title, review, url.']);
            }
        }
        $out = ['imported' => 0, 'duplicates' => 0, 'errors' => []];
        $line = 1;
        while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            $line++;
            if ($line > 1001) {
                $out['errors'][$line] = 'Stopped after 1,000 rows – split the file.';
                break;
            }
            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $d = [];
            foreach ($cols as $i => $c) {
                if ($c) {
                    $d[$c] = trim((string) ($row[$i] ?? ''));
                }
            }
            $d['source'] = strtolower(str_replace(['.com', ' ', '-'], '', $d['source'] ?? '')) ?: $defaultSource;
            if (! isset(OnlineReview::SOURCES[$d['source']])) {
                $out['errors'][$line] = "Unknown source \"{$d['source']}\".";

                continue;
            }
            $d['rating_scale'] = (int) ($d['rating_scale'] ?? 0) ?: ($d['source'] === 'booking' ? 10 : 5);
            $date = self::parseDate($d['review_date'] ?? '');
            $v = validator($d + ['review_date_parsed' => $date?->toDateString()], [
                'reviewer_name' => 'required|string|max:120', 'review_date_parsed' => 'required|date|before_or_equal:today', 'rating_scale' => 'in:5,10',
                'rating' => 'required|numeric|min:1|max:'.($d['rating_scale'] === 10 ? 10 : 5), 'body' => 'required|string|max:10000', 'title' => 'nullable|string|max:200',
                'external_id' => 'nullable|string|max:80', 'url' => 'nullable|url|max:500', 'reviewer_location' => 'nullable|string|max:120', 'trip_type' => 'nullable|in:'.implode(',', array_keys(OnlineReview::TRIP_TYPES)),
            ], ['review_date_parsed.required' => 'Date missing or not understood (use YYYY-MM-DD or DD/MM/YYYY).', 'review_date_parsed.before_or_equal' => 'Date is in the future.']);
            if ($v->fails()) {
                $out['errors'][$line] = $v->errors()->first();

                continue;
            }
            $d['review_date'] = $date->toDateString();
            $d = array_map(fn ($x) => $x === '' ? null : $x, $d);
            [, $created] = self::store($d);
            $created ? $out['imported']++ : $out['duplicates']++;
        }
        fclose($fh);
        ActivityLogger::log('feedback', 'review_import', "Imported {$out['imported']} online review(s), {$out['duplicates']} duplicate(s), ".count($out['errors']).' error(s)');

        return $out;
    }

    /** Strict date parsing: the value must round-trip through one of the accepted formats. */
    public static function parseDate(string $s): ?Carbon
    {
        $s = trim($s);
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'j M Y', 'd M Y', 'M j, Y', 'Y-m-d H:i:s'] as $f) {
            try {
                $d = Carbon::createFromFormat('!'.$f, $s);
            } catch (\Throwable) {
                continue;
            }
            if ($d && $d->format($f) === $s) {
                return $d->startOfDay();
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ responses

    protected static function listJoin(array $items): string
    {
        return count($items) > 1 ? implode(', ', array_slice($items, 0, -1)).' and '.end($items) : (string) ($items[0] ?? '');
    }

    public static function render(ReviewTemplate $t, OnlineReview $r): string
    {
        $asp = (array) $r->aspects;
        $liked = self::listJoin(array_values(array_map(fn ($d) => self::PHRASES[$d], array_keys(array_filter($asp, fn ($s, $d) => in_array($s, ['positive', 'mixed']) && isset(self::PHRASES[$d]), ARRAY_FILTER_USE_BOTH)))));
        $disliked = self::listJoin(array_values(array_map(fn ($d) => self::PHRASES[$d], array_keys(array_filter($asp, fn ($s, $d) => in_array($s, ['negative', 'mixed']) && isset(self::PHRASES[$d]), ARRAY_FILTER_USE_BOTH)))));
        $first = trim(explode(' ', trim($r->reviewer_name))[0]) ?: 'Guest';

        return strtr($t->body, ['{reviewer}' => $first, '{hotel}' => hotel()->name, '{signature}' => (string) setting('fb_signature', 'Guest Relations Manager'),
            '{email}' => hotel()->email ?: 'our front office', '{liked}' => $liked ?: 'your stay', '{disliked}' => $disliked ?: 'some aspects of your stay', '{source}' => $r->sourceName()]);
    }

    public static function suggestedTemplate(OnlineReview $r): ?ReviewTemplate
    {
        $s = (float) $r->rating5 >= 4 ? 'positive' : ((float) $r->rating5 <= 2.5 ? 'negative' : 'neutral');

        return ReviewTemplate::where('is_active', true)->where('sentiment', $s)->orderBy('id')->first()
            ?? ReviewTemplate::where('is_active', true)->where('sentiment', 'any')->orderBy('id')->first();
    }

    public static function needsApproval(OnlineReview $r): bool
    {
        return setting('fb_review_approval', '1') === '1' && (float) $r->rating5 <= 3;
    }

    protected static function assertEditable(OnlineReview $r): void
    {
        if ($r->isLocked()) {
            throw ValidationException::withMessages(['response_text' => 'This review is closed ('.strtolower(OnlineReview::STATUSES[$r->status]).').']);
        }
    }

    protected static function checkText(string $text): string
    {
        $text = trim($text);
        if (mb_strlen($text) < 20) {
            throw ValidationException::withMessages(['response_text' => 'Write a response of at least 20 characters.']);
        }
        if (mb_strlen($text) > self::MAX_RESPONSE) {
            throw ValidationException::withMessages(['response_text' => 'Keep the response under '.number_format(self::MAX_RESPONSE).' characters.']);
        }
        if (preg_match('/\{[a-z_]+\}/', $text)) {
            throw ValidationException::withMessages(['response_text' => 'Replace the remaining {placeholders} first.']);
        }

        return $text;
    }

    public static function saveDraft(OnlineReview $r, string $text): void
    {
        self::assertEditable($r);
        $r->update(['response_text' => self::checkText($text), 'status' => 'draft', 'drafted_by' => auth()->id(), 'drafted_at' => now(), 'approved_by' => null, 'approved_at' => null]);
    }

    /** Save + send for approval (critical reviews) or mark ready to post. Returns the new status. */
    public static function submit(OnlineReview $r, string $text): string
    {
        self::saveDraft($r, $text);
        $status = self::needsApproval($r) ? 'pending' : 'approved';
        $r->update(['status' => $status, 'return_note' => null] + ($status === 'approved' ? ['approved_by' => auth()->id(), 'approved_at' => now()] : []));

        return $status;
    }

    public static function approve(OnlineReview $r): void
    {
        if ($r->status !== 'pending') {
            throw ValidationException::withMessages(['response_text' => 'Only responses awaiting approval can be approved.']);
        }
        if ($r->drafted_by === auth()->id()) {
            throw ValidationException::withMessages(['response_text' => 'You wrote this response – another manager must approve it.']);
        }
        $r->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
        ActivityLogger::log('feedback', 'review_approve', "Approved the response to {$r->sourceName()} review by {$r->reviewer_name}", $r);
    }

    public static function returnDraft(OnlineReview $r, string $note): void
    {
        if ($r->status !== 'pending') {
            throw ValidationException::withMessages(['return_note' => 'Only responses awaiting approval can be returned.']);
        }
        $r->update(['status' => 'draft', 'return_note' => $note]);
    }

    public static function markPosted(OnlineReview $r): void
    {
        if ($r->status !== 'approved') {
            throw ValidationException::withMessages(['response_text' => $r->status === 'pending' ? 'The response is still awaiting approval.' : 'Submit the response first.']);
        }
        $r->update(['status' => 'posted', 'posted_by' => auth()->id(), 'posted_at' => now()]);
        ActivityLogger::log('feedback', 'review_post', "Response posted on {$r->sourceName()} to {$r->reviewer_name}", $r);
    }

    public static function ignore(OnlineReview $r, string $reason): void
    {
        self::assertEditable($r);
        $r->update(['status' => 'ignored', 'ignore_reason' => $reason]);
    }

    public static function restore(OnlineReview $r): void
    {
        if ($r->status !== 'ignored') {
            throw ValidationException::withMessages(['review' => 'Only reviews marked "no response" can be restored.']);
        }
        $r->update(['status' => $r->response_text ? 'draft' : 'new', 'ignore_reason' => null]);
    }

    public static function linkToReservation(OnlineReview $r, ?string $resNo): void
    {
        if (! $resNo) {
            $r->update(['guest_id' => null, 'reservation_id' => null]);

            return;
        }
        $res = Reservation::where('reservation_no', trim($resNo))->first();
        if (! $res || ! $res->guest_id) {
            throw ValidationException::withMessages(['reservation_no' => 'No reservation with that number (and a guest profile) in this hotel.']);
        }
        $r->update(['guest_id' => $res->guest_id, 'reservation_id' => $res->id]);
        if ($c = $r->recoveryCase) {
            $c->update(['guest_id' => $res->guest_id, 'reservation_id' => $res->id]);
        }
    }

    public static function guestFor(?int $id): ?Guest
    {
        return $id ? Guest::find($id) : null;
    }
}
