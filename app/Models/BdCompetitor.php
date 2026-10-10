<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.3 A hotel in the competitive set, with its profile and positioning. */
class BdCompetitor extends Model
{
    use BelongsToHotel;

    public const POSITIONING = ['luxury' => 'Luxury', 'upscale' => 'Upscale', 'midscale' => 'Midscale', 'economy' => 'Economy', 'apartments' => 'Serviced apartments'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'distance_km' => 'decimal:1'];
    }

    public function lines()
    {
        return $this->hasMany(BdRateShopLine::class, 'competitor_id');
    }
}
