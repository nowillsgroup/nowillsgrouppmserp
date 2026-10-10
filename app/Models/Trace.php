<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v3.5 – trace: a dated reminder for a department, optionally against a booking / stay. */
class Trace extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['trace_date' => 'date', 'carried_from' => 'date', 'done_at' => 'datetime'];
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function stay()
    {
        return $this->belongsTo(ReservationRoom::class, 'reservation_room_id');
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function doer()
    {
        return $this->belongsTo(User::class, 'done_by');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isOverdue(): bool
    {
        if (! $this->isOpen()) {
            return false;
        }
        if ($this->trace_date->lt(bdate())) {
            return true;
        }

        return $this->trace_date->isSameDay(bdate()) && $this->due_time && now()->format('H:i:s') > $this->due_time;
    }
}
