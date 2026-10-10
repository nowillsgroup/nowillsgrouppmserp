<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.3 A rate shop: the competitive set's public rates for one stay date on one channel, against our own rate. */
class BdRateShop extends Model
{
    use BelongsToHotel;

    public const CHANNELS = ['brand_site' => 'Hotel websites', 'booking_com' => 'Booking.com', 'expedia' => 'Expedia', 'google' => 'Google Hotels', 'phone' => 'Phone / walk-in quote'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['stay_date' => 'date', 'shopped_on' => 'date', 'own_rate' => 'decimal:2', 'own_sold_out' => 'boolean'];
    }

    public function lines()
    {
        return $this->hasMany(BdRateShopLine::class, 'shop_id');
    }

    public function shopper()
    {
        return $this->belongsTo(User::class, 'shopped_by');
    }

    /** average of the competitors' available rates */
    public function compAverage(): ?float
    {
        $r = $this->lines->where('sold_out', false)->whereNotNull('rate')->pluck('rate')->map(fn ($x) => (float) $x);

        return $r->count() ? round($r->avg(), 2) : null;
    }

    /** our rank by price among the hotels with a rate (1 = most expensive) */
    public function ownRank(): ?int
    {
        if ($this->own_rate === null || $this->own_sold_out) {
            return null;
        }

        return 1 + $this->lines->where('sold_out', false)->filter(fn ($l) => $l->rate !== null && (float) $l->rate > (float) $this->own_rate)->count();
    }
}
