<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.6 A parking permit for staff, tenants, contractors or club members – lets the vehicle in free for its validity. */
class ParkingPermit extends Model
{
    use BelongsToHotel;

    public const HOLDERS = ['staff' => 'Staff', 'tenant' => 'Tenant / lessee', 'contractor' => 'Contractor', 'member' => 'Club member', 'other' => 'Other'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_to' => 'date'];
    }

    public function vehicle()
    {
        return $this->belongsTo(ParkingVehicle::class, 'parking_vehicle_id');
    }

    public function zone()
    {
        return $this->belongsTo(ParkingZone::class, 'parking_zone_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function isValidOn(\DateTimeInterface|string $d): bool
    {
        $d = $d instanceof \DateTimeInterface ? $d->format('Y-m-d') : $d;

        return $this->status === 'active' && $this->valid_from->toDateString() <= $d && $this->valid_to->toDateString() >= $d;
    }

    /** active | expired | future | revoked */
    public function state(): string
    {
        if ($this->status === 'revoked') {
            return 'revoked';
        }
        $d = bdate()->toDateString();

        return $this->valid_to->toDateString() < $d ? 'expired' : ($this->valid_from->toDateString() > $d ? 'future' : 'active');
    }

    public function stateBadge(): array
    {
        return ['active' => ['Active', 'green'], 'expired' => ['Expired', 'slate'], 'future' => ['Starts '.$this->valid_from->format('d M'), 'blue'], 'revoked' => ['Revoked', 'red']][$this->state()];
    }
}
