<?php

namespace App\Services\Interfaces\Drivers;

use App\Models\ChannelConnection;
use App\Services\ChannelService;
use App\Services\IcalService;
use App\Services\Interfaces\ChannelDriver;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * v7.37 – the built-in channel hub protocol: signed JSON over HTTPS.
 *
 * ARI (outbound):  POST {endpoint_url}   body = ChannelService::payload()
 *                  headers X-Nowills-Timestamp / X-Nowills-Signature (HMAC-SHA256)
 *                  and optional Authorization: Bearer {api_key}
 * Test:            POST {endpoint_url}   payload kind = "test" (must answer 2xx)
 * Bookings (pull): GET  {endpoint_url}/bookings?since=ISO8601  (same headers)
 * Cancel:          POST {endpoint_url}/bookings/{ref}/cancel   (same headers)
 *
 * Channel hubs and OTA adapters that speak this protocol plug in with zero
 * code; vendor-specific protocols implement ChannelDriver instead.
 */
class HubChannelDriver implements ChannelDriver
{
    public function id(): string
    {
        return 'hub';
    }

    public function label(): string
    {
        return 'Channel hub / OTA API (signed JSON)';
    }

    public function test(ChannelConnection $c): array
    {
        try {
            $this->assertEndpoint($c);
            $body = json_encode(ChannelService::payload($c, [], 'test'), JSON_UNESCAPED_SLASHES);
            $res = Http::timeout(15)->withHeaders($this->headers($c, $body))->withBody($body, 'application/json')->post($c->endpoint_url);

            return $res->successful()
                ? ['ok' => true, 'message' => 'HTTP '.$res->status().' – endpoint answered the signed test call.']
                : ['ok' => false, 'message' => 'HTTP '.$res->status().' – '.mb_substr((string) ($res->json('error') ?? $res->json('message') ?? $res->body()), 0, 180)];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => mb_substr($e->getMessage(), 0, 180)];
        }
    }

    public function pushAri(ChannelConnection $c, array $chunks, string $kind, callable $ack): array
    {
        $this->assertEndpoint($c);
        $requests = 0;
        $http = null;
        foreach ($chunks as $chunk) {
            $body = json_encode(ChannelService::payload($c, $chunk, $kind), JSON_UNESCAPED_SLASHES);
            $res = Http::timeout(20)->withHeaders($this->headers($c, $body))->withBody($body, 'application/json')->post($c->endpoint_url);
            $requests++;
            $http = $res->status();
            if (! $res->successful()) {
                $detail = rescue(fn () => $res->json('error') ?? $res->json('message'), null, false);
                throw new RuntimeException('Channel returned HTTP '.$res->status().($detail ? ': '.mb_substr(is_string($detail) ? $detail : json_encode($detail), 0, 200) : ''));
            }
            $ack($chunk);   // chunks delivered before a failure stay acknowledged
        }

        return ['requests' => $requests, 'http' => $http];
    }

    public function pullBookings(ChannelConnection $c, ?\DateTimeInterface $since): array
    {
        $this->assertEndpoint($c);
        $url = rtrim($c->endpoint_url, '/').'/bookings'.($since ? '?since='.urlencode($since->format('c')) : '');
        $res = Http::timeout(20)->withHeaders($this->headers($c, ''))->get($url);
        if (! $res->successful()) {
            throw new RuntimeException('Booking pull returned HTTP '.$res->status());
        }

        return $res->json('bookings') ?? [];
    }

    public function cancelBooking(ChannelConnection $c, string $externalRef): array
    {
        $this->assertEndpoint($c);
        $body = json_encode(['ref' => $externalRef], JSON_UNESCAPED_SLASHES);
        $res = Http::timeout(20)->withHeaders($this->headers($c, $body))->withBody($body, 'application/json')
            ->post(rtrim($c->endpoint_url, '/').'/bookings/'.rawurlencode($externalRef).'/cancel');

        return $res->successful()
            ? ['ok' => true, 'message' => 'Cancelled at the channel (HTTP '.$res->status().').']
            : ['ok' => false, 'message' => 'Cancel returned HTTP '.$res->status()];
    }

    protected function assertEndpoint(ChannelConnection $c): void
    {
        if (! $c->endpoint_url) {
            throw new RuntimeException('No ARI endpoint URL configured.');
        }
        if (! config('pms.channel_allow_private')) {
            IcalService::assertSafeUrl($c->endpoint_url);
        }
    }

    protected function headers(ChannelConnection $c, string $body): array
    {
        $ts = (string) time();
        $headers = [
            'X-Nowills-Timestamp' => $ts,
            'X-Nowills-Signature' => 'sha256='.ChannelService::sign($c->secret, $ts, $body),
            'User-Agent' => 'NowillsPMS-Channel/1.0',
            'Accept' => 'application/json',
        ];
        if ($c->api_key) {
            $headers['Authorization'] = 'Bearer '.$c->api_key;
        }

        return $headers;
    }
}
