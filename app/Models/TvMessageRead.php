<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v7.1 Read receipt: a TV showed the message and the guest pressed OK. */
class TvMessageRead extends Model
{
    use BelongsToHotel;

    public $timestamps = false;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function device()
    {
        return $this->belongsTo(TvDevice::class, 'tv_device_id');
    }
}
