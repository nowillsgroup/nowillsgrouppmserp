<?php

namespace App\Services\Payments;

use App\Models\OnlinePayment;
use Illuminate\Http\Request;
use RuntimeException;

/** Paystack Standard (redirect). Cards, mobile money, bank – GHS, NGN, KES, ZAR, USD. */
class PaystackGateway extends Gateway
{
    protected const API = 'https://api.paystack.co';

    public function key(): string
    {
        return 'paystack';
    }

    protected function sk(): string
    {
        return self::secret('pay_paystack_secret') ?: throw new RuntimeException('Paystack secret key is not set.');
    }

    public function checkout(OnlinePayment $p, string $reference, string $returnUrl, string $cancelUrl): array
    {
        $res = $this->http()->withToken($this->sk())->post(self::API.'/transaction/initialize', [
            'email' => $p->payer_email ?: 'guest+'.$p->pay_no.'@'.parse_url(config('app.url'), PHP_URL_HOST),
            'amount' => self::minor((float) $p->amount, $p->currency), 'currency' => $p->currency,
            'reference' => $reference, 'callback_url' => $returnUrl,
            'metadata' => ['pay_no' => $p->pay_no, 'hotel' => hotel()->code, 'cancel_action' => $cancelUrl],
        ]);
        if (! $res->successful() || ! $res->json('status') || ! $res->json('data.authorization_url')) {
            $this->fail($res, 'checkout');
        }

        return ['url' => $res->json('data.authorization_url'), 'ref' => $reference];
    }

    protected function result(array $d): array
    {
        $cur = strtoupper($d['currency'] ?? '');
        $state = match ($d['status'] ?? '') {
            'success' => 'paid', 'failed', 'reversed' => 'failed', default => 'pending',
        };

        return ['state' => $state, 'amount' => self::major($d['amount'] ?? null, $cur), 'currency' => $cur, 'payment_id' => isset($d['id']) ? (string) $d['id'] : null,
            'fee' => self::major($d['fees'] ?? null, $cur), 'channel' => $d['channel'] ?? null, 'message' => $d['gateway_response'] ?? null];
    }

    public function verify(OnlinePayment $p, array $query): array
    {
        $res = $this->http()->withToken($this->sk())->get(self::API.'/transaction/verify/'.rawurlencode((string) $p->provider_ref));
        if (! $res->successful() || ! $res->json('status')) {
            $this->fail($res, 'verification');
        }
        $d = $res->json('data') ?: [];
        if (($d['reference'] ?? null) !== $p->provider_ref) {
            return ['state' => 'pending', 'message' => 'Reference mismatch.'];
        }

        return $this->result($d);
    }

    public function webhook(Request $request): ?array
    {
        $sig = (string) $request->header('x-paystack-signature');
        if ($sig === '' || ! hash_equals(hash_hmac('sha512', $request->getContent(), $this->sk()), $sig)) {
            throw new SignatureException('Invalid Paystack signature.');
        }
        $e = $request->json()->all();
        $d = $e['data'] ?? [];
        if (($e['event'] ?? '') !== 'charge.success') {
            return ['event_id' => ($e['event'] ?? 'event').':'.($d['id'] ?? $d['reference'] ?? sha1($request->getContent())), 'event' => $e['event'] ?? 'unknown', 'ref' => $d['reference'] ?? null, 'result' => null];
        }

        return ['event_id' => 'charge.success:'.($d['id'] ?? $d['reference'] ?? ''), 'event' => 'charge.success', 'ref' => $d['reference'] ?? null, 'result' => $this->result($d)];
    }

    public function refund(OnlinePayment $p, float $amount): array
    {
        $res = $this->http()->withToken($this->sk())->post(self::API.'/refund', [
            'transaction' => $p->provider_payment_id ?: $p->provider_ref, 'amount' => self::minor($amount, $p->currency),
        ]);
        if (! $res->successful() || ! $res->json('status')) {
            $this->fail($res, 'refund');
        }

        return ['ref' => isset($res->json('data')['id']) ? (string) $res->json('data.id') : null, 'status' => $res->json('data.status') === 'processed' ? 'processed' : 'pending'];
    }
}
