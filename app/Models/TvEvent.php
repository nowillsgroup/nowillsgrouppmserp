<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v7.1 A usage event from a TV (screen opened, channel watched, order placed …) for the analytics. */
class TvEvent extends Model
{
    use BelongsToHotel;

    public const UPDATED_AT = null;

    public const EVENTS = ['welcome' => 'Welcome screen', 'home' => 'Home menu', 'tv' => 'Live TV', 'channel' => 'Channel watched', 'movies' => 'Movies', 'rent' => 'Movie rented',
        'dining' => 'In-room dining', 'order' => 'Dining order', 'services' => 'Services', 'request' => 'Service request', 'wakeup' => 'Wake-up call booked', 'messages' => 'Messages',
        'bill' => 'My bill', 'checkout' => 'Express check-out', 'info' => 'Hotel information', 'page' => 'Information page', 'explore' => 'Explore Ghana', 'feedback' => 'Feedback',
        'language' => 'Language changed', 'weather' => 'Weather'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function device()
    {
        return $this->belongsTo(TvDevice::class, 'tv_device_id');
    }

    public function stay()
    {
        return $this->belongsTo(ReservationRoom::class, 'reservation_room_id');
    }
}
