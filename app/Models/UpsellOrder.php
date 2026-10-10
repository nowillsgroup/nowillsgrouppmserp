<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v4.1 – an upsell sold to (or requested by) a guest for one stay. */
class UpsellOrder extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    /** status => [label, badge colour] */
    public const STATUSES = [
        'requested' => ['Awaiting confirmation', 'amber'],
        'confirmed' => ['Confirmed', 'green'],
        'declined' => ['Declined', 'red'],
        'cancelled' => ['Cancelled', 'slate'],
    ];

    public const CHANNELS = ['pre_arrival' => 'Pre-arrival', 'desk' => 'Front desk', 'portal' => 'In-house portal'];

    protected function casts(): array
    {
        return ['unit_price' => 'decimal:2', 'amount' => 'decimal:2', 'incentive_amount' => 'decimal:2', 'service_date' => 'date',
            'confirmed_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function offer()
    {
        return $this->belongsTo(UpsellOffer::class, 'upsell_offer_id');
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function stay()
    {
        return $this->belongsTo(ReservationRoom::class, 'reservation_room_id');
    }

    public function charge()
    {
        return $this->belongsTo(StayFixedCharge::class, 'stay_fixed_charge_id');
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'sold_by');
    }

    public function confirmer()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function fromType()
    {
        return $this->belongsTo(RoomType::class, 'from_room_type_id');
    }

    public function toType()
    {
        return $this->belongsTo(RoomType::class, 'to_room_type_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['requested', 'confirmed'], true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? $this->status;
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->status][1] ?? 'slate';
    }

    public function channelLabel(): string
    {
        return self::CHANNELS[$this->channel] ?? $this->channel;
    }

    /** Amount already posted to the folio through the linked fixed charge (non-void lines). */
    public function postedAmount(): float
    {
        if (! $this->stay_fixed_charge_id) {
            return 0.0;
        }

        return (float) FolioTransaction::where('source_type', 'fixed')->where('source_id', $this->stay_fixed_charge_id)->where('is_void', false)->sum('amount');
    }
}
