<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.3 Housekeeping's physical (blind) room count for a business date, compared with front-office status. */
class RoomCount extends Model
{
    use BelongsToHotel;

    public const HK = ['occupied' => 'Occupied', 'vacant' => 'Vacant', 'ooo' => 'Out of order'];

    public const RESULTS = ['ok' => 'Agrees', 'sleeper' => 'Sleeper – FO occupied, room empty', 'unregistered' => 'Occupied but not registered', 'ooo_mismatch' => 'Out-of-order mismatch'];

    public const RESOLUTIONS = ['guest_out' => 'Guest in house – out for the day', 'checked_out' => 'Guest had left – checked out now', 'room_move' => 'Room move not recorded',
        'posted' => 'Occupancy found – charged', 'hk_error' => 'Housekeeping error – recounted', 'ooo_updated' => 'Room status corrected', 'other' => 'Other'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['business_date' => 'date', 'luggage' => 'boolean', 'resolved_at' => 'datetime'];
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function counter()
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
