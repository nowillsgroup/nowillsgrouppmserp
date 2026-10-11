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
