<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.2 A lettable apartment, office or shop – optionally a hotel room let long-term (blocked from nightly sale). */
class LeaseUnit extends Model
{
    use BelongsToHotel;

    public const TYPES = ['studio' => 'Studio', '1bed' => '1-bedroom', '2bed' => '2-bedroom', '3bed' => '3-bedroom', 'penthouse' => 'Penthouse', 'office' => 'Office suite', 'shop' => 'Retail / kiosk'];

    public const STATUSES = ['vacant' => 'Vacant', 'reserved' => 'Reserved', 'leased' => 'Leased', 'maintenance' => 'Under maintenance'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['furnished' => 'boolean', 'is_active' => 'boolean', 'list_rent' => 'decimal:2', 'service_charge' => 'decimal:2', 'size_sqm' => 'decimal:2'];
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function leases()
    {
        return $this->hasMany(Lease::class);
    }

    /** The lease occupying the unit now (active or on notice), else the next signed one. */
    public function currentLease()
    {
        return $this->hasOne(Lease::class)->whereIn('status', ['active', 'notice'])->latestOfMany('start_date');
    }

    public function readings()
    {
        return $this->hasMany(LeaseMeterReading::class);
    }

    public function label(): string
    {
        return $this->code.' – '.$this->name;
    }
}
