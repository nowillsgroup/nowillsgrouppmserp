<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockTakeLine extends Model
{
    protected $table = 'stock_take_lines';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['system_qty' => 'float', 'counted_qty' => 'float', 'unit_cost' => 'float'];
    }

    public function item()
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function varianceQty(): ?float
    {
        return $this->counted_qty === null ? null : round($this->counted_qty - $this->system_qty, 2);
    }

    public function varianceValue(): float
    {
        return round(($this->varianceQty() ?? 0) * $this->unit_cost, 2);
    }
}
