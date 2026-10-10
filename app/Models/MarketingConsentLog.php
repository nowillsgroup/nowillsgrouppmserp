<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class MarketingConsentLog extends Model
{
    use BelongsToHotel;

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['opted_in' => 'boolean'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
