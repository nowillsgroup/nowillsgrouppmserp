<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v7.55 – a credential (card, password, fingerprint) issued to a Robita device for a guest. */
class RobitaCredential extends Model
{
    use BelongsToHotel;

    public const TYPES = ['card', 'password', 'fingerprint'];
    public const STATUSES = ['active', 'expired', 'cancelled'];
    public const SYNC_STATES = ['ok', 'pending', 'failed']; // v7.57: did the cloud acknowledge it?

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return [
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'synced_at' => 'datetime',
            'sync_attempts' => 'integer',
        ];
    }

    public function device()
    {
        return $this->belongsTo(RobitaDevice::class, 'robita_device_id');
    }

    public function stay()
    {
        return $this->belongsTo(ReservationRoom::class, 'reservation_room_id');
    }

    public function card()
    {
        return $this->belongsTo(KeyCard::class, 'key_card_id');
    }

    /** The cloud has not confirmed this credential yet (or it failed). */
    public function needsSync(): bool
    {
        return in_array($this->sync_state, ['pending', 'failed'], true);
    }

    public function isActive(): bool
    {
        return $this->status === 'active'
            && (! $this->valid_until || $this->valid_until->isFuture());
    }
}
