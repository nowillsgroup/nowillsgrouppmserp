<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class RateOverride extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date_from' => 'date', 'date_to' => 'date', 'stop_sell' => 'boolean', 'is_fixed' => 'boolean'];
    }

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
