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
        return ['date' => 'date', 'amount' => 'decimal:2', 'allocated' => 'decimal:2', 'cancelled_at' => 'datetime', 'wht_rate' => 'float', 'wht_base' => 'decimal:2',
            // v7.54 a payment made in the currency the supplier billed in
            'fx_date' => 'date', 'fx_amount' => 'decimal:2', 'fx_rate' => 'decimal:4', 'fx_diff' => 'decimal:2', 'wht_amount' => 'decimal:2', 'wvat_base' => 'decimal:2', 'wvat_amount' => 'decimal:2'];
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

    /**
     * What is left of this payment to apply elsewhere. A payment in the property's own currency can be part-applied
     * and the rest stays on account; a foreign payment cannot, because the gap between what it cost and what the
     * bills were booked at is an exchange difference that has already been posted, not a credit owed to the supplier.
     */
    public function unapplied(): float
    {
        if ($this->cancelled_at || $this->isForeign()) {
            // Foreign payments are required to settle a bill in that same currency. Their exchange difference is not a
            // supplier credit, and withholding is a GHS liability, not a remainder to apply to another bill.
            return 0.0;
        }

        return round((float) $this->amount - (float) $this->allocated, 2);
    }

    /** v7.54 was this payment made in a currency other than the property's own? */
    public function isForeign(): bool
    {
        return filled($this->currency)
            && strtoupper(trim((string) $this->currency)) !== strtoupper(trim((string) (hotel()?->currency ?: '')));
    }

    /** v7.54 the figure the bank's advice states, printable on any payment: "EUR 4,180.55", or the cedis amount. */
    public function fxLabel(?float $amount = null): string
    {
        $code = strtoupper(trim((string) ($this->currency ?: hotel()?->currency ?: 'GHS')));
        $value = $amount ?? ($this->isForeign() ? (float) $this->fx_amount : (float) $this->amount);

        return $code.' '.number_format($value, 2);
    }

    /** v7.54 the realised exchange difference: positive is a loss, negative a gain, zero when the rate did not move. */
    public function fxDifference(): float
    {
        return $this->isForeign() ? round((float) $this->fx_diff, 2) : 0.0;
    }

    /**
     * v4.4: what actually left the bank / till (gross less tax withheld for GRA). A foreign transfer is already the
     * net amount the bank sent the supplier in that currency; WHT is withheld separately in GHS, so do not subtract
     * it from the bank debit a second time.
     */
    public function netPaid(): float
    {
        return $this->isForeign()
            ? round((float) $this->amount, 2)
            : round((float) $this->amount - (float) $this->wht_amount - (float) $this->wvat_amount, 2);
    }

    public function withheld(): float
    {
        return round((float) $this->wht_amount + (float) $this->wvat_amount, 2);
    }
}
