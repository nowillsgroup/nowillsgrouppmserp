<?php

namespace App\Services\Interfaces\Drivers;

use App\Models\ChannelConnection;
use App\Services\ChannelService;
use App\Services\IcalService;
use App\Services\Interfaces\ChannelDriver;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * v7.37 – mirror adapter: one HTTP JSON/XML feed per channel. Speaks the hub
 * protocol for ARI push, but tolerates plain REST mirrors (no HMAC) when the
 * connection has no secret — the template real vendor adapters copy
 * (Booking.com EPS, Expedia EPS, Hotelbeds, SiteMinder … each then adds its
 * own auth + payload dialect; see docs/CHANNEL-ADAPTER.md).
 */
class MirrorChannelDriver implements ChannelDriver
{
    public function id(): string
    {
        return 'mirror';
    }

    public function label(): string
    {
        return 'Mirror feed (HTTP JSON/XML adapter)';
    }

    public function test(ChannelConnection $c): array
    {
        $res = $this->request($c, 'GET', $this->endpoint($c));
        $ok = $res->successful();

        return ['ok' => $ok, 'message' => 'HTTP '.$res->status().' – '.($ok ? 'mirror endpoint reachable.' : mb_substr($res->body(), 0, 150))];
    }

    public function pushAri(ChannelConnection $c, array $chunks, string $kind, callable $ack): array
    {
        $requests = 0;
        $http = null;
        foreach ($chunks as $chunk) {
            $body = json_encode(ChannelService::payload($c, $chunk, $kind), JSON_UNESCAPED_SLASHES);
            $res = $this->request($c, 'POST', $this->endpoint($c), $body);
            $requests++;
            $http = $res->status();
            if (! $res->successful()) {
                throw new RuntimeException('Mirror returned HTTP '.$res->status());
            }
            $ack($chunk);
        }

        return ['requests' => $requests, 'http' => $http];
    }

    public function pullBookings(ChannelConnection $c, ?\DateTimeInterface $since): array
    {
        $url = $this->endpoint($c).($since ? '?since='.urlencode($since->format('c')) : '');
        $res = $this->request($c, 'GET', $url);
        if (! $res->successful()) {
            throw new RuntimeException('Mirror pull returned HTTP '.$res->status());
        }

        return $res->json('bookings') ?? [];
    }

    public function cancelBooking(ChannelConnection $c, string $externalRef): array
    {
        $res = $this->request($c, 'POST', $this->endpoint($c).'/'.rawurlencode($externalRef).'/cancel', json_encode(['ref' => $externalRef]));
        return ['ok' => $res->successful(), 'message' => 'HTTP '.$res->status()];
    }

    protected function endpoint(ChannelConnection $c): string
    {
        if (! $c->endpoint_url) {
            throw new RuntimeException('No mirror endpoint URL configured.');
        }
        if (! config('pms.channel_allow_private')) {
            IcalService::assertSafeUrl($c->endpoint_url);
        }

        return rtrim($c->endpoint_url, '/');
    }

    protected function request(ChannelConnection $c, string $method, string $url, ?string $body = null)
    {
        $headers = ['User-Agent' => 'NowillsPMS-Channel/1.0', 'Accept' => 'application/json, application/xml'];
        if ($c->api_key) {
            $headers['Authorization'] = 'Bearer '.$c->api_key;
        }
        if ($body !== null && $c->secret) {
            $ts = (string) time();
            $headers['X-Nowills-Timestamp'] = $ts;
            $headers['X-Nowills-Signature'] = 'sha256='.ChannelService::sign($c->secret, $ts, $body);
        }
        $req = Http::timeout(20)->withHeaders($headers);
        if ($body !== null) {
            $req = $req->withBody($body, 'application/json');
        }

        return $req->send($method, $url);
    }
}
