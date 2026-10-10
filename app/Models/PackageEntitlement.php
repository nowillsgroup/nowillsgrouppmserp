<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.5 What one stay night earns from a package: covers / allowance usable on the service date. */
class PackageEntitlement extends Model
{
    use BelongsToHotel;

    public const STATUSES = ['open' => 'Open', 'partial' => 'Partly used', 'consumed' => 'Used', 'expired' => 'Expired (breakage)', 'cancelled' => 'Cancelled'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['night_date' => 'date', 'service_date' => 'date', 'consumed_date' => 'date', 'expired_date' => 'date', 'amount' => 'decimal:2',
            'consumed_amount' => 'decimal:2', 'breakage_amount' => 'decimal:2', 'is_allowance' => 'boolean'];
    }

    public function stay()
    {
        return $this->belongsTo(ReservationRoom::class, 'reservation_room_id');
    }

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function redemptions()
    {
        return $this->hasMany(PackageRedemption::class);
    }

    public function remainingQty(): int
    {
        return max(0, (int) $this->qty - (int) $this->consumed_qty);
    }

    public function remainingAmount(): float
    {
        return max(0, round((float) $this->amount - (float) $this->consumed_amount, 2));
    }

    public function statusTone(): string
    {
        return ['open' => 'blue', 'partial' => 'amber', 'consumed' => 'green', 'expired' => 'red', 'cancelled' => 'slate'][$this->status] ?? 'slate';
    }
}
