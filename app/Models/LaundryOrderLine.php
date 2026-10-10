<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LaundryOrderLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rate' => 'decimal:2', 'amount' => 'decimal:2'];
    }

    public function order()
    {
        return $this->belongsTo(LaundryOrder::class, 'laundry_order_id');
    }

    public function item()
    {
        return $this->belongsTo(LaundryItem::class, 'laundry_item_id');
    }
}
