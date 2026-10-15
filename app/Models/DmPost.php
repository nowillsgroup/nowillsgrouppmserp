<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.2 A social post on the content calendar: draft → review → approved → published (four-eyes approval), with results. */
class DmPost extends Model
{
    use BelongsToHotel;

    public const STATUSES = ['draft' => ['Draft', 'slate'], 'review' => ['Awaiting approval', 'amber'], 'approved' => ['Approved', 'blue'], 'rejected' => ['Changes requested', 'red'],
        'published' => ['Published', 'green'], 'cancelled' => ['Cancelled', 'slate']];

    public const PILLARS = ['rooms' => 'Rooms & stays', 'food' => 'Food & drink', 'events' => 'Events & weddings', 'offers' => 'Offers & packages', 'culture' => 'Accra & culture',
        'people' => 'Our people', 'ugc' => 'Guest content (UGC)', 'news' => 'News & awards'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['channels' => 'array', 'publish_log' => 'array', 'scheduled_at' => 'datetime', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'published_at' => 'datetime'];
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function campaign()
    {
        return $this->belongsTo(DmCampaign::class, 'campaign_id');
    }

    public function link()
    {
        return $this->belongsTo(DmLink::class, 'link_id');
    }

    public function channelModels()
    {
        return DmChannel::whereIn('id', $this->channels ?: [0])->orderBy('name')->get();
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? ucfirst($this->status);
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->status][1] ?? 'slate';
    }

    /** approved but not published an hour after its slot */
    public function isMissed(): bool
    {
        return $this->status === 'approved' && $this->scheduled_at->lt(now()->subHour());
    }

    public function engagementRate(): ?float
    {
        return $this->reach > 0 ? round(100 * $this->engagements / $this->reach, 2) : null;
    }
}
