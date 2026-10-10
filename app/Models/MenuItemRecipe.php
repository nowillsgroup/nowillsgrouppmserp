<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class MenuItemRecipe extends Model
{
    use BelongsToHotel;

    protected $table = 'menu_item_recipes';

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return [];
    }

    public function menuItem()
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
