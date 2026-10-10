<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class InventoryItem extends Model
{
    use BelongsToHotel;

    protected $table = 'inventory_items';

    protected $guarded = ['id', 'hotel_id'];

    public function stock(?int $storeId = null): float
    {
        $q = $this->movements();
        if ($storeId) {
            $q->where('store_id', $storeId);
        }

        return (float) $q->sum('qty');
    }

    /** v6.8 a blank trim yield means the whole unit is usable */
    protected static function booted(): void
    {
        static::saving(function (self $i) {
            if ($i->yield_pct === null || $i->yield_pct === '' || (float) $i->yield_pct <= 0) {
                $i->yield_pct = 100;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'allergens' => 'array', // v5.0
        ];
    }

    public function category()
    {
        return $this->belongsTo(ItemCategory::class, 'item_category_id');
    }

    /** v5.0 recipes this ingredient is used in */
    public function recipes()
    {
        return $this->hasMany(MenuItemRecipe::class);
    }

    public function movements()
    {
        return $this->hasMany(StockMovement::class);
    }
}
