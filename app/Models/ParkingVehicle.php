<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.6 A vehicle known to the car park, keyed by its normalised plate. */
class ParkingVehicle extends Model
{
    use BelongsToHotel;

    public const TYPES = ['car' => 'Saloon / hatchback', 'suv' => 'SUV / 4x4', 'pickup' => 'Pick-up', 'van' => 'Van / minibus', 'bus' => 'Bus / coach', 'motorbike' => 'Motorbike'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['is_banned' => 'boolean', 'last_seen_at' => 'datetime'];
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function sessions()
    {
        return $this->hasMany(ParkingSession::class);
    }

    public function permits()
    {
        return $this->hasMany(ParkingPermit::class);
    }

    public function description(): string
    {
        return trim(implode(' ', array_filter([$this->colour, $this->make, $this->model]))) ?: (self::TYPES[$this->type] ?? 'Vehicle');
    }
}
