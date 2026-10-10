<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosOrderItem extends Model
{
    protected $table = 'pos_order_items';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_cancelled' => 'boolean',
            'is_complimentary' => 'boolean',
            'modifier_amount' => 'decimal:2',
            'modifiers' => 'array',
            'is_open' => 'boolean',
            'comp_at' => 'datetime',
            'is_pending' => 'boolean',
            'fired_at' => 'datetime',
            'ready_at' => 'datetime',
            'served_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function order()
    {
        return $this->belongsTo(PosOrder::class, 'pos_order_id');
    }

    public function menuItem()
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function compBy()
    {
        return $this->belongsTo(User::class, 'comp_by');
    }

    public function kotPrinter()
    {
        return $this->belongsTo(KotPrinter::class, 'kot_printer_id');
    }
}
