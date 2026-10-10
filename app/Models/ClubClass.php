<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.1 A weekly class on the timetable (aqua aerobics, spin, yoga …) with capacity. */
class ClubClass extends Model
{
    use BelongsToHotel;

    public const DAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['residents' => 'boolean', 'is_active' => 'boolean'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function bookings()
    {
        return $this->hasMany(ClubBooking::class);
    }

    public function instructorName(): ?string
    {
        return $this->employee?->name ?? $this->instructor;
    }

    public function timeLabel(): string
    {
        return substr($this->start_time, 0, 5);
    }
}
