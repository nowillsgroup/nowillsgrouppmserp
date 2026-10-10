<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.1 Access log: a check-in (or a refused attempt) at a club facility. */
class ClubVisit extends Model
{
    use BelongsToHotel;

    public const TYPES = ['member' => 'Member', 'resident' => 'Hotel guest', 'day_pass' => 'Day pass', 'guest' => "Member's guest"];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['checked_in_at' => 'datetime', 'checked_out_at' => 'datetime', 'denied' => 'boolean'];
    }

    public function member()
    {
        return $this->belongsTo(ClubMember::class, 'club_member_id');
    }

    public function host()
    {
        return $this->belongsTo(ClubMember::class, 'host_member_id');
    }

    public function dayPass()
    {
        return $this->belongsTo(ClubDayPass::class, 'club_day_pass_id');
    }

    public function stay()
    {
        return $this->belongsTo(ReservationRoom::class, 'reservation_room_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function overrider()
    {
        return $this->belongsTo(User::class, 'override_by');
    }
}
