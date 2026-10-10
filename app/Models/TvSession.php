<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v7.1 One stay on one TV: welcome shown, language chosen, PIN attempts; ended at check-out, room move or reset. */
class TvSession extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'welcomed_at' => 'datetime', 'ended_at' => 'datetime', 'pin_locked_until' => 'datetime'];
    }

    public function device()
    {
        return $this->belongsTo(TvDevice::class, 'tv_device_id');
    }

    public function stay()
    {
        return $this->belongsTo(ReservationRoom::class, 'reservation_room_id');
    }

    public function template()
    {
        return $this->belongsTo(TvWelcomeTemplate::class, 'tv_welcome_template_id');
    }
}
