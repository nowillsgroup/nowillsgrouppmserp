<?php

namespace App\Services\Payments;

use App\Models\OnlinePayment;

/**
 * v7.37 – phone-prompt payment collection (request-to-pay): the customer
 * approves a charge on their mobile phone. Implemented by MoMoGateway.
 */
interface CollectGateway
{
    /**
     * Request a collection from the customer's phone.
     *
     * @return array{state: string, ref: string, message: ?string}
     */
    public function collect(OnlinePayment $p, string $phone, string $network): array;
}
