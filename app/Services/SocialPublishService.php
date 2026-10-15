<?php

namespace App\Services;

use App\Models\DmChannel;
use App\Models\DmPost;
use Illuminate\Support\Facades\Http;

/**
 * v7.39 Social connectors — live posting for the content calendar and review-feed pulls.
 *
 * Facebook Page:  POST {graph}/v20.0/{page_id}/feed   {message, link}   Bearer {fb_token}
 * WhatsApp Cloud: POST {graph}/v20.0/{phone_id}/messages {messaging_product, to, type:text}
 * Review feeds:   GET  {review_feed}/reviews/{platform}  → rows for ReviewService::store()
 *
 * Where to post lives on the DmChannel record (handle = page id / phone-number id /
 * destination number); the access tokens live encrypted in MessageService secret 'social'
 * ({fb_token, wa_token, review_feed}). Point graph/review_feed at the local simulator
 * (deploy/dev/social-sim.mjs) for end-to-end tests; the graph base is
 * setting('social_graph_url', 'https://graph.facebook.com').
 *
 * Channels on other platforms (Instagram, X, LinkedIn …) are recorded-only: the post is
 * marked published with its URL exactly as before.
 */
class SocialPublishService
{
    public const LIVE = ['facebook', 'whatsapp'];

    public static function secrets(): array
    {
        $raw = MessageService::secret('social');
        if (is_array($raw)) {
            return $raw;
        }

        return is_string($raw) && $raw !== '' ? (json_decode($raw, true) ?: []) : [];
    }

    public static function graph(): string
    {
        return rtrim((string) setting('social_graph_url', 'https://graph.facebook.com'), '/');
    }

    public static function test(): array
    {
        $s = self::secrets();
        if (empty($s['fb_token']) && empty($s['wa_token'])) {
            return ['ok' => false, 'message' => 'No social tokens stored yet (MessageService secret “social” – see docs/SOCIAL-REVIEWS.md).'];
        }
        try {
            $res = Http::timeout(10)->withHeaders(['Authorization' => 'Bearer '.$s['fb_token']])->get(self::graph().'/v20.0/me', ['fields' => 'id,name']);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Graph endpoint unreachable: '.$e->getMessage()];
        }

        return $res->successful()
            ? ['ok' => true, 'message' => 'Graph API answered as '.($res->json('name') ?? $res->json('id') ?? 'app').'.']
            : ['ok' => false, 'message' => 'Graph API refused the token: HTTP '.$res->status().' – '.mb_substr($res->body(), 0, 140)];
    }

    /** Publish an approved post to every connected channel. Returns per-channel results. */
    public static function publish(DmPost $p): array
    {
        $out = [];
        foreach (DmChannel::whereIn('id', (array) $p->channels)->get() as $c) {
            $out[] = in_array($c->platform, self::LIVE, true)
                ? self::publishTo($c, $p)
                : ['channel' => $c->name, 'platform' => $c->platform, 'ok' => true, 'mode' => 'recorded', 'message' => 'Recorded only – post manually or via the platform’s own tools.'];
        }

        return $out;
    }

    public static function publishTo(DmChannel $c, DmPost $p): array
    {
        $s = self::secrets();
        $ref = ['channel' => $c->name, 'platform' => $c->platform, 'mode' => 'live'];
        try {
            if ($c->platform === 'facebook') {
                if (empty($s['fb_token']) || ! $c->handle) {
                    return $ref + ['ok' => false, 'message' => 'Facebook needs a page id on the channel (handle) and the fb_token secret.'];
                }
                $res = Http::timeout(20)->asForm()->post(self::graph().'/v20.0/'.rawurlencode($c->handle).'/feed', [
                    'message' => $p->caption ?: $p->title,
                    'link' => $p->post_url ?: null,
                    'access_token' => $s['fb_token'],
                ]);
            } elseif ($c->platform === 'whatsapp') {
                if (empty($s['wa_token']) || ! $c->handle) {
                    return $ref + ['ok' => false, 'message' => 'WhatsApp needs the phone-number id on the channel (handle) and the wa_token secret.'];
                }
                $res = Http::timeout(20)->asJson()->withHeaders(['Authorization' => 'Bearer '.$s['wa_token']])
                    ->post(self::graph().'/v20.0/'.rawurlencode($c->handle).'/messages', [
                        'messaging_product' => 'whatsapp',
                        'to' => (string) setting('social_wa_to', ''),
                        'type' => 'text',
                        'text' => ['body' => mb_substr(($p->caption ?: $p->title), 0, 4096)],
                    ]);
            } else {
                return $ref + ['ok' => true, 'mode' => 'recorded', 'message' => 'Recorded only.'];
            }
        } catch (\Throwable $e) {
            return $ref + ['ok' => false, 'message' => $e->getMessage()];
        }

        if (! $res->successful()) {
            return $ref + ['ok' => false, 'message' => 'HTTP '.$res->status().' – '.mb_substr($res->body(), 0, 160)];
        }
        $id = (string) ($res->json('id') ?? ($res->json('messages.0.id') ?? ''));
        if ($id !== '' && ! $p->post_url && $c->platform === 'facebook') {
            $p->forceFill(['post_url' => 'https://www.facebook.com/'.$id])->save();
        }

        return $ref + ['ok' => true, 'external_id' => $id, 'message' => 'Posted ('.($id ?: 'accepted').').'];
    }

    /**
     * Pull new reviews from a platform feed (or every configured platform).
     * Rows: {external_id, reviewer_name, reviewer_location, review_date, rating, rating_scale,
     *        title, body, url, trip_type}. Returns ['pulled', 'new', 'duplicates', 'errors'].
     */
    public static function pullReviews(?string $platform = null): array
    {
        $s = self::secrets();
        $feed = rtrim((string) ($s['review_feed'] ?? ''), '/');
        if ($feed === '') {
            throw new \RuntimeException('No review feed configured (MessageService secret “social” → review_feed – see docs/SOCIAL-REVIEWS.md).');
        }
        $platforms = $platform ? [$platform] : array_keys(\App\Models\OnlineReview::SOURCES);
        $out = ['pulled' => 0, 'new' => 0, 'duplicates' => 0, 'errors' => []];
        foreach ($platforms as $src) {
            if ($src === 'other') {
                continue;
            }
            try {
                $res = Http::timeout(20)->get($feed.'/reviews/'.rawurlencode($src));
                if (! $res->successful()) {
                    $out['errors'][] = $src.': HTTP '.$res->status();
                    continue;
                }
                foreach ((array) $res->json('reviews', []) as $row) {
                    $row['source'] = $src;
                    $row['rating_scale'] = $row['rating_scale'] ?? 5;
                    $out['pulled']++;
                    [, $isNew] = ReviewService::store($row);
                    $out[$isNew ? 'new' : 'duplicates']++;
                }
            } catch (\Throwable $e) {
                $out['errors'][] = $src.': '.$e->getMessage();
            }
        }
        if ($out['new']) {
            ActivityLogger::log('feedback', 'review_pull', "Pulled {$out['new']} new review(s) from the platform feeds ({$out['duplicates']} already seen)");
        }

        return $out;
    }
}
