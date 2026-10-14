# Multi-currency supplier invoicing — v7.53

**Scope:** a supplier's invoice that arrives in the supplier's own currency — an OTA billing commission
from its Amsterdam entity in euros, an international operator billing a management fee in dollars — and
the path it takes into Accounts payable.
**Result:** a bill can now be entered in the currency the supplier billed in. Every line is converted at
the forex board and stored in the property's own currency, so the ledger, the budget check, ageing,
allocations, withholding tax and payments all behave exactly as they did before; the supplier's own
document is kept on the header beside it, and Accounts payable › AP Ageing reports what the open foreign
bills would cost to settle at the board as it stands. A bill that cannot be converted at a rate somebody
chose is refused in plain English rather than booked at a guess.
**Evidence scripts:** `deploy/dev/audit/ap_fx.php` (84 checks — the policy, the conversion maths, the
refusals, the exposure, the columns and the screens), `deploy/dev/audit/ap_fx_flow.py` (43 checks — the
same thing walked through the real form, the real controller and the real flash messages).

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
| `forex_ap_rate_date` | `document` | `document` = the board for the invoice date (the transaction date, which is what accounting wants); `business` = today's board whatever the invoice says |

All four are edited under **Foreign Guests › Settings › Forex desk**, beside the channel policy from
v7.50 that they mirror. `ForexService::apPolicy()` resolves them, `ForexService::apBoard()` returns every
currency a bill could be converted at (which is what the entry form's picker is built from), and
`ForexService::boardRate()` does the lookup with carry-forward.

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
3. **A bill matched to a goods receipt** — *"A bill matched to a goods receipt is valued at the receipt,
   which is in GHS. Enter it in GHS, or record the supplier's own document as a direct bill."* A
   three-way match compares the bill to the receipt to the purchase order; converting one leg of it would
   break the match and the stock valuation with it. Purchase-order currency is the next increment, not
   this one.
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

## Exposure: reported, never posted

`ApService::fxExposure()` groups every open foreign bill by currency and reports, for each: how many bills
are open, what is still owed in the supplier's currency, what the ledger carries in cedis, the board rate
now, what settling it would cost at that board, and the movement between the two.

On the demo database at the 14 October board:

| | Open | Still owed | Booked in GH₵ | Board now | Cost to settle | Movement |
| --- | --- | --- | --- | --- | --- | --- |
| EUR | 1 | 4,180.55 | 56,398.55 | 13.6184 | 56,932.40 | **+533.85** |
| USD | 1 | 3,295.28 | 38,376.23 | 11.6200 | 38,291.15 | **−85.08** |

This is **reported, not posted**. An unrealised revaluation belongs to the period end and to a decision
about which rate the closing uses; a payables screen quietly writing FX gain and loss journals would be
worse than no number at all. There is no FX gain/loss journal in this release.

## What this release deliberately does not do

- **Purchase-order currency.** A PO, its receipt and its matched bill stay in the property's currency
  (refusal 3 above). A supplier who bills goods in a foreign currency is recorded as a direct bill, with
  the receipt matched by hand.
- **Payments in foreign currency.** A payment is still recorded in cedis; the dollar account and the
  bank's own conversion are the treasury side of this, not payables.
- **Deal rates.** The board only. A negotiated rate is a treasury conversation and a different screen.
- **FX gain/loss posting**, as above.
- **Withholding tax on a non-resident supplier** is unchanged and separate: `suppliers.wht_*` and the
  WHT block on a payment still work in cedis on the converted figures, which is what GRA wants.

## Ghana notes

The board is the Bank of Ghana reference rate as the desk records it, and the `buy` side is what a licensed
bank or forex bureau would pay a guest — money changing being a VAT-exempt financial service, no VAT,
NHIL, GETFund or tourism levy is deducted on the forex desk itself. Buying foreign currency needs a Bank
of Ghana forex bureau licence, or an agency with a bank; the payables side only ever *reads* the board.
A Sunday-dated or public-holiday-dated invoice still has to be booked, which is what
`forex_ap_stale_days` exists for — and a carried-forward rate is badged as such on the bill rather than
presented as that day's board.

## Verification

`deploy/dev/audit/ap_fx.php` — 84 checks: the four settings and both basis/date modes; the per-line
rounding, the tax conversion and the three refusals at the service layer; a real euro bill through
`expenseBill()` with its journal, its note and all four model readers; a cedi bill proving the untouched
path; debit-note inheritance and the third-currency refusal; the exposure arithmetic before and after a
part payment; the seven columns; and the four screens over HTTP. Everything runs inside a transaction
that is rolled back, and the harness measures the **rise** it causes in the exposure rather than an
absolute, so it passes on a database that already carries foreign bills.

`deploy/dev/audit/ap_fx_flow.py` — 43 checks: the same story walked through the real form and
`ApController::store()` — the picker quoting the board, a USD bill posting and landing on the bill page
with the right flash, the register and the ageing exposure following it, the identical form with the
currency left alone producing no fx block at all, the three refusals arriving as sentences, the policy
switched off and back on through Foreign Guests › Settings without moving any other setting on that form,
and finally its own documents cancelled and swept so the demo is left exactly as it was.
