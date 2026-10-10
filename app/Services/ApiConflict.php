<?php

namespace App\Services;

use App\Models\Reservation;
use RuntimeException;

/** 409 – the request clashes with the current state (duplicate external_ref, already cancelled …). */
class ApiConflict extends RuntimeException
{
    public function __construct(string $message, public ?Reservation $reservation = null)
    {
        parent::__construct($message);
    }
}
