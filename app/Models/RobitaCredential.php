<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** Local card credential record for a guest stay; hardware synchronization is not implemented. */
class RobitaCredential extends Model
{
    use BelongsToHotel;

    public const TYPES = ['card', 'password', 'fingerprint'];
    public const STATUSES = ['active', 'expired', 'cancelled'];
    public const SYNC_STATES = ['ok', 'pending', 'failed', 'simulated', 'unverified'];

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

    /** True only when a real adapter has pending work or reported a failure. */
    public function needsSync(): bool
    {
        return in_array($this->sync_state, ['pending', 'failed', 'unverified'], true);
    }

    public function isActive(): bool
    {
        return $this->status === 'active'
            && (! $this->valid_until || $this->valid_until->isFuture());
    }
}
