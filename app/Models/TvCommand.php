<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v7.1 A remote command queued for a TV and picked up on its next heartbeat. */
class TvCommand extends Model
{
    use BelongsToHotel;

    public const COMMANDS = ['reload' => 'Reload the TV portal', 'reset' => 'Reset (clear the guest session)', 'message' => 'Show a test message',
        'volume' => 'Set the volume limit', 'power_off' => 'Power off (standby)', 'power_on' => 'Power on', 'tune' => 'Tune to a channel'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'sent_at' => 'datetime', 'done_at' => 'datetime'];
    }

    public function device()
    {
        return $this->belongsTo(TvDevice::class, 'tv_device_id');
    }

    public function issuer()
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
