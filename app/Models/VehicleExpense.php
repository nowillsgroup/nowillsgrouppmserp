<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v3.3 vehicle running cost: fuel fill, service, repair, toll, parking. */
class VehicleExpense extends Model
{
    use BelongsToHotel;

    public const KINDS = ['fuel' => 'Fuel', 'service' => 'Service', 'repair' => 'Repair', 'toll' => 'Toll', 'parking' => 'Parking', 'other' => 'Other'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'litres' => 'decimal:2', 'amount' => 'decimal:2'];
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver()
    {
        return $this->belongsTo(Driver::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
