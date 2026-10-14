<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class Reservation extends Model
{
    use BelongsToHotel;

    protected $table = 'reservations';

    protected $guarded = ['id', 'hotel_id'];

    public const STATUSES = ['tentative', 'confirmed', 'checked_in', 'checked_out', 'cancelled', 'no_show'];

    public function getNightsAttribute(): int
    {
        return max(1, $this->arrival_date->diffInDays($this->departure_date));
    }

    /** Recompute header status from its room lines. */
    public function syncStatus(): void
    {
        $st = $this->rooms()->pluck('status');
        if ($st->isEmpty()) {
            return;
        }
        if ($st->contains('checked_in')) {
            $this->status = 'checked_in';
        } elseif ($st->every(fn ($s) => in_array($s, ['checked_out', 'cancelled', 'no_show'])) && $st->contains('checked_out')) {
            $this->status = 'checked_out';
        } elseif ($st->every(fn ($s) => $s === 'cancelled')) {
            $this->status = 'cancelled';
        } elseif ($st->every(fn ($s) => $s === 'no_show')) {
            $this->status = 'no_show';
        }
        $this->save();
    }

    protected function casts(): array
    {
        return [
            'arrival_date' => 'date',
            'departure_date' => 'date',
            'cancelled_at' => 'datetime',
            'prechecked_in_at' => 'datetime',
            'formc_prefill' => 'array', // v3.4
            'cancellation_fee' => 'decimal:2',
            'promo_discount' => 'decimal:2',
            'channel_amount' => 'decimal:2',
            'channel_commission' => 'decimal:2',
            'channel_amount_fx' => 'decimal:2',      // v7.50 the figure the channel sent, in its own currency
            'channel_fx_rate' => 'decimal:4',
            'channel_fx_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        // v2.6: credit new bookings to the campaign / automation that preceded them (never blocks a booking)
        static::created(fn (Reservation $r) => rescue(fn () => \App\Services\CrmService::attribute($r), null, true));
        // v3.3: a cancelled / no-show booking releases its planned airport transfers
        static::updated(function (Reservation $r) {
            if ($r->wasChanged('status') && in_array($r->status, ['cancelled', 'no_show'], true)) {
                rescue(fn () => \App\Services\TransportService::onReservationClosed($r), null, true);
                rescue(fn () => \App\Services\ConciergeService::onReservationClosed($r), null, true); // v5.9 activity bookings
            }
        });
    }

    public function promo()
    {
        return $this->belongsTo(PromoCode::class, 'promo_code_id');
    }

    public function campaign()
    {
        return $this->belongsTo(MarketingCampaign::class, 'campaign_id');
    }

    public function journey()
    {
        return $this->belongsTo(MarketingJourney::class, 'journey_id');
    }

    public function businessSource()
    {
        return $this->belongsTo(BusinessSource::class);
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function rateContract()
    {
        return $this->belongsTo(RateContract::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function source()
    {
        return $this->belongsTo(BusinessSource::class, 'business_source_id');
    }

    public function rooms()
    {
        return $this->hasMany(ReservationRoom::class);
    }

    public function folios()
    {
        return $this->hasMany(Folio::class);
    }

    /** Immutable reservation versions and lifecycle events. */
    public function amendments()
    {
        return $this->hasMany(ReservationAmendment::class)->orderByDesc('version');
    }

    /** v4.0 – the API client that created the reservation (partner / website) */
    public function apiClient()
    {
        return $this->belongsTo(ApiClient::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Public online check-in link (token created on first use). */
    public function checkinUrl(): string
    {
        if (! $this->checkin_token) {
            $this->forceFill(['checkin_token' => \Illuminate\Support\Str::random(40)])->save();
        }

        return route('checkin.show', $this->checkin_token);
    }

    /** v4.1 – private link to the guest's "enhance your stay" offers page. */
    public function offersUrl(): string
    {
        if (! $this->offer_token) {
            $this->forceFill(['offer_token' => \Illuminate\Support\Str::random(40)])->save();
        }

        return route('offers.show', $this->offer_token);
    }

    public function upsellOrders()
    {
        return $this->hasMany(UpsellOrder::class);
    }

    public function upsellPresentations()
    {
        return $this->hasMany(UpsellPresentation::class);
    }

    public function onlineCheckinOpen(): bool
    {
        return in_array($this->status, ['confirmed', 'tentative']) && $this->departure_date->gt(bdate()) && setting('online_checkin_enabled', '1') === '1';
    }

    public function commissions()
    {
        return $this->hasMany(AgentCommission::class);
    }

    /** v2.8 two-way channel manager connection that delivered this booking. */
    public function channel()
    {
        return $this->belongsTo(ChannelConnection::class, 'channel_connection_id');
    }

    public function block()
    {
        return $this->belongsTo(RoomBlock::class, 'room_block_id');
    }

    /** v7.4 the partner-portal booker who made the booking */
    public function partnerUser()
    {
        return $this->belongsTo(PartnerUser::class);
    }
}
