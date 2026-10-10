<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.1 Receipt (or refund) on a member's account, allocated oldest-charge-first. */
class ClubPayment extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'allocations' => 'array', 'voided_at' => 'datetime', 'amount' => 'decimal:2'];
    }

    public function membership()
    {
        return $this->belongsTo(ClubMembership::class, 'club_membership_id');
    }

    public function paymentMode()
    {
        return $this->belongsTo(PaymentMode::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Signed effect on the member's balance: a payment reduces it, a refund increases it. */
    public function signed(): float
    {
        return $this->voided_at ? 0.0 : ($this->kind === 'refund' ? -1 : 1) * (float) $this->amount;
    }
}
