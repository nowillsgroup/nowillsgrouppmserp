<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class StayFixedCharge extends Model
{
    use BelongsToHotel;

    protected $table = 'stay_fixed_charges';

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'apply_tax' => 'boolean', 'is_active' => 'boolean', 'start_date' => 'date', 'end_date' => 'date', 'last_posted_date' => 'date'];
    }

    /** v5.5 set when the charge is an add-on package from the catalogue */
    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function stay()
    {
        return $this->belongsTo(ReservationRoom::class, 'reservation_room_id');
    }
}
