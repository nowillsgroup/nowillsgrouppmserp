<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class Floor extends Model
{
    use BelongsToHotel;

    protected $table = 'floors';

    protected $guarded = ['id', 'hotel_id'];

    public function rooms()
    {
        return $this->hasMany(Room::class);
    }
}
