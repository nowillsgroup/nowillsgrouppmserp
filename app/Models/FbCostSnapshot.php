<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.8 Plate cost and menu price of a dish on a business date (taken by the night audit) – the basis of cost-creep alerts. */
class FbCostSnapshot extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['business_date' => 'date', 'cost' => 'float', 'price' => 'float'];
    }

    public function menuItem()
    {
        return $this->belongsTo(MenuItem::class);
    }
}
