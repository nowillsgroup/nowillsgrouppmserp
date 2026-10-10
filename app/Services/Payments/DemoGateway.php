<?php

namespace App\Services\Payments;

use App\Models\OnlinePayment;
use Illuminate\Http\Request;

/**
 * Built-in test gateway: a local "hosted checkout" page (/pay/{token}/demo) that approves or declines.
 * The result is HMAC-signed with the app key so it cannot be forged from the browser. No real money moves.
 */
class DemoGateway extends Gateway
{
    public function key(): string
    {
        return 'demo';
    }

    public static function sign(OnlinePayment $p, string $ref, string $result, string $channel): string
    {
        return hash_hmac('sha256', "{$p->token}|{$ref}|{$result}|{$channel}", (string) config('app.key'));
    }

    public function checkout(OnlinePayment $p, string $reference, string $returnUrl, string $cancelUrl): array
    {
        return ['url' => route('pay.demo', ['token' => $p->token, 'ref' => $reference]), 'ref' => $reference];
    }

    public function verify(OnlinePayment $p, array $query): array
    {
        $ref = (string) ($query['ref'] ?? '');
        $result = (string) ($query['result'] ?? '');
        $channel = (string) ($query['channel'] ?? 'card');
        if ($ref !== $p->provider_ref || ! hash_equals(self::sign($p, $ref, $result, $channel), (string) ($query['sig'] ?? ''))) {
            return ['state' => 'pending', 'message' => 'Unverified return – ignored.'];
        }
        if ($result !== 'success') {
            return ['state' => 'failed', 'message' => $result === 'insufficient' ? 'Declined: insufficient funds.' : 'Declined by the (test) issuer.'];
        }
        $amount = (float) $p->amount;

        return ['state' => 'paid', 'amount' => $amount, 'currency' => $p->currency, 'payment_id' => 'DEMO-'.strtoupper(substr(hash('sha1', $ref), 0, 10)),
            'fee' => round($amount * 0.0195, 2), 'channel' => in_array($channel, ['card', 'mobile_money', 'upi']) ? $channel : 'card', 'message' => null];
    }

    public function webhook(Request $request): ?array
    {
        return null; // the test gateway completes on return only
    }

    public function refund(OnlinePayment $p, float $amount): array
    {
        return ['ref' => 'DEMO-RF-'.strtoupper(substr(hash('sha1', $p->token.microtime()), 0, 8)), 'status' => 'processed'];
    }
}
