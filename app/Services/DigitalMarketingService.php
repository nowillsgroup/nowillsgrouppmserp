<?php

namespace App\Services;

use App\Models\DmAdStat;
use App\Models\DmCampaign;
use App\Models\DmChannel;
use App\Models\DmChannelStat;
use App\Models\DmInfluencer;
use App\Models\DmLink;
use App\Models\DmLinkClick;
use App\Models\DmPost;
use App\Models\Hotel;
use App\Models\MarketingBudget;
use App\Models\PromoCode;
use App\Models\Reservation;
use App\Models\Setting;
use App\Models\User;
use App\Support\Numbering;
use App\Support\Tenant;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * v6.2 Digital marketing.
 *
 *  • Channels – social profiles, listings (Google Business, Tripadvisor, OTA pages, website) and ad accounts, with monthly KPI
 *    snapshots (followers, reach, impressions, engagements, clicks) and a listing-health checklist audit.
 *  • Content calendar – posts across one or more social channels: draft → review → approved → published. Four-eyes: an approver
 *    can never approve their own post. Publishing is recorded by hand (post URL); results are entered afterwards. No external APIs.
 *  • Paid media – campaigns with a UTM campaign tag, budget, target ROAS and daily results entered by hand or imported from the
 *    platform's CSV export (Meta Ads Manager / Google Ads headers recognised).
 *  • Tracked links – /go/{code} logs the click (device, referring site, keyed IP hash; bots and double clicks ignored), sets an
 *    attribution cookie and redirects with UTM tags; a booking-engine booking made within the window is credited to the link,
 *    and through it to its campaign. Revenue = room rate × nights, cancelled bookings excluded (same basis as CRM).
 *  • Influencers – proposed → agreed → hosted → delivered with fee, complimentary value, reach, engagements and attributed bookings.
 *
 * Paid-media spend and influencer fees feed the 'digital' line of the v6.1 marketing budget through spendByMonth().
 * Guest e-mail / SMS campaigns, segments, journeys, promo codes (CRM) and online reviews (Feedback) are not duplicated here.
 */
class DigitalMarketingService
{
    public const DEFAULTS = ['dm_require_approval' => '1', 'dm_attribution_days' => '30', 'dm_audit_days' => '90'];

    public const COOKIE = 'nw_dl';

    protected const BOT = '/bot|crawl|spider|slurp|facebookexternalhit|embedly|preview|curl|wget|python|headless|monitor/i';

    protected static array $memo = [];

    public static function flush(): void
    {
        self::$memo = [];
    }

    public static function ensureDefaults(): void
    {
        foreach (self::DEFAULTS as $k => $v) {
            Setting::firstOrCreate(['key' => $k], ['value' => $v]);
        }
        Setting::$version++;
        self::flush();
    }

    public static function cfg(): array
    {
        return self::$memo['cfg'.Tenant::id()] ??= [
            'require_approval' => setting('dm_require_approval', '1') === '1',
            'attribution_days' => (int) setting('dm_attribution_days', '30'),
            'audit_days' => (int) setting('dm_audit_days', '90'),
        ];
    }

    public static function saveSettings(array $d): void
    {
        $days = (int) ($d['attribution_days'] ?? -1);
        $audit = (int) ($d['audit_days'] ?? -1);
        if ($days < 1 || $days > 90) {
            throw new RuntimeException('The attribution window is between 1 and 90 days.');
        }
        if ($audit < 7 || $audit > 365) {
            throw new RuntimeException('The listing audit interval is between 7 and 365 days.');
        }
        foreach (['dm_require_approval' => ! empty($d['require_approval']) ? '1' : '0', 'dm_attribution_days' => (string) $days, 'dm_audit_days' => (string) $audit] as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        Setting::$version++;
        self::flush();
        ActivityLogger::log('digital', 'settings', 'Digital marketing settings updated');
    }

    // ------------------------------------------------------------------ permissions

    public static function canManage(?User $u): bool
    {
        return (bool) $u?->hasPermission('digital.manage');
    }

    public static function canContent(?User $u): bool
    {
        return $u && ($u->hasPermission('digital.content') || $u->hasPermission('digital.manage'));
    }

    public static function canApprove(?User $u): bool
    {
        return $u && ($u->hasPermission('digital.approve') || $u->hasPermission('digital.manage'));
    }

    protected static function need(bool $ok, string $msg = 'You do not have permission to do that.'): void
    {
        if (! $ok) {
            throw new RuntimeException($msg);
        }
    }

    protected static function url(?string $v, string $label, bool $required = false): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            self::need(! $required, "$label is required.");

            return null;
        }
        self::need((bool) preg_match('#^https?://[^\s/$.?\#].[^\s]*$#i', $v) && strlen($v) <= 500, "$label must be a full web address starting with http:// or https://.");

        return $v;
    }

    protected static function int($v, string $label, int $max = 2_000_000_000): int
    {
        if ($v === null || $v === '') {
            return 0;
        }
        self::need(is_numeric($v) && (float) $v >= 0 && (float) $v <= $max && floor((float) $v) == (float) $v, "$label must be a whole number of 0 or more.");

        return (int) $v;
    }

    protected static function money($v, string $label): float
    {
        if ($v === null || $v === '') {
            return 0.0;
        }
        self::need(is_numeric($v) && (float) $v >= 0 && (float) $v < 1e11, "$label must be an amount of 0 or more.");

        return round((float) $v, 2);
    }

    protected static function date($v, string $label, bool $required = true): ?Carbon
    {
        if ($v === null || $v === '') {
            self::need(! $required, "$label is required.");

            return null;
        }
        try {
            return Carbon::parse((string) $v)->startOfDay();
        } catch (\Throwable) {
            throw new RuntimeException("$label is not a valid date.");
        }
    }

    protected static function owner($id, User $u): ?int
    {
        if (! $id) {
            return null;
        }
        self::need(User::where('hotel_id', hotel()->id)->where('id', (int) $id)->where('is_active', true)->exists(), 'Choose an active user of this hotel as the owner.');

        return (int) $id;
    }

    // ------------------------------------------------------------------ channels

    public static function saveChannel(array $d, ?DmChannel $c, User $u): DmChannel
    {
        self::need(self::canManage($u));
        $name = trim((string) ($d['name'] ?? ''));
        self::need($name !== '' && mb_strlen($name) <= 100, 'Give the channel a name (up to 100 characters).');
        self::need(! DmChannel::where('name', $name)->when($c, fn ($q) => $q->where('id', '!=', $c->id))->exists(), 'A channel with that name already exists.');
        $platform = (string) ($d['platform'] ?? '');
        self::need(isset(DmChannel::PLATFORMS[$platform]), 'Choose the platform.');
        $kind = DmChannel::PLATFORMS[$platform][1];
        $rating = ($d['rating'] ?? '') === '' ? null : $d['rating'];
        if ($rating !== null) {
            self::need(is_numeric($rating) && $rating >= 0 && $rating <= 10, 'Ratings are between 0 and 10.');
        }
        $data = [
            'name' => $name, 'platform' => $platform, 'handle' => mb_substr(trim((string) ($d['handle'] ?? '')), 0, 100) ?: null,
            'url' => self::url($d['url'] ?? null, 'The profile address'), 'owner_id' => self::owner($d['owner_id'] ?? null, $u),
            'is_active' => array_key_exists('is_active', $d) ? (bool) $d['is_active'] : ($c->is_active ?? true),
            'notes' => trim((string) ($d['notes'] ?? '')) ?: null,
        ];
        if ($kind === 'listing') {
            $data += ['rating' => $rating, 'reviews' => ($d['reviews'] ?? '') === '' ? null : self::int($d['reviews'], 'Reviews')];
        }
        if (array_key_exists('followers', $d) && $d['followers'] !== '' && $d['followers'] !== null) {
            $data['followers'] = self::int($d['followers'], 'Followers');
        }
        $c ? $c->update($data) : $c = DmChannel::create($data);
        ActivityLogger::log('digital', 'channel', "Digital channel {$c->name} saved", $c);

        return $c;
    }

    public static function recordChannelStats(DmChannel $c, array $d, User $u): DmChannelStat
    {
        self::need(self::canContent($u));
        self::need((bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) ($d['month'] ?? '')), 'Choose the month.');
        $month = Carbon::parse($d['month'].'-01')->startOfDay();
        self::need($month->lte(bdate()->copy()->startOfMonth()), 'You cannot record figures for a future month.');
        $vals = [];
        foreach (['followers' => 'Followers', 'reach' => 'Reach', 'impressions' => 'Impressions', 'engagements' => 'Engagements', 'clicks' => 'Clicks'] as $k => $l) {
            $vals[$k] = self::int($d[$k] ?? 0, $l);
        }
        self::need(! $vals['impressions'] || $vals['reach'] <= $vals['impressions'], 'Reach cannot be higher than impressions.');
        $s = date_upsert(DmChannelStat::class, ['channel_id' => $c->id, 'month' => $month->toDateString()], $vals);
        if (! DmChannelStat::where('channel_id', $c->id)->whereDate('month', '>', $month->toDateString())->exists()) {
            $c->update(['followers' => $vals['followers']]);
        }
        ActivityLogger::log('digital', 'stats', "{$c->name}: figures for {$month->format('M Y')} recorded", $c);

        return $s;
    }

    public static function auditListing(DmChannel $c, array $checked, array $d, User $u): int
    {
        self::need(self::canContent($u));
        self::need($c->kind() === 'listing', 'Only listings and the website have a health audit.');
        $health = [];
        foreach (DmChannel::HEALTH_ITEMS as $k => $l) {
            $health[$k] = ! empty($checked[$k]);
        }
        $upd = ['health' => $health, 'audited_at' => now(), 'audited_by' => $u->id];
        if (($d['rating'] ?? '') !== '') {
            self::need(is_numeric($d['rating']) && $d['rating'] >= 0 && $d['rating'] <= 10, 'Ratings are between 0 and 10.');
            $upd['rating'] = $d['rating'];
        }
        if (($d['reviews'] ?? '') !== '') {
            $upd['reviews'] = self::int($d['reviews'], 'Reviews');
        }
        $c->update($upd);
        $score = $c->fresh()->healthScore();
        ActivityLogger::log('digital', 'audit', "{$c->name} listing audit: {$score}%", $c);

        return $score;
    }

    public static function auditDue(DmChannel $c): bool
    {
        return $c->is_active && $c->kind() === 'listing' && (! $c->audited_at || $c->audited_at->lt(now()->subDays(self::cfg()['audit_days'])));
    }

    // ------------------------------------------------------------------ content calendar

    protected static function mayEditPost(DmPost $p, User $u): bool
    {
        return self::canManage($u) || ($p->author_id === $u->id && self::canContent($u));
    }

    public static function savePost(array $d, ?DmPost $p, User $u): DmPost
    {
        if ($p) {
            self::need(in_array($p->status, ['draft', 'rejected'], true), 'Only drafts and posts with changes requested can be edited.');
            self::need(self::mayEditPost($p, $u), 'Only the author or a digital marketing manager can edit this post.');
        } else {
            self::need(self::canContent($u));
        }
        $title = trim((string) ($d['title'] ?? ''));
        self::need($title !== '' && mb_strlen($title) <= 150, 'Give the post a working title (up to 150 characters).');
        $caption = trim((string) ($d['caption'] ?? ''));
        self::need($caption !== '', 'Write the caption.');
        self::need(mb_strlen($caption) <= 2200, 'Captions are limited to 2,200 characters.');
        $ids = array_values(array_unique(array_map('intval', (array) ($d['channels'] ?? []))));
        $chs = DmChannel::whereIn('id', $ids ?: [0])->where('is_active', true)->get();
        self::need($ids && $chs->count() === count($ids), 'Choose at least one active channel.');
        self::need($chs->every(fn ($c) => $c->kind() === 'social'), 'Posts go on social channels – listings and ad accounts are managed separately.');
        if ($chs->contains('platform', 'x')) {
            self::need(mb_strlen($caption) <= 280, 'Posts on X are limited to 280 characters – shorten the caption or post to X separately.');
        }
        try {
            $at = Carbon::parse((string) ($d['scheduled_at'] ?? ''));
        } catch (\Throwable) {
            throw new RuntimeException('Choose when the post goes out.');
        }
        self::need(! empty($d['scheduled_at']), 'Choose when the post goes out.');
        self::need($at->gt(now()), 'The publishing slot must be in the future.');
        $campaign = ! empty($d['campaign_id']) ? DmCampaign::find($d['campaign_id']) : null;
        self::need(empty($d['campaign_id']) || $campaign, 'Choose a campaign of this hotel.');
        $link = ! empty($d['link_id']) ? DmLink::find($d['link_id']) : null;
        self::need(empty($d['link_id']) || $link, 'Choose a tracked link of this hotel.');
        $pillar = ($d['pillar'] ?? '') ?: null;
        self::need(! $pillar || isset(DmPost::PILLARS[$pillar]), 'Choose a content pillar.');
        $data = ['title' => $title, 'caption' => $caption, 'channels' => $chs->pluck('id')->all(), 'scheduled_at' => $at, 'campaign_id' => $campaign?->id, 'link_id' => $link?->id,
            'media_url' => self::url($d['media_url'] ?? null, 'The media link'), 'pillar' => $pillar];
        if ($p) {
            $p->update($data + ['status' => 'draft']);
        } else {
            $p = DmPost::create($data + ['status' => 'draft', 'author_id' => $u->id]);
        }
        ActivityLogger::log('digital', 'post', "Post “{$p->title}” saved for {$at->format('d M H:i')}", $p);

        return $p;
    }

    public static function submitPost(DmPost $p, User $u): string
    {
        self::need(in_array($p->status, ['draft', 'rejected'], true), 'Only drafts can be sent for approval.');
        self::need(self::mayEditPost($p, $u), 'Only the author or a digital marketing manager can submit this post.');
        self::need($p->scheduled_at->gt(now()), 'The publishing slot has passed – choose a new time first.');
        if (! self::cfg()['require_approval']) {
            $p->update(['status' => 'approved', 'submitted_at' => now(), 'reviewer_id' => $u->id, 'reviewed_at' => now(), 'review_note' => null]);
            ActivityLogger::log('digital', 'post', "Post “{$p->title}” scheduled (approval not required)", $p);

            return 'Post scheduled – approval is switched off.';
        }
        $p->update(['status' => 'review', 'submitted_at' => now(), 'reviewer_id' => null, 'reviewed_at' => null]);
        ActivityLogger::log('digital', 'post', "Post “{$p->title}” sent for approval", $p);

        return 'Post sent for approval.';
    }

    public static function reviewPost(DmPost $p, string $decision, ?string $note, User $u): string
    {
        self::need(self::canApprove($u), 'You are not an approver for social content.');
        self::need($p->status === 'review', 'This post is not awaiting approval.');
        self::need($p->author_id !== $u->id, 'You cannot approve your own post – another approver must review it.');
        self::need(in_array($decision, ['approve', 'reject'], true), 'Approve or request changes.');
        $note = trim((string) $note);
        self::need($decision === 'approve' || $note !== '', 'Say what needs to change.');
        self::need(mb_strlen($note) <= 500, 'Keep the note under 500 characters.');
        $p->update(['status' => $decision === 'approve' ? 'approved' : 'rejected', 'reviewer_id' => $u->id, 'reviewed_at' => now(), 'review_note' => $note ?: null]);
        ActivityLogger::log('digital', 'review', "Post “{$p->title}” ".($decision === 'approve' ? 'approved' : 'returned for changes'), $p);

        return $decision === 'approve' ? 'Post approved.' : 'Changes requested – the author has been sent back the post.';
    }

    public static function publishPost(DmPost $p, ?string $url, User $u): void
    {
        self::need(self::canContent($u));
        self::need($p->status === 'approved', 'Only approved posts can be marked as published.');
        $p->update(['status' => 'published', 'published_at' => now(), 'post_url' => self::url($url, 'The post link')]);
        $log = \App\Services\SocialPublishService::publish($p->fresh()); // v7.39 live Facebook / WhatsApp delivery
        $p->update(['publish_log' => $log]);
        $live = collect($log)->where('mode', 'live');
        ActivityLogger::log('digital', 'publish', "Post “{$p->title}” published".($live->count()
            ? ' – '.implode(', ', $live->map(fn ($r) => $r['channel'].': '.($r['ok'] ? 'posted' : 'FAILED'))->all()) : ''), $p);
    }

    public static function recordPostStats(DmPost $p, array $d, User $u): void
    {
        self::need(self::canContent($u));
        self::need($p->status === 'published', 'Results can only be recorded for published posts.');
        $v = ['reach' => self::int($d['reach'] ?? 0, 'Reach'), 'engagements' => self::int($d['engagements'] ?? 0, 'Engagements'), 'clicks' => self::int($d['clicks'] ?? 0, 'Clicks')];
        if (! empty($d['post_url'])) {
            $v['post_url'] = self::url($d['post_url'], 'The post link');
        }
        $p->update($v);
    }

    public static function cancelPost(DmPost $p, User $u): void
    {
        self::need(! in_array($p->status, ['published', 'cancelled'], true), 'Published or cancelled posts cannot be cancelled.');
        self::need(self::mayEditPost($p, $u) || self::canApprove($u), 'Only the author, an approver or a manager can cancel this post.');
        $p->update(['status' => 'cancelled']);
        ActivityLogger::log('digital', 'post', "Post “{$p->title}” cancelled", $p);
    }

    /** 6-week grid (Monday first) with the month's posts on each day */
    public static function calendar(Carbon $month, ?string $status = null): array
    {
        $start = $month->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY);
        $end = $start->copy()->addDays(41)->endOfDay();
        $posts = DmPost::whereBetween('scheduled_at', [$start, $end])->where('status', '!=', 'cancelled')
            ->when($status, fn ($q) => $q->where('status', $status))->orderBy('scheduled_at')->get()->groupBy(fn ($p) => $p->scheduled_at->toDateString());
        $days = [];
        for ($i = 0; $i < 42; $i++) {
            $d = $start->copy()->addDays($i);
            $days[] = ['date' => $d, 'in' => $d->month === $month->month, 'posts' => $posts[$d->toDateString()] ?? collect()];
        }

        return $days;
    }

    // ------------------------------------------------------------------ paid media

    public static function saveCampaign(array $d, ?DmCampaign $c, User $u): DmCampaign
    {
        self::need(self::canManage($u));
        self::need(! $c || $c->status !== 'ended', 'Ended campaigns cannot be changed.');
        $name = trim((string) ($d['name'] ?? ''));
        self::need($name !== '' && mb_strlen($name) <= 150, 'Name the campaign (up to 150 characters).');
        $obj = (string) ($d['objective'] ?? '');
        self::need(isset(DmCampaign::OBJECTIVES[$obj]), 'Choose the objective.');
        $channel = ! empty($d['channel_id']) ? DmChannel::find($d['channel_id']) : null;
        self::need(empty($d['channel_id']) || $channel, 'Choose a channel of this hotel.');
        $tag = strtolower(trim((string) ($d['utm_campaign'] ?? ''))) ?: Str::limit(Str::slug($name, '-'), 60, '');
        self::need((bool) preg_match('/^[a-z0-9][a-z0-9_-]{1,59}$/', $tag), 'The UTM campaign tag uses lower-case letters, digits, - and _ (2–60 characters).');
        self::need(! DmCampaign::where('utm_campaign', $tag)->when($c, fn ($q) => $q->where('id', '!=', $c->id))->exists(), "Another campaign already uses the tag “{$tag}”.");
        $start = self::date($d['start_date'] ?? null, 'The start date');
        $end = self::date($d['end_date'] ?? null, 'The end date', false);
        self::need(! $end || $end->gte($start), 'The campaign cannot end before it starts.');
        if ($c && $c->adStats()->exists()) {
            self::need($start->lte($c->adStats()->min('date')), 'Results are already recorded before that start date.');
        }
        $promo = ! empty($d['promo_code_id']) ? PromoCode::find($d['promo_code_id']) : null;
        self::need(empty($d['promo_code_id']) || $promo, 'Choose a CRM promo code of this hotel.');
        $roas = ($d['target_roas'] ?? '') === '' ? null : $d['target_roas'];
        self::need($roas === null || (is_numeric($roas) && $roas >= 0 && $roas <= 1000), 'The target ROAS is a multiple between 0 and 1,000.');
        $data = ['name' => $name, 'objective' => $obj, 'channel_id' => $channel?->id, 'utm_campaign' => $tag, 'promo_code_id' => $promo?->id, 'start_date' => $start, 'end_date' => $end,
            'budget' => self::money($d['budget'] ?? 0, 'The budget'), 'target_roas' => $roas, 'owner_id' => self::owner($d['owner_id'] ?? null, $u) ?? $c?->owner_id ?? $u->id,
            'notes' => trim((string) ($d['notes'] ?? '')) ?: null];
        if ($c) {
            $old = $c->utm_campaign;
            $c->update($data);
            if ($old !== $tag) { // keep links tagged with the old value in step
                DmLink::where('campaign_id', $c->id)->where('utm_campaign', $old)->update(['utm_campaign' => $tag]);
            }
        } else {
            $c = DmCampaign::create($data + ['number' => Numbering::next('dm_campaign'), 'status' => 'draft']);
        }
        ActivityLogger::log('digital', 'campaign', "Digital campaign {$c->number} {$c->name} saved", $c);

        return $c;
    }

    public static function setCampaignStatus(DmCampaign $c, string $to, User $u): void
    {
        self::need(self::canManage($u));
        $allowed = ['draft' => ['active', 'ended'], 'active' => ['paused', 'ended'], 'paused' => ['active', 'ended'], 'ended' => []][$c->status] ?? [];
        self::need(in_array($to, $allowed, true), 'A '.strtolower($c->statusLabel()).' campaign cannot be set to '.(DmCampaign::STATUSES[$to][0] ?? $to).'.');
        $upd = ['status' => $to];
        if ($to === 'ended' && (! $c->end_date || $c->end_date->gt(bdate()))) {
            $upd['end_date'] = max(bdate()->toDateString(), $c->start_date->toDateString());
        }
        $c->update($upd);
        ActivityLogger::log('digital', 'campaign', "Digital campaign {$c->number} is now {$to}", $c);
    }

    public static function saveAdStat(DmCampaign $c, array $d, User $u, string $source = 'manual'): array
    {
        self::need(self::canManage($u));
        self::need($c->status !== 'draft', 'Activate the campaign before recording results.');
        $date = self::date($d['date'] ?? null, 'The date');
        self::need($date->gte($c->start_date), 'That date is before the campaign started.');
        self::need($date->lte(bdate()), 'You cannot record results for a future date.');
        $v = ['impressions' => self::int($d['impressions'] ?? 0, 'Impressions'), 'clicks' => self::int($d['clicks'] ?? 0, 'Clicks'), 'spend' => self::money($d['spend'] ?? 0, 'Spend'),
            'conversions' => self::int($d['conversions'] ?? 0, 'Conversions'), 'conversion_value' => self::money($d['conversion_value'] ?? 0, 'Conversion value'), 'source' => $source];
        self::need(! $v['impressions'] || $v['clicks'] <= $v['impressions'], 'Clicks cannot exceed impressions.');
        $existing = DmAdStat::where('campaign_id', $c->id)->whereDate('date', $date->toDateString())->first();
        $existing ? $existing->update($v) : DmAdStat::create($v + ['campaign_id' => $c->id, 'date' => $date->toDateString()]);

        return ['created' => ! $existing];
    }

    public static function deleteAdStat(DmAdStat $s, User $u): void
    {
        self::need(self::canManage($u));
        self::need($s->campaign->status !== 'ended', 'Results of an ended campaign are locked.');
        $s->delete();
    }

    /** header aliases found in Meta Ads Manager, Google Ads and TikTok exports */
    public const CSV_ALIASES = [
        'date' => ['date', 'day', 'reporting starts', 'reporting start', 'date start', 'start date', 'by day'],
        'impressions' => ['impressions', 'impr.', 'impr'],
        'clicks' => ['clicks', 'link clicks', 'clicks (all)', 'clicks (destination)'],
        'spend' => ['spend', 'cost', 'amount spent', 'amount spent (ghs)', 'amount spent (usd)', 'cost (ghs)', 'total cost'],
        'conversions' => ['conversions', 'results', 'purchases', 'website purchases', 'all conv.', 'bookings'],
        'conversion_value' => ['conv. value', 'conversion value', 'purchases conversion value', 'website purchases conversion value', 'total conv. value', 'all conv. value', 'revenue', 'purchase value'],
    ];

    /** @return array{imported:int, updated:int, skipped:int, errors:array} */
    public static function importCsv(DmCampaign $c, string $contents, User $u): array
    {
        self::need(self::canManage($u));
        self::need($c->status !== 'draft', 'Activate the campaign before importing results.');
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);
        $lines = preg_split('/\R/', trim($contents));
        self::need(count($lines) >= 2, 'The file has no data rows.');
        self::need(count($lines) <= 1001, 'Import up to 1,000 rows at a time.');
        $delim = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : (str_contains($lines[0], "\t") ? "\t" : ',');
        $map = null;
        $out = ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
        foreach ($lines as $n => $line) {
            $row = str_getcsv($line, $delim, '"', '\\');
            if ($map === null) { // look for the header row (exports often start with a title / date-range preamble)
                $h = array_map(fn ($x) => strtolower(trim((string) $x)), $row);
                $found = [];
                foreach (self::CSV_ALIASES as $field => $aliases) {
                    foreach ($h as $i => $col) {
                        if (in_array($col, $aliases, true) || ($field === 'spend' && (str_starts_with($col, 'amount spent') || str_starts_with($col, 'cost ('))) || ($field === 'conversion_value' && str_contains($col, 'conversion value'))) {
                            $found[$field] ??= $i;
                        }
                    }
                }
                if (isset($found['date'], $found['spend'])) {
                    $map = $found;
                }

                continue;
            }
            $cell = fn (string $f) => isset($map[$f]) ? trim((string) ($row[$map[$f]] ?? '')) : '';
            $rawDate = $cell('date');
            if ($rawDate === '' || preg_match('/^(total|--|grand total)/i', $rawDate)) {
                $out['skipped']++;

                continue;
            }
            $num = fn (string $f) => ($x = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $cell($f)))) === '' ? 0 : $x;
            try {
                if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $rawDate, $m)) {
                    $date = Carbon::createFromDate((int) $m[3], (int) $m[2], (int) $m[1])->toDateString(); // day/month/year (Ghana)
                } else {
                    $date = Carbon::parse($rawDate)->toDateString();
                }
                $r = self::saveAdStat($c, ['date' => $date, 'impressions' => $num('impressions'), 'clicks' => $num('clicks'), 'spend' => $num('spend'),
                    'conversions' => (int) round((float) $num('conversions')), 'conversion_value' => $num('conversion_value')], $u, 'csv');
                $out[$r['created'] ? 'imported' : 'updated']++;
            } catch (\Throwable $e) {
                $out['skipped']++;
                if (count($out['errors']) < 10) {
                    $out['errors'][] = 'Row '.($n + 1).': '.($e instanceof RuntimeException ? $e->getMessage() : 'unreadable date or number');
                }
            }
        }
        self::need($map !== null, 'No header row with a date and a spend / cost / amount spent column was found.');
        ActivityLogger::log('digital', 'import', "Ad results imported for {$c->number}: {$out['imported']} new, {$out['updated']} updated, {$out['skipped']} skipped", $c);

        return $out;
    }

    /** spend / delivery / conversion / attribution figures for a campaign */
    public static function campaignStats(DmCampaign $c, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $q = DmAdStat::where('campaign_id', $c->id)->when($from, fn ($x) => $x->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString()));
        $a = $q->selectRaw('COALESCE(SUM(impressions),0) i, COALESCE(SUM(clicks),0) c, COALESCE(SUM(spend),0) s, COALESCE(SUM(conversions),0) v, COALESCE(SUM(conversion_value),0) cv, MAX(date) last')->first();
        $linkIds = DmLink::where('campaign_id', $c->id)->pluck('id');
        $att = self::bookings($linkIds, $from, $to);
        $clicks = DmLinkClick::whereIn('link_id', $linkIds->all() ?: [0])->when($from, fn ($x) => $x->whereBetween('clicked_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]))->count();
        [$i, $cl, $s] = [(int) $a->i, (int) $a->c, round((float) $a->s, 2)];

        return ['impressions' => $i, 'clicks' => $cl, 'spend' => $s, 'conversions' => (int) $a->v, 'conversion_value' => round((float) $a->cv, 2), 'last' => $a->last,
            'ctr' => $i ? round(100 * $cl / $i, 2) : null, 'cpc' => $cl ? round($s / $cl, 2) : null, 'cpm' => $i ? round(1000 * $s / $i, 2) : null,
            'roas' => $s > 0 ? round((float) $a->cv / $s, 2) : null, 'link_clicks' => $clicks,
            'bookings' => $att['bookings'], 'nights' => $att['nights'], 'revenue' => $att['revenue'], 'pms_roas' => $s > 0 ? round($att['revenue'] / $s, 2) : null,
            'cpa' => $att['bookings'] ? round($s / $att['bookings'], 2) : null,
            'pacing' => (float) $c->budget > 0 ? round(100 * $s / (float) $c->budget, 1) : null];
    }

    // ------------------------------------------------------------------ tracked links

    public static function saveLink(array $d, ?DmLink $l, User $u): DmLink
    {
        self::need(self::canContent($u));
        $name = trim((string) ($d['name'] ?? ''));
        self::need($name !== '' && mb_strlen($name) <= 120, 'Name the link (up to 120 characters), e.g. “Instagram bio – book direct”.');
        $dest = trim((string) ($d['destination'] ?? ''));
        if ($dest === '' || strtolower($dest) === 'booking') {
            $dest = route('booking.show', hotel()->code);
        } elseif (str_starts_with($dest, '/')) {
            $dest = url($dest);
        }
        self::url($dest, 'The destination', true);
        $campaign = ! empty($d['campaign_id']) ? DmCampaign::find($d['campaign_id']) : null;
        self::need(empty($d['campaign_id']) || $campaign, 'Choose a campaign of this hotel.');
        $tag = fn (string $k, string $label, bool $req) => (function () use ($d, $k, $label, $req) {
            $v = strtolower(trim((string) ($d[$k] ?? '')));
            if ($v === '') {
                self::need(! $req, "$label is required.");

                return null;
            }
            self::need((bool) preg_match('/^[a-z0-9][a-z0-9._-]{0,59}$/', $v), "$label uses lower-case letters, digits, . - and _ only.");

            return $v;
        })();
        $utmCampaign = $tag('utm_campaign', 'utm_campaign', false) ?? $campaign?->utm_campaign;
        self::need((bool) $utmCampaign, 'utm_campaign is required (or choose a campaign).');
        $code = strtolower(trim((string) ($d['code'] ?? '')));
        if ($code !== '') {
            self::need((bool) preg_match('/^[a-z0-9][a-z0-9-]{2,39}$/', $code), 'The short code uses lower-case letters, digits and - (3–40 characters).');
        } elseif ($l) {
            $code = $l->code;
        } else {
            do {
                $code = trim(Str::limit(Str::slug($name), 24, ''), '-').'-'.Str::lower(Str::random(4));
            } while (DmLink::withoutGlobalScopes()->where('code', $code)->exists());
        }
        self::need(! DmLink::withoutGlobalScopes()->where('code', $code)->when($l, fn ($q) => $q->where('id', '!=', $l->id))->exists(), "The short code “{$code}” is taken.");
        self::need(! $l || $l->code === $code || $l->clicks === 0, 'This link has already been shared and clicked – its short code can no longer change.');
        $data = ['name' => $name, 'destination' => $dest, 'utm_source' => $tag('utm_source', 'utm_source', true), 'utm_medium' => $tag('utm_medium', 'utm_medium', true),
            'utm_campaign' => $utmCampaign, 'utm_content' => $tag('utm_content', 'utm_content', false), 'campaign_id' => $campaign?->id, 'code' => $code];
        if ($l) {
            $l->update($data);
        } else {
            $l = DmLink::create($data + ['created_by' => $u->id, 'is_active' => true]);
        }
        ActivityLogger::log('digital', 'link', "Tracked link /go/{$l->code} saved", $l);

        return $l;
    }

    public static function toggleLink(DmLink $l, User $u): void
    {
        self::need(self::canContent($u));
        $l->update(['is_active' => ! $l->is_active]);
    }

    /** destination + UTM tags (+ the link code when it points at the booking engine) */
    public static function buildUrl(DmLink $l): string
    {
        $p = parse_url($l->destination);
        parse_str($p['query'] ?? '', $q);
        $q = array_merge($q, array_filter(['utm_source' => $l->utm_source, 'utm_medium' => $l->utm_medium, 'utm_campaign' => $l->utm_campaign, 'utm_content' => $l->utm_content]));
        if (str_starts_with($p['path'] ?? '', '/book/')) {
            $q['dl'] = $l->code;
        }

        return ($p['scheme'] ?? 'https').'://'.($p['host'] ?? '').(isset($p['port']) ? ':'.$p['port'] : '').($p['path'] ?? '/').'?'.http_build_query($q).(isset($p['fragment']) ? '#'.$p['fragment'] : '');
    }

    public static function device(string $ua): string
    {
        return preg_match('/ipad|tablet|kindle|playbook/i', $ua) ? 'tablet' : (preg_match('/mobi|android|iphone|ipod|opera mini/i', $ua) ? 'mobile' : 'desktop');
    }

    /** public /go/{code}: log the click (not bots / repeats), set the attribution cookie, redirect */
    public static function click(string $code, Request $r)
    {
        $l = DmLink::withoutGlobalScopes()->where('code', strtolower($code))->first();
        abort_unless($l, 404);
        $hotel = Hotel::where('id', $l->hotel_id)->where('is_active', true)->first();
        abort_unless($hotel, 404);
        Tenant::set($hotel);
        if (! $l->is_active) {
            return redirect()->away($l->destination); // printed QR codes keep working, but nothing is tracked
        }
        $ua = (string) $r->userAgent();
        $human = $ua !== '' && ! preg_match(self::BOT, $ua);
        if ($human) {
            $hash = hash_hmac('sha256', (string) $r->ip(), (string) config('app.key'));
            if (! DmLinkClick::where('link_id', $l->id)->where('ip_hash', $hash)->where('clicked_at', '>=', now()->subSeconds(30))->exists()) {
                $ref = parse_url((string) $r->headers->get('referer'), PHP_URL_HOST);
                DmLinkClick::create(['link_id' => $l->id, 'clicked_at' => now(), 'device' => self::device($ua), 'referrer_host' => $ref ? mb_substr($ref, 0, 100) : null, 'ip_hash' => $hash]);
                DmLink::withoutGlobalScopes()->whereKey($l->id)->update(['clicks' => DB::raw('clicks + 1'), 'last_click_at' => now()]);
            }
        }
        $resp = redirect()->away(self::buildUrl($l));

        return $human ? $resp->withCookie(cookie(self::COOKIE, $l->code, self::cfg()['attribution_days'] * 1440)) : $resp;
    }

    /** credit a booking-engine booking to the tracked link carried in the URL (dl=) or the attribution cookie */
    public static function attributeBooking(Reservation $res, ?string $code): ?DmLink
    {
        $code = strtolower(trim((string) $code));
        if ($code === '' || $res->dm_link_id || ! preg_match('/^[a-z0-9-]{3,40}$/', $code)) {
            return null;
        }
        $l = DmLink::where('code', $code)->first(); // tenant-scoped: another hotel's link is never credited
        if (! $l) {
            return null;
        }
        Reservation::whereKey($res->id)->update(['dm_link_id' => $l->id]);
        $res->dm_link_id = $l->id;
        ActivityLogger::log('digital', 'attribution', "Booking {$res->reservation_no} credited to tracked link /go/{$l->code}", $res);

        return $l;
    }

    /** bookings / nights / room revenue made through the given links (cancelled excluded) */
    public static function bookings($linkIds, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $ids = collect($linkIds)->all();

        return CrmService::bookingValue(Reservation::whereIn('dm_link_id', $ids ?: [0])
            ->when($from, fn ($q) => $q->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])));
    }

    public static function linkStats(DmLink $l, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $clicks = DmLinkClick::where('link_id', $l->id)->when($from, fn ($q) => $q->whereBetween('clicked_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]));
        $dev = (clone $clicks)->selectRaw('device, count(*) n')->groupBy('device')->pluck('n', 'device');
        $b = self::bookings([$l->id], $from, $to);
        $n = (clone $clicks)->count();

        return ['clicks' => $n, 'unique' => (clone $clicks)->distinct('ip_hash')->count('ip_hash'), 'devices' => $dev->all(),
            'referrers' => (clone $clicks)->whereNotNull('referrer_host')->selectRaw('referrer_host, count(*) n')->groupBy('referrer_host')->orderByDesc('n')->limit(5)->pluck('n', 'referrer_host')->all(),
            'bookings' => $b['bookings'], 'nights' => $b['nights'], 'revenue' => $b['revenue'], 'conversion' => $n ? round(100 * $b['bookings'] / $n, 2) : null];
    }

    // ------------------------------------------------------------------ influencers

    public static function saveInfluencer(array $d, ?DmInfluencer $i, User $u): DmInfluencer
    {
        self::need(self::canManage($u));
        self::need(! $i || ! in_array($i->status, ['delivered', 'cancelled'], true), 'Closed collaborations cannot be changed.');
        $name = trim((string) ($d['name'] ?? ''));
        self::need($name !== '' && mb_strlen($name) <= 120, 'Enter the creator’s name.');
        $platform = (string) ($d['platform'] ?? '');
        self::need(isset(DmChannel::PLATFORMS[$platform]) && DmChannel::PLATFORMS[$platform][1] === 'social', 'Choose the creator’s main social platform.');
        $handle = trim((string) ($d['handle'] ?? ''));
        self::need($handle !== '' && mb_strlen($handle) <= 100, 'Enter the creator’s handle.');
        $email = trim((string) ($d['email'] ?? ''));
        self::need($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL), 'Enter a valid e-mail address.');
        $from = self::date($d['stay_from'] ?? null, 'The stay start', false);
        $to = self::date($d['stay_to'] ?? null, 'The stay end', false);
        self::need(! $from === ! $to, 'Give both stay dates, or neither.');
        self::need(! $from || $to->gte($from), 'The stay cannot end before it starts.');
        $due = self::date($d['due_date'] ?? null, 'The content due date', false);
        self::need(! $due || ! $to || $due->gte($to), 'Content cannot be due before the stay ends.');
        $res = ! empty($d['reservation_id']) ? Reservation::find($d['reservation_id']) : null;
        self::need(empty($d['reservation_id']) || $res, 'Choose a reservation of this hotel.');
        $link = ! empty($d['link_id']) ? DmLink::find($d['link_id']) : null;
        self::need(empty($d['link_id']) || $link, 'Choose a tracked link of this hotel.');
        $data = ['name' => $name, 'platform' => $platform, 'handle' => $handle, 'followers' => self::int($d['followers'] ?? 0, 'Followers'), 'niche' => mb_substr(trim((string) ($d['niche'] ?? '')), 0, 60) ?: null,
            'email' => $email ?: null, 'phone' => mb_substr(trim((string) ($d['phone'] ?? '')), 0, 30) ?: null, 'stay_from' => $from, 'stay_to' => $to, 'due_date' => $due,
            'reservation_id' => $res?->id, 'link_id' => $link?->id, 'deliverables' => trim((string) ($d['deliverables'] ?? '')) ?: null,
            'fee' => self::money($d['fee'] ?? 0, 'The fee'), 'comp_value' => self::money($d['comp_value'] ?? 0, 'The complimentary value'),
            'owner_id' => self::owner($d['owner_id'] ?? null, $u) ?? $i?->owner_id ?? $u->id, 'notes' => trim((string) ($d['notes'] ?? '')) ?: null];
        if ($i) {
            $i->update($data);
        } else {
            $i = DmInfluencer::create($data + ['number' => Numbering::next('dm_influencer'), 'status' => 'proposed']);
        }
        ActivityLogger::log('digital', 'influencer', "Influencer collaboration {$i->number} {$i->name} saved", $i);

        return $i;
    }

    public static function setInfluencerStatus(DmInfluencer $i, string $to, array $d, User $u): void
    {
        self::need(self::canManage($u));
        $allowed = ['proposed' => ['agreed', 'cancelled'], 'agreed' => ['hosted', 'cancelled'], 'hosted' => ['delivered'], 'delivered' => [], 'cancelled' => []][$i->status] ?? [];
        self::need(in_array($to, $allowed, true), 'A '.strtolower($i->statusLabel()).' collaboration cannot move to '.strtolower(DmInfluencer::STATUSES[$to][0] ?? $to).'.');
        $upd = ['status' => $to];
        if ($to === 'agreed') {
            self::need($i->deliverables && $i->stay_from && $i->due_date, 'Agree the deliverables, stay dates and content due date first.');
        }
        if ($to === 'hosted') {
            self::need($i->stay_from && $i->stay_from->lte(bdate()), 'The stay has not started yet.');
        }
        if ($to === 'delivered') {
            self::applyResults($i, $d, $upd);
            self::need(count(array_filter(preg_split('/\R/', (string) $upd['content_urls']))) > 0, 'Add the link to at least one published piece of content.');
        }
        if ($to === 'cancelled') {
            $reason = trim((string) ($d['cancel_reason'] ?? ''));
            self::need($reason !== '', 'Give a reason for cancelling.');
            $upd['cancel_reason'] = mb_substr($reason, 0, 255);
        }
        $i->update($upd);
        ActivityLogger::log('digital', 'influencer', "Influencer collaboration {$i->number} is now {$to}", $i);
    }

    protected static function applyResults(DmInfluencer $i, array $d, array &$upd): void
    {
        $urls = array_key_exists('content_urls', $d) ? array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $d['content_urls'])))) : $i->urls();
        foreach ($urls as $x) {
            self::url($x, 'Each content link');
        }
        $upd['content_urls'] = implode("\n", $urls) ?: null;
        foreach (['reach' => 'Reach', 'engagements' => 'Engagements'] as $k => $l) {
            if (array_key_exists($k, $d) && $d[$k] !== '' && $d[$k] !== null) {
                $upd[$k] = self::int($d[$k], $l);
            }
        }
        if (($d['rating'] ?? '') !== '' && isset($d['rating'])) {
            self::need(in_array((int) $d['rating'], [1, 2, 3, 4, 5], true), 'Rate the collaboration from 1 to 5.');
            $upd['rating'] = (int) $d['rating'];
        }
    }

    public static function saveInfluencerResults(DmInfluencer $i, array $d, User $u): void
    {
        self::need(self::canContent($u));
        self::need(in_array($i->status, ['hosted', 'delivered'], true), 'Results are recorded once the creator has been hosted.');
        $upd = [];
        self::applyResults($i, $d, $upd);
        $i->update($upd);
    }

    public static function influencerStats(DmInfluencer $i): array
    {
        $b = $i->link_id ? self::bookings([$i->link_id]) : ['bookings' => 0, 'nights' => 0, 'revenue' => 0.0];
        $cost = $i->cost();

        return $b + ['cost' => $cost, 'cpe' => $i->engagements ? round($cost / $i->engagements, 2) : null, 'cpm' => $i->reach ? round(1000 * $cost / $i->reach, 2) : null,
            'engagement_rate' => $i->reach ? round(100 * $i->engagements / $i->reach, 2) : null, 'roi' => $cost > 0 ? round(100 * ($b['revenue'] - $cost) / $cost, 1) : null];
    }

    // ------------------------------------------------------------------ money & KPIs

    /** month => digital spend for the year: paid-media spend (by day) + influencer cash fees (by stay month, hosted / delivered). Feeds the marketing budget. */
    public static function spendByMonth(int $year): array
    {
        $out = [];
        foreach (DmAdStat::whereYear('date', $year)->selectRaw('MONTH(date) m, SUM(spend) s')->groupBy('m')->pluck('s', 'm') as $m => $s) {
            $out[(int) $m] = round(($out[(int) $m] ?? 0) + (float) $s, 2);
        }
        foreach (DmInfluencer::whereIn('status', ['hosted', 'delivered'])->whereYear('stay_from', $year)->where('fee', '>', 0)->selectRaw('MONTH(stay_from) m, SUM(fee) s')->groupBy('m')->pluck('s', 'm') as $m => $s) {
            $out[(int) $m] = round(($out[(int) $m] ?? 0) + (float) $s, 2);
        }
        ksort($out);

        return $out;
    }

    public static function alerts(): array
    {
        return self::$memo['alerts'.Tenant::id()] ??= (function () {
            $over = DmCampaign::where('status', 'active')->where('budget', '>', 0)->get()
                ->filter(fn ($c) => (float) DmAdStat::where('campaign_id', $c->id)->sum('spend') > (float) $c->budget)->count();

            return [
                'review' => DmPost::where('status', 'review')->count(),
                'missed' => DmPost::where('status', 'approved')->where('scheduled_at', '<', now()->subHour())->count(),
                'audits' => DmChannel::where('is_active', true)->get()->filter(fn ($c) => self::auditDue($c))->count(),
                'over_budget' => $over,
                'influencers' => DmInfluencer::where('status', 'hosted')->whereNotNull('due_date')->whereDate('due_date', '<', bdate()->toDateString())->count(),
            ];
        })();
    }

    /** nav badge: approvals waiting, missed slots, overdue creator content */
    public static function attention(): int
    {
        $a = self::alerts();

        return $a['review'] + $a['missed'] + $a['influencers'];
    }

    /** headline KPIs for a month */
    public static function kpis(Carbon $month): array
    {
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();
        $prev = $from->copy()->subMonthNoOverflow();
        $social = DmChannel::where('is_active', true)->get()->filter(fn ($c) => $c->kind() === 'social')->pluck('id');
        $st = fn (Carbon $m) => DmChannelStat::whereIn('channel_id', $social->all() ?: [0])->whereDate('month', $m->toDateString())
            ->selectRaw('COALESCE(SUM(followers),0) f, COALESCE(SUM(reach),0) r, COALESCE(SUM(impressions),0) i, COALESCE(SUM(engagements),0) e, COALESCE(SUM(clicks),0) c, COUNT(*) n')->first();
        [$now, $before] = [$st($from), $st($prev)];
        $spend = (float) DmAdStat::whereBetween('date', [$from->toDateString(), $to->toDateString()])->sum('spend');
        $fees = (float) DmInfluencer::whereIn('status', ['hosted', 'delivered'])->whereDate('stay_from', '>=', $from->toDateString())->whereDate('stay_from', '<=', $to->toDateString())->sum('fee');
        $budget = (float) MarketingBudget::where('year', $from->year)->where('month', $from->month)->where('line', 'digital')->sum('amount');
        $att = CrmService::bookingValue(Reservation::whereNotNull('dm_link_id')->whereBetween('created_at', [$from, $to]));
        $listings = DmChannel::where('is_active', true)->get()->filter(fn ($c) => $c->kind() === 'listing');
        $scores = $listings->map->healthScore()->filter(fn ($x) => $x !== null);
        $rated = $listings->filter(fn ($c) => $c->rating !== null && (float) $c->rating <= 5);

        return [
            'followers' => (int) $now->f, 'followers_prev' => (int) $before->f, 'growth' => $before->f > 0 ? round(100 * ($now->f - $before->f) / $before->f, 1) : null,
            'reach' => (int) $now->r, 'impressions' => (int) $now->i, 'engagements' => (int) $now->e, 'profile_clicks' => (int) $now->c,
            'engagement_rate' => $now->r > 0 ? round(100 * $now->e / $now->r, 2) : null, 'stats_missing' => max(0, $social->count() - (int) $now->n),
            'published' => DmPost::where('status', 'published')->whereBetween('published_at', [$from, $to])->count(),
            'scheduled' => DmPost::whereIn('status', ['approved', 'review'])->where('scheduled_at', '>=', now())->count(),
            'spend' => round($spend, 2), 'fees' => round($fees, 2), 'budget' => round($budget, 2),
            'link_clicks' => DmLinkClick::whereBetween('clicked_at', [$from, $to])->count(),
            'bookings' => $att['bookings'], 'nights' => $att['nights'], 'revenue' => $att['revenue'],
            'roas' => ($spend + $fees) > 0 ? round($att['revenue'] / ($spend + $fees), 2) : null,
            'health' => $scores->count() ? (int) round($scores->avg()) : null, 'rating' => $rated->count() ? round((float) $rated->avg('rating'), 2) : null,
        ];
    }

    /** month-by-month trend for the last n months (followers, reach, engagements, spend, link clicks, attributed revenue) */
    public static function trend(int $months = 6): Collection
    {
        $end = bdate()->copy()->startOfMonth();
        $social = DmChannel::get()->filter(fn ($c) => $c->kind() === 'social')->pluck('id')->all() ?: [0];

        return collect(range($months - 1, 0))->map(function ($k) use ($end, $social) {
            $m = $end->copy()->subMonthsNoOverflow($k);
            $from = $m->copy()->startOfMonth();
            $to = $m->copy()->endOfMonth();
            $s = DmChannelStat::whereIn('channel_id', $social)->whereDate('month', $from->toDateString())->selectRaw('COALESCE(SUM(followers),0) f, COALESCE(SUM(reach),0) r, COALESCE(SUM(engagements),0) e')->first();

            return ['month' => $from, 'followers' => (int) $s->f, 'reach' => (int) $s->r, 'engagements' => (int) $s->e,
                'spend' => round((float) DmAdStat::whereBetween('date', [$from->toDateString(), $to->toDateString()])->sum('spend'), 2),
                'clicks' => DmLinkClick::whereBetween('clicked_at', [$from, $to])->count(),
                'revenue' => CrmService::bookingValue(Reservation::whereNotNull('dm_link_id')->whereBetween('created_at', [$from, $to]))['revenue']];
        });
    }
}
