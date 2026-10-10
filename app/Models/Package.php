<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.5 A rate package / inclusion: breakfast, dinner, airport drop, spa credit, premium Wi-Fi … */
class Package extends Model
{
    use BelongsToHotel;

    public const CALCS = ['per_person' => 'Per person (adult & child prices)', 'per_adult' => 'Per adult', 'per_room' => 'Per room'];

    public const RHYTHMS = ['every_night' => 'Every night', 'arrival' => 'Arrival night only', 'departure' => 'Last night only', 'once' => 'Once per stay'];

    public const SERVICE_DAYS = ['same_day' => 'Same day as the night', 'next_day' => 'Next morning'];

    public const MEALS = ['breakfast' => 'Breakfast', 'lunch' => 'Lunch', 'dinner' => 'Dinner'];

    public const MODES = ['covers' => 'Covers (count people served)', 'amount' => 'Amount (spend up to the allowance)'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['price_adult' => 'decimal:2', 'price_child' => 'decimal:2', 'overage_price' => 'decimal:2', 'is_allowance' => 'boolean',
            'apply_tax' => 'boolean', 'is_sellable' => 'boolean', 'is_active' => 'boolean'];
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function ratePlans()
    {
        return $this->belongsToMany(RatePlan::class, 'rate_plan_packages')->withPivot('hotel_id')->withTimestamps();
    }

    public function entitlements()
    {
        return $this->hasMany(PackageEntitlement::class);
    }

    /** Covers and value for a party: [qty, amount]. */
    public function quote(int $adults, int $children): array
    {
        $adults = max(0, $adults);
        $children = max(0, $children);

        return match ($this->calc) {
            'per_room' => [1, round((float) $this->price_adult, 2)],
            'per_adult' => [max(1, $adults), round(max(1, $adults) * (float) $this->price_adult, 2)],
            default => [max(1, $adults + $children), round(max(1, $adults) * (float) $this->price_adult + $children * (float) $this->price_child, 2)],
        };
    }

    public function priceLabel(): string
    {
        return match ($this->calc) {
            'per_room' => money($this->price_adult).' / room',
            'per_adult' => money($this->price_adult).' / adult',
            default => money($this->price_adult).' / adult'.((float) $this->price_child > 0 ? ' · '.money($this->price_child).' / child' : ''),
        };
    }
}
