<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.2 A tracked short link /go/{code}: logs clicks, appends UTM tags and credits resulting direct bookings. */
class DmLink extends Model
{
    use BelongsToHotel;

    /** common utm_medium values */
    public const MEDIUMS = ['social' => 'Organic social', 'paid_social' => 'Paid social', 'cpc' => 'Paid search (cpc)', 'display' => 'Display', 'email' => 'E-mail', 'influencer' => 'Influencer',
        'listing' => 'Listing / profile', 'qr' => 'QR code (print)', 'referral' => 'Referral', 'metasearch' => 'Metasearch'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'last_click_at' => 'datetime'];
    }

    public function campaign()
    {
        return $this->belongsTo(DmCampaign::class, 'campaign_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function clickLog()
    {
        return $this->hasMany(DmLinkClick::class, 'link_id');
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class, 'dm_link_id');
    }

    public function shortUrl(): string
    {
        return route('dm.go', $this->code);
    }
}
