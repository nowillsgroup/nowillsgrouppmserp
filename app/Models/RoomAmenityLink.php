<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class RoomAmenityLink extends Model
{
    use BelongsToHotel;

    protected $table = 'room_amenity_links';

    protected $guarded = ['id', 'hotel_id'];

    public function amenity()
    {
        return $this->belongsTo(RoomAmenity::class, 'room_amenity_id');
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }
}
