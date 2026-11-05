<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** Recurring service interval and next-due threshold for one fleet vehicle. */
class VehicleMaintenancePlan extends Model
{
    use BelongsToHotel;

    public const CATEGORIES = [
        'preventive' => 'Preventive service',
        'oil' => 'Oil & filters',
        'tyres' => 'Tyres',
        'inspection' => 'Inspection',
        'other' => 'Other',
    ];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return [
            'last_completed_date' => 'date',
            'due_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function records()
    {
        return $this->hasMany(VehicleMaintenanceRecord::class, 'plan_id');
    }

    public function isDue(?int $odometer = null, ?string $date = null): bool
    {
        $date ??= now()->toDateString();

        return ($this->due_date && $this->due_date->toDateString() <= $date)
            || ($this->due_odometer !== null && $odometer !== null && $odometer >= $this->due_odometer);
    }
}
