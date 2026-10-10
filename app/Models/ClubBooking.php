<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.1 A place in one session of a class: booked, waitlisted, attended, no-show or cancelled. */
class ClubBooking extends Model
{
    use BelongsToHotel;

    public const STATUSES = ['booked' => 'Booked', 'waitlist' => 'Waitlist', 'attended' => 'Attended', 'no_show' => 'No-show', 'cancelled' => 'Cancelled'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function klass()
    {
        return $this->belongsTo(ClubClass::class, 'club_class_id');
    }

    public function member()
    {
        return $this->belongsTo(ClubMember::class, 'club_member_id');
    }

    public function stay()
    {
        return $this->belongsTo(ReservationRoom::class, 'reservation_room_id');
    }
}
