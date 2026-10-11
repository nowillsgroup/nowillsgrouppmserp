<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * v2.8 two-way OTA / channel-hub connection. Credentials (api_key, secret) are encrypted at rest and never serialised.
 */
class ChannelConnection extends Model
{
    use BelongsToHotel;

    public const PROVIDERS = ['hub' => 'Channel hub / OTA API (signed JSON)', 'simulator' => 'Built-in test channel (simulator)', 'mirror' => 'Mirror feed (HTTP JSON/XML adapter)', 'booking' => 'Booking.com EPS (External Connectivity Standard)'];

    public const PAYMENT_MODELS = ['hotel_collect' => 'Hotel collect – guest pays at the hotel', 'channel_collect' => 'Channel collect – prepaid to the OTA, bill the OTA'];

    public const ROUNDING = ['none' => 'No rounding', 'unit' => 'Whole currency unit', 'ten' => 'Nearest 10'];

    protected $guarded = ['id', 'hotel_id', 'token', 'secret', 'pushed_upto_id'];

    protected $hidden = ['api_key', 'secret'];

    protected $attributes = ['provider' => 'hub', 'payment_model' => 'hotel_collect', 'rate_rounding' => 'none', 'push_days' => 365,
        'is_active' => true, 'full_sync_due' => true, 'fail_count' => 0, 'pushed_upto_id' => 0, 'commission_pct' => 0, 'rate_markup_pct' => 0];

    protected static function booted(): void
    {
        static::creating(function (self $c) {
            $c->token ??= Str::random(40);
            $c->secret ??= Str::random(48);
        });
        static::saved(fn () => \App\Services\ChannelService::forgetCache());
        static::deleted(fn () => \App\Services\ChannelService::forgetCache());
    }

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted', 'secret' => 'encrypted', 'is_active' => 'boolean', 'full_sync_due' => 'boolean', 'last_push_ok' => 'boolean',
            'last_push_at' => 'datetime', 'next_retry_at' => 'datetime', 'last_booking_at' => 'datetime',
            'commission_pct' => 'decimal:2', 'rate_markup_pct' => 'decimal:2',
        ];
    }

    public function rotateSecret(): string
    {
        $this->forceFill(['secret' => Str::random(48)])->save();

        return $this->secret;
    }

    public function isSimulator(): bool
    {
        return $this->provider === 'simulator';
    }

    public function webhookUrl(): string
    {
        return route('channel.api.reservations', $this->token);
    }

    public function ariUrl(): string
    {
        return route('channel.api.ari', $this->token);
    }

    /** ok / failing / paused / pending – for the dashboard status pill. */
    public function health(): string
    {
        if (! $this->is_active) {
            return 'paused';
        }
        if ($this->last_push_ok === false) {
            return 'failing';
        }

        return $this->last_push_at ? 'ok' : 'pending';
    }

    public function source()
    {
        return $this->belongsTo(BusinessSource::class, 'business_source_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function roomMaps()
    {
        return $this->hasMany(ChannelRoomMap::class);
    }

    public function rateMaps()
    {
        return $this->hasMany(ChannelRateMap::class);
    }

    public function restrictions()
    {
        return $this->hasMany(ChannelRestriction::class);
    }

    public function pushes()
    {
        return $this->hasMany(ChannelPush::class);
    }

    public function messages()
    {
        return $this->hasMany(ChannelMessage::class);
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }
}
