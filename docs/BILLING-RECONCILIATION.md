# Billing Reconciliation — folio workpaper (v1)

**Status:** implemented 21 October 2026 · **Menu:** Billing → Billing Reconciliation · **Route:** `/accounts/billing-reconciliation`

## Purpose and boundary

This is a **non-posting, document-level workpaper** for issued folio tax invoices and credit notes. It records what the PMS billed, what payment/refund transactions are recorded on each folio, and the settlement amounts and evidence reviewed by Finance. It does not create, edit, reverse, or reconcile a GL journal, and it is not a tax return or a replacement for Bank Reconciliation.

The initial scope is intentionally limited to folio documents. Direct-settled POS bills, subsequent company-ledger receipts allocated back to invoices, gateway payout batches, and bank-statement line allocation are **not included**. Automating those sources needs finance-approved rules for fees, timing, split settlements, and allocation before it is safe to build.

## Included figures

A workpaper is created for an invoice-date range (maximum 367 calendar days). Its snapshot contains every currently issued folio invoice and credit note in that period, including:

- invoice/credit-note number, folio number, issue date, customer name, and the original invoice reference for a credit note;
- document value (charges and tax less discounts/allowances; a credit-note value is displayed as a positive credit amount);
- active folio payment and refund totals; and
- city-ledger transfers/credits, shown separately from cash.

Period totals treat credit-note values as reductions: **net billed = invoices − credit notes**. **Net folio cash movement = payments − refunds**. City-ledger transfers are not counted as cash. Settlement evidence totals are manually recorded receipt and refund amounts; the net cash variance is the snapshot cash movement less those evidenced amounts. Review individual documents as portfolio net differences can offset one another.

## Operator workflow

1. Open **Billing → Billing Reconciliation** and create a workpaper with an invoice-date range. An existing workpaper for the same range opens instead of being duplicated.
2. Review the document snapshots. Use **Refresh from folios** while the workpaper is a draft to add newly issued documents and refresh changed source values. A changed source resets its review status to pending; existing manual references and evidence are retained for re-checking.
3. For a cash receipt/refund, enter the amount supported by the bank statement, cashier shift, receipt, voucher, or other local evidence, plus a settlement reference or private evidence file. A row can be marked **Matched** only when the entered receipt/refund totals equal the folio snapshot and at least one evidence reference/file exists.
4. Mark partial, disputed, or mismatched items **Exception noted** and explain the issue. A document with no cash movement, such as one transferred entirely to a company account, can be marked **No cash settlement** with an explanation. Company-account transfer figures remain visible and are not labelled as collected cash.
5. A user other than the preparer finalises the workpaper. Finalisation locks its snapshots and reviews. Reopening requires a different user from the last reviewer and a reason; the prior finalisation details remain on the workpaper and in the activity log.

Evidence files are stored on the private local disk (PDF, JPG/JPEG, PNG, CSV, XLSX; maximum 10 MB) and can be downloaded only through the permission-checked workpaper route. Do not upload unrelated guest identity documents or payment-card data.

## Permissions and upgrade

The `billingrec.view`, `billingrec.manage`, and `billingrec.approve` permissions gate access, preparation, and final review respectively. The built-in Accountant role receives all three; Administrator retains its wildcard access. Existing custom roles remain under hotel administration.

After deploying the migration, run the normal upgrade commands:

```sh
php artisan migrate --force
php artisan pms:upgrade --no-interaction
```

## Verification and limitations

- `deploy/dev/audit/billing_reconciliation.php`: rollback-only checks for invoice and credit-note snapshots, payments/refunds, company-ledger separation, idempotent refresh, totals, four-eyes finalisation/reopen, and no journal posting.
- `deploy/dev/audit/navigation_permissions.php`: checks menu discoverability, route middleware, seeded permissions, and Accountant grants.
- Native PHP/MySQL production acceptance and real bank/cashier evidence processes remain to be tested at a property. The development verification uses the disposable PHP-WASM/SQLite environment.
