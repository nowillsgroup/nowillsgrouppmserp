# Multi-currency supplier invoicing, settlement and procurement — v7.53–7.54 + G4

**Scope:** multi-currency supplier POs, GRNs, AP invoices and settlements — from an OTA commission
billed by its Amsterdam entity in euros or an international operator's management fee in dollars, through
the bank transfer that settles it.
**Result:** manual foreign POs retain supplier-currency values and a PO-date rate for approvals; each GRN
uses its receipt-date board for stock and the AP accrual; a matched bill uses the invoice-date board and
separates receipt-to-invoice FX from genuine price/tax variance. Direct bills can also be entered in the
supplier's currency. A later payment can be made in that same currency, at the board for the payment date
or at the rate on the bank's advice; the realised exchange difference is posted to Exchange Gain / Loss,
while GRA withholding stays a separate GHS liability. The ledger, budget check, supplier balance, ageing,
WHT return and bank reconciliation still have the cedi figures they expect. Open-bill revaluation is
reported, never posted, until period end.
**Evidence scripts:** `deploy/dev/audit/ap_fx.php` (85 checks — v7.53 invoice conversion),
`deploy/dev/audit/ap_fx_flow.py` (43 checks — invoice form to controller),
`deploy/dev/audit/ap_fx_pay.php` (98 checks — v7.54 WHT-aware payment and journal),
`deploy/dev/audit/ap_fx_pay_flow.py` (35 checks — settlement through the real payment form, detail, statement,
remittance and ageing screen), and `deploy/dev/audit/procurement_fx.php` (37 rollback-only checks — G4 PO/GRN
receipt-date FX, matched-bill accounting, FX quote endpoints, rendered procurement/AP screens, and AP register).

---

## The problem this closes

Until v7.50 the property's own currency was the only currency anywhere in the system. v7.50 converted an
**incoming** channel booking at the board, because an OTA quotes in whatever currency its rate plan is
published in. Payables had the same problem on the other side of the counter: a supplier invoices in the
currency *it* bills in, and the person at the desk had to re-key it in cedis at a rate they chose, so

- the ledger, the supplier's statement and the budget all disagreed by whatever the keystroke was worth;
- nothing recorded **which** rate was used, or which day's board it came from, so nobody could defend the
  figure to an auditor or to the supplier;
- an open foreign bill is an obligation in *that* currency, and the system had no way to say what it would
  cost to settle once the cedi moved.

## What is stored

Seven nullable columns on `supplier_invoices`
(`database/migrations/2026_10_14_004000_supplier_invoice_fx.php`). A bill in the property's own currency
leaves all seven null and behaves byte for byte as it always did.

| Column | Type | What it holds |
| --- | --- | --- |
| `currency` | char(3) | the supplier's own currency — `EUR`, `USD`. Null means the property's |
| `fx_subtotal` | decimal(14,2) | the supplier's net, as they billed it |
| `fx_tax` | decimal(14,2) | the supplier's tax, as they billed it |
| `fx_total` | decimal(14,2) | the supplier's document total |
| `fx_rate` | decimal(14,4) | the board rate the bill was booked at |
| `fx_date` | date | **which** board that rate came from |
| `fx_basis` | varchar(12) | `reference` (the published board) or `buy` (what the desk would pay a guest) |

`subtotal`, `tax` and `total` stay what they always were: the property's own currency, and the only
figures the ledger, the budget and ageing ever read. This is the same convention v7.50 set on
`reservations.channel_currency` / `channel_amount_fx` / `channel_fx_rate` / `channel_fx_date`.

`SupplierInvoice` grew four readers: `isForeign()`, `fxOutstanding()` (what is still owed, read back into
the supplier's currency), `fxLabel()` (a printable `EUR 4,180.55` — it falls back to the property's own
currency and the posted total on a cedi bill, so a template can call it unconditionally) and `fxStale()`
(the board used was not the board for the invoice date).

## Which rate a bill is booked at

The rate always comes from **the board** — Foreign Guests › Exchange Rates. There is no deal-rate override
on a supplier bill: a rate somebody typed is exactly what this release removes.

| Setting | Default | Effect |
| --- | --- | --- |
| `forex_ap_convert` | `1` | off ⇒ a foreign bill is refused, not guessed at |
| `forex_ap_basis` | `reference` | `reference` = the published board; `buy` = what the desk would pay a guest |
| `forex_ap_stale_days` | `7` | how far back a board may be carried when the invoice date has no rate |
| `forex_ap_rate_date` | `document` | `document` = the board for the invoice date (the transaction date, which is what accounting wants); `business` = today's board whatever the invoice says. Applies to invoices; settlement always reads the payment date. |
| `forex_ap_pay` | `1` | permit a foreign bill to be settled in the currency it was billed in; off means settle in the property's currency |
| `forex_ap_pay_deal` | `1` | permit the bank's advised deal rate on a payment; off means use only the forex board |

All six are edited under **Foreign Guests › Settings › Forex desk**, beside the channel policy from
v7.50 that they mirror. `ForexService::apPolicy()` resolves invoice settings; `apBoard()` returns every
currency a bill could be converted at; `apPayRate()` always reads the board for the payment date (same
basis and carry-forward window) unless an allowed bank deal rate is explicitly selected.

## The rounding rule, and why it is per line

**Each line is converted and rounded on its own** — `round(amount × rate, 2)` per line — and the bill's
`subtotal`, `tax` and `total` are the sums of those rounded lines. Not the other way round.

That choice is deliberate: `total` is what the ledger posts, and the ledger posts lines. If the total were
converted once and the lines derived from it, the lines would not sum to the posting and the journal would
be out by a pesewa — the sort of difference that takes an afternoon to find. Rounding per line means the
lines always sum to what was posted, whatever the rate.

The consequence is honest and visible: `fx_total × fx_rate` can differ from `total` by a pesewa or two.
`ApService::fxNote()` says so instead of hiding it, and the entry form's own arithmetic
(`resources/views/ap/bill-create.blade.php`) mirrors the same per-line rounding, so the cedi figure on
screen while you type is the figure that will be posted.

Worked example from the harness — three lines of USD 0.01 at a board of 11.6359:

| | |
| --- | --- |
| each line | `round(0.01 × 11.6359, 2)` = **0.12** |
| the posted subtotal | 0.12 × 3 = **GH₵ 0.36** |
| converting the total once | `round(0.03 × 11.6359, 2)` = GH₵ 0.35 — **not** what is posted |

## What is refused, and what it says

Four refusals, all `RuntimeException`, all surfaced by `ApController::attempt()` as a readable flash
rather than a stack trace:

1. **Conversion is switched off** — *"Supplier bills in USD are switched off, so nothing would record the
   rate this one was booked at. Either enter it in GHS, or turn on foreign-currency supplier bills under
   Foreign Guests › Settings › Forex desk."*
2. **No rate on the board inside the window** — *"There is no USD rate on the forex board for 01 Aug 2026,
   and none in the 7 days before it. Set the rate under Foreign Guests › Exchange Rates, or enter the bill
   in GHS."*
3. **A matched goods bill must use its PO currency.** Foreign-currency POs and GRNs are supported by G4: the PO is converted for approval values at its PO-date board, each GRN values stock and the AP accrual at that receipt-date board, and the matched supplier bill is converted at its invoice-date board. The foreign amounts are matched; receipt-to-invoice FX is posted separately to Exchange Gain / Loss, while genuine price/tax variance remains subject to AP tolerance and review. A document denominated differently from the PO cannot be matched; enter it as a direct bill or correct the PO currency.
4. **A debit note in a third currency** — *"BILL-00008 was billed in EUR, so a debit note against it has
   to be in EUR too — the two are allocated against each other."* Left blank, a debit note **inherits**
   the currency of the bill it corrects.

## Where it shows up

| Screen | What changed |
| --- | --- |
| AP › Bills › New bill | **Billed in** — the property's currency plus every currency the board quotes, each option carrying its rate, basis and board date. A live cedi equivalent row appears as lines are typed, and a note says which rate the bill will actually be booked at |
| AP › Bills | the supplier's own figure and rate under the cedi total — `EUR 4,180.55 at 13.4907` |
| AP › Bills › one bill | the supplier's document beside the posting: billed in, net + tax as billed, board rate and basis, which board, a *carried forward* badge when the rate is not that date's, and what is still owed in the supplier's currency |
| AP › Ageing | **Foreign-currency exposure** (below) |
| Foreign Guests › Settings › Forex desk | the four payables settings, under the channel policy they mirror |

Saving a foreign bill flashes the booking: *BILL-00013 saved: USD 1,490.75 at 11.6200 reference, board of
14 Oct 2026 = GH₵ 17,322.52.* — as a warning rather than a success when the board was carried forward.

## Paying a foreign bill — v7.54

A payment can be made in the currency the supplier billed in. The picker only offers a currency when
this supplier actually has open bills in it, and the allocation rows in every other currency are disabled.
A foreign payment must be allocated to one or more bills in that same currency: it never leaves a
phantom supplier credit on account and never settles a GHS bill with euros.

The six new nullable columns on `supplier_payments` are added by
`database/migrations/2026_10_14_005000_supplier_payment_fx.php`:

| Column | What it records |
| --- | --- |
| `currency` | the currency actually transferred to the supplier |
| `fx_amount` | the bank's foreign-currency figure, net of any GRA withholding |
| `fx_rate` | the board rate for the payment date, or the typed rate on the bank's advice |
| `fx_date` | the payment date / board provenance |
| `fx_basis` | `reference`, `buy` or `deal` |
| `fx_diff` | the realised exchange difference in GHS: positive is a loss; negative is a gain |

`SupplierPayment::amount` remains the GHS equivalent of the amount the bank actually transferred; that
is the debit to the selected cash / bank account and what the bank reconciliation matches. It is **not**
the cedi carrying value of the bill. `allocated` remains that carrying value, what Accounts Payable owed.
For a foreign transfer, the service therefore records these two different figures rather than quietly
forcing one to match the other.

### The payment-date rate

A payment is valued on its **own date**, never the invoice's date and never today's rate by accident.
The selected basis and carry-forward window are the same as invoice conversion. The optional deal-rate
box is a separate, explicit opt-in: leave it unticked and the form previews the dated board; tick it and
type the rate from the bank's advice, and the stored basis says `deal`. The typed rate is sanity-checked
against the board when one exists. `GET /ap/payments/fx-rate` supplies the payment-date quote to the form;
the controller recalculates again on save.

### WHT stays in GHS

The foreign figure typed is what the bank actually transferred to the supplier, **after** withholding.
WHT and withholding VAT are calculated from the GHS allocation as before and remain GHS liabilities to
GRA. The form's default foreign transfer is each selected bill's foreign carrying amount less those
GHS taxes translated at the settlement rate. An override rate changes the cedi equivalent but never
changes the tax base. This matters: using the bank debit itself as the WHT base would make an exchange
loss look like an on-account advance and tax it twice.

### The realised difference and the journal

The stored exchange difference is:

`cedi bank debit + WHT + withholding VAT − GHS bills allocated`

The journal keeps the supplier control exactly at the booked amount:

- **Dr Accounts Payable** — the GHS allocation, what the bill was booked at;
- **Dr Exchange Gain / Loss** on a loss, or **Cr Exchange Gain / Loss** on a gain — the realised difference;
- **Cr Bank** — the GHS equivalent of the actual foreign transfer;
- **Cr WHT Payable / Withholding VAT Payable**, when due — the separate GHS tax liabilities.

The existing system account is `forex_gain` / **Exchange Gain / Loss** (chart code 4986). A rate
increase from 11.6458 to 11.6700, for example, can produce a realised loss. A better rate credits the
same account as a gain. The payment detail, payment register, remittance advice and supplier statement
show the foreign figure, the date and rate, and the difference. The statement makes clear that the
exchange difference is ours, not an extra charge to the supplier. Cancelling a payment releases the bill
and reverses its posting through the ordinary AP cancellation path.

A foreign payment is never left unapplied: the difference between the bank transfer plus tax and the
booked allocation is the realised currency movement, **not** a supplier credit. Consequently,
`ApService::controlBalance()` and the supplier statement reduce by what the bill owed, not by the
amount the bank happened to debit.

### Demo settlement

The demo's Meridian Hotels USD management invoice is partly settled by a USD 1,000 bank transfer at the
13 October reference rate of 11.6700. The bank transfer costs GH₵ 11,670.00; the bill allocation is
GH₵ 11,645.80; the GH₵ 24.20 realised loss is posted separately. The open foreign exposure therefore
keeps the USD 2,295.28 still owed, while the same row also records USD 1,000 settled and GH₵ 24.20
realised.

## Exposure: reported, never posted

`ApService::fxExposure()` groups open foreign bills and their payments by currency. It reports the open
obligation and its **unrealised** movement separately from the amount already transferred and its
**realised** movement. A currency remains on the card after its last bill is settled so the posted gain
or loss is not lost from the report.

On the demo database at the 14 October board:

| Currency | Open | Still owed | Booked in GH₵ | Board now | Cost to settle | Unrealised | Settled in it | Realised |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| EUR | 1 | 4,180.55 | 56,398.55 | 13.6184 | 56,932.40 | **+533.85** | — | — |
| USD | 1 | 2,295.28 | 26,730.43 | 11.6200 | 26,671.15 | **−59.28** | USD 1,000.00 | **+24.20** |
| **Total (GHS)** | 2 | — | **83,128.98** | — | **83,603.55** | **+474.57** | — | **+24.20** |

Realised exchange movement is **posted** on the payment journal. The unrealised movement on open
bills is **reported, not posted**: revaluation belongs to period end and to a decision about which rate
the close uses. A payables screen quietly writing unrealised FX journals would be worse than no number
at all.

## G4: foreign-currency POs, GRNs and matched supplier bills

`database/migrations/2026_10_21_000100_add_foreign_currency_to_purchase_orders_and_grns.php` adds nullable FX snapshots to POs, PO lines, GRNs, GRN lines, and the supplier invoice's receipt snapshot. Old records remain home-currency records; null FX fields are not backfilled or guessed.

The manual PO form stores the supplier's unit prices and totals in the selected currency, alongside the PO-date Forex Desk rate, basis and board date. The PO's existing `subtotal`, `tax`, `total` and line `rate` remain in the property's currency; those values drive approval thresholds and purchase-price comparisons. A foreign PO cannot use the legacy one-step “mark received” action: it must be received through a GRN.

Every foreign GRN reads the Forex Desk board for that GRN date, using the existing AP basis and carry-forward window. It stores both the original foreign amounts and the converted property-currency values. Stock movements and the GRN accrual use the converted local unit cost; the FX rate and the board's actual date/basis are retained on the GRN. A carried-forward rate is visible as such. A missing rate inside the configured window stops the receipt before stock or AP is changed.

The matched supplier bill must use the PO's currency. Its amount is translated using the board for the **invoice date** (even when direct-bill policy is set to use business date). Matching compares the supplier-currency subtotal and tax represented by received goods to the invoice, then calculates the AP review threshold on the GRN amount revalued at the invoice-date rate:

- **Receipt-to-invoice FX movement** = GRN foreign subtotal/tax at the invoice-date rate − the local GRN accrual at each receipt-date rate. A positive amount is an exchange loss; negative is a gain. When the bill posts, this is posted separately to the Exchange Gain / Loss system account.
- **Price/tax variance** = supplier invoice local total − the received foreign amount revalued at invoice date. This alone is tested against AP tolerance and may put the bill on hold; it is posted to Purchase Price Variance after approval.

The two posted movements net to the difference between the invoice-date payable and receipt-date AP accrual, without confusing FX movement with a supplier price/tax dispute. Paying the bill later still uses the existing AP settlement-date FX process.

The first G4 flow is the manual PO form. RFQ quote entry and comparative sheets still require home-currency quotes; RFQ awards remain home-currency POs until cross-currency quote submission/comparison is designed.

## What this release deliberately does not do

- **RFQ quote currency.** The RFQ portal and comparative quote sheet still require suppliers to quote in the property's currency, so RFQ awards remain home-currency POs. Foreign-currency POs are raised through the manual PO form; cross-currency RFQ comparison and foreign-currency quote submission are a separate increment.
- **A separate foreign-currency bank subledger.** The payment journal and cash book carry the GHS
  equivalent of what left the bank; the transfer's original currency and amount stay on the payment for
  its audit trail and remittance. Bank-account revaluation and foreign-currency bank reconciliation are
  a separate treasury increment.
- **Unrealised FX gain/loss posting** on open bills. The ageing card reports it; period-end valuation is
  deliberately not automatic.

## Ghana notes

The board is the Bank of Ghana reference rate as the desk records it, and the `buy` side is what a licensed
bank or forex bureau would pay a guest — money changing being a VAT-exempt financial service, no VAT,
NHIL, GETFund or tourism levy is deducted on the forex desk itself. Buying foreign currency needs a Bank
of Ghana forex bureau licence, or an agency with a bank; payables reads the board and records the
bank's actual remittance. WHT on a non-resident supplier remains 20% in GHS; it is withheld separately
from the foreign amount sent to the supplier.
A Sunday-dated or public-holiday-dated invoice still has to be booked, which is what
`forex_ap_stale_days` exists for — and a carried-forward rate is badged as such on the bill rather than
presented as that day's board.

## Verification

`deploy/dev/audit/ap_fx.php` — 85 checks: the four settings and both basis/date modes; the per-line
rounding, the tax conversion and the three refusals at the service layer; a real euro bill through
`expenseBill()` with its journal, its note and all four model readers; a cedi bill proving the untouched
path; debit-note inheritance and the third-currency refusal; the exposure arithmetic before and after a
part payment; the seven columns; and the four screens over HTTP. Everything runs inside a transaction
that is rolled back, and the harness measures the **rise** it causes in the exposure rather than an
absolute, so it passes on a database that already carries foreign bills.

`deploy/dev/audit/ap_fx_flow.py` — 43 checks: the invoice story walked through the real form and
`ApController::store()`.

`deploy/dev/audit/ap_fx_pay.php` — 98 checks: board and deal rate provenance, WHT-aware cash transfer,
foreign allocation and same-currency restrictions, no on-account remainder, realised gain and loss
journals balanced against Accounts Payable, the home-currency journal unchanged, the supplier statement,
the tax certificate side, controls, refusal paths and exposure.

`deploy/dev/audit/ap_fx_pay_flow.py` — 35 checks: the actual payment form obtains the rate for the
payment date, posts through `ApController::payStore()`, shows the payment in the register, supplier
statement and remittance, separates realised from unrealised movement, cancels through the ordinary
AP path and sweeps its own rows and journals from the disposable demo.

`deploy/dev/audit/procurement_fx.php` — **37/37 checks passed on 21 Oct 2026**. The rollback-only
transaction verifies PO-date valuation, receipt-date GRN/stock/AP values (including rejected goods),
invoice-date matched-bill conversion and quote endpoints (including the stale-response guard), separate FX and price/tax ledger treatment,
AP hold behavior, home-currency compatibility, and rendered PO, GRN and AP screens plus AP register. Test records and
Forex Desk setting changes are rolled back.
