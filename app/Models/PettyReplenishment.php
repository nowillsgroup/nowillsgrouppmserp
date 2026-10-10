<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v4.7 A request to top a float back up to its imprest (requested → approved → paid, or rejected). */
class PettyReplenishment extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected $attributes = ['status' => 'requested'];

    protected function casts(): array
    {
        return ['date' => 'date', 'paid_on' => 'date', 'amount' => 'decimal:2', 'approved_at' => 'datetime'];
    }

    public function float()
    {
        return $this->belongsTo(PettyFloat::class, 'petty_float_id');
    }

    public function txns()
    {
        return $this->hasMany(PettyTxn::class)->orderBy('date')->orderBy('id');
    }

    public function paymentMode()
    {
        return $this->belongsTo(PaymentMode::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
