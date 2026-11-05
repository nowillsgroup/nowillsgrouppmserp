<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** A circle on the map: the hotel yard, a guest zone or an area vehicles should not enter. */
class FleetGeofence extends Model
{
    use BelongsToHotel;

    public const KINDS = ['yard' => 'Hotel yard', 'zone' => 'Service zone', 'restricted' => 'Restricted area'];

    public const ALERTS = ['both' => 'On entry and exit', 'enter' => 'On entry only', 'exit' => 'On exit only', 'none' => 'Record silently'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['latitude' => 'decimal:7', 'longitude' => 'decimal:7', 'is_active' => 'boolean'];
    }

    public function events()
    {
        return $this->hasMany(FleetGeofenceEvent::class, 'geofence_id');
    }

    public function states()
    {
        return $this->hasMany(FleetGeofenceState::class, 'geofence_id');
    }
}
