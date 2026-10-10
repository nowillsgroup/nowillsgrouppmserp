<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class MinibarItem extends Model
{
    use BelongsToHotel;

    protected $table = 'minibar_items';

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'price' => 'decimal:2'];
    }

    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
