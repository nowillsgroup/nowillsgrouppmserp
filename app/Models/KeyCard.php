<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v3.7 – an RFID / magstripe guest key card encoded for a stay. */
class KeyCard extends Model
{
    use BelongsToHotel;

    public const STATUSES = ['active' => ['Active', 'green'], 'returned' => ['Returned', 'slate'], 'lost' => ['Lost', 'red'], 'expired' => ['Expired', 'slate'], 'cancelled' => ['Cancelled', 'amber']];

    public const TYPES = ['guest' => 'New key', 'duplicate' => 'Duplicate', 'replacement' => 'Replacement'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['valid_from' => 'datetime', 'valid_until' => 'datetime', 'closed_at' => 'datetime', 'areas' => 'array'];
    }

    public function stay()
    {
        return $this->belongsTo(ReservationRoom::class, 'reservation_room_id');
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function issuer()
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function fee()
    {
        return $this->belongsTo(FolioTransaction::class, 'fee_transaction_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->valid_until->isFuture();
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? ucfirst($this->status);
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->status][1] ?? 'slate';
    }
}
