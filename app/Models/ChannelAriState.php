<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** Last ARI values acknowledged by a channel for one room type & night. */
class ChannelAriState extends Model
{
    use BelongsToHotel;

    public $timestamps = false;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'stop_sell' => 'boolean', 'cta' => 'boolean', 'rates' => 'array', 'pushed_at' => 'datetime'];
    }
}
