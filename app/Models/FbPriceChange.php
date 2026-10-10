<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.8 A menu price change made from the pricing screen, with the plate cost at the time and the reason. */
class FbPriceChange extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['effective_on' => 'date', 'old_price' => 'float', 'new_price' => 'float', 'cost_at' => 'float'];
    }

    public function menuItem()
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
