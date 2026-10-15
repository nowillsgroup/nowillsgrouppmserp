# SiteMinder adapter (v7.42)

The fourth real OTA driver, and the first that does not speak JSON. SiteMinder
is a **channel manager** rather than an OTA: one connection of this shape
carries inventory out to — and reservations back from — the sites it fronts
(Booking.com, Expedia, Agoda, Airbnb, Hostelworld and the rest), so it is the
highest-reach adapter in the set. It exists to prove the `ChannelDriver`
contract is genuinely transport-agnostic: an `OTA_AvailRateNotifyRQ` push and
an `OTA_HotelResModifyNotifRS` pull sit behind exactly the same five methods as
the Rapid and EPS adapters, with no change to `ChannelService`.

## Authentication

```
Authorization: Bearer {api_key}          ← SiteMinder Platform API token
Accept: application/xml
```

The hotel is identified twice: `property_code` in the URL path and as the
`HotelCode` attribute of the ARI document. The simulator rejects a wrong or
missing token with HTTP 401 and a document whose `HotelCode` does not match
with an OTA `<Errors>` block, so a passing e2e proves the dialect and the auth.

Unlike Rapid, SiteMinder does not sign each request, so the connection needs no
`api_secret` — `secret` stays what it always is: the HMAC secret for **inbound**
webhook pushes to `POST /api/channel/{token}/reservations`.

## Endpoints

| Call | Path | Wire format |
|---|---|---|
| test | `GET /hotels/{property_code}` | JSON property content (read-only, never mutates) |
| pushAri | `POST /hotels/{property_code}/ari` | `OTA_AvailRateNotifyRQ` → `OTA_AvailRateNotifyRS`, one chunk per request |
| pullBookings | `GET /hotels/{property_code}/reservations?modified_since=ISO` | `OTA_HotelResModifyNotifRS` → `<HotelReservation>` elements |
| cancelBooking | `POST /hotels/{property_code}/reservations/{ref}/cancel` | `OTA_CancelRQ` → `OTA_CancelRS` (idempotent) |

## ARI document

```xml
<OTA_AvailRateNotifyRQ Version="1.0" TimeStamp="…" HotelCode="NWG01" CurrencyCode="GHS">
  <AvailStatusMessages>
    <AvailStatusMessage BookingLimit="7" BookingLimitMessageType="SetLimit" MinLengthOfStay="1">
      <StatusApplicationControl RoomTypeCode="SM-STD" Start="2026-10-13" End="2026-10-13"/>
      <RestrictionStatus Status="Open"/>
      <RestrictionStatus RestrictionType="Arrival" Status="Close"/>   <!-- only when CTA -->
    </AvailStatusMessage>
  </AvailStatusMessages>
  <Rates>
    <Rate RoomTypeCode="SM-STD" RatePlanCode="SM-BAR" EffectiveDate="2026-10-13" Amount="1150.00"/>
  </Rates>
</OTA_AvailRateNotifyRQ>
```

Stop-sell maps to `BookingLimit="0"` plus `<RestrictionStatus Status="Close"/>`;
closed-to-arrival adds the `RestrictionType="Arrival"` restriction; `min_stay`
maps to `MinLengthOfStay`. Prices are the channel price already produced by
`ChannelService::price()` (markup, rounding), one `<Rate>` per mapped rate plan
per night. Acknowledgement is `<Success/>` inside `OTA_AvailRateNotifyRS` — a
2xx carrying `<Errors>` is treated as a rejection, the chunk is **not** acked,
and the push throws so the push log keeps the unsent room-nights for retry.

## Reservation feed

`ResModifyType` maps `New → new`, `Modify|Change|Overlay → modify`,
`Cancel → cancel`. Per `<HotelReservation>`: `UniqueID/@ID` is the channel ref
(stored as `reservations.ota_reference`, the key the whole inbound pipeline is
idempotent on), `TimeSpan` gives the dates (accepted under `ResGlobalInfo` or
`RoomStay`), `GuestCounts` uses the OTA age-qualifying codes (`10` adult,
`8` child), `Total/@AmountAfterTax` the amount, and `Comment/Text` becomes the
reservation remark. Guests, room codes and rate codes then run through the same
mapping pipeline as every other channel: unmapped codes land in the inbox as
`attention:unmapped_room` instead of guessing, and a currency that is not the
hotel currency is rejected with the reason on the message.

## Local simulator

```bash
node deploy/dev/siteminder-sim.mjs --port=8096 --token=smtoken --hotel=NWG01
# or all four channel simulators at once:
bash deploy/dev/sims-up.sh
```

Seeds three reservations (new / modified / cancelled) and drains the queue on
pull, like a real channel manager delivering each message once. Testing hooks:
`POST /__bookings` queues one more reservation, `POST /__fail?n=2` makes the
next *n* ARI pushes answer HTTP 503 with an OTA fault (that is how the
contract harness proves a mid-stream failure acks nothing), `GET /status`
reports pushes served, bad tokens and bad documents.

Configure a connection: type **SiteMinder**, endpoint `http://127.0.0.1:8096`,
hotel code `NWG01`, API key `smtoken`, then map room types to `SM-STD` /
`SM-TWN` and rate plans to `SM-BAR` / `SM-NR`.

## Proven (2026-10-13)

Contract harness (`deploy/dev/audit/channel_contract.php`): **54 checks, 0
failures** across all six drivers — identity and uniqueness, `test()` never
throws and never mutates channel state, `pushAri()` acks exactly the accepted
chunks and throws mid-stream without acking, `pullBookings()` returns rows the
mapping pipeline applies, `cancelBooking()` idempotent.

Through the Channel Manager UI: connection created with the SiteMinder type ✓ ·
test passed (*bearer token accepted*) ✓ · room/rate mapping saved ✓ · **full
ARI push: 730 room-nights in 12 requests**, simulator recorded every document
with `bad_xml: 0` ✓ · **pull applied two OTA XML reservations → RES-00209
(GH₵ 4,120.00, commission GH₵ 618.00) and RES-00210 (GH₵ 2,380.00, commission
GH₵ 357.00)** ✓ · an inbound `Cancel` message cancelled RES-00209 in the PMS ✓ ·
a USD-denominated booking was rejected into the inbox by the currency guard
(by design) ✓.

## Going live

Point `endpoint_url` at the SiteMinder Platform API base for the property's
region, store the platform token as the API key, and set `property_code` to the
SiteMinder hotel code. `ChannelService::pushDue()` then keeps ARI current on
the schedule, and Connections → Pull (or the `channel:sync` schedule) brings
reservations in. Private/loopback endpoints are refused in production by
`IcalService::assertSafeUrl()`; the sandbox needs `PMS_CHANNEL_ALLOW_PRIVATE=1`.
