# Desktop offline mode — multi-day cache-and-sync (v7.39)

Closes the FEATURE-GAP-AUDIT's last P3 software item: *"Offline mode (desktop)
— no multi-day cache-and-sync beyond POS draft caching and the existing
offline queues."* `DesktopSyncService` is the desk half; housekeeping /
guest-request queues stay in `OfflineSyncService`, POS outlets keep their
draft caching.

## The cycle

1. **Download a pack** — `GET /offline/desktop/pack?days=7&device=desk-01`
   (or `pms:offline:pack --days=7 --device=desk-01 --out=pack.json`). The pack
   carries the operating dataset for the window: rooms, room types, rate plans,
   payment modes, reservations + stays touching the window (plus 3 days'
   slack for late arrivals), guests, folios — each row `updated_at`-stamped —
   plus `meta.cursor` as the delta watermark.
2. **Work offline for days** — the desktop queues operations; every operation
   carries the `occurred_at` it happened at so back-dated work lands correctly.
3. **Sync the queue** — `POST /offline/desktop/sync`
   `{"device_id":"desk-01","operations":[…]}`. Results per operation:
   `applied` | `duplicate` | `rejected` (rejects are persisted to the sync log
   for audit; a rejected id may be retried, an applied one reports `duplicate`).
4. **Refresh the cache** — `GET /offline/desktop/delta?since={cursor}` returns
   changes to rooms, room types, rate plans, payment modes, stays, reservations,
   guests, folios and folio transactions so the desktop does not need a full
   re-export. The cursor overlaps the read by one second; clients should upsert
   by row id so a repeated boundary row is harmless.

## Operations

| Kind | Payload | Delegates to |
|---|---|---|
| `desk.check_in` | `stay_id, room_id` | `StayService::checkIn` (vacancy enforced) |
| `desk.check_out` | `stay_id, force?` | `StayService::checkOut` (zero-balance rule) |
| `desk.charge` | `stay_id, category, description, amount, tax?` | `FolioService::charge` |
| `desk.payment` | `stay_id, amount, payment_mode_id, reference?` | `FolioService::payment` |
| `desk.nights` | `stay_id, until?` | `StayService::postPendingNights` |

Business rules are the server's, not the desktop's: an occupied room, a folio
in balance, an unsupported operation — each is a `rejected` result with the
real reason, safe to retry after fixing. Housekeeping operations additionally
require `housekeeping.attendant` or `housekeeping.manage` (the shared sync route
also accepts `requests.manage` for guest-request creation). A stale room-status
change that would replace a newer status or room note returns a `conflict`; the
current server record is returned instead of overwritten.

## Workspace

**Front desk tools → Desktop sync** (`/offline/desktop`): pack download with
row counts and the covered window, a sync box that posts a pasted queue, and
the recent-operations log (applied / duplicate / rejected).

## Proven (2026-10-11)

Pack (8-day cover: 24 rooms, 9 reservations, 9 stays, 9 guests, 9 folios) →
back-dated check-in → day-1 dinner charge + day-2 laundry charge → cash
payment → night posting → settle GH₵ 9,780.66 → checkout. Retried operation
ids returned `duplicate` across server restarts; an unsupported kind and a
wrong-state op returned `rejected` with their reasons; `delta` over 15 minutes
returned 1 stay + 7 folio transactions + 1 room.
