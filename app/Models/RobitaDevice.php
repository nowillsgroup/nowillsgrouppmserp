<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/**
 * v7.55 – a Robita smart door lock device registered in the PMS.
 *
 * Each device has a cloud device ID and serial, is mapped to a room (or common area),
 * reports its lock state, battery, and signal, and pushes access events.
 */
class RobitaDevice extends Model
{
    use BelongsToHotel;

    public const STATUSES = ['provisioning', 'online', 'offline', 'decommissioned'];
    public const DEVICE_TYPES = ['door_lock', 'common_area', 'gate'];
    public const LOCK_STATES = ['locked', 'unlocked', 'unknown'];
    public const SIGNALS = ['excellent', 'good', 'fair', 'poor'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'last_event_at' => 'datetime',
            'battery_pct' => 'integer',
            'event_count' => 'integer',
            'unlock_count' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function events()
    {
        return $this->hasMany(RobitaEvent::class, 'robita_device_id');
    }

    public function credentials()
    {
        return $this->hasMany(RobitaCredential::class, 'robita_device_id');
    }

    public function activeCredentials()
    {
        return $this->hasMany(RobitaCredential::class, 'robita_device_id')->where('status', 'active');
    }

    public function isOnline(): bool
    {
        return $this->status === 'online';
    }

    public function isLocked(): bool
    {
        return $this->lock_state === 'locked';
    }

    public function batteryStatus(): string
    {
        if ($this->battery_pct === null) {
            return 'unknown';
        }
        if ($this->battery_pct < 20) {
            return 'critical';
        }
        if ($this->battery_pct < 40) {
            return 'low';
        }
        return 'ok';
    }

    public function signalTone(): string
    {
        return match ($this->signal) {
            'excellent' => 'green',
            'good' => 'blue',
            'fair' => 'amber',
            'poor', null => 'red',
            default => 'slate',
        };
    }

    public function batteryTone(): string
    {
        return match ($this->batteryStatus()) {
            'critical' => 'red',
            'low' => 'amber',
            'ok' => 'green',
            default => 'slate',
        };
    }
}
