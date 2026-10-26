<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v4.8 Goods received note: one delivery against a purchase order (partial deliveries allowed). */
class GoodsReceipt extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return [
            'date' => 'date', 'fx_date' => 'date',
            'fx_rate' => 'decimal:4', 'fx_subtotal' => 'decimal:2', 'fx_tax' => 'decimal:2',
            'fx_total' => 'decimal:2', 'fx_rejected_value' => 'decimal:2',
        ];
    }

    public function isForeign(): bool
    {
        return filled($this->currency)
            && strtoupper(trim((string) $this->currency)) !== strtoupper(trim((string) (hotel()?->currency ?: 'GHS')));
    }

    public function fxLabel(?float $amount = null): string
    {
        $code = strtoupper(trim((string) ($this->currency ?: hotel()?->currency ?: 'GHS')));
        $value = $amount ?? ($this->isForeign() ? (float) $this->fx_total : (float) $this->total);

        return $code.' '.number_format($value, 2);
    }

    public function order()
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function lines()
    {
        return $this->hasMany(GoodsReceiptLine::class);
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /** Delivered on or before the PO's expected date (no expected date = on time). */
    public function onTime(): bool
    {
        $exp = $this->order?->expected_date;

        return ! $exp || $this->date->toDateString() <= $exp->toDateString();
    }
}
