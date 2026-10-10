<?php

namespace App\Services;

use RuntimeException;

/** v3.3 the vehicle and / or driver is already busy – the dispatcher may override. */
class TransportClash extends RuntimeException
{
    public function __construct(public array $clashes)
    {
        $parts = [];
        if ($clashes['vehicle'] ?? []) {
            $parts[] = 'Vehicle busy: '.implode('; ', $clashes['vehicle']);
        }
        if ($clashes['driver'] ?? []) {
            $parts[] = 'Driver busy: '.implode('; ', $clashes['driver']);
        }
        parent::__construct(implode(' · ', $parts).'.');
    }
}
