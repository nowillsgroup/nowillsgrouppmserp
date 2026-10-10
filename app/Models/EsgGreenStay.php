<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v7.0 A guest's green-stay choice: stay-over cleaning is skipped (with a full service every few nights) in return for loyalty points. */
class EsgGreenStay extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['from_date' => 'date', 'ended_on' => 'date'];
    }

    public function stay()
    {
        return $this->belongsTo(ReservationRoom::class, 'reservation_room_id');
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function nights()
    {
        return $this->hasMany(EsgGreenNight::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
