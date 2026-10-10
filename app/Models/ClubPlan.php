<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.1 Health club membership plan: term, price (tax-exclusive), joining fee, facilities, off-peak hours, guest passes and member discounts. */
class ClubPlan extends Model
{
    use BelongsToHotel;

    public const CATEGORIES = ['individual' => 'Individual', 'couple' => 'Couple', 'family' => 'Family', 'corporate' => 'Corporate', 'student' => 'Student', 'senior' => 'Senior'];

    public const TERMS = [1 => 'Monthly', 3 => 'Quarterly', 6 => 'Half-yearly', 12 => 'Annual'];

    public const FACILITIES = ['gym' => 'Gym', 'pool' => 'Swimming pool', 'sauna' => 'Sauna & steam', 'tennis' => 'Tennis court', 'squash' => 'Squash court', 'kids' => 'Kids club', 'classes' => 'Group classes'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['facilities' => 'array', 'is_active' => 'boolean', 'price' => 'decimal:2', 'joining_fee' => 'decimal:2', 'fnb_discount_pct' => 'decimal:2', 'spa_discount_pct' => 'decimal:2'];
    }

    public function memberships()
    {
        return $this->hasMany(ClubMembership::class);
    }

    public function termLabel(): string
    {
        return self::TERMS[$this->term_months] ?? $this->term_months.' months';
    }

    public function hoursLabel(): ?string
    {
        return $this->access_from && $this->access_to ? substr($this->access_from, 0, 5).'–'.substr($this->access_to, 0, 5) : null;
    }

    public function facilityNames(): string
    {
        return collect($this->facilities ?? [])->map(fn ($f) => self::FACILITIES[$f] ?? $f)->implode(', ');
    }
}
