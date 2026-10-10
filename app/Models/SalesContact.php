<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.1 A person at a sales account: booker, travel manager, event planner, decision maker. */
class SalesContact extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected $attributes = ['is_active' => true, 'is_primary' => false, 'decision_maker' => false];

    protected function casts(): array
    {
        return ['birthday' => 'date', 'is_primary' => 'boolean', 'decision_maker' => 'boolean', 'is_active' => 'boolean'];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function activities()
    {
        return $this->hasMany(SalesActivity::class);
    }

    /** the next birthday on or after $from (null if unknown) */
    public function nextBirthday(\Carbon\Carbon $from): ?\Carbon\Carbon
    {
        if (! $this->birthday) {
            return null;
        }
        $d = $this->birthday->copy()->year($from->year);
        if ($this->birthday->month === 2 && $this->birthday->day === 29 && ! $d->isLeapYear()) {
            $d = $d->setDate($from->year, 2, 28);
        }

        return $d->lt($from->copy()->startOfDay()) ? $d->addYearNoOverflow() : $d;
    }
}
