<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class MarketingCampaign extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    public const STATUSES = ['draft' => 'Draft', 'scheduled' => 'Scheduled', 'sending' => 'Sending', 'sent' => 'Sent', 'cancelled' => 'Cancelled'];

    public const TONES = ['draft' => 'slate', 'scheduled' => 'amber', 'sending' => 'blue', 'sent' => 'green', 'cancelled' => 'red'];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'started_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    public function segment()
    {
        return $this->belongsTo(MarketingSegment::class, 'segment_id');
    }

    public function promo()
    {
        return $this->belongsTo(PromoCode::class, 'promo_code_id');
    }

    public function messages()
    {
        return $this->hasMany(MarketingMessage::class, 'campaign_id');
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class, 'campaign_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'scheduled']);
    }
}
