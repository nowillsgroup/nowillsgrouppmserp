# Channel / OTA adapter spec (v7.37)

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
  vendor adapters.

## Adding a real OTA adapter

1. `class BookingComDriver implements ChannelDriver` under
   `App\Services\Interfaces\Drivers\`; map the vendor's auth + payload dialect
   in each method (`ChannelService::payload()` builds the canonical ARI rows:
   `available`, `stop_sell`, `min_stay`, `cta`, `rates` per plan).
2. Register: `ChannelDriverRegistry::register('bookingcom', BookingComDriver::class)`
   (e.g. in `AppServiceProvider`) and add the id to
   `ChannelConnection::PROVIDERS`.
3. Contract expectations (kept honest by `tests/Feature/ChannelDriverContractTest`):
   - `test()` never mutates; failure returns `ok=false` (never throws).
   - `pushAri()` acknowledges **only** accepted chunks (failure mid-stream must
     not ack the unsent ones — the push log then keeps them for retry).
   - `pullBookings()` returns arrays with at least `ref`, `guest`, `room_type`,
     `arrival`, `departure`, `nights`, `rate` (raw — `ChannelService` maps and
     applies them idempotently by `ref`).
   - `cancelBooking()` is idempotent (cancelling twice still returns `ok`).

## Sandbox end-to-end

`deploy/dev/channel-sim.mjs` speaks the hub protocol locally:

```bash
deploy/dev/node_modules/.bin-local/bun deploy/dev/channel-sim.mjs --port=8091
# connection: type = Channel hub, endpoint = http://127.0.0.1:8091/ari
# (dev sandbox: PMS_CHANNEL_ALLOW_PRIVATE=1 in .env skips the private-IP guard)
```

Then **Test connection**, **Push changes** and **Full sync** on the connection
page run against the simulator over real HTTP.
