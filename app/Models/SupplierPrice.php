<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v4.8 Agreed supplier price for an item (price list / rate contract), valid for a date range. */
class SupplierPrice extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected $attributes = ['source' => 'manual', 'min_qty' => 0];

    protected function casts(): array
    {
        return ['rate' => 'decimal:2', 'min_qty' => 'decimal:2', 'valid_from' => 'date', 'valid_to' => 'date'];
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function item()
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function scopeValidOn($q, string $date)
    {
        return $q->whereDate('valid_from', '<=', $date)->where(fn ($w) => $w->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date));
    }

    public function state(?string $on = null): string
    {
        $on ??= bdate()->toDateString();

        return match (true) {
            $this->valid_from->toDateString() > $on => 'future',
            $this->valid_to && $this->valid_to->toDateString() < $on => 'expired',
            default => 'active',
        };
    }
}
