<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** On-the-books rooms/revenue for a future stay date, as recorded at the end of a business day. */
class RmSnapshot extends Model
{
    use BelongsToHotel;

    public $timestamps = false;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['snapshot_date' => 'date', 'stay_date' => 'date', 'revenue' => 'decimal:2'];
    }
}
