<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class RatePlan extends Model
{
    use BelongsToHotel;

    protected $table = 'rate_plans';

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return [
            'tax_inclusive' => 'boolean',
            'is_active' => 'boolean',
            'rate' => 'decimal:2',
        ];
    }

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }

    /** v5.5 packages included in this rate (breakfast, dinner, Wi-Fi …) – allocated out of the room tariff every night. */
    public function packages()
    {
        return $this->belongsToMany(Package::class, 'rate_plan_packages')->withPivot('hotel_id')->withTimestamps()->orderBy('sort')->orderBy('name');
    }

    public const MEAL_PLANS = ['EP' => 'Room only (EP)', 'CP' => 'Bed & breakfast (CP)', 'MAP' => 'Half board (MAP)', 'AP' => 'Full board (AP)'];
}
