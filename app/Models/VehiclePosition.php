<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** Normalized GPS fix received from a vehicle tracker. */
class VehiclePosition extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'speed_kph' => 'decimal:2',
            'heading' => 'decimal:2',
            'ignition' => 'boolean',
            'recorded_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function tracker()
    {
        return $this->belongsTo(VehicleTracker::class);
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }
}
