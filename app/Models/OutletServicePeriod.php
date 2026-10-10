<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v3.2 a service period (e.g. Dinner 19:00 – 22:30 last seating) during which an outlet takes table reservations. */
class OutletServicePeriod extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    public const DAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function opensOn(int $isoWeekday): bool
    {
        return str_contains((string) $this->days, (string) $isoWeekday);
    }

    public function daysLabel(): string
    {
        if ($this->days === '1234567') {
            return 'Daily';
        }

        return collect(str_split((string) $this->days))->map(fn ($d) => self::DAYS[(int) $d] ?? '')->filter()->implode(' ');
    }
}
