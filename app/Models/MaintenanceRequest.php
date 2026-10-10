<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class MaintenanceRequest extends Model
{
    use BelongsToHotel;

    protected $table = 'maintenance_requests';

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return [
            'block_room' => 'boolean',
            'block_from' => 'date',
            'block_to' => 'date',
            'resolved_at' => 'datetime',
            'due_date' => 'date',
            'cost' => 'decimal:2',
        ];
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function schedule()
    {
        return $this->belongsTo(PmSchedule::class, 'pm_schedule_id');
    }

    public function isPm(): bool
    {
        return (bool) $this->pm_schedule_id;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['open', 'in_progress']);
    }
}
