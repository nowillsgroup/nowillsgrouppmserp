<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v4.0 – the stored outcome of a POST made with an Idempotency-Key header (kept 24 h). */
class ApiIdempotencyKey extends Model
{
    use BelongsToHotel;

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'hotel_id'];
}
