# Booking.com EPS adapter (v7.39)

The `booking` channel driver speaks **Booking.com EPS (External Connectivity
Standard)** message shapes over HTTPS JSON. It plugs into the generic driver
contract (`app/Services/Interfaces/ChannelDriver.php`, see
`docs/CHANNEL-ADAPTER.md`); ChannelService owns dirty-tracking, ARI pricing,
the room/rate mapping pipeline and the push log.

## Endpoints (relative to the connection's `endpoint_url`)

| EPS message | HTTP | Purpose |
|---|---|---|
| handshake | `GET /eps/v1/properties/{property_code}` | Test connection |
| OTA_HotelAvailNotif / OTA_HotelRateAmountNotif | `POST /eps/v1/ari` | Availability + rates + restrictions push |
| OTA_HotelResNotif | `GET /eps/v1/reservations?since=ISO8601` | New / modified / cancelled reservations |
| cancellation advice | `POST /eps/v1/reservations/{id}/cancel` | Cancel at the channel |

**Auth** — `Authorization: Bearer {api_key}` and `X-Booking-Property: {property_code}`
(use the connection's encrypted `api_key`, `property_code` = the Booking.com hotel id).

## Field mapping (EPS JSON ⇄ PMS)

| EPS | PMS message (`ChannelService::receive`) | Reservation |
|---|---|---|
| `reservation_id` | `booking_id` | channel reference |
| `status` (`new`/`modified`/`cancelled`) | `event` (`new`/`modify`/`cancel`) | create / update / cancel |
| `checkin` / `checkout` | `arrival` / `departure` | `arrival_date` / `departure_date` |
| `guest.*` | `guest.{first,last,email,phone,country}` | guest profile |
| `room_code` + `rate_code` | `rooms[0].room_code/rate_code` | resolved via room/rate maps |
| `adults` / `children` | `rooms[0].adults/children` | pax |
| `total` | `rooms[0].amount`, `total` | rate amount |
| `special_requests` | `remarks` | reservation remarks |

Bookings are applied idempotently (payload-hash dedupe in `receive()`), so
re-pulling is safe. Currency must match the hotel currency or the message is
rejected to the OTA inbox — the real integration converts at the channel.

## Pull-mode sync

`ChannelService::pull($connection)` fetches since `last_booking_at` and feeds
each raw row through `canonicalRow()` → `receive()`. The **Pull bookings**
button on a connection (`POST /channel-manager/connections/{id}/pull`) runs
one cycle and flashes a summary.

## Local simulator

```
bun deploy/dev/booking-eps-sim.mjs --port=8093 --secret=epstest
# or: node deploy/dev/booking-eps-sim.mjs --port=8093 --secret=epstest
```

Seeds two GHS reservations (new + modified). `POST /__queue` (same bearer)
adds more. Point a `provider = booking` connection at
`http://127.0.0.1:8093` with `property_code` = your Booking hotel id,
`api_key` = `epstest`, map STD/DLX room codes, then Test → Push → Pull.

**Going live:** point `endpoint_url` at the certified Booking.com EPS
gateway, swap the API key, and keep the same mappings — the message dialect
is unchanged (see the OTA_Hotel* element names above).
