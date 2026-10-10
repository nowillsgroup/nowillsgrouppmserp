<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.3 One competitor's rate in a rate shop. */
class BdRateShopLine extends Model
{
    use BelongsToHotel;

    public $timestamps = false;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['rate' => 'decimal:2', 'sold_out' => 'boolean'];
    }

    public function competitor()
    {
        return $this->belongsTo(BdCompetitor::class, 'competitor_id');
    }

    public function shop()
    {
        return $this->belongsTo(BdRateShop::class, 'shop_id');
    }
}
