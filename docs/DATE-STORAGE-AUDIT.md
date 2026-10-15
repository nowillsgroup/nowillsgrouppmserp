# Date storage and comparison audit — v7.44

**Scope:** every query in `app/` that compares a `DATE` column, and every upsert keyed on one.
**Result:** 318 comparison sites and 11 upserts were silently dropping rows — including whole days of
money — and one of them made the Duty Roster unsavable with an HTTP 500. All are fixed and verified.
**Evidence scripts:** `deploy/dev/audit/date_shape_survey.php`, `date_equality_probe.php`,
`date_controls_compare.php`, `roster_save_flow.py`, `roster_counts.php`, `full_get_sweep.py`,
`report_sweep.py`.

**This is the completion of [`DATE-QUERIES.md`](DATE-QUERIES.md)**, which states the rule
(`whereDate()`, always), documents five earlier conversion passes and warns about the `orWhere` →
AND trap. Those passes selected columns by the **name pattern `*_date`**, so they never reached a
column called simply `date`, nor `expires_on`, `valid_to`, `valid_from`, `period_start`, `month`,
`night`, `week_start`, `carried_from` or `due_on`. Of the sites converted here, **91 per cent are on
columns whose name does not end in `_date`**, and a column named just `date` accounts for 170 of
them on its own. Two further classes were not in scope of any earlier pass at all: comparisons nested
inside `where(fn ($q) => …)` closures, and **upserts keyed on a date column**, which cannot use
`whereDate()` and need a different fix (§3.6). This pass selects columns from the live schema instead
of from their names.

---

## 1. The defect in one paragraph

SQLite is dynamically typed: a column declared `date` stores exactly the bytes the writer handed it,
and comparisons are plain string comparisons. Laravel writes a `date`-cast attribute through
`fromDateTime()`, which formats with the connection date format `Y-m-d H:i:s`, so a model save stores
`2026-10-05 00:00:00`. A query bound built from `toDateString()` is `2026-10-05`. Those two strings
never compare equal, and `"2026-10-05 00:00:00" <= "2026-10-05"` is **false** because the longer
string sorts after the shorter one. Every date comparison in the application was therefore at the
mercy of which of the two shapes each side happened to use.

| comparison against a date-only bound | stored `Y-m-d` | stored `Y-m-d 00:00:00` | verdict |
| --- | --- | --- | --- |
| `where('d', '=', '2026-10-05')` | matches | **misses** | broken |
| `where('d', '<=', '2026-10-05')` | matches | **misses — the boundary day is dropped** | broken, and it is the killer |
| `where('d', '<', '2026-10-05')` | correct | correct | safe |
| `where('d', '>=', '2026-10-05')` | correct | correct (a time-part row is still ≥ the bound) | safe |
| `where('d', '>', '2026-10-05')` | correct | **wrongly includes the boundary day** | broken |
| `whereBetween('d', [$a, $b])` | correct | **drops the last day of the range** | broken |
| `where('d', 'like', '2026-10%')` | correct | correct | safe |
| `whereDate('d', <any op>, $bound)` | correct | correct | **always correct** |

`whereDate()` compiles to `date(column) <op> date(bound)`, so it is shape-agnostic on both sides.
That is the fix applied throughout, except where a column is not a date at all (§5).

---

## 2. What the data actually looks like

`deploy/dev/audit/date_shape_survey.php` inspects every `DATE` column of every non-empty table and
groups the rows by stored length. Run 2026-10-13 on the demo database:

```
DATE columns in non-empty tables: 336   date-only: 8   has-time: 318   MIXED: 10
```

So **95 per cent of date columns store the time part**, 10 store both shapes in the same column, and
only 8 (for example `attendances.date`, written as a plain string by the attendance import) store a
bare date. A `date` cast on the model does not help: the cast governs what PHP sees on read, not what
SQLite stored on write, and the two shapes coexist inside single columns.

The 10 MIXED columns — the ones where the same column holds both shapes:

| table.column | `Y-m-d` rows | `Y-m-d H:i:s` rows |
| --- | --- | --- |
| `compliance_events.event_date` | 27 | 13 |
| `folio_transactions.business_date` | 176 | 370 |
| `folios.invoice_date` | 66 | 89 |
| `gift_voucher_transactions.business_date` | 20 | 5 |
| `leases.notice_on` | 1 | 1 |
| `leases.move_out_on` | 1 | 1 |
| `linen_movements.date` | 4 | 150 |
| `parking_charges.business_date` | 86 | 4 |
| `petty_txns.date` | 1 | 22 |
| `rfq_quotes.quote_date` | 5 | 2 |

`folio_transactions.business_date` is the guest-ledger money column: 370 of its 546 rows carry the
time part. A mixed column cannot be fixed by choosing the right string comparison — only
`whereDate()` is correct against it.

---

## 3. What it cost, measured

`deploy/dev/audit/date_equality_probe.php` runs the string form and the `whereDate` form side by side
over the money tables and prints the rows and the cedis that the string form misses, as at the
current business date. `date_controls_compare.php` does the same for the control accounts and the
trial balance at a posted cut. Both were run on the patched tree and, via `git stash`, on the
pre-fix tree.

### 3.1 Rows and money missed by one date bound (as at 2026-10-13)

| table.column | op | string form | `whereDate` | missed | money missed |
| --- | --- | --- | --- | --- | --- |
| `folio_transactions.business_date` | `<=` | 428 | 484 | **56** | **GH₵ 233,618.00** |
| `folio_transactions.business_date` | `=` | 3 | 59 | 56 | — |
| `rfq_quotes.quote_date` | `<=` | 5 | 7 | 2 | GH₵ 102,393.60 |
| `gift_voucher_transactions.business_date` | `<=` | 23 | 25 | 2 | GH₵ 3,000.00 |
| `vouchers.date` | `<=` | 11 | 12 | 1 | GH₵ 1,204.00 |
| `linen_movements.date` | `<=` | 105 | 127 | 22 | — |
| `folios.invoice_date` | `<=` | 134 | 140 | 6 | — |
| `stock_movements.date` | `<=` | 3,786 | 3,788 | 2 | — |

### 3.2 The general ledger

`LedgerService::balance()` and `LedgerService::totals()` — the two functions under every account
balance, ledger listing, trial balance, P&L and balance sheet in the product — both used the string
form. Measured on `gl_lines` (4,753 rows, all stored with the time part):

```
gl_lines as at 2026-10-12:  string <= 4,410 rows / debit 32,004,582.75
                            whereDate 4,458 rows / debit 32,086,822.15
                            MISSED     48 rows,  82,239.40 of debit
```

The last day of any period was excluded from every cumulative balance in the system.

### 3.3 Trial balance, before and after, in the running application

`/gl?from=2026-09-01&to=2026-09-30`, the totals row of the page itself, fetched on the pre-fix tree
and on the patched tree:

| | period movement (dr = cr) | closing (dr = cr) | cumulative debit since inception |
| --- | --- | --- | --- |
| before | 2,927,809.19 | 23,819,404.54 | 28,140,354.02 |
| after | **3,080,873.34** | **24,016,209.25** | **28,293,418.17** |
| difference | **+153,064.15** | +196,804.71 | +153,064.15 |

September 2026 was understated by **GH₵ 153,064.15** — the whole of 30 September. The trial balance
still balanced to the cent in both cases, because the day was dropped from both sides, which is
exactly why nothing ever reported it.

### 3.4 Control accounts at a posted cut (2026-09-30)

`CloseService` fails a period close when a *strict* control account disagrees with its sub-ledger, so
this is the reconciliation the finance team actually looks at.

| control account | before | after |
| --- | --- | --- |
| Guest ledger (open folio balances) | sub −275,819.00 / gl −275,819.00 | sub **−272,612.50** / gl **−272,612.50** |
| Membership fees in advance (unearned) | sub 26,621.42 / gl 32,131.67 — **diff −5,510.25 (warn)** | **26,621.42 / 26,621.42 — diff 0.00** |
| Inventory (stores valuation at cost) | sub 380,563.73 | sub **376,779.50** |
| every other strict control | agrees | agrees |
| strict controls out of balance | 0 | 0 |

The unearned-subscription control had been reporting a GH₵ 5,510.25 timing difference on the
period-close checklist; that difference was the bug, and it is now nil. Both guest ledger figures
moved because 30 September folio activity was previously invisible to the sub-ledger side.

At the *current* business date the two sides legitimately differ, because today has 59 folio
transactions and no GL lines at all (`gl_lines` maximum date is 2026-10-12): the GL is posted at
night audit. That is why `GlController` reconciles as at `setting('gl_cutoff')` and `CloseService` as
at the period end, never as at today. Before the fix, comparing as at today appeared to agree only
because the same bug was hiding today from both sides.

### 3.5 The Duty Roster could not be saved at all

`RosterService::saveWeek()` looked the existing shift up with string equality, so on a week that
already had shifts every posted cell looked like a change, and the `updateOrCreate()` that followed
inserted a second row for the same employee and day. `roster_shifts` has a unique index on
`(employee_id, date)`, so the save died:

```
POST /hr/roster  dept=9 week=2026-10-12   (pre-fix tree)
  -> 500  SQLSTATE[23000]: Integrity constraint violation: 19
          UNIQUE constraint failed: roster_shifts.employee_id, roster_shifts.date
          SQL: insert into "roster_shifts" ("employee_id","date",...) values (5, 2026-10-05 00:00:00, ...)

POST /hr/roster  dept=9 week=2026-10-12   (patched tree)
  -> 302  week rows 21 -> 21, roster_shifts 231 -> 231, duplicate employee+day groups 0
```

Reproduced by `deploy/dev/audit/roster_save_flow.py`, which harvests the grid from the page, posts it
back unchanged, and counts rows and duplicate `(employee, day)` groups before and after.

### 3.6 The same upsert defect in nine more places

An array-keyed `updateOrCreate()` / `firstOrCreate()` cannot use `whereDate()`, so those eleven
sites now call the two new helpers in `app/helpers.php` — `date_upsert()` and
`date_first_or_create()`, which match date-cast key columns by calendar day and everything else with
plain equality:

| site | table.column | stored | consequence before the fix |
| --- | --- | --- | --- |
| `RosterService` ×3 (save week, copy week, cover a swap) | `roster_shifts.date` | 231 rows, all with time part | HTTP 500 on every save (§3.5) |
| `IncomeAuditService` ×2 (open the day, close the night) | `audit_days.business_date` | 7 rows, all with time part | a second open audit day for a date that already had a **closed** one |
| `FbCostService::staffMeal()` | `fb_staff_meals.business_date` | 56 rows, all with time part | duplicate staff-meal cost rows per day |
| `DigitalMarketingService::stats()` | `dm_channel_stats.month` | 30 rows | duplicate monthly channel stats |
| `BusinessDevService` rate shop / benchmark / initiative result | `bd_rate_shops.stay_date`, `bd_benchmarks.month`, `bd_results.month` | 21 / 6 / 4 rows | duplicate rate-shop, comp-set benchmark and initiative rows |
| `EsgService::laundry()` | `esg_laundry_months.month` | 1 row | duplicate outsourced-laundry months |

The income-audit one is the serious case: `firstOrCreate()` would have created a fresh **open** audit
day beside a **closed** one, and the night audit would then have run twice over the same date. Verified
after the fix — `date_first_or_create()` returns the existing row and leaves `status = closed`
untouched.

---

## 4. What changed

103 application files — 411 insertions, 357 deletions. Every conversion was made against a column list derived from the live schema
(`pragma_table_info`), not from a guess, and the whole tree was linted and swept afterwards.

| change | count | notes |
| --- | --- | --- |
| `whereBetween('COL', [$a, $b])` → `whereDate('COL','>=',$a)->whereDate('COL','<=',$b)` | 114 | 43 files; bracket-depth-aware rewrite, so bounds containing commas survive |
| `where('COL', <op>, $v)` / `orWhere(...)` → `whereDate` / `orWhereDate` | 204 | 88 files, including comparisons nested inside `where(fn ($q) => …)` closures and one dynamic-operator site |
| `orWhereBetween('COL', [$a, $b])` → `orWhere(fn ($b) => $b->whereDate(…)->whereDate(…))` | 2 | grouped, so the OR keeps its scope as `DATE-QUERIES.md` requires: `CloseController::show()` on `period_postings.date` and `ReportController::r_traces_register()` on `traces.carried_from` |
| ambiguous column names resolved per table | 18 | see §5 |
| array-keyed upserts → `date_upsert()` / `date_first_or_create()` | 11 | 8 files; 2 new helpers + 1 shared lookup in `app/helpers.php` |
| control-account and balance layer converted by hand first | 21 | `LedgerService::balance/totals/controls`, `ApService`, `ClubBillingService`, `LeaseBillingService`, `TreasuryService`, `OwnerService`, `AssetService`, `ManagementReportService::arAgeing`, `OnlinePaymentService`, `ConciergeService`, `PettyCashService`, `LinenController` |

Left alone deliberately:

* `like '2026-10%'` on a date column — `GstController:51`, `GstService:546`,
  `WhtService:172/176/206`. A prefix LIKE matches both shapes, so it is already correct and
  converting it would be churn.
* `where('period', …)` / `where('month', …)` on the tables where those columns are `varchar`
  (`close_periods`, `owner_fees`, `owner_statements`, `statutory_remittances`, `sales_targets`,
  `budget_comments`, `period_postings`, `wht_periods`) or `INTEGER` (`budget_stats`,
  `marketing_budgets`). `whereDate()` on those would call SQLite `date()` on a value it cannot parse
  and return nothing at all.
* `where('valid_until', …)` on `key_cards` and `visitors`, where the column is a real `datetime` and
  the bound is `now()` — intra-day precision is intended there.
* `whereBetween('created_at'|'paid_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])` in
  `ReportController::r_online_payments()` and `orWhereBetween('cancelled_at', …)` in
  `ManagementReportService`. These are genuine `datetime` columns and both callers already widen the
  bounds to the ends of the day, which is the correct way to range a timestamp; `whereDate()` would
  throw away the time they are there to use.

---

## 5. Two classes of trap the first pass fell into, and how they were caught

A blanket rewrite of this size has to be checked against the schema and against the receiver type,
because both can turn a fix into a new bug. Two did:

1. **Columns that are not dates.** The `whereBetween` pass was driven by the full list of 177 date
   column *names*, but four names are used with different types elsewhere: `month` (INTEGER in
   `budget_stats` and `marketing_budgets`, DATE in `dm_channel_stats`, `bd_benchmarks`, `bd_results`,
   `esg_laundry_months`), `period` (varchar in ten tables, DATE only in `fs_checklist_runs`),
   `valid_from` (datetime in `key_cards`) and `valid_until` (datetime in `key_cards` and `visitors`).
   The comparison passes were therefore re-run against the 173 names that are DATE in *every* table
   that has them, and the ambiguous ones were resolved one site at a time against the model in the
   call. Four conversions were reverted: `UsaliService::budgetStats()` (fiscal month numbers) and
   three `period` report ranges in `ReportController` (`'Y-m'` strings).
2. **Collection receivers.** `Illuminate\Database\Eloquent\Collection` has `where()` but not
   `whereDate()`, so converting a collection filter produces
   `BadMethodCallException: Method Collection::whereDate does not exist` — a 500, not a wrong number.
   Three sites were affected and fixed: `MarketingPlanController` (`$events`, which made
   `/sales/marketing` a 500), `ComplianceController` (`$items`, where the collection already compares
   Carbon to Carbon and was correct as written), and `ApService::statement()` (`$rows`, a collection
   of arrays whose `date` key is already `Y-m-d`). Collection filters that compare a cast date to a
   *string* are still wrong — `ReportController::roomNightsBy()` was comparing a Collection with
   `whereDate()` before this audit and 500-ing on the source-mix and company-mix reports; it now
   filters on `toDateString()` explicitly, and both reports render.

Neither class is visible in a status-code sweep alone: the first returns empty result sets and a page
that still says 200, the second only fires on the one route that reaches it. They were found by
re-reading every introduced `whereDate()` against the schema and the receiver, then by sweeping.

---

## 6. Verification

| check | command | result |
| --- | --- | --- |
| syntax | `deploy/dev/bin/php -l` on all 103 changed files | 0 failures |
| **`orWhere` conservation** (the trap `DATE-QUERIES.md` records shipping once) | per-file count of `->orWhere(` + `->orWhereDate(` + `->orWhereBetween(`, working tree against `HEAD` | **0 of 102 files changed their OR-family count** — `orWhere` 154 → 135 and `orWhereDate` 15 → 34, so all 19 kept their `or`. No OR was turned into an AND. |
| **unsatisfiable pattern** | `whereNull('X')` followed by a non-`or` `whereDate('X', …)` on the same statement, whole tree | **0 sites**; the 26 same-column pairs that exist are all the correct `whereNull(X)->orWhereDate(X, …)` form |
| every page | `python3 deploy/dev/audit/full_get_sweep.py` | **462 parameter-free GET routes: 451 clean 200**; the 11 others are 9 × `/api/v1*` (401, token-only JSON API), `/group-dashboard` (403, permission gate) and `/reservations/api/rates` (404, JSON sub-resource). **Zero 500s.** |
| every report | `python3 deploy/dev/audit/report_sweep.py` | **259 of 259 `/reports/{type}` render clean over `from=2026-09-01&to=2026-09-30`** |
| control accounts | `date_controls_compare.php` at a posted cut | 0 strict controls out of balance; unearned-subscription difference −5,510.25 → **0.00** |
| trial balance | `/gl?from=2026-09-01&to=2026-09-30` before/after | period movement +153,064.15, still dr = cr |
| roster save | `roster_save_flow.py` before/after | 500 UNIQUE violation → 302, 231 rows unchanged, 0 duplicate `(employee, day)` groups |
| upsert helpers | tinker, inside a rolled-back transaction | `date_upsert` matches the existing row twice; `date_first_or_create` returns the existing row and leaves `status = closed` alone |
| payroll unaffected | `POST /payroll/runs/3/recalculate` on both trees | identical on both: gross 77,153.88, net 60,169.34 |
| HR automation | `php artisan pms:hr-run --dry` | unchanged output: roster warnings for both hotels, 10 time-clock items, the October draft chase, 6 overdue statutory returns |

The two page-level numbers the fix is meant to change are the trial-balance totals and the roster
save; everything else was checked to prove it did **not** change.

---

## 7. Still open

1. **Writers still produce two shapes.** Readers are now shape-agnostic, so this no longer changes a
   figure — it is hygiene, not a defect. `DATE-QUERIES.md` warns against normalising the stored data
   to `Y-m-d` *while string comparisons remain*, because call sites that bind a Carbon would then
   miss instead; that precondition no longer holds (every date comparison in `app/` now goes through
   `whereDate()`), but if normalisation is ever done it must be done as one change: an
   `UPDATE … SET col = date(col)` over the 10 MIXED columns **and** the writers that assign a Carbon
   to a date attribute switched to `toDateString()`, together, with the sweeps in §6 re-run
   afterwards. Until then the mixed shapes are harmless and the cheaper guard is a lint rule that
   fails on `->where('<datecol>'` or `->whereBetween('<datecol>'` for any column the schema declares
   `date`.
2. **`updateOrCreate()` keyed on a date column outside the 11 converted sites** — none exist today
   (the inventory in §3.6 was built from every array-keyed upsert in `app/` and `database/`), but
   new code should use `date_upsert()` rather than `updateOrCreate()` when the key contains a date.
3. **MySQL and PostgreSQL.** `whereDate()` compiles to `date(column)`, which cannot use a plain index
   on that column. SQLite is the dev and demo target here, so correctness was the priority; before a
   large MySQL deployment, normalise storage to `Y-m-d` (§7.1) and consider generated `DATE` columns
   or half-open ranges (`>= $from` and `< $to->copy()->addDay()`) on the hot reporting queries.
4. **Half-open ranges are the better idiom** for report queries that also need to be index-friendly.
   They are equivalent to `whereDate` for these columns and would survive a storage-shape change; the
   audit did not convert to that form because it changes 300 call sites a second time for no
   measurable gain on SQLite.

---

## 8. Reproducing

```bash
# what shape does every DATE column store?
TINKER='require "/app/deploy/dev/audit/date_shape_survey.php";' deploy/dev/bin/php -f deploy/dev/tinker.php

# rows and money missed by a string comparison, plus the balances built on them
TINKER='require "/app/deploy/dev/audit/date_equality_probe.php";' deploy/dev/bin/php -f deploy/dev/tinker.php

# control accounts and trial balance at a posted cut (set ASOF to move the cut)
ASOF=2026-09-30 TINKER='require "/app/deploy/dev/audit/date_controls_compare.php";' deploy/dev/bin/php -f deploy/dev/tinker.php

# save an already-rostered week through the UI and count the rows
python3 deploy/dev/audit/roster_save_flow.py 9 2026-10-12

# regression gates
python3 deploy/dev/audit/full_get_sweep.py /tmp/sweep.json      # every parameter-free GET route
python3 deploy/dev/audit/report_sweep.py /tmp/reports.json      # every /reports/{type} over a range

# before/after on the same database: stash the patch, re-run, restore
git stash push -- app/ && <probe> && git stash pop
```

`full_get_sweep.py` and `report_sweep.py` are the gates to re-run after any repo-wide query change;
both write JSON so two runs can be diffed.
