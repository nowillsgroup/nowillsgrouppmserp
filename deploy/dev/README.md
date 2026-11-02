# Nowills PMS — development runtime

A self-contained development runtime for this repository: **no system PHP,
MySQL or Docker required** — only Node/npm (to fetch two packages) once.

## How it works

- **Bun** (JSI-capable JS runtime; binary ships inside its npm tarball) executes
  **PHP 8.5.11 compiled to WebAssembly** (`@kirigami/php-wasm`).
- The repository is mounted read/write into the PHP filesystem at `/app`
  (NODEFS), so scripts run against the real working tree.
- PHP extensions come from the WASM build plus `@kirigami/phpext-*` side
  modules (`pdo_mysql`, `fileinfo`, `intl`, `gmp`, `soap`, `sodium`, `posix`).
- `lib.mjs` polyfills `SessionHandlerInterface` (ext-session is not in the
  WASM build; Laravel implements its own sessions and only needs the contract).
- The dev database is **SQLite** (`database/database.sqlite`), which is also
  the application's default when `DB_CONNECTION` is unset.

## Tools

| Command | Purpose |
|---|---|
| `bin/php <args>` | PHP CLI shim — `php artisan …`, `php -r '…'`, `php -f file.php`, `php -l file.php`, `php -m`, `php -v` |
| `bin/pms-serve` | HTTP dev server on `0.0.0.0:$PORT` (default 8000) — static files from `public/`, everything else through `public/index.php` |
| `setup.sh` | (Re)installs npm packages and the Bun binary |
| `python3 deploy/dev/audit/channel_manager_polish_check.py` | Checks the Channel Manager dashboards, connection setup and mapping, simulator guardrails, inbox permissions, resolution actions, sync history, rate parity and iCal feed behavior |
| `python3 deploy/dev/audit/channel_manager_http_check.py --base http://127.0.0.1:8000` | With the dev server running, smoke-tests the Channel Manager overview, external and sandbox connections, create form, OTA inbox/message dialog, sync log, rate parity and iCal feeds |
| `python3 deploy/dev/audit/main_dashboard_polish_check.py` | Checks main-dashboard workflow, metric semantics, chart accessibility, overflow cues, permission gates, dark-theme WCAG AA text contrast and chart-bar contrast |
| `python3 deploy/dev/audit/main_dashboard_links_http_check.py --base http://127.0.0.1:8000` | With the dev server running, checks unique page IDs, matching historical chart samples, and the Reservation Desk, Arrivals and Walk-in shortcut destinations |
| `deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/main_dashboard_permission_http_check.php';"` | Exercises the real Dashboard route as Housekeeping Supervisor, Restaurant Cashier and Security Officer; checks finance and reservation aggregates stay hidden while occupancy remains visible |
| `deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/main_dashboard_role_matrix_check.php';"` | Renders the Dashboard for every active demo user with dashboard.view and checks finance/reservation visibility, arrival progress, room-status explanations, permission-filtered Action Centre copy, Department snapshots visibility, chart data and skipped finance queries against actual permissions |
| `deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/main_dashboard_finance_aggregate_check.php';"` | Verifies SQL-aggregated dashboard revenue and collection figures match the row-by-row calculations across every development-ledger business date |
| `python3 deploy/dev/audit/reservation_detail_modal_accessibility_check.py` | Checks reservation-detail dialog names, close controls, opener relationships, room-assignment and dynamic split-advance labels, and log-permission gating for SMS recipient/error history |
| `python3 deploy/dev/audit/reservation_detail_accessibility_check.py` | Checks reservation-detail tables, full-year machine-readable dates, accessible icon actions, robust online check-in link copying and safe new-tab links |
| `python3 deploy/dev/audit/reservation_confirmation_dropdown_check.py` | Checks the accessible confirmation disclosure, print and direct PDF download actions, PDF response privacy headers, and permission-gated per-stay registration forms |
| `python3 deploy/dev/audit/reservation_print_confirmation_check.py` | Checks the browser-print and downloadable-PDF confirmations agree on status-specific copy, room/rate totals, payments/refunds and property policy, plus A4/page-break layout rules |
| `deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/reservation_print_confirmation_render_check.php';"` | Renders print/PDF confirmations for confirmed, tentative, cancelled, no-show and checked-out reservations; checks pre-arrival messaging and cross-format room totals, tax components and tax-inclusive estimate without database writes |
| `deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/reservation_confirmation_pdf_render_check.php';"` | Validates PDF structure, status and policy content, long property contacts, cross-reference offsets, and multi-page room-table rendering without database writes |
| `python3 deploy/dev/audit/reservation_activity_log_check.py` | Checks reservation Activity Log scope, linked-stay/payment permission handling, summarized guest communication outcomes, privacy-safe fields and machine-readable timestamps |
| `python3 deploy/dev/audit/frontdesk_arrivals_polish_check.py` | Checks the arrival queue’s dated/overdue filters, permission-gated check-in action, guest manifest, date semantics and responsive/dark/print styles |
| `python3 deploy/dev/audit/frontdesk_departures_polish_check.py` | Checks due-out scope and date handling, check-out actions, folio-aware summary, room turnover context, empty state and responsive/dark/print styles |
| `python3 deploy/dev/audit/frontdesk_rooms_polish_check.py` | Checks Room View inventory counts, derived due-out/arrival states, distinct status filters, accessible room action menus and permission-gated room/folio/housekeeping actions |
| `deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/frontdesk_rooms_permission_render_check.php';"` | Renders Room View for each active Front Desk role and verifies reservation, folio, check-in, room-move, check-out and housekeeping actions follow permissions |
| `python3 deploy/dev/audit/frontdesk_inhouse_polish_check.py` | Checks the live checked-in roster, business-date departure status, guest/room context, permission-gated move/folio/checkout actions, empty state and responsive/dark/print styles |
| `deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/frontdesk_inhouse_render_check.php';"` | Renders the empty in-house roster in memory without creating or changing stay records |
| `deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/frontdesk_departure_permission_render_check.php';"` | Renders Departures and In-house for each active Front Desk role and verifies folio, room-move and check-out visibility follows permissions |
| `python3 deploy/dev/audit/frontdesk_walkin_polish_check.py` | Checks the Walk-in registration flow, custom guest title, Company/City Ledger modal, JSON submission and validation-error handling |
| `TINKER='require "/app/deploy/dev/audit/frontdesk_walkin_permission_render_check.php";' deploy/dev/bin/php -f deploy/dev/tinker.php` | Renders Walk-in for each active demo role and verifies account-creation controls are permission-gated |
| `TINKER='require "/app/deploy/dev/audit/frontdesk_walkin_company_create_check.php";' deploy/dev/bin/php -f deploy/dev/tinker.php` | Verifies check-in-only users are denied, then creates a temporary City Ledger account inside a transaction and checks terms, duplicate rejection and Finance visibility; all writes roll back |
| `python3 deploy/dev/audit/reservation_dashboard_polish_check.py` | Checks Reservation Desk dashboard, Timeline and Room Board content, permissions, operational states, accessible interactions and WCAG AA contrast |
| `python3 deploy/dev/audit/reservation_room_page_polish_check.py` | Checks the full-shell room profile, scoped operational data, stay and outage date semantics, permission-gated actions, gallery and block-preview interactions, responsive/dark/print styles, and WCAG AA status contrast |
| `deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/reservation_room_page_render_check.php';"` | Renders both empty and populated upcoming-stay states for the actual room profile in memory without writing reservation records |
| `deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/reservation_room_page_permission_render_check.php';"` | Verifies a reservations-only CRO neither sees nor loads the current stay’s folio, while an authorized admin sees the folio summary |
| `python3 deploy/dev/audit/reservation_room_board_http_check.py --base http://127.0.0.1:8000` | With the dev server running, smoke-tests the configured preview home, Board date context, same-origin asset URLs, all grouping modes, filtered empty state, local room photos, sample room/guest/reservation links, the room-specific timeline shortcut, and the linked room profile’s operational sections |
| `python3 deploy/dev/audit/reservation_list_polish_check.py` | Checks the reservation register’s filters, search, facets, table semantics, responsive styles, and related drill-downs |
| `python3 deploy/dev/audit/reservation_arrivals_polish_check.py` | Checks the Reservation Desk arrival queue’s date-aware summaries, due-in/walk-in/unassigned/guest-details/live past-ETA facets, ETA ordering, direct permission-gated room assignment, contact search privacy, guest/room/preparation manifest, permission-gated contact and folio data, empty state, and responsive/dark/print presentation |
| `python3 deploy/dev/audit/reservation_departures_polish_check.py` | Checks the Reservation Desk departure hero, date-aware departure totals, status facets, search/navigation, checkout manifest and read-only hold/estimate details, permission-gated folio/contact/housekeeping/checkout actions, empty state, and responsive/dark/print styles |
| `python3 deploy/dev/audit/reservation_inhouse_polish_check.py` | Checks the Reservation Desk live in-house roster, business-date due-outs and overdue stays, search and status facets, guest/stay/room manifest, permission-gated folio/contact/housekeeping/checkout/room-move details, empty state, and responsive/dark/print styles |
| `python3 deploy/dev/audit/reservation_unassigned_polish_check.py` | Checks the Unassigned desk-date roster, arrival/group facets, guest and booking search with permission-gated phone data, requested room details, empty state, permission-gated actions to the availability-checked stay assignment flow, and responsive/dark/print treatment without folio data |
| `python3 deploy/dev/audit/reservation_waitlist_polish_check.py` | Checks the live Reservation Desk waitlist, status and arrival-window facets, prioritized guest-request manifest, permission-gated contact details, validated creation form, empty state, and existing availability-checked conversion/cancellation routes with responsive/dark/print styling |
| `python3 deploy/dev/audit/reservation_groups_polish_check.py` | Checks the group reservations desk window, status/block facets, group and room-stay summaries, permission-gated lead contact, room-block pickup preview/confirmation, empty state, and responsive/dark/print treatment without direct room assignment |
| `python3 deploy/dev/audit/reservation_noshow_polish_check.py` | Checks the full-shell no-show register's arrival-window semantics, summary-before-filter behavior, permission-gated contact search/display, group and individual facets, read-only links to existing booking history, empty state, and responsive/dark/print styling |
| `TINKER='require "/app/deploy/dev/audit/reservation_noshow_render_check.php";' deploy/dev/bin/php -f deploy/dev/tinker.php` | Renders a populated no-show register using a temporary status change inside a transaction, then rolls back all writes |
| `python3 deploy/dev/audit/reservation_cancelled_polish_check.py` | Checks the full-shell cancellation register's recorded timestamp window, summary-before-filter behavior, permission-gated contact search/display, group and individual facets, read-only booking-history links, empty state, and responsive/dark/print styling |
| `TINKER='require "/app/deploy/dev/audit/reservation_cancelled_render_check.php";' deploy/dev/bin/php -f deploy/dev/tinker.php` | Renders a populated cancellation register using a temporary timestamp/status change inside a transaction, then rolls back all writes |
| `python3 deploy/dev/audit/reservation_dashboard_links_http_check.py --base http://127.0.0.1:8000` | With the dev server running, follows every rendered Reservation Desk KPI link and checks it returns HTTP 200 |
| `deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/reservation_desk_date_count_check.php';"` | Verifies desk-date dashboard KPIs and drill-down counts against source data and confirms Room Board includes assigned departures on the selected date |
| `deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/reservation_room_board_outage_render_check.php';"` | Renders a same-day due-out alongside a continuing stay and active/upcoming room blocks in start-date order, checking that the exclusive block end is labeled as the room-return date; uses in-memory data and writes no records |
| `python3 deploy/dev/audit/folio_pages_polish_check.py` | Checks Folio detail, register, invoice-list and printed-invoice accessibility; the A4 invoice layout with property website contact details, branded buyer/stay panels (including reservation-level rooms and rate plans), per-transaction running folio balances and per-business-day debit/credit subtotals with net movement, adaptive ledger/tax tables, amount-in-words, the issuing staff member and settlement summaries; sanitized invoice filters, linked buyer context, transaction receipt links, preaggregated balances, responsive transaction summaries, full-year machine-readable dates/times including receipt business dates, permission-gated actions and forms, safe refund entry, split-tender controls, and detail-ledger query reuse |
| `python3 deploy/dev/audit/cashier_cash_balance_check.py` | Checks expected-cash formula and its payment/refund, drawer-movement, legacy-settlement and forex sources, plus live balances for open manager-visible shifts and closed shift snapshots |
| `node deploy/dev/audit/cashier_shift_denomination_behavior_check.js` | Exercises denomination counting to verify the expected cash count updates live and clears stale totals when all denominations are reset to zero |
| `deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/cashier_pos_payment_detail_check.php';"` | Renders a temporary POS payment/refund pair in the cashier shift report and rolls back the ledger test rows |
| `python3 deploy/dev/audit/pos_bill_receipt_check.py` | Checks the POS bill's ITEMS / QTY / RATE / AMOUNT table, plain bill/order/date/service details, dynamic payment receipt totals and cash tender/change arithmetic, Waiter Name in bill metadata, Cashier immediately before the Guest Details section, and aligned Guest Name / Room Number / Signature fields with printable underscore blanks, and placement before GRA E-VAT, tax rounding, accessible regions, sans-serif bill typography, monospaced KOT preservation, and print pagination rules |
| `python3 deploy/dev/audit/pos_kot_layout_check.py` | Checks the clearer 80 mm KOT slip layout, preserved compact 58 mm variant, separately arranged KOT number/date/time/table/order metadata, kitchen item details, and thermal page breaks |
| `python3 deploy/dev/audit/pos_orders_bills_polish_check.py` | Checks the actual Orders & Bills register's accessible date/search/outlet/status filters, unchanged query contract, semantic full-year table dates, guest/source and payment-mode context, unsettled balance visibility without false room-post/complimentary dues, named safe print actions, active-nav semantics, a resettable empty state, full-filter summary aggregates with 50-row pagination, and responsive table behavior |
| `python3 deploy/dev/audit/pos_live_orders_waiter_check.py` | Checks the waiter-scoped POS Live Orders feed, automatic waiter assignment and safe claiming of unassigned checks, outlet and All live filters, polling, kitchen-ready notifications and visual Ready tab, plus ready-item served actions |
| `python3 deploy/dev/audit/pos_unsettled_invoices_check.py` | Checks the cashier-only all-dates POS unsettled worklist, excludes labeled Live Orders preview fixtures from invoice summaries, verifies per-waiter due summaries, filters, idle auto-refresh safeguards, direct bill generation and the Take payment modal, pending-item safeguards, return-to-list behavior and relative URLs |
| `deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/pos_live_orders_claim_check.php';"` | Claims a real unassigned local POS check, verifies it appears in the staff member’s My orders feed, then rolls back and confirms the check remains unassigned |
| `deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/pos_live_orders_waiter_scope_check.php';"` | Uses the real Restaurant Waiter permission role and current live orders to verify My orders is the default, short waiter-name assignments are recognized, and ready items can be served; writes no records |
| `python3 deploy/dev/audit/pos_complimentary_details_check.py` | Checks whole-bill Complimentary / NC captures Department, Manager, Receiver and a City Ledger/Company or Guest Folio destination, creates only zero-value subledger records, clears unused details, and prints the record on the bill |
| `python3 deploy/dev/audit/pos_open_item_pax_check.py` | Checks the POS open-item form keeps billing Quantity separate from a read-only Covers / Pax reference and supports a direct modal preview |
| `node deploy/dev/audit/pos_open_item_pax_behavior_check.js` | Exercises open-item billing quantity independently of informational order covers/pax |
| `python3 deploy/dev/audit/pos_payment_tabs_check.py` | Checks the POS settlement and split-allocation Cash/MoMo/Card color tabs, accessibility, live payment-mode mapping, MoMo field synchronization, and continued availability of other configured modes |
| `python3 deploy/dev/audit/pos_settle_print_check.py` | Checks the permission-gated Settle & Print action, autoprint redirect after full settlement, print-layout handoff, and no misleading print on partial payment |
| `node deploy/dev/audit/pos_payment_tabs_behavior_check.js` | Exercises single-payment and split-allocation tab selection and confirms Cash, MoMo, Card and other live methods remain correctly selectable |
| `python3 deploy/dev/audit/kitchen_display_polish_check.py` | Checks the actual Kitchen Display's plain-JSON live feed/print jobs, live connection, new-ticket announcement/highlight and action feedback, persistent filters, accessible/keyboard-operable ticket actions, correct fullscreen synchronization, hidden-tab polling pause, mixed-ticket progress without regressing ready lines, preserved KDS routes/status flow, and responsive ticket lanes |
| `python3 deploy/dev/audit/menu_items_polish_check.py` | Checks the tenant-aware Menu Items catalog search/facets, availability summaries, live category/outlet/KOT/pricing context, responsive/accessibility behavior, grouped add/edit forms with repeatable side-dish variants (name, rate and cost), live photo and tax-aware price previews, inline POS-availability toggle, multi-select POS side dishes with additive rates, and preserved create/edit/delete/photo workflows |
| `node deploy/dev/audit/menu_item_form_behavior_check.js` | Exercises repeatable side-dish rows and their 30-row cap, inclusive/exclusive tax math and live recalculation, plus valid-photo preview, type/size/empty-file rejection, and preservation of an existing photo |
| `TINKER='require "/app/deploy/dev/audit/menu_item_variants_persistence_check.php";' deploy/dev/bin/php -f deploy/dev/tinker.php` | Creates two side-dish variants, replaces them on edit, verifies rates and costs, validates POS snapshots and item ownership, removes all variants, and rolls back every test write |
| `node deploy/dev/audit/pos_menu_variants_behavior_check.js` | Exercises the actual POS multi-select picker, additive side-dish rates, quantity grouping for identical selections, and separate lines for different selections |
| `TINKER='require "/app/deploy/dev/audit/menu_item_variant_render_smoke_check.php";' deploy/dev/bin/php -f deploy/dev/tinker.php` | Renders the real POS bill and KOT with a temporary side-dish snapshot, verifies labels alongside existing options, and rolls back the write |
| `python3 deploy/dev/audit/payment_request_modal_accessibility_check.py` | Checks the shared payment-request dialog, associated fields, grouped send options and reservation/folio openers |
| `python3 deploy/dev/audit/payment_page_polish_check.py` | Checks the public guest payment page's processed-vs-pending refund messaging, receipt-vs-request heading and amount label, semantic payment summary, machine-readable full-year dates, keyboard-accessible test payment-method radios, hosted-card security boundary and private/no-index response headers |
| `python3 deploy/dev/audit/payment_cheque_visibility_check.py` | Checks single and split payment, refund, advance, checkout and POS forms hide cheque inputs for non-cheque methods, and require/enable them when Cheque is selected |
| `python3 deploy/dev/audit/payment_momo_fields_check.py` | Checks Mobile Money methods reveal the Ghana network dropdown and MoMo number before the transaction reference across single, split, folio, reservation, front-desk and POS tenders |
| `node deploy/dev/audit/payment_momo_behavior_check.js` | Exercises both single-tender implementations through Cash → Mobile Money → Cash and checks hidden, disabled, required, and before-reference placement behavior |
| `deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/payment_page_status_render_check.php';"` | Renders processed and pending full-/partial-refund guest page variants in memory without changing payment records |
| `python3 deploy/dev/audit/online_payments_register_check.py` | Checks the staff payment register's sanitized filters, pending counts, accessible search/tabs/table and machine-readable full-year timestamps |
| `python3 deploy/dev/audit/online_payment_detail_check.py` | Checks payment-detail action labels, refund history table semantics, announced copy feedback and machine-readable dates |
| `deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/folio_index_balance_check.php';"` | Compares the Folio register's SQL-preaggregated balances with the canonical ledger calculation for 100 folios |
| `deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/folio_transaction_summary_check.php';"` | Compares filtered transaction-register SQL summaries with amounts displayed on the matching rows across recent business dates |
| `deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/folio_show_permission_check.php';"` | Renders an open folio for every active demo role with folio.view and verifies posting modals follow their permissions and stay-state requirements |
| `deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/folio_buyer_snapshot_context_check.php';"` | Verifies E-VAT buyer snapshots resolve a guest/company linked through a stay when the Folio has no direct guest/company links |
| `deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/folio_invoice_payment_summary_check.php';"` | Renders an issued folio invoice and checks invoice value, payment/refund totals, transaction references, settled balance and due/credit/zero-balance states without database writes |

Run the tools from anywhere; they `cd` here first so phpext side-module
discovery finds `node_modules`.

## First-time setup

```bash
deploy/dev/setup.sh

cp .env.example .env
# in .env set:
#   DB_CONNECTION=sqlite
#   DB_DATABASE=/app/database/database.sqlite   <- the *PHP-visible* path
touch database/database.sqlite
deploy/dev/bin/php artisan key:generate
deploy/dev/bin/php artisan migrate --force
deploy/dev/bin/php artisan db:seed --force
deploy/dev/bin/pms-serve
```

The full demo seeder is safe to run at month-end: Business Development launch and live dates use no-overflow month arithmetic so historical results cannot predate an initiative's live month.

`proc_open`/`exec`/`popen` run real host processes via `createSpawnHandler`
(`/app/...` paths translate to the repo root), so terminal probes and CLI
helpers work instead of crashing. For quick app queries:

```sh
TINKER='$u = App\Models\User::first(); echo $u->username;' deploy/dev/bin/php -f deploy/dev/tinker.php
```

Demo logins (from `database/seeders/DatabaseSeeder.php`):

| Hotel ID | Username | Password | Notes |
|---|---|---|---|
| `NOWILLS` | `admin` | `Admin@123` | Nowills Grand Hotel, super admin |
| `NOWILLS` | `groupadmin` | `Admin@123` | Group admin (can switch properties) |
| `NWRESORT` | `admin` | `Admin@123` | Nowills Lake Resort |

## Known quirks

- PHP runs as the `wasm` SAPI; the shim strips entry-script shebangs from output.
- `ext-session` is polyfilled (interfaces only) in `lib.mjs`'s auto-prepend.
- `register_argc_argv` is forced off: the wasm SAPI otherwise sets
  `$_SERVER['argv']` to a string and Symfony's `ArgvInput` fatals during boot.
- The WASM PHP is slower than native PHP; large report pages take a few seconds.
- `proc_open`/`shell_exec` exist but have no real OS behind them — anything
  shelling out to system binaries will not work.
