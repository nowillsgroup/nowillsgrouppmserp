<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrderItem extends Model
{
    protected $table = 'purchase_order_items';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fx_unit_rate' => 'decimal:4', 'fx_amount' => 'decimal:2', 'fx_tax_amount' => 'decimal:2'];
    }

    public function order()
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function item()
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    /** v4.8 quantity still to come (ordered − accepted). */
    public function outstanding(): float
    {
        return max(0.0, round((float) $this->qty - (float) $this->received_qty, 2));
    }

    /** v4.8 ordered above the supplier's agreed price-list rate (beyond the tolerance). */
    public function aboveList(): bool
    {
        if ($this->list_rate === null) {
            return false;
        }
        $tol = (float) setting('po_price_tolerance_pct', '2');

        return (float) $this->rate > round((float) $this->list_rate * (1 + $tol / 100), 2) + 0.004;
    }
}
