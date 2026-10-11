# Mobile Money collections (v7.37)

Request-to-pay settlements: the cashier sends a payment prompt to the guest's
phone (MTN MoMo / Telecel Cash / AirtelTigo Money); the guest approves on the
phone; the payment posts to the folio automatically — part of a split
settlement or the whole bill.

## Flow

1. Folio → **Request MoMo** (or `POST /payments/collect`): amount, network,
   phone. Creates an `OnlinePayment` (`provider=momo`, channel `momo:{network}`)
   and calls `MoMoGateway::collect()`.
2. The provider prompts the phone. Status `pending`.
3. Completion arrives either by **webhook**
   (`POST /webhooks/pay/momo/{HOTELCODE}`, `X-Provider-Signature:
   sha256=HMAC-SHA256(raw body, momo secret)`) or by pressing **Check status**
   on the payment page (`verify()` polls the provider).
4. `OnlinePaymentService::complete()` — idempotent (locked, duplicate events
   ignored, amount/currency checked → `review` on mismatch) — posts the
   payment to the folio exactly like a payment link.

## Transport

`App\Services\Payments\MoMoGateway` (implements `CollectGateway`):

- **Simulator (default):** no `momo` secret configured → the request pends and
  the next status check approves it (`SIMMOMO-…`). Zero-config demos.
- **HTTP provider API:** set the `momo` secret (developer console → secrets)
  and the `momo_base_url` setting. Protocol (signed JSON, same HMAC shape as
  the channel hub):

| Call | Request | Response |
|---|---|---|
| `POST {base}/v1/collections` | `{amount, currency, phone, network, reference, external_id, callback_url}` | `{status: pending\|successful\|failed, transaction_id, message}` |
| `GET {base}/v1/collections/{reference}` | – | `{status, transaction_id, amount, currency, fee, message}` |
| `POST {base}/v1/collections/{reference}/refund` | `{amount}` | `{transaction_id, status}` |
| webhook ← provider | `{reference, status, transaction_id, amount, currency, fee, message, event_id}` | `200` |

Auth: `Authorization: Bearer {secret}` + `X-Nowills-Timestamp` +
`X-Nowills-Signature: sha256=HMAC-SHA256(ts+body, secret)`.

## Sandbox end-to-end

```bash
deploy/dev/node_modules/.bin-local/bun deploy/dev/momo-sim.mjs \
  --port=8092 --secret=devsecret --callback-base=http://127.0.0.1:8000 --auto=3
# settings: MessageService secret "momo" = devsecret · momo_base_url = http://127.0.0.1:8092
```

Verified: collection request → provider prompt → webhook (`HTTP 200`) →
payment `paid` with txn id → folio transaction posted — part of a split
settlement alongside cash/card/cheque payments.

Real network adapters (MTN MoMo Collections / Telecel Cash / AirtelTigo Money
APIs) implement the same three calls — the payload dialect is the only thing
that changes per provider.
