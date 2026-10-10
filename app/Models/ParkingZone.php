<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.6 A car park / zone (basement, valet compound, visitor lot, staff yard) with its capacity. */
class ParkingZone extends Model
{
    use BelongsToHotel;

    public const TYPES = ['guest' => 'Guest self-park', 'valet' => 'Valet compound', 'visitor' => 'Visitors', 'staff' => 'Staff & permits', 'mixed' => 'Mixed use'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['is_covered' => 'boolean', 'is_active' => 'boolean'];
    }

    public function sessions()
    {
        return $this->hasMany(ParkingSession::class);
    }

    public function activeSessions()
    {
        return $this->hasMany(ParkingSession::class)->whereIn('status', ParkingSession::ACTIVE);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst($this->type);
    }
}
