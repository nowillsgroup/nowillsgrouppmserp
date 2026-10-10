<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v4.1 – offers shown to a reservation on one channel (conversion funnel: presented → opened → converted). */
class UpsellPresentation extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['offer_ids' => 'array', 'sent_at' => 'datetime', 'opened_at' => 'datetime', 'converted_at' => 'datetime'];
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }
}
