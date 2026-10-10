<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class LeaveRequest extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date_from' => 'date', 'date_to' => 'date', 'half_day' => 'boolean', 'days' => 'decimal:1', 'decided_at' => 'datetime'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType()
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function periodLabel(): string
    {
        return $this->date_from->equalTo($this->date_to)
            ? $this->date_from->format('d M Y').($this->half_day ? ' (½ day)' : '')
            : $this->date_from->format('d M').' – '.$this->date_to->format('d M Y');
    }
}
