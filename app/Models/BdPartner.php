<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.3 A strategic partnership (airline, tour operator, embassy, bank, event organiser …) with its production. */
class BdPartner extends Model
{
    use BelongsToHotel;

    public const TYPES = ['airline' => 'Airline', 'tour_operator' => 'Tour operator / DMC', 'embassy' => 'Embassy / high commission', 'corporate' => 'Corporate alliance',
        'event_organiser' => 'Event / conference organiser', 'bank' => 'Bank / card programme', 'education' => 'University / school', 'ngo' => 'NGO / development agency',
        'government' => 'Government / tourism authority', 'platform' => 'Travel platform', 'other' => 'Other'];

    public const AGREEMENTS = ['referral' => 'Referral', 'commission' => 'Commission', 'co_marketing' => 'Co-marketing', 'barter' => 'Barter / contra', 'preferred' => 'Preferred hotel', 'mou' => 'MoU'];

    public const STATUSES = ['prospect' => ['Prospect', 'slate'], 'negotiating' => ['Negotiating', 'amber'], 'active' => ['Active', 'green'], 'expired' => ['Expired', 'red'], 'terminated' => ['Terminated', 'red']];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'review_date' => 'date', 'commission_pct' => 'decimal:2', 'target_revenue' => 'decimal:2'];
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function source()
    {
        return $this->belongsTo(BusinessSource::class, 'business_source_id');
    }

    public function promo()
    {
        return $this->belongsTo(PromoCode::class, 'promo_code_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function initiatives()
    {
        return $this->hasMany(BdInitiative::class, 'partner_id');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? ucfirst($this->status);
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->status][1] ?? 'slate';
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function hasTracking(): bool
    {
        return (bool) ($this->business_source_id || $this->promo_code_id || $this->company_id);
    }
}
