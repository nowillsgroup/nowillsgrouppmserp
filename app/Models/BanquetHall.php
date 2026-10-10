<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class BanquetHall extends Model
{
    use BelongsToHotel;

    protected $table = 'banquet_halls';

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean', 'is_outdoor' => 'boolean', 'rate_per_session' => 'float', 'rate_full_day' => 'float',
        ];
    }

    public function functions()
    {
        return $this->hasMany(BanquetFunction::class);
    }

    /** Seating capacity for a set-up style (falls back to the maximum). */
    public function capacityFor(?string $style): int
    {
        $v = $style ? $this->{'cap_'.$style} ?? null : null;

        return (int) ($v ?: $this->capacity);
    }

    /** Rent for a function of this many hours: half-day (session) rate up to the threshold, full-day beyond. */
    public function rentFor(float $hours): float
    {
        return $hours > (float) setting('banquet_half_day_hours', '5') && $this->rate_full_day > 0 ? $this->rate_full_day : $this->rate_per_session;
    }

    public function events()
    {
        return $this->hasMany(BanquetEvent::class);
    }
}
