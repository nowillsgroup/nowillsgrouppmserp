<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v4.9 A reusable shift (M 06:00–14:00 …). An end before the start runs into the next day. */
class ShiftTemplate extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function label(): string
    {
        return substr($this->start_time, 0, 5).'–'.substr($this->end_time, 0, 5);
    }

    /** Paid minutes: span (overnight aware) less the break. */
    public function paidMinutes(): int
    {
        return RosterShift::span($this->start_time, $this->end_time) - (int) $this->break_minutes;
    }
}
