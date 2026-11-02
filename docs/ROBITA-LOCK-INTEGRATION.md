# Robita smart-lock module — local/demo scope

## Current implementation boundary

This module currently implements the PMS-side guest journey and a local demo workflow. It does **not** implement a Robita cloud client or a certified lock integration. There are no outbound Robita REST calls, polling jobs, credential deliveries, or physical lock commands. Registering a lock creates a local demo record; lock/unlock buttons create demo events; credential rows are marked `simulated` and explicitly are **not sent to a lock**.

Do not configure or describe this as a live Robita, Salto, Onity, Dormakaba, Assa Abloy, or other vendor integration. Vendor-specific work remains blocked until the vendor supplies protocol documentation and the target hardware passes acceptance testing. The generic TCP lock template is not vendor-certified.

The separate, existing key-card interface is still controlled by `InterfaceService`; its default driver is `demo`. Its optional template-driven TCP path is generic and not a Robita adapter. Do not enable it against hotel hardware until the particular protocol/template has been reviewed and accepted on that hardware.

## Guest lifecycle covered locally

- **Check-in / card issue:** for a checked-in stay with an active key card and mapped room-door demo record, create one local credential record per card. Re-running is idempotent.
- **Checkout:** mark the stay's active local credential records cancelled.
- **Room move:** cancel records on the old room mapping and create records against the new room mapping when one exists.
- **Extension:** update local credential expiry to the revised departure time.
- **Expiry:** `RobitaService::expireDue()` marks due records expired; it is called by the interfaces runner alongside key-card expiry.
- **Retry/poll:** `retryPending()` and `pollDevices()` are deliberate no-ops. No vendor command is issued.

The transaction-based regression scripts are `deploy/dev/audit/robita_journey_check.php` and `deploy/dev/audit/robita_wiring_check.php`.

## Normalized event intake (future trusted bridge only)

The endpoint accepts a PMS-normalized schema, **not a Robita vendor payload**:

- `POST /api/robita/events`
- `Authorization: Bearer <per-hotel token>` (the token is not placed in the URL; send only over HTTPS)
- `Content-Type: application/json`
- Maximum JSON body: 8 KiB; route is rate-limited to 120 requests/minute.
- Each event must include a stable `event_id` (maximum 128 characters). A unique per-hotel/device idempotency key prevents replayed bridge events from incrementing counts or creating duplicate event rows.

Example payload:

```json
{
  "event_id": "bridge-2026-11-01-000042",
  "serial": "RBT-S1-001234",
  "type": "unlock",
  "event_at": "2026-11-01T10:15:00Z",
  "method": "card",
  "card_serial": "ABCD1234",
  "user_name": "Guest",
  "user_id": "guest-42",
  "battery_pct": 87,
  "signal": "good",
  "lock_state": "unlocked"
}
```

Accepted `type` values are the keys in `RobitaEvent::TYPES`; `method`, `signal`, and `lock_state` are allow-listed against their model constants. The endpoint rejects malformed fields, unknown device serials, inactive hotel tokens, oversized payloads, and devices outside the resolved hotel. Unknown JSON fields are ignored; only allow-listed fields are stored. Duplicate event IDs are acknowledged with `duplicate: true` and do not update device counters.

This endpoint is an integration seam for a future bridge. Do not point vendor hardware at it until the vendor payload has been reviewed and an explicit trusted adapter is implemented.

## Hardware acceptance gate before any live adapter

1. Obtain official API/TCP protocol documentation, authentication requirements, firmware compatibility, event schema, retry/rate limits, and credential lifecycle semantics.
2. Review the protocol and threat model; add a vendor-specific adapter behind an explicit feature/configuration gate. Do not turn demo records into online records merely because a URL/token exists.
3. Test check-in, card replacement, checkout, room move, extension, expiry, reconnect/retry, stale events, duplicate webhooks, clock/timezone handling, credential revocation, and tenant isolation on the actual lock model/firmware.
4. Confirm observed physical state and credential acceptance with hardware, then document the tested model, firmware, environment, and acceptance evidence.
5. Only then may UI labels distinguish vendor-reported state from local simulation. Never infer hardware connectivity from a configured URL or a locally updated state.
