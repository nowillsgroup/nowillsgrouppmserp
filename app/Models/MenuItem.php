<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class MenuItem extends Model
{
    use BelongsToHotel;

    protected $table = 'menu_items';

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return [
            'is_available' => 'boolean',
            'price' => 'decimal:2',
            'allergens' => 'array', 'may_contain' => 'array', 'allergens_verified_at' => 'datetime', // v5.0
        ];
    }

    public function category()
    {
        return $this->belongsTo(MenuCategory::class, 'menu_category_id');
    }

    public function printer()
    {
        return $this->belongsTo(KotPrinter::class, 'kot_printer_id');
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function recipes()
    {
        return $this->hasMany(MenuItemRecipe::class);
    }

    public function modifierGroups()
    {
        return $this->belongsToMany(MenuModifierGroup::class, 'menu_item_modifier_groups')->withPivot('sort_order')->orderBy('menu_item_modifier_groups.sort_order');
    }

    /** v6.8 standard recipe card header */
    public function card()
    {
        return $this->hasOne(FbRecipeCard::class);
    }

    /** Public menu photo, or null when none has been saved. Shared demo art and hotel uploads both live under uploads/menu/. */
    public function imageUrl(): ?string
    {
        $path = (string) $this->image_path;
        if ($path === '' || str_contains($path, '..') || ! str_starts_with($path, 'uploads/menu/')) {
            return null;
        }

        return is_file(public_path($path)) ? asset($path) : null;
    }
}
