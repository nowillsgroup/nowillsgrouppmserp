<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.2 A paid / digital campaign: objective, platform, UTM campaign tag, budget and daily ad statistics. */
class DmCampaign extends Model
{
    use BelongsToHotel;

    public const OBJECTIVES = ['awareness' => 'Awareness', 'traffic' => 'Website traffic', 'engagement' => 'Engagement', 'leads' => 'Leads / enquiries', 'bookings' => 'Direct bookings'];

    public const STATUSES = ['draft' => ['Draft', 'slate'], 'active' => ['Active', 'green'], 'paused' => ['Paused', 'amber'], 'ended' => ['Ended', 'blue']];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'budget' => 'decimal:2', 'target_roas' => 'decimal:2'];
    }

    public function channel()
    {
        return $this->belongsTo(DmChannel::class, 'channel_id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function promo()
    {
        return $this->belongsTo(PromoCode::class, 'promo_code_id');
    }

    public function adStats()
    {
        return $this->hasMany(DmAdStat::class, 'campaign_id');
    }

    public function links()
    {
        return $this->hasMany(DmLink::class, 'campaign_id');
    }

    public function posts()
    {
        return $this->hasMany(DmPost::class, 'campaign_id');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? ucfirst($this->status);
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->status][1] ?? 'slate';
    }
}
