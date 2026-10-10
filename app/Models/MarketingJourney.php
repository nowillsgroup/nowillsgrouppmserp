<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class MarketingJourney extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    /** trigger => [label, offset meaning, default offset] */
    public const TRIGGERS = [
        'birthday' => ['Birthday', 'days before the birthday (0 = on the day)', 0],
        'anniversary' => ['Wedding anniversary', 'days before the anniversary (0 = on the day)', 0],
        'pre_arrival' => ['Pre-arrival', 'days before arrival (confirmed bookings)', 3],
        'post_stay' => ['Post-stay', 'days after check-out', 1],
        'win_back' => ['Win-back (lapsed guest)', 'days since the last stay, with no future booking', 180],
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'last_run_at' => 'datetime'];
    }

    public function segment()
    {
        return $this->belongsTo(MarketingSegment::class, 'segment_id');
    }

    public function promo()
    {
        return $this->belongsTo(PromoCode::class, 'promo_code_id');
    }

    public function messages()
    {
        return $this->hasMany(MarketingMessage::class, 'journey_id');
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class, 'journey_id');
    }

    public function triggerLabel(): string
    {
        return self::TRIGGERS[$this->trigger][0] ?? $this->trigger;
    }

    public function whenLabel(): string
    {
        $n = (int) $this->offset_days;

        return match ($this->trigger) {
            'birthday', 'anniversary' => $n === 0 ? 'On the day' : "{$n} day(s) before",
            'pre_arrival' => "{$n} day(s) before arrival",
            'post_stay' => "{$n} day(s) after check-out",
            'win_back' => "{$n} days after the last stay",
            default => (string) $n,
        };
    }
}
