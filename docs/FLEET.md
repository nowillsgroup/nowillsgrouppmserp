# Fleet management & GPS tracking — deployment runbook

This is the operator's guide to the transport/fleet module: what to run, how to pair a tracker,
exactly what a device may post, and what each warning means. It describes the code in this
repository; nothing here seeds data or changes a property's records.

The module is **provider-neutral**. It does not talk to Traccar, Teltonika, Wialon, Samsara or any
other vendor cloud by name — it accepts positions over plain HTTP from anything that can send them,
which covers a tracker's own “HTTP server” option, a vendor's webhook, a small script, or a phone.

---

## 1. One-time setup per installation

```bash
php artisan migrate          # includes the six fleet migrations
php artisan pms:upgrade       # grants the fleet permissions to existing roles (idempotent)
php artisan optimize:clear
```

Migrations involved, in order — all additive, idempotent and reversible:

| Migration | Adds |
| --- | --- |
| `2026_11_04_000100_create_fleet_management_tables` | `vehicle_trackers`, `vehicle_positions`, `vehicle_maintenance_plans`, `vehicle_maintenance_records`, `vehicle_expenses.maintenance_record_id` |
| `2026_11_04_000200_create_fleet_geofences` | `fleet_geofences`, `fleet_geofence_states`, `fleet_geofence_events` |
| `2026_11_04_000300_add_signal_window_to_vehicle_trackers` | `vehicle_trackers.stale_minutes` |
| `2026_11_04_000400_create_vehicle_inspections` | `vehicle_inspections` |
| `2026_11_04_000500_add_fleet_lookup_indexes` | unique `vehicle_trackers.token_hash`, and `vehicle_positions (hotel_id, recorded_at)` |
| `2026_11_04_000600_create_vehicle_incidents` | `vehicle_incidents` |

Those two indexes matter on a real fleet: the token hash is looked up on **every** position push and
cannot use the hotel scope (the token is what identifies the property), so without an index each fix
scans a growing table. The token index is **unique**, not merely indexed — two devices sharing a hash
would mean a position could be attributed to the wrong vehicle, so the migration fails loudly instead.
The positions index covers the fleet-wide window scans (speeding, the nightly prune) that the existing
per-vehicle index cannot serve.

Nothing is seeded: no trackers, no positions, no plans, no work orders, no inspections, no incidents.

### Permissions

| Permission | Opens |
| --- | --- |
| `transport.view` | fleet overview, vehicle history, status CSV |
| `transport.track` | live tracking board, GPS CSV, geofences |
| `transport.fleet` | device pairing, maintenance, inspections, incident log, fuel, month-end cost statement, settings, work-order exports |

The nightly job and the ingest endpoint ignore roles entirely — the ingest endpoint authenticates a
**device token**, not a user.

### The nightly job

`pms:fleet-run` is scheduled for **03:50** in `routes/console.php`. Keep the scheduler running
(`* * * * * php /path/artisan schedule:run`). It prunes old GPS history and prints a one-line digest
per property:

```bash
php artisan pms:fleet-run                 # prune + digest
php artisan pms:fleet-run --hotel=ACCRA   # one property
php artisan pms:fleet-run --dry-run       # report, delete nothing
```

It **only reports**. It never opens a work order, never moves a vehicle and never changes a status; a
night with nothing to report prints *nothing needs attention* and writes no log line.

---

## 2. Pairing a tracker

Transport → Fleet → **Live tracking** → *Pair a device*:

1. Choose the vehicle.
2. Choose the provider family (generic HTTP, Traccar, Teltonika, Ruptela, Concox, Queclink, Wialon,
   Samsara, local vendor) — this is a label for humans, not a protocol.
3. Give the device a UID (the serial or IMEI you will configure on the unit) and optionally its own
   signal window in minutes.
4. Save. The **48-character token is shown once** and stored only as a SHA-256 hash. Copy it into the
   device configuration now; there is no screen that can show it again.
5. Press *Rotate* if the token leaks — the old one stops working immediately.
6. Press *Disable* to refuse a device without deleting its history.

**One device per car.** A vehicle that already has an active tracker cannot quietly gain a second
one: two units on the same car would stream two trails into a single distance and fuel figure. Pairing
a replacement requires ticking *replace the paired device*, which disables the old unit in the same
step (and logs it). A retired vehicle cannot be paired at all.

Then point the device at:

```
POST https://your-pms-host/api/fleet/<48-character-token>
```

Check the wiring before you leave the car:

```bash
curl -s https://your-pms-host/api/fleet/<token>/health
```

```json
{
  "ok": true,
  "provider": "generic",
  "device_uid": "FMB920-4471",
  "vehicle": "VAN-3",
  "last_position_at": "2026-11-04T08:31:12+00:00",
  "server_time": "2026-11-04T09:02:40+00:00",
  "accepted_fields": ["lat", "lng", "speed", "heading", "ignition", "odometer", "recorded_at", "event_id"]
}
```

The health endpoint shares the ingest token and the same throttle. It is safe to call from an
installer's laptop; it returns `401` for an unknown token, `403` for a disabled device or inactive
property, and reads no positions.

---

## 3. The ingest contract

* **Method / path** — `POST /api/fleet/{token}`. The token is 48 characters of `[A-Za-z0-9]`.
* **Stateless** — no session, no CSRF, no cookies, no login. The device sends only the token.
* **Throttling** — 240 requests/minute per device and 3,000/minute per IP. A device that batches is
  fine; a device that retries in a tight loop is not.
* **No user agent or IP allowlist** — a mobile tracker on a changing SIM must still work. If you need
  to lock a device down, disable it and rotate its token.

### Bodies accepted

Any of these is valid; the controller sniffs the request rather than demanding one shape:

```bash
# 1. a single fix as JSON
curl -s -X POST https://host/api/fleet/$TOKEN -H 'Content-Type: application/json' \
  -d '{"device_uid":"FMB920-4471","lat":5.6037,"lng":-0.187,"speed":42,"heading":95,
       "ignition":true,"odometer":84120,"recorded_at":"2026-11-04T08:31:12Z","event_id":"4471-91822"}'

# 2. a batch (also accepts the keys "records" or "data" instead of "positions")
curl -s -X POST https://host/api/fleet/$TOKEN -H 'Content-Type: application/json' \
  -d '{"positions":[{"lat":5.6037,"lng":-0.187,"speed":0,"ignition":true},
                    {"lat":5.6041,"lng":-0.1866,"speed":12,"ignition":true}]}'

# 3. a bare list
curl -s -X POST https://host/api/fleet/$TOKEN -H 'Content-Type: application/json' \
  -d '[{"lat":5.6037,"lng":-0.187}]'

# 4. form fields — what most trackers can actually send
curl -s -X POST https://host/api/fleet/$TOKEN \
  -d 'lat=5.6037&lng=-0.187&speed=42&recorded_at=2026-11-04T08:31:12Z&device_uid=FMB920-4471'

# 5. query string — for units that can only call a fixed URL
curl -s "https://host/api/fleet/$TOKEN?lat=5.6037&lng=-0.187&speed=42&event_id=4471-91823"
```

Batch form fields must be nested, e.g. `positions[0][lat]=5.6037&positions[0][lng]=-0.187`.

### Fields

| Field | Aliases | Notes |
| --- | --- | --- |
| `lat` | `latitude` | required, −90…90, stored to 7 decimals |
| `lng` | `lon`, `longitude` | required, −180…180 |
| `speed` | `speed_kph` | km/h, clamped 0…400; also drives moving / parked state |
| `heading` | `bearing`, `course` | 0…359 |
| `ignition` | `acc` | boolean-ish (`true`/`1`/`on`/`yes`). **If a device omits it, standing-time analysis is skipped for that vehicle rather than guessed.** |
| `odometer` | `odometer_km` | integer km; if higher than the vehicle's odometer it rolls the vehicle forward |
| `recorded_at` | `timestamp`, `time` | ISO-8601 or a Unix timestamp; defaults to the server time if absent |
| `event_id` | `id` | de-duplication key, 120 chars |

### Limits and rejections

* **At most 500 fixes per request** — a bigger batch is refused whole with `422`.
* A fix **older than 7 days** is rejected — back-filling an old trail is not accepted.
* A fix **more than 5 minutes in the future** is rejected — a device with a wrong clock is a fault to
  fix, not data to store.
* A missing or out-of-range latitude/longitude is rejected.
* A repeated `event_id` for the same device counts as a **duplicate**, not an error.
* Only rejected/duplicate summaries come back; at most five error strings are returned.

### Responses

| Status | Body |
| --- | --- |
| `200` | `{"ok":true,"stored":2,"duplicates":0,"rejected":0,"errors":[]}` |
| `401` | `{"ok":false,"error":"Unknown device token"}` |
| `403` | `{"ok":false,"error":"This device is disabled"}` / `"Property is not active"` / `"Device ID does not match this token"` |
| `422` | `{"ok":false,"error":"Send at most 500 positions per request."}` or a rejection reason |
| `429` | Throttled — the device should back off |
| `500` | `{"ok":false,"error":"Could not store the position"}` — a real fault, check the log |

**`device_uid`** is optional on a push, but if a device sends it, it must match the UID recorded at
pairing (`device_id` is accepted as an alias). A mismatch is `403`.

### What the server does with a batch

Positions are stored in order, the tracker's `last_position_at` moves to the newest fix in the batch,
the vehicle's odometer follows the highest value reported, and **only the newest fix of the batch**
is evaluated against geofences — so a bulk upload cannot replay yesterday's yard crossings.

---

## 4. Settings that change behaviour

Transport → Fleet → Settings (property-wide), plus per-device overrides:

| Setting | Default | Effect |
| --- | --- | --- |
| offline after (min) | 20 | minutes without a fix before a device is shown offline |
| per-device window | — | overrides the above for one unit (a battery tracker that wakes hourly) |
| moving above (km/h) | 5 | below this the vehicle is parked |
| speed limit (km/h) | 80 | fixes above this are speeding, and feed the driver scorecard |
| fuel while idling (l/h) | 0.9 | **an assumption**, used only to price standing time |
| keep GPS history (days) | 180 | `prune()` deletes older fixes; crossings are kept `max(30, days×2)` |
| service due within (days / km) | 14 / 500 | when a plan shows “due soon” |
| inspect every (days) | 7 | how often a vehicle should be inspected; a blank history counts as overdue |
| paper warning (days) | 30 | insurance, permit, fitness, PUC and licence warnings |

### Correcting a fuel fill

Fills are money records, so they can be fixed but never silently. On **Fuel & running costs**, each row
in *Fuel fills* has a **Correct** control:

* **Save correction** takes the date, litres, amount, odometer and station, and **requires a reason**
  (4–120 characters). The before and after values and the reason go into the activity log.
* **Delete** also requires a reason and is for an entry made in error. It removes the fill from every
  cost figure but **deliberately leaves the vehicle's odometer where it is** — a later fill or a service
  plan may already have used that reading, and rewinding it would corrupt them.
* **Service and repair money is not editable here.** Those costs belong to the work order that raised
  them; the screen says so and the server refuses (`403`), so the workshop's figure and the expense can
  never disagree.

Nothing is hard-deleted from a ledger: the expense row is removed, the activity log keeps who did it,
what it used to say and why.

### Cost per kilometre, judged against the fleet

Below the fuel table the same screen ranks every car by what a kilometre costs it, dearest first, against
the **fleet's own median** — no industry benchmark is invented. Two rules keep it honest:

* a car with no recorded cost **or** no GPS trail is not listed at all, rather than shown at zero;
* a car is flagged *more than 25% above the median* only once it has covered **50 km** in the period —
  a 5 km hop to the airport makes the ratio meaningless.

The ranking adds no extra distance scan: it ranks the running-cost rows the screen has already computed
from vehicle expenses and the trail.

### The incident log

Transport → Fleet → **Maintenance** → *Incident log* records what happened **to** a car on the road —
accident, damage, breakdown, theft — with the time, place, other party, police report number and an
estimate of what it will cost. It is the record an insurance claim or a police report has to stand on.

* **The estimate is not money.** It is what the property expects the claim to come to; nothing is
  posted to expenses by logging it. Actual repair spend goes on the work order, and the screen says so.
* **Logging raises nothing automatically** — no work order, no status change on the vehicle. Raise the
  job yourself and post the real cost there.
* Status moves `open → investigating → closed` and back, and **every status change requires a note**
  (4–255 characters) that is stored on the incident and written to the activity log with your name.
  Closing stamps `closed_at`; reopening clears it.
* An incident cannot be dated in the future, and its description needs at least 10 characters — a
  one-word entry is not evidence.
* The four numbers on the card are **live** incidents and their estimates; closed ones stay in the list
  for history but stop counting. The dashboard alert strip shows how many are still open.

### The month-end cost statement

Transport → Fleet → **Fuel & running costs** → *Monthly statement*, or open
`/tr/fleet/cost-statement?month=2026-10` directly. It prints on A4 portrait, one line per vehicle that
had any running cost that month, with fuel / service & repair / other, litres, GPS distance and cost
per km, a totals row and three signature lines for the fleet office, the workshop and accounts.

It is deliberately conservative, and the page says so in its own footnote:

* A vehicle with nothing recorded that month is **left off**, not printed as a row of zeroes.
* **`No trail`** means the car sent no GPS fixes at all in the month. Its cost per km is left blank,
  because dividing by zero would invent a number.
* The fleet cost per km only divides by the distance of the cars that *could* be measured, and states
  how many that was (`3 of 4 this month`).
* It is a statement of what was recorded against each vehicle — not a purchase ledger and not a tax
  invoice. Reconcile against the filling-station and garage receipts before posting to accounts.

A hand-typed `?month=` cannot reach into the future or back more than five years; both fall back to a
sane month rather than printing an empty statement for a period that cannot exist.

*Download CSV* on the statement exports the same rows — `fleet-statement-2026-10.csv` — with the same
blank-not-zero rule for a car that reported no trail, plus a `TOTAL` line. It uses the same month rules
and the same `transport.fleet` permission as the screen.

---

## 5. Troubleshooting

| Symptom | Cause | Fix |
| --- | --- | --- |
| `401 Unknown device token` | wrong/truncated token, or it was rotated | pair again — a rotated token cannot be recovered |
| `403 This device is disabled` | device was disabled | re-enable it on the tracking board |
| `403 Device ID does not match this token` | `device_uid` differs from the paired UID | fix the device config or the UID |
| `422 … older than 7 days` | device clock, or a backlog after downtime | set the clock/NTP on the unit |
| `422 … device clock is in the future` | clock skew | same |
| `422 Send at most 500 positions` | the device dumps its whole buffer | configure smaller batches |
| Device shows **never reported** | token never reached the server | `curl …/health`, then check DNS, TLS and the path |
| Device shows **offline** but it is powered | no fix inside the signal window | widen the window for a sleepy unit, or fix the device |
| Vehicle on the map but **no idle time** | tracker does not send `ignition` | configure it; the screen says the analysis is skipped, not zero |
| A trip's **GPS km** disagrees with the billed km | detour, early close, or odometer typo | open the trip; a gap over 3 km *and* 25% is flagged |
| Dashboard shows speed / restricted entries while the board looks quiet | the alerts are today's counts, the board shows the newest fix | open live tracking, the counts link there |

---

## 6. What the module deliberately does not do

* It does not seed devices, positions, plans, work orders or inspections. Every figure comes from
  records the property created or a device actually sent.
* It does not repair anything automatically — the nightly run reports, and a person decides.
* It does not call a vendor cloud API, hold a vendor key, or require internet access for the map
  (the board draws trails locally from the coordinates, with no third-party tiles).
* It does not price fuel from an assumption in reports: litres come from expense records, distance
  from the GPS trail, and the only assumed number — the idle burn rate — is labelled as such
  wherever it appears.
* Every CSV download streams, is capped, sits behind the same permission as its screen, and passes
  each cell through a formula-injection guard.

---

## 7. Verifying an install

```bash
php artisan route:list --path=tr.fleet      # 7 screens, 1 JSON feed, 1 job card, 1 printable statement, 6 CSV downloads
php artisan route:list --path=api/fleet     # ingest + health
python3 deploy/dev/audit/fleet_management_check.py   # 63 static checks, no database needed
```

The static audit reads the code only; it cannot replace one real ingest from a real device. After
pairing, send one fix with `curl` and confirm it appears on the board before mounting the unit.
