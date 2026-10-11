<?php

namespace App\Services\Payments;

use App\Models\OnlinePayment;
use App\Services\MessageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * v7.37 – Mobile Money collections (MTN MoMo / Telecel Cash / AirtelTigo Money):
 * request-to-pay — the customer gets a prompt on their phone and approves it;
 * no redirect, no hosted checkout. Completion arrives by webhook
 * (POST /webhooks/pay/momo/{HOTELCODE}) or by pressing "Check status"
 * (verify() below polls the provider).
 *
 * Transport: with a `momo` secret configured (developer console → secrets) the
 * class talks to the provider API ({momo_base_url} settings) over signed JSON —
 * see docs/MOMO-GATEWAY.md. Without a secret it runs the built-in simulator:
 * the request pends and the next status check approves it (txn SIMMOMO-…),
 * which is exactly how the flow looks with a real network.
 */
class MoMoGateway extends Gateway implements CollectGateway
{
    public function key(): string
    {
        return 'momo';
    }

    /** Collections do not use hosted checkout. */
    public function checkout(OnlinePayment $p, string $reference, string $returnUrl, string $cancelUrl): array
    {
        throw new RuntimeException('Mobile Money collections do not use hosted checkout — call collect().');
    }

    public function collect(OnlinePayment $p, string $phone, string $network): array
    {
        $ref = 'MOMO-'.strtoupper(substr(hash('sha1', $p->token.microtime()), 0, 12));
        if ($this->sim()) {
            return ['state' => 'pending', 'ref' => $ref,
                'message' => 'Prompt sent to '.$phone.' ('.$network.', simulator) – approve on the phone or press Check status.'];
        }
        $body = [
            'amount' => (float) $p->amount, 'currency' => $p->currency, 'phone' => $phone,
            'network' => $network, 'reference' => $ref, 'external_id' => $p->pay_no,
            'callback_url' => route('pay.webhook', ['provider' => 'momo', 'code' => $p->hotel->code]),
        ];
        $res = $this->request('POST', '/v1/collections', $body);
        if (! $res->successful()) {
            return ['state' => 'failed', 'ref' => $ref, 'message' => 'Provider returned HTTP '.$res->status().': '.mb_substr($res->body(), 0, 150)];
        }
        $j = $res->json();

        return ['state' => in_array($j['status'] ?? '', ['pending', 'processing'], true) ? 'pending' : (($j['status'] ?? '') === 'successful' ? 'paid' : 'failed'),
            'ref' => $ref, 'message' => mb_substr((string) ($j['message'] ?? 'Collection requested.'), 0, 190)];
    }

    /** Status poll — powers the "Check status" button (handleReturn → verify). */
    public function verify(OnlinePayment $p, array $query): array
    {
        if ($this->sim()) {
            $amount = (float) $p->amount;

            return ['state' => 'paid', 'amount' => $amount, 'currency' => $p->currency,
                'payment_id' => 'SIMMOMO-'.strtoupper(substr(hash('sha1', $p->provider_ref ?? $p->token), 0, 10)),
                'fee' => 0.0, 'channel' => 'momo', 'message' => null];
        }
        $res = $this->request('GET', '/v1/collections/'.rawurlencode((string) $p->provider_ref));
        if (! $res->successful()) {
            return ['state' => 'pending', 'message' => 'Status check returned HTTP '.$res->status().'.'];
        }
        $j = $res->json();
        $state = match ($j['status'] ?? '') {
            'successful' => 'paid', 'failed', 'rejected', 'expired' => 'failed', default => 'pending',
        };

        return ['state' => $state, 'amount' => (float) ($j['amount'] ?? $p->amount), 'currency' => (string) ($j['currency'] ?? $p->currency),
            'payment_id' => (string) ($j['transaction_id'] ?? $p->provider_ref), 'fee' => isset($j['fee']) ? (float) $j['fee'] : null,
            'channel' => 'momo', 'message' => isset($j['message']) ? mb_substr((string) $j['message'], 0, 190) : null];
    }

    /** Webhook from the provider (or the local simulator): HMAC over the raw body. */
    public function webhook(Request $request): ?array
    {
        $body = (string) $request->getContent();
        $sig = (string) $request->header('X-Provider-Signature', '');
        $expect = 'sha256='.hash_hmac('sha256', $body, (string) (MessageService::secret('momo') ?: config('app.key')));
        if (! hash_equals($expect, $sig)) {
            throw new SignatureException('Invalid collection webhook signature.');
        }
        $j = json_decode($body, true) ?: [];
        if (empty($j['reference'])) {
            return null;
        }
        $state = match ($j['status'] ?? '') {
            'successful' => 'paid', 'failed', 'rejected', 'expired' => 'failed', default => 'pending',
        };

        return ['ref' => (string) $j['reference'], 'event' => 'collection.update',
            'event_id' => (string) ($j['event_id'] ?? hash('sha1', $body)),
            'result' => ['state' => $state, 'amount' => (float) ($j['amount'] ?? 0), 'currency' => (string) ($j['currency'] ?? 'GHS'),
                'payment_id' => (string) ($j['transaction_id'] ?? $j['reference']), 'fee' => isset($j['fee']) ? (float) $j['fee'] : null,
                'channel' => 'momo', 'message' => isset($j['message']) ? mb_substr((string) $j['message'], 0, 190) : null]];
    }

    public function refund(OnlinePayment $p, float $amount): array
    {
        if ($this->sim()) {
            return ['ref' => 'SIMMOMO-RF-'.strtoupper(substr(hash('sha1', $p->token.microtime()), 0, 8)), 'status' => 'processed'];
        }
        $res = $this->request('POST', '/v1/collections/'.rawurlencode((string) $p->provider_ref).'/refund', ['amount' => $amount]);
        $j = rescue(fn () => $res->json(), [], false);

        return ['ref' => (string) ($j['transaction_id'] ?? $p->provider_ref), 'status' => $res->successful() ? 'processed' : 'failed'];
    }

    /** No provider key configured → built-in simulator. */
    protected function sim(): bool
    {
        return ! MessageService::secret('momo');
    }

    protected function request(string $method, string $path, array $json = [])
    {
        $base = rtrim((string) setting('momo_base_url', ''), '/');
        if ($base === '') {
            throw new RuntimeException('Mobile Money gateway URL not configured (settings → momo_base_url).');
        }
        $body = $json ? json_encode($json, JSON_UNESCAPED_SLASHES) : '';
        $ts = (string) time();

        return Http::timeout(20)->withHeaders([
            'Authorization' => 'Bearer '.MessageService::secret('momo'),
            'X-Nowills-Timestamp' => $ts,
            'X-Nowills-Signature' => 'sha256='.hash_hmac('sha256', $ts.$body, (string) MessageService::secret('momo')),
            'Accept' => 'application/json', 'User-Agent' => 'NowillsPMS-Payments/1.0',
        ])->withBody($body, 'application/json')->send($method, $base.$path);
    }
}
