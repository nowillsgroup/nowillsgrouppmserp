<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class PromoCode extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['book_from' => 'date', 'book_to' => 'date', 'stay_from' => 'date', 'stay_to' => 'date', 'room_type_ids' => 'array',
            'is_active' => 'boolean', 'value' => 'decimal:2'];
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }

    public function campaigns()
    {
        return $this->hasMany(MarketingCampaign::class);
    }

    /** Bookings that used the code (cancelled ones free the use again). */
    public function usesCount(): int
    {
        return $this->reservations()->where('status', '!=', 'cancelled')->count();
    }

    public function offerLabel(): string
    {
        return $this->discount_type === 'percent'
            ? rtrim(rtrim(number_format((float) $this->value, 2), '0'), '.').'% off'
            : money($this->value).' off per night';
    }

    /** Discount per room-night on a given nightly rate. */
    public function discountOn(float $rate): float
    {
        $d = $this->discount_type === 'percent' ? $rate * (float) $this->value / 100 : (float) $this->value;

        return round(min(max(0, $d), $rate), 2);
    }
}
