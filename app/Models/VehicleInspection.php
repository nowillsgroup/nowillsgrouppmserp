<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v7.61 – a pre-trip vehicle inspection: the checks confirmed, the result and any defect raised. */
class VehicleInspection extends Model
{
    use BelongsToHotel;

    /** The checks a driver or the transport desk confirms before a car leaves the yard. */
    public const CHECKS = [
        'brakes' => 'Brakes & handbrake',
        'tyres' => 'Tyres & pressures',
        'lights' => 'Lights & indicators',
        'wipers' => 'Wipers & screen wash',
        'fluids' => 'Oil, coolant & fuel',
        'belts' => 'Seat belts',
        'kit' => 'First-aid kit & extinguisher',
        'body' => 'Body, mirrors & cleanliness',
    ];

    public const RESULTS = ['pass' => 'Passed', 'minor' => 'Passed with a minor defect', 'major' => 'Unsafe – do not dispatch'];

    public const TONES = ['pass' => 'green', 'minor' => 'amber', 'major' => 'red'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['inspected_at' => 'datetime', 'checks' => 'array'];
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function workOrder()
    {
        return $this->belongsTo(VehicleMaintenanceRecord::class, 'work_order_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Checks that were confirmed OK, as labels. */
    public function passedChecks(): array
    {
        return array_values(array_intersect_key(self::CHECKS, array_flip((array) $this->checks)));
    }

    /** Checks that were not confirmed OK. */
    public function missingChecks(): array
    {
        return array_values(array_diff_key(self::CHECKS, array_flip((array) $this->checks)));
    }

    public function isUnsafe(): bool
    {
        return $this->result === 'major';
    }
}
