<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class RoomType extends Model
{
    use BelongsToHotel;

    protected $table = 'room_types';

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return [
            'show_online' => 'boolean',
            'is_active' => 'boolean',
            'base_rate' => 'decimal:2',
            'extra_adult_rate' => 'decimal:2',
            'extra_child_rate' => 'decimal:2',
        ];
    }

    public function rooms()
    {
        return $this->hasMany(Room::class);
    }

    public function ratePlans()
    {
        return $this->hasMany(RatePlan::class);
    }

    public function images()
    {
        return $this->hasMany(RoomImage::class)->whereNull('room_id')->where('is_active', true)->orderByDesc('is_primary')->orderBy('sort_order');
    }

    public function amenityLinks()
    {
        return $this->hasMany(RoomAmenityLink::class);
    }
}
