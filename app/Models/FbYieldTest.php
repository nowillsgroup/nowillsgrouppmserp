<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.8 Butcher / yield test: how much of an ingredient as purchased is usable after trimming and cooking loss. */
class FbYieldTest extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['tested_on' => 'date', 'gross_qty' => 'float', 'trim_qty' => 'float', 'cooking_loss_qty' => 'float', 'usable_qty' => 'float',
            'yield_pct' => 'float', 'unit_cost' => 'float', 'applied' => 'boolean'];
    }

    public function item()
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function applier()
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    /** Cost of one usable unit (purchase cost ÷ yield). */
    public function usableCost(): float
    {
        return $this->yield_pct > 0 ? round($this->unit_cost * 100 / $this->yield_pct, 2) : 0.0;
    }
}
