<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v4.9 A clock-in / clock-out pair for one work date. */
class TimePunch extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['work_date' => 'date', 'clock_in' => 'datetime', 'clock_out' => 'datetime', 'approved_at' => 'datetime'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift()
    {
        return $this->belongsTo(RosterShift::class, 'roster_shift_id');
    }

    public function editor()
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function hours(): float
    {
        return round(((int) $this->worked_minutes) / 60, 2);
    }
}
