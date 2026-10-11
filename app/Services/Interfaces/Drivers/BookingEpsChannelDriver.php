<?php

namespace App\Services\Interfaces\Drivers;

use App\Models\ChannelConnection;
use App\Services\Interfaces\ChannelDriver;
use Illuminate\Support\Facades\Http;

/**
 * v7.39 – Booking.com EPS (External Connectivity Standard) adapter.
 *
 * Speaks the EPS message set over HTTPS JSON at {endpoint_url}/eps/v1/*:
 *   GET  /properties/{property_code}              handshake / test
 *   POST /ari                                     OTA_HotelAvailNotif + OTA_HotelRateAmountNotif (rates & restrictions)
 *   GET  /reservations?since=ISO8601              OTA_HotelResNotif list (new / modified / cancelled)
 *   POST /reservations/{id}/cancel                cancellation advice
 *
 * Auth: Authorization: Bearer {api_key} plus X-Booking-Property: {property_code}.
 * Room and rate codes are the channel-side codes from the connection's room /
 * rate mappings — the PMS mapping pipeline (ChannelService::receive) resolves
 * them to room types and applies the bookings.
 *
 * The message shapes mirror Booking.com's OTA XML payloads as JSON (field
 * names follow the OTA_Hotel* elements); a local EPS simulator
 * (deploy/dev/booking-eps-sim.mjs) speaks the same dialect for e2e testing.
 * See docs/BOOKING-EPS.md for the full mapping.
 */
class BookingEpsChannelDriver implements ChannelDriver
{
    public function id(): string
    {
        return 'booking';
    }

    public function label(): string
    {
        return 'Booking.com EPS (External Connectivity Standard)';
    }

    protected function headers(ChannelConnection $c): array
    {
        return [
            'Authorization' => 'Bearer '.(string) $c->api_key,
            'X-Booking-Property' => (string) ($c->property_code ?? ''),
            'Accept' => 'application/json',
        ];
    }

    protected function base(ChannelConnection $c): string
    {
        return rtrim((string) $c->endpoint_url, '/');
    }

    protected function propertyRef(ChannelConnection $c): string
    {
        return (string) ($c->property_code ?: '');
    }

    public function test(ChannelConnection $c): array
    {
        if (! $c->endpoint_url || ! $c->property_code) {
            return ['ok' => false, 'message' => 'Set the EPS endpoint URL and the Booking.com property id on this connection first.'];
        }
        try {
            $res = Http::timeout(15)->withHeaders($this->headers($c))->get($this->base($c).'/eps/v1/properties/'.rawurlencode($this->propertyRef($c)));
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'EPS endpoint unreachable: '.$e->getMessage()];
        }

        return $res->successful()
            ? ['ok' => true, 'message' => 'EPS handshake OK – property '.$this->propertyRef($c).' answered ('.($res->json('name') ?? 'Booking.com').').']
            : ['ok' => false, 'message' => 'EPS handshake failed: HTTP '.$res->status().' – '.mb_substr($res->body(), 0, 160)];
    }

    /** Chunks of [room_type_id => [date => ari-row]] → OTA_HotelAvailNotif / OTA_HotelRateAmountNotif JSON. */
    public function pushAri(ChannelConnection $c, array $chunks, string $kind, callable $ack): array
    {
        $requests = 0;
        $http = null;
        foreach ($chunks as $chunk) {
            ksort($chunk, SORT_NUMERIC);
            $rooms = [];
            foreach ($chunk as $byDate) {
                $first = reset($byDate);
                $dates = [];
                foreach ($byDate as $date => $v) {
                    $dates[] = [
                        'date' => $date,
                        'available' => (int) $v['available'],
                        'closed' => ! empty($v['stop_sell']),
                        'min_stay' => (int) ($v['min_stay'] ?? 1),
                        'closed_to_arrival' => ! empty($v['cta']),
                        'rates' => array_map(fn ($code, $price) => ['rate_code' => (string) $code, 'rate' => round((float) $price, 2)],
                            array_keys($v['rates'] ?? []), array_values($v['rates'] ?? [])),
                    ];
                }
                $rooms[] = ['room_code' => $first['code'], 'dates' => $dates];
            }
            $body = json_encode([
                'message_type' => $kind === 'rates' ? 'OTA_HotelRateAmountNotif' : 'OTA_HotelAvailNotif',
                'property_id' => $this->propertyRef($c),
                'currency' => hotel()?->currency ?: 'GHS',
                'timestamp' => now()->toIso8601String(),
                'rooms' => $rooms,
            ], JSON_UNESCAPED_SLASHES);

            $res = Http::timeout(20)->withHeaders($this->headers($c))->withBody($body, 'application/json')
                ->post($this->base($c).'/eps/v1/ari');
            $requests++;
            $http = $res->status();
            if (! $res->successful()) {
                throw new \RuntimeException("Booking.com EPS rejected ARI chunk {$requests}: HTTP {$http} – ".mb_substr($res->body(), 0, 160));
            }
            $ack($chunk);
        }

        return ['requests' => $requests, 'http' => $http];
    }

    /** OTA_HotelResNotif list → raw booking rows for the mapping pipeline. */
    public function pullBookings(ChannelConnection $c, ?\DateTimeInterface $since): array
    {
        $url = $this->base($c).'/eps/v1/reservations'.($since ? '?since='.urlencode($since->format('c')) : '');
        try {
            $res = Http::timeout(20)->withHeaders($this->headers($c))->get($url);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Booking.com EPS reservations pull failed: '.$e->getMessage(), previous: $e);
        }
        if (! $res->successful()) {
            throw new \RuntimeException('Booking.com EPS reservations pull failed: HTTP '.$res->status().' – '.mb_substr($res->body(), 0, 160));
        }

        $out = [];
        foreach ((array) $res->json('reservations', []) as $r) {
            $status = strtolower((string) ($r['status'] ?? 'new'));
            $out[] = [
                'event' => $status === 'cancelled' ? 'cancel' : ($status === 'modified' ? 'modify' : 'new'),
                'booking_id' => (string) ($r['reservation_id'] ?? ''),
                'arrival' => (string) ($r['checkin'] ?? ''),
                'departure' => (string) ($r['checkout'] ?? ''),
                'currency' => $r['currency'] ?? 'EUR',
                'guest' => [
                    'first_name' => (string) ($r['guest']['first_name'] ?? 'Guest'),
                    'last_name' => $r['guest']['last_name'] ?? null,
                    'email' => $r['guest']['email'] ?? null,
                    'phone' => $r['guest']['phone'] ?? null,
                    'country' => $r['guest']['country'] ?? null,
                ],
                'rooms' => [[
                    'room_code' => (string) ($r['room_code'] ?? ''),
                    'rate_code' => $r['rate_code'] ?? null,
                    'adults' => (int) ($r['adults'] ?? 2),
                    'children' => (int) ($r['children'] ?? 0),
                    'amount' => (float) ($r['total'] ?? 0),
                ]],
                'total' => isset($r['total']) ? (float) $r['total'] : null,
                'remarks' => isset($r['special_requests']) ? 'Special requests: '.$r['special_requests'] : null,
            ];
        }

        return $out;
    }

    public function cancelBooking(ChannelConnection $c, string $externalRef): array
    {
        try {
            $res = Http::timeout(15)->withHeaders($this->headers($c))
                ->post($this->base($c).'/eps/v1/reservations/'.rawurlencode($externalRef).'/cancel');
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'EPS cancel failed: '.$e->getMessage()];
        }

        return $res->successful()
            ? ['ok' => true, 'message' => "Booking.com reservation {$externalRef} cancelled at the channel."]
            : ['ok' => false, 'message' => 'EPS cancel failed: HTTP '.$res->status().' – '.mb_substr($res->body(), 0, 160)];
    }
}
