# Data migration & cutover (v7.35)

Import guest, company, employee, room and reservation data from a legacy PMS
(the Exceed-based Nowills ERP or any system that can export CSV) into Nowills
PMS — with dry-run, idempotent re-runs, per-row outcomes and reconciliation.

Available from **Administration → Data Migration** (permission
`settings.manage`) or from the command line:

```bash
php artisan pms:import guests   /path/guests.csv   --hotel=1 --source="Exceed PMS export 2026-10"
php artisan pms:import guests   /path/guests.csv   --hotel=1 --dry-run     # validate only
php artisan pms:import reservations /path/res.csv  --hotel=1
```

## Import order

Datasets reference each other through **external ids** (the legacy system's own
keys), so import in this order:

1. **companies** — corporate accounts, agencies, OTAs
2. **guests** — guest profiles (may reference a company's `external_id`)
3. **employees** — staff records (departments are found or created by name)
4. **rooms** — rooms (room types and floors are found or created from the row)
5. **reservations** — bookings/stays (reference guests and companies)

Each upload creates a batch (`MIG-00001` …) that is **dry-run validated on
upload**. Nothing is written to live tables until you press **Import (apply)**
on the batch (or run `pms:import` without `--dry-run`).

## CSV format rules

- UTF-8, comma-separated, first row is the header. Column order is free;
  names are matched case-insensitively and common aliases are accepted
  (`id`, `code`, `guest_id`, … — see the per-dataset template download).
- **Every row must have a stable `external_id`** from the legacy system. It is
  the idempotency key: re-importing the same file updates changed rows and
  skips unchanged ones. Reservations already imported are never duplicated.
- Dates accept `YYYY-MM-DD`, `DD/MM/YYYY`, `DD-MM-YYYY`, `DD.MM.YYYY` and a
  few other common shapes. Numbers may carry thousands separators or a
  currency symbol.
- Max 5,000 rows per file — split larger exports and import them in batches.
- Templates with example rows: **Data Migration → templates**, or
  `pms:import`-adjacent links on the screen.

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
  after that point are made in-app or through a new batch.

## Reconciliation

After an import the batch shows: counts per outcome, dataset totals in the
live database (e.g. guest profiles now, in-house stays, upcoming arrivals) and
mapped external ids. Verify these against the legacy system's own totals
before closing the cutover checklist.

## Cutover runbook (suggested)

1. Freeze writes in the legacy system; take its final export + backup.
2. Import masters (companies → guests → employees → rooms) and reconcile.
3. Import reservations on the eve of cut-over (future + in-house rows).
4. Walk the tape chart: resolve overlaps flagged in step 3's notes.
5. Run the night audit for the first live business date.
6. Keep the legacy system read-only for one full cycle (payroll/month-end),
   then archive it.

## Storage & audit

Source CSVs are stored under `storage/app/migrations/{MIG-code}/`. Every batch
and row keeps its outcome (`data_migration_batches`, `data_migration_rows`),
and actions are written to the activity log. Row payloads keep the original
CSV values for the audit trail.

## Roadmap (not yet importable)

Opening general-ledger balances, supplier/inventory masters, folio history and
payment history are deliberately out of scope for the first slice — folio and
ledger history should be carried as opening balances agreed with the
accountants rather than replayed transaction-by-transaction. Extend
`App\Services\Migration\Importers\` with a new importer class and register it
in `MigrationService::DATASETS` when needed.
