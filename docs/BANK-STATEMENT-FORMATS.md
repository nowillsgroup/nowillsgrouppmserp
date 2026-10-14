# Bank statement formats the reconciler reads — v7.48

**Scope:** the file that arrives from a bank or a Mobile Money merchant portal, and the path it takes
into a statement in Accounts › Bank Reconciliation.
**Result:** the importer read one shape of file — comma-separated text. It now reads four: **CSV /
tab-delimited text, Excel `.xlsx`, SWIFT MT940 / MT942 and ISO 20022 `camt.052` / `camt.053`**, each
reduced to the same columns the CSV parser already knew, so detection, de-duplication, auto-matching,
the running-balance check, fee splitting and the reconciliation statement all behave exactly as they
did before. A statement also records which format it arrived as.
**Evidence scripts:** `deploy/dev/audit/bank_formats.php` (54 checks — the readers),
`deploy/dev/audit/bank_import_flow.py` (50 checks — the same four files uploaded through the real
form), `deploy/dev/audit/bank_fixtures.py` (builds them).

---

## 1. Why this mattered

A hotel in Ghana reconciles at least three accounts, and none of them hands over the same file:

| Where the money is | What actually arrives |
| --- | --- |
| Ecobank / Stanbic / Absa current account | An **Excel workbook** from internet banking, or a **CSV** depending on which screen it was exported from |
| GCB corporate account | A **SWIFT MT940** e-mailed by relationship management, tagged text with two-digit years |
| A bank mid-migration to ISO 20022 | A **`camt.053`** XML file, often with payroll batched into one entry |
| MTN MoMo / Telecel merchant wallet | A **CSV** with a fee column and a running balance taken after the fee |

Before v7.48 the only two options for the first three rows were "export a CSV by hand" or "type the
lines in". Both put a person between the bank's own figures and the ledger, which is precisely what a
reconciliation is supposed to avoid: the retyped statement reconciles against itself whether or not it
reconciles against the bank.

## 2. What is accepted

`BankRecService::readFile($path, $name, $mime)` sniffs the file rather than trusting its extension, and
returns the CSV to import plus whatever the file says about itself:

```php
['text' => string, 'format' => 'csv|xlsx|mt940|camt', 'label' => string, 'rows' => int,
 'opening' => ?float, 'closing' => ?float, 'account' => ?string, 'currency' => ?string, 'notes' => string[]]
```

| Format | Recognised by | Read by | Notes |
| --- | --- | --- | --- |
| CSV / tab-delimited text | anything else | `readCsv()` — unchanged | BOM stripped, CRLF normalised, delimiter voted over `, ; TAB \|` from the first ten lines, heading row is the first row that names a date column |
| Excel `.xlsx` / `.xlsm` | the `PK\x03\x04` ZIP magic, or the extension | `fromXlsx()` | No spreadsheet library: an `.xlsx` is a ZIP of XML, read with `ZipArchive` + SimpleXML. Shared strings, inline strings and rich-text runs, the workbook's first sheet, and **dates that Excel stores as serial numbers** converted back through the sheet's own number formats |
| SWIFT MT940 / MT942 | `:60F:`/`:61:`/`:62F:` tags together with `:20:`/`:25:` | `fromMt940()` | Block wrappers `{1:…}{2:…}{4:` … `-}` ignored, `:86:` information to customer folded onto the transaction above it however many lines it spans, two-digit years, comma decimals, `R`/`E` prefixes read as reversals |
| ISO 20022 `camt.052` / `camt.053` | `camt.0` in the header, or a `<Document>` carrying `<Ntry>` | `fromCamt()` | Namespace-agnostic (`local-name()` XPath), so any prefix the bank's tool used works. Balances by type (`OPBD`/`PRCD` open, `CLBD`/`ITBD`/`CLAV` close), entries by `<CdtDbtInd>`, `<Rvsl>` reverses, pending entries kept but reported |

**Deliberately refused, with a plain-English reason rather than a 500:**

* **Excel 97-2003 `.xls`** — a binary BIFF stream, not a ZIP. *"That is an old Excel 97-2003 (.xls)
  workbook, which is a binary format. Open it and save it as .xlsx, or export a CSV."*
* **PDF statements** — the text layer of a bank PDF is laid out for printing, not for parsing; a
  mis-read digit in a balance is worse than a rejected file. Export a CSV or an MT940 instead.
* **A file with no date column** — the existing message stands: *"No date column found (columns: …).
  Type its name under 'Column names'."*
* **An MT940 with no `:61:` lines, a camt with no dated entries, an empty workbook.**

## 3. How each format becomes a row

Every reader emits the same six columns, all of which are already synonyms in
`BankRecService::HEADERS`, so `detect()` maps them without the user typing a column name:

`Date, Description, Reference, Debit, Credit, Balance`

| Importer column | Excel `.xlsx` | SWIFT MT940 | ISO 20022 camt |
| --- | --- | --- | --- |
| Date | the cell's own value, converted from a serial when its number format is a date (built-in ids 14–22, 27–36, 45–47, 50–58, or a custom format built of `d m y h s` parts) | `:61:` booking date when present, else the value date; the booking date's year is taken from the value date, adjusting at a December/January boundary | `<BookgDt>` (date or date-time), else `<ValDt>` |
| Description | the sheet's own text | the `:86:` information to customer, else the supplementary details on the `:61:` line | `<RmtInf><Ustrd>`, else `<AddtlTxInf>`/`<AddtlNtryInf>`, else the transaction family code |
| Reference | the sheet's own | the customer reference before the `//`; the bank's own reference after it is used only when the customer sent none | `<EndToEndId>`, then `InstrId`, `TxId`, `MndtId`, `ChqNb`, and `<AcctSvcrRef>` last — the originator's reference is what a folio or invoice matches on |
| Debit / Credit | the sheet's own columns, or a signed amount column | `C` credits, `D` debits, `RC`/`RD` reverse | `<CdtDbtInd>` `CRDT`/`DBIT`, `<Rvsl>` flipping it |
| Balance | the sheet's own | **rebuilt** from `:60F:` — an MT940 carries no running balance | **rebuilt** from `OPBD`/`PRCD` |

Two rules worth stating because they are easy to get wrong:

* **Money in is positive.** A credit is `+`, a debit is `−`, which is the sign the rest of the module
  already uses (`parse()` computes `abs(credit) − abs(debit)`). A statement issued from the bank's
  point of view can still be flipped with the existing *"Amounts are the bank's view"* checkbox.
* **A batched camt entry is split.** One `<Ntry>` carrying several `<TxDtls>` becomes one row per
  transaction, each with its own amount, reference and narrative — a payroll batch of forty salaries
  arrives as forty matchable lines rather than one unexplained lump. A detail line with no amount of
  its own is left to the batch total rather than duplicated.

## 4. What the importer now checks against the file itself

An MT940 and a camt.053 state the account's opening and closing balances, which is a cross-check a CSV
cannot offer. `BankRecController::store()` uses it:

* the opening balance typed on the form disagrees with `:60F:` / `OPBD` → *"The file itself opens at
  GH₵ X but the opening balance entered was GH₵ Y."*
* the closing balance typed disagrees with `:62F:` / `CLBD` → the same, for the closing figure
* the rebuilt running balance does not end at the file's own closing balance → *"…a page is probably
  missing from the statement."* This catches a truncated e-mail attachment, which is how most missing
  transactions arrive.
* pending camt entries (`<Sts><Cd>PDNG`) → *"N entry/entries are still pending at the bank… they are
  listed, but may not settle."*
* `:61:` lines the reader does not understand → counted and reported, never dropped silently.

These land in the same yellow banner the CSV importer already used for its warnings.

## 5. Evidence

Four files, one story: **Ecobank – Collections (1021), 01–31 August 2026, opening GH₵ 125,000.50,
nine transactions, closing GH₵ 117,366.25** — GH₵ 59,011.25 in, GH₵ 66,645.50 out. The same story is
written as a CSV with three preamble rows and a trailing `Total` row, as an `.xlsx` whose dates are
serials under number format 14, as an MT940 with CRLF line ends and comma decimals, and as a
`camt.053` whose two salary payments are one batched entry.

`deploy/dev/audit/bank_formats.php` — **54 checks, 0 failures**:

| Check | Result |
| --- | --- |
| Each file detected as its own format | `CSV / tab-delimited text`, `Excel workbook (xlsx)`, `SWIFT MT940 / MT942`, `ISO 20022 camt.052 / camt.053` |
| Lines parsed from each | **9** in every format, 0 duplicates |
| Excel dates came out of serials | first line `2026-08-03`, no five-digit serial anywhere |
| MT940 two-digit year and comma decimal | `2026-08-03`, `−145.00` |
| MT940 reference is the customer's, not the bank's | `INV-4471` (the file also carries `//EBC1…`) |
| camt batched salary entry split | two rows, both `2026-08-17` |
| camt narrative from the remittance information | `MoMo merchant settlement` |
| Account and currency read from the file | `1441000235678`, `GHS` (both formats) |
| Opening / closing read from the file | `125,000.50` / `117,366.25` (both formats) |
| Rebuilt balance ends at the file's own closing | `117,366.25` — no missing-page warning |
| **Every format matches the CSV line for line** | date, reference, amount and running balance identical across all four |
| `.xls`, a dateless file, an MT940 with no `:61:` | all three refused with the messages in §2 |

`deploy/dev/audit/bank_import_flow.py` — **50 checks, 0 failures.** This one walks the real path:
Accounts › Bank Reconciliation › New statement › pick the file › Create statement, for each format in
turn, then deletes the statement it created before the next upload (the module allows only one draft
per account) and leaves the SWIFT import behind as demo evidence:

* `Imported 9 line(s) into BST-00015 from a SWIFT MT940 / MT942. Auto-matched 0.`
* the statement page lists 9 lines, footer `9 line(s)`, in `59,011.25`, out `66,645.50`
* `INV-4471` shows as money in `18,450.00`; `CHG-000881` as money out `145.00`; `REV-AFD2291` as money
  coming back `9,320.50`; both `PAY-2026-08` salary lines on `17 Aug`
* the banner reports how the file was read, and that an MT940's balance was rebuilt
* the index and the statement header now read `ecobank_aug26.mt940 — SWIFT MT940 / MT942`, while the
  three CSV statements in the demo database read exactly as they did before

## 6. Reproducing it

```bash
python3 deploy/dev/audit/bank_fixtures.py        # rebuilds the four fixtures (no libraries needed)
TINKER='require "/app/deploy/dev/audit/bank_formats.php";' deploy/dev/bin/php -f deploy/dev/tinker.php
python3 deploy/dev/audit/bank_import_flow.py     # needs the dev server on :8000
```

Or in the browser: **Accounts › Bank Reconciliation › New statement**, pick
`deploy/dev/audit/fixtures/ecobank_aug26.xlsx`, account *1021 Ecobank – Collections*, 01 Aug 2026 –
31 Aug 2026, opening `125000.50`, closing `117366.25`, **Create statement**. Delete the statement
afterwards if you want to try the next format — one draft per account is the rule.

## 7. Adding another format

One function and one line. Write `fromSomething(string $path): array` returning
`[csv-text, meta]` where the CSV's first row uses the column names in §3, add a key and a human label
to `BankRecService::FILE_FORMATS`, add a sniffing arm to the `match (true)` in `readFile()`, and put a
fixture in `deploy/dev/audit/fixtures/` with a block of checks in `bank_formats.php`. Nothing
downstream of `readFile()` needs to change: `parse()` onwards is format-blind, which is the whole
point of reducing every reader to the same six columns.

Candidate formats, in rough order of demand: **BAI2** (US cash-management, occasionally used by
correspondent banks), **OFX/QFX** (some internet banking exports), and **PDF with a text layer**
(worth doing only with a per-bank template and a hard warning, because a mis-read digit is worse than
a refused file).

---

**Files changed in v7.48:** `app/Services/BankRecService.php` (`readFile`, `fromXlsx`, `fromMt940`,
`fromCamt` and their helpers; `import()` now records the format), `app/Models/BankStatement.php`
(`isImported()`, `sourceLabel()`), `app/Http/Controllers/BankRecController.php` (upload validation and
the balance cross-checks), `resources/views/bankrec/{create,index,show}.blade.php`, plus
`deploy/dev/audit/bank_formats.php`, `bank_import_flow.py`, `bank_fixtures.py` and the four fixtures.
