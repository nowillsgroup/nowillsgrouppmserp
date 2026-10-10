<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.2 An invoice line on a tenant's account (rent, service charge, utility recharge, late fee, damages, credit note). */
class LeaseCharge extends Model
{
    use BelongsToHotel;

    public const TYPES = ['rent' => 'Rent', 'service_charge' => 'Service charge', 'electricity' => 'Electricity', 'water' => 'Water', 'late_fee' => 'Late payment fee',
        'damage' => 'Damages', 'other' => 'Other charge', 'credit' => 'Credit note'];

    /** Rent goes to rent revenue; everything else to service charges & recharges. */
    public const RENT_TYPES = ['rent'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'period_start' => 'date', 'period_end' => 'date', 'due_date' => 'date', 'cancelled_at' => 'datetime',
            'amount' => 'decimal:2', 'tax' => 'decimal:2', 'total' => 'decimal:2', 'paid' => 'decimal:2'];
    }

    public function lease()
    {
        return $this->belongsTo(Lease::class);
    }

    public function creditOf()
    {
        return $this->belongsTo(LeaseCharge::class, 'credit_of_id');
    }

    public function credits()
    {
        return $this->hasMany(LeaseCharge::class, 'credit_of_id')->whereNull('cancelled_at');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function outstanding(): float
    {
        return $this->cancelled_at || $this->type === 'credit' ? 0.0 : round((float) $this->total - (float) $this->paid, 2);
    }

    public function state(): string
    {
        if ($this->cancelled_at) {
            return 'cancelled';
        }
        if ($this->type === 'credit') {
            return 'credit';
        }
        $o = $this->outstanding();

        return $o <= 0.004 ? 'paid' : ((float) $this->paid > 0 ? 'part' : 'open');
    }

    /** Revenue line this charge belongs to (credit notes follow the charge they reverse). */
    public function revenueType(): string
    {
        $t = $this->type === 'credit' ? ($this->creditOf?->type ?? 'other') : $this->type;

        return in_array($t, self::RENT_TYPES, true) ? 'rent' : 'other';
    }
}
