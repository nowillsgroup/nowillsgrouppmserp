<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    use BelongsToHotel;

    protected $table = 'rooms';

    protected $guarded = ['id', 'hotel_id'];

    public function currentStay()
    {
        // status filter inside the of-many subquery (v3.8 fix: a newer row in another status must not hide this one)
        return $this->hasOne(ReservationRoom::class)->ofMany(['id' => 'max'], fn ($q) => $q->where('status', 'checked_in'));
    }

    public function isAvailable(): bool
    {
        return $this->is_active && $this->status === 'vacant';
    }

    protected function casts(): array
    {
        return [
            'is_smoking' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }

    public function floor()
    {
        return $this->belongsTo(Floor::class);
    }

    public function stays()
    {
        return $this->hasMany(ReservationRoom::class);
    }

    public function images()
    {
        return $this->hasMany(RoomImage::class)->where('is_active', true)->orderByDesc('is_primary')->orderBy('sort_order');
    }

    public function amenityLinks()
    {
        return $this->hasMany(RoomAmenityLink::class);
    }

    public function outages()
    {
        return $this->hasMany(RoomOutage::class);
    }
}
