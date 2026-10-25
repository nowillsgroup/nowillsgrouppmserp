<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class FolioTransaction extends Model
{
    use BelongsToHotel;

    protected $table = 'folio_transactions';

    protected $guarded = ['id', 'hotel_id'];

    public function getTotalAttribute(): float
    {
        return (float) $this->amount + (float) $this->tax_amount;
    }

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'opening_date' => 'date',
            'due_date' => 'date',
            'is_void' => 'boolean',
            'amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
        ];
    }

    /** True for a cutover receivable balance that did not replay a historical posting. */
    public function isOpening(): bool
    {
        return $this->opening_date !== null;
    }

    public function folio()
    {
        return $this->belongsTo(Folio::class);
    }

    public function paymentMode()
    {
        return $this->belongsTo(PaymentMode::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function poster()
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
