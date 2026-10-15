<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** v7.38 One line on a ticket outlet sale. */
class ClubTicketSaleItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['unit_price' => 'decimal:2', 'line_total' => 'decimal:2', 'tax_rate' => 'decimal:4', 'tax' => 'decimal:2'];
    }

    public function sale()
    {
        return $this->belongsTo(ClubTicketSale::class, 'sale_id');
    }

    public function type()
    {
        return $this->belongsTo(ClubTicketType::class, 'ticket_type_id');
    }
}
