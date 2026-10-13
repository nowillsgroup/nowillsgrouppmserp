# Expedia Rapid adapter (v7.40)

The second real OTA channel driver after Booking.com EPS — proof the
`ChannelDriver` contract generalises to a second protocol. The hard part of
every Expedia integration is request signing; this driver signs exactly as
Rapid does, and the local simulator REJECTS bad signatures, so an e2e pass
proves the maths.

## Request signing

```
signature     = base64(HMAC-SHA512(api_secret, request_path_with_query + json_body))
Authorization: {api_key}:{signature}
```

The path includes the query string; the body is the raw JSON (empty for GET).
Credentials live on the connection: `api_key` + **`api_secret`** (both
encrypted columns), `property_code` = Expedia property id, `endpoint_url` =
Rapid base.

> **v7.42:** the signing secret used to be read from `secret`, which is really
> the PMS-generated HMAC secret for **inbound** webhook pushes — setting it for
> Rapid silently broke inbound signature verification, and no form field
> existed to enter it anyway. `api_secret` is now the outbound vendor
> credential (Connection form → *API secret*), and `sign()` falls back to
> `secret` only so pre-v7.42 connections keep working.

## Endpoints

| Call | Rapid path | Notes |
|---|---|---|
| test | `GET /v3/rooms?property_id=…` | property content |
| pullBookings | `GET /v3/booking?property_id=…&records.updated_from=…` | maps `itineraries[]` → the internal event shape (cancel flag → `cancel`, modified → `modify`) |
| pushAri | `PUT /v3/availability` | signed body, chunked, per-chunk ack |
| cancelBooking | `POST /v3/booking/{id}/supplier-cancel` | supplier-side cancellation |

## Local simulator

```
node deploy/dev/expedia-sim.mjs --port=8095 --key=expkey --secret=expsecret
```

Seeds three itineraries (new / modified / cancelled); `POST /__bookings`
queues more. Configure a connection: type **Expedia Rapid**, endpoint
`http://127.0.0.1:8095`, property `300452`, API key `expkey`, API secret
`expsecret`. (`bash deploy/dev/sims-up.sh` starts this simulator together with
the hub, EPS and SiteMinder ones.)

## Proven (2026-10-12)

Signed handshake ✓ · booking pull mapped (new/modify/cancel, USD amounts,
guests) ✓ · signed ARI push ✓ · signed supplier-cancel ✓ (the simulator
caught a real signing bug on the first run) · `ChannelService::receive`
pipeline: unmapped room codes → `attention:unmapped_room` safety gate →
after mapping, **RES-00207 / RES-00208 accepted** with rate-mismatch
warnings, every re-push reported `duplicate` (idempotent) · USD rows
rejected against the GHS property by the currency guard (by design —
matching bookings must trade in hotel currency).

## Proven again (2026-10-13)

Before v7.42 this driver could not be configured at all: `expedia` was missing
from `ChannelConnection::PROVIDERS`, so the type was not in the form's select
and the `provider` validation rule rejected it, and the endpoint / API-key
fields were hidden for every type except `hub`. Both are fixed — the provider
list now comes from `ChannelDriverRegistry::options()`, so registering a driver
is enough to make it selectable and valid. Re-verified through the Channel
Manager UI: connection created with API key + API secret ✓ · mapping saved ✓ ·
test passed (*signed request accepted*) ✓ · **full ARI push 730 room-nights in
12 requests with `bad_sig: 0` on the signature-checking simulator** ✓ · pull
applied a GHS itinerary as **RES-00211** (GH₵ 2,960.00, commission GH₵ 444.00)
and rejected a USD one into the inbox with the currency-guard reason ✓ ·
contract harness 54/54 (`deploy/dev/audit/channel_contract.php`).

## Going live

Real Rapid base `https://connect.expedia.com`; supply the production
api_key/secret and property id on the connection. The simulator mode of the
connection list stays hidden for real drivers, and `ChannelService::pull`
runs this driver exactly like the others (Connections → Pull).
