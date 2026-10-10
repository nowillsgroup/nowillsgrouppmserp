<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v4.9 Tips & gratuities paid into the staff pool (credited to 2110 Service Charge Payable). */
class TipCollection extends Model
{
    use BelongsToHotel;

    public const SOURCES = ['card_tips' => 'Card tips (POS / terminal)', 'momo_tips' => 'Mobile Money tips', 'cash_box' => 'Cash tips box', 'banquet' => 'Banquet / event gratuity', 'other' => 'Other gratuity'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'cancelled_at' => 'datetime'];
    }

    public function paymentMode()
    {
        return $this->belongsTo(PaymentMode::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
