<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.5 A package used at an outlet: covers served / allowance spent, and any extra covers charged to the room. */
class PackageRedemption extends Model
{
    use BelongsToHotel;

    public const OVERAGE = ['folio' => 'Charge to room', 'paid' => 'Paid at the outlet', 'waived' => 'Waived (complimentary)'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['business_date' => 'date', 'amount' => 'decimal:2', 'extra_amount' => 'decimal:2', 'voided_at' => 'datetime'];
    }

    public function entitlement()
    {
        return $this->belongsTo(PackageEntitlement::class, 'package_entitlement_id');
    }

    public function stay()
    {
        return $this->belongsTo(ReservationRoom::class, 'reservation_room_id');
    }

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function voider()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function folioTransaction()
    {
        return $this->belongsTo(FolioTransaction::class);
    }
}
