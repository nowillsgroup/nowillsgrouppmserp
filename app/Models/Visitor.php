<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v4.2 – a visitor on the property: guests' visitors, staff / event visitors, pre-registered via the guest portal. */
class Visitor extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    /** status => [label, badge colour] */
    public const STATUSES = [
        'expected' => ['Expected', 'blue'],
        'awaiting' => ['Awaiting guest OK', 'amber'],
        'in' => ['On site', 'green'],
        'out' => ['Left', 'slate'],
        'denied' => ['Denied', 'red'],
        'cancelled' => ['Cancelled', 'slate'],
        'no_show' => ['Did not come', 'slate'],
    ];

    public const PURPOSES = ['guest_visit' => 'Visiting a guest', 'meeting' => 'Meeting / business', 'delivery' => 'Delivery / courier',
        'contractor' => 'Contractor / vendor', 'interview' => 'Interview', 'event' => 'Event / banquet', 'other' => 'Other'];

    public const HOSTS = ['guest' => 'In-house guest', 'staff' => 'Staff member', 'event' => 'Banquet / event'];

    public const ID_TYPES = ['ghana_card' => 'Ghana Card', 'aadhaar' => 'Aadhaar (India)', 'driving_licence' => 'Driving licence', 'voter_id' => 'Voter ID', 'passport' => 'Passport',
        'pan' => 'PAN card', 'company_id' => 'Company ID', 'ghana_card' => 'Ghana Card', 'other' => 'Other'];

    public const APPROVALS = ['phone' => 'Guest confirmed by phone', 'host' => 'Host confirmed', 'pre_registered' => 'Pre-registered by the guest', 'override' => 'Manager override'];

    public const OPEN = ['expected', 'awaiting', 'in'];

    protected function casts(): array
    {
        return ['expected_at' => 'datetime', 'valid_until' => 'datetime', 'checked_in_at' => 'datetime', 'checked_out_at' => 'datetime',
            'overstay_alerted_at' => 'datetime', 'anonymised_at' => 'datetime', 'persons' => 'integer'];
    }

    public function stay()
    {
        return $this->belongsTo(ReservationRoom::class, 'reservation_room_id');
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function hostUser()
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    public function event()
    {
        return $this->belongsTo(BanquetEvent::class, 'banquet_event_id');
    }

    public function watch()
    {
        return $this->belongsTo(SecurityWatch::class, 'watchlist_id');
    }

    public function inBy()
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }

    public function outBy()
    {
        return $this->belongsTo(User::class, 'checked_out_by');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? $this->status;
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->status][1] ?? 'slate';
    }

    public function isOverstay(): bool
    {
        return $this->status === 'in' && $this->valid_until && $this->valid_until->lt(now());
    }

    public function maskedId(): ?string
    {
        return $this->id_type ? (self::ID_TYPES[$this->id_type] ?? $this->id_type).($this->id_last4 ? ' ••••'.$this->id_last4 : '') : null;
    }

    /** Where the visitor is going – room number for guests' visitors (desk view only), the staff member or the event. */
    public function hostLabel(): string
    {
        return match ($this->host_type) {
            'guest' => ($this->room?->room_no ? 'Room '.$this->room->room_no.' · ' : '').($this->host_name ?? 'Guest'),
            'staff' => ($this->host_name ?? 'Staff').' (staff)',
            default => $this->host_name ?? 'Event',
        };
    }
}
