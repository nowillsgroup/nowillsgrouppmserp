<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.2 A digital channel: a social profile, an online listing (Google Business, TripAdvisor, OTA page, website) or an ad account. */
class DmChannel extends Model
{
    use BelongsToHotel;

    /** platform => [label, kind, colour] – kind: social | listing | ads */
    public const PLATFORMS = [
        'facebook' => ['Facebook', 'social', '#1877f2'], 'instagram' => ['Instagram', 'social', '#c13584'], 'x' => ['X (Twitter)', 'social', '#111827'],
        'linkedin' => ['LinkedIn', 'social', '#0a66c2'], 'tiktok' => ['TikTok', 'social', '#010101'], 'youtube' => ['YouTube', 'social', '#ff0000'],
        'whatsapp' => ['WhatsApp Business', 'social', '#25d366'],
        'google_business' => ['Google Business Profile', 'listing', '#4285f4'], 'tripadvisor' => ['Tripadvisor', 'listing', '#00af87'],
        'booking_com' => ['Booking.com page', 'listing', '#003580'], 'expedia' => ['Expedia page', 'listing', '#fddb32'], 'website' => ['Hotel website', 'listing', '#0f766e'],
        'google_ads' => ['Google Ads', 'ads', '#fbbc04'], 'meta_ads' => ['Meta Ads (Facebook / Instagram)', 'ads', '#0866ff'], 'tiktok_ads' => ['TikTok Ads', 'ads', '#fe2c55'],
        'metasearch' => ['Metasearch (Google Hotel Ads / Trivago)', 'ads', '#e32851'], 'other' => ['Other', 'social', '#64748b'],
    ];

    public const KINDS = ['social' => 'Social profiles', 'listing' => 'Listings & website', 'ads' => 'Ad accounts'];

    /** listing-health checklist */
    public const HEALTH_ITEMS = [
        'profile_complete' => 'Name, address, phone and category complete and consistent (NAP)',
        'photos_recent' => 'Photos refreshed in the last 90 days (rooms, F&B, pool, events)',
        'description_current' => 'Description and highlights current (renovations, new outlets)',
        'amenities_accurate' => 'Amenities / attributes accurate (Wi-Fi, parking, airport shuttle, pool)',
        'hours_contact' => 'Outlet hours, check-in / out times and contact details correct',
        'reviews_answered' => 'Every review in the last 30 days has a management response',
        'booking_link' => 'Book-direct / website link works and is UTM-tagged',
        'rate_parity' => 'Rates shown match the hotel website (parity)',
    ];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'health' => 'array', 'audited_at' => 'datetime', 'rating' => 'decimal:2'];
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function auditor()
    {
        return $this->belongsTo(User::class, 'audited_by');
    }

    public function stats()
    {
        return $this->hasMany(DmChannelStat::class, 'channel_id');
    }

    public function kind(): string
    {
        return self::PLATFORMS[$this->platform][1] ?? 'social';
    }

    public function platformLabel(): string
    {
        return self::PLATFORMS[$this->platform][0] ?? ucfirst($this->platform);
    }

    public function colour(): string
    {
        return self::PLATFORMS[$this->platform][2] ?? '#64748b';
    }

    /** % of checklist items ticked at the last audit (null = never audited) */
    public function healthScore(): ?int
    {
        if (! $this->audited_at || ! is_array($this->health)) {
            return null;
        }

        return (int) round(100 * count(array_filter(array_intersect_key($this->health, self::HEALTH_ITEMS))) / count(self::HEALTH_ITEMS));
    }
}
