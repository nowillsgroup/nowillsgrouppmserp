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
Credentials live on the connection: `api_key` + `secret` (both encrypted
columns), `property_code` = Expedia property id, `endpoint_url` = Rapid base.

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
queues more. Configure a connection: driver `expedia`, endpoint
`http://127.0.0.1:8095`, property `300452`, api_key `expkey`, secret
`expsecret`.

## Proven (2026-10-12)

Signed handshake ✓ · booking pull mapped (new/modify/cancel, USD amounts,
guests) ✓ · signed ARI push ✓ · signed supplier-cancel ✓ (the simulator
caught a real signing bug on the first run) · `ChannelService::receive`
pipeline: unmapped room codes → `attention:unmapped_room` safety gate →
after mapping, **RES-00207 / RES-00208 accepted** with rate-mismatch
warnings, every re-push reported `duplicate` (idempotent) · USD rows
rejected against the GHS property by the currency guard (by design —
matching bookings must trade in hotel currency).

## Going live

Real Rapid base `https://connect.expedia.com`; supply the production
api_key/secret and property id on the connection. The simulator mode of the
connection list stays hidden for real drivers, and `ChannelService::pull`
runs this driver exactly like the others (Connections → Pull).
