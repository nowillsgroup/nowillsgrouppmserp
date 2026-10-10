<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.2 An electricity / water meter reading on a unit; metered leases are recharged for the consumption. */
class LeaseMeterReading extends Model
{
    use BelongsToHotel;

    public const METERS = ['electricity' => ['Electricity', 'kWh'], 'water' => ['Water', 'm³']];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'reading' => 'decimal:2', 'previous' => 'decimal:2', 'consumption' => 'decimal:2', 'rate' => 'decimal:4'];
    }

    public function unit()
    {
        return $this->belongsTo(LeaseUnit::class, 'lease_unit_id');
    }

    public function lease()
    {
        return $this->belongsTo(Lease::class);
    }

    public function charge()
    {
        return $this->belongsTo(LeaseCharge::class, 'lease_charge_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
