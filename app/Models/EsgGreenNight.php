<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v7.0 A night on which a green-stay room was not serviced: points awarded and resources saved (estimate). */
class EsgGreenNight extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['business_date' => 'date', 'kwh' => 'float', 'litres' => 'float', 'co2_kg' => 'float'];
    }

    public function greenStay()
    {
        return $this->belongsTo(EsgGreenStay::class, 'esg_green_stay_id');
    }

    public function stay()
    {
        return $this->belongsTo(ReservationRoom::class, 'reservation_room_id');
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }
}
