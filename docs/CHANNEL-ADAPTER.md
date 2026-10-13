# Channel / OTA adapter spec (v7.42)

Every channel transport implements `App\Services\Interfaces\ChannelDriver`
(resolved by `App\Services\Interfaces\ChannelDriverRegistry` from the
connection's **Connection type** (`provider`). `ChannelService` keeps owning
dirty-tracking, ARI pricing/payloads, booking application and the push log —
drivers only talk to the channel.

## Contract

| Method | Purpose |
|---|---|
| `id()` / `label()` | stable id (`hub`, `simulator`, `mirror`, vendor ids) + UI label |
| `test($c)` | probe the endpoint, no state changes → `['ok'=>bool,'message'=>string]` |
| `pushAri($c, $chunks, $kind, $ack)` | deliver ARI chunks; call `$ack($chunk)` per accepted chunk; return `['requests'=>int,'http'=>?int]`; throw on failure |
| `pullBookings($c, $since)` | raw booking arrays created/updated since `$since` |
| `cancelBooking($c, $ref)` | cancel at the channel → `['ok'=>bool,'message'=>string]` |

## Built-in drivers

- **`hub` — Channel hub / OTA API (signed JSON).** ARI `POST {endpoint_url}`
  with body `ChannelService::payload()`; headers `X-Nowills-Timestamp` +
  `X-Nowills-Signature: sha256=<hmac-sha256(secret, ts+body)>`, optional
  `Authorization: Bearer {api_key}`. Test = same POST with payload `kind`
  `"test"`. Pull `GET {endpoint_url}/bookings?since=ISO8601` (same headers) →
  `{"bookings":[…]}`. Cancel `POST {endpoint_url}/bookings/{ref}/cancel`.
- **`simulator` — built-in test channel.** Acknowledges pushes locally, always
  passes `test()`, returns a two-booking demo fixture on pull.
- **`mirror` — HTTP JSON/XML adapter.** REST mirror of the hub protocol;
  plain-REST (unsigned) when the connection has no secret. Template for
  vendor adapters. Bookings are read from `{endpoint_url}/bookings` and
  cancelled at `{endpoint_url}/bookings/{ref}/cancel` — the hub layout, so one
  feed serves a hub and a mirror connection alike (v7.42 fixed a pull/cancel
  that hit `{endpoint_url}` itself and 404ed on every hub-protocol feed).

## Vendor adapters (real OTAs)

| id | Adapter | Dialect | Auth | Doc |
|---|---|---|---|---|
| `booking` | Booking.com EPS | JSON | `Authorization: Bearer {api_key}` + `X-Booking-Property` | `docs/BOOKING-EPS.md` |
| `expedia` | Expedia Rapid | JSON | `{api_key}:base64(HMAC-SHA512(api_secret, path+body))` | `docs/EXPEDIA-RAPID.md` |
| `siteminder` | SiteMinder channel manager | **OTA XML** | `Authorization: Bearer {api_key}` | `docs/SITEMINDER.md` |

`ChannelConnection::providerOptions()` is the selectable type list and the
source of the `provider` validation rule; it is built from
`ChannelDriverRegistry::options()`, so **registering a driver is enough** — no
model edit, and a driver can never again ship unreachable from the form (the
Expedia adapter did exactly that until v7.42).

## Credentials: two secrets, two directions

| Column | Direction | Meaning |
|---|---|---|
| `token` + `secret` | **inbound** | the PMS webhook URL and the HMAC-SHA256 secret the channel signs its pushes with (`ChannelService::verify`). Generated on create, rotated on the connection page. |
| `api_key` + `api_secret` | **outbound** | what the PMS presents when it calls the channel: a bearer token, a signed-request key, or both. Encrypted at rest, blank-on-edit keeps the stored value. |

They are not interchangeable. Before v7.42 the Rapid adapter signed outbound
requests with `secret`, so configuring Rapid destroyed inbound verification —
and no form field existed for a vendor secret at all. `api_secret` is that
field; Rapid still falls back to `secret` for pre-v7.42 connections.

## Adding a real OTA adapter

1. `class BookingComDriver implements ChannelDriver` under
   `App\Services\Interfaces\Drivers\`; map the vendor's auth + payload dialect
   in each method (`ChannelService::payload()` builds the canonical ARI rows:
   `available`, `stop_sell`, `min_stay`, `cta`, `rates` per plan).
2. Register: `ChannelDriverRegistry::register('bookingcom', BookingComDriver::class)`
   (in `ChannelDriverRegistry::$drivers`, or from a package service provider).
   That is the whole registration — the type list, the validation rule and the
   connection form all read the registry.
3. Contract expectations, enforced by `deploy/dev/audit/channel_contract.php`
   (this repository has no PHPUnit tree; the harness runs on the same WASM PHP
   that serves the app, against the simulators):
   - `id()` / `label()` non-empty and unique per driver.
   - `test()` never mutates channel state; on an unconfigured connection it
     returns `ok=false` with a usable message and **never throws** (the
     built-in `simulator` is the documented exception — it always passes).
   - `pushAri()` acknowledges **only** accepted chunks (failure mid-stream must
     not ack the unsent ones — the push log then keeps them for retry).
   - `pullBookings()` returns rows `ChannelService::canonicalRow()` can apply:
     either the canonical shape (`event`, `booking_id`, `guest{}`, `rooms[]`,
     `arrival`, `departure`, `total`) or the loose one (`ref`, `guest` string,
     `room_type`, `arrival`, `departure`, `nights`, `rate`). `ChannelService`
     maps and applies them idempotently by `ref`.
   - `cancelBooking()` is idempotent (cancelling twice still returns `ok`).
   - the driver id is selectable in the Channel Manager form.

```bash
bash deploy/dev/sims-up.sh   # hub 8091 · EPS 8093 · Rapid 8095 · SiteMinder 8096
TINKER='require "/app/deploy/dev/audit/channel_contract.php";' deploy/dev/bin/php -f deploy/dev/tinker.php
# → 54 passed, 0 failed, 0 skipped / CONTRACT_OK   (2026-10-13, all six drivers)
```

## Outbound cancellation

`ChannelService::cancelAtChannel(Reservation)` closes the loop: a reservation
cancelled in the PMS is cancelled at the channel too. `CancellationService::cancel()`
calls it **after** its transaction commits, so all five cancellation paths
(desk, API v1, partner portal ×3) are covered by one call site. It never throws
and never rolls the cancellation back — a channel that refuses is written to the
activity log (`channels/cancel_failed`) and flagged on
`reservations.channel_attention` for the desk to cancel by hand; a later success
clears only that flag, never an overbooking one. Partial cancellations (one room
of many) and no-shows are deliberately **not** sent: the booking still stands at
the channel and, for a no-show, its retention fee is claimed here.

## Sandbox end-to-end

`bash deploy/dev/sims-up.sh` starts every simulator at once:

| Port | Simulator | Speaks | Testing hooks |
|---|---|---|---|
| 8091 | `channel-sim.mjs` | Nowills hub protocol (signed JSON) | `GET /` status page |
| 8093 | `booking-eps-sim.mjs` | Booking.com EPS | `POST /__queue` |
| 8095 | `expedia-sim.mjs` | Expedia Rapid — **rejects bad signatures** | `POST /__bookings` |
| 8096 | `siteminder-sim.mjs` | SiteMinder OTA XML — **rejects bad tokens** | `POST /__bookings`, `POST /__fail?n=2`, `GET /status` |

Connection settings: type = Channel hub, endpoint `http://127.0.0.1:8091/ari`
(dev sandbox: `PMS_CHANNEL_ALLOW_PRIVATE=1` in `.env` skips the private-IP
guard, which production enforces through `IcalService::assertSafeUrl()`).

Then **Test connection**, **Push changes**, **Full sync** and **Pull** on the
connection page run against the simulator over real HTTP — and a cancellation
on the reservation page runs `cancelBooking()` against it. Queue a reservation
with the simulator's hook, pull, and the booking appears as a real PMS
reservation with its channel amount, commission and OTA reference.
