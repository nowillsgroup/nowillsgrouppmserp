<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v3.5 – a message or parcel held at the front desk for a guest. */
class GuestMessage extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    public const CHANNELS = ['phone' => 'Phone call', 'visitor' => 'Visitor at desk', 'email' => 'E-mail / fax', 'internal' => 'Hotel / staff', 'courier' => 'Courier / delivery'];

    public const STATUSES = ['pending' => ['Waiting', 'amber'], 'notified' => ['Guest notified', 'blue'], 'delivered' => ['Delivered', 'green'],
        'returned' => ['Returned / forwarded', 'slate'], 'cancelled' => ['Cancelled', 'slate']];

    public const OPEN = ['pending', 'notified'];

    protected function casts(): array
    {
        return ['urgent' => 'boolean', 'notified_at' => 'datetime', 'portal_read_at' => 'datetime', 'delivered_at' => 'datetime'];
    }

    public function stay()
    {
        return $this->belongsTo(ReservationRoom::class, 'reservation_room_id');
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function taker()
    {
        return $this->belongsTo(User::class, 'taken_by');
    }

    public function deliverer()
    {
        return $this->belongsTo(User::class, 'delivered_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function isParcel(): bool
    {
        return $this->kind === 'parcel';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? $this->status;
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->status][1] ?? 'slate';
    }

    /** minutes the message has been waiting */
    public function ageMinutes(): int
    {
        return (int) $this->created_at->diffInMinutes(now());
    }
}
