# GRA E-VAT live adapter (v7.39)

E-invoicing runs through `EInvoiceService::submit()` with two drivers
(Billing → GRA VAT → Settings → **Connection**):

| Driver | Purpose |
|---|---|
| `sandbox` | Offline VSDC simulator in-process (default) — applies GRA's checks (A08 levy/VAT totals, B16 seller TIN, B19 refund reference), signs the document and returns a demo SDC stamp + QR |
| `gra` | The certified **GRA E-VAT API** over HTTP — `POST {gst_evat_url}` with header `security_key: {gst_evat_key}`, body = the GRA invoice schema (`invoiceNumber`, `sellerTin`, `businessPartnerTin`, `items[]` with `levyAmountA–E`, `vatAmount`, `flag` INVOICE/REFUND, `userName`) |

Responses are normalised from the VSDC dialect `{status, response: {message:
{ysdcid, ysdcrecnum, ysdcintdata, ysdcregsig, ysdcmrc, ysdcmrctim, …},
qr_code}}` — including the `mesaage` spelling some GRA versions return.
Stamps land on the folio (IRN, receipt no., internal data, signature, QR)
and print on the invoice; POS outlet bills go through `PosEvatService` on
the same driver. Failures use the `BACKOFF_MIN` retry ladder
(`php artisan einvoice:retry`).

## Live-adapter e2e (local VSDC protocol simulator)

```
node deploy/dev/vsdc-sim.mjs --port=8094 --key=gra-secret   # --fail-first=1 exercises the retry ladder
```

Point the connection at it: `gst_evat_url=http://127.0.0.1:8094/vsdc/invoice`,
`gst_evat_key=gra-secret`, driver `gra`. Private/loopback endpoints require
**Developer settings → allow private addresses** (or `PMS_CHANNEL_ALLOW_PRIVATE=1`).

Proven (2026-10-11): invoice INV-00071 stamped live (`VSDC-0001-LIVE`,
receipt `2/2 NS`, QR attached); resubmission returned the same stamp with
`info: DUPLICATE`; a REFUND without `reference` was rejected `B19`.

## VAT registration and the Act 1151 test (v7.52)

Whether a property may submit at all is a statutory question, so it is kept
per property rather than in the E-VAT switch. The record lives on `hotels`
and is edited under **Administration → Settings → VAT registration**:

| Column | Meaning |
|---|---|
| `vat_registered` | On the GRA VAT register. Backfilled `true` wherever a `tax_number` was already on file |
| `vat_registered_at` | The date GRA registered it (cleared when the property is taken off the register) |
| `vat_supply_kind` | `services` (default), `goods` or `both` — decides which threshold applies |
| `supports_e_invoicing` | A certified invoicing system is connected to the VSDC |

`GstService::vatRegistration()` applies the **Value Added Tax Act, 2025
(Act 1151)**, in force 1 January 2026:

- a supplier of **services** — a hotel, restaurant or guest house — has **no
  threshold**: registration is mandatory whatever it turns over;
- a supplier of taxable **goods** registers at **GH₵750,000**, raised from
  GH₵200,000 under the repealed Act 870;
- e-invoicing is mandatory for **every** VAT-registered business, with no
  revenue threshold.

Turnover is measured from the property's own taxable base — the same figures a
filed VAT return reports, month by month, closed documents with credit notes
negative plus direct-settled outlet bills (`GstService::monthTaxable()`, proven
equal to `vatReturn()['totals']['taxable']` to the pesewa). Thirteen months are
read as two twelve-month windows, the completed one and the one ending today
with the open month part-counted, and the test takes whichever is larger.
Status is `registered`, `must_register`, `approaching` (within 80% of the
threshold) or `below_threshold`, each with the reason in plain English.
Billing → GRA VAT → Settings shows the whole test on the Registration card.

`EInvoiceService::registrationBlocker()` is the gate, and both directions
matter: an unregistered property must not submit (and must not charge VAT), and
a registered one has to. While it returns a reason, `eligible()` is false so
nothing is queued, `validate()` fails a forced submit naming the reason, the
E-VAT page shows the block and hides every submit control, and outlet bills stop
too. Recording a registration puts documents that were parked on that error back
to `pending` for the five-minute retry task (`registrationChanged()`); taking one
off stops submission but never unstamps — a stamped document cannot be cancelled,
so corrections stay credit notes.

## Going live

1. Joint user-acceptance testing with GRA → they issue the endpoint URL,
   security key and E-VAT user name (Settings → GRA E-VAT).
2. Record the property on the GRA VAT register, with the date and what it
   supplies (Administration → Settings → VAT registration). Until then E-VAT
   will not submit, whichever way the switch is set.
3. Set **Connection = GRA E-VAT API** and fill the credentials.
4. The message dialect is unchanged — no code swap needed.

Proven by `deploy/dev/audit/vat_registration.php` (42 checks, service layer)
and `deploy/dev/audit/vat_registration_flow.py` (41 checks, driven through the
real forms).
