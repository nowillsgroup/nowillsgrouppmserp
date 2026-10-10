<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.2 Monthly KPI snapshot of a digital channel (followers, reach, impressions, engagements, clicks). */
class DmChannelStat extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['month' => 'date'];
    }

    public function channel()
    {
        return $this->belongsTo(DmChannel::class, 'channel_id');
    }
}
