<?php

namespace App\Services\Payments;

use App\Models\OnlinePayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A hosted-checkout payment gateway. The guest is redirected to the gateway's page, so card / wallet
 * details never touch this server. Every result is confirmed server-to-server (verify) or by a signed
 * webhook – query-string values from the browser are never trusted on their own.
 *
 * verify() / webhook() results: ['state' => paid|failed|pending, 'amount' => float|null (major units),
 *   'currency' => ISO, 'payment_id' => gateway payment id, 'fee' => float|null, 'channel' => string|null, 'message' => string|null]
 */
abstract class Gateway
{
    /** Currencies without minor units. */
    protected const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    abstract public function key(): string;

    /** @return array{url: string, ref: string} */
    abstract public function checkout(OnlinePayment $p, string $reference, string $returnUrl, string $cancelUrl): array;

    abstract public function verify(OnlinePayment $p, array $query): array;

    /**
     * Parse + authenticate a webhook. Throws SignatureException when the signature is missing or wrong.
     *
     * @return array{event_id: string, event: string, ref: ?string, result: ?array}|null null = not an event we act on
     */
    abstract public function webhook(Request $request): ?array;

    /** @return array{ref: ?string, status: string} status processed|pending */
    abstract public function refund(OnlinePayment $p, float $amount): array;

    public static function minor(float $amount, string $currency): int
    {
        return (int) round($amount * (in_array(strtoupper($currency), self::ZERO_DECIMAL) ? 1 : 100));
    }

    public static function major(int|float|string|null $minor, string $currency): ?float
    {
        return $minor === null ? null : round((float) $minor / (in_array(strtoupper($currency), self::ZERO_DECIMAL) ? 1 : 100), 2);
    }

    protected function http()
    {
        return Http::timeout(20)->acceptJson();
    }

    /** Turn a failed gateway response into a readable error. */
    protected function fail($res, string $what): never
    {
        $j = $res->json() ?: [];
        $msg = $j['message'] ?? $j['error']['message'] ?? $j['error']['description'] ?? ('HTTP '.$res->status());
        throw new RuntimeException(ucfirst($this->key())." {$what} failed: ".mb_substr((string) $msg, 0, 180));
    }

    protected static function secret(string $key): ?string
    {
        return \App\Services\MessageService::secret($key);
    }
}
