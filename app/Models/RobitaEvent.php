<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v7.55 – an access event pushed from a Robita device (unlock, lock, alarm, etc.). */
class RobitaEvent extends Model
{
    use BelongsToHotel;

    public const TYPES = [
        'unlock' => 'Unlock',
        'lock' => 'Lock',
        'alarm' => 'Alarm',
        'low_battery' => 'Low battery',
        'tamper' => 'Tamper',
        'forced_entry' => 'Forced entry',
        'remote_unlock' => 'Remote unlock',
        'key_issued' => 'Key issued',
        'key_cancelled' => 'Key cancelled',
        'door_ajar' => 'Door ajar',
    ];

    public const METHODS = ['card', 'fingerprint', 'password', 'remote', 'mobile', 'master_key', 'auto'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return [
            'event_at' => 'datetime',
            'received_at' => 'datetime',
            'payload' => 'array',
            'is_alarm' => 'boolean',
        ];
    }

    public function device()
    {
        return $this->belongsTo(RobitaDevice::class, 'robita_device_id');
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function stay()
    {
        return $this->belongsTo(ReservationRoom::class, 'reservation_room_id');
    }

    public function card()
    {
        return $this->belongsTo(KeyCard::class, 'key_card_id');
    }

    public function isAlarm(): bool
    {
        return $this->is_alarm;
    }
}
