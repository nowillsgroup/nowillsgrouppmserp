# Nowills PMS — whole-codebase feature and gap audit

**Audit date:** 20 October 2026; **remediation verification updated:** 21 October 2026

- **Branch:** `arena/c983888a-nowillsgrouppmserp`
- **Checkout HEAD:** `210d279` (shallow/grafted checkout)
- **Application version:** `config('pms.version') = 7.54.0`
- **Worktree note:** the audit included the existing dashboard/IPTV and dev-preview worktree changes. Subsequent remediation normalized the reservation-rate API payload, corrected both installer version labels, added supplier/inventory catalogue importers, exposed Car Rental, Desktop Sync and HR self-service pages with explicit permission gates, added the non-posting folio billing-reconciliation workpaper, and delivered the manual foreign-currency PO/GRN/matched-bill path; the other pre-existing worktree changes were preserved. The G4 verification and its scope limits are documented below.

**Audit type:** static source review plus route, page, report, and documentation checks. This is not a penetration test, a native-PHP/MySQL production acceptance test, or proof of parity with the previous proprietary Nowills system.

## Executive assessment

Nowills PMS is a broad, substantially implemented hotel ERP/PMS—not a collection of stubs. After the navigation and billing-workpaper changes, the live menu declares **54 modules and 369 pages**. The route table contains **2,023 registered routes**; all **2,019 controller actions** resolve to an existing class and method, with four closure routes. The pre-G3 parameter-free HTTP sweep returned **452 clean 200s from 463 routes**; its 11 exceptions were protected/token-only or required query parameters, not application 500s. The current remediation's targeted navigation/billing smoke returned 8/8 HTTP 200. All **259 report types** rendered over the tested September 2026 date range. The current application source had no TODO/FIXME, “not implemented”, “coming soon”, or stub-action markers.

That breadth does **not** mean every integration, legacy workflow, or production acceptance step is complete. The most important confirmed gaps are: limited legacy-data migration, no in-app backup restoration or off-site backup destination, billing reconciliation still limited to folio documents (direct POS, company-ledger cash receipts and gateway/bank settlements are not attributed), incomplete direct provider coverage, and a desktop-offline backend without a corresponding autonomous desktop client. Manual foreign-currency PO/GRN/matched-bill processing was added on 21 October 2026 under the receipt-date Forex Desk policy; cross-currency RFQ quote comparison remains out of scope. GRA e-invoicing and external providers also require real-world acceptance with credentials and counterparties.

The report separates **not built**, **partially built**, and **not verified in this environment**. A missing live credential, hardware device, or regulator test is not automatically a code defect.

## 1. What is in the audited application

The live module inventory is documented in [`MODULES.md`](MODULES.md):

| Menu group | Modules | Pages |
|---|---:|---:|
| Dashboard & Operations | 4 | 4 |
| Front Office & Guest Services | 10 | 64 |
| Guest Relations & Commercial | 7 | 49 |
| Revenue & Financial Management | 7 | 53 |
| Housekeeping & Laundry | 2 | 9 |
| Food & Beverage | 6 | 57 |
| Accommodation & Property | 3 | 16 |
| Facilities & Security | 4 | 18 |
| Wellness & Recreation | 3 | 13 |
| HR & Workforce | 3 | 40 |
| Quality, Safety & Sustainability | 3 | 27 |
| Administration & Configuration | 2 | 19 |
| **Total** | **54** | **369** |

The modules cover reservations and room inventory, front desk and guest services, CRM/sales, folios and cashiering, POS and kitchen, banquets, procurement and inventory, AP/GL/treasury, Ghana payroll and statutory calculations, security, transport, facilities, wellness, sustainability, privacy, portals, API/webhooks, and administration. The complete page-by-page list is in `docs/MODULES.md`.

At audit time, working routes for **Car Rental**, **Desktop Sync**, and HR self-service pages were not in the sidebar. The remediation now exposes Car Rental and Desktop Sync under operations, the three employee self-service pages under HR & Payroll (only for linked employees), and the HR Self-Service Desk for `hr.manage` users. Billing Reconciliation is visible to users with `billingrec.view`; the built-in Accountant role receives view, manage, and approval permissions. Default Front Office Manager and Receptionist roles receive the required rental and offline-sync permissions through `pms:upgrade`; existing custom roles remain under administrator control.

## 2. Checks performed against this checkout

| Check | Result | Interpretation |
|---|---|---|
| Registered routes | 2,023 total; 2,022 named; no duplicate names or method/URI pairs | Route inventory is internally consistent. |
| Route action resolution | 2,019 controller actions checked; 4 closures; 0 missing classes/methods | No dead controller actions found. |
| Parameter-free GET sweep (pre-G3) | 463 checked; 452 HTTP 200; 0 HTTP 500 | Nine `/api/v1` endpoints return 401 without bearer credentials; `/group-dashboard` returns 403 without its permission; `/reservations/api/rates` needs query parameters and returned 200 when called with a room type and dates. The current G3/navigation changes received a separate 8-page HTTP smoke. |
| Report sweep | 259/259 HTTP 200 | Tested with `from=2026-09-01&to=2026-09-30`; this is not a proof of every date/data combination. |
| Module documentation check | Latest run: 207 checks, 0 discrepancies | Includes the documented receipt URI check against the live route registry; `docs/MODULES.md` matches the 54-module / 369-page menu. |
| Navigation/RBAC regression check | 89/89 passed | Sidebar routes, permission definitions and middleware, employee-only visibility, billing-reconciliation permissions, and Accountant grants verified. |
| HTTP navigation smoke | 8/8 pages returned 200; Desktop Sync, Rental Desk, HR ESS, and Billing Reconciliation links visible | Employee-only links remain hidden for the unlinked dev admin, while the three self-service routes return their explanatory unlinked state. |
| Billing reconciliation service harness | 15/15 passed; rollback-only | Covers null invoice-date fallback, source changes/cancellation, invoice/credit snapshots, payments/refunds, company-ledger separation, evidence totals, four-eyes finalisation/reopen, and no GL journal creation. |
| Feature-gap artifact check | 333 checks passed; 2 history checks failed | Both failures are the audit document’s referenced commit `6bb63c3` not being available/ancestor in this shallow checkout; they are provenance checks, not missing feature artifacts. |
| Explicit TODO/stub scan | 0 application-source matches | No obvious markers were found; this does not rule out undocumented omissions. |
| Blade compilation | Successful during dev recovery | Templates compiled under the development runtime. |
| PHPUnit | Not reproducible from this checkout | There is no tracked `tests/` tree or `phpunit.xml`; `php artisan test` in the PHP-WASM runtime fails with `Executable not found in $PATH: "exec"`. The direct PHPUnit runner is also not usable through this runtime. |

### Test-evidence caveat

`docs/TEST-STATUS.md` reports 651 passing PHPUnit tests on code commit `d857feb` from 8 October 2026. That historical result is not reproducible from this branch snapshot: the checkout is shallow at `210d279`, contains no app test suite/configuration, and uses PHP 8.5.11 WebAssembly with SQLite for local development. Do not present the old CI result as a fresh test run for this exact worktree. The repository does have many focused scripts in `deploy/dev/audit/`, but they are not a substitute for a current, checked-in automated regression suite.

## 3. Confirmed feature gaps and incomplete scopes

### G1 — Financial and historical-stock cutover remains incomplete; opening-stock import and opening-GL preflight are staged (**high**)

The initial audit found five importable datasets. Remediation added **supplier master records**, the **inventory item catalogue**, the **opening-stock cutover importer**, and now an **opening-GL trial-balance preflight**. `MigrationService::DATASETS` supports companies, guests, employees, rooms, suppliers, inventory items, opening stock, opening GL preflight, and reservations. Opening stock creates dated movements and a balanced Dr Inventory / Cr Opening Balance Equity journal. The GL preflight requires explicit active Nowills account-code mapping, one as-of date, an exact balanced source TB (no automatic Opening Equity plug), and an exact inventory-to-stock-subledger match; it reports a provisional adjustment against existing GL so the stock journal is not duplicated. **Posting is deliberately disabled** until supplier, folio and other opening subledgers and their control-account/duplicate-posting rules are ready. Supplier bills/payments, folio/payment history and historical stock movements remain unfinished. Supplier bank details and expense-account mappings are deliberately excluded; inventory allergens must be verified in Nowills. Finance approval, subledger reconciliation and sign-off are still required.

**Evidence:** `docs/DATA-MIGRATION.md`; `app/Services/Migration/MigrationService.php`; `app/Services/Migration/Importers/SupplierImporter.php`; `app/Services/Migration/Importers/InventoryItemImporter.php`; `app/Services/Migration/Importers/OpeningStockImporter.php`; `app/Services/Migration/Importers/OpeningGlImporter.php`; `deploy/dev/audit/opening_stock_migration.php` (20/20) and `deploy/dev/audit/opening_gl_preflight.php` (19/19), both rollback-only and posting no GL changes.

### G2 — Backup export exists; product-managed recovery/off-site replication does not (**high**)

`pms:backup` and the Backup page create SQL exports and retain them locally under `storage/app/backups`. The Backup UI instructs an operator to restore by running `mysql … < file.sql` or using phpMyAdmin. There is no in-app restore workflow, verified restore test, or built-in off-site/cloud backup target. The cPanel guide correctly warns that a backup on the same server is not an off-site backup; moving it elsewhere is operational/manual.

**Evidence:** `app/Services/BackupService.php`, `app/Console/Commands/PmsBackup.php`, `resources/views/settings/backup.blade.php`, `docs/INSTALL-CPANEL.md`.

### G3 — Billing reconciliation: folio-document workpaper shipped; wider settlement attribution remains (**high**)

The new Accounts › Billing › Billing Reconciliation workpaper snapshots issued folio invoices and credit notes by invoice date, their active folio payments/refunds, and city-ledger transfers (kept separate from cash). Finance records the settlement amounts, reference, optional private evidence file, and a matched/exception/no-cash outcome. Changes are four-eyes finalised and reopenable with a reason. It is deliberately **non-posting**: it creates, reverses, or changes no GL journal.

This first scope does not include direct POS bills, later company-account receipts allocated back to individual folio invoices, or gateway payout/bank-statement line attribution. Extending reconciliation across those sources requires explicit finance rules for batch allocation, fees, timing, and company-account settlements before it should be automated.

**Evidence:** `app/Services/BillingReconciliationService.php`; `app/Http/Controllers/BillingReconciliationController.php`; `docs/BILLING-RECONCILIATION.md`; `deploy/dev/audit/billing_reconciliation.php`.

### G4 — Manual foreign-currency PO / GRN / matched-bill flow — RESOLVED (21 October 2026)

Manual purchase orders retain supplier-currency values and a PO-date Forex Desk quote for approval. Each GRN uses the configured Forex Desk board and carry-forward window on its receipt date; stock and the GRN accrual remain in the property's currency. The matched supplier bill uses the invoice-date board, and receipt-to-invoice FX movement posts separately to Exchange Gain / Loss; only genuine price/tax variance is tested against AP tolerance and sent for AP review. The PO receipt and matched-bill screens identify carried-forward rates. Home-currency purchasing retains its prior path.

**Scope left open:** RFQ quote entry/comparison remains in the property's currency; foreign-currency RFQs require a separate cross-currency comparison design. Native PHP/MySQL acceptance is also still required.

**Evidence:** `docs/MULTI-CURRENCY.md` G4 and Verification; `deploy/dev/audit/procurement_fx.php` (37/37); `deploy/dev/audit/ap_fx.php` (85/85); `deploy/dev/audit/ap_fx_flow.py` (43/43); `docs/TEST-STATUS.md`.

### G5 — OTA coverage is real but limited to the registered drivers (**medium/high, depends on channel mix**)

The driver layer and two-way channel pipeline exist. Registered drivers are the hub, simulator, mirror, Booking.com EPS, Expedia Rapid, and SiteMinder. The repository’s gap audit names **STAAH, RateGain, SynXis, Cubilis, DJUBO, Hotelbeds, and direct Airbnb** as not yet implemented. SiteMinder can front multiple OTAs, but that is not the same as a dedicated adapter for every OTA or every hotel’s chosen channel manager.

**Evidence:** `app/Services/Interfaces/ChannelDriverRegistry.php`; `docs/CHANNEL-ADAPTER.md`; `docs/FEATURE-GAP-AUDIT.md`.

### G6 — Social publishing is not live for every configured platform (**medium**)

Live publishing is limited to **Facebook Pages** and **WhatsApp Cloud**. Instagram, X, and LinkedIn are recorded-only: the PMS stores a post URL/status but does not deliver the post through those platforms. Review ingestion is implemented through a configured review-feed/aggregator endpoint and a per-hotel webhook; platform credentials/feeds still need to be supplied and tested.

**Evidence:** `app/Services/SocialPublishService.php` (`LIVE = ['facebook', 'whatsapp']`); `docs/SOCIAL-REVIEWS.md`.

### G7 — Transport has operational workflows, not full live travel inventory connections (**medium**)

Rental-car desk and fleet workflows are implemented and now discoverable in the sidebar under **Facilities & Security → Car Rental**. The feature-gap audit says bus, flight, and activity functions remain template-driven in Transport; no live airline, bus inventory, or third-party activity-availability adapter is identified in the current driver set. Front Office Manager and Receptionist default roles receive `rentals.view`/`rentals.manage`; property-specific custom roles still need an administrator to grant the intended rental permissions.

**Evidence:** `docs/MODULES.md`; `config/pms.php`; `app/Services/HotelProvisioner.php`; `routes/web.php`.

### G8 — “Desktop offline” is a backend/sync slice, not a finished offline desktop product (**medium**)

`DesktopSyncService` can build a multi-day data pack, return deltas, and safely apply a defined set of operations after reconnection. However, the `/offline/desktop` page asks the operator to paste a JSON operation queue. The service worker caches only the housekeeping and guest-request offline workspaces, not `/offline/desktop`; the browser IndexedDB outboxes are for housekeeping and guest requests, not the desktop desk-operation queue. There is no packaged desktop client or automatic local queue/cache/replay UI in this checkout. Thus the server-side protocol is built, but a normal front-desk user still needs a separate client/integration to work offline through this path.

**Evidence:** `resources/views/offline/desktop.blade.php`, `public/sw.js`, `public/js/offline-sync.js`, `public/js/offline-guest-requests.js`, `app/Services/DesktopSyncService.php`.

### G9 — Full GTA hotel-grading workflow is only partially represented (**medium**)

The code has a compliance-register item for the GTA Star Rating / Grading Certificate. The feature-gap audit does not identify a full GTA grading workflow—self-assessment rubric, scored inspection, evidence capture, corrective actions against grading criteria, and certificate renewal tied to scores. The register should not be mistaken for a complete grading/inspection system.

**Evidence:** `docs/FEATURE-GAP-AUDIT.md` discussion of grading as a `ComplianceService` register item.

### G10 — Current REST API v1 covers a limited slice of the PMS (**medium if external systems need more**)

The public API exposes hotel/events, availability and rates, reservation list/create/show/cancel/folio, room list/update, guest list/show, and a daily report, with token scopes. No v1 routes are registered for the full set of folio mutations, POS, inventory/procurement, payroll, HR, housekeeping tasks, banquet operations, or administration. Those are not currently part of the documented v1 surface.

**Evidence:** `routes/web.php` API v1 route group; `app/Http/Controllers/Api/V1Controller.php`.

### G11 — Navigation discoverability gap — RESOLVED (20 October 2026)

Desktop Sync is now linked under Front Office, Car Rental under Facilities & Security, the three employee self-service pages under HR & Payroll (hidden when the login has no linked employee record), and the HR Self-Service Desk under `hr.manage`. Desktop Sync now uses declared `offline.desktop.view` / `offline.desktop.sync` permissions instead of the previously undefined `frontoffice.view` / `frontoffice.manage` keys. `pms:upgrade` registers the new permission keys and grants the required access to the built-in Front Office Manager, Receptionist and Administrator roles without overwriting custom role grants. Existing custom roles still need an administrator to assign intended access.

The expanded 89-check RBAC/navigation harness passes, `docs/MODULES.md` reconciles against the live menu with 0 discrepancies, and the active dev preview returned HTTP 200 for all eight targeted navigation/billing pages. Billing Reconciliation is visible to `billingrec.view` users; the Accountant role receives view/manage/approve permissions.

**Evidence:** `config/pms.php`; `routes/web.php`; `app/Services/HotelProvisioner.php`; `resources/views/partials/sidebar-nav.blade.php`; `deploy/dev/audit/navigation_permissions.php`; `docs/MODULES.md`.

## 4. Live integration and acceptance work still required

These items have code paths or adapters, but the sandbox cannot prove production acceptance without real credentials, devices, or counterparties:

1. **GRA E-VAT:** the live VSDC driver exists, but its payload field names still require joint GRA/user-acceptance confirmation before switching a property from sandbox to live. Separately, Ghana PAYE/SSNIT/Tier 2 screens, CSV exports, and remittance records are present; no electronic filing endpoint for those statutory returns is identified.
2. **Payment gateways / Mobile Money:** demo/simulator flows are testable. Paystack, Stripe, Razorpay, and MTN/Telecel/AirtelTigo integrations need merchant credentials, callbacks/webhooks, and production settlement reconciliation. They were not validated against live merchant accounts in this audit.
3. **Bank reconciliation:** file import is implemented for CSV, Excel, MT940/MT942, and camt.052/camt.053. No direct bank/Open Banking connection is identified; the operator imports statements.
4. **OTA and review providers:** adapters/feed paths exist, but each hotel needs real vendor credentials and end-to-end certification against its chosen provider.
5. **Social delivery and messaging:** the `.env.example` defaults `MAIL_MAILER=log`; the installation guide requires SMTP setup, and the HR audit notes SMS can be configured as `log`. Until production transport credentials/settings are supplied, messages may be recorded rather than delivered.
6. **Physical devices:** no key-card lock vendor driver is bundled. The code has key-card issuance/API abstractions and generic TCP PBX/SMDR capture, but vendor-specific lock SDKs, real encoders, biometric clocks, real PBX devices, and actual TV sets were not connected here. Hardware-dependent workflows need a named vendor and on-site acceptance.
7. **Ghana holiday calendar:** dates are editable because Islamic dates and substitute days follow official gazettes; no automatic gazette ingestion is identified, so properties must review/update the calendar.
8. **Tier 3 pension files / provider integration:** the HR audit describes Tier 3 as voluntary/provider-led and not validated against a provider file format.

These are **not all code omissions**. Some are required configuration or external acceptance work.

## 5. Quality, documentation, and release-readiness gaps

### Q1 — No current checked-in application test suite

There are no tracked app tests or `phpunit.xml` in this checkout. `composer.json` advertises `composer test`, but the PHP-WASM dev runtime cannot run `artisan test` because its process execution dependency (`exec`) is unavailable. The historical 651-pass result in `docs/TEST-STATUS.md` is useful background, but does not substitute for rerunning the exact current branch under native PHP 8.3+/MySQL 8. Add a checked-in regression suite and CI that runs on the supported production database/runtime.

### Q2 — Versioned install instructions were stale — RESOLVED

At audit time, `START-HERE.txt` and `docs/INSTALL-CPANEL.md` said **7.34.0** while `config/pms.php` reported **7.54.0**. Both installer labels have now been updated to **7.54.0** and verified against the app config.

### Q3 — Audit-document provenance — RESOLVED (21 October 2026)

`docs/FEATURE-GAP-AUDIT.md` now identifies the active branch and the available source snapshot commit (`210d279905ba78217ff35ad2afc8e9bd5e547234`), and states that the audit also includes the uncommitted worktree. `deploy/dev/audit/gap_audit_check.py` reads that source hash from the header and verifies it exists and is an ancestor of `HEAD`, instead of pinning the unavailable historical `6bb63c3`. The checker now reports **341 passed, 0 discrepancies**. The checkout remains shallow, so it cannot verify unrelated history outside the recorded snapshot.

### Q4 — API payload serialization anomaly — RESOLVED IN THIS RUNTIME; NATIVE-PHP TEST STILL NEEDED

The internal `/reservations/api/rates` endpoint initially returned nested `plans` as Eloquent collection internals under PHP-WASM. `ReservationController::apiRates()` now explicitly converts the selected plans to a plain array before building either response branch. Both standard-rate and company-filtered HTTP queries were rerun in the WASM preview: each returned HTTP 200 and `plans` parsed as a three-item JSON array of `{id, name, meal_plan, rate}` objects. The form uses `rate`/`seasons`; still add a native PHP 8.3/8.4 JSON contract test because production runtime behavior was not exercised here.

### Q5 — Production deployment requires operator action

The cPanel guide requires a once-per-minute Laravel scheduler cron for nightly backups, channel sync, e-invoice retries, and other automations. The example `.env` defaults to debug/local and `MAIL_MAILER=log`; the installer is intended to write production settings, and the guide instructs operators to delete `public/install.php` after setup. Verify these steps as a deployment checklist, not merely as code features.

## 6. Recommended next work, by priority

1. **Release confidence:** add a tracked test suite and native PHP/MySQL CI; add contracts for JSON APIs, migrations, backup/restore, and the highest-risk posting workflows. Re-run all current migration and route checks from the exact branch.
2. **Production readiness:** perform joint GRA E-VAT UAT; document whether statutory filing is explicitly export/manual or planned for electronic transmission; validate real payment/MoMo callbacks and SMTP/SMS delivery.
3. **Data continuity:** implement/approve an opening-balance and supplier/inventory migration plan; decide whether to import folio/payment history; provide a tested restore runbook and off-site backup destination/retention policy.
4. **Finance completeness:** extend the folio-only billing workpaper to POS receipts, company-ledger settlement allocation, and gateway/bank batch attribution after finance approves the reconciliation rules; consider cross-currency RFQ quote comparison separately if required.
5. **Operational usability:** build a real offline desk client or rename the current feature to describe its pack/sync protocol accurately; navigation exposure for the previously hidden modules is resolved.
6. **Integration roadmap:** prioritize the OTA adapters, direct review/provider connections, social channels, travel inventory, and physical device drivers needed by actual hotel deployments.
7. **Documentation synchronization:** update `START-HERE.txt`, `docs/INSTALL-CPANEL.md`, and audit provenance to the current application version/branch/package.

## 7. Scope limitations

The audit excluded third-party Composer packages under `build/vendor` and generated runtime files. It checked app-owned source and the available harnesses, not every third-party dependency. No full browser visual/accessibility sweep, penetration test, live payment or channel certification, GRA UAT, real-device exercise, or native MySQL/PHP test run was possible here. The README states the previous proprietary system is behind a login and the current PMS was built from public product information; without a signed legacy requirements catalogue, no one can truthfully certify **complete parity** with every old-system function.

**Audit outcome:** broad implemented feature set; no obvious stub actions; several material integration, cutover, finance, offline-client, recovery, test-evidence, and documentation gaps remain as listed above.