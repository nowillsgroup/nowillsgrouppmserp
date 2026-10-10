<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v7.1 A hotel information page on the TV (restaurants, spa, pool, Wi-Fi, safety, local guide …). */
class TvPage extends Model
{
    use BelongsToHotel;

    public const CATEGORIES = ['dining' => 'Dining', 'wellness' => 'Spa & wellness', 'facilities' => 'Facilities', 'services' => 'Services', 'explore' => 'Explore Ghana',
        'offers' => 'Offers', 'safety' => 'Safety', 'info' => 'Hotel information'];

    public const ICONS = ['book', 'coffee', 'cup', 'spa', 'dumbbell', 'wifi', 'car', 'plane', 'globe', 'star', 'shield', 'bell', 'key', 'phone', 'tree', 'home', 'money', 'calendar'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
