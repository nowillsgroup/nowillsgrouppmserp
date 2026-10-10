<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class PosPayment extends Model
{
    use BelongsToHotel;

    protected $table = 'pos_payments';

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'tendered_amount' => 'decimal:2',
            'change_amount' => 'decimal:2',
            'business_date' => 'date',
            'received_at' => 'datetime',
        ];
    }

    public function order()
    {
        return $this->belongsTo(PosOrder::class, 'pos_order_id');
    }

    public function paymentMode()
    {
        return $this->belongsTo(PaymentMode::class);
    }

    public function refundOf()
    {
        return $this->belongsTo(self::class, 'refund_of_id');
    }

    public function refunds()
    {
        return $this->hasMany(self::class, 'refund_of_id');
    }

    public function refundedAmount(): float
    {
        return round((float) $this->refunds()->where('kind', 'refund')->sum('amount'), 2);
    }

    public function refundableAmount(): float
    {
        return round(max(0, (float) $this->amount - $this->refundedAmount()), 2);
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function isRefund(): bool
    {
        return $this->kind === 'refund';
    }

    public function isCash(): bool
    {
        return $this->paymentMode?->type === 'cash';
    }
}
