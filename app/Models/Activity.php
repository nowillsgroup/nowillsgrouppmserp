<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

/** v5.9 An activity / excursion run by a vendor: price per person or per private group, schedule, capacity, cancellation terms. */
class Activity extends Model
{
    use BelongsToHotel;

    public const CATEGORIES = ['tour' => 'City & heritage tours', 'nature' => 'Nature & eco', 'daytrip' => 'Day trips', 'culture' => 'Culture & crafts', 'beach' => 'Beach & water', 'nightlife' => 'Nightlife & music', 'events' => 'Events & tickets', 'other' => 'Other'];

    public const ICONS = ['tour' => 'building', 'nature' => 'tree', 'daytrip' => 'car', 'culture' => 'star', 'beach' => 'globe', 'nightlife' => 'moon', 'events' => 'calendar', 'other' => 'star'];

    public const DAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['price_adult' => 'decimal:2', 'price_child' => 'decimal:2', 'commission_pct' => 'decimal:2', 'cancel_fee_pct' => 'decimal:2', 'weekdays' => 'array', 'times' => 'array',
            'pickup_included' => 'boolean', 'instant' => 'boolean', 'portal_visible' => 'boolean', 'is_active' => 'boolean'];
    }

    public function vendor()
    {
        return $this->belongsTo(ActivityVendor::class, 'activity_vendor_id');
    }

    public function departures()
    {
        return $this->hasMany(ActivityDeparture::class);
    }

    public function bookings()
    {
        return $this->hasMany(ActivityBooking::class);
    }

    public function commissionPct(): float
    {
        return (float) ($this->commission_pct ?? $this->vendor?->commission_pct ?? 0);
    }

    public function collectionMode(): string
    {
        return $this->collection ?: ($this->vendor?->collection ?: 'folio');
    }

    public function isScheduled(): bool
    {
        return $this->schedule === 'scheduled';
    }

    /** does it run on this date (scheduled activities: weekday list) */
    public function runsOn(Carbon $d): bool
    {
        return ! $this->isScheduled() || in_array($d->dayOfWeekIso, array_map('intval', $this->weekdays ?: []), true);
    }

    public function timesList(): array
    {
        return array_values(array_filter($this->times ?: [], fn ($t) => preg_match('/^\d{2}:\d{2}$/', (string) $t)));
    }

    public function durationLabel(): string
    {
        $h = intdiv($this->duration_min, 60);
        $m = $this->duration_min % 60;

        return $h >= 8 ? 'Full day ('.$h.' h)' : trim(($h ? $h.' h' : '').($m ? ' '.$m.' min' : ''));
    }

    public function daysLabel(): string
    {
        if (! $this->isScheduled()) {
            return 'On request';
        }
        $d = array_map('intval', $this->weekdays ?: []);
        sort($d);

        return count($d) === 7 ? 'Daily' : implode(', ', array_map(fn ($x) => self::DAYS[$x] ?? $x, $d));
    }

    public function priceLabel(): string
    {
        if ($this->pricing === 'group') {
            return money($this->price_adult).' per group'.($this->capacity ? ' (up to '.$this->capacity.')' : '');
        }

        return money($this->price_adult).' adult'.((float) $this->price_child > 0 ? ' · '.money($this->price_child).' child' : '');
    }
}
