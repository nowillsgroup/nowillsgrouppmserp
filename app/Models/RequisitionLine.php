<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequisitionLine extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['qty_requested' => 'float', 'qty_approved' => 'float', 'qty_issued' => 'float'];
    }

    public function requisition()
    {
        return $this->belongsTo(Requisition::class);
    }

    public function item()
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    /** What the store may issue: the approved quantity (or requested while not yet approved). */
    public function allowed(): float
    {
        return (float) ($this->qty_approved ?? $this->qty_requested);
    }

    public function outstanding(): float
    {
        if (! in_array($this->requisition?->status, Requisition::OPEN_FOR_ISSUE, true) || $this->qty_approved === null) {
            return 0.0;
        }

        return max(0.0, round($this->allowed() - (float) $this->qty_issued, 2));
    }

    public function cut(): bool
    {
        return $this->qty_approved !== null && $this->qty_approved + 0.0001 < $this->qty_requested;
    }
}
