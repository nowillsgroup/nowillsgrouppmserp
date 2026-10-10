<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class SupplierPayment extends Model
{
    use BelongsToHotel;

    protected $table = 'supplier_payments';

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'amount' => 'decimal:2', 'allocated' => 'decimal:2', 'cancelled_at' => 'datetime', 'wht_rate' => 'float', 'wht_base' => 'decimal:2', 'wht_amount' => 'decimal:2', 'wvat_base' => 'decimal:2', 'wvat_amount' => 'decimal:2'];
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function paymentMode()
    {
        return $this->belongsTo(PaymentMode::class);
    }

    public function allocations()
    {
        return $this->hasMany(ApAllocation::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function unapplied(): float
    {
        return $this->cancelled_at ? 0.0 : round((float) $this->amount - (float) $this->allocated, 2);
    }

    /** v4.4: what actually left the bank / till (gross less tax withheld for GRA). */
    public function netPaid(): float
    {
        return round((float) $this->amount - (float) $this->wht_amount - (float) $this->wvat_amount, 2);
    }

    public function withheld(): float
    {
        return round((float) $this->wht_amount + (float) $this->wvat_amount, 2);
    }
}
