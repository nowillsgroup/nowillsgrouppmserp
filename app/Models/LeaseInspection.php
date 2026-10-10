<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.2 Move-in / move-out / periodic inspection: condition per item, damages charged to the tenant. */
class LeaseInspection extends Model
{
    use BelongsToHotel;

    public const KINDS = ['move_in' => 'Move-in', 'move_out' => 'Move-out', 'periodic' => 'Periodic'];

    public const CONDITIONS = ['good' => 'Good', 'fair' => 'Fair', 'damaged' => 'Damaged', 'missing' => 'Missing'];

    /** Default checklist: area => items. */
    public const CHECKLIST = [
        'Living room' => ['Walls & paint', 'Floor', 'Sofa & chairs', 'TV & remote', 'Curtains / blinds', 'Air conditioner'],
        'Kitchen' => ['Cooker & oven', 'Fridge', 'Microwave', 'Sink & taps', 'Cabinets', 'Crockery & utensils'],
        'Bedroom(s)' => ['Bed & mattress', 'Wardrobe', 'Air conditioner', 'Bedside lamps'],
        'Bathroom(s)' => ['Shower / bath', 'WC', 'Basin & mirror', 'Water heater'],
        'General' => ['Doors & locks', 'Keys & access cards', 'Windows & nets', 'Light fittings', 'Smoke detector'],
    ];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'items' => 'array', 'damages' => 'decimal:2'];
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
