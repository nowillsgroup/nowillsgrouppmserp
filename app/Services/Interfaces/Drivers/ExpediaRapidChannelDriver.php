<?php

namespace App\Services\Interfaces\Drivers;

use App\Models\ChannelConnection;
use App\Services\Interfaces\ChannelDriver;
use Illuminate\Support\Facades\Http;

/**
 * v7.40 Expedia Rapid channel driver — the second real OTA adapter.
 *
 * Speaks Rapid-style JSON at {endpoint_url}/v3/* and uses Rapid's request
 * signing, the part every Expedia integration trips on:
 *
 *   signature    = base64(HMAC-SHA512(api_secret, request_path + json_body))
 *   Authorization: {api_key}:{signature}
 *
 * Credentials on the connection: property_code = Expedia property id,
 * api_key/api_secret in the connection's credentials (same storage as the
 * EPS driver). Endpoints:
 *   GET  /v3/rooms                              property content (test)
 *   GET  /v3/booking?records.updated_from=…     booking retrieval
 *   PUT  /v3/availability                       ARI push (chunked)
 *   POST /v3/booking/{id}/supplier-cancel       cancel at the channel
 *
 * The local simulator (deploy/dev/expedia-sim.mjs) REJECTS bad signatures,
 * so an e2e pass proves the signing maths, not just the plumbing.
 */
class ExpediaRapidChannelDriver implements ChannelDriver
{
    public function id(): string
    {
        return 'expedia';
    }

    public function label(): string
    {
        return 'Expedia Rapid (JSON, HMAC-SHA512 request signing)';
    }

    protected function base(ChannelConnection $c): string
    {
        return rtrim((string) $c->endpoint_url, '/');
    }

    /** Rapid request signing: base64(HMAC-SHA512(secret, path + body)). */
    protected function sign(ChannelConnection $c, string $path, string $body = ''): string
    {
        return base64_encode(hash_hmac('sha512', $path.$body, (string) $c->secret, true)); // secret column (encrypted cast)
    }

    protected function headers(ChannelConnection $c, string $path, string $body = ''): array
    {
        return [
            'Authorization' => (string) $c->api_key.':'.$this->sign($c, $path, $body),
            'Accept' => 'application/json',
        ];
    }

    protected function propertyRef(ChannelConnection $c): string
    {
        return (string) $c->property_code;
    }

    public function test(ChannelConnection $c): array
    {
        if (! $c->endpoint_url || ! $c->property_code || ! $c->api_key || ! $c->secret) {
            return ['ok' => false, 'message' => 'Set the Rapid endpoint URL, property id, api_key and api_secret on this connection first.'];
        }
        $path = '/v3/rooms?property_id='.rawurlencode($this->propertyRef($c));
        try {
            $res = Http::timeout(15)->withHeaders($this->headers($c, $path))->get($this->base($c).$path);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Rapid endpoint unreachable: '.$e->getMessage()];
        }

        return $res->successful()
            ? ['ok' => true, 'message' => 'Rapid answered for property '.$this->propertyRef($c).' (signed request accepted).']
            : ['ok' => false, 'message' => 'Rapid refused: HTTP '.$res->status().' – '.mb_substr($res->body(), 0, 140)];
    }

    public function pushAri(ChannelConnection $c, array $chunks, string $kind, callable $ack): array
    {
        $requests = 0;
        $http = null;
        foreach ($chunks as $chunk) {
            $path = '/v3/availability';
            $body = json_encode([
                'property_id' => $this->propertyRef($c),
                'kind' => $kind, // rates | availability
                'values' => array_values((array) $chunk),
            ], JSON_UNESCAPED_SLASHES);
            $requests++;
            try {
                $res = Http::timeout(20)->withHeaders($this->headers($c, $path, (string) $body))
                    ->withBody((string) $body, 'application/json')->put($this->base($c).$path);
            } catch (\Throwable $e) {
                throw new \RuntimeException('Rapid ARI push failed: '.$e->getMessage(), previous: $e);
            }
            $http = $res->status();
            if (! $res->successful()) {
                throw new \RuntimeException("Rapid rejected ARI chunk {$requests}: HTTP {$http} – ".mb_substr($res->body(), 0, 160));
            }
            $ack($chunk);
        }

        return ['requests' => $requests, 'http' => $http];
    }

    public function pullBookings(ChannelConnection $c, ?\DateTimeInterface $since): array
    {
        $path = '/v3/booking?property_id='.rawurlencode($this->propertyRef($c))
            .($since ? '&records.updated_from='.urlencode($since->format('c')) : '');
        try {
            $res = Http::timeout(20)->withHeaders($this->headers($c, $path))->get($this->base($c).$path);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Rapid bookings pull failed: '.$e->getMessage(), previous: $e);
        }
        if (! $res->successful()) {
            throw new \RuntimeException('Rapid bookings pull failed: HTTP '.$res->status().' – '.mb_substr($res->body(), 0, 160));
        }

        $out = [];
        foreach ((array) $res->json('itineraries', []) as $r) {
            $cancelled = (bool) ($r['cancel'] ?? false);
            $modified = (bool) ($r['modified'] ?? false);
            $out[] = [
                'event' => $cancelled ? 'cancel' : ($modified ? 'modify' : 'new'),
                'booking_id' => (string) ($r['itinerary_id'] ?? ''),
                'arrival' => (string) ($r['checkin'] ?? ''),
                'departure' => (string) ($r['checkout'] ?? ''),
                'currency' => $r['total']['currency'] ?? 'USD',
                'guest' => [
                    'first_name' => (string) ($r['guest']['first_name'] ?? 'Guest'),
                    'last_name' => $r['guest']['last_name'] ?? null,
                    'email' => $r['guest']['email'] ?? null,
                    'phone' => $r['guest']['phone'] ?? null,
                    'country' => $r['guest']['country'] ?? null,
                ],
                'rooms' => [[
                    'room_code' => (string) ($r['room_id'] ?? ''),
                    'rate_code' => $r['rate_plan_id'] ?? null,
                    'adults' => (int) ($r['number_of_adults'] ?? 2),
                    'children' => (int) ($r['number_of_children'] ?? 0),
                    'amount' => (float) ($r['total']['amount'] ?? 0),
                ]],
                'total' => isset($r['total']['amount']) ? (float) $r['total']['amount'] : null,
                'remarks' => isset($r['special_request']) ? 'Special requests: '.$r['special_request'] : null,
            ];
        }

        return $out;
    }

    public function cancelBooking(ChannelConnection $c, string $externalRef): array
    {
        $path = '/v3/booking/'.rawurlencode($externalRef).'/supplier-cancel';
        $body = json_encode(['itinerary_id' => $externalRef], JSON_UNESCAPED_SLASHES);
        try {
            $res = Http::timeout(15)->withHeaders($this->headers($c, $path, (string) $body))
                ->withBody((string) $body, 'application/json')->post($this->base($c).$path);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Rapid cancel failed: '.$e->getMessage()];
        }

        return $res->successful()
            ? ['ok' => true, 'message' => "Expedia itinerary {$externalRef} cancelled at the channel."]
            : ['ok' => false, 'message' => 'Rapid cancel failed: HTTP '.$res->status().' – '.mb_substr($res->body(), 0, 160)];
    }
}
