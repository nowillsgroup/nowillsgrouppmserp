<?php

namespace App\Services\Interfaces\Drivers;

use App\Models\ChannelConnection;
use App\Services\Interfaces\ChannelDriver;
use Illuminate\Support\Facades\Http;

/**
 * v7.42 SiteMinder channel driver — the OTA XML dialect.
 *
 * SiteMinder is a channel manager rather than an OTA: one connection of this shape carries the inventory out to, and
 * the reservations back from, the sites it fronts (Booking.com, Expedia, Agoda, Airbnb, Hostelworld, …). It is also
 * the first adapter here that does not speak JSON, which is the point — the driver contract is transport-agnostic,
 * so an OTA_AvailRateNotifyRQ push and an OTA_HotelResModifyNotifRS pull sit behind exactly the same five methods
 * as the Rapid and EPS adapters.
 *
 * Auth: Authorization: Bearer {api_key} (SiteMinder Platform API tokens). The hotel is identified by
 * property_code, both in the path and in the HotelCode attribute of the ARI document.
 *
 *   GET  {endpoint_url}/hotels/{property_code}                                  property content (test)
 *   POST {endpoint_url}/hotels/{property_code}/ari                              ARI push, one chunk per request
 *   GET  {endpoint_url}/hotels/{property_code}/reservations?modified_since=ISO   reservation feed
 *   POST {endpoint_url}/hotels/{property_code}/reservations/{ref}/cancel         cancel at the channel
 *
 * Wire formats (all UTF-8 XML unless noted):
 *
 *   push    <OTA_AvailRateNotifyRQ HotelCode CurrencyCode Version TimeStamp>
 *             <AvailStatusMessages><AvailStatusMessage BookingLimit BookingLimitMessageType MinLengthOfStay>
 *               <StatusApplicationControl RoomTypeCode Start End/>
 *               <RestrictionStatus Status="Open|Close" [RestrictionType="Arrival"]/>   ← stop-sell and CTA
 *             </AvailStatusMessage>…</AvailStatusMessages>
 *             <Rates><Rate RoomTypeCode RatePlanCode EffectiveDate Amount/>…</Rates>
 *           </OTA_AvailRateNotifyRQ>
 *   ack     <OTA_AvailRateNotifyRS><Success/></OTA_AvailRateNotifyRS>   (or <Errors><Error ShortText="…"/>)
 *   pull    <OTA_HotelResModifyNotifRS><HotelReservations><HotelReservation ResModifyType="New|Modify|Cancel">
 *             <UniqueID ID="…"/><RoomStays><RoomStay>…</RoomStay></RoomStays><ResGlobalInfo>…</ResGlobalInfo>
 *           </HotelReservation>…</HotelReservations></OTA_HotelResModifyNotifRS>
 *   cancel  <OTA_CancelRQ CancelType="Both"><UniqueID Type="14" ID="{ref}"/></OTA_CancelRQ>
 *
 * The local simulator (deploy/dev/siteminder-sim.mjs) rejects a wrong bearer token with 401 and answers a malformed
 * document with an OTA <Errors> block, so a passing e2e proves the dialect and the auth, not just the plumbing.
 */
class SiteMinderChannelDriver implements ChannelDriver
{
    /** OTA AgeQualifyingCode: 10 = adult, 8 = child. */
    protected const AQ_ADULT = '10';

    protected const AQ_CHILD = '8';

    public function id(): string
    {
        return 'siteminder';
    }

    public function label(): string
    {
        return 'SiteMinder (OTA XML – AvailRateNotify / HotelResModifyNotif)';
    }

    protected function base(ChannelConnection $c): string
    {
        return rtrim((string) $c->endpoint_url, '/');
    }

    protected function hotel(ChannelConnection $c): string
    {
        return (string) ($c->property_code ?: '');
    }

    protected function headers(): array
    {
        return ['Accept' => 'application/xml'];
    }

    protected function withAuth(ChannelConnection $c, $pending)
    {
        return $c->api_key ? $pending->withToken((string) $c->api_key) : $pending;
    }

    protected function esc(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** Parse an XML body, or null when it is not XML. libxml warnings are collected, never emitted. */
    protected function xml(string $body): ?\SimpleXMLElement
    {
        $body = trim($body);
        if ($body === '' || $body[0] !== '<') {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $doc === false ? null : $doc;
    }

    /** The ShortText of the first <Error> in an OTA fault document, else null. */
    protected function fault(string $body): ?string
    {
        $doc = $this->xml($body);
        $err = $doc?->xpath('//Error');
        if (! $err) {
            return null;
        }
        $short = trim((string) ($err[0]['ShortText'] ?? ''));
        $code = trim((string) ($err[0]['Code'] ?? ''));

        return trim(($code !== '' ? "OTA error {$code}: " : '').($short !== '' ? $short : (string) $err[0]->asXML()));
    }

    public function test(ChannelConnection $c): array
    {
        if (! $c->endpoint_url || ! $c->property_code || ! $c->api_key) {
            return ['ok' => false, 'message' => 'Set the SiteMinder endpoint URL, hotel code and bearer token on this connection first.'];
        }
        $path = '/hotels/'.rawurlencode($this->hotel($c));
        try {
            $res = $this->withAuth($c, Http::timeout(15)->withHeaders($this->headers()))->get($this->base($c).$path);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'SiteMinder endpoint unreachable: '.$e->getMessage()];
        }
        if (! $res->successful()) {
            return ['ok' => false, 'message' => 'SiteMinder refused: HTTP '.$res->status().' – '.mb_substr($this->fault($res->body()) ?: $res->body(), 0, 140)];
        }
        $hotel = $res->json('name') ?: $this->hotel($c);

        return ['ok' => true, 'message' => 'SiteMinder answered for hotel '.$hotel.' ('.$this->hotel($c).') – bearer token accepted.'];
    }

    public function pushAri(ChannelConnection $c, array $chunks, string $kind, callable $ack): array
    {
        $requests = 0;
        $http = null;
        $currency = \App\Support\Tenant::hotel()?->currency ?: 'GHS';
        foreach ($chunks as $chunk) {
            $path = '/hotels/'.rawurlencode($this->hotel($c)).'/ari';
            $body = $this->ariDocument($c, $chunk, $kind, $currency);
            $requests++;
            try {
                $res = $this->withAuth($c, Http::timeout(25)->withHeaders($this->headers()))
                    ->withBody($body, 'application/xml')->post($this->base($c).$path);
            } catch (\Throwable $e) {
                throw new \RuntimeException('SiteMinder ARI push failed: '.$e->getMessage(), previous: $e);
            }
            $http = $res->status();
            $doc = $this->xml($res->body());
            $accepted = $res->successful() && $doc !== null && ($doc->getName() === 'OTA_AvailRateNotifyRS') && ! empty($doc->xpath('//Success'));
            if (! $accepted) {
                // Nothing from this chunk on is acked, so the push log keeps the unsent room-nights for retry.
                throw new \RuntimeException('SiteMinder rejected ARI chunk '.$requests.': HTTP '.$http.' – '
                    .mb_substr($this->fault($res->body()) ?: $res->body(), 0, 160));
            }
            $ack($chunk);
        }

        return ['requests' => $requests, 'http' => $http];
    }

    /** One OTA_AvailRateNotifyRQ per chunk: availability/restrictions per room-night, then the rates for those nights. */
    protected function ariDocument(ChannelConnection $c, array $chunk, string $kind, string $currency): string
    {
        $avail = [];
        $rates = [];
        foreach ($chunk as $byDate) {
            foreach ($byDate as $date => $v) {
                $code = $this->esc((string) $v['code']);
                $closed = ! empty($v['stop_sell']);
                $limit = $closed ? 0 : (int) $v['available'];
                $restr = '<RestrictionStatus Status="'.($closed ? 'Close' : 'Open').'"/>'
                    .(! empty($v['cta']) ? '<RestrictionStatus RestrictionType="Arrival" Status="Close"/>' : '');
                $avail[] = '<AvailStatusMessage BookingLimit="'.$limit.'" BookingLimitMessageType="SetLimit"'
                    .' MinLengthOfStay="'.(int) ($v['min_stay'] ?? 0).'">'
                    .'<StatusApplicationControl RoomTypeCode="'.$code.'" Start="'.$this->esc((string) $date).'" End="'.$this->esc((string) $date).'"/>'
                    .$restr.'</AvailStatusMessage>';
                foreach ((array) ($v['rates'] ?? []) as $rateCode => $price) {
                    $rates[] = '<Rate RoomTypeCode="'.$code.'" RatePlanCode="'.$this->esc((string) $rateCode).'"'
                        .' EffectiveDate="'.$this->esc((string) $date).'" Amount="'.number_format((float) $price, 2, '.', '').'"/>';
                }
            }
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<OTA_AvailRateNotifyRQ Version="1.0" TimeStamp="'.now()->toIso8601String().'"'
            .' HotelCode="'.$this->esc($this->hotel($c)).'" CurrencyCode="'.$this->esc((string) $currency).'">'
            .'<AvailStatusMessages>'.implode('', $avail).'</AvailStatusMessages>'
            .'<Rates>'.implode('', $rates).'</Rates>'
            .'</OTA_AvailRateNotifyRQ>';
    }

    public function pullBookings(ChannelConnection $c, ?\DateTimeInterface $since): array
    {
        $path = '/hotels/'.rawurlencode($this->hotel($c)).'/reservations'
            .($since ? '?modified_since='.urlencode($since->format('c')) : '');
        try {
            $res = $this->withAuth($c, Http::timeout(25)->withHeaders($this->headers()))->get($this->base($c).$path);
        } catch (\Throwable $e) {
            throw new \RuntimeException('SiteMinder reservation pull failed: '.$e->getMessage(), previous: $e);
        }
        if (! $res->successful()) {
            throw new \RuntimeException('SiteMinder reservation pull failed: HTTP '.$res->status().' – '
                .mb_substr($this->fault($res->body()) ?: $res->body(), 0, 160));
        }
        $doc = $this->xml($res->body());
        if (! $doc) {
            throw new \RuntimeException('SiteMinder returned a body that is not OTA XML ('.mb_substr($res->body(), 0, 80).').');
        }

        $out = [];
        foreach ($doc->xpath('//HotelReservation') ?: [] as $r) {
            $row = $this->row($r);
            if ($row !== null) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /** One <HotelReservation> element → the canonical raw row ChannelService::canonicalRow() accepts. */
    protected function row(\SimpleXMLElement $r): ?array
    {
        $ref = trim((string) ($r->xpath('./UniqueID/@ID')[0] ?? ''));
        if ($ref === '') {
            return null;
        }
        $modify = strtolower(trim((string) ($r['ResModifyType'] ?? 'new')));
        $event = in_array($modify, ['cancel', 'cancelled'], true) ? 'cancel' : (in_array($modify, ['modify', 'change', 'overlay'], true) ? 'modify' : 'new');

        $stay = $r->xpath('./ResGlobalInfo/TimeSpan')[0] ?? $r->xpath('./RoomStays/RoomStay/TimeSpan')[0] ?? null;
        $arrival = $stay ? trim((string) $stay['Start']) : '';
        $departure = $stay ? trim((string) $stay['End']) : '';

        $customer = $r->xpath('.//Customer')[0] ?? null;
        $name = $customer?->xpath('./PersonName')[0] ?? null;
        $total = $r->xpath('./ResGlobalInfo/Total')[0] ?? $r->xpath('./RoomStays/RoomStay/Total')[0] ?? null;
        $currency = trim((string) ($total['CurrencyCode'] ?? '')) ?: null;
        $comments = array_map(fn ($t) => trim((string) $t), $r->xpath('.//Comment/Text') ?: []);

        $rooms = [];
        $roomStays = $r->xpath('./RoomStays/RoomStay') ?: [];
        foreach ($roomStays as $rs) {
            $counts = [];
            foreach ($rs->xpath('./GuestCounts/GuestCount') ?: [] as $gc) {
                $counts[(string) ($gc['AgeQualifyingCode'] ?? self::AQ_ADULT)] = (int) ($gc['Count'] ?? 0);
            }
            $rsTotal = $rs->xpath('./Total')[0] ?? null;
            $rooms[] = [
                'room_code' => trim((string) ($rs->xpath('./RoomTypes/RoomType/@RoomTypeCode')[0] ?? '')),
                'rate_code' => ($code = $rs->xpath('./RatePlans/RatePlan/@RatePlanCode')[0] ?? null) ? trim((string) $code) : null,
                'adults' => $counts[self::AQ_ADULT] ?? 2,
                'children' => $counts[self::AQ_CHILD] ?? 0,
                'amount' => $rsTotal ? (float) ($rsTotal['AmountAfterTax'] ?? $rsTotal['AmountBeforeTax'] ?? 0) : 0.0,
            ];
        }
        if ($rooms === []) {
            $rooms[] = ['room_code' => '', 'rate_code' => null, 'adults' => 2, 'children' => 0, 'amount' => 0.0];
        }

        return [
            'event' => $event,
            'booking_id' => $ref,
            'arrival' => $arrival,
            'departure' => $departure,
            'currency' => $currency,
            'guest' => [
                'first_name' => trim((string) ($name?->xpath('./GivenName')[0] ?? '')) ?: 'Guest',
                'last_name' => ($sn = trim((string) ($name?->xpath('./Surname')[0] ?? ''))) !== '' ? $sn : null,
                'email' => ($e = trim((string) ($customer?->xpath('./Email')[0] ?? ''))) !== '' ? $e : null,
                'phone' => ($p = trim((string) ($customer?->xpath('./Telephone/@PhoneNumber')[0] ?? ''))) !== '' ? $p : null,
                'country' => ($cc = trim((string) ($customer?->xpath('./Address/CountryName/@Code')[0] ?? ''))) !== '' ? $cc : null,
            ],
            'rooms' => $rooms,
            'total' => $total ? (float) ($total['AmountAfterTax'] ?? $total['AmountBeforeTax'] ?? 0) : null,
            'remarks' => $comments === [] ? null : 'Channel comments: '.implode(' | ', $comments),
        ];
    }

    public function cancelBooking(ChannelConnection $c, string $externalRef): array
    {
        $path = '/hotels/'.rawurlencode($this->hotel($c)).'/reservations/'.rawurlencode($externalRef).'/cancel';
        $body = '<?xml version="1.0" encoding="UTF-8"?><OTA_CancelRQ Version="1.0" CancelType="Both" HotelCode="'
            .$this->esc($this->hotel($c)).'"><UniqueID Type="14" ID="'.$this->esc($externalRef).'"/></OTA_CancelRQ>';
        try {
            $res = $this->withAuth($c, Http::timeout(15)->withHeaders($this->headers()))
                ->withBody($body, 'application/xml')->post($this->base($c).$path);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'SiteMinder cancel failed: '.$e->getMessage()];
        }
        $doc = $this->xml($res->body());
        // A channel that answers Success for a booking it has already cancelled keeps this call idempotent.
        if ($res->successful() && $doc !== null && ! empty($doc->xpath('//Success'))) {
            return ['ok' => true, 'message' => "SiteMinder reservation {$externalRef} cancelled at the channel."];
        }

        return ['ok' => false, 'message' => 'SiteMinder cancel failed: HTTP '.$res->status().' – '
            .mb_substr($this->fault($res->body()) ?: $res->body(), 0, 160)];
    }
}
