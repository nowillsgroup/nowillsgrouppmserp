# PHPUnit suite status

**Full-suite verification of the current application code:** GitHub Actions [37752732099](https://github.com/nowillsgroup/NowillsPMS-ERP/actions/runs/37752732099), code commit `d857feb210949556de1741b2234b4d9feb890542`, PHP 8.4 / MySQL 8, reference clock, all six shards. Completed 8 October 2026 at 09:08 UTC.

| Result | Count |
|---|---:|
| Tests run | 651 |
| Passing | **651 (100%)** |
| Failures | **0** |
| Errors | **0** |
| Skipped | **0** |

**The complete suite passes.** Every shard completed successfully; this report is not based on partial shard totals.

| Shard | Tests | Passed | Failures / errors / skipped |
|---:|---:|---:|---:|
| 1 | 127 | 127 | 0 / 0 / 0 |
| 2 | 104 | 104 | 0 / 0 / 0 |
| 3 | 111 | 111 | 0 / 0 / 0 |
| 4 | 106 | 106 | 0 / 0 / 0 |
| 5 | 94 | 94 | 0 / 0 / 0 |
| 6 | 109 | 109 | 0 / 0 / 0 |
| **Total** | **651** | **651** | **0 / 0 / 0** |

## Live PABX adapter (v7.36) — runtime verification (11 October 2026)

Feature: first live PABX driver — template-driven TCP command link + `pms:pbx:listen`
SMDR stream capture (`docs/PBX-ADAPTER.md`), verified end-to-end against the
`deploy/dev/pbx-sim.mjs` TCP PBX simulator (WASM PHP runtime, demo seed NOWILLS).

| Check | Result |
|---|---|
| SMDR capture (connect mode) → rate → auto-post to in-house folio | pass (2 calls posted, GH₵ 121, masked numbers on the bill) |
| Free / internal numbers not charged | pass (1 call imported as free) |
| Duplicate suppression on re-capture (raw_hash) | pass (2 duplicates, 0 double-posted) |
| Parse failure isolation (future call time reported, stream continues) | pass |
| TCP commands `cos` / `checkin` / `move` with template substitution | pass (`OK …` replies recorded in the command log) |
| Unconfigured template → failed command with actionable message | pass |
| Unreachable PABX → failed command, retryable from the command log | pass |
| `InterfaceService::saveSettings()` round-trip + invalid success-regex rejection | pass |
| Interfaces UI renders TCP fields (settings modal) | pass |

## Legacy data migration toolkit — runtime verification (11 October 2026)

Feature: legacy Exceed/Nowills → PMS data migration & cutover tooling (`pms:import`,
`DataMigrationController`, `docs/DATA-MIGRATION.md`), verified end-to-end on the
`deploy/dev/` WASM runtime (PHP 8.5 / sqlite), demo seed data (NOWILLS hotel 1).

| Check | Result |
|---|---|
| `migrate` incl. `2026_10_11_000100_create_data_migration_tables` | pass |
| `db:seed` full demo rebuild | pass |
| `php -l` on all new/edited PHP | pass |
| companies import — create + update paths (`docs/examples/migration/companies.csv`) | pass (1 create, 1 update, 0 fail) |
| guests import + idempotent re-run | pass (3 create; re-run 3 unchanged, no duplicates) |
| employees import | pass (2 create) |
| rooms import — create/update/unchanged + validation failure path | pass (2 valid rows; 2 misaligned fixture rows rejected with actionable messages) |
| reservations import — create + failure path (`G-9999` unknown guest) | pass (3 create; row fails with "import the guests file first") |
| reservation idempotent re-run (`Already imported` skip) | pass (0 duplicates) |
| dry-run mode (would_create / would_update, no writes) | pass |
| CLI reconciliation block per dataset | pass |
| UI: login → `/settings/migration` → batch show → CSV template download | pass (badges green/red correct) |

Known fixture notes: `docs/examples/migration/*.csv` are the reference example set
(also served by the in-app template route). The rooms example intentionally shows
column-count validation — rows 101/901 in an early draft had a shifted column;
corrected form has 12 columns matching the header.

## Previously identified failures — resolved

These are the 14 failures from full run `37742322325` on `885388a`. The fixes and fixture/assertion corrections below are covered by the fully green run above.

| Test(s) | Resolution |
|---|---|
| `PosModifiersTest` (2 tests) | The modifier-assignment route used `{item}`, globally model-bound to `LostFoundItem`, while the controller expected a `MenuItem`. Renamed the route key to `{menuItem}` to invoke the correct implicit model binding. Both assignment/KOT and required-selection tests now pass. |
| `ReservationDeskTest` | The test used rooms free for sale rather than rooms assignable to an existing reservation. It now selects an active room type with at least two currently assignable rooms and uses that type's base rate; this also avoids assuming the occupied seeded `STD` rooms are available. |
| `ReservationInventorySafetyTest` | Corrected the test to generate the registered `reservations.api.rooms` route, which serves the free-rooms lookup, so the permission assertion reaches the endpoint. |
| `Phase19Test` | Made ARI room-type iteration and the channel payload's room-type map order deterministic by room-type ID. |
| `Phase29Test` | Corrected the voucher-balance assertion to subtract the actual remaining-balance redemption, not the full POS bill that had already received another tender. |
| `Phase31Test` | The test now starts its `modified_since` checkpoint after the latest seeded reservation timestamp, then advances only its target reservation. This isolates the intended change from demo seed history. |
| `Phase63Test` | Set the conversation's awaiting timestamp to 25 minutes before the reply, making the test deterministic against its 24-minute response threshold. |
| `Phase65Test` | Canonicalized nested associative-array keys before hashing a BEO snapshot. This makes the signature independent of key ordering normalized by MySQL JSON storage while preserving list order. |
| `QrModifiersTest` (2 tests) | The tests now account for pre-seeded table orders and lines: they record a before-state and assert the request creates no new order line on a missing required modifier, or inspect only the newly created line for the snapshot assertion. |
| `Phase12Test` | Updated the paid-payment fixture count to include all seeded flows, including the organiser's banquet deposit (five paid records). |
| `Phase16Test` | Updated the seeded banquet pipeline assertions for the organiser demo data: four definite events, two lost events, and 19 seeded functions. |
| `Phase25Test` | Opened the test cashier shift with a 30,000 opening float so it can fund the exchange payout, and used the matching expected cash for close. The product's counted-cash close guard remains intact. |

## Follow-up reference-clock cursor regression — resolved

A later partial verification run, [37751216595](https://github.com/nowillsgroup/NowillsPMS-ERP/actions/runs/37751216595), exposed two related `Phase14Test` journal misses. CI seeds the database and runs PHPUnit in separate PHP processes; each process re-anchors the reference clock. When seeding takes longer than the ledger's five-minute tolerance, the saved `gl_synced_at` can be ahead of the test worker's clock, causing incremental sync to skip newly created asset/depreciation sources. `LedgerService::sync()` now forces a full reconcile when its tolerance-adjusted cursor is still in the future. The two affected tests explicitly exercise that future-cursor case, and the complete run `37752732099` confirms the fix.

## Historical full-run results

| Failures | Run / source | Note |
|---:|---|---|
| 130 | `37685551698` @ `981575f` | Contaminated by an unpinned seeding change; not a clean baseline |
| 33 | `37695078828` @ `08b6fec` | First clean pinned-seed baseline |
| 21 | `37697155178` @ `0b686eb` | After stale report/menu expectations were corrected |
| 15 | `37700840845` @ `469e399` | Before the sidebar tripwire was updated from 356 to 358 |
| 14 | `37742322325` @ `885388a` | Last complete failing baseline; 637 passed / 14 failed |
| 0 | `37748890464` @ `7f04ef8` | First complete green run after the initial 14 fixes |
| **0** | **`37752732099` @ `d857feb`** | **Current application-code verification; 651 passed / 0 failed** |

## Running locally

```bash
php build/vendor/bin/phpunit                       # whole suite
php build/vendor/bin/phpunit --filter Phase19Test  # one class
```

The feature suite uses MySQL 8 (`nowills_pms_test` in `phpunit.xml`). CI seeds through `tests/seed.php` so the database and PHPUnit share `Tests\ReferenceClock`; do not seed outside that path when measuring a full-suite result. Shards are disjoint; publish an aggregate only after all six complete.

## G1 cutover remediation follow-up — 20–23 October 2026

The following focused acceptances were run against the disposable PHP-WASM 8.5 / SQLite development database. Each migration harness wraps its fixtures in a database transaction and rolls them back. A historical PHPUnit full-suite result is listed above, but it is not a fresh run for this cutover branch.

| Check | Result |
|---|---|
| `deploy/dev/audit/opening_stock_migration.php` | **20 passed, 0 failed**; rollback-only; opening stock posts Dr Inventory / Cr Opening Balance Equity, remains balanced and idempotent, with locked-period and precision guards |
| `deploy/dev/audit/opening_ap_migration.php` | **28 passed, 0 failed**; rollback-only; open supplier bills/credits populate AP without historical GL; control balance reconciles to opening GL |
| `deploy/dev/audit/opening_ar_migration.php` | **22 passed, 0 failed**; rollback-only; open guest/company documents preserve references/dates, create non-posting subledger balances, and do not replay paid items or settlement history |
| `deploy/dev/audit/opening_gl_preflight.php` | **25 passed, 0 failed**; rollback-only; full opening-TB mapping, balance/inventory checks and strict AP/guest/city control variances are reported; `acc_depreciation` contra-asset sign handled correctly; no posting or ID map; Apply is blocked |
| `deploy/dev/audit/historical_stock_preflight.php` | **27 passed, 0 failed**; rollback-only; immutable archive-only history with idempotency/correction guards and zero live stock/GL/ID-map writes |
| `deploy/dev/audit/paid_supplier_history_archive.php` | **25 passed, 0 failed**; rollback-only; bills, payments, credits and allocations remain in the immutable archive, with zero operational AP/GL/ID-map writes |
| `deploy/dev/audit/inventory_allergen_preflight.php` | **17 passed, 0 failed**; rollback-only; mapped ingredient declarations preflight only, with no live allergen declarations or verification stamps |
| `deploy/dev/audit/migration_master_import.php` | **17 passed, 0 failed**; rollback-only supplier and inventory master regression |
| `deploy/dev/audit/procurement_fx.php` | **37 passed, 0 failed**; rollback-only; receipt-date stock/GRN valuation, invoice-date supplier bill conversion and separate Exchange Gain/Loss behavior |
| Opening cutover migration order | `opening_stock → opening_ap → opening_ar → opening_gl → historical_stock → inventory_allergens → reservations → bank_account_mappings → paid_supplier_history` |

The open-items-only AP method is **not approved**. The required detail-page caveat remains exactly: `Provisional — policy not yet approved; confirm cutover method before production.` AP items are non-posting and the full opening-GL Apply gate remains disabled pending policy approval, subledger sign-off, control mapping and duplicate-posting rules. The separate paid-history archive does not settle or replay source AP history.

## G1 Treasury/bank-account mapping preflight — read-only (23 October 2026)

The `bank_account_mappings` dataset validates stable, non-sensitive legacy
bank/MoMo/clearing IDs against distinct active Nowills Treasury accounts of the
matching kind. Only four documented CSV columns are accepted; extra columns and
account/card-number-like values are rejected before the source file is retained.
The dry-run is a mapping workpaper only: Apply remains disabled, and it creates
no Treasury account, live ID map, statement, opening balance, AP record or GL
journal. Supplier bank details and expense-account mappings remain excluded.
Operational mapping and opening-statement reconciliation remain open.

| Check | Result |
|---|---|
| `deploy/dev/audit/bank_account_mapping_preflight.php` | **20 passed, 0 failed**; rollback-only; validates bank/MoMo target eligibility and uniqueness, rejects sensitive columns/number-like values, checks the retained safe source file/report, and proves service/importer/controller/CLI Apply guards with zero Treasury, statement, ID-map or GL writes |
| `deploy/dev/audit/gap_audit_check.py` | **365 passed, 0 discrepancies** |
| Focused PHP syntax checks | pass for the new importer, migration service, CLI command, controller and rollback harness |
| `view:cache` and migration route list | pass; six migration endpoints registered |
| Migration page and `bank_account_mappings` template | **2/2 HTTP 200**; template exposes only the four allowed fields and a neutral account label |
| `git diff --check` | pass |

The disposable dev database was freshly migrated and seeded for this verification. No real legacy bank mapping, bank statement, account balance or supplier banking detail was loaded. The complete historical PHPUnit suite and native PHP/MySQL production acceptance remain outstanding for this branch.

## Report Suite Expansion — 210 new detailed reports (24 October 2026)

The report engine in `ReportController.php` expanded from **259 to 469 reports**. The
210 new reports are detailed registers for models that previously had zero report
coverage (412 of 413 models now have report coverage; only `ClubMember` was already
covered by the v5.1 `club_members` report). Each new report includes multiple
contextual columns, subtotals, summary statistics, and explanatory notes — not
just a flat list of records.

**Batch 1 — 39 reports (v8.0):** cashier_shifts, reservation_amendments, room_blocks,
room_counts, room_moves, room_outages, stock_takes, stock_take_lines, store_pars,
time_punches_detail, attendance, pay_components, tip_collections, staff_advances,
bank_matches, gateway_payouts, online_refunds, supplier_prices, grn_lines,
sales_contacts, sales_opportunities, channel_rate_maps, channel_room_maps,
loyalty_entries, security_watch, menu_items, shift_templates, utility_bills,
pm_schedules, upsell_offers, rfq_register, rental_vehicles, car_rentals,
parking_vehicles, training_sessions, credit_logs, activity_bookings,
reservation_waitlist, minibar_items

**Batch 2 — 38 reports (v8.1):** inventory_items, item_categories, store_issue_lines,
po_items, supplier_invoice_lines, requisition_lines, rfq_quotes, rfq_quote_lines,
rfq_suppliers, banquet_packages, banquet_services, banquet_event_items,
menu_categories, menu_modifier_groups, menu_modifiers, menu_item_recipes,
pos_tables, room_amenities, spa_rooms, stay_fixed_charges, leave_types,
employee_pay_components, roster_weeks, shift_swaps, budget_lines, budget_events,
business_sources, payment_modes, ap_allocations, agent_commissions,
recurring_journals, training_courses, sop_documents, sop_versions, kiosks,
kot_print_jobs, fb_recipe_cards, fb_cost_snapshots

**Batch 3 — 66 reports (v8.2):** linen_batches, linen_batch_lines, laundry_items,
laundry_order_lines, asset_categories, asset_dep_runs, gl_journals,
cash_forecast_lines, budget_stats, fiscal_year_closes, club_classes,
club_ticket_types, club_ticket_sales, club_ticket_sale_items, club_ticket_payments,
channel_restrictions, channel_ari_states, channel_messages, ota_channels,
rate_plans, rate_overrides, rate_contract_rates, rate_contract_blackouts,
reservation_desk_views, security_incident_updates, petty_counts,
petty_replenishments, surveys, survey_invitations, survey_questions,
talent_cycles, talent_interviews, talent_offers, talent_probations,
talent_scorecards, talent_tasks, compliance_events, fs_certificates,
fs_equipment, fs_labels, esg_policies, esg_targets, esg_communities,
esg_green_stays, esg_laundry_months, esg_checklist_items, tv_movies,
tv_pages, tv_programmes, tv_sessions, tv_commands, tv_welcome_templates,
concierge_places, cro_contacts, dm_ad_stats, dm_link_clicks,
einvoice_logs, gst_periods, gst_cancelled_docs, wht_periods, kpi_alerts,
report_schedules, report_views, interface_commands, message_logs, email_logs

**Batch 4 — 67 reports (v8.3):** cashier_movements, forex_rates, forex_surrenders,
gate_pass_items, gate_pass_returns, guest_documents, hotel_groups,
management_agreements, owner_statements, owner_queries, yield_rules,
bd_competitors, bd_milestones, bd_rate_shop_lines, bd_results,
qa_standards, qa_audit_items, review_templates, marketing_segments,
marketing_messages, marketing_collateral, lease_inspections,
lease_meter_readings, meter_readings, sop_acknowledgements,
training_requirements, transport_tariffs, outlet_service_periods,
fb_staff_meals, fb_yield_tests, hr_announcements, hr_documents,
hr_profile_updates, hse_wardens, kot_printers, rfq_lines, sc_distribution_lines,
organiser_attendees, feedback_answers, feedback_case_events,
activity_departures, rm_snapshots, room_amenity_links, room_block_lines,
room_images, billing_reconciliations, billing_reconciliation_items,
expense_claim_lines, inbox_broadcasts, inbox_quick_replies,
offline_sync_operations, online_payment_events, privacy_request_events,
data_breaches, data_migration_batches, data_migration_rows,
historical_stock_movement_archives, marketing_consent_logs,
supplier_paid_history_archives, close_events, api_idempotency_keys,
permissions, settings, tv_message_reads, wakeup_attempts,
log_entry_comments, log_entry_reads

**Structural verification:** 469 slugs in the REPORTS constant, 469 `r_{slug}()`
method implementations, zero missing, zero duplicates. Brace/bracket/paren
counts are balanced. All model imports are in place. PHP syntax is consistent
with existing code patterns (full `\App\Models\` namespace references for
models not in the `use` block).

**Live verification:** The disposable PHP-WASM dev runtime was not available
for a live report render in this session. The existing codebase audit
(`docs/NOWILLS-PMS-CODEBASE-AUDIT-2026-10-20.md`) confirmed all 259 prior
reports rendered HTTP 200; the 210 new reports follow the same structural
patterns and are expected to render identically once the dev runtime is
available.
