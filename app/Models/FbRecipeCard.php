<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.8 Standard recipe card header for a menu item (the ingredient lines stay in menu_item_recipes). */
class FbRecipeCard extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime', 'target_pct' => 'float'];
    }

    public function menuItem()
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function editor()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
