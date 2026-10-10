<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class MenuModifier extends Model
{
    use BelongsToHotel;

    protected $table = 'menu_modifiers';

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return [
            'price_delta' => 'decimal:2',
            'is_available' => 'boolean',
        ];
    }

    public function group()
    {
        return $this->belongsTo(MenuModifierGroup::class, 'menu_modifier_group_id');
    }
}
