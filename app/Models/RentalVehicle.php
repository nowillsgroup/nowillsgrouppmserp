<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v7.40 A rental-fleet vehicle (self-drive car rental). */
class RentalVehicle extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    public const CLASSES = ['economy' => 'Economy', 'standard' => 'Standard', 'suv' => 'SUV', 'luxury' => 'Luxury', 'van' => 'Van'];

    public const STATUSES = ['available' => ['Available', 'green'], 'rented' => ['On rent', 'blue'], 'maintenance' => ['Maintenance', 'amber'], 'retired' => ['Retired', 'slate']];

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? ucfirst($this->status);
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->status][1] ?? 'slate';
    }

    public function classLabel(): string
    {
        return self::CLASSES[$this->class] ?? ucfirst($this->class);
    }

    public function activeRental()
    {
        return $this->hasOne(CarRental::class)->whereIn('status', ['reserved', 'picked_up']);
    }
}
