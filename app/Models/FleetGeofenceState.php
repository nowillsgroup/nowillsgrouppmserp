<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** Last known inside / outside position of one vehicle relative to one geofence. */
class FleetGeofenceState extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['inside' => 'boolean', 'changed_at' => 'datetime'];
    }

    public function geofence()
    {
        return $this->belongsTo(FleetGeofence::class, 'geofence_id');
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }
}
