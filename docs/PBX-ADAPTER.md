# Live PABX adapter (v7.36) — TCP command link & SMDR capture

The Interfaces module (`/interfaces`) previously offered only the **demo** and
**offline** PABX drivers: nothing ever reached a physical PBX. This is the first
live driver. It is deliberately vendor-neutral: hotel PBX command protocols are
vendor-specific, so the property configures **command templates** and the
**SMDR stream source** instead of the PMS hard-coding one vendor.

> Scope: the PABX / call-accounting side. Door-lock encoders still use the
> demo/offline drivers — a real encoder adapter needs the lock vendor's SDK and
> hardware (see “What this does not cover”).

## How the pieces fit

| Piece | Where | What it does |
|---|---|---|
| TCP command driver | Interfaces › PABX → driver **TCP template (live PABX)** | Sends one command line per PMS event (check-in, check-out, class of service, room move) and matches the reply |
| Command templates | Interfaces › PABX → *TCP command link & SMDR capture* | One template per command; placeholders are filled from the command payload |
| SMDR capture | `php artisan pms:pbx:listen` | Streams call-detail records from the PBX into call accounting |
| HTTP push (unchanged) | `POST /api/pbx/{token}` | For call-loggers that push over HTTP instead of a raw TCP stream |
| Simulator | `deploy/dev/pbx-sim.mjs` | A fake PBX for rehearsals and regression tests |

SMDR lines flow through `CallAccountingService::import()`, so rating, extension
mapping, class-of-service checks, duplicate detection (`raw_hash`) and folio
auto-posting behave exactly like a manual import.

## Command templates

Placeholders are replaced from the command payload; `{date}`, `{time}` and
`{datetime}` use the business date. Examples (replace with your vendor's
documented dial-codes / ASCII protocol):

| PMS command | Payload placeholders | Example template |
|---|---|---|
| `checkin` | `{extension} {name} {cos} {language}` | `CMD CHECKIN {extension} {name} {cos}` |
| `checkout` | `{extension} {cos}` | `COS {extension} BARRED` |
| `cos` | `{extension} {cos}` | `COS {extension} {cos}` |
| `move` | `{from} {to} {cos}` | `MOVE {from} {to}` |

A blank template leaves that command failing with an explanatory message in the
command log (Interfaces › command log) — it is never silently dropped.

**Success pattern** (`pbx_tcp_ok`) is a case-insensitive regex matched against
the reply, default `\b(OK|ACK|SUCCESS)\b`. Leave it blank for PBXes that do not
answer: a completed write then counts as the acknowledgement.

## SMDR capture

```sh
# The PMS dials into the PBX's SMDR output port (connect mode — most common)
php artisan pms:pbx:listen --hotel=1

# The PBX (or its SMDR forwarding tool) connects to the PMS instead
php artisan pms:pbx:listen --hotel=1 --mode=listen --port=4456

# One session only (testing / wrappers around a scheduler)
php artisan pms:pbx:listen --hotel=1 --once
```

Host and port default to the `pbx_smdr_host` / `pbx_smdr_port` settings.
Lines are parsed with the configured **SMDR column order** (same field as for
manual imports). The listener reconnects with backoff; log output shows each
flush (`received / imported / posted / duplicates / errors`).

Recommended production shape: run `pms:pbx:listen` under supervisor/systemd
(`Restart=always`) on the property server, on an isolated VLAN route to the PBX.

## Certification walkthrough (no hardware needed)

Rehearse the whole integration against the simulator first:

```sh
# 1. start the fake PBX (answers commands with "OK …", streams 3 SMDR lines then closes)
deploy/dev/node_modules/.bin-local/bun deploy/dev/pbx-sim.mjs --cmd-port=4455 --smdr-port=4456 --close-after=3
#    (or simply `bun deploy/dev/pbx-sim.mjs …` where bun is on PATH)

# 2. point the interface at it (Interfaces › PABX → driver TCP template …)
#    command host 127.0.0.1:4455 · SMDR host 127.0.0.1:4456 · templates as above

# 3. capture one session — expect imported + posted calls, 0 errors
php artisan pms:pbx:listen --hotel=1 --host=127.0.0.1 --port=4456 --once

# 4. trigger a command (check-in / room move / COS from the front desk) and
#    confirm the command log shows done with the simulator's "OK …" reply
```

Then repeat against the real PBX with the vendor's documented commands and SMDR
format, and walk the hardware acceptance checklist:

1. Command round-trip for each of the four commands, from the real UI actions.
2. SMDR stream stability for ≥ 24 h (reconnects, overnight calls, clock skew).
3. Rating spot-checks: free numbers, prefixes to strip, class-of-service limits.
4. Folio postings match the PBX bill for a sample of rooms.
5. Failure drills: unplug the link, restart the PBX, retry the command log.

## What this does not cover

- **Door locks** — card encoding/invalidation needs the encoder vendor's SDK and
  hardware; drivers stay `demo`/`offline` until a vendor is chosen. The command
  log and retry workflow already model the operational flow.
- **Vendor-proprietary binary protocols** (e.g. some FIAS/serial encoders) —
  implement `App\Services\Interfaces\TcpPbxDriver`-style adapters behind the
  same `InterfaceService::send()` seam; nothing else in the PMS changes.
- **Security** — the command link is plain TCP by vendor design; put it on a
  management VLAN. The HTTP push endpoint keeps its per-hotel 40-char token.

## Verified (11 October 2026, WASM dev runtime)

End-to-end against `pbx-sim.mjs`: SMDR capture → rating → auto-post to the
in-house folio (masked numbers, GH₵ 121 across two calls), duplicate suppression
on re-capture, free-number handling, `cos`/`checkin`/`move` command round-trips
(`OK …`), unconfigured-template and unreachable-host failures with actionable
messages, and settings round-trip incl. invalid success-regex rejection. See
[`TEST-STATUS.md`](TEST-STATUS.md).
