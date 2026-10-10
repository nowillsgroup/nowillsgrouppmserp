<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class RoomAmenity extends Model
{
    use BelongsToHotel;

    protected $table = 'room_amenities';

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return [
            'show_on_desk' => 'boolean',
            'show_online' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function links()
    {
        return $this->hasMany(RoomAmenityLink::class);
    }
}
