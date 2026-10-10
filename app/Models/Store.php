<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    use BelongsToHotel;

    protected $table = 'stores';

    protected $guarded = ['id', 'hotel_id'];

    public function movements()
    {
        return $this->hasMany(StockMovement::class);
    }
}
