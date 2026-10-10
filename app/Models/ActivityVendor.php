<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.9 A third-party activity vendor – tour operator, guide, attraction or event ticketing – the concierge sells for as agent. */
class ActivityVendor extends Model
{
    use BelongsToHotel;

    public const KINDS = ['tour_operator' => 'Tour operator', 'guide' => 'Licensed guide', 'attraction' => 'Attraction / park', 'events' => 'Events & tickets', 'water' => 'Beach & water sports', 'other' => 'Other'];

    public const COLLECTION = ['folio' => 'Hotel collects (charged to the room)', 'vendor' => 'Guest pays the vendor'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['commission_pct' => 'decimal:2', 'licence_expires' => 'date', 'is_active' => 'boolean'];
    }

    public function activities()
    {
        return $this->hasMany(Activity::class);
    }

    public function bookings()
    {
        return $this->hasMany(ActivityBooking::class);
    }

    public function settlements()
    {
        return $this->hasMany(ActivitySettlement::class);
    }

    public function licenceExpired(): bool
    {
        return $this->licence_expires !== null && $this->licence_expires->lt(bdate()->copy()->startOfDay());
    }
}
