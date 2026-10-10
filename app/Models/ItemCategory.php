<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class ItemCategory extends Model
{
    use BelongsToHotel;

    protected $table = 'item_categories';

    protected $guarded = ['id', 'hotel_id'];

    public function items()
    {
        return $this->hasMany(InventoryItem::class);
    }
}
