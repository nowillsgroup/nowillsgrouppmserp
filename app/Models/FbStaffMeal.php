<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.8 Staff canteen covers for one meal of a business date – credited out of the cost of food sold. */
class FbStaffMeal extends Model
{
    use BelongsToHotel;

    public const MEALS = ['breakfast' => 'Breakfast', 'lunch' => 'Lunch', 'dinner' => 'Dinner', 'night' => 'Night shift'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['business_date' => 'date', 'cost_per_cover' => 'float', 'total' => 'float'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
