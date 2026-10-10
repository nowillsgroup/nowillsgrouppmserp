<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.9 Local guide entry: attractions, restaurants, shopping, health, embassies, emergency numbers – shown on the guest portal. */
class ConciergePlace extends Model
{
    use BelongsToHotel;

    public const CATEGORIES = ['attraction' => 'Sights & attractions', 'restaurant' => 'Restaurants', 'nightlife' => 'Bars & nightlife', 'shopping' => 'Shopping & markets', 'beach' => 'Beaches', 'health' => 'Hospitals & pharmacies', 'embassy' => 'Embassies', 'services' => 'Banks, forex & services', 'emergency' => 'Emergency'];

    public const ICONS = ['attraction' => 'star', 'restaurant' => 'cup', 'nightlife' => 'moon', 'shopping' => 'box', 'beach' => 'globe', 'health' => 'shield', 'embassy' => 'building', 'services' => 'wallet', 'emergency' => 'alert'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['distance_km' => 'decimal:1', 'portal_visible' => 'boolean'];
    }
}
