<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class MarketingMessage extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['clicked_at' => 'datetime', 'unsubscribed_at' => 'datetime'];
    }

    public function campaign()
    {
        return $this->belongsTo(MarketingCampaign::class, 'campaign_id');
    }

    public function journey()
    {
        return $this->belongsTo(MarketingJourney::class, 'journey_id');
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function sourceLabel(): string
    {
        return $this->campaign?->name ?? ($this->journey ? 'Automation: '.$this->journey->name : 'Test');
    }
}
