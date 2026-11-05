# Working without a connection

The property loses internet. The lights, the kitchen and the guests do not. This
document says exactly what keeps working on a device that has signed in once,
what waits for the connection, and how the system makes sure nothing is applied
twice when it comes back.

Everything here is opt-in per screen: a screen is offline-capable only if it has
been taught to draw itself from a snapshot and to write its work into the queue.

---

## 1. What works offline today

| Screen | Route | Offline work | When it syncs |
|---|---|---|---|
| Restaurant orders | `/offline/pos` | Open a dine-in / takeaway / room-service order, add dishes with side dishes and modifiers, send kitchen tickets | Automatically, in the order the work happened |
| Housekeeping | `/offline/housekeeping` | Room status, start / DND / complete a task, checklist, maintenance issue | Automatically |
| Guest requests | `/offline/guest-requests` | Log a request against an in-house room | Automatically |
| Front desk | `/offline/frontdesk` | Check a reserved guest into a vacant room, post a folio charge | Automatically, on the business date the desk was working |
| Reconciliation | `/offline/reconciliation` | After an outage: every receipt a device left, what it produced, what was refused, CSV export | — |
| Outbox | `/offline/queue` | Every change this device still holds, the server's reason when it refused one, and a person's decision: send it again or discard it | Automatically, or when the person presses *Sync now* |
| Offline hub | `/offline` | What is saved on this device, what is queued, what needs a person | — |
| Desk packs | `/offline/desktop` | Multi-day pack for a front-desk machine, then review what it sent back | On demand |

### What deliberately waits for a connection

* **Taking money.** Cash, card, MoMo, room posting, comps, refunds and VAT receipts
  all run through the cashier path — shift totals, the guest folio, E-VAT and the
  printed receipt live in one place. A second implementation on the device could
  charge a guest twice; the order itself never waits, only the payment does.
* **Payments, refunds and check-out.** The front desk records the charge offline;
  the money — advance, settlement, refund — and the check-out that closes the folio
  stay on the online cashier screen, so a guest is never charged twice and the
  shift's cash total never has two sources.
* **Kitchen printing.** Ticket rows are created and appear on the kitchen screen
  when the sync lands, but paper comes from a printer the server talks to. If the
  kitchen needs paper during an outage, use the printer's own copy path or write
  the ticket by hand and reconcile it.
* **Anything that changes a rate, a permission or a master record.** Those are
  deliberate, rare and reviewed.

The screens say this themselves, in place, so a waiter does not discover it at the
moment a guest asks for the bill.

---

## 2. How it works

```
 device                                             server
 ──────                                             ──────
 snapshot (menu, tables, running orders)  ←──GET──── /offline/pos/snapshot
        │
        ├─ waiter taps "Send to kitchen"
        │     offline-core.js writes an operation (uuid) ──→ IndexedDB outbox
        │     the screen updates at once, marked "not sent"
        │
        └─ connection returns
              POST /offline/sync  { operations: [...] } ──→ OfflineSyncService::push()
                                                             ├─ operation id already stored?
                                                             │     → return the first answer
                                                             ├─ permission / shift / validity checks
                                                             ├─ apply in a transaction
                                                             └─ remember id + result
              ←── results: applied | conflict | rejected | retry
```

### The four rules

1. **An operation id is generated on the device** (`crypto.randomUUID`) and stored
   on the server when the operation lands. A retried request, a second tab, or a
   lost response returns the stored answer instead of acting twice.
2. **The queue is first-in, first-out.** "Open the order" always reaches the server
   before the tickets that belong to it.
3. **The work keeps the business date it happened on.** A 23:50 order synced at
   00:10 still belongs to the night that was served — the device sends its business
   date and the server only accepts it within the last 7 days.
4. **Nothing is silently overwritten.** A conflict (the table was already running
   on another device, the room status moved on) comes back as `conflict`, stays in
   the queue with the server's version attached, and a person decides.

### Where the pieces live

| File | Job |
|---|---|
| `public/js/offline-core.js` | The queue: IndexedDB stores, enqueue, flush with backoff, snapshot cache, status events. Used by pages **and** by the service worker. |
| `public/sw.js` | App shell cache, offline navigation fallback to `/offline`, Background Sync that flushes the queue with no tab open. |
| `public/js/offline-sync.js` | Housekeeping workspace: room statuses, task actions, inspections and maintenance issues. |
| `public/js/offline-guest-requests.js` | Guest-request form and cards. |
| `public/js/offline-pos.js` | Restaurant terminal drawn from the snapshot. |
| `public/js/offline-frontdesk.js` | Front desk: arrivals, in-house rooms, the charge form, drawn from the snapshot. |
| `public/js/offline-queue.js` | The outbox screen: what this device holds, why the server refused it, retry and discard. |
| `app/Services/OfflinePreparationService.php` | Which screens and snapshots a login may prepare on a device. |
| `app/Services/OfflineSyncService.php` | Server half of the queue: dispatch, replay safety, housekeeping and guest-request slices. |
| `app/Services/OfflinePosService.php` | Snapshot + `pos.order.create` + `pos.order.kot`. |
| `app/Services/OfflineFrontDeskService.php` | Desk snapshot + `frontdesk.checkin` + `frontdesk.charge`, replayed through `StayService` and `FolioService`. |
| `app/Services/OfflineReconciliationService.php` | Reads the receipts (`offline_sync_operations`) for the reconciliation page and its CSV. Read-only. |
| `resources/views/offline/` | The hub and the workspaces. |
| `database/migrations/*_add_offline_ref_to_pos_orders.php` | `pos_orders.offline_ref`: the device's reference for a bill it opened offline. |

---

## 3. Queue behaviour in detail

| Result | What happens |
|---|---|
| `applied` | The operation leaves the queue. The screen is patched with the server's version (an order opened offline learns its order number). |
| `conflict` | Kept, marked, shown in the app-wide strip and on `/offline`. The server's version is attached so the person can see what changed. |
| `rejected` | Kept. The server refused it (no permission, shift closed, dish off the menu). The reason is shown in plain words. |
| retry | The server could not apply it *yet* (for example the order is still ahead in the queue). Exponential backoff: 30 s, 1 m, 2 m … capped at 15 min, stalled after 20 attempts. |
| signed out | The queue stops and says so. Nothing is dropped; sign in and it resumes. |

Retries never re-order the queue, and a stalled operation is never deleted on its
own — someone has to look at it.

One queue, one set of rules: the restaurant, front desk, housekeeping and
guest-request screens all write into the same outbox, so one backoff, one Background
Sync, one preparation step and one review screen cover every slice. The two screens
that used to keep an outbox of their own (`nowills-pms-offline`) move anything they
queued there onto the shared queue the first time they load, keeping the operation id
and the base timestamp each row was stored with — so the server still recognises work
it may already have applied. That look happens on `/offline/queue`: the server's
reason is on the row, **Try again** puts one change back in the queue with its backoff
cleared (and sends it at once), and **Discard** asks first, because the server never
received that work and it cannot be recovered.

Background Sync is used where the browser has it (Chrome, Edge, Android). Without
it, the queue flushes the next time any app screen is opened. On iOS the tab has to
be open: Safari has no Background Sync yet.

---

## 4. Preparing a device

1. Sign in **while online**, on the network the device will work on.
2. Open `/offline` and press **Prepare now**. The device asks the server which
   screens this login may work (`/offline/preparation`), then keeps each one: the
   page itself in the service worker's cache, and its snapshot in the shared
   snapshot cache the screen reads from. The list says `ready` per screen, and the
   time of the last preparation is kept so a supervisor can see how stale it is.
   Opening each screen once while online does the same thing one at a time.
3. Read the **This device** card on the same page. It reports the service worker,
   the cache and how many files it holds, the storage this origin is using of the
   browser's quota, whether the browser has Background Sync at all, and how old each
   saved list is. If a device is not really ready, that is the card that says so —
   before the line drops, not after.
4. Leave the tab open when the internet is expected to be shaky. The strip under
   the top bar shows offline state and how many changes are waiting.

A login only ever prepares what its permissions carry: a waiter is not sent the
folio snapshot, and a housekeeper is not sent the menu.
4. When the connection returns, nothing has to be pressed: the queue sends itself.

A device that never opened a screen online has nothing to draw from, and says so
rather than showing invented data.

---

## 5. Extending offline support to another module

1. **Snapshot endpoint** — `GET /offline/<module>/snapshot`, permission-gated, JSON,
   `Cache-Control: no-store`, carrying a `version` and `generated_at`. Return only
   what the screen needs, and no guest PII beyond what the screen already shows.
2. **Operations** — one operation per user intent, named `<module>.<thing>`
   (`pos.order.kot`, `housekeeping.task`). Validate the payload in the service,
   check the permission, and use `OfflineSyncService::record()` so the operation id
   is stored and a replay returns the first answer.
3. **Add a match arm** in `OfflineSyncService::apply()`.
4. **Screen** — draw from `PmsOffline.snapshot(...)`, write with
   `PmsOffline.enqueue(...)`, never fetch on tap. Everything the member of staff
   sees after a tap must come from the device.
5. **Service worker** — add the route to `WORKSPACES` in `public/sw.js` so it opens
   from the device when the server is unreachable.
6. **Checks** — extend `deploy/dev/audit/offline_core_check.py` with the new
   operations, and assert that a replay of the same operation id yields one record.

---

## 6. After the line returns

`/offline/reconciliation` is the evidence, not a promise. Every operation a device
replays leaves a receipt in `offline_sync_operations`: which device, which member of
staff, what was asked, what it produced (order number, room, folio) and whether it
applied. The screen filters by date window (31 days at most), status, kind of work
and device, and exports the same rows as CSV.

It is read-only. Looking at what happened cannot change what happened: nothing on
that screen replays, edits or deletes a receipt, and a refused change is still on
the device — the page says so and links to `/offline/queue`, where a person sends it
again or discards it.

Who may read it: a login with `offline.desktop.view`, `frontdesk.view` or
`housekeeping.manage`.

---

## 7. Honest limits

* The snapshot is a copy. Two devices working the same table offline can both open
  a bill; the server joins the second one to the first and says so.
* Storage is the browser's: a device with a full disk or a private window will not
  keep a queue, and the screen says so instead of pretending.
* Clearing site data deletes unsent work. That is what "clear browsing data" means;
  the app cannot prevent it, only warn before it happens.
* Nothing here makes the server itself highly available. If the *server* is down
  but the network is up, the same queue and the same snapshots keep the device
  working.

---

## 8. Checking it

```bash
python3 deploy/dev/audit/offline_core_check.py      # structure, rules, routes, docs
node deploy/dev/audit/offline_pos_dom_check.js      # the terminal, driven in jsdom (needs: npm i --no-save jsdom)
node deploy/dev/audit/offline_housekeeping_queue_check.js   # housekeeping: queue, migration, checklist
node deploy/dev/audit/offline_guest_request_queue_check.js  # guest requests: queue and migration
node deploy/dev/audit/offline_hub_check.js                  # the hub: device health, queue, preparation
node deploy/dev/audit/php_syntax_check.js           # PHP parses
node deploy/dev/audit/blade_syntax_check.js         # Blade parses
node --check public/js/offline-core.js              # queue engine parses
node --check public/js/offline-pos.js               # terminal parses
```

A live outage drill on a real device remains the only way to prove the whole chain
end to end; there is no browser in the development sandbox.
