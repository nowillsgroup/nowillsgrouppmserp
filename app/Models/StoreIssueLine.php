<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreIssueLine extends Model
{
    protected $guarded = ['id'];

    public function issue()
    {
        return $this->belongsTo(StoreIssue::class, 'store_issue_id');
    }

    public function item()
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function requisitionLine()
    {
        return $this->belongsTo(RequisitionLine::class);
    }
}
