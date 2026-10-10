<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class ReservationWaitlist extends Model
{
    use BelongsToHotel;

    protected $table = 'reservation_waitlists';

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return [
            'arrival_date' => 'date',
            'departure_date' => 'date',
        ];
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }
}
