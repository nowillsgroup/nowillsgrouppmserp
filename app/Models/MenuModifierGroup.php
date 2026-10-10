<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class MenuModifierGroup extends Model
{
    use BelongsToHotel;

    protected $table = 'menu_modifier_groups';

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function modifiers()
    {
        return $this->hasMany(MenuModifier::class)->orderBy('sort_order')->orderBy('name');
    }

    public function items()
    {
        return $this->belongsToMany(MenuItem::class, 'menu_item_modifier_groups')->withPivot('sort_order')->orderBy('menu_item_modifier_groups.sort_order');
    }
}
