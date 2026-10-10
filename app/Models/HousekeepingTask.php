<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class HousekeepingTask extends Model
{
    use BelongsToHotel;

    protected $table = 'housekeeping_tasks';

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return [
            'task_date' => 'date',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'dnd_at' => 'datetime',
            'inspected_at' => 'datetime',
            'checklist' => 'array',
        ];
    }

    public const TYPES = ['cleaning' => 'Stay-over clean', 'departure' => 'Departure clean', 'deep_clean' => 'Deep clean', 'turndown' => 'Turn-down',
        'inspection' => 'Inspection', 'linen_change' => 'Linen change', 'public_area' => 'Public area'];

    public function minutes(): ?int
    {
        return $this->started_at && $this->completed_at ? (int) $this->started_at->diffInMinutes($this->completed_at) : null;
    }

    public function awaitingInspection(): bool
    {
        return $this->status === 'completed' && $this->room_id && $this->inspection_status === null && $this->task_type !== 'inspection';
    }

    public function completer()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function inspector()
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
