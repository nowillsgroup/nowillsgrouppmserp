<?php

namespace App\Services;

use App\Models\Hotel;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\Tenant;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * v4.0 – outgoing webhooks.
 *  • dispatch(): called by the model observer after the DB commit – one outbox row per subscribed endpoint.
 *  • deliverDue(): `pms:webhooks` (every minute) / "Deliver now" – POSTs the JSON payload, signed
 *    X-Nowills-Signature: t=<unix>,v1=<hex HMAC-SHA256(secret, "<t>.<body>")> (a second v1 during a secret rotation).
 *  • Failed attempts are retried after 1 m, 5 m, 30 m, 2 h, 6 h, 12 h, 24 h – then the delivery is marked failed.
 *    An endpoint failing `webhook_disable_after` times in a row is switched off and the log book is told.
 *  • SSRF guard: https only (unless allowed), no redirects, and the host is resolved and pinned – private, loopback
 *    and link-local addresses are refused unless the hotel allows private endpoints (on-premise integrations).
 */
class WebhookService
{
    public const EVENTS = [
        'reservation.created' => ['A reservation was made (any channel: desk, booking engine, OTA, API)', 'Reservations'],
        'reservation.cancelled' => ['A reservation was cancelled', 'Reservations'],
        'reservation.no_show' => ['A reservation was marked no-show', 'Reservations'],
        'guest.checked_in' => ['A guest checked in to a room', 'Stays'],
        'guest.checked_out' => ['A guest checked out', 'Stays'],
        'room.status_changed' => ['A room\'s occupancy or housekeeping status changed', 'Rooms'],
        'payment.received' => ['A payment was posted to a folio', 'Billing'],
        'night_audit.completed' => ['The night audit closed the day (with the day\'s statistics)', 'Operations'],
    ];

    /** seconds to wait before attempt n+1 */
    public const BACKOFF = [60, 300, 1800, 7200, 21600, 43200, 86400];

    public const MAX_ATTEMPTS = 8;

    /** test seam: fn(string $host): array of IPs */
    public static ?Closure $resolver = null;

    // ───────────────────────────── endpoints ─────────────────────────────

    /** @return array{0: WebhookEndpoint, 1: string} endpoint + signing secret (shown once) */
    public static function create(array $d): array
    {
        $secret = self::newSecret();
        $e = new WebhookEndpoint(self::attrs($d) + ['secret' => $secret, 'created_by' => auth()->id(), 'is_active' => true]);
        $e->save();
        ActivityLogger::log('api', 'webhook_create', "Webhook \"{$e->name}\" → {$e->host()} for ".implode(', ', $e->events), $e);

        return [$e, $secret];
    }

    public static function update(WebhookEndpoint $e, array $d): WebhookEndpoint
    {
        $e->fill(self::attrs($d))->save();
        ActivityLogger::log('api', 'webhook_update', "Webhook \"{$e->name}\" updated", $e);

        return $e;
    }

    /** New secret; the old one keeps signing (as a second v1) for 24 h so receivers can switch without downtime. */
    public static function rotateSecret(WebhookEndpoint $e): string
    {
        $secret = self::newSecret();
        $e->forceFill(['previous_secret' => $e->secret, 'previous_expires_at' => now()->addDay(), 'secret' => $secret])->save();
        ActivityLogger::log('api', 'webhook_rotate', "Signing secret of webhook \"{$e->name}\" rotated (old secret valid 24 h)", $e);

        return $secret;
    }

    public static function setActive(WebhookEndpoint $e, bool $on): void
    {
        $e->forceFill($on ? ['is_active' => true, 'consecutive_failures' => 0, 'disabled_at' => null, 'disabled_reason' => null]
            : ['is_active' => false, 'disabled_at' => now(), 'disabled_reason' => null])->save();
        ActivityLogger::log('api', $on ? 'webhook_enable' : 'webhook_pause', "Webhook \"{$e->name}\" ".($on ? 'enabled' : 'paused'), $e);
    }

    public static function delete(WebhookEndpoint $e): void
    {
        ActivityLogger::log('api', 'webhook_delete', "Webhook \"{$e->name}\" ({$e->host()}) deleted with ".$e->deliveries()->count().' delivery record(s)');
        $e->delete();
    }

    protected static function newSecret(): string
    {
        return 'whsec_'.Str::random(40);
    }

    protected static function attrs(array $d): array
    {
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Give the endpoint a name.');
        }
        $url = trim((string) ($d['url'] ?? ''));
        self::validateUrl($url);
        $events = (array) ($d['events'] ?? []);
        $events = in_array('*', $events, true) ? ['*'] : array_values(array_intersect(array_keys(self::EVENTS), $events));
        if (! $events) {
            throw new RuntimeException('Subscribe the endpoint to at least one event.');
        }

        return ['name' => mb_substr($name, 0, 80), 'url' => $url, 'events' => $events, 'description' => trim((string) ($d['description'] ?? '')) ?: null];
    }

    /** Checked when saving; the resolved address is checked again (and pinned) on every delivery. */
    public static function validateUrl(string $url): void
    {
        $cfg = ApiService::cfg();
        $p = parse_url($url);
        if (! $p || empty($p['host']) || ! in_array(strtolower($p['scheme'] ?? ''), ['https', 'http'], true) || strlen($url) > 500) {
            throw new RuntimeException('Enter a full URL, e.g. https://partner.example.com/webhooks/nowills.');
        }
        if (strtolower($p['scheme']) === 'http' && ! $cfg['allow_http']) {
            throw new RuntimeException('Webhook URLs must use https:// (plain http can be allowed in the API settings for testing).');
        }
        if (! empty($p['user']) || ! empty($p['pass'])) {
            throw new RuntimeException('Do not put credentials in the URL – verify the X-Nowills-Signature header instead.');
        }
        $host = strtolower(trim($p['host'], '[]'));
        if (! $cfg['allow_private'] && ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')
            || (filter_var($host, FILTER_VALIDATE_IP) && ! self::isPublicIp($host)))) {
            throw new RuntimeException('That address is on a private / internal network. Allow private endpoints in the API settings for on-premise receivers.');
        }
    }

    public static function isPublicIp(string $ip): bool
    {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    // ───────────────────────────── events → outbox ─────────────────────────────

    /** Queue $event for every active endpoint of the hotel that subscribes to it. Returns the number of deliveries queued. */
    public static function dispatch(string $event, array $data, ?int $hotelId = null): int
    {
        $hotelId ??= Tenant::id();
        if (! $hotelId) {
            return 0;
        }
        $eps = WebhookEndpoint::withoutGlobalScope('hotel')->where('hotel_id', $hotelId)->where('is_active', true)->get()->filter->listensTo($event);
        if ($eps->isEmpty()) {
            return 0;
        }
        $code = Tenant::id() === $hotelId ? Tenant::hotel()->code : Hotel::whereKey($hotelId)->value('code');
        $envelope = ['id' => 'evt_'.Str::uuid()->toString(), 'type' => $event, 'created_at' => now()->toIso8601String(), 'api_version' => ApiService::VERSION,
            'hotel' => ['code' => $code], 'data' => $data];
        foreach ($eps as $ep) {
            self::queue($ep, $envelope);
        }

        return $eps->count();
    }

    protected static function queue(WebhookEndpoint $ep, array $envelope): WebhookDelivery
    {
        $d = new WebhookDelivery(['webhook_endpoint_id' => $ep->id, 'event' => $envelope['type'], 'event_id' => $envelope['id'], 'payload' => $envelope,
            'status' => 'pending', 'next_attempt_at' => now()]);
        $d->hotel_id = $ep->hotel_id;
        $d->save();

        return $d;
    }

    /** "Send test event" – a ping delivered straight away. */
    public static function ping(WebhookEndpoint $ep): WebhookDelivery
    {
        $d = self::queue($ep, ['id' => 'evt_'.Str::uuid()->toString(), 'type' => 'ping', 'created_at' => now()->toIso8601String(), 'api_version' => ApiService::VERSION,
            'hotel' => ['code' => Tenant::hotel()?->code], 'data' => ['message' => 'Test event from Nowills PMS – your endpoint is reachable.', 'endpoint' => $ep->name]]);

        return self::attempt($d, true);
    }

    public static function retry(WebhookDelivery $d): WebhookDelivery
    {
        if ($d->status === 'success') {
            throw new RuntimeException('This delivery already succeeded.');
        }
        $d->forceFill(['status' => 'pending', 'next_attempt_at' => now()])->save();

        return self::attempt($d, true);
    }

    // ───────────────────────────── delivery ─────────────────────────────

    /** Attempt every due delivery (all hotels when $hotelId is null). Returns [attempted, delivered, failed]. */
    public static function deliverDue(?int $hotelId = null, int $limit = 100): array
    {
        $prev = Tenant::hotel();
        $due = WebhookDelivery::withoutGlobalScope('hotel')->where('status', 'pending')->where('next_attempt_at', '<=', now())
            ->when($hotelId, fn ($q) => $q->where('hotel_id', $hotelId))
            ->whereHas('endpoint', fn ($q) => $q->withoutGlobalScope('hotel')->where('is_active', true))
            ->orderBy('id')->limit($limit)->get();
        $ok = $bad = 0;
        foreach ($due->groupBy('hotel_id') as $hid => $rows) {
            if (Tenant::id() !== (int) $hid) {
                Tenant::set(Hotel::find($hid));
            }
            foreach ($rows as $d) {
                $d->refresh();
                if ($d->status !== 'pending' || ! $d->endpoint?->is_active) {
                    continue;     // another run got it, or the endpoint was just disabled by an earlier failure
                }
                self::attempt($d)->status === 'success' ? $ok++ : $bad++;
            }
        }
        if ($prev && Tenant::id() !== $prev->id) {
            Tenant::set($prev);
        }

        return [$due->count(), $ok, $bad];
    }

    public static function sign(string $secret, int $t, string $body): string
    {
        return hash_hmac('sha256', $t.'.'.$body, $secret);
    }

    public static function signatureHeader(WebhookEndpoint $ep, int $t, string $body): string
    {
        $h = 't='.$t.',v1='.self::sign($ep->secret, $t, $body);
        if ($ep->previous_secret && $ep->previous_expires_at?->isFuture()) {
            $h .= ',v1='.self::sign($ep->previous_secret, $t, $body);
        }

        return $h;
    }

    /** One POST. Updates the delivery (and the endpoint's health) and returns it. */
    public static function attempt(WebhookDelivery $d, bool $manual = false): WebhookDelivery
    {
        $ep = WebhookEndpoint::withoutGlobalScope('hotel')->find($d->webhook_endpoint_id);
        $body = json_encode($d->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $t = time();
        $code = null;
        $resp = null;
        $error = null;
        $start = microtime(true);
        try {
            [$host, $port, $ip] = self::resolveSafe($ep->url);
            $pin = str_contains($ip, ':') ? "[{$ip}]" : $ip;   // IPv6 must be bracketed for CURLOPT_RESOLVE
            $response = Http::withHeaders([
                'User-Agent' => 'Nowills-Webhooks/1.0', 'X-Nowills-Event' => $d->event, 'X-Nowills-Delivery' => (string) $d->id,
                'X-Nowills-Event-Id' => $d->event_id, 'X-Nowills-Signature' => self::signatureHeader($ep, $t, $body),
            ])->withBody($body, 'application/json')->timeout(10)->connectTimeout(5)
                ->withOptions(['allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$pin}"]]])
                ->post($ep->url);
            $code = $response->status();
            $resp = mb_substr((string) $response->body(), 0, 2000);
            if (! $response->successful()) {
                $error = $code >= 300 && $code < 400 ? "Redirect ({$code}) – redirects are not followed; use the final URL" : "HTTP {$code}";
            }
        } catch (ConnectionException $e) {
            $error = 'Connection failed: '.mb_substr($e->getMessage(), 0, 180);
        } catch (RuntimeException $e) {
            $error = mb_substr($e->getMessage(), 0, 200);
        }
        $ms = (int) round((microtime(true) - $start) * 1000);
        $attempts = $d->attempts + 1;
        $log = array_slice(array_merge($d->attempt_log ?? [], [['at' => now()->toIso8601String(), 'code' => $code, 'ms' => $ms, 'error' => $error, 'manual' => $manual]]), -10);
        $fill = ['attempts' => min(255, $attempts), 'response_code' => $code, 'response_body' => $resp, 'error' => $error, 'duration_ms' => $ms, 'attempt_log' => $log];
        if ($error === null) {
            $d->forceFill($fill + ['status' => 'success', 'delivered_at' => now(), 'next_attempt_at' => null])->save();
            $ep->forceFill(['consecutive_failures' => 0, 'last_success_at' => now()])->save();

            return $d;
        }
        $dead = $attempts >= self::MAX_ATTEMPTS;
        $d->forceFill($fill + ['status' => $dead ? 'failed' : 'pending',
            'next_attempt_at' => $dead ? null : now()->addSeconds(self::BACKOFF[min($attempts, count(self::BACKOFF)) - 1])])->save();
        $ep->forceFill(['consecutive_failures' => $ep->consecutive_failures + 1, 'last_failure_at' => now()])->save();
        $limit = ApiService::cfg()['disable_after'];
        if ($ep->is_active && $ep->consecutive_failures >= $limit) {
            $ep->forceFill(['is_active' => false, 'disabled_at' => now(), 'disabled_reason' => "{$limit} failed attempts in a row – last: {$error}"])->save();
            ActivityLogger::log('api', 'webhook_disabled', "Webhook \"{$ep->name}\" switched off after {$limit} consecutive failures", $ep);
            rescue(fn () => LogBookService::system("Webhook switched off: {$ep->name}",
                "Deliveries to {$ep->host()} failed {$limit} times in a row (last: {$error}). Pending events are kept – fix the receiver, then enable the endpoint under Administration › API & Webhooks.",
                'maintenance', 'high', ['department' => 'management'], true), null, true);
        }

        return $d;
    }

    /** @return array{0: string, 1: int, 2: string} host, port and the vetted IP to pin */
    public static function resolveSafe(string $url): array
    {
        $p = parse_url($url);
        $host = strtolower(trim($p['host'] ?? '', '[]'));
        $port = (int) ($p['port'] ?? (strtolower($p['scheme'] ?? '') === 'http' ? 80 : 443));
        $cfg = ApiService::cfg();
        if (strtolower($p['scheme'] ?? '') === 'http' && ! $cfg['allow_http']) {
            throw new RuntimeException('Blocked: plain http is not allowed (API settings).');
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (self::$resolver ? (self::$resolver)($host) : (gethostbynamel($host) ?: []));
        if (! $ips) {
            throw new RuntimeException("DNS: {$host} does not resolve.");
        }
        foreach ($ips as $ip) {
            if (! $cfg['allow_private'] && ! self::isPublicIp($ip)) {
                throw new RuntimeException("Blocked: {$host} resolves to a private / internal address ({$ip}).");
            }
        }

        return [$host, $port, $ips[0]];
    }

    // ───────────────────────────── console data ─────────────────────────────

    public static function stats(int $hours = 24): array
    {
        $q = WebhookDelivery::where('created_at', '>=', now()->subHours($hours));
        $total = (clone $q)->count();
        $ok = (clone $q)->where('status', 'success')->count();

        return ['total' => $total, 'success' => $ok, 'pending' => WebhookDelivery::where('status', 'pending')->count(),
            'failed' => WebhookDelivery::where('status', 'failed')->where('updated_at', '>=', now()->subDays(7))->count(),
            'rate' => $total ? round($ok * 100 / $total, 1) : null, 'disabled' => WebhookEndpoint::where('is_active', false)->whereNotNull('disabled_reason')->count()];
    }

    /** nav badge: switched-off endpoints + deliveries that gave up in the last 7 days */
    public static function attentionCount(): int
    {
        return WebhookEndpoint::where('is_active', false)->whereNotNull('disabled_reason')->count()
            + WebhookDelivery::where('status', 'failed')->where('updated_at', '>=', now()->subDays(7))->count();
    }

    /** per endpoint over 7 days: [id => [n, ok]] */
    public static function health(): Collection
    {
        return WebhookDelivery::where('created_at', '>=', now()->subDays(7))->groupBy('webhook_endpoint_id')
            ->selectRaw("webhook_endpoint_id, count(*) n, sum(status = 'success') ok, sum(status = 'pending') pending")->get()->keyBy('webhook_endpoint_id');
    }

    public static function prune(): int
    {
        return WebhookDelivery::whereIn('status', ['success', 'failed'])->where('updated_at', '<', now()->subDays(ApiService::cfg()['log_days']))->delete();
    }
}
