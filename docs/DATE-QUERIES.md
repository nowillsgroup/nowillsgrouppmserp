# Date columns: always compare with `whereDate()`

## The problem

Date columns in this application (`arrival_date`, `departure_date`,
`business_date`, `due_date`, `event_date`, …) are **text columns that hold two
different forms of the same value**:

| Written by | Stored as |
| --- | --- |
| Eloquent (`Model::create`, `->update`, `->save`) with a `date` cast | `2026-10-13 00:00:00` |
| Raw inserts, `DB::table()->insert()`, seeders passing `->toDateString()` | `2026-10-13` |

Eloquent serialises a `date` cast with the model date format
(`Y-m-d H:i:s`), so anything written through a model carries the time suffix.
Nothing normalises it on the way in, and a probe of the dev database found
**63 date columns storing the datetime form**, four of them mixed within the
same column (`folio_transactions.business_date` held 286 datetime-form rows and
24 date-only rows).

A query that compares such a column against a plain date **string** therefore
matches only part of the table — or nothing at all:

```php
ReservationRoom::where('arrival_date', $date->toDateString())->count();  // 0  ✗
ReservationRoom::whereDate('arrival_date', $date)->count();              // 4  ✓
```

String comparison also breaks at range boundaries:
`'2026-10-13 00:00:00' <= '2026-10-13'` is **false**, so a stay arriving today
was excluded from `where('arrival_date', '<=', $today)` — the exact set the
night audit has to work on.

Passing a **Carbon instance** happened to work, because the query grammar binds
`DateTimeInterface` values as `Y-m-d H:i:s` and that matches the stored form.
That is why the defect was invisible on most pages and only showed up where a
service did `$d = $date->toDateString()` first.

## The rule

Compare date columns with `whereDate()` (and `orWhereDate()`), whatever you
pass — a Carbon, a `Y-m-d` string, either works:

```php
// equality
$q->whereDate('arrival_date', $date);

// ranges — the operator goes in the middle, as usual
$q->whereDate('arrival_date', '<=', $date)
  ->whereDate('departure_date', '>', $date);
```

`whereDate()` wraps the column in a date cast in SQL, so it is correct for both
storage forms and for the boundary cases.

Do **not** "fix" this by normalising the stored data to `Y-m-d`: the many
call sites that pass Carbon instances bind `Y-m-d H:i:s`, so date-only storage
would break them instead. `whereDate()` is the only form that is correct
against either.

## What was converted

285 call sites, in three passes:

1. **Reservation Workspace** (`ReservationWorkspaceService`,
   `ReservationWorkspaceController`, the `ws_arrivals` side-menu badge) — the
   board, KPI strip, right rail and Day Report were reporting **0 arrivals and
   0 departures** while four and three were on the books. Arrivals are now also
   counted **hotel-wide instead of room-scoped**: a `reserved` arrival carries
   `room_id = null` until it is assigned, so a room-scoped query dropped
   precisely the arrivals the desk still has to work.
2. **Night Audit and the reservations desk** (`NightAuditService`,
   `ReservationDeskService`, 67 sites) — the day's folio revenue was
   understated (4 of 15 transactions matched), the already-audited guard could
   miss an existing audit, `precheck()` missed same-day arrivals, and the desk
   `kpis()` / `tabs()` counts were zero.
3. **App-wide sweep** (55 files, 150 sites) — every `where()` / `orWhere()` on
   a `*_date` column passed a date string, either inline (`->toDateString()`)
   or via a variable assigned from it.
4. **Paren-balanced second pass** (46 files, 68 sites) — a plain regex cannot
   see a date string nested inside another call, so
   `where('arrival_date', '<', Carbon::parse($to)->toDateString())` survived
   pass 3. That exact line is `AvailabilityService::blockingStays()`, the query
   behind every availability, assignment and rate decision in the app. A
   scanner that matches the argument list by balancing parentheses (and
   respects quotes) found the rest; a re-scan now reports zero remaining
   string comparisons against a `*_date` column anywhere under `app/`.

Cumulative totals: `IncomeAuditService` 18, `ReportController` 18,
`RevenueService` 9, `UtilityService` 8, `FbCostService` 6.

Also fixed while sweeping: `GREATEST()` is not a SQLite function, so
`/inventory/reorder` and `/transport/fleet` returned 500. Replaced with
portable `CASE WHEN` in `TransportController`, `RequisitionService` and
`StockTakeService` — same semantics on MySQL and Postgres.

## Checking for regressions

To find columns whose rows disagree about the storage form:

```sql
SELECT count(*) FROM folio_transactions WHERE business_date LIKE '% %';
```

If that is greater than zero and less than the row count, the column is mixed
and any string comparison against it is returning partial results.
