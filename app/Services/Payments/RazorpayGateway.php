<?php

namespace App\Services\Payments;

use App\Models\OnlinePayment;
use Illuminate\Http\Request;
use RuntimeException;

/** Razorpay Payment Links. Cards, UPI, netbanking, wallets – INR. */
class RazorpayGateway extends Gateway
{
    protected const API = 'https://api.razorpay.com/v1';

    public function key(): string
    {
        return 'razorpay';
    }

    protected function api()
    {
        $id = setting('pay_razorpay_key_id');
        $secret = self::secret('pay_razorpay_key_secret');
        if (! $id || ! $secret) {
            throw new RuntimeException('Razorpay key ID / secret are not set.');
        }

        return $this->http()->withBasicAuth($id, $secret);
    }

    public function checkout(OnlinePayment $p, string $reference, string $returnUrl, string $cancelUrl): array
    {
        $body = [
            'amount' => self::minor((float) $p->amount, $p->currency), 'currency' => $p->currency, 'reference_id' => $reference,
            'description' => mb_substr($p->description, 0, 200), 'customer' => array_filter(['name' => $p->payer_name, 'email' => $p->payer_email, 'contact' => $p->payer_phone]),
            'notify' => ['sms' => false, 'email' => false], 'reminder_enable' => false,
            'notes' => ['pay_no' => $p->pay_no, 'hotel' => hotel()->code], 'callback_url' => $returnUrl, 'callback_method' => 'get',
        ];
        if ($p->expires_at && $p->expires_at->gt(now()->addMinutes(16))) {
            $body['expire_by'] = $p->expires_at->timestamp;
        }
        $res = $this->api()->post(self::API.'/payment_links', $body);
        if (! $res->successful() || ! $res->json('short_url')) {
            $this->fail($res, 'checkout');
        }

        return ['url' => $res->json('short_url'), 'ref' => $res->json('id')];
    }

    public function verify(OnlinePayment $p, array $query): array
    {
        $res = $this->api()->get(self::API.'/payment_links/'.rawurlencode((string) $p->provider_ref));
        if (! $res->successful()) {
            $this->fail($res, 'verification');
        }
        $l = $res->json() ?: [];
        $cur = strtoupper($l['currency'] ?? '');
        $pay = collect($l['payments'] ?? [])->firstWhere('status', 'captured') ?? collect($l['payments'] ?? [])->last();
        $state = match ($l['status'] ?? '') {
            'paid' => 'paid', 'cancelled', 'expired' => 'failed', default => 'pending',
        };

        return ['state' => $state, 'amount' => self::major($l['amount_paid'] ?? null, $cur), 'currency' => $cur, 'payment_id' => $pay['payment_id'] ?? null,
            'fee' => null, 'channel' => $pay['method'] ?? null, 'message' => $state === 'failed' ? 'Payment link '.$l['status'].'.' : null];
    }

    public function webhook(Request $request): ?array
    {
        $secret = self::secret('pay_razorpay_webhook_secret') ?: throw new SignatureException('Razorpay webhook secret is not set.');
        $sig = (string) $request->header('X-Razorpay-Signature');
        if ($sig === '' || ! hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $sig)) {
            throw new SignatureException('Invalid Razorpay signature.');
        }
        $e = $request->json()->all();
        $id = (string) ($request->header('X-Razorpay-Event-Id') ?: sha1($request->getContent()));
        if (($e['event'] ?? '') !== 'payment_link.paid') {
            return ['event_id' => $id, 'event' => $e['event'] ?? 'unknown', 'ref' => $e['payload']['payment_link']['entity']['id'] ?? null, 'result' => null];
        }
        $l = $e['payload']['payment_link']['entity'] ?? [];
        $pay = $e['payload']['payment']['entity'] ?? [];
        $cur = strtoupper($l['currency'] ?? '');

        return ['event_id' => $id, 'event' => 'payment_link.paid', 'ref' => $l['id'] ?? null, 'result' => [
            'state' => ($l['status'] ?? '') === 'paid' ? 'paid' : 'pending', 'amount' => self::major($l['amount_paid'] ?? null, $cur), 'currency' => $cur,
            'payment_id' => $pay['id'] ?? null, 'fee' => self::major($pay['fee'] ?? null, $cur), 'channel' => $pay['method'] ?? null, 'message' => null,
        ]];
    }

    public function refund(OnlinePayment $p, float $amount): array
    {
        if (! $p->provider_payment_id) {
            throw new RuntimeException('Razorpay payment id is unknown – refund it in the Razorpay dashboard.');
        }
        $res = $this->api()->post(self::API.'/payments/'.rawurlencode($p->provider_payment_id).'/refund', ['amount' => self::minor($amount, $p->currency)]);
        if (! $res->successful()) {
            $this->fail($res, 'refund');
        }

        return ['ref' => $res->json('id'), 'status' => $res->json('status') === 'processed' ? 'processed' : 'pending'];
    }
}
