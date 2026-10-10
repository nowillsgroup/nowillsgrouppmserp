<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** A server-side receipt for an operation replayed from a browser outbox. */
class OfflineSyncOperation extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'applied_at' => 'datetime',
        ];
    }
}
