# Front Office & Guest Services — completeness audit

**Latest revalidation:** 26 Oct 2026 · **Branch:** `arena/fb5ac1ac-nowillsgrouppmserp` · **App version:** 7.77.0

**Current result:** all **65/65** Front Office & Guest Services menu pages returned HTTP 200 with a valid single-document HTML structure. The menu has **0 undeclared permissions** and **0 missing badge keys**. Two confirmed Front Office gaps are fixed: the built-in Front Office Manager lacked `robita.manage`, leaving the Robita menu inaccessible; and an invalid IPTV status value produced a misleading “TV awaiting pairing” message. The Robita grant is now included in the role upgrade path; the IPTV status and pairing validations now return distinct, actionable messages. The current role probe shows Front Office Manager **33/33** page permissions and Receptionist **22/33**. The lock-management permission remains withheld from Receptionists because it includes direct physical-lock, credential and device-management operations.

Two adjacent date-boundary defects were also fixed. The guest-voucher `liabilityAt()` helper omitted timestamp-shaped same-day values from SQLite as-of totals; it now uses `whereDate` (the helper has no current application call site, so this was a latent calculation bug). The date-specific `/api/v1/reports/daily` lookup returned a false 404 for stored timestamp-shaped NightAudit rows; it now queries by date and returns the existing report.

A separate sweep requested **471 unique, parameter-free GET paths** from the full route table: 460 returned clean 200 responses; 11 did not, and source inspection confirmed all 11 are expected test-context outcomes (nine API endpoints require bearer credentials, `/group-dashboard` requires `group.dashboard`, and `/reservations/api/rates` requires `room_type_id`). The broad route sweep itself uncovered no additional runtime defect. The four models with no rows in the disposable demo database are user/property data or optional configuration—not evidence of unfinished code.

**Verification:** `navigation_permissions.php` 100/100; Robita guest-journey 17/17; Robita stay-wiring 9/9; IPTV volume policy 2/2; IPTV status-validation 3/3; gift-voucher date boundary 1/1; night-audit API date lookup 1/1; lock-driver checks 10/10; PABX-driver checks 7/7. Historical end-to-end write-flow evidence from 13 Oct is retained below; it is not a claim that vendor hardware, live payment providers, or vendor-specific OTA integrations have been accepted in production.

## Current revalidation (26 Oct 2026)

| Check | Result |
|---|---|
| Front Office & Guest Services menu pages | **65/65 HTTP 200**, no structural defects, no empty-content pages flagged |
| Menu permissions / badge keys | **0 / 0 missing** |
| Front Office Manager / Receptionist page permissions | **33/33 / 22/33**; the Receptionist omissions are management/security gates |
| Full parameter-free GET sweep | **460 clean 200 / 471**; all 11 non-200 outcomes explained below |
| Confirmed Front Office RBAC / UX defects | Front Office Manager lacked `robita.manage`; IPTV invalid-status errors blamed pairing. Both fixed and covered by regression checks |
| Adjacent DATE-boundary defects | Gift-voucher as-of helper (latent; no current call site) and daily NightAudit API lookup (false 404 for timestamp-shaped rows); both fixed with date-safe lookup and regression checks |
| Other confirmed Front Office defects | **0 additional defects found in this pass** |

**False positives / expected outcomes (not product defects):** `/api/v1` and its eight tested resources return 401 without an API bearer token; `/group-dashboard` returns 403 to the dev identity because it lacks the separate group-dashboard grant; `/reservations/api/rates` returns 404 when the required `room_type_id` is omitted from the synthetic request. A candidate Carbon-to-date comparison in `IncomeAuditService::resolveCount()` was tested with both model-saved and timestamp-shaped signed audit rows; both correctly blocked resolution, so no change was made. The parameter-free sweep is a broad smoke test, not an authenticated API contract test.

**Empty data is not a missing feature:** the latest disposable database has no saved reservation desk views, waitlist rows, room images, or room-outage records. These are per-user settings, transient guest demand, or property-specific operating data and are created through existing workflows. The optional amenity catalog had rows after the local upgrade, confirming the previous zero counts were dataset-specific.

**Scope limits:** real lock encoders, PABX, IPTV devices, payment credentials, and vendor channel-manager acceptance remain deployment/vendor validation items. Robita guest journeys are automated locally; the generic TCP lock adapter remains template-based until a hardware vendor protocol and device are available. This audit does not certify five-star service outcomes or hardware compatibility.

---

## Reservation follow-up (27 Oct 2026)

A source review of the New Reservation form found a confirmed DOM-XSS path in the existing-guest lookup: guest-controlled profile fields were concatenated into `innerHTML`. The form also constructed room and rate-plan `<option>` elements from database text in HTML strings, and overlapping asynchronous availability / rate / guest searches could let an older response replace newer choices.

**Fixed:** guest matches and live room/rate choices are now built with DOM APIs and `textContent`; guest search has combobox/listbox semantics, keyboard navigation, empty/error states, and stale-response checks. Room and rate lookups reject stale responses, report lookup failures without blocking the booking, and preserve a selected room while dates are refreshed. Date-only arithmetic now avoids timezone/DST drift and keeps departure after arrival. The existing contract banner remains the only dynamic HTML insertion in the form and HTML-escapes every interpolated server value.

**Verification:** `deploy/dev/audit/reservation_lookup_safety_check.py` (6 source-contract checks), the existing reservation CSV/edit/outage guards (14 checks), inline JavaScript syntax checks, and `git diff --check` pass. A Laravel/PHP runtime flow was not run in this workspace.

## Historical deep-dive (13 Oct 2026 baseline)

The following scorecard, route/flow counts and workflow records describe the earlier 13 Oct snapshot (with a 20 Oct navigation follow-up), not the current inventory above.

## 1. Scorecard

| Check | Result |
|---|---|
| Modules in the section | **10** (Front Office, Desk Ops, Reservations, CRO, Concierge, Guest Messaging, Kiosk, IPTV, Foreign Guests, Interfaces) |
| Menu pages | **63 / 63 return HTTP 200** |
| Named routes | **370** (142 GET + **228 write**) — every one resolves to an existing action and view |
| Stub / placeholder actions | **0** |
| Broken `route()` references in views | **0** |
| Permission badges with no matching key | **0** |
| Permissions declared vs. seeded vs. held by Front Office Manager | **31 / 31 / 31** |
| Scheduled automations touching this section | **29** registered (`schedule:list`) |
| Write flows driven over HTTP | **126 scripted** (batches A–D) + **14 targeted** (batch E / device APIs) |
| Genuine defects found | **1** — forex rate desk (fixed, verified) |
| Business-rule refusals observed | all correct (each one is a documented guard, see §5) |
| Stale documentation (at the time of the 13 Oct audit) | `docs/MODULES.md` then listed FO&GS as 64 pages vs the live 63; the 20 Oct follow-up exposed Desktop Sync, and both now show **64** |

Per-module inventory (menu pages | GET routes | write routes | total):

| Module | Pages | GET | Write | Total |
|---|---|---|---|---|
| Front Office | 8 | 17 | 13 | 30 |
| Desk Ops | 7 | 15 | 24 | 39 |
| Reservations | 8 | 33 | 57 | 90 |
| Central Reservations (CRO) | 6 | 7 | 6 | 13 |
| Concierge | 6 | 14 | 21 | 35 |
| Guest Messaging | 6 | 11 | 21 | 32 |
| Kiosk | 4 | 12 | 11 | 23 |
| IPTV | 9 | 16 | 38 | 54 |
| Foreign Guests | 6 | 13 | 13 | 26 |
| Interfaces | 3 | 4 | 24 | 28 |
| **Total** | **63** | **142** | **228** | **370** |

One page exists beyond the sidebar: `/reservations/amenities` (amenity library and room links). It is reachable from the reservations screens and works.

---

## 2. How this was verified

Nothing here is inferred from reading code. Three independent passes were run against a live server (`deploy/dev/bin/pms-serve`, business date 13 Oct 2026, hotel NOWILLS):

1. **Static resolution** (`/tmp/audit1–3.py`, `/tmp/fo_static.json`) — walked the route table, resolved every action and Blade view, grepped for stubs, missing permission keys and `route()` calls with no matching name.
2. **Page sweep** (`deploy/dev/audit/http_sweep.py` → `fo_http.json`) — authenticated GET of all 63 pages: status, byte size, doctype-first, form count, permission badge keys.
3. **Behavioural flows** (`deploy/dev/audit/pmshttp.py` + `flows_lib.py` + `flows_a…e.py` → `flows_*.json`) — real POSTs with fresh CSRF tokens and correct Referers, then read the flash, the redirect target, and the database. `form_dump.py` was used to discover the actual field names of every form, including the ones rendered as `action="#"` and driven by JavaScript.
4. **Database confirmation** (`deploy/dev/bin/php -f deploy/dev/tinker.php`) — wherever a flash message was ambiguous or absent, the row itself was read back.

The behavioural batches initially scored A 16/16, B 27/29, C 28/46, D 20/35. **Every non-pass except one was a property of the harness, not the product** — either a JSON/SPA endpoint being probed as an HTML form, a `languages[]`-style checkbox array not being scraped, a form living on another tab, or a deliberate business-rule refusal. Those were then closed one by one with targeted probes (§4).

---

## 3. The one defect: Foreign Guests exchange-rate desk

**Symptoms.** `POST /foreign/rates` and `/foreign/rates/copy` returned **HTTP 500** — `SQLSTATE[23000]: UNIQUE constraint failed: forex_rates.hotel_id, currency_id, date`. `ForexService::rate()` and `todayRates()` returned null even when rows existed, so every exchange was refused with *No USD rate has been set for 13 Oct 2026*. The seeder skipped its FX demo data, so a fresh install had an empty desk.

**Root cause.** `forex_rates.date` is a `DATE` column, but the Eloquent `'date'` cast writes `2026-10-13 00:00:00` on SQLite. Bare `where('date', $d)` / `updateOrCreate(['date' => $d], …)` therefore never matched the stored row, while the unique index still recognised the same day — so lookups missed and inserts collided. This is the same date-storage trap that has already produced a class of defects elsewhere in the codebase (419 comparable sites were swept earlier); the canonical form is `whereDate()`.

**Fix** (3 files, +19/−8, uncommitted at the time of writing and included in this branch):

- `app/Services/ForexService.php` — `rate()`, `todayRates()` now use `whereDate()`; `saveRates()` and `copyPrevious()` look the row up first and update it instead of inserting.
- `app/Http/Controllers/ForexController.php` — `rates()` uses `whereDate()`.
- `database/seeders/DatabaseSeeder.php` — the `$rateOn` demo-rate lookup uses `whereDate()`.

**Verification after the fix** (all over HTTP, then confirmed in the database):

| Step | Result |
|---|---|
| Save the daily board | 12 rates saved on one POST, 200 |
| Copy from the previous day | filled a blank day, no unique violation |
| Exchange for a resident | **FX-00001**, USD 100 → **GH₵ 1,145.00** credited to the room folio |
| Exchange certificate | 148,172 bytes, doctype-first, carries the FX number |
| Void the exchange | flash confirms *the bill credit was reversed*; folio agrees |
| Cash exchange | **FX-00002**, USD 200 over the counter |
| Currency position | shows the held-currency line with an input for surrender |
| Surrender | **FXS-00001** — GH₵ 581.00 realised, **gain GH₵ 8.50** |

---

## 4. What was proven working, module by module

**Front Office / Desk Ops.** Guest-request lifecycle, log book entries, wake-up calls (with the ±10-minute clash guard), parcels, traces, lost & found intake and valuable release, desk settings (morning < evening < night enforced), desk views saved per user.

**Reservations.** Waitlist add → check → **convert to RES-00205**; room outage block/release; amenity create; room photo upload; rate override set and removed; room block **BLK-00004** created → definite → pick-up → release; Reservation Workspace room assignment; desk dashboard, board, timeline, arrivals, departures, in-house, unassigned, groups and waitlist views all render and act; printable Day Report.

**CRO.** Enquiry **ENQ-00031** logged → contacted → **converted to RES-00208 at GH₵ 6,020**; lost-enquiry reason recorded; CRO settings saved. 30 seeded enquiries.

**Concierge.** Activity booking **ACT-00022** with voucher, settlement **AST-00003**, and a void. Guards confirmed correct: an activity date outside the stay is refused; `complete` before the activity date is refused; instant-availability bookings auto-confirm at creation (there is deliberately no separate confirm form). 21 seeded bookings.

**Guest Messaging.** Inbox quick replies, rules, rule test, broadcast to 9 guests, settings, webhook secret rotation. Conversation 16 was replied to, noted, assigned to user 1, set to priority `high`, routed to department `housekeeping`, closed and reopened — and a **guest request GRQ-00014** was raised from the chat (source `chat`, stay 65). These flows showed no flash because the conversation screen redirects to `/inbox/c/{id}`, which renders no alert; the database confirms every one applied.

**Kiosk.** Desk side: kiosk registered, status changed, token rotated (note: the flash truncates the token at 160 characters, so the token must be read from the kiosks screen or the database), settings saved. Guest side is a **JSON API**, not an HTML form, and the full state machine was driven:

`POST /kiosk/{token}/start` → visit **KSK-00072** → `find` (`ref=RES-00071`, `last_name=Menon`) → booking resolved (Dr Kavita Menon, Deluxe, room 202, 1 night) → `document` (multipart ID photo; the service reads the image and requires ≥200×120 px and >2 KB — a 1×1 JPEG is correctly rejected) → `sign` (PNG signature data-URL, GRC stored on the stay) → `pay` (**OnlinePayment PL-00011, GH₵ 4,126.50** deposit, purpose `deposit`) → settled through the built-in test gateway (`/pay/{token}/start` → `/pay/{token}/demo` with `result=success` → signed `/pay/{token}/return`) → `pay-status` reports `paid` → `room` (assigned) → `keys` (card issued) → **`done`, visit `completed`**.

Database afterwards: stay 71 `checked_in`, room **202**, `checked_in_at` set, `grc_signature_path` set, 1 ID document, 1 key card, folio 184 carrying the GH₵ 4,126.50 online payment on top of the GH₵ 1,500 advance.

**IPTV.** Desk side: device list (42 devices), message **TVM-00010** to room 103, channel create/edit, **M3U import ("1 added, 0 updated")**, welcome-message template with the documented placeholders, **emergency TVM-00011 pushed to 27 screens**, and the **all-clear** (`POST /iptv/emergency/11/clear` → *All clear sent – the alert is off the screens*). Settings save correctly once the `langs[]` checkbox array is posted (*IPTV settings saved*). Device status accepts only `active` / `disabled` (*TV updated*). All **seven** remote commands queued to a device: `reload`, `reset`, `message`, `volume` (10–100 enforced), `power_off`, `power_on`, `tune` (must be an active channel number in the line-up).
Guest side is JSON: `/tv/{token}` app shell (4,001 B), TV state (3,077 B), usage event 200, and a **pay-per-view rental** — `POST /tv/{token}/rent/1` refused with `Wrong PIN – 3 attempt(s) left` until the stay PIN was supplied, then returned `{"ok":true,"number":"TVP-00007","url":"…/the-gold-coast-heist/index.m3u8","title":"The Gold Coast Heist","left":24,"total":"GH₵ 102.85"}`.

**Foreign Guests.** Exchange chain as in §3. Form C: record **FC-00057** for stay 69 saved with 23 fields → submission refused until an FRRO reference was supplied → submitted with `FRRO/AUDIT/20261013` → departure reporting correctly refused (*The guest has not checked out yet*) → reopened with a reason. Settings save through `/foreign/settings`.

**Call accounting.** SMDR import in the documented column order (`date,time,ext,trunk,number,duration`) produced ext 101 / 251 s / class `std` / **GH₵ 40.00 posted**, ext 103 / 52 s / `std` / **GH₵ 8.00 posted**, and ext 206 / 760 s / **`isd` / GH₵ 390.00 held for review** because the extension does not map to an in-house guest — with a re-import correctly reporting *3 duplicate(s) skipped*. Also proven: manual call log (GH₵ 135, room 101), posting a call to a folio (GH₵ 18), waiving a call, extension 199 created, tariff create/update, reprocess, and *Every room already has an extension* from `/calls/extensions-sync`.

**Key cards.** **KC-00017** encoded; card read by `serial`; **KC-00004** reported lost with **2 replacements**; KC-00006 returned. Master keys: **AUDIT-MK1** registered; **EM-01** signed out to a holder with a due time (*due back 13 Oct 21:18*) and signed back in (*EM-01 signed in*), with the overdue-master-key alert rendering on the same screen.

**Interfaces.** Hub settings saved, PBX token rotated, command retry routes present (the retry-all button renders only when failed commands exist — correct).

---

## 5. Product rules that look like failures but are not

These are deliberate guards, verified by reading the guard and then triggering it:

- **Form C ships switched off** (`formc_enabled = "0"`). The register says *Form C tracking is switched off for this hotel* and links to the switch. Once enabled it auto-opens records for foreign in-house stays, needs an FRRO reference to submit, blocks departure reporting until check-out, and needs a reason to reopen.
- **A paid movie rental needs the guest PIN** (`reservation_rooms.tv_pin`, written at check-in and resettable from `/iptv/stay/{stay}/reset`). Wrong PIN decrements an attempt counter.
- **Kiosk and IPTV guest surfaces are JSON/SPA device APIs**, not HTML forms — an HTML-form sweep will always report them as empty.
- **IPTV emergency broadcast requires literally `confirm=EMERGENCY`**, and the all-clear lives at `/iptv/emergency/{id}/clear`.
- **Concierge** refuses activity dates outside the stay and refuses completion before the activity date.
- **Currency surrender lines render only for currencies actually held** (> 0.004).
- **Conversation screens render no flash** — they redirect to `/inbox/c/{id}`; judge those writes in the database.
- **A saved default desk view makes bare `/reservations` 302** to `?view=…`.

---

## 6. What could not be exercised in this environment

Not gaps in the product — gaps in a headless sandbox:

- **Live payment providers.** Only the built-in `demo` gateway can complete a payment here; Paystack / Stripe / Razorpay / MoMo need real credentials (the webhook route `/webhooks/pay/{provider}/{code}` exists and is signature-verified).
- **Physical hardware.** Door-lock encoders, PBX/SMDR feeds and TV heartbeats are simulated (`deploy/dev/pbx-sim.mjs`, `channel-sim.mjs`, `vsdc-sim.mjs`, `expedia-sim.mjs`, `booking-eps-sim.mjs`); a real device heartbeat could not be produced.
- **Camera capture.** The kiosk ID photo was supplied as a generated 900×560 JPEG; a live capture path was not exercised.

---

## 7. Recommended follow-ups

1. **Documentation — closed (26 Oct 2026, v7.74.0):** `docs/MODULES.md` now records the 65-page Front Office & Guest Services menu and retains the separate `/reservations/amenities` route note.
2. **Defensive DATE-query sweep — partial:** `ForexService`, guest-voucher `liabilityAt()`, and the date-specific NightAudit API lookup now use date-safe comparisons. The latter two mismatches were reproduced against timestamp-shaped SQLite rows and fixed; the voucher helper has no current caller. The broader repository-wide sweep of comparable date predicates remains open—mixed SQLite storage shape alone is not a defect, and the historical 419-site grep count is only a candidate set.
3. **Message clarity — closed (26 Oct 2026, v7.75.0):** `IptvService::setStatus()` now validates the allowed status values separately from the pending-pairing guard, so invalid input no longer produces the unrelated pairing message. `deploy/dev/audit/iptv_status_validation_check.php` covers both cases.
4. **Keep the harness:** `deploy/dev/audit/` (`pmshttp.py`, `flows_lib.py`, `form_dump.py`, `flows_a…e.py`) is now a repeatable regression rig for this section — re-run it after any Reservations/Folio change.

---

## Appendix — reproduction

```bash
bash deploy/dev/setup.sh && bash deploy/dev/recover.sh
PMS_DEV_HOME=/reservations/workspace deploy/dev/bin/pms-serve   # 0.0.0.0:8000
python3 deploy/dev/audit/http_sweep.py            # 63/63 pages 200
python3 deploy/dev/audit/flows_a.py               # 16 flows
python3 deploy/dev/audit/flows_b.py               # 29 flows
python3 deploy/dev/audit/flows_c.py               # 46 flows
python3 deploy/dev/audit/flows_d.py               # 35 flows
python3 deploy/dev/audit/flows_e.py               # device/JSON corners
python3 deploy/dev/audit/form_dump.py /calls?tab=review /key-cards?tab=master
```

Preview click-paths for the fixed and audited surfaces: `/foreign/rates` · `/foreign/forex/new` · `/foreign/position` · `/foreign/form-c` · `/foreign/settings` · `/self-service/visits` · `/self-service/kiosks` · `/iptv/devices` · `/iptv/messages` · `/calls?tab=log|review|extensions` · `/key-cards?tab=master` · `/reservations/workspace` · `/__dev` (development-change log).
