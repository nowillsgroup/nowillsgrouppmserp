<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class MarketingSegment extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['rules' => 'array', 'is_active' => 'boolean'];
    }

    public function campaigns()
    {
        return $this->hasMany(MarketingCampaign::class, 'segment_id');
    }

    public function journeys()
    {
        return $this->hasMany(MarketingJourney::class, 'segment_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Guests matching the rules (no consent filter – that is applied per channel when sending). */
    public function guests()
    {
        return \App\Services\CrmService::segmentQuery($this->rules ?? [], $this->match);
    }

    public function describe(): string
    {
        return \App\Services\CrmService::describeRules($this->rules ?? [], $this->match);
    }
}
