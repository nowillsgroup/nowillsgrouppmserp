<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Guest;
use App\Models\GuestRequest;
use App\Models\InboxBroadcast;
use App\Models\InboxQuickReply;
use App\Models\InboxRule;
use App\Models\MarketingConsentLog;
use App\Models\ReservationRoom;
use App\Models\Setting;
use App\Models\User;
use App\Support\Numbering;
use App\Support\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * v7.3 Guest messaging inbox.
 *
 * Every guest message – typed in the guest-portal chat, sent by WhatsApp / SMS (through the SMS gateway's inbound webhook)
 * or by e-mail (through an inbound-mail webhook) – lands in one shared inbox as a {@see Conversation}: one thread per guest
 * and channel, matched to the guest profile and the current stay by phone number / e-mail. Replies go back on the same
 * channel through the existing {@see MessageService} (SMS / WhatsApp) and {@see MailService} (e-mail) – every send is
 * also in the SMS / e-mail logs. Keyword rules route messages to a team, raise the guest request for an in-house guest and
 * can answer on their own; outside desk hours the guest gets an automatic answer. The response-time clock
 * (awaiting_since) starts with the first unanswered guest message and stops with the first staff answer.
 */
class InboxService
{
    public const DEFAULTS = [
        'inbox_enabled' => '1', 'inbox_portal_chat' => '1', 'inbox_sla' => '10', 'inbox_hours_from' => '07:00', 'inbox_hours_to' => '23:00',
        'inbox_after_hours' => '1',
        'inbox_after_hours_reply' => 'Thank you for your message. Our guest services team replies between {from} and {to} – we will come back to you first thing. For anything urgent please dial 0 from your room phone.',
        'inbox_ack' => '0', 'inbox_ack_reply' => 'Thank you, {guest} – we have your message and will reply in a few minutes.',
        'inbox_auto_close' => '24', 'inbox_csat' => '1', 'inbox_default_dept' => 'front_desk',
    ];

    public const SECRET = 'inbox_webhook_secret';

    /** WhatsApp Business only allows free-form messages within 24 hours of the guest's last message */
    public const WHATSAPP_WINDOW_H = 24;

    public const STOP_WORDS = ['stop', 'unsubscribe', 'stopall', 'cancel messages'];

    public const START_WORDS = ['start', 'unstop', 'subscribe'];

    public const MAX_LEN = 2000;

    protected static array $memo = [];

    public static function flush(): void
    {
        self::$memo = [];
    }

    public static function cfg(): array
    {
        return self::$memo['cfg'.Tenant::id()] ??= (function () {
            $s = fn ($k) => (string) setting($k, self::DEFAULTS[$k]);
            $b = fn ($k) => $s($k) === '1';
            $t = fn ($k) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $s($k)) ? $s($k) : self::DEFAULTS[$k];

            return [
                'enabled' => $b('inbox_enabled'), 'portal_chat' => $b('inbox_portal_chat'), 'sla' => max(1, min(240, (int) $s('inbox_sla'))),
                'from' => $t('inbox_hours_from'), 'to' => $t('inbox_hours_to'), 'after_hours' => $b('inbox_after_hours'),
                'after_hours_reply' => mb_substr($s('inbox_after_hours_reply'), 0, 600), 'ack' => $b('inbox_ack'), 'ack_reply' => mb_substr($s('inbox_ack_reply'), 0, 600),
                'auto_close' => max(1, min(168, (int) $s('inbox_auto_close'))), 'csat' => $b('inbox_csat'),
                'dept' => isset(Conversation::DEPARTMENTS[$s('inbox_default_dept')]) ? $s('inbox_default_dept') : 'front_desk',
            ];
        })();
    }

    public static function saveSettings(array $d, User $by): void
    {
        self::need(self::canManage($by));
        foreach (['inbox_hours_from', 'inbox_hours_to'] as $k) {
            self::need(preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) ($d[$k] ?? '')) === 1, 'Enter the desk hours as HH:MM.');
        }
        self::need(is_numeric($d['inbox_sla'] ?? null) && $d['inbox_sla'] >= 1 && $d['inbox_sla'] <= 240, 'The response-time target is 1 to 240 minutes.');
        self::need(is_numeric($d['inbox_auto_close'] ?? null) && $d['inbox_auto_close'] >= 1 && $d['inbox_auto_close'] <= 168, 'Conversations waiting for the guest close after 1 to 168 hours.');
        self::need(isset(Conversation::DEPARTMENTS[$d['inbox_default_dept'] ?? '']), 'Choose the team new conversations go to.');
        $vals = ['inbox_hours_from' => $d['inbox_hours_from'], 'inbox_hours_to' => $d['inbox_hours_to'], 'inbox_sla' => (string) (int) $d['inbox_sla'],
            'inbox_auto_close' => (string) (int) $d['inbox_auto_close'], 'inbox_default_dept' => $d['inbox_default_dept']];
        foreach (['inbox_after_hours_reply', 'inbox_ack_reply'] as $k) {
            $vals[$k] = mb_substr(trim((string) ($d[$k] ?? '')), 0, 600) ?: self::DEFAULTS[$k];
        }
        foreach (['inbox_enabled', 'inbox_portal_chat', 'inbox_after_hours', 'inbox_ack', 'inbox_csat'] as $k) {
            $vals[$k] = ! empty($d[$k]) ? '1' : '0';
        }
        foreach ($vals as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        Setting::$version++;
        self::flush();
        ActivityLogger::log('inbox', 'settings', 'Guest messaging settings updated');
    }

    // ───────────────────────────── permissions ─────────────────────────────

    protected static function need(bool $ok, string $msg = 'You do not have permission to do that.'): void
    {
        if (! $ok) {
            throw new RuntimeException($msg);
        }
    }

    public static function canView(?User $u): bool
    {
        return (bool) ($u?->hasPermission('inbox.view') || self::canReply($u));
    }

    public static function canReply(?User $u): bool
    {
        return (bool) ($u?->hasPermission('inbox.reply') || $u?->hasPermission('inbox.manage'));
    }

    public static function canBroadcast(?User $u): bool
    {
        return (bool) ($u?->hasPermission('inbox.broadcast') || $u?->hasPermission('inbox.manage'));
    }

    public static function canManage(?User $u): bool
    {
        return (bool) $u?->hasPermission('inbox.manage');
    }

    // ───────────────────────────── helpers ─────────────────────────────

    public static function inHours(?Carbon $at = null): bool
    {
        $c = self::cfg();
        $hm = ($at ?? now())->format('H:i');

        return $c['from'] <= $c['to'] ? ($hm >= $c['from'] && $hm < $c['to']) : ($hm >= $c['from'] || $hm < $c['to']); // overnight desks (e.g. 22:00–06:00)
    }

    /** conversations where a guest is waiting for an answer (sidebar badge) */
    public static function attentionCount(): int
    {
        return (int) (self::$memo['att'.Tenant::id()] ??= Conversation::whereIn('status', ['open', 'pending'])->whereNotNull('awaiting_since')->count());
    }

    public static function webhookSecret(): ?string
    {
        return MessageService::secret(self::SECRET);
    }

    public static function rotateSecret(User $by): string
    {
        self::need(self::canManage($by));
        $plain = Str::random(40);
        MessageService::storeSecret(self::SECRET, $plain);
        Setting::$version++;
        ActivityLogger::log('inbox', 'secret', 'Inbound messaging webhook secret changed');

        return $plain;
    }

    /** placeholders for quick replies, rules and broadcasts */
    public static function vars(?Conversation $c, ?User $agent = null, ?ReservationRoom $stay = null, ?Guest $guest = null): array
    {
        $stay ??= $c?->stay;
        $guest ??= $c?->guest ?? $stay?->guest ?? $stay?->reservation?->guest;
        $hotel = hotel();
        $cfg = self::cfg();

        return [
            'guest' => $guest ? (trim(($guest->title ? $guest->title.' ' : '').$guest->last_name) ?: $guest->first_name) : 'Guest',
            'first_name' => $guest?->first_name ?: 'Guest', 'name' => $guest?->full_name ?: ($c?->contact_name ?: 'Guest'),
            'room' => $stay?->room?->room_no ?? '', 'departure' => $stay?->departure_date?->format('D d M') ?? '',
            'hotel' => $hotel?->name, 'checkout_time' => substr((string) $hotel?->checkout_time, 0, 5), 'hotel_phone' => $hotel?->phone,
            'wifi_name' => (string) setting('portal_wifi_name', ''), 'wifi_password' => (string) setting('portal_wifi_password', ''),
            'agent' => $agent ? Str::before($agent->name, ' ') : 'Guest services', 'from' => $cfg['from'], 'to' => $cfg['to'],
        ];
    }

    public static function render(string $body, array $vars): string
    {
        $out = strtr($body, collect($vars)->mapWithKeys(fn ($v, $k) => ['{'.$k.'}' => (string) $v])->all());

        return trim(preg_replace('/\{[a-z_]+\}/', '', $out));
    }

    protected static function clean(string $body): string
    {
        $b = trim(preg_replace("/\r\n?/", "\n", $body));
        $b = preg_replace('/[^\P{C}\n\t]/u', '', $b); // strip control characters, keep new lines

        return mb_substr((string) $b, 0, self::MAX_LEN);
    }

    // ───────────────────────────── matching ─────────────────────────────

    /** a guest profile by mobile number (last 9 digits, any formatting) or e-mail; prefers the one staying now */
    public static function matchGuest(string $channel, string $handle): ?Guest
    {
        if ($channel === 'email') {
            $list = Guest::whereRaw('LOWER(email) = ?', [mb_strtolower($handle)])->get();
        } else {
            $digits = preg_replace('/\D/', '', $handle);
            if (strlen($digits) < 8) {
                return null;
            }
            $tail = substr($digits, -9);
            $list = Guest::whereNotNull('phone')->where('phone', 'like', '%'.substr($tail, -4))->get()
                ->filter(fn ($g) => str_ends_with(preg_replace('/\D/', '', (string) $g->phone), $tail));
        }
        if ($list->count() <= 1) {
            return $list->first();
        }

        return $list->sortByDesc(fn ($g) => [self::stayFor($g)?->status === 'checked_in' ? 1 : 0, $g->last_stay_on?->timestamp ?? 0, $g->id])->first();
    }

    /** the stay a guest is most likely writing about: in house now, else arriving within 14 days, else left in the last 3 days */
    public static function stayFor(Guest $g): ?ReservationRoom
    {
        $mine = fn ($q) => $q->where('guest_id', $g->id)->orWhereHas('reservation', fn ($r) => $r->where('guest_id', $g->id));
        $today = bdate()->toDateString();

        return ReservationRoom::where($mine)->where('status', 'checked_in')->orderByDesc('checked_in_at')->first()
            ?? ReservationRoom::where($mine)->where('status', 'reserved')->whereDate('arrival_date', '>=', $today)->whereDate('arrival_date', '<=', bdate()->copy()->addDays(14)->toDateString())
                ->whereHas('reservation', fn ($r) => $r->whereIn('status', ['confirmed', 'tentative']))->orderBy('arrival_date')->first()
            ?? ReservationRoom::where($mine)->where('status', 'checked_out')->where('checked_out_at', '>=', now()->subDays(3))->orderByDesc('checked_out_at')->first();
    }

    protected static function stayGuest(ReservationRoom $stay): ?Guest
    {
        return $stay->guest ?? $stay->reservation?->guest;
    }

    /** the thread for this channel + handle – created on first contact */
    public static function conversationFor(string $channel, string $handle, ?Guest $guest = null, ?ReservationRoom $stay = null, ?string $name = null, bool $create = true): ?Conversation
    {
        $c = Conversation::where('channel', $channel)->where('handle', $handle)->orderByDesc('id')->first();
        if (! $c && ! $create) {
            return null;
        }
        if (! $c) {
            $c = new Conversation(['number' => Numbering::next('conversation'), 'channel' => $channel, 'handle' => $handle, 'status' => 'closed',
                'department' => self::cfg()['dept'], 'priority' => 'normal', 'opened_at' => now()]);
        }
        $guest ??= $c->guest;
        if ($guest && ! $stay && (! $c->stay || ! in_array($c->stay->status, ['checked_in', 'reserved'], true))) {
            $stay = self::stayFor($guest);
        }
        $c->fill(array_filter(['guest_id' => $guest?->id, 'reservation_room_id' => $stay?->id, 'reservation_id' => $stay?->reservation_id,
            'contact_name' => $name ? mb_substr($name, 0, 120) : null], fn ($v) => $v !== null));
        $c->save();

        return $c;
    }

    // ───────────────────────────── inbound ─────────────────────────────

    /**
     * A guest wrote. $meta: stay (portal), name, subject (e-mail), provider_id (dedupe).
     * Returns the stored message (an existing one when the provider re-delivers the same message).
     */
    public static function receive(string $channel, string $handle, string $body, array $meta = []): ConversationMessage
    {
        self::need(isset(Conversation::CHANNELS[$channel]), 'Unknown channel.');
        self::need(self::cfg()['enabled'], 'Guest messaging is switched off.');
        $body = self::clean($body);
        self::need($body !== '', 'The message is empty.');
        $pid = isset($meta['provider_id']) ? mb_substr((string) $meta['provider_id'], 0, 100) : null;
        if ($pid && ($dup = ConversationMessage::where('provider_id', $pid)->where('direction', 'in')->first())) {
            return $dup;
        }
        if (in_array($channel, ['sms', 'whatsapp'], true)) {
            $handle = MessageService::normalise($handle) ?? throw new RuntimeException('The sender number is not valid.');
        } elseif ($channel === 'email') {
            $handle = mb_strtolower(trim($handle));
            self::need(filter_var($handle, FILTER_VALIDATE_EMAIL) !== false, 'The sender e-mail is not valid.');
        }

        return DB::transaction(function () use ($channel, $handle, $body, $meta, $pid) {
            $stay = $meta['stay'] ?? null;
            $guest = $stay ? self::stayGuest($stay) : self::matchGuest($channel, $handle);
            $c = self::conversationFor($channel, $handle, $guest, $stay, $meta['name'] ?? null);
            $c = Conversation::whereKey($c->id)->lockForUpdate()->first();
            $low = mb_strtolower(trim($body));

            // a star rating answering "how did we do?" after the conversation was closed
            if ($c->status === 'closed' && $c->csat_asked && $c->csat === null && preg_match('/^[1-5]$/', $low)) {
                $m = self::store($c, 'in', $body, ['provider_id' => $pid]);
                self::rate($c, (int) $low);

                return $m;
            }
            $wasClosed = $c->status === 'closed';
            if ($c->status !== 'open') {
                $c->forceFill(['status' => 'open', 'snoozed_until' => null] + ($wasClosed ? ['opened_at' => now(), 'closed_at' => null, 'closed_by' => null, 'csat_asked' => false, 'csat' => null,
                    'priority' => 'normal', 'department' => self::cfg()['dept'], 'after_hours_sent_at' => null] : []));
            }
            if ($channel === 'email' && ! empty($meta['subject']) && ($wasClosed || ! $c->subject)) {
                $c->subject = mb_substr(trim((string) $meta['subject']), 0, 190);
            }
            $m = self::store($c, 'in', $body, ['provider_id' => $pid]);
            $c->forceFill(['unread' => min(999, $c->unread + 1), 'last_inbound_at' => now(), 'awaiting_since' => $c->awaiting_since ?? now()])->save();

            // opt-out / opt-in keywords (SMS / WhatsApp)
            if (in_array($channel, ['sms', 'whatsapp'], true) && (in_array($low, self::STOP_WORDS, true) || in_array($low, self::START_WORDS, true))) {
                self::optOut($c, in_array($low, self::STOP_WORDS, true));

                return $m;
            }

            $answered = false;
            if ($rule = InboxRule::where('is_active', true)->orderBy('sort')->orderBy('id')->get()->first(fn ($r) => $r->matches($body))) {
                $answered = self::applyRule($c, $rule, $body);
                $answered = $answered || (bool) $rule->reply; // the guest already has an automatic answer – never stack a second one on top
            }
            $c->refresh();
            if (! $answered && self::cfg()['after_hours'] && ! self::inHours() && (! $c->after_hours_sent_at || $c->after_hours_sent_at->lt($c->opened_at ?? now()->subDay()))) {
                self::autoReply($c, self::cfg()['after_hours_reply']);
                $c->forceFill(['after_hours_sent_at' => now()])->save();
            } elseif (! $answered && self::cfg()['ack'] && $wasClosed) {
                self::autoReply($c, self::cfg()['ack_reply']);
            }
            self::flush();

            return $m;
        });
    }

    /** what the matching keyword rule does; true when its automatic answer settles the guest's question */
    protected static function applyRule(Conversation $c, InboxRule $rule, string $body): bool
    {
        $rule->forceFill(['hits' => $rule->hits + 1, 'last_hit_at' => now()])->saveQuietly();
        $upd = [];
        if ($rule->department && isset(Conversation::DEPARTMENTS[$rule->department])) {
            $upd['department'] = $rule->department;
        }
        if ($rule->urgent) {
            $upd['priority'] = 'high';
        }
        if ($upd) {
            $c->forceFill($upd)->save();
        }
        $raised = false;
        if ($rule->request_category && $rule->request_item) {
            $stay = $c->stay;
            if ($stay && $stay->status === 'checked_in') {
                try {
                    $req = GuestPortalService::raise($stay, ['category' => $rule->request_category, 'item' => $rule->request_item, 'notes' => 'Guest message: '.Str::limit($body, 400)], 'chat');
                    self::store($c, 'system', "Guest request {$req->request_no} raised: {$req->item} (rule “{$rule->name}”)", ['rule_id' => $rule->id]);
                    $raised = true;
                } catch (RuntimeException $e) {
                    self::store($c, 'system', 'No request raised: '.$e->getMessage(), ['rule_id' => $rule->id]);
                }
            } else {
                self::store($c, 'system', "Rule “{$rule->name}” matched – no request raised because the guest is not in house.", ['rule_id' => $rule->id]);
            }
        } else {
            self::store($c, 'system', "Rule “{$rule->name}” matched".($upd ? ' – '.mb_strtolower($rule->actionsLabel()) : '').'.', ['rule_id' => $rule->id]);
        }
        if ($rule->reply) {
            self::autoReply($c, $rule->reply, $rule);

            // a pure information answer (Wi-Fi, breakfast times…) settles it; a raised request / routing still needs a person to follow up
            return ! $rule->request_item && ! $raised;
        }

        return false;
    }

    protected static function autoReply(Conversation $c, string $template, ?InboxRule $rule = null): ?ConversationMessage
    {
        $text = self::render($template, self::vars($c));
        if ($text === '') {
            return null;
        }
        $m = self::deliver($c, $text, ['auto' => true, 'rule_id' => $rule?->id]);
        if ($rule && ! $rule->request_item) {
            $c->forceFill(['awaiting_since' => null])->save();
        }

        return $m;
    }

    protected static function optOut(Conversation $c, bool $stop): void
    {
        $c->forceFill(['opted_out_at' => $stop ? now() : null, 'awaiting_since' => null])->save();
        if ($c->guest) {
            $col = $c->channel === 'whatsapp' ? 'whatsapp_opt_in' : 'sms_opt_in';
            $c->guest->forceFill([$col => ! $stop, 'consent_at' => now(), 'consent_source' => 'inbox'])->save();
            MarketingConsentLog::create(['guest_id' => $c->guest->id, 'channel' => $c->channel, 'opted_in' => ! $stop, 'source' => 'inbox_keyword', 'user_id' => null]);
        }
        self::store($c, 'system', $stop ? 'Guest replied STOP – no broadcasts or marketing to this number. Replies to the guest\'s own questions are still possible.' : 'Guest replied START – notices to this number are on again.');
        self::deliver($c, $stop ? 'You will no longer receive notices from '.hotel()->name.'. Reply START to receive them again.' : 'Thank you – you will receive notices from '.hotel()->name.' again.', ['auto' => true]);
    }

    // ───────────────────────────── outbound ─────────────────────────────

    /** low-level: store a message line and keep the conversation's timestamps in step */
    protected static function store(Conversation $c, string $direction, string $body, array $extra = []): ConversationMessage
    {
        $m = new ConversationMessage;
        $m->forceFill(['conversation_id' => $c->id, 'direction' => $direction, 'body' => $body, 'channel' => $c->channel,
            'status' => $direction === 'in' ? 'received' : ($direction === 'out' ? 'sent' : 'received')] + $extra);
        $m->save();
        if (in_array($direction, ['in', 'out'], true)) {
            $c->forceFill(['last_message_at' => now()])->save();
        }

        return $m;
    }

    public static function whatsappWindowOpen(Conversation $c): bool
    {
        return $c->last_inbound_at && $c->last_inbound_at->gt(now()->subHours(self::WHATSAPP_WINDOW_H));
    }

    /** send on the conversation's channel; failures are stored on the message (status failed + error), never thrown */
    protected static function deliver(Conversation $c, string $text, array $extra = []): ConversationMessage
    {
        $m = self::store($c, 'out', $text, $extra);
        if (in_array($c->channel, ['sms', 'whatsapp'], true)) {
            $log = MessageService::send($c->handle, $text, $c->channel, 'inbox', $c);
            $m->forceFill(['status' => $log->status === 'sent' ? 'sent' : 'failed', 'provider_id' => $log->provider_id, 'error' => $log->error ? mb_substr($log->error, 0, 300) : null])->save();
        } elseif ($c->channel === 'email') {
            $subject = $c->subject ? (str_starts_with(mb_strtolower($c->subject), 're:') ? $c->subject : 'Re: '.$c->subject) : 'Your message to '.hotel()->name;
            $log = MailService::send($c->handle, new \App\Mail\GuestMessage($subject, $text, hotel()), 'inbox', $c);
            $m->forceFill(['status' => $log->status === 'sent' ? 'sent' : 'failed', 'error' => $log->error ? mb_substr($log->error, 0, 300) : null])->save();
        }

        return $m;
    }

    /** a staff answer (or a quick reply) */
    public static function reply(Conversation $c, string $body, User $by, ?int $quickReplyId = null, bool $wait = false): ConversationMessage
    {
        self::need(self::canReply($by));
        $body = self::clean($body);
        self::need($body !== '', 'Write a message first.');
        if ($c->channel === 'whatsapp') {
            self::need(self::whatsappWindowOpen($c), 'WhatsApp only allows free replies within 24 hours of the guest\'s last message. Answer by SMS from the guest profile or wait for the guest to write again.');
        }
        if ($c->channel === 'portal') {
            $state = $c->stay?->portalState();
            self::need(in_array($state, ['inhouse', 'upcoming', 'departed'], true), 'The guest\'s portal link has expired – reach the guest by phone or e-mail instead.');
        }
        $qr = $quickReplyId ? InboxQuickReply::find($quickReplyId) : null;

        return DB::transaction(function () use ($c, $body, $by, $qr, $wait) {
            $c = Conversation::whereKey($c->id)->lockForUpdate()->first();
            $resp = $c->awaiting_since ? max(0, (int) $c->awaiting_since->diffInSeconds(now())) : null;
            $m = self::deliver($c, $body, ['user_id' => $by->id, 'quick_reply_id' => $qr?->id, 'response_sec' => $resp]);
            $qr?->increment('uses');
            $c->forceFill(['awaiting_since' => null, 'unread' => 0, 'assigned_to' => $c->assigned_to ?? $by->id,
                'status' => $wait || $c->status === 'closed' ? 'pending' : ($c->status === 'snoozed' ? 'open' : $c->status), 'snoozed_until' => null])->save();
            if ($c->status === 'pending' && $c->closed_at) {
                $c->forceFill(['closed_at' => null, 'closed_by' => null, 'opened_at' => now()])->save();
            }
            self::flush();

            return $m;
        });
    }

    /** a note only staff can see */
    public static function note(Conversation $c, string $body, User $by): ConversationMessage
    {
        self::need(self::canReply($by));
        $body = self::clean($body);
        self::need($body !== '', 'Write the note first.');

        return self::store($c, 'note', $body, ['user_id' => $by->id]);
    }

    // ───────────────────────────── desk actions ─────────────────────────────

    public static function markRead(Conversation $c): void
    {
        if ($c->unread) {
            $c->forceFill(['unread' => 0])->saveQuietly();
            self::flush();
        }
    }

    public static function assign(Conversation $c, ?int $userId, User $by): Conversation
    {
        self::need(self::canReply($by));
        $u = $userId ? User::where('is_active', true)->find($userId) : null;
        self::need(! $userId || ($u && self::canReply($u)), 'That colleague cannot answer guest messages.');
        $c->forceFill(['assigned_to' => $u?->id])->save();
        self::store($c, 'system', $u ? ($u->id === $by->id ? "{$by->name} took the conversation." : "{$by->name} assigned the conversation to {$u->name}.") : "{$by->name} unassigned the conversation.");

        return $c;
    }

    public static function setDepartment(Conversation $c, string $dept, User $by): Conversation
    {
        self::need(self::canReply($by));
        self::need(isset(Conversation::DEPARTMENTS[$dept]), 'Choose a team.');
        if ($c->department !== $dept) {
            $c->forceFill(['department' => $dept])->save();
            self::store($c, 'system', "{$by->name} moved the conversation to ".Conversation::DEPARTMENTS[$dept].'.');
        }

        return $c;
    }

    public static function setPriority(Conversation $c, string $p, User $by): Conversation
    {
        self::need(self::canReply($by));
        self::need(in_array($p, ['normal', 'high'], true), 'Choose normal or urgent.');
        $c->forceFill(['priority' => $p])->save();

        return $c;
    }

    public static function snooze(Conversation $c, string $until, User $by): Conversation
    {
        self::need(self::canReply($by));
        self::need($c->status !== 'closed', 'Reopen the conversation first.');
        try {
            $at = Carbon::parse($until);
        } catch (\Throwable) {
            throw new RuntimeException('Choose when the conversation should come back.');
        }
        self::need($at->gt(now()->addMinutes(4)) && $at->lt(now()->addDays(8)), 'Snooze for 5 minutes up to 7 days.');
        $c->forceFill(['status' => 'snoozed', 'snoozed_until' => $at])->save();
        self::store($c, 'system', "{$by->name} snoozed the conversation until ".$at->format('D d M H:i').'.');
        self::flush();

        return $c;
    }

    public static function close(Conversation $c, User $by, bool $askRating = true): Conversation
    {
        self::need(self::canReply($by));
        self::need($c->status !== 'closed', 'The conversation is already closed.');
        $c->forceFill(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $by->id, 'unread' => 0, 'awaiting_since' => null, 'snoozed_until' => null])->save();
        self::store($c, 'system', "{$by->name} closed the conversation.");
        if ($askRating && self::cfg()['csat'] && ! $c->opted_out_at) {
            if ($c->channel === 'portal') {
                $c->forceFill(['csat_asked' => true])->save();
            } elseif ($c->channel === 'sms' || ($c->channel === 'whatsapp' && self::whatsappWindowOpen($c))) {
                self::deliver($c, 'Thank you for contacting '.hotel()->name.'. How did we do? Reply with a number from 1 (poor) to 5 (excellent).', ['auto' => true]);
                $c->forceFill(['csat_asked' => true])->save();
            }
        }
        self::flush();

        return $c;
    }

    public static function reopen(Conversation $c, User $by): Conversation
    {
        self::need(self::canReply($by));
        self::need($c->status !== 'open', 'The conversation is already open.');
        $c->forceFill(['status' => 'open', 'snoozed_until' => null, 'closed_at' => null, 'closed_by' => null] + ($c->closed_at ? ['opened_at' => now()] : []))->save();
        self::store($c, 'system', "{$by->name} reopened the conversation.");

        return $c;
    }

    public static function rate(Conversation $c, int $stars): Conversation
    {
        self::need($stars >= 1 && $stars <= 5, 'Choose 1 to 5 stars.');
        self::need($c->csat_asked && $c->csat === null, 'Thank you – this conversation has already been rated.');
        $c->forceFill(['csat' => $stars])->save();
        self::store($c, 'system', "Guest rated the conversation {$stars} / 5.");

        return $c;
    }

    /** raise a guest request for the in-house guest straight from the conversation */
    public static function raiseRequest(Conversation $c, array $d, User $by): GuestRequest
    {
        self::need(self::canReply($by));
        self::need($c->stay && $c->stay->status === 'checked_in', 'Requests can only be raised for a guest who is in house.');
        $req = GuestPortalService::raise($c->stay, ['category' => $d['category'] ?? '', 'item' => $d['item'] ?? '', 'notes' => trim((string) ($d['notes'] ?? '')) ?: null, 'time' => $d['time'] ?? null,
            'preferred_at' => $d['preferred_at'] ?? null], 'chat');
        self::store($c, 'system', "{$by->name} raised guest request {$req->request_no}: {$req->item}.");

        return $req;
    }

    /** attach an unknown sender to a guest profile (and their current stay) */
    public static function linkGuest(Conversation $c, int $guestId, User $by): Conversation
    {
        self::need(self::canReply($by));
        $g = Guest::find($guestId) ?? throw new RuntimeException('Guest not found.');
        $stay = self::stayFor($g);
        $c->forceFill(['guest_id' => $g->id, 'reservation_room_id' => $stay?->id, 'reservation_id' => $stay?->reservation_id])->save();
        self::store($c, 'system', "{$by->name} linked the conversation to {$g->full_name}".($stay?->room ? ' (room '.$stay->room->room_no.')' : '').'.');

        return $c;
    }

    /** snoozes that are due come back; conversations waiting for the guest close after the configured hours */
    public static function sweep(): int
    {
        $n = 0;
        foreach (Conversation::where('status', 'snoozed')->where('snoozed_until', '<=', now())->get() as $c) {
            $c->forceFill(['status' => 'open', 'snoozed_until' => null])->save();
            self::store($c, 'system', 'Snooze ended – back in the inbox.');
            $n++;
        }
        $h = self::cfg()['auto_close'];
        foreach (Conversation::where('status', 'pending')->whereNull('awaiting_since')->where('last_message_at', '<', now()->subHours($h))->get() as $c) {
            $c->forceFill(['status' => 'closed', 'closed_at' => now(), 'closed_by' => null, 'unread' => 0])->save();
            self::store($c, 'system', "Closed automatically – no reply from the guest for {$h} hours.");
            $n++;
        }
        if ($n) {
            self::flush();
        }

        return $n;
    }

    // ───────────────────────────── guest portal chat ─────────────────────────────

    public static function portalOn(): bool
    {
        return self::cfg()['enabled'] && self::cfg()['portal_chat'];
    }

    public static function portalThread(ReservationRoom $stay): ?Conversation
    {
        return Conversation::where('channel', 'portal')->where('handle', 'stay:'.$stay->id)->first();
    }

    /** messages the guest may see: theirs and the hotel's (never internal notes / system lines) */
    public static function portalMessages(?Conversation $c, int $after = 0): Collection
    {
        if (! $c) {
            return collect();
        }

        return ConversationMessage::where('conversation_id', $c->id)->whereIn('direction', ['in', 'out'])->where('id', '>', $after)->orderBy('id')->limit(200)->get();
    }

    public static function portalSeen(?Conversation $c): void
    {
        if ($c) {
            ConversationMessage::where('conversation_id', $c->id)->where('direction', 'out')->whereNull('read_at')->update(['read_at' => now(), 'status' => 'read']);
        }
    }

    public static function portalSend(ReservationRoom $stay, string $body): ConversationMessage
    {
        self::need(self::portalOn(), 'Chat is not available at the moment – please call the front desk.');
        self::need(in_array($stay->portalState(), ['inhouse', 'upcoming'], true), 'This stay has ended – please e-mail or call us.');
        $c = self::portalThread($stay);
        self::need(! $c || ConversationMessage::where('conversation_id', $c->id)->where('direction', 'in')->where('created_at', '>=', now()->subMinutes(10))->count() < 20,
            'You have sent a lot of messages – please wait a moment for our reply.');

        return self::receive('portal', 'stay:'.$stay->id, $body, ['stay' => $stay]);
    }

    // ───────────────────────────── broadcasts ─────────────────────────────

    /** @return Collection<ReservationRoom> */
    public static function audience(string $audience, ?int $floorId = null): Collection
    {
        self::need(isset(InboxBroadcast::AUDIENCES[$audience]), 'Choose who receives the message.');
        $today = bdate()->toDateString();
        $q = ReservationRoom::with('guest', 'reservation.guest', 'room');
        match ($audience) {
            'arrivals' => $q->where('status', 'reserved')->whereDate('arrival_date', $today)->whereHas('reservation', fn ($r) => $r->whereIn('status', ['confirmed', 'tentative'])),
            'departures' => $q->where('status', 'checked_in')->whereDate('departure_date', $today),
            'floor' => $q->where('status', 'checked_in')->whereHas('room', fn ($r) => $r->where('floor_id', $floorId ?: 0)),
            default => $q->where('status', 'checked_in'),
        };

        return $q->get()->sortBy(fn ($s) => $s->room?->room_no ?? 'zzz')->values();
    }

    /** how each stay would be reached: [channel, handle] or [null, reason] */
    public static function route(ReservationRoom $stay, string $channel): array
    {
        $g = self::stayGuest($stay);
        $num = MessageService::normalise($g?->phone);
        $optedOut = fn ($ch) => $num && Conversation::where('channel', $ch)->where('handle', $num)->whereNotNull('opted_out_at')->exists();
        $wa = fn () => $num && ! $optedOut('whatsapp') && ($c = Conversation::where('channel', 'whatsapp')->where('handle', $num)->first()) && self::whatsappWindowOpen($c);
        $sms = fn () => $num && ! $optedOut('sms');
        $portal = fn () => self::portalOn() && $stay->portal_token && in_array($stay->portalState(), ['inhouse', 'upcoming'], true);

        return match ($channel) {
            'portal' => $portal() ? ['portal', 'stay:'.$stay->id] : [null, 'no portal link'],
            'whatsapp' => $wa() ? ['whatsapp', $num] : [null, ! $num ? 'no mobile number' : ($optedOut('whatsapp') ? 'replied STOP' : 'no open WhatsApp session')],
            'sms' => $sms() ? ['sms', $num] : [null, ! $num ? 'no mobile number' : 'replied STOP'],
            default => $wa() ? ['whatsapp', $num] : ($sms() ? ['sms', $num] : ($portal() ? ['portal', 'stay:'.$stay->id] : [null, $num ? 'replied STOP, no portal link' : 'no mobile number or portal link'])),
        };
    }

    public static function broadcast(array $d, User $by): InboxBroadcast
    {
        self::need(self::canBroadcast($by));
        self::need(self::cfg()['enabled'], 'Guest messaging is switched off.');
        $title = trim((string) ($d['title'] ?? ''));
        $body = self::clean((string) ($d['body'] ?? ''));
        self::need($title !== '' && $body !== '', 'Give the broadcast a title and a message.');
        self::need(mb_strlen($body) <= 640, 'Keep a broadcast under 640 characters (four SMS).');
        $channel = $d['channel'] ?? 'best';
        self::need(isset(InboxBroadcast::CHANNELS[$channel]), 'Choose how to send it.');
        $audience = $d['audience'] ?? '';
        $floor = $audience === 'floor' ? (int) ($d['floor_id'] ?? 0) : null;
        self::need($audience !== 'floor' || \App\Models\Floor::whereKey($floor)->exists(), 'Choose the floor.');
        $stays = self::audience($audience, $floor);
        self::need($stays->isNotEmpty(), 'Nobody matches that audience right now.');
        self::need($stays->count() <= 500, 'A broadcast can reach at most 500 guests at once.');

        return DB::transaction(function () use ($title, $body, $channel, $audience, $floor, $stays, $by) {
            $b = InboxBroadcast::create(['number' => Numbering::next('inbox_broadcast'), 'title' => mb_substr($title, 0, 120), 'body' => $body, 'audience' => $audience,
                'floor_id' => $floor, 'channel' => $channel, 'recipients' => $stays->count(), 'created_by' => $by->id]);
            $sent = $failed = 0;
            $reasons = [];
            foreach ($stays as $stay) {
                [$ch, $handle] = self::route($stay, $channel);
                if (! $ch) {
                    $reasons[$handle] = ($reasons[$handle] ?? 0) + 1;

                    continue;
                }
                $g = self::stayGuest($stay);
                $c = self::conversationFor($ch, $handle, $g, $stay);
                $m = self::deliver($c, self::render($body, self::vars($c, $by, $stay, $g)), ['user_id' => $by->id, 'broadcast_id' => $b->id]);
                $m->status === 'failed' ? $failed++ : $sent++;
            }
            $b->forceFill(['sent' => $sent, 'failed' => $failed, 'skipped' => array_sum($reasons), 'skipped_reasons' => $reasons ?: null])->save();
            ActivityLogger::log('inbox', 'broadcast', "Broadcast {$b->number} “{$b->title}” to ".InboxBroadcast::AUDIENCES[$audience].": {$sent} sent, {$failed} failed, ".array_sum($reasons).' skipped', $b);

            return $b;
        });
    }

    // ───────────────────────────── inbound webhook ─────────────────────────────

    /**
     * Provider → hotel. 'generic': JSON body signed with HMAC-SHA256 of the raw body (header X-Nowills-Signature: sha256=<hex>)
     * {type: message|status, channel: sms|whatsapp|email, from, name?, subject?, body, id?, status?}.
     * 'twilio': form post signed with X-Twilio-Signature (HMAC-SHA1 of URL + sorted params, the Twilio auth token).
     * Returns [http status, response array].
     */
    public static function webhook(string $provider, string $raw, array $params, array $headers, string $url): array
    {
        if ($provider === 'twilio') {
            $token = MessageService::secret('sms_twilio_token');
            if (! $token) {
                return [503, ['error' => 'Twilio is not configured.']];
            }
            ksort($params);
            $data = $url;
            foreach ($params as $k => $v) {
                $data .= $k.(is_array($v) ? implode('', $v) : $v);
            }
            $expected = base64_encode(hash_hmac('sha1', $data, $token, true));
            if (! hash_equals($expected, (string) ($headers['x-twilio-signature'] ?? ''))) {
                return [403, ['error' => 'Bad signature.']];
            }
            if (isset($params['MessageStatus']) && ! isset($params['Body'])) {
                return [200, ['ok' => true, 'updated' => self::statusUpdate((string) ($params['MessageSid'] ?? ''), (string) $params['MessageStatus'])]];
            }
            $from = (string) ($params['From'] ?? '');
            $channel = str_starts_with($from, 'whatsapp:') ? 'whatsapp' : 'sms';
            $payload = ['type' => 'message', 'channel' => $channel, 'from' => Str::after($from, 'whatsapp:'), 'body' => (string) ($params['Body'] ?? ''),
                'id' => $params['MessageSid'] ?? null, 'name' => $params['ProfileName'] ?? null];
        } elseif ($provider === 'generic') {
            $secret = self::webhookSecret();
            if (! $secret) {
                return [503, ['error' => 'The inbound webhook has no secret yet – create one in Guest Messaging settings.']];
            }
            $sig = (string) ($headers['x-nowills-signature'] ?? '');
            if (! hash_equals('sha256='.hash_hmac('sha256', $raw, $secret), $sig)) {
                return [403, ['error' => 'Bad signature.']];
            }
            $payload = json_decode($raw, true);
            if (! is_array($payload)) {
                return [422, ['error' => 'Send a JSON body.']];
            }
        } else {
            return [404, ['error' => 'Unknown provider.']];
        }
        if (($payload['type'] ?? 'message') === 'status') {
            return [200, ['ok' => true, 'updated' => self::statusUpdate((string) ($payload['id'] ?? ''), (string) ($payload['status'] ?? ''))]];
        }
        try {
            $m = self::receive((string) ($payload['channel'] ?? 'sms'), (string) ($payload['from'] ?? ''), (string) ($payload['body'] ?? ''),
                ['name' => $payload['name'] ?? null, 'subject' => $payload['subject'] ?? null, 'provider_id' => $payload['id'] ?? null]);
        } catch (RuntimeException $e) {
            return [422, ['error' => $e->getMessage()]];
        }

        return [200, ['ok' => true, 'conversation' => $m->conversation?->number, 'message' => $m->id]];
    }

    /** delivery receipts from the gateway: sent → delivered → read, or failed */
    public static function statusUpdate(string $providerId, string $status): int
    {
        $map = ['delivered' => 'delivered', 'read' => 'read', 'failed' => 'failed', 'undelivered' => 'failed', 'sent' => 'sent'];
        if ($providerId === '' || ! isset($map[strtolower($status)])) {
            return 0;
        }
        $st = $map[strtolower($status)];
        $rank = ['sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4];

        return ConversationMessage::where('provider_id', $providerId)->where('direction', 'out')->get()
            ->filter(fn ($m) => ($rank[$st] ?? 0) > ($rank[$m->status] ?? 0))
            ->each(fn ($m) => $m->forceFill(['status' => $st, 'read_at' => $st === 'read' ? now() : $m->read_at])->save())->count();
    }

    // ───────────────────────────── dashboard ─────────────────────────────

    public static function dashboard(): array
    {
        self::sweep();
        $sla = self::cfg()['sla'];
        $active = Conversation::with('guest', 'stay.room', 'assignee', 'lastMessage')->whereIn('status', Conversation::ACTIVE)->get();
        $since = now()->subDays(7);
        $replies = ConversationMessage::where('direction', 'out')->whereNotNull('response_sec')->where('created_at', '>=', $since)->get(['response_sec', 'user_id', 'channel']);
        $msgs = ConversationMessage::where('created_at', '>=', $since)->whereIn('direction', ['in', 'out'])->get(['direction', 'channel', 'created_at', 'auto']);
        $hours = array_fill(0, 24, 0);
        foreach ($msgs->where('direction', 'in') as $m) {
            $hours[(int) $m->created_at->format('G')]++;
        }
        $rated = Conversation::whereNotNull('csat')->where('closed_at', '>=', now()->subDays(30))->pluck('csat');
        $agents = $replies->groupBy('user_id')->map(fn ($g, $uid) => ['user' => User::find($uid), 'replies' => $g->count(), 'avg' => (int) round($g->avg('response_sec')),
            'within' => round($g->filter(fn ($r) => $r->response_sec <= $sla * 60)->count() * 100 / max(1, $g->count()))])->sortByDesc('replies')->values();

        return [
            'active' => $active, 'waiting' => $active->whereNotNull('awaiting_since')->where('status', '!=', 'snoozed'),
            'overdue' => $active->filter(fn ($c) => $c->isOverdue($sla))->sortBy('awaiting_since')->values(),
            'unassigned' => $active->whereNull('assigned_to')->where('status', 'open')->count(), 'mine' => $active->where('assigned_to', auth()->id())->count(),
            'avg_response' => $replies->count() ? (int) round($replies->avg('response_sec')) : null,
            'within_sla' => $replies->count() ? round($replies->filter(fn ($r) => $r->response_sec <= $sla * 60)->count() * 100 / $replies->count()) : null,
            'today_in' => ConversationMessage::where('direction', 'in')->where('created_at', '>=', now()->startOfDay())->count(),
            'automated' => $msgs->where('direction', 'out')->where('auto', true)->count(), 'out7' => $msgs->where('direction', 'out')->count(),
            'channels' => collect(Conversation::CHANNELS)->map(fn ($l, $k) => $msgs->where('direction', 'in')->where('channel', $k)->count()),
            'departments' => $active->groupBy('department')->map->count()->sortDesc(),
            'hours' => $hours, 'csat' => $rated->count() ? round($rated->avg(), 1) : null, 'csat_n' => $rated->count(), 'agents' => $agents,
            'rules' => InboxRule::where('hits', '>', 0)->orderByDesc('hits')->limit(6)->get(),
            'broadcasts' => InboxBroadcast::with('creator')->latest('id')->limit(5)->get(), 'sla' => $sla,
        ];
    }

    public static function fmtSecs(?int $s): string
    {
        if ($s === null) {
            return '—';
        }

        return $s < 60 ? $s.'s' : ($s < 3600 ? floor($s / 60).'m '.str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT).'s' : floor($s / 3600).'h '.str_pad((string) floor(($s % 3600) / 60), 2, '0', STR_PAD_LEFT).'m');
    }
}
