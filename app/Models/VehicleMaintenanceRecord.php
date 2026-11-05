<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** A scheduled or completed repair/service work order. */
class VehicleMaintenanceRecord extends Model
{
    use BelongsToHotel;

    public const CATEGORIES = ['preventive' => 'Preventive service', 'repair' => 'Repair', 'inspection' => 'Inspection', 'tyres' => 'Tyres', 'other' => 'Other'];
    public const STATUSES = ['scheduled' => 'Scheduled', 'in_progress' => 'In progress', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
    public const PRIORITIES = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'estimated_cost' => 'decimal:2',
            'actual_cost' => 'decimal:2',
        ];
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function plan()
    {
        return $this->belongsTo(VehicleMaintenancePlan::class, 'plan_id');
    }

    public function expenses()
    {
        return $this->hasMany(VehicleExpense::class, 'maintenance_record_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function completer()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
