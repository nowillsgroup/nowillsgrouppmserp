<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** A vehicle entering or leaving a geofence. */
class FleetGeofenceEvent extends Model
{
    use BelongsToHotel;

    public const TYPES = ['enter' => 'Entered', 'exit' => 'Left'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'acknowledged_at' => 'datetime'];
    }

    public function geofence()
    {
        return $this->belongsTo(FleetGeofence::class, 'geofence_id');
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function acknowledger()
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }
}
