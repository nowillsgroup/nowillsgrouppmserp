<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/**
 * A head of department's signature on their daily briefing, with the headline figures
 * that were on the screen when they signed it.
 */
class BriefingSignoff extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['business_date' => 'date', 'signed_at' => 'datetime', 'headline' => 'array'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
