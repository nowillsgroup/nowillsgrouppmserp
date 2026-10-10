<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.7 One inspection of a piece of fire equipment: a visual check on the round or the contractor's annual service. */
class HseCheck extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['checked_on' => 'date', 'faults' => 'array'];
    }

    public function equipment()
    {
        return $this->belongsTo(HseEquipment::class, 'hse_equipment_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function ticket()
    {
        return $this->belongsTo(MaintenanceRequest::class, 'maintenance_request_id');
    }
}
