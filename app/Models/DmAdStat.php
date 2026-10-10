<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.2 One day of paid-media results for a campaign (entered manually or imported from the platform's CSV export). */
class DmAdStat extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'spend' => 'decimal:2', 'conversion_value' => 'decimal:2'];
    }

    public function campaign()
    {
        return $this->belongsTo(DmCampaign::class, 'campaign_id');
    }
}
