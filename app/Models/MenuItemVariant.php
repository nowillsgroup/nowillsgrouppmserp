<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class MenuItemVariant extends Model
{
    use BelongsToHotel;

    protected $table = 'menu_item_variants';

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:2',
            'cost' => 'decimal:2',
        ];
    }

    public function menuItem()
    {
        return $this->belongsTo(MenuItem::class);
    }
}
