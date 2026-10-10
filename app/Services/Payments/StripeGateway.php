<?php

namespace App\Services\Payments;

use App\Models\OnlinePayment;
use Illuminate\Http\Request;
use RuntimeException;

/** Stripe Checkout (hosted). Cards, Apple / Google Pay and local methods – worldwide. */
class StripeGateway extends Gateway
{
    protected const API = 'https://api.stripe.com/v1';

    public const TOLERANCE = 300; // seconds a webhook timestamp may be old (replay protection)

    public function key(): string
    {
        return 'stripe';
    }

    protected function sk(): string
    {
        return self::secret('pay_stripe_secret') ?: throw new RuntimeException('Stripe secret key is not set.');
    }

    protected function api()
    {
        return $this->http()->asForm()->withBasicAuth($this->sk(), '');
    }

    public function checkout(OnlinePayment $p, string $reference, string $returnUrl, string $cancelUrl): array
    {
        $body = [
            'mode' => 'payment', 'client_reference_id' => $reference,
            'success_url' => $returnUrl.(str_contains($returnUrl, '?') ? '&' : '?').'session_id={CHECKOUT_SESSION_ID}', 'cancel_url' => $cancelUrl,
            'line_items' => [['quantity' => 1, 'price_data' => ['currency' => strtolower($p->currency), 'unit_amount' => self::minor((float) $p->amount, $p->currency),
                'product_data' => ['name' => mb_substr(hotel()->name.' – '.$p->description, 0, 120)]]]],
            'metadata' => ['pay_no' => $p->pay_no, 'hotel' => hotel()->code, 'reference' => $reference],
            'payment_intent_data' => ['metadata' => ['pay_no' => $p->pay_no, 'hotel' => hotel()->code]],
        ];
        if ($p->payer_email) {
            $body['customer_email'] = $p->payer_email;
        }
        if ($p->expires_at && $p->expires_at->gt(now()->addMinutes(31)) && $p->expires_at->lt(now()->addHours(23))) {
            $body['expires_at'] = $p->expires_at->timestamp;
        }
        $res = $this->api()->post(self::API.'/checkout/sessions', $body);
        if (! $res->successful() || ! $res->json('url')) {
            $this->fail($res, 'checkout');
        }

        return ['url' => $res->json('url'), 'ref' => $res->json('id')];
    }

    protected function result(array $s): array
    {
        $cur = strtoupper($s['currency'] ?? '');
        $pi = $s['payment_intent'] ?? null;

        return ['state' => ($s['payment_status'] ?? '') === 'paid' ? 'paid' : (($s['status'] ?? '') === 'expired' ? 'failed' : 'pending'),
            'amount' => self::major($s['amount_total'] ?? null, $cur), 'currency' => $cur,
            'payment_id' => is_array($pi) ? ($pi['id'] ?? null) : $pi, 'fee' => null,
            'channel' => is_array($pi) ? ($pi['payment_method_types'][0] ?? 'card') : 'card', 'message' => ($s['status'] ?? '') === 'expired' ? 'Checkout session expired.' : null];
    }

    public function verify(OnlinePayment $p, array $query): array
    {
        $res = $this->api()->get(self::API.'/checkout/sessions/'.rawurlencode((string) $p->provider_ref), ['expand' => ['payment_intent']]);
        if (! $res->successful()) {
            $this->fail($res, 'verification');
        }

        return $this->result($res->json() ?: []);
    }

    public function webhook(Request $request): ?array
    {
        $secret = self::secret('pay_stripe_webhook_secret') ?: throw new SignatureException('Stripe webhook signing secret is not set.');
        $parts = [];
        foreach (explode(',', (string) $request->header('Stripe-Signature')) as $kv) {
            [$k, $v] = array_pad(explode('=', trim($kv), 2), 2, '');
            $parts[$k][] = $v;
        }
        $t = (int) ($parts['t'][0] ?? 0);
        $expected = hash_hmac('sha256', $t.'.'.$request->getContent(), $secret);
        $ok = $t > 0 && collect($parts['v1'] ?? [])->contains(fn ($v) => hash_equals($expected, $v));
        if (! $ok || abs(time() - $t) > self::TOLERANCE) {
            throw new SignatureException($ok ? 'Stripe webhook timestamp outside the tolerance.' : 'Invalid Stripe signature.');
        }
        $e = $request->json()->all();
        $o = $e['data']['object'] ?? [];
        $acts = ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed', 'checkout.session.expired'];

        return ['event_id' => (string) ($e['id'] ?? sha1($request->getContent())), 'event' => $e['type'] ?? 'unknown', 'ref' => $o['id'] ?? null,
            'result' => in_array($e['type'] ?? '', $acts) ? $this->result($o) : null];
    }

    public function refund(OnlinePayment $p, float $amount): array
    {
        if (! $p->provider_payment_id) {
            throw new RuntimeException('Stripe payment intent is unknown – refund it in the Stripe dashboard.');
        }
        $res = $this->api()->post(self::API.'/refunds', ['payment_intent' => $p->provider_payment_id, 'amount' => self::minor($amount, $p->currency)]);
        if (! $res->successful()) {
            $this->fail($res, 'refund');
        }

        return ['ref' => $res->json('id'), 'status' => $res->json('status') === 'succeeded' ? 'processed' : 'pending'];
    }
}
