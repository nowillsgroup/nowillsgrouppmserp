<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class PmSchedule extends Model
{
    use BelongsToHotel;

    public const UNITS = ['day' => 'day(s)', 'week' => 'week(s)', 'month' => 'month(s)'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['next_due' => 'date', 'last_done' => 'date', 'is_active' => 'boolean'];
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function tickets()
    {
        return $this->hasMany(MaintenanceRequest::class);
    }

    public function openTicket()
    {
        // status filter inside the of-many subquery (v3.8 fix: a newer row in another status must not hide this one)
        return $this->hasOne(MaintenanceRequest::class)->ofMany(['id' => 'max'], fn ($q) => $q->whereIn('status', ['open', 'in_progress']));
    }

    public function frequency(): string
    {
        $map = ['day' => ['daily', 'days'], 'week' => ['weekly', 'weeks'], 'month' => ['monthly', 'months']];
        if ($this->every === 1) {
            return ucfirst($map[$this->every_unit][0]);
        }
        if ($this->every_unit === 'month' && in_array($this->every, [3, 6, 12])) {
            return [3 => 'Quarterly', 6 => 'Every 6 months', 12 => 'Yearly'][$this->every];
        }

        return "Every {$this->every} ".$map[$this->every_unit][1];
    }

    /** The due date after $from, one interval on. */
    public function advance(\Carbon\Carbon $from): \Carbon\Carbon
    {
        return match ($this->every_unit) {
            'day' => $from->copy()->addDays($this->every),
            'week' => $from->copy()->addWeeks($this->every),
            default => $from->copy()->addMonthsNoOverflow($this->every),
        };
    }

    public function state(): string
    {
        if (! $this->is_active) {
            return 'paused';
        }
        $today = bdate();

        return $this->next_due->lt($today) ? 'overdue' : ($this->next_due->lte($today->copy()->addDays(max(1, $this->lead_days))) ? 'due' : 'scheduled');
    }

    public function target(): string
    {
        return $this->asset ? $this->asset->name.' ('.$this->asset->asset_no.')' : trim(($this->room ? 'Room '.$this->room->room_no : '').($this->room && $this->location ? ' · ' : '').($this->location ?? ''));
    }
}
