<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.2 Money in / out on a lease: receipts (with tax withheld), refunds, and the security deposit (held, applied, refunded). */
class LeasePayment extends Model
{
    use BelongsToHotel;

    public const KINDS = ['payment' => 'Receipt', 'refund' => 'Refund of credit', 'deposit' => 'Deposit received', 'deposit_refund' => 'Deposit refunded', 'deposit_applied' => 'Deposit applied to account'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'voided_at' => 'datetime', 'amount' => 'decimal:2', 'wht_amount' => 'decimal:2', 'allocations' => 'array'];
    }

    public function lease()
    {
        return $this->belongsTo(Lease::class);
    }

    public function paymentMode()
    {
        return $this->belongsTo(PaymentMode::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Effect on the tenant's account: receipts and applied deposit reduce it, refunds increase it; deposits held don't touch it. */
    public function accountEffect(): float
    {
        if ($this->voided_at) {
            return 0.0;
        }

        return match ($this->kind) {
            'payment' => -((float) $this->amount + (float) $this->wht_amount),
            'deposit_applied' => -(float) $this->amount,
            'refund' => (float) $this->amount,
            default => 0.0,
        };
    }

    /** Effect on the deposit held. */
    public function depositEffect(): float
    {
        if ($this->voided_at) {
            return 0.0;
        }

        return match ($this->kind) {
            'deposit' => (float) $this->amount,
            'deposit_refund', 'deposit_applied' => -(float) $this->amount,
            default => 0.0,
        };
    }
}
