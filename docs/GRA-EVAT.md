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

## Going live

1. Joint user-acceptance testing with GRA → they issue the endpoint URL,
   security key and E-VAT user name (Settings → GRA E-VAT).
2. Set **Connection = GRA E-VAT API** and fill the credentials.
3. The message dialect is unchanged — no code swap needed.
