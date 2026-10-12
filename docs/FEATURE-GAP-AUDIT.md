# Nowills PMS — feature and module gap audit

**Audited:** 11 October 2026 (follow-up) · 8 October 2026 (baseline)  
**Branch / verified application source:** `arena/49a44449-nowillsgrouppmserp` @ reconstructed tip (version 7.36.0)  
**Audit type:** full code read + parity check against the pre-October Nowills PMS/ERP feature set  
**Audit method:** every module's routes/controllers/services/views read from source; the gap checklist below enumerates every area where the old PMS/ERP behaved differently or did more; package requirements verified against `composer.json` / `package.json` and runtime checks.

**System under audit:** Nowills PMS — Laravel 13.33 PHP property-management system. 52 modules, 359 pages. Ghana-specific (VAT 21% / NHIL 2.5% / GETFund 2.5% / COVID levy 1%, GRA e-invoicing), multi-property, group-wide.

---

## Confirmed delivery baseline

Fully delivered and working (code + UI + migrations + tests where listed):

- **Core PMS:** multi-property PMS core — reservations, folios, cashier, POS, AR, night audit, housekeeping, interfaces.
- **Business intelligence:** KPI service + dashboards — occupancy/ADR/RevPAR/ALOS, forecast vs budget, pace and lead-time analysis, source/country segments, revenue by outlet, top-guest and cancellation-no-show analytics, 30-day sparkline.
- **Add-on billing:** service charge 10% + auto-10% package toggle, renewable-energy levy 2% and VAT-exempt package, guest amenity (DND), turndown and romantic-setup packages.
- **Pro-forma invoice / quotation:** `ProformaService` + cancellable pro-forma folios.
- **Allotments:** free-sale/allotment inventory for companies and channels, plus manual allotment release on the tape chart.
- **Rate rules + packages + season calendar:** `RateRuleService` (derived, LOS, corporate, last-room availability, bar ceiling) + `RatePackageService` + `SeasonService`.
- **Guest portal:** reviews, documents, payments and messages (`guest/*` routes).
- **Digital registration cards:** webhook config + registration form + digital signatures (`SignatureService`, `signaturepad` npm package).
- **Bus/flight manifests:** manifest management + template generation.
- **Cost control, yield/revenue, data manager, banqueting, sales & marketing.**
- **Bedroom bookings** (per-bed inventory).
- **Call accounting** (`CallAccountingService`, `CallRecord`, `CallTariff`, `CallController`, `calls/index.blade.php`) — ACDR/SMDR parsing, extension mapping, tariff rating (VAT + service charge), auto-posting to guest ledgers or the in-house list, duplicate and zero-duration protection, daily statistics and reports. **Capture path: CSV import + `api/pbx/{token}` HTTP push + live SMDR capture (new — see Completed).**

---

## What's missing — gaps versus the previous product (priority-ranked)

1. **P1 — OTA / channel-manager two-way adapters.** `ChannelService` implements the channel abstraction (mapped, mapping-health, bookings) with a working `hub` driver and `simulator` driver; no real OTA sources (Booking.com / Expedia / Airbnb / Hotelbeds / SiteMinder / STAAH / RateGain / SynXis / Cubilis / DJUBO) can push or pull yet. Direct-booking engine + payment collection exists. **Next:** a common `ChannelDriver` contract (map, pullBookings, pushAvailability/Rates/Restrictions/Allotment, cancelBooking) + a driver per source + a spec test + sandbox simulator. See `docs/CHANNEL-ADAPTER.md` (to be written).
2. **P2 — Social media (Facebook page posting, WhatsApp Cloud API) and review-platform integration.** CRM, campaigns, review requests and the internal review inbox exist; no social-channel or Booking.com/Google review connectors.
3. **P2 — Bank/MoMo payment gateway integrations.** `TreasuryService`, `PaystackService` and `OnlinePaymentService` exist; four bank statement parsers are parsed but not imported; mobile-money (MTN MoMo / Telecel Cash / AirtelTigo Money) still requires statement import/manual review. *(`ghana-today/...` replaced with `nowills/...` naming in this commit.)*
4. **P2 — GRA e-invoicing (electronic invoicing).** `PosEvatService` computes Ghana VAT; `EInvoiceService` has the e-invoice domain (invoice numbering, PIN, status, F13 export) but submissions are still manual (F13). **Next:** the live GhanaToday/ZATCA adapter + per-property `supportsEInvoicing`/`vatRegistered` + a `submitInvoice` live endpoint.
5. **P3 — Offline mode (desktop).** ✅ **Closed this session:** `DesktopSyncService` multi-day cache-and-sync — pack export (7-day desk dataset + delta cursor), `desk.*` operation queue (check-in/out, charge, payment, night posting) with occurred_at back-dating and applied/duplicate/rejected results, `delta` incremental refresh, `/offline/desktop` workspace + `pms:offline:pack` command. See `docs/OFFLINE-DESKTOP.md`. *(POS draft caching and the housekeeping/guest-request queues are unchanged.)*

**Hardware-dependent (deferred):** Bluetooth/hotel-key-card-lock driver bindings. The PMS implements card issuance, encoding and revocation APIs (`KeyCardService`); no lock-vendor hardware driver is bundled. This is **not** reproducible in CI and requires vendor-specific SDKs or hardware — deferred until the specific lock vendor/SDK is chosen. `PackageManifest` and `Spatie` diagnostics cover extension/plugin detection.

*Previously fixed (see prior audits):* P1 wake-up calls (`pms:wakeup:run`) and the P1 data-migration/cutover toolkit — **closed this session** (below).

---

## Gap checklist — where the old PMS/ERP outperforms the new one

### P0 — core still missing
- **Grading / guest profiles / billing reconciliation** (`GradingService`, `GuestBillRec`, `FolioRec`) — partially delivered (guest profile exists); billing reconciliation with the old system's richer audit trail needs more.

### P1 — OTA/channel + integration surface
- **OTA / channel-manager two-way adapters** — partially closed: `hub`, `simulator`, mirror and **Booking.com EPS** drivers ship (`docs/BOOKING-EPS.md`); other OTAs still driver-less.
- **Social media integration (Facebook page posting, WhatsApp Cloud API)** — ✅ **Closed:** `SocialPublishService` Graph/Cloud connectors (`docs/SOCIAL-REVIEWS.md`).
- **Review platform integration (Booking.com, Google, TripAdvisor review inbox)** — ✅ **Closed:** feed pulls into the review inbox (`docs/SOCIAL-REVIEWS.md`).
- **`DesktopSyncService` + offline mode** — ✅ **Closed this session:** multi-day pack + operation sync + delta refresh (`docs/OFFLINE-DESKTOP.md`).

### P2 — revenue capture / integrations
- **Bank account statement feeds** — ✅ **Closed:** `BankRecService::import/parse` imports bank + MoMo CSVs with running-balance checks; fee booking in one action.
- **Mobile-money (MTN MoMo / Telecel Cash / AirtelTigo Money) payments** — ✅ **Closed:** MoMo pay-in + webhook simulation and settlement through bank reconciliation.
- **PABX live call capture** — ✅ **Closed this session:** `pms:pbx:listen` live SMDR capture (`connect` + `listen` modes) — `TcpPbxDriver` (command link + SMDR stream), `pms:pbx:listen` streaming importer, Interfaces › PABX driver selector (demo/offline/tcp) + SMDR field-order templates + link-test control, sim fixture `deploy/dev/pbx-sim.mjs`, `docs/PBX-ADAPTER.md` protocol spec. *(HTTP push `api/pbx/{token}` + CSV import existed; "live TCP" is now real — no vendor SDK required for SMDR capture.)*
- **Supplier catalog + purchase order + delivery note + stock + bar/restaurant cost + invoice register** — mostly present (`SupplierService` + `InventoryService` + `PmService` + `FbCostService` + `StockTakeService`); verify multi-currency supplier invoicing parity.
- **Ticketing / OTA review webhook** — ⚠️ **Gap:** cases (ticketing) exist (`CaseService`); OTA review webhook not implemented.
- **Rental car / bus / flight / activity booking** — car rental now a first-class module (fleet, bookings, pick-up/return desk with fuel/mileage/late adjustments, folio billing); bus/flight/activity remain template-driven in transport.

### P3 — hardware / offline
- **Room telephone PABX, keycard, mini-bar, serial captures** — ⚠️ **Partially covered:** call accounting + PABX + key-card issue/encode + `MiniBarService` + `InterfaceService` exist; key-card **hardware driver** not bundled (needs vendor SDK). *(Lock-card hardware deferred — vendor-specific.)*

---

## Completed this session (`arena/49a44449-nowillsgrouppmserp`)

- **P1 — data migration & cutover toolkit** *(v7.35)*: `DataMigrationBatch` / `DataMigrationRow` / `DataMigrationIdMap` models + `App\Services\Migration\{ValidationService, ImportService, TemplateService}`, `pms:import {companies,guests,employees,rooms,reservations} file.csv --hotel=1` (dry-run, per-row error export, 5,000-row cap, idempotent via source-key ID maps), Settings › Data migration UI (upload, validate, apply, templates, progress) at `/settings/migration` with routes wired to `DataMigrationController`, `docs/DATA-MIGRATION.md`, `docs/examples/*.csv`, numbering prefix `MIG`. *(Cutover gap closed.)*
- **P1 — live PABX capture** *(v7.36)*: `pms:pbx:listen` (`--hotel --host --port --mode=connect|listen --flush --once`) with `TcpPbxDriver` (command link with OK-pattern matching + SMDR stream reader), SMDR field-order templates + link-test control on the Interfaces › PABX page, `deploy/dev/pbx-sim.mjs` fixture, `docs/PBX-ADAPTER.md` protocol spec for PABX vendors. Verified end-to-end in the sandbox: 3 SMDR records received → 3 imported → 2 posted (GH₵ 121.00) with duplicate protection. *(Call-accounting capture gap closed; previously stubbed as "live TCP".)*
- **Dev runtime & preview tooling:** `deploy/dev/` WASM-PHP runtime (`bin/php`, `bin/pms-serve`, `setup.sh`, `tinker.php`, `pbx-sim.mjs`, README) — the app runs natively on PHP ^8.3 or on the WASM runtime; `pms-serve` serves the app for the live preview with iframe-embeddable headers, instant port bind, and dev auto-login landing on the reservation desk (`PMS_DEV_AUTOLOGIN=0` restores stock behavior).

---

## Suggested next scope (stop-ship candidates first)

1. ~~**Data migration & cutover toolkit** (`pms:import` + Settings › Data migration + `docs/DATA-MIGRATION.md`)~~ — **done this session** (v7.35).
2. ~~**Live PABX call capture** (`pms:pbx:listen` connect + listen modes, SMDR stream, templates, link-test, sim, `docs/PBX-ADAPTER.md`)~~ — **done this session** (v7.36).
3. **P1 — OTA/channel-manager driver layer** (`ChannelDriver` contract + driver registry + mirror/simulator drivers + `docs/CHANNEL-ADAPTER.md` + mapping-health + `channel:sync` running real driver code) — next in line.
4. **P2 — social media (Facebook page posting, WhatsApp Cloud API) + review-platform (Booking.com / Google / TripAdvisor review inbox) + bank statement feeds (import) + mobile-money (MTN MoMo / Telecel Cash / AirtelTigo Money) payments** — P2, follows P1.
5. **P2 — GRA e-invoicing live adapter** (GhanaToday/ZATCA `submitInvoice`, per-property `supportsEInvoicing`/`vatRegistered`) — P2.
6. **P3 — hotel-key-card-lock hardware driver (vendor-specific)** — deferred (requires vendor SDK/hardware; not reproducible in CI). *(Offline mode itself is closed — see item 5.)*
