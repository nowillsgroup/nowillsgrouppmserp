<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/**
 * v7.61 – something that happened to a vehicle on the road: accident, damage, breakdown or theft.
 * The estimated cost here is an estimate for the insurer, never posted money — real spend still goes
 * through vehicle_expenses or the work order it raises.
 */
class VehicleIncident extends Model
{
    use BelongsToHotel;

    public const KINDS = ['accident' => 'Accident', 'damage' => 'Damage', 'breakdown' => 'Breakdown', 'theft' => 'Theft', 'other' => 'Other'];

    public const SEVERITIES = ['minor' => 'Minor', 'major' => 'Major', 'critical' => 'Critical'];

    public const STATUSES = ['open' => 'Open', 'investigating' => 'Under investigation', 'closed' => 'Closed'];

    public const TONES = ['minor' => 'amber', 'major' => 'red', 'critical' => 'red'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'closed_at' => 'datetime', 'estimated_cost' => 'decimal:2'];
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver()
    {
        return $this->belongsTo(Driver::class);
    }

    public function workOrder()
    {
        return $this->belongsTo(VehicleMaintenanceRecord::class, 'work_order_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** An incident is live until somebody closes it with a reason. */
    public function isOpen(): bool
    {
        return in_array($this->status, ['open', 'investigating'], true);
    }
}
