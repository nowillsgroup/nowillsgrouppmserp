<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** v7.40 A self-drive car rental booking. */
class CarRental extends Model
{
    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['pickup_at' => 'datetime', 'return_due_at' => 'datetime', 'returned_at' => 'datetime'];
    }

    public const STATUSES = [
        'reserved' => ['Reserved', 'blue'], 'picked_up' => ['On rent', 'green'], 'returned' => ['Returned', 'slate'],
        'cancelled' => ['Cancelled', 'red'], 'no_show' => ['No-show', 'amber'],
    ];

    public function vehicle()
    {
        return $this->belongsTo(RentalVehicle::class, 'rental_vehicle_id');
    }

    public function stay()
    {
        return $this->belongsTo(ReservationRoom::class, 'reservation_room_id');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? ucfirst($this->status);
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->status][1] ?? 'slate';
    }
}
