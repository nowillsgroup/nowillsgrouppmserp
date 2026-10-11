<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** v7.38 One tender row on a ticket outlet sale (split payments). */
class ClubTicketPayment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function sale()
    {
        return $this->belongsTo(ClubTicketSale::class, 'sale_id');
    }

    public function paymentMode()
    {
        return $this->belongsTo(PaymentMode::class);
    }
}
