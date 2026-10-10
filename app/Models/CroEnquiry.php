<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/**
 * v6.4 A reservations enquiry – one caller asking for rooms – and its outcome: booked here, referred to a sister hotel,
 * or lost as a denial (we could not offer) or a regret (we offered, the guest declined).
 */
class CroEnquiry extends Model
{
    use BelongsToHotel;

    public const CHANNELS = ['phone' => 'Phone', 'email' => 'E-mail', 'whatsapp' => 'WhatsApp', 'walk_in' => 'Walk-in / desk', 'web_chat' => 'Web chat',
        'travel_agent' => 'Travel agent', 'social' => 'Social media DM', 'referral' => 'Sister-hotel referral'];

    public const STATUSES = ['open' => ['Open', 'blue'], 'booked' => ['Booked', 'green'], 'referred' => ['Referred to sister hotel', 'purple'], 'lost' => ['Lost', 'red']];

    public const LOSS_TYPES = ['denial' => ['Denial', 'red'], 'regret' => ['Regret', 'amber']];

    /** Denials: the hotel could not offer what was asked (true turnaways – unconstrained demand). */
    public const DENIALS = ['sold_out' => 'Sold out – no rooms', 'room_type' => 'Requested room type full', 'restriction' => 'Minimum stay / closed to arrival',
        'group_size' => 'Group too large for inventory', 'occupancy' => 'Party too large for the room'];

    /** Regrets: we offered, the guest chose not to book. */
    public const REGRETS = ['price' => 'Price too high', 'location' => 'Location', 'product' => 'Room / facilities not suitable', 'dates' => 'Changed dates / plans',
        'competitor' => 'Booked another hotel', 'payment' => 'Deposit / payment terms', 'no_response' => 'No response to follow-up', 'shopping' => 'Only comparing prices', 'other' => 'Other'];

    public const CONTACT_TYPES = ['call_in' => 'Call received', 'call_out' => 'Call made', 'email' => 'E-mail', 'whatsapp' => 'WhatsApp', 'sms' => 'SMS', 'note' => 'Note'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['arrival_date' => 'date', 'departure_date' => 'date', 'follow_up_at' => 'datetime', 'closed_at' => 'datetime',
            'quoted_rate' => 'decimal:2', 'quoted_total' => 'decimal:2', 'booked_value' => 'decimal:2'];
    }

    public static function reasons(): array
    {
        return self::DENIALS + self::REGRETS;
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function competitor()
    {
        return $this->belongsTo(BdCompetitor::class, 'competitor_id');
    }

    public function agent()
    {
        return $this->belongsTo(User::class, 'taken_by');
    }

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function referredHotel()
    {
        return $this->belongsTo(Hotel::class, 'referred_hotel_id');
    }

    public function referredFrom()
    {
        return $this->belongsTo(Hotel::class, 'referred_from_hotel_id');
    }

    public function contacts()
    {
        return $this->hasMany(CroContact::class)->latest('id');
    }

    public function nights(): int
    {
        return max(1, (int) $this->arrival_date->diffInDays($this->departure_date));
    }

    public function roomNights(): int
    {
        return $this->nights() * max(1, (int) $this->rooms);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? ucfirst($this->status);
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->status][1] ?? 'slate';
    }

    public function channelLabel(): string
    {
        return self::CHANNELS[$this->channel] ?? ucfirst((string) $this->channel);
    }

    public function reasonLabel(): ?string
    {
        return $this->loss_reason ? (self::reasons()[$this->loss_reason] ?? $this->loss_reason) : null;
    }

    public function isFollowUpDue(): bool
    {
        return $this->status === 'open' && $this->follow_up_at && $this->follow_up_at->lte(now());
    }
}
