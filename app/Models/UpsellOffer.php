<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v4.1 – a sellable enhancement: paid room upgrade, add-on, early check-in or late check-out. */
class UpsellOffer extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    /** kind => [label, icon, default charge category] */
    public const KINDS = [
        'upgrade' => ['Room upgrade', 'layers', 'room'],
        'addon' => ['Add-on', 'star', 'misc'],
        'early_checkin' => ['Early check-in', 'login', 'room'],
        'late_checkout' => ['Late check-out', 'moon', 'room'],
    ];

    /** pricing => [label, unit suffix] */
    public const PRICING = [
        'per_stay' => ['Per stay', 'per stay'],
        'per_night' => ['Per night', 'per night'],
        'per_person' => ['Per person (once)', 'per person'],
        'per_person_night' => ['Per person per night', 'per person / night'],
        'rate_difference' => ['% of the rate difference (upgrades)', 'per night'],
    ];

    public const CHANNELS = ['pre_arrival' => 'Pre-arrival (e-mail / link)', 'desk' => 'Front desk', 'portal' => 'In-house (guest portal)'];

    public const INCENTIVES = ['none' => 'No incentive', 'pct' => '% of the sale', 'fixed' => 'Fixed amount per sale'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'diff_pct' => 'decimal:2', 'min_price' => 'decimal:2', 'incentive_value' => 'decimal:2',
            'room_type_ids' => 'array', 'source_ids' => 'array', 'channels' => 'array', 'apply_tax' => 'boolean', 'auto_confirm' => 'boolean',
            'is_active' => 'boolean', 'valid_from' => 'date', 'valid_to' => 'date'];
    }

    public function toRoomType()
    {
        return $this->belongsTo(RoomType::class, 'to_room_type_id');
    }

    public function orders()
    {
        return $this->hasMany(UpsellOrder::class);
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind][0] ?? $this->kind;
    }

    public function unitLabel(): string
    {
        return self::PRICING[$this->pricing][1] ?? '';
    }

    public function isTimed(): bool
    {
        return in_array($this->kind, ['early_checkin', 'late_checkout'], true);
    }

    public function timeLabel(): ?string
    {
        if (! $this->service_time) {
            return null;
        }
        $t = \Illuminate\Support\Carbon::parse($this->service_time)->format('H:i');

        return $this->kind === 'early_checkin' ? "from {$t}" : ($this->kind === 'late_checkout' ? "until {$t}" : $t);
    }

    /** Short price description for lists: "GH₵ 450 per person / night", "60% of the difference (min GH₵ 900) per night". */
    public function priceLabel(): string
    {
        if ($this->pricing === 'rate_difference') {
            return rtrim(rtrim(number_format((float) $this->diff_pct, 2), '0'), '.').'% of the rate difference'.($this->min_price ? ' (min '.money($this->min_price).')' : '').' per night';
        }

        return money($this->price).' '.$this->unitLabel();
    }

    public function incentiveLabel(): string
    {
        return match ($this->incentive_type) {
            'pct' => rtrim(rtrim(number_format((float) $this->incentive_value, 2), '0'), '.').'% of the sale',
            'fixed' => money($this->incentive_value).' per sale',
            default => '—',
        };
    }
}
