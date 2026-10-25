# Data migration & cutover (v7.35 + G1 opening stock, AP/AR, GL and Treasury preflight)

Import company, guest, employee, room, supplier, inventory-item and reservation
master data from a legacy PMS (the Exceed-based Nowills ERP or any system that
can export CSV) into Nowills PMS — with dry-run, idempotent re-runs, per-row
outcomes and reconciliation. A separate `opening_stock` dataset imports a
property's cutover quantities and unit costs as opening stock movements and
posts the balanced stock-day journal (Dr Inventory / Cr Opening Balance Equity).
A separate `opening_ap` dataset carries forward **only unpaid supplier bill
balances and unapplied supplier credits** into the AP subledger. This open-items-
only policy is **provisional and not yet user/finance-approved**; confirm the
cutover treatment before production use. Provisional — policy not yet approved; confirm cutover method before production.
It preserves source references, original
dates and explicit foreign-currency carrying data, but creates no historical AP
journal and does not replay paid bills/payments.
A separate `opening_ar` dataset carries open guest folio and company city-ledger
items by source document, retaining source references, document/opening dates and
company due dates. It does not replay paid items or payment/refund history and
creates non-posting subledger rows, so the opening trial balance establishes the
guest and city-ledger control balances once. Opening guest folios are standalone
and detached from reservations; their opening balances remain separate from
charges/revenue/tax, cannot be voided, split or moved, and do not produce an
invoice or tax record when an opening-only folio is settled. Company invoice
ageing uses the required source due date.
The `opening_gl` dataset performs **preflight only** for a full opening trial
balance: explicit account-code mapping, exact balance checks, an inventory
subledger check, AP/AR-control comparison, and a provisional adjustment report
against Nowills GL. The report separates structural preflight status from strict
subledger-control reconciliation and posting readiness. For each strict control,
the report lists source, subledger and difference with a match/block status.
Posting remains disabled pending AP-policy approval, required subledger sign-offs
and duplicate-posting controls.
A separate `historical_stock` dataset validates source movement mappings and
fields, then stores applied rows in an immutable, non-operational
`historical_stock_movement_archives` table. It never writes `stock_movements`,
changes on-hand balances, posts GL, or creates historical-stock ID maps. Source
IDs are immutable within each property: identical re-imports are skipped and
changed rows are rejected rather than overwritten. The
`inventory_allergens` dataset likewise preflights mapped ingredient declarations
against Nowills' canonical allergen keys/codes without changing live stock or
menu data or marking declarations as verified. Food Safety staff must review
supplier labels/specifications and enter or verify live declarations in the
Food Safety matrix. The separate `paid_supplier_history` dataset retains source
paid bills, payments, credits and allocations in an immutable archive only. It
never creates or changes operational AP invoices, payments, allocations, opening
balances or GL journals, and does not approve or operationalize the provisional
open-items-only AP policy. The optional `bank_account_mappings` dataset is a
read-only preflight for stable legacy bank/MoMo/clearing IDs against existing,
active, reconcilable Nowills Treasury accounts of the same kind. It accepts only
four non-sensitive columns; it does not import supplier bank details, account
numbers, bank statements, opening balances, live ID maps, or GL entries. The
operational mapping and opening-statement cutover remain separate work.

Available from **Administration → Data Migration** (permission
`settings.manage`) or from the command line:

```bash
php artisan pms:import guests   /path/guests.csv   --hotel=1 --source="Exceed PMS export 2026-10"
php artisan pms:import guests   /path/guests.csv   --hotel=1 --dry-run     # validate only
php artisan pms:import reservations /path/res.csv  --hotel=1
php artisan pms:import opening_stock /path/opening_stock.csv --hotel=1 --source="Opening inventory at cutover"
php artisan pms:import opening_ap /path/opening_ap.csv --hotel=1 --source="Open supplier items at cutover"
php artisan pms:import opening_ar /path/opening_ar.csv --hotel=1 --source="Open guest/company receivables at cutover"
php artisan pms:import opening_gl /path/opening_gl.csv --hotel=1 --dry-run --source="Opening TB preflight"
php artisan pms:import historical_stock /path/historical_stock.csv --hotel=1 --dry-run --source="Legacy movement archive dry-run"
php artisan pms:import historical_stock /path/historical_stock.csv --hotel=1 --source="Legacy movement archive" # archive-only apply
php artisan pms:import paid_supplier_history /path/paid_supplier_history.csv --hotel=1 --dry-run --source="Paid supplier history archive dry-run"
php artisan pms:import paid_supplier_history /path/paid_supplier_history.csv --hotel=1 --source="Paid supplier history archive" # archive-only apply
php artisan pms:import bank_account_mappings /path/bank_account_mappings.csv --hotel=1 --dry-run --source="Treasury mapping preflight"
php artisan pms:import inventory_allergens /path/inventory_allergens.csv --hotel=1 --dry-run --source="Ingredient allergen source review"
```

`opening_ap` and `opening_ar` apply open-item records to their respective AP/AR
subledgers only; neither creates historical GL journals. `opening_gl`,
`inventory_allergens` and `bank_account_mappings` are preflight-only:
the CLI requires `--dry-run` and rejects apply. `historical_stock` and
`paid_supplier_history` may be applied only to their immutable archive tables;
their reports explicitly show zero operational stock/AP and GL writes. The bank
mapping report validates only stable legacy IDs and target-account eligibility;
it does not apply a map or create statements or opening balances. The opening-GL
report separates source/mapping/inventory
checks from strict control reconciliation and posting readiness. The historical
reports summarize source mappings, types, references, totals and archive counts.
The allergen report checks item mappings and known canonical declarations but
changes no inventory/menu allergen data and asserts no Food Safety verification.
No opening GL journal, operational historical stock movement or AP payment is
posted; bank mapping preflight likewise creates no Treasury mapping, statement,
balance or GL record.

## Import order

Datasets reference each other through **external ids** (the legacy system's own
keys), so import in this order:

1. **companies** — corporate accounts, agencies, OTAs
2. **guests** — guest profiles (may reference a company's `external_id`)
3. **employees** — staff records (departments are found or created by name)
4. **rooms** — rooms (room types and floors are found or created from the row)
5. **suppliers** — supplier contact, payment-term and withholding-tax master data
6. **inventory_items** — stock-item catalogue (categories are found or created)
7. **opening_stock** — on-hand cutover quantities and valuation (requires imported items and existing stores)
8. **opening_ap** — open supplier bill/credit balances (requires mapped suppliers; no historical GL posting)
9. **opening_ar** — open guest folios and company invoices (requires mapped guests/companies; no historical GL posting)
10. **opening_gl** — full trial-balance preflight only; posting remains disabled pending AP-policy approval, subledger sign-off and duplicate-posting controls
11. **historical_stock** — immutable archive-only movement history; no operational stock or GL rows are written
12. **inventory_allergens** — ingredient allergen source preflight only; no live declarations or verification stamps are changed
13. **reservations** — bookings/stays (reference guests and companies)
14. **bank_account_mappings** — optional read-only bank/MoMo/clearing mapping preflight against existing Nowills Treasury accounts; no maps/statements/balances are written
15. **paid_supplier_history** — immutable paid-bill/payment/credit/allocation archive only; no operational AP or GL rows are written

Each upload creates a batch (`MIG-00001` …) that is **dry-run validated on
upload**. Supported datasets can be applied from the batch page (or with
`pms:import` without `--dry-run`). `opening_ap` and `opening_ar` apply open
subledger items without historical GL journals. `opening_gl` and
`inventory_allergens` are preflight-only; their Apply actions are intentionally
disabled. `historical_stock` and `paid_supplier_history` Apply store immutable
source rows in separate historical archives only; the latter does not approve or
operationalize the provisional AP open-items policy. `bank_account_mappings` is
also preflight-only and has no Apply path; it validates one-to-one mappings to
existing reconcilable Treasury accounts without creating ID maps, statements or
balances.

## CSV format rules

- UTF-8, comma-separated, first row is the header. Column order is free;
  names are matched case-insensitively and common aliases are accepted
  (`id`, `code`, `guest_id`, … — see the per-dataset template download).
- `bank_account_mappings` is deliberately stricter: the CSV may contain only
  `external_id`, `source_account_name`, `source_kind` and `target_account_code`
  (or their documented aliases). Unknown/extra columns and long numeric runs
  that look like account/card numbers are rejected before the source file is
  retained. Use non-sensitive source IDs; do not include bank name, branch,
  account number, SWIFT/IBAN, credentials, balances or statement lines.
- **Every row must have a stable `external_id`** from the legacy system. For
  applied datasets it is the idempotency key: re-importing updates changed rows
  and skips unchanged ones; reservations are never duplicated. In
  `historical_stock` and `paid_supplier_history`, it is an immutable archive
  identity: identical rows are skipped and changed rows are rejected; neither
  creates an ID map. The preflight-only `opening_gl` and `inventory_allergens`
  datasets create neither ID maps nor live ledger, stock or allergen records.
- Dates accept `YYYY-MM-DD`, `DD/MM/YYYY`, `DD-MM-YYYY`, `DD.MM.YYYY` and a
  few other common shapes. Numbers may carry thousands separators or a
  currency symbol.
- Max 5,000 rows per file — split larger exports and import them in batches.
- Templates with example rows: **Data Migration → templates**; static source
  samples are in `docs/examples/migration/`.

## Dataset columns (required in bold)

### companies
**external_id**, **name**, type (`corporate`/`travel_agent`/`ota`), contact_person,
phone, email, address, tax_number, credit_limit, discount_pct, is_active.

Matching: external id map → exact company name → create.

### guests
**external_id**, **first_name**, last_name, title, gender, dob, phone, email,
nationality, id_type, id_number, passport_no, address, city, state, country,
pincode, tax_number, company_external_id, is_vip, preferences, notes,
loyalty_points.

Matching: external id map → single exact e-mail match → create.

### employees
**external_id**, **name**, emp_code, department, designation, phone, email,
joining_date, salary, id_proof, address, is_active.

Matching: external id map → staff number → name+phone → create. Staff numbers
already in use are never rewritten.

### rooms
**external_id**, **room_no**, **room_type_code**, room_type_name, base_rate,
floor, extension, status (`vacant`/`occupied`/`out_of_order`/`out_of_service`),
hk_status, is_smoking, notes, is_active.

Matching: external id map → room number → create. Room numbers are the
physical identity and never rewritten. Unknown room-type codes are created
with the row's `room_type_name`/`base_rate`.

### suppliers
**external_id**, **name**, contact_person, phone, email, address, tax_number,
payment_terms_days, wht_category (`none`/`goods`/`works`/`services`/`rent_res`/
`rent_com`/`nonresident`), wht_exempt_ref, wht_exempt_until, is_local, is_active.
Payment terms default to 30 days and WHT category defaults to `services`.

Matching: external id map → unique exact TIN → unique exact name → create.
Ambiguous name/TIN matches fail for manual resolution. On an existing supplier,
blank optional cells preserve the value already in Nowills; use the supplier
screen to clear a field. Bank details and expense-account mappings are
intentionally not imported; configure them through the secured
supplier/accounting screens after cutover.

### inventory_items
**external_id**, **name**, code, category, unit, reorder_level, last_cost,
tax_rate, yield_pct, is_active. `last_cost` is in the property's currency;
category is found or created by name. Unit defaults to `pcs`, numeric amounts
and reorder levels to zero, yield to 100%, and active to true.

Matching: external id map → unique exact stock code → unique exact name →
create. Ambiguous matches or a name/code conflict fail for manual resolution.
On an existing item, blank optional cells preserve the current value; use the
inventory screen to clear a field. This dataset imports the catalogue only:
**no on-hand quantity, stock movement, allergen declaration, or stocktake
history is imported**. Opening quantities are a separate `opening_stock` dataset
below; historical movements and stocktake history are not imported. The
`inventory_allergens` dataset validates source ingredient declarations only; it
does not update the live allergen matrix or verify source evidence.

### opening_stock
**external_id**, **item_external_id**, **store_name**, **opening_date**,
**quantity**, **unit_cost**.

Matching: `item_external_id` must already be mapped by an applied
`inventory_items` batch; `store_name` must identify exactly one existing store
in this property. There is no store importer, so create/verify stores before this
step. Every row in one file must use the same opening date; split separate
cutover dates into separate files. The date must be on or before the closed-ledger
cutoff (the calendar day before the business date), not after the current business
date. New rows and changed values must also be dated after the GL lock date. An
unchanged, mapped row may be re-imported in a locked period only if its existing
stock journal still reconciles. This keeps new postings on a closed, unlocked day
and avoids posting today's unfinished stock journal early. A locked-period
correction belongs in a current-period stocktake, not a rewritten opening balance.

Quantity and unit cost must be positive, fit the stock ledger and have no more
than two decimal places. Unit cost is the finance-approved inventory carrying
cost in the property's currency; apply the property's treatment of recoverable
tax and directly attributable costs before uploading. Each row's value is
rounded to two decimals. The import records an idempotent
`stock_movements` row with type `opening`; applying it reconciles the existing
stock-day journal as **Dr Inventory / Cr Opening Balance Equity**, using the
standard system accounts `inventory` and `opening_equity` (no per-batch account
picker). Set the catalogue's current `last_cost` separately in `inventory_items`;
opening `unit_cost` remains the dated stock valuation. The importer
verifies that the dated opening-equity credit equals the opening movement value
and that the journal balances. Stock movements, migration rows, mappings and GL
reconciliation are committed in one transaction; if GL reconciliation fails,
the opening-stock apply rolls back. The standard stock-day journal can also
contain other non-purchase/non-transfer stock movements dated that day.

Re-importing the same `external_id` while its date is open updates the mapped
movement and reconciles the affected stock journal; repeating an unchanged row
does not duplicate stock or GL, even after the date is locked (locked rows are
checked against their journal but cannot be edited). Rows omitted from a later
CSV are not deleted automatically. As with
other datasets, a partially invalid file reports row failures while successful
rows are applied and posted; fix failed rows in a new batch. The report shows
row count, quantity, value, journal number(s) and opening-equity credit.

### opening_ap (open supplier balances; no historical GL posting)

> **Policy is provisional, not approved:** this importer follows the open-items-only
> assumption (unpaid bills + unapplied credits). Confirm this treatment with the
> user/finance owner before using it for a production cutover.

**external_id**, **supplier_external_id**, **item_type** (`bill`/`credit`),
**opening_date**, **document_date**, **supplier_ref**, **open_amount**,
due_date (required for bills), currency, foreign_open_amount, fx_rate, fx_date,
fx_basis, notes.

- `supplier_external_id` must already be mapped by an applied `suppliers`
  batch. Every `opening_date` in the file must be the same cutover/as-of date,
  on or before the closed-ledger cutoff; use the same date as the opening GL
  trial-balance file. `document_date` must be on/before that date. Bills require
  the original `due_date` so AP ageing does not invent payment terms.
- One `bill` row carries one **unpaid supplier invoice balance**; `open_amount`
  is the balance still payable at cutover, not the invoice's original gross.
  One `credit` row carries one **unapplied supplier credit** (including an
  unapplied payment-on-account balance). Credits already applied to a bill must
  not be imported a second time. Paid bills and historical payment/allocation
  rows are not replayed. The amount is stored as a cutover payable or credit;
  invoice line detail and prior payment history are not reconstructed.
- `open_amount` is positive property-currency value to two decimals. `currency`
  may be blank or the property currency for home-currency balances. A foreign
  item requires `foreign_open_amount`, the original source carrying `fx_rate`
  (up to four decimals), and `fx_date`; `fx_basis` is `reference`, `buy`,
  `deal`, or `legacy` when the source basis is unknown. The property-currency
  `open_amount` must equal foreign balance × source rate to the cent. The
  importer never substitutes today's Forex Desk rate. Resolve a source FX
  revaluation separately rather than forcing a mismatch into the AP control.
- Each row needs a unique `external_id` and supplier document reference; duplicate
  supplier/type/reference pairs and unresolved supplier mappings block the
  entire snapshot. The batch is atomic. Re-importing an unchanged mapped row is
  safe; a changed row is only replaceable before it has allocations or other
  subsequent activity. Omitted rows are not deleted automatically.
- Applying the dataset creates an opening supplier bill or supplier-credit
  item (shown as a debit-note-style credit), marks it with the cutover date, and
  creates an external-id map. These rows contribute to `ApService::controlBalance`
  and AP ageing, but `LedgerService` does not post their historical bill/credit
  journals. No GL is posted by this dataset; the opening trial balance will
  establish the AP control once. Future settlements use normal AP posting. An
  opening item cannot be cancelled through the historical-bill workflow; correct
  an unapplied row before opening-GL posting, or enter an approved live
  adjustment after cutover.
- The report shows source bill/credit totals, current and projected AP control
  balances, and the existing AP GL balance. Compare the projected AP control to
  the mapped Accounts Payable source balance in `opening_gl`; any variance is
  reported there and is not plugged automatically.

### opening_ar (open guest folios and company invoices; no historical GL posting)

**external_id**, **item_type** (`guest_folio` / `company_invoice`),
**opening_date**, **document_date**, due_date, **source_ref**, **open_amount**,
guest_external_id, company_external_id, notes.

- `opening_date` is the end-of-day as-of date for the receivable snapshot. All
  rows in one file use the same date, on or before the closed-ledger cutoff;
  use the same date in the opening GL file. `document_date` is the original
  folio/invoice date and cannot be after the opening date. A company invoice
  requires its source `due_date` for ageing; a guest folio may retain one too.
- `guest_folio` requires a mapped `guest_external_id` from the `guests` dataset
  and a blank `company_external_id`. `company_invoice` requires a mapped
  `company_external_id` from the `companies` dataset; its guest reference is
  optional. Each row needs a stable `external_id` and source folio/invoice
  reference. Duplicate references for the same owner and item type block the
  whole snapshot. `open_amount` is the positive remaining balance in property
  currency, with no more than two decimals.
- Import only unpaid residual document balances at cutover. Do not import paid
  folios/invoices, receipts, payments, refunds, credit/refund history or
  historical tax/revenue lines. The importer records one distinct
  `opening_balance` subledger transaction per source item with its reference,
  original document date, opening date and (for company invoices) source due
  date. It creates no GL journal; the full opening trial balance establishes the
  guest AR and city-ledger controls once. Company invoice ageing and credit
  review use the source due date instead of recomputing it from current terms.
- Guest opening folios are new standalone `walk_in` folios with no reservation or
  room linkage, so they cannot be reused as the active stay folio. Their opening
  balances are shown separately from charges and carry no tax or revenue; the
  opening row cannot be voided, split or moved. Settling an opening-only guest
  folio closes it without issuing a new invoice/tax record. If new live charges
  are added to a guest folio, those charges remain separate and only those live
  charges can appear on a new tax invoice.
- The batch is atomic and idempotent. An unused mapped row can be corrected
  before later settlement/charge activity or an opening-GL journal makes the
  source balance unsafe to rewrite; omitted rows are never deleted automatically.
  The reconciliation report shows file totals and projected guest/city-ledger
  controls against the existing subledgers and GL balances. Resolve any control
  variance before opening-GL sign-off; apply remains non-posting.

### opening_gl (full opening trial-balance preflight only)
**external_id**, source_account_code, source_account_name, **opening_date**,
**account_code**, **debit_balance**, **credit_balance**.

- `external_id` is a stable unique legacy account-row id. The optional source
  code/name columns are retained for audit and do not drive account resolution.
  `docs/examples/migration/opening_gl.csv` is a balanced zero-value template
  pair only; replace it with the complete, finance-approved source trial balance.
- `account_code` is the exact, active Nowills chart-of-accounts code. The
  importer does not guess by name or create accounts; prepare/review the chart
  first. Multiple source accounts may intentionally map to one Nowills code and
  are aggregated.
- Every row uses one `opening_date` and the balances are in the property's
  currency. Amounts are non-negative, to two decimals, and may be positive on
  at most one side (debit or credit); both may be zero for a zero-balance row.
  The full source trial balance must balance exactly
  to the cent. No automatic Opening Balance Equity plug or rounding account is
  permitted.
- The date must be after the GL lock and on/before the closed-ledger cutoff.
  This is a planned cutover date; the preflight does not post it.
- The mapped Nowills Inventory account must equal the stock subledger valuation
  at that date to the cent. A mismatch blocks the whole preflight and must be
  resolved in the stock mapping/valuation first.
- The report compares the full legacy snapshot with Nowills GL balances at the
  same date and calculates a *provisional* balanced adjustment (source snapshot
  less current GL, including any existing opening-stock journal). This avoids
  treating opening stock's Dr Inventory / Cr Opening Balance Equity as a second
  debit/credit when a future posting phase is enabled. It also refuses to
  propose an adjustment if the existing GL baseline is unbalanced, an affected
  account is inactive/uncoded, or the proposed line/journal would exceed GL
  precision. It lists each target account's source balance, current GL baseline
  and provisional adjustment so the proposed journal can be reviewed by account.
- AP, guest/city ledger, payroll and other system control-account comparisons
  are checked against their subledgers. Variances are listed by control, and
  strict control mismatches are explicitly marked `BLOCKED`; an exact AP/AR
  match requires the `opening_ap`/`opening_ar` snapshots at the same date. Paid
  supplier bill/payment history and historical folio settlement history are not
  replayed. **Apply is disabled in both the migration screen and `pms:import`;
  this dataset only validates and reports.** A passing preflight creates neither
  a GL journal nor an ID map. Posting must wait for AP-policy approval, required
  subledger sign-offs, and verified reconciliation/duplicate-posting rules.

### paid_supplier_history (immutable, archive-only)
**external_id**, **supplier_external_id**, **cutover_date**, **record_type**,
**document_ref**, **document_date**, **currency**, **amount**,
bill_external_id, payment_external_id, credit_external_id, remarks.

- One row represents one source record. `record_type` is `paid_bill`, `payment`,
  `allocation`, `supplier_credit` or `credit_application`. Use `paid_bill` only
  for bills confirmed settled in the source system before cutover; unpaid balances
  belong in the separate, still-provisional opening-AP process.
- `supplier_external_id` must map to an imported supplier. One `cutover_date`
  is required for the file; it must be after the GL lock and on or before the
  closed-ledger cutoff. Each `document_date` must be earlier than cutover.
- An `allocation` row requires both `bill_external_id` (a `paid_bill` row) and
  `payment_external_id` (a `payment` row). A `credit_application` row requires
  `bill_external_id` and `credit_external_id` (a `supplier_credit` row). Linked
  records may be in the same batch or an earlier archive batch and must belong
  to the same supplier. Source currency is retained per row; values are not
  converted or netted across currencies.
- `amount` must be a positive source-currency amount to two decimals.
  Reconciliation reports row counts and source amount totals by record type and
  currency; it does not infer a payable balance or make accounting adjustments.
- Apply stores the immutable source row, supplier-name snapshot and original
  payload in `supplier_paid_history_archives`. Identical re-imports are skipped;
  changed source rows with the same `external_id` are rejected. It never creates
  or changes `SupplierInvoice`, `SupplierPayment`, `ApAllocation`, opening AP,
  GL journals or `DataMigrationIdMap` records. This archive does not approve or
  operationalize the provisional open-items-only AP policy.

### bank_account_mappings (read-only preflight)
**external_id**, **source_account_name**, **source_kind**, **target_account_code**.

- This is a mapping workpaper only for legacy bank, Mobile Money and card/gateway
  clearing account identities. `source_kind` must be `bank`, `momo` or `clearing`.
  Each `external_id` and each `target_account_code` must be unique in the file.
- `target_account_code` must exactly match an active Nowills GL code in the
  `Cash & Bank` asset group, linked to an active Treasury account of the same
  kind and enabled for bank-statement reconciliation. No name-based matching,
  target-account creation, or guess is performed.
- Use a stable, non-sensitive legacy ID and a neutral account label. Uploads
  are restricted to these four columns; unknown fields are rejected. Long
  numeric runs in the ID/label are rejected as a precaution against accidental
  account or card numbers. Never include account numbers, bank/branch metadata, SWIFT/IBAN, login, PIN,
  statement lines or balances. Supplier bank details and expense-account
  mappings remain excluded.
- Dry-run records only validation outcomes in the migration batch and shows the
  eligible target codes. Apply is blocked at the UI, service, importer and CLI
  layers. It creates no Treasury account or legacy-ID map, no statement or
  opening balance, and no AP/GL record. Actual account mapping and opening
  statement reconciliation remain open pending a separate cutover method.

### historical_stock (immutable, archive-only)
**external_id**, **item_external_id**, **store_name**, **cutover_date**,
**movement_date**, **movement_type**, **quantity**, unit_cost, reference,
department, remarks.

- `external_id` must be a stable, property-unique legacy movement id. Each
  `item_external_id` must map to an imported inventory item, and `store_name`
  must identify one existing Nowills store. Historical item/store names are not
  created or guessed.
- Use one `cutover_date` for the file. It must be after the GL lock and on or
  before the closed-ledger cutoff; every `movement_date` must be earlier than
  that cutover date. This prevents the history review from overlapping live
  post-cutover activity.
- `movement_type` accepts the stock-ledger vocabulary: `purchase`, `issue`,
  `transfer_in`, `transfer_out`, `adjustment`, `wastage`, `consumption` or
  `opening`. `quantity` is signed in the item's stock unit, up to three decimal
  places: purchase/transfer-in/opening are positive; issue/transfer-out/wastage/
  consumption are negative; adjustment may have either sign. Optional
  `unit_cost` is a non-negative property-currency value to two decimals; common
  currency symbols are accepted.
- The report summarizes row counts by type, unique item/store references, gross
  value for rows with costs, and archive counts. Quantity is not summed across
  item units.
- Apply writes an immutable copy and item metadata snapshots to
  `historical_stock_movement_archives`; the original CSV remains attached to the
  migration batch. Identical re-imports are skipped; a changed row with an
  existing `external_id` is rejected instead of overwriting the archive.
- This dataset never writes `stock_movements`, changes on-hand balances, posts
  GL or creates `DataMigrationIdMap` entries. Use `opening_stock` for the
  approved cutover quantity/value and its Dr Inventory / Cr Opening Balance
  Equity journal; historical rows remain an audit archive, not live stock.

### inventory_allergens (ingredient declaration preflight only)
**external_id**, **item_external_id**, **allergens**.

- One row describes one inventory ingredient. `item_external_id` must already
  map through an applied `inventory_items` batch; each item may appear only once
  per file. No item is created, matched by name or remapped here.
- `allergens` is required. Use `none` only when the source explicitly records
  that the item contains none of the listed major allergens; blank values are
  blocked rather than treated as safe. Otherwise use a pipe-separated list of
  canonical keys (`gluten`, `milk`, `nuts`, …) or the two-letter codes shown in
  the Food Safety matrix (`GL`, `MI`, `TN`, …). Unknown values, duplicates and
  mixed `none` declarations are rejected; free-text guesses are not inferred.
- This is a **preflight only**. No `InventoryItem.allergens` or menu declaration
  is changed, no verification stamp is created, and no id map or GL journal is
  written. The CSV stays attached to its migration batch for review. A chef or
  other responsible Food Safety staff member must check the supplier label or
  specification and then enter/verify the declaration in Food Safety. A passing
  preflight is not evidence that the declared information is correct or safe.

### reservations
**external_id**, **guest_external_id**, **arrival_date**, **departure_date**,
**room_type_code**, **rate**, room_no, status (`tentative`/`confirmed`/
`checked_in`/`checked_out`/`cancelled`/`no_show`), adults, children,
reservation_no, company_external_id, billing_instruction, group_name,
booking_type, ota_reference, purpose, special_request, eta, checked_in_at,
checked_out_at.

- One row = one reservation with one room line (split multi-room bookings into
  rows; use `group_name` to keep them together).
- Pass `reservation_no` to keep the legacy booking number; otherwise one is
  generated (`RES-#####`).
- `checked_in_at` is required for `checked_in`/`checked_out` rows;
  `checked_out_at` for `checked_out` rows.
- The importer intentionally **bypasses live inventory checks** and sends no
  confirmations — cutover data must land exactly as exported. After import,
  scan the tape chart for overlaps and resolve any double-sell manually.

## Idempotency & corrections

- The id map (`data_migration_id_map`) links each `external_id` to the created
  record. Re-running a file is safe.
- Guests are also matched on a single exact e-mail, so a profile that was
  created manually before the cut is updated instead of duplicated.
- To correct data after import, either fix it in the PMS (preferred — it is
  live) or amend the CSV and re-upload; re-runs update changed fields.
- Import batches cannot be re-applied after a successful import; corrections
  after that point are made in-app or through a new batch. `opening_ap` is
  stricter: changed opening rows are accepted only before any allocation or
  subsequent activity. `opening_ar` likewise blocks rewrites after guest
  transactions, company city-ledger activity/receipts, or opening-GL posting.
  Source rows omitted from a later batch are not deleted.

## Reconciliation

After an applied import the batch shows: counts per outcome, dataset totals in
the live database (e.g. guest profiles now, in-house stays, upcoming arrivals)
and mapped external ids. Verify these against the legacy system's own totals
before closing the cutover checklist. The `opening_ap` report shows supplier-
item totals, current/projected AP control and existing AP GL balance; compare it
with the source trial balance. The `opening_ar` report shows guest/company
source totals, current/projected subledger controls and the projected control-
to-existing-GL differences. The `opening_gl` preflight shows source debit/credit
totals, mapped accounts, inventory-to-stock control, current GL baseline,
provisional balanced adjustment and control-account variances, with a proposed
per-account delta against the current GL baseline. Its structural `Preflight
status` is distinct from `Strict subledger control reconciliation`; for every
strict control, the report shows the source natural balance, current subledger
balance, difference and reconciliation status. `Opening GL posting readiness`
remains blocked pending AP-policy approval, subledger sign-off and duplicate-
posting controls, even when structural and control checks pass. No GL posting
occurs. The `bank_account_mappings` report lists source kinds and distinct
existing account codes; a pass means only that those IDs point to eligible
Treasury accounts. It does not establish a bank balance, clear reconciling items,
or create a live legacy-ID mapping.

## Cutover runbook (suggested)

1. Freeze writes in the legacy system; take its final export + backup.
2. Import masters (companies → guests → employees → rooms → suppliers → inventory items) and reconcile.
3. Import and reconcile opening stock against the legacy stocktake.
4. Import open supplier bills/unapplied credits with `opening_ap` at the same as-of date as the trial balance; do not include paid history or credits already applied to bills. If paid source bills/payments are retained, dry-run then apply `paid_supplier_history` to its archive only; it must not create operational AP or GL entries.
5. Import open guest folios and company city-ledger invoices with `opening_ar` at that same as-of date; verify document references/dates and company due dates, and do not include paid items or payment/refund history.
6. If historical stock movement exports are available, run the `historical_stock` dry-run, resolve every mapping/date/value issue, then apply only to retain the immutable audit copy. Confirm the archive is not being treated as live stock: it never changes on-hand quantities or posts GL; use `opening_stock` separately for the cutover balance.
7. If ingredient-allergen source data is available, run `inventory_allergens` preflight; then have Food Safety staff compare each declaration with supplier labels/specifications and enter or verify it in the live matrix. Preflight does not certify safety or change allergen data.
8. Complete any remaining opening subledgers and agree their control/duplicate-posting rules before sign-off.
9. Run the `opening_gl` preflight against the same as-of date; resolve every mapping and control variance. Treat structural pass and posting readiness separately; posting remains disabled until AP policy is approved, subledgers are signed off, and duplicate-posting controls are verified.
10. After Nowills Treasury accounts are configured, run `bank_account_mappings` with `--dry-run` to review source IDs, account kinds and target codes. It does not create live mappings, bank statements, balances or GL entries; separately agree the operational mapping/opening-statement process.
11. Import reservations on the eve of cut-over (future + in-house rows), then walk the tape chart and resolve overlaps.
12. After the full financial cutover is approved and posting support is enabled, start live operation, run the night audit, and keep the legacy system read-only for one full cycle before archiving it.

## Storage & audit

Source CSVs are stored under `storage/app/migrations/{MIG-code}/`. Every batch
and row keeps its outcome (`data_migration_batches`, `data_migration_rows`),
and actions are written to the activity log. Row payloads keep the original
CSV values for the audit trail.

## Remaining cutover roadmap

Supplier and inventory **master records**, opening-stock balances, the
non-posting supplier AP open-item snapshot and document-level guest/company AR
opening balances are importable. Full opening GL balances have an account-mapped,
balanced, inventory-reconciled preflight, but no opening-GL journal posting is
available yet. Paid supplier bills, payments, credits and allocations can now
be retained in an immutable archive; operational AP replay remains unfinished
and is not approved by this archive. The open-items-only AP policy remains
provisional and unapproved. `historical_stock` supports immutable archive-only
retention, but archived rows never become operational stock movements or GL
postings. Ingredient-allergen source files can be preflighted, but declarations are not imported or verified;
Food Safety staff must check source evidence and update the live matrix. A
read-only bank/MoMo/clearing mapping preflight now checks source IDs against
existing reconcilable Treasury accounts, but it creates no live mappings or
opening bank statement/balance records; operational bank cutover remains open.
Supplier bank details and expense-account mappings remain intentionally excluded.
Remaining other-subledger control-account reconciliation/duplicate-posting rules
also need sign-off before enabling opening-GL apply. Historical folio payment/refund
history is intentionally not replayed under the approved document-level opening-
AR method. Agree the financial opening position with the accountants and avoid
replaying old postings without a reconciliation plan. Opening-stock values still
require finance approval and reconciliation against the legacy stocktake before
upload. To add another
supported dataset, extend `App\Services\Migration\Importers\` and register it
in `MigrationService::DATASETS`.
