<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v3.7 – a message sent from the PMS to an external system (PABX or door-lock encoder) and its outcome. */
class InterfaceCommand extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function stay()
    {
        return $this->belongsTo(ReservationRoom::class, 'reservation_room_id');
    }

    public function card()
    {
        return $this->belongsTo(KeyCard::class, 'key_card_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
